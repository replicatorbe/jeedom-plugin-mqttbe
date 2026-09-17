<?php
/* This file is part of the mqttbe plugin for Jeedom.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/* Le routeur est une dépendance de la boucle et d'elle seule : le point
 * d'entrée n'a pas à savoir qu'il existe, ni dans quel ordre le charger. */
require_once __DIR__ . '/Router.php';
/* Le moteur de découverte, de même : il ne sait rien du transport ni de
 * Jeedom, c'est la boucle qui lui branche ses deux bouts. */
require_once __DIR__ . '/../discovery/Engine.php';

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
    /* Durée au-delà de laquelle une liaison est jugée saine. En deçà, le recul
     * progressif est conservé plutôt que remis à zéro : un broker qui accepte
     * puis expulse ne doit pas nous faire boucler toutes les deux secondes. */
    const CONNECTION_STABLE = 60;

    private $config;
    private $transport;
    private $link;
    private $commands;
    private $pidFile;
    private $router;
    private $discovery;

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

    /* Ceux de la découverte, tenus séparément pour exactement la même raison,
     * et avec une précaution de plus : les deux ensembles se recouvrent
     * volontiers — `shellies/+/online` sert à découvrir un appareil autant
     * qu'à router sa disponibilité. Chacun consulte donc l'autre avant de
     * résilier, sans quoi arrêter la découverte couperait l'arrivée des
     * valeurs, en silence et jusqu'au prochain redémarrage du démon. */
    private $discoverySubs = array();

    /* Compteurs de découverte au dernier résumé, même raison que ci-dessus :
     * le journal dit la période écoulée, pas le total depuis le démarrage. */
    private $lastDiscovery = array('emitted' => 0, 'duplicates' => 0, 'failures' => 0);

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

        /*
         * La découverte, branchée de la même façon et pas davantage : elle
         * réclame des abonnements (la boucle les pose), publie (la boucle
         * écrit sur le transport) et rend des modèles (la boucle les confie à
         * Jeedom). Elle ne voit ni l'un ni l'autre, et c'est ce qui permet de
         * l'éprouver hors ligne sur le code exact qui tourne ici.
         */
        $this->discovery = new MqttbeDiscoveryEngine($this->config);
        $this->discovery->onModel(array($this->link, 'pushDiscovered'));
        $this->discovery->onPublish(array($this, 'discoveryPublish'));
        $this->discovery->onSubscribe(array($this, 'syncDiscoverySubscriptions'));
        /*
         * Le vocabulaire des capacités, chargé AVANT les adapters.
         *
         * Sans lui, MqttbeChannel::validate() saute son contrôle et un canal
         * dont la capacité est mal orthographiée traverse tout le système :
         * côté Jeedom, la fabrique retombe sur `generic.value` et crée une
         * commande texte sans type générique, sans que rien ne désigne l'adapter
         * fautif. C'est précisément la panne que ce contrôle existe pour éviter.
         * Le fichier absent laisse le contrôle inactif, comme avant.
         */
        $vocabulaire = __DIR__ . '/../../../core/config/capabilities.json';
        if (MqttbeChannel::loadCapabilities($vocabulaire)) {
            MqttbeLog::debug('vocabulaire des capacités chargé ('
                           . count(MqttbeChannel::vocabulary()) . ' capacités)');
        } else {
            MqttbeLog::warning('vocabulaire des capacités introuvable ou illisible : '
                             . 'les capacités des adapters ne seront pas contrôlées');
        }

        $this->discovery->loadAdapters(__DIR__ . '/../discovery/adapters');
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
        /* Le battement des adapters : le moteur s'y limite tout seul à une
         * fois par seconde, la boucle n'a pas à tenir ce compte. */
        $this->discovery->tick();
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
        /* Lu AVANT disconnect(), qui remet l'horodatage à zéro. */
        $depuis = $this->transport->connectedSince();
        $this->transport->disconnect();
        if ($this->brokerOk || !$this->brokerAnnounced) {
            $this->brokerOk        = false;
            $this->brokerAnnounced = true;
            $this->link->push(array('cmd' => 'brokerDown', 'message' => $_reason));
        }
        /*
         * Le recul ne repart de zéro que si la liaison a réellement tenu.
         *
         * Le remettre à zéro à chaque perte suppose que la connexion précédente
         * était saine. Or un broker peut accepter puis expulser aussitôt — un
         * autre client portant le même identifiant, une session fantôme, un
         * pare-feu qui coupe l'établi. Dans ce cas le cycle « connecté,
         * déconnecté, deux secondes, on recommence » ne ralentissait jamais :
         * mesuré à douze reconnexions et dix-neuf appels à Jeedom en vingt
         * secondes, soit près de cent mille requêtes par jour, avec le voyant
         * d'état qui clignote et Apache qui rejoue le cœur trois fois par
         * seconde. L'utilisateur ne voyait pas « le broker refuse ma session »,
         * il voyait « le plugin est instable ».
         */
        if ($depuis > 0 && (time() - $depuis) >= self::CONNECTION_STABLE) {
            $this->attempts = 0;
        }
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

        /* La découverte APRÈS le routage, et jamais l'inverse : une valeur
         * attendue par une commande existante ne doit pas attendre qu'un
         * adapter ait fini d'examiner le message. Le moteur rend la main
         * aussitôt quand la découverte est arrêtée ou que le topic ne
         * concerne aucun adapter. */
        $this->discovery->onMessage($_topic, $_payload, $_retained);

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
                    /* Même raison que pour la version de table : un démon
                     * relancé a perdu son ordre `discovery`, et c'est au
                     * battement que Jeedom s'en aperçoit — sans avoir à le
                     * repousser toutes les minutes à tout hasard. */
                    'discovery' => $this->discovery->isEnabled() ? 1 : 0,
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

            case 'discovery':
                return $this->applyDiscovery($_order);

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
        $this->syncSubscriptions($this->routingSubs, $this->router->subscriptions(),
                                 $this->discoverySubs, 'routage');
    }

    /* ---------------------------------------------------------- découverte */

    /*
     * L'ordre `discovery` (CONTRAT-J4 §4). Même forme que pour le routage, et
     * dans le même ordre : le moteur décide d'abord quels adapters travaillent,
     * les abonnements viennent ensuite. S'abonner avant ferait arriver des
     * messages qu'aucun adapter actif ne réclamerait encore, et le premier état
     * retenu — celui que le broker rejoue aussitôt l'abonnement posé, et qui
     * porte justement l'annonce d'un appareil — serait celui qu'on perdrait.
     */
    private function applyDiscovery($_order) {
        $result = $this->discovery->apply($_order);
        if (empty($result['applied'])) {
            return array('state' => 'error', 'result' => $result);
        }
        $this->syncDiscoverySubscriptions();
        return array('state' => 'ok', 'result' => $result);
    }

    /*
     * Publique parce que le moteur l'appelle : un adapter qui s'abonne en
     * cours de route (le topic de réponse d'un appel RPC) n'attend pas le
     * prochain ordre `discovery` pour que l'abonnement soit posé.
     */
    public function syncDiscoverySubscriptions() {
        $this->syncSubscriptions($this->discoverySubs, $this->discovery->subscriptions(),
                                 $this->routingSubs, 'découverte');
    }

    /* La publication demandée par un adapter. Elle passe par la boucle et non
     * par le transport en direct : le moteur n'a pas à savoir qu'il existe un
     * broker, ni à décider ce qu'on fait quand il est absent. */
    public function discoveryPublish($_topic, $_payload, $_qos = 0, $_retain = false) {
        if (!$this->transport->isConnected()) {
            return false;
        }
        return $this->transport->publish($_topic, $_payload, $_qos, $_retain);
    }

    /* ------------------------------------------------------- les deux jeux */

    /*
     * Met un jeu d'abonnements en accord avec ce qu'il devrait être, sans
     * jamais toucher à ceux dont l'AUTRE jeu a besoin.
     *
     * Deux règles, et la seconde est celle qui se paie cher quand on l'oublie :
     *
     * 1. Différence, jamais table rase. Résilier puis reprendre un abonnement
     *    inchangé ferait rejouer par le broker tous les messages retenus de la
     *    branche — pour un parc Shelly, des centaines d'états d'un coup, à
     *    chaque enregistrement d'une commande dans Jeedom.
     *
     * 2. On ne résilie pas ce que l'autre mécanisme tient, et on ne repose pas
     *    ce qu'il a déjà posé. Les deux jeux se recouvrent largement — le même
     *    `shellies/+/online` sert à découvrir et à router — et un UNSUBSCRIBE
     *    est global à la session MQTT : il n'existe pas de « se désabonner
     *    pour la découverte seulement ». Arrêter la découverte couperait donc
     *    l'arrivée des valeurs, sans un mot dans le journal, jusqu'au prochain
     *    redémarrage du démon.
     */
    private function syncSubscriptions(&$_current, $_wanted, $_other, $_quoi) {
        $poses = 0;
        $otes  = 0;
        foreach ($_current as $topic => $qos) {
            if (isset($_wanted[$topic]) || isset($_other[$topic])) {
                continue;
            }
            $this->transport->unsubscribe($topic);
            $otes++;
        }
        foreach ($_wanted as $topic => $qos) {
            if (isset($_current[$topic]) || isset($_other[$topic])) {
                continue;
            }
            $this->transport->subscribe($topic, $qos);
            $poses++;
        }
        $_current = $_wanted;

        if (($poses > 0 || $otes > 0) && MqttbeLog::isDebug()) {
            MqttbeLog::debug('abonnements de ' . $_quoi . ' : ' . $poses . ' posé(s), '
                           . $otes . ' résilié(s), ' . count($_wanted) . ' au total');
        }
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

        /* La découverte a son propre delta : elle peut avoir travaillé pendant
         * que le routage se taisait — c'est même le cas d'une installation
         * neuve, où rien n'est encore routé. */
        $decouverte = $this->discovery->stats();
        $deltaD = array();
        foreach ($this->lastDiscovery as $cle => $valeur) {
            $deltaD[$cle] = $decouverte[$cle] - $valeur;
            $this->lastDiscovery[$cle] = $decouverte[$cle];
        }

        $pending = $this->link->pending();
        if ($delta['received'] === 0 && $delta['excluded'] === 0 && $pending === 0
            && $deltaD['emitted'] === 0 && $deltaD['failures'] === 0) {
            return;
        }

        MqttbeLog::info('activité (5 min) : ' . $delta['received'] . ' message(s) reçus, '
                      . $delta['routed'] . ' valeur(s) routée(s), ' . $delta['ignored'] . ' ignorée(s), '
                      . $delta['noTarget'] . ' sans cible, ' . $delta['excluded'] . ' écarté(s), '
                      . 'file ' . $pending . ', latence médiane '
                      . number_format($stats['median'], 3, ',', ' ') . ' ms, '
                      . 'mémoire ' . round(memory_get_usage(true) / 1048576, 1) . ' Mo');

        if ($this->discovery->isEnabled()) {
            MqttbeLog::info('découverte (5 min) : ' . $deltaD['emitted'] . ' modèle(s) remis à Jeedom, '
                          . $deltaD['duplicates'] . ' inchangé(s), ' . $deltaD['failures'] . ' erreur(s) '
                          . 'd\'adapter — ' . $decouverte['active'] . ' adapter(s) actif(s), '
                          . $decouverte['subscriptions'] . ' abonnement(s), '
                          . $decouverte['memory'] . ' clé(s) en mémoire');
        }
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
