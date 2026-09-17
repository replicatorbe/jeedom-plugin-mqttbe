<?php
/* Banc d'essai du routage — jalon 2, critères d'acceptation de CONTRAT-J2 §9.
 *
 *   php tests/bench-routage.php
 *
 * Ni broker, ni Jeedom, ni base de données : le routeur est la seule classe du
 * démon qui n'ait besoin de rien d'autre que d'une table et de messages, et
 * c'est délibéré — c'est ce qui permet de l'éprouver ici, dix mille messages à
 * la suite, en une seconde, sans monter un banc d'intégration.
 *
 * Ce banc ne mesure pas seulement une vitesse. Il vérifie D'ABORD que chaque
 * valeur arrive sur la bonne commande, avec la bonne transformation, et que
 * rien n'arrive là où rien n'était attendu : un routeur rapide qui se trompe
 * de commande est une panne bien pire qu'un routeur lent. Les chiffres ne sont
 * affichés qu'ensuite, et n'ont de sens que si tout le reste est vert.
 *
 * Code de retour non nul dès qu'un contrôle échoue. */

require_once __DIR__ . '/outils.php';
require_once mqttbeRacine() . '/resources/mqttbed/core/Log.php';
require_once mqttbeRacine() . '/resources/mqttbed/core/Config.php';
require_once mqttbeRacine() . '/resources/mqttbed/core/Router.php';

/* Le routeur journalise l'application d'une table et les entrées qu'il rejette.
 * Ici ces lignes se mêleraient au compte rendu : on les éteint, et les contrôles
 * constatent les rejets sur les compteurs, pas sur le texte du journal. */
MqttbeLog::setLevel('none');

/* Nombre de messages rejoués. Dix mille est le chiffre du contrat : assez pour
 * que la mesure ne soit pas du bruit, assez peu pour que le banc reste un outil
 * qu'on relance à chaque modification. */
define('BENCH_MESSAGES', 10000);
define('BENCH_DEVICES', 50);

/* -----------------------------------------------------------------------------
 * Un collecteur de valeurs à la place de la liaison Jeedom.
 *
 * Le routeur ne connaît pas son destinataire : il appelle un gestionnaire. En
 * production c'est MqttbeJeedomLink::pushValue(), ici c'est ce tableau — et le
 * fait que ce remplacement soit possible, sans une ligne de conditionnel dans
 * le chemin chaud, est exactement ce qui rend ce banc représentatif.
 * -------------------------------------------------------------------------- */
class MqttbeCollecteur {

    public $valeurs = array();

    public function pousse($_cmdId, $_valeur, $_ts) {
        $this->valeurs[] = array($_cmdId, $_valeur);
    }

    public function vide() {
        $this->valeurs = array();
    }
}

function mqttbeRouteurNeuf($_exclusions = '') {
    $config = new MqttbeConfig(array('host' => '192.0.2.1', 'exclude' => $_exclusions));
    return new MqttbeRouter($config);
}

/* Table minimale d'une seule cible : le tronc commun des contrôles unitaires. */
function mqttbeTable($_version, $_topic, $_targets) {
    return array('cmd' => 'routing', 'version' => $_version,
                 'entries' => array(array('topic' => $_topic, 'targets' => $_targets)));
}

function mqttbeCible($_cmdId, $_selector = null, $_transform = null, $_repeat = null) {
    $cible = array('cmdId' => $_cmdId);
    $cible['selector'] = ($_selector === null) ? array('type' => 'raw') : $_selector;
    if ($_transform !== null) {
        $cible['transform'] = $_transform;
    }
    /* Par défaut « always » : un contrôle qui vérifie une transformation ne
     * doit pas échouer parce que la politique de répétition a avalé la
     * deuxième valeur — chaque chose est éprouvée séparément. */
    $cible['repeat'] = ($_repeat === null) ? array('mode' => 'always') : $_repeat;
    return $cible;
}

/* =============================================================================
 * 1. Correspondance des topics
 *
 * Les règles de l'OASIS 3.1.1 §4.7 sont courtes et toutes contre-intuitives au
 * moins une fois. Chaque ligne ci-dessous est un cas qui s'est déjà payé
 * quelque part : `sport/#` qui doit couvrir `sport` lui-même, `+` qui ne
 * traverse pas un `/`, le joker qui ne doit pas ramasser les topics de service
 * du broker. Elles sont éprouvées ici, sur le routeur réel, et non sur une
 * copie de la fonction de correspondance.
 * ========================================================================== */
