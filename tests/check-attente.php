<?php
/* La file des ordres émis pendant le démarrage du démon, mise à l'épreuve.
 *
 *   php tests/check-attente.php
 *
 * Comme check-adoption.php, ce contrôle charge le VRAI mqttbeDaemon par-dessus
 * le cœur de papier : c'est là que vit la file. tests/run.php le lance donc
 * dans son propre processus.
 *
 * Le 5 novembre 2026, au redémarrage du serveur, vingt « Allumer » et cinq
 * « Chaudière Éteindre » ont échoué sur « le démon n'est pas démarré » : Jeedom
 * lance scénarios et crons avant les démons. Ce qui est vérifié ici :
 *
 *   - pendant un démarrage annoncé, un ordre est gardé au lieu d'échouer ;
 *   - hors de cette fenêtre, un démon absent refuse toujours à voix haute ;
 *   - un seul ordre par topic, le dernier ;
 *   - la file est bornée et garde les plus récents ;
 *   - un ordre trop vieux est abandonné, et le journal le dit — un
 *     « Allumer » rejoué dix minutes plus tard serait pire que perdu ;
 *   - un ordre que le démon n'a pas pu prendre retourne en file, et rien ne
 *     part tant que le broker n'est pas connecté. */

require_once __DIR__ . '/outils.php';

function mqttbeAttentePrete() {
    static $pret = null;
    if ($pret !== null) {
        return $pret;
    }
    if (!defined('MQTTBE_VRAI_DEMON')) {
        define('MQTTBE_VRAI_DEMON', true);
    }
    require_once __DIR__ . '/faux-coeur.php';
    mqttbeChargeClassesPlugin();
    return $pret = class_exists('mqttbeDaemon');
}

/* Table rase. La gestion automatique est coupée : sans cela, la file
 * s'ouvrirait ou non selon le temps écoulé depuis le démarrage de la machine
 * qui fait tourner les contrôles. */
function mqttbeAttenteRAZ() {
    MqttbeFauxCoeur::reinitialise();
    config::save('deamonAutoMode', 0, 'mqttbe');
}

/* Le démon s'annonce, sur un port où personne n'écoute : chaque envoi échoue. */
function mqttbeAttenteDemonInjoignable($_broker = 'ok') {
    cache::set(mqttbeDaemon::CACHE_UID, '999999:1');
    cache::set(mqttbeDaemon::CACHE_PORT, 1);
    cache::set(mqttbeDaemon::CACHE_BROKER, $_broker);
}

