<?php
/* La fabrique, mise à l'épreuve sur un cœur de papier.
 *
 *   php tests/check-factory.php
 *
 * Ni Jeedom, ni base de données, ni broker : tests/faux-coeur.php fournit un
 * eqLogic, un cmd, un config, un cache et un journal qui se comportent comme
 * ceux du cœur — y compris là où ils sont désagréables : les deux contraintes
 * d'unicité, les longueurs de colonnes, et le fait qu'une lecture rende des
 * objets neufs relus depuis les colonnes.
 *
 * mqttbeFactory est, avec mqttbeRouting, la seule classe du plugin qui écrive
 * dans la base de quelqu'un. Ce qu'elle rate ne se voit pas tout de suite :
 *
 *   - une découverte non idempotente réécrit tout le parc à chaque démarrage du
 *     démon — les messages de découverte sont retenus par le broker, donc
 *     rejoués —, ce qui réveille les widgets, l'historique et les scénarios ;
 *   - un appareil reconnu par un seul de ses chemins d'identité se retrouve en
 *     double le jour où un second adapter l'annonce autrement ;
 *   - deux commandes de même nom sur un équipement font échouer la contrainte
 *     cmd (eqLogic_id, name), et l'utilisateur voit son appareil disparaître ;
 *   - une commande supprimée parce que son canal a disparu emporte avec elle
 *     les scénarios, vues et plans qui la citaient, sans retour possible ;
 *   - un champ corrigé à la main puis réécrit à la passe suivante fait passer
 *     le plugin pour têtu, et l'utilisateur renonce.
 *
 * Aucun de ces défauts ne se voit à la relecture ni au « php -l ». Tous se
 * démontrent ici en quelques dizaines de lignes. */

require_once __DIR__ . '/outils.php';

/* --------------------------------------------------------------------------
 * Mise en place
 * ------------------------------------------------------------------------ */

/* Le simulacre est chargé à la demande et non au sommet du fichier : run.php
 * inclut tous les contrôles, et un dépôt où la fabrique n'existerait pas encore
 * doit rendre « non vérifiable » plutôt que de tomber à l'inclusion. */
function mqttbeFauxCoeurPret() {
    static $pret = null;
    if ($pret !== null) {
        return $pret;
    }
    foreach (array('core/class/mqttbe.class.php',
                   'core/class/mqttbeRouting.class.php',
                   'core/class/mqttbeFactory.class.php',
                   'core/config/capabilities.json') as $requis) {
        if (!is_readable(mqttbeRacine() . '/' . $requis)) {
            return $pret = false;
        }
    }
    require_once __DIR__ . '/faux-coeur.php';
    $pret = class_exists('mqttbeFactory') && class_exists('mqttbeRouting');
    return $pret;
}

function mqttbeFauxCoeurAbsent() {
    return mqttbeIndecis('fabrique et routage',
        "core/class/mqttbeFactory.class.php, mqttbeRouting.class.php ou "
        . "core/config/capabilities.json n'existe pas encore.");
}

/* Un contrôle échoué doit dire la panne qu'il prévient, et non la règle
 * enfreinte : c'est la moitié du travail de recherche déjà faite. */
function mqttbeVerdict($_titre, $_fautes, $_panne) {
    if (empty($_fautes)) {
        return mqttbeOk($_titre);
    }
    return mqttbeEchec($_titre, $_panne . "\n" . implode("\n", $_fautes));
}

/* --------------------------------------------------------------------------
 * Modèles d'essai
 *
 * Le contrat décrit un modèle de périphérique ; la fabrique accepte aussi bien
 * l'objet que le tableau qu'en donne le JSON du démon. Les contrôles emploient
 * le tableau : c'est la forme qui arrive vraiment, et elle n'oblige pas à
 * charger les classes de découverte.
 * ------------------------------------------------------------------------ */

function mqttbeModele($_uid, $_nom, $_canaux, $_empreinte = '', $_options = array()) {
    $modele = array(
        'identity' => array(
            'uid'     => $_uid,
            'adapter' => isset($_options['adapter']) ? $_options['adapter'] : 'essai',
        ),
        'meta' => array(
            'name'         => $_nom,
            'manufacturer' => 'Fabricant',
            'model'        => 'MOD-1',
        ),
        'channels' => $_canaux,
    );
    if (!empty($_options['aliases'])) {
        $modele['identity']['aliases'] = $_options['aliases'];
    }
    if ($_empreinte !== '') {
        $modele['fingerprint'] = $_empreinte;
    }
    return $modele;
}

function mqttbeCanalInfo($_cle, $_capacite, $_topic, $_options = array()) {
    $canal = array(
        'key'        => $_cle,
        'capability' => $_capacite,
        'source'     => array('topic' => $_topic, 'selector' => array('type' => 'raw')),
    );
    if (isset($_options['path'])) {
        $canal['source']['selector'] = array('type' => 'json', 'path' => $_options['path']);
    }
    foreach (array('name', 'unit', 'order') as $champ) {
        if (isset($_options[$champ])) {
            $canal[$champ] = $_options[$champ];
        }
    }
    foreach (array('transform', 'repeat', 'value') as $champ) {
        if (isset($_options[$champ])) {
            $canal[$champ] = $_options[$champ];
        }
    }
    return $canal;
}

