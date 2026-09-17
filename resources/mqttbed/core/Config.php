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

/* =============================================================================
 * Configuration du broker, telle que Jeedom la voit.
 *
 * Le démon ne lit aucun fichier de configuration : il reçoit tout par le
 * message `setBroker` sur sa socket de commande. C'est volontaire — les
 * réglages vivent dans la base de Jeedom, un seul endroit, et un changement
 * s'applique à chaud sans redémarrer le processus ni dupliquer la vérité.
 *
 * Au lancement, cet objet est donc vide : le démon démarre, annonce sa
 * présence, et attend son premier `setBroker`. Tant qu'il n'a pas d'hôte
 * (isUsable() == false), il ne tente aucune connexion plutôt que de boucler
 * sur des échecs contre une adresse vide.
 * ========================================================================== */
class MqttbeConfig {

    const DEFAULT_PORT      = 1883;
    const DEFAULT_KEEPALIVE = 60;

    private $host        = '';
    private $port        = self::DEFAULT_PORT;
    private $tls         = false;
    private $tlsInsecure = false;
    private $caFile      = '';
    private $username    = '';
    private $password    = '';
    private $clientId    = '';
    private $keepalive   = self::DEFAULT_KEEPALIVE;

    /* Motifs de topics que le démon ignore à la réception : une liste de
     * filtres MQTT, jokers compris. */
    private $excludes = array();

    public function __construct($_values = array()) {
        if (is_array($_values) && !empty($_values)) {
            $this->apply($_values);
        }
    }

    /*
     * Applique une configuration reçue de Jeedom et dit si la liaison avec le
     * broker doit être refaite.
     *
     * La distinction n'est pas cosmétique : un simple changement de liste
     * d'exclusion ne doit pas couper une connexion en place, alors qu'un
     * changement d'identifiant ou de keepalive ne peut être pris en compte
     * qu'au prochain CONNECT — ces valeurs partent dans la trame d'ouverture.
     */
    public function apply($_values) {
        if (!is_array($_values)) {
            return false;
        }
        $before = array($this->host, $this->port, $this->tls, $this->tlsInsecure, $this->caFile,
                        $this->username, $this->password, $this->clientId, $this->keepalive);

        if (array_key_exists('host', $_values)) {
            $this->host = trim((string) $_values['host']);
        }
        if (array_key_exists('port', $_values)) {
            $port = (int) $_values['port'];
            $this->port = ($port > 0 && $port <= 65535) ? $port : self::DEFAULT_PORT;
        }
        if (array_key_exists('tls', $_values)) {
            $this->tls = self::toBool($_values['tls']);
        }
        if (array_key_exists('tlsInsecure', $_values)) {
            $this->tlsInsecure = self::toBool($_values['tlsInsecure']);
        }
        if (array_key_exists('caFile', $_values)) {
            $this->caFile = trim((string) $_values['caFile']);
        }
        if (array_key_exists('username', $_values)) {
            $this->username = (string) $_values['username'];
        }
        if (array_key_exists('password', $_values)) {
            $this->password = (string) $_values['password'];
        }
        if (array_key_exists('clientId', $_values)) {
            $this->clientId = trim((string) $_values['clientId']);
        }
        if (array_key_exists('keepalive', $_values)) {
            $keepalive = (int) $_values['keepalive'];
            /* Sous 10 s le démon passerait son temps à pinguer ; au-delà d'une
             * heure le broker aurait depuis longtemps oublié la session. */
            $this->keepalive = max(10, min(3600, $keepalive > 0 ? $keepalive : self::DEFAULT_KEEPALIVE));
        }
        if (array_key_exists('exclude', $_values)) {
            $this->excludes = self::parsePatterns($_values['exclude']);
        } elseif (array_key_exists('excludes', $_values)) {
            $this->excludes = self::parsePatterns($_values['excludes']);
        }

        $this->ensureClientId();

        return $before !== array($this->host, $this->port, $this->tls, $this->tlsInsecure, $this->caFile,
                                 $this->username, $this->password, $this->clientId, $this->keepalive);
    }

