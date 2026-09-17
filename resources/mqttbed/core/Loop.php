<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Le routeur est une dépendance de la boucle et d'elle seule : le point
 * d'entrée n'a pas à savoir qu'il existe, ni dans quel ordre le charger. */
require_once __DIR__ . '/Router.php';

/* =============================================================================
 * La boucle principale : un seul processus, un seul fil, trois sources
 * d'événements multiplexées par stream_select.
 *
 *   - la socket du broker         → messages MQTT
 *   - la socket de commande       → ordres de Jeedom
 *   - le temps qui passe          → battements, reconnexions, lots à envoyer
 *
 * Le choix d'un mono-fil sans fork est délibéré : tout ce que fait ce démon
 * est de l'attente de réseau, et deux processus qui se partageraient une
 * connexion MQTT — dont l'identifiant de message est un compteur unique —
 * produiraient des trames entrelacées illisibles par le broker.
 *
 * Rien, dans cette boucle, n'a le droit de durer. Le seul appel qui attende
 * vraiment est la tentative de connexion au broker (cinq secondes au pire),
 * et c'est précisément pourquoi ces tentatives sont espacées par un recul
 * progressif au lieu d'être retentées à chaque tour.
 * ========================================================================== */
class MqttbeLoop {

    const SELECT_TIMEOUT_US = 50000;   // 50 ms : assez court pour que les lots partent à l'heure
    const RETRY_BASE        = 2;       // secondes avant la première nouvelle tentative
    const RETRY_MAX         = 60;      // plafond du recul progressif
    const REPORT_PERIOD     = 300;     // résumé d'activité dans le journal

    private $config;
    private $transport;
    private $link;
    private $commands;
    private $pidFile;
    private $router;

    private $running  = true;
    private $exitCode = 0;

    /* État de la liaison au broker, du point de vue de Jeedom : il ne sert
     * qu'à n'annoncer que les CHANGEMENTS. Répéter `brokerDown` à chaque
     * tentative ratée ferait clignoter l'interface toutes les deux secondes. */
    private $brokerOk        = false;
    private $brokerAnnounced = false;

    private $attempts  = 0;
    private $nextRetry = 0.0;
    private $waitingForConfig = true;

    private $received   = 0;
    private $excluded   = 0;
    private $lastReport = 0;

    /* Compteurs au dernier résumé : le journal donne l'activité de la période
     * écoulée, pas des totaux depuis le démarrage — « 4 812 371 messages reçus »
     * répété toutes les cinq minutes ne dit rien de ce qui vient de se passer. */
    private $lastCounts = array('received' => 0, 'excluded' => 0, 'routed' => 0,
                                'ignored' => 0, 'noTarget' => 0);

    /* Abonnements posés pour le compte de la table de routage. Tenus à part de
     * ceux que Jeedom demande à la main (ordre `subscribe`) : une nouvelle
     * table ne doit toucher qu'aux siens. */
    private $routingSubs = array();

    public function __construct(MqttbeConfig $_config, MqttbeTransport $_transport,
                                MqttbeJeedomLink $_link, MqttbeCommandSocket $_commands, $_pidFile) {
        $this->config    = $_config;
        $this->transport = $_transport;
        $this->link      = $_link;
        $this->commands  = $_commands;
        $this->pidFile   = (string) $_pidFile;

        $this->transport->onMessage(array($this, 'onMessage'));
        $this->commands->onCommand(array($this, 'onCommand'));

        /* Le routeur ne connaît ni le transport ni Jeedom : il reçoit des
         * messages d'un côté, rend des couples (commande, valeur) de l'autre,
         * et c'est la boucle qui branche les deux bouts. */
        $this->router = new MqttbeRouter($this->config);
        $this->router->onValue(array($this->link, 'pushValue'));
    }

    public function stop() {
        $this->running = false;
    }