function mqttbeCanalAction($_cle, $_capacite, $_topic, $_payload, $_options = array()) {
    $canal = array(
        'key'        => $_cle,
        'capability' => $_capacite,
        'sink'       => array('topic' => $_topic, 'payload' => $_payload),
    );
    if (isset($_options['links'])) {
        $canal['links'] = $_options['links'];
    }
    if (isset($_options['name'])) {
        $canal['name'] = $_options['name'];
    }
    return $canal;
}

/* Chaque application de modèle simule une requête HTTP distincte : le cache
 * d'index de la fabrique ne survit pas d'une requête à l'autre, et un contrôle
 * qui l'oublierait vérifierait un anti-doublon qui, en production, repart d'une
 * base vide. */
function mqttbeApplique($_modele) {
    mqttbeFactory::resetCache();
    return mqttbeFactory::applyData($_modele);
}

function mqttbeCommandesDe($_uid) {
    return MqttbeFauxCoeur::commandes(MqttbeFauxCoeur::equipement($_uid));
}

/* --------------------------------------------------------------------------
 * Les contrôles
 * ------------------------------------------------------------------------ */

function mqttbeControlesFabrique() {
    if (!mqttbeFauxCoeurPret()) {
        return array(mqttbeFauxCoeurAbsent());
    }
    $resultats = array();

    /* ------------------------------------------------------------------ 1 ---
     * Un modèle complet devient un équipement complet. Le contrôle le plus
     * simple, et celui qui tombe en premier quand une clé de configuration est
     * renommée d'un côté sans l'autre : le démon ne routerait alors plus rien,
     * et le journal du plugin resterait muet. */
    $titre = 'création : équipement, commandes, configuration';
    MqttbeFauxCoeur::reinitialise();
    $modele = mqttbeModele('essai:complet', 'Prise du salon', array(
        mqttbeCanalInfo('relay:0.state', 'switch.state', 'essai/relay/0'),
        mqttbeCanalInfo('relay:0.power', 'power.active', 'essai/relay/0/power'),
        mqttbeCanalAction('relay:0.on', 'switch.on', 'essai/relay/0/command', 'on',
                          array('links' => 'relay:0.state')),
    ), 'empreinte-1', array('adapter' => 'shelly-gen1', 'aliases' => array('essai/prise-salon')));
    $rapport = mqttbeApplique($modele);
    $eqLogic = MqttbeFauxCoeur::equipement('essai:complet');
    $fautes = array();
    if ($rapport['status'] !== 'created') {
        $fautes[] = 'compte rendu : ' . $rapport['status'] . ' au lieu de created ('
                  . implode(' / ', $rapport['messages']) . ')';
    }
    if (!is_object($eqLogic)) {
        $fautes[] = "aucun équipement de logicalId « essai:complet »";
    } else {
        $attendu = array(
            'eqType_name'         => 'mqttbe',
            'mqttbe::uid'         => 'essai:complet',
            'mqttbe::adapter'     => 'shelly-gen1',
            'mqttbe::fingerprint' => 'empreinte-1',
            'mqttbe::manufacturer' => 'Fabricant',
            'mqttbe::model'       => 'MOD-1',
        );
        foreach ($attendu as $cle => $valeur) {
            $obtenu = ($cle === 'eqType_name') ? $eqLogic->getEqType_name()
                                               : $eqLogic->getConfiguration($cle, '');
            if ((string) $obtenu !== $valeur) {
                $fautes[] = $cle . ' = « ' . $obtenu . ' » au lieu de « ' . $valeur . ' »';
            }
        }
        if ($eqLogic->getName() !== 'Prise du salon') {
            $fautes[] = 'nom = « ' . $eqLogic->getName() . ' »';
        }
        $alias = $eqLogic->getConfiguration('mqttbe::aliases', array());
        if (!is_array($alias) || !in_array('essai/prise-salon', $alias, true)) {
            $fautes[] = "l'alias annoncé n'est pas mémorisé : " . json_encode($alias);
        }
        $cmds = mqttbeCommandesDe('essai:complet');
        $attenduCmd = array(
            'relay:0.state' => array('name' => 'État', 'type' => 'info', 'subType' => 'binary',
                                     'generic_type' => 'ENERGY_STATE', 'topic' => 'essai/relay/0'),
            'relay:0.power' => array('name' => 'Puissance', 'type' => 'info', 'subType' => 'numeric',
                                     'generic_type' => 'POWER', 'topic' => 'essai/relay/0/power'),
            'relay:0.on'    => array('name' => 'On', 'type' => 'action', 'subType' => 'other',
                                     'generic_type' => 'ENERGY_ON', 'topic' => 'essai/relay/0/command'),
        );
        foreach ($attenduCmd as $cle => $champs) {
            if (!isset($cmds[$cle])) {
                $fautes[] = 'commande absente : ' . $cle;
                continue;
            }
            $cmd = $cmds[$cle];
            $obtenu = array('name' => $cmd->getName(), 'type' => $cmd->getType(),
                            'subType' => $cmd->getSubType(),
                            'generic_type' => (string) $cmd->getGeneric_type(),
                            'topic' => (string) $cmd->getConfiguration('topic', ''));
            foreach ($champs as $champ => $valeur) {
                if ((string) $obtenu[$champ] !== (string) $valeur) {
                    $fautes[] = $cle . '.' . $champ . ' = « ' . $obtenu[$champ]
                              . ' » au lieu de « ' . $valeur . ' »';
                }
            }
        }
        if (isset($cmds['relay:0.power'])
            && (string) $cmds['relay:0.power']->getUnite() !== 'W') {
            $fautes[] = "l'unité du vocabulaire n'est pas posée (Puissance : « "
                      . $cmds['relay:0.power']->getUnite() . ' »)';
        }
        if (isset($cmds['relay:0.state'])
            && (string) $cmds['relay:0.state']->getConfiguration('keepalive', '') !== '300') {
            $fautes[] = 'keepalive par défaut absent : un capteur stable finirait en timeout';
        }
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "un équipement créé à moitié ne se voit pas : la page s'affiche, et c'est le démon "
        . 'qui ne route rien.');

    /* ------------------------------------------------------------------ 2 ---
     * Idempotence. Le démon rejoue tout le parc à chaque démarrage, les
     * messages de découverte étant retenus par le broker. « Rien n'a changé »
     * ne se vérifie pas en relisant l'équipement mais en comptant les écritures
     * réellement parties vers la base : zéro, sans quoi cent appareils, ce sont
     * mille UPDATE et autant de widgets réveillés à chaque redémarrage. */
    $titre = 'idempotence : aucune écriture sur un modèle identique';
    $avant = MqttbeFauxCoeur::ecritures();
    $rapport = mqttbeApplique($modele);
    $ecritures = MqttbeFauxCoeur::ecritures() - $avant;
    $fautes = array();
    if ($ecritures !== 0) {
        $fautes[] = $ecritures . ' écriture(s) en base pour un modèle rigoureusement identique';
    }
    if ($rapport['status'] !== 'unchanged') {
        $fautes[] = 'compte rendu : « ' . $rapport['status'] . ' » au lieu de « unchanged »';
    }
    /* Et pas seulement aucune écriture : aucune lecture non plus. Une empreinte
     * identique doit couper court avant même d'aller chercher les commandes de
     * l'équipement — le compte rendu le dit, tous ses compteurs restant à zéro.
     * Sur un parc de cent appareils rejoué à chaque démarrage, c'est la
     * différence entre une requête et plusieurs milliers. */
    foreach ($rapport['cmd'] as $compteur => $valeur) {
        if ($valeur !== 0) {
            $fautes[] = 'les commandes ont tout de même été examinées (' . $compteur
                      . ' = ' . $valeur . ') : l\'empreinte de l\'équipement ne sert à rien';
        }
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'les messages de découverte sont retenus par le broker et rejoués à chaque démarrage '
        . "du démon : sans idempotence, tout le parc est réécrit à chaque redémarrage.");

    /* ------------------------------------------------------------------ 3 ---
     * Même idempotence, mais sans empreinte : un modèle bâti à la main ou
     * importé n'en porte pas forcément, et le raccourci de l'empreinte ne joue
     * alors pas. Le travail doit tout de même se solder par « unchanged » et
     * par aucune écriture — c'est la comparaison champ par champ qui le dit. */
    $titre = 'idempotence sans empreinte (modèle importé à la main)';
    $sansEmpreinte = $modele;
    unset($sansEmpreinte['fingerprint']);
    mqttbeApplique($sansEmpreinte);
    $avant = MqttbeFauxCoeur::ecritures();
    $rapport = mqttbeApplique($sansEmpreinte);
    $ecritures = MqttbeFauxCoeur::ecritures() - $avant;
    $fautes = array();
    if ($ecritures !== 0) {
        $fautes[] = $ecritures . ' écriture(s) en base';
    }
    if ($rapport['status'] !== 'unchanged') {
        $fautes[] = 'compte rendu : « ' . $rapport['status'] . ' »';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "un modèle sans empreinte passe par la comparaison champ par champ : si elle se "
        . 'trompe, chaque passage réécrit l\'équipement.');

    /* ------------------------------------------------------------------ 4 ---
     * Reconnaissance par alias. Un même appareil se présente par plusieurs
     * chemins — adresse MAC, préfixe de topic, unique_id Home Assistant. En
     * reconnaître un seul suffit à faire une mise à jour au lieu d'une
     * création : c'est le seul rempart contre un parc créé en double le jour où
     * Zigbee2MQTT publie aussi du Home Assistant Discovery. */
    $titre = 'reconnaissance par alias : un seul équipement';
    MqttbeFauxCoeur::reinitialise();
    $canaux = array(mqttbeCanalInfo('t', 'sensor.temperature', 'maison/salon/temp'));
    $premier = mqttbeApplique(mqttbeModele('mac:a8b0c1', 'Sonde', $canaux, 'e-1',
        array('adapter' => 'shelly-gen1', 'aliases' => array('maison/salon'))));
    $rapport = mqttbeApplique(mqttbeModele('maison/salon', 'Sonde', $canaux, 'e-2',
        array('adapter' => 'tasmota')));
    $fautes = array();
    /* Les deux applications doivent avoir abouti : sans cela, « un seul
     * équipement » serait vrai pour la mauvaise raison — il n'y en aurait
     * aucun. */
    foreach (array($premier, $rapport) as $rang => $bilan) {
        if ($bilan['status'] === 'error') {
            $fautes[] = 'modèle ' . ($rang + 1) . ' refusé : ' . implode(' / ', $bilan['messages']);
        }
    }
    if (MqttbeFauxCoeur::nombreEquipements() !== 1) {
        $fautes[] = MqttbeFauxCoeur::nombreEquipements() . ' équipements au lieu d\'un seul';
    }
    if ($rapport['status'] === 'created') {
        $fautes[] = "le second modèle a créé un équipement au lieu de reconnaître l'existant";
    }
    if (MqttbeFauxCoeur::nombreCommandes() !== 1) {
        $fautes[] = MqttbeFauxCoeur::nombreCommandes() . ' commandes au lieu d\'une seule';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "un appareil qui s'annonce par deux chemins différents finit en deux équipements, "
        . "avec deux historiques et des scénarios qui pointent sur le mauvais.");

    /* ------------------------------------------------------------------ 5 ---
     * Suite du précédent, et exigence que la fabrique s'impose elle-même : « les
     * alias sont cumulés et jamais retirés ». L'identifiant d'hier est
     * exactement un de ces chemins : quand le uid change, il doit rejoindre les
     * alias. Sans cela, l'appareil qui se réannonce par son identité d'origine
     * — le premier adapter qui reprend la main après un redémarrage — est
     * recréé en double, et le doublon passe inaperçu parce que les deux
     * équipements portent presque le même nom. */
    $titre = 'identité précédente conservée quand le uid change';
    $rapport = mqttbeApplique(mqttbeModele('mac:a8b0c1', 'Sonde', $canaux, 'e-3',
        array('adapter' => 'shelly-gen1')));
    $fautes = array();
    if ($rapport['status'] === 'error') {
        $fautes[] = 'modèle refusé : ' . implode(' / ', $rapport['messages']);
    }
    if (MqttbeFauxCoeur::nombreEquipements() !== 1) {
        $fautes[] = MqttbeFauxCoeur::nombreEquipements() . " équipements : le uid d'origine "
                  . "« mac:a8b0c1 » n'est plus reconnu après le passage à « maison/salon »";
    }
    if ($rapport['status'] === 'created') {
        $fautes[] = "l'appareil a été recréé alors qu'il se présente par son identité d'origine";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "applyEqLogic() écrase logicalId et mqttbe::uid par le nouvel identifiant sans ranger "
        . "l'ancien dans mqttbe::aliases : l'appareil devient méconnaissable par le chemin qui "
        . "l'avait fait naître.");

    /* ------------------------------------------------------------------ 6 ---
     * Les retouches de l'utilisateur, champ par champ. Renommer une commande ne
     * doit pas geler l'unité : le vocabulaire des capacités se corrige avec le
     * temps, et une empreinte globale figerait toute la présentation au premier
     * renommage. */
    $titre = 'retouches respectées champ par champ';
    MqttbeFauxCoeur::reinitialise();
    $modeleSonde = mqttbeModele('essai:sonde', 'Sonde', array(
        mqttbeCanalInfo('t', 'sensor.temperature', 'essai/t'),
    ), 'e-1');
    mqttbeApplique($modeleSonde);
    $cmd = mqttbeCommandesDe('essai:sonde');
    $cmd = $cmd['t'];
    $cmd->setName('Température de la cave');
    $cmd->setIsHistorized(0);
    $cmd->save();
    $corrige = $modeleSonde;
    $corrige['fingerprint'] = 'e-2';
    $corrige['channels'][0]['unit'] = 'K';
    mqttbeApplique($corrige);
    $cmd = mqttbeCommandesDe('essai:sonde');
    $cmd = isset($cmd['t']) ? $cmd['t'] : null;
    $fautes = array();
    if ($cmd === null) {
        $fautes[] = 'la commande a disparu';
    } else {
        if ($cmd->getName() !== 'Température de la cave') {
            $fautes[] = 'le nom choisi par l\'utilisateur a été réécrit : « ' . $cmd->getName() . ' »';
        }
        if ((string) $cmd->getUnite() !== 'K') {
            $fautes[] = "l'unité n'a pas suivi le modèle (« " . $cmd->getUnite()
                      . ' » au lieu de « K ») : un renommage a gelé le reste de la présentation';
        }
        if ((int) $cmd->getIsHistorized() !== 0) {
            $fautes[] = "l'historisation coupée par l'utilisateur a été rétablie";
        }
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "le suivi doit être champ par champ : une empreinte globale ferait qu'un simple "
        . 'renommage empêche à jamais toute correction d\'unité ou de type générique.');

    /* ------------------------------------------------------------------ 7 ---
     * L'envers du précédent : la plomberie n'appartient pas à l'utilisateur. Un
     * firmware qui déplace une valeur dans sa charge utile doit être suivi,
     * sinon la commande cesse de remonter quoi que ce soit, sans un mot. Les
     * réglages fins, eux, restent à l'utilisateur. */
    $titre = 'plomberie suivie, réglages fins préservés';
    MqttbeFauxCoeur::reinitialise();
    $avantFirmware = mqttbeModele('essai:firmware', 'Compteur', array(
        mqttbeCanalInfo('p', 'power.active', 'essai/emeter', array('path' => 'power')),
    ), 'e-1');
    mqttbeApplique($avantFirmware);
    $cmd = mqttbeCommandesDe('essai:firmware');
    $cmd = $cmd['p'];
    $cmd->setConfiguration('keepalive', 1800);
    $cmd->save();
    $apresFirmware = $avantFirmware;
    $apresFirmware['fingerprint'] = 'e-2';
    $apresFirmware['channels'][0]['source'] = array(
        'topic' => 'essai/status/em:0',
        'selector' => array('type' => 'json', 'path' => 'a_act_power'),
    );
    mqttbeApplique($apresFirmware);
    $cmd = mqttbeCommandesDe('essai:firmware');
    $cmd = $cmd['p'];
    $fautes = array();
    if ((string) $cmd->getConfiguration('topic', '') !== 'essai/status/em:0') {
        $fautes[] = 'le topic n\'a pas suivi le firmware : « ' . $cmd->getConfiguration('topic', '') . ' »';
    }
    if ((string) $cmd->getConfiguration('path', '') !== 'a_act_power') {
        $fautes[] = 'le chemin JSON n\'a pas suivi : « ' . $cmd->getConfiguration('path', '') . ' »';
    }
    if ((string) $cmd->getConfiguration('keepalive', '') !== '1800') {
        $fautes[] = 'le keepalive choisi par l\'utilisateur a été écrasé : « '
                  . $cmd->getConfiguration('keepalive', '') . ' »';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "topic et chemin JSON sont du câblage : figés, la commande devient muette sans que "
        . 'rien ne le signale.');

    /* ------------------------------------------------------------------ 8 ---
     * Dédoublonnage AVANT l'enregistrement. cmd (eqLogic_id, name) est unique :
     * trois canaux qui retombent sur le nom « Température » ne doivent pas
     * produire une commande et deux refus de la base. */
    $titre = 'noms de commande dédoublonnés avant écriture';
    MqttbeFauxCoeur::reinitialise();
    $rapport = mqttbeApplique(mqttbeModele('essai:trois', 'Triple sonde', array(
        mqttbeCanalInfo('t1', 'sensor.temperature', 'essai/t1'),
        mqttbeCanalInfo('t2', 'sensor.temperature', 'essai/t2'),
        mqttbeCanalInfo('t3', 'sensor.temperature', 'essai/t3'),
    ), 'e-1'));
    $cmds = mqttbeCommandesDe('essai:trois');
    $noms = array();
    foreach ($cmds as $cle => $cmd) {
        $noms[$cle] = $cmd->getName();
    }
    $fautes = array();
    if (count($cmds) !== 3) {
        $fautes[] = count($cmds) . ' commandes enregistrées au lieu de 3 ('
                  . implode(' / ', $rapport['messages']) . ')';
    }
    if (count(array_unique($noms)) !== count($noms)) {
        $fautes[] = 'noms en double : ' . json_encode($noms, JSON_UNESCAPED_UNICODE);
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'la contrainte cmd (eqLogic_id, name) rejette le doublon : la commande manque, et sur '
        . "le chemin d'enregistrement de l'interface c'est l'équipement entier qui disparaît.");

    /* ------------------------------------------------------------------ 9 ---
     * Les colonnes de noms sont des varchar(127). Un nom plus long part vers
     * MySQL tel quel si personne ne le coupe, et l'insertion est refusée. Le
     * cœur tronque dans setName() : encore faut-il que la déduplication
     * travaille sur le nom tronqué, faute de quoi deux noms longs et identiques
     * se retrouvent identiques après coupe. */
    $titre = 'noms tronqués à 127 caractères, et toujours distincts';
    MqttbeFauxCoeur::reinitialise();
    $long = str_repeat('Nom de commande interminable ', 8);
    $rapport = mqttbeApplique(mqttbeModele('essai:longs', 'Noms longs', array(
        mqttbeCanalInfo('a', 'generic.value', 'essai/a', array('name' => $long)),
        mqttbeCanalInfo('b', 'generic.value', 'essai/b', array('name' => $long)),
    ), 'e-1'));
    $cmds = mqttbeCommandesDe('essai:longs');
    $fautes = array();
    if (count($cmds) !== 2) {
        $fautes[] = count($cmds) . ' commande(s) au lieu de 2 ('
                  . implode(' / ', $rapport['messages']) . ')';
    }
    $vus = array();
    foreach ($cmds as $cle => $cmd) {
        $nom = $cmd->getName();
        if (mqttbeLongueur($nom) > 127) {
            $fautes[] = $cle . ' : nom de ' . mqttbeLongueur($nom) . ' caractères';
        }
        if (isset($vus[$nom])) {
            $fautes[] = 'deux commandes portent le même nom après troncature';
        }
        $vus[$nom] = true;
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "MySQL refuse l'insertion (« Data too long for column 'name' ») et la commande "
        . 'manque, sans rien dans le journal du plugin.');

    /* ----------------------------------------------------------------- 10 ---
     * Un uid trop long ne peut pas être tronqué : deux appareils différents
     * partageraient alors le même logicalId, et leurs valeurs se mélangeraient.
     * Le refus doit être net et l'équipement ne doit pas exister à moitié. */
    $titre = 'uid de plus de 127 caractères refusé, rien de créé';
    MqttbeFauxCoeur::reinitialise();
    $rapport = mqttbeApplique(mqttbeModele(str_repeat('u', 128), 'Trop long', array(
        mqttbeCanalInfo('t', 'sensor.temperature', 'essai/t'),
    ), 'e-1'));
    $fautes = array();
    if ($rapport['status'] !== 'error') {
        $fautes[] = 'compte rendu : « ' . $rapport['status'] . ' » au lieu de « error »';
    }
    if (MqttbeFauxCoeur::nombreEquipements() !== 0) {
        $fautes[] = MqttbeFauxCoeur::nombreEquipements() . ' équipement(s) créé(s) malgré tout';
    }
    if (MqttbeFauxCoeur::nombreCommandes() !== 0) {
        $fautes[] = MqttbeFauxCoeur::nombreCommandes() . ' commande(s) créée(s) malgré tout';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'eqLogic.logicalId est un varchar(127) que le cœur ne tronque pas : sans ce refus, '
        . "l'enregistrement part vers MySQL et échoue au milieu de la fabrication.");

    /* ----------------------------------------------------------------- 11 ---
     * Un canal disparu n'est pas une commande à supprimer. Un identifiant de
     * commande est cité par les scénarios, les vues, les plans et les
     * interactions : le supprimer casse tout cela sans avertissement et sans
     * retour. Et la disparition n'est parfois qu'un firmware qui s'est tu le
     * temps d'un redémarrage. */
    $titre = 'canal disparu : mis en sommeil, jamais supprimé';
    MqttbeFauxCoeur::reinitialise();
    $complet = mqttbeModele('essai:orphelin', 'Appareil', array(
        mqttbeCanalInfo('t', 'sensor.temperature', 'essai/t'),
        mqttbeCanalInfo('h', 'sensor.humidity', 'essai/h'),
    ), 'e-1');
    mqttbeApplique($complet);
    $cmds = mqttbeCommandesDe('essai:orphelin');
    MqttbeFauxCoeur::ajouteHistorique($cmds['h']->getId());
    $idHumidite = $cmds['h']->getId();
    $ampute = $complet;
    $ampute['fingerprint'] = 'e-2';
    array_pop($ampute['channels']);
    $rapport = mqttbeApplique($ampute);
    $cmds = mqttbeCommandesDe('essai:orphelin');
    $fautes = array();
    if (!isset($cmds['h'])) {
        $fautes[] = "la commande a été supprimée : son historique et les scénarios qui la "
                  . 'citaient sont perdus';
    } else {
        if ($cmds['h']->getId() != $idHumidite) {
            $fautes[] = "la commande a été recréée : son identifiant a changé";
        }
        if ((int) $cmds['h']->getIsVisible() !== 0) {
            $fautes[] = 'la commande est restée sur le tableau de bord';
        }
        if ((string) $cmds['h']->getConfiguration('mqttbe::orphan', '') === '') {
            $fautes[] = 'aucune date de disparition sur la commande';
        }
    }
    if ($rapport['cmd']['orphaned'] !== 1) {
        $fautes[] = 'compte rendu : orphaned = ' . $rapport['cmd']['orphaned'];
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "supprimer une commande citée ailleurs casse des scénarios sans retour possible, et "
        . "l'utilisateur ne l'apprend que le jour où le scénario ne se déclenche pas.");

    /* ----------------------------------------------------------------- 12 ---
     * Le canal revient — c'est le cas le plus fréquent, le firmware ayant
     * simplement redémarré. La commande doit reprendre sa place, avec son
     * historique et ses scénarios intacts. Le piège est que la fabrique a
     * elle-même retiré la commande du tableau de bord : elle doit s'en souvenir
     * pour ne pas prendre son propre geste pour une retouche de l'utilisateur. */
    $titre = 'canal revenu : commande rendue à la vie';
    $rapport = mqttbeApplique($complet);
    $cmds = mqttbeCommandesDe('essai:orphelin');
    $fautes = array();
    if (!isset($cmds['h'])) {
        $fautes[] = 'la commande a disparu';
    } else {
        if ($cmds['h']->getId() != $idHumidite) {
            $fautes[] = "la commande a été recréée (identifiant " . $cmds['h']->getId()
                      . ' au lieu de ' . $idHumidite . ') : historique et scénarios perdus';
        }
        if ((int) $cmds['h']->getIsVisible() !== 1) {
            $fautes[] = 'la commande est restée invisible sur le tableau de bord';
        }
        if ((string) $cmds['h']->getConfiguration('mqttbe::orphan', '') !== '') {
            $fautes[] = 'la marque de disparition n\'a pas été effacée';
        }
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'une commande restée invisible après le retour de son canal passe pour une panne du '
        . 'plugin, et personne ne pense à regarder la case « visible ».');

    /* ----------------------------------------------------------------- 13 ---
     * Le seul cas où supprimer est sans danger : aucune référence nulle part et
     * aucun relevé. Laisser traîner ces commandes-là finirait par rendre la
     * page de l'équipement illisible. */
    $titre = 'canal disparu sans historique ni référence : supprimé';
    MqttbeFauxCoeur::reinitialise();
    $deux = mqttbeModele('essai:propre', 'Appareil', array(
        mqttbeCanalInfo('t', 'sensor.temperature', 'essai/t'),
        mqttbeCanalInfo('x', 'generic.value', 'essai/x'),
    ), 'e-1');
    mqttbeApplique($deux);
    $ampute = $deux;
    $ampute['fingerprint'] = 'e-2';
    array_pop($ampute['channels']);
    $rapport = mqttbeApplique($ampute);
    $cmds = mqttbeCommandesDe('essai:propre');
    $fautes = array();
    if (isset($cmds['x'])) {
        $fautes[] = 'la commande morte est restée sur la page de l\'équipement';
    }
    if ($rapport['cmd']['removed'] !== 1) {
        $fautes[] = 'compte rendu : removed = ' . $rapport['cmd']['removed'];
    }
    /* La même, mais citée par un scénario : en cas de doute, on garde. */
    MqttbeFauxCoeur::reinitialise();
    mqttbeApplique($deux);
    $cmds = mqttbeCommandesDe('essai:propre');
    MqttbeFauxCoeur::reference($cmds['x']->getId(), 'scenario');
    $rapport = mqttbeApplique($ampute);
    $cmds = mqttbeCommandesDe('essai:propre');
    if (!isset($cmds['x'])) {
        $fautes[] = 'une commande citée par un scénario a été supprimée';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "le tri se fait sur les références et l'historique : trop prudent, la page se remplit "
        . 'de commandes mortes ; trop pressé, un scénario casse.');

    /* ----------------------------------------------------------------- 14 ---
     * Le lien action → information (cmd.value). Sur un appareil à deux relais,
     * « switch:1.on » doit piloter l'état du relais 1 et non celui du voisin :
     * l'erreur donne un tableau de bord où un bouton allume la mauvaise
     * lampe — et où l'état affiché ne bouge pas. */
    $titre = 'lien action → info, composant par composant';
    MqttbeFauxCoeur::reinitialise();
    mqttbeApplique(mqttbeModele('essai:double', 'Double relais', array(
        mqttbeCanalInfo('switch:0.state', 'switch.state', 'essai/relay/0'),
        mqttbeCanalInfo('switch:1.state', 'switch.state', 'essai/relay/1'),
        mqttbeCanalAction('switch:0.on', 'switch.on', 'essai/relay/0/command', 'on'),
        mqttbeCanalAction('switch:1.on', 'switch.on', 'essai/relay/1/command', 'on'),
    ), 'e-1'));
    $cmds = mqttbeCommandesDe('essai:double');
    $fautes = array();
    foreach (array('0', '1') as $rang) {
        $action = 'switch:' . $rang . '.on';
        $info   = 'switch:' . $rang . '.state';
        if (!isset($cmds[$action], $cmds[$info])) {
            $fautes[] = 'commande absente : ' . $action . ' ou ' . $info;
            continue;
        }
        if ((string) $cmds[$action]->getValue() !== (string) $cmds[$info]->getId()) {
            $fautes[] = $action . ' pilote la commande ' . $cmds[$action]->getValue()
                      . ' au lieu de ' . $cmds[$info]->getId() . ' (' . $info . ')';
        }
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "un lien posé sur le mauvais composant donne un bouton qui allume l'autre relais, et "
        . "un état qui ne bouge jamais : on croit le plugin cassé, c'est le lien.");

    /* ----------------------------------------------------------------- 15 ---
     * Une capacité absente du vocabulaire n'est pas une raison de perdre la
     * valeur : la commande existe en information texte, et le journal dit
     * quelle entrée manque à capabilities.json. */
    $titre = 'capacité inconnue : repli sur generic.value';
    MqttbeFauxCoeur::reinitialise();
    mqttbeApplique(mqttbeModele('essai:inconnu', 'Appareil exotique', array(
        mqttbeCanalInfo('z', 'licorne.paillettes', 'essai/z'),
    ), 'e-1'));
    $cmds = mqttbeCommandesDe('essai:inconnu');
    $fautes = array();
    if (!isset($cmds['z'])) {
        $fautes[] = 'la valeur est perdue : aucune commande créée';
    } else {
        if ($cmds['z']->getType() !== 'info' || $cmds['z']->getSubType() !== 'string') {
            $fautes[] = 'commande créée en ' . $cmds['z']->getType() . '/'
                      . $cmds['z']->getSubType() . ' au lieu de info/string';
        }
        if ((string) $cmds['z']->getConfiguration('topic', '') !== 'essai/z') {
            $fautes[] = 'le topic n\'a pas été posé';
        }
    }
    if (!MqttbeFauxCoeur::journalContient('licorne.paillettes')) {
        $fautes[] = "le journal ne dit pas quelle capacité manque au vocabulaire";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "sans repli, un appareil dont une seule capacité est inconnue perd cette valeur en "
        . 'silence, et rien ne dit qu\'il faut compléter capabilities.json.');

    /* ----------------------------------------------------------------- 16 ---
     * Reprise par un autre adapter. Deux adapters peuvent annoncer le même
     * appareil ; l'arbitrage par priorité viendra, mais dès maintenant la
     * reprise doit être visible dans le journal, faute de quoi un parc qui
     * change de main sous les pieds de l'utilisateur reste inexplicable. */
    $titre = 'reprise par un autre adapter : tracée et appliquée';
    MqttbeFauxCoeur::reinitialise();
    $canal = array(mqttbeCanalInfo('t', 'sensor.temperature', 'essai/t'));
    mqttbeApplique(mqttbeModele('essai:reprise', 'Sonde', $canal, 'e-1',
        array('adapter' => 'shelly-gen1')));
    mqttbeApplique(mqttbeModele('essai:reprise', 'Sonde', $canal, 'e-2',
        array('adapter' => 'homeassistant')));
    $eqLogic = MqttbeFauxCoeur::equipement('essai:reprise');
    $fautes = array();
    if (!is_object($eqLogic)) {
        $fautes[] = 'équipement introuvable';
    } elseif ((string) $eqLogic->getConfiguration('mqttbe::adapter', '') !== 'homeassistant') {
        $fautes[] = 'adapter resté à « ' . $eqLogic->getConfiguration('mqttbe::adapter', '') . ' »';
    }
    if (MqttbeFauxCoeur::nombreEquipements() !== 1) {
        $fautes[] = "l'appareil a été dédoublé au lieu d'être repris";
    }
    if (!MqttbeFauxCoeur::journalContient('homeassistant')) {
        $fautes[] = 'la reprise ne laisse aucune trace au journal';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'une reprise silencieuse rend incompréhensible un équipement dont la configuration '
        . 'change toute seule.');

    /* ----------------------------------------------------------------- 17 ---
     * eqLogic (name, object_id) est unique, tous plugins confondus : le conflit
     * peut donc venir d'un équipement d'un autre plugin rangé dans la même
     * pièce. Un équipement refusé par la base, c'est un appareil découvert qui
     * n'apparaît jamais. */
    $titre = 'nom d\'équipement dédoublonné dans la pièce';
    MqttbeFauxCoeur::reinitialise();
    $voisin = new eqLogic();
    $voisin->setEqType_name('virtual');
    $voisin->setName('Chaudière');
    $voisin->setObject_id(3);
    $voisin->save();
    mqttbeApplique(mqttbeModele('essai:piece', 'Appareil', $canal, 'e-1'));
    $eqLogic = MqttbeFauxCoeur::equipement('essai:piece');
    $eqLogic->setObject_id(3);
    $eqLogic->save();
    $rapport = mqttbeApplique(mqttbeModele('essai:piece', 'Chaudière', $canal, 'e-2'));
    $eqLogic = MqttbeFauxCoeur::equipement('essai:piece');
    $fautes = array();
    if ($rapport['status'] === 'error') {
        $fautes[] = 'la fabrique a échoué : ' . implode(' / ', $rapport['messages']);
    }
    if (!is_object($eqLogic)) {
        $fautes[] = 'équipement perdu';
    } elseif ($eqLogic->getName() === 'Chaudière') {
        $fautes[] = "deux équipements « Chaudière » dans la pièce 3 : MySQL refuse le second";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "l'enregistrement est refusé par la contrainte eqLogic (name, object_id) et "
        . "l'appareil découvert n'apparaît jamais dans l'interface.");

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Fabrique : écriture en base', mqttbeControlesFabrique());
}
