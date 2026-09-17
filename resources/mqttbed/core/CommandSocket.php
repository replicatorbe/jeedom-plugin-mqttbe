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
 * Canal Jeedom → démon.
 *
 * Un serveur TCP sur 127.0.0.1 : une connexion par message, un objet JSON,
 * une réponse, puis fermeture. C'est le schéma de jMQTT, et il a une qualité
 * que n'a pas une liaison persistante : il n'y a rien à reconnecter. Un worker
 * PHP de Jeedom qui meurt en plein envoi ne laisse derrière lui qu'une socket
 * fermée, pas un état à réconcilier.
 *
 * Deux règles, non négociables :
 *
 * - AUCUNE ATTENTE. Le descripteur d'écoute et chaque connexion en cours
 *   entrent dans le stream_select de la boucle principale. Lire un message
 *   « juste jusqu'au bout » en bloquant, c'est offrir à n'importe quel
 *   processus local le pouvoir de figer le démon en ouvrant une connexion
 *   qu'il n'écrira jamais.
 *
 * - CLÉ D'API VÉRIFIÉE À CHAQUE MESSAGE. L'écoute est limitée à la boucle
 *   locale, mais sur une box tout le monde est local : un autre plugin, un
 *   script, un conteneur. Un refus est journalisé, jamais silencieux.
 * ========================================================================== */
class MqttbeCommandSocket {

    /* Jeedom n'ouvre qu'une connexion à la fois. Au-delà de quelques-unes,
     * c'est que quelque chose ne va pas : on refuse plutôt que de grossir. */
    const MAX_CONNECTIONS = 16;
    const MAX_MESSAGE     = 65536;
    const READ_TIMEOUT    = 5;       // secondes pour dire ce qu'on a à dire

    private $port;
    private $apikey;
    private $handler  = null;
    private $listener = null;
    private $connections = array();
    private $nextId = 1;
    private $refused = 0;

    public function __construct($_port, $_apikey) {
        $this->port   = (int) $_port;
        $this->apikey = (string) $_apikey;
    }

    /* Le traitement d'un ordre n'appartient pas à ce fichier : il reçoit un
     * tableau, il rend un tableau de réponse. */
    public function onCommand($_handler) {
        $this->handler = $_handler;
    }

    public function listen() {
        $errno = 0; $errstr = '';
        $this->listener = @stream_socket_server('tcp://127.0.0.1:' . $this->port, $errno, $errstr);
        if ($this->listener === false) {
            $this->listener = null;
            MqttbeLog::error('écoute impossible sur 127.0.0.1:' . $this->port . ' : ' . $errstr);
            return false;
        }
        stream_set_blocking($this->listener, false);
        MqttbeLog::info('socket de commande ouverte sur 127.0.0.1:' . $this->port);
        return true;
    }

    /* Descripteurs à confier au stream_select de la boucle. */
    public function streams() {
        $streams = array();
        if (is_resource($this->listener)) {
            $streams[] = $this->listener;
        }
        foreach ($this->connections as $conn) {
            if (is_resource($conn['stream'])) {
                $streams[] = $conn['stream'];
            }
        }
        return $streams;
    }

    /*
     * Reçoit les descripteurs que stream_select a déclarés prêts. Ceux qui ne
     * lui appartiennent pas — la socket du broker, par exemple — sont ignorés
     * sans bruit : la boucle passe la liste entière à tout le monde.
     */
    public function handleReady($_ready) {
        foreach ($_ready as $stream) {
            if (is_resource($this->listener) && $stream === $this->listener) {
                $this->accept();
                continue;
            }
            foreach ($this->connections as $id => $conn) {
                if ($conn['stream'] === $stream) {
                    $this->read($id);
                    break;
                }
            }
        }
    }

    /* Ferme les connexions ouvertes qui ne disent rien : un scan de port laisse
     * des sockets muettes, et elles ne doivent pas s'accumuler. */
    public function purge() {
        $now = time();
        foreach ($this->connections as $id => $conn) {
            if ($conn['deadline'] <= $now) {
                MqttbeLog::debug('connexion locale close sans message complet (délai dépassé)');
                $this->closeConnection($id);
            }
        }
    }

    public function close() {
        foreach (array_keys($this->connections) as $id) {
            $this->closeConnection($id);
        }
        if (is_resource($this->listener)) {
            @fclose($this->listener);
        }
        $this->listener = null;
    }

    public function refusedCount() {
        return $this->refused;
    }

    /* ----------------------------------------------------------- interne */