function mqttbeControlesCorrespondance() {
    $cas = array(
        /* filtre, topic, attendu, ce que le cas protège */
        array('sport/tennis/player1', 'sport/tennis/player1', true,  'correspondance exacte'),
        array('sport/tennis/player1', 'sport/tennis/player2', false, 'topic voisin non capté'),
        array('sport/tennis/#',       'sport/tennis',         true,  '« # » couvre le niveau parent lui-même'),
        array('sport/tennis/#',       'sport/tennis/',        true,  '« # » couvre un dernier niveau vide'),
        array('sport/tennis/#',       'sport/tennis/p1/score', true, '« # » couvre plusieurs niveaux'),
        array('sport/#',              'sport',                true,  '« sport/# » couvre « sport »'),
        array('sport/#',              'sports',               false, '« # » ne coupe pas un segment en deux'),
        array('#',                    'a/b/c',                true,  '« # » seul prend tout'),
        array('#',                    '$SYS/broker/uptime',   false, '« # » ne prend pas les topics de service'),
        array('+/monitor/Clients',    '$SYS/monitor/Clients', false, '« + » de tête ne prend pas « $… »'),
        array('sport/+',              'sport/tennis',         true,  '« + » prend un niveau'),
        array('sport/+',              'sport/tennis/player1', false, '« + » ne traverse pas un « / »'),
        array('sport/+',              'sport',                false, '« + » exige un niveau, même vide'),
        array('sport/+/player1',      'sport//player1',       true,  '« + » prend un niveau vide'),
        array('+/+',                  'a/b',                  true,  'deux jokers de niveau'),
        array('a/+/#',                'a/b',                  true,  '« # » après un « + », au niveau parent'),
        array('a/+/#',                'a',                    false, '« # » ne remonte pas au-dessus du « + »'),
        array('shellies/+/online',    'shellies/shelly1/online', true, 'le cas réel du parc Shelly'),
        array('shellies/+/online',    'shellies/shelly1/relay/0', false, 'topic voisin du même appareil'),
        array('$SYS/#',               '$SYS/broker/uptime',   true,  'un filtre explicite atteint « $SYS »'),
    );

    $resultats = array();
    foreach ($cas as $numero => $item) {
        list($filtre, $topic, $attendu, $intitule) = $item;

        $routeur = mqttbeRouteurNeuf();
        $collecteur = new MqttbeCollecteur();
        $routeur->onValue(array($collecteur, 'pousse'));
        $routeur->apply(mqttbeTable(1, $filtre, array(mqttbeCible(7))));
        $routeur->route($topic, 'v');

        $obtenu = !empty($collecteur->valeurs);
        $titre = $intitule . ' — « ' . $filtre . ' » / « ' . $topic . ' »';
        $resultats[] = ($obtenu === $attendu) ? mqttbeOk($titre) : mqttbeEchec($titre,
            $attendu
                ? 'le message aurait dû être routé et ne l\'a pas été : la commande restera muette, '
                . 'et rien dans le journal ne dira pourquoi.'
                : 'le message a été routé alors qu\'il ne devait pas l\'être : une commande recevra '
                . 'les valeurs d\'un autre appareil.');
    }

    /* Les filtres illégaux sont refusés à l'arrivée de la table, et non
     * interprétés au petit bonheur : le broker, lui, les refusera aussi, et un
     * démon qui croit router un topic auquel il n'est pas abonné est un démon
     * qui attend un message qui ne viendra jamais. */
    foreach (array('sport/a#', 'sp+rt', 'sport/#/score', 'a/#/#', '') as $mauvais) {
        $routeur = mqttbeRouteurNeuf();
        $bilan = $routeur->apply(mqttbeTable(1, $mauvais, array(mqttbeCible(7))));
        $titre = 'filtre illégal refusé — « ' . $mauvais . ' »';
        $resultats[] = (empty($routeur->subscriptions()) && $bilan['rejected'] > 0)
            ? mqttbeOk($titre)
            : mqttbeEchec($titre, 'ce filtre n\'est pas un filtre MQTT valide : le broker refusera '
                                . 'l\'abonnement, et le démon attendra indéfiniment des messages '
                                . 'qu\'il croit avoir demandés.');
    }

    return $resultats;
}

/* =============================================================================
 * 2. Sélecteurs et transformations
 * ========================================================================== */
