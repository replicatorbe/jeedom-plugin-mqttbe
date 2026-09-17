<?php
/* Les sondes de nom : aller chercher le nom que l'utilisateur a donné à son
 * appareil, sans jamais dégrader ce qui existe.
 *
 *   php tests/check-nameprobe.php
 *
 * Ni réseau, ni broker, ni Jeedom : l'exécuteur HTTP est branché, et ces
 * contrôles y mettent un exécuteur de papier. Le moteur, lui, est le vrai — ce
 * qui est éprouvé ici est EXACTEMENT le code qui tourne en production, avec une
 * horloge qu'on avance à la main plutôt qu'une expiration de dix minutes par
 * contrôle.
 *
 * Ce qui est attrapé ici ne se voit ni à la relecture, ni au « php -l », ni sur
 * un banc d'un seul appareil allumé :
 *
 *   - une requête faite sur le chemin d'un message fige le démon : vingt-deux
 *     appareils éteints, ce sont vingt-deux délais d'expiration pendant
 *     lesquels plus un message MQTT n'est routé, et le broker finit par couper
 *     la session sur le keepalive ;
 *   - un appareil qui ne répond pas, resondé à chaque tour, c'est une requête
 *     par seconde pour toujours — invisible dans Jeedom, parfaitement visible
 *     sur le réseau ;
 *   - un nom obtenu puis effacé par le premier échec, c'est un équipement qui
 *     perd son nom la nuit où l'on débranche l'appareil ;
 *   - une sonde qui répond trois minutes après la découverte, sans réémission,
 *     n'a servi à rien : le nom n'est jamais appliqué ;
 *   - et un nom vient du réseau : il peut porter du HTML, des caractères de
 *     contrôle, ou dix kilo-octets. */

require_once __DIR__ . '/outils.php';
/* Le banc du moteur et l'adapter factice vivent dans le contrôle du moteur :
 * les recopier ici ferait deux bancs qui divergeraient, et le jour où l'un
 * cesserait de ressembler au moteur réel, c'est l'autre qui le dirait. */
require_once __DIR__ . '/check-discovery.php';

$mqttbeSondeFichier = mqttbeRacine() . '/resources/mqttbed/discovery/NameProbe.php';
if (is_readable($mqttbeSondeFichier)) {
    require_once $mqttbeSondeFichier;
}

/* -----------------------------------------------------------------------------
 * L'exécuteur HTTP de papier.
 *
 * Il rend ce qu'on lui a dit de rendre, compte les requêtes, et n'attend jamais.
 * Le défaut — aucune réponse programmée — est celui qui compte le plus : c'est
 * l'appareil éteint, celui qui fait expirer le délai et qu'il ne faut surtout
 * pas réinterroger à chaque tour.
 * -------------------------------------------------------------------------- */
class MqttbeSondeurPapier {

    /* url => réponse, ou liste de réponses servies dans l'ordre (la dernière
     * vaut pour tous les appels suivants). */
    public $reponses = array();

    public $requetes   = array();   // [cle, url, connect, total]
    public $appelsPoll = 0;
    /* Nombre d'appels à poll() pendant lesquels une requête reste « en vol ».
     * Zéro = réponse au tour suivant, ce qui est déjà asynchrone. */
    public $retard = 0;

    private $vol = array();

    public function start($_cle, $_url, $_connect, $_total) {
        $this->requetes[] = array('cle' => $_cle, 'url' => $_url,
                                  'connect' => $_connect, 'total' => $_total);
        $this->vol[$_cle] = array('url' => $_url, 'reste' => $this->retard);
        return true;
    }

    public function busy() {
        return count($this->vol);
    }

    public function poll() {
        $this->appelsPoll++;
        $sortie = array();
        foreach ($this->vol as $cle => $info) {
            if ($info['reste'] > 0) {
                $this->vol[$cle]['reste']--;
                continue;
            }
            unset($this->vol[$cle]);
            $sortie[] = $this->reponse($cle, $info['url']);
        }
        return $sortie;
    }

    public function close() {
        $this->vol = array();
    }

    /* -- commodités du contrôle, jamais vues par le code éprouvé ----------- */

    public function repond($_url, $_corps) {
        $this->reponses[$_url] = array('ok' => true, 'code' => 200, 'body' => $_corps, 'error' => '');
    }

