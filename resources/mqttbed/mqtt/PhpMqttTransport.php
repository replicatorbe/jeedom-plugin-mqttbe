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

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/* =============================================================================
 * Implémentation de MqttbeTransport au-dessus de php-mqtt/client 2.3.2,
 * embarquée dans resources/mqttbed/lib (chargée par lib/autoload.php, que le
 * point d'entrée inclut).
 *
 * CE FICHIER EST LE SEUL DU DÉMON À CONNAÎTRE LA BIBLIOTHÈQUE. Rien de ce
 * qu'elle définit — client, réglages, exceptions, socket — ne franchit cette
 * frontière : au-dehors ne sortent que des booléens, des chaînes et les
 * quatre paramètres d'un message reçu.
 * ========================================================================== */

/* -----------------------------------------------------------------------------
 * La sous-classe de cinq lignes annoncée par docs/ARCHITECTURE.md §4.1.
 *
 * La bibliothèque travaille déjà en non bloquant et expose loopOnce(), faite
 * pour un ordonnanceur externe ; il ne lui manque que l'accès au descripteur,
 * `protected`, sans lequel notre stream_select ne peut pas savoir quand le
 * broker a parlé. L'alternative — interroger loopOnce() en boucle serrée —
 * ferait tourner un coeur à 100 % pour ne rien lire.
 *
 * La bibliothèque n'est ni modifiée, ni forkée : elle reste remplaçable par
 * une simple montée de version.
 * -------------------------------------------------------------------------- */
final class MqttbeMqttClient extends MqttClient {

    public function stream() {
        return $this->socket;
    }
}

class MqttbePhpMqttTransport implements MqttbeTransport {

    const CONNECT_TIMEOUT = 5;
    const SOCKET_TIMEOUT  = 5;

    private $config;
    private $client = null;
    private $lastError = '';
    private $connectedAt = 0;

    /* Les abonnements souhaités, topic => qos. C'est ici qu'ils vivent, et
     * non dans la boucle : après une coupure, le rejeu est une affaire de
     * transport, pas une chose dont le reste du démon doive se souvenir. */
    private $subscriptions = array();

    private $messageHandler   = null;
    private $connectedHandler = null;

    public function __construct(MqttbeConfig $_config) {
        $this->config = $_config;
    }

    public function onMessage($_handler) {
        $this->messageHandler = $_handler;
    }

    public function onConnected($_handler) {
        $this->connectedHandler = $_handler;
    }

    public function lastError() {
        return $this->lastError;
    }

    public function connect() {
        $this->lastError = '';
        if (!$this->config->isUsable()) {
            $this->lastError = 'aucun broker configuré';
            return false;
        }
        $this->forget();

        try {
            $this->client = new MqttbeMqttClient(
                $this->config->host(),
                $this->config->port(),
                $this->config->clientId(),
                /*
                 * Explicitement 3.1.1 : le défaut de la bibliothèque est 3.1,
                 * dialecte de 1999 que certains brokers récents refusent tout
                 * net, et dont l'identifiant client est limité à 23 octets.
                 */
                MqttClient::MQTT_3_1_1
            );

            $this->client->registerMessageReceivedEventHandler(
                function ($_client, $_topic, $_message, $_qos, $_retained) {
                    $this->deliver($_topic, $_message, $_qos, $_retained);
                }
            );

            /*
             * Session propre à chaque connexion.
             *
             * Le démon rejoue lui-même ses abonnements, et Jeedom lui repousse
             * sa table de routage au démarrage : une session persistante ne
             * lui apporterait rien et lui ferait du tort. Le broker garderait
             * des abonnements dont le démon ne veut plus — un topic retiré de
             * la configuration continuerait d'arriver — et lui empilerait les
             * messages QoS ≥ 1 émis pendant qu'il était absent, c'est-à-dire
             * un paquet d'états périmés à traiter comme des nouveautés au
             * retour. On préfère repartir de zéro et redemander.
             */
            $this->client->connect($this->settings(), true);

        } catch (Throwable $e) {
            $this->lastError = self::explain($e);
            $this->client = null;
            return false;
        }

        $this->connectedAt = time();
        $this->replaySubscriptions();

        if ($this->connectedHandler !== null) {
            try {
                call_user_func($this->connectedHandler);
            } catch (Throwable $e) {
                MqttbeLog::error('erreur dans le gestionnaire de connexion : ' . $e->getMessage());
            }
        }
        return true;
    }

