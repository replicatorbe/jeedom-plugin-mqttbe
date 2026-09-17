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

    /* La file d'adoption : ce que la découverte a vu sans le créer. */
    const PENDING_KEY = 'mqttbe::pending';

    /* Au-delà, la file cesse d'être une liste qu'on regarde et devient un
     * fouillis. Elle est coupée au plus intéressant, jamais au plus récent. */
    const PENDING_MAX = 50;

    /* Un candidat qu'on n'a pas revu depuis une semaine n'est plus un candidat,
     * c'est un souvenir. Le garder, c'est le laisser prendre la place d'un
     * appareil bien présent le jour où la file déborde. */
    const PENDING_TTL = 604800;

    /* Ce que vaut une adresse stable, dans la seule unité que la file
     * connaisse : des secondes de présence. Une adresse publique est gravée
     * dans le matériel et désignera le même appareil dans six mois ; une
     * adresse aléatoire aura changé dans le quart d'heure. */
    const PENDING_STABLE_BONUS = 3600;

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

    /**
     * Le démon vit-il, sans rien décider ?
     *
     * Plus sûr que state(), qui ne consulte que le cache : on vérifie aussi que
     * le processus existe. Mais on ne nettoie rien et on n'attend rien.
     */
    public static function alive() {
        $cuid = self::cachedValue(self::CACHE_UID, '0:0');
        if ($cuid === '0:0') {
            return false;
        }
        list($cpid, ) = array_map('intval', explode(':', $cuid));
        return $cpid > 0 && @posix_getsid($cpid) !== false;
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
        /*
         * Lecture seule, volontairement : check() peut décider d'arrêter le
         * démon, et stop() attend jusqu'à huit secondes. Or le cœur appelle
         * deamon_info() à chaque affichage de la page de configuration : la
         * page se figeait. La remise en ordre appartient au cron, qui est là
         * pour cela.
         */
        $return = array(
            'log'        => 'mqttbed',
            'state'      => self::alive() ? 'ok' : 'nok',
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
                /* Le démon vient de naître : il ne sait rien des équipements.
                 * L'envoi est forcé, sinon la comparaison d'empreinte conclurait
                 * à tort que la table est déjà en place. */
                mqttbeRouting::push(true);
                self::sendDiscoveryConfig();
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

        /*
         * Un fichier de PID survit à une coupure de courant : après un
         * redémarrage, le numéro qu'il contient a de bonnes chances d'appartenir
         * à un tout autre programme, et nous étions sur le point de lui envoyer
         * SIGKILL. On ne signale que ce qu'on reconnaît.
         */
        if ($cpid > 0 && !self::isOurDaemon($cpid)) {
            mqttbe::logger('debug', sprintf(
                __('Le processus %s n\'est pas le démon mqttbe : aucun signal ne lui est envoyé', __FILE__),
                $cpid
            ));
            $pidFile = jeedom::getTmpFolder('mqttbe') . '/mqttbed.pid';
            if (file_exists($pidFile)) {
                @unlink($pidFile);
            }
            $cpid = 0;
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

    /**
     * Ce processus est-il bien notre démon ?
     *
     * /proc est la seule source qui ne mente pas. Son absence (conteneur
     * restreint, système exotique) laisse le bénéfice du doute : on préfère
     * arrêter un démon qu'on croit être le nôtre plutôt que d'en laisser un
     * tourner sans contrôle.
     */
    private static function isOurDaemon($_pid) {
        $cmdline = '/proc/' . ((int) $_pid) . '/cmdline';
        if (!@is_readable($cmdline)) {
            return true;
        }
        return strpos((string) @file_get_contents($cmdline), 'mqttbed.php') !== false;
    }

    /** Remise à zéro de l'état, quelle que soit la façon dont le démon a fini. */
    public static function cleanup() {
        /* La table de routage vit dans la mémoire du démon : elle disparaît avec
         * lui. Oublier son empreinte garantit que le prochain démon la recevra,
         * au lieu d'un cache qui prétend qu'elle est déjà en place. */
        mqttbeRouting::forget();
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

        /*
         * On lit la réponse, au lieu de fermer aussitôt.
         *
         * Le démon répond déjà `{"state":"ok"}` ou `{"state":"error","result":…}`
         * à chaque ordre, et personne ne l'écoutait : une publication refusée —
         * broker tombé, topic invalide — passait pour une réussite. L'utilisateur
         * appuyait sur « Allumer », rien ne se produisait, et aucun message ne le
         * lui disait alors que le démon en connaissait la raison.
         */
        $reponse = null;
        if ($written !== false) {
            $brut = @stream_get_contents($socket);
            if (is_string($brut) && $brut !== '') {
                $decode = json_decode($brut, true);
                if (is_array($decode)) {
                    $reponse = $decode;
                }
            }
        }
        fclose($socket);

        if ($written === false) {
            mqttbe::logger('debug', __("Échec de l'envoi d'un ordre au démon", __FILE__));
            return false;
        }
        cache::set(self::CACHE_LAST_SND, time());

        if (is_array($reponse) && isset($reponse['state']) && $reponse['state'] !== 'ok') {
            $motif = isset($reponse['result']) && is_string($reponse['result'])
                   ? $reponse['result'] : __('motif inconnu', __FILE__);
            if ($_throw) {
                throw new Exception(sprintf(
                    __('Le démon a refusé la commande « %1$s » : %2$s', __FILE__),
                    isset($_params['cmd']) ? $_params['cmd'] : '?', $motif
                ));
            }
            mqttbe::logger('warning', sprintf(
                __('Le démon a refusé la commande « %1$s » : %2$s', __FILE__),
                isset($_params['cmd']) ? $_params['cmd'] : '?', $motif
            ));
            return false;
        }

        /*
         * La réponse au battement porte la version de table que le démon
         * applique réellement, et l'état de sa découverte — sous `result`, comme
         * toute réponse du démon. Un démon relancé qui n'a ni notre table ni nos
         * réglages de découverte est ainsi rattrapé au tour de cron suivant,
         * sans attendre qu'un équipement soit modifié.
         */
        if (isset($_params['cmd']) && $_params['cmd'] === 'hb'
         && isset($reponse['result']) && is_array($reponse['result'])) {
            $etat = $reponse['result'];
            if (isset($etat['routing'])) {
                cache::set('mqttbe::daemonRouting', (int) $etat['routing']);
            }
            /* Le démon a-t-il gardé nos réglages de découverte ? */
            if (isset($etat['discovery'])
             && ((int) $etat['discovery'] === 1) !== (config::byKey('discovery::enabled', 'mqttbe', 1) == 1)) {
                mqttbe::logger('info', __('La découverte du démon ne correspond plus aux réglages : renvoi.', __FILE__));
                self::sendDiscoveryConfig();
            }
        }
        return true;
    }

    /**
     * Réglages de découverte envoyés au démon.
     *
     * `rescan` redemande à tout le parc de se présenter. C'est indispensable
     * pour un appareil connecté depuis des semaines : il ne s'annonce plus de
     * lui-même, et sans cette relance il resterait invisible alors qu'il parle.
     */
    /**
     * Le démon applique-t-il bien la table que Jeedom croit avoir envoyée ?
     *
     * La version appliquée revient dans la réponse au battement. Si elle diffère,
     * la table est renvoyée de force : c'est le seul rattrapage qui couvre les
     * cas où l'ordre s'est perdu en route sans que Jeedom l'apprenne.
     */
    public static function checkRoutingVersion() {
        $appliquee = (int) self::cachedValue('mqttbe::daemonRouting', -1);
        if ($appliquee < 0) {
            return;   /* Aucun battement n'a encore répondu : rien à comparer. */
        }
        $attendue = (int) mqttbeRouting::version();
        if ($appliquee === $attendue) {
            return;
        }
        mqttbe::logger('info', sprintf(
            __('Le démon applique la table %1$s alors que Jeedom en est à la %2$s : renvoi.', __FILE__),
            $appliquee, $attendue
        ));
        mqttbeRouting::push(true);
    }

    public static function sendDiscoveryConfig($_rescan = false) {
        $ordre = array(
            'cmd'      => 'discovery',
            'enabled'  => config::byKey('discovery::enabled', 'mqttbe', 1) == 1,
            'rescan'   => (bool) $_rescan,
            /* Aller lire le nom sur l'appareil suppose une requête vers lui :
             * certains ne veulent pas que Jeedom frappe aux portes de leur
             * réseau, et c'est leur droit. */
            'probeNames' => config::byKey('discovery::probeNames', 'mqttbe', 1) == 1,
            /* Réglages des balises Bluetooth. Le démon ne charge pas le cœur :
             * tout ce qu'il doit savoir passe par cet ordre. */
            'bleAwayDelay' => (int) config::byKey('discovery::bleAwayDelay', 'mqttbe', 300),
            'bleAdoptAll'  => config::byKey('discovery::bleAdoptAll', 'mqttbe', 0) == 1,
        );

        /*
         * Une liste d'adapters vide ne veut pas dire « aucun » mais « tous ceux
         * que le démon connaît » — c'est ce que promet le fichier de
         * configuration. Le moteur distingue la clé absente (tous) de la liste
         * vide (aucun) : on ne l'envoie donc pas plutôt que de l'envoyer vide,
         * sinon effacer le champ éteindrait toute la découverte sans un mot.
         */
        $adapters = trim(config::byKey('discovery::adapters', 'mqttbe', ''));
        if ($adapters !== '') {
            $ordre['adapters'] = array_values(array_filter(array_map('trim', explode(',', $adapters))));
        }
        return self::send($ordre, false);
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

        /*
         * Mesure de la latence de bout en bout : horodatage posé par le démon à
         * la réception du message, comparé à maintenant.
         *
         * Un échantillon toutes les deux secondes au plus, et non un tirage au
         * sort : sur une installation calme, le hasard ne produisait presque
         * jamais assez de mesures pour une médiane, et sur une installation
         * bavarde il en produisait trop. Le rythme est ici le même dans les deux
         * cas, et les écritures de cache restent sous une toutes les deux
         * secondes. Une lecture de cache coûte 0,015 ms : la mesurer pour chaque
         * lot était une prudence mal placée.
         */
        if (isset($_items[0]['ts'])) {
            self::sampleLatency(microtime(true) - (float) $_items[0]['ts']);
        }

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

    const LATENCY_INTERVAL = 2;

    /** Ajoute une mesure au réservoir, au plus une toutes les deux secondes. */
    private static function sampleLatency($_seconds) {
        try {
            $reserve = cache::byKey('mqttbe::latency')->getValue(array());
            if (!is_array($reserve) || !isset($reserve['v']) || !is_array($reserve['v'])) {
                $reserve = array('t' => 0, 'v' => array());
            }
            $maintenant = microtime(true);
            if ($maintenant - $reserve['t'] < self::LATENCY_INTERVAL) {
                return;
            }
            $reserve['t'] = $maintenant;
            $reserve['v'][] = round($_seconds * 1000, 1);
            if (count($reserve['v']) > 200) {
                $reserve['v'] = array_slice($reserve['v'], -200);
            }
            cache::set('mqttbe::latency', $reserve);
        } catch (Throwable $e) {
            /* Une mesure perdue n'est pas un incident : on ne casse jamais le
             * chemin chaud pour un chiffre de confort. */
        }
    }

    /**
     * Inscrit la latence médiane au journal, puis vide le réservoir.
     *
     * La médiane et non la moyenne : une seule pointe à deux secondes, due à
     * une sauvegarde de Jeedom, rendrait une moyenne ininterprétable alors que
     * la question posée est « est-ce fluide d'ordinaire ? ».
     */
    public static function logLatency() {
        try {
            $reserve = cache::byKey('mqttbe::latency')->getValue(array());
        } catch (Throwable $e) {
            return;
        }
        $mesures = (is_array($reserve) && isset($reserve['v']) && is_array($reserve['v']))
                 ? $reserve['v'] : array();
        if (count($mesures) < 5) {
            return;
        }
        sort($mesures);
        $n = count($mesures);
        $mediane = ($n % 2) ? $mesures[intdiv($n, 2)]
                            : ($mesures[$n / 2 - 1] + $mesures[$n / 2]) / 2;
        mqttbe::logger('info', sprintf(
            __('Latence de bout en bout : médiane %1$s ms, maximum %2$s ms sur %3$s mesures', __FILE__),
            round($mediane, 1), end($mesures), $n
        ));
        cache::set('mqttbe::latency', array('t' => 0, 'v' => array()));
    }

    /**
     * Modèles de périphériques remontés par les adapters du démon.
     *
     * Le démon a déjà écarté les modèles inchangés : ce qui arrive ici mérite
     * au moins d'être examiné. La fabrique, elle, décidera si quelque chose doit
     * réellement être écrit en base.
     */
    public static function onDiscovered($_models) {
        cache::set(self::CACHE_LAST_RCV, time());
        if (config::byKey('discovery::enabled', 'mqttbe', 1) != 1) {
            return;
        }
        mqttbeFactory::loadDiscovery();
        $auto = config::byKey('discovery::autoCreate', 'mqttbe', 1) == 1;

        /*
         * Plafond de création.
         *
         * La découverte crée à partir de ce qui passe sur le broker, et rien
         * n'oblige ce qui passe à être honnête : un appareil compromis, ou
         * n'importe qui pouvant publier sur le topic d'annonce, engendre autant
         * d'équipements qu'il invente d'identifiants. Au-delà du plafond, on
         * n'écrit plus rien et on met en attente : l'utilisateur garde la main.
         */
        $plafond = (int) config::byKey('discovery::maxDevices', 'mqttbe', 250);
        if ($auto && $plafond > 0 && self::countDiscovered() >= $plafond) {
            $auto = false;
            if (message::byPluginLogicalId('mqttbe', 'discoveryLimit') === false
             || count(message::byPluginLogicalId('mqttbe', 'discoveryLimit')) === 0) {
                message::add('mqttbe', sprintf(
                    __("La découverte a atteint le plafond de %s équipements : les suivants attendent votre adoption au lieu d'être créés.", __FILE__),
                    $plafond
                ), null, 'discoveryLimit');
            }
        }

        $avant = self::countDiscovered();

        /* Lus une fois pour tout le lot : la liste des refus ne change pas
         * pendant qu'on traite vingt-cinq modèles. */
        $ignores = self::ignoredUids();

        foreach ($_models as $donnees) {
            try {
                $modele = MqttbeDeviceModel::fromArray($donnees);
                $motifs = $modele->validate();
                if (!empty($motifs)) {
                    mqttbe::logger('warning', sprintf(
                        __('Découverte : modèle refusé (%1$s) — %2$s', __FILE__),
                        isset($donnees['identity']['uid']) ? $donnees['identity']['uid'] : '?',
                        implode(' ; ', $motifs)
                    ));
                    continue;
                }
                /*
                 * L'équipement existe-t-il déjà ? La question passe avant
                 * toutes les autres.
                 *
                 * Un candidat adopté repasse ici à chaque redémarrage du démon,
                 * à chaque « relancer la découverte », dès qu'une passerelle de
                 * plus voit la balise — et le démon continue de l'annoncer
                 * « guess » : l'adoption n'a forcé la confiance que du côté de
                 * Jeedom. Sans cette question, il repartait indéfiniment dans
                 * la file, et surtout son équipement n'était PLUS JAMAIS mis à
                 * jour : une mesure nouvellement décodée ou une passerelle
                 * supplémentaire ne devenait jamais une commande, puisque plus
                 * aucun modèle ne passait par la fabrique.
                 *
                 * findByIdentity() reconnaît aussi les alias : un appareil qui
                 * s'annonce par un autre chemin reste le même équipement.
                 */
                $existe = mqttbeFactory::findByIdentity($modele) !== null;

                if (!$existe) {
                    /*
                     * Un refus vaut aussi contre la création, et pas seulement
                     * contre la mise en file. Sinon l'appareil écarté était
                     * créé d'office dès que sa confiance montait — la
                     * passerelle s'est mise à le décoder — ou dès que
                     * l'utilisateur cochait « adopter toutes les balises » :
                     * tous les refus passés étaient balayés d'un coup.
                     */
                    if (isset($ignores[$modele->uid()])) {
                        continue;
                    }
                    /*
                     * Deux raisons de mettre en attente plutôt que de créer.
                     *
                     * La première est un choix global : la création automatique
                     * est décochée, l'utilisateur veut regarder avant.
                     *
                     * La seconde tient au modèle lui-même. Une passerelle
                     * Bluetooth voit tout ce qui passe, y compris le téléphone
                     * d'un visiteur dont l'adresse change toutes les quinze
                     * minutes. Quand l'adapter dit « guess » — je vois quelque
                     * chose, je ne sais pas ce que c'est — créer un équipement
                     * serait présumer à la place de l'utilisateur. « probable »
                     * reste créé : c'est « je sais ce que c'est, je ne le
                     * connais pas encore tout à fait », le cas d'un Shelly
                     * annoncé dont l'état complet n'est pas encore arrivé.
                     */
                    if (!$auto || $modele->confidence() === 'guess') {
                        self::rememberPending($modele);
                        continue;
                    }
                }
                $compte = mqttbeFactory::apply($modele);
                if ($compte['status'] !== 'unchanged') {
                    mqttbe::logger('info', sprintf(
                        __('Découverte : %1$s — %2$s (%3$s commande(s))', __FILE__),
                        $compte['name'], $compte['status'], $compte['touched']
                    ));
                    event::add('mqttbe::discovered', array(
                        'uid'    => $modele->uid(),
                        'name'   => $compte['name'],
                        'status' => $compte['status'],
                    ));
                }
            } catch (Throwable $e) {
                /* Un modèle fautif ne doit jamais emporter les autres : sur un
                 * parc de vingt appareils, un seul mal formé rendrait la
                 * découverte inutilisable. */
                mqttbe::logger('error', sprintf(
                    __('Découverte : %s', __FILE__), $e->getMessage()
                ));
            }
        }

        self::announceCreations($avant);
    }

    /** Nombre d'équipements issus de la découverte. */
    private static function countDiscovered() {
        $n = 0;
        foreach (eqLogic::byType('mqttbe') as $eqLogic) {
            if ($eqLogic->getConfiguration('mqttbe::adapter', '') !== '') {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Prévient l'utilisateur que des équipements sont apparus — et surtout
     * qu'ils ne sont rangés nulle part.
     *
     * Un équipement sans objet parent n'apparaît pas sur le Dashboard, qui est
     * le seul écran que la plupart des gens regardent. Sans ce message, la
     * découverte réussit et l'utilisateur conclut que le plugin n'a rien fait.
     * Le rangement dans une pièce reste son choix : on ne le devine pas à sa
     * place, on le lui dit.
     */
    private static function announceCreations($_avant) {
        $apres = self::countDiscovered();
        if ($apres <= $_avant) {
            return;
        }
        $orphelins = 0;
        foreach (eqLogic::byType('mqttbe') as $eqLogic) {
            if ($eqLogic->getConfiguration('mqttbe::adapter', '') !== ''
             && (int) $eqLogic->getObject_id() === 0) {
                $orphelins++;
            }
        }
        $texte = sprintf(
            __('%1$s équipement(s) découvert(s) sur le broker, %2$s au total.', __FILE__),
            $apres - $_avant, $apres
        );
        if ($orphelins > 0) {
            $texte .= ' ' . sprintf(
                __("%s ne sont rangés dans aucun objet : ils n'apparaîtront pas sur le Dashboard tant que vous ne leur aurez pas donné une pièce.", __FILE__),
                $orphelins
            );
        }
        message::add('mqttbe', $texte, null, 'discovery');
    }

    /* ------------------------------------------------------ file d'adoption */

    /**
     * Identifiants que l'utilisateur a explicitement écartés.
     *
     * En configuration et non en cache : un refus est une décision, elle doit
     * survivre à un vidage de cache et partir dans les sauvegardes. Sans cette
     * liste, une balise écartée reviendrait dans la file à la trame suivante,
     * c'est-à-dire quelques secondes plus tard, indéfiniment.
     */
    public static function ignoredUids() {
        $brut = config::byKey('discovery::ignored', 'mqttbe', '');
        /*
         * config::byKey() décode le JSON lui-même (core/class/config.class.php,
         * is_json) : ce qui arrive ici est DÉJÀ un tableau. Le redécoder
         * revenait à faire json_decode('Array') — donc null, donc une liste de
         * refus éternellement vide : aucun refus ne tenait, chaque nouveau
         * refus effaçait les précédents, le plafond ne servait à rien, le
         * bandeau « n appareil(s) écarté(s) » ne s'affichait jamais, et la
         * conversion du tableau en chaîne laissait une alerte PHP 8 dans
         * /var/www/html/log/http.error. La chaîne reste acceptée : c'est ce que
         * rendent un cœur qui ne décode pas et une valeur écrite à la main.
         */
        $liste = is_array($brut) ? $brut : json_decode((string) $brut, true);
        if (!is_array($liste)) {
            return array();
        }
        /*
         * Forme rendue : uid => array('at' => horodatage, 'name' => nom vu).
         *
         * Le nom est gardé avec le refus parce que c'est la seule chose qui
         * rende la liste des écartés lisible. Un identifiant de balise —
         * « omg:ble:d4a3f2118c07 » — ne dit rien de ce qu'on a refusé, et
         * revenir sur un refus demande de reconnaître ce sur quoi on revient :
         * la file, elle, n'a plus l'entrée, elle a été retirée au moment du
         * refus.
         *
         * Deux formes antérieures sont relues plutôt que jetées, parce qu'un
         * refus est une décision et qu'une décision ne se perd pas sur un
         * détail de forme : la liste plate ["uid", ...], et uid => horodatage.
         * Ni l'une ni l'autre ne porte de nom ; l'entrée reste valide, elle
         * s'affichera par son identifiant, comme avant.
         */
        $propre = array();
        foreach ($liste as $cle => $valeur) {
            if (is_int($cle) && is_string($valeur)) {
                if ($valeur !== '') {
                    $propre[$valeur] = array('at' => 0, 'name' => '');
                }
                continue;
            }
            $uid = (string) $cle;
            if ($uid === '') {
                continue;
            }
            if (is_array($valeur)) {
                $propre[$uid] = array(
                    'at'   => isset($valeur['at']) && is_numeric($valeur['at']) ? (int) $valeur['at'] : 0,
                    'name' => isset($valeur['name']) ? (string) $valeur['name'] : '',
                );
                continue;
            }
            $propre[$uid] = array(
                'at'   => is_numeric($valeur) ? (int) $valeur : 0,
                'name' => '',
            );
        }
        return $propre;
    }

    /**
     * Écarte un candidat, et retient sous quel nom on l'a vu.
     *
     * Le nom n'est pas demandé à l'appelant : la file l'a déjà, et le prendre
     * là où il est évite qu'une page puisse décider de ce qui s'inscrit en
     * configuration. Il est lu avant le retrait, sans quoi il n'y aurait plus
     * rien à lire.
     */
    public static function ignoreUid($_uid) {
        $uid = (string) $_uid;
        $nom = self::pendingName($uid);
        $liste = self::ignoredUids();
        $liste[$uid] = array('at' => time(), 'name' => $nom);
        /* Bornée : une maison très passante pourrait sinon faire enfler la
         * configuration sans fin. Les plus anciens refus sortent en premier. */
        if (count($liste) > 500) {
            uasort($liste, array(__CLASS__, 'compareIgnored'));
            $liste = array_slice($liste, -500, null, true);
        }
        config::save('discovery::ignored', json_encode($liste), 'mqttbe');
        self::forgetPending($uid);
    }

    /* Ordre croissant de date de refus : array_slice(-N) garde la fin, donc
     * les refus les plus récents. Un refus sans date — relu d'une version
     * antérieure — sort en premier, c'est aussi le plus ancien. */
    private static function compareIgnored($_a, $_b) {
        $a = isset($_a['at']) ? (int) $_a['at'] : 0;
        $b = isset($_b['at']) ? (int) $_b['at'] : 0;
        if ($a === $b) {
            return 0;
        }
        return $a < $b ? -1 : 1;
    }

    /** Le nom sous lequel la file connaît ce candidat, ou '' si elle l'ignore. */
    private static function pendingName($_uid) {
        $verrou = self::pendingLock(false);
        try {
            $attente = self::pendingRead();
        } finally {
            self::pendingUnlock($verrou);
        }
        if (!is_array($attente)) {
            return '';
        }
        $uid = (string) $_uid;
        return isset($attente[$uid]['name']) ? (string) $attente[$uid]['name'] : '';
    }

    public static function forgetIgnored($_uid) {
        $liste = self::ignoredUids();
        unset($liste[(string) $_uid]);
        /* Le tableau vide s'encode « [] » : relu, il redonne bien un tableau
         * vide, et non la chaîne « [] » prise pour un refus. */
        config::save('discovery::ignored', json_encode($liste), 'mqttbe');
    }

    /**
     * Verrou de la file d'adoption.
     *
     * Le cache de Jeedom écrit son fichier sans verrou ni renommage atomique
     * (core/class/cache.class.php, FileCache::save() : un file_put_contents()
     * nu). Un lecteur qui tombe au milieu de l'écriture lit un fichier
     * tronqué, unserialize() échoue, et la file paraît VIDE. Mesuré sur
     * l'installation, file réelle de 110 Ko, un écrivain et un lecteur : 607
     * lectures vides sur 45 742, soit plus d'une sur cent, et jusqu'à 3,4 %
     * selon la charge. Le callback du démon écrit jusqu'à vingt-cinq fois par
     * lot, c'est-à-dire précisément pendant que l'administrateur ouvre la
     * modale.
     *
     * flock sur un fichier du dossier temporaire du plugin, comme
     * mqttbeRouting::lock() : même dossier, même raison, et le cœur n'offre
     * aucune opération atomique sur le cache.
     */
    private static function pendingLock($_exclusif = true) {
        $fichier = @fopen(jeedom::getTmpFolder('mqttbe') . '/pending.lock', 'c');
        if ($fichier === false) {
            return null;
        }
        if (!@flock($fichier, $_exclusif ? LOCK_EX : LOCK_SH)) {
            fclose($fichier);
            return null;
        }
        return $fichier;
    }

    private static function pendingUnlock($_verrou) {
        if ($_verrou === null) {
            return;
        }
        @flock($_verrou, LOCK_UN);
        fclose($_verrou);
    }

    /**
     * Lit la file sans rien en interpréter.
     *
     * Rend null quand on ne sait pas ce qu'elle contient : cache inaccessible,
     * ou valeur d'un autre type que le tableau attendu. « Je ne sais pas »
     * n'est pas « elle est vide » — réécrire par-dessus une file inconnue
     * l'effacerait, et c'est exactement ce qui se produisait.
     */
    private static function pendingRead() {
        try {
            $valeur = cache::byKey(self::PENDING_KEY)->getValue(null);
        } catch (Throwable $e) {
            return null;
        }
        /* Jamais écrite, ou vidée : là, on sait, et la file est bien vide. */
        if ($valeur === null || $valeur === '') {
            return array();
        }
        return is_array($valeur) ? $valeur : null;
    }

    /**
     * Écarte les candidats périmés.
     *
     * Sans péremption, un passant aperçu une fois il y a trois semaines gardait
     * sa place à vie et participait à l'éviction : il faisait sortir de la file
     * un appareil que l'on voit tous les jours.
     */
    private static function pendingPrune($_attente) {
        $limite = time() - self::PENDING_TTL;
        $propre = array();
        foreach ($_attente as $uid => $candidat) {
            if (!is_array($candidat)) {
                continue;
            }
            $vu = isset($candidat['seen']) ? (int) $candidat['seen'] : 0;
            if ($vu <= 0) {
                /* Entrée d'une version antérieure, sans date : datée de
                 * maintenant plutôt que jetée sur un détail de forme. */
                $candidat['seen'] = time();
                $propre[$uid] = $candidat;
                continue;
            }
            if ($vu < $limite) {
                continue;
            }
            $propre[$uid] = $candidat;
        }
        return $propre;
    }

    /**
     * Ce qu'un candidat vaut quand la file déborde, en secondes de présence.
     *
     * Le critère est celui que la modale affiche déjà : depuis combien de temps
     * on le voit, et son adresse tiendra-t-elle. Seule « random » dit d'une
     * adresse qu'elle ne durera pas ; ne rien savoir du type n'est pas une
     * accusation et ne retire rien.
     */
    private static function pendingScore($_candidat) {
        $premier = isset($_candidat['first']) ? (int) $_candidat['first'] : 0;
        $vu      = isset($_candidat['seen'])  ? (int) $_candidat['seen']  : 0;
        $duree   = ($premier > 0 && $vu > $premier) ? $vu - $premier : 0;

        $type = '';
        if (isset($_candidat['model']['meta']['address_type'])) {
            $type = strtolower(trim((string) $_candidat['model']['meta']['address_type']));
        }
        return $duree + ($type === 'random' ? 0 : self::PENDING_STABLE_BONUS);
    }

    /**
     * Ordre croissant d'intérêt : le moins intéressant en tête.
     *
     * array_slice(-N) garde la FIN du tableau : trier ainsi puis couper garde
     * les meilleurs. La coupe précédente, elle, prétendait garder les cinquante
     * derniers insérés — sauf que réaffecter une clé existante ne la déplace
     * pas en fin de tableau en PHP : le traceur vu depuis des heures sortait
     * donc en premier, au profit du téléphone d'un passant arrivé à l'instant.
     */
    private static function comparePending($_a, $_b) {
        $sa = self::pendingScore($_a);
        $sb = self::pendingScore($_b);
        if ($sa !== $sb) {
            return $sa < $sb ? -1 : 1;
        }
        /* À égalité, le plus récemment vu passe devant : il est encore là. */
        $va = isset($_a['seen']) ? (int) $_a['seen'] : 0;
        $vb = isset($_b['seen']) ? (int) $_b['seen'] : 0;
        if ($va === $vb) {
            return 0;
        }
        return $va < $vb ? -1 : 1;
    }

    /**
     * La file telle qu'on peut la montrer : périmés écartés, meilleurs d'abord.
     *
     * Rend null quand la file n'a pas pu être lue. La page doit alors le dire :
     * afficher « rien n'attend » serait affirmer le contraire de ce qu'on sait.
     */
    public static function pendingQueue() {
        $verrou = self::pendingLock(false);
        try {
            $attente = self::pendingRead();
        } finally {
            self::pendingUnlock($verrou);
        }
        if ($attente === null) {
            return null;
        }
        $attente = self::pendingPrune($attente);
        uasort($attente, array(__CLASS__, 'comparePending'));
        /* Le plus intéressant d'abord : c'est l'ordre dans lequel on décide. */
        return array_reverse($attente, true);
    }

    /**
     * Le modèle mis de côté pour ce candidat, ou null s'il n'y en a plus.
     *
     * Lu sous le même verrou que le reste : l'adoption ne doit pas tomber sur
     * une file à moitié écrite et répondre « ce candidat n'est plus là » alors
     * qu'il y est.
     */
    public static function pendingModel($_uid) {
        $verrou = self::pendingLock(false);
        try {
            $attente = self::pendingRead();
        } finally {
            self::pendingUnlock($verrou);
        }
        if (!is_array($attente)) {
            return null;
        }
        $uid = (string) $_uid;
        return isset($attente[$uid]['model']) && is_array($attente[$uid]['model'])
             ? $attente[$uid]['model'] : null;
    }

    /**
     * Retire un candidat de la file, adopté ou écarté.
     *
     * Rend false quand rien n'a pu être écrit : l'appelant doit alors savoir
     * que le candidat est toujours là.
     */
    public static function forgetPending($_uid) {
        $verrou = self::pendingLock();
        try {
            $attente = self::pendingRead();
            if ($attente === null) {
                mqttbe::logger('warning', __("File d'adoption illisible : le candidat n'en a pas été retiré.", __FILE__));
                return false;
            }
            $avant = count($attente);
            unset($attente[(string) $_uid]);
            $attente = self::pendingPrune($attente);
            if (count($attente) === $avant) {
                /* Rien à retirer, rien à périmer : ne pas réécrire une file
                 * inchangée, c'est une occasion de moins de la corrompre. */
                return true;
            }
            cache::set(self::PENDING_KEY, $attente);
            return true;
        } catch (Throwable $e) {
            mqttbe::logger('debug', __("File d'adoption non enregistrée : ", __FILE__) . $e->getMessage());
            return false;
        } finally {
            self::pendingUnlock($verrou);
        }
    }

    /**
     * Met de côté un modèle en attente d'adoption.
     *
     * La liste est bornée et vit dans le cache : ce sont des candidats, pas des
     * données à conserver. Une file qui grossirait sans limite sur un broker
     * partagé finirait par peser plus lourd que les équipements eux-mêmes.
     */
    private static function rememberPending($_modele) {
        /* Ce que l'utilisateur a écarté ne revient pas le déranger. */
        $ignores = self::ignoredUids();
        if (isset($ignores[$_modele->uid()])) {
            return;
        }
        $verrou = self::pendingLock();
        try {
            $attente = self::pendingRead();
            if ($attente === null) {
                /* File illisible : on ne sait pas ce qu'elle contient, et la
                 * réécrire avec ce seul candidat effacerait tous les autres. */
                mqttbe::logger('warning', __("File d'adoption illisible : le candidat n'y a pas été ajouté.", __FILE__));
                return;
            }
            $connu = isset($attente[$_modele->uid()]) ? $attente[$_modele->uid()] : array();
            $attente[$_modele->uid()] = array(
                'name'     => $_modele->name(),
                'adapter'  => $_modele->adapter(),
                'model'    => $_modele->toArray(),
                /* Depuis quand on le voit, et non seulement la dernière fois :
                 * c'est la durée de présence qui distingue un objet de la
                 * maison d'un passant, et c'est elle qui permet de décider. */
                'first'    => isset($connu['first']) ? (int) $connu['first'] : time(),
                'seen'     => time(),
                'channels' => $_modele->countChannels(),
            );
            $attente = self::pendingPrune($attente);
            if (count($attente) > self::PENDING_MAX) {
                /* Trier AVANT de couper : sans tri, la coupe sortait le
                 * candidat qu'il fallait justement garder. */
                uasort($attente, array(__CLASS__, 'comparePending'));
                $attente = array_slice($attente, -self::PENDING_MAX, null, true);
            }
            cache::set(self::PENDING_KEY, $attente);
        } catch (Throwable $e) {
            mqttbe::logger('debug', __("File d'adoption non enregistrée : ", __FILE__) . $e->getMessage());
        } finally {
            self::pendingUnlock($verrou);
        }
    }

    public static function sendDaemonStateEvent($_state) {
        event::add('mqttbe::daemonState', array('state' => (bool) $_state));
    }

    public static function sendBrokerStateEvent($_state, $_message = '') {
        event::add('mqttbe::brokerState', array('state' => $_state, 'message' => $_message));
    }
}