    public function repondNom($_url, $_nom) {
        $this->repond($_url, json_encode(array('name' => $_nom), JSON_UNESCAPED_UNICODE));
    }

    public function echoue($_url, $_code = 401, $_erreur = '') {
        $this->reponses[$_url] = array('ok' => false, 'code' => $_code, 'body' => '', 'error' => $_erreur);
    }

    public function muet($_url) {
        unset($this->reponses[$_url]);
    }

    public function compte($_url = null) {
        if ($_url === null) {
            return count($this->requetes);
        }
        $compte = 0;
        foreach ($this->requetes as $requete) {
            if ($requete['url'] === $_url) {
                $compte++;
            }
        }
        return $compte;
    }

    private function reponse($_cle, $_url) {
        /* L'appareil éteint : le délai expire, et c'est tout ce que curl en
         * dira. */
        $reponse = array('key' => $_cle, 'ok' => false, 'code' => 0, 'body' => '',
                         'error' => 'Connection timed out after 4001 milliseconds');
        if (isset($this->reponses[$_url])) {
            $programme = $this->reponses[$_url];
            if (isset($programme[0]) && is_array($programme[0])) {
                $programme = (count($programme) > 1) ? array_shift($this->reponses[$_url]) : $programme[0];
            }
            $reponse = array_merge($reponse, $programme);
            $reponse['key'] = $_cle;
        }
        return $reponse;
    }
}

/* -----------------------------------------------------------------------------
 * Le décor
 * -------------------------------------------------------------------------- */

define('MQTTBE_SONDE_URL', 'http://192.0.2.10/settings');

/* Un moteur neuf, son banc, son exécuteur de papier et un adapter qui émet ce
 * qu'on lui envoie : l'adapter ne modélise rien, il transmet — tout ce qui est
 * vérifié ici doit l'être sur n'importe quel adapter, et non sur celui de
 * Shelly. */
function mqttbeBancSonde($_probeNames = true) {
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $sondeur = new MqttbeSondeurPapier();
    $moteur->probes()->useFetcher($sondeur);

    $adapter = new MqttbeAdapterFactice('factice.a', 100, array('parc/annonce'));
    $adapter->surMessage = function ($_adapter, $_ctx, $_topic, $_payload) {
        $modele = json_decode($_payload, true);
        if (is_array($modele)) {
            $_ctx->emit($modele);
        }
    };
    $moteur->register($adapter);
    $ordre = array('enabled' => true);
    if ($_probeNames !== null) {
        $ordre['probeNames'] = $_probeNames;
    }
    $moteur->apply($ordre);
    return array($moteur, $banc, $sondeur, $adapter);
}

/* La sonde telle qu'un adapter la déclare. */
function mqttbeSondeHttp($_url = MQTTBE_SONDE_URL, $_chemin = 'name', $_ttl = 86400) {
    return array('type' => 'http.json', 'url' => $_url, 'path' => $_chemin, 'ttl' => $_ttl);
}

function mqttbeModeleSonde($_uid = 'sonde:1', $_sonde = null, $_nomAppareil = '') {
    return array(
        'identity' => array('adapter' => 'factice.a', 'uid' => $_uid, 'confidence' => 'certain'),
        'meta'     => array(
            'name'        => 'Appareil ' . $_uid,
            'device_name' => $_nomAppareil,
            'probe'       => ($_sonde === null) ? mqttbeSondeHttp() : $_sonde,
        ),
        'channels' => array(array(
            'key' => 'state', 'capability' => 'switch.state', 'name' => 'État',
            'source' => array('topic' => 'factice/state', 'selector' => array('type' => 'raw')),
        )),
    );
}

/* L'appareil se présente : le moteur remet le message à l'adapter, qui émet. */
function mqttbeAnnonce($_moteur, $_modele) {
    $_moteur->onMessage('parc/annonce', json_encode($_modele), false);
}

/* Un tour de boucle du démon : le temps passe, et le moteur bat. */
function mqttbeTours($_moteur, $_banc, $_nombre = 1, $_secondes = 1) {
    for ($i = 0; $i < $_nombre; $i++) {
        $_banc->avance($_secondes);
        $_moteur->tick();
    }
}