function mqttbeControlesValeurs() {
    $resultats = array();

    $cas = array(
        array('titre' => 'charge utile brute',
              'cible' => mqttbeCible(1),
              'payload' => '21.5', 'attendu' => array(array(1, '21.5'))),

        array('titre' => 'chemin JSON à points',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'emeter.0.power')),
              'payload' => '{"emeter":[{"power":1234.56}]}', 'attendu' => array(array(1, '1234.56'))),

        array('titre' => 'segment numérique = index de tableau',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'a.2')),
              'payload' => '{"a":[10,11,12]}', 'attendu' => array(array(1, '12'))),

        array('titre' => 'chemin introuvable : aucune valeur, pas une valeur vide',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'emeter.9.power')),
              'payload' => '{"emeter":[{"power":1}]}', 'attendu' => array()),

        array('titre' => 'valeur nulle en JSON : aucune valeur',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'temp')),
              'payload' => '{"temp":null}', 'attendu' => array()),

        array('titre' => 'sous-objet visé : aucune valeur',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'emeter')),
              'payload' => '{"emeter":[{"power":1}]}', 'attendu' => array()),

        array('titre' => 'booléen JSON rendu en 0/1',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'on')),
              'payload' => '{"on":true}', 'attendu' => array(array(1, '1'))),

        array('titre' => 'charge utile non JSON alors qu\'un chemin est demandé',
              'cible' => mqttbeCible(1, array('type' => 'json', 'path' => 'power')),
              'payload' => 'OK', 'attendu' => array()),

        array('titre' => 'map : correspondance exacte de chaînes',
              'cible' => mqttbeCible(1, null, array('map' => array('on' => '1', 'off' => '0'))),
              'payload' => 'on', 'attendu' => array(array(1, '1'))),

        array('titre' => 'map : valeur inconnue laissée telle quelle',
              'cible' => mqttbeCible(1, null, array('map' => array('on' => '1'))),
              'payload' => 'overpower', 'attendu' => array(array(1, 'overpower'))),

        array('titre' => 'scale puis offset puis round, dans cet ordre',
              'cible' => mqttbeCible(1, null, array('scale' => 0.1, 'offset' => -2, 'round' => 2)),
              'payload' => '2135', 'attendu' => array(array(1, '211.5'))),

        array('titre' => 'round à zéro décimale',
              'cible' => mqttbeCible(1, null, array('round' => 0)),
              'payload' => '21.67', 'attendu' => array(array(1, '22'))),

        array('titre' => 'arithmétique ignorée sur une valeur non numérique',
              'cible' => mqttbeCible(1, null, array('scale' => 0.1)),
              'payload' => 'off', 'attendu' => array(array(1, 'off'))),

        array('titre' => 'map avant arithmétique',
              'cible' => mqttbeCible(1, null, array('map' => array('on' => '100'), 'scale' => 0.5)),
              'payload' => 'on', 'attendu' => array(array(1, '50'))),
    );

    foreach ($cas as $item) {
        $routeur = mqttbeRouteurNeuf();
        $collecteur = new MqttbeCollecteur();
        $routeur->onValue(array($collecteur, 'pousse'));
        $routeur->apply(mqttbeTable(1, 'x/y', array($item['cible'])));
        $routeur->route('x/y', $item['payload']);

        $resultats[] = ($collecteur->valeurs == $item['attendu'])
            ? mqttbeOk($item['titre'])
            : mqttbeEchec($item['titre'], 'attendu ' . json_encode($item['attendu'])
                        . ', obtenu ' . json_encode($collecteur->valeurs));
    }

    /* Le JSON n'est décodé qu'une fois par message, quel que soit le nombre de
     * commandes qui y puisent. Sur un état complet de Shelly lu par dix
     * commandes, c'est la dépense dominante du routage : la mesurer ici évite
     * qu'une réécriture la réintroduise sans que personne ne s'en aperçoive. */
    $routeur = mqttbeRouteurNeuf();
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));
    $routeur->apply(array('cmd' => 'routing', 'version' => 1, 'entries' => array(
        array('topic' => 'x/y', 'targets' => array(
            mqttbeCible(1, array('type' => 'json', 'path' => 'a')),
            mqttbeCible(2, array('type' => 'json', 'path' => 'b')),
            mqttbeCible(3, array('type' => 'json', 'path' => 'c')))),
        array('topic' => 'x/y', 'targets' => array(
            mqttbeCible(4, array('type' => 'json', 'path' => 'd')))),
    )));
    $routeur->route('x/y', '{"a":1,"b":2,"c":3,"d":4}');
    $stats = $routeur->stats();
    $titre = 'un seul décodage JSON pour quatre commandes du même topic';
    $resultats[] = ($stats['parsed'] === 1 && count($collecteur->valeurs) === 4)
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, $stats['parsed'] . ' décodage(s) pour ' . count($collecteur->valeurs)
                    . ' valeur(s) : le coût du routage devient proportionnel au nombre de commandes '
                    . 'et non au nombre de messages.');

    return $resultats;
}

