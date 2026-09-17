<?php
/* L'adapter Shelly Gen1, confronté à un parc réel de 22 appareils.
 *
 *   php tests/check-shelly-gen1.php
 *
 * Ni Jeedom, ni base de données, ni broker : les captures anonymisées de
 * tests/fixtures/shelly/gen1 sont rejouées dans un contexte de papier, et l'on
 * regarde ce que l'adapter produit, appareil par appareil.
 *
 * Ce que ces contrôles attrapent ne se voit ni à la relecture, ni au « php -l »,
 * ni même sur un broker d'essai avec un seul appareil :
 *
 *   - un SHSW-1 n'a pas de wattmètre, mais publie quand même un `meters[]` ;
 *     compter les entrées donnerait à la moitié du parc une commande
 *     « Puissance » éternellement nulle, et le plugin passerait pour cassé ;
 *   - les énergies de la génération 1 sont en watt-minutes (relais) ou en
 *     watt-heures (compteurs d'énergie), jamais en kWh ; sans facteur d'échelle,
 *     la commande affiche 87759 kWh et personne ne sait d'où ça sort ;
 *   - deux commandes de même nom sur un équipement font échouer
 *     l'enregistrement de tout l'équipement (cmd (eqLogic_id, name) est unique) ;
 *   - un modèle réémis à l'identique réécrit la base à chaque redémarrage du
 *     démon, les messages de découverte étant rejoués.
 *
 * Les captures sont anonymisées (SSID, IP en 192.0.2.x, MAC et identifiants
 * d'appareil remplacés, numéros de série des sondes aussi) : le dépôt part sur
 * GitHub, et une capture brute y publierait le réseau d'une maison. */

require_once __DIR__ . '/outils.php';

function mqttbeCheminAdapterGen1() {
    return mqttbeRacine() . '/resources/mqttbed/discovery/adapters/ShellyGen1.php';
}

function mqttbeCheminFixturesGen1() {
    return mqttbeRacine() . '/tests/fixtures/shelly/gen1';
}

/* Les quatre modèles du parc, et les deux appareils qui portent les cas
 * particuliers : les sondes externes et l'entrée à appui long. */
function mqttbeReperesGen1() {
    return array(
        'SHSW-1'    => 'shelly1-A8B0C1000001',
        'SHSW-1+2s' => 'shelly1-A8B0C1000006',
        'SHSW-PM'   => 'shelly1pm-A8B0C1000012',
        'SHEM'      => 'shellyem-A8B0C1000013',
        'SHPLG-S'   => 'shellyplug-s-A8B0C1000014',
    );
}

/* --------------------------------------------------------------------------
 * Contexte de papier
 *
 * Le contrat ne donne à l'adapter que huit méthodes de contexte : publish,
 * subscribe, remember, recall, forget, emit, log, now — auxquelles le moteur
 * ajoute rescan(), la relance demandée par l'utilisateur. Les voici, et rien de
 * plus : un contexte d'essai plus riche que le vrai laisserait passer un
 * adapter qui s'appuie sur ce que le moteur ne lui donnera pas.
 *
 * L'horloge est fausse et se pousse à la main : les expirations se mesurent en
 * dizaines de secondes, et un contrôle ne va pas les attendre.
 * ------------------------------------------------------------------------ */
class MqttbeContexteEssaiGen1 {

    public $publications = array();
    public $abonnements  = array();
    public $modeles      = array();
    public $journal      = array();

    private $memoire = array();
    private $horloge = 1700000000.0;

    public function publish($_topic, $_payload) {
        $this->publications[] = array('topic' => $_topic, 'payload' => $_payload);
    }

    public function subscribe($_topic) {
        $this->abonnements[] = $_topic;
    }

    public function remember($_cle, $_donnees) {
        $this->memoire[$_cle] = $_donnees;
    }

    public function recall($_cle) {
        return array_key_exists($_cle, $this->memoire) ? $this->memoire[$_cle] : null;
    }

    public function forget($_cle) {
        unset($this->memoire[$_cle]);
    }

    public function emit($_modele) {
        $this->modeles[] = $_modele;
    }

    public function log($_niveau, $_message) {
        $this->journal[] = $_niveau . ' : ' . $_message;
    }

    public function now() {
        return $this->horloge;
    }

    /* Le moteur présente la relance de l'utilisateur ainsi, vraie le temps d'un
     * seul onTick : un adapter qui la lirait deux fois redemanderait deux fois. */
    public $relance = false;

    public function rescan() {
        $demandee = $this->relance;
        $this->relance = false;
        return $demandee;
    }

    /* -- commodités du contrôle, jamais vues par l'adapter ----------------- */

    public function avance($_secondes) {
        $this->horloge += $_secondes;
    }

    public function demandesAnnonce() {
        $compte = 0;
        foreach ($this->publications as $publication) {
            if ($publication['topic'] === 'shellies/command' && $publication['payload'] === 'announce') {
                $compte++;
            }
        }
        return $compte;
    }
}

/* --------------------------------------------------------------------------
 * Rejeu des captures
 * ------------------------------------------------------------------------ */

