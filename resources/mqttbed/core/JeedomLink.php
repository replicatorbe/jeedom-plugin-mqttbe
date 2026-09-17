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
 * Canal démon → Jeedom.
 *
 * Un POST HTTP sur le callback du plugin, corps = tableau JSON de messages,
 * clé d'API et uid en paramètres d'URL.
 *
 * Trois exigences gouvernent ce fichier :
 *
 * 1. NE JAMAIS BLOQUER LA BOUCLE. Un curl_exec synchrone de quatre secondes
 *    contre un Apache saturé, c'est quatre secondes sans lire la socket du
 *    broker : le keepalive MQTT expire et le broker coupe. Les envois passent
 *    donc par curl_multi, lancés puis relancés à chaque tour de boucle, et
 *    aucun appel de tick() n'attend une réponse. Un fils par envoi — la
 *    solution du plugin Dahua — coûterait un fork toutes les 200 ms ici, où
 *    les lots sont bien plus fréquents que des sonnettes.
 *
 * 2. NE PAS PERDRE LES MESSAGES sur un échec passager. Le lot en vol qui
 *    échoue repart en tête de file : Jeedom redémarré sous nos pieds, ou une
 *    seconde de surcharge, ne doivent pas coûter une valeur.
 *
 * 3. NE PAS GROSSIR SANS FIN. La file est plafonnée. Au-delà, on jette les
 *    plus anciens et on le dit : un démon qui garde tout finit tué par l'OOM
 *    killer, et c'est la panne la plus difficile à comprendre de toutes.
 *
 * Le pendant de (2) et (3) est le suicide : si Jeedom reste injoignable cinq
 * minutes, le démon s'arrête. Mieux vaut un démon mort que Jeedom relance
 * qu'un démon vivant qui accumule dans le vide.
 * ========================================================================== */
class MqttbeJeedomLink {

    const QUEUE_MAX         = 1000;   // messages en attente, au-delà on jette les plus vieux
    const HEARTBEAT_PERIOD  = 45;     // silence maximal toléré par Jeedom
    const DEAD_AFTER        = 300;    // Jeedom injoignable au-delà : le démon s'arrête
    const CONNECT_TIMEOUT   = 2;
    const TIMEOUT           = 5;
    const DROP_LOG_PERIOD   = 30;     // une plainte toutes les 30 s, pas une par message perdu
    const FAIL_LOG_PERIOD   = 30;     // idem pour les échecs d'envoi
    const RETRY_MAX_DELAY   = 30;     // plafond de l'espacement des nouvelles tentatives

    private $callback;
    private $apikey;
    private $uid;
    private $batchDelay;

    private $queue    = array();
    private $inFlight = array();      // lot confié à curl, gardé jusqu'à l'acquittement
    private $multi    = null;
    private $handle   = null;

    private $lastFlush   = 0.0;
    private $lastSend    = 0;
    private $lastSuccess = 0;
    private $failures    = 0;
    private $retryAt     = 0.0;
    private $dropped     = 0;
    private $lastDropLog = 0;
    private $lastFailLog = 0;

    public function __construct($_callback, $_apikey, $_uid, $_batchDelay = 0.2) {
        $this->callback = (string) $_callback;
        $this->apikey   = (string) $_apikey;
        $this->uid      = (string) $_uid;
        /* En dessous de 50 ms le lot n'a plus de sens — autant un POST par
         * message ; au-delà d'une seconde la latence devient visible sur un
         * bouton qu'on vient d'appuyer. */
        $this->batchDelay = max(0.05, min(1.0, (float) $_batchDelay));
        /* Le compte à rebours des 300 s part du lancement : sans cela, un démon
         * qui n'a jamais réussi le moindre envoi ne mourrait jamais. */
        $this->lastSuccess = time();
        $this->lastSend    = time();
    }

    public function setBatchDelay($_delay) {
        $this->batchDelay = max(0.05, min(1.0, (float) $_delay));
    }

    /* --------------------------------------------------------------- envoi */

    public function push($_message) {
        $this->queue[] = $_message;
        $this->enforceQueueLimit();
    }

    /*
     * Envoi synchrone, réservé aux deux messages qui encadrent la vie du démon.
     *
     * `daemonUp` conditionne la suite : tant qu'il n'est pas passé, rien ne
     * garantit que l'URL de rappel et la clé d'API sont bonnes, et un démon
     * qui se connecterait au broker pour parler dans le vide ne rendrait
     * service à personne. `daemonDown` est le dernier souffle : le mettre en
     * file, c'est ne jamais l'envoyer.
     */
    public function sendNow($_message) {
        $body = json_encode(array($_message));
        $ch = curl_init($this->url());
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $this->lastSend = time();
        if ($response !== false && $code == 200) {
            $this->lastSuccess = time();
            $this->failures    = 0;
            return true;
        }
        $this->failures++;
        $this->retryAt = microtime(true) + min(self::RETRY_MAX_DELAY, pow(2, min(5, $this->failures - 1)));
        MqttbeLog::error('envoi synchrone vers Jeedom en échec (HTTP ' . $code
                       . ($error != '' ? ' / ' . $error : '') . ') : ' . json_encode($_message));
        return false;
    }