/* =============================================================================
 * 3. Politique de répétition
 * ========================================================================== */
function mqttbeControlesRepetition() {
    $resultats = array();

    /* onchange, keepalive 0 : la deuxième valeur identique ne passe pas. */
    $routeur = mqttbeRouteurNeuf();
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));
    $routeur->apply(mqttbeTable(1, 'x/y', array(
        mqttbeCible(1, null, null, array('mode' => 'onchange', 'keepalive' => 0)))));
    $routeur->route('x/y', '21');
    $routeur->route('x/y', '21');
    $routeur->route('x/y', '22');
    $titre = 'onchange : seules les valeurs qui changent sont émises';
    $resultats[] = ($collecteur->valeurs == array(array(1, '21'), array(1, '22')))
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($collecteur->valeurs));

    /* always : tout passe, y compris deux fois la même valeur. */
    $routeur = mqttbeRouteurNeuf();
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));
    $routeur->apply(mqttbeTable(1, 'x/y', array(mqttbeCible(1, null, null, array('mode' => 'always')))));
    $routeur->route('x/y', '21');
    $routeur->route('x/y', '21');
    $titre = 'always : la valeur inchangée est émise quand même';
    $resultats[] = (count($collecteur->valeurs) === 2) ? mqttbeOk($titre)
        : mqttbeEchec($titre, count($collecteur->valeurs) . ' valeur(s) au lieu de 2 : un bouton '
                    . 'appuyé deux fois de suite ne déclencherait qu\'une fois.');

    /* minInterval : une limite de débit, qui s'applique même à un changement. */
    $routeur = mqttbeRouteurNeuf();
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));
    $routeur->apply(mqttbeTable(1, 'x/y', array(
        mqttbeCible(1, null, null, array('mode' => 'always', 'minInterval' => 60)))));
    $routeur->route('x/y', '1');
    $routeur->route('x/y', '2');
    $routeur->route('x/y', '3');
    $titre = 'minInterval : le débit est limité même quand la valeur change';
    $resultats[] = ($collecteur->valeurs == array(array(1, '1'))) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($collecteur->valeurs) . ' : un capteur bavard '
                    . 'noierait l\'historique de Jeedom.');

    /*
     * keepalive : la seule mesure de ce banc qui prenne du temps réel, et elle
     * le vaut. Sans réémission périodique, le champ « dernière communication »
     * d'un capteur parfaitement stable vieillit indéfiniment, et Jeedom finit
     * par déclarer l'équipement en défaut alors qu'il publie toutes les
     * secondes.
     */
    $routeur = mqttbeRouteurNeuf();
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));
    $routeur->apply(mqttbeTable(1, 'x/y', array(
        mqttbeCible(1, null, null, array('mode' => 'onchange', 'keepalive' => 1)))));
    $routeur->route('x/y', '21');
    $routeur->route('x/y', '21');
    usleep(1050000);
    $routeur->route('x/y', '21');
    $titre = 'keepalive : la valeur inchangée est réémise après le délai';
    $resultats[] = (count($collecteur->valeurs) === 2) ? mqttbeOk($titre)
        : mqttbeEchec($titre, count($collecteur->valeurs) . ' valeur(s) au lieu de 2 : la « dernière '
                    . 'communication » d\'un capteur stable ne serait jamais rafraîchie.');

    /* L'état de répétition est tenu par commande, jamais par topic : c'est la
     * seule façon de garantir que la mémoire du démon soit bornée par la table
     * de Jeedom et non par ce que le broker lui envoie. */
    $routeur = mqttbeRouteurNeuf();
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));
    $routeur->apply(mqttbeTable(1, 'x/#', array(
        mqttbeCible(1, null, null, array('mode' => 'onchange', 'keepalive' => 0)))));
    $avant = memory_get_usage();
    for ($i = 0; $i < 20000; $i++) {
        $routeur->route('x/' . $i, '21');
    }
    $croissance = memory_get_usage() - $avant;
    $titre = 'mémoire bornée sur 20 000 topics distincts';
    /* Le cache de résolution est plafonné et vidé d'un bloc : la croissance
     * doit rester de l'ordre du plafond, pas des topics vus. */
    $resultats[] = ($croissance < 4 * 1048576) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'le routeur a grossi de ' . round($croissance / 1048576, 1)
                    . ' Mo : un appareil publiant sur des topics engendrés finira par faire tuer '
                    . 'le démon par l\'OOM killer, la panne la plus difficile à comprendre de toutes.');

    return $resultats;
}