function mqttbeLitJsonGen1($_nom) {
    $chemin = mqttbeCheminFixturesGen1() . '/' . $_nom;
    if (!is_readable($chemin)) {
        return null;
    }
    $decode = json_decode(file_get_contents($chemin), true);
    return is_array($decode) ? $decode : null;
}

/*
 * Rejoue le parc : une demande d'annonce au démarrage, puis pour chaque
 * appareil son annonce et son info, dans l'ordre où le broker les délivre.
 *
 * Un appareil sur deux reçoit son info AVANT son annonce : c'est ce qui se
 * passe réellement au démarrage du démon, les messages retenus arrivant dans
 * l'ordre du broker et non dans celui que l'on souhaiterait. Un adapter qui
 * n'accepterait que l'ordre annonce-puis-info perdrait la moitié du parc.
 */
function mqttbeRejoueParcGen1($_adapter, $_ctx, $_annonces, $_infos) {
    $_adapter->onTick($_ctx);
    $rang = 0;
    foreach ($_annonces as $identifiant => $annonce) {
        $rang++;
        $messageAnnonce = array('shellies/announce', json_encode($annonce));
        $messageInfo    = array('shellies/' . $identifiant . '/info',
                                json_encode(isset($_infos[$identifiant]) ? $_infos[$identifiant] : array()));
        $ordre = ($rang % 2 === 0)
            ? array($messageInfo, $messageAnnonce)
            : array($messageAnnonce, $messageInfo);
        foreach ($ordre as $message) {
            $_adapter->onMessage($message[0], $message[1], true, $_ctx);
        }
    }
    $_adapter->onTick($_ctx);
}

function mqttbeModelesParUidGen1($_ctx) {
    $parUid = array();
    foreach ($_ctx->modeles as $modele) {
        $parUid[$modele->uid()] = $modele;
    }
    return $parUid;
}

function mqttbeUidGen1($_identifiant) {
    $morceaux = explode('-', $_identifiant);
    return 'shelly:' . strtolower(end($morceaux));
}

function mqttbeModeleGen1($_parUid, $_identifiant) {
    $uid = mqttbeUidGen1($_identifiant);
    return isset($_parUid[$uid]) ? $_parUid[$uid] : null;
}

/* Clés des canaux d'un modèle, triées : comparer des listes triées rend le
 * message d'échec lisible et l'ordre d'ajout sans importance. */
function mqttbeClesGen1($_modele) {
    $cles = $_modele->channelKeys();
    sort($cles, SORT_STRING);
    return $cles;
}

function mqttbeManqueEnTropGen1($_attendu, $_obtenu) {
    sort($_attendu, SORT_STRING);
    $manque = array_values(array_diff($_attendu, $_obtenu));
    $enTrop = array_values(array_diff($_obtenu, $_attendu));
    if (empty($manque) && empty($enTrop)) {
        return '';
    }
    $texte = array();
    if (!empty($manque)) {
        $texte[] = 'manque : ' . implode(', ', $manque);
    }
    if (!empty($enTrop)) {
        $texte[] = 'en trop : ' . implode(', ', $enTrop);
    }
    return implode(' ; ', $texte);
}

/* La transformation d'un canal, où qu'elle soit rangée : le contrat la place
 * dans `value`, la fabrique la cherche aussi à la racine et dans `source`. */
function mqttbeTransformGen1($_canal) {
    if ($_canal === null) {
        return array();
    }
    $valeur = $_canal->value();
    if (isset($valeur['transform']) && is_array($valeur['transform'])) {
        return $valeur['transform'];
    }
    return array();
}

function mqttbeRepeatGen1($_canal) {
    if ($_canal === null) {
        return array();
    }
    $valeur = $_canal->value();
    return (isset($valeur['repeat']) && is_array($valeur['repeat'])) ? $valeur['repeat'] : array();
}

/* --------------------------------------------------------------------------
 * Les contrôles
 * ------------------------------------------------------------------------ */