    private function accept() {
        /* Boucle : plusieurs connexions peuvent être en attente derrière un
         * seul réveil du select, et n'en prendre qu'une les ferait patienter
         * un tour de boucle chacune. */
        while (true) {
            $conn = @stream_socket_accept($this->listener, 0);
            if ($conn === false) {
                return;
            }
            if (count($this->connections) >= self::MAX_CONNECTIONS) {
                MqttbeLog::warning('trop de connexions locales simultanées, connexion refusée');
                @fclose($conn);
                continue;
            }
            stream_set_blocking($conn, false);
            $this->connections[$this->nextId++] = array(
                'stream'   => $conn,
                'buffer'   => '',
                'deadline' => time() + self::READ_TIMEOUT,
            );
        }
    }

    private function read($_id) {
        $conn  = $this->connections[$_id];
        $chunk = @fread($conn['stream'], 8192);

        if ($chunk === false || ($chunk === '' && feof($conn['stream']))) {
            /* Fin de flux : ce qui a été reçu est tout ce qui sera reçu. Une
             * connexion fermée sans avoir rien dit n'est pas une erreur, c'est
             * un scan de port ou un test de disponibilité : on n'en fait pas
             * une ligne de journal. */
            if (trim($this->connections[$_id]['buffer']) === '') {
                $this->closeConnection($_id);
                return;
            }
            $this->dispatch($_id, $this->connections[$_id]['buffer']);
            return;
        }
        if ($chunk === '') {
            return;
        }

        $this->connections[$_id]['buffer'] .= $chunk;
        if (strlen($this->connections[$_id]['buffer']) > self::MAX_MESSAGE) {
            MqttbeLog::warning('message local hors limite (' . self::MAX_MESSAGE . ' octets), connexion fermée');
            $this->closeConnection($_id);
            return;
        }

        /*
         * Un objet JSON complet suffit à déclencher le traitement : l'émetteur
         * n'est pas tenu de fermer sa socket ni de terminer par un saut de
         * ligne, et attendre l'un ou l'autre ferait dépendre le démon de la
         * politesse de son correspondant.
         */
        $raw = trim($this->connections[$_id]['buffer']);
        if ($raw === '') {
            return;
        }
        if (json_decode($raw, true) !== null || strtolower($raw) === 'null') {
            $this->dispatch($_id, $raw);
        }
    }

    private function dispatch($_id, $_raw) {
        $raw   = trim((string) $_raw);
        $order = ($raw === '') ? null : json_decode($raw, true);

        if (!is_array($order)) {
            $this->refused++;
            MqttbeLog::warning('message local illisible, ignoré : ' . substr($raw, 0, 120));
            $this->reply($_id, array('state' => 'error', 'result' => 'message illisible'));
            return;
        }

        /*
         * hash_equals et non == : la comparaison naïve de deux chaînes s'arrête
         * au premier caractère différent, et ce temps de réponse se mesure.
         */
        if (!isset($order['apikey']) || !is_string($order['apikey'])
         || !hash_equals($this->apikey, $order['apikey'])) {
            $this->refused++;
            MqttbeLog::warning('message local rejeté : clé d\'API invalide (commande '
                             . (isset($order['cmd']) ? json_encode($order['cmd']) : 'absente') . ')');
            $this->reply($_id, array('state' => 'error', 'result' => 'clé d\'API invalide'));
            return;
        }

        $result = array('state' => 'ok');
        if ($this->handler !== null) {
            try {
                $handled = call_user_func($this->handler, $order);
                if (is_array($handled)) {
                    $result = $handled;
                }
            } catch (Throwable $e) {
                /* Un ordre mal formé ne doit pas emporter le démon : il n'y a
                 * pas d'ordre assez important pour valoir la connexion MQTT. */
                MqttbeLog::error('erreur en traitant l\'ordre '
                               . (isset($order['cmd']) ? (string) $order['cmd'] : '?') . ' : ' . $e->getMessage());
                $result = array('state' => 'error', 'result' => $e->getMessage());
            }
        }
        $this->reply($_id, $result);
    }

    private function reply($_id, $_result) {
        if (isset($this->connections[$_id]) && is_resource($this->connections[$_id]['stream'])) {
            @fwrite($this->connections[$_id]['stream'], json_encode($_result) . "\n");
        }
        $this->closeConnection($_id);
    }

    private function closeConnection($_id) {
        if (!isset($this->connections[$_id])) {
            return;
        }
        if (is_resource($this->connections[$_id]['stream'])) {
            @fclose($this->connections[$_id]['stream']);
        }
        unset($this->connections[$_id]);
    }
}
