<?php
/* La table de routage et son expédition, sur un cœur de papier.
 *
 *   php tests/check-routing.php
 *
 * Ni Jeedom, ni base de données, ni broker : tests/faux-coeur.php fournit le
 * cœur, et un double du démon note ce qu'on lui envoie au lieu de l'envoyer.
 *
 * Cette table porte toute la vérité du plugin : ce qui n'y figure pas n'existe
 * pas pour le démon, qui en déduit jusqu'à ses abonnements. Deux exigences en
 * découlent, et ce sont elles que ces contrôles éprouvent.
 *
 *   - Elle doit être complète. Une commande oubliée, c'est une valeur qui ne
 *     remonte plus, sans erreur nulle part : le démon n'est même pas abonné au
 *     topic. Une table de correspondance mal saisie par l'utilisateur ne doit
 *     donc emporter ni la commande ni, surtout, les deux cents autres.
 *   - Elle doit être déterministe. L'ordre dans lequel la base rend les
 *     équipements n'est garanti par rien ; si la table en dépend, son empreinte
 *     change sans que rien n'ait changé, et le garde-fou qui évite les envois
 *     inutiles se met à envoyer une table complète à chaque enregistrement.
 *
 * S'y ajoute la question du numéro de version : le démon ignore toute table
 * plus ancienne que celle qu'il applique. Une version qui régresse — après un
 * vidage de cache, ce qui arrive à chaque mise à jour de Jeedom — et c'est un
 * routage qui reste mort jusqu'au prochain redémarrage du démon, sans que rien
 * ne le dise. */

require_once __DIR__ . '/outils.php';
require_once __DIR__ . '/check-factory.php';   /* mqttbeFauxCoeurPret(), mqttbeVerdict() */

/* --------------------------------------------------------------------------
 * Fabrication d'un parc d'essai
 *
 * Les équipements sont écrits directement et non par la fabrique : ce qui est
 * éprouvé ici, c'est la lecture que le routage fait de la base, y compris de
 * commandes saisies à la main — un champ vide, une correspondance fautive, un
 * topic oublié. La fabrique ne produirait jamais certains de ces cas, et ce
 * sont justement ceux qui cassent.
 * ------------------------------------------------------------------------ */

function mqttbeEqLogicEssai($_nom, $_active = 1) {
    $eqLogic = new mqttbe();
    $eqLogic->setEqType_name('mqttbe');
    $eqLogic->setName($_nom);
    $eqLogic->setLogicalId('essai:' . strtolower($_nom));
    $eqLogic->setIsEnable($_active);
    $eqLogic->setIsVisible(1);
    $eqLogic->save();
    return $eqLogic;
}

function mqttbeCmdEssai($_eqLogic, $_nom, $_configuration = array(), $_type = 'info', $_subType = 'numeric') {
    $cmd = new mqttbeCmd();
    $cmd->setEqLogic_id($_eqLogic->getId());
    $cmd->setEqType('mqttbe');
    $cmd->setName($_nom);
    $cmd->setLogicalId($_nom);
    $cmd->setType($_type);
    $cmd->setSubType($_subType);
    foreach ($_configuration as $cle => $valeur) {
        $cmd->setConfiguration($cle, $valeur);
    }
    $cmd->save();
    return $cmd;
}

/* L'entrée de la table qui porte ce topic, ou null. */
function mqttbeEntree($_table, $_topic) {
    foreach ($_table as $entree) {
        if ($entree['topic'] === $_topic) {
            return $entree;
        }
    }
    return null;
}

function mqttbeCible($_table, $_cmdId) {
    foreach ($_table as $entree) {
        foreach ($entree['targets'] as $cible) {
            if ((int) $cible['cmdId'] === (int) $_cmdId) {
                return $cible;
            }
        }
    }
    return null;
}

function mqttbeTopicsDe($_table) {
    $topics = array();
    foreach ($_table as $entree) {
        $topics[] = $entree['topic'];
    }
    return $topics;
}

/* --------------------------------------------------------------------------
 * Les contrôles
 * ------------------------------------------------------------------------ */

