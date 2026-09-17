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

/* La configuration porte la liste des topics exclus, et la correspondance
 * topic ↔ filtre MQTT. Le moteur n'a besoin de rien d'autre du démon : ni
 * transport, ni liaison Jeedom, ni journal — tout cela lui est branché. */
require_once __DIR__ . '/../core/Config.php';
require_once __DIR__ . '/Adapter.php';
require_once __DIR__ . '/Context.php';
require_once __DIR__ . '/DeviceModel.php';
/* L'exécution des sondes de nom. Le moteur les déclenche et les relit ; il ne
 * sait pas ce qu'elles font, et ce fichier-ci ne sait pas pour qui. */
require_once __DIR__ . '/NameProbe.php';

/* =============================================================================
 * Le moteur de découverte.
 *
 * Il tient les adapters, réunit leurs abonnements, leur distribue les messages
 * qui les concernent, les fait battre une fois par seconde, et remet à Jeedom
 * les modèles qu'ils produisent.
 *
 * CE FICHIER NE CONNAÎT AUCUN CONSTRUCTEUR. Pas de Shelly, pas de Tasmota, pas
 * de Zigbee2MQTT : rien qu'une interface (MqttbeAdapter) et un modèle
 * (MqttbeDeviceModel). Ce n'est pas de la coquetterie d'architecture. Le jour
 * où un cas particulier remonte jusqu'ici, il devient le cas particulier de
 * TOUS les protocoles : chaque adapter suivant doit vivre avec, et personne ne
 * sait plus dire lequel en dépend. Si un protocole ne rentre pas dans
 * l'interface, c'est l'interface qu'il faut corriger.
 *
 * Quatre soins, qui expliquent l'essentiel de ce qu'on lit plus bas :
 *
 * 1. UN ADAPTER QUI LÈVE NE TUE PAS LE DÉMON. L'exception est attrapée,
 *    journalisée, et c'est LE MESSAGE qui est abandonné — pas l'adapter, pas
 *    les autres adapters, pas la boucle. Un adapter fautif ne se désactive pas
 *    tout seul non plus : il reviendra au message suivant, et son auteur aura
 *    un journal qui le désigne. Les plaintes répétées sont espacées, faute de
 *    quoi un adapter qui lève à chaque message remplirait le disque.
 *
 * 2. RIEN NE GROSSIT AVEC LE TRAFIC. La mémoire des adapters est plafonnée
 *    PAR ADAPTER, le cache de résolution est plafonné, les empreintes des
 *    modèles émis aussi, et les abonnements demandés en cours de route
 *    également. Un adapter qui mémoriserait un état par topic vu bute sur son
 *    plafond et le journal le dit — il ne fait pas enfler le démon jusqu'à ce
 *    que l'OOM killer tranche la question à trois heures du matin.
 *
 * 3. UN MODÈLE IDENTIQUE N'EST PAS RENVOYÉ. Les messages de découverte sont
 *    retenus : le broker les rejoue en entier à chaque démarrage du démon.
 *    Sans la comparaison d'empreintes faite ici, Jeedom recevrait tout le parc
 *    à chaque redémarrage, et la fabrique le passerait en revue en entier —
 *    c'est la même idempotence qu'elle applique déjà, mais une couche plus tôt
 *    et pour le prix d'un sha1 au lieu d'un aller-retour HTTP et d'un parcours
 *    de base.
 *
 * 4. LES ABONNEMENTS SONT DEMANDÉS, JAMAIS POSÉS. Le moteur dit quels topics
 *    il veut ; c'est la boucle qui tient le transport et fait la différence
 *    avec ceux du routage. Personne ici ne peut donc résilier un abonnement
 *    dont l'autre mécanisme a besoin.
 * ========================================================================== */
class MqttbeDiscoveryEngine {

    /* Le battement des adapters. Le contrat dit « au plus une fois par
     * seconde » : plus souvent n'apporte rien — les expirations de candidats
     * se comptent en dizaines de secondes — et coûterait un appel par adapter
     * à chaque tour de boucle, soit vingt fois par seconde. */
    const TICK_PERIOD = 1.0;

    /* Clés mémorisables PAR ADAPTER. Un parc domestique, c'est quelques
     * dizaines d'appareils et deux ou trois clés par appareil ; 512 laisse
     * dix fois la marge nécessaire. Ce plafond n'est atteint que par un
     * adapter qui mémorise par topic vu — c'est-à-dire par une quantité que le
     * trafic fait croître sans fin, et c'est justement ce qu'il faut attraper. */
    const MEMORY_MAX = 512;

    /* Abonnements demandés en cours de route, tous adapters confondus. Un
     * adapter qui s'abonnerait à un topic de réponse par appareil et ne
     * résilierait jamais buterait ici. */
    const SUBS_MAX = 512;

    /* Empreintes de modèles retenues. Au-delà, la table est vidée d'un bloc :
     * le parc sera réémis une fois — la fabrique, idempotente, n'écrira rien —
     * plutôt que de laisser la table croître indéfiniment. */
    const SEEN_MAX = 4096;

    /* Cache de résolution topic vu → adapters concernés, y compris la réponse
     * vide. Sans lui, chaque message du broker coûterait un parcours de tous
     * les filtres de découverte. */
    const CACHE_MAX = 1024;

    /* Espacement des plaintes répétées (adapter qui lève, plafond atteint). */
    const WARN_PERIOD = 60;

    private $config;

    /* id => array('adapter' => MqttbeAdapter, 'priority' => int,
     *             'context' => MqttbeDiscoveryContext) */
    private $adapters = array();
    /* Dernier ordre `discovery` reçu, servi aux adapters par setting(). */
    private $settings = array();

    /* Identifiants triés une fois pour toutes : priorité décroissante, puis
     * ordre alphabétique. C'est ce qui donne à la découverte le même résultat
     * d'une exécution à l'autre, quel que soit l'ordre des fichiers sur le
     * disque. $rank est la position dans cet ordre, pour trier sans le
     * reparcourir. */
    private $order = array();
    private $rank  = array();

    private $enabled = false;
    private $active  = array();   // id => true

