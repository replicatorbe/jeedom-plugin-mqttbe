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
 * Le chemin chaud : topic → valeur → commande Jeedom.
 *
 * C'est l'inversion qui fait tout l'intérêt du plugin. Jeedom n'interprète
 * rien : il pousse ici une table (message `routing`, CONTRAT-J2 §4) et ne
 * reçoit en retour que des couples (identifiant de commande, valeur) déjà
 * résolus. Toute la traversée d'un message — résolution des cibles, lecture
 * du JSON, transformation, politique de répétition — tient dans route(), qui
 * est appelée une fois par message MQTT et rien qu'une.
 *
 * Trois principes gouvernent ce fichier, et expliquent la plupart de ses
 * choix d'écriture :
 *
 * 1. TOUT CE QUI PEUT ÊTRE CALCULÉ UNE FOIS L'EST À L'ARRIVÉE DE LA TABLE.
 *    Les filtres sont découpés, les chemins JSON éclatés en segments, les
 *    transformations réduites à des nombres, les cibles mises à plat dans un
 *    tableau indexé par entier. À la réception d'un message il ne reste qu'à
 *    consulter des tables de hachage et à boucler sur des entiers. Aucun
 *    preg_match n'est exécuté par message : la correspondance de topics est
 *    une comparaison de chaînes segment par segment, et l'essentiel du trafic
 *    ne fait même pas cela (voir le cache de résolution).
 *
 * 2. LA MÉMOIRE EST BORNÉE PAR LA TABLE, JAMAIS PAR LE TRAFIC. Les états de
 *    répétition sont tenus PAR COMMANDE — un ensemble fini, connu, qui vient
 *    de Jeedom — et non par topic vu, qui est infini : il suffit d'un appareil
 *    bavard publiant sur `.../<horodatage>` pour qu'un index par topic mange
 *    toute la mémoire de la box en une nuit. Le seul index réellement alimenté
 *    par le trafic est le cache de résolution, et il est plafonné.
 *
 * 3. UN MESSAGE FAUTIF N'EST PAS UNE ERREUR. Une charge utile qui n'est pas du
 *    JSON alors qu'un chemin est demandé, un chemin absent, une valeur qui
 *    n'est pas un scalaire : ce sont des valeurs qu'on n'émet pas et des
 *    compteurs qui montent, pas des exceptions. Un capteur qui publie mal ne
 *    doit pas remplir le journal ni arrêter le démon.
 * ========================================================================== */
class MqttbeRouter {

    /* Plafond du cache de résolution. Une installation domestique tourne
     * autour de quelques centaines de topics distincts ; ce plafond n'est
     * atteint que par un parc qui publie sur des topics engendrés. */
    const CACHE_MAX = 4096;

    /* Fenêtre glissante des mesures de latence. La médiane se calcule sur les
     * derniers messages, pas depuis le démarrage : une pointe d'il y a six
     * heures n'apprend rien sur l'état actuel du démon. */
    const LAT_SAMPLES = 512;

    private $config;

    /* Destinataire des valeurs résolues : ($cmdId, $value, $ts). Le routeur
     * ignore délibérément ce qu'il y a au bout — la liaison Jeedom en
     * production, un tableau en banc d'essai. */
    private $valueHandler = null;

    /* Version de la table appliquée. -1 et non 0 : une première table peut
     * légitimement porter le numéro 0, et elle doit être acceptée. */
    private $version = -1;

    /* ---- index, reconstruit d'un bloc à chaque table ---------------------
     *
     * $targets  : toutes les cibles à plat, indexées par entier. Ce sont ces
     *             entiers qui circulent partout ailleurs — un entier se copie
     *             et se compare pour rien, un tableau associatif non.
     * $exact    : topic littéral => liste d'indices de cibles. La très grande
     *             majorité du trafic se résout par ce seul isset().
     * $filters  : filtres à joker, déjà découpés en segments.
     * $byHead   : premier segment littéral d'un filtre => indices de filtres.
     *             C'est le tri qui rend les jokers négligeables : un message
     *             de `shellies/...` ne se compare qu'aux filtres commençant
     *             par `shellies`, et ignore les autres sans les regarder.
     * $anyHead  : filtres commençant eux-mêmes par un joker (`+/...`, `#`),
     *             seuls à devoir être essayés sur tout.
     */
    private $targets = array();
    private $exact   = array();
    private $filters = array();
    private $byHead  = array();
    private $anyHead = array();

    /* Abonnements déduits de la table : topic => qos. */
    private $subs = array();

