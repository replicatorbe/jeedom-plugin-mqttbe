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

    /* Le broker, ou son absence. Le vrai contexte rend false tant que la
     * liaison n'est pas établie — c'est l'état ordinaire d'un démon qui
     * démarre en même temps que son broker, après une coupure de courant — et
     * un contexte d'essai qui rendrait toujours true laisserait passer un
     * adapter qui tient ses demandes pour faites. */
    public $broker = true;

    public function publish($_topic, $_payload) {
        if (!$this->broker) {
            return false;
        }
        $this->publications[] = array('topic' => $_topic, 'payload' => $_payload);
        return true;
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

/*
 * Les familles que le parc réel ne contient pas : volet, variateur, lampe de
 * couleur, ampoule à température de couleur, capteurs sur pile, compteur
 * triphasé, boîtier d'entrées seules.
 *
 * Leurs `info` sont reconstitués d'après la documentation officielle — la
 * structure de /status et les topics MQTT y sont donnés modèle par modèle —
 * et non relevés sur un appareil : personne ici n'a de volet Shelly sous la
 * main, et c'est justement pour cela que ces trois familles étaient traitées
 * comme des relais.
 */
function mqttbeFamillesGen1() {
    $brut = mqttbeLitJsonGen1('familles.json');
    return (is_array($brut) && isset($brut['appareils']) && is_array($brut['appareils']))
        ? $brut['appareils'] : array();
}

/* Un parc de papier rejoué d'un coup, et les modèles qui en sortent. */
function mqttbeRejoueFamillesGen1($_catalogue, $_familles, $_ctx = null) {
    $ctx = ($_ctx === null) ? new MqttbeContexteEssaiGen1() : $_ctx;
    $adapter = new MqttbeShellyGen1($_catalogue);
    $adapter->onTick($ctx);
    foreach ($_familles as $identifiant => $appareil) {
        $annonce = isset($appareil['announce']) ? $appareil['announce'] : array();
        $info    = isset($appareil['info']) ? $appareil['info'] : array();
        $adapter->onMessage('shellies/announce', json_encode($annonce), true, $ctx);
        $adapter->onMessage('shellies/' . $identifiant . '/info', json_encode($info), true, $ctx);
    }
    return mqttbeModelesParUidGen1($ctx);
}

/* La destination d'un canal, en une chaîne lisible dans un message d'échec :
 * « topic [charge utile] ». */
function mqttbeSinkGen1($_canal) {
    if ($_canal === null || !$_canal->hasSink()) {
        return '';
    }
    return $_canal->sinkTopic() . ' [' . $_canal->sinkPayload() . ']';
}

function mqttbeSelecteurGen1($_canal) {
    if ($_canal === null || !$_canal->hasSource()) {
        return '';
    }
    $selecteur = $_canal->sourceSelector();
    return (isset($selecteur['type']) && $selecteur['type'] === 'json' && isset($selecteur['path']))
        ? $selecteur['path'] : '';
}

/*
 * Un canal attendu, décrit en une ligne : capacité, topic lu, topic écrit.
 * Rend le motif d'écart, ou la chaîne vide. La chaîne '*' vaut « peu importe ».
 */
function mqttbeCanalGen1($_modele, $_cle, $_capacite, $_source, $_sink = '') {
    if ($_modele === null) {
        return $_cle . ' : aucun modèle';
    }
    $canal = $_modele->channel($_cle);
    if ($canal === null) {
        return $_cle . ' : canal absent';
    }
    $fautes = array();
    if ($_capacite !== '*' && $canal->capability() !== $_capacite) {
        $fautes[] = 'capacité ' . $canal->capability() . ', attendu ' . $_capacite;
    }
    if ($_source !== '*' && $canal->sourceTopic() !== $_source) {
        $fautes[] = 'source « ' . $canal->sourceTopic() . ' », attendu « ' . $_source . ' »';
    }
    if ($_sink !== '*' && mqttbeSinkGen1($canal) !== $_sink) {
        $fautes[] = 'destination « ' . mqttbeSinkGen1($canal) . ' », attendu « ' . $_sink . ' »';
    }
    return empty($fautes) ? '' : ($_cle . ' : ' . implode(' ; ', $fautes));
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
    foreach (array('annonces.json', 'info.json', 'arbre.json', 'familles.json') as $nom) {
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

    /* ----------------------------------------------------------------- 21 ---
     * Les familles que le parc de la maison ne contient pas.
     *
     * Tout ce qui suit se joue sur familles.json, reconstitué d'après la
     * documentation officielle. Les 22 appareils réels ne contiennent que des
     * relais : un adapter qui ne sait traiter que des relais passe donc tous
     * les contrôles précédents, et produit pourtant un équipement faux ou vide
     * pour trois familles entières. */
    $familles = mqttbeFamillesGen1();
    if (empty($familles)) {
        $resultats[] = mqttbeIndecis('familles Shelly hors relais',
            'tests/fixtures/shelly/gen1/familles.json est absent ou illisible.');
        return $resultats;
    }
    $parFamille = mqttbeRejoueFamillesGen1($catalogue, $familles);

    /* -- 21.a  Volets ------------------------------------------------------ */
    $titre = 'volet (SHSW-25 en mode roller) : roller/0, et pas de relais';
    $volet = mqttbeModeleGen1($parFamille, 'shellyswitch25-A8B0C1000020');
    $base = 'shellies/shellyswitch25-A8B0C1000020';
    if ($volet === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHSW-25 en mode volet.');
    } else {
        $fautes = array();
        $attendus = array(
            array('roller.0.state',    'cover.state',    $base . '/roller/0/pos', ''),
            array('roller.0.open',     'cover.open',     '', $base . '/roller/0/command [open]'),
            array('roller.0.close',    'cover.close',    '', $base . '/roller/0/command [close]'),
            array('roller.0.stop',     'cover.stop',     '', $base . '/roller/0/command [stop]'),
            array('roller.0.position', 'cover.position', '', $base . '/roller/0/command/pos [#slider#]'),
            array('roller.0.power',    'power.active',   $base . '/roller/0/power', ''),
            array('roller.0.energy',   'energy.total',   $base . '/roller/0/energy', ''),
        );
        foreach ($attendus as $attendu) {
            $ecart = mqttbeCanalGen1($volet, $attendu[0], $attendu[1], $attendu[2], $attendu[3]);
            if ($ecart !== '') {
                $fautes[] = $ecart;
            }
        }
        /* Les deux jeux s'excluent : en mode volet, `relay/<i>` ne publie plus
         * rien et n'écoute plus rien. Deux interrupteurs qui ne commandent
         * rien, c'est un utilisateur qui appuie et attend. */
        foreach ($volet->channelKeys() as $cle) {
            if (strpos($cle, 'relay.') === 0) {
                $fautes[] = $cle . ' : canal de relais créé sur un appareil en mode volet, alors '
                    . 'que relay/<i> ne publie plus rien et n\'écoute plus rien';
            }
        }
        /* -1 est publié par un volet non calibré : ce n'est pas une position,
         * et « -1 % » sur un widget de volet ne veut rien dire. */
        $etat = $volet->channel('roller.0.state');
        $transform = mqttbeTransformGen1($etat);
        if ($etat !== null && (!isset($transform['map']) || !array_key_exists('-1', $transform['map'])
                               || $transform['map']['-1'] !== '')) {
            $fautes[] = 'roller.0.state : la position -1 (volet non calibré) n\'est pas neutralisée, '
                . 'le widget afficherait « -1 % »';
        }
        /* Le moteur compte en watt-minutes, comme un relais. */
        $energie = $volet->channel('roller.0.energy');
        $transform = mqttbeTransformGen1($energie);
        $echelle = isset($transform['scale']) ? (float) $transform['scale'] : 1.0;
        if (abs($echelle - (1 / 60000)) > 1e-12) {
            $fautes[] = 'roller.0.energy : échelle ' . $echelle . ', attendu 1/60000 (watt-minutes)';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "en mode volet, un Shelly 2.5 publie encore `relays[]` dans son info mais ne dit plus "
            . "un mot sur relay/<i> : lire `relays` sans regarder `rollers` donne deux "
            . "interrupteurs muets et aucune commande de volet.\n" . implode("\n", $fautes));
    }

    /* -- 21.b  Le même appareil, en mode relais ---------------------------- */
    $titre = 'le même SHSW-25 en mode relais : deux interrupteurs';
    $relaisModele = mqttbeModeleGen1($parFamille, 'shellyswitch25-A8B0C1000021');
    if ($relaisModele === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHSW-25 en mode relais.');
    } else {
        $fautes = array();
        foreach (array('relay.0.state', 'relay.1.state', 'relay.0.power', 'relay.1.energy') as $cle) {
            if ($relaisModele->channel($cle) === null) {
                $fautes[] = $cle . ' absent';
            }
        }
        foreach ($relaisModele->channelKeys() as $cle) {
            if (strpos($cle, 'roller.') === 0) {
                $fautes[] = $cle . ' : canal de volet créé sans `rollers` dans le info';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "le mode n'est pas une propriété du modèle : le même code produit deux appareils "
            . "différents, et seul `rollers` dans le info les distingue.\n" . implode("\n", $fautes));
    }

    /* ----------------------------------------------------------------- 22 ---
     * Variateurs et lampes. */
    $titre = 'variateur (SHDM-2) : allumage, luminosité, puissance';
    $variateur = mqttbeModeleGen1($parFamille, 'shellydimmer2-A8B0C1000022');
    $base = 'shellies/shellydimmer2-A8B0C1000022';
    if ($variateur === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHDM-2.');
    } else {
        $fautes = array();
        $attendus = array(
            array('light.0.state',      'light.state',      $base . '/light/0', ''),
            array('light.0.on',         'light.on',         '', $base . '/light/0/command [on]'),
            array('light.0.off',        'light.off',        '', $base . '/light/0/command [off]'),
            array('light.0.brightness', 'light.brightness', '',
                  $base . '/light/0/set [{"brightness":#slider#,"turn":"on"}]'),
            /* meters[0] appartient à la lampe 0, et non à un relais qui
             * n'existe pas : jusqu'ici la puissance d'un variateur était
             * simplement jetée, avec une ligne de journal en debug. */
            array('light.0.power',      'power.active',     $base . '/light/0/power', ''),
            array('light.0.energy',     'energy.total',     $base . '/light/0/energy', ''),
        );
        foreach ($attendus as $attendu) {
            $ecart = mqttbeCanalGen1($variateur, $attendu[0], $attendu[1], $attendu[2], $attendu[3]);
            if ($ecart !== '') {
                $fautes[] = $ecart;
            }
        }
        foreach ($variateur->channelKeys() as $cle) {
            if (strpos($cle, 'relay.') === 0) {
                $fautes[] = $cle . ' : canal de relais sur un variateur';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "un variateur sans commande d'allumage ni curseur de luminosité est un équipement "
            . "que l'utilisateur ne peut pas piloter du tout.\n" . implode("\n", $fautes));
    }

    $titre = 'RGBW2 en mode couleur : préfixe color/0, et gain';
    $rgbw = mqttbeModeleGen1($parFamille, 'shellyrgbw2-A8B0C1000023');
    $base = 'shellies/shellyrgbw2-A8B0C1000023';
    if ($rgbw === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHRGBW2.');
    } else {
        $fautes = array();
        $attendus = array(
            array('light.0.state',      'light.state',      $base . '/color/0', ''),
            array('light.0.on',         'light.on',         '', $base . '/color/0/command [on]'),
            /* En mode couleur, la luminosité s'appelle `gain` : `brightness` n'y
             * pilote que la voie blanche, et le curseur ne ferait rien bouger. */
            array('light.0.brightness', 'light.brightness', '',
                  $base . '/color/0/set [{"gain":#slider#,"turn":"on"}]'),
            array('light.0.power',      'power.active',     $base . '/color/0/power', ''),
        );
        foreach ($attendus as $attendu) {
            $ecart = mqttbeCanalGen1($rgbw, $attendu[0], $attendu[1], $attendu[2], $attendu[3]);
            if ($ecart !== '') {
                $fautes[] = $ecart;
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "le RGBW2 est la seule lampe de la génération 1 qui ne parle pas sous `light/<i>` : "
            . "c'est `color/0` en mode couleur, `white/<i>` en mode blanc.\n"
            . implode("\n", $fautes));
    }

    $titre = 'ampoule à blanc réglable (SHBDUO-1) : température de couleur';
    $duo = mqttbeModeleGen1($parFamille, 'shellybulbduo-A8B0C1000024');
    $base = 'shellies/shellybulbduo-A8B0C1000024';
    if ($duo === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHBDUO-1.');
    } else {
        $fautes = array();
        $ecart = mqttbeCanalGen1($duo, 'light.0.color_temp', 'light.color_temp', '',
                                 $base . '/light/0/set [{"temp":#slider#,"turn":"on"}]');
        if ($ecart !== '') {
            $fautes[] = $ecart;
        }
        $temp = $duo->channel('light.0.color_temp');
        if ($temp !== null && $temp->unit() !== 'K') {
            $fautes[] = 'light.0.color_temp : unité « ' . $temp->unit() . ' », attendu K';
        }
        /* Un variateur n'a pas de `temp` dans son info : lui créer le curseur
         * donnerait une commande qui publie une charge utile refusée. */
        if ($variateur !== null && $variateur->channel('light.0.color_temp') !== null) {
            $fautes[] = 'le variateur a reçu une température de couleur qu\'il n\'a pas';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            implode("\n", $fautes));
    }

    /* ----------------------------------------------------------------- 23 ---
     * Capteurs sur pile. */
    $titre = 'H&T sur pile : sensor/…, et une température ambiante';
    $ht = mqttbeModeleGen1($parFamille, 'shellyht-A8B0C1000025');
    $base = 'shellies/shellyht-A8B0C1000025';
    if ($ht === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHHT-1.');
    } else {
        $fautes = array();
        $attendu = array('online', 'temperature', 'humidity', 'battery');
        $ecart = mqttbeManqueEnTropGen1($attendu, mqttbeClesGen1($ht));
        if ($ecart !== '') {
            $fautes[] = 'canaux — ' . $ecart;
        }
        $attendus = array(
            array('temperature', 'sensor.temperature', $base . '/sensor/temperature', ''),
            array('humidity',    'sensor.humidity',    $base . '/sensor/humidity', ''),
            array('battery',     'battery.level',      $base . '/sensor/battery', ''),
        );
        foreach ($attendus as $a) {
            $e = mqttbeCanalGen1($ht, $a[0], $a[1], $a[2], $a[3]);
            if ($e !== '') {
                $fautes[] = $e;
            }
        }
        /* Sur un H&T, la sonde est dehors, pas dans le boîtier : « Température
         * interne » ferait croire à la chauffe de l'électronique, et celui qui
         * bâtit un thermostat dessus s'en apercevrait trop tard. */
        $temperature = $ht->channel('temperature');
        if ($temperature !== null && stripos($temperature->name(), 'interne') !== false) {
            $fautes[] = 'la température d\'un H&T est celle de la pièce : le libellé « '
                . $temperature->name() . ' » est trompeur';
        }
        if (!$ht->isBatteryPowered()) {
            $fautes[] = 'l\'appareil n\'est pas marqué comme alimenté par pile';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "un capteur sur pile ne publie jamais `temperature` : tout passe sous `sensor/…`. "
            . "Traité comme un relais, un H&T arrive avec une température branchée sur un topic "
            . "muet, sans humidité ni niveau de pile.\n" . implode("\n", $fautes));
    }

    $titre = 'Flood, Door/Window, Smoke, Motion, Button : chacun ses topics';
    $attendusCapteurs = array(
        'shellyflood-A8B0C1000026' => array(
            array('flood',       'alarm.water_leak',   'sensor/flood'),
            array('temperature', 'sensor.temperature', 'sensor/temperature'),
            array('battery',     'battery.level',      'sensor/battery'),
        ),
        'shellydw2-A8B0C1000027' => array(
            array('contact',     'contact.open',       'sensor/state'),
            array('lux',         'sensor.luminosity',  'sensor/lux'),
            array('battery',     'battery.level',      'sensor/battery'),
        ),
        'shellysmoke-A8B0C1000028' => array(
            array('smoke',       'alarm.smoke',        'sensor/smoke'),
            array('battery',     'battery.level',      'sensor/battery'),
        ),
        'shellymotionsensor-A8B0C1000029' => array(
            array('motion',      'presence.detected',  'sensor/motion'),
            array('lux',         'sensor.luminosity',  'sensor/lux'),
            array('battery',     'battery.level',      'sensor/battery'),
        ),
        'shellybutton1-A8B0C100002A' => array(
            array('battery',     'battery.level',      'sensor/battery'),
            array('input.0.event', 'button.event',     'input_event/0'),
        ),
        /* Le détecteur de gaz n'a pas de pile, et parle pourtant sous
         * `sensor/…` comme ses cousins : c'est bien la façon de parler qui
         * décide, et non l'alimentation. */
        'shellygas-A8B0C100002D' => array(
            array('gas',         'alarm.gas',          'sensor/gas'),
        ),
    );
    $fautes = array();
    foreach ($attendusCapteurs as $identifiant => $canaux) {
        $modele = mqttbeModeleGen1($parFamille, $identifiant);
        if ($modele === null) {
            $fautes[] = $identifiant . ' : aucun modèle';
            continue;
        }
        foreach ($canaux as $canal) {
            $ecart = mqttbeCanalGen1($modele, $canal[0], $canal[1],
                                     'shellies/' . $identifiant . '/' . $canal[2], '*');
            if ($ecart !== '') {
                $fautes[] = $identifiant . ' : ' . $ecart;
            }
        }
        /* Aucun de ces appareils n'a de relais, et aucun ne publie la
         * température de son boîtier sous `temperature`. */
        foreach ($modele->channels() as $canal) {
            if (strpos($canal->key(), 'relay.') === 0) {
                $fautes[] = $identifiant . ' : ' . $canal->key() . ' — relais sur un capteur';
            }
            if ($canal->hasSource()
                && $canal->sourceTopic() === 'shellies/' . $identifiant . '/temperature') {
                $fautes[] = $identifiant . ' : ' . $canal->key()
                    . ' lit shellies/…/temperature, topic qu\'un capteur sur pile ne publie jamais';
            }
        }
        /* Le contrôle qui compte vraiment : un capteur sans niveau de pile est
         * un capteur dont personne ne saura qu'il s'est tu faute de courant.
         * Exigé exactement là où l'info porte `bat`, et nulle part ailleurs. */
        $aPile = isset($familles[$identifiant]['info']['bat']);
        if ($aPile !== ($modele->channel('battery') !== null)) {
            $fautes[] = $identifiant . ' : info ' . ($aPile ? 'avec' : 'sans') . ' `bat`, niveau '
                . 'de pile ' . ($aPile ? 'absent' : 'créé');
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        implode("\n", array_slice($fautes, 0, 8)));

    /* -- 23.b  Les nouveaux modèles tiennent les mêmes règles que les autres */
    $titre = 'familles hors relais : modèles valides, noms uniques';
    $fautes = array();
    $nomsEquipements = array();
    foreach ($parFamille as $uid => $modele) {
        foreach ($modele->validate() as $faute) {
            $fautes[] = $uid . ' : ' . $faute;
        }
        $nom = $modele->name();
        if (isset($nomsEquipements[$nom])) {
            $fautes[] = 'nom d\'équipement en double : « ' . $nom . ' »';
        }
        $nomsEquipements[$nom] = $uid;
        $vus = array();
        foreach ($modele->channels() as $canal) {
            $nomCanal = $canal->name();
            if ($nomCanal === '') {
                continue;
            }
            if (isset($vus[$nomCanal])) {
                $fautes[] = $uid . ' : deux commandes nommées « ' . $nomCanal . ' » ('
                    . $vus[$nomCanal] . ' et ' . $canal->key() . ') — cmd (eqLogic_id, name) est '
                    . 'unique, et c\'est tout l\'équipement qui ne s\'enregistre plus';
            }
            $vus[$nomCanal] = $canal->key();
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        implode("\n", array_slice($fautes, 0, 6)));

    /* ----------------------------------------------------------------- 24 ---
     * Relancer la découverte doit vraiment tout redire.
     *
     * Le moteur dédoublonne déjà par empreinte : un adapter qui garde en plus
     * son propre souvenir de ce qu'il a émis n'économise rien et rend le bouton
     * inopérant. Le jour où un équipement est supprimé par erreur dans Jeedom,
     * il ne revient alors JAMAIS. */
    $titre = 'relance : les modèles sont vraiment réémis';
    $ctx3 = new MqttbeContexteEssaiGen1();
    $adapter3 = new MqttbeShellyGen1($catalogue);
    mqttbeRejoueParcGen1($adapter3, $ctx3, $annonces, $infos);
    $premier = count($ctx3->modeles);
    /* Sans relance : rien de neuf, c'est le contrôle 18. */
    mqttbeRejoueParcGen1($adapter3, $ctx3, $annonces, $infos);
    $sansRelance = count($ctx3->modeles);
    /* Avec relance : le parc se réannonce, et tout doit repartir vers Jeedom. */
    $ctx3->relance = true;
    $adapter3->onTick($ctx3);
    mqttbeRejoueParcGen1($adapter3, $ctx3, $annonces, $infos);
    $avecRelance = count($ctx3->modeles) - $sansRelance;
    $fautes = array();
    if ($premier < 1) {
        $fautes[] = 'aucun modèle au premier passage : le contrôle ne prouve rien.';
    }
    if ($sansRelance !== $premier) {
        $fautes[] = 'un passage sans relance a réémis ' . ($sansRelance - $premier) . ' modèle(s).';
    }
    if ($avecRelance !== $premier) {
        $fautes[] = 'après « relancer la découverte », ' . $avecRelance . ' modèle(s) réémis sur '
            . $premier . ' : un équipement supprimé par erreur dans Jeedom ne reviendrait pas, '
            . 'et l\'utilisateur peut presser le bouton autant qu\'il veut.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 25 ---
     * Le broker pas encore là.
     *
     * Le cas ordinaire après une coupure de courant : la box et le broker
     * redémarrent ensemble, le démon tourne avant d'avoir sa liaison. Si les
     * trois relances (0, 10, 60 s) se consomment à vide, le parc reste
     * invisible jusqu'au prochain redémarrage du démon — et le journal affirme
     * que les demandes sont parties. */
    $titre = 'broker absent : les demandes d\'annonce ne sont pas perdues';
    $ctx4 = new MqttbeContexteEssaiGen1();
    $ctx4->broker = false;
    $adapter4 = new MqttbeShellyGen1($catalogue);
    for ($tour = 0; $tour < 5; $tour++) {
        $adapter4->onTick($ctx4);
        $ctx4->avance(30);
    }
    $fautes = array();
    if ($ctx4->demandesAnnonce() !== 0) {
        $fautes[] = 'des demandes ont été comptées alors que rien n\'est parti.';
    }
    $affirme = false;
    foreach ($ctx4->journal as $ligne) {
        if (strpos($ligne, 'info : ') === 0 && strpos($ligne, 'annonce demandée') !== false) {
            $affirme = true;
        }
    }
    if ($affirme) {
        $fautes[] = 'le journal annonce en « info » une demande qui n\'est jamais partie : '
            . 'c\'est la seule trace dont dispose celui qui cherche pourquoi il ne voit aucun '
            . 'appareil, et elle dit le contraire de ce qui s\'est passé.';
    }
    /* Le broker arrive enfin : la séquence doit être intacte, et non consommée. */
    $ctx4->broker = true;
    $adapter4->onTick($ctx4);
    if ($ctx4->demandesAnnonce() < 1) {
        $fautes[] = 'le broker est revenu et aucune annonce n\'a été demandée : les trois '
            . 'relances ont été consommées à vide, le parc restera invisible.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 26 ---
     * Le courant par phase d'un 3EM. */
    $titre = 'Shelly 3EM : le courant de chaque phase';
    $triphase = mqttbeModeleGen1($parFamille, 'shellyem3-A8B0C100002B');
    if ($triphase === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour le SHEM-3.');
    } else {
        $fautes = array();
        foreach (array(0, 1, 2) as $voie) {
            $ecart = mqttbeCanalGen1($triphase, 'emeter.' . $voie . '.current', 'power.current',
                'shellies/shellyem3-A8B0C100002B/emeter/' . $voie . '/current', '');
            if ($ecart !== '') {
                $fautes[] = $ecart;
            }
            $courant = $triphase->channel('emeter.' . $voie . '.current');
            if ($courant !== null && $courant->unit() !== 'A') {
                $fautes[] = 'voie ' . $voie . ' : unité « ' . $courant->unit() . ' », attendu A';
            }
        }
        /* Et l'autre côté de la garde : le SHEM à deux voies ne publie pas
         * `current`, et n'a donc pas à porter un ampérage éternellement vide. */
        $shem = mqttbeModeleGen1($parUid, $reperes['SHEM']);
        if ($shem !== null && $shem->channel('emeter.0.current') !== null) {
            $fautes[] = 'le SHEM a reçu un courant qu\'il ne publie pas : `current` est absent de '
                . 'son info, comme `pf` et `reactive` sont absents de celui d\'un 3EM.';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            implode("\n", $fautes));
    }

    /* ----------------------------------------------------------------- 27 ---
     * `overpower`, le troisième mot de relay/<i>. */
    $titre = 'relais : « overpower » est un état, pas une chaîne inconnue';
    $fautes = array();
    foreach (array($reperes['SHSW-PM'], $reperes['SHPLG-S']) as $identifiant) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        $canal = ($modele === null) ? null : $modele->channel('relay.0.state');
        $transform = mqttbeTransformGen1($canal);
        $map = isset($transform['map']) && is_array($transform['map']) ? $transform['map'] : array();
        foreach (array('on', 'off', 'overpower') as $mot) {
            if (!array_key_exists($mot, $map)) {
                $fautes[] = $identifiant . ' : « ' . $mot . ' » absent de la correspondance ('
                    . json_encode($map, JSON_UNESCAPED_UNICODE) . ')';
            }
        }
        /* Le relais a disjoncté : la sortie est ouverte. Le dire « allumé »
         * serait pire que de ne rien dire. */
        if (isset($map['overpower']) && (string) $map['overpower'] !== '0') {
            $fautes[] = $identifiant . ' : « overpower » traduit en « ' . $map['overpower']
                . ' » — la surcharge coupe la sortie, l\'état binaire vaut donc 0';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "relay/<i> vaut on, off ou overpower. Sans la troisième entrée, la commande binaire "
        . "reçoit la chaîne « overpower » exactement au moment qui compte — celui où le relais "
        . "disjoncte.\n" . implode("\n", $fautes));

    /* ----------------------------------------------------------------- 28 ---
     * L'appui long fantôme. */
    $titre = 'appui long : là où il existe, et nulle part ailleurs';
    $fautes = array();
    /* Le i3 porte son appui long dans input_event (« L ») et ne publie jamais
     * longpush : trois commandes définitivement vides sur un appareil qui n'a
     * que trois entrées, c'est tout son tableau de bord. */
    $i3 = mqttbeModeleGen1($parFamille, 'shellyix3-A8B0C100002C');
    $bouton = mqttbeModeleGen1($parFamille, 'shellybutton1-A8B0C100002A');
    foreach (array('shellyix3-A8B0C100002C' => $i3, 'shellybutton1-A8B0C100002A' => $bouton) as $id => $modele) {
        if ($modele === null) {
            $fautes[] = $id . ' : aucun modèle';
            continue;
        }
        foreach ($modele->channels() as $canal) {
            if ($canal->hasSource() && strpos($canal->sourceTopic(), '/longpush/') !== false) {
                $fautes[] = $id . ' : ' . $canal->key() . ' lit ' . $canal->sourceTopic()
                    . ', topic que cet appareil ne publie jamais';
            }
        }
        /* L'événement, lui, doit rester : c'est par là que passe l'appui long. */
        if ($modele->channel('input.0.event') === null) {
            $fautes[] = $id . ' : input.0.event absent, l\'appui long n\'a plus aucun chemin';
        }
    }
    /* Et l'inverse : un appareil qui commande quelque chose garde ses appuis
     * longs, faute de quoi ce contrôle serait satisfait par un adapter qui les
     * supprimerait partout. */
    foreach (array($reperes['SHSW-1'], $reperes['SHSW-PM']) as $identifiant) {
        $modele = mqttbeModeleGen1($parUid, $identifiant);
        if ($modele !== null && $modele->channel('input.0.longpush') === null) {
            $fautes[] = $identifiant . ' : appui long supprimé sur un appareil qui le publie';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 29 ---
     * L'identifiant annoncé compose des topics : il vient du réseau, il se
     * contrôle.
     *
     * `shellies/announce` est un topic public : n'importe quel client du broker
     * peut y publier. L'identifiant qu'il porte devient la racine des topics de
     * l'équipement, en lecture ET en écriture. Un « + » suffit : en lecture,
     * `shellies/+/relay/0` est un filtre légal qui déverse l'état de tout le
     * parc sur un équipement fantôme ; en écriture, `shellies/+/relay/0/command`
     * est interdit par MQTT 3.1.1 §3.3.2 dans un nom de topic de publication, et
     * le broker ferme la connexion à chaque appui sur le bouton. */
    $titre = 'identifiant annoncé : refusé s\'il peut composer un joker';
    $ctx5 = new MqttbeContexteEssaiGen1();
    $adapter5 = new MqttbeShellyGen1($catalogue);
    $mauvais = array(
        '+'                      => 'joker MQTT d\'un niveau',
        '#'                      => 'joker MQTT multiniveau',
        'shelly1/../autre'       => 'barre oblique : niveaux de topic supplémentaires',
        ''                       => 'identifiant vide',
        str_repeat('a', 200)     => 'identifiant de 200 caractères',
        'shelly1 A8B0C1'         => 'espace',
        "shelly1\nannounce"      => 'retour à la ligne',
    );
    $fautes = array();
    foreach ($mauvais as $identifiant => $pourquoi) {
        $ctx5->modeles = array();
        $adapter5->onMessage('shellies/announce',
            json_encode(array('id' => $identifiant, 'model' => 'SHSW-1',
                              'mac' => '001122334455', 'ip' => '192.0.2.99')),
            false, $ctx5);
        $adapter5->onMessage('shellies/' . $identifiant . '/info',
            json_encode(array('relays' => array(array('ison' => false)))), false, $ctx5);
        $adapter5->onTick($ctx5);
        $ctx5->avance(60);
        $adapter5->onTick($ctx5);
        if (!empty($ctx5->modeles)) {
            $modele = $ctx5->modeles[0];
            $fautes[] = 'accepté : ' . $pourquoi . ' — uid « ' . $modele->uid() . ' », topics « '
                . implode(', ', array_slice($modele->sourceTopics(), 0, 2)) . ' »';
        }
    }
    /* Un identifiant Shelly légitime doit passer sans réserve : un contrôle trop
     * serré rendrait tout le parc invisible, ce qui est pire que le défaut. */
    $ctx6 = new MqttbeContexteEssaiGen1();
    $adapter6 = new MqttbeShellyGen1($catalogue);
    $adapter6->onMessage('shellies/announce',
        json_encode($annonces[$reperes['SHSW-PM']]), false, $ctx6);
    $adapter6->onMessage('shellies/' . $reperes['SHSW-PM'] . '/info',
        json_encode($infos[$reperes['SHSW-PM']]), false, $ctx6);
    if (count($ctx6->modeles) !== 1) {
        $fautes[] = 'un identifiant parfaitement légitime (' . $reperes['SHSW-PM']
            . ') a été refusé : le contrôle de forme est trop serré.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "l'identifiant vient du réseau et compose tous les topics de l'équipement.\n"
        . implode("\n", $fautes));

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Découverte Shelly Gen1', mqttbeControlesShellyGen1());
}
