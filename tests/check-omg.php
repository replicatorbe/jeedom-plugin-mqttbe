<?php
/* L'adapter OpenMQTTGateway, confronté à un parc réel de cinq passerelles.
 *
 *   php tests/check-omg.php
 *
 * Ni Jeedom, ni base de données, ni broker : les captures anonymisées de
 * tests/fixtures/omg sont rejouées dans un contexte de papier, et l'on regarde
 * ce que l'adapter produit, message par message.
 *
 * Ce que ces contrôles attrapent ne se voit ni à la relecture, ni au « php -l »,
 * ni même sur un broker d'essai avec une passerelle et un capteur :
 *
 *   - le RSSI change à CHAQUE trame. S'il entre dans ce qui décrit l'appareil,
 *     280 messages en 165 secondes donnent 280 réécritures de la base — et une
 *     seule balise vue par trois passerelles en produit déjà autant ;
 *   - le champ `name` n'est présent qu'une trame sur deux. Un nom qui s'efface
 *     quand il manque fait osciller le modèle entre deux formes, ce qui revient
 *     exactement au même ;
 *   - une passerelle BLE voit tout ce qui passe. Sans plafond ni péremption,
 *     l'inventaire du démon grandit toute la nuit sans que rien ne le dise ;
 *   - le parc réel comporte une passerelle au préfixe DUPLIQUÉ. Identifier une
 *     passerelle par son préfixe y crée un équipement fantôme ;
 *   - une balise absente ne dit rien. Sans calcul d'absence, l'équipement
 *     affirme éternellement qu'un objet parti depuis trois jours est au salon.
 *
 * Les captures sont anonymisées (SSID « reseau-essai », adresses en 192.0.2.x,
 * MAC fabriquées) : le dépôt part sur GitHub, et une capture brute y publierait
 * le réseau d'une maison. */

require_once __DIR__ . '/outils.php';

function mqttbeCheminAdapterOmg() {
    return mqttbeRacine() . '/resources/mqttbed/discovery/adapters/OpenMqttGateway.php';
}

function mqttbeCheminFixturesOmg() {
    return mqttbeRacine() . '/tests/fixtures/omg';
}

/* Les repères du parc, une fois anonymisé. */
function mqttbeReperesOmg() {
    return array(
        'SAM'       => 'bt/OMG_ESP32_BLE_SAM',
        'ENTREE'    => 'bt/OMG_ESP32_BLE_ENTREE',
        'ETAGE'     => 'bt/OMG_ESP32_BLE_ETAGE',
        'SALON'     => 'bt/OMG_ESP32_BLE_SALON',
        'DOUBLE'    => 'bt/OMG_ESP32_BLE_ETAGEOMG_ESP32_BLE_ETAGE',
        'BRUTE'     => 'd2d2d2102030',
        'CAPTEUR'   => 'a8b0c1003001',
        'TRACEUR'   => 'a8b0c1003006',
    );
}

/* --------------------------------------------------------------------------
 * Contexte de papier
 *
 * Le contrat ne donne à l'adapter que huit méthodes de contexte : publish,
 * subscribe, remember, recall, forget, emit, log, now — auxquelles le moteur
 * ajoute rescan(). Les voici, et rien de plus : un contexte d'essai plus riche
 * que le vrai laisserait passer un adapter qui s'appuie sur ce que le moteur ne
 * lui donnera pas.
 *
 * La mémoire est BORNÉE comme celle du moteur (MEMORY_MAX = 512 clés) : c'est
 * précisément le plafond qu'un inventaire de balises non tenu ferait sauter, et
 * un contexte d'essai à mémoire infinie ne le montrerait jamais.
 *
 * L'horloge est fausse et se pousse à la main : l'absence se mesure en
 * centaines de secondes et la péremption en heures, et un contrôle ne va pas
 * les attendre.
 * ------------------------------------------------------------------------ */
class MqttbeContexteEssaiOmg {

    const MEMOIRE_MAX = 512;

    public $publications = array();
    public $abonnements  = array();
    public $modeles      = array();
    public $journal      = array();
    public $refus        = 0;

    private $memoire = array();
    private $horloge = 1700000000.0;

    public $broker = true;

    public function publish($_topic, $_payload, $_qos = 0, $_retain = false) {
        if (!$this->broker) {
            return false;
        }
        $this->publications[] = array('topic' => $_topic, 'payload' => $_payload,
                                      'qos' => $_qos, 'retain' => $_retain);
        return true;
    }

    public function subscribe($_topic) {
        $this->abonnements[] = $_topic;
        return true;
    }

    public function remember($_cle, $_donnees) {
        if (!array_key_exists($_cle, $this->memoire) && count($this->memoire) >= self::MEMOIRE_MAX) {
            $this->refus++;
            return false;
        }
        $this->memoire[$_cle] = $_donnees;
        return true;
    }

    public function recall($_cle) {
        return array_key_exists($_cle, $this->memoire) ? $this->memoire[$_cle] : null;
    }

    public function forget($_cle) {
        unset($this->memoire[$_cle]);
    }

    public function memoryCount() {
        return count($this->memoire);
    }

    public function emit($_modele) {
        $this->modeles[] = $_modele;
        return true;
    }

    public function log($_niveau, $_message) {
        $this->journal[] = $_niveau . ' : ' . $_message;
    }

    public function now() {
        return $this->horloge;
    }

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

    /* Les réglages tels que le moteur les déposerait dans la mémoire de
     * l'adapter, à la façon de « <adapter>:rescan ». */
    public function pose($_adapter, $_reglages) {
        $this->remember($_adapter . ':settings', $_reglages);
    }

    public function derniereEtat($_mac) {
        $dernier = null;
        foreach ($this->publications as $publication) {
            if ($publication['topic'] === 'mqttbe/omg/ble/' . $_mac . '/state') {
                $dernier = $publication['payload'];
            }
        }
        return ($dernier === null || $dernier === '') ? null : json_decode($dernier, true);
    }

    public function etats($_mac) {
        $compte = 0;
        foreach ($this->publications as $publication) {
            if ($publication['topic'] === 'mqttbe/omg/ble/' . $_mac . '/state') {
                $compte++;
            }
        }
        return $compte;
    }
}

/* Le même contexte, mais qui propose setting() : c'est la forme que prendra le
 * passage des réglages de l'ordre `discovery` si le moteur les expose. Les deux
 * chemins doivent donner le même résultat. */
class MqttbeContexteReglagesOmg extends MqttbeContexteEssaiOmg {

    public $reglages = array();

    public function setting($_cle, $_defaut = null) {
        return array_key_exists($_cle, $this->reglages) ? $this->reglages[$_cle] : $_defaut;
    }
}

/* --------------------------------------------------------------------------
 * Rejeu des captures
 * ------------------------------------------------------------------------ */

function mqttbeLitJsonOmg($_nom) {
    $chemin = mqttbeCheminFixturesOmg() . '/' . $_nom;
    if (!is_readable($chemin)) {
        return null;
    }
    $decode = json_decode(file_get_contents($chemin), true);
    return is_array($decode) ? $decode : null;
}

/* Tout ce que le broker rejoue au démarrage du démon : les états retenus des
 * cinq préfixes. `retained` vaut true, parce que c'est ce qui se passe
 * réellement — et un adapter qui prendrait ces messages pour des événements
 * fabriquerait un faux redémarrage à chaque lancement. */
function mqttbeRejouePasserellesOmg($_adapter, $_ctx, $_passerelles) {
    foreach ($_passerelles as $base => $messages) {
        foreach ($messages as $feuille => $charge) {
            if ($feuille === '_' || $feuille !== 'SYStoMQTT') {
                continue;
            }
            $_adapter->onMessage($base . '/SYStoMQTT', json_encode($charge), true, $_ctx);
        }
    }
}

/* Les trames BLE, dans l'ordre du broker : une passerelle après l'autre, et
 * plusieurs trames par passerelle. */
function mqttbeRejoueBalisesOmg($_adapter, $_ctx, $_trames, $_tours = 1) {
    for ($tour = 0; $tour < $_tours; $tour++) {
        foreach ($_trames as $topic => $charges) {
            foreach ($charges as $charge) {
                $_adapter->onMessage($topic, $charge, false, $_ctx);
            }
        }
    }
}

function mqttbeModelesParUidOmg($_ctx) {
    $par = array();
    foreach ($_ctx->modeles as $modele) {
        $par[$modele->uid()] = $modele;
    }
    return $par;
}