    private $memory = array();    // id => clé => données
    private $seen   = array();    // adapter|uid => empreinte du dernier modèle émis
    /*
     * topic => array('adapters' => array(id => true), 'qos' => int)
     *
     * Un ENSEMBLE de demandeurs, et non un seul : deux adapters peuvent vouloir
     * le même topic — deux topics de réponse RPC portant le même nom, par
     * exemple. Avec un demandeur unique, le second volait l'abonnement au
     * premier, qui ne recevait plus rien, et son arrêt retirait l'abonnement
     * sous les pieds de l'autre, toujours actif et se croyant abonné.
     */
    private $adhoc  = array();
    private $rescan = array();    // id => true, le temps d'un onTick

    /* Index reconstruit d'un bloc à chaque changement d'adapters actifs :
     * $subs (topic => qos) est ce que la boucle doit poser, $exact et
     * $filters servent à distribuer les messages. */
    private $subs    = array();
    private $exact   = array();
    private $filters = array();

    private $cache      = array();
    private $cacheCount = 0;

    private $lastTick = 0.0;

    /* L'exécution des sondes de nom (MqttbeNameProbe), et les modèles gardés le
     * temps qu'elles répondent.
     *
     * Une sonde n'aboutit pas sur le tour où le modèle est émis : l'appareil met
     * quelques dizaines de millisecondes à répondre, parfois plusieurs minutes,
     * parfois jamais. Le modèle est donc remis à Jeedom TOUT DE SUITE, avec son
     * nom technique, et gardé ici : quand la sonde répond enfin, c'est le moteur
     * — et non l'adapter, qui n'a aucune raison de reparler — qui le renvoie
     * complété. Sans ce souvenir, le nom obtenu ne serait appliqué qu'au
     * prochain redémarrage du démon, quand le broker rejoue ses messages
     * retenus. */
    private $probes = null;
    /* adapter|uid => array('adapter' => id, 'probe' => clé de sonde,
     *                      'model' => tableau du dernier modèle émis) */
    private $probed = array();

    private $modelHandler     = null;
    private $subscribeHandler = null;
    private $publishHandler   = null;
    private $logHandler       = null;
    private $clock            = null;

    private $delivered  = 0;   // messages remis à un adapter
    private $emitted    = 0;   // modèles remis à Jeedom
    private $duplicates = 0;   // modèles identiques au précédent, non renvoyés
    private $refused    = 0;   // modèles invalides
    private $failures   = 0;   // exceptions levées par les adapters

    /* Comptes et dernière plainte, par motif : « adapter|phase » pour les
     * exceptions, « memory|adapter » pour les plafonds. */
    private $warnCount = array();
    private $warnLast  = array();

    public function __construct(MqttbeConfig $_config) {
        $this->config = $_config;
        /*
         * Les sondes partagent l'horloge du moteur, et non microtime() : c'est
         * ce qui permet d'éprouver hors ligne un recul progressif de dix minutes
         * sans attendre dix minutes.
         */
        $this->probes = new MqttbeNameProbe();
        $this->probes->useClock(array($this, 'now'));
        $this->probes->onLog(array($this, 'logProbe'));
        /*
         * Le journal du démon si on est dans le démon, rien sinon.
         *
         * Ces fichiers sont aussi chargés par le processus web
         * (mqttbeFactory::loadDiscovery), où MqttbeLog écrirait sur STDOUT,
         * qui n'existe pas hors CLI : une ligne de journal y coûterait une
         * erreur fatale en plein milieu d'une page. La boucle, elle, pose son
         * propre puits par onLog().
         */
        if (class_exists('MqttbeLog') && php_sapi_name() === 'cli') {
            $this->logHandler = array('MqttbeLog', 'write');
        }
    }

    /* ==========================================================================
     * BRANCHEMENTS
     *
     * Même convention que partout ailleurs dans le démon : le moteur ne connaît
     * pas ses interlocuteurs, il les appelle. C'est ce qui permet de l'éprouver
     * hors ligne, sans broker et sans Jeedom, sur exactement le même code que
     * celui qui tourne en production.
     * ======================================================================= */

    /* Destinataire des modèles émis : reçoit le TABLEAU du modèle, pas l'objet.
     * La liaison Jeedom n'a ainsi rien à savoir des classes de découverte. */
    public function onModel($_handler) {
        $this->modelHandler = $_handler;
    }

    /* Appelé sans argument quand l'ensemble des abonnements souhaités a changé.
     * À charge de la boucle d'aller lire subscriptions() et de faire la
     * différence avec ce qu'elle a déjà posé. */
    public function onSubscribe($_handler) {
        $this->subscribeHandler = $_handler;
    }

    /* ($topic, $payload, $qos, $retain) => bool */
    public function onPublish($_handler) {
        $this->publishHandler = $_handler;
    }

    /* ($niveau, $message) */
    public function onLog($_handler) {
        $this->logHandler = $_handler;
    }

    /* Horloge de remplacement : une fonction qui rend des secondes flottantes.
     * Elle n'existe que pour les contrôles hors ligne, où l'on veut éprouver
     * une relance au bout de trente secondes sans attendre trente secondes. */
    public function useClock($_handler) {
        $this->clock = $_handler;
    }

    public function now() {
        if ($this->clock !== null) {
            return (float) call_user_func($this->clock);
        }
        return microtime(true);
    }

    /* ==========================================================================
     * LES ADAPTERS
     * ======================================================================= */

    /*
     * Enregistre un adapter. Refuse, en le disant, ce qui n'implémente pas
     * l'interface ou porte un identifiant déjà pris : deux adapters de même
     * identifiant, c'est une configuration d'équipement (`mqttbe::adapter`)
     * qui ne désigne plus personne.
     */
    public function register($_adapter) {
        if (!($_adapter instanceof MqttbeAdapter)) {
            $this->log('error', 'adapter refusé : ' . (is_object($_adapter) ? get_class($_adapter) : gettype($_adapter))
                              . ' n\'implémente pas MqttbeAdapter.');
            return false;
        }
        try {
            $id       = strtolower(trim((string) $_adapter->id()));
            $priority = (int) $_adapter->priority();
        } catch (Throwable $e) {
            $this->log('error', 'adapter refusé : son identité est illisible (' . $e->getMessage() . ')');
            return false;
        }
        if ($id === '') {
            $this->log('error', 'adapter refusé : identifiant vide (' . get_class($_adapter) . ').');
            return false;
        }
        if (isset($this->adapters[$id])) {
            $this->log('error', 'adapter « ' . $id .' » refusé : cet identifiant est déjà pris par '
                              . get_class($this->adapters[$id]['adapter']) . '.');
            return false;
        }

        $this->adapters[$id] = array(
            'adapter'  => $_adapter,
            'priority' => $priority,
            'context'  => new MqttbeDiscoveryContext($this, $id),
        );
        $this->sortAdapters();
        $this->log('info', 'découverte : adapter « ' . $id . ' » enregistré (priorité ' . $priority . ')');
        return true;
    }

