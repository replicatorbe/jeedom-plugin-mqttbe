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

/*
 * Cycle de vie du démon et dialogue avec lui.
 *
 * Deux canaux, volontairement dissymétriques :
 *   - démon vers Jeedom : POST HTTP par lots sur core/php/callback.php. C'est le
 *     démon qui parle, Jeedom n'a rien à interroger ;
 *   - Jeedom vers démon : une socket TCP sur 127.0.0.1, un message JSON par
 *     connexion. Le processus web ne doit jamais rester bloqué sur le démon.
 *
 * L'état du démon vit dans le cache et non dans un fichier : plusieurs processus
 * Apache le lisent en même temps, et le cache est déjà partagé entre eux.
 * Le fichier de PID reste la référence pour savoir si le processus vit vraiment,
 * parce qu'un cache peut mentir après un redémarrage brutal de la machine.
 */
class mqttbeDaemon {

    /* "<pid>:<port>" du démon vivant, "0:0" quand il n'y en a pas. Le port fait
     * partie de l'identité : un démon relancé sur un autre port est un autre
     * démon, et les messages de l'ancien doivent être refusés. */
    const CACHE_UID       = 'mqttbe::daemonUid';
    const CACHE_PORT      = 'mqttbe::daemonPort';
    const CACHE_LAST_RCV  = 'mqttbe::lastRcv';
    const CACHE_LAST_SND  = 'mqttbe::lastSnd';
    const CACHE_BROKER    = 'mqttbe::brokerState';

    /* Au-delà, on considère le démon mort et on le relance. Il envoie un
     * battement toutes les 45 s : 300 s laissent passer plusieurs ratés
     * d'affilée avant de tuer un démon qui fonctionne peut-être très bien. */
    const TIMEOUT_RCV     = 300;
    const DELAY_HEARTBEAT = 45;

    /**
     * URL que le démon appellera pour parler à Jeedom.
     *
     * getNetworkAccess() traite les cas qui ont coûté cher aux autres plugins :
     * port non standard, sous-répertoire d'installation, et conteneur Docker où
     * 127.0.0.1 ne désigne pas Jeedom.
     */
    public static function getCallbackUrl() {
        return network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
             . '/plugins/mqttbe/core/php/callback.php';
    }

    public static function getSocketPort() {
        return (int) config::byKey('daemon::socketport', 'mqttbe', 55062);
    }

    /**
     * Configuration de connexion envoyée au démon.
     *
     * Elle est autosuffisante : le démon ne charge pas core.inc.php et ne peut
     * donc rien relire du coeur. Tout ce dont il a besoin passe par ici.
     */
    public static function getBrokerConfig() {
        return array(
            'host'        => trim(config::byKey('broker::host', 'mqttbe', '')),
            'port'        => (int) config::byKey('broker::port', 'mqttbe', 1883),
            'tls'         => config::byKey('broker::tls', 'mqttbe', 0) == 1,
            'tlsInsecure' => config::byKey('broker::tlsInsecure', 'mqttbe', 0) == 1,
            'caFile'      => trim(config::byKey('broker::caFile', 'mqttbe', '')),
            'username'    => config::byKey('broker::username', 'mqttbe', ''),
            'password'    => config::byKey('broker::password', 'mqttbe', ''),
            'clientId'    => self::getClientId(),
            'keepalive'   => (int) config::byKey('broker::keepalive', 'mqttbe', 60),
            'exclude'     => self::getExcludedTopics(),
            'batchDelay'  => (float) config::byKey('daemon::batchDelay', 'mqttbe', 0.2),
            /*
             * Le démon ne charge pas le coeur et ne connaît donc pas le fuseau
             * de Jeedom ; PHP en ligne de commande date volontiers en UTC. Sans
             * ce réglage, les deux journaux sont décalés de plusieurs heures et
             * plus personne ne peut rapprocher un incident de sa cause.
             */
            'timezone'    => date_default_timezone_get(),
        );
    }