/* Le nom d'usage porté par le dernier modèle remis à Jeedom. */
function mqttbeDernierNom($_banc) {
    if (empty($_banc->modeles)) {
        return null;
    }
    $modele = $_banc->modeles[count($_banc->modeles) - 1];
    return isset($modele['meta']['device_name']) ? $modele['meta']['device_name'] : '';
}

function mqttbeNomsEmis($_banc) {
    $noms = array();
    foreach ($_banc->modeles as $modele) {
        $noms[] = isset($modele['meta']['device_name']) ? $modele['meta']['device_name'] : '';
    }
    return $noms;
}

/* =============================================================================
 * 1. Le cas nominal, et la réémission qui le rend utile
 * ========================================================================== */
function mqttbeControlesSondeNominale() {
    $resultats = array();

    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'chaudiere');
    mqttbeAnnonce($moteur, mqttbeModeleSonde());

    /* ------------------------------------------------------------------ 1 ---
     * Rien n'est demandé sur le chemin du message. C'est LA règle : le moteur
     * distribue les messages MQTT depuis la boucle principale du démon, celle
     * qui lit aussi la socket du broker. */
    $resultats[] = mqttbeVerifie('aucune requête sur le chemin d\'un message',
        $sondeur->compte() === 0 && $sondeur->appelsPoll === 0 && count($banc->modeles) === 1,
        $sondeur->compte() . ' requête(s) et ' . $sondeur->appelsPoll . ' relecture(s) pendant le '
        . 'traitement du message de découverte. Une requête HTTP faite ici attend jusqu\'au délai '
        . 'd\'expiration : sur un parc où la moitié des appareils sont éteints, c\'est une minute '
        . 'pendant laquelle plus aucun message MQTT n\'est routé, et le broker coupe la session '
        . 'sur le keepalive.');

    /* ------------------------------------------------------------------ 2 ---
     * Le modèle part TOUT DE SUITE, avec son nom technique : la découverte
     * n'attend pas la sonde. */
    $resultats[] = mqttbeVerifie('l\'appareil est découvert sans attendre la sonde',
        count($banc->modeles) === 1 && mqttbeDernierNom($banc) === '',
        'le premier modèle porte déjà un nom d\'usage, ou n\'est pas parti : '
        . count($banc->modeles) . ' modèle(s). Un appareil dont la sonde met trois minutes à '
        . 'répondre — ou ne répond jamais — doit apparaître dans Jeedom immédiatement.');

    /* ------------------------------------------------------------------ 3 ---
     * La sonde répond, et le modèle REPART. L'adapter, lui, n'a rien reçu de
     * nouveau : sans réémission par le moteur, le nom ne serait appliqué qu'au
     * prochain redémarrage du démon. */
    mqttbeTours($moteur, $banc, 3);
    $resultats[] = mqttbeVerifie('le nom obtenu fait repartir le modèle vers Jeedom',
        count($banc->modeles) === 2 && mqttbeDernierNom($banc) === 'chaudiere',
        count($banc->modeles) . ' modèle(s) remis, dernier nom d\'usage « '
        . var_export(mqttbeDernierNom($banc), true) . ' », attendu « chaudiere » au deuxième. '
        . 'L\'adapter n\'a aucune raison de réémettre : son appareil n\'a rien publié de nouveau. '
        . 'Si le moteur ne renvoie pas le modèle lui-même, la sonde n\'aura servi à rien.');

    /* ------------------------------------------------------------------ 4 ---
     * Et une fois le nom appliqué, plus rien ne bouge : ni le battement, ni une
     * nouvelle annonce identique ne doivent réécrire la base. Les deux caches
     * d'empreinte — celui du moteur et celui de l'adapter — doivent s'accorder
     * sur le modèle AVEC son nom. */
    $avant = count($banc->modeles);
    mqttbeTours($moteur, $banc, 5);
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 2);
    $resultats[] = mqttbeVerifie('le nom appliqué ne fait pas réécrire le parc',
        count($banc->modeles) === $avant,
        (count($banc->modeles) - $avant) . ' modèle(s) de plus après le battement et une annonce '
        . 'identique. Les messages de découverte sont rejoués par le broker à chaque démarrage : '
        . 'un modèle qui repart à chaque fois, c\'est tout le parc réécrit en base plusieurs fois '
        . 'par jour.');

    /* ------------------------------------------------------------------ 5 ---
     * Une seule requête pour tout cela : le nom est retenu. */
    $resultats[] = mqttbeVerifie('un nom connu n\'est pas redemandé',
        $sondeur->compte(MQTTBE_SONDE_URL) === 1,
        $sondeur->compte(MQTTBE_SONDE_URL) . ' requête(s) pour un seul appareil dont le nom est '
        . 'déjà connu. Un nom ne change qu\'au moment où quelqu\'un renomme son appareil : le '
        . 'redemander à chaque annonce ferait frapper à toutes les portes du réseau à chaque '
        . 'redémarrage du démon.');

    /* ------------------------------------------------------------------ 6 ---
     * La durée de validité, elle, finit par expirer — sans quoi un appareil
     * renommé garderait son ancien nom jusqu'au prochain redémarrage. */
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'chaudiere gaz');
    $banc->avance(90000);
    mqttbeTours($moteur, $banc, 3);
    $resultats[] = mqttbeVerifie('le nom est rafraîchi à l\'expiration de sa validité',
        $sondeur->compte(MQTTBE_SONDE_URL) === 2 && mqttbeDernierNom($banc) === 'chaudiere gaz',
        $sondeur->compte(MQTTBE_SONDE_URL) . ' requête(s) après un jour, dernier nom « '
        . var_export(mqttbeDernierNom($banc), true) . ' ». Un résultat qui ne périme jamais fige '
        . 'le nom au jour de la découverte.');

    /* ------------------------------------------------------------------ 7 ---
     * Le délai passé à curl : deux secondes pour la connexion, quatre en tout.
     * Ce sont des appareils du réseau local ; au-delà, ils ne répondront pas, et
     * chaque seconde de plus est une seconde de requêtes en cours. */
    $premiere = $sondeur->requetes[0];
    $resultats[] = mqttbeVerifie('délais courts, adaptés au réseau local',
        (int) $premiere['connect'] <= 2 && (int) $premiere['total'] <= 4
        && (int) $premiere['total'] >= 1,
        'connexion ' . $premiere['connect'] . ' s, total ' . $premiere['total'] . ' s — attendu '
        . 'au plus 2 et 4. Un délai long ne rattrape rien sur un réseau local et tient des '
        . 'connexions ouvertes pendant que le parc entier se découvre.');

    return $resultats;
}