function mqttbeAttentePublie($_topic, $_payload) {
    try {
        mqttbeDaemon::publish($_topic, $_payload);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function mqttbeControlesAttente() {
    if (!mqttbeAttentePrete()) {
        return array(mqttbeIndecis('file des ordres en attente',
            "core/class/mqttbeDaemon.class.php n'existe pas."));
    }
    /* La classe existe sans sa file : c'est la panne même, pas un fichier
     * encore à écrire. */
    if (!method_exists('mqttbeDaemon', 'queuePublish') || !method_exists('mqttbeDaemon', 'flushQueued')) {
        return array(mqttbeEchec('pendant le démarrage du démon, un ordre attend au lieu d\'échouer',
            "mqttbeDaemon n'a pas de file d'attente : au redémarrage du serveur, tout ordre "
            . 'émis avant le démon est perdu.'));
    }
    $resultats = array();

    /* 1. Démarrage annoncé : l'ordre attend. */
    $titre = 'pendant le démarrage du démon, un ordre attend au lieu d\'échouer';
    mqttbeAttenteRAZ();
    cache::set(mqttbeDaemon::CACHE_DEMARRAGE, time() - 30);
    $erreur = mqttbeAttentePublie('shellies/lampe/relay/0/command', 'on');
    $file = mqttbeDaemon::queuedOrders();
    $fautes = array();
    if ($erreur !== null) {
        $fautes[] = 'publish() a levé : « ' . $erreur . ' ».';
    }
    if (!isset($file['shellies/lampe/relay/0/command'])
        || $file['shellies/lampe/relay/0/command']['payload'] !== 'on') {
        $fautes[] = "l'ordre n'est pas dans la file.";
    }
    if (!MqttbeFauxCoeur::journalContient('mis en attente', 'info')) {
        $fautes[] = "la mise en attente n'est pas journalisée.";
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "Au redémarrage du serveur, les ordres des scénarios lancés avant le démon sont perdus :\n"
        . implode("\n", $fautes));

    /* 2. Hors fenêtre : le refus reste franc. */
    $titre = 'un démon arrêté hors démarrage refuse toujours les ordres';
    mqttbeAttenteRAZ();
    $erreur1 = mqttbeAttentePublie('a/b', 'on');
    cache::set(mqttbeDaemon::CACHE_DEMARRAGE, time() - 600);
    $erreur2 = mqttbeAttentePublie('a/b', 'on');
    $resultats[] = ($erreur1 !== null && $erreur2 !== null && empty(mqttbeDaemon::queuedOrders()))
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'un démon arrêté exprès accumule des ordres en silence au lieu de le dire.');

    /* 3. Un seul ordre par topic, le dernier. */
    $titre = 'un seul ordre par topic : le dernier demandé';
    mqttbeAttenteRAZ();
    cache::set(mqttbeDaemon::CACHE_DEMARRAGE, time());
    mqttbeAttentePublie('chaudiere/relay/0/command', 'on');
    mqttbeAttentePublie('autre/topic', '1');
    mqttbeAttentePublie('chaudiere/relay/0/command', 'off');
    $file = mqttbeDaemon::queuedOrders();
    $resultats[] = (count($file) === 2 && $file['chaudiere/relay/0/command']['payload'] === 'off')
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, count($file) . ' ordre(s) en file : « Allumer » puis « Éteindre » '
            . 'rejoués dans le désordre, ou deux fois.');

    /* 4. Bornée, en gardant les plus récents. */
    $titre = 'la file est bornée et garde les ordres les plus récents';
    mqttbeAttenteRAZ();
    $t = time();
    for ($i = 0; $i < mqttbeDaemon::ATTENTE_MAX + 20; $i++) {
        mqttbeDaemon::queuePublish(array('cmd' => 'publish', 'topic' => 't/' . $i, 'payload' => 'x',
                                         'qos' => 0, 'retain' => false), $t);
    }
    $file = mqttbeDaemon::queuedOrders();
    $dernier = 't/' . (mqttbeDaemon::ATTENTE_MAX + 19);
    $resultats[] = (count($file) === mqttbeDaemon::ATTENTE_MAX && isset($file[$dernier]) && !isset($file['t/0']))
        ? mqttbeOk($titre)
        : mqttbeEchec($titre, count($file) . ' ordre(s) en file, le plus récent '
            . (isset($file[$dernier]) ? 'gardé' : 'jeté') . '.');

    /* 5. Péremption. */
    $titre = 'un ordre trop vieux est abandonné, et le journal le dit';
    mqttbeAttenteRAZ();
    $t = time();
    $ordre = array('cmd' => 'publish', 'topic' => 'vieux', 'payload' => 'on', 'qos' => 0, 'retain' => false);
    mqttbeDaemon::queuePublish($ordre, $t - mqttbeDaemon::ATTENTE_TTL - 60);
    $ordre['topic'] = 'frais';
    mqttbeDaemon::queuePublish($ordre, $t - 30);
    $pris = mqttbeDaemon::takeQueued($t);
    $fautes = array();
    if (isset($pris['vieux'])) {
        $fautes[] = 'un ordre vieux de ' . (mqttbeDaemon::ATTENTE_TTL + 60) . ' s serait publié.';
    }
    if (!isset($pris['frais'])) {
        $fautes[] = "l'ordre récent n'est pas rendu.";
    }
    if (!MqttbeFauxCoeur::journalContient('abandonné', 'warning')) {
        $fautes[] = "l'abandon n'est pas journalisé en avertissement.";
    }
    if (!empty(mqttbeDaemon::queuedOrders())) {
        $fautes[] = "la file n'est pas vidée : les ordres partiraient deux fois.";
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* 6. Un envoi raté remet en file ; un broker absent ne vide rien. */
    $titre = 'broker absent ou démon injoignable : l\'ordre reste en file';
    mqttbeAttenteRAZ();
    mqttbeDaemon::queuePublish(array('cmd' => 'publish', 'topic' => 'lampe', 'payload' => 'on',
                                     'qos' => 0, 'retain' => false));
    $fautes = array();
    mqttbeAttenteDemonInjoignable('nok');
    if (mqttbeDaemon::flushQueued() !== 0 || !isset(mqttbeDaemon::queuedOrders()['lampe'])) {
        $fautes[] = "la file est vidée alors que le broker n'est pas connecté.";
    }
    mqttbeAttenteDemonInjoignable('ok');
    if (mqttbeDaemon::flushQueued() !== 0 || !isset(mqttbeDaemon::queuedOrders()['lampe'])) {
        $fautes[] = "un ordre que le démon n'a pas reçu a disparu de la file.";
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Ordres en attente du démon', mqttbeControlesAttente());
}