    public function disconnect() {
        $this->forget();
    }

    public function isConnected() {
        try {
            return $this->client !== null && $this->client->isConnected();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function stream() {
        if ($this->client === null) {
            return null;
        }
        $stream = $this->client->stream();
        return is_resource($stream) ? $stream : null;
    }

    public function subscribe($_topic, $_qos = 0) {
        $topic = (string) $_topic;
        if ($topic === '') {
            $this->lastError = 'topic vide';
            return false;
        }
        $qos = max(0, min(2, (int) $_qos));

        /* Mémorisé avant l'envoi, et gardé même si l'envoi échoue : c'est la
         * liste de ce que le démon VEUT, pas de ce qu'il a obtenu. La
         * prochaine connexion la rejouera. */
        $this->subscriptions[$topic] = $qos;

        if (!$this->isConnected()) {
            MqttbeLog::debug('abonnement ' . $topic . ' mémorisé, en attente de connexion');
            return true;
        }
        try {
            $this->client->subscribe($topic, null, $qos);
            MqttbeLog::info('abonné à ' . $topic . ' (QoS ' . $qos . ')');
            return true;
        } catch (Throwable $e) {
            $this->lastError = self::explain($e);
            MqttbeLog::error('abonnement à ' . $topic . ' impossible : ' . $this->lastError);
            return false;
        }
    }

    public function unsubscribe($_topic) {
        $topic = (string) $_topic;
        unset($this->subscriptions[$topic]);

        if (!$this->isConnected()) {
            return true;
        }
        try {
            $this->client->unsubscribe($topic);
            MqttbeLog::info('désabonné de ' . $topic);
            return true;
        } catch (Throwable $e) {
            $this->lastError = self::explain($e);
            MqttbeLog::error('désabonnement de ' . $topic . ' impossible : ' . $this->lastError);
            return false;
        }
    }

    public function publish($_topic, $_payload, $_qos = 0, $_retain = false) {
        if (!$this->isConnected()) {
            $this->lastError = 'non connecté au broker';
            return false;
        }
        /*
         * MQTT 3.1.1 §3.3.2 interdit les jokers dans un topic de publication, et
         * un broker qui en reçoit ferme la connexion pour violation de
         * protocole. Le topic peut venir d'un appareil du réseau : on refuse ici
         * plutôt que de se faire expulser à chaque appui sur un bouton.
         */
        if ($_topic === '' || strpbrk((string) $_topic, '+#') !== false) {
            $this->lastError = 'topic de publication invalide (vide ou contenant un joker)';
            MqttbeLog::error('publication refusée sur « ' . $_topic . ' » : ' . $this->lastError);
            return false;
        }
        try {
            $this->client->publish((string) $_topic, (string) $_payload,
                                   max(0, min(2, (int) $_qos)), (bool) $_retain);
            return true;
        } catch (Throwable $e) {
            $this->lastError = self::explain($e);
            MqttbeLog::error('publication sur ' . $_topic . ' impossible : ' . $this->lastError);
            return false;
        }
    }

    public function tick() {
        if ($this->client === null) {
            return false;
        }
        try {
            /*
             * L'instant passé en premier argument ne sert qu'aux gestionnaires
             * de boucle de la bibliothèque, dont nous n'enregistrons aucun :
             * lui donner « maintenant » est exact et évite de traîner une date
             * de départ qui ne veut rien dire pour un ordonnanceur externe.
             *
             * allowSleep à false : c'est le stream_select de la boucle qui
             * décide quand dormir, et lui seul sait qu'il y a d'autres
             * descripteurs à surveiller.
             */
            $this->client->loopOnce(microtime(true), false);
        } catch (Throwable $e) {
            $this->lastError = self::explain($e);
            $this->forget();
            return false;
        }
        if (!$this->client->isConnected()) {
            $this->lastError = 'liaison fermée par le broker';
            $this->forget();
            return false;
        }
        return true;
    }

    public function connectedSince() {
        return $this->connectedAt;
    }

    public function subscriptionCount() {
        return count($this->subscriptions);
    }

    /* ----------------------------------------------------------- interne */

    private function deliver($_topic, $_payload, $_qos, $_retained) {
        if ($this->messageHandler === null) {
            return;
        }
        try {
            call_user_func($this->messageHandler, $_topic, $_payload, (int) $_qos, (bool) $_retained);
        } catch (Throwable $e) {
            /* Un message mal formé, ou un adapter fautif, ne coupe pas la
             * liaison : il fait une ligne de journal et le suivant est lu. */
            MqttbeLog::error('erreur en traitant le message de ' . $_topic . ' : ' . $e->getMessage());
        }
    }

    private function replaySubscriptions() {
        foreach ($this->subscriptions as $topic => $qos) {
            try {
                $this->client->subscribe($topic, null, $qos);
            } catch (Throwable $e) {
                MqttbeLog::error('ré-abonnement à ' . $topic . ' impossible : ' . self::explain($e));
            }
        }
        if (!empty($this->subscriptions)) {
            MqttbeLog::info(count($this->subscriptions) . ' abonnement(s) rejoué(s)');
        }
    }

    /* Oublie le client en tentant l'adieu poli. La socket est fermée par la
     * bibliothèque, ou à défaut par la libération de l'objet. */
    private function forget() {
        if ($this->client === null) {
            return;
        }
        try {
            if ($this->client->isConnected()) {
                $this->client->disconnect();
            }
        } catch (Throwable $e) {
            MqttbeLog::debug('déconnexion sans acquittement : ' . $e->getMessage());
        }
        $this->client = null;
        $this->connectedAt = 0;
    }

    private function settings() {
        $settings = new ConnectionSettings();

        /*
         * Les setters de ConnectionSettings rendent une COPIE modifiée : la
         * chaîne d'affectations n'est pas une coquetterie d'écriture, oublier
         * de réaffecter $settings perdrait silencieusement le réglage.
         */
        $settings = $settings
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setSocketTimeout(self::SOCKET_TIMEOUT)
            ->setKeepAliveInterval($this->config->keepalive())
            /*
             * Reconnexion automatique DÉSACTIVÉE, à dessein.
             *
             * Celle de la bibliothèque dort (usleep) entre ses tentatives, au
             * beau milieu d'un loopOnce : la boucle du démon serait figée
             * pendant ce temps, socket de commande comprise, et Jeedom nous
             * croirait morts. La reconnexion est donc conduite par la boucle,
             * avec un recul progressif et sans jamais s'endormir.
             */
            ->setReconnectAutomatically(false);

        if ($this->config->username() !== '') {
            $settings = $settings
                ->setUsername($this->config->username())
                ->setPassword($this->config->password());
        }

        if ($this->config->tls()) {
            $verify = !$this->config->tlsInsecure();
            $settings = $settings
                ->setUseTls(true)
                ->setTlsVerifyPeer($verify)
                ->setTlsVerifyPeerName($verify)
                ->setTlsSelfSignedAllowed(!$verify);

            $caFile = $this->config->caFile();
            if ($caFile !== '') {
                if (is_file($caFile) && is_readable($caFile)) {
                    $settings = $settings->setTlsCertificateAuthorityFile($caFile);
                } else {
                    /* Ne pas le dire, c'est laisser l'utilisateur croire que
                     * son certificat est vérifié alors qu'il ne l'est pas. */
                    MqttbeLog::warning('certificat d\'autorité illisible, ignoré : ' . $caFile);
                }
            }
        }
        return $settings;
    }

    /*
     * Traduit une exception de la bibliothèque en une phrase destinée à
     * l'utilisateur : ce texte part dans le journal ET dans le message
     * `brokerDown`, que l'interface affiche telle quelle.
     */
    private static function explain(Throwable $_e) {
        $message = trim($_e->getMessage());
        if ($message === '') {
            $message = get_class($_e);
        }
        /*
         * Ces messages partent tels quels dans l'interface de Jeedom : les
         * laisser en anglais et en jargon de bibliothèque (« Transferring data
         * over socket failed »), c'est demander à l'utilisateur de traduire
         * une panne qu'il n'a pas causée.
         */
        if (stripos($message, 'EOF') !== false || stripos($message, 'closed by the broker') !== false) {
            return 'le broker a fermé la liaison';
        }
        if (stripos($message, 'no ping response') !== false) {
            return 'le broker ne répond plus aux pings (liaison considérée comme morte)';
        }
        if (stripos($message, 'Connection refused') !== false) {
            return 'connexion refusée par le broker (adresse ou port erroné, ou broker arrêté)';
        }
        if (stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false) {
            return 'le broker n\'a pas répondu à temps';
        }
        if (stripos($message, 'not authorized') !== false || stripos($message, 'bad username') !== false) {
            return 'identifiants refusés par le broker';
        }
        return (strlen($message) > 200) ? substr($message, 0, 200) . '…' : $message;
    }
}