    /*
     * Un identifiant client vide est licite en MQTT 3.1.1, mais il impose une
     * session propre côté broker et rend les journaux du broker illisibles :
     * on ne sait plus qui est connecté. Le démon s'en fabrique donc un, une
     * seule fois, et le garde pour toute la durée de sa vie — en changer à
     * chaque reconnexion laisserait derrière lui une traînée de sessions
     * fantômes chez le broker.
     */
    private function ensureClientId() {
        if ($this->clientId !== '') {
            return;
        }
        $this->clientId = 'mqttbe-' . substr(md5(gethostname() . '|' . getmypid()), 0, 8);
    }

    public function isUsable() {
        return $this->host !== '';
    }

    public function host()        { return $this->host; }
    public function port()        { return $this->port; }
    public function tls()         { return $this->tls; }
    public function tlsInsecure() { return $this->tlsInsecure; }
    public function caFile()      { return $this->caFile; }
    public function username()    { return $this->username; }
    public function password()    { return $this->password; }
    public function clientId()    { return $this->clientId; }
    public function keepalive()   { return $this->keepalive; }
    public function excludes()    { return $this->excludes; }

    /* Jamais le mot de passe : ce texte part dans le journal de Jeedom, que
     * l'utilisateur colle tel quel sur le forum quand il demande de l'aide. */
    public function describe() {
        return ($this->tls ? 'mqtts://' : 'mqtt://') . $this->host . ':' . $this->port
             . ' (client ' . $this->clientId . ($this->username !== '' ? ', utilisateur ' . $this->username : '') . ')';
    }

    public function isExcluded($_topic) {
        foreach ($this->excludes as $filter) {
            if (self::topicMatches($filter, $_topic)) {
                return true;
            }
        }
        return false;
    }

    /* Accepte indifféremment un tableau ou le texte multiligne du formulaire
     * Jeedom (`topics::exclude`), qui arrive avec des fins de ligne Windows
     * quand l'utilisateur a collé sa liste depuis un éditeur. */
    private static function parsePatterns($_raw) {
        $lines = is_array($_raw) ? $_raw : preg_split('/\r\n|\r|\n/', (string) $_raw);
        $patterns = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $patterns[] = $line;
            }
        }
        return $patterns;
    }

    /* Jeedom transmet ses booléens tantôt en JSON, tantôt en "1"/"0" hérités
     * d'un champ de formulaire : les deux doivent donner le même résultat. */
    private static function toBool($_value) {
        if (is_bool($_value)) {
            return $_value;
        }
        if (is_numeric($_value)) {
            return ((int) $_value) !== 0;
        }
        return in_array(strtolower(trim((string) $_value)), array('true', 'yes', 'on'), true);
    }

    /*
     * Correspondance d'un topic avec un filtre MQTT (OASIS 3.1.1 §4.7).
     *
     * Elle est écrite ici plutôt qu'empruntée à la bibliothèque : celle-ci ne
     * l'expose que par son dépôt d'abonnements, et le démon doit pouvoir dire
     * « ce topic est exclu » sans qu'aucun abonnement n'existe.
     */
    public static function topicMatches($_filter, $_topic) {
        if ($_filter === $_topic) {
            return true;
        }
        $filterParts = explode('/', $_filter);
        $topicParts  = explode('/', $_topic);

        /* Les topics de service commençant par $ ne doivent jamais être pris
         * par un joker de tête : sans cette règle, un abonnement `#` capterait
         * les statistiques internes du broker ($SYS), qui n'intéressent
         * personne ici et arrivent en continu. */
        if (($filterParts[0] === '#' || $filterParts[0] === '+')
         && isset($topicParts[0]) && strncmp($topicParts[0], '$', 1) === 0) {
            return false;
        }

        foreach ($filterParts as $i => $part) {
            if ($part === '#') {
                /* `a/#` couvre `a` lui-même autant que `a/b/c`. */
                return count($topicParts) >= $i;
            }
            if (!isset($topicParts[$i])) {
                return false;
            }
            if ($part !== '+' && $part !== $topicParts[$i]) {
                return false;
            }
        }
        return count($filterParts) === count($topicParts);
    }
}
