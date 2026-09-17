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

/* L'interface vit dans Adapter.php, à côté du moteur. Elle est chargée ici
 * parce qu'un adapter doit pouvoir être inclus seul — par un contrôle hors
 * ligne, par exemple — sans que l'ordre des inclusions du démon soit rejoué. */
if (!interface_exists('MqttbeAdapter') && is_readable(__DIR__ . '/../Adapter.php')) {
    require_once __DIR__ . '/../Adapter.php';
}
if (!interface_exists('MqttbeAdapter')) {
    /* Filet de sécurité, et rien de plus : dès que Adapter.php existe, c'est
     * lui qui fait foi (require_once ci-dessus) et ce bloc ne s'exécute jamais. */
    interface MqttbeAdapter {
        public function id();
        public function priority();
        public function subscriptions();
        public function onMessage($_topic, $_payload, $_retained, $_ctx);
        public function onTick($_ctx);
    }
}

/* =============================================================================
 * Découverte des passerelles OpenMQTTGateway et des balises Bluetooth qu'elles
 * voient.
 *
 * LA RÈGLE QUI GOUVERNE TOUT CE FICHIER : aucun catalogue de balises, aucun
 * identifiant de constructeur, aucun décodage de `manufacturerdata`.
 *
 * Décoder est le métier de la passerelle, et elle le fait mieux — c'est là
 * qu'est Theengs, avec ses centaines de modèles, mis à jour à chaque version du
 * micrologiciel. Un catalogue recopié ici serait périmé le jour de son écriture
 * et faux le lendemain. L'adapter ne fait donc qu'une chose : TRADUIRE DES NOMS
 * DE CHAMPS EN CAPACITÉS. Le jour où la passerelle est mise à jour et se met à
 * décoder un capteur de plus, les commandes apparaissent sans qu'on touche au
 * plugin — et un champ que personne n'a prévu devient une valeur générique
 * plutôt que d'être jeté.
 *
 * AUCUNE DÉPENDANCE À UN AUTRE SYSTÈME DOMOTIQUE. La passerelle publie aussi,
 * sur une branche à part, des messages de découverte destinés à un autre
 * logiciel : ils ne sont ni écoutés, ni lus, ni même nommés ici. Les en lire
 * ferait dépendre le plugin d'un réglage qui ne lui appartient pas — celui qui
 * décoche « Auto discovery » sur sa passerelle verrait Jeedom cesser de voir
 * quoi que ce soit, sans un mot pour l'expliquer. Les topics natifs disent
 * tout, et c'est précisément pour s'en servir ailleurs qu'on les lit.
 *
 * CE QUE LA PASSERELLE PUBLIE, ET QU'ON LIT
 *
 *   <base>/SYStoMQTT     identité et santé : mac, ip, version, env, uptime,
 *                        freemem, tempc, rssi. C'est la `mac` qui fait
 *                        l'identité, JAMAIS le préfixe de topic.
 *   <base>/LWT           online / offline, retenu, publié par testament.
 *   <base>/RLStoMQTT     la version disponible en amont.
 *   <base>/BTtoMQTT      la configuration courante du module Bluetooth.
 *   <base>/BTtoMQTT/<adresse>   une trame BLE, brute ou décodée.
 *   <base>/commands/MQTTtoSYS/config   ordres système.
 *   <base>/commands/MQTTtoBT/config    réglages Bluetooth.
 *
 * DEUX SORTES D'ÉQUIPEMENTS
 *
 *   1. Une passerelle = un équipement, `omg:<mac>`. Capteurs de santé,
 *      disponibilité par le LWT, et les actions qui se publient sur les deux
 *      topics `commands`.
 *   2. Une balise = un équipement, `ble:<mac>`, FUSIONNÉ ENTRE PASSERELLES. Une
 *      même balise vue par trois passerelles donne un seul équipement, avec un
 *      RSSI par passerelle et la passerelle la plus proche en commande texte :
 *      c'est l'indication de pièce, et c'est ce qui a le plus de valeur.
 *
 * CE QUI SE CRÉE TOUT SEUL ET CE QUI ATTEND — PAR `identity.confidence`
 *
 * Une passerelle BLE voit TOUT ce qui passe, et créer un équipement par balise
 * vue, c'est deux cents équipements en une semaine. Le tri ne se fait pas en ne
 * disant rien — un modèle non émis est un appareil dont personne ne saura
 * jamais qu'il est passé — mais par la confiance portée dans le modèle :
 *
 *   `certain`  la passerelle a DÉCODÉ la balise : elle lui a donné un `model`
 *              ou des champs de mesure nommés (`tempc`, `hum`, `batt`…). C'est
 *              un capteur, il a été reconnu, l'équipement est créé.
 *   `guess`    trame brute : `manufacturerdata` et `rssi`, rien d'autre. Le
 *              modèle part quand même, et Jeedom le range dans la file
 *              d'adoption plutôt que de le créer. L'utilisateur tranche.
 *
 * `probable` n'est PAS employé ici : il dit « je sais ce que c'est mais je ne
 * le connais pas encore complètement », et ces modèles-là sont créés.
 *
 * Le modèle d'une balise brute porte donc ce qui permet de trancher d'un coup
 * d'œil : le nom BLE annoncé s'il y en a un, le nombre de passerelles qui la
 * voient, et le type d'adresse — publique, donc stable et adoptable, ou
 * aléatoire, qui peut tourner toutes les quinze minutes et ne désignera plus
 * rien demain.
 *
 * LES DEUX ENDROITS OÙ CE GENRE D'ADAPTER SE CASSE
 *
 *   LE DÉBIT. Une seule balise vue par trois passerelles, c'est 280 messages en
 *   165 secondes — et un parc réel en produit bien davantage. Le RSSI change à
 *   CHAQUE trame : s'il entrait dans ce qui décrit l'appareil, un modèle
 *   repartirait vers Jeedom à chaque message et la base serait réécrite en
 *   boucle. Le RSSI est donc une VALEUR, qui circule par le routage normal
 *   (un canal, un topic, un chemin JSON), et jamais une raison de réémettre.
 *   Rien n'est émis depuis onMessage() : la réception se contente de tenir à
 *   jour un dossier et une signature ; c'est le battement d'horloge qui
 *   compare les signatures et n'émet que ce qui a bougé pour de bon.
 *
 *   L'INVENTAIRE. Une passerelle BLE voit TOUT ce qui passe : téléphones des
 *   visiteurs, montres, écouteurs, balises des voisins. L'inventaire des
 *   balises vues est donc plafonné (MAX_BALISES), périmé (PEREMPTION) et,
 *   au plafond, il évince la candidate la plus ancienne plutôt que de grandir.
 *   Seules les balises jamais décodées se périment et s'évincent : le silence
 *   d'un capteur reconnu est justement ce que son équipement rapporte.
 *   C'est le seul endroit de ce plugin où un démon peut enfler sans limite.
 *
 * Aucune référence à Jeedom dans ce fichier : il est chargé par le démon, qui
 * tourne sans core.inc.php. Les décisions de présentation (type de commande,
 * type générique, unité) ne sont pas prises ici non plus : un canal désigne une
 * capacité, et core/config/capabilities.json traduit.
 * ========================================================================== */
class MqttbeOpenMqttGateway implements MqttbeAdapter {

    const ID = 'omg';

    /* 100 = découverte native du protocole de l'appareil. La passerelle publie
     * aussi son parc en découverte Home Assistant ; un adapter générique qui
     * lirait celle-ci décrirait le même matériel de plus loin. */
    const PRIORITY = 100;

    /* ------------------------------------------------------------------ */
    /* Topics                                                             */
    /* ------------------------------------------------------------------ */

    const SYS   = 'SYStoMQTT';
    const RLS   = 'RLStoMQTT';
    const BT    = 'BTtoMQTT';
    const LWT   = 'LWT';
    const CMDSYS = 'commands/MQTTtoSYS/config';
    const CMDBT  = 'commands/MQTTtoBT/config';

    /*
     * Le préfixe de la passerelle est libre — « bt/OMG_ESP32_BLE_SALON »,
     * « OpenMQTTGateway », « maison/etage/omg » — et rien ne permet de le
     * deviner. On s'abonne donc par la FORME du topic et non par sa racine :
     * ce qui identifie OpenMQTTGateway, c'est le niveau `SYStoMQTT` ou
     * `BTtoMQTT`, à une profondeur de préfixe de un, deux ou trois niveaux.
     *
     * Trois profondeurs, et pas `#` : s'abonner à `#` « pour trier ensuite »
     * ferait traverser au démon la totalité du trafic du broker — avec une
     * passerelle Zigbee, des dizaines de milliers de messages par heure dont
     * pas un ne concerne la découverte.
     *
     * Ni le LWT, ni le RLStoMQTT, ni la configuration BT ne sont écoutés : ils
     * n'apprennent rien sur l'EXISTENCE d'une passerelle, seulement sur son
     * état. Ce sont des canaux du modèle, et c'est la table de routage qui les
     * abonnera une fois l'équipement créé — les écouter ici les ferait
     * traverser deux fois le démon pour le même résultat.
     */
    const PROFONDEUR_MAX = 3;

    /* ------------------------------------------------------------------ */
    /* Garde-fous                                                         */
    /* ------------------------------------------------------------------ */