    /*
     * À appeler à chaque tour de boucle. Fait avancer l'envoi en cours, en
     * démarre un nouveau si la file l'exige, et produit le battement de coeur.
     */
    public function tick() {
        $this->poll();
        $this->heartbeat();

        if ($this->handle !== null || empty($this->queue)) {
            return;
        }
        if ((microtime(true) - $this->lastFlush) < $this->batchDelay) {
            return;
        }
        /*
         * Après un échec, on espace : réessayer toutes les 200 ms contre un
         * Jeedom absent, c'est cinq connexions refusées par seconde pendant
         * cinq minutes, et autant de lignes dans un journal que personne ne
         * pourra plus lire.
         */
        if ($this->failures > 0 && microtime(true) < $this->retryAt) {
            return;
        }
        $this->start();
    }

    /* Un silence de plus de 45 s est interprété par Jeedom comme un démon mort :
     * le battement dit « rien à signaler », pas « tout va bien ». */
    private function heartbeat() {
        if ((time() - $this->lastSend) < self::HEARTBEAT_PERIOD) {
            return;
        }
        /* Rien à ajouter si quelque chose attend déjà de partir : ce lot-là
         * vaut battement, et lastSend sera repoussé par son envoi. */
        if (!empty($this->queue) || $this->handle !== null) {
            return;
        }
        $this->push(array('cmd' => 'hb'));
    }

    private function start() {
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }
        $this->inFlight = $this->queue;
        $this->queue    = array();

        $ch = curl_init($this->url());
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($this->inFlight),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
        curl_multi_add_handle($this->multi, $ch);
        $this->handle    = $ch;
        $this->lastFlush = microtime(true);
        $this->lastSend  = time();

        // Amorce le transfert : sans ce premier tour, rien n'est écrit sur le réseau.
        $this->poll();
    }

    private function poll() {
        if ($this->handle === null) {
            return;
        }
        $active = 0;
        do {
            $status = curl_multi_exec($this->multi, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }
            $ch    = $info['handle'];
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = ($info['result'] !== CURLE_OK) ? curl_error($ch) : '';
            curl_multi_remove_handle($this->multi, $ch);
            curl_close($ch);
            $this->handle = null;
            $this->finish($code, $error);
        }
    }

    private function finish($_code, $_error) {
        $count = count($this->inFlight);
        if ($_code == 200) {
            $this->lastSuccess = time();
            if ($this->failures > 0) {
                MqttbeLog::info('Jeedom de nouveau joignable après ' . $this->failures . ' échec(s)');
            }
            $this->failures = 0;
            $this->retryAt  = 0.0;
            $this->inFlight = array();
            if (MqttbeLog::isDebug()) {
                MqttbeLog::debug($count . ' message(s) remis à Jeedom');
            }
            return;
        }

        $this->failures++;
        $delay = min(self::RETRY_MAX_DELAY, pow(2, min(5, $this->failures - 1)));
        $this->retryAt = microtime(true) + $delay;

        /* Le premier échec est dit tout de suite — c'est celui qui explique la
         * panne — puis on se tait, sauf un rappel toutes les 30 s. */
        if ($this->failures == 1 || (time() - $this->lastFailLog) >= self::FAIL_LOG_PERIOD) {
            MqttbeLog::error('Jeedom ne prend pas le lot de ' . $count . ' message(s) (HTTP ' . $_code
                           . ($_error != '' ? ' / ' . $_error : '') . ') — ' . $this->failures
                           . ' échec(s), nouvelle tentative dans ' . $delay . ' s');
            $this->lastFailLog = time();
        }

        /* Remis EN TÊTE : l'ordre chronologique des valeurs compte pour
         * l'historique de Jeedom, et un lot rejoué après les suivants
         * écrirait le passé par-dessus le présent. */
        $this->queue = array_merge($this->inFlight, $this->queue);
        $this->inFlight = array();
        $this->enforceQueueLimit();
    }

    private function enforceQueueLimit() {
        $excess = count($this->queue) - self::QUEUE_MAX;
        if ($excess <= 0) {
            return;
        }
        array_splice($this->queue, 0, $excess);
        $this->dropped += $excess;

        /* Une ligne de journal par message jeté ferait, sur une panne longue,
         * exactement ce qu'on cherche à éviter : remplir le disque. */
        if ((time() - $this->lastDropLog) >= self::DROP_LOG_PERIOD) {
            MqttbeLog::warning('file d\'envoi saturée (' . self::QUEUE_MAX . ' messages), '
                             . $this->dropped . ' message(s) abandonné(s) depuis le début de l\'incident');
            $this->lastDropLog = time();
        }
    }

    /* ------------------------------------------------------------- constats */

    /*
     * Jeedom est-il perdu pour de bon ? La question se pose sur le dernier
     * envoi RÉUSSI, pas sur le nombre d'échecs : cinq minutes sans une seule
     * remise, c'est une installation à l'arrêt (sauvegarde, mise à jour,
     * Apache tombé), pas un incident réseau.
     */
    public function isDead() {
        return (time() - $this->lastSuccess) > self::DEAD_AFTER;
    }

    public function failures()   { return $this->failures; }
    public function pending()    { return count($this->queue) + count($this->inFlight); }
    public function dropped()    { return $this->dropped; }
    public function silentFor()  { return time() - $this->lastSuccess; }

    public function close() {
        if ($this->handle !== null) {
            curl_multi_remove_handle($this->multi, $this->handle);
            curl_close($this->handle);
            $this->handle = null;
        }
        if ($this->multi !== null) {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    private function url() {
        return $this->callback
             . (strpos($this->callback, '?') === false ? '?' : '&')
             . 'apikey=' . urlencode($this->apikey)
             . '&uid=' . urlencode($this->uid);
    }
}