/* =============================================================================
 * 2. Tout ce qui tourne mal, et qui ne doit rien dégrader
 * ========================================================================== */
function mqttbeControlesSondeEchecs() {
    $resultats = array();

    /* ------------------------------------------------------------------ 1 ---
     * L'API est fermée par un mot de passe : HTTP 401. Le modèle garde son nom
     * technique, et rien d'autre ne change. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->echoue(MQTTBE_SONDE_URL, 401);
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 4);
    $resultats[] = mqttbeVerifie('sonde en échec : le nom technique est conservé',
        count($banc->modeles) === 1 && mqttbeDernierNom($banc) === ''
        && isset($banc->modeles[0]['meta']['name']) && $banc->modeles[0]['meta']['name'] !== '',
        count($banc->modeles) . ' modèle(s), nom d\'usage ' . var_export(mqttbeDernierNom($banc), true)
        . '. Une API protégée par un mot de passe est le cas ordinaire, pas un incident : '
        . 'l\'équipement doit exister dans Jeedom exactement comme avant cette fonctionnalité.');

    /* ------------------------------------------------------------------ 2 ---
     * L'appareil est éteint : plus rien ne répond. Le recul progressif doit
     * s'arrêter — c'est ici que se joue la différence entre quatre requêtes et
     * une requête par seconde jusqu'à la fin des temps. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->muet(MQTTBE_SONDE_URL);
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 3600);      /* une heure, seconde par seconde */
    $enUneHeure = $sondeur->compte(MQTTBE_SONDE_URL);
    $resultats[] = mqttbeVerifie('appareil muet : pas de resondage en boucle',
        $enUneHeure > 1 && $enUneHeure <= 6,
        $enUneHeure . ' requête(s) en une heure pour un appareil qui ne répond pas — attendu '
        . 'entre 2 et 6 (une tentative, puis un recul progressif, puis l\'abandon). Sur un parc '
        . 'où trois appareils sont débranchés, une requête par tour de boucle fait trois '
        . 'requêtes par seconde sur le réseau, indéfiniment, et rien dans Jeedom ne le montre.');

    /* Et l'abandon n'est pas définitif : « relancer la découverte » redemande. */
    $moteur->apply(array('enabled' => true, 'rescan' => true));
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'chaudiere');
    mqttbeTours($moteur, $banc, 3);
    $resultats[] = mqttbeVerifie('une relance de découverte reprend les abandons',
        $sondeur->compte(MQTTBE_SONDE_URL) > $enUneHeure && mqttbeDernierNom($banc) === 'chaudiere',
        'après « relancer la découverte » : ' . ($sondeur->compte(MQTTBE_SONDE_URL) - $enUneHeure)
        . ' requête(s) de plus, nom « ' . var_export(mqttbeDernierNom($banc), true) . ' ». C\'est '
        . 'le bouton que presse celui qui vient de rebrancher son appareil ou de le renommer : '
        . 'un abandon définitif le laisserait sans effet jusqu\'au prochain redémarrage du démon.');

    /* ------------------------------------------------------------------ 3 ---
     * Le nom a été obtenu, puis l'appareil est débranché. Le nom RESTE. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'chaudiere');
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 3);
    $obtenu = mqttbeDernierNom($banc);
    $sondeur->muet(MQTTBE_SONDE_URL);
    $banc->avance(90000);                   /* la validité expire */
    mqttbeTours($moteur, $banc, 3600);
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 2);
    $resultats[] = mqttbeVerifie('un nom obtenu survit à une sonde en échec',
        $obtenu === 'chaudiere' && mqttbeDernierNom($banc) === 'chaudiere'
        && !in_array('', array_slice(mqttbeNomsEmis($banc), 1), true),
        'noms successivement remis à Jeedom : ' . json_encode(mqttbeNomsEmis($banc)) . '. '
        . 'Remplacer un nom connu par du vide au premier échec, c\'est un équipement qui perd son '
        . 'nom la nuit où l\'on débranche l\'appareil — et qui ne le retrouve qu\'au retour du '
        . 'courant, si tant est qu\'on ait remarqué.');

    /* ------------------------------------------------------------------ 4 ---
     * L'appareil répond, mais n'a jamais été nommé, ou son firmware ne porte pas
     * ce champ. Ce n'est pas un échec, et cela ne vaut pas non plus une reprise
     * toutes les trente secondes. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->repond(MQTTBE_SONDE_URL, '{"device":{"hostname":"shelly1-aabbcc"},"name":null}');
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 600);
    $resultats[] = mqttbeVerifie('appareil sans nom : une seule demande, pas de dégradation',
        $sondeur->compte(MQTTBE_SONDE_URL) === 1 && count($banc->modeles) === 1
        && mqttbeDernierNom($banc) === '',
        $sondeur->compte(MQTTBE_SONDE_URL) . ' requête(s), ' . count($banc->modeles) . ' modèle(s). '
        . 'Un appareil jamais nommé par son propriétaire est le cas le plus courant du parc : il '
        . 'ne doit produire ni réémission, ni insistance.');

    /* ------------------------------------------------------------------ 5 ---
     * Une réponse qui n'est pas une chaîne n'est pas un nom : « Array » est
     * exactement ce qui finirait dans le nom de l'équipement. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->repond(MQTTBE_SONDE_URL, '{"name":{"fr":"chaudiere"}}');
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 4);
    $objet = mqttbeDernierNom($banc);

    /* Le chemin par points, lui, doit fonctionner : c'est là que Shelly Gen2+
     * porte son nom (`device.name`), et la convention est celle de tout le
     * plugin. */
    list($moteur2, $banc2, $sondeur2) = mqttbeBancSonde();
    $sondeur2->repond(MQTTBE_SONDE_URL, '{"device":{"name":"salon","mac":"AABB"}}');
    mqttbeAnnonce($moteur2, mqttbeModeleSonde('sonde:2', mqttbeSondeHttp(MQTTBE_SONDE_URL, 'device.name')));
    mqttbeTours($moteur2, $banc2, 4);
    $resultats[] = mqttbeVerifie('seule une chaîne est un nom, et le chemin par points marche',
        $objet === '' && mqttbeDernierNom($banc2) === 'salon',
        'objet rendu comme nom : ' . var_export($objet, true) . ' ; chemin « device.name » : '
        . var_export(mqttbeDernierNom($banc2), true) . '. Un tableau converti en chaîne donne '
        . '« Array », qui s\'écrirait tel quel dans le nom de l\'équipement ; et sans chemin par '
        . 'points, la génération suivante de Shelly demanderait un second type de sonde.');

    /* ------------------------------------------------------------------ 6 ---
     * Une sonde d'un type que ce plugin ne sait pas exécuter, et une adresse
     * qui n'en est pas une : ignorées, sans rien casser. L'adresse vient d'un
     * adapter qui l'a composée avec ce qu'un appareil a annoncé sur un topic
     * public — file:///etc/passwd est un nom d'appareil parfaitement légal. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    mqttbeAnnonce($moteur, mqttbeModeleSonde('sonde:rpc',
        array('type' => 'mqtt.rpc', 'topic' => 'appareil/x/rpc', 'path' => 'device.name')));
    /* Un type inconnu qui porterait par ailleurs une adresse parfaitement
     * exécutable : c'est le type, et lui seul, qui doit l'écarter — sans quoi
     * une sonde à venir serait exécutée par le mauvais mécanisme. */
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'ne doit pas être lu');
    mqttbeAnnonce($moteur, mqttbeModeleSonde('sonde:type',
        array('type' => 'http.xml', 'url' => MQTTBE_SONDE_URL, 'path' => 'name')));
    mqttbeAnnonce($moteur, mqttbeModeleSonde('sonde:file',
        mqttbeSondeHttp('file:///etc/passwd')));
    mqttbeAnnonce($moteur, mqttbeModeleSonde('sonde:vide', array()));
    mqttbeTours($moteur, $banc, 10);
    $resultats[] = mqttbeVerifie('sonde inexécutable : ignorée, jamais fatale',
        $sondeur->compte() === 0 && count($banc->modeles) === 4,
        $sondeur->compte() . ' requête(s) pour des sondes inexécutables, ' . count($banc->modeles)
        . ' modèle(s) sur 4 attendus. Un type inconnu doit laisser l\'appareil être découvert sans '
        . 'son nom d\'usage — c\'est ainsi que `mqtt.rpc` pourra s\'ajouter sans toucher au reste — '
        . 'et une adresse qui n\'est pas http(s) ne doit jamais atteindre curl.');

    return $resultats;
}