function mqttbeControlesRoutage() {
    if (!mqttbeFauxCoeurPret()) {
        return array(mqttbeFauxCoeurAbsent());
    }
    $resultats = array();

    /* ------------------------------------------------------------------ 1 ---
     * Un parc ordinaire : un compteur dont on extrait trois valeurs d'un même
     * JSON, un relais, une commande d'action, une commande sans topic, une
     * correspondance fautive, et un équipement désactivé. Tout le reste des
     * contrôles s'appuie sur cette table. */
    MqttbeFauxCoeur::reinitialise();
    $alpha = mqttbeEqLogicEssai('Alpha');
    $beta  = mqttbeEqLogicEssai('Beta');
    $eteint = mqttbeEqLogicEssai('Zoulou', 0);

    $puissance = mqttbeCmdEssai($alpha, 'Puissance',
        array('topic' => 'essai/emeter', 'path' => 'power', 'round' => '1'));
    $tension = mqttbeCmdEssai($alpha, 'Tension',
        array('topic' => 'essai/emeter', 'path' => 'voltage', 'scale' => '', 'round' => '', 'offset' => ''));
    $energie = mqttbeCmdEssai($alpha, 'Énergie',
        array('topic' => 'essai/emeter', 'path' => 'energy', 'scale' => '0.001'));
    $etat = mqttbeCmdEssai($alpha, 'État',
        array('topic' => 'essai/relay/0', 'map' => '{"on":"1","off":"0"}'), 'info', 'binary');
    $fautive = mqttbeCmdEssai($alpha, 'Correspondance fautive',
        array('topic' => 'essai/relay/0', 'map' => '{"on":1,'));
    $calculee = mqttbeCmdEssai($alpha, 'Calculée', array('path' => 'peu importe'));
    $action = mqttbeCmdEssai($alpha, 'Allumer',
        array('topic' => 'essai/relay/0/command', 'payload' => 'on'), 'action', 'other');
    $arrondi = mqttbeCmdEssai($beta, 'Température',
        array('topic' => 'essai/temp', 'round' => '0', 'keepalive' => '60', 'repeat' => 'always'));
    $muette = mqttbeCmdEssai($eteint, 'Muette', array('topic' => 'essai/zoulou'));
    /* Une commande qui lit un ÉVÉNEMENT : ce que le broker rejoue ne doit pas
     * l'alimenter, sans quoi un appui sur un bouton vieux de trois semaines
     * déclencherait un scénario au démarrage du démon. */
    $evenement = mqttbeCmdEssai($beta, 'Dernier événement',
        array('topic' => 'essai/events/rpc', 'path' => 'params.events.0.event',
              'repeat' => 'always', 'ignore_retained' => '1'), 'info', 'string');

    $table = mqttbeRouting::build();

    /* ------------------------------------------------------------------ 1 ---
     * Regroupement : trois commandes sur le même topic ne font qu'une entrée.
     * Le démon n'analyse ainsi la charge utile qu'une fois, quel que soit le
     * nombre de valeurs qu'on en tire — c'est tout l'intérêt d'un routage
     * calculé à l'avance. */
    $titre = 'plusieurs commandes sur un topic : une seule entrée';
    $entree = mqttbeEntree($table, 'essai/emeter');
    $fautes = array();
    if ($entree === null) {
        $fautes[] = 'aucune entrée pour essai/emeter';
    } else {
        if (count($entree['targets']) !== 3) {
            $fautes[] = count($entree['targets']) . ' cible(s) au lieu de 3';
        }
        $ids = array();
        foreach ($entree['targets'] as $cible) {
            $ids[] = (int) $cible['cmdId'];
        }
        $tries = $ids;
        sort($tries);
        if ($ids !== $tries) {
            $fautes[] = 'cibles non triées par identifiant : ' . json_encode($ids);
        }
    }
    if (count(mqttbeTopicsDe($table)) !== count(array_unique(mqttbeTopicsDe($table)))) {
        $fautes[] = 'un topic apparaît dans deux entrées : ' . json_encode(mqttbeTopicsDe($table));
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'un topic éclaté en plusieurs entrées fait décoder la même charge utile autant de '
        . "fois, et le démon s'y abonne plusieurs fois.");

    /* ------------------------------------------------------------------ 2 ---
     * Déterminisme. La base ne garantit aucun ordre : deux lectures peuvent
     * rendre les équipements dans l'ordre inverse. Si la table en dépend, son
     * empreinte change sans raison, et la comparaison qui évite les envois
     * inutiles ne compare plus rien — le démon réindexe son arbre de topics à
     * chaque enregistrement d'un équipement. */
    $titre = 'déterminisme : le même parc donne le même octet';
    /* Un topic partagé par deux équipements : c'est là que l'ordre de lecture
     * de la base se voit, puisque les deux cibles tombent dans la même entrée. */
    mqttbeCmdEssai($alpha, 'Partagée A', array('topic' => 'essai/commun'));
    mqttbeCmdEssai($beta, 'Partagée B', array('topic' => 'essai/commun'));
    $premiere = mqttbeRouting::build();
    MqttbeFauxDb::$ordreInstable = true;
    $seconde = mqttbeRouting::build();
    MqttbeFauxDb::$ordreInstable = false;
    $fautes = array();
    if (mqttbeRouting::fingerprint($premiere) !== mqttbeRouting::fingerprint($seconde)) {
        $fautes[] = "l'ordre de lecture de la base change l'empreinte de la table";
        $fautes[] = 'ordre 1 : ' . implode(', ', mqttbeTopicsDe($premiere));
        $fautes[] = 'ordre 2 : ' . implode(', ', mqttbeTopicsDe($seconde));
    }
    if (json_encode($premiere) !== json_encode($seconde)) {
        $entreeA = mqttbeEntree($premiere, 'essai/commun');
        $entreeB = mqttbeEntree($seconde, 'essai/commun');
        $fautes[] = 'cibles de essai/commun : ' . json_encode($entreeA['targets'])
                  . ' puis ' . json_encode($entreeB['targets']);
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "l'empreinte cesse de dire quoi que ce soit : le plugin envoie une table complète à "
        . "chaque enregistrement, ou n'en envoie plus du tout.");

    /* ------------------------------------------------------------------ 3 ---
     * Une correspondance mal saisie est un cas normal : c'est le seul réglage
     * du contrat qui soit un langage. Elle doit être ignorée, journalisée, et
     * la commande doit continuer d'être routée sans elle — surtout, les deux
     * cents autres commandes de l'installation ne doivent rien perdre. */
    $titre = 'correspondance invalide : ignorée, la table reste entière';
    $cible = mqttbeCible($table, $fautive->getId());
    $fautes = array();
    if ($cible === null) {
        $fautes[] = "la commande fautive n'est plus routée du tout";
    } elseif (isset($cible['transform']) && is_array($cible['transform'])
              && isset($cible['transform']['map'])) {
        $fautes[] = 'la correspondance illisible a été transmise au démon';
    }
    if (mqttbeCible($table, $etat->getId()) === null) {
        $fautes[] = 'la commande voisine a disparu de la table';
    }
    if (!MqttbeFauxCoeur::journalContient('JSON invalide', 'warning')) {
        $fautes[] = "rien au journal : l'utilisateur ne saura jamais quelle saisie est fautive";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "une accolade oubliée priverait de routage tout le parc, et le symptôme — plus rien "
        . 'ne remonte — ne désigne pas sa cause.');

    /* ------------------------------------------------------------------ 4 ---
     * Une correspondance valable arrive au démon en chaînes des deux côtés : la
     * charge utile MQTT est toujours du texte, et comparer « on » à l'entier 1
     * ne donnerait jamais rien. */
    $titre = 'correspondance valable : des chaînes des deux côtés';
    $cible = mqttbeCible($table, $etat->getId());
    $fautes = array();
    if ($cible === null || !isset($cible['transform']['map'])) {
        $fautes[] = 'la correspondance a été perdue';
    } else {
        foreach ($cible['transform']['map'] as $cle => $valeur) {
            if (!is_string($valeur)) {
                $fautes[] = 'la valeur de « ' . $cle . ' » n\'est pas une chaîne : '
                          . var_export($valeur, true);
            }
        }
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'une correspondance comparée à autre chose que du texte ne se déclenche jamais, et '
        . 'la commande reste à sa valeur brute.');

    /* ------------------------------------------------------------------ 5 ---
     * Un champ laissé vide n'est pas un zéro. Une échelle vide prise pour un
     * zéro ramène toutes les valeurs à zéro ; un arrondi vide pris pour un zéro
     * transforme 21,4 °C en 21. Et « 0 décimale », lui, est bien une consigne :
     * les deux cas doivent être distingués. */
    $titre = 'champs vides traités comme absents, zéro comme une consigne';
    $fautes = array();
    $cible = mqttbeCible($table, $tension->getId());
    if ($cible === null) {
        $fautes[] = 'la commande à champs vides n\'est pas routée';
    } else {
        $transform = $cible['transform'];
        $transform = is_object($transform) ? (array) $transform : $transform;
        if (!empty($transform)) {
            $fautes[] = 'des transformations sont nées de champs vides : ' . json_encode($transform);
        }
    }
    $cible = mqttbeCible($table, $arrondi->getId());
    if ($cible === null) {
        $fautes[] = 'la commande arrondie à 0 décimale n\'est pas routée';
    } else {
        $transform = (array) $cible['transform'];
        if (!array_key_exists('round', $transform)) {
            $fautes[] = "« 0 décimale » a été pris pour une absence de consigne";
        } elseif ($transform['round'] !== 0) {
            $fautes[] = 'round = ' . var_export($transform['round'], true) . ' au lieu de 0';
        }
    }
    $cible = mqttbeCible($table, $energie->getId());
    if ($cible === null || !isset($cible['transform']['scale'])) {
        $fautes[] = "l'échelle 0.001 n'a pas été transmise";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "une échelle vide prise pour un zéro donne un graphique parfaitement plat, et "
        . 'découvrir pourquoi coûte une soirée.');

    /* ------------------------------------------------------------------ 6 ---
     * La politique de répétition est toujours présente et toujours complète :
     * le démon n'a ainsi aucun défaut à connaître, et changer l'un d'eux ne
     * demande pas de le redéployer. */
    $titre = 'répétition toujours complète, défauts du contrat appliqués';
    $fautes = array();
    $cible = mqttbeCible($table, $puissance->getId());
    if ($cible === null) {
        $fautes[] = 'commande introuvable dans la table';
    } else {
        $attendu = array('mode' => 'onchange', 'keepalive' => 300, 'minInterval' => 0);
        foreach ($attendu as $cle => $valeur) {
            if (!isset($cible['repeat'][$cle]) || $cible['repeat'][$cle] !== $valeur) {
                $fautes[] = 'repeat.' . $cle . ' = '
                          . (isset($cible['repeat'][$cle]) ? var_export($cible['repeat'][$cle], true) : 'absent')
                          . ' au lieu de ' . var_export($valeur, true);
            }
        }
    }
    /* Le drapeau des événements voyage jusqu'au démon, et il vaut zéro partout
     * ailleurs : des milliers de commandes d'état comptent dessus pour que le
     * broker leur rejoue bien leur dernière valeur au démarrage. */
    $cible = mqttbeCible($table, $evenement->getId());
    if ($cible === null || !isset($cible['repeat']['ignore_retained'])
        || $cible['repeat']['ignore_retained'] !== 1) {
        $fautes[] = 'la commande d\'événement ne dit pas au démon d\'ignorer ce que le broker '
            . 'rejoue : au démarrage, elle rejouera le dernier appui reçu.';
    }
    $cible = mqttbeCible($table, $puissance->getId());
    if ($cible !== null && isset($cible['repeat']['ignore_retained'])
        && $cible['repeat']['ignore_retained'] !== 0) {
        $fautes[] = 'une commande d\'état refuse les messages retenus : elle resterait vide '
            . 'jusqu\'à la prochaine publication de l\'appareil, qui peut ne jamais venir.';
    }
    $cible = mqttbeCible($table, $arrondi->getId());
    if ($cible !== null && ($cible['repeat']['mode'] !== 'always' || $cible['repeat']['keepalive'] !== 60)) {
        $fautes[] = 'les réglages de la commande ne sont pas repris : '
                  . json_encode($cible['repeat']);
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "sans keepalive, le champ « dernière communication » d'un capteur stable vieillit "
        . "indéfiniment et l'équipement passe en timeout sans raison.");

    /* ------------------------------------------------------------------ 7 ---
     * Ce qui ne doit pas figurer dans la table : une commande sans topic (une
     * information calculée, ou une saisie en cours), une commande d'action (le
     * démon publie, il n'écoute pas), et tout un équipement désactivé — couper
     * un équipement doit couper son trafic, sinon le désactiver ne sert à rien. */
    $titre = 'sans topic, actions et équipements désactivés : exclus';
    $fautes = array();
    if (mqttbeCible($table, $calculee->getId()) !== null) {
        $fautes[] = 'une commande sans topic a produit une entrée';
    }
    if (mqttbeCible($table, $action->getId()) !== null) {
        $fautes[] = "une commande d'action a été mise en écoute";
    }
    if (mqttbeCible($table, $muette->getId()) !== null) {
        $fautes[] = "les commandes d'un équipement désactivé sont toujours routées";
    }
    if (mqttbeEntree($table, 'essai/zoulou') !== null) {
        $fautes[] = "le démon resterait abonné au topic d'un équipement désactivé";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "le démon déduit ses abonnements de cette table : ce qui y figure à tort est un "
        . 'abonnement de trop, et un équipement désactivé qui continue de réveiller Jeedom.');

    /* ------------------------------------------------------------------ 8 ---
     * Un « enable » posé sur la commande elle-même coupe cette valeur sans
     * obliger à supprimer la commande — et sans emporter ses voisines. */
    $titre = 'commande coupée à la main : exclue, ses voisines restent';
    $tension->setConfiguration('enable', 0);
    $tension->save();
    $apres = mqttbeRouting::build();
    $fautes = array();
    if (mqttbeCible($apres, $tension->getId()) !== null) {
        $fautes[] = 'la commande coupée est toujours routée';
    }
    if (mqttbeCible($apres, $puissance->getId()) === null) {
        $fautes[] = 'la commande voisine du même topic a disparu';
    }
    $tension->setConfiguration('enable', 1);
    $tension->save();
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "couper une seule valeur bavarde obligerait sinon à supprimer la commande, avec son "
        . 'historique et les scénarios qui la citent.');

    /* ------------------------------------------------------------------ 9 ---
     * La forme du JSON compte autant que son contenu : un démon qui décode en
     * objets doit voir un objet là où il attend un objet. Un tableau PHP vide
     * sortirait en « [] » et non en « {} ». */
    $titre = 'forme du JSON : transform reste un objet même vide';
    $json = json_encode(mqttbeRouting::build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $fautes = array();
    if (strpos($json, '"transform":[]') !== false) {
        $fautes[] = 'une transformation vide sort en tableau et non en objet';
    }
    if (strpos($json, '"transform":{}') === false) {
        $fautes[] = 'aucune transformation vide dans la table : contrôle sans objet';
    }
    /* Un topic qui ressemble à un nombre : PHP en ferait une clé entière, et le
     * topic sortirait du JSON sans ses guillemets. */
    mqttbeCmdEssai($beta, 'Numérique', array('topic' => '0'));
    $json = json_encode(mqttbeRouting::build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (strpos($json, '"topic":"0"') === false) {
        $fautes[] = 'un topic numérique ne sort pas en chaîne : ' . $json;
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "le démon décode la table une fois pour toutes : une forme qui change d'un envoi à "
        . "l'autre le fait tomber sur un type inattendu, loin de la cause.");

    /* ----------------------------------------------------------------- 10 ---
     * L'empreinte évite l'envoi inutile. Enregistrer un équipement ne change le
     * plus souvent rien au routage — un nom, une icône, une catégorie — et le
     * démon n'a aucune raison de réindexer son arbre de topics pour cela. */
    $titre = 'empreinte : aucun envoi quand rien ne change';
    MqttbeFauxCoeur::reinitialise();
    $eqLogic = mqttbeEqLogicEssai('Empreinte');
    mqttbeCmdEssai($eqLogic, 'Valeur', array('topic' => 'essai/valeur'));
    $fautes = array();
    if (mqttbeRouting::push() !== true) {
        $fautes[] = 'le premier envoi n\'a pas eu lieu';
    }
    $envois = count(mqttbeDaemon::$envois);
    if (mqttbeRouting::push() !== false) {
        $fautes[] = 'une table inchangée a été renvoyée';
    }
    if (count(mqttbeDaemon::$envois) !== $envois) {
        $fautes[] = 'le démon a reçu une table qu\'il avait déjà';
    }
    if (mqttbeRouting::push(true) !== true) {
        $fautes[] = 'l\'envoi forcé n\'a pas eu lieu : un démon qui redémarre resterait sans table';
    }
    mqttbeCmdEssai($eqLogic, 'Autre valeur', array('topic' => 'essai/autre'));
    if (mqttbeRouting::push() !== true) {
        $fautes[] = 'une table réellement modifiée n\'est pas partie';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'sans ce garde-fou, chaque enregistrement fait réindexer au démon tout son arbre de '
        . 'topics ; avec un garde-fou trop zélé, une modification réelle ne part jamais.');

    /* ----------------------------------------------------------------- 11 ---
     * Un envoi qui échoue — le démon redémarrait — ne doit pas passer pour
     * appliqué. Sinon l'appel suivant trouve la table « identique », s'abstient,
     * et le routage reste mort jusqu'à ce que quelqu'un modifie un équipement. */
    $titre = 'envoi manqué : la table repart au coup suivant';
    MqttbeFauxCoeur::reinitialise();
    $eqLogic = mqttbeEqLogicEssai('Manque');
    mqttbeCmdEssai($eqLogic, 'Valeur', array('topic' => 'essai/manque'));
    mqttbeDaemon::$reponse = false;
    $fautes = array();
    if (mqttbeRouting::push() !== false) {
        $fautes[] = 'un envoi refusé par le démon est compté comme réussi';
    }
    mqttbeDaemon::$reponse = true;
    if (mqttbeRouting::push() !== true) {
        $fautes[] = "la table n'est pas renvoyée alors que le démon ne l'a jamais reçue";
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "le routage reste mort jusqu'à ce que quelqu'un modifie un équipement par hasard, et "
        . "rien dans l'interface ne dit que le démon n'a pas de table.");

    /* ----------------------------------------------------------------- 12 ---
     * Le numéro de version est strictement croissant : le démon ignore toute
     * table plus ancienne que celle qu'il applique. Le cache est vidé à chaque
     * mise à jour de Jeedom ; sans plancher sur l'horodatage, la suite
     * repartirait de 1 et le démon rejetterait silencieusement tout. */
    $titre = 'version croissante, même après la perte du cache';
    MqttbeFauxCoeur::reinitialise();
    $eqLogic = mqttbeEqLogicEssai('Version');
    /* Cinq envois d'affilée, donc dans la même seconde : c'est ce que produit
     * une rafale de découverte, un envoi par requête. Le compteur passe alors
     * DEVANT l'horloge, et le plancher time() ne le rattrapera pas avant
     * plusieurs secondes. */
    $versions = array();
    for ($i = 1; $i <= 5; $i++) {
        mqttbeCmdEssai($eqLogic, 'Valeur ' . $i, array('topic' => 'essai/version/' . $i));
        mqttbeRouting::push();
        $versions[] = mqttbeRouting::version();
    }
    $fautes = array();
    for ($i = 1; $i < count($versions); $i++) {
        if ($versions[$i] <= $versions[$i - 1]) {
            $fautes[] = 'version ' . $versions[$i] . ' après ' . $versions[$i - 1]
                      . ' : le démon refuse toute version inférieure ou égale';
        }
    }
    if ($versions[0] < time() - 60) {
        $fautes[] = "la version ne prend pas l'horodatage pour plancher : " . $versions[0];
    }
    $appliquee = end($versions);

    /* Le cache disparaît — mise à jour de Jeedom, nettoyage du cache fichier,
     * changement de moteur — pendant que le démon, lui, continue de tourner
     * avec sa table. Son battement dit quelle version il applique : c'est le
     * seul plancher qui reste, et sans lui la version repart en arrière de
     * plusieurs unités, le démon ignorant alors silencieusement tout ce que
     * Jeedom lui envoie. */
    cache::reinitialise();
    cache::set('mqttbe::daemonRouting', $appliquee);
    mqttbeRouting::push(true);
    $reprise = mqttbeRouting::version();
    if ($reprise <= $appliquee) {
        $fautes[] = 'après la perte du cache, la version repart à ' . $reprise
                  . ' alors que le démon en applique ' . $appliquee;
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "time() ne progresse qu'une fois par seconde : plusieurs envois dans la même seconde "
        . "mettent le compteur devant l'horloge, et une version qui repart en arrière fait "
        . "rejeter toutes les tables suivantes, sans un mot, jusqu'au redémarrage du démon.");

    /* ----------------------------------------------------------------- 13 ---
     * Un seul envoi par requête. Enregistrer un équipement écrit l'eqLogic puis
     * chacune de ses commandes : sans le report en fin de requête, un équipement
     * de quinze commandes enverrait seize tables, dont quinze aussitôt périmées
     * — et calculées trop tôt, donc fausses. */
    $titre = 'enregistrements en rafale : rien n\'est envoyé pendant la requête';
    MqttbeFauxCoeur::reinitialise();
    $eqLogic = mqttbeEqLogicEssai('Rafale');
    for ($i = 1; $i <= 5; $i++) {
        mqttbeCmdEssai($eqLogic, 'Valeur ' . $i, array('topic' => 'essai/rafale/' . $i));
    }
    $fautes = array();
    if (count(mqttbeDaemon::$envois) !== 0) {
        $fautes[] = count(mqttbeDaemon::$envois) . ' table(s) envoyée(s) au fil des '
                  . 'enregistrements, dont la première ignore les commandes suivantes';
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        'une table calculée avant la fin de la requête ignore la commande qu\'on vient de '
        . 'créer, et le démon reçoit autant de tables périmées que de commandes.');

    /* ----------------------------------------------------------------- 14 ---
     * L'autre sens du chemin : mqttbeCmd::execute() publie. Les clés de
     * configuration employées ici (topic, payload, qos, retain) sont celles du
     * contrat, et les remplacements #slider# et #color# sont l'usage Jeedom. */
    $titre = 'commande d\'action : publication conforme au contrat';
    MqttbeFauxCoeur::reinitialise();
    $eqLogic = mqttbeEqLogicEssai('Action');
    $curseur = mqttbeCmdEssai($eqLogic, 'Luminosité', array(
        'topic' => 'essai/light/set', 'payload' => '{"brightness":#slider#}',
        'qos' => 2, 'retain' => 1,
    ), 'action', 'slider');
    $sansTopic = mqttbeCmdEssai($eqLogic, 'Sans topic', array('payload' => 'on'), 'action', 'other');
    $fautes = array();
    $curseur->execute(array('slider' => 42));
    $publications = mqttbeDaemon::$publications;
    if (count($publications) !== 1) {
        $fautes[] = count($publications) . ' publication(s) au lieu d\'une';
    } else {
        $publication = $publications[0];
        if ($publication['topic'] !== 'essai/light/set') {
            $fautes[] = 'topic publié : ' . $publication['topic'];
        }
        if ($publication['payload'] !== '{"brightness":42}') {
            $fautes[] = 'charge utile publiée : ' . $publication['payload'];
        }
        if ((int) $publication['qos'] !== 2 || $publication['retain'] !== true) {
            $fautes[] = 'qos/retain perdus : ' . json_encode($publication);
        }
    }
    try {
        $sansTopic->execute();
        $fautes[] = 'une action sans topic a été exécutée sans rien dire';
    } catch (Throwable $e) {
        /* Attendu : l'utilisateur doit savoir que la commande est incomplète. */
    }
    $resultats[] = mqttbeVerdict($titre, $fautes,
        "une action qui publie au mauvais endroit, ou qui perd le curseur, donne un bouton "
        . 'qui ne fait rien — et rien au journal.');

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Table de routage', mqttbeControlesRoutage());
}