function mqttbeControlesShellyGen1() {
    $resultats = array();

    $adapterPresent = is_readable(mqttbeCheminAdapterGen1());
    $annonces = mqttbeLitJsonGen1('annonces.json');
    $infos    = mqttbeLitJsonGen1('info.json');
    $arbre    = mqttbeLitJsonGen1('arbre.json');

    if (!$adapterPresent || $annonces === null || $infos === null || $arbre === null) {
        $raison = !$adapterPresent
            ? 'resources/mqttbed/discovery/adapters/ShellyGen1.php n\'existe pas encore.'
            : 'les captures de tests/fixtures/shelly/gen1 sont absentes ou illisibles.';
        return array(mqttbeIndecis('adapter Shelly Gen1', $raison));
    }

    require_once mqttbeRacine() . '/resources/mqttbed/discovery/DeviceModel.php';
    require_once mqttbeCheminAdapterGen1();

    /* Le vocabulaire armé : sans lui, MqttbeChannel::validate() ne peut pas
     * dire qu'une capacité est inventée, et c'est précisément ce qu'on veut
     * savoir ici. */
    $vocabulaire = mqttbeRacine() . '/core/config/capabilities.json';
    $vocabulaireCharge = MqttbeChannel::loadCapabilities($vocabulaire);

    $catalogue = mqttbeRacine() . '/core/config/catalog/shelly-gen1.json';
    $ctx = new MqttbeContexteEssaiGen1();
    $adapter = new MqttbeShellyGen1($catalogue);
    mqttbeRejoueParcGen1($adapter, $ctx, $annonces, $infos);
    $parUid = mqttbeModelesParUidGen1($ctx);
    $reperes = mqttbeReperesGen1();

    /* ------------------------------------------------------------------ 1 ---
     * L'adapter tel que le moteur le voit. */
    $titre = 'interface d\'adapter respectée';
    $manquantes = array();
    foreach (array('id', 'priority', 'subscriptions', 'onMessage', 'onTick') as $methode) {
        if (!method_exists($adapter, $methode)) {
            $manquantes[] = $methode . '()';
        }
    }
    if (!empty($manquantes)) {
        $resultats[] = mqttbeEchec($titre, 'le moteur appelle ces méthodes sur chaque adapter : '
            . implode(', ', $manquantes) . ' manque(nt).');
    } elseif ($adapter->id() !== 'shelly.gen1') {
        $resultats[] = mqttbeEchec($titre, 'id() rend « ' . $adapter->id()
            . ' », attendu « shelly.gen1 » : c\'est ce nom que Jeedom envoie dans la liste '
            . 'des adapters à activer.');
    } elseif ((int) $adapter->priority() !== 100) {
        $resultats[] = mqttbeEchec($titre, 'priority() rend ' . $adapter->priority()
            . ', attendu 100 (découverte native du constructeur).');
    } else {
        $resultats[] = mqttbeOk($titre);
    }

    /* ------------------------------------------------------------------ 2 ---
     * Découverte active. Sans demande d'annonce, un parc déjà connecté reste
     * invisible : l'annonce initiale de chaque appareil est passée bien avant
     * que le démon n'existe, et elle n'est pas retenue. */
    $titre = 'annonce provoquée au démarrage';
    $abonnements = $adapter->subscriptions();
    $attendus = array('shellies/announce', 'shellies/+/announce', 'shellies/+/info');
    $ecart = mqttbeManqueEnTropGen1($attendus, $abonnements);
    if ($ctx->demandesAnnonce() < 1) {
        $resultats[] = mqttbeEchec($titre,
            'aucun « announce » publié sur shellies/command : un parc déjà connecté ne se '
            . 'présentera jamais, et la découverte ne verra que les appareils qui redémarrent.');
    } elseif ($ecart !== '') {
        $resultats[] = mqttbeEchec($titre, 'abonnements de découverte — ' . $ecart);
    } else {
        $resultats[] = mqttbeOk($titre);
    }

    /* ------------------------------------------------------------------ 3 ---
     * Le parc entier, sans intervention. */
    $titre = 'les 22 appareils du parc découverts';
    $attendu = count($annonces);
    if (count($parUid) !== $attendu) {
        $vus = array();
        foreach ($parUid as $uid => $modele) {
            $vus[] = $uid;
        }
        $resultats[] = mqttbeEchec($titre, count($parUid) . ' modèle(s) émis pour ' . $attendu
            . ' appareils annoncés. Un appareil non découvert n\'apparaît nulle part dans '
            . "Jeedom, et rien ne dit à l'utilisateur qu'il manque.\n" . implode(', ', $vus));
    } else {
        $incertains = array();
        foreach ($parUid as $uid => $modele) {
            if ($modele->confidence() !== 'certain') {
                $incertains[] = $uid . ' (' . $modele->confidence() . ')';
            }
        }
        $resultats[] = empty($incertains) ? mqttbeOk($titre) : mqttbeEchec($titre,
            'annonce et info connus, la confiance doit être « certain » : '
            . implode(', ', $incertains));
    }

    /* ------------------------------------------------------------------ 4 ---
     * uid : la forme que l'adapter Gen2+ emploiera aussi. Un uid qui change
     * d'une découverte à l'autre crée un équipement neuf à chaque fois. */
    $titre = 'uid « shelly:<mac> », stable et sans doublon';
    $fautes = array();
    foreach ($annonces as $identifiant => $annonce) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele === null) {
            $fautes[] = $identifiant . ' : aucun modèle sous ' . mqttbeUidGen1($identifiant);
            continue;
        }
        $attenduUid = 'shelly:' . strtolower($annonce['mac']);
        if ($modele->uid() !== $attenduUid) {
            $fautes[] = $identifiant . ' : uid « ' . $modele->uid() . ' », attendu « ' . $attenduUid . ' »';
        }
        $alias = $modele->aliases();
        if (!in_array('mac:' . strtolower($annonce['mac']), $alias, true)
            || !in_array('topic:shellies/' . $identifiant, $alias, true)) {
            $fautes[] = $identifiant . ' : alias incomplets (' . implode(', ', $alias) . ')';
        }
    }
    if (count($parUid) !== count($annonces) && empty($fautes)) {
        $fautes[] = 'deux appareils partagent le même uid : l\'un écraserait l\'autre en base.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "l'uid est le logicalId de l'équipement : s'il bouge, chaque découverte crée un doublon.\n"
        . implode("\n", array_slice($fautes, 0, 6)));

    /* ------------------------------------------------------------------ 5 ---
     * Le piège du meters[] sans wattmètre. */
    $titre = 'SHSW-1 sans wattmètre : aucune commande de puissance';
    $fautes = array();
    foreach ($annonces as $identifiant => $annonce) {
        if ($annonce['model'] !== 'SHSW-1') {
            continue;
        }
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele === null) {
            continue;
        }
        foreach ($modele->channels() as $canal) {
            if (in_array($canal->capability(), array('power.active', 'energy.total'), true)) {
                $fautes[] = $identifiant . ' : ' . $canal->key() . ' (' . $canal->capability() . ')';
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "un SHSW-1 publie « meters:[{\"power\":0,\"is_valid\":true}] » sans en avoir un seul : "
        . "compter les entrées donne à la moitié du parc une puissance éternellement nulle, et "
        . "l'utilisateur en conclut que le plugin est cassé.\n" . implode("\n", $fautes));

    /* ------------------------------------------------------------------ 6 ---
     * Le même piège, vu de l'autre côté : un vrai compteur doit donner une
     * commande. */
    $titre = 'SHSW-PM avec wattmètre : puissance et consommation';
    $modele = mqttbeModeleGen1($parUid, $reperes['SHSW-PM']);
    $attendu = array('online', 'relay.0.state', 'relay.0.on', 'relay.0.off', 'relay.0.toggle',
                     'relay.0.power', 'relay.0.energy', 'input.0', 'input.0.event',
                     'input.0.longpush', 'temperature');
    if ($modele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ' . $reperes['SHSW-PM'] . '.');
    } else {
        $ecart = mqttbeManqueEnTropGen1($attendu, mqttbeClesGen1($modele));
        $puissance = $modele->channel('relay.0.power');
        if ($ecart !== '') {
            $resultats[] = mqttbeEchec($titre, 'canaux du SHSW-PM — ' . $ecart);
        } elseif ($puissance->capability() !== 'power.active' || $puissance->unit() !== 'W'
                  || $puissance->sourceTopic() !== 'shellies/' . $reperes['SHSW-PM'] . '/relay/0/power') {
            $resultats[] = mqttbeEchec($titre, 'canal de puissance mal câblé : '
                . $puissance->capability() . ' / ' . $puissance->unit() . ' / ' . $puissance->sourceTopic());
        } else {
            $resultats[] = mqttbeOk($titre);
        }
    }

    /* ------------------------------------------------------------------ 7 ---
     * Les watt-minutes. */
    $titre = 'énergie d\'un relais : watt-minutes vers kWh';
    $fautes = array();
    foreach (array($reperes['SHSW-PM'], $reperes['SHPLG-S']) as $identifiant) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        $canal = ($modele === null) ? null : $modele->channel('relay.0.energy');
        if ($canal === null) {
            $fautes[] = $identifiant . ' : pas de canal relay.0.energy';
            continue;
        }
        $transform = mqttbeTransformGen1($canal);
        $echelle = isset($transform['scale']) ? (float) $transform['scale'] : 1.0;
        $arrondi = isset($transform['round']) ? (int) $transform['round'] : -1;
        if (abs($echelle - (1 / 60000)) > 1e-12) {
            $fautes[] = $identifiant . ' : échelle ' . $echelle . ', attendu 1/60000';
        }
        if ($arrondi !== 3) {
            $fautes[] = $identifiant . ' : arrondi ' . $arrondi . ', attendu 3 décimales';
        }
        if ($canal->capability() !== 'energy.total' || $canal->unit() !== 'kWh') {
            $fautes[] = $identifiant . ' : ' . $canal->capability() . ' / ' . $canal->unit();
        }
    }
    /* La valeur relevée sur l'appareil, passée à la moulinette : 87759
     * watt-minutes font 1,463 kWh. Sans échelle, Jeedom afficherait « 87759 kWh »
     * — dix ans de consommation d'une maison, sur une prise. */
    $brut = 87759;
    $converti = round($brut * (1 / 60000), 3);
    if (abs($converti - 1.463) > 0.0005) {
        $fautes[] = 'conversion de contrôle fausse : ' . $brut . ' Wmin donne ' . $converti . ' kWh';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "relay/<i>/energy est en watt-minutes ; une commande energy.total est en kWh.\n"
        . implode("\n", $fautes));

    /* ------------------------------------------------------------------ 8 ---
     * Le SHEM : deux voies, la tension, les watt-heures, et la production. */
    $titre = 'SHEM : deux voies, énergies en watt-heures';
    $modele = mqttbeModeleGen1($parUid, $reperes['SHEM']);
    $attendu = array('online', 'relay.0.state', 'relay.0.on', 'relay.0.off', 'relay.0.toggle',
                     'emeter.0.power', 'emeter.0.voltage', 'emeter.0.energy', 'emeter.0.returned',
                     'emeter.0.pf', 'emeter.0.reactive',
                     'emeter.1.power', 'emeter.1.voltage', 'emeter.1.energy', 'emeter.1.returned',
                     'emeter.1.pf', 'emeter.1.reactive');
    if ($modele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ' . $reperes['SHEM'] . '.');
    } else {
        $fautes = array();
        $ecart = mqttbeManqueEnTropGen1($attendu, mqttbeClesGen1($modele));
        if ($ecart !== '') {
            $fautes[] = 'canaux — ' . $ecart;
        }
        foreach (array(0, 1) as $voie) {
            $energie = $modele->channel('emeter.' . $voie . '.energy');
            $transform = ($energie === null) ? array() : mqttbeTransformGen1($energie);
            $echelle = isset($transform['scale']) ? (float) $transform['scale'] : 1.0;
            if (abs($echelle - 0.001) > 1e-12) {
                $fautes[] = 'voie ' . $voie . ' : échelle ' . $echelle . ', attendu 1/1000 (watt-heures)';
            }
            if (!isset($transform['round']) || (int) $transform['round'] !== 3) {
                $fautes[] = 'voie ' . $voie . ' : arrondi absent ou différent de 3';
            }
            /* La reinjection n'est pas une consommation : etiquetee comme
             * telle, elle inverserait le bilan d'une installation solaire dans
             * la vue Maison. */
            $reinjection = $modele->channel('emeter.' . $voie . '.returned');
            if ($reinjection !== null && $reinjection->capability() !== 'energy.returned') {
                $fautes[] = 'la reinjection de la voie ' . $voie . ' est en '
                          . $reinjection->capability() . ' au lieu de energy.returned.';
            }

            $tension = $modele->channel('emeter.' . $voie . '.voltage');
            if ($tension === null || $tension->capability() !== 'power.voltage' || $tension->unit() !== 'V') {
                $fautes[] = 'voie ' . $voie . ' : tension absente ou mal typée';
            }
            /* La puissance d'une voie est signée : -385,81 W sur la capture,
             * c'est une installation solaire qui réinjecte. Une correspondance
             * ou un décalage sur ce canal effacerait exactement l'information
             * pour laquelle l'appareil a été posé. */
            $puissance = $modele->channel('emeter.' . $voie . '.power');
            $transform = ($puissance === null) ? array() : mqttbeTransformGen1($puissance);
            if ($puissance === null || $puissance->unit() !== 'W') {
                $fautes[] = 'voie ' . $voie . ' : puissance absente ou sans unité';
            } elseif (isset($transform['map']) || isset($transform['offset'])
                      || (isset($transform['scale']) && (float) $transform['scale'] < 0)) {
                $fautes[] = 'voie ' . $voie . ' : la puissance subit une transformation qui '
                    . 'dénaturerait une valeur négative';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "emeters[].total est en watt-heures, et non en watt-minutes comme meters[].total : "
            . "le même nom porte deux unités selon l'appareil.\n" . implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 9 ---
     * SHPLG-S : une prise n'a pas d'entrée physique. */
    $titre = 'SHPLG-S : jeu de canaux complet';
    $modele = mqttbeModeleGen1($parUid, $reperes['SHPLG-S']);
    $attendu = array('online', 'relay.0.state', 'relay.0.on', 'relay.0.off', 'relay.0.toggle',
                     'relay.0.power', 'relay.0.energy', 'temperature');
    if ($modele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ' . $reperes['SHPLG-S'] . '.');
    } else {
        $ecart = mqttbeManqueEnTropGen1($attendu, mqttbeClesGen1($modele));
        $resultats[] = ($ecart === '') ? mqttbeOk($titre) : mqttbeEchec($titre,
            'le info d\'un SHPLG-S ne porte aucun `inputs` : créer une entrée serait '
            . "inventer une commande qui ne remontera jamais rien.\n" . $ecart);
    }

    /* ----------------------------------------------------------------- 10 ---
     * SHSW-1 nu, et SHSW-1 à deux sondes : même modèle, deux appareils. */
    $titre = 'SHSW-1 : relais, entrée, et rien d\'inventé';
    $modele = mqttbeModeleGen1($parUid, $reperes['SHSW-1']);
    $attendu = array('online', 'relay.0.state', 'relay.0.on', 'relay.0.off', 'relay.0.toggle',
                     'input.0', 'input.0.event', 'input.0.longpush');
    if ($modele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ' . $reperes['SHSW-1'] . '.');
    } else {
        $ecart = mqttbeManqueEnTropGen1($attendu, mqttbeClesGen1($modele));
        $resultats[] = ($ecart === '') ? mqttbeOk($titre) : mqttbeEchec($titre, $ecart);
    }

    $titre = 'sondes externes : deux DS18B20 sur un même Shelly 1';
    $modele = mqttbeModeleGen1($parUid, $reperes['SHSW-1+2s']);
    if ($modele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ' . $reperes['SHSW-1+2s'] . '.');
    } else {
        $fautes = array();
        foreach (array(0, 1) as $rang) {
            $canal = $modele->channel('ext.temperature.' . $rang);
            if ($canal === null) {
                $fautes[] = 'ext.temperature.' . $rang . ' absent';
                continue;
            }
            if ($canal->capability() !== 'sensor.temperature' || $canal->unit() !== '°C') {
                $fautes[] = 'ext.temperature.' . $rang . ' : ' . $canal->capability()
                    . ' / ' . $canal->unit();
            }
            $topic = 'shellies/' . $reperes['SHSW-1+2s'] . '/ext_temperature/' . $rang;
            if ($canal->sourceTopic() !== $topic) {
                $fautes[] = 'ext.temperature.' . $rang . ' : topic ' . $canal->sourceTopic();
            }
        }
        if ($modele->channel('ext.temperature.2') !== null) {
            $fautes[] = 'une troisième sonde a été inventée';
        }
        /* Les noms sont numérotés même quand la sonde est seule : un appareil
         * qui en reçoit une deuxième ne doit pas voir la première changer de
         * nom sous l'utilisateur. */
        $seule = mqttbeModeleGen1($parUid, 'shelly1-A8B0C1000004');
        if ($seule !== null && $seule->channel('ext.temperature.0') !== null
            && $seule->channel('ext.temperature.0')->name() !== 'Température externe 1') {
            $fautes[] = 'sonde unique nommée « ' . $seule->channel('ext.temperature.0')->name()
                . ' » : la numérotation doit être la même qu\'avec deux sondes';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "le nombre de sondes se lit dans `ext_temperature`, pas dans le code du modèle : "
            . "deux SHSW-1 identiques n'ont pas les mêmes commandes.\n" . implode("\n", $fautes));
    }

    /* ----------------------------------------------------------------- 11 ---
     * Entrées : état, événement, appui long. */
    $titre = 'entrées : état, événement rejoué, appui long';
    $modele = mqttbeModeleGen1($parUid, $reperes['SHSW-PM']);
    if ($modele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ' . $reperes['SHSW-PM'] . '.');
    } else {
        $fautes = array();
        $base = 'shellies/' . $reperes['SHSW-PM'];
        $entree = $modele->channel('input.0');
        if ($entree === null || $entree->sourceTopic() !== $base . '/input/0') {
            $fautes[] = 'input.0 absent ou mal câblé';
        }
        $evenement = $modele->channel('input.0.event');
        if ($evenement === null) {
            $fautes[] = 'input.0.event absent';
        } else {
            $selecteur = $evenement->sourceSelector();
            if (!isset($selecteur['type']) || $selecteur['type'] !== 'json'
                || !isset($selecteur['path']) || $selecteur['path'] !== 'event') {
                $fautes[] = 'input.0.event : la charge utile est {"event":"S","event_cnt":1377}, '
                    . 'il faut le sélecteur json « event »';
            }
            $repeat = mqttbeRepeatGen1($evenement);
            if (!isset($repeat['mode']) || $repeat['mode'] !== 'always') {
                $fautes[] = 'input.0.event : répétition « '
                    . (isset($repeat['mode']) ? $repeat['mode'] : 'onchange')
                    . ' » — deux appuis courts de suite donnent deux fois « S », et le second '
                    . 'serait avalé : le scénario ne partirait pas';
            }
        }
        $long = $modele->channel('input.0.longpush');
        if ($long === null || $long->sourceTopic() !== $base . '/longpush/0') {
            $fautes[] = 'input.0.longpush absent ou mal câblé';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));
    }

    /* ----------------------------------------------------------------- 12 ---
     * Température interne : seulement là où l'appareil en publie une. */
    $titre = 'température interne là où elle existe, et nulle part ailleurs';
    $fautes = array();
    foreach ($annonces as $identifiant => $annonce) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele === null) {
            continue;
        }
        $publiee = isset($infos[$identifiant]) && array_key_exists('temperature', $infos[$identifiant]);
        $creee = $modele->channel('temperature') !== null;
        if ($publiee !== $creee) {
            $fautes[] = $identifiant . ' (' . $annonce['model'] . ') : info '
                . ($publiee ? 'avec' : 'sans') . ' température, canal '
                . ($creee ? 'créé' : 'absent');
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "une commande de température sur un appareil qui n'en publie pas reste vide à jamais.\n"
        . implode("\n", array_slice($fautes, 0, 6)));

    /* ----------------------------------------------------------------- 13 ---
     * Disponibilité. */
    $titre = 'disponibilité par « online »';
    $fautes = array();
    foreach ($annonces as $identifiant => $annonce) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele === null) {
            continue;
        }
        $disponibilite = $modele->availability();
        if (!isset($disponibilite['topic'])
            || $disponibilite['topic'] !== 'shellies/' . $identifiant . '/online'
            || $disponibilite['payload_on'] !== 'true' || $disponibilite['payload_off'] !== 'false') {
            $fautes[] = $identifiant . ' : disponibilité ' . json_encode($disponibilite);
        }
        $canal = $modele->channel('online');
        if ($canal === null || $canal->capability() !== 'connectivity.online') {
            $fautes[] = $identifiant . ' : canal « online » absent';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "shellies/<id>/online est retenu et publié par testament : c'est lui, et non l'absence "
        . "de messages, qui dit qu'un appareil a disparu.\n" . implode("\n", array_slice($fautes, 0, 6)));

    /* ----------------------------------------------------------------- 14 ---
     * Noms : lisibles, et uniques sur l'équipement comme entre équipements. */
    $titre = 'noms lisibles, et uniques sur chaque équipement';
    $fautes = array();
    $nomsEquipements = array();
    foreach ($annonces as $identifiant => $annonce) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele === null) {
            continue;
        }
        $nom = $modele->name();
        if ($nom === '' || strpos($nom, $annonce['model']) === 0) {
            $fautes[] = $identifiant . ' : nom « ' . $nom . ' » — le code constructeur n\'est pas '
                . 'un nom lisible tant que le catalogue en connaît un';
        }
        if (isset($nomsEquipements[$nom])) {
            $fautes[] = 'nom d\'équipement en double : « ' . $nom . ' » ('
                . $nomsEquipements[$nom] . ' et ' . $identifiant . ') — eqLogic (name, object_id) '
                . 'est unique, le second enregistrement échoue';
        }
        $nomsEquipements[$nom] = $identifiant;

        $vus = array();
        foreach ($modele->channels() as $canal) {
            $nomCanal = $canal->name();
            if ($nomCanal === '') {
                continue;
            }
            if (isset($vus[$nomCanal])) {
                $fautes[] = $identifiant . ' : deux commandes nommées « ' . $nomCanal . ' » ('
                    . $vus[$nomCanal] . ' et ' . $canal->key() . ') — cmd (eqLogic_id, name) est '
                    . 'unique, et c\'est tout l\'équipement qui ne s\'enregistre plus';
            }
            $vus[$nomCanal] = $canal->key();
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        implode("\n", array_slice($fautes, 0, 6)));

    /* ----------------------------------------------------------------- 15 ---
     * Tous les modèles valides, et aucune capacité inventée. */
    $titre = 'modèles valides, capacités du vocabulaire';
    if (!$vocabulaireCharge) {
        $resultats[] = mqttbeIndecis($titre, 'core/config/capabilities.json est illisible : la '
            . 'validité des capacités ne peut pas être affirmée.');
    } else {
        $fautes = array();
        foreach ($parUid as $uid => $modele) {
            foreach ($modele->validate() as $faute) {
                $fautes[] = $uid . ' : ' . $faute;
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "une capacité inventée donne une commande sans type générique : elle n'apparaît ni "
            . "dans l'application mobile, ni dans les widgets.\n"
            . implode("\n", array_slice($fautes, 0, 6)));
    }

    /* ----------------------------------------------------------------- 16 ---
     * Rien de ce que le parc publie ne se perd en silence.
     *
     * arbre.json est l'état retenu du broker : les topics que les appareils ont
     * réellement publiés. Chacun doit se retrouver dans un canal — sauf ceux
     * dont on sait qu'ils sont écartés, et pourquoi. C'est le seul contrôle qui
     * attrape une valeur oubliée : tout le reste vérifie ce qu'on a pensé à
     * écrire. */
    $titre = 'aucun topic publié laissé de côté sans raison';
    $ignores = array(
        'info'                 => 'message de découverte, pas une valeur',
        'temperature_f'        => 'la même température en Fahrenheit',
        'ext_temperature_f/N'  => 'la même température externe en Fahrenheit',
        'ext_temperatures'     => 'les sondes en bloc, déjà prises une par une',
        'ext_temperatures_f'   => 'idem, en Fahrenheit',
        'overtemperature'      => 'aucune capacité de surchauffe au vocabulaire',
        'temperature_status'   => 'aucune capacité de surchauffe au vocabulaire',
        'emeter/N/pf'          => 'aucune capacité de facteur de puissance',
        'emeter/N/reactive_power' => 'aucune capacité de puissance réactive',
    );
    $fautes = array();
    foreach ($arbre as $identifiant => $topics) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele === null) {
            continue;
        }
        $couverts = array();
        foreach ($modele->sourceTopics() as $topic) {
            $couverts[$topic] = true;
        }
        foreach (array_keys($topics) as $relatif) {
            $normalise = preg_replace('/\d+/', 'N', $relatif);
            if (isset($ignores[$relatif]) || isset($ignores[$normalise])) {
                continue;
            }
            if (!isset($couverts['shellies/' . $identifiant . '/' . $relatif])) {
                $fautes[] = $identifiant . ' : ' . $relatif . ' publié, mais aucun canal ne le lit';
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "ces topics existent sur le broker et personne ne les lira.\n"
        . implode("\n", array_slice($fautes, 0, 8)));

    /* ----------------------------------------------------------------- 17 ---
     * Idempotence : le parc rejoué ne produit rien de neuf.
     *
     * Les messages de découverte sont retenus, donc rejoués à chaque démarrage
     * du démon, et chaque demande d'annonce les fait republier. Sans cette
     * garde, tout le parc serait réécrit en base plusieurs fois par jour. */
    $titre = 'second passage : aucun modèle réémis';
    $avant = count($ctx->modeles);
    mqttbeRejoueParcGen1($adapter, $ctx, $annonces, $infos);
    $apres = count($ctx->modeles);
    $resultats[] = ($apres === $avant) ? mqttbeOk($titre) : mqttbeEchec($titre,
        ($apres - $avant) . ' modèle(s) réémis alors que rien n\'a changé : les messages retenus '
        . 'étant rejoués à chaque démarrage du démon, la base serait réécrite en entier à chaque '
        . 'fois, et les retouches de l\'utilisateur repassées en revue pour rien.');

    /* ----------------------------------------------------------------- 18 ---
     * La relance demandée par Jeedom. */
    $titre = 'relance de découverte : nouvelle demande d\'annonce';
    $fautes = array();
    $avant = $ctx->demandesAnnonce();
    $ctx->relance = true;
    $adapter->onTick($ctx);
    if ($ctx->demandesAnnonce() <= $avant) {
        $fautes[] = 'ctx->rescan() ignoré : le bouton « relancer la découverte » ne ferait rien.';
    }
    /* Deuxième chemin : la clé de mémoire, pour qui pilote l'adapter sans le
     * moteur. Elle doit être consommée, sinon chaque seconde redemanderait une
     * annonce à tout le parc. */
    $avant = $ctx->demandesAnnonce();
    $ctx->remember('shelly.gen1:rescan', true);
    $adapter->onTick($ctx);
    if ($ctx->demandesAnnonce() !== $avant + 1) {
        $fautes[] = 'la relance par clé de mémoire a produit '
            . ($ctx->demandesAnnonce() - $avant) . ' demande(s), attendu 1.';
    }
    $avant = $ctx->demandesAnnonce();
    $adapter->onTick($ctx);
    $adapter->onTick($ctx);
    if ($ctx->demandesAnnonce() !== $avant) {
        $fautes[] = 'une relance consommée revient toute seule : le parc serait sommé de '
            . 's\'annoncer à chaque seconde.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 19 ---
     * L'appareil à moitié connu. */
    $titre = 'annonce seule : modèle « probable », puis complété sans doublon';
    $ctx2 = new MqttbeContexteEssaiGen1();
    $adapter2 = new MqttbeShellyGen1($catalogue);
    $identifiant = $reperes['SHSW-PM'];
    $adapter2->onTick($ctx2);
    $adapter2->onMessage('shellies/' . $identifiant . '/announce',
                         json_encode($annonces[$identifiant]), false, $ctx2);
    $adapter2->onTick($ctx2);
    $immediat = count($ctx2->modeles);
    $ctx2->avance(30);
    $adapter2->onTick($ctx2);
    $fautes = array();
    if ($immediat !== 0) {
        $fautes[] = 'un modèle a été émis sans laisser au info le temps d\'arriver : '
            . 'l\'équipement serait créé incomplet alors que tout allait bien';
    }
    if (count($ctx2->modeles) !== 1) {
        $fautes[] = 'après expiration du délai, ' . count($ctx2->modeles)
            . ' modèle(s) émis, attendu 1 : une annonce sans info doit quand même donner un '
            . 'appareil, faute de quoi il n\'apparaît nulle part';
    } else {
        $probable = $ctx2->modeles[0];
        if ($probable->confidence() !== 'probable') {
            $fautes[] = 'confiance « ' . $probable->confidence() .' », attendu « probable » : '
                . 'un appareil à moitié connu doit le dire';
        }
        if ($probable->countChannels() !== 1 || $probable->channel('online') === null) {
            $fautes[] = 'le modèle sans info porte ' . $probable->countChannels()
                . ' canaux : sans le info, seule la disponibilité est connue';
        }
    }
    /* Le info arrive enfin : le même appareil, complété, et surtout pas un
     * second équipement. */
    $adapter2->onMessage('shellies/' . $identifiant . '/info',
                         json_encode($infos[$identifiant]), true, $ctx2);
    if (count($ctx2->modeles) !== 2) {
        $fautes[] = 'l\'arrivée du info n\'a pas produit de modèle complété ('
            . count($ctx2->modeles) . ' émission(s) en tout)';
    } else {
        $complet = $ctx2->modeles[1];
        if ($complet->uid() !== $ctx2->modeles[0]->uid()) {
            $fautes[] = 'uid différent entre le modèle probable et le modèle complet : '
                . 'Jeedom créerait deux équipements pour un seul appareil';
        }
        if ($complet->confidence() !== 'certain') {
            $fautes[] = 'confiance « ' . $complet->confidence() . ' » après réception du info';
        }
        if ($complet->countChannels() < 10) {
            $fautes[] = 'modèle complété à ' . $complet->countChannels() . ' canaux seulement';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 20 ---
     * Les captures ne doivent rien porter du réseau de qui que ce soit. */
    $titre = 'captures anonymisées';
    $fautes = array();
    foreach (array('annonces.json', 'info.json', 'arbre.json') as $nom) {
        $texte = file_get_contents(mqttbeCheminFixturesGen1() . '/' . $nom);
        if (preg_match('/\b(?:10|127)\.\d+\.\d+\.\d+\b/', $texte)
            || preg_match('/\b192\.168\.\d+\.\d+\b/', $texte)
            || preg_match('/\b172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+\b/', $texte)) {
            $fautes[] = $nom . ' : adresse IP privée (attendu 192.0.2.x, réservé à la documentation)';
        }
        if (preg_match('/"ssid"\s*:\s*"(?!reseau-essai)/', $texte)) {
            $fautes[] = $nom . ' : SSID réel';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "le dépôt part sur GitHub : une capture brute y publierait la topologie du réseau "
        . "d'une maison.\n" . implode("\n", $fautes));

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Découverte Shelly Gen1', mqttbeControlesShellyGen1());
}
