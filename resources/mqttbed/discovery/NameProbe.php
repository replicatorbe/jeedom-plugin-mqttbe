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

require_once __DIR__ . '/DeviceModel.php';

/* =============================================================================
 * L'EXÉCUTION DES SONDES DE NOM.
 *
 * Un appareil porte deux noms : celui que le protocole donne — « Shelly 1
 * 55670C », technique et unique — et celui que l'utilisateur lui a donné dans
 * l'application du constructeur : « chaudiere ». Le second ne circule pas
 * toujours sur MQTT. Quand il faut aller le chercher, l'adapter décrit COMMENT,
 * dans `meta.probe`, et ce fichier l'exécute.
 *
 * CE FICHIER NE CONNAÎT AUCUN CONSTRUCTEUR. Il ne sait pas ce qu'est un Shelly,
 * il sait faire une requête HTTP et lire un chemin dans du JSON. Un adapter qui
 * connaît déjà le nom (Tasmota le porte dans `dn`, Zigbee2MQTT dans
 * `friendly_name`, Home Assistant dans `dev.name`) ne déclare pas de sonde, et
 * rien ici ne change le jour où il arrive.
 *
 * QUATRE SOINS, ET CHACUN RÉPARE UNE PANNE CONSTATÉE
 *
 * 1. RIEN NE BLOQUE LA BOUCLE. Une requête HTTP vers un appareil éteint attend
 *    jusqu'au délai d'expiration. Vingt-deux appareils sondés en séquence
 *    bloquante, ce sont vingt-deux fois ce délai pendant lesquels le démon ne
 *    lit plus la socket du broker : le keepalive MQTT expire, le broker coupe,
 *    et plus un message n'est routé. Les requêtes passent donc par curl_multi,
 *    lancées ici et relues au tour suivant — exactement comme MqttbeJeedomLink
 *    le fait pour ses envois. AUCUNE MÉTHODE DE CE FICHIER N'ATTEND UNE RÉPONSE,
 *    submit() moins que toute autre : elle est appelée sur le chemin d'un
 *    message.
 *
 * 2. ON NE FRAPPE PAS DEUX FOIS À LA MÊME PORTE CLOSE. Un appareil éteint, ou
 *    dont l'API locale est fermée, ne doit pas être interrogé à chaque tour :
 *    recul progressif, puis abandon jusqu'à la prochaine relance de découverte.
 *    Sans cela, un seul appareil débranché produit une requête par seconde,
 *    pour toujours.
 *
 * 3. UN NOM OBTENU NE SE PERD PAS. Une sonde qui échoue après coup ne remplace
 *    jamais le nom connu par du vide : l'appareil était simplement éteint au
 *    moment où l'on repassait, et son équipement doit garder le nom qu'il porte
 *    depuis des mois.
 *
 * 4. LE NOM VIENT DU RÉSEAU. Il est borné et nettoyé ici, à la source, par
 *    MqttbeDeviceModel::cleanDeviceName() — la même règle des deux côtés, et
 *    écrite à un seul endroit.
 *
 * Aucune référence à Jeedom : ce fichier est chargé par le démon, qui tourne
 * sans core.inc.php.
 * ========================================================================== */
class MqttbeNameProbe {

    /* Le seul type exécuté aujourd'hui. Le champ `type` de la sonde existe
     * précisément pour que `mqtt.rpc` (Shelly Gen2+, Sys.GetConfig) s'ajoute un
     * jour sans toucher au reste : une sonde d'un type inconnu est ignorée, en
     * le disant, et l'appareil est découvert sans son nom d'usage plutôt que
     * pas du tout. */
    const TYPE_HTTP = 'http.json';

    /* Ce sont des appareils du réseau local : au-delà de quatre secondes, ils
     * ne répondront pas. Des délais longs ne rattraperaient rien et tiendraient
     * des connexions ouvertes pendant que le parc se découvre. */
    const CONNECT_TIMEOUT = 2;
    const TIMEOUT         = 4;