    /*
     * Charge les adapters d'un dossier : chaque fichier PHP est inclus, et
     * toute classe concrète qui implémente l'interface est instanciée.
     *
     * Aucune convention de nommage n'est imposée — ni préfixe, ni fichier de
     * déclaration à tenir à jour. Une convention de plus serait une règle non
     * écrite de plus, et un adapter qui ne se charge pas sans qu'on sache
     * pourquoi. Ce que la classe doit respecter tient en deux points : être
     * concrète, et se construire sans argument.
     */
    public function loadAdapters($_dossier) {
        if (!is_dir($_dossier)) {
            return 0;
        }
        $fichiers = glob(rtrim($_dossier, '/') . '/*.php');
        if (!is_array($fichiers) || empty($fichiers)) {
            return 0;
        }
        sort($fichiers);

        $avant = get_declared_classes();
        foreach ($fichiers as $fichier) {
            try {
                require_once $fichier;
            } catch (Throwable $e) {
                /* Un adapter qui ne se charge pas ne doit pas empêcher les
                 * autres de fonctionner : le parc Shelly reste découvert même
                 * si l'adapter Tasmota a une erreur de frappe. */
                $this->log('error', 'découverte : chargement de ' . basename($fichier) . ' impossible : '
                                  . $e->getMessage());
            }
        }

        $compte = 0;
        foreach (array_diff(get_declared_classes(), $avant) as $classe) {
            $interfaces = class_implements($classe);
            if (!is_array($interfaces) || !isset($interfaces['MqttbeAdapter'])) {
                continue;
            }
            try {
                $reflexion = new ReflectionClass($classe);
                if ($reflexion->isAbstract()) {
                    continue;
                }
                $constructeur = $reflexion->getConstructor();
                if ($constructeur !== null && $constructeur->getNumberOfRequiredParameters() > 0) {
                    $this->log('warning', 'découverte : ' . $classe . ' implémente MqttbeAdapter mais exige '
                                        . $constructeur->getNumberOfRequiredParameters()
                                        . ' argument(s) de construction : elle n\'est pas chargée.');
                    continue;
                }
                if ($this->register($reflexion->newInstance())) {
                    $compte++;
                }
            } catch (Throwable $e) {
                $this->log('error', 'découverte : ' . $classe . ' n\'a pas pu être instanciée : ' . $e->getMessage());
            }
        }
        return $compte;
    }

    /* Priorité décroissante, puis ordre alphabétique : le natif du
     * constructeur avant Home Assistant Discovery, qui passe avant le
     * générique (voir Adapter::priority()). */
    private function sortAdapters() {
        $ids = array_keys($this->adapters);
        $adapters = $this->adapters;
        usort($ids, function ($_a, $_b) use ($adapters) {
            if ($adapters[$_a]['priority'] !== $adapters[$_b]['priority']) {
                return $adapters[$_b]['priority'] - $adapters[$_a]['priority'];
            }
            return strcmp($_a, $_b);
        });
        $this->order = $ids;
        $this->rank  = array_flip($ids);
    }

    public function adapters() {
        return $this->order;
    }

    public function activeAdapters() {
        $actifs = array();
        foreach ($this->order as $id) {
            if (isset($this->active[$id])) {
                $actifs[] = $id;
            }
        }
        return $actifs;
    }

    public function isEnabled() {
        return $this->enabled;
    }

    /* ==========================================================================
     * L'ORDRE `discovery` (CONTRAT-J4 §4)
     * ======================================================================= */