/* =============================================================================
 * 3. Le nom vient du réseau
 * ========================================================================== */
function mqttbeControlesSondeNettoyage() {
    $resultats = array();

    /* ------------------------------------------------------------------ 1 ---
     * HTML et caractères de contrôle. La fabrique et l'interface échappent ce
     * qu'elles affichent, mais un retour à la ligne dans un nom fabrique une
     * fausse entrée dans tous les journaux qui le citent, et un nom qui traverse
     * le démon, la base et trois gabarits finit toujours par ressortir quelque
     * part sans échappe. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->repond(MQTTBE_SONDE_URL,
        json_encode(array('name' => "  <b>chaudière</b>\r\n du\tsalon \x07"),
                    JSON_UNESCAPED_UNICODE));
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 3);
    $nom = mqttbeDernierNom($banc);
    $resultats[] = mqttbeVerifie('un nom porteur de HTML ou de contrôle est nettoyé',
        $nom === 'chaudière du salon',
        'nom obtenu : ' . var_export($nom, true) . ', attendu « chaudière du salon » : ni balise, '
        . 'ni caractère de contrôle, les espaces réduits, et le nom lisible conservé.');

    /* ------------------------------------------------------------------ 2 ---
     * La longueur. Le nom composé entre dans le nom de l'équipement, un
     * varchar(127) — et la coupe se fait en CARACTÈRES : couper 64 octets au
     * milieu d'un « é » produit une chaîne qui n'est plus de l'UTF-8, et
     * json_encode rend alors false pour le lot entier, qui part vide. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    /* Des caractères de deux ET de trois octets : c'est ce mélange qui fait
     * qu'une coupe à 64 OCTETS tombe au milieu d'un caractère. Avec des « é »
     * seuls, elle tomberait par chance sur une frontière et le défaut
     * passerait. */
    $sondeur->repondNom(MQTTBE_SONDE_URL, str_repeat('é', 30) . str_repeat('€', 200));
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 3);
    $nom = (string) mqttbeDernierNom($banc);
    $longueur = function_exists('mb_strlen') ? mb_strlen($nom, 'UTF-8') : strlen($nom);
    $resultats[] = mqttbeVerifie('un nom trop long est borné, et reste de l\'UTF-8',
        $longueur > 0 && $longueur <= 64 && json_encode($nom) !== false,
        $longueur . ' caractère(s) (' . strlen($nom) . ' octets), attendu au plus 64, et une '
        . 'chaîne que json_encode accepte. Un seul octet non-UTF-8 dans le lot et Jeedom reçoit '
        . 'un corps vide avec un HTTP 200 : les valeurs saines du même lot disparaissent sans un mot.');

    return $resultats;
}

