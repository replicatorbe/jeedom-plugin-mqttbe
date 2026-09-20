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

require_once __DIR__ . '/../Channel.php';
require_once __DIR__ . '/../DeviceModel.php';

/* Voir ShellyGen1.php : un adapter doit pouvoir être inclus seul, par un
 * contrôle hors ligne, sans que l'ordre des inclusions du démon soit rejoué. */
if (!interface_exists('MqttbeAdapter') && is_readable(__DIR__ . '/../Adapter.php')) {
    require_once __DIR__ . '/../Adapter.php';
}
if (!interface_exists('MqttbeAdapter')) {
    interface MqttbeAdapter {
        public function id();
        public function priority();
        public function subscriptions();
        public function onMessage($_topic, $_payload, $_retained, $_ctx);
        public function onTick($_ctx);
    }
}

/* =============================================================================
 * Découverte des Shelly de deuxième génération et suivantes — Plus, Pro, Mini,
 * Gen3, Gen4.
 *
 * TOUT PASSE PAR MQTT, ET RIEN D'AUTRE.
 *
 * C'est la différence de fond avec la génération 1, et elle mérite d'être dite
 * parce qu'elle surprend : le « RPC » d'un Shelly moderne n'est pas une API
 * HTTP. C'est du JSON-RPC 2.0 *transporté*, et MQTT en est un canal officiel au
 * même titre que HTTP ou le WebSocket. On publie une requête sur `<P>/rpc`,
 * l'appareil publie sa réponse sur un topic que NOUS avons choisi. Aucune
 * requête réseau ne part de ce fichier ; l'adapter n'a d'autre outil que
 * $ctx->publish(), exactement comme celui de la génération 1.
 *
 * Conséquence heureuse : là où le Gen1 doit déclarer une sonde HTTP pour
 * apprendre le nom que son propriétaire lui a donné — il ne le publie sur aucun
 * topic —, le Gen2 le rend dans sa réponse RPC. Cet adapter ne déclare donc
 * AUCUNE sonde, et `discovery::probeNames` ne le concerne pas.
 *
 * TROIS FAÇONS DE VOIR UN APPAREIL, ET IL EN FAUT TROIS
 *
 *   1. <P>/online — le seul topic RETENU d'un Shelly moderne. Le broker le
 *      rejoue à l'abonnement : au démarrage du démon, tout le parc se signale
 *      en une seconde, sans qu'on ait rien demandé. Il ne dit que l'existence
 *      d'un préfixe — ni modèle, ni MAC, ni capacités — mais c'est par lui
 *      qu'on sait à qui parler.
 *
 *   2. shellies/announce — la réponse à `announce` publié sur
 *      `shellies/command`. Ces deux topics sont FIXES et non préfixés, et la
 *      documentation officielle les donne pour actifs en sortie d'usine
 *      (`enable_control` vaut `true` depuis la version 0.14.0). La charge utile
 *      est celle de `Shelly.GetDeviceInfo` : identité complète en un message.
 *      Aucune intégration connue ne s'en sert — toutes font saisir la liste des
 *      appareils à la main — et un fil communautaire affirme même que cela
 *      n'existe pas sur Gen2, en contradiction avec la documentation. D'où le
 *      choix de ne jamais en dépendre : si cela répond, la découverte est
 *      immédiate ; sinon, les deux autres voies suffisent.
 *
 *   3. <P>/events/rpc — les notifications. `rpc_ntf` vaut `true` d'usine, donc
 *      tout appareil qui change d'état s'y annonce, et le champ `src` de la
 *      trame porte son identifiant. C'est aussi, et surtout, le topic où l'état
 *      circule une fois l'équipement créé.
 *
 * ET UNE QUATRIÈME VOIE, QUI NE PARLE QUE SI ON LUI PARLE
 *
 * `<P>/status` et `<P>/status/<composant>` ne publient RIEN spontanément :
 * `status_ntf` vaut `false` en sortie d'usine, et le critère de ce jalon est
 * qu'un appareil soit découvert sans que son propriétaire n'ait à toucher à sa
 * configuration. Les autres intégrations s'en sortent en allant retourner ce
 * réglage par HTTP ; cette porte-là nous est fermée, et c'est très bien ainsi.
 *
 * Mais ces topics ne sont pas muets pour autant : ils répondent. La
 * documentation décrit, sous le nom de « MQTT control », deux commandes
 * écoutées d'usine sur `<P>/command` — `enable_control` vaut `true` depuis la
 * version 0.14.0 :
 *
 *   `announce`      → l'identité complète, sur `<P>/announce` ;
 *   `status_update` → le statut complet de l'appareil, sur `<P>/status`.
 *
 * C'est une SECONDE PORTE, et elle sert exactement là où la première se ferme :
 * un appareil dont le RPC est coupé — `enable_rpc` à `false` — ou qui reste
 * muet pour une raison qu'on n'a pas, répond encore à celle-ci. L'adapter ne
 * s'en sert qu'après le silence du RPC : c'est un secours, pas la voie
 * normale, et rien n'en dépend.
 *
 * Tout l'état passe donc par `<P>/events/rpc`, avec des sélecteurs JSON
 * `params.<composant>.<champ>`. Le démon les évalue tels quels — le `:` de
 * `switch:0` ne coupe pas un chemin par points — et un composant absent de la
 * trame rend `null`, que le routeur laisse tomber sans rien écrire. C'est
 * exactement ce qu'il faut : un `NotifyStatus` est un DELTA, il ne porte que ce
 * qui a changé, et une valeur absente ne doit jamais effacer une mesure valable.
 *
 * DEUX LIMITES, DITES FRANCHEMENT.
 *
 * `events/rpc` n'est pas retenu. Au redémarrage
 * du démon, les commandes gardent la dernière valeur que Jeedom a enregistrée,
 * et la première notification venue les rafraîchit. Un appareil qui ne change
 * pas d'état de la semaine n'émet rien, et ses commandes affichent une valeur
 * datée. Aucun topic d'usine ne permet de faire mieux : `NotifyFullStatus`
 * n'est poussé sur MQTT que par les appareils sur pile.
 *
 * Et un `NotifyEvent` peut porter PLUSIEURS événements à la fois — la
 * documentation dit « tous les événements survenus », et l'exemple officiel en
 * montre deux, sur deux entrées différentes. Les commandes d'événement lisent
 * le premier, parce qu'un sélecteur est un chemin par points et qu'un chemin ne
 * sait pas parcourir un tableau. Deux appuis dans la même fenêtre d'agrégation
 * donnent donc une seule remontée. Corriger cela demande d'étendre le langage
 * de sélecteurs du noyau, pas d'écrire une exception ici.
 *
 * Aucune référence à Jeedom dans ce fichier : il est chargé par le démon, qui
 * tourne sans core.inc.php. Les décisions de présentation ne sont pas prises
 * ici non plus — un canal désigne une capacité, capabilities.json traduit.
 * ========================================================================== */
class MqttbeShellyGen2 implements MqttbeAdapter {

    const ID = 'shelly.gen2';

    /* 100, comme la génération 1 : découverte native du constructeur. Les deux
     * adapters ne se disputeront jamais un appareil — ils se départagent sur le
     * champ `gen` de l'annonce, que la génération 1 ne publie nulle part. */
    const PRIORITY = 100;

    /* Les topics fixes, non préfixés, de la commande diffusée. */
    const DIFFUSION_COMMANDE = 'shellies/command';
    const DIFFUSION_ANNONCE  = 'shellies/announce';

    /*
     * Profondeur maximale d'un préfixe de topic.
     *
     * `mqtt.topic_prefix` vaut par défaut l'identifiant de l'appareil, un seul
     * niveau — « shellyplus1pm-a8032abd1234 ». Mais la documentation ne compte
     * le `/` ni parmi les caractères interdits, ni dans la limite de 300
     * caractères : un préfixe « maison/salon/lampe » est parfaitement légal, et
     * se rencontre. Trois niveaux couvrent ce qu'on voit en pratique sans
     * s'abonner à `#`, ce qui ferait traverser la découverte par la totalité du
     * trafic du broker.
     */
    const PROFONDEUR_MAX = 3;

    /* La racine de la génération 1. Un `shellies/<id>/online` n'est pas le
     * préfixe d'un Gen2 : c'est un appareil de la génération précédente, dont
     * l'autre adapter s'occupe. Sans cette exclusion, on irait publier des
     * appels RPC sur `shellies/<id>/rpc`, où personne n'écoute. */
    const RACINE_GEN1 = 'shellies';

    /*
     * Délai d'attente d'une réponse RPC, et nombre de tentatives.
     *
     * La documentation ne donne AUCUN délai de réponse pour le canal MQTT. Cinq
     * secondes sont généreuses pour un appareil sur secteur, et l'attente ne
     * coûte rien puisque rien n'est bloqué : on repose la question au tour
     * suivant. Trois tentatives, parce qu'un appareil peut manquer la première
     * — sa file de publication est bornée (trente messages depuis le
     * micrologiciel 2.0.0, moins avant, la documentation ne dit pas combien), et
     * une seule publication pouvait être en vol avant la version 1.4.0.
     */
    const DELAI_REPONSE = 5.0;
    const TENTATIVES    = 3;

    /*
     * Délai d'attente de la seconde porte, celle du « MQTT control ».
     *
     * Elle n'est frappée qu'après le silence du RPC, et un appareil qui écoute
     * `<P>/command` répond aussi vite qu'au RPC. Dix secondes suffisent donc
     * largement ; au-delà, on conclut avec ce qu'on a, et le modèle reste
     * `probable` jusqu'au jour où l'appareil parlera.
     */
    const DELAI_CONTROLE = 10.0;

    /* Demandes d'annonce diffusée, en secondes depuis l'activation. Même
     * raisonnement que pour la génération 1 : la première part parfois avant
     * que les abonnements ne soient établis côté broker. */
    const RELANCES = array(0, 10, 60);

    /*
     * Une énergie Gen2 n'est jamais en kWh : `aenergy.total`, `ret_aenergy`,
     * `total_act_energy` et leurs variantes sont toutes en WATT-HEURES. Les
     * trois intégrations lues le confirment, et aucune ne divise — elles
     * affichent des Wh. Ici, la commande annonce des kWh, donc elle convertit.
     */
    const WATTHEURE_VERS_KWH = 0.001;
    const DECIMALES_ENERGIE  = 3;

    /*
     * Ce que l'adapter met dans `src` quand il compose une action.
     *
     * La charge utile d'une action est figée dans la commande Jeedom au moment
     * de la découverte : elle survivra à tous les redémarrages du démon. Y
     * inscrire le `src` de la conversation en cours — qui, lui, est tiré au
     * hasard à chaque activation — ferait qu'un an plus tard, l'appareil
     * publierait ses accusés de réception sur un topic dont plus personne
     * n'aurait entendu parler. Une valeur fixe et reconnaissable est préférable :
     * on ne lit jamais ces réponses, mais celui qui observe son broker doit
     * pouvoir savoir d'où elles viennent.
     */
    const SRC_ACTIONS = 'mqttbe';

    /*
     * La forme d'un préfixe de topic acceptable.
     *
     * Le préfixe vient du réseau, et il compose TOUS les topics de
     * l'équipement, en lecture comme en écriture. Un préfixe contenant `+` ou
     * `#` donnerait un abonnement ou une publication que personne n'a voulus —
     * MQTT 3.1.1 §3.3.2 interdit d'ailleurs les jokers dans un nom de topic de
     * publication, et le broker fermerait la connexion à chaque appui sur un
     * bouton. La documentation Shelly interdit déjà `#`, `+`, `%`, `?` et le `$`
     * initial, et borne le préfixe à 300 caractères ; on est plus strict, parce
     * qu'un préfixe légal côté Shelly n'est pas forcément un préfixe qu'on veut
     * voir apparaître dans un logicalId.
     */
    const PREFIXE_VALIDE = '/^[A-Za-z0-9._\-]+(\/[A-Za-z0-9._\-]+)*$/';

    /* Noms commerciaux, chargés une fois. Comme pour la génération 1, le
     * tableau vide et le « pas encore lu » ne se confondent pas. */
    private $catalogue = null;
    private $cheminCatalogue;

    /* Le `src` de nos appels : il détermine le topic où les réponses arrivent. */
    private $source;

    /* Compteur d'identifiants de requête. Il n'a pas à être global au broker :
     * la corrélation se fait sur le couple (appareil, id). */
    private $prochainId = 1;

    /**
     * @param string|null $_catalogue chemin du catalogue des noms commerciaux ;
     *        null = celui du plugin.
     * @param string|null $_source    le `src` des appels RPC ; null = tiré au
     *        hasard. Les deux paramètres existent pour les contrôles hors ligne,
     *        qui ne connaissent pas l'arborescence installée et qui ont besoin
     *        d'un topic de réponse prévisible. Tous deux sont facultatifs : le
     *        moteur n'instancie que les adapters qui se construisent sans
     *        argument.
     */
    public function __construct($_catalogue = null, $_source = null) {
        $this->cheminCatalogue = ($_catalogue === null)
            ? __DIR__ . '/../../../../core/config/catalog/shelly-gen2.json'
            : $_catalogue;
        /*
         * Un `src` unique par instance, et c'est une nécessité, pas une
         * précaution. Le topic de réponse est `<src>/rpc`, sans aucun préfixe :
         * il est donc PARTAGÉ par tout ce qui l'écoute. Deux Jeedom sur le même
         * broker, ou un Jeedom et un script qui aurait recopié le `user_1` des
         * exemples de la documentation, recevraient chacun les réponses de
         * l'autre et ne pourraient pas les distinguer — rien n'empêche deux
         * clients d'émettre le même `id`.
         */
        $this->source = ($_source === null)
            ? 'mqttbe-' . substr(md5(uniqid('', true)), 0, 10)
            : (string) $_source;
    }

    /* --------------------------------------------------------------------- */
    /* Interface MqttbeAdapter                                               */
    /* --------------------------------------------------------------------- */

    public function id() {
        return self::ID;
    }

    public function priority() {
        return self::PRIORITY;
    }

    /* Le topic où nos réponses RPC arrivent. Public pour les contrôles. */
    public function source() {
        return $this->source;
    }

    public function topicReponses() {
        return $this->source . '/rpc';
    }

    /*
     * Ce qu'on écoute, et rien d'autre.
     *
     * Trois familles, déclinées sur un à trois niveaux de préfixe, plus le
     * topic fixe de l'annonce diffusée et notre propre topic de réponse. Les
     * topics d'exploitation ne sont délibérément pas écoutés ici : `events/rpc`
     * l'est pour la découverte, et sera réabonné par la table de routage une
     * fois les commandes créées. Le moteur et le routage tiennent chacun leurs
     * abonnements ; un topic demandé deux fois n'est souscrit qu'une.
     */
    public function subscriptions() {
        $filtres = array(self::DIFFUSION_ANNONCE, $this->topicReponses());
        $prefixe = '';
        for ($niveaux = 1; $niveaux <= self::PROFONDEUR_MAX; $niveaux++) {
            $prefixe .= '+/';
            $filtres[] = $prefixe . 'online';
            $filtres[] = $prefixe . 'announce';
            $filtres[] = $prefixe . 'events/rpc';
            /* La réponse à `status_update`. Elle n'arrive que si on l'a
             * demandée, mais l'abonnement doit précéder la demande — et un
             * abonnement posé par appareil, résilié par personne, ferait enfler
             * le démon au rythme du parc. */
            $filtres[] = $prefixe . 'status';
        }
        return array_values(array_unique($filtres));
    }

    public function onMessage($_topic, $_payload, $_retained, $_ctx) {
        $topic = (string) $_topic;

        /* Nos réponses RPC d'abord : c'est le seul topic dont on soit sûr qu'il
         * nous est destiné, et le plus fréquent pendant une découverte. */
        if ($topic === $this->topicReponses()) {
            $this->recoitReponse($_ctx, $_payload);
            return;
        }

        if ($topic === self::DIFFUSION_ANNONCE) {
            /* Le préfixe reste inconnu : l'annonce ne le porte pas. On ne
             * retient que l'identité, et le préfixe se déduira du topic d'un
             * `online` ou d'un `events/rpc`, ou à défaut de l'identifiant de
             * l'appareil, qui en est la valeur par défaut. */
            $this->recoitAnnonce($_ctx, '', $_payload);
            return;
        }

        $parties = explode('/', $topic);
        $dernier = array_pop($parties);

        if ($dernier === 'rpc' && !empty($parties) && end($parties) === 'events') {
            array_pop($parties);
            $this->recoitNotification($_ctx, implode('/', $parties), $_payload, $_retained);
            return;
        }
        if ($dernier === 'announce') {
            $this->recoitAnnonce($_ctx, implode('/', $parties), $_payload);
            return;
        }
        if ($dernier === 'status') {
            $this->recoitStatutDemande($_ctx, implode('/', $parties), $_payload);
            return;
        }
        if ($dernier === 'online') {
            $this->recoitDisponibilite($_ctx, implode('/', $parties), $_payload);
        }
    }