    /* Cache de résolution topic → indices de cibles, y compris la réponse
     * vide : un topic bavard sans cible ne doit pas coûter un parcours des
     * filtres à chaque message. */
    private $cache      = array();
    private $cacheCount = 0;

    /* État de répétition, PAR COMMANDE (voir principe 2 en tête de fichier). */
    private $lastValue = array();
    private $lastSent  = array();

    private $received   = 0;
    private $routed     = 0;
    private $ignored    = 0;
    private $noTarget   = 0;
    private $badPayload = 0;
    private $parsed     = 0;   // décodages JSON réellement effectués
    private $errors     = 0;

    /* Tampon circulaire des latences, en secondes. */
    private $lat      = array();
    private $latPos   = 0;
    private $latCount = 0;

    public function __construct(MqttbeConfig $_config) {
        $this->config = $_config;
    }

    /* Même convention que MqttbeTransport::onMessage : le routeur ne connaît
     * pas son destinataire, il l'appelle. */
    public function onValue($_handler) {
        $this->valueHandler = $_handler;
    }

    /* ==========================================================================
     * LA TABLE
     * ======================================================================= */

    /*
     * Applique une table reçue de Jeedom, et dit ce qu'elle a changé.
     *
     * La table est reconstruite d'un bloc plutôt que modifiée : une table de
     * trois cents commandes se compile en une fraction de milliseconde, alors
     * qu'une mise à jour incrémentale devrait réconcilier les index, le cache
     * et les abonnements, avec un risque d'incohérence permanent pour un gain
     * qui n'existe pas.
     */
    public function apply($_order) {
        if (!is_array($_order)) {
            return array('applied' => false, 'message' => 'table illisible');
        }

        /*
         * Le garde-fou de version. Jeedom peut pousser deux fois la même
         * table (cron de reprise, enregistrement d'une page, redémarrage du
         * démon) et deux tables peuvent se croiser sur le réseau local :
         * appliquer la plus ancienne, c'est perdre silencieusement les
         * commandes créées entre les deux.
         */
        $version = isset($_order['version']) ? (int) $_order['version'] : 0;
        if ($version <= $this->version) {
            MqttbeLog::debug('table de routage ' . $version . ' ignorée (version ' . $this->version . ' déjà appliquée)');
            return array('applied' => false, 'version' => $this->version,
                         'message' => 'version ' . $version . ' ignorée, ' . $this->version . ' est appliquée');
        }

        $entries = (isset($_order['entries']) && is_array($_order['entries']))
            ? $_order['entries'] : array();

        $targets = array();
        $exact   = array();
        $filters = array();
        $byHead  = array();
        $anyHead = array();
        $subs    = array();
        $rejected = 0;
        $excluded = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                $rejected++;
                continue;
            }
            $topic = isset($entry['topic']) ? trim((string) $entry['topic']) : '';
            if (!self::isValidFilter($topic)) {
                MqttbeLog::warning('entrée de routage ignorée, filtre de topic invalide : «' . $topic . '»');
                $rejected++;
                continue;
            }
            $list = (isset($entry['targets']) && is_array($entry['targets'])) ? $entry['targets'] : array();

            $indices = array();
            foreach ($list as $raw) {
                $target = self::compileTarget($raw);
                if ($target === null) {
                    $rejected++;
                    continue;
                }
                $targets[] = $target;
                $indices[] = count($targets) - 1;
            }
            if (empty($indices)) {
                continue;
            }

            /*
             * Deux entrées peuvent porter le même topic — c'est même le cas
             * normal d'un appareil qui publie un état complet en JSON, lu par
             * dix commandes. Leurs cibles sont fusionnées, et le JSON ne sera
             * décodé qu'une fois à la réception.
             */
            if (strpbrk($topic, '+#') === false) {
                $exact[$topic] = isset($exact[$topic])
                    ? array_merge($exact[$topic], $indices) : $indices;
            } else {
                $parts = explode('/', $topic);
                $count = count($parts);
                $hash  = ($parts[$count - 1] === '#');
                $head  = $parts[0];
                $key   = ($head === '+' || $head === '#') ? '' : $head;

                /* Un filtre déjà compilé est complété plutôt que dédoublé :
                 * un filtre essayé deux fois par message, c'est deux fois le
                 * travail pour le même résultat. */
                $found = null;
                foreach ($filters as $i => $filter) {
                    if ($filter['topic'] === $topic) {
                        $found = $i;
                        break;
                    }
                }
                if ($found !== null) {
                    $filters[$found]['targets'] = array_merge($filters[$found]['targets'], $indices);
                } else {
                    $filters[] = array('topic' => $topic, 'parts' => $parts, 'n' => $count,
                                       'hash' => $hash, 'targets' => $indices);
                    $index = count($filters) - 1;
                    if ($key === '') {
                        $anyHead[] = $index;
                    } else {
                        $byHead[$key][] = $index;
                    }
                }
            }