    /*
     * {"cmd":"discovery","enabled":true,"adapters":["shelly.gen1"],"rescan":false}
     *
     * `adapters` absent vaut « tous ceux qui sont chargés » : Jeedom n'a pas à
     * connaître la liste pour lancer la découverte, et un adapter ajouté par
     * une mise à jour du plugin fonctionne sans que personne ait à ressaisir
     * quoi que ce soit.
     *
     * Les adapters qui s'activent reçoivent le drapeau de relance : une
     * activation et un « relancer la découverte » appellent le même geste —
     * provoquer une annonce générale — et l'adapter n'a donc qu'un seul
     * traitement à écrire.
     */
    public function apply($_order) {
        if (!is_array($_order)) {
            return array('applied' => false, 'message' => 'ordre de découverte illisible');
        }

        $enabled = array_key_exists('enabled', $_order) ? self::toBool($_order['enabled']) : true;
        $rescan  = array_key_exists('rescan', $_order) && self::toBool($_order['rescan']);
        /*
         * `discovery::probeNames` (défaut 1), porté par l'ordre `discovery` : le
         * démon ne charge pas le cœur et ne peut pas lire la configuration de
         * Jeedom lui-même.
         *
         * Absent, la sonde est ACTIVE : un Jeedom d'une version antérieure qui
         * n'enverrait pas la clé doit se comporter comme le défaut du fichier
         * ini, et non comme un utilisateur qui aurait décoché la case.
         */
        $probeNames = array_key_exists('probeNames', $_order)
                    ? self::toBool($_order['probeNames']) : true;
        $this->probes->enable($probeNames);

        /* L'ordre entier est conservé : les adapters y puisent leurs propres
         * réglages par $ctx->setting(), sans que le moteur ait à connaître ce
         * que chacun attend — il n'a pas à savoir ce qu'est une balise. */
        $this->settings = $_order;

        $demandes = null;
        $inconnus = array();
        if (isset($_order['adapters']) && is_array($_order['adapters'])) {
            $demandes = array();
            foreach ($_order['adapters'] as $id) {
                $id = strtolower(trim((string) $id));
                if ($id === '') {
                    continue;
                }
                if (!isset($this->adapters[$id])) {
                    $inconnus[] = $id;
                    continue;
                }
                $demandes[$id] = true;
            }
        }

        $avant = $this->active;
        $apres = array();
        if ($enabled) {
            foreach ($this->order as $id) {
                if ($demandes === null || isset($demandes[$id])) {
                    $apres[$id] = true;
                }
            }
        }

        /* Un adapter qui s'arrête rend tout ce qu'il tenait : sa mémoire, ses
         * abonnements de circonstance, son drapeau de relance. Garder l'état
         * d'un adapter éteint, c'est le faire reprendre plus tard sur des
         * candidats qui n'existent peut-être plus. */
        foreach ($avant as $id => $ignore) {
            if (!isset($apres[$id])) {
                $this->releaseAdapter($id);
            }
        }
        foreach ($apres as $id => $ignore) {
            if (!isset($avant[$id])) {
                $this->armRescan($id);
            }
        }

        $this->enabled = $enabled;
        $this->active  = $apres;

        if ($rescan) {
            /*
             * « Relancer la découverte » doit vraiment tout renvoyer : c'est
             * le bouton qu'on presse quand un équipement a été supprimé dans
             * Jeedom par erreur, et un moteur qui répondrait « rien n'a
             * changé » ne le ferait jamais revenir. Les empreintes sont donc
             * oubliées, et le prochain modèle identique repartira.
             *
             * La mémoire des adapters, elle, n'est PAS touchée : elle leur
             * appartient, peut porter une corrélation en cours, et c'est
             * $ctx->rescan() qui leur dit de reprendre ce qu'ils jugent bon.
             */
            $this->seen = array();
            /* Les sondes abandonnées repartent, et les noms connus sont
             * redemandés : « relancer la découverte » est justement le geste de
             * celui qui vient de renommer son appareil dans l'application du
             * constructeur, ou de rebrancher celui qui ne répondait pas. */
            $this->probes->rescan();
            foreach ($apres as $id => $ignore) {
                $this->armRescan($id);
            }
            /* Le prochain tour de boucle bat, sans attendre la seconde. */
            $this->lastTick = 0.0;
        }

        $this->rebuild();

        if (!empty($inconnus)) {
            $this->log('warning', 'découverte : adapter(s) inconnu(s) demandé(s) : ' . implode(', ', $inconnus)
                                . ' — connus : ' . (empty($this->order) ? 'aucun' : implode(', ', $this->order)));
        }
        $this->log('info', 'découverte ' . ($enabled ? 'activée' : 'arrêtée')
                         . ($enabled ? ' : ' . (empty($apres) ? 'aucun adapter' : implode(', ', array_keys($apres)))
                                     . ', ' . count($this->subs) . ' abonnement(s)' : '')
                         . ($rescan ? ', relance demandée' : ''));

        return array('applied' => true, 'enabled' => $enabled,
                     'adapters' => $this->activeAdapters(), 'unknown' => $inconnus,
                     'subscriptions' => count($this->subs), 'rescan' => $rescan);
    }

    /*
     * Arme la relance d'un adapter, DE DEUX FAÇONS.
     *
     * $ctx->rescan() est l'interface : elle ne coûte rien, elle retombe toute
     * seule à la fin du battement, et un adapter qui l'ignore ne consomme rien.
     * La même relance est en outre déposée dans la mémoire de l'adapter, sous
     * la clé « <son identifiant>:rescan », que l'adapter consomme par un
     * forget() au moment où il la traite.
     *
     * Deux conventions pour une seule intention, c'est une de trop — mais
     * l'alternative était pire : un adapter écrit contre l'une et servi par
     * l'autre ne fait RIEN quand on presse « relancer la découverte », et ne
     * dit rien non plus. Le bouton paraît marcher, le parc ne revient pas, et
     * il n'y a pas une ligne de journal pour l'expliquer. Le moteur honore
     * donc les deux, et la clé déposée est parfaitement générique : elle ne
     * nomme aucun constructeur, seulement l'adapter à qui elle appartient.
     */
    private function armRescan($_id) {
        $this->rescan[$_id] = true;
        $this->rememberFor($_id, $_id . ':rescan', true);
    }

    /** Un réglage de l'ordre `discovery`, ou le défaut fourni. */
    public function setting($_nom, $_defaut = null) {
        return array_key_exists($_nom, $this->settings) ? $this->settings[$_nom] : $_defaut;
    }

    private function releaseAdapter($_id) {
        unset($this->memory[$_id], $this->rescan[$_id]);
        /* Les modèles gardés pour la réémission suivent le sort du reste : un
         * adapter arrêté n'a plus à voir ses appareils repartir vers Jeedom
         * parce qu'une sonde lancée avant l'arrêt a fini par répondre. */
        foreach ($this->probed as $cle => $entree) {
            if ($entree['adapter'] === $_id) {
                unset($this->probed[$cle]);
            }
        }
        foreach ($this->adhoc as $topic => $info) {
            unset($this->adhoc[$topic]['adapters'][$_id]);
            /* Le topic ne disparaît qu'avec son dernier demandeur. */
            if (empty($this->adhoc[$topic]['adapters'])) {
                unset($this->adhoc[$topic]);
            }
        }
    }

    /* ==========================================================================
     * ABONNEMENTS
     * ======================================================================= */

    /*
     * L'ensemble des abonnements que la découverte réclame, topic => qos.
     *
     * La boucle en fait la différence avec ce qu'elle a déjà posé pour la
     * découverte, ET consulte ceux du routage avant de résilier quoi que ce
     * soit : reprendre un abonnement inchangé ferait rejouer par le broker
     * tous les messages retenus de la branche — sur un parc Shelly, des
     * centaines d'états d'un coup.
     */
    public function subscriptions() {
        return $this->subs;
    }

