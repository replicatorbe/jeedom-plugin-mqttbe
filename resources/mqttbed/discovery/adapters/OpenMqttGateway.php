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
 *   balises vues est donc plafonné (MAX_BALISES), périmé (PEREMPTION, et
 *   PEREMPTION_DECODEE pour ce que la passerelle a reconnu) et, au plafond, il
 *   évince la plus ancienne balise sans faisceau plutôt que de grandir. TOUT se
 *   périme, y compris les décodées : « décodée » ne veut pas dire « à moi », et
 *   les capteurs du voisinage sont décodés tout aussi bien que les nôtres.
 *   C'est le seul endroit de ce plugin où un démon peut enfler sans limite —
 *   et les dossiers de PASSERELLE s'y périment aussi (PEREMPTION_GW), sans
 *   quoi le préfixe d'une passerelle renommée lui survivrait pour toujours.
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

    /*
     * Une balise que la passerelle n'a jamais décodée et qu'on n'a plus vue
     * depuis trois heures n'encombre plus la file : c'est le téléphone d'un
     * visiteur, vu une fois, jamais revu.
     *
     * UNE BALISE DÉCODÉE SE PÉRIME AUSSI, plus tard. « Décodée » ne veut pas
     * dire « à moi » : les thermomètres du voisinage sont décodés tout aussi
     * bien que les miens, et une décodée qui ne périmerait jamais remplirait
     * l'inventaire de capteurs vus une fois à −97 dBm — après quoi la balise de
     * la maison ne serait plus jamais découverte, faute de place. Vingt-quatre
     * heures : un capteur qui n'a rien dit de la journée a été retiré, et son
     * équipement, lui, reste dans Jeedom avec sa dernière valeur.
     */
    const PEREMPTION          = 10800;
    const PEREMPTION_DECODEE  = 86400;
    const PERIODE_PURGE       = 60;

    /*
     * Depuis combien de temps le démon doit tourner avant de s'étonner qu'un
     * préfixe n'ait pas dit qui il était.
     *
     * Le broker rejoue les annonces retenues à l'abonnement, et rien ne garantit
     * qu'elles arrivent avant les trames. Mais surtout, L'INTERVALLE D'ANNONCE
     * NE NOUS APPARTIENT PAS : il se règle sur la passerelle, et cinq minutes
     * — la première valeur essayée — ont suffi à faire accuser une passerelle
     * parfaitement vivante, qui publiait trois cents trames pendant ce
     * temps-là et dont l'annonce, simplement, venait plus tard. Une demi-heure
     * laisse passer les intervalles longs sans rien perdre de ce qu'on cherche :
     * un appareil qui ne s'annonce qu'à son démarrage ne s'annoncera jamais.
     * Voir surveilleAnonymes().
     */
    const DELAI_ANONYME = 1800;

    /*
     * Et une PASSERELLE se périme comme une balise.
     *
     * Renommer une passerelle dans son interface web lui donne un préfixe de
     * topic neuf sans changer sa `mac` : l'ancien dossier ne décrit plus rien,
     * mais rien ne l'effaçait — il restait en mémoire pour toujours, et
     * continuait de revendiquer l'équipement. Vingt-quatre heures sans
     * SYStoMQTT : le préfixe n'existe plus.
     */
    const PEREMPTION_GW = 86400;

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

    /*
     * Depuis combien de temps une passerelle doit s'être tue pour être écartée
     * du calcul de proximité.
     *
     * C'est une constante COURTE et FIXE, et surtout pas le délai d'absence
     * d'une balise : celui-ci se règle jusqu'à une journée, pour une balise qui
     * n'émet que de loin en loin — tandis qu'une passerelle, elle, parle en
     * permanence. Les juger avec le même délai, c'est laisser « au salon »
     * pendant une journée entière après avoir débranché la passerelle du salon,
     * à l'endroit exact où l'utilisateur vient chercher son objet. Une
     * passerelle muette depuis quatre-vingt-dix secondes ne voit plus rien.
     */
    const FRAICHEUR_PROCHE = 90;

    /*
     * Deux préfixes pour une même `mac` : de combien le plus frais doit
     * devancer l'autre pour l'emporter à coup sûr.
     *
     * En deçà, les deux sont réputés vivants et c'est la longueur qui tranche —
     * sans quoi deux préfixes qui publient tous les deux (le parc réel en a un,
     * dupliqué par une mauvaise saisie) se voleraient l'équipement à chaque
     * battement, et le modèle repartirait sans fin vers Jeedom.
     */
    const FRAICHEUR_GW = 300;

    /*
     * UNE ADRESSE ALÉATOIRE N'EXISTE QU'APRÈS AVOIR DURÉ.
     *
     * Une adresse BLE aléatoire tourne — un quart d'heure chez Apple et sur
     * Android, une demi-heure chez Microsoft. Un seul téléphone fabrique donc
     * quatre-vingt-seize identités par jour, toutes distinctes, toutes vouées à
     * ne désigner plus rien le lendemain. On attend qu'une telle balise ait
     * duré PLUS QUE LA PLUS LONGUE DES ROTATIONS CONNUES avant de lui
     * reconnaître une existence : ce qui a survécu à la rotation ne tournait
     * pas.
     *
     * La règle vaut POUR LA CRÉATION COMME POUR LA FILE D'ADOPTION, et c'est
     * ce qui a changé. Elle ne gardait que la file, au motif qu'une balise
     * `certain` avait mérité son équipement — et un iPhone de la maison,
     * décodé « iBeacon » et entendu à −70 dBm, méritait le sien toutes les
     * vingt minutes. Cent quatre-vingt-dix équipements en trois jours sur
     * l'installation réelle, aucun n'ayant jamais reçu la moindre valeur : à
     * la trame suivante, l'adresse avait déjà changé.
     *
     * Ce qu'elle ne retarde pas : une adresse PUBLIQUE, gravée dans le
     * matériel, n'attend rien. Et un traceur à adresse aléatoire figée — les
     * Tile en sont — franchit le seuil en une heure, puis se crée tout seul.
     */
    const STABILITE_ALEATOIRE = 3600;

    /*
     * Le faisceau qui distingue « décodée » de « à moi ». Voir confiance().
     *
     * Un signal au-dessus du plancher — une balise de la maison, même à
     * l'autre bout —, ou bien plusieurs trames étalées sur plusieurs minutes.
     * Les 260 thermomètres du voisinage vus une fois à −97 dBm ne passent ni
     * l'un ni l'autre.
     */
    const PLANCHER_RSSI = -85;
    const VUES_MIN      = 3;
    const DUREE_MIN     = 300;

    /* Un réessai de publication au plus par balise et par minute. Sans cette
     * borne, un broker injoignable produisait 7 200 tentatives en soixante
     * secondes — chacune journalisée, au moment précis où l'utilisateur est
     * passé en debug pour comprendre sa panne. */
    const PERIODE_ESSAI = 60;

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
    const RACINE_ETAT  = 'mqttbe/omg/ble';
    const FEUILLE_ETAT = 'state';

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
            'motion'   => array('presence.detected',  '',    'Mouvement',        null, null),
            'open'     => array('contact.open',       '',    'Ouverture',        null, null),
            'contact'  => array('contact.open',       '',    'Contact',          null, null),
        );
    }

    /* Le décalage du Fahrenheit, appliqué après l'échelle : (F − 32) × 5/9. */
    const OFFSET_FAHRENHEIT = -17.777778;

    /*
     * LES CHAMPS QUI DISENT « JE SUIS LÀ », ET QUI NE DEVIENNENT PAS UNE
     * COMMANDE.
     *
     * `track` et `presence` sont vrais tant que la balise émet — et ils ne
     * redescendent JAMAIS, puisqu'une balise qui part cesse d'émettre. Les
     * exposer, c'est poser à côté de la présence CALCULÉE une seconde commande
     * de présence, du même type générique, historisée elle aussi, et qui reste
     * bloquée sur « présent » pour l'éternité. Un scénario a alors une chance
     * sur deux de choisir la mauvaise, et celui qui se trompe ne se déclenchera
     * jamais.
     *
     * Ils ne sont pas jetés pour autant : leur présence dans une trame prouve
     * que la passerelle a RECONNU la balise (voir trameDecodee), et c'est ce
     * qui fait d'un traceur Tile un équipement plutôt qu'un candidat.
     */
    private static function champsPresence() {
        return array('track' => true, 'presence' => true);
    }

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
     *
     *   acts, cidc, cont, adv_type, mac_type, device
     *                        des DRAPEAUX INTERNES DU DÉCODEUR. Le traceur
     *                        Tile réel les apporte tous les six, et ils
     *                        arrivaient sur le tableau de bord en commandes
     *                        visibles, sans nom lisible ni sens pour personne :
     *                        « cidc » ne veut rien dire, et sa valeur ne
     *                        décrira jamais l'objet qu'on cherche. `mac_type`
     *                        et `adv_type`, eux, sont déjà lus — ils décident
     *                        de ce qui va dans la file d'adoption — mais ils
     *                        décrivent la TRAME, pas l'appareil.
     */
    private static function champsTechniques() {
        return array(
            'id' => true, 'mac' => true,
            'name' => true, 'model' => true, 'model_id' => true,
            'brand' => true, 'type' => true,
            'manufacturerdata' => true, 'manufacturerdata2' => true,
            'servicedata' => true, 'servicedatauuid' => true, 'servicuuid' => true,
            'rssi' => true,
            'acts' => true, 'cidc' => true, 'cont' => true,
            'adv_type' => true, 'mac_type' => true, 'device' => true,
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
     *
     * L'ESPACE EST ACCEPTÉ. Il est légal dans un nom de topic MQTT, et
     * l'interface d'OpenMQTTGateway laisse parfaitement saisir « OMG Salon » :
     * le refuser, c'était abandonner la passerelle d'un utilisateur qui n'avait
     * rien fait d'anormal — et l'abandonner sans une ligne de journal, si bien
     * qu'aucune enquête ne pouvait aboutir. Ce qui reste interdit, ce sont les
     * jokers et les caractères de contrôle, qui, eux, feraient fermer la
     * connexion. Un niveau fait de seuls espaces est refusé à part : il ne
     * nomme rien.
     */
    const NIVEAU_VALIDE = '/^[A-Za-z0-9 ._:-]{1,64}$/';

    /* Un nom de champ décodé doit pouvoir servir de chemin JSON : le routage
     * éclate le chemin sur les points, et un champ « a.b » désignerait alors
     * un sous-objet qui n'existe pas. */
    const CHAMP_VALIDE = '/^[A-Za-z0-9_]{1,40}$/';

    /*
     * Le temps laissé au broker pour rejouer ce qu'il retient.
     *
     * À l'abonnement, le broker déverse d'un coup tous ses messages retenus :
     * les trames BLE et les états que le démon précédent a laissés, dans un
     * ordre qui n'est garanti nulle part. Décider du sort d'un état à la
     * seconde où il arrive, c'est décider sans savoir si la balise concernée
     * est déjà connue — et se tromper une fois sur deux selon l'ordre d'arrivée.
     * On attend donc que le déluge soit retombé, et on tranche alors sur un
     * inventaire complet.
     */
    const RESORPTION_ETATS = 25;

    /*
     * Les états retenus trouvés au démarrage, en attente d'arbitrage.
     *
     * Transitoire par nature : ce registre décrit ce qu'on a trouvé sur le
     * broker à cette exécution-ci, et n'a aucun sens à survivre au processus.
     * D'où une propriété d'instance plutôt qu'un dossier du contexte.
     */
    private $etatsTrouves = array();
    private $etatsDepuis  = 0;
    private $etatsRegles  = false;

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
        /*
         * ET CE QUE LE DÉMON PRÉCÉDENT A LAISSÉ DERRIÈRE LUI.
         *
         * L'état calculé est publié RETENU, pour qu'il survive au redémarrage
         * du démon — mais il lui survit aussi quand l'objet, lui, est parti
         * entre-temps. Le démon s'arrête, le traceur quitte la maison, le démon
         * repart : le message retenu dit toujours « présent, au salon », et
         * comme l'inventaire est vide au redémarrage, plus rien ne vient jamais
         * le contredire. Les scénarios bâtis sur cette présence ne se
         * déclencheraient plus jamais.
         *
         * S'abonner à sa propre branche, c'est se faire rejouer par le broker,
         * à l'instant du démarrage, tout ce qu'on y a laissé — et pouvoir le
         * corriger. Voir recoitEtatRetenu().
         */
        $filtres[] = self::RACINE_ETAT . '/+/' . self::FEUILLE_ETAT;
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

        /* Notre propre branche, rejouée par le broker au démarrage. Elle est
         * reconnue avant tout le reste : personne d'autre n'écrit là. */
        if ($nombre === 5 && $niveaux[$nombre - 1] === self::FEUILLE_ETAT
            && implode('/', array_slice($niveaux, 0, 3)) === self::RACINE_ETAT) {
            if ($_retained) {
                $this->recoitEtatRetenu($_ctx, $niveaux[3], $_payload);
            }
            return;
        }

        if ($niveaux[$nombre - 1] === self::SYS) {
            $base = $this->base($_ctx, $niveaux, $nombre - 1, $_topic);
            if ($base !== '') {
                $this->recoitSys($_ctx, $base, $_payload);
            }
            return;
        }
        if ($nombre >= 3 && $niveaux[$nombre - 2] === self::BT) {
            $base = $this->base($_ctx, $niveaux, $nombre - 2, $_topic);
            if ($base !== '') {
                $this->recoitBalise($_ctx, $base, $niveaux[$nombre - 1], $_payload);
            }
        }
    }

    /*
     * UN ÉTAT QUE LE DÉMON PRÉCÉDENT A LAISSÉ SUR LE BROKER.
     *
     * Le message est retenu : il date d'avant l'arrêt, et il affirme une
     * présence que plus personne ne vérifie. Deux cas, et deux seulement :
     *
     *   la balise est INCONNUE de l'inventaire — c'est le téléphone d'un
     *   passant, adopté par personne, dont l'état ne sera jamais recalculé :
     *   le message s'efface (charge utile vide sur un topic retenu, OASIS
     *   3.1.1 §3.3.1.3). C'est le seul geste qui empêche le broker de garder
     *   un état par appareil passé devant la maison, pour toujours ;
     *
     *   la balise est CONNUE et sa dernière vue remonte à plus que le délai
     *   d'absence : on republie tout de suite une présence fausse, sans
     *   attendre le battement. Jeedom lit « absent » dès le démarrage plutôt
     *   que « présent, au salon » pour un objet parti depuis trois jours.
     *
     * Et un état encore frais n'est pas touché : le démon qui redémarre en
     * quinze secondes ne doit pas faire clignoter la présence de la maison.
     */
    /**
     * Un état que le démon précédent a laissé sur le broker.
     *
     * RIEN N'EST DÉCIDÉ ICI, et c'est tout l'objet de la correction. Ce message
     * arrive au milieu du déluge de messages retenus que le broker rejoue à
     * l'abonnement, avant, pendant ou après les trames BLE qui, elles, disent
     * quelles balises existent encore. Trancher maintenant revenait à trancher
     * sur un inventaire à moitié rempli.
     *
     * On se contente donc de noter que ce topic existe. resoutEtatsRetenus(),
     * au battement d'horloge suivant la résorption, décidera sur un inventaire
     * complet.
     */
    private function recoitEtatRetenu($_ctx, $_mac, $_payload) {
        $mac = self::normaliseMac($_mac);
        if ($mac === '') {
            return;
        }
        if ($this->json($_payload) === null) {
            /* Charge utile vide : c'est un effacement, le nôtre ou celui d'un
             * démon précédent. Le topic n'existe déjà plus. */
            return;
        }
        /* Borné comme le reste : un broker sur lequel traînent des milliers
         * d'états — c'est précisément le symptôme qu'on vient corriger — ne
         * doit pas faire enfler la mémoire du démon avant d'être nettoyé. */
        if (!isset($this->etatsTrouves[$mac]) && count($this->etatsTrouves) >= self::MAX_BALISES) {
            return;
        }
        $this->etatsTrouves[$mac] = true;
        if ($this->etatsDepuis === 0) {
            $this->etatsDepuis = $this->maintenant($_ctx);
        }
    }

    /**
     * Le sort des états retenus trouvés au démarrage, tranché une fois.
     *
     * UN DÉMENTI N'EFFACE RIEN. C'est l'erreur que corrige cette méthode :
     * republier `{"presence":0}` par-dessus un état périmé laisse un message
     * RETENU de plus sur le broker de l'utilisateur, c'est-à-dire éternel. Mesuré
     * sur l'installation : trente et un états orphelins au premier constat, cent
     * quatre-vingt-douze après que le démenti s'est mis à en produire à chaque
     * démarrage. Le compte ne pouvait que croître, puisque la version précédente
     * ignorait d'emblée tout état déjà à `presence:0` — donc tous ceux qu'elle
     * venait elle-même d'écrire.
     *
     * Seule la charge utile vide retire un message retenu du broker (MQTT
     * 3.1.1 §3.3.1.3). C'est donc ce qu'on publie, pour tout ce qui n'a pas
     * d'équipement chez l'utilisateur — et ce sont ces états-là, et eux seuls,
     * qui n'avaient aucun lecteur.
     *
     * Une balise `certain`, elle, garde son état : il a un équipement en face,
     * et suitBalise() le tiendra à jour. On le lui rattache au passage, pour que
     * la comparaison « a-t-il changé ? » reparte de ce que le broker porte
     * vraiment, et non d'une mémoire vide qui republierait tout.
     */
    private function resoutEtatsRetenus($_ctx, $_maintenant) {
        if ($this->etatsRegles || $this->etatsDepuis === 0) {
            return;
        }
        if (($_maintenant - $this->etatsDepuis) < self::RESORPTION_ETATS) {
            return;
        }
        $this->etatsRegles = true;

        $efface = $garde = 0;
        foreach (array_keys($this->etatsTrouves) as $mac) {
            $cle     = 'ble:' . $mac;
            $dossier = $this->dossier($_ctx, $cle);
            $connue  = !empty($dossier)
                    && $this->texte($dossier, 'confiance') === 'certain';
            if ($connue) {
                $garde++;
                continue;
            }
            if ($_ctx->publish($this->topicEtat($mac), '', 0, true) === true) {
                $efface++;
                /* Et la mémoire suit : sans cela, la balise qui redeviendrait
                 * `certain` croirait avoir déjà publié cet état. */
                if (!empty($dossier)) {
                    unset($dossier['etat'], $dossier['etatQuand'], $dossier['etatEchec']);
                    $dossier['proche'] = '';
                    $this->range($_ctx, $cle, $dossier);
                }
            }
        }
        $this->etatsTrouves = array();

        if ($efface > 0 || $garde > 0) {
            $_ctx->log($efface > 0 ? 'info' : 'debug',
                'OpenMQTTGateway : états retenus du démarrage arbitrés — ' . $efface
                . ' effacé(s) du broker (aucun équipement en face), ' . $garde
                . ' conservé(s). Un état sans équipement n\'a aucun lecteur et '
                . 'resterait indéfiniment sur le broker.');
        }
    }

    /* Une date « Y-m-d H:i:s » relue, et zéro si elle ne veut rien dire. Le
     * message retenu peut venir d'une version antérieure, ou d'un tiers. */
    private function horodate($_valeur) {
        if (!is_string($_valeur) || trim($_valeur) === '') {
            return 0;
        }
        $quand = strtotime($_valeur);
        return ($quand === false) ? 0 : (float) $quand;
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

        /* Avant la purge : ce que le broker retenait est arbitré une fois, une
         * fois le déluge de messages retenus retombé. */
        $this->resoutEtatsRetenus($_ctx, $maintenant);

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
            $dossier = array('base' => $_base, 'env' => '', 'version' => '', 'ip' => '');
        }
        $dossier['base'] = $_base;
        $dossier['mac']  = $mac;
        /*
         * `ip`, `env` et `version` SONT COLLANTS, exactement comme le nom d'une
         * balise l'est déjà.
         *
         * Un SYStoMQTT publié pendant une reconnexion Wi-Fi arrive sans son
         * `ip` : le prendre tel quel effaçait l'adresse une fois sur deux, donc
         * changeait la signature une fois sur deux, donc réémettait vingt
         * modèles pour vingt messages — et l'utilisateur retrouvait un
         * équipement dont le lien « ouvrir l'interface » disparaissait et
         * revenait tout seul. Une valeur absente n'est pas une valeur nouvelle.
         */
        foreach (array('env', 'version', 'ip') as $champ) {
            $valeur = $this->texte($sys, $champ);
            if ($valeur !== '') {
                $dossier[$champ] = $valeur;
            } elseif (!isset($dossier[$champ])) {
                $dossier[$champ] = '';
            }
        }
        $dossier['nom'] = self::dernierNiveau($_base);
        /* La date du dernier SYStoMQTT : c'est elle qui départage deux préfixes
         * pour une même `mac` (voir baseRetenue) et elle qui fait périmer un
         * préfixe qui ne sert plus. */
        $dossier['vu'] = $this->maintenant($_ctx);
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
            /* Le TYPE est retenu avec le champ, parce qu'il ne se devine pas
             * d'un nom : un champ que personne n'a prévu devient une valeur
             * générique NUMÉRIQUE quand la passerelle en publie un nombre, et
             * textuelle sinon. La différence se voit sur le tableau de bord —
             * une valeur numérique se trace, se compare et se moyenne. */
            $dossier['champs'][$champ] = is_numeric($valeur) ? 'n' : 's';
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
        /* Combien de fois vue : avec `depuis`, c'est le faisceau qui distingue
         * une balise de la maison d'un capteur du voisinage aperçu une fois.
         * Borné, parce qu'un compteur qui grandit pendant des jours finit par
         * peser plus que le dossier. */
        $compte = (int) $this->nombre($dossier, 'compte', 0);
        if ($compte < self::VUES_MIN) {
            $dossier['compte'] = $compte + 1;
        }

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
            $this->purgeBalises($_ctx, $_maintenant);
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
            'compte'  => 0,
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
     *
     * MAIS UN FORMAT D'ANNONCE N'EST PAS UN APPAREIL.
     *
     * Un `model` suffisait, et c'était l'erreur. La passerelle sait lire des
     * formats d'annonce STANDARDS — iBeacon, et de la même famille les trames
     * de proximité d'Apple, de Microsoft, ou celles du traçage de contacts —
     * qu'émettent un téléphone, une montre, un autoradio. Elle les nomme
     * (`model: "iBeacon"`) tout en disant elle-même qu'elle ne sait pas de quel
     * appareil il s'agit : `brand: "GENERIC"`. Le prendre pour une
     * reconnaissance, c'est créer un équipement par téléphone présent, et ses
     * commandes ne portent rien qui se lise — `uuid`, `major`, `minor` sont des
     * numéros de trame, pas des mesures.
     *
     * On lit donc la MARQUE, qui est un champ de plus publié par la passerelle
     * et non un catalogue. Elle tranche dans les deux sens :
     *
     *   — GÉNÉRIQUE, RIEN NE RATTRAPE. Pas même une mesure. C'est le point qui
     *     a coûté le plus cher à comprendre : le décodeur générique tire des
     *     octets d'un format d'annonce ce qu'il peut, et sur l'installation
     *     réelle il en sortait une tension — 10,9 V sur une balise, 3,8 V sur
     *     une autre, prises dans des octets qui ne veulent rien dire. Cette
     *     valeur de fantaisie suffisait à faire passer la balise pour un
     *     capteur, et elle protégeait ensuite cent cinq équipements morts du
     *     balayage. Une mesure ne vaut que si l'on sait de QUOI elle vient.
     *   — ABSENTE, ON NE CONCLUT RIEN CONTRE. Une marque qui manque n'est pas
     *     un aveu : la balise est alors jugée sur ses champs, comme avant.
     *
     * Rien n'est perdu pour autant : ces balises partent en file d'adoption, et
     * celui qui reconnaît la sienne l'adopte d'un clic. Et le jour où la
     * passerelle reconnaît vraiment l'appareil, elle écrit « Apple » ou
     * « Xiaomi » à la place de « GENERIC », et l'équipement se crée sans qu'on
     * touche au plugin.
     */
    const MARQUE_GENERIQUE = 'GENERIC';

    private function trameDecodee($_dossier) {
        $marque = strtoupper($this->texte($_dossier, 'marque'));
        if ($marque === self::MARQUE_GENERIQUE) {
            return false;
        }
        $nomme = $this->texte($_dossier, 'modele') !== ''
              || $this->texte($_dossier, 'modeleId') !== '';
        if ($nomme && $marque !== '') {
            return true;
        }
        $table = self::table();
        /* `track` et `presence` ne deviennent pas des commandes (voir
         * champsPresence), mais ils PROUVENT que la passerelle a reconnu la
         * balise : c'est à cela, et à rien d'autre, qu'un traceur se
         * reconnaît. */
        $presence = self::champsPresence();
        foreach (array_keys($_dossier['champs']) as $champ) {
            if (isset($table[$champ]) || isset($presence[$champ])) {
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

        $modifie = false;

        /* Le faisceau, une fois acquis, ne se perd plus : une balise qui a
         * mérité son équipement ne doit pas retomber dans la file d'adoption
         * parce qu'elle s'est éloignée de deux mètres. Voir faisceau(). */
        if (empty($dossier['faisceau']) && !empty($dossier['decodee'])
            && $this->faisceau($dossier)) {
            $dossier['faisceau'] = true;
            $modifie = true;
        }
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
            $proche = $this->plusProche($dossier, $_maintenant);
        }

        /*
         * ON NE PUBLIE L'ÉTAT QUE DES BALISES `certain`.
         *
         * Une balise `guess` n'a aucun équipement dans Jeedom, donc aucun
         * lecteur : publier son état, c'est déposer sur le broker de
         * l'utilisateur un message RETENU, c'est-à-dire éternel, pour chaque
         * téléphone qui passe devant la maison. Mesuré sur l'installation
         * réelle : trente et un messages retenus pour un seul équipement.
         *
         * Et ce qui a pu être publié avant — parce que `bleAdoptAll` était
         * coché, ou parce que la balise a perdu son faisceau — s'efface.
         */
        if ($confiance !== 'certain') {
            if (isset($dossier['etat']) && $dossier['etat'] !== '') {
                if ($_ctx->publish($this->topicEtat($_mac), '', 0, true) === true) {
                    unset($dossier['etat'], $dossier['etatQuand'], $dossier['etatEchec']);
                    $dossier['proche'] = '';
                    $modifie = true;
                }
            }
            if ($modifie) {
                $this->range($_ctx, $cle, $dossier);
            }
            $this->emetBalise($_ctx, $_mac, $_reglages);
            return;
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
        $echec = $this->nombre($dossier, 'etatEchec', 0);

        /* Un changement de présence ou de pièce part tout de suite ; un simple
         * rafraîchissement de la date attend la minute. Sans cette seconde
         * borne, « vue à » repartirait à chaque trame reçue, soit plusieurs
         * fois par seconde pour une information qui se lit à la minute. */
        $urgent = ($avant === '') || $this->changeEtat($avant, $etat);
        /*
         * ET LE GARDE-TEMPS BORNE AUSSI LES RÉESSAIS.
         *
         * Une publication qui échoue ne posait aucune date : le battement
         * suivant réessayait, et celui d'après, pour chaque balise — sept mille
         * deux cents tentatives en soixante secondes de broker injoignable,
         * chacune journalisée, au moment précis où l'utilisateur est passé en
         * debug pour comprendre sa panne. Une balise dont la dernière tentative
         * a échoué attend donc PERIODE_ESSAI avant la suivante, urgence ou non :
         * ce qu'elle a à dire n'arrivera pas plus vite sur un broker absent.
         */
        $reessaie = ($echec <= 0) || (($_maintenant - $echec) >= self::PERIODE_ESSAI);
        if ($texte !== $avant && $reessaie
            && ($urgent || ($_maintenant - $quand) >= self::PERIODE_VUE)) {
            if ($_ctx->publish($this->topicEtat($_mac), $texte, 0, true) === true) {
                $dossier['etat']      = $texte;
                $dossier['etatQuand'] = $_maintenant;
                $dossier['proche']    = $proche;
                if ($echec > 0) {
                    unset($dossier['etatEchec']);
                }
                $modifie = true;
            } else {
                $dossier['etatEchec'] = $_maintenant;
                $modifie = true;
            }
        }

        if ($modifie) {
            $this->range($_ctx, $cle, $dossier);
        }
        $this->emetBalise($_ctx, $_mac, $_reglages);
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
     *
     * LA FRAÎCHEUR D'UNE PASSERELLE SE JUGE AVEC FRAICHEUR_PROCHE, et jamais
     * avec le délai d'absence d'une BALISE : ce dernier se règle jusqu'à une
     * journée, pour un traceur qui n'émet que de loin en loin, alors qu'une
     * passerelle parle en permanence. Les confondre, c'était afficher « au
     * salon » pendant tout ce délai après avoir débranché la passerelle du
     * salon.
     */
    private function plusProche($_dossier, $_maintenant) {
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
            if (($_maintenant - $this->nombre($vue, 'vu', 0)) >= self::FRAICHEUR_PROCHE) {
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
     *   `certain` la passerelle l'a RECONNUE **et** la balise est chez nous.
     *             C'est un capteur de la maison, l'équipement est créé.
     *   `guess`   tout le reste : trame brute, ou capteur reconnu mais qui
     *             passait par là. Le modèle part quand même — sinon personne ne
     *             saurait jamais qu'il y a là quelque chose à adopter — mais il
     *             atterrit dans la file d'adoption.
     *
     * « DÉCODÉE » NE VEUT PAS DIRE « À MOI ».
     *
     * C'était pourtant la règle : un champ décodé, et l'équipement se créait
     * tout seul. Or Theengs décode les thermomètres du voisin aussi bien que
     * les miens. Deux cent soixante d'entre eux, vus une fois à −97 dBm à
     * travers deux murs, remplissaient l'inventaire et fabriquaient deux cent
     * cinquante et un équipements — après quoi la balise de la maison, faute de
     * place, n'était plus jamais découverte.
     *
     * On exige donc un FAISCEAU plutôt qu'un champ (voir faisceau()), et le
     * faisceau, une fois acquis, ne se perd plus.
     *
     * ET UNE ADRESSE QUI TOURNE NE MÉRITE AUCUN ÉQUIPEMENT.
     *
     * C'est la seconde condition, et elle ne doit rien au décodage. Un iPhone
     * posé sur la table est décodé (il annonce un format standard), il est chez
     * nous (−70 dBm) : décodée et faisceau, donc `certain`, donc un équipement.
     * Puis son adresse tourne, et un équipement de plus. Sur l'installation
     * réelle : cent quatre-vingt-dix en trois jours, tous vides, et le plafond
     * de découverte à deux doigts d'être atteint — après quoi plus RIEN
     * n'aurait été créé, pas même un Shelly.
     *
     * Une balise à adresse aléatoire attend donc d'avoir duré (voir
     * adresseStable). Ce n'est pas un refus, c'est un délai : le traceur, dont
     * l'adresse ne tourne pas, devient `certain` au bout d'une heure et se crée
     * tout seul. Le téléphone, lui, n'atteint jamais l'heure.
     *
     * `bleAdoptAll` fait passer toutes les balises en `certain` : c'est le sens
     * du réglage, pour qui veut vraiment tout.
     */
    public function confiance($_dossier, $_reglages) {
        if (!empty($_reglages['adoptAll'])) {
            return 'certain';
        }
        if (!empty($_dossier['decodee']) && !empty($_dossier['faisceau'])
            && $this->adresseStable($_dossier)) {
            return 'certain';
        }
        return 'guess';
    }

    /*
     * L'ADRESSE DE CETTE BALISE DÉSIGNERA-T-ELLE ENCORE QUELQUE CHOSE DEMAIN ?
     *
     * Publique, elle est gravée dans le matériel : oui, tout de suite. Aléatoire,
     * elle peut être figée — un traceur — ou tourner au quart d'heure — un
     * téléphone —, et rien dans la trame ne dit lequel. Seule la durée le dit :
     * ce qui a survécu à la rotation ne tournait pas.
     *
     * Inconnue (la passerelle n'a pas publié `mac_type`), elle est traitée comme
     * publique : c'est le comportement d'avant ce garde-fou, et refuser sur un
     * champ manquant priverait d'équipement les balises d'une passerelle plus
     * ancienne — un silence n'est pas un aveu.
     */
    private function adresseStable($_dossier) {
        if ($this->texte($_dossier, 'macType') !== 'random') {
            return true;
        }
        $duree = $this->nombre($_dossier, 'vu', 0) - $this->nombre($_dossier, 'depuis', 0);
        return $duree >= self::STABILITE_ALEATOIRE;
    }

    /*
     * LE FAISCEAU : cette balise décodée est-elle chez nous ?
     *
     * Deux indices, et l'un suffit — parce qu'ils ratrapent deux situations
     * différentes :
     *
     *   UN SIGNAL AU-DESSUS DU PLANCHER. Une balise de la maison, même à
     *   l'autre bout, se voit à −85 dBm ou mieux ; un capteur du voisinage, à
     *   travers deux murs, non. C'est l'indice immédiat : un thermomètre qu'on
     *   vient de déballer est reconnu à sa première trame.
     *
     *   OU BIEN LA DURÉE. Un signal peut être faible et la balise bien à nous —
     *   la sonde du jardin, le capteur du garage. Mais une balise qui est là
     *   depuis plusieurs minutes ET qu'on a vue plusieurs fois n'est pas
     *   passée dans la rue. Les deux ensemble, jamais l'un seul : un passant
     *   croisé trois fois en dix secondes n'habite pas ici, et une trame unique
     *   ne dure pas.
     *
     * Le dossier porte déjà `depuis` et `vu` ; `compte` s'y ajoute, borné.
     */
    private function faisceau($_dossier) {
        if ($this->meilleurRssi($_dossier) >= self::PLANCHER_RSSI) {
            return true;
        }
        $duree = $this->nombre($_dossier, 'vu', 0) - $this->nombre($_dossier, 'depuis', 0);
        return ($this->nombre($_dossier, 'compte', 0) >= self::VUES_MIN)
            && ($duree >= self::DUREE_MIN);
    }

    /* Le meilleur signal reçu, toutes passerelles confondues. Une balise vue
     * par trois passerelles est chez nous si l'une d'elles l'entend bien. */
    private function meilleurRssi($_dossier) {
        $meilleur = null;
        if (!isset($_dossier['gws']) || !is_array($_dossier['gws'])) {
            return -127.0;
        }
        foreach ($_dossier['gws'] as $vue) {
            if (!isset($vue['rssi']) || !is_numeric($vue['rssi'])) {
                continue;
            }
            $rssi = (float) $vue['rssi'];
            if ($meilleur === null || $rssi > $meilleur) {
                $meilleur = $rssi;
            }
        }
        return ($meilleur === null) ? -127.0 : $meilleur;
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
        /*
         * Deux préfixes pour une seule passerelle : l'identité étant la `mac`,
         * les deux décriraient le MÊME équipement avec des topics différents —
         * donc un modèle qui oscille et repart vers Jeedom sans fin. Un seul
         * préfixe est retenu (voir baseRetenue), l'autre se tait.
         *
         * Ce qui a déjà été émis est marqué de la base retenue AU MOMENT DE
         * L'ÉMISSION, et pas seulement de la signature : le jour où la
         * passerelle est renommée et où le préfixe retenu change, la marque ne
         * correspond plus, et le modèle repart avec les bons topics. Sans cela,
         * une passerelle qui avait été écartée une fois restait marquée « à
         * jour » et ne reprenait jamais la main — six capteurs lisant un topic
         * mort et des boutons publiant dans le vide, sans une ligne de journal.
         */
        $base      = $this->texte($dossier, 'base');
        $retenue   = $this->baseRetenue($_ctx, $this->texte($dossier, 'mac'));
        $signature = $this->texte($dossier, 'signature');
        $marque    = ($retenue === $base) ? $signature : ('passe|' . $retenue . '|' . $signature);
        if ($marque === $this->texte($dossier, 'emis')) {
            return false;
        }
        if ($retenue !== $base) {
            /* Une seule fois, au moment où ce préfixe perd la main : c'est
             * exactement la trace qui manquait quand un renommage tuait la
             * passerelle en silence. */
            if ($this->texte($dossier, 'emis') !== '') {
                $_ctx->log('info', 'OpenMQTTGateway : la passerelle ' . $this->texte($dossier, 'mac')
                    . ' publie désormais sous « ' . $this->citation($retenue) . ' » ; son ancien '
                    . 'préfixe « ' . $this->citation($base) . ' » ne décrit plus rien et se taira.');
            }
            $dossier['emis'] = $marque;
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
        $dossier['emis'] = $marque;
        $this->range($_ctx, 'gw:' . $_slug, $dossier);
        $_ctx->log('info', 'OpenMQTTGateway : passerelle ' . $modele->name() . ' ('
            . $modele->uid() . ') — ' . $modele->countChannels() . ' canaux.');
        return true;
    }

    private function emetBalise($_ctx, $_mac, $_reglages = array()) {
        $cle     = 'ble:' . $_mac;
        $dossier = $this->dossier($_ctx, $cle);
        if (empty($dossier)) {
            return false;
        }
        if ($this->texte($dossier, 'signature') === $this->texte($dossier, 'emis')) {
            return false;
        }
        /*
         * UNE ADRESSE ALÉATOIRE TROP JEUNE NE SORT PAS D'ICI.
         *
         * Une adresse BLE aléatoire tourne au quart d'heure : un seul téléphone
         * dans la maison produit quatre-vingt-seize identités par jour, toutes
         * distinctes, toutes inutiles — et la file, bornée, éjecte le traceur
         * que l'utilisateur avait repéré la veille sans avoir eu le temps de
         * l'adopter. On attend donc qu'une telle balise ait duré (voir
         * STABILITE_ALEATOIRE) : ce qui y a survécu ne tournait pas.
         *
         * LA GARDE NE FAIT PLUS D'EXCEPTION POUR LES BALISES `certain`. Elle en
         * faisait une — « elle a mérité son équipement, elle part tout de
         * suite » — et c'est par là que cent quatre-vingt-dix équipements vides
         * sont entrés : un iPhone décodé « iBeacon » et entendu à −70 dBm est
         * `certain` à sa première trame, puis son adresse tourne, et le
         * suivant l'est tout autant. Un modèle n'a rien à faire dehors tant que
         * son identité n'est pas acquise, quelle que soit la confiance.
         *
         * LA SEULE EXCEPTION EST `bleAdoptAll`. Décochée par défaut, elle dit
         * « crée tout ce que tu vois », éphémères compris ; la garder sous
         * cette garde-ci reviendrait à lui faire dire autre chose que son nom,
         * sans un mot pour l'expliquer.
         *
         * Rien n'est marqué comme émis : le modèle partira le jour où la balise
         * aura assez duré.
         */
        if (empty($_reglages['adoptAll']) && !$this->adresseStable($dossier)) {
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

    /*
     * LE PRÉFIXE RETENU POUR UNE `mac` DONNÉE : LE PLUS FRAIS.
     *
     * C'est la fraîcheur qui départage, et non la longueur. Renommer une
     * passerelle dans son interface web lui donne un préfixe neuf sans changer
     * sa `mac` ; l'ancien dossier, lui, restait en mémoire — et comme le nom
     * d'usine (« OMG_ESP32_BLE_1A2B ») est toujours plus court qu'un nom choisi
     * (« maison/salon/omg »), le mort l'emportait sur le vif à chaque
     * battement. Les capteurs lisaient un topic que plus personne n'alimentait,
     * les boutons publiaient dans le vide, et « relancer la découverte » ne
     * réparait rien puisque l'arbitrage était le même. Tout utilisateur qui
     * renomme sa passerelle était touché.
     *
     * La longueur ne tranche plus qu'entre préfixes ÉGALEMENT VIVANTS — à
     * FRAICHEUR_GW près. C'est le cas du parc réel, dont un préfixe est
     * dupliqué par une mauvaise saisie et où les deux publient pour de bon :
     * là, il faut un arbitre stable, sans quoi les deux se voleraient
     * l'équipement à chaque battement.
     *
     * Le résultat ne dépend ni de l'ordre des messages, ni du moment où le
     * démon a démarré.
     */
    private function baseRetenue($_ctx, $_mac) {
        if ($_mac === '') {
            return '';
        }
        $candidates = array();
        $fraicheur  = null;
        foreach ($this->passerelles($_ctx) as $slug) {
            $autre = $this->dossier($_ctx, 'gw:' . $slug);
            if (empty($autre) || $this->texte($autre, 'mac') !== $_mac) {
                continue;
            }
            $base = $this->texte($autre, 'base');
            if ($base === '') {
                continue;
            }
            $vu = $this->nombre($autre, 'vu', 0);
            if (!isset($candidates[$base]) || $vu > $candidates[$base]) {
                $candidates[$base] = $vu;
            }
            if ($fraicheur === null || $vu > $fraicheur) {
                $fraicheur = $vu;
            }
        }
        $retenue = '';
        foreach ($candidates as $base => $vu) {
            if ($fraicheur !== null && ($fraicheur - $vu) > self::FRAICHEUR_GW) {
                continue;
            }
            if ($retenue === ''
                || strlen($base) < strlen($retenue)
                || (strlen($base) === strlen($retenue) && strcmp($base, $retenue) < 0)) {
                $retenue = $base;
            }
        }
        return $retenue;
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
        /*
         * LA RADIO BLUETOOTH N'EST PAS UNE PRISE ÉLECTRIQUE.
         *
         * `switch.state` porte le type générique ENERGY_STATE et le gabarit
         * « core::prise » : sur le tableau de bord, la radio d'une passerelle
         * prenait l'apparence d'un interrupteur de courant, avec le geste qui
         * va avec. Une valeur générique numérique dit la même chose (1 ou 0)
         * sans promettre une lampe. Voir le compte rendu : une famille de
         * capacités « radio » (état + marche + arrêt, sans le gabarit de la
         * prise) serait le bon remède, et elle appartient à capabilities.json.
         */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.state', 'capability' => 'generic.numeric', 'name' => 'Bluetooth actif',
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

        /*
         * Les actions, telles que la passerelle les attend sur ses deux topics
         * de commande.
         *
         * `save:true` A ÉTÉ RETIRÉ, et c'est le plus important de ce fichier
         * après l'identité. Il écrit le réglage dans la mémoire persistante de
         * la passerelle : un clic sur « Couper le Bluetooth » arrêtait la
         * détection de présence de TOUTE LA MAISON — plus une balise, plus un
         * traceur, plus une indication de pièce — et l'écrivait pour toujours,
         * si bien qu'un redémarrage ne rattrapait rien. Sans lui, le pire d'un
         * clic malheureux dure jusqu'à la prochaine coupure de courant.
         *
         * Et le nom dit ce que le bouton arrête vraiment : « Bluetooth » ne
         * ressemble pas à une panne de domotique, « arrête la détection »,
         * si.
         */
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'restart', 'capability' => 'device.restart', 'name' => 'Redémarrer',
            'sink' => array('topic' => $base . '/' . self::CMDSYS, 'payload' => '{"cmd":"restart"}'),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.on', 'capability' => 'generic.action',
            'name' => 'Reprendre la détection Bluetooth',
            'sink' => array('topic' => $base . '/' . self::CMDBT, 'payload' => '{"enabled":true}'),
            'links' => array('state' => 'ble.state'),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key' => 'ble.off', 'capability' => 'generic.action',
            'name' => 'Arrêter la détection Bluetooth (toutes les balises)',
            'sink' => array('topic' => $base . '/' . self::CMDBT, 'payload' => '{"enabled":false}'),
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
            $table    = self::table();
            $unites   = self::unitesGeneriques();
            $presence = self::champsPresence();
            $champs   = array_keys($_dossier['champs']);
            foreach ($champs as $champ) {
                /* Un capteur qui publie les deux ne publie pas deux
                 * températures : c'est la même, dans deux unités. */
                if ($champ === 'tempf' && isset($_dossier['champs']['tempc'])) {
                    continue;
                }
                /* Et une balise n'a qu'UNE commande de présence : celle que le
                 * démon calcule, qui redescend quand la balise se tait. Voir
                 * champsPresence(). */
                if (isset($presence[$champ])) {
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
                     * plugin.
                     *
                     * Mais un NOMBRE arrive en valeur numérique, et non en
                     * chaîne : c'est la différence entre une donnée qu'on trace,
                     * qu'on compare et qu'on moyenne, et un texte qu'on ne peut
                     * que lire. Le type a été relevé sur la trame (voir
                     * recoitBalise) parce qu'un nom de champ ne le dit pas. */
                    $capacite = ($_dossier['champs'][$champ] === 'n')
                              ? 'generic.numeric' : 'generic.value';
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
        $this->purgeBalises($_ctx, $_maintenant);
        $this->purgePasserelles($_ctx, $_maintenant);
        $this->surveilleAnonymes($_ctx, $_maintenant);
    }

    /*
     * UNE PASSERELLE QUI PUBLIE SANS S'ÊTRE PRÉSENTÉE, ET QU'ON NE PEUT PAS
     * CRÉER — MAIS QU'ON NOMME.
     *
     * L'identité d'une passerelle est sa `mac`, et elle n'arrive que par
     * SYStoMQTT : sans lui, aucun équipement ne peut être créé, et c'est un
     * choix assumé — mieux vaut une passerelle absente qu'un fantôme qui
     * ressuscitera sous un autre nom à chaque redémarrage (voir recoitSys).
     *
     * Mais se taire là-dessus était une faute. Une passerelle qui publie ses
     * trames Bluetooth depuis des heures sans s'être jamais présentée est
     * exactement le cas où l'utilisateur voit ses balises remonter, cherche
     * sa passerelle dans la liste, ne l'y trouve pas, et n'a RIEN pour
     * comprendre : le journal du plugin est muet, la découverte marche, et
     * pourtant il manque un équipement. C'est arrivé sur un Shelly qui
     * émulait une passerelle par script : le script ne publiait son identité
     * qu'au démarrage, et y renonçait sans bruit quand MQTT n'était pas
     * encore connecté. Deux appareils sur trois s'étaient présentés, le
     * troisième jamais — même script, ordre de démarrage différent.
     *
     * Le contrôle ne tourne qu'une fois par minute, à la purge, et jamais
     * avant que le démon n'ait eu le temps de recevoir les annonces retenues
     * que le broker rejoue à l'abonnement : s'en plaindre au démarrage serait
     * crier au loup à chaque redémarrage. Une plainte par heure et par
     * préfixe, comme les autres.
     */
    private function surveilleAnonymes($_ctx, $_maintenant) {
        $depart = $_ctx->recall(self::ID . ':depart');
        if (!is_numeric($depart)) {
            $_ctx->remember(self::ID . ':depart', $_maintenant);
            return;
        }
        if (($_maintenant - (float) $depart) < self::DELAI_ANONYME) {
            return;
        }
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (empty($dossier['gws']) || !is_array($dossier['gws'])) {
                continue;
            }
            foreach ($dossier['gws'] as $slug => $vue) {
                /* Seulement ce qui publie ENCORE : un préfixe qui s'est taché
                 * il y a deux heures n'appelle pas d'explication, il a
                 * disparu. */
                if (($_maintenant - $this->nombre($vue, 'vu', 0)) > self::FRAICHEUR_GW) {
                    continue;
                }
                $gw = $this->dossier($_ctx, 'gw:' . $slug);
                if (!empty($gw)) {
                    continue;
                }
                $base = isset($vue['topic']) ? self::baseDepuisTopic((string) $vue['topic']) : '';
                if ($base === '') {
                    continue;
                }
                /*
                 * CE QUE LE MESSAGE PEUT DIRE, ET CE QU'IL NE PEUT PAS.
                 *
                 * Il ne dit pas « aucun équipement n'existe » : cet adapter ne
                 * connaît pas Jeedom et ne sait rien de ce qui est en base — la
                 * passerelle a très bien pu être découverte hier, quand son
                 * annonce passait. Il dit ce qu'il sait : depuis que ce démon
                 * tourne, ce préfixe n'a pas dit qui il était.
                 */
                $this->plainte($_ctx, 'anon:' . $slug,
                    'la passerelle « ' . $this->citation($base) . ' » publie des trames Bluetooth '
                    . 'mais n\'a pas publié son identité sur « '
                    . $this->citation($base . '/' . self::SYS) . ' » depuis le démarrage du démon : '
                    . 'tant qu\'elle ne l\'aura pas fait, son équipement de passerelle ne peut être '
                    . 'ni créé ni mis à jour. Ses mesures, elles, remontent normalement. Une '
                    . 'passerelle OpenMQTTGateway republie ce message périodiquement ; un appareil '
                    . 'qui ne l\'envoie qu\'à son démarrage l\'aura fait avant d\'être connecté au '
                    . 'broker.');
            }
        }
    }

    /* La base d'un topic de trame : ce qui précède « /BTtoMQTT/ ». */
    private static function baseDepuisTopic($_topic) {
        $marque = '/' . self::BT . '/';
        $pos = strpos($_topic, $marque);
        return ($pos === false || $pos === 0) ? '' : substr($_topic, 0, $pos);
    }

    /*
     * ET LES PASSERELLES SE PÉRIMENT COMME LES BALISES.
     *
     * Rien ne les périmait : un préfixe abandonné — la passerelle renommée, le
     * module remplacé, la mauvaise saisie corrigée — restait en mémoire pour
     * toujours et continuait de peser dans l'arbitrage (voir baseRetenue) comme
     * dans le plafond de MAX_PASSERELLES. Un dossier de passerelle qui n'a pas
     * reçu de SYStoMQTT depuis un jour ne décrit plus rien.
     */
    private function purgePasserelles($_ctx, $_maintenant) {
        $restantes = array();
        $oubliees  = array();
        foreach ($this->passerelles($_ctx) as $slug) {
            $dossier = $this->dossier($_ctx, 'gw:' . $slug);
            if (empty($dossier)) {
                continue;
            }
            if (($_maintenant - $this->nombre($dossier, 'vu', 0)) >= self::PEREMPTION_GW) {
                $_ctx->forget(self::ID . ':gw:' . $slug);
                $oubliees[] = $this->citation($this->texte($dossier, 'base'));
                continue;
            }
            $restantes[] = $slug;
        }
        if (!empty($oubliees)) {
            $_ctx->remember(self::ID . ':index.gw', $restantes);
            $_ctx->log('info', 'OpenMQTTGateway : préfixe(s) sans SYStoMQTT depuis plus d\'un '
                . 'jour, oublié(s) : ' . implode(', ', $oubliees) . '.');
        }
    }

    /*
     * Une balise vue une fois puis plus jamais n'encombre pas la file : elle se
     * périme au bout de trois heures. UNE BALISE DÉCODÉE SE PÉRIME AUSSI, au
     * bout de vingt-quatre heures.
     *
     * Elle ne se périmait jamais, au motif que le silence d'un capteur est
     * justement ce que son équipement rapporte. C'est vrai du capteur de la
     * maison — et faux des deux cent soixante thermomètres du voisinage que
     * Theengs décode tout aussi bien : ceux-là remplissaient l'inventaire pour
     * l'éternité, et la balise de la maison n'y trouvait plus de place. Un jour
     * de silence est généreux pour un capteur qui parle toutes les minutes, et
     * son équipement, lui, reste dans Jeedom avec sa dernière valeur : il
     * repartira au premier message.
     */
    private function purgeBalises($_ctx, $_maintenant) {
        $restantes = array();
        $oubliees  = 0;
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (empty($dossier)) {
                continue;
            }
            $delai = empty($dossier['decodee']) ? self::PEREMPTION : self::PEREMPTION_DECODEE;
            if (($_maintenant - $this->nombre($dossier, 'vu', 0)) >= $delai) {
                $this->oublie($_ctx, $mac, $dossier);
                $oubliees++;
                continue;
            }
            $restantes[] = $mac;
        }
        if ($oubliees > 0) {
            $_ctx->remember(self::ID . ':index.ble', $restantes);
            $_ctx->log('debug', 'OpenMQTTGateway : ' . $oubliees
                . ' balise(s) silencieuse(s) oubliée(s), ' . count($restantes) . ' suivie(s).');
        }
    }

    /*
     * Le plafond atteint et rien à périmer : une balise cède la place.
     *
     * L'ordre du sacrifice compte, et il ne peut pas être « la plus ancienne
     * jamais décodée ». Les capteurs décodés du voisinage, vus une fois chacun,
     * étaient jusqu'ici inévinçables : deux cent cinquante d'entre eux
     * verrouillaient l'inventaire, et la balise de la maison, arrivée après,
     * était refusée — définitivement, puisque rien ne libérait jamais de place.
     *
     * On évince donc d'abord ce qui n'a pas fait ses preuves (pas de faisceau,
     * voir confiance()), et la plus ancienne d'entre elles ; une balise qui a
     * mérité son équipement n'est sacrifiée qu'en dernier recours, quand il n'y
     * a plus qu'elles. Ce qui passe maintenant intéresse plus que ce qui est
     * passé hier.
     */
    private function evince($_ctx) {
        $plusVieille = null;
        $quand = null;
        $rang  = null;
        foreach ($this->balises($_ctx) as $mac) {
            $dossier = $this->dossier($_ctx, 'ble:' . $mac);
            if (empty($dossier)) {
                continue;
            }
            $sonRang = empty($dossier['faisceau']) ? 0 : 1;
            $vu = $this->nombre($dossier, 'vu', 0);
            if ($rang === null || $sonRang < $rang || ($sonRang === $rang && $vu < $quand)) {
                $rang  = $sonRang;
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
    private function base($_ctx, $_niveaux, $_jusqua, $_topic) {
        if ($_jusqua < 1) {
            return '';
        }
        $base = array();
        for ($i = 0; $i < $_jusqua; $i++) {
            if (!preg_match(self::NIVEAU_VALIDE, $_niveaux[$i]) || trim($_niveaux[$i]) === '') {
                /* ET ON LE DIT. Une passerelle écartée en silence est une
                 * enquête sans issue : l'utilisateur voit son parc incomplet,
                 * le journal du plugin est muet, et rien ne désigne le préfixe
                 * fautif. Une plainte par heure suffit à le nommer sans noyer
                 * le journal sous le trafic voisin. */
                $this->plainte($_ctx, 'topic', 'préfixe de topic écarté : « '
                    . $this->citation($_topic) . ' ». Un niveau contient un joker ou un '
                    . 'caractère qui ne peut pas figurer dans un topic de publication — les '
                    . 'actions de cette passerelle partiraient sur un topic que le broker '
                    . 'refuse. Les lettres, chiffres, espaces, « . _ : - » sont acceptés.');
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