    /* Requêtes simultanées. Quatre : assez pour qu'un parc de vingt-deux
     * appareils soit sondé en quelques secondes, assez peu pour ne pas ouvrir
     * vingt-deux connexions d'un coup depuis une box qui fait déjà tourner
     * Jeedom, son serveur web et sa base. */
    const MAX_INFLIGHT = 4;

    /* Sondes suivies. Une par appareil à sonder : un parc domestique en compte
     * quelques dizaines. Ce plafond n'est atteint que par un adapter qui
     * fabriquerait une sonde par message vu, et c'est justement ce qu'il faut
     * attraper avant que le démon n'enfle toute la nuit. */
    const MAX_ENTRIES = 512;

    /* Recul progressif entre deux tentatives, en secondes, puis abandon. Une
     * première tentative, puis trois reprises ainsi espacées : cela couvre
     * l'appareil qui redémarre (30 s), celui qui attend son bail DHCP (2 min)
     * et celui qui revient après une coupure de courant (10 min). Quatre
     * requêtes en douze minutes, et plus rien. Au-delà, l'appareil est éteint ou
     * son API est fermée — insister ne rapporterait rien et coûterait, sur un
     * parc dont trois appareils sont débranchés, trois requêtes par seconde
     * jusqu'à la fin des temps. */
    const BACKOFF = array(30, 120, 600);

    /* Corps de réponse retenu. Un /settings de Shelly pèse deux kilo-octets ;
     * 64 Ko laissent dix fois la marge et empêchent qu'un appareil fautif — ou
     * ce qui se fait passer pour lui — ne fasse avaler un flux sans fin au
     * démon. */
    const MAX_BODY = 65536;

    /* Espacement des plaintes répétées, comme dans le moteur. */
    const WARN_PERIOD = 300;

    private $enabled = true;
    private $fetcher = null;
    private $logHandler = null;
    private $clock = null;

    /*
     * clé => array(
     *   'probe'    => la sonde normalisée,
     *   'name'     => le dernier nom obtenu ('' = jamais obtenu),
     *   'at'       => instant de cette obtention,
     *   'attempts' => échecs consécutifs,
     *   'next'     => pas de nouvelle tentative avant cet instant,
     *   'inflight' => requête en cours,
     *   'over'     => abandonnée jusqu'à la prochaine relance)
     */
    private $entries = array();

    /* Clés dont le nom vient de changer, en attente d'être relevées par le
     * moteur. C'est ce qui déclenche la RÉÉMISSION du modèle : sans elle, le
     * nom obtenu trois minutes après la découverte ne serait jamais appliqué. */
    private $changed = array();

    private $started   = 0;
    private $succeeded = 0;
    private $failed    = 0;
    private $refused   = 0;

    private $warnLast = array();

    public function __construct($_fetcher = null) {
        $this->fetcher = $_fetcher;
    }

    /* ==========================================================================
     * BRANCHEMENTS
     * ======================================================================= */

    /*
     * L'exécuteur HTTP. Branché pour la même raison que tout le reste du démon
     * l'est : les contrôles hors ligne y mettent un exécuteur de papier et
     * éprouvent EXACTEMENT le code qui tourne en production, sans réseau et
     * sans attendre un délai d'expiration par contrôle.
     */
    public function useFetcher($_fetcher) {
        $this->fetcher = $_fetcher;
    }

    public function onLog($_handler) {
        $this->logHandler = $_handler;
    }

    public function useClock($_handler) {
        $this->clock = $_handler;
    }

    public function now() {
        if ($this->clock !== null) {
            return (float) call_user_func($this->clock);
        }
        return microtime(true);
    }