    /*
     * Reconstruit d'un bloc l'index de distribution et la liste des
     * abonnements. Appelé à chaque changement d'adapters actifs ou
     * d'abonnement de circonstance — c'est-à-dire quelques fois par jour, et
     * jamais sur le chemin d'un message.
     */
    private function rebuild() {
        $subs    = array();
        $exact   = array();
        $filters = array();
        $exclus  = array();

        $ajoute = function ($_id, $_topic, $_qos, $_origine)
                  use (&$subs, &$exact, &$filters, &$exclus) {
            $topic = trim((string) $_topic);
            if ($topic === '') {
                return;
            }
            if (!self::isValidFilter($topic)) {
                $this->log('warning', 'découverte : filtre de topic invalide, ignoré (' . $_id . ', '
                                    . $_origine . ') : «' . $topic . '»');
                return;
            }
            /*
             * Les topics exclus par la configuration ne sont jamais souscrits,
             * pas même pour découvrir : cette liste porte d'office ce que
             * Jeedom publie lui-même, et s'y abonner reviendrait à redécouvrir
             * ses propres équipements — un appareil de plus à chaque tour.
             */
            if ($this->config->isExcluded($topic)) {
                $exclus[$topic] = true;
                return;
            }
            $qos = max(0, min(2, (int) $_qos));
            $subs[$topic] = isset($subs[$topic]) ? max($subs[$topic], $qos) : $qos;

            if (strpbrk($topic, '+#') === false) {
                if (!isset($exact[$topic])) {
                    $exact[$topic] = array();
                }
                if (!in_array($_id, $exact[$topic], true)) {
                    $exact[$topic] = $this->ranked(array_merge($exact[$topic], array($_id)));
                }
                return;
            }
            foreach ($filters as $index => $filtre) {
                if ($filtre['topic'] === $topic) {
                    if (!in_array($_id, $filtre['adapters'], true)) {
                        $filters[$index]['adapters'] = $this->ranked(array_merge($filtre['adapters'], array($_id)));
                    }
                    return;
                }
            }
            $filters[] = array('topic' => $topic, 'adapters' => array($_id));
        };

        foreach ($this->order as $id) {
            if (!isset($this->active[$id])) {
                continue;
            }
            try {
                $demandes = $this->adapters[$id]['adapter']->subscriptions();
            } catch (Throwable $e) {
                $this->failure($id, 'subscriptions', $e);
                continue;
            }
            if (!is_array($demandes)) {
                $this->log('warning', 'découverte : subscriptions() de « ' . $id . ' » ne rend pas un tableau.');
                continue;
            }
            foreach ($demandes as $cle => $valeur) {
                /* Les deux formes du contrat : liste de filtres, ou
                 * filtre => QoS. */
                if (is_int($cle)) {
                    $ajoute($id, $valeur, 0, 'subscriptions()');
                } else {
                    $ajoute($id, $cle, $valeur, 'subscriptions()');
                }
            }
        }

        foreach ($this->adhoc as $topic => $info) {
            foreach ($info['adapters'] as $demandeur => $ignore) {
                if (isset($this->active[$demandeur])) {
                    $ajoute($demandeur, $topic, $info['qos'], 'subscribe()');
                }
            }
        }

        $this->subs    = $subs;
        $this->exact   = $exact;
        $this->filters = $filters;

        /* Le cache portait sur l'index précédent : le garder, ce serait
         * distribuer des messages à un adapter qui vient d'être arrêté. */
        $this->cache      = array();
        $this->cacheCount = 0;

        if (!empty($exclus)) {
            $this->log('info', 'découverte : ' . count($exclus) . ' topic(s) écarté(s) par la liste '
                             . 'd\'exclusion : ' . implode(', ', array_keys($exclus)));
        }
    }

    /* Remet une liste d'identifiants dans l'ordre de priorité : un message qui
     * intéresse deux adapters doit toujours atteindre le plus prioritaire en
     * premier, quel que soit l'ordre dans lequel les filtres ont été compilés. */
    private function ranked($_ids) {
        $rank = $this->rank;
        usort($_ids, function ($_a, $_b) use ($rank) {
            $ra = isset($rank[$_a]) ? $rank[$_a] : PHP_INT_MAX;
            $rb = isset($rank[$_b]) ? $rank[$_b] : PHP_INT_MAX;
            return ($ra === $rb) ? strcmp($_a, $_b) : ($ra - $rb);
        });
        return $_ids;
    }