    /*
     * Relances, expirations, et surtout : la conversation RPC, qui n'avance que
     * par ici. Appelé au plus une fois par seconde.
     */
    public function onTick($_ctx) {
        $maintenant = $this->maintenant($_ctx);

        $horloge = $this->lit($_ctx, 'horloge');
        if (!isset($horloge['depart'])) {
            $horloge = array('depart' => $maintenant, 'relances' => 0);
        }

        /* La relance demandée par l'utilisateur. Deux chemins, un seul geste —
         * voir ShellyGen1::onTick(), qui fait exactement pareil et pour les
         * mêmes raisons. */
        $relanceDemandee = method_exists($_ctx, 'rescan') && $_ctx->rescan();
        if ($_ctx->recall(self::ID . ':rescan')) {
            $_ctx->forget(self::ID . ':rescan');
            $relanceDemandee = true;
        }
        if ($relanceDemandee) {
            if ($this->demandeAnnonce($_ctx, 'relance demandée')) {
                $this->relanceConversations($_ctx);
            } else {
                $_ctx->remember(self::ID . ':rescan', true);
            }
        }

        $relances = (int) (isset($horloge['relances']) ? $horloge['relances'] : 0);
        while ($relances < count(self::RELANCES)
               && ($maintenant - $horloge['depart']) >= self::RELANCES[$relances]) {
            if (!$this->demandeAnnonce($_ctx, 'demande ' . ($relances + 1) . '/' . count(self::RELANCES))) {
                /* Rien n'est parti : le compteur ne bouge pas. Voir la
                 * génération 1 — compter une demande non partie consommerait à
                 * vide les trois relances après une coupure de courant. */
                break;
            }
            $relances++;
        }
        $horloge['relances'] = $relances;
        $this->ecrit($_ctx, 'horloge', $horloge);

        foreach ($this->inventaire($_ctx) as $cle) {
            $this->avanceConversation($_ctx, $this->dossier($_ctx, $cle), $maintenant);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Découverte active                                                     */
    /* --------------------------------------------------------------------- */

    /*
     * Publier `announce` sur `shellies/command`.
     *
     * La documentation officielle donne ce topic pour fixe et non préfixé. Les
     * Gen2+ l'écoutent d'usine depuis leur version 0.14.0 ; la génération 1 le
     * fait aussi, par un mécanisme antérieur et sans rapport avec ce numéro. Aucune
     * intégration connue ne s'en sert, et un fil communautaire affirme même que
     * cela n'existe pas sur Gen2 : la documentation et la pratique se
     * contredisent, et sans matériel on ne peut pas trancher.
     *
     * D'où le parti pris : on le demande, parce que si cela répond, tout le parc
     * est identifié en un message ; et on ne construit RIEN dessus, parce que si
     * cela ne répond pas, `online` et `events/rpc` découvrent le même parc.
     */
    public function demandeAnnonce($_ctx, $_motif = '') {
        if ($_ctx->publish(self::DIFFUSION_COMMANDE, 'announce', 1) !== true) {
            $_ctx->log('warning', 'Shelly Gen2+ : demande d\'annonce non partie, broker injoignable'
                . ($_motif === '' ? '' : ' (' . $_motif . ')') . ' — reprise au prochain tour.');
            return false;
        }
        $_ctx->log('info', 'Shelly Gen2+ : annonce demandée à tout le parc'
            . ($_motif === '' ? '' : ' (' . $_motif . ')') . '.');
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Réception                                                             */
    /* --------------------------------------------------------------------- */

    /*
     * `<P>/online` : le seul topic retenu d'un Shelly moderne.
     *
     * Il n'apprend qu'une chose — qu'un préfixe existe — mais c'est la seule
     * qu'on ne puisse obtenir nulle part ailleurs sans que l'appareil ait
     * quelque chose à dire. Le broker le rejoue à l'abonnement : tout le parc
     * se signale au démarrage du démon, y compris l'interrupteur qui n'a pas
     * changé d'état depuis trois semaines.
     *
     * La charge utile n'est PAS du JSON : ce sont les mots `true` et `false`,
     * nus. Elle sert ici de filtre, et il est nécessaire — le filtre `+/online`
     * attrape n'importe quel topic de deux niveaux finissant par « online »,
     * et publier un appel RPC à un inconnu serait grossier autant qu'inutile.
     */
    private function recoitDisponibilite($_ctx, $_prefixe, $_payload) {
        $charge = trim((string) $_payload);
        if ($charge !== 'true' && $charge !== 'false') {
            return;
        }

        /*
         * UN `false` NE S'INTERROGE PAS.
         *
         * Ce message-là n'est pas publié par l'appareil : c'est son testament,
         * publié par le BROKER quand la session tombe, et il est retenu. Un
         * appareil débranché depuis six mois le rejoue donc à chaque abonnement,
         * c'est-à-dire à chaque démarrage du démon. L'interroger coûterait trois
         * appels RPC, trois délais d'expiration et une ligne d'avertissement,
         * pour un appareil dont le broker vient précisément de dire qu'il n'est
         * plus là.
         *
         * Aucun dossier n'est donc ouvert sur un `false`. S'il en existe un, la
         * conversation est suspendue : elle repartira au `true` suivant, quand
         * l'appareil reviendra. L'équipement Jeedom, lui, ne bouge pas — c'est
         * le bloc `availability` qui le déclare hors ligne, et c'est son rôle.
         */
        if ($charge === 'false') {
            $this->suspend($_ctx, $_prefixe);
            return;
        }

        $dossier = $this->ouvreDossier($_ctx, $_prefixe, 'online');
        if ($dossier !== null && !empty($dossier['absent'])) {
            $this->reveille($_ctx, $dossier);
        }
    }

    /*
     * Suspendre la conversation d'un appareil que le broker déclare parti.
     *
     * Tout ce qu'on a appris de lui est gardé ; seule la parole s'arrête. La
     * question en vol est oubliée, faute de quoi son expiration consommerait
     * une tentative à l'aveugle, et les trois tentatives seraient brûlées avant
     * même que l'appareil ne soit revenu.
     */
    private function suspend($_ctx, $_prefixe) {
        $prefixe = trim((string) $_prefixe);
        if ($prefixe === '' || !in_array($prefixe, $this->inventaire($_ctx), true)) {
            return;
        }
        $dossier = $this->dossier($_ctx, $prefixe);
        if (!empty($dossier['absent'])) {
            return;
        }
        $this->oublieAttente($_ctx, $dossier);
        $dossier['absent']  = true;
        $dossier['attente'] = array();
        $this->range($_ctx, $dossier);
        $_ctx->log('debug', 'Shelly Gen2+ : ' . $prefixe . ' déclaré hors ligne par le broker — '
            . 'conversation suspendue jusqu\'à son retour.');
    }

    /* Il a reparlé : la conversation reprend là où le testament l'avait
     * laissée, et le compteur de tentatives repart à zéro — les questions
     * perdues l'ont été parce qu'il était absent, pas parce qu'il refuse de
     * répondre. */
    private function reveille($_ctx, $_dossier) {
        $_dossier['absent'] = false;
        $_dossier['essais'] = 0;
        $this->range($_ctx, $_dossier);
        return $_dossier;
    }

    /* Retirer de la table des questions en vol celle que cette fiche attendait.
     * Sans cela, la réponse tardive d'un appareil réanimerait une conversation
     * qu'on a close, et la table ne se viderait qu'au plafond. */
    private function oublieAttente($_ctx, $_dossier) {
        $attente = isset($_dossier['attente']) && is_array($_dossier['attente'])
            ? $_dossier['attente'] : array();
        if (empty($attente)) {
            return;
        }
        $attentes = $this->lit($_ctx, 'attentes');
        unset($attentes[(string) $this->texte($attente, 'numero')]);
        $this->ecrit($_ctx, 'attentes', $attentes);
    }

    /*
     * `shellies/announce` et `<P>/announce` : la charge utile de
     * `Shelly.GetDeviceInfo`, sans enveloppe RPC.
     *
     * C'est l'identité complète en un seul message, et sans avoir rien demandé
     * à personne. Reste que la génération 1 répond sur le MÊME topic fixe
     * `shellies/announce` : le champ `gen`, qu'elle ne publie nulle part,
     * tranche dans les deux sens — l'adapter Gen1 refuse désormais ce qui porte
     * `gen >= 2`, celui-ci refuse ce qui ne le porte pas.
     */
    private function recoitAnnonce($_ctx, $_prefixe, $_payload) {
        $annonce = $this->json($_ctx, $_payload, 'annonce');
        if ($annonce === null) {
            return;
        }
        $generation = isset($annonce['gen']) ? (int) $annonce['gen'] : 0;
        if ($generation < 2) {
            /* Pas un mot de plus : sur un parc Gen1 de vingt appareils, chaque
             * demande d'annonce produirait vingt lignes de journal disant que
             * tout va bien. */
            return;
        }
        $identifiant = $this->texte($annonce, 'id');
        if ($identifiant === '') {
            $_ctx->log('warning', 'Shelly Gen2+ : annonce sans identifiant, ignorée.');
            return;
        }

        /*
         * Le préfixe, quand l'annonce ne le porte pas.
         *
         * `shellies/announce` est un topic fixe : il ne dit pas où joindre
         * l'appareil, et `Shelly.GetDeviceInfo` ne contient pas
         * `mqtt.topic_prefix`. Or c'est le préfixe, et lui seul, qui compose
         * tous les topics de l'équipement.
         *
         * Deux sources, dans cet ordre : ce qu'on a déjà appris d'un vrai topic
         * — un `online` ou un `events/rpc` portent le préfixe dans leur nom —
         * et, à défaut, l'identifiant de l'appareil, qui est la valeur d'usine
         * du préfixe. La seconde est une supposition, et elle est marquée comme
         * telle : si elle est fausse, l'appel RPC partira dans le vide, aucune
         * réponse n'arrivera, et l'appareil restera un candidat sans modèle
         * plutôt que de devenir un équipement aux topics inventés.
         */
        $prefixe = (string) $_prefixe;
        $suppose = false;
        if ($prefixe === '') {
            $connu = $this->prefixeDe($_ctx, $identifiant);
            if ($connu !== '') {
                $prefixe = $connu;
            } else {
                $prefixe = $identifiant;
                $suppose = true;
            }
        }

        $dossier = $this->ouvreDossier($_ctx, $prefixe, 'annonce');
        if ($dossier === null) {
            return;
        }
        $dossier['info']    = $annonce;
        $dossier['suppose'] = $suppose;
        $dossier['vu']      = $this->maintenant($_ctx);
        $this->range($_ctx, $dossier);
        $this->nommePrefixe($_ctx, $identifiant, $prefixe);
    }

    /*
     * `<P>/events/rpc` : NotifyStatus, NotifyFullStatus et NotifyEvent.
     *
     * Trois choses en sortent, et la troisième est la plus précieuse :
     *   - le préfixe, lu dans le nom du topic ;
     *   - l'identifiant de l'appareil, dans le champ `src` ;
     *   - un inventaire de composants, dans les clés de `params`.
     *
     * Cet inventaire est ce qui permet de décrire un appareil auquel on n'a
     * jamais parlé. Pour un appareil sur pile, c'est même la seule voie : il
     * dort, ne répondra à aucun appel, et pousse un `NotifyFullStatus` complet
     * au réveil. Une trame partielle, elle, ne donne qu'un inventaire partiel —
     * d'où la confiance `probable` tant qu'aucune réponse complète n'est venue.
     */
    private function recoitNotification($_ctx, $_prefixe, $_payload, $_retained) {
        $trame = $this->json($_ctx, $_payload, 'notification');
        if ($trame === null) {
            return;
        }
        $methode = $this->texte($trame, 'method');
        /*
         * LE DRAPEAU `retained` N'EST PAS UN DÉTAIL.
         *
         * `events/rpc` n'est pas censé être retenu, mais rien n'empêche un
         * broker ou un pont mal réglé de le retenir — et alors, à chaque
         * abonnement, le dernier appui sur un bouton est rejoué comme s'il
         * venait de se produire. Un état rejoué ne coûte rien : il dit ce qui
         * est. Un ÉVÉNEMENT rejoué déclenche un scénario que personne n'a
         * demandé, plusieurs semaines après le geste qui l'a produit.
         */
        if ($_retained && $methode === 'NotifyEvent') {
            $_ctx->log('debug', 'Shelly Gen2+ : événement retenu ignoré sur ' . $_prefixe
                . ' — il dit un appui passé, pas un appui qui vient d\'avoir lieu.');
            return;
        }
        if ($methode !== 'NotifyStatus' && $methode !== 'NotifyFullStatus'
            && $methode !== 'NotifyEvent') {
            return;
        }
        $params = (isset($trame['params']) && is_array($trame['params'])) ? $trame['params'] : array();
        unset($params['ts']);
        if (empty($params)) {
            return;
        }

        $dossier = $this->ouvreDossier($_ctx, $_prefixe, 'notification');
        if ($dossier === null) {
            return;
        }
        /*
         * L'IDENTITÉ S'APPREND DE TOUTE TRAME, Y COMPRIS D'UN ÉVÉNEMENT.
         *
         * Un `NotifyEvent` porte `src` exactement comme un `NotifyStatus`, et
         * il est parfois le seul à le porter : un i4 dont les quatre entrées
         * sont en mode bouton ne publie aucun état d'entrée — il n'émet que des
         * événements. Jeter la trame avant d'en avoir tiré le couple
         * (identifiant, préfixe) reviendrait à ignorer le seul appareil qui
         * parle.
         */
        $identifiant = $this->texte($trame, 'src');
        if ($identifiant !== '') {
            $dossier['src'] = $identifiant;
            $this->nommePrefixe($_ctx, $identifiant, $_prefixe);
        }
        /* Il parle : il est donc là, quoi qu'en dise un testament retenu. */
        $dossier['absent'] = false;

        if ($methode === 'NotifyEvent') {
            $dossier = $this->recoitEvenements($_ctx, $dossier, $params);
            $dossier['vu'] = $this->maintenant($_ctx);
            $this->range($_ctx, $dossier);
            return;
        }

        /*
         * Fusionner, jamais remplacer — et `null` veut dire « cette clé n'existe
         * plus », pas « cette clé vaut null ». C'est écrit noir sur blanc dans
         * la documentation, et un merge naïf garderait éternellement des
         * composants que l'appareil a perdus : une sonde débranchée, un script
         * supprimé, un capteur BTHome qui ne répond plus.
         */
        /* L'inventaire tel qu'il est AVANT cette trame. Comparé à celui
         * d'après, il dit si la trame a ajouté ou retiré quelque chose — et
         * c'est la seule chose qui justifie de reconstruire un modèle. */
        $avant = $this->signatureInventaire($dossier);

        $observes = isset($dossier['observes']) && is_array($dossier['observes'])
            ? $dossier['observes'] : array();
        foreach ($params as $cle => $etat) {
            $cle = $this->cleComposant($cle);
            if ($cle === '') {
                continue;
            }
            if ($etat === null) {
                unset($observes[$cle]);
                continue;
            }
            if (!is_array($etat)) {
                continue;
            }
            $observes[$cle] = ($methode === 'NotifyFullStatus' || !isset($observes[$cle]))
                ? $etat : array_merge($observes[$cle], $etat);
            /*
             * Et la même règle un cran plus bas, qui est d'ailleurs celle que la
             * documentation énonce : « certaines CLÉS DE STATUT n'existent que
             * dans certaines situations ; quand elles disparaissent, la charge
             * utile les porte avec la valeur `null` ». Un `array_merge` les
             * garderait telles quelles, et la garde qui décide de créer un canal
             * est une simple présence de clé : un champ disparu fabriquerait une
             * commande éternellement vide.
             */
            foreach ($observes[$cle] as $champ => $valeur) {
                if ($valeur !== null) {
                    continue;
                }
                unset($observes[$cle][$champ]);
                /* Et le champ disparaît aussi de ce que la conversation RPC
                 * avait rapporté : cet inventaire-là est une photographie, prise
                 * une fois. L'appareil vient de dire que le champ n'existe plus
                 * — une sonde débranchée, un tore retiré —, et le garder ferait
                 * survivre la commande à la chose qu'elle mesurait. */
                if (isset($dossier['composants'][$cle]['status'][$champ])) {
                    unset($dossier['composants'][$cle]['status'][$champ]);
                }
            }
        }
        $dossier['observes'] = $observes;
        $dossier = $this->surveilleRevision($_ctx, $dossier, $params);
        if ($methode === 'NotifyFullStatus') {
            /* Un inventaire complet, poussé spontanément : c'est tout ce qu'on
             * obtiendra jamais d'un appareil sur pile, et cela vaut une
             * conversation RPC réussie. */
            $dossier['complet'] = true;
        }
        $dossier['vu'] = $this->maintenant($_ctx);
        $this->range($_ctx, $dossier);

        if ($methode === 'NotifyFullStatus') {
            $this->emet($_ctx, $dossier);
            return;
        }

        /*
         * UN DELTA QUI CHANGE L'INVENTAIRE VAUT UNE RÉÉMISSION.
         *
         * Un `NotifyStatus` ordinaire ne fait que porter des valeurs : les
         * commandes existent déjà, le routeur les remplit, et reconstruire un
         * modèle à chaque trame coûterait cher sur un compteur d'énergie qui en
         * publie plusieurs par seconde. Mais la même trame peut aussi annoncer
         * qu'un champ a DISPARU — une sonde débranchée, un tore retiré — ou
         * qu'un composant est apparu. Le modèle n'est alors plus celui de
         * l'appareil, et personne d'autre ne viendra le dire : la conversation
         * est close, et rien ne la rouvre.
         */
        if ($this->signatureInventaire($dossier) !== $avant) {
            $this->emet($_ctx, $dossier);
        }
    }

    /* Les clés de l'inventaire, et elles seules : les composants, et le nom des
     * champs de chacun. Les VALEURS n'y sont pas — elles changent sans cesse, et
     * ce n'est pas ce qu'on surveille ici. */
    private function signatureInventaire($_dossier) {
        $signature = array();
        foreach ($this->composantsDe($_dossier) as $cle => $composant) {
            $champs = (isset($composant['status']) && is_array($composant['status']))
                ? array_keys($composant['status']) : array();
            sort($champs);
            $signature[] = $cle . ':' . implode(',', $champs);
        }
        return implode('|', $signature);
    }

    /*
     * Les événements d'un `NotifyEvent`, et ce qu'ils apprennent de l'appareil.
     *
     * Trois d'entre eux comptent ici, et ils disent tous la même chose :
     * L'INVENTAIRE QU'ON A N'EST PLUS CELUI DE L'APPAREIL.
     *
     *   `config_changed`    — commun à tous les composants. Il part quand on
     *                         renomme une sortie dans l'application Shelly,
     *                         quand on change le profil d'un 2PM, quand on règle
     *                         une entrée en bouton. Tout cela change les
     *                         commandes qu'il faut créer.
     *   `component_added`   — un composant virtuel, un capteur BTHome ou un
     *   `component_removed`   script apparaît ou disparaît.
     *
     * Sans cette relecture, un équipement resterait figé sur l'inventaire du
     * jour de sa découverte jusqu'à ce que quelqu'un pense à relancer la
     * découverte à la main — c'est-à-dire, en pratique, pour toujours.
     *
     * Les autres événements ne sont pas traités ici : ils vont aux commandes
     * d'événement, par la table de routage, et c'est Jeedom qui les voit.
     */
    private function recoitEvenements($_ctx, $_dossier, $_params) {
        $evenements = (isset($_params['events']) && is_array($_params['events']))
            ? $_params['events'] : array();
        foreach ($evenements as $evenement) {
            if (!is_array($evenement)) {
                continue;
            }
            $nom = $this->texte($evenement, 'event');
            if ($nom !== 'config_changed' && $nom !== 'component_added'
                && $nom !== 'component_removed') {
                continue;
            }
            return $this->reprendInventaire($_ctx, $_dossier,
                $nom . ' sur ' . $this->texte($evenement, 'component'));
        }
        return $_dossier;
    }

    /*
     * `sys.cfg_rev` : le même avertissement, par une autre voie.
     *
     * La révision de configuration accompagne chaque réponse de
     * `Shelly.GetComponents` et reparaît dans les notifications de `sys`. Elle
     * change à chaque modification de l'appareil. La surveiller rattrape le cas
     * où l'événement `config_changed` s'est perdu — il n'est pas retenu, et un
     * démon arrêté au mauvais moment ne le verra jamais.
     */
    private function surveilleRevision($_ctx, $_dossier, $_params) {
        if (!isset($_params['sys']['cfg_rev']) || !is_numeric($_params['sys']['cfg_rev'])) {
            return $_dossier;
        }
        $vue = (int) $_params['sys']['cfg_rev'];
        $connue = isset($_dossier['cfg_rev']) ? (int) $_dossier['cfg_rev'] : 0;
        if ($connue === 0 || $vue === $connue) {
            $_dossier['cfg_rev'] = $vue;
            return $_dossier;
        }
        $_dossier['cfg_rev'] = $vue;
        return $this->reprendInventaire($_ctx, $_dossier,
            'révision de configuration ' . $connue . ' → ' . $vue);
    }

    /*
     * Reposer la question de l'inventaire, et elle seule.
     *
     * L'identité ne bouge pas — une MAC ne change pas parce qu'on a renommé une
     * sortie —, donc la conversation reprend à l'énumération et non au début.
     *
     * Et l'inventaire connu n'est PAS effacé tout de suite : il l'est à
     * l'arrivée de la première page. Entre les deux, l'appareil peut très bien
     * ne jamais répondre ; l'effacer d'avance transformerait un équipement
     * complet en équipement vide, pour cause de renommage d'une sortie.
     */
    private function reprendInventaire($_ctx, $_dossier, $_motif) {
        if ($this->texte($_dossier, 'prefixe') === '') {
            return $_dossier;
        }
        $this->oublieAttente($_ctx, $_dossier);
        $_dossier['etape']      = 'composants';
        $_dossier['offset']     = 0;
        $_dossier['pages']      = 0;
        $_dossier['essais']     = 0;
        $_dossier['attente']    = array();
        $_dossier['renouvelle'] = true;
        $_ctx->log('info', 'Shelly Gen2+ : ' . $this->texte($_dossier, 'prefixe')
            . ' a changé (' . $_motif . ') — son inventaire est redemandé.');
        return $_dossier;
    }

    /*
     * `<P>/status` : le statut complet, en réponse à `status_update`.
     *
     * La seconde porte de l'en-tête. Elle donne ce que `Shelly.GetStatus`
     * aurait donné — les mêmes clés de composants — sans le RPC, donc sans
     * dépendre de `enable_rpc`. Il lui manque la configuration, et donc les noms
     * que le propriétaire a donnés à ses sorties : les commandes porteront leur
     * numéro. C'est un secours, et un secours qui marche vaut mieux qu'une voie
     * royale qui se tait.
     *
     * AUCUN DOSSIER NE S'OUVRE ICI. Le filtre `+/status` attrape n'importe quel
     * topic de deux niveaux finissant par « status », et il y en a sur un broker
     * partagé — `homeassistant/status` pour ne citer que celui-là. On ne lit
     * donc ce message que pour un préfixe déjà repéré par un `online`, une
     * annonce ou une notification.
     */
    private function recoitStatutDemande($_ctx, $_prefixe, $_payload) {
        $prefixe = trim((string) $_prefixe);
        if ($prefixe === '' || !in_array($prefixe, $this->inventaire($_ctx), true)) {
            return;
        }
        $statut = $this->json($_ctx, $_payload, 'statut demandé');
        if ($statut === null || empty($statut)) {
            return;
        }
        $dossier = $this->dossier($_ctx, $prefixe);
        $etape   = $this->texte($dossier, 'etape');

        $dossier = $this->rangeStatuts($dossier, $statut, 'status');
        if (empty($dossier['composants'])) {
            return;
        }
        $dossier['absent'] = false;
        $dossier['vu']     = $this->maintenant($_ctx);

        /*
         * Une conversation RPC en cours n'est pas interrompue pour autant.
         *
         * Ce message peut aussi arriver sans qu'on l'ait demandé — chez qui a
         * mis `status_ntf` à `true`. Il enrichit alors l'inventaire, mais
         * conclure sur lui ferait perdre la configuration que la conversation
         * allait ramener, c'est-à-dire les noms des sorties.
         */
        if ($etape !== '' && $etape !== 'fini' && $etape !== 'controle') {
            $this->range($_ctx, $dossier);
            return;
        }

        $dossier['etape']   = 'fini';
        $dossier['complet'] = true;
        $dossier['attente'] = array();
        $this->range($_ctx, $dossier);
        $_ctx->log('info', 'Shelly Gen2+ : ' . $prefixe . ' a répondu à status_update — '
            . count($dossier['composants']) . ' composants, sans passer par le RPC.');
        $this->emet($_ctx, $dossier);
    }

    /*
     * Une réponse RPC, sur notre propre topic.
     *
     * Ce topic n'est préfixé par rien : il ramène les réponses de TOUT le parc,
     * et l'identité de celui qui répond n'est que dans le champ `src` de la
     * charge utile. D'où la corrélation par le numéro de requête, que nous
     * seuls avons attribué, plus la vérification que `dst` est bien notre `src`
     * — un autre client ayant recopié le « user_1 » des exemples de la
     * documentation recevrait les mêmes messages que nous, et nous les siens.
     */
    private function recoitReponse($_ctx, $_payload) {
        $trame = $this->json($_ctx, $_payload, 'réponse RPC');
        if ($trame === null) {
            return;
        }
        /*
         * `dst` : vérifié quand il est là, jamais exigé.
         *
         * La page normative le donne pour obligatoire dans une réponse, et
         * c'est bien ce que les appareils publient. Mais les exemples engendrés
         * du site — ceux d'après lesquels un micrologiciel a pu être écrit — le
         * montrent absent, et ce sont exactement ceux dont la clé s'appelle
         * `params` au lieu de `result`. L'exiger ferait donc jeter en silence la
         * réponse même que le repli plus bas prétend rattraper. La corrélation
         * par le numéro de requête suffit à se l'approprier : ce numéro, nous
         * seuls l'avons attribué. Un `dst` présent et étranger, lui, reste
         * refusé — c'est la réponse d'un autre client.
         */
        $destinataire = $this->texte($trame, 'dst');
        if ($destinataire !== '' && $destinataire !== $this->source) {
            return;
        }
        if (!isset($trame['id'])) {
            return;
        }
        $numero = (string) $trame['id'];
        $attentes = $this->lit($_ctx, 'attentes');
        if (!isset($attentes[$numero]) || !is_array($attentes[$numero])) {
            /* Une réponse qu'on n'attend plus : requête expirée, ou doublon
             * livré deux fois — la liaison est en QoS 1, « au moins une fois ».
             * Ce n'est pas une anomalie. */
            return;
        }
        $attente = $attentes[$numero];
        unset($attentes[$numero]);
        $this->ecrit($_ctx, 'attentes', $attentes);

        $prefixe = isset($attente['prefixe']) ? (string) $attente['prefixe'] : '';
        $methode = isset($attente['methode']) ? (string) $attente['methode'] : '';
        if ($prefixe === '') {
            return;
        }
        $dossier = $this->dossier($_ctx, $prefixe);
        $dossier['vu'] = $this->maintenant($_ctx);
        $dossier['absent'] = false;
        /* La question est répondue : sans cet oubli, la fiche resterait en
         * attente et la question SUIVANTE ne partirait jamais — la conversation
         * s'arrêterait sur son premier échange, silencieusement. */
        $dossier['attente'] = array();

        $identifiant = $this->texte($trame, 'src');
        if ($identifiant !== '') {
            $dossier['src'] = $identifiant;
            $this->nommePrefixe($_ctx, $identifiant, $prefixe);
        }

        if (isset($trame['error']) && is_array($trame['error'])) {
            $this->recoitErreur($_ctx, $dossier, $methode, $trame['error']);
            return;
        }
        /*
         * `result`, et `params` en repli.
         *
         * La page normative impose `result`. Mais les exemples engendrés du
         * site montrent par endroits une clé `params` à sa place, et un
         * micrologiciel pourrait très bien avoir été écrit d'après ces
         * exemples-là. Lire les deux ne coûte rien et évite de conclure à une
         * panne là où il n'y a qu'une clé mal nommée.
         */
        $resultat = null;
        if (isset($trame['result']) && is_array($trame['result'])) {
            $resultat = $trame['result'];
        } elseif (isset($trame['params']) && is_array($trame['params'])) {
            $resultat = $trame['params'];
        }
        if ($resultat === null) {
            $this->range($_ctx, $dossier);
            return;
        }

        switch ($methode) {
            case 'Shelly.GetDeviceInfo':
                $dossier['info'] = $resultat;
                $dossier['etape'] = 'composants';
                break;
            case 'Shelly.GetComponents':
                $dossier = $this->rangeComposants($_ctx, $dossier, $resultat);
                break;
            case 'Shelly.GetStatus':
                $dossier = $this->rangeStatuts($dossier, $resultat, 'status');
                $dossier['etape'] = 'config';
                break;
            case 'Shelly.GetConfig':
                $dossier = $this->rangeStatuts($dossier, $resultat, 'config');
                $dossier['etape'] = 'fini';
                $dossier['complet'] = true;
                break;
            default:
                break;
        }
        $dossier['essais'] = 0;
        $this->range($_ctx, $dossier);

        if (isset($dossier['etape']) && $dossier['etape'] === 'fini') {
            $this->emet($_ctx, $dossier);
        }
    }

    /*
     * Une erreur RPC. Une seule mérite un traitement : « je ne connais pas
     * cette méthode ».
     *
     * `Shelly.GetComponents` n'existe pas sur les micrologiciels antérieurs à
     * la 1.2.0 — la documentation ne dit pas quand elle est apparue, mais des
     * appareils en 1.0.3 la refusent, et l'intégration de référence la garde
     * derrière la version du 13 février 2024. Le code rendu n'est pas dans la
     * liste des erreurs communes : c'est un 404 accompagné de « No handler
     * for … ». Le repli est `Shelly.GetStatus` puis `Shelly.GetConfig`, dont
     * l'union des clés donne le même inventaire DES COMPOSANTS STATIQUES — les
     * composants dynamiques (200 à 299 : virtuels, BTHome, scripts) ne sont
     * énumérés que par `Shelly.GetComponents`. Il n'y a rien à perdre : ces
     * composants-là n'existent pas sur les micrologiciels qui refusent la
     * méthode. Le repli coûte les deux plus grosses réponses de toute l'API, ce
     * pour quoi il reste un repli et non la voie normale.
     *
     * ET SEUL UN 404 LE DÉCLENCHE. Un `-103 INVALID ARGUMENT` ou un `-108` sont
     * d'autres erreurs, qui veulent dire autre chose ; les traiter comme une
     * méthode inconnue écrirait dans le journal une cause fausse, et c'est ce
     * journal qu'on lira le jour où la découverte n'aboutira pas.
     */
    private function recoitErreur($_ctx, $_dossier, $_methode, $_erreur) {
        $code    = isset($_erreur['code']) ? (int) $_erreur['code'] : 0;
        $message = isset($_erreur['message']) ? (string) $_erreur['message'] : '';
        $prefixe = $this->texte($_dossier, 'prefixe');
        $inconnue = ($code === 404 || stripos($message, 'no handler') !== false);

        if ($_methode === 'Shelly.GetComponents' && $inconnue) {
            $_ctx->log('info', 'Shelly Gen2+ : ' . $prefixe . ' ne connaît pas Shelly.GetComponents ('
                . $code . ' ' . $this->citation($message) . ') — repli sur GetStatus puis GetConfig.');
            $_dossier['etape']  = 'status';
            $_dossier['essais'] = 0;
            unset($_dossier['composants']);
            $this->range($_ctx, $_dossier);
            return;
        }

        $_ctx->log('warning', 'Shelly Gen2+ : ' . $prefixe . ' a refusé ' . $_methode . ' ('
            . $code . ' ' . $this->citation($message) . ') — ce n\'est pas « méthode inconnue », '
            . 'et l\'inventaire restera ce qu\'on en sait.');
        /* On ne réessaie pas une méthode que l'appareil vient de refuser : la
         * réponse serait la même, et trois fois la même erreur dans le journal
         * n'apprend rien de plus que la première. On conclut avec ce qu'on a. */
        $_dossier['etape']  = 'fini';
        $_dossier['essais'] = 0;
        $this->range($_ctx, $_dossier);
        $this->emet($_ctx, $_dossier);
    }

    /* --------------------------------------------------------------------- */
    /* La conversation                                                       */
    /* --------------------------------------------------------------------- */

    /*
     * Un appareil, une question à la fois.
     *
     * La file de publication d'un Shelly est bornée à trente messages, et avant
     * le micrologiciel 1.4.0 une seule publication pouvait être en vol : une
     * rafale de quatre requêtes se perd en silence, sans erreur et sans trace.
     * D'où cette machine à états — une question, la réponse, la question
     * suivante — et d'où, aussi, le fait que tout se passe dans onTick : rien
     * n'attend, rien ne bloque, la boucle du démon continue de lire la socket
     * du broker pendant que l'appareil réfléchit.
     */
    private function avanceConversation($_ctx, $_dossier, $_maintenant) {
        $prefixe = $this->texte($_dossier, 'prefixe');
        if ($prefixe === '') {
            return;
        }
        $etape = $this->texte($_dossier, 'etape');
        if ($etape === '' || $etape === 'fini') {
            return;
        }
        /* Le broker l'a déclaré parti : on ne parle pas à une session fermée.
         * Son retour rouvrira la conversation, et pas une seconde avant. */
        if (!empty($_dossier['absent'])) {
            return;
        }

        /*
         * UN APPAREIL SUR PILE NE S'INTERROGE PAS.
         *
         * Il dort. Il se réveille quelques secondes, pousse son état complet,
         * et se rendort — la documentation parle d'une fenêtre de deux
         * secondes, qu'aucune intégration lue n'exploite. Lui envoyer des
         * appels revient à parler à une porte close : trois tentatives, trois
         * délais d'expiration, et une ligne d'avertissement par appareil et par
         * découverte. Son `NotifyFullStatus` dit déjà tout ce qu'on peut savoir.
         */
        if ($this->dortSurPile($_dossier)) {
            $_dossier['etape'] = 'fini';
            $this->range($_ctx, $_dossier);
            $this->emet($_ctx, $_dossier);
            return;
        }

        /* La seconde porte a été frappée : on attend sa réponse, puis on
         * conclut. Aucune question ne part d'ici — `status_update` est déjà
         * publié, et le répéter n'apprendrait rien. */
        if ($etape === 'controle') {
            if (($_maintenant - $this->nombre($_dossier, 'controle')) < self::DELAI_CONTROLE) {
                return;
            }
            $_ctx->log('info', 'Shelly Gen2+ : ' . $prefixe . ' n\'a répondu ni au RPC ni à '
                . 'status_update ; on conclut avec ce qui est connu.');
            $_dossier['etape'] = 'fini';
            $this->range($_ctx, $_dossier);
            $this->emet($_ctx, $_dossier);
            return;
        }

        $attente = isset($_dossier['attente']) && is_array($_dossier['attente'])
            ? $_dossier['attente'] : array();
        if (!empty($attente)) {
            $depuis = $this->nombre($attente, 'depuis');
            if (($_maintenant - $depuis) < self::DELAI_REPONSE) {
                return;
            }
            /* Le délai est passé : la requête est oubliée, et la même sera
             * reposée ci-dessous si le quota de tentatives le permet. */
            $attentes = $this->lit($_ctx, 'attentes');
            unset($attentes[(string) $this->texte($attente, 'numero')]);
            $this->ecrit($_ctx, 'attentes', $attentes);
            $_dossier['attente'] = array();
            $_dossier['essais']  = (int) $this->nombre($_dossier, 'essais') + 1;
        }

        if ((int) $this->nombre($_dossier, 'essais') >= self::TENTATIVES) {
            /*
             * Trois questions sans réponse. Avant de conclure, la seconde porte.
             *
             * Le silence ne prouve rien : des Gen2 sur SECTEUR restent muets au
             * RPC tout en publiant leur télémétrie, et un appareil dont
             * `enable_rpc` a été coupé ne répondra jamais, quel que soit le
             * nombre de tentatives. « MQTT control » ne dépend pas de ce
             * réglage-là : c'est une autre porte, ouverte d'usine, et elle ne
             * coûte que deux publications.
             *
             * Si elle reste close elle aussi, on conclut avec ce qu'on a —
             * l'identité, et ce que les notifications ont montré. Cela donne un
             * équipement `probable`, qui deviendra `certain` le jour où
             * l'appareil parlera. Le faire disparaître de la liste serait faux ;
             * le laisser en conversation éternelle le ferait interroger à chaque
             * battement.
             */
            $this->demandeParControle($_ctx, $_dossier, $etape);
            return;
        }

        switch ($etape) {
            case 'info':
                $this->demandeRpc($_ctx, $_dossier, 'Shelly.GetDeviceInfo', null);
                break;
            case 'composants':
                $this->demandeRpc($_ctx, $_dossier, 'Shelly.GetComponents', array(
                    'offset'  => (int) $this->nombre($_dossier, 'offset'),
                    /* Sans `keys` : ce paramètre n'existe qu'à partir du
                     * micrologiciel 1.5.0, et un appareil plus ancien
                     * répondrait « argument invalide » à une requête par
                     * ailleurs parfaitement légitime. */
                    'include' => array('status', 'config'),
                ));
                break;
            case 'status':
                $this->demandeRpc($_ctx, $_dossier, 'Shelly.GetStatus', null);
                break;
            case 'config':
                $this->demandeRpc($_ctx, $_dossier, 'Shelly.GetConfig', null);
                break;
            default:
                break;
        }
    }

    /*
     * Frapper à la seconde porte : `announce` et `status_update` sur
     * `<P>/command`.
     *
     * Les deux commandes du « MQTT control », écoutées d'usine — `enable_control`
     * vaut `true` depuis la version 0.14.0 — et qui ne demandent RIEN à
     * l'utilisateur : le critère de ce jalon est qu'un appareil soit découvert
     * sans qu'on touche à sa configuration, et c'est bien le cas.
     *
     * `announce` ramène l'identité sur `<P>/announce`, topic auquel on est déjà
     * abonné ; `status_update` ramène le statut complet sur `<P>/status`. Un
     * appareil qui répond à l'une ou à l'autre devient un équipement complet
     * sans qu'un seul appel RPC n'ait abouti.
     *
     * Rien n'en dépend : si les deux restent sans réponse, la conversation se
     * conclut comme avant, avec ce qu'on sait.
     */
    private function demandeParControle($_ctx, $_dossier, $_etape) {
        $prefixe = $this->texte($_dossier, 'prefixe');
        $partie  = ($_ctx->publish($prefixe . '/command', 'announce', 1) === true);
        $partie  = ($_ctx->publish($prefixe . '/command', 'status_update', 1) === true) && $partie;
        if (!$partie) {
            /* Broker injoignable : rien n'est consommé, on repassera. */
            return false;
        }
        $_ctx->log('info', 'Shelly Gen2+ : ' . $prefixe . ' muet au RPC après ' . self::TENTATIVES
            . ' tentatives sur ' . $_etape . ' — on lui demande de s\'annoncer et de publier son '
            . 'statut, ce qui ne dépend pas du RPC.');
        $_dossier['etape']    = 'controle';
        $_dossier['controle'] = $this->maintenant($_ctx);
        $_dossier['essais']   = 0;
        $_dossier['attente']  = array();
        $this->range($_ctx, $_dossier);
        return true;
    }

    /*
     * Poser une question. La réponse arrivera — ou n'arrivera pas — sur
     * `<src>/rpc`, et c'est onTick qui s'apercevra du silence.
     */
    private function demandeRpc($_ctx, $_dossier, $_methode, $_params) {
        $prefixe = $this->texte($_dossier, 'prefixe');
        $numero  = $this->prochainId++;
        $requete = array('id' => $numero, 'src' => $this->source, 'method' => $_methode);
        if (is_array($_params)) {
            $requete['params'] = $_params;
        }
        $charge = json_encode($requete, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        /* QoS 1 : c'est le seul niveau que la documentation reconnaisse sur ce
         * canal, et une question perdue coûte cinq secondes d'attente. Une
         * question livrée deux fois ne coûte, elle, rien du tout — ce sont des
         * lectures, et la corrélation par numéro range la seconde réponse. */
        if ($_ctx->publish($prefixe . '/rpc', $charge, 1) !== true) {
            /* Le broker n'est pas là. Ni tentative consommée, ni attente
             * enregistrée : la même question repartira au tour suivant. */
            return false;
        }

        $attentes = $this->lit($_ctx, 'attentes');
        /*
         * La table des questions en vol, bornée.
         *
         * Elle est tenue dans UNE clé de mémoire et non une par requête : le
         * plafond du moteur est de 512 clés par adapter, et une clé par
         * question posée le ferait sauter sur un parc de deux cents appareils.
         * Au-delà de cette borne, les plus anciennes sortent — elles ont de
         * toute façon dépassé leur délai.
         */
        if (count($attentes) > 256) {
            $attentes = array_slice($attentes, -64, null, true);
        }
        $attentes[(string) $numero] = array('prefixe' => $prefixe, 'methode' => $_methode);
        $this->ecrit($_ctx, 'attentes', $attentes);

        $_dossier['attente'] = array('numero' => (string) $numero, 'methode' => $_methode,
                                     'depuis' => $this->maintenant($_ctx));
        $this->range($_ctx, $_dossier);
        return true;
    }

    /*
     * Une page de `Shelly.GetComponents`.
     *
     * La taille d'une page est imposée par l'appareil — il n'y a ni `limit` ni
     * `count` —, et c'est justement ce qui rend la pagination obligatoire :
     * supposer qu'un appel suffit revient à perdre en silence les composants
     * d'un appareil chargé. La boucle suit `total` et `offset`, avec deux
     * garde-fous : une page vide alors qu'il reste des composants arrête tout
     * (sinon la boucle ne finit jamais), et le nombre de pages est borné.
     */
    private function rangeComposants($_ctx, $_dossier, $_resultat) {
        $composants = isset($_resultat['components']) && is_array($_resultat['components'])
            ? $_resultat['components'] : array();
        $total = isset($_resultat['total']) ? (int) $_resultat['total'] : 0;
        $debut = isset($_resultat['offset']) ? (int) $_resultat['offset'] : (int) $this->nombre($_dossier, 'offset');

        /*
         * Une révision de configuration qui change EN COURS DE PAGINATION.
         *
         * `cfg_rev` accompagne chaque page. S'il bouge entre deux — un composant
         * virtuel ajouté, un script supprimé pendant qu'on énumère —, les index
         * glissent : la page suivante ne reprend pas où la précédente s'est
         * arrêtée, et l'inventaire assemblé mélange deux états sans qu'aucun
         * doublon ne le trahisse. On recommence, c'est la seule issue honnête.
         */
        $revision = isset($_resultat['cfg_rev']) ? (int) $_resultat['cfg_rev'] : 0;
        $connue   = isset($_dossier['cfg_rev']) ? (int) $_dossier['cfg_rev'] : 0;
        if ($revision !== 0 && $connue !== 0 && $revision !== $connue
            && (int) $this->nombre($_dossier, 'pages') > 0) {
            $_ctx->log('info', 'Shelly Gen2+ : ' . $this->texte($_dossier, 'prefixe')
                . ' a changé de configuration pendant l\'énumération (' . $connue . ' → '
                . $revision . ') — on recommence, un inventaire à cheval sur deux états '
                . 'ne vaut rien.');
            $_dossier['cfg_rev']    = $revision;
            $_dossier['offset']     = 0;
            $_dossier['pages']      = 0;
            $_dossier['etape']      = 'composants';
            $_dossier['renouvelle'] = true;
            return $_dossier;
        }

        $connus = isset($_dossier['composants']) && is_array($_dossier['composants'])
            ? $_dossier['composants'] : array();
        /*
         * L'inventaire précédent ne s'efface qu'ICI, à l'arrivée de la première
         * page du nouveau. Entre la demande et la réponse, l'appareil peut très
         * bien se taire : l'effacer d'avance ferait d'un équipement complet un
         * équipement vide, pour cause de renommage d'une sortie.
         */
        if (!empty($_dossier['renouvelle']) && $debut === 0) {
            $connus = array();
            unset($_dossier['renouvelle']);
        }
        foreach ($composants as $composant) {
            if (!is_array($composant)) {
                continue;
            }
            $cle = $this->cleComposant($this->texte($composant, 'key'));
            if ($cle === '') {
                continue;
            }
            $connus[$cle] = array(
                'status' => (isset($composant['status']) && is_array($composant['status']))
                    ? $composant['status'] : array(),
                'config' => (isset($composant['config']) && is_array($composant['config']))
                    ? $composant['config'] : array(),
            );
        }
        $_dossier['composants'] = $connus;
        if (isset($_resultat['cfg_rev'])) {
            $_dossier['cfg_rev'] = (int) $_resultat['cfg_rev'];
        }

        $pages = (int) $this->nombre($_dossier, 'pages') + 1;
        $_dossier['pages'] = $pages;
        $suivant = $debut + count($composants);

        /* 16 pages : très au-delà de ce qu'un appareil peut porter — les
         * composants dynamiques sont bornés aux identifiants 200 à 299 — et
         * assez bas pour qu'un appareil qui répondrait n'importe quoi ne fasse
         * pas tourner la découverte indéfiniment. */
        if (count($composants) > 0 && $suivant < $total && $pages < 16) {
            $_dossier['offset'] = $suivant;
            $_dossier['etape']  = 'composants';
            return $_dossier;
        }
        /*
         * On s'arrête, et il faut dire pourquoi : parce que tout est arrivé, ou
         * parce qu'on a renoncé.
         *
         * Dans le second cas l'inventaire est amputé, et le publier comme
         * `certain` serait un mensonge — c'est précisément ce que la confiance
         * sert à ne pas faire. Un appareil resté `probable` sera repris à la
         * prochaine occasion ; un appareil `certain` et incomplet ne le sera
         * jamais.
         */
        if ($suivant < $total) {
            $_ctx->log('warning', 'Shelly Gen2+ : ' . $this->texte($_dossier, 'prefixe')
                . ' annonce ' . $total . ' composants, ' . $suivant . ' sont arrivés'
                . (count($composants) === 0 ? ' — il n\'en livre plus à partir de ' . $debut
                                            : ' — plafond de pages atteint')
                . ', l\'inventaire est incomplet.');
        }
        $_dossier['etape']   = 'fini';
        $_dossier['complet'] = ($suivant >= $total);
        return $_dossier;
    }

    /*
     * Le repli : les clés de `Shelly.GetStatus` et de `Shelly.GetConfig` SONT
     * les clés de composants. Leur union est l'inventaire, exactement comme
     * `Shelly.GetComponents` l'aurait donné.
     */
    private function rangeStatuts($_dossier, $_resultat, $_quoi) {
        $connus = isset($_dossier['composants']) && is_array($_dossier['composants'])
            ? $_dossier['composants'] : array();
        foreach ($_resultat as $cle => $contenu) {
            if (!is_array($contenu)) {
                continue;
            }
            $cle = $this->cleComposant($cle);
            if ($cle === '') {
                continue;
            }
            if (!isset($connus[$cle])) {
                $connus[$cle] = array('status' => array(), 'config' => array());
            }
            $connus[$cle][$_quoi] = $contenu;
        }
        $_dossier['composants'] = $connus;
        return $_dossier;
    }

    /* Redemander tout : le bouton « relancer la découverte » ne veut rien dire
     * d'autre. Les conversations repartent de zéro, et les modèles déjà émis
     * sont oubliés — sans quoi un équipement supprimé par erreur dans Jeedom ne
     * reviendrait jamais. */
    private function relanceConversations($_ctx) {
        $this->ecrit($_ctx, 'attentes', array());
        foreach ($this->inventaire($_ctx) as $prefixe) {
            $dossier = $this->dossier($_ctx, $prefixe);
            $dossier['etape']      = 'info';
            $dossier['essais']     = 0;
            $dossier['offset']     = 0;
            $dossier['pages']      = 0;
            $dossier['attente']    = array();
            $dossier['complet']    = false;
            unset($dossier['emis'], $dossier['composants']);
            $this->range($_ctx, $dossier);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Émission                                                              */
    /* --------------------------------------------------------------------- */

    /*
     * Émettre, si et seulement si quelque chose a changé.
     *
     * Même raisonnement que pour la génération 1 : les notifications se
     * succèdent, et le moteur dédoublonne déjà par empreinte. Le souvenir tenu
     * ici évite simplement de reconstruire un modèle entier à chaque
     * NotifyStatus — c'est-à-dire, sur un compteur d'énergie, plusieurs fois
     * par seconde.
     */
    private function emet($_ctx, $_dossier) {
        $modele = $this->construit($_ctx, $_dossier);
        if ($modele === null) {
            return false;
        }
        $empreinte = $modele->fingerprint() . '|' . $modele->volatileFingerprint();
        $emis = isset($_dossier['emis']) && is_array($_dossier['emis']) ? $_dossier['emis'] : array();
        if (isset($emis['fingerprint']) && $emis['fingerprint'] === $empreinte
            && isset($emis['confidence']) && $emis['confidence'] === $modele->confidence()) {
            return false;
        }

        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $_ctx->log('warning', 'Shelly Gen2+ : modèle refusé pour « ' . $modele->uid() . ' » — '
                . implode(' ', $fautes));
            return false;
        }

        $_ctx->emit($modele);
        $_dossier['emis'] = array('fingerprint' => $empreinte, 'confidence' => $modele->confidence());
        $this->range($_ctx, $_dossier);
        $this->avertitSiMuet($_ctx, $_dossier);
        $_ctx->log('info', 'Shelly Gen2+ : ' . $modele->name() . ' (' . $modele->uid() . ') — '
            . $modele->countChannels() . ' canaux, confiance ' . $modele->confidence() . '.');
        return true;
    }

    /*
     * LE RÉGLAGE QUI REND UN ÉQUIPEMENT DÉFINITIVEMENT VIDE.
     *
     * Toutes les commandes d'information de cet adapter lisent
     * `<P>/events/rpc`, et ce topic n'existe que si `rpc_ntf` vaut `true`.
     * C'est sa valeur d'usine, mais elle se change — et un appareil dont son
     * propriétaire l'a mise à `false` sera découvert normalement, créera toutes
     * ses commandes, et aucune ne recevra jamais rien.
     *
     * Le plugin ne peut pas le corriger : il ne touche pas à la configuration
     * d'un appareil. Il peut le DIRE, et c'est la seule chose qui distingue,
     * dans un journal, une panne du plugin d'un réglage de l'appareil.
     *
     * Cette configuration-là n'arrive qu'avec `Shelly.GetComponents` ou
     * `Shelly.GetConfig` : un appareil découvert par ses seules notifications
     * ne la publie pas, et il n'y a alors rien à dire.
     */
    private function avertitSiMuet($_ctx, $_dossier) {
        $composants = $this->composantsDe($_dossier);
        if (!isset($composants['mqtt']['config']) || !is_array($composants['mqtt']['config'])) {
            return;
        }
        $config  = $composants['mqtt']['config'];
        $prefixe = $this->texte($_dossier, 'prefixe');
        if (array_key_exists('rpc_ntf', $config) && $config['rpc_ntf'] === false) {
            $_ctx->log('warning', 'Shelly Gen2+ : ' . $prefixe . ' a « rpc_ntf » à false — il ne '
                . 'publie aucune notification, et les commandes de cet équipement resteront vides '
                . 'tant que ce réglage n\'aura pas été remis à true DANS L\'APPAREIL. Le plugin ne '
                . 'touche pas à sa configuration.');
        }
        $annonce = $this->texte($config, 'topic_prefix');
        if ($annonce !== '' && $annonce !== $prefixe) {
            $_ctx->log('warning', 'Shelly Gen2+ : ' . $prefixe . ' dit publier sous « '
                . $this->citation($annonce) . ' ». Les topics de l\'équipement sont composés avec '
                . 'le préfixe où il a été vu, et non avec celui qu\'il annonce.');
        }
    }

    /* --------------------------------------------------------------------- */
    /* Construction du modèle                                                */
    /* --------------------------------------------------------------------- */

    /**
     * @return MqttbeDeviceModel|null
     */
    public function construit($_ctx, $_dossier) {
        $prefixe = $this->texte($_dossier, 'prefixe');
        if ($prefixe === '') {
            return null;
        }
        $info = (isset($_dossier['info']) && is_array($_dossier['info'])) ? $_dossier['info'] : array();

        /*
         * L'IDENTITÉ, ET RIEN D'AUTRE, DÉCIDE S'IL Y A UN MODÈLE.
         *
         * Sans MAC, pas d'équipement. Un `online` retenu tout seul apprend
         * qu'un préfixe existe, et rien de plus : en faire un équipement
         * reviendrait à prendre le préfixe pour une identité, c'est-à-dire à
         * créer un doublon le jour où son propriétaire le renomme. L'appareil
         * reste un candidat, et le restera tant qu'il n'aura pas parlé.
         */
        $mac = $this->macDe($info, $this->texte($_dossier, 'src'), $prefixe);
        if ($mac === '') {
            return null;
        }

        $composants = $this->composantsDe($_dossier);
        $complet    = !empty($_dossier['complet']);
        $code       = $this->texte($info, 'model');
        $ip         = $this->adresseDe($composants);
        $surPile    = $this->surPile($code, $composants);

        /*
         * La disponibilité : `<P>/online`, retenu, publié par testament du
         * broker — mais pas pour un appareil sur pile.
         *
         * Un capteur qui dort vingt-trois heures sur vingt-quatre voit sa
         * session MQTT coupée à chaque sommeil : le testament part, `online`
         * passe à `false`, et Jeedom déclare hors ligne un appareil qui va
         * parfaitement bien. Le déclarer indisponible en permanence est pire
         * que ne rien déclarer du tout.
         */
        $disponibilite = $surPile ? array() : array(
            'topic'       => $prefixe . '/online',
            'payload_on'  => 'true',
            'payload_off' => 'false',
        );

        $modele = new MqttbeDeviceModel(array(
            'identity' => array(
                'adapter' => self::ID,
                /* Même forme d'uid que la génération 1, et c'est le point
                 * entier : sur un parc mixte, un appareil vu par les deux
                 * adapters est un seul équipement. La MAC en minuscules,
                 * toujours — `Shelly.GetDeviceInfo` la rend en majuscules,
                 * l'identifiant d'appareil en minuscules, et deux orthographes
                 * donneraient deux équipements. */
                'uid'     => 'shelly:' . strtolower($mac),
                'aliases' => array('mac:' . strtolower($mac), 'topic:' . $prefixe),
                /* `certain` seulement quand l'inventaire est complet : une
                 * conversation RPC menée à son terme, ou un NotifyFullStatus.
                 * Un appareil deviné d'après trois notifications partielles est
                 * `probable` — il sera créé, puisque seul `guess` passe par la
                 * file d'adoption, mais l'utilisateur lit que le plugin n'a pas
                 * encore tout vu. */
                'confidence' => $complet ? 'certain' : 'probable',
            ),
            'meta' => array(
                'name'         => $this->nomLisible($code, $info, $mac),
                /* LE NOM DONNÉ PAR L'UTILISATEUR, et aucune sonde pour aller le
                 * chercher : contrairement au Gen1, l'appareil le publie dans
                 * sa réponse RPC. `sys.device.name` d'abord — c'est le champ
                 * documenté — et `Shelly.GetDeviceInfo.name` en repli, présent
                 * sur les micrologiciels récents mais absent de la
                 * documentation. */
                'device_name'  => $this->nomUtilisateur($info, $composants),
                'manufacturer' => 'Shelly',
                'model'        => $code,
                'model_name'   => $this->nomCommercial($code, $info),
                'generation'   => isset($info['gen']) ? (int) $info['gen'] : null,
                'firmware'     => $this->texte($info, 'ver'),
                'ip'           => $ip,
                'config_url'   => ($ip === '') ? '' : 'http://' . $ip . '/',
                'battery_powered' => $surPile,
                /* Aucune sonde. Voir ci-dessus : tout ce que le Gen1 allait
                 * chercher en HTTP arrive ici par MQTT. */
                'probe'        => array(),
            ),
            'availability' => $disponibilite,
        ));

        /* La disponibilité en commande visible, en plus du bloc `availability` :
         * l'un pilote l'équipement, l'autre se lit sur le tableau de bord. */
        if (!$surPile) {
            $modele->addChannel(new MqttbeChannel(array(
                'key'        => 'online',
                'capability' => 'connectivity.online',
                'name'       => 'Connecté',
                'source'     => array('topic' => $prefixe . '/online'),
                'value'      => array('transform' => array('map' => array('true' => '1', 'false' => '0'))),
            )));
        }

        $this->ajouteComposants($_ctx, $modele, $prefixe, $composants);

        /*
         * Un modèle sans un seul canal est refusé par le moteur, et il a
         * raison : un équipement sans commande n'apprend rien à personne. Le
         * cas se rencontre pour un appareil sur pile dont on ne connaît encore
         * que l'identité — pas de canal de disponibilité, pas d'inventaire.
         */
        if ($modele->countChannels() === 0) {
            return null;
        }
        return $modele;
    }

    /* --------------------------------------------------------------------- */
    /* Les composants, un par un                                             */
    /* --------------------------------------------------------------------- */

    /*
     * LE PROFIL NE SE DEVINE PAS, IL SE LIT.
     *
     * Un Shelly 2PM en profil volet n'énumère pas `switch:0` et `switch:1` : il
     * énumère `cover:0`, et les relais ont littéralement disparu de son
     * inventaire. C'est la grande différence avec la génération 1, où le même
     * boîtier publiait `relays[]` dans les deux modes et où il fallait regarder
     * `rollers[]` pour ne pas fabriquer deux interrupteurs fantômes.
     *
     * Ici, rien à arbitrer : ce que l'appareil liste est ce qu'il a. Le
     * `profile` de `Shelly.GetDeviceInfo` n'a même pas besoin d'être lu.
     */
    private function ajouteComposants($_ctx, $_modele, $_prefixe, $_composants) {
        /* Compter les instances par type AVANT de nommer quoi que ce soit : un
         * appareil à une seule sortie porte « État », un appareil à quatre
         * sorties porte « État 1 » à « État 4 ». Le nombre de sorties est une
         * propriété du matériel, il ne changera pas sous l'équipement, et le
         * nom reste donc stable. */
        $nombres = array();
        foreach (array_keys($_composants) as $cle) {
            $type = $this->typeDe($cle);
            $nombres[$type] = (isset($nombres[$type]) ? $nombres[$type] : 0) + 1;
        }

        $aDesEvenements = false;
        foreach ($_composants as $cle => $composant) {
            $type    = $this->typeDe($cle);
            $rang    = $this->rangDe($cle);
            $statut  = (isset($composant['status']) && is_array($composant['status'])) ? $composant['status'] : array();
            $config  = (isset($composant['config']) && is_array($composant['config'])) ? $composant['config'] : array();
            /*
             * L'ÉTIQUETTE D'UN COMPOSANT NE SERT QU'À LE DISTINGUER DES AUTRES.
             *
             * Un appareil à une seule sortie porte « État » ; un appareil à
             * quatre sorties porte « État 1 » à « État 4 », ou mieux, les noms
             * que son propriétaire leur a donnés dans l'application Shelly —
             * « État Lampe du couloir ». Le nombre de sorties est une propriété
             * du matériel, il ne changera pas sous l'équipement, et le nom reste
             * donc stable.
             *
             * Mais sur un appareil à UNE sortie, l'étiquette n'a plus rien à
             * distinguer, et elle nuit : l'équipement s'appelle déjà « Shelly
             * Plus 2PM 000031 — Volet salon », et ses commandes s'appelleraient
             * « Position Volet salon », « Ouvrir Volet salon ». Home Assistant a
             * livré exactement cela, et l'a fermé sans correctif : des entités
             * « porch_light_porch_light ». Le nom du composant est alors passé
             * sous silence — il est déjà dans le nom de l'équipement.
             */
            $plusieurs = isset($nombres[$type]) && $nombres[$type] > 1;
            $nomme     = $this->texte($config, 'name');
            $etiquette = '';
            if ($plusieurs) {
                $etiquette = ($nomme !== '') ? ' ' . $nomme : ' ' . ($rang + 1);
            }

            switch ($type) {
                case 'switch':
                    $this->ajouteInterrupteur($_modele, $_prefixe, $cle, $rang, $etiquette, $statut);
                    $aDesEvenements = true;
                    break;
                case 'cover':
                    $this->ajouteVolet($_modele, $_prefixe, $cle, $rang, $etiquette, $statut, $config);
                    break;
                case 'light':
                case 'rgb':
                case 'rgbw':
                case 'rgbcct':
                case 'cct':
                    $this->ajouteLumiere($_modele, $_prefixe, $cle, $type, $rang, $etiquette,
                                         $statut, $config);
                    break;
                case 'input':
                    $this->ajouteEntree($_modele, $_prefixe, $cle, $rang, $etiquette, $statut, $config);
                    $aDesEvenements = true;
                    break;
                case 'pm1':
                    $this->ajouteWattmetre($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'em':
                    $this->ajouteCompteurTriphase($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'em1':
                    $this->ajouteCompteurMonophase($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'emdata':
                case 'em1data':
                    $this->ajouteEnergies($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'temperature':
                    $this->ajouteMesure($_modele, $_prefixe, $cle, 'tC', 'sensor.temperature',
                                        'Température' . $etiquette, '°C', 1, $statut);
                    break;
                case 'humidity':
                    $this->ajouteMesure($_modele, $_prefixe, $cle, 'rh', 'sensor.humidity',
                                        'Humidité' . $etiquette, '%', 1, $statut);
                    break;
                case 'illuminance':
                    $this->ajouteMesure($_modele, $_prefixe, $cle, 'lux', 'sensor.luminosity',
                                        'Luminosité' . $etiquette, 'lx', 0, $statut);
                    /* `illumination` range le lux en trois mots — sombre,
                     * pénombre, plein jour. C'est ce qu'on écrit dans un
                     * scénario, là où un seuil en lux se règle par tâtonnements. */
                    if (array_key_exists('illumination', $statut)) {
                        $_modele->addChannel(new MqttbeChannel(array(
                            'key'        => $this->racineCle($cle) . '.illumination',
                            'capability' => 'generic.value',
                            'name'       => 'Éclairement' . $etiquette,
                            'source'     => $this->venantDe($_prefixe, $cle, 'illumination'),
                        )));
                    }
                    break;
                case 'voltmeter':
                    $this->ajouteVoltmetre($_modele, $_prefixe, $cle, $etiquette, $statut, $config);
                    break;
                case 'devicepower':
                    $this->ajouteAlimentation($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'smoke':
                    $this->ajouteAlarme($_modele, $_prefixe, $cle, 'alarm.smoke',
                                        'Fumée' . $etiquette, $etiquette, $statut, $rang);
                    break;
                case 'flood':
                    $this->ajouteAlarme($_modele, $_prefixe, $cle, 'alarm.water_leak',
                                        'Fuite d\'eau' . $etiquette, $etiquette, $statut, $rang);
                    break;
                case 'presencezone':
                    $this->ajoutePresence($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'boolean':
                case 'number':
                case 'text':
                case 'enum':
                case 'button':
                    $this->ajouteVirtuel($_modele, $_prefixe, $cle, $type, $etiquette, $statut, $config);
                    break;
                case 'sys':
                    $this->ajouteSysteme($_modele, $_prefixe, $statut);
                    $aDesEvenements = true;
                    break;
                case 'wifi':
                    $this->ajouteReseau($_modele, $_prefixe, $statut);
                    break;
                case 'cloud':
                    /* Un seul champ, et il dit s'il faut chercher la panne ici
                     * ou chez Shelly le jour où l'application ne répond plus. */
                    if (array_key_exists('connected', $statut)) {
                        $_modele->addChannel(new MqttbeChannel(array(
                            'key'        => 'cloud.connected',
                            'capability' => 'generic.binary',
                            'name'       => 'Cloud Shelly',
                            'source'     => $this->venantDe($_prefixe, 'cloud', 'connected'),
                        )));
                    }
                    break;
                default:
                    /* ble, mqtt, ws, script, schedule, knx, modbus, group, et
                     * les composants d'interface au statut vide : rien à en
                     * tirer qui mérite une commande. Les créer remplirait le
                     * tableau de bord de réglages que personne ne regarde depuis
                     * Jeedom. */
                    break;
            }
        }

        if ($aDesEvenements) {
            $this->ajouteEvenements($_modele, $_prefixe);
        }
    }

    /*
     * Interrupteur : l'état, les trois actions, et la métrologie quand
     * l'appareil en a.
     *
     * L'état est un booléen JSON, et le routeur en fait « 1 » ou « 0 » sans
     * qu'on ait rien à déclarer : là où le Gen1 exigeait une table de
     * correspondance pour traduire « on », « off » et « overpower », le Gen2
     * publie `"output": true`.
     */
    private function ajouteInterrupteur($_modele, $_prefixe, $_cle, $_rang, $_etiquette, $_statut) {
        $racine  = $this->racineCle($_cle);
        $cleEtat = $racine . '.state';
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $cleEtat,
            'capability' => 'switch.state',
            'name'       => 'État' . $_etiquette,
            'source'     => $this->venantDe($_prefixe, $_cle, 'output'),
        )));
        $actions = array(
            'on'     => array('switch.on',     'Allumer',  'Switch.Set',    array('on' => true)),
            'off'    => array('switch.off',    'Éteindre', 'Switch.Set',    array('on' => false)),
            'toggle' => array('switch.toggle', 'Basculer', 'Switch.Toggle', array()),
        );
        foreach ($actions as $role => $action) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $role,
                'capability' => $action[0],
                'name'       => $action[1] . $_etiquette,
                'sink'       => $this->appel($_prefixe, $action[2], array('id' => $_rang) + $action[3]),
                'links'      => array('state' => $cleEtat),
            )));
        }
        $this->ajouteMetrologie($_modele, $_prefixe, $_cle, $_etiquette, $_statut);
    }

    /*
     * Volet roulant.
     *
     * UN VOLET NON CALIBRÉ N'A PAS DE POSITION, ET CE N'EST PAS UNE PANNE.
     *
     * `current_pos` n'est présent que si le volet est calibré — la
     * documentation le dit mot pour mot, et `pos_control` est le champ qui
     * l'annonce. Faire de la position la seule information du composant
     * condamnait donc un volet non calibré à une commande vide à vie, sans rien
     * pour l'expliquer.
     *
     * D'où deux informations, et non une :
     *   `state` — ouvert, fermé, en ouverture, en fermeture, arrêté, en cours
     *      de calibration. Toujours présent, et il dit le MOUVEMENT, ce qu'une
     *      position ne dit pas ;
     *   la position en pourcents — quand, et seulement quand, elle existe.
     *
     * Le curseur suit la même règle, et pour la même raison : un appareil non
     * calibré refuse `Cover.GoToPosition`.
     */
    private function ajouteVolet($_modele, $_prefixe, $_cle, $_rang, $_etiquette, $_statut, $_config) {
        $racine   = $this->racineCle($_cle);
        $calibre  = !empty($_statut['pos_control']);
        $cleEtat  = $racine . '.status';
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $cleEtat,
            'capability' => 'generic.value',
            'name'       => 'État' . $_etiquette,
            'source'     => $this->venantDe($_prefixe, $_cle, 'state'),
        )));
        if ($calibre) {
            $cleEtat = $racine . '.state';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleEtat,
                'capability' => 'cover.state',
                'name'       => 'Position' . $_etiquette,
                'unit'       => '%',
                'source'     => $this->venantDe($_prefixe, $_cle, 'current_pos'),
                'value'      => array('transform' => array('round' => 0)),
            )));
        }
        $actions = array(
            'open'  => array('cover.open',  'Ouvrir', 'Cover.Open'),
            'close' => array('cover.close', 'Fermer', 'Cover.Close'),
            'stop'  => array('cover.stop',  'Stop',   'Cover.Stop'),
        );
        foreach ($actions as $role => $action) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $role,
                'capability' => $action[0],
                'name'       => $action[1] . $_etiquette,
                'sink'       => $this->appel($_prefixe, $action[2], array('id' => $_rang)),
                'links'      => array('state' => $cleEtat),
            )));
        }
        if ($calibre) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.position',
                'capability' => 'cover.position',
                'name'       => 'Régler la position' . $_etiquette,
                'unit'       => '%',
                /* `#slider#` est substitué par la valeur du curseur au moment
                 * de l'appui. Il n'est pas entre guillemets : `pos` attend un
                 * nombre, et une chaîne ferait refuser l'appel. */
                'sink'       => $this->appelBrut($_prefixe, 'Cover.GoToPosition',
                                                 '{"id":' . $_rang . ',"pos":#slider#}'),
                'links'      => array('state' => $cleEtat),
            )));
        }
        $this->ajouteMetrologie($_modele, $_prefixe, $_cle, $_etiquette, $_statut);
    }

    /*
     * Lumières : `light`, `rgb`, `rgbw` et `cct`.
     *
     * Quatre composants, une seule façon de les piloter — `<Type>.Set` — et des
     * champs qui diffèrent. Ce qui est créé dépend de ce que le STATUT contient,
     * jamais du modèle de l'appareil : un bandeau qui ne publie pas `white` n'a
     * pas de voie blanche, et lui en créer une donnerait un curseur qui ne
     * commande rien.
     */
    private function ajouteLumiere($_modele, $_prefixe, $_cle, $_type, $_rang, $_etiquette, $_statut, $_config = array()) {
        $racine  = $this->racineCle($_cle);
        $cleEtat = $racine . '.state';
        $methode = $this->methodeDe($_type);

        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $cleEtat,
            'capability' => 'light.state',
            'name'       => 'État' . $_etiquette,
            'source'     => $this->venantDe($_prefixe, $_cle, 'output'),
        )));
        $actions = array(
            'on'  => array('light.on',  'Allumer',  'true'),
            'off' => array('light.off', 'Éteindre', 'false'),
        );
        foreach ($actions as $role => $action) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $role,
                'capability' => $action[0],
                'name'       => $action[1] . $_etiquette,
                'sink'       => $this->appelBrut($_prefixe, $methode . '.Set',
                                                 '{"id":' . $_rang . ',"on":' . $action[2] . '}'),
                'links'      => array('state' => $cleEtat),
            )));
        }

        if (array_key_exists('brightness', $_statut)) {
            $cleNiveau = $racine . '.level';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleNiveau,
                'capability' => 'light.brightness_state',
                'name'       => 'Niveau' . $_etiquette,
                'unit'       => '%',
                'source'     => $this->venantDe($_prefixe, $_cle, 'brightness'),
                'value'      => array('transform' => array('round' => 0)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.brightness',
                'capability' => 'light.brightness',
                'name'       => 'Luminosité' . $_etiquette,
                'unit'       => '%',
                /* `"on":true` dans la même charge utile : régler la luminosité
                 * d'une lampe éteinte, depuis un tableau de bord, veut dire
                 * l'allumer à ce niveau-là. */
                'sink'       => $this->appelBrut($_prefixe, $methode . '.Set',
                                    '{"id":' . $_rang . ',"on":true,"brightness":#slider#}'),
                'links'      => array('state' => $cleNiveau),
            )));
        }

        /*
         * La couleur : l'action seulement, jamais l'état.
         *
         * Le statut porte `"rgb": [255, 128, 0]` — un TABLEAU. Le sélecteur du
         * plugin lit un chemin par points, et le routeur laisse tomber toute
         * valeur qui est un tableau : il n'y a pas moyen de recomposer une
         * couleur hexadécimale à partir de trois commandes séparées. Créer
         * `light.color_state` reviendrait à créer une commande définitivement
         * vide.
         *
         * L'action, elle, fonctionne : Jeedom donne la couleur choisie en
         * hexadécimal, et `#red#`, `#green#` et `#blue#` la rendent en trois
         * entiers décimaux — exactement ce que `RGB.Set` attend.
         */
        if (array_key_exists('rgb', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.color',
                'capability' => 'light.color',
                'name'       => 'Couleur' . $_etiquette,
                'sink'       => $this->appelBrut($_prefixe, $methode . '.Set',
                                    '{"id":' . $_rang . ',"on":true,"rgb":[#red#,#green#,#blue#]}'),
                'links'      => array('state' => $cleEtat),
            )));
        }
        if (array_key_exists('white', $_statut)) {
            $cleBlanc = $racine . '.white_state';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleBlanc,
                'capability' => 'light.white_state',
                'name'       => 'Blanc' . $_etiquette,
                'unit'       => '%',
                'source'     => $this->venantDe($_prefixe, $_cle, 'white'),
                /* La voie blanche se compte de 0 à 255, là où la luminosité se
                 * compte en pourcents. L'état est ramené en pourcents pour que
                 * le tableau de bord montre la même grandeur partout. */
                'value'      => array('transform' => array('scale' => 0.39215686274509803, 'round' => 0)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.white',
                'capability' => 'light.white',
                'name'       => 'Voie blanche' . $_etiquette,
                'unit'       => '%',
                'sink'       => $this->appelBrut($_prefixe, $methode . '.Set',
                                    '{"id":' . $_rang . ',"on":true,"white":#slider#}'),
                /*
                 * ET LA CONVERSION SE FAIT DANS LA CHARGE UTILE, PAS DANS LE
                 * CURSEUR.
                 *
                 * Un curseur de lumière Jeedom va de 0 à 100 : c'est ce que son
                 * widget suppose, et le borner à 255 le ferait afficher faux.
                 * C'est donc la valeur publiée qui est multipliée par 2,55 —
                 * sans quoi le curseur poussé à fond enverrait 100 sur 255, soit
                 * une voie blanche au tiers de sa puissance, et l'état relisant
                 * la même échelle, le curseur semblerait refuser de dépasser 39.
                 */
                'value'      => array('slider' => array(
                    'scale' => 2.55, 'round' => 0, 'min' => 0, 'max' => 100,
                )),
                'links'      => array('state' => $cleBlanc),
            )));
        }
        if (array_key_exists('ct', $_statut)) {
            /*
             * LES BORNES D'UNE TEMPÉRATURE DE COULEUR VIENNENT DE L'APPAREIL.
             *
             * `ct` est en KELVINS, et l'appareil publie sa plage dans
             * `config.ct_range`. Sans ces bornes, le curseur garde le 0 à 100 du
             * cœur et n'envoie que des valeurs que l'appareil refuse : la
             * commande existe, elle s'actionne, et il ne se passe jamais rien.
             *
             * À défaut de plage annoncée, 2700 à 6500 K — celle que la
             * documentation donne par défaut, et celle d'à peu près toutes les
             * ampoules blanc réglable. Une valeur hors de la plage réelle est
             * refusée par l'appareil, ce qui reste préférable à un curseur dont
             * AUCUNE position n'est acceptable.
             */
            $bornes = array(2700, 6500);
            if (isset($_config['ct_range']) && is_array($_config['ct_range'])
                && count($_config['ct_range']) === 2
                && is_numeric($_config['ct_range'][0]) && is_numeric($_config['ct_range'][1])
                && $_config['ct_range'][0] < $_config['ct_range'][1]) {
                $bornes = array((int) $_config['ct_range'][0], (int) $_config['ct_range'][1]);
            }
            $cleTemp = $racine . '.color_temp_state';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleTemp,
                'capability' => 'light.color_temp_state',
                'name'       => 'Température de couleur' . $_etiquette,
                'unit'       => 'K',
                'source'     => $this->venantDe($_prefixe, $_cle, 'ct'),
                'value'      => array('transform' => array('round' => 0)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.color_temp',
                'capability' => 'light.color_temp',
                'name'       => 'Régler la température de couleur' . $_etiquette,
                'unit'       => 'K',
                'sink'       => $this->appelBrut($_prefixe, $methode . '.Set',
                                    '{"id":' . $_rang . ',"on":true,"ct":#slider#}'),
                'value'      => array('slider' => array('min' => $bornes[0], 'max' => $bornes[1])),
                'links'      => array('state' => $cleTemp),
            )));
        }
        $this->ajouteMetrologie($_modele, $_prefixe, $_cle, $_etiquette, $_statut);
    }

    /*
     * Entrée physique.
     *
     * Le champ à lire dépend du mode configuré, et lui seul :
     *   `switch` → `state`, un booléen ;
     *   `button` → `state` existe mais vaut TOUJOURS `null` — l'appui se lit
     *              dans les événements, pas dans le statut ;
     *   `analog` → `percent` ;
     *   `count`  → `counts.total`.
     * Créer un état binaire pour une entrée en mode bouton donnerait une
     * commande éternellement vide, que son propriétaire prendrait pour une
     * panne du plugin — c'est exactement la faute que la génération 1 a appris
     * à ne pas commettre.
     */
    private function ajouteEntree($_modele, $_prefixe, $_cle, $_rang, $_etiquette, $_statut, $_config) {
        $racine = $this->racineCle($_cle);
        /*
         * Une entrée désactivée ne publie que des `null`.
         *
         * `enable: false` est un réglage ordinaire — on coupe l'entrée d'un
         * appareil dont le bouton n'est pas câblé. La documentation dit alors :
         * « reports status properties as null ». Lui créer des commandes
         * donnerait exactement ce que cet adapter s'interdit ailleurs : des
         * commandes éternellement vides, que leur propriétaire prend pour une
         * panne du plugin.
         */
        if (array_key_exists('enable', $_config) && $_config['enable'] === false) {
            return;
        }
        $mode   = $this->texte($_config, 'type');
        if ($mode === '') {
            /* Sans configuration — repli `Shelly.GetStatus` seul, ou trame de
             * notification — on déduit du statut ce qu'il porte réellement. */
            if (array_key_exists('percent', $_statut)) {
                $mode = 'analog';
            } elseif (isset($_statut['counts'])) {
                $mode = 'count';
            } elseif (isset($_statut['state']) && $_statut['state'] !== null) {
                $mode = 'switch';
            } else {
                $mode = 'button';
            }
        }

        if ($mode === 'analog') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.percent',
                'capability' => 'generic.numeric',
                'name'       => 'Entrée' . $_etiquette,
                'unit'       => '%',
                'source'     => $this->venantDe($_prefixe, $_cle, 'percent'),
                'value'      => array('transform' => array('round' => 1)),
            )));
            /*
             * Et la MESURE MÉTIER, quand son propriétaire en a réglé une.
             *
             * C'est le même mécanisme que le voltmètre : une expression dans la
             * configuration convertit les pourcents en litres, en bars ou en
             * degrés, et `config.xpercent.unit` en donne l'unité. Le champ
             * n'existe que si l'expression ET l'unité sont renseignées — donc
             * s'il est là, c'est qu'on le veut, et c'est lui qu'on veut voir.
             */
            $this->ajouteMesureMetier($_modele, $_prefixe, $_cle, 'xpercent', 'xpercent',
                                      'Mesure' . $_etiquette, $_statut, $_config);
            return;
        }
        if ($mode === 'count') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.counts',
                'capability' => 'generic.numeric',
                'name'       => 'Comptage' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'counts.total'),
            )));
            /* La fréquence des impulsions : un compteur d'eau ou de gaz la rend
             * lisible en débit instantané, là où un total n'apprend rien avant
             * la fin du mois. */
            if (array_key_exists('freq', $_statut)) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => $racine . '.freq',
                    'capability' => 'power.frequency',
                    'name'       => 'Fréquence' . $_etiquette,
                    'unit'       => 'Hz',
                    'source'     => $this->venantDe($_prefixe, $_cle, 'freq'),
                    'value'      => array('transform' => array('round' => 2)),
                )));
            }
            $this->ajouteMesureMetier($_modele, $_prefixe, $_cle, 'counts.xtotal', 'xcounts',
                                      'Comptage converti' . $_etiquette, $_statut, $_config);
            $this->ajouteMesureMetier($_modele, $_prefixe, $_cle, 'xfreq', 'xfreq',
                                      'Fréquence convertie' . $_etiquette, $_statut, $_config);
            return;
        }
        if ($mode === 'switch') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.state',
                'capability' => 'generic.binary',
                'name'       => 'Entrée' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'state'),
            )));
        }
        /* Mode bouton : rien ici. Voir ajouteEvenements(). */
    }

    /*
     * Une valeur convertie par l'appareil dans l'unité de son propriétaire.
     *
     * Les Gen2 offrent partout le même mécanisme : une expression réglée dans
     * la configuration transforme une grandeur brute — des volts, des pourcents,
     * des impulsions — en ce que la mesure représente vraiment, et la
     * configuration porte l'unité correspondante. Le champ converti n'apparaît
     * dans le statut que si l'expression et l'unité sont toutes deux réglées.
     *
     * Le chemin du champ peut descendre d'un cran (`counts.xtotal`) : la clé de
     * la commande ne garde alors que sa dernière partie.
     */
    private function ajouteMesureMetier($_modele, $_prefixe, $_cle, $_chemin, $_reglage, $_nom, $_statut, $_config) {
        $morceaux = explode('.', $_chemin);
        $valeur   = $_statut;
        foreach ($morceaux as $morceau) {
            if (!is_array($valeur) || !array_key_exists($morceau, $valeur)) {
                return;
            }
            $valeur = $valeur[$morceau];
        }
        if ($valeur === null) {
            return;
        }
        $unite = '';
        if (isset($_config[$_reglage]) && is_array($_config[$_reglage])) {
            $unite = $this->texte($_config[$_reglage], 'unit');
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $this->racineCle($_cle) . '.' . end($morceaux),
            'capability' => 'generic.numeric',
            'name'       => $_nom,
            'unit'       => $unite,
            'source'     => $this->venantDe($_prefixe, $_cle, $_chemin),
            'value'      => array('transform' => array('round' => 2)),
        )));
    }

    /*
     * La métrologie d'une sortie : puissance, tension, courant, énergie.
     *
     * Chaque canal est gardé par la présence du champ dans le statut, et non
     * par le modèle de l'appareil. Un Shelly Plus 1 n'a pas de wattmètre et ne
     * publie pas `apower` ; lui créer une commande « Puissance » donnerait un
     * zéro éternel, et c'est précisément le piège que la génération 1 a payé
     * pour apprendre — un appareil sans compteur y publie quand même un
     * `meters[]` réduit.
     */
    private function ajouteMetrologie($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $racine = $this->racineCle($_cle);
        $mesures = array(
            'apower'    => array('power',   'power.active',  'Puissance',   'W',  1),
            'voltage'   => array('voltage', 'power.voltage', 'Tension',     'V',  1),
            'current'   => array('current', 'power.current', 'Courant',     'A',  2),
            'freq'      => array('freq',    'power.frequency', 'Fréquence', 'Hz', 1),
            'aprtpower' => array('apparent', 'power.apparent', 'Puissance apparente', 'VA', 1),
            'pf'        => array('pf',      'power.factor',  'Facteur de puissance', '', 2),
        );
        foreach ($mesures as $champ => $mesure) {
            if (!array_key_exists($champ, $_statut)) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $mesure[0],
                'capability' => $mesure[1],
                'name'       => $mesure[2] . $_etiquette,
                'unit'       => $mesure[3],
                'source'     => $this->venantDe($_prefixe, $_cle, $champ),
                'value'      => array('transform' => array('round' => $mesure[4])),
            )));
        }

        /*
         * L'énergie. TOUJOURS EN WATT-HEURES en génération 2 — sans exception,
         * et la documentation le dit pour chaque composant. La commande annonce
         * des kWh : sans cette division, l'utilisateur lirait trois ordres de
         * grandeur de trop et en conclurait que le plugin est faux, ce en quoi
         * il aurait raison.
         *
         * Et `aenergy.total` n'est PAS net : il inclut déjà ce qui est reparti
         * vers le réseau. Il ne faut donc rien en soustraire — la réinjection
         * est une mesure à part, pas une correction de celle-ci.
         */
        if (isset($_statut['aenergy']) && is_array($_statut['aenergy'])
            && array_key_exists('total', $_statut['aenergy'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.energy',
                'capability' => 'energy.total',
                'name'       => 'Consommation' . $_etiquette,
                'unit'       => 'kWh',
                'source'     => $this->venantDe($_prefixe, $_cle, 'aenergy.total'),
                'value'      => array('transform' => array(
                    'scale' => self::WATTHEURE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
        }
        if (isset($_statut['ret_aenergy']) && is_array($_statut['ret_aenergy'])
            && array_key_exists('total', $_statut['ret_aenergy'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.returned',
                /* Et non `energy.total` : ce compteur mesure ce qui PART vers
                 * le réseau. Étiqueté en consommation, il apparaîtrait dans la
                 * vue Maison comme une dépense, et le bilan d'une installation
                 * solaire serait exactement inversé. */
                'capability' => 'energy.returned',
                'name'       => 'Réinjection' . $_etiquette,
                'unit'       => 'kWh',
                'source'     => $this->venantDe($_prefixe, $_cle, 'ret_aenergy.total'),
                'value'      => array('transform' => array(
                    'scale' => self::WATTHEURE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
        }
        /* La température interne du boîtier : elle dit qu'un relais chauffe,
         * et c'est l'information qui précède une panne. */
        if (isset($_statut['temperature']) && is_array($_statut['temperature'])
            && array_key_exists('tC', $_statut['temperature'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.temperature',
                'capability' => 'sensor.temperature',
                'name'       => 'Température interne' . $_etiquette,
                'unit'       => '°C',
                'source'     => $this->venantDe($_prefixe, $_cle, 'temperature.tC'),
                'value'      => array('transform' => array('round' => 1)),
            )));
        }
    }

    /* Wattmètre monovoie : la même métrologie qu'une sortie, sans sortie. */
    private function ajouteWattmetre($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $this->ajouteMetrologie($_modele, $_prefixe, $_cle, $_etiquette, $_statut);
    }

    /*
     * Compteur triphasé.
     *
     * Trois phases plus un total, et rien d'autre : `em` ne porte AUCUNE
     * énergie — elle est dans `emdata`, un composant distinct. Chaque champ est
     * gardé par sa présence : un tore de neutre non installé ne publie pas
     * `n_current`, et la commande correspondante resterait vide.
     */
    private function ajouteCompteurTriphase($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $racine = $this->racineCle($_cle);
        $grandeurs = array(
            'act_power'  => array('power.active',   'Puissance',            'W',  1),
            'voltage'    => array('power.voltage',  'Tension',              'V',  1),
            'current'    => array('power.current',  'Courant',              'A',  2),
            'aprt_power' => array('power.apparent', 'Puissance apparente',  'VA', 1),
            'pf'         => array('power.factor',   'Facteur de puissance', '',   2),
            'freq'       => array('power.frequency', 'Fréquence',           'Hz', 1),
        );
        foreach (array('a', 'b', 'c') as $phase) {
            foreach ($grandeurs as $champ => $grandeur) {
                $complet = $phase . '_' . $champ;
                if (!array_key_exists($complet, $_statut)) {
                    continue;
                }
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => $racine . '.' . $phase . '.' . $champ,
                    'capability' => $grandeur[0],
                    'name'       => $grandeur[1] . ' phase ' . strtoupper($phase),
                    'unit'       => $grandeur[2],
                    'source'     => $this->venantDe($_prefixe, $_cle, $complet),
                    'value'      => array('transform' => array('round' => $grandeur[3])),
                )));
            }
        }
        $totaux = array(
            'total_act_power'  => array('power.active',   'Puissance totale',           'W',  1),
            'total_aprt_power' => array('power.apparent', 'Puissance apparente totale', 'VA', 1),
            'total_current'    => array('power.current',  'Courant total',              'A',  2),
            'n_current'        => array('power.current',  'Courant neutre',             'A',  2),
        );
        foreach ($totaux as $champ => $total) {
            if (!array_key_exists($champ, $_statut) || $_statut[$champ] === null) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $champ,
                'capability' => $total[0],
                'name'       => $total[1],
                'unit'       => $total[2],
                'source'     => $this->venantDe($_prefixe, $_cle, $champ),
                'value'      => array('transform' => array('round' => $total[3])),
            )));
        }
    }

    /* Compteur monovoie : les mêmes grandeurs, sans préfixe de phase. */
    private function ajouteCompteurMonophase($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $racine = $this->racineCle($_cle);
        $grandeurs = array(
            'act_power'  => array('power.active',   'Puissance',            'W',  1),
            'voltage'    => array('power.voltage',  'Tension',              'V',  1),
            'current'    => array('power.current',  'Courant',              'A',  2),
            'aprt_power' => array('power.apparent', 'Puissance apparente',  'VA', 1),
            'pf'         => array('power.factor',   'Facteur de puissance', '',   2),
            'freq'       => array('power.frequency', 'Fréquence',           'Hz', 1),
        );
        foreach ($grandeurs as $champ => $grandeur) {
            if (!array_key_exists($champ, $_statut)) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $champ,
                'capability' => $grandeur[0],
                'name'       => $grandeur[1] . $_etiquette,
                'unit'       => $grandeur[2],
                'source'     => $this->venantDe($_prefixe, $_cle, $champ),
                'value'      => array('transform' => array('round' => $grandeur[3])),
            )));
        }
    }

    /*
     * Les bases d'énergie, `emdata` et `em1data`.
     *
     * Elles n'ont aucune configuration, donc aucun nom : celui-ci est emprunté
     * au compteur du même rang. Et leurs totaux sont, eux aussi, en
     * watt-heures.
     */
    private function ajouteEnergies($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $racine = $this->racineCle($_cle);
        $compteurs = array(
            'total_act'              => array('energy.total',    'Consommation totale'),
            'total_act_ret'          => array('energy.returned', 'Réinjection totale'),
            'total_act_energy'       => array('energy.total',    'Consommation'),
            'total_act_ret_energy'   => array('energy.returned', 'Réinjection'),
        );
        foreach (array('a', 'b', 'c') as $phase) {
            $compteurs[$phase . '_total_act_energy'] =
                array('energy.total', 'Consommation phase ' . strtoupper($phase));
            $compteurs[$phase . '_total_act_ret_energy'] =
                array('energy.returned', 'Réinjection phase ' . strtoupper($phase));
        }
        foreach ($compteurs as $champ => $compteur) {
            if (!array_key_exists($champ, $_statut)) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.' . $champ,
                'capability' => $compteur[0],
                'name'       => $compteur[1] . $_etiquette,
                'unit'       => 'kWh',
                'source'     => $this->venantDe($_prefixe, $_cle, $champ),
                'value'      => array('transform' => array(
                    'scale' => self::WATTHEURE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
        }
    }

    /* Une mesure simple : un champ, une capacité, une unité. */
    private function ajouteMesure($_modele, $_prefixe, $_cle, $_champ, $_capacite, $_nom, $_unite, $_decimales, $_statut) {
        if (!array_key_exists($_champ, $_statut)) {
            return;
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $this->racineCle($_cle) . '.' . $_champ,
            'capability' => $_capacite,
            'name'       => $_nom,
            'unit'       => $_unite,
            'source'     => $this->venantDe($_prefixe, $_cle, $_champ),
            'value'      => array('transform' => array('round' => $_decimales)),
        )));
    }

    /*
     * Voltmètre. Deux valeurs, et c'est la seconde qui parle : `xvoltage` est
     * la tension convertie dans l'unité métier réglée par l'utilisateur — des
     * litres, des bars, un niveau de cuve. Quand elle existe, c'est elle qu'on
     * veut voir ; `voltage` reste, parce qu'un diagnostic électrique en a
     * besoin.
     */
    private function ajouteVoltmetre($_modele, $_prefixe, $_cle, $_etiquette, $_statut, $_config) {
        $this->ajouteMesure($_modele, $_prefixe, $_cle, 'voltage', 'power.voltage',
                            'Tension' . $_etiquette, 'V', 2, $_statut);
        $this->ajouteMesureMetier($_modele, $_prefixe, $_cle, 'xvoltage', 'xvoltage',
                                  'Mesure' . $_etiquette, $_statut, $_config);
    }

    /*
     * Alimentation d'un appareil sur pile.
     *
     * `external` est ABSENT sur les appareils qui ne savent pas détecter le
     * secteur — et non pas présent à `false`. La distinction compte : « je ne
     * sais pas » et « le secteur est absent » ne veulent pas dire la même chose
     * pour qui règle une alerte de batterie faible.
     */
    private function ajouteAlimentation($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $racine = $this->racineCle($_cle);
        $pile = (isset($_statut['battery']) && is_array($_statut['battery'])) ? $_statut['battery'] : array();
        if (array_key_exists('percent', $pile)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.battery',
                'capability' => 'battery.level',
                'name'       => 'Batterie' . $_etiquette,
                'unit'       => '%',
                'source'     => $this->venantDe($_prefixe, $_cle, 'battery.percent'),
                'value'      => array('transform' => array('round' => 0)),
            )));
        }
        if (array_key_exists('V', $pile)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.battery_voltage',
                'capability' => 'power.voltage',
                'name'       => 'Tension pile' . $_etiquette,
                'unit'       => 'V',
                'source'     => $this->venantDe($_prefixe, $_cle, 'battery.V'),
                'value'      => array('transform' => array('round' => 2)),
            )));
        }
        if (isset($_statut['external']) && is_array($_statut['external'])
            && array_key_exists('present', $_statut['external'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.external',
                'capability' => 'generic.binary',
                'name'       => 'Secteur présent' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'external.present'),
            )));
        }
    }

    /*
     * Une alarme : le booléen qui déclenche, et ce qu'on peut faire quand il
     * hurle.
     *
     * `mute` dit si la sirène a été fait taire — une alarme silencieuse reste
     * une alarme, et confondre les deux ferait croire au calme. Et le détecteur
     * de fumée expose une écriture, une seule : `Smoke.Mute`. Une sirène qu'on
     * ne peut pas couper depuis son tableau de bord à trois heures du matin est
     * une lacune, pas un raffinement. Le détecteur de fuite, lui, n'a pas
     * d'équivalent : sa seule écriture est un réglage de configuration, que ce
     * plugin ne touche pas.
     */
    private function ajouteAlarme($_modele, $_prefixe, $_cle, $_capacite, $_nom, $_etiquette, $_statut, $_rang) {
        $racine = $this->racineCle($_cle);
        if (array_key_exists('alarm', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.alarm',
                'capability' => $_capacite,
                'name'       => $_nom,
                'source'     => $this->venantDe($_prefixe, $_cle, 'alarm'),
            )));
        }
        if (array_key_exists('mute', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.mute',
                'capability' => 'generic.binary',
                'name'       => 'Alarme silencieuse' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'mute'),
            )));
        }
        if ($this->typeDe($_cle) === 'smoke') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.silence',
                'capability' => 'generic.action',
                'name'       => 'Faire taire' . $_etiquette,
                'sink'       => $this->appel($_prefixe, 'Smoke.Mute', array('id' => $_rang)),
            )));
        }
    }

    /*
     * Présence.
     *
     * Elle est portée par la ZONE, pas par le capteur : le composant
     * `presence` ne publie que son suivi temps réel et ses erreurs. Un appareil
     * mmWave définit une ou plusieurs zones, et chacune dit si quelqu'un s'y
     * trouve.
     */
    private function ajoutePresence($_modele, $_prefixe, $_cle, $_etiquette, $_statut) {
        $racine = $this->racineCle($_cle);
        if (array_key_exists('value', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.value',
                'capability' => 'presence.detected',
                'name'       => 'Présence' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'value'),
            )));
        }
        if (array_key_exists('num_objects', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.num_objects',
                'capability' => 'generic.numeric',
                'name'       => 'Personnes détectées' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'num_objects'),
            )));
        }
    }

    /*
     * Les composants virtuels — `boolean`, `number`, `text`, `enum`.
     *
     * Ils n'existent pas dans le matériel : leur propriétaire les a créés dans
     * l'appareil, souvent pour qu'un script y publie une valeur. Leur
     * configuration porte `meta.ui`, qui dit ce qu'ils sont, et un seul champ y
     * décide de tout : `view` valant « label » signifie AFFICHAGE SEUL. Lui
     * créer une action donnerait un bouton qui ne commande rien.
     */
    private function ajouteVirtuel($_modele, $_prefixe, $_cle, $_type, $_etiquette, $_statut, $_config) {
        $racine = $this->racineCle($_cle);
        $rang   = $this->rangDe($_cle);

        /*
         * LE BOUTON VIRTUEL N'A PAS D'ÉTAT, ET C'EST TOUT L'INTÉRÊT.
         *
         * Son statut est vide — la documentation l'écrit en toutes lettres. On
         * ne le crée pas pour lire quelque chose : on le crée pour l'actionner
         * de l'extérieur, et un script de l'appareil réagit. C'est le seul
         * composant virtuel qui soit une action pure, et la garde « pas de
         * valeur, pas de commande » l'écartait pour cette raison même.
         *
         * `Button.Trigger` exige un `event`, et quatre valeurs sont
         * documentées. Deux commandes suffisent : l'appui court, qui est ce
         * qu'on veut neuf fois sur dix, et l'appui long, qui sert de second
         * geste. Les quatre encombreraient le tableau de bord pour un bouton
         * qui n'en demande pas tant.
         *
         * Le nom du paramètre est bien `event` : `event_type` est celui
         * d'`Input.Trigger`, qui est un autre appel.
         */
        if ($_type === 'button') {
            $nomBouton = $this->texte($_config, 'name');
            if ($nomBouton === '') {
                $nomBouton = 'Bouton ' . $rang;
            }
            foreach (array('single_push' => 'Appuyer', 'long_push' => 'Appui long') as $evenement => $verbe) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => $racine . '.' . $evenement,
                    'capability' => 'generic.action',
                    'name'       => $verbe . ' ' . $nomBouton,
                    'sink'       => $this->appel($_prefixe, 'Button.Trigger',
                                                 array('id' => $rang, 'event' => $evenement)),
                )));
            }
            return;
        }

        if (!array_key_exists('value', $_statut)) {
            return;
        }
        /*
         * UN VIRTUEL PORTE TOUJOURS SON NOM, lui.
         *
         * La règle qui vaut pour le matériel — taire le nom du composant quand
         * l'appareil n'en a qu'un, puisque l'équipement le porte déjà — serait
         * ici une faute. Un appareil peut porter vingt composants virtuels de
         * types mélangés, tous créés et nommés par son propriétaire ; les taire
         * donnerait vingt commandes « Valeur », dont dix-neuf que Jeedom
         * refuserait d'enregistrer — cmd (eqLogic_id, name) est unique.
         *
         * Et son nom est meilleur que tout ce qu'on saurait fabriquer : « Mode
         * nuit » dit ce que « Valeur 200 » ne dira jamais.
         */
        $nomme  = $this->texte($_config, 'name');
        $nom    = ($nomme !== '') ? $nomme : 'Valeur ' . $rang;
        $_etiquette = ' ' . $nom;
        $ui     = (isset($_config['meta']['ui']) && is_array($_config['meta']['ui']))
            ? $_config['meta']['ui'] : array();
        /* `view` valant « label » signifie AFFICHAGE SEUL. La documentation ne
         * publie nulle part la liste des valeurs possibles ; celle-ci est lue
         * dans ses exemples, et c'est la prudence qui décide du sens du doute :
         * une commande de lecture en trop ne dérange personne, une commande
         * d'écriture qui ne commande rien passe pour une panne. */
        $lectureSeule = ($this->texte($ui, 'view') === 'label');
        $unite  = $this->texte($ui, 'unit');

        $capacites = array(
            'boolean' => 'generic.binary',
            'number'  => 'generic.numeric',
            'text'    => 'generic.value',
            'enum'    => 'generic.value',
        );
        $cleEtat = $racine . '.value';
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $cleEtat,
            'capability' => $capacites[$_type],
            'name'       => $nom,
            'unit'       => $unite,
            'source'     => $this->venantDe($_prefixe, $_cle, 'value'),
        )));
        if ($lectureSeule) {
            return;
        }

        $methode = $this->methodeDe($_type) . '.Set';
        if ($_type === 'boolean') {
            foreach (array('on' => array('switch.on', 'Activer', 'true'),
                           'off' => array('switch.off', 'Désactiver', 'false')) as $role => $action) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => $racine . '.' . $role,
                    'capability' => $action[0],
                    'name'       => $action[1] . $_etiquette,
                    'sink'       => $this->appelBrut($_prefixe, $methode,
                                        '{"id":' . $rang . ',"value":' . $action[2] . '}'),
                    'links'      => array('state' => $cleEtat),
                )));
            }
            return;
        }
        if ($_type === 'number') {
            /*
             * LES BORNES D'UN NOMBRE VIRTUEL SONT CELLES QU'ON LUI A DONNÉES.
             *
             * `min` et `max` sont des champs de configuration de premier niveau
             * — à la différence de l'unité, qui vit dans `meta.ui`. Un nombre
             * réglé de 15 à 25 pour une consigne, ou de 0 à 1000 pour des
             * litres, devient sans eux un curseur 0-100 qui ne peut pas
             * atteindre les valeurs pour lesquelles il a été créé.
             */
            $curseur = array();
            if (isset($_config['min']) && is_numeric($_config['min'])) {
                $curseur['min'] = 0 + $_config['min'];
            }
            if (isset($_config['max']) && is_numeric($_config['max'])) {
                $curseur['max'] = 0 + $_config['max'];
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.set',
                'capability' => 'generic.slider',
                'name'       => 'Régler' . $_etiquette,
                'unit'       => $unite,
                'sink'       => $this->appelBrut($_prefixe, $methode,
                                    '{"id":' . $rang . ',"value":#slider#}'),
                'value'      => empty($curseur) ? array() : array('slider' => $curseur),
                'links'      => array('state' => $cleEtat),
            )));
            return;
        }
        if ($_type === 'text') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.set',
                'capability' => 'generic.text',
                'name'       => 'Écrire' . $_etiquette,
                /*
                 * `#message_json#` et non `#message#` : le texte saisi est
                 * substitué DÉJÀ entouré de guillemets et échappé. Écrire
                 * `"value":"#message#"` marche tant que personne ne tape de
                 * guillemet, de barre oblique inverse ou de retour à la ligne —
                 * après quoi la charge utile n'est plus du JSON, l'appareil la
                 * rejette sans un mot, et la commande passe pour cassée.
                 */
                'sink'       => $this->appelBrut($_prefixe, $methode,
                                    '{"id":' . $rang . ',"value":#message_json#}'),
                'links'      => array('state' => $cleEtat),
            )));
        }
        /*
         * `enum` : la valeur se lit, elle ne s'écrit pas.
         *
         * Écrire dans une énumération veut dire « choisir parmi N options », et
         * le vocabulaire n'a aucune capacité de ce genre — seul
         * `thermostat.set_mode` est de ce sous-type, et il est réservé au
         * thermostat. Créer N commandes d'action, une par option, fonctionnerait
         * et donnerait un tableau de bord illisible pour une énumération à huit
         * entrées. Mieux vaut une lecture honnête qu'une écriture encombrante.
         */
    }

    /*
     * Système : ce qui mérite une commande, et rien d'autre.
     *
     * `sys` porte une quarantaine de champs. Trois seulement intéressent
     * quelqu'un depuis Jeedom : depuis quand l'appareil tourne, combien de
     * mémoire il lui reste, et s'il existe une mise à jour. Le reste — révisions
     * de configuration, fuseau horaire, taille du système de fichiers — se règle
     * dans l'appareil et se lit dans son interface.
     */
    private function ajouteSysteme($_modele, $_prefixe, $_statut) {
        if (array_key_exists('uptime', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'sys.uptime',
                'capability' => 'device.uptime',
                'name'       => 'Durée de fonctionnement',
                'unit'       => 's',
                'source'     => $this->venantDe($_prefixe, 'sys', 'uptime'),
            )));
        }
        if (array_key_exists('ram_free', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'sys.ram_free',
                'capability' => 'device.memory',
                'name'       => 'Mémoire libre',
                'unit'       => 'o',
                'source'     => $this->venantDe($_prefixe, 'sys', 'ram_free'),
            )));
        }
        /*
         * « Mise à jour disponible » ne se crée que s'il y en a une.
         *
         * `available_updates` vaut `{}` quand l'appareil est à jour : le
         * sélecteur rend alors `null`, que le routeur laisse tomber sans rien
         * écrire — et la commande continuerait d'annoncer, pour toujours, la
         * version qui vient justement d'être installée. Le modèle étant
         * reconstruit à chaque notification, la commande réapparaîtra le jour où
         * une mise à jour sortira, et disparaîtra une fois posée.
         */
        if (isset($_statut['available_updates']['stable']['version'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'sys.update',
                'capability' => 'generic.value',
                'name'       => 'Mise à jour disponible',
                'source'     => $this->venantDe($_prefixe, 'sys', 'available_updates.stable.version'),
            )));
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => 'sys.restart',
            'capability' => 'device.restart',
            'name'       => 'Redémarrer',
            'sink'       => $this->appel($_prefixe, 'Shelly.Reboot', array()),
        )));
    }

    /* Le signal Wi-Fi. Masqué par défaut — il change à chaque trame et
     * encombrerait le tableau de bord — mais c'est la première chose qu'on
     * regarde quand un appareil devient capricieux. */
    private function ajouteReseau($_modele, $_prefixe, $_statut) {
        /* L'état de la liaison, en quatre mots documentés — `disconnected`,
         * `connecting`, `connected`, `got ip`. Un appareil qui va et vient le
         * dit ici avant que le RSSI n'ait l'air suspect. */
        if (array_key_exists('status', $_statut)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'wifi.status',
                'capability' => 'generic.value',
                'name'       => 'État Wi-Fi',
                'source'     => $this->venantDe($_prefixe, 'wifi', 'status'),
            )));
        }
        if (!array_key_exists('rssi', $_statut)) {
            return;
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => 'wifi.rssi',
            'capability' => 'connectivity.rssi',
            'name'       => 'Signal',
            'unit'       => 'dBm',
            'source'     => $this->venantDe($_prefixe, 'wifi', 'rssi'),
        )));
    }

    /*
     * LES ÉVÉNEMENTS, ET LA LIMITE QU'IL FAUT DIRE EN FACE.
     *
     * Un appui sur un bouton arrive dans un `NotifyEvent`, dont la charge utile
     * est de cette forme :
     *
     *   {"method":"NotifyEvent","params":{"ts":…,"events":[
     *      {"component":"input:0","id":0,"event":"single_push","ts":…}]}}
     *
     * Le composant concerné est DANS la charge utile, pas dans le topic. Or le
     * plugin sélectionne une valeur par un chemin par points, et un chemin ne
     * sait pas filtrer : il n'existe aucune façon d'écrire « le champ `event`
     * de l'entrée qui porte le numéro 1 ». Créer une commande « Événement
     * entrée 1 » et une « Événement entrée 2 » qui liraient toutes deux
     * `params.events.0.event` serait pire que tout — elles afficheraient la
     * même chose, et l'une des deux mentirait à chaque appui.
     *
     * D'où deux commandes par appareil, et non deux par entrée : ce qui s'est
     * passé, et où. Un scénario Jeedom teste les deux, ce qui est exactement ce
     * qu'il aurait fait d'une commande par entrée. C'est moins joli et tout
     * aussi exact.
     *
     * `repeat: always` sur les deux, et il est indispensable : deux appuis
     * courts de suite donnent deux fois « single_push », et en mode `onchange`
     * le second serait avalé — le scénario ne partirait pas.
     */
    private function ajouteEvenements($_modele, $_prefixe) {
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => 'event.name',
            'capability' => 'button.event',
            'name'       => 'Dernier événement',
            'source'     => array(
                'topic'    => $_prefixe . '/events/rpc',
                'selector' => array('type' => 'json', 'path' => 'params.events.0.event'),
            ),
            /* `always` parce que deux appuis courts de suite donnent deux fois
             * « single_push », et qu'en mode `onchange` le second serait avalé.
             * `ignore_retained` parce que c'est le revers exact de `always` :
             * une commande qui réagit à tout réagirait aussi à ce que le broker
             * rejoue au démarrage du démon. `events/rpc` n'est pas censé être
             * retenu, mais un pont mal réglé suffit, et l'accident est alors
             * indétectable — le scénario part, et rien n'en donne la cause. */
            'value'      => array('repeat' => array('mode' => 'always', 'ignore_retained' => true)),
        )));
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => 'event.component',
            'capability' => 'generic.value',
            'name'       => 'Composant de l\'événement',
            'source'     => array(
                'topic'    => $_prefixe . '/events/rpc',
                'selector' => array('type' => 'json', 'path' => 'params.events.0.component'),
            ),
            'value'      => array('repeat' => array('mode' => 'always', 'ignore_retained' => true)),
        )));
    }

    /* --------------------------------------------------------------------- */
    /* Composer un canal                                                     */
    /* --------------------------------------------------------------------- */

    /*
     * D'où vient une valeur : toujours `<P>/events/rpc`, toujours un chemin
     * `params.<composant>.<champ>`.
     *
     * Le `:` de `switch:0` ne coupe pas un chemin par points, et le démon
     * traverse donc `params` puis `switch:0` puis `output` sans qu'on ait rien
     * à échapper. Un composant absent de la trame rend `null`, que le routeur
     * laisse tomber : c'est ce qui rend les notifications partielles
     * inoffensives.
     */
    private function venantDe($_prefixe, $_cle, $_champ) {
        return array(
            'topic'    => $_prefixe . '/events/rpc',
            'selector' => array('type' => 'json', 'path' => 'params.' . $_cle . '.' . $_champ),
        );
    }

    /*
     * Où part une action : un appel RPC publié sur `<P>/rpc`.
     *
     * On passe par le RPC et non par les topics `<P>/command/<composant>`, qui
     * seraient plus courts. Deux raisons. Le RPC couvre TOUS les composants —
     * les topics de commande ne connaissent que le système, l'interrupteur, le
     * volet, et l'éclairage depuis le micrologiciel 1.6.0 seulement. Et il
     * dépend d'un réglage de moins : `enable_rpc` suffit, là où les topics de
     * commande exigent `enable_control`. Un mécanisme unique qui marche partout
     * vaut mieux que deux mécanismes dont l'un a des trous.
     */
    private function appel($_prefixe, $_methode, $_params) {
        $charge = array('id' => 0, 'src' => self::SRC_ACTIONS, 'method' => $_methode);
        if (!empty($_params)) {
            $charge['params'] = $_params;
        }
        return array(
            'topic'   => $_prefixe . '/rpc',
            'payload' => json_encode($charge, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'qos'     => $this->qosDe($_methode),
            'retain'  => false,
        );
    }

    /*
     * LE NIVEAU DE SERVICE D'UNE ACTION, ET POURQUOI IL N'EST PAS LE MÊME
     * PARTOUT.
     *
     * La documentation ne connaît qu'un niveau sur ce canal : « the supported
     * quality of service level is 1 ». En QoS 0, un appui sur un bouton se perd
     * sans un mot dès que la liaison hoquette — c'est « au plus une fois », et
     * l'utilisateur n'a que l'absence de réaction de sa lampe pour l'apprendre.
     *
     * Mais « au moins une fois » veut dire qu'un message peut être livré DEUX
     * fois, et deux appels ne se valent pas là-dessus. `Switch.Set{on:true}`
     * rejoué laisse la sortie allumée ; `Switch.Toggle` rejoué l'éteint, et
     * `Shelly.Reboot` rejoué redémarre une seconde fois un appareil qui vient
     * tout juste de revenir. Ces deux-là restent donc en QoS 0 : mieux vaut un
     * ordre perdu, que l'utilisateur voit et refait, qu'un ordre exécuté deux
     * fois, qu'il ne comprendra jamais.
     */
    private function qosDe($_methode) {
        $methode = (string) $_methode;
        if (substr($methode, -7) === '.Toggle' || $methode === 'Shelly.Reboot') {
            return 0;
        }
        return 1;
    }

    /*
     * Le même appel, mais dont les paramètres sont écrits à la main.
     *
     * json_encode() ne peut pas servir ici : la charge utile contient des
     * jetons comme `#slider#` ou `#red#`, que Jeedom remplacera par un NOMBRE
     * au moment de l'appui. Les faire passer par json_encode les entourerait de
     * guillemets, et l'appareil refuserait l'appel — `pos` attend un entier,
     * pas la chaîne « 42 ».
     */
    private function appelBrut($_prefixe, $_methode, $_params) {
        return array(
            'topic'   => $_prefixe . '/rpc',
            'payload' => '{"id":0,"src":"' . self::SRC_ACTIONS . '","method":"' . $_methode
                       . '","params":' . $_params . '}',
            'qos'     => $this->qosDe($_methode),
            'retain'  => false,
        );
    }

    /* --------------------------------------------------------------------- */
    /* Identité, noms, catalogue                                             */
    /* --------------------------------------------------------------------- */

    /*
     * La MAC, dans l'ordre où les sources sont fiables.
     *
     * 1. `Shelly.GetDeviceInfo.mac` — la source officielle, en majuscules.
     * 2. `sys.device.mac` — la même, vue par la configuration.
     * 3. Le suffixe de l'identifiant d'appareil : « shellyplus1pm-a8032abd1234 »
     *    porte la MAC complète, en minuscules. Cette source-là est celle qui
     *    permet de décrire un appareil auquel on n'a jamais parlé, à partir de
     *    la seule trame de notification qu'il a poussée.
     * 4. Le préfixe de topic, s'il a gardé la forme d'usine.
     *
     * Toujours rendue en majuscules : c'est `construit()` qui la met en
     * minuscules pour l'uid, et deux orthographes donneraient deux équipements.
     */
    private function macDe($_info, $_identifiant, $_prefixe) {
        $candidats = array($this->texte($_info, 'mac'));
        if (isset($_info['device']) && is_array($_info['device'])) {
            $candidats[] = $this->texte($_info['device'], 'mac');
        }
        foreach ($candidats as $candidat) {
            $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $candidat));
            if (preg_match('/^[0-9A-F]{12}$/', $mac)) {
                return $mac;
            }
        }
        foreach (array((string) $_identifiant, (string) $_prefixe) as $nom) {
            if ($nom === '') {
                continue;
            }
            $morceaux = explode('/', $nom);
            $morceaux = explode('-', (string) end($morceaux));
            $dernier  = strtoupper((string) end($morceaux));
            if (preg_match('/^[0-9A-F]{12}$/', $dernier)) {
                return $dernier;
            }
        }
        return '';
    }

    /*
     * Le nom que l'utilisateur a donné à son appareil.
     *
     * `sys.device.name` est le champ documenté ; `Shelly.GetDeviceInfo.name`
     * existe sur les micrologiciels récents mais n'est écrit nulle part dans la
     * documentation — d'où l'ordre, et d'où le fait de ne jamais dépendre du
     * second seul. Les deux valent `null` tant que personne n'a renommé
     * l'appareil, et un `null` n'est pas un nom : `texte()` le rend vide, et le
     * modèle garde alors son seul nom technique.
     */
    private function nomUtilisateur($_info, $_composants) {
        if (isset($_composants['sys']['config']['device']) && is_array($_composants['sys']['config']['device'])) {
            $nom = $this->texte($_composants['sys']['config']['device'], 'name');
            if ($nom !== '') {
                return $nom;
            }
        }
        return $this->texte($_info, 'name');
    }

    /*
     * « Shelly Plus 1PM 1A0805 » : le nom commercial, puis les six derniers
     * chiffres de la MAC.
     *
     * Deux appareils du même modèle sont la règle et non l'exception, et deux
     * équipements homonymes font échouer l'enregistrement du second — eqLogic
     * (name, object_id) est unique. Le suffixe est aussi ce que son propriétaire
     * lit sur l'étiquette du boîtier et dans l'application Shelly.
     */
    private function nomLisible($_code, $_info, $_mac) {
        $nom = $this->nomCommercial($_code, $_info);
        $mac = strtoupper((string) $_mac);
        if (strlen($mac) >= 6) {
            $nom .= ' ' . substr($mac, -6);
        }
        return $nom;
    }

    /*
     * Le nom commercial, et son repli.
     *
     * Le catalogue est décoratif : s'il manque, ou si le modèle n'y figure pas
     * — un appareil sorti après la dernière mise à jour du plugin —, l'appareil
     * est découvert exactement de la même façon. Le repli est meilleur qu'en
     * génération 1 : l'appareil publie lui-même un nom d'application lisible
     * (« Plus1PM », « Mini1G3 »), et « Shelly Plus1PM » vaut mieux que
     * « SNSW-001P16EU ». Le code constructeur ne sert qu'en dernier recours.
     */
    public function nomCommercial($_code, $_info = array()) {
        $fiche = $this->fiche($_code);
        $nom = isset($fiche['name']) ? trim((string) $fiche['name']) : '';
        if ($nom !== '') {
            return $nom;
        }
        $application = $this->texte($_info, 'app');
        if ($application !== '') {
            return 'Shelly ' . $application;
        }
        return ((string) $_code === '') ? 'Shelly' : (string) $_code;
    }

    /*
     * Un appareil qui dort.
     *
     * Trois indices, et les deux premiers viennent de l'appareil lui-même :
     *
     *   `sys.wakeup_period` — la période de réveil, en secondes. Un appareil
     *      sur secteur ne dort pas et la publie à zéro, ou pas du tout. C'est
     *      le critère le plus sûr, et c'est celui de l'intégration de
     *      référence ;
     *   un composant `devicepower` — il n'existe que sur un appareil alimenté
     *      par pile ;
     *   le catalogue — six modèles, écrits à la main. Il ne sert qu'avant
     *      qu'on n'ait le moindre inventaire, c'est-à-dire au moment précis où
     *      il faut décider de ne pas interroger l'appareil.
     */
    private function surPile($_code, $_composants) {
        if (isset($_composants['sys']['status']['wakeup_period'])
            && is_numeric($_composants['sys']['status']['wakeup_period'])
            && (float) $_composants['sys']['status']['wakeup_period'] > 0) {
            return true;
        }
        foreach (array_keys($_composants) as $cle) {
            if ($this->typeDe($cle) === 'devicepower') {
                return true;
            }
        }
        $fiche = $this->fiche($_code);
        return isset($fiche['battery']) && $fiche['battery'];
    }

    /* L'appareil dort-il, au vu de ce qu'on sait avant toute conversation ? */
    private function dortSurPile($_dossier) {
        $info = (isset($_dossier['info']) && is_array($_dossier['info'])) ? $_dossier['info'] : array();
        return $this->surPile($this->texte($info, 'model'), $this->composantsDe($_dossier));
    }

    /* L'adresse IP, telle que l'appareil la publie. `eth` d'abord : un Pro
     * branché en Ethernet garde un `wifi.sta_ip` à null, et c'est l'adresse
     * filaire qui ouvre son interface. */
    private function adresseDe($_composants) {
        foreach (array(array('eth', 'ip'), array('wifi', 'sta_ip')) as $piste) {
            if (!isset($_composants[$piste[0]]['status'])) {
                continue;
            }
            $adresse = $this->texte($_composants[$piste[0]]['status'], $piste[1]);
            if (filter_var($adresse, FILTER_VALIDATE_IP) !== false) {
                return $adresse;
            }
        }
        return '';
    }

    private function catalogue() {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }
        $this->catalogue = array();
        if (is_string($this->cheminCatalogue) && is_readable($this->cheminCatalogue)) {
            $brut = file_get_contents($this->cheminCatalogue);
            $decode = ($brut === false) ? null : json_decode($brut, true);
            if (is_array($decode) && isset($decode['models']) && is_array($decode['models'])) {
                $this->catalogue = $decode['models'];
            }
        }
        return $this->catalogue;
    }

    private function fiche($_code) {
        $catalogue = $this->catalogue();
        $code = strtoupper(trim((string) $_code));
        return (isset($catalogue[$code]) && is_array($catalogue[$code])) ? $catalogue[$code] : array();
    }

    /* --------------------------------------------------------------------- */
    /* Mémoire de l'adapter                                                  */
    /* --------------------------------------------------------------------- */

    /*
     * Un dossier par appareil, indexé par son PRÉFIXE de topic.
     *
     * Le préfixe, et non l'identifiant : c'est lui qui dit où joindre
     * l'appareil, et c'est la seule chose qu'on connaisse d'un appareil vu par
     * son seul `online`. L'identifiant, lui, mène au préfixe par une table à
     * part — une annonce diffusée le donne sans dire où publier.
     *
     * La mémoire du contexte se lit par clé, elle ne s'énumère pas : d'où
     * l'inventaire. Et elle est bornée à 512 clés par adapter, d'où une clé par
     * appareil et une seule pour toutes les questions en vol.
     */
    private function ouvreDossier($_ctx, $_prefixe, $_origine) {
        $prefixe = trim((string) $_prefixe);
        if ($prefixe === '') {
            return null;
        }
        /*
         * `shellies/...` n'est pas un préfixe Gen2 : c'est la racine de la
         * génération précédente, dont l'autre adapter s'occupe. Sans cette
         * exclusion, chaque `shellies/<id>/online` d'un parc Gen1 ouvrirait ici
         * un dossier, et l'on publierait des appels RPC sur `shellies/<id>/rpc`
         * où personne n'écoute — vingt-deux appareils, soixante-six questions
         * sans réponse à chaque découverte.
         */
        if ($prefixe === self::RACINE_GEN1
            || strpos($prefixe, self::RACINE_GEN1 . '/') === 0) {
            return null;
        }
        if (!preg_match(self::PREFIXE_VALIDE, $prefixe)) {
            $_ctx->log('debug', 'Shelly Gen2+ : préfixe « ' . $this->citation($prefixe)
                . ' » refusé — il composerait les topics de l\'équipement.');
            return null;
        }

        $dossier = $this->dossier($_ctx, $prefixe);
        if (!isset($dossier['etape'])) {
            $dossier['etape']   = 'info';
            $dossier['essais']  = 0;
            $dossier['offset']  = 0;
            $dossier['pages']   = 0;
            $dossier['attente'] = array();
            $dossier['vu']      = $this->maintenant($_ctx);
            $_ctx->log('debug', 'Shelly Gen2+ : « ' . $prefixe . ' » repéré (' . $_origine . ').');
        }
        $this->range($_ctx, $dossier);
        return $dossier;
    }

    private function dossier($_ctx, $_prefixe) {
        $dossier = $this->lit($_ctx, 'dev:' . $_prefixe);
        if (!isset($dossier['prefixe'])) {
            $dossier['prefixe'] = (string) $_prefixe;
        }
        return $dossier;
    }

    private function range($_ctx, $_dossier) {
        $prefixe = $this->texte($_dossier, 'prefixe');
        if ($prefixe === '') {
            return;
        }
        $this->ecrit($_ctx, 'dev:' . $prefixe, $_dossier);
        $inventaire = $this->inventaire($_ctx);
        if (!in_array($prefixe, $inventaire, true)) {
            $inventaire[] = $prefixe;
            $this->ecrit($_ctx, 'index', $inventaire);
        }
    }

    public function inventaire($_ctx) {
        $inventaire = $_ctx->recall(self::ID . ':index');
        return is_array($inventaire) ? array_values($inventaire) : array();
    }

    /* La table identifiant d'appareil → préfixe. Elle n'existe que pour
     * l'annonce diffusée, qui donne l'un sans l'autre. */
    private function nommePrefixe($_ctx, $_identifiant, $_prefixe) {
        if ($_identifiant === '' || $_prefixe === '') {
            return;
        }
        $table = $this->lit($_ctx, 'prefixes');
        if (isset($table[$_identifiant]) && $table[$_identifiant] === $_prefixe) {
            return;
        }
        if (count($table) > 512) {
            $table = array_slice($table, -256, null, true);
        }
        $table[$_identifiant] = $_prefixe;
        $this->ecrit($_ctx, 'prefixes', $table);
    }

    private function prefixeDe($_ctx, $_identifiant) {
        $table = $this->lit($_ctx, 'prefixes');
        return isset($table[$_identifiant]) ? (string) $table[$_identifiant] : '';
    }

    private function lit($_ctx, $_cle) {
        $valeur = $_ctx->recall(self::ID . ':' . $_cle);
        return is_array($valeur) ? $valeur : array();
    }

    private function ecrit($_ctx, $_cle, $_valeur) {
        $_ctx->remember(self::ID . ':' . $_cle, $_valeur);
    }

    /* --------------------------------------------------------------------- */
    /* Petits outils                                                         */
    /* --------------------------------------------------------------------- */

    /*
     * L'inventaire des composants, quelle qu'en soit la provenance.
     *
     * Deux sources qui ne se valent pas : la conversation RPC, qui donne statut
     * ET configuration — donc les noms que l'utilisateur a donnés à chaque
     * sortie —, et les notifications, qui ne donnent que le statut. La première
     * l'emporte ; la seconde complète ce qu'elle n'a pas vu, et c'est tout ce
     * dont on dispose pour un appareil qui dort.
     */
    private function composantsDe($_dossier) {
        $composants = isset($_dossier['composants']) && is_array($_dossier['composants'])
            ? $_dossier['composants'] : array();
        $observes = isset($_dossier['observes']) && is_array($_dossier['observes'])
            ? $_dossier['observes'] : array();
        foreach ($observes as $cle => $statut) {
            if (!isset($composants[$cle])) {
                $composants[$cle] = array('status' => $statut, 'config' => array());
                continue;
            }
            $connu = isset($composants[$cle]['status']) && is_array($composants[$cle]['status'])
                ? $composants[$cle]['status'] : array();
            $composants[$cle]['status'] = array_merge($connu, $statut);
        }
        ksort($composants);
        return $composants;
    }

    /*
     * La clé d'un composant, normalisée.
     *
     * La documentation écrit « PM1:<id> » là où les micrologiciels publient
     * « pm1:0 » : comparer sans normaliser ferait manquer le wattmètre d'un
     * appareil sur deux, selon la source dont l'inventaire provient. Tout est
     * donc ramené en minuscules, une fois, à l'entrée.
     */
    private function cleComposant($_cle) {
        $cle = strtolower(trim((string) $_cle));
        return preg_match('/^[a-z0-9_]+(:[0-9]+)?$/', $cle) ? $cle : '';
    }

    private function typeDe($_cle) {
        $morceaux = explode(':', (string) $_cle);
        return $morceaux[0];
    }

    private function rangDe($_cle) {
        $morceaux = explode(':', (string) $_cle);
        return (count($morceaux) > 1) ? (int) $morceaux[1] : 0;
    }

    /* `switch:0` devient `switch.0` : le `:` n'a rien à faire dans un logicalId
     * de commande, où il se lit mal et se cherche plus mal encore. Le chemin du
     * sélecteur, lui, garde la clé telle que l'appareil l'écrit. */
    private function racineCle($_cle) {
        return str_replace(':', '.', (string) $_cle);
    }

    /* Le nom d'espace RPC d'un type de composant : `em1data` devient `EM1Data`,
     * `rgbw` devient `RGBW`, `switch` devient `Switch`. La clé est en
     * minuscules, la méthode ne l'est pas. */
    private function methodeDe($_type) {
        $particuliers = array(
            'rgb' => 'RGB', 'rgbw' => 'RGBW', 'cct' => 'CCT', 'rgbcct' => 'RGBCCT',
            'pm1' => 'PM1', 'em' => 'EM', 'em1' => 'EM1',
            'emdata' => 'EMData', 'em1data' => 'EM1Data',
        );
        if (isset($particuliers[$_type])) {
            return $particuliers[$_type];
        }
        return ucfirst((string) $_type);
    }

    /* Une charge utile illisible est un incident ordinaire — message tronqué,
     * appareil en cours de redémarrage — et non une erreur de programme. */
    private function json($_ctx, $_payload, $_quoi) {
        $decode = json_decode((string) $_payload, true);
        if (!is_array($decode)) {
            $_ctx->log('debug', 'Shelly Gen2+ : ' . $_quoi . ' illisible, message ignoré.');
            return null;
        }
        return $decode;
    }

    private function texte($_tableau, $_cle) {
        return (isset($_tableau[$_cle]) && !is_array($_tableau[$_cle]))
            ? trim((string) $_tableau[$_cle]) : '';
    }

    private function nombre($_tableau, $_cle) {
        return (isset($_tableau[$_cle]) && is_numeric($_tableau[$_cle])) ? (float) $_tableau[$_cle] : 0;
    }

    /* Une valeur venue du réseau, rendue citable dans une ligne de journal :
     * les caractères de contrôle ôtés — un retour à la ligne y fabriquerait une
     * fausse entrée de journal — et la longueur bornée. */
    private function citation($_valeur) {
        $texte = preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $_valeur);
        return (strlen($texte) > 48) ? substr($texte, 0, 48) . '…' : $texte;
    }

    /* Le temps vient du contexte : un contrôle hors ligne rejoue une journée en
     * quelques millisecondes, et time() lui ferait manquer toutes les
     * expirations. */
    private function maintenant($_ctx) {
        $maintenant = $_ctx->now();
        return is_numeric($maintenant) ? (float) $maintenant : 0;
    }
}