    public function run() {
        /*
         * La socket de commande s'ouvre AVANT l'annonce : Jeedom, en recevant
         * `daemonUp`, pousse aussitôt la configuration du broker. Annoncer
         * d'abord, c'est se faire envoyer un `setBroker` sur un port qui
         * n'écoute pas encore.
         */
        if (!$this->commands->listen()) {
            $this->cleanup();
            return 1;
        }
        if (!$this->link->sendNow(array('cmd' => 'daemonUp'))) {
            MqttbeLog::error('Jeedom n\'a pas accepté l\'annonce de démarrage : arrêt immédiat. '
                           . 'Vérifier l\'URL de rappel et la clé d\'API.');
            $this->cleanup();
            return 1;
        }
        MqttbeLog::info('démon prêt (pid ' . getmypid() . ', journal ' . MqttbeLog::level() . ')');
        $this->lastReport = time();

        while ($this->running) {
            try {
                $this->iterate();
            } catch (Throwable $e) {
                /*
                 * Dernier filet. Une seule chose est pire qu'un démon qui se
                 * trompe : un démon qui meurt en silence, laissant Jeedom le
                 * relancer toutes les minutes sans que personne ne sache
                 * pourquoi. On journalise, et on continue.
                 */
                MqttbeLog::error('erreur non rattrapée dans la boucle : ' . $e->getMessage()
                               . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                usleep(200000);
            }
        }

        $this->shutdown();
        return $this->exitCode;
    }

    /* ------------------------------------------------------------ un tour */

    private function iterate() {
        $this->connectBroker();

        $read = $this->commands->streams();
        $brokerStream = $this->transport->stream();
        if (is_resource($brokerStream)) {
            $read[] = $brokerStream;
        }

        if (empty($read)) {
            /* Ni broker ni socket de commande : il n'y a rien à surveiller,
             * mais les échéances, elles, continuent de courir. */
            usleep(self::SELECT_TIMEOUT_US);
        } else {
            $write = null; $except = null;
            /* Le @ n'est pas de la paresse : un signal reçu pendant l'attente
             * interrompt stream_select, qui se plaint d'un EINTR dont on n'a
             * rien à faire — l'arrêt demandé sera vu au prochain test. */
            if (@stream_select($read, $write, $except, 0, self::SELECT_TIMEOUT_US) > 0) {
                $this->commands->handleReady($read);
            }
        }

        /*
         * tick() est appelé même quand le descripteur n'était pas prêt : le
         * ping de keepalive et les réémissions ne dépendent pas de ce que le
         * broker envoie, et un broker silencieux couperait la session si nous
         * n'attendions que lui pour lui parler.
         */
        if ($this->transport->isConnected() && !$this->transport->tick()) {
            $this->brokerLost($this->transport->lastError());
        }

        $this->commands->purge();
        $this->link->tick();

        if ($this->link->isDead()) {
            /* Voir JeedomLink : on préfère mourir qu'accumuler. Le cron de
             * Jeedom relancera le démon quand Jeedom sera de nouveau là. */
            MqttbeLog::error('Jeedom injoignable depuis ' . $this->link->silentFor()
                           . ' s : arrêt du démon (' . $this->link->pending() . ' message(s) perdus)');
            $this->exitCode = 1;
            $this->running  = false;
        }

        $this->report();
    }

    /* ------------------------------------------------------------- broker */

    private function connectBroker() {
        if ($this->transport->isConnected()) {
            return;
        }
        if (!$this->config->isUsable()) {
            if ($this->waitingForConfig) {
                MqttbeLog::info('en attente de la configuration du broker (message setBroker)');
                $this->waitingForConfig = false;
            }
            return;
        }
        if (microtime(true) < $this->nextRetry) {
            return;
        }

        MqttbeLog::info('connexion au broker ' . $this->config->describe() . '…');
        if ($this->transport->connect()) {
            $this->attempts  = 0;
            $this->nextRetry = 0.0;
            MqttbeLog::info('connecté au broker');
            if (!$this->brokerOk || !$this->brokerAnnounced) {
                $this->brokerOk        = true;
                $this->brokerAnnounced = true;
                $this->link->push(array('cmd' => 'brokerUp'));
            }
            return;
        }

        $error = $this->transport->lastError();
        $delay = $this->scheduleRetry();
        MqttbeLog::error('connexion au broker impossible : ' . $error
                       . ' — nouvel essai dans ' . $delay . ' s');

        /* Une seule annonce par panne : la suivante sera `brokerUp`. */
        if ($this->brokerOk || !$this->brokerAnnounced) {
            $this->brokerOk        = false;
            $this->brokerAnnounced = true;
            $this->link->push(array('cmd' => 'brokerDown', 'message' => $error));
        }
    }

    private function brokerLost($_reason) {
        MqttbeLog::warning('liaison avec le broker perdue : ' . $_reason);
        $this->transport->disconnect();
        if ($this->brokerOk || !$this->brokerAnnounced) {
            $this->brokerOk        = false;
            $this->brokerAnnounced = true;
            $this->link->push(array('cmd' => 'brokerDown', 'message' => $_reason));
        }
        /* Le recul repart de zéro : la liaison fonctionnait il y a une seconde,
         * rien ne dit que le broker est durablement absent. */
        $this->attempts = 0;
        $this->scheduleRetry();
    }

    /*
     * Recul exponentiel plafonné, avec gigue.
     *
     * La gigue n'est pas décorative : quand le broker redémarre, tous ses
     * clients — le démon, les Shelly, les passerelles — le retrouvent à la même
     * seconde et se présentent ensemble. Sur un Mosquitto de Raspberry Pi, cette
     * foule suffit à faire échouer une partie des connexions, qui repartent
     * alors ensemble pour un nouveau tour.
     */
    private function scheduleRetry() {
        $delay = min(self::RETRY_MAX, self::RETRY_BASE * pow(2, min(6, $this->attempts)));
        $delay = (int) max(1, $delay * (0.9 + (mt_rand(0, 200) / 1000)));
        $this->attempts++;
        $this->nextRetry = microtime(true) + $delay;
        return $delay;
    }

    /* ----------------------------------------------------------- messages */

    /*
     * POINT D'EXTENSION — tout ce que le plugin fait des messages MQTT passe
     * par ici. C'est le chemin chaud du démon : deux mille messages par
     * seconde le traversent sur un parc chargé, et rien de ce qui s'y ajoute
     * ne doit coûter plus que ce qu'il apporte.
     *
     * L'exclusion est vérifiée avant toute chose, et avant même le comptage :
     * un topic écarté par la configuration n'est pas un message reçu, c'est un
     * message qu'on n'a pas voulu. La découverte, au jalon suivant, viendra se
     * brancher ici et nulle part ailleurs.
     *
     * $_retained est transmis jusqu'ici et ne doit jamais être perdu en route :
     * il dit « état rejoué par le broker » et non « cela vient de se produire ».
     * Le routage le traite comme un message ordinaire — un état rejoué reste
     * l'état courant de l'appareil — mais il le reçoit, et c'est ce qui compte.
     */
    public function onMessage($_topic, $_payload, $_qos, $_retained) {
        if ($this->config->isExcluded($_topic)) {
            $this->excluded++;
            return;
        }
        $this->received++;

        $this->router->route($_topic, $_payload, $_qos, $_retained);

        if (MqttbeLog::isDebug()) {
            $payload = (string) $_payload;
            /* Une charge utile peut peser des dizaines de kilo-octets (une
             * photo encodée, un état complet) : le journal n'en a pas besoin. */
            if (strlen($payload) > 256) {
                $payload = substr($payload, 0, 256) . '… (' . strlen((string) $_payload) . ' octets)';
            }
            MqttbeLog::debug('reçu ' . $_topic . ' (QoS ' . $_qos
                           . ($_retained ? ', retenu' : '') . ') : ' . $payload);
        }
    }

    /* ------------------------------------------------------------- ordres */

    /*
     * Traite un ordre de Jeedom, déjà authentifié par la socket de commande, et
     * rend la réponse à lui retourner. Ne lève jamais : un ordre incompris vaut
     * une réponse d'erreur, pas un démon mort.
     */
    public function onCommand($_order) {
        $cmd = isset($_order['cmd']) ? (string) $_order['cmd'] : '';
        MqttbeLog::debug('ordre reçu : ' . $cmd);

        switch ($cmd) {
            case 'hb':
                /* La réponse porte l'état du broker : Jeedom en profite pour
                 * rafraîchir son affichage sans avoir à demander deux fois. */
                $stats = $this->router->stats();
                return array('state' => 'ok', 'result' => array(
                    'broker'   => $this->brokerOk ? 'ok' : 'nok',
                    'received' => $this->received,
                    'pending'  => $this->link->pending(),
                    /* La version de table appliquée voyage dans chaque
                     * battement : c'est ainsi que Jeedom s'aperçoit qu'un
                     * démon relancé n'a pas la table courante, sans avoir à
                     * la repousser toutes les minutes à tout hasard. */
                    'routing'  => $this->router->version(),
                    'routed'   => $stats['routed'],
                ));

            case 'loglevel':
                $level = isset($_order['level']) ? $_order['level'] : '';
                if (!MqttbeLog::setLevel($level)) {
                    return array('state' => 'error', 'result' => 'niveau de journal inconnu : ' . $level);
                }
                MqttbeLog::info('niveau de journal porté à ' . MqttbeLog::level());
                return array('state' => 'ok');

            case 'setBroker':
                return $this->applyBroker(isset($_order['config']) ? $_order['config'] : array());

            case 'routing':
                return $this->applyRouting($_order);

            case 'subscribe':
                $topic = isset($_order['topic']) ? (string) $_order['topic'] : '';
                if ($topic === '') {
                    return array('state' => 'error', 'result' => 'topic manquant');
                }
                $qos = isset($_order['qos']) ? (int) $_order['qos'] : 0;
                return $this->transport->subscribe($topic, $qos)
                    ? array('state' => 'ok')
                    : array('state' => 'error', 'result' => $this->transport->lastError());

            case 'unsubscribe':
                $topic = isset($_order['topic']) ? (string) $_order['topic'] : '';
                if ($topic === '') {
                    return array('state' => 'error', 'result' => 'topic manquant');
                }
                return $this->transport->unsubscribe($topic)
                    ? array('state' => 'ok')
                    : array('state' => 'error', 'result' => $this->transport->lastError());

            case 'publish':
                $topic = isset($_order['topic']) ? (string) $_order['topic'] : '';
                if ($topic === '') {
                    return array('state' => 'error', 'result' => 'topic manquant');
                }
                $ok = $this->transport->publish(
                    $topic,
                    isset($_order['payload']) ? $_order['payload'] : '',
                    isset($_order['qos']) ? (int) $_order['qos'] : 0,
                    isset($_order['retain']) ? (bool) $_order['retain'] : false
                );
                return $ok ? array('state' => 'ok')
                           : array('state' => 'error', 'result' => $this->transport->lastError());

            case 'stop':
                MqttbeLog::info('arrêt demandé par Jeedom');
                $this->running = false;
                return array('state' => 'ok');

            default:
                return array('state' => 'error', 'result' => 'commande inconnue : ' . $cmd);
        }
    }

    private function applyBroker($_config) {
        if (!is_array($_config)) {
            return array('state' => 'error', 'result' => 'configuration illisible');
        }
        /* Tolérés mais facultatifs : setBroker est le seul message qui apporte
         * des réglages au démon, et ces deux-là n'en méritaient pas un autre.
         * Absents, le démon garde ce qu'il avait. */
        if (isset($_config['batchDelay'])) {
            $this->link->setBatchDelay($_config['batchDelay']);
        }
        /*
         * Le fuseau de Jeedom, s'il consent à le dire : le démon ne charge pas
         * le coeur et ne peut donc pas le lire lui-même. Sans lui, ses lignes
         * de journal peuvent être décalées de deux heures par rapport à celles
         * de Jeedom — assez pour qu'on ne rapproche plus un message MQTT de
         * l'événement qu'il a provoqué.
         */
        if (isset($_config['timezone']) && is_string($_config['timezone'])
         && in_array($_config['timezone'], timezone_identifiers_list(), true)
         && $_config['timezone'] !== date_default_timezone_get()) {
            date_default_timezone_set($_config['timezone']);
            MqttbeLog::info('journal daté en ' . $_config['timezone']);
        }

        $changed = $this->config->apply($_config);
        if (!$changed) {
            MqttbeLog::debug('configuration du broker inchangée');
            /*
             * Configuration identique, mais liaison absente : l'utilisateur
             * vient d'enregistrer sa page, sans doute parce qu'il a réparé
             * quelque chose de l'autre côté. Lui faire attendre la fin du
             * recul progressif — jusqu'à une minute devant un écran qui ne
             * bouge pas — serait le pousser à redémarrer le démon pour rien.
             */
            if (!$this->transport->isConnected()) {
                $this->nextRetry = 0.0;
            }
            return array('state' => 'ok');
        }

        MqttbeLog::info('nouvelle configuration du broker : ' . $this->config->describe());
        /*
         * La liaison en place est coupée sans annoncer `brokerDown` : ce n'est
         * pas une panne, c'est un changement voulu, et la tentative qui suit
         * dira tout de suite où en est la nouvelle configuration.
         */
        $this->transport->disconnect();
        $this->brokerOk         = false;
        $this->brokerAnnounced  = false;
        $this->attempts         = 0;
        $this->nextRetry        = 0.0;
        $this->waitingForConfig = true;
        return array('state' => 'ok');
    }

    /* ------------------------------------------------------------ routage */

    /*
     * Une nouvelle table de routage. Deux effets, et dans cet ordre : le
     * routeur l'applique, puis les abonnements sont mis en accord avec elle.
     *
     * L'inverse — s'abonner d'abord — ferait arriver des messages que le
     * routeur ne saurait pas encore placer, et le premier état retenu d'un
     * appareil, celui que le broker rejoue aussitôt l'abonnement posé, serait
     * justement celui qu'on perdrait.
     */
    private function applyRouting($_order) {
        $result = $this->router->apply($_order);
        if (empty($result['applied'])) {
            /* Une table périmée n'est pas une erreur : Jeedom a le droit de
             * pousser deux fois, et c'est même ce qu'il fait à chaque
             * redémarrage du démon. */
            return array('state' => 'ok', 'result' => $result);
        }
        $this->syncRoutingSubscriptions();
        return array('state' => 'ok', 'result' => $result);
    }

    /*
     * Différence entre les abonnements voulus et ceux déjà posés.
     *
     * Le calcul par différence n'est pas une élégance : résilier puis
     * reprendre un abonnement inchangé ferait rejouer par le broker tous les
     * messages retenus de la branche concernée — pour un parc Shelly, des
     * centaines d'états d'un coup, à chaque enregistrement d'une commande
     * dans Jeedom.
     */
    private function syncRoutingSubscriptions() {
        $wanted = $this->router->subscriptions();

        foreach ($this->routingSubs as $topic => $qos) {
            if (!isset($wanted[$topic])) {
                $this->transport->unsubscribe($topic);
            }
        }
        foreach ($wanted as $topic => $qos) {
            if (!isset($this->routingSubs[$topic])) {
                $this->transport->subscribe($topic, $qos);
            }
        }
        $this->routingSubs = $wanted;
    }

    /* --------------------------------------------------------------- vie */

    /*
     * Le résumé d'activité, toutes les cinq minutes, au niveau info.
     *
     * Il est MUET quand il n'y a rien à dire. Un démon de maison passe des
     * nuits entières sans un message : une ligne « 0 message » toutes les cinq
     * minutes, c'est deux cent quatre-vingt-huit lignes par jour qui n'ont
     * jamais rien appris à personne, et un journal que plus personne ne lit —
     * y compris le matin où il contenait enfin quelque chose.
     */
    private function report() {
        if ((time() - $this->lastReport) < self::REPORT_PERIOD) {
            return;
        }
        $this->lastReport = time();

        $stats = $this->router->stats();
        $since = array('received' => $this->received, 'excluded' => $this->excluded,
                       'routed'   => $stats['routed'], 'ignored' => $stats['ignored'],
                       'noTarget' => $stats['noTarget']);
        $delta = array();
        foreach ($since as $key => $value) {
            $delta[$key] = $value - $this->lastCounts[$key];
        }
        $this->lastCounts = $since;

        $pending = $this->link->pending();
        if ($delta['received'] === 0 && $delta['excluded'] === 0 && $pending === 0) {
            return;
        }

        MqttbeLog::info('activité (5 min) : ' . $delta['received'] . ' message(s) reçus, '
                      . $delta['routed'] . ' valeur(s) routée(s), ' . $delta['ignored'] . ' ignorée(s), '
                      . $delta['noTarget'] . ' sans cible, ' . $delta['excluded'] . ' écarté(s), '
                      . 'file ' . $pending . ', latence médiane '
                      . number_format($stats['median'], 3, ',', ' ') . ' ms, '
                      . 'mémoire ' . round(memory_get_usage(true) / 1048576, 1) . ' Mo');
    }

    private function shutdown() {
        MqttbeLog::info('arrêt du démon');
        try {
            $this->transport->disconnect();
        } catch (Throwable $e) {
            MqttbeLog::debug('déconnexion du broker : ' . $e->getMessage());
        }
        /* En synchrone, et avant de fermer quoi que ce soit : mis en file, ce
         * message ne partirait jamais, et Jeedom attendrait 300 s pour
         * comprendre tout seul que le démon s'est arrêté. */
        $this->link->sendNow(array('cmd' => 'daemonDown'));
        $this->cleanup();
    }

    private function cleanup() {
        $this->commands->close();
        $this->link->close();
        if ($this->pidFile !== '' && file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }
    }
}