    /* L'inventaire des balises. Le plafond est bas devant MEMORY_MAX (512 clés
     * par adapter) : il reste de la place pour les passerelles, les index et
     * les réglages, et le moteur n'a jamais à refuser une clé. */
    const MAX_BALISES     = 250;
    const MAX_PASSERELLES = 32;

    /* Une balise que la passerelle n'a jamais décodée et qu'on n'a plus vue
     * depuis trois heures n'encombre plus la file : c'est le téléphone d'un
     * visiteur, vu une fois, jamais revu. Une balise DÉCODÉE, elle, ne périme
     * pas — son silence est justement l'information que porte son équipement. */
    const PEREMPTION      = 10800;
    const PERIODE_PURGE   = 60;

    /* Une trame décodée porte une dizaine de champs ; deux douzaines couvrent
     * très largement le plus bavard des capteurs Theengs. Au-delà, c'est une
     * charge utile qui n'a pas de sens, et vingt-quatre commandes valent déjà
     * mieux que deux cents sur un équipement. */
    const MAX_CHAMPS = 24;

    /* Une balise vue par plus de huit passerelles dans la même maison n'existe
     * pas ; la borne est là pour qu'un préfixe changeant ne fabrique pas un
     * canal de plus à chaque message. */
    const MAX_PASSERELLES_PAR_BALISE = 8;

    /* ------------------------------------------------------------------ */
    /* Absence et proximité                                               */
    /* ------------------------------------------------------------------ */

    /*
     * Délai au bout duquel une balise silencieuse est déclarée absente, et
     * adoption automatique des balises non décodées : deux réglages de Jeedom
     * (`discovery::bleAwayDelay`, `discovery::bleAdoptAll`) portés par l'ordre
     * `discovery`. Voir reglages() pour la façon dont ils arrivent jusqu'ici.
     *
     * Le défaut est généreux à dessein : un traceur Tile n'émet pas en continu,
     * et le déclarer parti trop tôt est pire qu'un retard — on se met à
     * chercher un objet qui est là.
     */
    const AWAY_DEFAUT = 300;
    const AWAY_MIN    = 30;
    const AWAY_MAX    = 86400;

    /*
     * Marge, en dB, qu'un prétendant doit prendre sur la passerelle en place
     * pour devenir « la plus proche ».
     *
     * Sans elle, une balise posée à égale distance de deux passerelles ferait
     * basculer le champ à chaque trame — soit, sur la capture réelle, plus
     * d'une bascule par seconde, chacune publiée puis historisée par Jeedom.
     * Six dB, c'est le bruit ordinaire d'un RSSI BLE immobile.
     */
    const MARGE_PROCHE = 6;

    /* La balise dont on publie l'état : au plus un message par changement, et
     * un rafraîchissement de la date de dernière vue par minute. Sans cette
     * seconde borne, « vue à » repartirait à chaque trame reçue — c'est-à-dire
     * plusieurs fois par seconde, pour une information qui se lit à la
     * minute. */
    const PERIODE_VUE = 60;

    /*
     * Là où le démon publie ce qu'il a CALCULÉ pour une balise : présence,
     * passerelle la plus proche, date de dernière vue.
     *
     * Ces trois valeurs n'existent sur aucun topic de la passerelle — elles ne
     * peuvent pas y exister, puisqu'elles se déduisent d'un SILENCE. Elles sont
     * donc posées sur le broker, en un seul message JSON retenu, et relues par
     * le routage comme n'importe quel état d'appareil : un aller-retour de plus,
     * mais aucune voie détournée entre l'adapter et Jeedom, et l'état survit au
     * redémarrage du démon.
     *
     * Un seul topic pour les trois : trois topics, ce serait trois publications
     * à chaque changement de présence, pour trois valeurs qui changent ensemble.
     */
    const RACINE_ETAT = 'mqttbe/omg/ble';

    /* ------------------------------------------------------------------ */
    /* Des noms de champs vers des capacités                              */
    /* ------------------------------------------------------------------ */

    /*
     * LA TABLE, ET RIEN D'AUTRE.
     *
     * champ => array(capacité, unité, nom, échelle|null, décimales|null)
     *
     * Elle ne nomme aucun appareil, aucun constructeur, aucun modèle : elle ne
     * connaît que les noms de champs que Theengs emploie, qui sont les mêmes
     * quel que soit le capteur derrière. Ajouter une ligne le jour où un champ
     * nouveau mérite mieux qu'une valeur générique est un changement d'une
     * ligne — et ne rien ajouter n'a jamais fait perdre une donnée, puisque
     * l'inconnu devient `generic.value`.
     */
    private static function table() {
        return array(
            'tempc'    => array('sensor.temperature', '°C',  'Température',      null, 1),
            /* Theengs publie en Fahrenheit quand la passerelle est réglée
             * ainsi. Le routage applique échelle puis décalage : F × 5/9
             * − 17,7778 donne bien des degrés Celsius, et l'utilisateur lit la
             * même unité que ses autres capteurs. */
            'tempf'    => array('sensor.temperature', '°C',  'Température',      0.5555556, 1),
            'hum'      => array('sensor.humidity',    '%',   'Humidité',         null, 1),
            'moi'      => array('sensor.humidity',    '%',   'Humidité du sol',  null, 0),
            'batt'     => array('battery.level',      '%',   'Pile',             null, 0),
            'volt'     => array('power.voltage',      'V',   'Tension',          null, 3),
            'pres'     => array('sensor.pressure',    'hPa', 'Pression',         null, 1),
            'lux'      => array('sensor.luminosity',  'lx',  'Luminosité',       null, 0),
            'co2'      => array('sensor.co2',         'ppm', 'CO2',              null, 0),
            'noise'    => array('sensor.noise',       'dB',  'Bruit',            null, 1),
            'uv'       => array('sensor.uv',          '',    'Indice UV',        null, 1),
            'presence' => array('presence.detected',  '',    'Présence signalée', null, null),
            'track'    => array('presence.detected',  '',    'Traceur',          null, null),
            'motion'   => array('presence.detected',  '',    'Mouvement',        null, null),
            'open'     => array('contact.open',       '',    'Ouverture',        null, null),
            'contact'  => array('contact.open',       '',    'Contact',          null, null),
        );
    }

    /* Le décalage du Fahrenheit, appliqué après l'échelle : (F − 32) × 5/9. */
    const OFFSET_FAHRENHEIT = -17.777778;

    /*
     * Les champs qui ne deviennent JAMAIS un canal, et pourquoi.
     *
     *   id, mac              c'est l'identité de l'équipement, pas une mesure ;
     *   name, model, model_id, brand, type
     *                        des métadonnées : elles nomment l'équipement,
     *                        elles ne se lisent pas sur un tableau de bord ;
     *   manufacturerdata, servicedata…
     *                        des blocs hexadécimaux qui changent à chaque
     *                        trame. Les décoder est le métier de la passerelle,
     *                        et les afficher tels quels remplirait l'historique
     *                        de Jeedom d'un bruit que personne ne lit ;
     *   rssi                 traité à part : un canal PAR PASSERELLE.
     */
    private static function champsTechniques() {
        return array(
            'id' => true, 'mac' => true,
            'name' => true, 'model' => true, 'model_id' => true,
            'brand' => true, 'type' => true,
            'manufacturerdata' => true, 'manufacturerdata2' => true,
            'servicedata' => true, 'servicedatauuid' => true, 'servicuuid' => true,
            'rssi' => true,
        );
    }

    /* Une unité pour quelques champs qui n'ont pas de capacité à eux : la
     * valeur reste générique, mais elle s'affiche avec ce qu'elle mesure. */
    private static function unitesGeneriques() {
        return array('distance' => 'm', 'txpower' => 'dBm', 'mfid' => '');
    }

    /* Les clés de canal que l'adapter se réserve pour ce qu'il calcule. Un
     * champ décodé qui porterait le même nom serait renommé plutôt que de faire
     * disparaître l'un des deux canaux — mais aucun champ Theengs ne contient
     * de point, si bien que le cas ne devrait jamais se produire. */
    const CLE_PRESENCE = 'state.presence';
    const CLE_PROCHE   = 'state.nearest';
    const CLE_VUE      = 'state.seen';

    /*
     * La forme d'un niveau de topic acceptable.
     *
     * Le préfixe de la passerelle vient du réseau, et il compose ensuite les
     * topics de l'équipement — EN PUBLICATION pour les actions. Un préfixe
     * `{"base":"+"}` donnerait `+/commands/MQTTtoSYS/config`, ce que MQTT
     * 3.1.1 §3.3.2 interdit dans un nom de topic de publication : le broker
     * fermerait la connexion à chaque appui sur le bouton « Redémarrer ».
     */
    const NIVEAU_VALIDE = '/^[A-Za-z0-9._:-]{1,64}$/';

    /* Un nom de champ décodé doit pouvoir servir de chemin JSON : le routage
     * éclate le chemin sur les points, et un champ « a.b » désignerait alors
     * un sous-objet qui n'existe pas. */
    const CHAMP_VALIDE = '/^[A-Za-z0-9_]{1,40}$/';

    /* --------------------------------------------------------------------- */
    /* Interface MqttbeAdapter                                               */
    /* --------------------------------------------------------------------- */

    public function id() {
        return self::ID;
    }

    public function priority() {
        return self::PRIORITY;
    }