    /**
     * Identifiant client MQTT, engendré une fois puis mémorisé.
     *
     * Deux clients qui se présentent au broker avec le même identifiant se
     * déconnectent mutuellement en boucle : il doit être propre à cette
     * installation, et surtout ne pas changer à chaque démarrage, sinon une
     * session persistante ne sert à rien.
     */
    public static function getClientId() {
        $clientId = trim(config::byKey('broker::clientId', 'mqttbe', ''));
        if ($clientId === '') {
            $clientId = 'jeedom-mqttbe-' . substr(md5(uniqid('', true)), 0, 8);
            config::save('broker::clientId', $clientId, 'mqttbe');
        }
        return $clientId;
    }

    /**
     * Topics que le démon ne doit jamais remonter.
     *
     * 'jeedom/#' est le topic racine de MQTT Manager, qui y publie l'état de
     * Jeedom : sans cette exclusion, la découverte réimporterait Jeedom dans
     * Jeedom. '$SYS/#' est le bavardage interne du broker.
     */
    public static function getExcludedTopics() {
        $raw = config::byKey('topics::exclude', 'mqttbe', "jeedom/#\n\$SYS/#");
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && substr($line, 0, 1) !== '#') {
                $out[] = $line;
            }
        }
        return $out;
    }

    public static function state() {
        try {
            return cache::byKey(self::CACHE_UID)->getValue('0:0') !== '0:0';
        } catch (Throwable $e) {
            /* Clé de cache absente : le démon n'a jamais annoncé sa présence. */
            return false;
        }
    }

    public static function brokerState() {
        try {
            return cache::byKey(self::CACHE_BROKER)->getValue('nok');
        } catch (Throwable $e) {
            return 'nok';
        }
    }

    /**
     * Le message reçu vient-il du démon en cours ?
     *
     * Rend null tant qu'aucun démon ne s'est annoncé : l'appelant distingue
     * ainsi « pas encore prêt » de « imposteur ».
     */
    public static function validUid($_uid) {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        if ($cuid === '0:0') {
            return null;
        }
        return $cuid === $_uid;
    }

    private static function cachedValue($_key, $_default) {
        try {
            return cache::byKey($_key)->getValue($_default);
        } catch (Throwable $e) {
            return $_default;
        }
    }

    /**
     * Contrôle complet : le démon vit-il, et parle-t-il encore ?
     *
     * Appelé par le cron toutes les minutes. Un démon dont le processus existe
     * mais qui ne dit plus rien est pire qu'un démon arrêté : l'interface le
     * montre vert alors que plus rien ne remonte. On le tue pour que le coeur
     * le relance.
     */
    public static function check() {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        if ($cuid === '0:0') {
            return false;
        }
        list($cpid, $cport) = array_map('intval', explode(':', $cuid));
        if ($cpid <= 0 || !@posix_getsid($cpid)) {
            mqttbe::logger('debug', __('Démon déclaré vivant mais son processus a disparu', __FILE__));
            self::stop();
            return false;
        }
        if ((int) self::cachedValue(self::CACHE_PORT, 0) !== $cport) {
            mqttbe::logger('debug', __('Démon annoncé sur un autre port que celui attendu', __FILE__));
            self::stop();
            return false;
        }

        /* Valeur par défaut = maintenant : une clé de cache absente ne doit
         * jamais faire tuer un démon qui vient de démarrer. */
        $deltaRx = time() - (int) self::cachedValue(self::CACHE_LAST_RCV, time());
        if ($deltaRx > self::TIMEOUT_RCV) {
            mqttbe::logger('warning', sprintf(
                __('Aucune nouvelle du démon depuis %ss : il est relancé', __FILE__),
                $deltaRx
            ));
            self::stop();
            return false;
        }

        /* Valeur par défaut = 0 : sur cache vide on envoie un battement, ce qui
         * est sans risque et remet le compteur d'aplomb. */
        $deltaTx = time() - (int) self::cachedValue(self::CACHE_LAST_SND, 0);
        if ($deltaTx > self::DELAY_HEARTBEAT) {
            self::send(array('cmd' => 'hb'), false);
        }
        return true;
    }

    /** Rappel du coeur : état affiché sur la page de configuration du plugin. */
    public static function info() {
        $return = array(
            'log'        => 'mqttbed',
            'state'      => self::check() ? 'ok' : 'nok',
            'launchable' => 'ok',
        );
        if (trim(config::byKey('broker::host', 'mqttbe', '')) === '') {
            $return['launchable'] = 'nok';
            $return['launchable_message'] = __("Renseignez l'adresse du broker MQTT", __FILE__);
        }
        return $return;
    }

    /** Rappel du coeur : démarrage. */
    public static function start() {
        self::stop();

        $info = self::info();
        if ($info['launchable'] != 'ok') {
            throw new Exception(__('Le démon ne peut pas être lancé :', __FILE__) . ' '
                              . (isset($info['launchable_message']) ? $info['launchable_message'] : ''));
        }

        /* On repart d'horodatages frais : sans cela, le premier passage du cron
         * pourrait tuer un démon qui n'a pas encore eu le temps de parler. */
        cache::set(self::CACHE_LAST_RCV, time());
        cache::set(self::CACHE_LAST_SND, time());

        $daemon = realpath(__DIR__ . '/../../resources/mqttbed/mqttbed.php');
        if ($daemon === false) {
            throw new Exception(__('Le démon est introuvable dans resources/mqttbed/', __FILE__));
        }

        $cmd  = 'php ' . escapeshellarg($daemon);
        $cmd .= ' --callback '   . escapeshellarg(self::getCallbackUrl());
        $cmd .= ' --pid '        . escapeshellarg(jeedom::getTmpFolder('mqttbe') . '/mqttbed.pid');
        $cmd .= ' --socketport ' . escapeshellarg(self::getSocketPort());
        $cmd .= ' --loglevel '   . escapeshellarg(log::convertLogLevel(log::getLogLevel('mqttbe')));

        /* La clé d'API passe par l'entrée standard et jamais par la ligne de
         * commande : ps est lisible par n'importe quel utilisateur local. */
        $full = 'echo ' . escapeshellarg(jeedom::getApiKey('mqttbe')) . ' | ' . $cmd
              . ' >> ' . log::getPathToLog('mqttbed') . ' 2>&1 &';

        mqttbe::logger('info', __('Lancement du démon', __FILE__));
        exec($full);

        /* Le démon annonce lui-même sa présence par le callback : on attend ce
         * signal plutôt que de supposer que tout s'est bien passé. */
        for ($i = 1; $i <= 30; $i++) {
            if (self::state()) {
                mqttbe::logger('info', __('Démon démarré', __FILE__));
                message::removeAll('mqttbe', 'unableStartDeamon');
                self::sendBrokerConfig();
                return true;
            }
            usleep(500000);
        }

        mqttbe::logger('error', __("Le démon n'a pas démarré. Consultez le journal mqttbed.", __FILE__));
        message::add('mqttbe', __("Le démon n'a pas démarré. Consultez le journal mqttbed.", __FILE__),
                     null, 'unableStartDeamon');
        return false;
    }

    /** Rappel du coeur : arrêt. Doit rester sans effet si rien ne tourne. */
    public static function stop() {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        list($cpid, ) = array_map('intval', explode(':', $cuid));

        if ($cpid <= 0) {
            /* Le cache peut avoir été vidé alors que le processus vit encore :
             * le fichier de PID est la seule trace qui survit à cela. */
            $pidFile = jeedom::getTmpFolder('mqttbe') . '/mqttbed.pid';
            if (file_exists($pidFile)) {
                $cpid = (int) trim(file_get_contents($pidFile));
            }
        }

        if ($cpid > 0 && @posix_getsid($cpid)) {
            mqttbe::logger('info', __('Arrêt du démon', __FILE__));

            /*
             * On demande d'abord poliment. Le démon ferme alors sa session MQTT
             * par un DISCONNECT en règle : le broker ne publie pas le message de
             * dernière volonté, et aucune session à moitié ouverte ne traîne
             * derrière nous. Un signal, lui, coupe la ligne sans prévenir.
             */
            if (self::send(array('cmd' => 'stop'), false)) {
                for ($i = 1; $i <= 12; $i++) {
                    if (!@posix_getsid($cpid)) {
                        break;
                    }
                    usleep(250000);
                }
            }
        }

        /* Le signal reste le recours : un démon bloqué n'a pas lu notre ordre. */
        if ($cpid > 0 && @posix_getsid($cpid)) {
            posix_kill($cpid, 15);
            for ($i = 1; $i <= 20; $i++) {
                if (!@posix_getsid($cpid)) {
                    break;
                }
                usleep(250000);
            }
            if (@posix_getsid($cpid)) {
                mqttbe::logger('debug', __('Le démon ignore SIGTERM : envoi de SIGKILL', __FILE__));
                posix_kill($cpid, 9);
            }
        }
        self::cleanup();
    }

    /** Remise à zéro de l'état, quelle que soit la façon dont le démon a fini. */
    public static function cleanup() {
        cache::set(self::CACHE_UID, '0:0');
        cache::set(self::CACHE_PORT, 0);
        cache::set(self::CACHE_BROKER, 'nok');
        $pidFile = jeedom::getTmpFolder('mqttbe') . '/mqttbed.pid';
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
        self::sendDaemonStateEvent(false);
        self::sendBrokerStateEvent('nok', '');
    }

    /* ------------------------------------------------------- Jeedom -> démon */

    /**
     * Envoi d'un ordre au démon.
     *
     * Une connexion par message, puis fermeture : le processus web ne garde
     * aucune ressource ouverte, et un démon mort ne peut pas le bloquer. Le
     * délai est volontairement court — une page Jeedom ne doit jamais attendre.
     */
    public static function send($_params, $_throw = true) {
        if (!self::state()) {
            if ($_throw) {
                throw new Exception(__("Le démon n'est pas démarré", __FILE__));
            }
            return false;
        }
        $_params['apikey'] = jeedom::getApiKey('mqttbe');
        $payload = json_encode($_params);
        $port    = (int) self::cachedValue(self::CACHE_PORT, self::getSocketPort());

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 2);
        if ($socket === false) {
            mqttbe::logger('debug', sprintf(
                __('Démon injoignable sur le port %1$s : %2$s', __FILE__), $port, $error
            ));
            return false;
        }
        stream_set_timeout($socket, 2);
        $written = @fwrite($socket, $payload);
        fclose($socket);

        if ($written === false) {
            mqttbe::logger('debug', __("Échec de l'envoi d'un ordre au démon", __FILE__));
            return false;
        }
        cache::set(self::CACHE_LAST_SND, time());
        return true;
    }

    public static function sendBrokerConfig() {
        return self::send(array('cmd' => 'setBroker', 'config' => self::getBrokerConfig()), false);
    }

    public static function setLogLevel() {
        return self::send(array(
            'cmd'   => 'loglevel',
            'level' => log::convertLogLevel(log::getLogLevel('mqttbe')),
        ), false);
    }

    /**
     * Fait suivre au démon un changement de niveau de journalisation.
     *
     * Le niveau est un réglage du coeur, pas du plugin : aucun rappel
     * postConfig ne nous prévient quand l'utilisateur le modifie. Sans cette
     * comparaison au fil du cron, il faudrait redémarrer le démon pour obtenir
     * les traces qu'on vient justement de demander pour comprendre une panne.
     */
    public static function syncLogLevel() {
        $niveau = log::convertLogLevel(log::getLogLevel('mqttbe'));
        if (self::cachedValue('mqttbe::logLevel', '') === $niveau) {
            return;
        }
        if (self::setLogLevel()) {
            cache::set('mqttbe::logLevel', $niveau);
            mqttbe::logger('info', sprintf(__('Niveau de journal du démon porté à %s', __FILE__), $niveau));
        }
    }

    public static function subscribe($_topic, $_qos = 0) {
        return self::send(array('cmd' => 'subscribe', 'topic' => $_topic, 'qos' => (int) $_qos));
    }

    public static function unsubscribe($_topic) {
        return self::send(array('cmd' => 'unsubscribe', 'topic' => $_topic));
    }

    public static function publish($_topic, $_payload, $_qos = 0, $_retain = false) {
        return self::send(array(
            'cmd'     => 'publish',
            'topic'   => $_topic,
            'payload' => (string) $_payload,
            'qos'     => (int) $_qos,
            'retain'  => (bool) $_retain,
        ));
    }

    /* ------------------------------------------------------- démon -> Jeedom */

    public static function onDaemonUp($_uid) {
        list($pid, $port) = array_map('intval', explode(':', $_uid));
        cache::set(self::CACHE_UID, $_uid);
        cache::set(self::CACHE_PORT, $port);
        cache::set(self::CACHE_LAST_RCV, time());
        config::save('lastDeamonLaunchTime', date('Y-m-d H:i:s'), 'mqttbe');
        mqttbe::logger('info', sprintf(__('Démon connecté [pid %1$s, port %2$s]', __FILE__), $pid, $port));
        self::sendDaemonStateEvent(true);
    }

    public static function onHeartbeat() {
        cache::set(self::CACHE_LAST_RCV, time());
    }

    public static function onDaemonDown() {
        mqttbe::logger('info', __('Le démon signale son arrêt', __FILE__));
        self::cleanup();
    }

    public static function onBrokerUp() {
        cache::set(self::CACHE_LAST_RCV, time());
        cache::set(self::CACHE_BROKER, 'ok');
        mqttbe::logger('info', __('Connecté au broker MQTT', __FILE__));
        self::sendBrokerStateEvent('ok', '');
    }

    public static function onBrokerDown($_message = '') {
        cache::set(self::CACHE_LAST_RCV, time());
        cache::set(self::CACHE_BROKER, 'nok');
        mqttbe::logger('warning', __('Déconnecté du broker MQTT', __FILE__)
                     . ($_message !== '' ? ' : ' . $_message : ''));
        self::sendBrokerStateEvent('nok', $_message);
    }

    /**
     * Valeurs résolues par le démon.
     *
     * Le démon a déjà fait la correspondance topic vers commande : Jeedom ne
     * reçoit que des couples identifiant/valeur et n'a plus qu'à les poser.
     * C'est ce qui évite la boucle sur tous les équipements à chaque message.
     */
    public static function onValues($_items) {
        cache::set(self::CACHE_LAST_RCV, time());
        foreach ($_items as $item) {
            if (!isset($item['cmdId']) || !array_key_exists('value', $item)) {
                continue;
            }
            $cmd = cmd::byId((int) $item['cmdId']);
            if (!is_object($cmd) || $cmd->getEqType_name() != 'mqttbe') {
                continue;
            }
            $date = isset($item['ts']) ? date('Y-m-d H:i:s', (int) $item['ts']) : null;
            $cmd->event($item['value'], $date);
        }
    }

    public static function sendDaemonStateEvent($_state) {
        event::add('mqttbe::daemonState', array('state' => (bool) $_state));
    }

    public static function sendBrokerStateEvent($_state, $_message = '') {
        event::add('mqttbe::brokerState', array('state' => $_state, 'message' => $_message));
    }
}