    /*
     * Le réglage `discovery::probeNames`. Coupé, aucune requête n'est lancée :
     * certains n'aiment pas que Jeedom aille frapper aux portes de leur réseau,
     * et il faut respecter cela. Ce qui a déjà été obtenu n'est pas oublié pour
     * autant — couper les sondes ne doit pas faire perdre les noms connus.
     */
    public function enable($_actif) {
        $actif = (bool) $_actif;
        if ($actif === $this->enabled) {
            return;
        }
        $this->enabled = $actif;
        if (!$actif) {
            $this->abortAll();
        }
    }

    public function isEnabled() {
        return $this->enabled;
    }

    /* ==========================================================================
     * CE QUE LE MOTEUR APPELLE
     * ======================================================================= */

    /*
     * Prendre en charge une sonde, et rendre sa clé.
     *
     * NE FAIT AUCUNE REQUÊTE : elle est appelée sur le chemin d'un message de
     * découverte, et une requête ici bloquerait la boucle. Elle inscrit, et
     * c'est tick() qui exécute, au tour suivant.
     *
     * @return string la clé de la sonde, ou '' si elle n'est pas exécutable.
     */
    public function submit($_sonde) {
        if (!$this->enabled) {
            return '';
        }
        $sonde = MqttbeDeviceModel::normalizeProbe($_sonde);
        if (empty($sonde)) {
            return '';
        }
        if ($sonde['type'] !== self::TYPE_HTTP) {
            $this->throttled('type|' . $sonde['type'], 'warning',
                'sonde de nom : type « ' . $sonde['type'] . ' » inconnu de ce plugin, ignoré — '
              . 'l\'appareil sera découvert sans le nom que lui a donné son propriétaire.');
            return '';
        }
        if (!$this->isUsableUrl(isset($sonde['url']) ? $sonde['url'] : '')) {
            $this->throttled('url|' . (isset($sonde['url']) ? $sonde['url'] : ''), 'warning',
                'sonde de nom : adresse refusée « ' . $this->citation(isset($sonde['url']) ? $sonde['url'] : '')
              . ' » — seules http:// et https:// sont exécutées, et l\'adresse vient d\'un adapter '
              . 'qui l\'a composée à partir de ce que l\'appareil a annoncé.');
            return '';
        }

        $cle = self::key($sonde);
        if (isset($this->entries[$cle])) {
            /* La sonde peut avoir changé de ttl sans changer de clé. */
            $this->entries[$cle]['probe'] = $sonde;
            return $cle;
        }
        if (count($this->entries) >= self::MAX_ENTRIES) {
            /*
             * Avant de refuser, faire de la place.
             *
             * Une entrée n'était jamais retirée : chaque nouveau bail DHCP
             * change l'URL, donc la clé, et laisse une entrée morte derrière
             * lui. Un parc qui tourne atteignait le plafond en quelques mois, et
             * plus AUCUN appareil n'obtenait son nom — avec une plainte espacée
             * de cinq minutes, donc une installation muette sur la cause.
             *
             * On sacrifie d'abord les sondes abandonnées, puis les plus
             * anciennement interrogées : une entrée qui a rendu un nom
             * récemment est celle qui sert.
             */
            $this->makeRoom();
        }
        if (count($this->entries) >= self::MAX_ENTRIES) {
            $this->refused++;
            $this->throttled('plafond', 'warning',
                'sonde de nom : plafond de ' . self::MAX_ENTRIES . ' sondes atteint, « '
              . $this->citation($sonde['url']) . ' » refusée — un adapter qui déclare une sonde '
              . 'par message vu plutôt que par appareil ferait enfler le démon sans fin.');
            return '';
        }
        $this->entries[$cle] = array(
            'probe'    => $sonde,
            'name'     => '',
            'at'       => 0.0,
            'attempts' => 0,
            'next'     => 0.0,
            'inflight' => false,
            'over'     => false,
        );
        return $cle;
    }