/* =============================================================================
 * 4. Table de routage : version et abonnements
 * ========================================================================== */
function mqttbeControlesTable() {
    $resultats = array();

    /* Version : deux tables peuvent se croiser sur le réseau local, et Jeedom
     * repousse la sienne à chaque redémarrage du démon. */
    $routeur = mqttbeRouteurNeuf();
    $routeur->apply(mqttbeTable(17, 'a/b', array(mqttbeCible(1))));
    $ancienne = $routeur->apply(mqttbeTable(16, 'c/d', array(mqttbeCible(2))));
    $meme     = $routeur->apply(mqttbeTable(17, 'e/f', array(mqttbeCible(3))));
    $titre = 'une table de version inférieure ou égale est ignorée';
    $resultats[] = (empty($ancienne['applied']) && empty($meme['applied'])
                    && $routeur->subscriptions() === array('a/b' => 0))
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'une table périmée a été appliquée : les commandes créées entre les '
                    . 'deux versions cesseraient de recevoir leurs valeurs, sans un mot nulle part.');

    $titre = 'une table de version supérieure remplace la précédente';
    $routeur->apply(mqttbeTable(18, 'g/h', array(mqttbeCible(4))));
    $resultats[] = ($routeur->subscriptions() === array('g/h' => 0)) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($routeur->subscriptions()));

    /* Abonnements déduits de la table. */
    $routeur = mqttbeRouteurNeuf();
    $routeur->apply(array('cmd' => 'routing', 'version' => 1, 'entries' => array(
        array('topic' => 'shellies/a/power', 'targets' => array(mqttbeCible(1))),
        array('topic' => 'shellies/a/power', 'targets' => array(mqttbeCible(2))),
        array('topic' => 'shellies/+/online', 'targets' => array(mqttbeCible(3))),
    )));
    $titre = 'abonnements déduits de la table, un par topic distinct';
    $resultats[] = ($routeur->subscriptions() === array('shellies/a/power' => 0, 'shellies/+/online' => 0))
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($routeur->subscriptions()));

    $titre = 'table vide : plus aucun abonnement de routage';
    $routeur->apply(array('cmd' => 'routing', 'version' => 2, 'entries' => array()));
    $resultats[] = (empty($routeur->subscriptions())) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($routeur->subscriptions()));

    /* Exclusions : un topic exclu par la configuration n'est jamais souscrit. */
    $routeur = mqttbeRouteurNeuf("jeedom/#\n\$SYS/#");
    $routeur->apply(array('cmd' => 'routing', 'version' => 1, 'entries' => array(
        array('topic' => 'jeedom/dev/cmd', 'targets' => array(mqttbeCible(1))),
        array('topic' => 'shellies/a', 'targets' => array(mqttbeCible(2))),
    )));
    $titre = 'un topic exclu par la configuration n\'est jamais souscrit';
    $resultats[] = ($routeur->subscriptions() === array('shellies/a' => 0)) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($routeur->subscriptions()) . ' : le démon '
                    . 'demanderait au broker ce que l\'utilisateur lui a explicitement interdit de lire.');

    /* Une cible sans identifiant de commande n'a nulle part où poser sa valeur. */
    $routeur = mqttbeRouteurNeuf();
    $bilan = $routeur->apply(mqttbeTable(1, 'a/b', array(array('selector' => array('type' => 'raw')))));
    $titre = 'une cible sans cmdId est rejetée, pas devinée';
    $resultats[] = ($bilan['rejected'] === 1 && $bilan['targets'] === 0) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'obtenu ' . json_encode($bilan));

    /* Rien de ce qui arrive du réseau ne doit pouvoir tuer le démon. */
    $routeur = mqttbeRouteurNeuf();
    $titre = 'une table absurde ne lève pas';
    $echec = null;
    try {
        $routeur->apply('ceci n\'est pas une table');
        $routeur->apply(array('cmd' => 'routing', 'version' => 3, 'entries' => 'zut'));
        $routeur->apply(array('cmd' => 'routing', 'version' => 4, 'entries' => array(
            'chaîne', 42, array('topic' => 'a/b', 'targets' => 'pas un tableau'))));
        $routeur->route('a/b', "\x00\x01 binaire");
    } catch (Throwable $e) {
        $echec = $e->getMessage();
    }
    $resultats[] = ($echec === null) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'exception : ' . $echec . ' — un message du réseau ne doit jamais '
                    . 'pouvoir arrêter le démon.');

    return $resultats;
}