    /*
     * Validité d'un filtre MQTT (OASIS 3.1.1 §4.7.1).
     *
     * Recopiée du routeur plutôt qu'empruntée : celui-ci vit dans core/, que
     * le processus web ne charge pas, et une découverte qui dépendrait du
     * routeur ne serait plus rejouable hors du démon. Accepter `sport/a#` ou
     * `sp+rt` reviendrait à leur inventer une sémantique que le broker n'aura
     * pas — le moteur croirait écouter un topic auquel il n'est pas abonné.
     */
    private static function isValidFilter($_topic) {
        if ($_topic === '' || strlen($_topic) > 65535) {
            return false;
        }
        if (strpos($_topic, '#') !== false) {
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
     * DISTRIBUTION
     * ======================================================================= */

    /*
     * Un message MQTT, déjà filtré par la liste d'exclusion de la boucle.
     *
     * Appelée pour CHAQUE message reçu par le démon, routage compris : c'est
     * le chemin chaud, et elle doit coûter le moins possible à ceux qui ne
     * concernent aucun adapter — un isset dans le cache, et rien d'autre.
     *
     * $_retained est transmis tel quel : il dit « état rejoué par le broker »
     * et non « cela vient de se produire ». Une découverte qui le perd
     * fabrique de faux événements à chaque démarrage.
     */
    public function onMessage($_topic, $_payload, $_retained = false) {
        if (!$this->enabled || empty($this->active)) {
            return;
        }
        $ids = isset($this->cache[$_topic]) ? $this->cache[$_topic] : $this->resolve($_topic);
        if (empty($ids)) {
            return;
        }
        foreach ($ids as $id) {
            if (!isset($this->active[$id])) {
                continue;
            }
            $this->delivered++;
            try {
                $this->adapters[$id]['adapter']->onMessage($_topic, $_payload, $_retained,
                                                           $this->adapters[$id]['context']);
            } catch (Throwable $e) {
                /* Le message est abandonné pour CET adapter ; les suivants le
                 * reçoivent quand même, et le démon continue. */
                $this->failure($id, 'onMessage ' . $_topic, $e);
            }
        }
    }

    private function resolve($_topic) {
        $liste = isset($this->exact[$_topic]) ? $this->exact[$_topic] : array();
        foreach ($this->filters as $filtre) {
            if (!MqttbeConfig::topicMatches($filtre['topic'], $_topic)) {
                continue;
            }
            foreach ($filtre['adapters'] as $id) {
                if (!in_array($id, $liste, true)) {
                    $liste[] = $id;
                }
            }
        }
        if (count($liste) > 1) {
            $liste = $this->ranked($liste);
        }

        /* Vidé d'un bloc quand il déborde, comme celui du routeur : la
         * comptabilité d'une LRU coûterait, par message, plus que le parcours
         * de filtres qu'elle ferait économiser. */
        if ($this->cacheCount >= self::CACHE_MAX) {
            $this->cache      = array();
            $this->cacheCount = 0;
        }
        $this->cache[$_topic] = $liste;
        $this->cacheCount++;
        return $liste;
    }

    /*
     * Le battement des adapters, au plus une fois par seconde.
     *
     * Appelée à chaque tour de boucle : c'est le moteur qui tient le rythme,
     * et non la boucle, pour que l'intervalle reste vrai quel que soit le
     * trafic — une boucle qui tourne vingt fois par seconde sous charge
     * appellerait sinon onTick() vingt fois.
     */
    public function tick() {
        /*
         * Les sondes AVANT tout le reste, et à chaque tour — pas une fois par
         * seconde comme les adapters.
         *
         * curl_multi n'avance que lorsqu'on le relance : une réponse arrivée
         * reste dans le tampon du noyau tant que personne ne la lit, et la
         * ralentir au rythme des adapters ajouterait une seconde d'attente à
         * chaque appareil du parc, pour rien. C'est aussi pourquoi la boucle
         * n'a rien eu à changer : elle appelle déjà tick() à chaque tour.
         */
        $this->probes->tick();
        $this->republish();

        if (!$this->enabled || empty($this->active)) {
            return;
        }
        $maintenant = $this->now();
        if (($maintenant - $this->lastTick) < self::TICK_PERIOD) {
            return;
        }
        $this->lastTick = $maintenant;

        foreach ($this->order as $id) {
            if (!isset($this->active[$id])) {
                continue;
            }
            try {
                $this->adapters[$id]['adapter']->onTick($this->adapters[$id]['context']);
            } catch (Throwable $e) {
                $this->failure($id, 'onTick', $e);
            }
            /* Le drapeau de relance ne vaut que pour ce battement, y compris
             * quand l'adapter a levé : le laisser armé après un échec ferait
             * publier une annonce générale à chaque seconde, indéfiniment. */
            unset($this->rescan[$id]);
        }
    }

    /*
     * LA RÉÉMISSION — sans elle, le nom obtenu ne serait jamais appliqué.
     *
     * Quand une sonde finit par répondre, le modèle a déjà été remis à Jeedom
     * depuis longtemps, et l'adapter n'a aucune raison de le reproduire : son
     * appareil n'a rien publié de nouveau. C'est donc le moteur qui renvoie le
     * modèle gardé, complété du nom.
     *
     * Le chemin est exactement celui d'une émission ordinaire — emitFrom() — et
     * non un raccourci vers Jeedom : le modèle repasse par la validation, par
     * l'empreinte et par la table des modèles déjà émis. Comme `device_name`
     * entre dans l'empreinte principale, l'empreinte diffère, le modèle part, et
     * le suivant identique ne partira pas.
     */
    private function republish() {
        $changes = $this->probes->drainChanged();
        if (empty($changes) || !$this->enabled) {
            return;
        }
        $changes = array_flip($changes);
        foreach ($this->probed as $cle => $entree) {
            if (!isset($changes[$entree['probe']]) || !isset($this->active[$entree['adapter']])) {
                continue;
            }
            $this->emitFrom($entree['adapter'], $entree['model']);
        }
    }

    /* ==========================================================================
     * CE QUE LE CONTEXTE APPELLE
     *
     * Public faute de mieux — PHP n'a pas de visibilité de paquet — mais
     * destiné au seul MqttbeDiscoveryContext, qui y apporte l'identité de
     * l'adapter appelant. C'est cette identité, ajoutée par le contexte et non
     * par l'adapter, qui rend le cloisonnement des mémoires gratuit : un
     * adapter ne peut pas nommer la mémoire d'un autre, puisqu'il ne nomme
     * jamais la sienne.
     * ======================================================================= */

    public function publishFor($_id, $_topic, $_payload, $_qos = 0, $_retain = false) {
        if (!isset($this->active[$_id])) {
            return false;
        }
        $topic = trim((string) $_topic);
        if ($topic === '' || $this->publishHandler === null) {
            return false;
        }
        $ok = (bool) call_user_func($this->publishHandler, $topic, $_payload,
                                    max(0, min(2, (int) $_qos)), (bool) $_retain);
        if (!$ok) {
            /* Sans broker, l'adapter réessaiera au battement suivant : ce
             * n'est pas un incident, c'est l'état ordinaire d'un démon qui
             * attend sa liaison. */
            $this->log('debug', 'découverte[' . $_id . '] : publication impossible sur ' . $topic);
        }
        return $ok;
    }

    public function subscribeFor($_id, $_topic) {
        if (!isset($this->active[$_id])) {
            return false;
        }
        $topic = trim((string) $_topic);
        if ($topic === '' || !self::isValidFilter($topic)) {
            $this->log('warning', 'découverte[' . $_id . '] : abonnement refusé, filtre invalide : «' . $topic . '»');
            return false;
        }
        if ($this->config->isExcluded($topic)) {
            $this->log('warning', 'découverte[' . $_id . '] : abonnement refusé, topic exclu par la '
                                . 'configuration : ' . $topic);
            return false;
        }
        if (isset($this->adhoc[$topic]['adapters'][$_id])) {
            return true;
        }
        if (!isset($this->adhoc[$topic]) && count($this->adhoc) >= self::SUBS_MAX) {
            $this->throttled('subs|' . $_id, 'warning',
                'découverte[' . $_id . '] : plafond de ' . self::SUBS_MAX . ' abonnements de circonstance '
              . 'atteint, ' . $topic . ' refusé — un adapter qui s\'abonne par appareil sans jamais '
              . 'résilier fait enfler le démon sans fin.');
            return false;
        }

        if (!isset($this->adhoc[$topic])) {
            $this->adhoc[$topic] = array('adapters' => array(), 'qos' => 0);
        }
        $this->adhoc[$topic]['adapters'][$_id] = true;
        $this->rebuild();
        /* La boucle seule sait poser un abonnement : on la prévient, elle fera
         * la différence avec ce qu'elle tient déjà. */
        if ($this->subscribeHandler !== null) {
            call_user_func($this->subscribeHandler);
        }
        return true;
    }

    public function rememberFor($_id, $_cle, $_donnees) {
        $cle = (string) $_cle;
        if ($cle === '') {
            return false;
        }
        if (!isset($this->memory[$_id])) {
            $this->memory[$_id] = array();
        }
        if (!array_key_exists($cle, $this->memory[$_id])
            && count($this->memory[$_id]) >= self::MEMORY_MAX) {
            /*
             * Refuser plutôt qu'évincer. Évincer le plus ancien ferait
             * repartir l'adapter en boucle sur les mêmes candidats sans que
             * rien ne le dise ; refuser et le journaliser désigne le défaut —
             * une mémoire tenue par topic vu au lieu de l'être par appareil.
             * Une clé déjà connue reste modifiable : un adapter arrivé au
             * plafond doit pouvoir rafraîchir ce qu'il sait déjà.
             */
            $this->throttled('memory|' . $_id, 'warning',
                'découverte[' . $_id . '] : plafond de ' . self::MEMORY_MAX . ' clés en mémoire atteint, '
              . '« ' . $cle . ' » refusée — cet adapter mémorise sans doute par topic vu plutôt que par '
              . 'appareil, et ferait enfler le démon indéfiniment.');
            return false;
        }
        $this->memory[$_id][$cle] = $_donnees;
        return true;
    }

    public function recallFor($_id, $_cle) {
        $cle = (string) $_cle;
        return isset($this->memory[$_id]) && array_key_exists($cle, $this->memory[$_id])
            ? $this->memory[$_id][$cle] : null;
    }

    public function forgetFor($_id, $_cle) {
        $cle = (string) $_cle;
        if (!isset($this->memory[$_id]) || !array_key_exists($cle, $this->memory[$_id])) {
            return false;
        }
        unset($this->memory[$_id][$cle]);
        return true;
    }

    public function memoryCountFor($_id) {
        return isset($this->memory[$_id]) ? count($this->memory[$_id]) : 0;
    }

    public function rescanFor($_id) {
        return isset($this->rescan[$_id]);
    }

    public function logFrom($_id, $_niveau, $_message) {
        $this->log($_niveau, 'découverte[' . $_id . '] : ' . $_message);
    }

    /*
     * Un modèle terminé.
     *
     * Deux refus et un silence, dans cet ordre :
     *
     *   - ce qui n'est pas un modèle est refusé ;
     *   - un modèle invalide aussi, en disant pourquoi : envoyé tel quel, il
     *     échouerait côté Jeedom sur une erreur SQL qui ne désigne pas sa
     *     cause, et l'adapter fautif ne serait jamais mis en cause ;
     *   - un modèle identique au dernier émis pour le même appareil n'est pas
     *     renvoyé. C'est le cas NORMAL, pas l'exception : les messages de
     *     découverte sont retenus, donc rejoués à chaque démarrage du démon, et
     *     sans cette comparaison Jeedom recevrait tout le parc à chaque
     *     redémarrage.
     *
     * L'empreinte est retenue par adapter ET par uid, jamais par uid seul :
     * deux adapters peuvent légitimement décrire le même appareil — un Shelly
     * vu par son protocole natif et par Home Assistant Discovery — et c'est
     * Jeedom qui arbitre par priorité. Les confondre ici ferait disparaître
     * silencieusement le modèle du second.
     */
    public function emitFrom($_id, $_modele) {
        $modele = $_modele;
        if (is_array($modele)) {
            $modele = MqttbeDeviceModel::fromArray($modele);
        }
        if (!($modele instanceof MqttbeDeviceModel)) {
            $this->refused++;
            $this->log('error', 'découverte[' . $_id . '] : modèle refusé, ce n\'est ni un '
                              . 'MqttbeDeviceModel ni un tableau (' . gettype($_modele) . ').');
            return false;
        }

        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $this->refused++;
            $this->log('error', 'découverte[' . $_id . '] : modèle « ' . $modele->uid() . ' » refusé — '
                              . implode(' / ', $fautes));
            return false;
        }

        /* Le nom que l'utilisateur a donné à son appareil, s'il est déjà connu,
         * et la sonde inscrite s'il ne l'est pas. AVANT l'empreinte : c'est ce
         * nom-là qui la fait changer le jour où la sonde répond. */
        $this->resolveDeviceName($_id, $modele);

        $cle       = $_id . '|' . $modele->uid();
        /* L'empreinte volatile entre dans la décision d'émettre : un appareil
         * qui a simplement changé d'adresse IP doit repartir vers Jeedom, qui
         * rafraîchira ce seul champ sans rien réécrire d'autre. */
        $empreinte = $modele->fingerprint() . '|' . $modele->volatileFingerprint();
        if (isset($this->seen[$cle]) && $this->seen[$cle] === $empreinte) {
            $this->duplicates++;
            $this->log('debug', 'découverte[' . $_id . '] : ' . $modele->uid()
                              . ' inchangé, rien n\'est envoyé à Jeedom');
            return true;
        }

        if (!isset($this->seen[$cle]) && count($this->seen) >= self::SEEN_MAX) {
            /* Vidée d'un bloc : le parc sera réémis une fois — la fabrique,
             * idempotente, n'écrira rien — plutôt que de laisser cette table
             * croître avec le nombre d'uid distincts vus. */
            $this->seen = array();
            $this->log('warning', 'découverte : ' . self::SEEN_MAX . ' empreintes distinctes retenues, '
                                . 'la table est vidée (le parc sera réémis une fois).');
        }
        $this->seen[$cle] = $empreinte;
        $this->emitted++;

        $this->log('info', 'découverte[' . $_id . '] : ' . ($modele->name() !== '' ? $modele->name() : $modele->uid())
                         . ($modele->deviceName() !== '' ? ' « ' . $modele->deviceName() . ' »' : '')
                         . ' (' . $modele->uid() . ', ' . $modele->countChannels() . ' canal/canaux, '
                         . $modele->confidence() . ')');

        if ($this->modelHandler !== null) {
            /* Le tableau, et non l'objet : la liaison Jeedom groupe et
             * sérialise, elle n'a pas à connaître les classes de découverte. */
            call_user_func($this->modelHandler, $modele->toArray());
        }
        return true;
    }

    /*
     * LE NOM D'USAGE D'UN APPAREIL.
     *
     * Le moteur ne sait pas ce qu'est une sonde — il sait que le modèle en porte
     * une, il la confie à MqttbeNameProbe, et il pose sur le modèle ce qui en
     * revient. Aucun protocole n'est nommé ici, et aucun ne le sera : un adapter
     * dont le message de découverte porte déjà le nom (Tasmota `dn`,
     * Zigbee2MQTT `friendly_name`, Home Assistant `dev.name`) remplit
     * `meta.device_name` et ne déclare pas de sonde — ce cas-là traverse cette
     * fonction sans rien déclencher.
     */
    private function resolveDeviceName($_id, $_modele) {
        $cle = $_id . '|' . $_modele->uid();

        /* L'adapter connaît déjà le nom, ou il n'a rien à proposer : dans les
         * deux cas, aucune requête n'est faite et il n'y a rien à garder. */
        if (!$_modele->hasProbe() || $_modele->deviceName() !== '') {
            unset($this->probed[$cle]);
            return;
        }

        $sonde = $this->probes->submit($_modele->probe());
        if ($sonde === '') {
            /* Sonde refusée : type inconnu, adresse illégale, ou l'utilisateur a
             * coupé `discovery::probeNames`. L'appareil est découvert avec son
             * nom technique, comme avant — jamais moins. */
            unset($this->probed[$cle]);
            return;
        }

        if (!isset($this->probed[$cle]) && count($this->probed) >= self::SEEN_MAX) {
            /* Vidée d'un bloc, comme la table des empreintes : les appareils
             * concernés ne seront pas réémis à la réponse de leur sonde, et
             * porteront leur nom d'usage à la découverte suivante. */
            $this->probed = array();
            $this->log('warning', 'découverte : ' . self::SEEN_MAX . ' modèles gardés pour la '
                                . 'réémission, la table est vidée.');
        }
        /*
         * Le modèle est gardé TEL QUE L'ADAPTER L'A PRODUIT, avant que le nom
         * n'y soit posé — et c'est essentiel.
         *
         * Le garder avec son nom reviendrait à ne plus pouvoir distinguer, à la
         * réémission, un nom que l'adapter connaît (et qui interdit la sonde)
         * d'un nom que le moteur vient d'y poser. Un appareil renommé dans
         * l'application du constructeur garderait alors son ancien nom pour
         * toujours : la sonde le redemanderait bien, l'obtiendrait, et la
         * réémission le jetterait en croyant que l'adapter l'a déjà donné.
         */
        $this->probed[$cle] = array('adapter' => $_id, 'probe' => $sonde,
                                    'model' => $_modele->toArray());

        /* Un vide ne remplace jamais un nom connu : applyDeviceName() s'y
         * refuse, et c'est ce qui rend une sonde en échec inoffensive. */
        $_modele->applyDeviceName($this->probes->name($sonde));
    }

    public function probes() {
        return $this->probes;
    }

    /* ==========================================================================
     * JOURNAL ET COMPTEURS
     * ======================================================================= */

    /* Le journal des sondes passe par celui du moteur : une ligne qui ne dit pas
     * de quel mécanisme elle vient n'apprend rien sur une installation où trois
     * adapters travaillent en même temps. Publique parce que MqttbeNameProbe
     * l'appelle, et destinée à lui seul. */
    public function logProbe($_niveau, $_message) {
        $this->log($_niveau, 'découverte : ' . $_message);
    }

    private function log($_niveau, $_message) {
        if ($this->logHandler === null) {
            return;
        }
        call_user_func($this->logHandler, $_niveau, $_message);
    }

    /*
     * Une plainte immédiate, puis une au plus par minute, avec le compte de
     * celles qu'on a tues. Un adapter qui lève à chaque message produirait
     * sinon deux mille lignes par seconde : le journal deviendrait illisible
     * au moment précis où il aurait quelque chose à apprendre, et le disque
     * de la box se remplirait avant le matin.
     */
    private function throttled($_motif, $_niveau, $_message) {
        if (!isset($this->warnCount[$_motif])) {
            $this->warnCount[$_motif] = 0;
            $this->warnLast[$_motif]  = 0;
        }
        $this->warnCount[$_motif]++;
        $maintenant = $this->now();
        if ($this->warnCount[$_motif] > 1
            && ($maintenant - $this->warnLast[$_motif]) < self::WARN_PERIOD) {
            return;
        }
        $repetitions = ($this->warnCount[$_motif] > 1)
            ? ' (' . $this->warnCount[$_motif] . ' fois depuis le début de l\'incident)' : '';
        $this->warnLast[$_motif] = $maintenant;
        $this->log($_niveau, $_message . $repetitions);
    }

    private function failure($_id, $_phase, $_exception) {
        $this->failures++;
        $this->throttled('lève|' . $_id, 'error',
            'découverte[' . $_id . '] : exception dans ' . $_phase . ' — ' . $_exception->getMessage()
          . ' (' . basename($_exception->getFile()) . ':' . $_exception->getLine() . ')');
    }

    public function stats() {
        $memoire = 0;
        foreach ($this->memory as $clefs) {
            $memoire += count($clefs);
        }
        $sondes = $this->probes->stats();
        return array(
            'enabled'       => $this->enabled,
            'adapters'      => count($this->adapters),
            'active'        => count($this->active),
            'subscriptions' => count($this->subs),
            'delivered'     => $this->delivered,
            'emitted'       => $this->emitted,
            'duplicates'    => $this->duplicates,
            'refused'       => $this->refused,
            'failures'      => $this->failures,
            'memory'        => $memoire,
            'fingerprints'  => count($this->seen),
            /* Les sondes de nom, à plat : un compteur imbriqué serait invisible
             * de la ligne de résumé de la boucle, qui lit des entiers. */
            'probes'        => $sondes['probes'],
            'probesKnown'   => $sondes['known'],
            'probesFailed'  => $sondes['failed'],
        );
    }

    /* Jeedom transmet ses booléens tantôt en JSON, tantôt en "1"/"0" hérités
     * d'un champ de formulaire : les deux doivent donner le même résultat. */
    private static function toBool($_valeur) {
        if (is_bool($_valeur)) {
            return $_valeur;
        }
        if (is_numeric($_valeur)) {
            return ((int) $_valeur) !== 0;
        }
        return in_array(strtolower(trim((string) $_valeur)), array('true', 'yes', 'on'), true);
    }
}