    /**
     * Libère un cinquième du plafond, des entrées les moins utiles aux plus.
     *
     * Ni les sondes en vol ni celles qui portent un nom obtenu récemment ne sont
     * touchées : perdre un nom acquis pour faire de la place serait échanger un
     * problème contre un autre.
     */
    private function makeRoom() {
        $cible = (int) ceil(self::MAX_ENTRIES / 5);
        $libere = 0;

        /* D'abord les abandonnées : elles ne rendront rien tant qu'une relance
         * de découverte ne les réarme pas, et une relance les recréera. */
        foreach ($this->entries as $cle => $entree) {
            if ($libere >= $cible) {
                break;
            }
            if (!empty($entree['over']) && empty($entree['inflight'])) {
                unset($this->entries[$cle]);
                $libere++;
            }
        }
        if ($libere >= $cible) {
            return;
        }

        /* Puis les plus anciennement interrogées, sans nom connu. */
        $candidats = array();
        foreach ($this->entries as $cle => $entree) {
            if (!empty($entree['inflight']) || $entree['name'] !== '') {
                continue;
            }
            $candidats[$cle] = $entree['at'];
        }
        asort($candidats);
        foreach (array_keys($candidats) as $cle) {
            if ($libere >= $cible) {
                break;
            }
            unset($this->entries[$cle]);
            $libere++;
        }
        if ($libere > 0) {
            $this->throttled('purge', 'info',
                'sonde de nom : ' . $libere . ' entrée(s) libérée(s) pour faire de la place — '
              . 'sondes abandonnées ou jamais abouties.');
        }
    }

    /* Le nom connu pour cette clé, ou '' — jamais null : « pas encore obtenu »
     * et « obtenu vide » se traitent de la même façon, et distinguer les deux
     * n'apporterait qu'une question de plus à chaque appel. */
    public function name($_cle) {
        return isset($this->entries[$_cle]) ? $this->entries[$_cle]['name'] : '';
    }

    public function knows($_cle) {
        return isset($this->entries[$_cle]) && $this->entries[$_cle]['name'] !== '';
    }

    /*
     * Les clés dont le nom vient de changer, et qui sont relevées par le même
     * geste : le moteur les prend une fois, réémet les modèles concernés, et ne
     * les reverra pas au tour suivant.
     */
    public function drainChanged() {
        $liste = array_keys($this->changed);
        $this->changed = array();
        return $liste;
    }

    /*
     * Une relance de découverte. Les abandons sont levés et les résultats
     * redemandés : « relancer la découverte » ne veut rien dire d'autre que
     * « redis-moi tout », et c'est le geste de celui qui vient justement de
     * renommer son appareil dans l'application du constructeur.
     *
     * Les noms connus, eux, sont conservés jusqu'à ce qu'une réponse en apporte
     * d'autres : une relance faite pendant que le parc est éteint ne doit pas
     * faire perdre ce qui était su.
     */
    public function rescan() {
        foreach ($this->entries as $cle => $entree) {
            $this->entries[$cle]['attempts'] = 0;
            $this->entries[$cle]['next']     = 0.0;
            $this->entries[$cle]['over']     = false;
            $this->entries[$cle]['at']       = 0.0;
        }
    }

    /*
     * Un tour d'horloge. Appelée à CHAQUE tour de la boucle du démon, et non
     * une fois par seconde : curl_multi n'avance que lorsqu'on le relance, et
     * une réponse arrivée reste dans le tampon du noyau tant que personne ne la
     * lit. Elle ne coûte rien quand il n'y a rien en vol.
     */
    public function tick() {
        $this->collect();
        if (!$this->enabled) {
            return;
        }
        $this->launch();
    }

    /* Ce qui est en vol est abandonné : à l'arrêt du démon, et quand
     * l'utilisateur coupe les sondes. */
    public function close() {
        $this->abortAll();
    }

    public function stats() {
        $connus = 0;
        $abandons = 0;
        foreach ($this->entries as $entree) {
            if ($entree['name'] !== '') {
                $connus++;
            }
            if ($entree['over']) {
                $abandons++;
            }
        }
        return array(
            'enabled'   => $this->enabled,
            'probes'    => count($this->entries),
            'known'     => $connus,
            'given_up'  => $abandons,
            'started'   => $this->started,
            'succeeded' => $this->succeeded,
            'failed'    => $this->failed,
            'refused'   => $this->refused,
        );
    }