/* =============================================================================
 * 5. Le rejeu : 10 000 messages sur un parc réaliste
 *
 * Cinquante équipements, trois cents commandes, topics plats et JSON, un
 * abonnement à joker par équipement. Les messages sont fabriqués AVANT la
 * mesure, avec la valeur attendue sur chaque commande : le banc compare
 * ensuite ce qui est arrivé à ce qui devait arriver, valeur par valeur et dans
 * l'ordre. C'est le critère d'acceptation du jalon ; le débit n'en est que le
 * sous-produit.
 * ========================================================================== */
function mqttbeTableParc() {
    $entries = array();
    for ($d = 0; $d < BENCH_DEVICES; $d++) {
        $base = 'mqttbe/dev' . $d;
        $cmd  = 1 + $d * 6;

        /* Toutes les cibles en « always » : le rejeu vérifie le routage, et
         * une politique de répétition qui avalerait des valeurs rendrait la
         * comparaison illisible. La répétition a sa propre section. */
        $entries[] = array('topic' => $base . '/relay/0/power', 'targets' => array(
            mqttbeCible($cmd, null, array('round' => 1))));
        $entries[] = array('topic' => $base . '/relay/0', 'targets' => array(
            mqttbeCible($cmd + 1, null, array('map' => array('on' => '1', 'off' => '0')))));
        $entries[] = array('topic' => $base . '/temperature', 'targets' => array(
            mqttbeCible($cmd + 2, null, array('scale' => 0.1, 'round' => 1))));
        /* Deux commandes sur le même état complet : le cas qui justifie le
         * décodage unique. */
        $entries[] = array('topic' => $base . '/status', 'targets' => array(
            mqttbeCible($cmd + 3, array('type' => 'json', 'path' => 'emeter.0.power'), array('round' => 1)),
            mqttbeCible($cmd + 4, array('type' => 'json', 'path' => 'bat.value'), array('round' => 0))));
        $entries[] = array('topic' => $base . '/+/online', 'targets' => array(
            mqttbeCible($cmd + 5, null, array('map' => array('true' => '1', 'false' => '0')))));
    }
    return array('cmd' => 'routing', 'version' => 1, 'entries' => $entries);
}

/* Fabrique les messages et, pour chacun, la liste exacte de ce qui doit en
 * sortir. Rien n'est laissé au hasard : mt_srand fixe la graine, un banc dont
 * les chiffres changent d'une exécution à l'autre ne sert à rien. */
function mqttbeMessagesParc() {
    mt_srand(20250917);
    $messages = array();
    $attendu  = array();

    for ($n = 0; $n < BENCH_MESSAGES; $n++) {
        $d    = $n % BENCH_DEVICES;
        $base = 'mqttbe/dev' . $d;
        $cmd  = 1 + $d * 6;

        switch (intdiv($n, BENCH_DEVICES) % 8) {
            case 0:
                $watts = mt_rand(0, 3000000) / 1000;
                $messages[] = array($base . '/relay/0/power', (string) $watts);
                $attendu[]  = array(array($cmd, (string) round($watts, 1)));
                break;

            case 1:
                $etat = (mt_rand(0, 1) === 1) ? 'on' : 'off';
                $messages[] = array($base . '/relay/0', $etat);
                $attendu[]  = array(array($cmd + 1, $etat === 'on' ? '1' : '0'));
                break;

            case 2:
                $deci = mt_rand(-200, 800);
                $messages[] = array($base . '/temperature', (string) $deci);
                $attendu[]  = array(array($cmd + 2, (string) round($deci * 0.1, 1)));
                break;

            case 3:
            case 4:
                $power = mt_rand(0, 250000) / 100;
                $bat   = mt_rand(0, 10000) / 100;
                $messages[] = array($base . '/status', json_encode(array(
                    'emeter' => array(array('power' => $power, 'voltage' => 231.4)),
                    'bat'    => array('value' => $bat, 'voltage' => 4.1),
                    'name'   => 'dev' . $d)));
                $attendu[]  = array(array($cmd + 3, (string) round($power, 1)),
                                    array($cmd + 4, (string) round($bat, 0)));
                break;

            case 5:
                $en = (mt_rand(0, 1) === 1) ? 'true' : 'false';
                $messages[] = array($base . '/sys/online', $en);
                $attendu[]  = array(array($cmd + 5, $en === 'true' ? '1' : '0'));
                break;

            case 6:
                /* Bruit : un topic réel du même appareil, mais qu'aucune
                 * commande ne lit. Il ne doit rien produire — et surtout pas
                 * une valeur sur la commande voisine. */
                $messages[] = array($base . '/ota_status', 'idle');
                $attendu[]  = array();
                break;

            default:
                /* Charge utile non JSON là où un chemin est demandé : ce n'est
                 * pas une erreur, c'est une valeur qu'on n'émet pas. */
                $messages[] = array($base . '/status', 'Not Found');
                $attendu[]  = array();
                break;
        }
    }
    return array($messages, $attendu);
}