    /*
     * Les topics à écouter, et eux seuls : la santé des passerelles et les
     * trames BLE, à une profondeur de préfixe de un à trois niveaux.
     *
     * Voir PROFONDEUR_MAX : ce qui identifie OpenMQTTGateway est la forme du
     * topic, pas sa racine, qui est libre.
     */
    public function subscriptions() {
        $filtres = array();
        $prefixe = '';
        for ($niveaux = 1; $niveaux <= self::PROFONDEUR_MAX; $niveaux++) {
            $prefixe .= '+/';
            $filtres[] = $prefixe . self::SYS;
            $filtres[] = $prefixe . self::BT . '/+';
        }
        return $filtres;
    }

    /*
     * Un message reçu. RIEN N'EST ÉMIS ICI.
     *
     * C'est la pièce maîtresse du débit : sur un parc réel, ce code s'exécute
     * plusieurs fois par seconde et pendant des jours. Il décode la charge
     * utile, range ce qu'il a appris dans un dossier, met à jour une signature,
     * et s'arrête là. La construction d'un modèle — qui alloue des dizaines
     * d'objets — n'a lieu qu'au battement d'horloge, et seulement si la
     * signature a changé.
     */
    public function onMessage($_topic, $_payload, $_retained, $_ctx) {
        $niveaux = explode('/', (string) $_topic);
        $nombre  = count($niveaux);
        if ($nombre < 2) {
            return;
        }

        if ($niveaux[$nombre - 1] === self::SYS) {
            $base = $this->base($niveaux, $nombre - 1);
            if ($base !== '') {
                $this->recoitSys($_ctx, $base, $_payload);
            }
            return;
        }
        if ($nombre >= 3 && $niveaux[$nombre - 2] === self::BT) {
            $base = $this->base($niveaux, $nombre - 2);
            if ($base !== '') {
                $this->recoitBalise($_ctx, $base, $niveaux[$nombre - 1], $_payload);
            }
        }
    }

