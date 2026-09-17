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

    public function __construct(MqttbeConfig $_config, MqttbeTransport $_transport,
                                MqttbeJeedomLink $_link, MqttbeCommandSocket $_commands, $_pidFile) {
        $this->config    = $_config;
        $this->transport = $_transport;
        $this->link      = $_link;
        $this->commands  = $_commands;
        $this->pidFile   = (string) $_pidFile;

        $this->transport->onMessage(array($this, 'onMessage'));
        $this->commands->onCommand(array($this, 'onCommand'));
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
     * POINT D'EXTENSION — tout ce que le plugin fera des messages MQTT passera
     * par ici.
     *
     * Aux jalons 0 et 1, le démon ne route rien et ne découvre rien : il compte
     * et il journalise. La suite (table de routage, sélecteurs, adapters de
     * découverte) se branche à cet endroit précis, et nulle part ailleurs —
     * c'est la raison d'être de cette méthode isolée, appelée par le transport
     * et ignorante de la bibliothèque qui la déclenche.
     *
     * $_retained est transmis jusqu'ici et ne doit jamais être perdu en route :
     * il dit « état rejoué par le broker » et non « cela vient de se produire ».
     */
    public function onMessage($_topic, $_payload, $_qos, $_retained) {
        if ($this->config->isExcluded($_topic)) {
            $this->excluded++;
            return;
        }
        $this->received++;

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
                return array('state' => 'ok', 'result' => array(
                    'broker'   => $this->brokerOk ? 'ok' : 'nok',
                    'received' => $this->received,
                    'pending'  => $this->link->pending(),
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

    /* --------------------------------------------------------------- vie */

    private function report() {
        if ((time() - $this->lastReport) < self::REPORT_PERIOD) {
            return;
        }
        $this->lastReport = time();
        MqttbeLog::info('activité : ' . $this->received . ' message(s) reçus, '
                      . $this->excluded . ' écarté(s), ' . $this->link->pending()
                      . ' en attente d\'envoi, mémoire ' . round(memory_get_usage(true) / 1048576, 1) . ' Mo');
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