            /*
             * L'abonnement se déduit du topic de l'entrée, et seulement de
             * lui : s'abonner à `#` pour tout recevoir et trier ensuite ferait
             * traverser au démon la totalité du trafic du broker — sur une
             * installation avec une passerelle Zigbee, des dizaines de
             * milliers de messages par heure dont pas un ne l'intéresse.
             */
            if ($this->config->isExcluded($topic)) {
                MqttbeLog::warning('topic de routage exclu par la configuration, aucun abonnement : ' . $topic);
                $excluded++;
                continue;
            }
            $subs[$topic] = 0;
        }

        $this->version = $version;
        $this->targets = $targets;
        $this->exact   = $exact;
        $this->filters = $filters;
        $this->byHead  = $byHead;
        $this->anyHead = $anyHead;
        $this->subs    = $subs;

        /* Le cache porte sur l'ancienne table : le garder, c'est router vers
         * des commandes qui n'existent peut-être plus. */
        $this->cache      = array();
        $this->cacheCount = 0;

        $this->pruneRepeatState();

        MqttbeLog::info('table de routage ' . $version . ' appliquée : ' . count($targets)
                      . ' cible(s) sur ' . (count($exact) + count($filters)) . ' topic(s), '
                      . count($subs) . ' abonnement(s)'
                      . ($excluded > 0 ? ', ' . $excluded . ' exclu(s)' : '')
                      . ($rejected > 0 ? ', ' . $rejected . ' entrée(s) rejetée(s)' : ''));

        return array('applied' => true, 'version' => $version, 'targets' => count($targets),
                     'topics' => count($exact) + count($filters), 'subscriptions' => count($subs),
                     'rejected' => $rejected, 'excluded' => $excluded);
    }

    /*
     * Les états de répétition des commandes disparues sont oubliés : sans
     * cela, une table remplacée cent fois laisserait derrière elle cent
     * générations de dernières valeurs. Ceux des commandes qui restent sont
     * gardés, et c'est volontaire — les remettre à zéro à chaque table ferait
     * réémettre tout le parc en `onchange` à chaque enregistrement d'une page
     * de Jeedom.
     */
    private function pruneRepeatState() {
        $alive = array();
        foreach ($this->targets as $target) {
            $alive[$target['cmdId']] = true;
        }
        foreach ($this->lastValue as $cmdId => $ignored) {
            if (!isset($alive[$cmdId])) {
                unset($this->lastValue[$cmdId], $this->lastSent[$cmdId]);
            }
        }
    }

    /*
     * Compile une cible : tout ce qui peut être décidé maintenant l'est
     * maintenant. Rend null si la cible n'a pas de sens — une valeur sans
     * commande où la poser n'est pas routable.
     */
    private static function compileTarget($_raw) {
        if (!is_array($_raw)) {
            return null;
        }
        $cmdId = isset($_raw['cmdId']) ? (int) $_raw['cmdId'] : 0;
        if ($cmdId <= 0) {
            return null;
        }

        /* Sélecteur : le chemin est éclaté ici une fois pour toutes, un
         * explode par message coûterait une allocation par message. */
        $path = null;
        if (isset($_raw['selector']) && is_array($_raw['selector'])) {
            $type = isset($_raw['selector']['type']) ? (string) $_raw['selector']['type'] : 'raw';
            if ($type === 'json') {
                $expr = isset($_raw['selector']['path']) ? trim((string) $_raw['selector']['path']) : '';
                if ($expr !== '') {
                    $path = explode('.', $expr);
                }
            }
        }

        $map = null; $scale = null; $offset = null; $round = null;
        if (isset($_raw['transform']) && is_array($_raw['transform'])) {
            $transform = $_raw['transform'];
            if (isset($transform['map']) && is_array($transform['map']) && !empty($transform['map'])) {
                /* Clés ET valeurs en chaînes : la correspondance se fait sur
                 * la charge utile, qui est toujours du texte, et un 1 entier
                 * venu du JSON ne s'y retrouverait pas. */
                $map = array();
                foreach ($transform['map'] as $from => $to) {
                    if (is_scalar($to) || $to === null) {
                        $map[(string) $from] = ($to === null) ? '' : (string) $to;
                    }
                }
            }
            if (isset($transform['scale']) && is_numeric($transform['scale'])) {
                $scale = (float) $transform['scale'];
            }
            if (isset($transform['offset']) && is_numeric($transform['offset'])) {
                $offset = (float) $transform['offset'];
            }
            if (isset($transform['round']) && is_numeric($transform['round'])) {
                $round = max(0, min(10, (int) $transform['round']));
            }
        }

        $always = false; $keepalive = 300; $minInterval = 0;
        if (isset($_raw['repeat']) && is_array($_raw['repeat'])) {
            $repeat = $_raw['repeat'];
            if (isset($repeat['mode'])) {
                $always = ((string) $repeat['mode'] === 'always');
            }
            if (isset($repeat['keepalive']) && is_numeric($repeat['keepalive'])) {
                $keepalive = max(0, (int) $repeat['keepalive']);
            }
            if (isset($repeat['minInterval']) && is_numeric($repeat['minInterval'])) {
                $minInterval = max(0, (int) $repeat['minInterval']);
            }
        }

        return array(
            'cmdId'       => $cmdId,
            'path'        => $path,
            'map'         => $map,
            'scale'       => $scale,
            'offset'      => $offset,
            'round'       => $round,
            /* Deux raccourcis lus une fois par cible et par message, qui
             * évitent quatre tests dans le cas le plus courant : une valeur
             * brute recopiée telle quelle. */
            'plain'       => ($map === null && $scale === null && $offset === null && $round === null),
            'arith'       => ($scale !== null || $offset !== null || $round !== null),
            'always'      => $always,
            'keepalive'   => $keepalive,
            'minInterval' => $minInterval,
        );
    }

    /*
     * Validité d'un filtre MQTT (OASIS 3.1.1 §4.7.1).
     *
     * Contrôlé à l'arrivée de la table, jamais à la réception d'un message :
     * `sport/a#` ou `sp+rt` sont des filtres illégaux, et les accepter
     * reviendrait à leur inventer une sémantique que le broker, lui, n'aura
     * pas — le démon croirait router un topic auquel il n'est pas abonné.
     */
    private static function isValidFilter($_topic) {
        if ($_topic === '' || strlen($_topic) > 65535) {
            return false;
        }
        if (strpos($_topic, '#') !== false) {
            /* `#` est forcément le dernier caractère, seul dans son segment,
             * et il n'y en a qu'un. */
            if (substr($_topic, -1) !== '#' || substr_count($_topic, '#') > 1) {
                return false;
            }
            if (strlen($_topic) > 1 && substr($_topic, -2, 1) !== '/') {
                return false;
            }
        }
        if (strpos($_topic, '+') !== false) {
            foreach (explode('/', $_topic) as $segment) {
                if (strpos($segment, '+') !== false && $segment !== '+') {
                    return false;
                }
            }
        }
        return true;
    }

    /* ==========================================================================
     * LE CHEMIN CHAUD
     * ======================================================================= */

    /*
     * Un message MQTT. Ne lève jamais, ne journalise rien en marche normale,
     * et n'alloue que ce qui sert : au mieux (topic sans cible déjà vu) elle
     * coûte un isset et un incrément.
     *
     * $_retained est reçu pour mémoire : un état rejoué par le broker est
     * l'état courant de l'appareil, et il se route exactement comme un autre.
     * C'est la découverte, au jalon suivant, qui aura besoin de la nuance.
     */
    public function route($_topic, $_payload, $_qos = 0, $_retained = false) {
        $this->received++;

        $indices = isset($this->cache[$_topic]) ? $this->cache[$_topic] : $this->resolve($_topic);
        if (empty($indices)) {
            $this->noTarget++;
            return;
        }

        $start = microtime(true);
        try {
            $payload = (string) $_payload;

            /* Décodage paresseux et unique : dix commandes qui lisent dix
             * champs du même état complet ne doivent pas coûter dix
             * json_decode — sur un état de Shelly, c'est la dépense
             * dominante du routage. */
            $json    = null;
            $decoded = false;
            $jsonOk  = false;

            foreach ($indices as $index) {
                $target = $this->targets[$index];

                if ($target['path'] === null) {
                    $value = $payload;
                } else {
                    if (!$decoded) {
                        $decoded = true;
                        $this->parsed++;
                        $json   = json_decode($payload, true);
                        $jsonOk = (json_last_error() === JSON_ERROR_NONE);
                        if (!$jsonOk) {
                            /* Pas une erreur : un appareil publie ce qu'il
                             * veut, et le jour où il publie « OK » sur un
                             * topic dont on lit un champ, la bonne réponse
                             * est de ne rien émettre. Compté une fois par
                             * message, pas une fois par cible. */
                            $this->badPayload++;
                        }
                    }
                    if (!$jsonOk) {
                        $this->ignored++;
                        continue;
                    }
                    $value = self::pick($json, $target['path']);
                }

                /* Chemin absent, valeur nulle, ou sous-objet : aucune valeur,
                 * donc aucun événement — et surtout pas une valeur vide, qui
                 * écraserait dans Jeedom une mesure valable par du néant. */
                if ($value === null || is_array($value)) {
                    $this->ignored++;
                    continue;
                }
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (!is_string($value)) {
                    $value = (string) $value;
                }

                if (!$target['plain']) {
                    if ($target['map'] !== null && isset($target['map'][$value])) {
                        $value = $target['map'][$value];
                    }
                    /* L'arithmétique ne s'applique qu'à ce qui est un nombre :
                     * multiplier « on » par 0,1 ne donne pas une erreur en
                     * PHP, il donne 0 — une fausse valeur bien pire qu'une
                     * valeur absente. */
                    if ($target['arith'] && is_numeric($value)) {
                        $number = (float) $value;
                        if ($target['scale'] !== null) {
                            $number *= $target['scale'];
                        }
                        if ($target['offset'] !== null) {
                            $number += $target['offset'];
                        }
                        if ($target['round'] !== null) {
                            $number = round($number, $target['round']);
                        }
                        $value = (string) $number;
                    }
                }

                $cmdId = $target['cmdId'];
                $sent  = isset($this->lastSent[$cmdId]) ? $this->lastSent[$cmdId] : 0.0;

                /* minInterval d'abord : c'est une limite de débit, elle
                 * s'applique aussi à une valeur qui a changé et à une
                 * réémission de keepalive. */
                if ($target['minInterval'] > 0 && ($start - $sent) < $target['minInterval']) {
                    $this->ignored++;
                    continue;
                }
                if (!$target['always']
                 && isset($this->lastValue[$cmdId]) && $this->lastValue[$cmdId] === $value
                 && ($target['keepalive'] <= 0 || ($start - $sent) < $target['keepalive'])) {
                    $this->ignored++;
                    continue;
                }

                $this->lastValue[$cmdId] = $value;
                $this->lastSent[$cmdId]  = $start;
                $this->routed++;

                if ($this->valueHandler !== null) {
                    call_user_func($this->valueHandler, $cmdId, $value, $start);
                }
            }
        } catch (Throwable $e) {
            /*
             * Un routage ne tue pas le démon. La seule chose qui puisse lever
             * ici est imprévue par construction — d'où la ligne de journal,
             * qui dit le topic : sans lui, l'incident serait irreproductible.
             */
            $this->errors++;
            MqttbeLog::error('routage de ' . $_topic . ' interrompu : ' . $e->getMessage()
                           . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }

        /* Mesuré sur les messages qui ont du travail à faire, et sur eux
         * seuls : mêler les messages sans cible, qui ne coûtent qu'un isset,
         * tirerait la médiane vers le bas et masquerait exactement ce qu'on
         * cherche à surveiller. */
        $this->sample(microtime(true) - $start);
    }

    /*
     * Résolution d'un topic : correspondance exacte, puis jokers. Le résultat
     * est mis en cache, y compris quand il est vide.
     */
    private function resolve($_topic) {
        $list = isset($this->exact[$_topic]) ? $this->exact[$_topic] : array();

        if (!empty($this->filters)) {
            $slash = strpos($_topic, '/');
            $head  = ($slash === false) ? $_topic : substr($_topic, 0, $slash);

            $candidates = isset($this->byHead[$head]) ? $this->byHead[$head] : array();
            if (!empty($this->anyHead) && strncmp($_topic, '$', 1) !== 0) {
                /* Un joker de tête ne couvre jamais un topic de service :
                 * `#` ou `+/x` ne doivent pas capter `$SYS/...`, que le
                 * broker débite en continu. */
                $candidates = empty($candidates) ? $this->anyHead : array_merge($candidates, $this->anyHead);
            }

            if (!empty($candidates)) {
                $parts = explode('/', $_topic);
                $count = count($parts);
                foreach ($candidates as $index) {
                    $filter = $this->filters[$index];
                    if (self::filterMatches($filter, $parts, $count)) {
                        $list = empty($list) ? $filter['targets'] : array_merge($list, $filter['targets']);
                    }
                }
            }
        }

        $this->remember($_topic, $list);
        return $list;
    }

    /*
     * Correspondance filtre ↔ topic, sur des segments déjà découpés des deux
     * côtés. Les trois règles qui se trompent le plus souvent :
     *   - `+` occupe exactement un segment et ne traverse pas un `/` ;
     *   - `sport/#` couvre `sport/a/b`, `sport/` ET `sport` lui-même ;
     *   - le nombre de segments doit être égal, sauf derrière un `#`.
     * La garde sur `$` est faite en amont, par le tri des candidats.
     */
    private static function filterMatches($_filter, $_parts, $_count) {
        $limit = $_filter['n'];
        if ($_filter['hash']) {
            $limit--;
            if ($_count < $limit) {
                return false;
            }
        } elseif ($_count !== $limit) {
            return false;
        }
        $parts = $_filter['parts'];
        for ($i = 0; $i < $limit; $i++) {
            if ($parts[$i] !== $_parts[$i] && $parts[$i] !== '+') {
                return false;
            }
        }
        return true;
    }

    /*
     * Vidé d'un bloc quand il déborde, plutôt que tenu en LRU : la
     * comptabilité d'une LRU coûterait, à chaque message, plus que le
     * parcours de filtres qu'elle fait économiser. Le débordement suppose des
     * topics engendrés, donc un cache qui ne servait déjà à rien.
     */
    private function remember($_topic, $_list) {
        if ($this->cacheCount >= self::CACHE_MAX) {
            $this->cache      = array();
            $this->cacheCount = 0;
            MqttbeLog::debug('cache de résolution vidé (' . self::CACHE_MAX . ' topics distincts)');
        }
        $this->cache[$_topic] = $_list;
        $this->cacheCount++;
    }

    /*
     * Extraction par chemin à points. Un segment numérique désigne un index
     * de tableau : PHP normalise déjà « 0 » en 0 sur les clés de tableau,
     * c'est pourquoi un seul array_key_exists suffit pour les objets comme
     * pour les listes.
     */
    private static function pick($_data, $_path) {
        $node = $_data;
        foreach ($_path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /* ==========================================================================
     * ABONNEMENTS ET COMPTEURS
     * ======================================================================= */

    /*
     * L'ensemble des abonnements que la table exige, topic => qos. La boucle
     * en fait la différence avec ce qu'elle a déjà demandé : c'est elle qui
     * tient le transport, et c'est la différence — et non cet ensemble — qui
     * garantit qu'un abonnement inchangé n'est pas résilié puis repris, ce
     * qui rejouerait tous ses messages retenus pour rien.
     */
    public function subscriptions() {
        return $this->subs;
    }

    public function version() {
        return $this->version;
    }

    public function stats() {
        return array(
            'version'    => $this->version,
            'received'   => $this->received,
            'routed'     => $this->routed,
            'ignored'    => $this->ignored,
            'noTarget'   => $this->noTarget,
            'badPayload' => $this->badPayload,
            'parsed'     => $this->parsed,
            'errors'     => $this->errors,
            'targets'    => count($this->targets),
            'topics'     => count($this->exact) + count($this->filters),
            'median'     => $this->medianLatency(),
        );
    }

    private function sample($_seconds) {
        $this->lat[$this->latPos] = $_seconds;
        $this->latPos++;
        if ($this->latPos >= self::LAT_SAMPLES) {
            $this->latPos = 0;
        }
        if ($this->latCount < self::LAT_SAMPLES) {
            $this->latCount++;
        }
    }

    /*
     * Médiane et non moyenne, en millisecondes.
     *
     * Ce n'est pas un raffinement de statisticien : le démon partage sa box
     * avec le cron de Jeedom, et une seconde volée par une sauvegarde suffit
     * à tripler une moyenne calculée sur cinq minutes. La médiane, elle, dit
     * ce que coûte un message ordinaire — la seule chose qu'on sache corriger.
     */
    public function medianLatency() {
        if ($this->latCount === 0) {
            return 0.0;
        }
        $sorted = $this->lat;
        sort($sorted);
        $count  = count($sorted);
        $middle = intdiv($count, 2);
        $value  = ($count % 2 === 1)
            ? $sorted[$middle]
            : (($sorted[$middle - 1] + $sorted[$middle]) / 2);
        return $value * 1000;
    }
}