    /*
     * Le battement : au plus une fois par seconde. Trois gestes, et aucun qui
     * coûte quoi que ce soit quand il n'y a rien à faire.
     *
     *   1. la relance demandée par l'utilisateur ;
     *   2. la purge de l'inventaire, une fois par minute ;
     *   3. pour chaque appareil connu, l'émission du modèle SI sa signature a
     *      changé — et, pour les balises, le calcul de l'absence.
     *
     * Ce calcul se fait ici et nulle part ailleurs : c'est une ABSENCE de
     * message qu'il mesure, et aucun message ne viendra donc le déclencher.
     */
    public function onTick($_ctx) {
        $maintenant = $this->maintenant($_ctx);
        $reglages   = $this->reglages($_ctx);

        /* Relance : le moteur la présente de deux façons, et les deux mènent au
         * même geste. Ici, aucune annonce à provoquer — une passerelle
         * OpenMQTTGateway republie son SYStoMQTT toute seule, périodiquement, et
         * il n'existe pas d'ordre « présente-toi » à lui envoyer. Ce qu'on peut
         * faire, et qui est exactement ce que demande l'utilisateur qui presse
         * le bouton, c'est oublier ce qui a déjà été émis pour que tout le parc
         * connu reparte vers Jeedom. */
        $relance = method_exists($_ctx, 'rescan') && $_ctx->rescan();
        if ($_ctx->recall(self::ID . ':rescan')) {
            $_ctx->forget(self::ID . ':rescan');
            $relance = true;
        }
        if ($relance) {
            $this->oublieEmissions($_ctx);
        }

        $this->purge($_ctx, $maintenant, $reglages);

        foreach ($this->passerelles($_ctx) as $slug) {
            $this->emetPasserelle($_ctx, $slug);
        }
        foreach ($this->balises($_ctx) as $mac) {
            $this->suitBalise($_ctx, $mac, $maintenant, $reglages);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Réglages portés par l'ordre « discovery »                             */
    /* --------------------------------------------------------------------- */

    /*
     * `bleAwayDelay` (défaut 300 s) et `bleAdoptAll` (défaut 0), tels que
     * Jeedom les envoie dans l'ordre `discovery` — le démon ne charge pas le
     * cœur et ne peut pas lire `discovery::bleAwayDelay` lui-même.
     *
     * Deux chemins, dans cet ordre, parce que le moteur ne tranche pas encore :
     *
     *   1. $ctx->setting('bleAwayDelay', défaut), si le contexte la propose ;
     *   2. la mémoire de l'adapter, sous « omg:settings » — la convention que
     *      le moteur emploie déjà pour la relance (« <adapter>:rescan »), et qui
     *      ne nomme aucun constructeur.
     *
     * Et à défaut : les valeurs du fichier ini, recopiées ici. Un Jeedom plus
     * ancien, ou un moteur qui ne transmet pas encore l'ordre, doit se
     * comporter comme un utilisateur qui n'a rien touché — et non comme un
     * utilisateur qui aurait tout décoché.
     */
    public function reglages($_ctx) {
        $away   = self::AWAY_DEFAUT;
        $adopte = false;
        $source = 'défauts';

        $memoire = $_ctx->recall(self::ID . ':settings');
        if (is_array($memoire)) {
            $source = 'ordre discovery (mémoire)';
            if (isset($memoire['bleAwayDelay']) && is_numeric($memoire['bleAwayDelay'])) {
                $away = (int) $memoire['bleAwayDelay'];
            }
            if (array_key_exists('bleAdoptAll', $memoire)) {
                $adopte = self::vraiFaux($memoire['bleAdoptAll']);
            }
        }
        if (method_exists($_ctx, 'setting')) {
            $source = 'ordre discovery (contexte)';
            $lu = $_ctx->setting('bleAwayDelay', $away);
            if (is_numeric($lu)) {
                $away = (int) $lu;
            }
            $adopte = self::vraiFaux($_ctx->setting('bleAdoptAll', $adopte));
        }

        /* Bornes : un délai de zéro déclarerait toute balise absente à l'instant
         * même où elle est vue, et l'équipement clignoterait indéfiniment. */
        $away = max(self::AWAY_MIN, min(self::AWAY_MAX, $away));
        return array('away' => $away, 'adoptAll' => $adopte, 'source' => $source);
    }

    /* --------------------------------------------------------------------- */
    /* Réception : une passerelle                                            */
    /* --------------------------------------------------------------------- */

    private function recoitSys($_ctx, $_base, $_payload) {
        $sys = $this->json($_payload);
        if ($sys === null) {
            return;
        }
        $mac = self::normaliseMac($this->texte($sys, 'mac'));
        if ($mac === '') {
            /* L'identité se dérive de la `mac`, et de rien d'autre : c'est
             * exactement ce qui fait qu'un préfixe de topic dupliqué ne crée pas
             * de second équipement. Sans elle, on ne peut rien adopter — mieux
             * vaut une passerelle absente de la liste qu'un fantôme qui
             * ressuscitera sous un autre nom à chaque redémarrage. */
            return;
        }

        $slug    = self::slug($_base);
        $dossier = $this->dossier($_ctx, 'gw:' . $slug);
        if (empty($dossier)) {
            if (!$this->inscrit($_ctx, 'index.gw', $slug, self::MAX_PASSERELLES)) {
                $this->plainte($_ctx, 'gw', 'plafond de ' . self::MAX_PASSERELLES
                    . ' passerelles atteint : « ' . $this->citation($_base) . ' » n\'est pas suivie.');
                return;
            }
            $dossier = array('base' => $_base);
        }
        $dossier['base']    = $_base;
        $dossier['mac']     = $mac;
        $dossier['env']     = $this->texte($sys, 'env');
        $dossier['version'] = $this->texte($sys, 'version');
        $dossier['ip']      = $this->texte($sys, 'ip');
        $dossier['nom']     = self::dernierNiveau($_base);
        $dossier['signature'] = implode('|', array($mac, $_base, $dossier['env'],
                                                   $dossier['version'], $dossier['ip']));
        $this->range($_ctx, 'gw:' . $slug, $dossier);
    }

    /* --------------------------------------------------------------------- */
    /* Réception : une balise                                                */
    /* --------------------------------------------------------------------- */

    /*
     * Le chemin chaud du plugin. Tout ce qui pouvait être fait ailleurs l'est
     * ailleurs : ni construction de modèle, ni parcours d'inventaire, ni
     * publication. Un json_decode, quelques écritures dans un tableau, une
     * signature recomposée — et c'est tout.
     */
    private function recoitBalise($_ctx, $_base, $_adresse, $_payload) {
        if (!preg_match(self::NIVEAU_VALIDE, (string) $_adresse)) {
            return;
        }
        $trame = $this->json($_payload);
        if ($trame === null) {
            /* Une charge utile qui n'est pas du JSON sur une branche BTtoMQTT
             * existe : un autre système publie ses états de présence au même
             * endroit, et le broker les rejoue, retenus. Ce n'est pas un
             * incident, c'est du trafic voisin — il se jette sans un mot. */
            return;
        }
        /* Une trame BLE porte toujours au moins l'adresse ou le signal. Sans ce
         * contrôle, un `[]` ou un `{}` — et il en passe, sur une branche que
         * d'autres logiciels écrivent aussi — ouvrirait un dossier de balise
         * sur la seule foi du niveau de topic, et l'inventaire se remplirait de
         * ce que d'autres publient. */
        if (!array_key_exists('id', $trame) && !array_key_exists('rssi', $trame)) {
            return;
        }

        /* L'identité vient du champ `id` quand il est là, du niveau de topic
         * sinon : les deux formes existent, avec et sans séparateurs, et deux
         * orthographes ne doivent pas donner deux équipements. */
        $adresse = $this->texte($trame, 'id');
        if ($adresse === '') {
            $adresse = (string) $_adresse;
        }
        $mac = self::normaliseMac($adresse);
        if ($mac === '') {
            return;
        }

        $maintenant = $this->maintenant($_ctx);
        $cle        = 'ble:' . $mac;
        $dossier    = $this->dossier($_ctx, $cle);
        if (empty($dossier)) {
            $dossier = $this->ouvreBalise($_ctx, $mac, $adresse, $maintenant);
            if ($dossier === null) {
                return;
            }
        }

        /* Le nom, le modèle et la marque sont COLLANTS : la même balise est vue
         * par trois passerelles, et deux d'entre elles ne reçoivent pas la
         * trame qui porte le nom — les trames BLE alternent. Effacer le nom
         * parce qu'il manque dans la dernière trame ferait osciller le modèle
         * entre deux formes, donc réémettre en boucle. */
        $this->colle($dossier, $trame, 'name',     'nom');
        $this->colle($dossier, $trame, 'model',    'modele');
        $this->colle($dossier, $trame, 'model_id', 'modeleId');
        $this->colle($dossier, $trame, 'brand',    'marque');

        /* Les champs vus, en union et dans l'ordre d'apparition : une valeur
         * que la passerelle ne décode qu'une fois sur deux (parce que le
         * capteur alterne ses trames) ne doit pas faire disparaître sa
         * commande. */
        $techniques = self::champsTechniques();
        foreach ($trame as $champ => $valeur) {
            $champ = (string) $champ;
            if (isset($techniques[$champ]) || is_array($valeur) || $valeur === null) {
                continue;
            }
            if (isset($dossier['champs'][$champ])) {
                continue;
            }
            if (!preg_match(self::CHAMP_VALIDE, $champ)) {
                continue;
            }
            if (count($dossier['champs']) >= self::MAX_CHAMPS) {
                continue;
            }
            $dossier['champs'][$champ] = true;
        }
        if (isset($dossier['champs']['batt'])) {
            $dossier['pile'] = true;
        }

        /*
         * Le type d'adresse, qui décide si la balise vaut la peine d'être
         * adoptée : 0 = publique, gravée dans le matériel, la même dans six
         * mois ; 1 = aléatoire, qui peut être statique… ou tourner toutes les
         * quinze minutes, auquel cas l'équipement créé aujourd'hui ne désignera
         * plus rien demain. C'est l'information que l'utilisateur doit lire
         * d'un coup d'œil dans la file d'adoption.
         */
        if (!isset($dossier['macType']) && isset($trame['mac_type'])
            && is_numeric($trame['mac_type'])) {
            $dossier['macType'] = ((int) $trame['mac_type'] === 0) ? 'public' : 'random';
        }

        /* Décodée ou brute : la question se tranche sur la trame, pas sur un
         * réglage — `bleAdoptAll` change ce qu'on FAIT d'une balise brute, pas
         * le fait qu'elle le soit. Une fois décodée, toujours décodée : le
         * capteur alterne ses trames, et la moitié d'entre elles ne portent que
         * l'adresse et le signal. */
        if (empty($dossier['decodee']) && $this->trameDecodee($dossier)) {
            $dossier['decodee'] = true;
        }

        /* La passerelle qui vient de la voir : son topic exact — c'est la
         * source du canal de RSSI — et la dernière valeur reçue, qui ne sert
         * qu'à désigner la plus proche. Le RSSI n'entre NULLE PART dans ce qui
         * décrit l'appareil : il vaut −71 puis −69 puis −93, et un modèle qui
         * en dépendrait repartirait vers Jeedom à chaque trame. */
        $slug  = self::slug($_base);
        $topic = $_base . '/' . self::BT . '/' . $_adresse;
        if (!isset($dossier['gws'][$slug])) {
            if (count($dossier['gws']) >= self::MAX_PASSERELLES_PAR_BALISE) {
                return;
            }
            $dossier['gws'][$slug] = array();
        }
        $dossier['gws'][$slug]['topic'] = $topic;
        $dossier['gws'][$slug]['nom']   = self::dernierNiveau($_base);
        $dossier['gws'][$slug]['vu']    = $maintenant;
        $dossier['gws'][$slug]['rssi']  = $this->nombre($trame, 'rssi', null);
        $dossier['vu'] = $maintenant;

        $dossier['signature'] = $this->signatureBalise($dossier);
        $this->range($_ctx, $cle, $dossier);
    }

    /*
     * Ouvre un dossier de balise, ou rend null si l'inventaire est plein.
     *
     * C'est LE point où le démon pourrait enfler indéfiniment : une passerelle
     * BLE voit les téléphones des passants, et il en passe. Trois gestes, dans
     * cet ordre : périmer ce qui n'a jamais été adopté, évincer la plus
     * ancienne candidate si le plafond est encore atteint, refuser enfin.
     */
    private function ouvreBalise($_ctx, $_mac, $_adresse, $_maintenant) {
        if (!$this->inscrit($_ctx, 'index.ble', $_mac, self::MAX_BALISES)) {
            $this->purgeCandidates($_ctx, $_maintenant, self::PEREMPTION);
            if (!$this->inscrit($_ctx, 'index.ble', $_mac, self::MAX_BALISES)) {
                $this->evince($_ctx);
                if (!$this->inscrit($_ctx, 'index.ble', $_mac, self::MAX_BALISES)) {
                    $this->plainte($_ctx, 'ble', 'inventaire plein (' . self::MAX_BALISES
                        . ' balises) : les balises suivantes ne sont plus suivies. Une passerelle '
                        . 'Bluetooth voit tout ce qui passe — adoptez ce qui vous intéresse, le '
                        . 'reste se périme tout seul.');
                    return null;
                }
            }
        }
        return array(
            'mac'     => $_mac,
            'adresse' => $_adresse,
            'nom'     => '', 'modele' => '', 'modeleId' => '', 'marque' => '',
            'champs'  => array(),
            'gws'     => array(),
            'vu'      => $_maintenant,
            'depuis'  => $_maintenant,
            'pile'    => false,
            'decodee' => false,
        );
    }

    /*
     * La passerelle a-t-elle RECONNU cette balise ?
     *
     * Un modèle, ou au moins un champ de mesure de la table. C'est la seule
     * question que l'adapter pose sur le contenu d'une trame, et elle ne
     * consulte aucun catalogue : elle regarde des NOMS DE CHAMPS. Le jour où la
     * passerelle est mise à jour et se met à décoder un capteur de plus,
     * celui-ci devient `certain` sans qu'on touche au plugin.
     */
    private function trameDecodee($_dossier) {
        if ($this->texte($_dossier, 'modele') !== '' || $this->texte($_dossier, 'modeleId') !== '') {
            return true;
        }
        $table = self::table();
        foreach (array_keys($_dossier['champs']) as $champ) {
            if (isset($table[$champ])) {
                return true;
            }
        }
        return false;
    }

    /* --------------------------------------------------------------------- */
    /* Le battement : absence, proximité, émission                           */
    /* --------------------------------------------------------------------- */

    /*
     * Une balise, à un instant donné : est-elle là, quelle passerelle est la
     * plus proche, et quand l'a-t-on vue pour la dernière fois.
     *
     * Rien de ce qui est calculé ici ne passe par le modèle : ce sont des
     * VALEURS, qui se publient sur le broker et reviennent par le routage
     * ordinaire. Le modèle, lui, ne bouge que si l'appareil a changé.
     */
    private function suitBalise($_ctx, $_mac, $_maintenant, $_reglages) {
        $cle     = 'ble:' . $_mac;
        $dossier = $this->dossier($_ctx, $cle);
        if (empty($dossier)) {
            return;
        }

        $modifie   = false;
        $confiance = $this->confiance($dossier, $_reglages);
        if ($this->texte($dossier, 'confiance') !== $confiance) {
            $dossier['confiance'] = $confiance;
            $dossier['signature'] = $this->signatureBalise($dossier);
            $modifie = true;
        }

        /* L'absence, qui est l'information principale d'un traceur : une balise
         * ne dit rien quand elle part, elle cesse simplement d'émettre. */
        $presente = (($_maintenant - $this->nombre($dossier, 'vu', 0)) < $_reglages['away']);

        /* La plus proche, avec hystérésis, et seulement parmi les passerelles
         * qui l'ont vue récemment : une passerelle débranchée garderait sinon
         * pour toujours le dernier RSSI qu'elle a rapporté, et l'équipement
         * affirmerait qu'un objet est dans une pièce où plus rien n'écoute. */
        $proche = '';
        if ($presente) {
            $proche = $this->plusProche($dossier, $_maintenant, $_reglages['away']);
        }

        $etat = array(
            'presence' => $presente ? 1 : 0,
            'nearest'  => $proche,
            /* Vide plutôt que faux : une balise absente n'est nulle part, et
             * un champ qui garderait le nom d'une pièce mentirait à l'endroit
             * exact où l'utilisateur vient chercher son objet. */
            'seen'     => date('Y-m-d H:i:s', (int) $this->nombre($dossier, 'vu', 0)),
        );
        $texte = json_encode($etat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $avant = isset($dossier['etat']) ? (string) $dossier['etat'] : '';
        $quand = $this->nombre($dossier, 'etatQuand', 0);

        /* Un changement de présence ou de pièce part tout de suite ; un simple
         * rafraîchissement de la date attend la minute. Sans cette seconde
         * borne, « vue à » repartirait à chaque trame reçue, soit plusieurs
         * fois par seconde pour une information qui se lit à la minute. */
        $urgent = ($avant === '') || $this->changeEtat($avant, $etat);
        if ($texte !== $avant && ($urgent || ($_maintenant - $quand) >= self::PERIODE_VUE)) {
            if ($_ctx->publish($this->topicEtat($_mac), $texte, 0, true) === true) {
                $dossier['etat']      = $texte;
                $dossier['etatQuand'] = $_maintenant;
                $dossier['proche']    = $proche;
                $modifie = true;
            }
        }

        if ($modifie) {
            $this->range($_ctx, $cle, $dossier);
        }
        $this->emetBalise($_ctx, $_mac);
    }

    /* Présence ou pièce : les deux champs qui ne peuvent pas attendre. */
    private function changeEtat($_avant, $_etat) {
        $ancien = json_decode($_avant, true);
        if (!is_array($ancien)) {
            return true;
        }
        $presence = isset($ancien['presence']) ? (int) $ancien['presence'] : -1;
        $proche   = isset($ancien['nearest']) ? (string) $ancien['nearest'] : '';
        return ($presence !== (int) $_etat['presence']) || ($proche !== (string) $_etat['nearest']);
    }

    /*
     * La passerelle la plus proche, avec hystérésis.
     *
     * Le plus fort RSSI l'emporte, mais un prétendant doit prendre MARGE_PROCHE
     * dB sur celle qui est en place. Deux passerelles à égale distance feraient
     * sinon basculer le champ à chaque trame — plus d'une fois par seconde sur
     * la capture réelle.
     */
    private function plusProche($_dossier, $_maintenant, $_away) {
        $sortant = isset($_dossier['proche']) ? (string) $_dossier['proche'] : '';
        $meilleur = null;
        $nom      = '';
        $tenant   = null;

        /* Tri par slug : à RSSI rigoureusement égal, le résultat ne doit pas
         * dépendre de l'ordre dans lequel les messages sont arrivés. */
        $slugs = array_keys($_dossier['gws']);
        sort($slugs, SORT_STRING);
        foreach ($slugs as $slug) {
            $vue = $_dossier['gws'][$slug];
            if (($_maintenant - $this->nombre($vue, 'vu', 0)) >= $_away) {
                continue;
            }
            if (!isset($vue['rssi']) || !is_numeric($vue['rssi'])) {
                continue;
            }
            $rssi = (float) $vue['rssi'];
            $ceNom = isset($vue['nom']) ? (string) $vue['nom'] : $slug;
            if ($ceNom === $sortant) {
                $tenant = $rssi;
            }
            if ($meilleur === null || $rssi > $meilleur) {
                $meilleur = $rssi;
                $nom      = $ceNom;
            }
        }
        if ($meilleur === null) {
            return '';
        }
        if ($tenant !== null && $nom !== $sortant && ($meilleur - $tenant) < self::MARGE_PROCHE) {
            return $sortant;
        }
        return $nom;
    }

    /*
     * La confiance d'une balise, qui décide de ce que Jeedom en fait.
     *
     *   `certain` la passerelle l'a RECONNUE — un modèle, ou au moins un champ
     *             de mesure. C'est un capteur, l'équipement est créé.
     *   `guess`   trame brute : c'est peut-être le téléphone d'un visiteur ou
     *             la balise d'un voisin. Le modèle part quand même — sinon
     *             personne ne saurait jamais qu'il y a là quelque chose à
     *             adopter — mais il atterrit dans la file d'adoption.
     *
     * `bleAdoptAll` fait passer toutes les balises en `certain` : c'est le sens
     * du réglage, pour qui veut vraiment tout.
     */
    public function confiance($_dossier, $_reglages) {
        if (!empty($_reglages['adoptAll']) || !empty($_dossier['decodee'])) {
            return 'certain';
        }
        return 'guess';
    }

    /*
     * La file d'adoption, vue du démon : ce que la passerelle voit sans le
     * reconnaître.
     *
     * Jeedom la reconstitue à partir des modèles `guess` qu'il reçoit ; cette
     * méthode dit la même chose à qui interroge le démon directement — un
     * contrôle hors ligne, une ligne de diagnostic.
     */
    public function fileAdoption($_ctx) {
        $maintenant = $this->maintenant($_ctx);
        $reglages   = $this->reglages($_ctx);
        $file = array();
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (empty($dossier) || $this->confiance($dossier, $reglages) !== 'guess') {
                continue;
            }
            $file[] = array(
                'uid'         => 'ble:' . $mac,
                'adresse'     => $this->texte($dossier, 'adresse'),
                'nom'         => $this->texte($dossier, 'nom'),
                'adressage'   => $this->texte($dossier, 'macType'),
                'passerelles' => count($dossier['gws']),
                'depuis'      => (int) ($maintenant - $this->nombre($dossier, 'depuis', $maintenant)),
                'vu'          => (int) ($maintenant - $this->nombre($dossier, 'vu', $maintenant)),
            );
        }
        return $file;
    }

    /* --------------------------------------------------------------------- */
    /* Émission des modèles                                                  */
    /* --------------------------------------------------------------------- */

    /*
     * Émettre SI ET SEULEMENT SI la signature a changé.
     *
     * Le moteur dédoublonne déjà par empreinte, mais il le fait APRÈS avoir
     * reçu un modèle construit : sur une balise vue par trois passerelles, ce
     * serait plusieurs constructions de modèle par seconde, jetées aussitôt.
     * La signature, elle, est une chaîne qu'on compare.
     */
    private function emetPasserelle($_ctx, $_slug) {
        $dossier = $this->dossier($_ctx, 'gw:' . $_slug);
        if (empty($dossier) || $this->texte($dossier, 'mac') === '') {
            return false;
        }
        if ($this->texte($dossier, 'signature') === $this->texte($dossier, 'emis')) {
            return false;
        }
        /* Deux préfixes pour une seule passerelle : le parc d'essai en comporte
         * un (« …_ETAGEOMG_ESP32_BLE_ETAGE », doublé par une mauvaise saisie),
         * et l'identité étant la `mac`, les deux décriraient le MÊME
         * équipement avec des topics différents — donc un modèle qui oscille et
         * repart vers Jeedom sans fin. Le préfixe le plus court l'emporte, à
         * égalité le premier dans l'ordre alphabétique : le résultat ne dépend
         * ni de l'ordre des messages, ni du moment où le démon a démarré. */
        if (!$this->prefixeRetenu($_ctx, $dossier)) {
            $dossier['emis'] = $this->texte($dossier, 'signature');
            $this->range($_ctx, 'gw:' . $_slug, $dossier);
            return false;
        }

        $modele = $this->construitPasserelle($dossier);
        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $_ctx->log('warning', 'OpenMQTTGateway : modèle de passerelle refusé pour « '
                . $modele->uid() . ' » — ' . implode(' ', $fautes));
            return false;
        }
        $_ctx->emit($modele);
        $dossier['emis'] = $this->texte($dossier, 'signature');
        $this->range($_ctx, 'gw:' . $_slug, $dossier);
        $_ctx->log('info', 'OpenMQTTGateway : passerelle ' . $modele->name() . ' ('
            . $modele->uid() . ') — ' . $modele->countChannels() . ' canaux.');
        return true;
    }

    private function emetBalise($_ctx, $_mac) {
        $cle     = 'ble:' . $_mac;
        $dossier = $this->dossier($_ctx, $cle);
        if (empty($dossier)) {
            return false;
        }
        if ($this->texte($dossier, 'signature') === $this->texte($dossier, 'emis')) {
            return false;
        }
        $modele = $this->construitBalise($dossier);
        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $_ctx->log('warning', 'OpenMQTTGateway : modèle de balise refusé pour « '
                . $modele->uid() . ' » — ' . implode(' ', $fautes));
            return false;
        }
        $_ctx->emit($modele);
        $dossier['emis'] = $this->texte($dossier, 'signature');
        $this->range($_ctx, $cle, $dossier);
        /* Une balise reconnue est un capteur trouvé : cela mérite une ligne.
         * Une balise brute, non — il en passe des dizaines par jour devant une
         * maison, et le moteur journalise déjà chaque modèle qu'il reçoit. */
        $_ctx->log($modele->confidence() === 'certain' ? 'info' : 'debug',
            'OpenMQTTGateway : balise ' . $modele->name() . ' (' . $modele->uid() . ') — '
            . $modele->countChannels() . ' canaux, ' . count($dossier['gws']) . ' passerelle(s), '
            . 'confiance ' . $modele->confidence()
            . ($modele->confidence() === 'guess' ? ' (file d\'adoption)' : '') . '.');
        return true;
    }