function mqttbeCompteModelesOmg($_ctx, $_uid) {
    $compte = 0;
    foreach ($_ctx->modeles as $modele) {
        if ($modele->uid() === $_uid) {
            $compte++;
        }
    }
    return $compte;
}

/* La description d'un canal, telle qu'un message d'échec doit la citer :
 * « topic [chemin] » en lecture, « topic [charge] » en écriture. */
function mqttbeDecritCanalOmg($_canal) {
    if ($_canal === null) {
        return '(absent)';
    }
    if ($_canal->hasSink()) {
        return $_canal->sinkTopic() . ' [' . $_canal->sinkPayload() . ']';
    }
    $selecteur = $_canal->sourceSelector();
    $chemin = (isset($selecteur['type']) && $selecteur['type'] === 'json' && isset($selecteur['path']))
            ? ' [' . $selecteur['path'] . ']' : '';
    return $_canal->sourceTopic() . $chemin;
}

/* Un canal attendu : clé, capacité, et la description ci-dessus. */
function mqttbeCanalOmg($_modele, $_cle, $_capacite, $_description) {
    $canal = $_modele->channel($_cle);
    if ($canal === null) {
        return 'canal « ' . $_cle . ' » absent (attendu ' . $_capacite . ' sur ' . $_description . ')';
    }
    if ($canal->capability() !== $_capacite) {
        return 'canal « ' . $_cle . ' » : capacité « ' . $canal->capability()
             . ' », attendu « ' . $_capacite . ' »';
    }
    $obtenu = mqttbeDecritCanalOmg($canal);
    if ($obtenu !== $_description) {
        return 'canal « ' . $_cle . ' » : ' . $obtenu . ', attendu ' . $_description;
    }
    return '';
}

/* Le parc entier rejoué d'un coup : passerelles, puis balises brutes, puis un
 * battement. C'est l'ordre du démarrage du démon. */
function mqttbeParcOmg($_reglages = null) {
    $passerelles = mqttbeLitJsonOmg('passerelles.json');
    $balises     = mqttbeLitJsonOmg('balises.json');
    if ($passerelles === null || $balises === null) {
        return null;
    }
    $adapter = new MqttbeOpenMqttGateway();
    $ctx = new MqttbeContexteEssaiOmg();
    if ($_reglages !== null) {
        $ctx->pose('omg', $_reglages);
    }
    mqttbeRejouePasserellesOmg($adapter, $ctx, $passerelles['passerelles']);
    mqttbeRejoueBalisesOmg($adapter, $ctx, $balises['trames']);
    $adapter->onTick($ctx);
    return array('adapter' => $adapter, 'ctx' => $ctx,
                 'passerelles' => $passerelles, 'balises' => $balises);
}

/* ==========================================================================
 * Les contrôles
 * ======================================================================= */