    /* ==========================================================================
     * EXÉCUTION
     * ======================================================================= */

    /* Les réponses arrivées depuis le tour précédent. */
    private function collect() {
        $fetcher = $this->fetcher();
        if ($fetcher === null) {
            return;
        }
        $resultats = $fetcher->poll();
        if (!is_array($resultats) || empty($resultats)) {
            return;
        }
        foreach ($resultats as $resultat) {
            $cle = isset($resultat['key']) ? (string) $resultat['key'] : '';
            if ($cle === '' || !isset($this->entries[$cle])) {
                continue;
            }
            $this->entries[$cle]['inflight'] = false;
            if (empty($resultat['ok'])) {
                $this->fail($cle, isset($resultat['error']) ? (string) $resultat['error']
                                 : ('HTTP ' . (isset($resultat['code']) ? (int) $resultat['code'] : 0)));
                continue;
            }
            $this->succeed($cle, isset($resultat['body']) ? (string) $resultat['body'] : '');
        }
    }

    /* Ce qui est dû, dans la limite des requêtes simultanées. */
    private function launch() {
        $fetcher = $this->fetcher();
        if ($fetcher === null) {
            return;
        }
        $maintenant = $this->now();
        $places = self::MAX_INFLIGHT - (int) $fetcher->busy();
        if ($places <= 0) {
            return;
        }
        foreach ($this->entries as $cle => $entree) {
            if ($places <= 0) {
                return;
            }
            if ($entree['inflight'] || $entree['over'] || $maintenant < $entree['next']) {
                continue;
            }
            /* Un nom déjà obtenu n'est redemandé qu'à l'expiration de sa durée
             * de validité : un nom ne change qu'au jour où quelqu'un renomme
             * son appareil, et redemander à chaque découverte ferait frapper à
             * vingt-deux portes à chaque redémarrage du démon. */
            if ($entree['name'] !== '' && ($maintenant - $entree['at']) < $entree['probe']['ttl']) {
                continue;
            }
            if (!$fetcher->start($cle, $entree['probe']['url'],
                                 self::CONNECT_TIMEOUT, self::TIMEOUT)) {
                continue;
            }
            $this->entries[$cle]['inflight'] = true;
            $this->started++;
            $places--;
        }
    }

    private function succeed($_cle, $_corps) {
        $entree = $this->entries[$_cle];
        $chemin = isset($entree['probe']['path']) ? $entree['probe']['path'] : '';
        $decode = json_decode($_corps, true);
        $brut   = is_array($decode) ? self::valueAt($decode, $chemin) : null;
        /* Seule une chaîne est un nom : voir cleanDeviceName(). */
        $nom    = MqttbeDeviceModel::cleanDeviceName(is_string($brut) ? $brut : null);

        if ($nom === '') {
            /*
             * Réponse reçue, mais rien d'utilisable : l'appareil n'a pas été
             * nommé, ou son firmware ne porte pas ce champ. Ce n'est pas une
             * panne — on ne réessaiera pas en boucle pour autant — et SURTOUT
             * cela n'efface pas un nom déjà connu.
             */
            $this->entries[$_cle]['attempts'] = 0;
            $this->entries[$_cle]['next']     = $this->now() + $entree['probe']['ttl'];
            $this->log('debug', 'sonde de nom : ' . $this->citation($entree['probe']['url'])
                . ' a répondu, mais « ' . $chemin . ' » n\'y porte aucun nom exploitable.');
            return;
        }

        $this->succeeded++;
        $change = ($nom !== $entree['name']);
        $this->entries[$_cle]['name']     = $nom;
        $this->entries[$_cle]['at']       = $this->now();
        $this->entries[$_cle]['attempts'] = 0;
        $this->entries[$_cle]['next']     = 0.0;
        $this->entries[$_cle]['over']     = false;
        if ($change) {
            /* Ce qui déclenchera la réémission du modèle vers Jeedom. */
            $this->changed[$_cle] = true;
            $this->log('info', 'sonde de nom : ' . $this->citation($entree['probe']['url'])
                . ' se nomme « ' . $nom .' ».');
        }
    }