    private function oublieEmissions($_ctx) {
        foreach ($this->passerelles($_ctx) as $slug) {
            $dossier = $this->dossier($_ctx, 'gw:' . $slug);
            if (isset($dossier['emis'])) {
                unset($dossier['emis']);
                $this->range($_ctx, 'gw:' . $slug, $dossier);
            }
        }
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (isset($dossier['emis'])) {
                unset($dossier['emis']);
                $this->range($_ctx, 'ble:' . $mac, $dossier);
            }
        }
    }

    /* Le préfixe retenu pour une `mac` donnée : le plus court, puis le premier
     * dans l'ordre alphabétique. Voir emetPasserelle(). */
    private function prefixeRetenu($_ctx, $_dossier) {
        $mac  = $this->texte($_dossier, 'mac');
        $base = $this->texte($_dossier, 'base');
        foreach ($this->passerelles($_ctx) as $slug) {
            $autre = $this->dossier($_ctx, 'gw:' . $slug);
            if (empty($autre) || $this->texte($autre, 'mac') !== $mac) {
                continue;
            }
            $sonBase = $this->texte($autre, 'base');
            if ($sonBase === $base) {
                continue;
            }
            $court = strlen($sonBase) < strlen($base)
                  || (strlen($sonBase) === strlen($base) && strcmp($sonBase, $base) < 0);
            if ($court) {
                return false;
            }
        }
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Construction : la passerelle                                          */
    /* --------------------------------------------------------------------- */

    /**
     * @return MqttbeDeviceModel
     */
    public function construitPasserelle($_dossier) {
        $base = $this->texte($_dossier, 'base');
        $mac  = $this->texte($_dossier, 'mac');
        $ip   = $this->texte($_dossier, 'ip');
        $sys  = $base . '/' . self::SYS;
        $bt   = $base . '/' . self::BT;

        $modele = new MqttbeDeviceModel(array(
            'identity' => array(
                'adapter' => self::ID,
                /* La MAC, et non le préfixe : le parc d'essai comporte une
                 * passerelle dont le préfixe est dupliqué, qui créerait sinon un
                 * équipement fantôme à côté du vrai. */
                'uid'     => 'omg:' . $mac,
                'aliases' => array('mac:' . $mac, 'topic:' . $base),
                'confidence' => 'certain',
            ),
            'meta' => array(
                'name'         => 'OpenMQTTGateway ' . strtoupper(substr($mac, -6)),
                /* Le nom que l'utilisateur a donné à sa passerelle, c'est
                 * celui qu'il a saisi dans son interface et qui compose le
                 * préfixe de topic : « OMG_ESP32_BLE_SALON ». */
                'device_name'  => $this->texte($_dossier, 'nom'),
                'manufacturer' => 'OpenMQTTGateway',
                'model'        => $this->texte($_dossier, 'env'),
                'firmware'     => $this->texte($_dossier, 'version'),
                'ip'           => $ip,
                'config_url'   => ($ip === '') ? '' : 'http://' . $ip . '/',
                'battery_powered' => false,
            ),
            /* Le LWT est retenu et publié par testament : c'est lui, et non
             * l'absence de messages, qui dit qu'une passerelle a disparu. */
            'availability' => array(
                'topic'       => $base . '/' . self::LWT,
                'payload_on'  => 'online',
                'payload_off' => 'offline',
            ),
        ));

        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'online', 'capability' => 'connectivity.online', 'name' => 'Connectée',
            'source' => array('topic' => $base . '/' . self::LWT),
            'value'  => array('transform' => array('map' => array('online' => '1', 'offline' => '0'))),
        )));

        /* La santé, toute entière dans le SYStoMQTT : un seul topic, un chemin
         * JSON par commande. Le routage ne décode la charge utile qu'une fois
         * pour les six. */
        $sante = array(
            array('temperature', 'sensor.temperature', 'Température interne', '°C',  'tempc',   1),
            array('memory',      'device.memory',      'Mémoire libre',       'o',   'freemem', null),
            array('rssi',        'connectivity.rssi',  'Signal Wi-Fi',        'dBm', 'rssi',    null),
            array('uptime',      'device.uptime',      'Durée de fonctionnement', 's', 'uptime', null),
            array('ip',          'generic.value',      'Adresse IP',          '',    'ip',      null),
            array('version',     'generic.value',      'Version',             '',    'version', null),
        );
        foreach ($sante as $canal) {
            $valeur = array();
            if ($canal[5] !== null) {
                $valeur['transform'] = array('round' => $canal[5]);
            }
            $modele->addChannel(new MqttbeChannel(array(
                'key' => $canal[0], 'capability' => $canal[1], 'name' => $canal[2], 'unit' => $canal[3],
                'source' => array('topic' => $sys, 'selector' => array('type' => 'json', 'path' => $canal[4])),
                'value'  => $valeur,
            )));
        }

        /* La version disponible en amont : le parc d'essai tourne en v1.7.0
         * alors que la v1.8.1 existe, et personne ne le sait. */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'latest', 'capability' => 'generic.value', 'name' => 'Dernière version disponible',
            'source' => array('topic' => $base . '/' . self::RLS,
                              'selector' => array('type' => 'json', 'path' => 'latest_version')),
        )));

        /* L'état du module Bluetooth et ses deux réglages, publiés par la
         * passerelle sur sa propre branche BTtoMQTT. Les millisecondes y sont
         * ramenées en secondes : « 55555 » ne veut rien dire à l'écran. */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.state', 'capability' => 'switch.state', 'name' => 'Bluetooth',
            'source' => array('topic' => $bt, 'selector' => array('type' => 'json', 'path' => 'enabled')),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.interval', 'capability' => 'generic.value', 'name' => 'Intervalle entre scans',
            'unit' => 's',
            'source' => array('topic' => $bt, 'selector' => array('type' => 'json', 'path' => 'interval')),
            'value'  => array('transform' => array('scale' => 0.001, 'round' => 0)),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.duration', 'capability' => 'generic.value', 'name' => 'Durée de scan',
            'unit' => 's',
            'source' => array('topic' => $bt, 'selector' => array('type' => 'json', 'path' => 'scanduration')),
            'value'  => array('transform' => array('scale' => 0.001, 'round' => 0)),
        )));

        /* Les actions, telles que la passerelle les attend sur ses deux topics
         * de commande. `save:true` écrit le réglage en mémoire persistante :
         * sans lui, couper le Bluetooth ne survivrait pas à une coupure de
         * courant, et le réglage reviendrait tout seul. */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'restart', 'capability' => 'device.restart', 'name' => 'Redémarrer',
            'sink' => array('topic' => $base . '/' . self::CMDSYS, 'payload' => '{"cmd":"restart"}'),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.on', 'capability' => 'switch.on', 'name' => 'Activer le Bluetooth',
            'sink' => array('topic' => $base . '/' . self::CMDBT, 'payload' => '{"enabled":true,"save":true}'),
            'links' => array('state' => 'ble.state'),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.off', 'capability' => 'switch.off', 'name' => 'Couper le Bluetooth',
            'sink' => array('topic' => $base . '/' . self::CMDBT, 'payload' => '{"enabled":false,"save":true}'),
            'links' => array('state' => 'ble.state'),
        )));
        /* Un intervalle de zéro déclenche un scan immédiat : c'est la façon
         * dont la passerelle elle-même expose son bouton « Force scan ». */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.scan', 'capability' => 'generic.action', 'name' => 'Forcer un scan',
            'sink' => array('topic' => $base . '/' . self::CMDBT, 'payload' => '{"interval":0}'),
        )));

        return $modele;
    }

    /* --------------------------------------------------------------------- */
    /* Construction : la balise                                              */
    /* --------------------------------------------------------------------- */

    /**
     * @return MqttbeDeviceModel
     */
    public function construitBalise($_dossier) {
        $mac   = $this->texte($_dossier, 'mac');
        $etat  = self::RACINE_ETAT . '/' . $mac . '/state';
        $nom   = $this->texte($_dossier, 'nom');
        $modeleId = $this->texte($_dossier, 'modeleId');

        $modele = new MqttbeDeviceModel(array(
            'identity' => array(
                'adapter' => self::ID,
                /* Une balise, un équipement, quel que soit le nombre de
                 * passerelles qui la voient : c'est tout l'intérêt de
                 * l'affaire, et la MAC est la seule chose qu'elles aient en
                 * commun. */
                'uid'     => 'ble:' . $mac,
                'aliases' => array('mac:' . $mac),
                /* `certain` = décodée, donc créée ; `guess` = brute, donc
                 * proposée. Jamais `probable`, qui veut dire « je sais ce que
                 * c'est mais pas encore tout », et dont les modèles sont créés.
                 * Voir confiance(). */
                'confidence' => $this->texte($_dossier, 'confiance') === 'certain'
                              ? 'certain' : 'guess',
            ),
            'meta' => array(
                'name'         => 'Balise ' . strtoupper(substr($mac, -6)),
                /* Le nom que la balise ANNONCE elle-même : c'est celui que
                 * l'utilisateur voit dans son téléphone, et le seul qui lui
                 * parle. Dans la file d'adoption, c'est souvent la seule chose
                 * qui distingue son traceur du téléphone d'un passant. */
                'device_name'  => $nom,
                'manufacturer' => $this->texte($_dossier, 'marque'),
                'model'        => $modeleId,
                'model_name'   => $this->texte($_dossier, 'modele'),
                'battery_powered' => !empty($_dossier['pile']),
                /*
                 * DE QUOI TRANCHER DANS LA FILE D'ADOPTION.
                 *
                 *   address_type  « public » : adresse gravée dans le
                 *       matériel, la même dans six mois, l'équipement créé
                 *       aujourd'hui vaudra encore demain. « random » : adresse
                 *       aléatoire, qui peut être statique ou tourner toutes les
                 *       quinze minutes — adopter celle-là, c'est souvent créer
                 *       un équipement qui ne désignera plus rien.
                 *   gateways  combien de passerelles la voient : une balise
                 *       vue par les trois passerelles de la maison y habite ;
                 *       une balise vue par une seule, faiblement, passe dans la
                 *       rue.
                 *
                 * MqttbeDeviceModel ne recopie aujourd'hui que les clés de
                 * `meta` qu'il déclare : ces deux-là sont posées ici et
                 * attendent d'y être ajoutées. Elles ne coûtent rien tant que ce
                 * n'est pas fait — et le jour où ce l'est, la file d'adoption
                 * les affiche sans qu'on retouche cet adapter.
                 */
                'address_type' => $this->texte($_dossier, 'macType'),
                'gateways'     => count($_dossier['gws']),
            ),
        ));

        /* Les trois valeurs calculées par le démon, sur un seul topic. */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => self::CLE_PRESENCE, 'capability' => 'presence.detected', 'name' => 'Présence',
            'source' => array('topic' => $etat, 'selector' => array('type' => 'json', 'path' => 'presence')),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => self::CLE_PROCHE, 'capability' => 'generic.value', 'name' => 'Passerelle la plus proche',
            'source' => array('topic' => $etat, 'selector' => array('type' => 'json', 'path' => 'nearest')),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => self::CLE_VUE, 'capability' => 'generic.value', 'name' => 'Vue à',
            'source' => array('topic' => $etat, 'selector' => array('type' => 'json', 'path' => 'seen')),
        )));

        /* Un RSSI PAR PASSERELLE, en commandes distinctes : c'est la matière
         * première de l'indication de pièce, et la seule façon de voir qu'une
         * balise s'éloigne d'un côté de la maison pour se rapprocher de
         * l'autre. La valeur circule par le routage ordinaire, depuis le topic
         * de la passerelle — l'adapter n'y touche pas. */
        $slugs = array_keys($_dossier['gws']);
        sort($slugs, SORT_STRING);
        foreach ($slugs as $slug) {
            $vue = $_dossier['gws'][$slug];
            $modele->addChannel(new MqttbeChannel(array(
                'key'        => $this->cleRssi($slug),
                'capability' => 'connectivity.rssi',
                'name'       => 'Signal ' . (isset($vue['nom']) ? $vue['nom'] : $slug),
                'unit'       => 'dBm',
                'source'     => array('topic' => $vue['topic'],
                                      'selector' => array('type' => 'json', 'path' => 'rssi')),
                /* Une trame BLE toutes les deux secondes, trois passerelles :
                 * sans cette limite, l'historique d'une seule balise pèserait
                 * plus lourd que tout le reste du plugin. Une valeur qui change
                 * franchit toujours la limite (voir Router::route) : on calme un
                 * capteur bavard, on ne perd pas un état. */
                'value'      => array('repeat' => array('mode' => 'onchange', 'minInterval' => 30)),
            )));
        }

        /* Et les champs que la passerelle a décodés. Le filtre commun couvre
         * toutes les passerelles à la fois : une mesure remonte par celle qui
         * l'a reçue, et l'arrêt d'une passerelle ne fait pas taire le capteur. */
        $filtre = $this->filtreCommun($_dossier);
        if ($filtre !== '') {
            $table  = self::table();
            $unites = self::unitesGeneriques();
            $champs = array_keys($_dossier['champs']);
            foreach ($champs as $champ) {
                /* Un capteur qui publie les deux ne publie pas deux
                 * températures : c'est la même, dans deux unités. */
                if ($champ === 'tempf' && isset($_dossier['champs']['tempc'])) {
                    continue;
                }
                $cle = $champ;
                if ($cle === self::CLE_PRESENCE || $cle === self::CLE_PROCHE || $cle === self::CLE_VUE) {
                    $cle = 'ble.' . $champ;
                }
                $valeur = array();
                if (isset($table[$champ])) {
                    $fiche = $table[$champ];
                    $capacite = $fiche[0];
                    $unite    = $fiche[1];
                    $nomCanal = $fiche[2];
                    $transform = array();
                    if ($fiche[3] !== null) {
                        $transform['scale'] = $fiche[3];
                    }
                    if ($champ === 'tempf') {
                        $transform['offset'] = self::OFFSET_FAHRENHEIT;
                    }
                    if ($fiche[4] !== null) {
                        $transform['round'] = $fiche[4];
                    }
                    if (!empty($transform)) {
                        $valeur['transform'] = $transform;
                    }
                } else {
                    /* L'INCONNU N'EST PAS JETÉ. Mieux vaut une valeur brute
                     * exploitable dans un scénario qu'une donnée perdue — et le
                     * jour où la passerelle est mise à jour et décode un capteur
                     * de plus, ses champs apparaissent sans qu'on touche au
                     * plugin. */
                    $capacite = 'generic.value';
                    $unite    = isset($unites[$champ]) ? $unites[$champ] : '';
                    $nomCanal = $champ;
                }
                $modele->addChannel(new MqttbeChannel(array(
                    'key' => $cle, 'capability' => $capacite, 'name' => $nomCanal, 'unit' => $unite,
                    'source' => array('topic' => $filtre,
                                      'selector' => array('type' => 'json', 'path' => $champ)),
                    'value'  => $valeur,
                )));
            }
        }

        return $modele;
    }

    /*
     * Le filtre qui couvre toutes les passerelles à la fois.
     *
     * `bt/SALON/BTtoMQTT/E7…` et `bt/ETAGE/BTtoMQTT/E7…` se généralisent en
     * `bt/+/BTtoMQTT/E7…` : la mesure remonte quelle que soit la passerelle qui
     * l'a reçue. LE DERNIER NIVEAU N'EST JAMAIS GÉNÉRALISÉ — `bt/+/BTtoMQTT/+`
     * ferait remonter la température de toutes les balises de la maison sur un
     * seul équipement. Les passerelles dont le topic n'a ni la même profondeur
     * ni la même écriture d'adresse sont donc écartées du filtre commun ; elles
     * gardent leur canal de RSSI, qui, lui, est exact.
     */
    private function filtreCommun($_dossier) {
        $slugs = array_keys($_dossier['gws']);
        sort($slugs, SORT_STRING);
        $reference = null;
        foreach ($slugs as $slug) {
            $topic = isset($_dossier['gws'][$slug]['topic']) ? (string) $_dossier['gws'][$slug]['topic'] : '';
            if ($topic === '') {
                continue;
            }
            $niveaux = explode('/', $topic);
            if ($reference === null) {
                $reference = $niveaux;
                continue;
            }
            if (count($niveaux) !== count($reference)
                || end($niveaux) !== $reference[count($reference) - 1]) {
                continue;
            }
            for ($i = 0; $i < count($reference) - 1; $i++) {
                if ($niveaux[$i] !== $reference[$i]) {
                    $reference[$i] = '+';
                }
            }
        }
        return ($reference === null) ? '' : implode('/', $reference);
    }

    /* La clé du canal de RSSI d'une passerelle. Le préfixe de topic peut être
     * long — le parc d'essai en a un de quarante-quatre caractères, dupliqué —
     * et la clé est bornée à 127 : au-delà, une empreinte du préfixe, qui reste
     * stable et ne peut pas entrer en collision. */
    private function cleRssi($_slug) {
        $cle = 'rssi.' . $_slug;
        if (MqttbeChannel::length($cle) <= MqttbeChannel::MAX_KEY) {
            return $cle;
        }
        return 'rssi.' . substr(sha1($_slug), 0, 16);
    }

    /* Ce qui, dans un dossier de balise, décrit l'APPAREIL — et donc ce qui
     * mérite qu'un modèle reparte vers Jeedom. Le RSSI n'y est pas. La date de
     * dernière vue non plus. */
    private function signatureBalise($_dossier) {
        $gws = array();
        $slugs = array_keys($_dossier['gws']);
        sort($slugs, SORT_STRING);
        foreach ($slugs as $slug) {
            $vue = $_dossier['gws'][$slug];
            $gws[] = $slug . '=' . (isset($vue['topic']) ? $vue['topic'] : '')
                   . '=' . (isset($vue['nom']) ? $vue['nom'] : '');
        }
        $champs = array_keys($_dossier['champs']);
        sort($champs, SORT_STRING);
        return implode('|', array(
            $this->texte($_dossier, 'nom'),
            $this->texte($_dossier, 'modele'),
            $this->texte($_dossier, 'modeleId'),
            $this->texte($_dossier, 'marque'),
            empty($_dossier['pile']) ? '0' : '1',
            $this->texte($_dossier, 'macType'),
            /* La confiance n'entre pas dans l'empreinte du modèle — c'est une
             * décision d'adoption, pas une propriété de l'appareil — mais elle
             * doit faire REPARTIR le modèle : le jour où la passerelle se met à
             * décoder une balise, elle passe de la file d'adoption à un
             * équipement, et Jeedom doit l'apprendre. */
            $this->texte($_dossier, 'confiance'),
            implode(',', $champs),
            implode(',', $gws),
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Inventaire : plafond et péremption                                    */
    /* --------------------------------------------------------------------- */

    private function purge($_ctx, $_maintenant, $_reglages) {
        $quand = $_ctx->recall(self::ID . ':purge');
        if (is_numeric($quand) && ($_maintenant - (float) $quand) < self::PERIODE_PURGE) {
            return;
        }
        $_ctx->remember(self::ID . ':purge', $_maintenant);
        $this->purgeCandidates($_ctx, $_maintenant, self::PEREMPTION);
    }

    /*
     * Une balise vue une fois puis plus jamais n'encombre pas la file : elle se
     * périme. Une balise DÉCODÉE, jamais — son silence est précisément ce que
     * son équipement rapporte, et l'oublier ferait figer sa présence à la
     * dernière valeur publiée.
     */
    private function purgeCandidates($_ctx, $_maintenant, $_delai) {
        $restantes = array();
        $oubliees  = 0;
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (empty($dossier)) {
                continue;
            }
            if (empty($dossier['decodee'])
                && ($_maintenant - $this->nombre($dossier, 'vu', 0)) >= $_delai) {
                $this->oublie($_ctx, $mac, $dossier);
                $oubliees++;
                continue;
            }
            $restantes[] = $mac;
        }
        if ($oubliees > 0) {
            $_ctx->remember(self::ID . ':index.ble', $restantes);
            $_ctx->log('debug', 'OpenMQTTGateway : ' . $oubliees
                . ' balise(s) jamais décodée(s) oubliée(s), ' . count($restantes) . ' suivie(s).');
        }
    }

    /* Le plafond atteint et rien à périmer : la candidate vue il y a le plus
     * longtemps cède la place. Évincer plutôt que refuser garde l'inventaire
     * utile — ce qui passe maintenant intéresse plus que ce qui est passé hier —
     * et une balise décodée n'est jamais évincée. */
    private function evince($_ctx) {
        $plusVieille = null;
        $quand = null;
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (empty($dossier) || !empty($dossier['decodee'])) {
                continue;
            }
            $vu = $this->nombre($dossier, 'vu', 0);
            if ($quand === null || $vu < $quand) {
                $quand = $vu;
                $plusVieille = $mac;
            }
        }
        if ($plusVieille === null) {
            return false;
        }
        $this->oublie($_ctx, $plusVieille, $this->dossier($_ctx, 'ble:' . $plusVieille));
        $restantes = array();
        foreach ($this->balises($_ctx) as $mac) {
            if ($mac !== $plusVieille) {
                $restantes[] = $mac;
            }
        }
        $_ctx->remember(self::ID . ':index.ble', $restantes);
        return true;
    }

    /*
     * Oublier une balise, dossier ET message retenu.
     *
     * L'état calculé est publié en RETENU : sans cet effacement, chaque
     * téléphone passé devant la maison laisserait sur le broker un message qui
     * y resterait pour toujours. Une charge utile vide sur un topic retenu est
     * la façon dont MQTT dit « oublie ça » (OASIS 3.1.1 §3.3.1.3), et c'est le
     * seul geste qui garde le broker aussi borné que l'inventaire.
     */
    private function oublie($_ctx, $_mac, $_dossier) {
        $_ctx->forget(self::ID . ':ble:' . $_mac);
        if (isset($_dossier['etat'])) {
            $_ctx->publish($this->topicEtat($_mac), '', 0, true);
        }
    }

    private function topicEtat($_mac) {
        return self::RACINE_ETAT . '/' . $_mac . '/state';
    }

    /* --------------------------------------------------------------------- */
    /* Mémoire de l'adapter                                                  */
    /* --------------------------------------------------------------------- */

    /*
     * Un dossier par appareil, plus deux index : la mémoire du contexte se lit
     * par clé, elle ne s'énumère pas. Les index sont ce qui rend l'inventaire
     * BORNABLE — sans eux, on ne saurait ni compter ni purger.
     */
    public function passerelles($_ctx) {
        $index = $_ctx->recall(self::ID . ':index.gw');
        return is_array($index) ? array_values($index) : array();
    }

    public function balises($_ctx) {
        $index = $_ctx->recall(self::ID . ':index.ble');
        return is_array($index) ? array_values($index) : array();
    }

    private function dossier($_ctx, $_cle) {
        $valeur = $_ctx->recall(self::ID . ':' . $_cle);
        return is_array($valeur) ? $valeur : array();
    }

    private function range($_ctx, $_cle, $_dossier) {
        $_ctx->remember(self::ID . ':' . $_cle, $_dossier);
    }

    /* Inscrit une entrée dans un index, sous plafond. Rend false quand le
     * plafond est atteint et que l'entrée est nouvelle — jamais quand elle y
     * est déjà, sans quoi un inventaire plein cesserait de suivre ce qu'il
     * suit. */
    private function inscrit($_ctx, $_index, $_entree, $_plafond) {
        $liste = $_ctx->recall(self::ID . ':' . $_index);
        if (!is_array($liste)) {
            $liste = array();
        }
        if (in_array($_entree, $liste, true)) {
            return true;
        }
        if (count($liste) >= $_plafond) {
            return false;
        }
        $liste[] = $_entree;
        $_ctx->remember(self::ID . ':' . $_index, $liste);
        return true;
    }

    /* Une plainte au plus par heure et par motif : le message qui dit que
     * l'inventaire déborde ne doit pas, lui-même, faire déborder le journal. */
    private function plainte($_ctx, $_motif, $_message) {
        $cle = self::ID . ':plainte:' . $_motif;
        $quand = $_ctx->recall($cle);
        $maintenant = $this->maintenant($_ctx);
        if (is_numeric($quand) && ($maintenant - (float) $quand) < 3600) {
            return;
        }
        $_ctx->remember($cle, $maintenant);
        $_ctx->log('warning', 'OpenMQTTGateway : ' . $_message);
    }

    /* --------------------------------------------------------------------- */
    /* Petits outils                                                         */
    /* --------------------------------------------------------------------- */

    /*
     * La base d'un topic : tous les niveaux avant celui qui a servi à le
     * reconnaître. Chaque niveau est contrôlé, parce que cette base compose
     * ensuite les topics de PUBLICATION des actions — un joker y ferait fermer
     * la connexion par le broker au premier appui sur un bouton.
     */
    private function base($_niveaux, $_jusqua) {
        if ($_jusqua < 1) {
            return '';
        }
        $base = array();
        for ($i = 0; $i < $_jusqua; $i++) {
            if (!preg_match(self::NIVEAU_VALIDE, $_niveaux[$i])) {
                return '';
            }
            $base[] = $_niveaux[$i];
        }
        return implode('/', $base);
    }

    /* Une charge utile illisible est un incident ordinaire — trafic voisin,
     * message tronqué, appareil qui redémarre — et non une erreur de
     * programme : elle se jette, sans un mot et sans lever. */
    private function json($_payload) {
        $decode = json_decode((string) $_payload, true);
        return is_array($decode) ? $decode : null;
    }

    /* Un champ qui ne s'efface jamais : voir recoitBalise(). */
    private function colle(&$_dossier, $_trame, $_champ, $_cle) {
        $valeur = MqttbeDeviceModel::cleanDeviceName(
            isset($_trame[$_champ]) && !is_array($_trame[$_champ]) ? (string) $_trame[$_champ] : '');
        if ($valeur !== '') {
            $_dossier[$_cle] = $valeur;
        }
    }

    /* Une MAC, quelle que soit son écriture : « E7:E7:E7:72:C7:95 » et
     * « E7E7E772C795 » sont le même appareil, et deux orthographes ne doivent
     * pas donner deux équipements. */
    public static function normaliseMac($_valeur) {
        $mac = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', (string) $_valeur));
        return preg_match('/^[0-9a-f]{12}$/', $mac) ? $mac : '';
    }

    /* Un préfixe de topic rendu utilisable comme fragment de clé de commande. */
    public static function slug($_base) {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $_base);
        return trim($slug, '_');
    }

    public static function dernierNiveau($_base) {
        $niveaux = explode('/', (string) $_base);
        return (string) end($niveaux);
    }

    private static function vraiFaux($_valeur) {
        if (is_bool($_valeur)) {
            return $_valeur;
        }
        if (is_numeric($_valeur)) {
            return ((int) $_valeur) !== 0;
        }
        return in_array(strtolower(trim((string) $_valeur)), array('true', 'yes', 'on'), true);
    }

    private function texte($_tableau, $_cle) {
        return (isset($_tableau[$_cle]) && !is_array($_tableau[$_cle]))
            ? trim((string) $_tableau[$_cle]) : '';
    }

    private function nombre($_tableau, $_cle, $_defaut = 0) {
        return (isset($_tableau[$_cle]) && is_numeric($_tableau[$_cle]))
            ? (float) $_tableau[$_cle] : $_defaut;
    }

    /* Une valeur venue du réseau, rendue citable dans une ligne de journal :
     * caractères de contrôle ôtés — un retour à la ligne y fabriquerait une
     * fausse entrée — et longueur bornée. */
    private function citation($_valeur) {
        $texte = preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $_valeur);
        return (strlen($texte) > 48) ? substr($texte, 0, 48) . '…' : $texte;
    }

    /* Le temps vient du contexte : un contrôle hors ligne rejoue une journée en
     * quelques millisecondes, et time() lui ferait manquer toutes les
     * expirations — à commencer par celle qui décide qu'une balise est partie. */
    private function maintenant($_ctx) {
        $maintenant = $_ctx->now();
        return is_numeric($maintenant) ? (float) $maintenant : 0;
    }
}