function mqttbeControlesOmg() {
    $resultats = array();
    $fichier = mqttbeCheminAdapterOmg();

    if (!is_readable($fichier)) {
        return array(mqttbeIndecis('adapter OpenMQTTGateway',
            'resources/mqttbed/discovery/adapters/OpenMqttGateway.php n\'existe pas encore.'));
    }
    $source = file_get_contents($fichier);

    /* ------------------------------------------------------------------ 1 ---
     * L'adapter se charge seul, comme le fait le moteur : un fichier, une
     * classe concrète, construite sans argument. */
    $titre = 'adapter chargeable et conforme à l\'interface';
    require_once __DIR__ . '/../resources/mqttbed/discovery/adapters/OpenMqttGateway.php';
    $fautes = array();
    if (!class_exists('MqttbeOpenMqttGateway')) {
        $resultats[] = mqttbeEchec($titre, 'la classe MqttbeOpenMqttGateway n\'est pas déclarée.');
        return $resultats;
    }
    $reflexion = new ReflectionClass('MqttbeOpenMqttGateway');
    $interfaces = class_implements('MqttbeOpenMqttGateway');
    if (!isset($interfaces['MqttbeAdapter'])) {
        $fautes[] = 'la classe n\'implémente pas MqttbeAdapter : le moteur la refusera sans autre forme de procès.';
    }
    $constructeur = $reflexion->getConstructor();
    if ($constructeur !== null && $constructeur->getNumberOfRequiredParameters() > 0) {
        $fautes[] = 'le constructeur exige un argument : loadAdapters() ne l\'instanciera pas.';
    }
    $adapter = new MqttbeOpenMqttGateway();
    if ($adapter->id() === '' || $adapter->id() !== strtolower($adapter->id())) {
        $fautes[] = 'identifiant « ' . $adapter->id() . ' » : il voyage jusque dans la '
                  . 'configuration des équipements, il doit être minuscule et stable.';
    }
    if ((int) $adapter->priority() <= 0) {
        $fautes[] = 'priorité nulle ou négative.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ------------------------------------------------------------------ 2 ---
     * Les abonnements : la forme du topic, jamais `#`.
     *
     * Le préfixe d'une passerelle est libre, donc indevinable ; ce qui la
     * désigne est le niveau `SYStoMQTT` ou `BTtoMQTT`. S'abonner à `#` « pour
     * trier ensuite » ferait traverser au démon la totalité du trafic du
     * broker — avec une passerelle Zigbee, des dizaines de milliers de messages
     * par heure dont pas un ne concerne la découverte. */
    $titre = 'abonnements ciblés, jamais « # »';
    $fautes = array();
    $abonnements = $adapter->subscriptions();
    if (empty($abonnements)) {
        $fautes[] = 'aucun abonnement : l\'adapter ne verrait jamais un message.';
    }
    foreach ($abonnements as $filtre => $qos) {
        $topic = is_int($filtre) ? $qos : $filtre;
        if (strpos($topic, '#') !== false) {
            $fautes[] = $topic . ' : joker « # », c\'est tout le broker qui traverserait le démon.';
        }
        if (strpos($topic, 'SYStoMQTT') === false && strpos($topic, 'BTtoMQTT') === false) {
            $fautes[] = $topic . ' : ce filtre ne désigne pas OpenMQTTGateway.';
        }
    }
    /* Les deux profondeurs du parc réel doivent être couvertes : « bt/<nom> »
     * et « OpenMQTTGateway » nu. */
    $couvre = function ($_abonnements, $_topic) {
        foreach ($_abonnements as $cle => $valeur) {
            $filtre = is_int($cle) ? $valeur : $cle;
            if (MqttbeConfig_topicMatchesOmg($filtre, $_topic)) {
                return true;
            }
        }
        return false;
    };
    $exiges = array(
        'bt/OMG_ESP32_BLE_SAM/SYStoMQTT',
        'bt/OMG_ESP32_BLE_SAM/BTtoMQTT/D2D2D2102030',
        'OpenMQTTGateway/SYStoMQTT',
        'OpenMQTTGateway/BTtoMQTT/D2D2D2102030',
    );
    foreach ($exiges as $topic) {
        if (!$couvre($abonnements, $topic)) {
            $fautes[] = $topic . ' : aucun abonnement ne le couvre — cette passerelle-là resterait invisible.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* Les captures, sans lesquelles rien de ce qui suit n'est vérifiable. */
    $parc = mqttbeParcOmg();
    if ($parc === null) {
        $resultats[] = mqttbeIndecis('rejeu du parc',
            'tests/fixtures/omg/passerelles.json ou balises.json est absent ou illisible.');
        return $resultats;
    }
    $ctx = $parc['ctx'];
    $reperes = mqttbeReperesOmg();
    $modeles = mqttbeModelesParUidOmg($ctx);

    /* ------------------------------------------------------------------ 3 ---
     * Une passerelle complète : capteurs, actions, disponibilité. */
    $titre = 'passerelle complète : santé, actions, disponibilité';
    $base = $reperes['SAM'];
    $passerelle = isset($modeles['omg:a8b0c1001001']) ? $modeles['omg:a8b0c1001001'] : null;
    if ($passerelle === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour la passerelle '
            . $base . ' (uid attendu omg:a8b0c1001001). Modèles obtenus : '
            . implode(', ', array_keys($modeles)));
    } else {
        $fautes = array();
        $attendus = array(
            array('online',       'connectivity.online', $base . '/LWT'),
            array('temperature',  'sensor.temperature',  $base . '/SYStoMQTT [tempc]'),
            array('memory',       'device.memory',       $base . '/SYStoMQTT [freemem]'),
            array('rssi',         'connectivity.rssi',   $base . '/SYStoMQTT [rssi]'),
            array('uptime',       'device.uptime',       $base . '/SYStoMQTT [uptime]'),
            array('ip',           'generic.value',       $base . '/SYStoMQTT [ip]'),
            array('version',      'generic.value',       $base . '/SYStoMQTT [version]'),
            array('latest',       'generic.value',       $base . '/RLStoMQTT [latest_version]'),
            array('ble.state',    'switch.state',        $base . '/BTtoMQTT [enabled]'),
            array('ble.interval', 'generic.value',       $base . '/BTtoMQTT [interval]'),
            array('ble.duration', 'generic.value',       $base . '/BTtoMQTT [scanduration]'),
            array('restart',      'device.restart',      $base . '/commands/MQTTtoSYS/config [{"cmd":"restart"}]'),
            array('ble.on',       'switch.on',           $base . '/commands/MQTTtoBT/config [{"enabled":true,"save":true}]'),
            array('ble.off',      'switch.off',          $base . '/commands/MQTTtoBT/config [{"enabled":false,"save":true}]'),
            array('ble.scan',     'generic.action',      $base . '/commands/MQTTtoBT/config [{"interval":0}]'),
        );
        foreach ($attendus as $attendu) {
            $ecart = mqttbeCanalOmg($passerelle, $attendu[0], $attendu[1], $attendu[2]);
            if ($ecart !== '') {
                $fautes[] = $ecart;
            }
        }
        /* Le LWT est retenu et publié par testament : c'est lui, et non
         * l'absence de messages, qui dit qu'une passerelle a disparu. */
        $dispo = $passerelle->availability();
        if (!$passerelle->hasAvailability() || $dispo['topic'] !== $base . '/LWT'
            || $dispo['payload_on'] !== 'online' || $dispo['payload_off'] !== 'offline') {
            $fautes[] = 'disponibilité absente ou fausse : ' . json_encode($dispo);
        }
        if ($passerelle->meta('ip') !== '192.0.2.132') {
            $fautes[] = 'adresse IP non reprise du SYStoMQTT : « ' . $passerelle->meta('ip') . ' »';
        }
        if ($passerelle->deviceName() !== 'OMG_ESP32_BLE_SAM') {
            $fautes[] = 'le nom donné par l\'utilisateur à sa passerelle est perdu : « '
                      . $passerelle->deviceName() . ' »';
        }
        if ($passerelle->confidence() !== 'certain') {
            $fautes[] = 'confiance « ' . $passerelle->confidence()
                      . ' » : une passerelle qui publie son SYStoMQTT ne laisse aucun doute.';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 4 ---
     * L'identité par la `mac`, et non par le préfixe.
     *
     * Le parc réel comporte un préfixe dupliqué (« …_ETAGEOMG_ESP32_BLE_ETAGE »),
     * né d'une saisie malheureuse. Identifier une passerelle par son préfixe y
     * créerait un second équipement, avec les mêmes capteurs et les mêmes
     * actions, dont personne ne saurait lequel est le vrai. */
    $titre = 'identité par la mac : le préfixe dupliqué ne crée pas de fantôme';
    $fautes = array();
    $passerellesVues = 0;
    foreach ($modeles as $uid => $modele) {
        if (strpos($uid, 'omg:') === 0) {
            $passerellesVues++;
        }
    }
    if ($passerellesVues !== 3) {
        $fautes[] = $passerellesVues . ' passerelle(s) découverte(s), attendu 3 : seules celles qui '
                  . 'publient un SYStoMQTT ont une identité.';
    }
    /* SALON n'a qu'un LWT et un RLStoMQTT retenus : pas de `mac`, donc pas
     * d'équipement. Un adapter qui déduirait l'identité du préfixe en ferait un. */
    foreach ($modeles as $uid => $modele) {
        foreach ($modele->aliases() as $alias) {
            if (strpos($alias, 'topic:' . $reperes['SALON']) === 0) {
                $fautes[] = 'la passerelle hors ligne ' . $reperes['SALON'] . ' a produit un équipement '
                          . 'alors qu\'aucun SYStoMQTT n\'est arrivé.';
            }
        }
    }
    /* Et le cas frontal : le même SYStoMQTT sous les deux préfixes. */
    $jumeau = $parc['passerelles']['jumeau'];
    $adapterJ = new MqttbeOpenMqttGateway();
    $ctxJ = new MqttbeContexteEssaiOmg();
    $adapterJ->onMessage($reperes['ETAGE'] . '/SYStoMQTT',
        json_encode($parc['passerelles']['passerelles'][$reperes['ETAGE']]['SYStoMQTT']), true, $ctxJ);
    $adapterJ->onMessage($jumeau['base'] . '/SYStoMQTT', json_encode($jumeau['SYStoMQTT']), true, $ctxJ);
    $adapterJ->onTick($ctxJ);
    $modelesJ = mqttbeModelesParUidOmg($ctxJ);
    if (count($ctxJ->modeles) !== 1) {
        $fautes[] = 'deux préfixes pour une seule mac ont donné ' . count($ctxJ->modeles)
                  . ' modèle(s) : l\'équipement fantôme est là, avec les mêmes commandes que le vrai.';
    } elseif (isset($modelesJ['omg:a8b0c1001003'])) {
        $retenu = $modelesJ['omg:a8b0c1001003']->channel('restart');
        if ($retenu === null || $retenu->sinkTopic() !== $reperes['ETAGE'] . '/commands/MQTTtoSYS/config') {
            $fautes[] = 'le préfixe retenu n\'est pas le plus court : les ordres partiraient sur « '
                      . ($retenu === null ? '(aucun)' : $retenu->sinkTopic())
                      . ' », que la passerelle n\'écoute pas.';
        }
    } else {
        $fautes[] = 'uid inattendu : ' . implode(', ', array_keys($modelesJ));
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ------------------------------------------------------------------ 5 ---
     * Une balise vue par trois passerelles : UN équipement, trois RSSI.
     *
     * C'est le cœur de l'affaire. Trois équipements pour un objet, c'est trois
     * fois la même pile, la même température, et aucune indication de pièce —
     * alors que l'indication de pièce est précisément ce qu'on est venu
     * chercher. */
    $titre = 'une balise vue par trois passerelles : un équipement, trois RSSI';
    $mac = $reperes['BRUTE'];
    $balise = isset($modeles['ble:' . $mac]) ? $modeles['ble:' . $mac] : null;
    if ($balise === null) {
        $resultats[] = mqttbeEchec($titre, 'aucun modèle pour ble:' . $mac
            . '. Modèles obtenus : ' . implode(', ', array_keys($modeles)));
    } else {
        $fautes = array();
        if (mqttbeCompteModelesOmg($ctx, 'ble:' . $mac) !== 1) {
            $fautes[] = mqttbeCompteModelesOmg($ctx, 'ble:' . $mac) . ' modèles émis pour la même '
                      . 'balise : les trois passerelles en ont fait trois appareils.';
        }
        $attendus = array(
            'rssi.bt_OMG_ESP32_BLE_SAM'    => $reperes['SAM'] . '/BTtoMQTT/D2D2D2102030 [rssi]',
            'rssi.bt_OMG_ESP32_BLE_ETAGE'  => $reperes['ETAGE'] . '/BTtoMQTT/D2D2D2102030 [rssi]',
            'rssi.bt_OMG_ESP32_BLE_ENTREE' => $reperes['ENTREE'] . '/BTtoMQTT/D2D2D2102030 [rssi]',
        );
        foreach ($attendus as $cle => $description) {
            $ecart = mqttbeCanalOmg($balise, $cle, 'connectivity.rssi', $description);
            if ($ecart !== '') {
                $fautes[] = $ecart;
            }
        }
        /* Le nom annoncé n'arrive que dans une trame sur deux, et pas depuis
         * toutes les passerelles : il doit COLLER. */
        if ($balise->deviceName() !== 'BALISE-ESSAI 01') {
            $fautes[] = 'nom annoncé perdu (« ' . $balise->deviceName() . ' ») : il n\'est présent que '
                      . 'dans une trame sur deux, et l\'effacer fait osciller le modèle.';
        }
        /* La plus proche, en commande texte : c'est l'indication de pièce. */
        $ecart = mqttbeCanalOmg($balise, 'state.nearest', 'generic.value',
                                'mqttbe/omg/ble/' . $mac . '/state [nearest]');
        if ($ecart !== '') {
            $fautes[] = $ecart;
        }
        $etat = $ctx->derniereEtat($mac);
        if ($etat === null) {
            $fautes[] = 'aucun état publié pour la balise : présence, pièce et date de dernière vue '
                      . 'n\'atteindraient jamais Jeedom.';
        } elseif ($etat['nearest'] !== 'OMG_ESP32_BLE_SAM') {
            $fautes[] = 'la plus proche est « ' . $etat['nearest'] . ' », attendu OMG_ESP32_BLE_SAM : '
                      . 'c\'est elle qui reçoit -71 dBm, contre -92 et -98 pour les autres.';
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 6 ---
     * Le RSSI ne provoque AUCUNE réémission.
     *
     * Le contrôle qui compte. 280 messages en 165 secondes pour une seule
     * balise sur trois passerelles : si le signal entre dans ce qui décrit
     * l'appareil, c'est une réécriture de la base par message. */
    $titre = 'le RSSI ne provoque aucune réémission';
    $adapterR = new MqttbeOpenMqttGateway();
    $ctxR = new MqttbeContexteEssaiOmg();
    $topicR = $reperes['SAM'] . '/BTtoMQTT/D2D2D2102030';
    $adapterR->onMessage($topicR, '{"id":"D2:D2:D2:10:20:30","mac_type":1,"name":"BALISE-ESSAI 01",'
        . '"manufacturerdata":"a705","rssi":-70,"txpower":0}', false, $ctxR);
    $adapterR->onTick($ctxR);
    $apresPremier = count($ctxR->modeles);
    for ($i = 0; $i < 140; $i++) {
        $rssi = -60 - ($i % 40);
        $adapterR->onMessage($topicR, '{"id":"D2:D2:D2:10:20:30","mac_type":1,"name":"BALISE-ESSAI 01",'
            . '"manufacturerdata":"a705","rssi":' . $rssi . ',"txpower":0}', false, $ctxR);
        if ($i % 10 === 0) {
            $ctxR->avance(1);
            $adapterR->onTick($ctxR);
        }
    }
    $fautes = array();
    if ($apresPremier !== 1) {
        $fautes[] = $apresPremier . ' modèle(s) après la première trame, attendu 1.';
    }
    if (count($ctxR->modeles) !== 1) {
        $fautes[] = count($ctxR->modeles) . ' modèles émis pour 141 trames qui ne diffèrent que par '
                  . 'le RSSI : chacune réécrirait l\'équipement en base.';
    }
    /* Et rien n'est émis depuis la réception : le modèle ne part qu'au
     * battement. Une balise qui arrive entre deux battements ne coûte rien. */
    $adapterM = new MqttbeOpenMqttGateway();
    $ctxM = new MqttbeContexteEssaiOmg();
    $adapterM->onMessage($topicR, '{"id":"D2:D2:D2:10:20:30","rssi":-70,"tempc":20}', false, $ctxM);
    if (!empty($ctxM->modeles)) {
        $fautes[] = 'un modèle a été émis depuis onMessage() : sur un parc bavard, c\'est une '
                  . 'construction de modèle par message reçu.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ------------------------------------------------------------------ 7 ---
     * Une balise décodée est créée d'office ; une balise brute attend.
     *
     * `certain` = la passerelle l'a reconnue, c'est un capteur. `guess` = on ne
     * sait pas ce que c'est : le modèle part quand même — sinon personne ne
     * saurait qu'il y a là quelque chose à adopter — mais il atterrit dans la
     * file d'adoption au lieu d'être créé. */
    $titre = 'décodée : créée d\'office ; brute : proposée, jamais créée';
    $decodees = mqttbeLitJsonOmg('decodees.json');
    if ($decodees === null) {
        $resultats[] = mqttbeIndecis($titre, 'tests/fixtures/omg/decodees.json est absent ou illisible.');
        return $resultats;
    }
    $adapterD = new MqttbeOpenMqttGateway();
    $ctxD = new MqttbeContexteEssaiOmg();
    mqttbeRejouePasserellesOmg($adapterD, $ctxD, $parc['passerelles']['passerelles']);
    mqttbeRejoueBalisesOmg($adapterD, $ctxD, $parc['balises']['trames']);
    mqttbeRejoueBalisesOmg($adapterD, $ctxD, $decodees['trames']);
    $adapterD->onTick($ctxD);
    $modelesD = mqttbeModelesParUidOmg($ctxD);
    $fautes = array();
    $confiances = array(
        'ble:' . $reperes['CAPTEUR'] => 'certain',
        'ble:a8b0c1003003'           => 'certain',
        'ble:a8b0c1003004'           => 'certain',
        'ble:' . $reperes['TRACEUR'] => 'certain',
        'ble:' . $reperes['BRUTE']   => 'guess',
        'ble:a8b0c1002002'           => 'guess',
    );
    foreach ($confiances as $uid => $attendue) {
        if (!isset($modelesD[$uid])) {
            $fautes[] = $uid . ' : aucun modèle. Une balise vue et jamais décrite est une balise que '
                      . 'l\'utilisateur ne pourra jamais adopter.';
            continue;
        }
        $obtenue = $modelesD[$uid]->confidence();
        if ($obtenue !== $attendue) {
            $fautes[] = $uid . ' : confiance « ' . $obtenue . ' », attendu « ' . $attendue . ' »'
                      . ($attendue === 'guess'
                         ? ' — une trame brute créée d\'office, c\'est deux cents équipements en une semaine.'
                         : ' — la passerelle l\'a décodée, c\'est un capteur.');
        }
    }
    /* `probable` veut dire « je sais ce que c'est mais pas encore tout », et
     * ces modèles-là SONT créés : l'employer pour une balise brute ferait
     * exactement ce qu'on cherche à éviter. */
    foreach ($modelesD as $uid => $modele) {
        if (strpos($uid, 'ble:') === 0 && $modele->confidence() === 'probable') {
            $fautes[] = $uid . ' : confiance « probable », qui est créée sans rien demander.';
        }
    }
    /* Le traceur n'est reconnu que par le nom d'un champ — `track` — et par le
     * `model` que la passerelle lui donne. Aucun catalogue n'intervient. */
    if (isset($modelesD['ble:' . $reperes['TRACEUR']])) {
        $traceur = $modelesD['ble:' . $reperes['TRACEUR']];
        $ecart = mqttbeCanalOmg($traceur, 'track', 'presence.detected',
            'bt/OMG_ESP32_BLE_SAM/BTtoMQTT/A8B0C1003006 [track]');
        if ($ecart !== '') {
            $fautes[] = $ecart;
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ------------------------------------------------------------------ 8 ---
     * De quoi trancher dans la file d'adoption.
     *
     * Sur le parc d'essai, deux balises brutes sur trois valent la peine d'être
     * adoptées et une est un passant : c'est exactement ce que l'utilisateur
     * doit distinguer d'un coup d'œil. Une adresse publique est gravée dans le
     * matériel ; une adresse aléatoire peut tourner toutes les quinze minutes,
     * et l'équipement créé aujourd'hui ne désignera plus rien demain. */
    $titre = 'file d\'adoption : nom, passerelles, type d\'adresse';
    $fautes = array();
    $file = $adapterD->fileAdoption($ctxD);
    $parUid = array();
    foreach ($file as $entree) {
        $parUid[$entree['uid']] = $entree;
    }
    if (!isset($parUid['ble:' . $reperes['BRUTE']])) {
        $fautes[] = 'la balise brute n\'est pas dans la file d\'adoption.';
    } else {
        $entree = $parUid['ble:' . $reperes['BRUTE']];
        if ($entree['nom'] !== 'BALISE-ESSAI 01') {
            $fautes[] = 'file d\'adoption : nom annoncé absent (« ' . $entree['nom'] . ' »).';
        }
        if ((int) $entree['passerelles'] !== 3) {
            $fautes[] = 'file d\'adoption : ' . $entree['passerelles'] . ' passerelle(s), attendu 3 — '
                      . 'une balise vue par toute la maison y habite, une balise vue par une seule passe dans la rue.';
        }
        if ($entree['adressage'] !== 'random') {
            $fautes[] = 'file d\'adoption : type d\'adresse « ' . $entree['adressage'] . ' », attendu random.';
        }
    }
    if (isset($parUid['ble:a8b0c1002002']) && $parUid['ble:a8b0c1002002']['adressage'] !== 'public') {
        $fautes[] = 'file d\'adoption : mac_type 0 doit donner une adresse « public », stable et adoptable.';
    }
    /* Et la même chose dans le modèle, qui est ce que Jeedom reçoit. */
    if (isset($modelesD['ble:' . $reperes['BRUTE']])) {
        $brute = $modelesD['ble:' . $reperes['BRUTE']];
        if ($brute->deviceName() !== 'BALISE-ESSAI 01') {
            $fautes[] = 'le modèle d\'une balise brute ne porte pas son nom annoncé : la file '
                      . 'd\'adoption n\'aurait qu\'une adresse à montrer.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ------------------------------------------------------------------ 9 ---
     * La table champ → capacité.
     *
     * Aucun catalogue d'appareils : des noms de champs, et rien d'autre. C'est
     * ce qui fait qu'une mise à jour de la passerelle — qui apporte des
     * décodeurs, pas des noms de champs nouveaux — fait apparaître les
     * commandes sans qu'on touche au plugin. */
    $titre = 'table champ → capacité';
    $fautes = array();
    foreach ($decodees['attendus'] as $uid => $champs) {
        if ($uid === '_') {
            continue;
        }
        if (!isset($modelesD[$uid])) {
            $fautes[] = $uid . ' : aucun modèle.';
            continue;
        }
        foreach ($champs as $champ => $capacite) {
            $canal = $modelesD[$uid]->channel($champ);
            if ($canal === null) {
                $fautes[] = $uid . ' : champ « ' . $champ . ' » sans commande (attendu ' . $capacite . ').';
            } elseif ($canal->capability() !== $capacite) {
                $fautes[] = $uid . ' : champ « ' . $champ . ' » → « ' . $canal->capability()
                          . ' », attendu « ' . $capacite . ' ».';
            }
        }
    }
    /* Le Fahrenheit converti : sans facteur, l'utilisateur lit 71,6 sur un
     * thermomètre qui affiche 22, et en conclut que le plugin est faux. */
    if (isset($modelesD['ble:a8b0c1003002'])) {
        $canal = $modelesD['ble:a8b0c1003002']->channel('tempf');
        $valeur = ($canal === null) ? array() : $canal->value();
        $transform = isset($valeur['transform']) ? $valeur['transform'] : array();
        if ($canal === null || $canal->capability() !== 'sensor.temperature') {
            $fautes[] = 'tempf seul : pas de commande de température.';
        } elseif (!isset($transform['scale']) || abs($transform['scale'] - 5 / 9) > 0.001
                  || !isset($transform['offset']) || abs($transform['offset'] + 17.777778) > 0.01) {
            $fautes[] = 'tempf non converti (' . json_encode($transform) . ') : 71,6 °F doivent '
                      . 'donner 22 °C, et non 71,6 sur une commande étiquetée °C.';
        } elseif ($canal->unit() !== '°C') {
            $fautes[] = 'tempf : unité « ' . $canal->unit() .' », attendu °C.';
        }
    }
    /* Les deux unités du même capteur ne font pas deux capteurs. */
    if (isset($modelesD['ble:' . $reperes['CAPTEUR']])
        && $modelesD['ble:' . $reperes['CAPTEUR']]->channel('tempf') !== null) {
        $fautes[] = 'tempc et tempf sur le même appareil ont donné deux températures : c\'est la '
                  . 'même, dans deux unités.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 10 ---
     * Un champ inconnu devient une valeur générique.
     *
     * Mieux vaut une valeur brute exploitable dans un scénario qu'une donnée
     * perdue — et c'est ce qui fait qu'un décodeur ajouté à la passerelle
     * remonte tout de suite, même si personne n'a prévu son champ. */
    $titre = 'un champ inconnu devient une valeur générique';
    $fautes = array();
    if (!isset($modelesD['ble:a8b0c1003007'])) {
        $fautes[] = 'aucun modèle pour la balise aux champs inattendus.';
    } else {
        $bizarre = $modelesD['ble:a8b0c1003007'];
        $ecart = mqttbeCanalOmg($bizarre, 'tilt', 'generic.value',
            'bt/OMG_ESP32_BLE_SAM/BTtoMQTT/A8B0C1003007 [tilt]');
        if ($ecart !== '') {
            $fautes[] = $ecart;
        }
        $distance = $bizarre->channel('distance');
        if ($distance === null || $distance->unit() !== 'm') {
            $fautes[] = 'distance : valeur générique attendue, avec son unité.';
        }
        /* Un nom de champ qui contient un point ne peut pas servir de chemin
         * JSON : le routage l'éclate sur les points et désignerait un
         * sous-objet qui n'existe pas. */
        if ($bizarre->channel('a.b') !== null) {
            $fautes[] = 'un champ « a.b » a produit une commande : le chemin JSON étant éclaté sur '
                      . 'les points, elle ne remonterait jamais rien.';
        }
        if ($bizarre->channel('imbrique') !== null) {
            $fautes[] = 'un sous-objet a produit une commande : le routage n\'en tire aucune valeur.';
        }
        /* Et surtout : les blocs hexadécimaux ne deviennent pas des commandes. */
        foreach (array('manufacturerdata', 'servicedata', 'id', 'name', 'model') as $interdit) {
            foreach ($modelesD as $modele) {
                if ($modele->channel($interdit) !== null) {
                    $fautes[] = 'le champ « ' . $interdit . ' » a produit une commande : c\'est une '
                              . 'métadonnée ou un bloc que la passerelle décode, pas une mesure.';
                    break;
                }
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 11 ---
     * Une mesure remonte par n'importe quelle passerelle.
     *
     * Le capteur du salon est vu par deux passerelles. Câbler sa température
     * sur l'une des deux, c'est un capteur qui se tait le jour où l'on
     * débranche la mauvaise. */
    $titre = 'une mesure remonte par n\'importe quelle passerelle';
    $fautes = array();
    if (isset($modelesD['ble:' . $reperes['CAPTEUR']])) {
        $capteur = $modelesD['ble:' . $reperes['CAPTEUR']];
        $ecart = mqttbeCanalOmg($capteur, 'tempc', 'sensor.temperature',
            'bt/+/BTtoMQTT/A8B0C1003001 [tempc]');
        if ($ecart !== '') {
            $fautes[] = $ecart . "\n  — le filtre doit couvrir les deux passerelles qui voient ce capteur.";
        }
        /* Mais le dernier niveau n'est JAMAIS généralisé : `bt/+/BTtoMQTT/+`
         * ferait remonter la température de toutes les balises de la maison sur
         * un seul équipement. */
        foreach ($capteur->channels() as $canal) {
            if (substr($canal->sourceTopic(), -2) === '/+') {
                $fautes[] = 'canal « ' . $canal->key() . ' » : le dernier niveau du topic est un joker ('
                          . $canal->sourceTopic() . ') — toutes les balises de la maison alimenteraient '
                          . 'cette commande.';
            }
        }
    } else {
        $fautes[] = 'aucun modèle pour le capteur vu par deux passerelles.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 12 ---
     * L'absence, qui est l'information principale d'un traceur.
     *
     * Une balise ne dit rien quand elle part : elle cesse d'émettre. Sans ce
     * calcul, l'équipement reste au dernier RSSI reçu et affirme qu'un objet
     * parti depuis trois jours est dans le salon. */
    $titre = 'absence après le délai, et la pièce qui se vide';
    $fautes = array();
    $adapterA = new MqttbeOpenMqttGateway();
    $ctxA = new MqttbeContexteEssaiOmg();
    $ctxA->pose('omg', array('bleAwayDelay' => 120, 'bleAdoptAll' => false));
    mqttbeRejoueBalisesOmg($adapterA, $ctxA, $parc['balises']['trames']);
    $adapterA->onTick($ctxA);
    $etat = $ctxA->derniereEtat($reperes['BRUTE']);
    if ($etat === null) {
        $fautes[] = 'aucun état publié tant que la balise est là.';
    } else {
        if ((int) $etat['presence'] !== 1) {
            $fautes[] = 'balise vue à l\'instant, déclarée absente.';
        }
        if ($etat['nearest'] === '') {
            $fautes[] = 'balise présente sans passerelle la plus proche.';
        }
        if (trim((string) $etat['seen']) === '') {
            $fautes[] = 'aucune date de dernière vue : c\'est pourtant ce qu\'on regarde quand on '
                      . 'cherche un objet.';
        }
    }
    /* Le silence, maintenant. Rien n'arrive — et c'est bien le fait qu'il
     * n'arrive rien qui doit être mesuré : aucun message ne viendra déclencher
     * ce calcul. */
    $ctxA->avance(60);
    $adapterA->onTick($ctxA);
    $etat = $ctxA->derniereEtat($reperes['BRUTE']);
    if ($etat !== null && (int) $etat['presence'] !== 1) {
        $fautes[] = 'balise déclarée absente après 60 s alors que le délai est de 120 s : un traceur '
                  . 'n\'émet pas en continu, et le déclarer parti trop tôt fait chercher un objet qui est là.';
    }
    $ctxA->avance(200);
    $adapterA->onTick($ctxA);
    $etat = $ctxA->derniereEtat($reperes['BRUTE']);
    if ($etat === null) {
        $fautes[] = 'aucun état publié après le délai.';
    } else {
        if ((int) $etat['presence'] !== 0) {
            $fautes[] = 'balise silencieuse depuis 260 s toujours déclarée présente (délai 120 s).';
        }
        if ($etat['nearest'] !== '') {
            $fautes[] = 'la balise est absente et la pièce indiquée est « ' . $etat['nearest']
                      . ' » : absente, elle n\'est nulle part, et ce champ doit se vider plutôt que '
                      . 'de mentir à l\'endroit exact où l\'on vient chercher son objet.';
        }
    }
    /* Elle revient : la présence repart, sans qu\'un nouvel équipement naisse. */
    $ctxA->avance(10);
    mqttbeRejoueBalisesOmg($adapterA, $ctxA, $parc['balises']['trames']);
    $adapterA->onTick($ctxA);
    $etat = $ctxA->derniereEtat($reperes['BRUTE']);
    if ($etat === null || (int) $etat['presence'] !== 1) {
        $fautes[] = 'la balise revenue n\'est pas redevenue présente.';
    }
    if (mqttbeCompteModelesOmg($ctxA, 'ble:' . $reperes['BRUTE']) !== 1) {
        $fautes[] = 'aller et retour : ' . mqttbeCompteModelesOmg($ctxA, 'ble:' . $reperes['BRUTE'])
                  . ' modèles émis, attendu 1 — la présence est une valeur, pas une raison de réémettre.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 13 ---
     * L'état n'est publié que quand il change.
     *
     * Publier à chaque trame reviendrait à rendre au broker les 280 messages
     * qu'on vient d'en recevoir. */
    $titre = 'l\'état calculé n\'est publié que lorsqu\'il change';
    $fautes = array();
    $adapterP = new MqttbeOpenMqttGateway();
    $ctxP = new MqttbeContexteEssaiOmg();
    $topicP = $reperes['SAM'] . '/BTtoMQTT/A8B0C1003001';
    for ($i = 0; $i < 60; $i++) {
        $adapterP->onMessage($topicP,
            '{"id":"A8:B0:C1:00:30:01","mac_type":0,"rssi":' . (-60 - ($i % 5)) . ',"tempc":21.4}',
            false, $ctxP);
        $adapterP->onTick($ctxP);
        $ctxP->avance(0.5);
    }
    $publies = $ctxP->etats($reperes['CAPTEUR']);
    if ($publies > 2) {
        $fautes[] = $publies . ' états publiés pour 60 trames sur 30 secondes : la présence n\'a pas '
                  . 'changé, la pièce non plus, et la date de dernière vue se lit à la minute.';
    }
    if ($publies < 1) {
        $fautes[] = 'aucun état publié : les commandes de présence resteraient vides.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 14 ---
     * La pièce ne clignote pas.
     *
     * Deux passerelles à distance comparable, et le RSSI qui respire de deux ou
     * trois dB : sans hystérésis, le champ bascule à chaque trame, et Jeedom
     * historise une entrée par seconde pour un objet qui n'a pas bougé. */
    $titre = 'la passerelle la plus proche ne clignote pas';
    $fautes = array();
    $adapterH = new MqttbeOpenMqttGateway();
    $ctxH = new MqttbeContexteEssaiOmg();
    $topicA = $reperes['SAM'] . '/BTtoMQTT/A8B0C1003001';
    $topicB = $reperes['ETAGE'] . '/BTtoMQTT/A8B0C1003001';
    /* Les deux passerelles se CROISENT : -70…-67 d'un côté, -71…-68 de
     * l'autre, jamais plus de quatre dB d'écart. C'est exactement ce que
     * donne un objet posé entre deux pièces, et un « meilleur signal
     * l'emporte » sans marge y change d'avis une fois sur deux. */
    for ($i = 0; $i < 30; $i++) {
        $adapterH->onMessage($topicA, '{"id":"A8:B0:C1:00:30:01","rssi":' . (-70 + ($i % 4)) . ',"tempc":21}', false, $ctxH);
        $adapterH->onMessage($topicB, '{"id":"A8:B0:C1:00:30:01","rssi":' . (-71 + (($i * 3) % 4)) . ',"tempc":21}', false, $ctxH);
        $adapterH->onTick($ctxH);
        $ctxH->avance(1);
    }
    $pieces = array();
    foreach ($ctxH->publications as $publication) {
        if ($publication['topic'] !== 'mqttbe/omg/ble/a8b0c1003001/state') {
            continue;
        }
        $decode = json_decode($publication['payload'], true);
        if (is_array($decode)) {
            $pieces[] = $decode['nearest'];
        }
    }
    $bascules = 0;
    for ($i = 1; $i < count($pieces); $i++) {
        if ($pieces[$i] !== $pieces[$i - 1]) {
            $bascules++;
        }
    }
    if ($bascules > 0) {
        $fautes[] = $bascules . ' bascule(s) de pièce alors que la balise n\'a pas bougé : deux ou '
                  . 'trois dB de respiration du signal suffisent à faire clignoter l\'indication.';
    }
    /* Mais un vrai déplacement, lui, doit se voir. */
    for ($i = 0; $i < 5; $i++) {
        $adapterH->onMessage($topicB, '{"id":"A8:B0:C1:00:30:01","rssi":-50,"tempc":21}', false, $ctxH);
        $adapterH->onTick($ctxH);
        $ctxH->avance(1);
    }
    $etat = $ctxH->derniereEtat('a8b0c1003001');
    if ($etat === null || $etat['nearest'] !== 'OMG_ESP32_BLE_ETAGE') {
        $fautes[] = 'la balise est passée de -71 à -50 dBm sur l\'autre passerelle et la pièce n\'a '
                  . 'pas suivi : l\'hystérésis a été poussée jusqu\'à l\'immobilité.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 15 ---
     * L'inventaire est plafonné.
     *
     * Une passerelle BLE voit tout ce qui passe : téléphones des visiteurs,
     * montres, écouteurs, balises des voisins. C'est le seul endroit de ce
     * plugin où un démon peut enfler sans limite — et la mémoire du moteur est
     * bornée à 512 clés par adapter. */
    $titre = 'inventaire plafonné : le démon n\'enfle pas';
    $fautes = array();
    $adapterI = new MqttbeOpenMqttGateway();
    $ctxI = new MqttbeContexteEssaiOmg();
    for ($i = 0; $i < 900; $i++) {
        $adresse = sprintf('C0FFEE%06X', $i);
        $adapterI->onMessage($reperes['ENTREE'] . '/BTtoMQTT/' . $adresse,
            '{"id":"' . $adresse . '","mac_type":1,"manufacturerdata":"a705","rssi":-90}', false, $ctxI);
        $ctxI->avance(0.1);
    }
    $vues = count($adapterI->balises($ctxI));
    if ($vues > 250) {
        $fautes[] = $vues . ' balises en inventaire pour 900 vues : rien ne borne la mémoire, et le '
                  . 'démon grandit toute la nuit sans que rien ne le dise.';
    }
    if ($vues < 100) {
        $fautes[] = $vues . ' balises seulement : le plafond est si bas qu\'une maison ordinaire '
                  . 'perdrait ses propres capteurs.';
    }
    if ($ctxI->refus > 0) {
        $fautes[] = $ctxI->refus . ' clé(s) refusée(s) par la mémoire du moteur : l\'inventaire doit '
                  . 'se borner lui-même bien avant le plafond de 512 clés, sinon c\'est le moteur qui '
                  . 'décide, en journalisant un défaut de conception.';
    }
    if ($ctxI->memoryCount() > MqttbeContexteEssaiOmg::MEMOIRE_MAX) {
        $fautes[] = 'mémoire à ' . $ctxI->memoryCount() . ' clés.';
    }
    /* Et ce qui reste doit être ce qui est passé le plus récemment : une
     * balise vue il y a une heure intéresse moins que celle qui passe. */
    $restantes = $adapterI->balises($ctxI);
    if (!empty($restantes) && !in_array(strtolower(sprintf('c0ffee%06x', 899)), $restantes, true)) {
        $fautes[] = 'la dernière balise vue n\'est pas dans l\'inventaire : le plafond refuse le '
                  . 'nouveau plutôt que d\'évincer le plus ancien.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 16 ---
     * Et il se purge. Une balise vue une fois puis plus jamais ne doit pas
     * encombrer la file — mais un capteur décodé qui se tait, si : son silence
     * est justement ce que son équipement rapporte. */
    $titre = 'péremption : le passant s\'oublie, le capteur reste';
    $fautes = array();
    $adapterX = new MqttbeOpenMqttGateway();
    $ctxX = new MqttbeContexteEssaiOmg();
    $adapterX->onMessage($reperes['ENTREE'] . '/BTtoMQTT/A8B0C1009999',
        '{"id":"A8:B0:C1:00:99:99","mac_type":1,"manufacturerdata":"a705","rssi":-95}', false, $ctxX);
    $adapterX->onMessage($reperes['ENTREE'] . '/BTtoMQTT/A8B0C1003001',
        '{"id":"A8:B0:C1:00:30:01","mac_type":0,"tempc":21.4,"batt":86,"rssi":-80}', false, $ctxX);
    $adapterX->onTick($ctxX);
    $ctxX->avance(4 * 3600);
    $adapterX->onTick($ctxX);
    $restantes = $adapterX->balises($ctxX);
    if (in_array('a8b0c1009999', $restantes, true)) {
        $fautes[] = 'une balise jamais décodée et jamais revue depuis quatre heures est toujours en '
                  . 'inventaire : la file d\'adoption se remplirait de passants.';
    }
    if (!in_array('a8b0c1003001', $restantes, true)) {
        $fautes[] = 'un capteur décodé a été oublié parce qu\'il s\'est tu : son silence est '
                  . 'précisément ce que son équipement doit rapporter, et sa présence resterait '
                  . 'figée à la dernière valeur publiée.';
    }
    /* L'état retenu du passant est effacé : sans cela, chaque téléphone passé
     * devant la maison laisserait sur le broker un message qui y resterait
     * pour toujours. */
    $efface = false;
    foreach ($ctxX->publications as $publication) {
        if ($publication['topic'] === 'mqttbe/omg/ble/a8b0c1009999/state'
            && $publication['payload'] === '' && $publication['retain']) {
            $efface = true;
        }
    }
    if (!$efface) {
        $fautes[] = 'le message retenu du passant n\'est pas effacé : le broker garderait un état par '
                  . 'téléphone jamais revu, indéfiniment.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 17 ---
     * Les réglages de l'ordre `discovery`, par les deux chemins et par défaut. */
    $titre = 'réglages bleAwayDelay et bleAdoptAll lus, et défauts sinon';
    $fautes = array();
    $ctxDefaut = new MqttbeContexteEssaiOmg();
    $defauts = $adapter->reglages($ctxDefaut);
    if ((int) $defauts['away'] !== 300 || $defauts['adoptAll'] !== false) {
        $fautes[] = 'sans réglage transmis : away=' . $defauts['away'] . ', adoptAll='
                  . var_export($defauts['adoptAll'], true) . ', attendu 300 et false — un Jeedom qui '
                  . 'n\'envoie pas encore la clé doit se comporter comme le fichier ini, pas comme un '
                  . 'utilisateur qui aurait tout décoché.';
    }
    $ctxMem = new MqttbeContexteEssaiOmg();
    $ctxMem->pose('omg', array('bleAwayDelay' => 900, 'bleAdoptAll' => 1));
    $lus = $adapter->reglages($ctxMem);
    if ((int) $lus['away'] !== 900 || $lus['adoptAll'] !== true) {
        $fautes[] = 'réglages déposés en mémoire non lus : away=' . $lus['away'] . ', adoptAll='
                  . var_export($lus['adoptAll'], true) . '.';
    }
    $ctxSet = new MqttbeContexteReglagesOmg();
    $ctxSet->reglages = array('bleAwayDelay' => 60, 'bleAdoptAll' => true);
    $lus = $adapter->reglages($ctxSet);
    if ((int) $lus['away'] !== 60 || $lus['adoptAll'] !== true) {
        $fautes[] = 'réglages exposés par le contexte non lus : away=' . $lus['away'] . '.';
    }
    /* Un délai de zéro déclarerait toute balise absente à l'instant où elle est
     * vue, et l'équipement clignoterait indéfiniment. */
    $ctxZero = new MqttbeContexteEssaiOmg();
    $ctxZero->pose('omg', array('bleAwayDelay' => 0));
    if ((int) $adapter->reglages($ctxZero)['away'] < 1) {
        $fautes[] = 'un délai de zéro est accepté tel quel : la présence clignoterait sans fin.';
    }
    /* Et le réglage doit vraiment agir : bleAdoptAll fait passer les balises
     * brutes en `certain`, c'est son sens même. */
    $parcTout = mqttbeParcOmg(array('bleAwayDelay' => 300, 'bleAdoptAll' => true));
    $modelesT = mqttbeModelesParUidOmg($parcTout['ctx']);
    if (!isset($modelesT['ble:' . $reperes['BRUTE']])
        || $modelesT['ble:' . $reperes['BRUTE']]->confidence() !== 'certain') {
        $fautes[] = 'bleAdoptAll actif : une balise brute doit passer en « certain » et être créée, '
                  . 'c\'est le sens du réglage.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 18 ---
     * Aucun modèle invalide, aucune clé ni aucun nom en double.
     *
     * Deux commandes de même nom sur un équipement font échouer
     * l'enregistrement de tout l'équipement (cmd (eqLogic_id, name) est
     * unique) : le modèle part, et rien n'est créé. */
    $titre = 'modèles valides, clés et noms uniques';
    $fautes = array();
    MqttbeChannel::loadCapabilities(mqttbeRacine() . '/core/config/capabilities.json');
    foreach (array($ctx, $ctxD) as $contexte) {
        foreach ($contexte->modeles as $modele) {
            $mauvais = $modele->validate();
            if (!empty($mauvais)) {
                $fautes[] = $modele->uid() . ' : ' . implode(' ', $mauvais);
            }
            $noms = array();
            foreach ($modele->channels() as $canal) {
                $nom = $canal->name();
                if (isset($noms[$nom])) {
                    $fautes[] = $modele->uid() . ' : deux commandes nommées « ' . $nom . ' » ('
                              . $noms[$nom] . ' et ' . $canal->key() . ').';
                }
                $noms[$nom] = $canal->key();
            }
        }
    }
    MqttbeChannel::useCapabilities(null);
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 19 ---
     * Le trafic voisin ne casse rien.
     *
     * « home », sur une branche BTtoMQTT, n'est pas du JSON : c'est un autre
     * système qui publie ses présences au même endroit, et le broker le rejoue
     * à chaque démarrage du démon. */
    $titre = 'une charge utile illisible est jetée sans dégât';
    $fautes = array();
    $adapterZ = new MqttbeOpenMqttGateway();
    $ctxZ = new MqttbeContexteEssaiOmg();
    foreach (array('home', '', '[]', '{"id":"pas une mac"}', 'null', '{"id":') as $charge) {
        $adapterZ->onMessage('OpenMQTTGateway/BTtoMQTT/C5:F6:50:A5:28:0F', $charge, true, $ctxZ);
    }
    $adapterZ->onMessage('bt/+/SYStoMQTT', '{"mac":"A8:B0:C1:00:10:09"}', true, $ctxZ);
    $adapterZ->onMessage('bt/#/SYStoMQTT', '{"mac":"A8:B0:C1:00:10:0A"}', true, $ctxZ);
    $adapterZ->onTick($ctxZ);
    if (!empty($ctxZ->modeles)) {
        $fautes[] = count($ctxZ->modeles) . ' modèle(s) tirés de charges utiles illisibles ou de '
                  . 'préfixes contenant un joker — un topic de publication à joker fait fermer la '
                  . 'connexion par le broker au premier appui sur un bouton.';
    }
    if (!empty($adapterZ->balises($ctxZ))) {
        $fautes[] = 'du trafic voisin a ouvert un dossier de balise.';
    }
    foreach ($ctxZ->journal as $ligne) {
        if (strpos($ligne, 'error') === 0) {
            $fautes[] = 'journalisé en erreur : ' . $ligne . ' — une charge utile illisible est un '
                      . 'incident ordinaire, pas une erreur de programme.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 20 ---
     * La relance de l'utilisateur renvoie tout le parc. */
    $titre = 'relancer la découverte fait tout repartir';
    $fautes = array();
    $parcR = mqttbeParcOmg();
    $avant = count($parcR['ctx']->modeles);
    $parcR['adapter']->onTick($parcR['ctx']);
    if (count($parcR['ctx']->modeles) !== $avant) {
        $fautes[] = 'un battement de plus a réémis des modèles sans que rien ne change.';
    }
    $parcR['ctx']->relance = true;
    $parcR['adapter']->onTick($parcR['ctx']);
    if (count($parcR['ctx']->modeles) !== 2 * $avant) {
        $fautes[] = 'après « relancer la découverte », ' . (count($parcR['ctx']->modeles) - $avant)
                  . ' modèle(s) réémis sur ' . $avant . ' : c\'est le bouton qu\'on presse quand un '
                  . 'équipement a été supprimé par erreur, et il doit vraiment tout renvoyer.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ----------------------------------------------------------------- 21 ---
     * Aucune dépendance à Home Assistant.
     *
     * La passerelle publie aussi son parc en découverte Home Assistant. Lire
     * ces messages-là, c'est faire dépendre le plugin d'un réglage d'un AUTRE
     * système : l'utilisateur décoche « Auto discovery » sur sa passerelle, et
     * Jeedom cesse de voir quoi que ce soit sans qu'un mot l'explique. */
    $titre = 'aucune trace de « homeassistant » dans l\'adapter';
    $fautes = array();
    if (stripos($source, 'homeassistant') !== false) {
        $fautes[] = 'la chaîne « homeassistant » figure dans l\'adapter.';
    }
    foreach (mqttbeChaines($source) as $chaine) {
        if (stripos($chaine, 'homeassistant') !== false || stripos($chaine, 'hass') !== false) {
            $fautes[] = 'chaîne « ' . $chaine . ' » : la découverte Home Assistant n\'a pas à '
                      . 'traverser cet adapter.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "les topics natifs disent tout, et c'est précisément pour s'en servir ailleurs qu'on les lit.\n"
        . implode("\n", $fautes));

    /* ----------------------------------------------------------------- 22 ---
     * Aucun catalogue, aucun décodage.
     *
     * Décoder est le métier de la passerelle — c'est là qu'est Theengs, avec ses
     * centaines de modèles, mis à jour à chaque version du micrologiciel. Un
     * catalogue recopié ici serait périmé le jour de son écriture. */
    $titre = 'aucun catalogue de balises, aucun décodage de manufacturerdata';
    $fautes = array();
    $identifiants = mqttbeIdentifiants($source);
    foreach ($identifiants as $identifiant) {
        if (preg_match('/^(hexdec|bindec|unpack|pack|base_convert|bin2hex|hex2bin)$/i', $identifiant)) {
            $fautes[] = 'l\'adapter appelle ' . $identifiant . '() : décoder une trame est le métier '
                      . 'de la passerelle, et elle le fait mieux.';
        }
        if (preg_match('/^(file_get_contents|fopen|glob|is_readable|scandir|include|require)$/i', $identifiant)
            && strpos($source, 'Adapter.php') === false) {
            $fautes[] = 'l\'adapter lit un fichier (' . $identifiant . ') : un catalogue de modèles '
                      . 'n\'a pas sa place ici.';
        }
        /* Et rien de ce qui bloquerait la boucle qui lit la socket du broker. */
        if (preg_match('/^(curl_|fsockopen|stream_socket_client|sleep|usleep|file_get_contents)/i', $identifiant)
            && $identifiant !== 'file_get_contents') {
            $fautes[] = 'l\'adapter appelle ' . $identifiant . '() : il tourne dans la boucle qui lit '
                      . 'la socket du broker, et une seconde d\'attente est une seconde pendant '
                      . 'laquelle le keepalive MQTT court.';
        }
    }
    /* `manufacturerdata` ne doit apparaître que comme un nom de champ à
     * ÉCARTER, jamais comme quelque chose qu\'on lit. */
    foreach (mqttbeChaines($source) as $chaine) {
        if (stripos($chaine, 'manufacturerdata') !== false
            && $chaine !== 'manufacturerdata' && $chaine !== 'manufacturerdata2') {
            $fautes[] = 'chaîne « ' . $chaine . ' » : le contenu de manufacturerdata n\'est pas lu ici.';
        }
        /* Un identifiant de constructeur, c'est un catalogue qui commence. */
        if (preg_match('/^[0-9a-fA-F]{8,}$/', $chaine)) {
            $fautes[] = 'constante hexadécimale « ' . $chaine . ' » : rien ici ne doit reconnaître un '
                      . 'appareil à sa signature binaire.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "l'adapter traduit des noms de champs en capacités ; le jour où la passerelle décode un\n"
        . "capteur de plus, les commandes apparaissent sans qu'on touche au plugin.\n"
        . implode("\n", $fautes));

    /* ----------------------------------------------------------------- 23 ---
     * Aucune capacité inventée.
     *
     * Une capacité absente de capabilities.json donne une commande sans type
     * générique, qui n'apparaît ni dans la vue Maison ni dans les widgets. */
    $titre = 'toutes les capacités employées existent dans capabilities.json';
    $vocabulaire = json_decode(file_get_contents(mqttbeRacine() . '/core/config/capabilities.json'), true);
    if (!is_array($vocabulaire) || !isset($vocabulaire['capabilities'])) {
        $resultats[] = mqttbeIndecis($titre, 'core/config/capabilities.json est illisible.');
    } else {
        $fautes = array();
        $connues = $vocabulaire['capabilities'];
        foreach (array($ctx, $ctxD) as $contexte) {
            foreach ($contexte->modeles as $modele) {
                foreach ($modele->channels() as $canal) {
                    if (!isset($connues[$canal->capability()])) {
                        $fautes[] = $modele->uid() . ' / ' . $canal->key() . ' : capacité « '
                                  . $canal->capability() . ' » inconnue.';
                    }
                }
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));
    }

    /* ----------------------------------------------------------------- 24 ---
     * Les captures ne doivent rien porter du réseau de qui que ce soit. */
    $titre = 'captures anonymisées';
    $fautes = array();
    foreach (array('passerelles.json', 'balises.json', 'decodees.json') as $nom) {
        $chemin = mqttbeCheminFixturesOmg() . '/' . $nom;
        if (!is_readable($chemin)) {
            continue;
        }
        $texte = file_get_contents($chemin);
        if (preg_match('/\b(?:10|127)\.\d+\.\d+\.\d+\b/', $texte)
            || preg_match('/\b192\.168\.\d+\.\d+\b/', $texte)
            || preg_match('/\b172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+\b/', $texte)) {
            $fautes[] = $nom . ' : adresse IP privée (attendu 192.0.2.x, réservé à la documentation)';
        }
        if (preg_match('/"SSID"\s*:\s*"(?!reseau-essai)/', $texte)) {
            $fautes[] = $nom . ' : SSID réel';
        }
        /* Les MAC des fixtures sont fabriquées : A8:B0:C1 (parc d'essai),
         * D2:D2:D2 (adresse aléatoire), C0:FF:EE (foule engendrée). Une MAC
         * d'un autre préfixe est une MAC relevée sur un vrai appareil. */
        if (preg_match_all('/\b([0-9A-Fa-f]{2}):([0-9A-Fa-f]{2}):([0-9A-Fa-f]{2}):[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}\b/', $texte, $trouvees)) {
            foreach ($trouvees[0] as $trouvee) {
                $prefixe = strtoupper(substr($trouvee, 0, 8));
                if (!in_array($prefixe, array('A8:B0:C1', 'D2:D2:D2', 'C0:FF:EE', 'C5:F6:50'), true)) {
                    $fautes[] = $nom . ' : adresse MAC « ' . $trouvee . ' » d\'un préfixe non fabriqué';
                }
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "le dépôt part sur GitHub : une capture brute y publierait la topologie du réseau "
        . "d'une maison.\n" . implode("\n", $fautes));

    return $resultats;
}

/* Correspondance d'un topic avec un filtre MQTT (OASIS 3.1.1 §4.7), recopiée
 * plutôt qu'empruntée : le contrôle doit pouvoir dire « ce filtre couvre ce
 * topic » sans charger le démon et sa configuration. */
function MqttbeConfig_topicMatchesOmg($_filtre, $_topic) {
    if ($_filtre === $_topic) {
        return true;
    }
    $filtre = explode('/', $_filtre);
    $topic  = explode('/', $_topic);
    $n = count($filtre);
    for ($i = 0; $i < $n; $i++) {
        if ($filtre[$i] === '#') {
            return true;
        }
        if (!isset($topic[$i])) {
            return false;
        }
        if ($filtre[$i] !== '+' && $filtre[$i] !== $topic[$i]) {
            return false;
        }
    }
    return count($topic) === $n;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Découverte OpenMQTTGateway', mqttbeControlesOmg());
}