function mqttbeRejeu(&$_chiffres) {
    $resultats = array();

    $routeur = mqttbeRouteurNeuf("jeedom/#\n\$SYS/#");
    $collecteur = new MqttbeCollecteur();
    $routeur->onValue(array($collecteur, 'pousse'));

    $table = mqttbeTableParc();
    $debutTable = microtime(true);
    $bilan = $routeur->apply($table);
    $dureeTable = microtime(true) - $debutTable;

    $titre = BENCH_DEVICES . ' équipements, ' . $bilan['targets'] . ' commandes, '
           . $bilan['subscriptions'] . ' abonnements';
    $resultats[] = ($bilan['targets'] === 300 && $bilan['subscriptions'] === 250)
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'table attendue à 300 cibles et 250 abonnements, obtenu '
                    . $bilan['targets'] . ' et ' . $bilan['subscriptions'] . '.');

    list($messages, $attendu) = mqttbeMessagesParc();

    /* Tout ce qui pouvait être alloué l'a été : ce qui grossit à partir d'ici
     * est le fait du routage lui-même. */
    gc_collect_cycles();
    $memAvant = memory_get_usage();

    $debut = microtime(true);
    foreach ($messages as $message) {
        $routeur->route($message[0], $message[1], 0, false);
    }
    $duree = microtime(true) - $debut;

    $stats = $routeur->stats();

    /* --- le critère d'acceptation : chaque valeur sur la bonne commande --- */
    $plat = array();
    foreach ($attendu as $liste) {
        foreach ($liste as $couple) {
            $plat[] = $couple;
        }
    }

    $titre = 'nombre de valeurs émises';
    $resultats[] = (count($collecteur->valeurs) === count($plat)) ? mqttbeOk($titre)
        : mqttbeEchec($titre, count($collecteur->valeurs) . ' valeur(s) émise(s) pour '
                    . count($plat) . ' attendue(s).');

    $fautes = array();
    $limite = min(count($plat), count($collecteur->valeurs));
    for ($i = 0; $i < $limite; $i++) {
        if ($collecteur->valeurs[$i] !== $plat[$i]) {
            if (count($fautes) < 5) {
                $fautes[] = 'valeur ' . $i . ' : attendu cmd ' . $plat[$i][0] . ' = « ' . $plat[$i][1]
                          . ' », obtenu cmd ' . $collecteur->valeurs[$i][0] . ' = « '
                          . $collecteur->valeurs[$i][1] . ' »';
            }
        }
    }
    $titre = 'chaque valeur sur la bonne commande (' . number_format(count($plat), 0, ',', ' ') . ' valeurs)';
    $resultats[] = empty($fautes) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'une valeur routée vers la mauvaise commande écrit dans Jeedom '
                    . "l'état d'un autre appareil, et rien ne le signale.\n" . implode("\n", $fautes));

    /* Les compteurs doivent raconter la même histoire que le collecteur : ce
     * sont eux qu'un utilisateur lira dans le journal, et un compteur qui ment
     * est pire qu'un compteur absent. */
    $titre = 'compteurs cohérents avec ce qui est sorti';
    $resultats[] = ($stats['received'] === BENCH_MESSAGES
                 && $stats['routed'] === count($collecteur->valeurs)
                 && $stats['noTarget'] === intdiv(BENCH_MESSAGES, 8)
                 && $stats['badPayload'] === intdiv(BENCH_MESSAGES, 8))
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'reçus ' . $stats['received'] . ', routés ' . $stats['routed']
                    . ', sans cible ' . $stats['noTarget'] . ', charges utiles illisibles '
                    . $stats['badPayload'] . ' — attendus ' . BENCH_MESSAGES . ', '
                    . count($collecteur->valeurs) . ', ' . intdiv(BENCH_MESSAGES, 8) . ', '
                    . intdiv(BENCH_MESSAGES, 8) . '.');

    $titre = 'aucune exception pendant le rejeu';
    $resultats[] = ($stats['errors'] === 0) ? mqttbeOk($titre)
        : mqttbeEchec($titre, $stats['errors'] . ' erreur(s) rattrapée(s) : voir le journal.');

    /*
     * Ce que le ROUTEUR a pris, et lui seul : les dix mille valeurs gardées
     * par le collecteur sont relâchées d'abord. En production elles ne
     * séjournent pas — la liaison Jeedom les groupe et les poste — et les
     * compter ici donnerait un chiffre trois fois trop gros, qui masquerait
     * justement ce qu'on surveille : le routeur grossit-il avec le trafic ?
     */
    $valeursEmises = count($collecteur->valeurs);
    $collecteur->vide();
    unset($plat);
    gc_collect_cycles();
    $memoire = memory_get_usage() - $memAvant;

    $debit = ($duree > 0) ? (BENCH_MESSAGES / $duree) : 0;
    $titre = 'débit tenu au-delà de 2 000 messages/s';
    $resultats[] = ($debit >= 2000) ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'débit mesuré ' . round($debit) . ' messages/s, en deçà de la cible '
                    . 'du contrat sur matériel modeste.');

    $_chiffres = array(
        'messages'    => BENCH_MESSAGES,
        'valeurs'     => $valeursEmises,
        'duree'       => $duree,
        'debit'       => $debit,
        'mediane'     => $stats['median'],
        'memoire'     => $memoire,
        'pic'         => memory_get_peak_usage(true),
        'table'       => $dureeTable,
        'cibles'      => $bilan['targets'],
        'abonnements' => $bilan['subscriptions'],
        'decodages'   => $stats['parsed'],
    );

    return $resultats;
}

