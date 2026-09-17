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
 * CE QU'ON NE PEUT PAS FAIRE, ET POURQUOI
 *
 * `<P>/status/<composant>` serait le topic idéal — un objet de statut nu, par
 * composant. Il est muet : `status_ntf` vaut `false` en sortie d'usine, et le
 * critère de ce jalon est qu'un appareil soit découvert SANS que son
 * propriétaire n'ait à toucher à sa configuration. Les autres intégrations s'en
 * sortent en allant retourner ce réglage par HTTP ; cette porte-là nous est
 * fermée, et c'est très bien ainsi.
 *
 * Tout l'état passe donc par `<P>/events/rpc`, avec des sélecteurs JSON
 * `params.<composant>.<champ>`. Le démon les évalue tels quels — le `:` de
 * `switch:0` ne coupe pas un chemin par points — et un composant absent de la
 * trame rend `null`, que le routeur laisse tomber sans rien écrire. C'est
 * exactement ce qu'il faut : un `NotifyStatus` est un DELTA, il ne porte que ce
 * qui a changé, et une valeur absente ne doit jamais effacer une mesure valable.
 *
 * LA LIMITE, DITE FRANCHEMENT : `events/rpc` n'est pas retenu. Au redémarrage
 * du démon, les commandes gardent la dernière valeur que Jeedom a enregistrée,
 * et la première notification venue les rafraîchit. Un appareil qui ne change
 * pas d'état de la semaine n'émet rien, et ses commandes affichent une valeur
 * datée. Aucun topic d'usine ne permet de faire mieux : `NotifyFullStatus`
 * n'est poussé sur MQTT que par les appareils sur pile.
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
     * — sa file de publication est bornée à trente messages, et une seule
     * publication pouvait être en vol avant le micrologiciel 1.4.0.
     */
    const DELAI_REPONSE = 5.0;
    const TENTATIVES    = 3;

    /*
     * Délai avant de renoncer à une conversation et de conclure avec ce qu'on a.
     *
     * Des Gen2 sur SECTEUR restent muets au RPC tout en publiant leur
     * télémétrie : le silence ne prouve donc pas le sommeil, et il ne doit pas
     * faire disparaître l'appareil de la liste. Passé ce délai, un modèle
     * `probable` est émis avec ce que les notifications ont appris — c'est le
     * même parti que la génération 1 prend au bout de vingt secondes sans info.
     */
    const DELAI_ABANDON = 90;

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
     * La documentation officielle donne ce topic pour fixe, non préfixé, et
     * écouté d'usine par toutes les générations depuis la version 0.14.0. Aucune
     * intégration connue ne s'en sert, et un fil communautaire affirme même que
     * cela n'existe pas sur Gen2 : la documentation et la pratique se
     * contredisent, et sans matériel on ne peut pas trancher.
     *
     * D'où le parti pris : on le demande, parce que si cela répond, tout le parc
     * est identifié en un message ; et on ne construit RIEN dessus, parce que si
     * cela ne répond pas, `online` et `events/rpc` découvrent le même parc.
     */
    public function demandeAnnonce($_ctx, $_motif = '') {
        if ($_ctx->publish(self::DIFFUSION_COMMANDE, 'announce') !== true) {
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
        $this->ouvreDossier($_ctx, $_prefixe, 'online');
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
        if ($methode !== 'NotifyStatus' && $methode !== 'NotifyFullStatus') {
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
        $identifiant = $this->texte($trame, 'src');
        if ($identifiant !== '') {
            $dossier['src'] = $identifiant;
            $this->nommePrefixe($_ctx, $identifiant, $_prefixe);
        }

        /*
         * Fusionner, jamais remplacer — et `null` veut dire « cette clé n'existe
         * plus », pas « cette clé vaut null ». C'est écrit noir sur blanc dans
         * la documentation, et un merge naïf garderait éternellement des
         * composants que l'appareil a perdus : une sonde débranchée, un script
         * supprimé, un capteur BTHome qui ne répond plus.
         */
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
        }
        $dossier['observes'] = $observes;
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
        }
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
        if ($this->texte($trame, 'dst') !== $this->source) {
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
     * la version 1, et le code rendu n'est pas dans la liste des erreurs
     * communes : c'est un 404 accompagné de « No handler for … ». Le repli est
     * `Shelly.GetStatus` puis `Shelly.GetConfig`, dont l'union des clés donne
     * le même inventaire — au prix des deux plus grosses réponses de toute
     * l'API, ce pour quoi il reste un repli et non la voie normale.
     */
    private function recoitErreur($_ctx, $_dossier, $_methode, $_erreur) {
        $code    = isset($_erreur['code']) ? (int) $_erreur['code'] : 0;
        $message = isset($_erreur['message']) ? (string) $_erreur['message'] : '';
        $prefixe = $this->texte($_dossier, 'prefixe');

        if ($_methode === 'Shelly.GetComponents') {
            $_ctx->log('info', 'Shelly Gen2+ : ' . $prefixe . ' ne connaît pas Shelly.GetComponents ('
                . $code . ' ' . $this->citation($message) . ') — repli sur GetStatus puis GetConfig.');
            $_dossier['etape']  = 'status';
            $_dossier['essais'] = 0;
            unset($_dossier['composants']);
            $this->range($_ctx, $_dossier);
            return;
        }

        $_ctx->log('warning', 'Shelly Gen2+ : ' . $prefixe . ' a refusé ' . $_methode . ' ('
            . $code . ' ' . $this->citation($message) . ').');
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
             * Trois questions sans réponse. On conclut avec ce qu'on a.
             *
             * Le silence ne prouve rien : des Gen2 sur SECTEUR restent muets au
             * RPC tout en publiant leur télémétrie. Faire disparaître
             * l'appareil de la liste serait donc faux, et le laisser en
             * conversation éternelle le ferait interroger à chaque battement.
             * Ce qu'on a — l'identité, et ce que les notifications ont montré —
             * donne un équipement `probable`, qui deviendra `certain` le jour
             * où l'appareil répondra.
             */
            $_ctx->log('info', 'Shelly Gen2+ : ' . $prefixe . ' n\'a pas répondu à ' . $etape
                . ' après ' . self::TENTATIVES . ' tentatives ; on conclut avec ce qui est connu.');
            $_dossier['etape'] = 'fini';
            $this->range($_ctx, $_dossier);
            $this->emet($_ctx, $_dossier);
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

        if ($_ctx->publish($prefixe . '/rpc', $charge) !== true) {
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

        $connus = isset($_dossier['composants']) && is_array($_dossier['composants'])
            ? $_dossier['composants'] : array();
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
        if (count($composants) === 0 && $suivant < $total) {
            $_ctx->log('warning', 'Shelly Gen2+ : ' . $this->texte($_dossier, 'prefixe')
                . ' annonce ' . $total . ' composants mais n\'en livre plus à partir de ' . $debut
                . ' — pagination interrompue, l\'inventaire est incomplet.');
        }
        $_dossier['etape']   = 'fini';
        $_dossier['complet'] = true;
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
        $_ctx->log('info', 'Shelly Gen2+ : ' . $modele->name() . ' (' . $modele->uid() . ') — '
            . $modele->countChannels() . ' canaux, confiance ' . $modele->confidence() . '.');
        return true;
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
                case 'cct':
                    $this->ajouteLumiere($_modele, $_prefixe, $cle, $type, $rang, $etiquette, $statut);
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
                    break;
                case 'voltmeter':
                    $this->ajouteVoltmetre($_modele, $_prefixe, $cle, $etiquette, $statut, $config);
                    break;
                case 'devicepower':
                    $this->ajouteAlimentation($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'smoke':
                    $this->ajouteAlarme($_modele, $_prefixe, $cle, 'alarm.smoke',
                                        'Fumée' . $etiquette, $statut);
                    break;
                case 'flood':
                    $this->ajouteAlarme($_modele, $_prefixe, $cle, 'alarm.water_leak',
                                        'Fuite d\'eau' . $etiquette, $statut);
                    break;
                case 'presencezone':
                    $this->ajoutePresence($_modele, $_prefixe, $cle, $etiquette, $statut);
                    break;
                case 'boolean':
                case 'number':
                case 'text':
                case 'enum':
                    $this->ajouteVirtuel($_modele, $_prefixe, $cle, $type, $etiquette, $statut, $config);
                    break;
                case 'sys':
                    $this->ajouteSysteme($_modele, $_prefixe, $statut);
                    $aDesEvenements = true;
                    break;
                case 'wifi':
                    $this->ajouteReseau($_modele, $_prefixe, $statut);
                    break;
                default:
                    /* ble, cloud, mqtt, ws, script, schedule, knx, modbus,
                     * group, et les composants d'interface au statut vide :
                     * rien à en tirer qui mérite une commande. Les créer
                     * remplirait le tableau de bord de réglages que personne ne
                     * regarde depuis Jeedom. */
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
     * La position sert d'état, comme pour la génération 1 : c'est elle que le
     * widget de volet montre, et « ouvre » ou « ferme » ne se range dans aucune
     * capacité du vocabulaire.
     *
     * Le curseur de position, lui, ne se crée que si l'appareil sait où il en
     * est. C'est `pos_control` qui le dit, et non la présence de `current_pos` :
     * un volet non calibré publie un `current_pos` absent ou nul, et un curseur
     * qui renverrait une position à un appareil incapable de s'y rendre ne
     * ferait rien du tout, sans un mot pour l'expliquer.
     */
    private function ajouteVolet($_modele, $_prefixe, $_cle, $_rang, $_etiquette, $_statut, $_config) {
        $racine  = $this->racineCle($_cle);
        $cleEtat = $racine . '.state';
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $cleEtat,
            'capability' => 'cover.state',
            'name'       => 'Position' . $_etiquette,
            'unit'       => '%',
            'source'     => $this->venantDe($_prefixe, $_cle, 'current_pos'),
            'value'      => array('transform' => array('round' => 0)),
        )));
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
        if (!empty($_statut['pos_control'])) {
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
    private function ajouteLumiere($_modele, $_prefixe, $_cle, $_type, $_rang, $_etiquette, $_statut) {
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
                /* La voie blanche se compte en 0 à 255, là où la luminosité se
                 * compte en pourcents. Un curseur Jeedom va de 0 à 100 : sans
                 * cette conversion, pousser le curseur à fond donnerait 100 sur
                 * 255, soit une voie blanche au tiers de sa puissance. */
                'value'      => array('transform' => array('scale' => 0.39215686274509803, 'round' => 0)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.white',
                'capability' => 'light.white',
                'name'       => 'Voie blanche' . $_etiquette,
                'unit'       => '%',
                'sink'       => $this->appelBrut($_prefixe, $methode . '.Set',
                                    '{"id":' . $_rang . ',"on":true,"white":#slider#}'),
                'links'      => array('state' => $cleBlanc),
            )));
        }
        if (array_key_exists('ct', $_statut)) {
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
            return;
        }
        if ($mode === 'count') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.counts',
                'capability' => 'generic.numeric',
                'name'       => 'Comptage' . $_etiquette,
                'source'     => $this->venantDe($_prefixe, $_cle, 'counts.total'),
            )));
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
        if (!array_key_exists('xvoltage', $_statut)) {
            return;
        }
        $unite = '';
        if (isset($_config['xvoltage']) && is_array($_config['xvoltage'])) {
            $unite = $this->texte($_config['xvoltage'], 'unit');
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $this->racineCle($_cle) . '.xvoltage',
            'capability' => 'generic.numeric',
            'name'       => 'Mesure' . $_etiquette,
            'unit'       => $unite,
            'source'     => $this->venantDe($_prefixe, $_cle, 'xvoltage'),
            'value'      => array('transform' => array('round' => 2)),
        )));
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

    /* Une alarme : un booléen, et le widget qui va avec. */
    private function ajouteAlarme($_modele, $_prefixe, $_cle, $_capacite, $_nom, $_statut) {
        if (!array_key_exists('alarm', $_statut)) {
            return;
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => $this->racineCle($_cle) . '.alarm',
            'capability' => $_capacite,
            'name'       => $_nom,
            'source'     => $this->venantDe($_prefixe, $_cle, 'alarm'),
        )));
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
        if (!array_key_exists('value', $_statut)) {
            return;
        }
        $racine = $this->racineCle($_cle);
        $rang   = $this->rangDe($_cle);
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
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.set',
                'capability' => 'generic.slider',
                'name'       => 'Régler' . $_etiquette,
                'unit'       => $unite,
                'sink'       => $this->appelBrut($_prefixe, $methode,
                                    '{"id":' . $rang . ',"value":#slider#}'),
                'links'      => array('state' => $cleEtat),
            )));
            return;
        }
        if ($_type === 'text') {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $racine . '.set',
                'capability' => 'generic.text',
                'name'       => 'Écrire' . $_etiquette,
                /* `#message#` est substitué par le texte saisi. Les guillemets
                 * l'entourent ici, parce que `value` attend une chaîne — à la
                 * différence d'un curseur, qui attend un nombre. */
                'sink'       => $this->appelBrut($_prefixe, $methode,
                                    '{"id":' . $rang . ',"value":"#message#"}'),
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
        if (isset($_statut['available_updates'])) {
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
            'value'      => array('repeat' => array('mode' => 'always')),
        )));
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => 'event.component',
            'capability' => 'generic.value',
            'name'       => 'Composant de l\'événement',
            'source'     => array(
                'topic'    => $_prefixe . '/events/rpc',
                'selector' => array('type' => 'json', 'path' => 'params.events.0.component'),
            ),
            'value'      => array('repeat' => array('mode' => 'always')),
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
            'qos'     => 0,
            'retain'  => false,
        );
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
            'qos'     => 0,
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
     * Deux indices, et le second est le plus sûr : le catalogue le dit — six
     * modèles seulement —, ou l'appareil porte un composant `devicepower`, qui
     * n'existe que sur un appareil alimenté par pile. Le second l'emporte
     * toujours, parce qu'il vient de l'appareil et non d'une liste écrite à la
     * main ; le premier sert avant qu'on n'ait le moindre inventaire, c'est-à-
     * dire au moment précis où il faut décider de ne pas l'interroger.
     */
    private function surPile($_code, $_composants) {
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
            'rgb' => 'RGB', 'rgbw' => 'RGBW', 'cct' => 'CCT',
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