    private function fail($_cle, $_raison) {
        $this->failed++;
        $entree   = $this->entries[$_cle];
        $essais   = (int) $entree['attempts'] + 1;
        $this->entries[$_cle]['attempts'] = $essais;

        if ($essais > count(self::BACKOFF)) {
            /*
             * Abandon jusqu'à la prochaine relance de découverte. L'appareil est
             * éteint, ou son API locale est fermée par un mot de passe : une
             * quatrième tentative ne rapporterait rien, et retenter à chaque
             * tour de boucle produirait une requête par seconde pour toujours.
             */
            $this->entries[$_cle]['over'] = true;
            $this->log($entree['name'] === '' ? 'info' : 'debug',
                'sonde de nom : ' . $this->citation($entree['probe']['url']) . ' ne répond pas ('
              . $this->citation($_raison) . ') après ' . $essais . ' tentatives — abandon jusqu\'à '
              . 'la prochaine relance de la découverte'
              . ($entree['name'] === '' ? '. L\'appareil garde son nom technique.'
                                        : ', le nom déjà connu est conservé.'));
            return;
        }
        $delai = self::BACKOFF[$essais - 1];
        $this->entries[$_cle]['next'] = $this->now() + $delai;
        $this->log('debug', 'sonde de nom : ' . $this->citation($entree['probe']['url'])
            . ' en échec (' . $this->citation($_raison) . '), nouvelle tentative dans ' . $delai . ' s.');
    }

    private function abortAll() {
        foreach ($this->entries as $cle => $entree) {
            $this->entries[$cle]['inflight'] = false;
        }
        if ($this->fetcher !== null) {
            $this->fetcher->close();
        }
    }

    /*
     * L'exécuteur, posé au premier besoin.
     *
     * Sans l'extension curl — elle manque sur certaines installations minimales
     * — les sondes ne s'exécutent pas, et c'est tout : le parc est découvert
     * avec ses noms techniques, comme avant cette fonctionnalité.
     */
    private function fetcher() {
        if ($this->fetcher !== null) {
            return $this->fetcher;
        }
        if (!function_exists('curl_multi_init')) {
            $this->throttled('curl', 'warning',
                'sonde de nom : l\'extension curl manque, les noms donnés aux appareils ne '
              . 'peuvent pas être lus — les équipements garderont leur nom technique.');
            return null;
        }
        $this->fetcher = new MqttbeProbeCurlFetcher();
        return $this->fetcher;
    }

    /* ==========================================================================
     * OUTILS
     * ======================================================================= */