/* =============================================================================
 * Compte rendu
 * ========================================================================== */
function mqttbeLigneChiffre($_titre, $_valeur, $_largeur = 40) {
    $points = max(3, $_largeur - mqttbeLongueur($_titre));
    echo '  ' . $_titre . ' ' . str_repeat('.', $points) . ' ' . $_valeur . "\n";
}

$chiffres = array();
$sections = array(
    'Correspondance des topics MQTT'   => mqttbeControlesCorrespondance(),
    'Sélecteurs et transformations'    => mqttbeControlesValeurs(),
    'Politique de répétition'          => mqttbeControlesRepetition(),
    'Table de routage et abonnements'  => mqttbeControlesTable(),
    'Rejeu de ' . number_format(BENCH_MESSAGES, 0, ',', ' ') . ' messages' => mqttbeRejeu($chiffres),
);

$tous = array();
foreach ($sections as $titre => $resultats) {
    echo "\n== " . $titre . " ==\n";
    mqttbeAffiche($resultats, 66);
    $tous = array_merge($tous, $resultats);
}

echo "\n== Chiffres ==\n";
mqttbeLigneChiffre('table appliquée en',
    number_format($chiffres['table'] * 1000, 2, ',', ' ') . ' ms ('
    . $chiffres['cibles'] . ' cibles, ' . $chiffres['abonnements'] . ' abonnements)');
mqttbeLigneChiffre('messages rejoués',
    number_format($chiffres['messages'], 0, ',', ' ') . ' en '
    . number_format($chiffres['duree'], 3, ',', ' ') . ' s');
mqttbeLigneChiffre('valeurs émises vers Jeedom', number_format($chiffres['valeurs'], 0, ',', ' ')
    . ' (' . number_format($chiffres['decodages'], 0, ',', ' ') . ' décodages JSON)');
mqttbeLigneChiffre('débit', number_format($chiffres['debit'], 0, ',', ' ') . ' messages/s');
mqttbeLigneChiffre('latence médiane par message',
    number_format($chiffres['mediane'], 4, ',', ' ') . ' ms');
mqttbeLigneChiffre('mémoire prise par le routeur',
    number_format($chiffres['memoire'] / 1024, 1, ',', ' ') . ' Ko après le rejeu');
mqttbeLigneChiffre('pic mémoire du processus',
    number_format($chiffres['pic'] / 1048576, 1, ',', ' ') . ' Mo');
echo "  (le pic est celui du BANC : il garde en mémoire les " . number_format(BENCH_MESSAGES, 0, ',', ' ')
   . " messages,\n   leurs valeurs attendues et tout ce qui est sorti, ce que le démon ne fait pas.)\n";

$bilan = mqttbeBilan($tous);
printf("\n  ==> %d réussi(s), %d échec(s), %d non vérifiable(s)\n\n",
       $bilan[MQTTBE_OK], $bilan[MQTTBE_ECHEC], $bilan[MQTTBE_INDECIS]);
exit($bilan[MQTTBE_ECHEC] === 0 ? 0 : 1);