/* =============================================================================
 * 4. Le réglage de l'utilisateur, et la généricité
 * ========================================================================== */
function mqttbeControlesSondeReglage() {
    $resultats = array();

    /* ------------------------------------------------------------------ 1 ---
     * `discovery::probeNames` à 0 : aucune requête. Certains n'aiment pas que
     * Jeedom aille frapper aux portes de leur réseau. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde(false);
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'chaudiere');
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 600);
    $coupe = ($sondeur->compte() === 0 && count($banc->modeles) === 1
              && mqttbeDernierNom($banc) === '');

    /* Et le réglage n'est pas un interrupteur mort : rallumé, la sonde part. */
    $moteur->apply(array('enabled' => true, 'probeNames' => true));
    mqttbeAnnonce($moteur, mqttbeModeleSonde());
    mqttbeTours($moteur, $banc, 3);
    $rallume = ($sondeur->compte() > 0 && mqttbeDernierNom($banc) === 'chaudiere');

    /* Décoché EN COURS DE ROUTE, ce qui est le cas réel : l'utilisateur voit
     * passer les requêtes sur son réseau et décoche la case. Les sondes déjà
     * inscrites doivent s'arrêter là — sinon le réglage ne coupe rien pour les
     * appareils déjà découverts, c'est-à-dire pour tout le parc. */
    $moteur->apply(array('enabled' => true, 'probeNames' => false));
    $sondeur->muet(MQTTBE_SONDE_URL);
    $apres = $sondeur->compte();
    $banc->avance(90000);                   /* la validité expire */
    mqttbeTours($moteur, $banc, 600);
    $resultats[] = mqttbeVerifie('discovery::probeNames à 0 : aucune requête',
        $coupe && $rallume && $sondeur->compte() === $apres,
        ($coupe ? '' : 'des requêtes sont parties alors que le réglage était coupé au démarrage. ')
        . ($rallume ? '' : 'le réglage rallumé n\'a relancé aucune sonde. ')
        . ($sondeur->compte() - $apres) . ' requête(s) après avoir décoché la case en cours de '
        . 'route. Un réglage qui ne coupe rien trahit la confiance de celui qui l\'a décoché ; un '
        . 'réglage qui ne se rallume pas est une panne muette.');

    /* ------------------------------------------------------------------ 2 ---
     * Un adapter qui connaît déjà le nom ne déclenche AUCUNE sonde. C'est le cas
     * de Tasmota (`dn`), de Zigbee2MQTT (`friendly_name`) et de Home Assistant
     * (`dev.name`) : leur message de découverte porte le nom, et le jour où ils
     * arriveront, rien dans le moteur ne devra changer. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->repondNom(MQTTBE_SONDE_URL, 'ce nom ne doit pas être lu');
    mqttbeAnnonce($moteur, mqttbeModeleSonde('sonde:connu', mqttbeSondeHttp(), 'Chaudière'));
    mqttbeTours($moteur, $banc, 60);
    $resultats[] = mqttbeVerifie('un adapter qui donne le nom ne déclenche aucune sonde',
        $sondeur->compte() === 0 && mqttbeDernierNom($banc) === 'Chaudière'
        && count($banc->modeles) === 1,
        $sondeur->compte() . ' requête(s) alors que le modèle portait déjà son nom d\'usage, '
        . count($banc->modeles) . ' modèle(s), nom ' . var_export(mqttbeDernierNom($banc), true)
        . '. Un adapter dont le message de découverte porte le nom n\'a aucune requête à provoquer, '
        . 'et le nom qu\'il donne ne doit pas être écrasé par une sonde.');

    /* ------------------------------------------------------------------ 3 ---
     * Vingt-deux appareils éteints d'un coup : les requêtes simultanées sont
     * bornées. Ouvrir vingt-deux connexions depuis la box qui fait déjà tourner
     * Jeedom, son serveur web et sa base, c'est la faire tousser au moment
     * précis où l'utilisateur regarde la découverte. */
    list($moteur, $banc, $sondeur) = mqttbeBancSonde();
    $sondeur->retard = 50;                  /* personne ne répond tout de suite */
    for ($i = 0; $i < 22; $i++) {
        mqttbeAnnonce($moteur, mqttbeModeleSonde('sonde:' . $i,
            mqttbeSondeHttp('http://192.0.2.' . (10 + $i) . '/settings')));
    }
    mqttbeTours($moteur, $banc, 1);
    $simultanees = $sondeur->busy();
    mqttbeTours($moteur, $banc, 3);
    $resultats[] = mqttbeVerifie('les requêtes simultanées sont bornées',
        $simultanees > 0 && $simultanees <= 4 && $sondeur->busy() <= 4,
        $simultanees . ' requête(s) lancées d\'un coup pour 22 appareils, puis '
        . $sondeur->busy() . ' — attendu au plus 4 à la fois. Le parc entier se présente en une '
        . 'seconde au démarrage du démon, les messages de découverte étant rejoués par le broker.');

    /* ------------------------------------------------------------------ 4 ---
     * Et la règle qui gouverne tout : rien de spécifique à un constructeur hors
     * de l'adapter. On lit les CHAÎNES du moteur et de l'exécuteur, pas les
     * commentaires : un protocole cité en exemple dans un commentaire n'est pas
     * un cas particulier, une chaîne « shellies/… » en serait un. */
    $fichiers = array(
        mqttbeRacine() . '/resources/mqttbed/discovery/Engine.php',
        mqttbeRacine() . '/resources/mqttbed/discovery/NameProbe.php',
        mqttbeRacine() . '/resources/mqttbed/discovery/DeviceModel.php',
    );
    $fautes = array();
    foreach ($fichiers as $fichier) {
        if (!is_readable($fichier)) {
            continue;
        }
        foreach (mqttbeChaines(file_get_contents($fichier)) as $chaine) {
            if (preg_match('/(shell(y|ies)|tasmota|zigbee|z2m|homeassistant)/i', $chaine, $trouve)) {
                $fautes[] = mqttbeRelatif($fichier) . ' : « ' . $trouve[0] . ' » dans la chaîne «'
                    . substr($chaine, 0, 60) . '»';
            }
        }
    }
    $resultats[] = mqttbeVerifie('aucun constructeur nommé hors des adapters',
        empty($fautes),
        "un cas particulier remonté jusqu'au moteur devient le cas particulier de TOUS les "
        . "protocoles : chaque adapter suivant doit vivre avec, et personne ne sait plus dire "
        . "lequel en dépend.\n" . implode("\n", $fautes));

    return $resultats;
}

/* -------------------------------------------------------------------------- */

function mqttbeControlesSondeNom() {
    if (!class_exists('MqttbeNameProbe') || !class_exists('MqttbeDiscoveryEngine')
        || !interface_exists('MqttbeAdapter')) {
        return array(mqttbeIndecis('sondes de nom',
            'resources/mqttbed/discovery/NameProbe.php (ou Engine.php) n\'existe pas encore.'));
    }
    if (!method_exists('MqttbeDiscoveryEngine', 'probes')
        || !method_exists('MqttbeNameProbe', 'useFetcher')) {
        return array(mqttbeIndecis('sondes de nom',
            'le moteur n\'expose pas ses sondes, ou l\'exécuteur HTTP n\'est pas branchable : '
            . 'ces contrôles ne peuvent pas s\'exécuter sans réseau.'));
    }
    return array_merge(
        mqttbeControlesSondeNominale(),
        mqttbeControlesSondeEchecs(),
        mqttbeControlesSondeNettoyage(),
        mqttbeControlesSondeReglage()
    );
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Sondes de nom', mqttbeControlesSondeNom());
}