    /*
     * La clé d'une sonde : ce qui la rend unique, et rien d'autre.
     *
     * Le ttl n'y entre pas — deux adapters qui décriraient la même requête avec
     * deux durées de validité interrogeraient sinon deux fois le même appareil.
     * Une empreinte plutôt que l'adresse en clair : c'est une clé de tableau,
     * et une adresse venue du réseau peut faire n'importe quelle longueur.
     */
    public static function key($_sonde) {
        $sonde = MqttbeDeviceModel::normalizeProbe($_sonde);
        if (empty($sonde)) {
            return '';
        }
        unset($sonde['ttl']);
        return sha1(json_encode($sonde, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /*
     * Une adresse exécutable.
     *
     * Le contrôle du schéma n'est pas une formalité : cette adresse est composée
     * par un adapter à partir de ce qu'un appareil a ANNONCÉ sur un topic public.
     * Sans ce filtre, une annonce bien choisie ferait lire à curl un fichier
     * local (file://) ou ouvrir une connexion vers ce que le protocole voudrait.
     */
    private function isUsableUrl($_url) {
        $url = trim((string) $_url);
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        /*
         * L'hôte doit être une ADRESSE IP littérale, et rien d'autre.
         *
         * Cette URL est composée par un adapter à partir de ce qu'un appareil a
         * annoncé sur le broker — et n'importe qui pouvant publier sur le topic
         * d'annonce peut donc la choisir. Sans ce contrôle, le démon acceptait
         * `attaquant.example.com`, `127.0.0.1:8080/admin`, `192.168.0.5:22` ou
         * `1.2.3.4@evil.tld` : une requête sortante arbitraire depuis
         * l'intérieur du réseau, renouvelée chaque jour, dont la réponse devient
         * le nom d'un équipement.
         *
         * Un nom d'hôte est refusé : un appareil du réseau local s'annonce par
         * son adresse, et refuser les noms supprime d'un coup la résolution DNS
         * comme canal de sortie.
         */
        $parties = parse_url($url);
        if (!is_array($parties) || !isset($parties['host'])) {
            return false;
        }
        /* Un « user:pass@ » déguise l'hôte réel : parse_url le sépare, mais un
         * lecteur humain — et certaines bibliothèques — s'y trompent. */
        if (isset($parties['user']) || isset($parties['pass'])) {
            return false;
        }
        $hote = trim($parties['host'], '[]');
        if ($hote === '' || filter_var($hote, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        /* Le bouclage local n'est jamais un appareil à interroger : c'est
         * Jeedom lui-même, et cibler ses propres services depuis une annonce
         * MQTT n'a aucune raison d'être. */
        if (filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        /*
         * Et un port d'usage web, sinon rien.
         *
         * Aucun adapter ne compose de port : ils interrogent tous la page d'un
         * appareil. Laisser passer un port arbitraire offrirait un balayage du
         * réseau local à qui sait publier une annonce — « est-ce que le port 22
         * de cette machine répond ? » se lit dans le journal du démon.
         */
        if (isset($parties['port']) && !in_array((int) $parties['port'], array(80, 443, 8080), true)) {
            return false;
        }
        return true;
    }

    /*
     * Un chemin par points dans une structure décodée — « name »,
     * « device.name » — comme partout ailleurs dans le plugin.
     */
    public static function valueAt($_donnees, $_chemin) {
        $chemin = trim((string) $_chemin);
        if ($chemin === '') {
            return null;
        }
        $courant = $_donnees;
        foreach (explode('.', $chemin) as $segment) {
            if (!is_array($courant) || !array_key_exists($segment, $courant)) {
                return null;
            }
            $courant = $courant[$segment];
        }
        return $courant;
    }

    private function log($_niveau, $_message) {
        if ($this->logHandler === null) {
            return;
        }
        call_user_func($this->logHandler, $_niveau, $_message);
    }

    /* Une plainte, puis une au plus toutes les cinq minutes : vingt-deux
     * appareils dont l'API est fermée produiraient sinon vingt-deux lignes
     * identiques à chaque relance. */
    private function throttled($_motif, $_niveau, $_message) {
        $maintenant = $this->now();
        if (isset($this->warnLast[$_motif]) && ($maintenant - $this->warnLast[$_motif]) < self::WARN_PERIOD) {
            return;
        }
        $this->warnLast[$_motif] = $maintenant;
        $this->log($_niveau, $_message);
    }

    /* Une valeur venue du réseau, rendue citable dans une ligne de journal. */
    private function citation($_valeur) {
        $texte = preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $_valeur);
        return (strlen($texte) > 96) ? substr($texte, 0, 96) . '…' : $texte;
    }
}

/* =============================================================================
 * L'exécuteur HTTP réel : curl_multi, et rien qui attende.
 *
 * Même mécanique que MqttbeJeedomLink : les transferts sont amorcés puis
 * relancés à chaque tour de boucle, et aucun appel n'attend une réponse. Un
 * curl_exec() synchrone à la place de ceci coûterait, sur un parc où la moitié
 * des appareils sont éteints, plusieurs secondes sans lecture de la socket du
 * broker — c'est-à-dire une session MQTT coupée par le keepalive.
 * ========================================================================== */
class MqttbeProbeCurlFetcher {

    private $multi   = null;
    private $handles = array();   // clé => array('ch' => resource, 'body' => string)

    public function busy() {
        return count($this->handles);
    }

    public function start($_cle, $_url, $_connectTimeout, $_timeout) {
        if (isset($this->handles[$_cle])) {
            return false;
        }
        if ($this->multi === null) {
            $this->multi = curl_multi_init();
        }
        $ch = curl_init($_url);
        if ($ch === false) {
            return false;
        }
        $this->handles[$_cle] = array('ch' => $ch, 'body' => '');
        $cle = $_cle;
        curl_setopt_array($ch, array(
            CURLOPT_CONNECTTIMEOUT => (int) $_connectTimeout,
            CURLOPT_TIMEOUT        => (int) $_timeout,
            /* Aucune redirection suivie : l'adresse a été contrôlée, une
             * redirection ne le serait pas — et un appareil qui répond par un
             * 302 vers ailleurs n'est pas celui qu'on interroge. */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => array('Accept: application/json'),
            /* Le corps est accumulé par un rappel plutôt que rendu d'un bloc :
             * c'est ce qui permet de couper net au-delà de MAX_BODY, sans
             * dépendre d'un Content-Length que l'appareil n'est pas obligé
             * d'annoncer. */
            CURLOPT_WRITEFUNCTION  => function ($_ch, $_morceau) use ($cle) {
                if (!isset($this->handles[$cle])) {
                    return 0;
                }
                $this->handles[$cle]['body'] .= $_morceau;
                if (strlen($this->handles[$cle]['body']) > MqttbeNameProbe::MAX_BODY) {
                    return 0;   /* curl interrompt le transfert */
                }
                return strlen($_morceau);
            },
        ));
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_multi_add_handle($this->multi, $ch);
        /* Amorce : sans ce premier tour, rien n'est écrit sur le réseau. */
        $this->pump();
        return true;
    }

    public function poll() {
        if ($this->multi === null || empty($this->handles)) {
            return array();
        }
        $this->pump();

        $resultats = array();
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }
            $ch  = $info['handle'];
            $cle = $this->cleDe($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $corps = ($cle === null) ? '' : $this->handles[$cle]['body'];
            $erreur = ($info['result'] !== CURLE_OK) ? curl_error($ch) : '';
            curl_multi_remove_handle($this->multi, $ch);
            curl_close($ch);
            if ($cle !== null) {
                unset($this->handles[$cle]);
                $resultats[] = array(
                    'key'   => $cle,
                    /* 200 seulement : un 401 est un appareil dont l'API est
                     * protégée par un mot de passe, un 404 un firmware qui ne
                     * connaît pas cette route — les deux sont des échecs, et
                     * leur corps n'est pas un nom. */
                    'ok'    => ($erreur === '' && $code === 200),
                    'code'  => $code,
                    'body'  => $corps,
                    'error' => $erreur,
                );
            }
        }
        return $resultats;
    }

    public function close() {
        foreach ($this->handles as $cle => $entree) {
            curl_multi_remove_handle($this->multi, $entree['ch']);
            curl_close($entree['ch']);
        }
        $this->handles = array();
        if ($this->multi !== null) {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    private function pump() {
        $actives = 0;
        do {
            $etat = curl_multi_exec($this->multi, $actives);
        } while ($etat === CURLM_CALL_MULTI_PERFORM);
    }

    private function cleDe($_ch) {
        foreach ($this->handles as $cle => $entree) {
            if ($entree['ch'] === $_ch) {
                return $cle;
            }
        }
        return null;
    }
}
