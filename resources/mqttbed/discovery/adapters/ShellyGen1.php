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
     * lui qui fait foi (require_once ci-dessus) et ce bloc ne s'exécute jamais.
     * Sans lui, un dépôt où le moteur n'est pas encore écrit ne pourrait ni
     * charger ni contrôler cet adapter : l'erreur serait fatale, à l'inclusion,
     * et sans rapport avec Shelly. */
    interface MqttbeAdapter {
        public function id();
        public function priority();
        public function subscriptions();
        public function onMessage($_topic, $_payload, $_retained, $_ctx);
        public function onTick($_ctx);
    }
}

/* =============================================================================
 * Découverte des appareils Shelly de première génération.
 *
 * Deux messages suffisent, et il en faut deux :
 *
 *   shellies/announce        l'identité  — id, modèle, MAC, IP, micrologiciel ;
 *   shellies/<id>/info       les capacités — relais, compteurs, entrées, sondes.
 *
 * L'annonce dit QUI, le info dit QUOI. Fabriquer un modèle sur la seule annonce
 * reviendrait à déduire les commandes du code constructeur, donc d'un catalogue
 * écrit à la main : un SHSW-1 avec deux sondes DS18B20 et un SHSW-1 nu portent
 * le même code, et le catalogue se tromperait sur l'un des deux. Le info, lui,
 * décrit l'appareil tel qu'il est branché aujourd'hui.
 *
 * La découverte est ACTIVE : au démarrage, l'adapter publie `announce` sur
 * `shellies/command`, et tout le parc se re-présente dans la seconde. Sans
 * cela, un appareil connecté depuis des mois — c'est-à-dire tous, sur une
 * installation existante — resterait invisible : son annonce initiale est
 * passée bien avant que le démon n'existe, et elle n'est pas retenue.
 *
 * Aucune référence à Jeedom dans ce fichier : il est chargé par le démon, qui
 * tourne sans core.inc.php. Les décisions de présentation (type de commande,
 * type générique, unité) ne sont pas prises ici non plus : un canal désigne une
 * capacité, et core/config/capabilities.json traduit.
 * ========================================================================== */
class MqttbeShellyGen1 implements MqttbeAdapter {

    const ID       = 'shelly.gen1';
    /* 100 = découverte native du constructeur. Un même appareil vu à la fois
     * par cet adapter et par un adapter générique (Home Assistant discovery)
     * doit être décrit par celui qui parle son protocole d'origine. */
    const PRIORITY = 100;

    const RACINE = 'shellies';

    /* Délai au bout duquel une annonce sans info donne quand même un modèle,
     * en `probable`. 20 s : le info suit l'annonce à quelques dizaines de
     * millisecondes quand tout va bien ; passé ce délai, c'est que l'appareil
     * ne le publiera pas (firmware ancien, publication désactivée). Un
     * équipement à moitié connu — nom, modèle, disponibilité — vaut mieux qu'un
     * appareil absent de la liste, à condition que sa confiance le dise. */
    const DELAI_INFO = 20;

    /* Demandes d'annonce, en secondes depuis le démarrage de l'adapter. Trois
     * plutôt qu'une : la première part parfois avant que les abonnements ne
     * soient réellement établis côté broker, et les réponses se perdent alors
     * sans que rien ne le signale. Le coût d'une demande est d'un message
     * publié et d'un lot de messages retenus — négligeable, et borné. */
    const RELANCES = array(0, 10, 60);

    /* Une énergie Gen1 n'est jamais en kWh.
     *   relay/<i>/energy et meters[].total : watt-minutes ;
     *   emeter/<i>/total, total_returned   : watt-heures.
     * Sans ces facteurs, la commande « Consommation » affiche 87759 avec pour
     * unité kWh : l'utilisateur lit trois ordres de grandeur de trop et en
     * conclut que le plugin est faux — ce en quoi il a raison. */
    const WATTMINUTE_VERS_KWH = 0.000016666666666666667; /* 1/60000 */
    const WATTHEURE_VERS_KWH  = 0.001;

    /* Trois décimales : 1 Wh près sur un compteur domestique, et une valeur qui
     * tient dans un graphique sans traîner quatorze chiffres de flottant. */
    const DECIMALES_ENERGIE = 3;

    /*
     * L'état d'un relais, tel que le firmware l'écrit sur `relay/<i>`.
     *
     * `overpower` n'est pas un troisième état : c'est ce que l'appareil publie
     * à l'instant où il COUPE la sortie pour surcharge. La sortie est donc
     * ouverte, et l'état binaire vaut 0. Sans cette troisième entrée, la
     * commande reçoit la chaîne « overpower » exactement au moment qui compte —
     * un disjonctage — et Jeedom en fait ce qu'il peut, c'est-à-dire n'importe
     * quoi.
     */
    const ETAT_RELAIS = array('on' => '1', 'off' => '0', 'overpower' => '0');

    /*
     * La forme d'un identifiant d'appareil acceptable.
     *
     * L'identifiant vient du réseau — le champ `id` d'une charge utile publiée
     * par n'importe qui sur `shellies/announce` — et il compose ensuite TOUS
     * les topics du modèle, en lecture comme en écriture. Une annonce
     * `{"id":"+"}` donnerait `shellies/+/relay/0` en source, filtre parfaitement
     * légal qui déverserait l'état de tout le parc sur un équipement fantôme,
     * et `shellies/+/relay/0/command` en destination, ce que MQTT 3.1.1 §3.3.2
     * interdit dans un nom de topic de publication : le broker fermerait la
     * connexion à chaque appui sur le bouton.
     *
     * Les identifiants Shelly légitimes — « shelly1pm-D8BFC01A0805 » — tiennent
     * très largement dans cette forme.
     */
    const ID_VALIDE = '/^[A-Za-z0-9._-]{1,64}$/';

    /* Noms commerciaux, chargés une fois. Le tableau vide et le « pas encore
     * lu » ne se confondent pas : sans cette distinction, un fichier illisible
     * serait relu à chaque message. */
    private $catalogue = null;
    private $cheminCatalogue;

    /**
     * @param string|null $_catalogue chemin du catalogue des noms commerciaux ;
     *        null = celui du plugin. Le paramètre existe pour les contrôles
     *        hors ligne, qui ne connaissent pas l'arborescence installée.
     */
    public function __construct($_catalogue = null) {
        $this->cheminCatalogue = ($_catalogue === null)
            ? __DIR__ . '/../../../../core/config/catalog/shelly-gen1.json'
            : $_catalogue;
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

    /*
     * Deux familles de topics, et rien d'autre.
     *
     * `shellies/announce` est l'endroit où répond une demande générale ;
     * `shellies/<id>/announce` celui où un appareil se présente en arrivant.
     * Les deux portent la même charge utile, et un parc réel se sert des deux.
     *
     * Les topics d'exploitation (relay/0, temperature, emeter/…) ne sont
     * délibérément pas écoutés ici : ils sont souscrits par la table de
     * routage, une fois les commandes créées. Les écouter deux fois ferait
     * transiter tout le trafic du parc par la découverte pour rien.
     */
    public function subscriptions() {
        return array(
            self::RACINE . '/announce',
            self::RACINE . '/+/announce',
            self::RACINE . '/+/info',
        );
    }

    public function onMessage($_topic, $_payload, $_retained, $_ctx) {
        $parties = explode('/', (string) $_topic);
        if (count($parties) < 2 || $parties[0] !== self::RACINE) {
            return;
        }

        if (count($parties) === 2 && $parties[1] === 'announce') {
            $this->recoitAnnonce($_ctx, '', $_payload);
            return;
        }
        if (count($parties) === 3 && $parties[2] === 'announce') {
            $this->recoitAnnonce($_ctx, $parties[1], $_payload);
            return;
        }
        if (count($parties) === 3 && $parties[2] === 'info') {
            $this->recoitInfo($_ctx, $parties[1], $_payload);
        }
    }

    /*
     * Relances et expirations. Appelé au plus une fois par seconde par le
     * moteur : tout ce qui est écrit ici doit rester sans effet quand il n'y a
     * rien à faire.
     */
    public function onTick($_ctx) {
        $maintenant = $this->maintenant($_ctx);

        $horloge = $this->lit($_ctx, 'horloge');
        if (!isset($horloge['depart'])) {
            $horloge = array('depart' => $maintenant, 'relances' => 0);
        }

        /* Relance demandée par Jeedom (bouton « relancer la découverte »).
         * Le moteur la présente par $ctx->rescan(), vrai le temps d'un onTick ;
         * une clé de mémoire fait le même office pour qui appelle l'adapter
         * sans le moteur. Les deux chemins mènent au même geste : redemander
         * une annonce, puisque c'est la seule chose qui fasse reparler un parc
         * déjà connecté. */
        $relanceDemandee = method_exists($_ctx, 'rescan') && $_ctx->rescan();
        if ($_ctx->recall(self::ID . ':rescan')) {
            $_ctx->forget(self::ID . ':rescan');
            $relanceDemandee = true;
        }
        if ($relanceDemandee) {
            if ($this->demandeAnnonce($_ctx, 'relance demandée')) {
                /* Et l'on oublie ce qui a déjà été émis. Le moteur dédoublonne
                 * lui-même par empreinte (voir Adapter.php : « émettre deux
                 * fois de suite un modèle identique ne coûte rien »), si bien
                 * que ce souvenir-ci n'économise rien et empêche tout : un
                 * équipement supprimé par erreur dans Jeedom ne reviendrait
                 * jamais, puisque l'adapter tiendrait son modèle pour déjà
                 * remis. Or « relancer la découverte » ne veut rien dire
                 * d'autre que « redis-moi tout ». */
                $this->oublieEmissions($_ctx);
            } else {
                /* Le broker n'est pas là : la demande n'est pas partie, et le
                 * bouton de l'utilisateur ne doit pas rester sans effet. On la
                 * réarme pour le tour suivant. */
                $_ctx->remember(self::ID . ':rescan', true);
            }
        }

        $relances = (int) (isset($horloge['relances']) ? $horloge['relances'] : 0);
        while ($relances < count(self::RELANCES)
               && ($maintenant - $horloge['depart']) >= self::RELANCES[$relances]) {
            if (!$this->demandeAnnonce($_ctx, 'demande ' . ($relances + 1) . '/' . count(self::RELANCES))) {
                /* Rien n'est parti : le compteur ne bouge pas, et la même
                 * demande sera reprise au battement suivant. C'est le cas
                 * ordinaire après une coupure de courant — la box et le broker
                 * redémarrent ensemble, et le démon tourne avant d'avoir sa
                 * liaison. Compter ces demandes-là comme faites consommerait à
                 * vide les trois relances, et le parc resterait invisible
                 * jusqu'au prochain redémarrage du démon. */
                break;
            }
            $relances++;
        }
        $horloge['relances'] = $relances;
        $this->ecrit($_ctx, 'horloge', $horloge);

        /* Expiration : une annonce restée seule assez longtemps donne un modèle
         * `probable`. Il porte le nom, le modèle et la disponibilité — de quoi
         * exister dans Jeedom — et sera complété sans doublon le jour où le
         * info arrive, puisque l'uid, lui, ne dépend que de la MAC. */
        foreach ($this->inventaire($_ctx) as $identifiant) {
            $dossier = $this->dossier($_ctx, $identifiant);
            if (empty($dossier['announce']) || !empty($dossier['info'])) {
                continue;
            }
            if (($maintenant - $this->nombre($dossier, 'vu')) < self::DELAI_INFO) {
                continue;
            }
            $this->emet($_ctx, $dossier);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Découverte active                                                     */
    /* --------------------------------------------------------------------- */

    /*
     * Publier `announce` sur `shellies/command` : toute la génération 1 y
     * répond, par son annonce puis par son info. C'est la seule façon de voir
     * un appareil déjà connecté — donc, sur une installation existante, la
     * seule façon de voir quoi que ce soit.
     */
    public function demandeAnnonce($_ctx, $_motif = '') {
        /* Le retour de publish() n'est pas décoratif : il vaut false tant que
         * la liaison au broker n'est pas établie. Journaliser « annonce
         * demandée » sans l'avoir vérifié produit un journal qui affirme
         * exactement le contraire de ce qui s'est passé, et c'est alors la
         * seule trace dont dispose celui qui cherche pourquoi il ne voit
         * aucun appareil. */
        if ($_ctx->publish(self::RACINE . '/command', 'announce') !== true) {
            $_ctx->log('warning', 'Shelly Gen1 : demande d\'annonce non partie, broker injoignable'
                . ($_motif === '' ? '' : ' (' . $_motif . ')') . ' — reprise au prochain tour.');
            return false;
        }
        $_ctx->log('info', 'Shelly Gen1 : annonce demandée à tout le parc'
            . ($_motif === '' ? '' : ' (' . $_motif . ')') . '.');
        return true;
    }

    /*
     * Oublier ce qui a été émis, pour tout le parc : la prochaine annonce
     * reproduira les modèles. Voir onTick() pour le pourquoi.
     */
    private function oublieEmissions($_ctx) {
        foreach ($this->inventaire($_ctx) as $identifiant) {
            $dossier = $this->dossier($_ctx, $identifiant);
            if (!isset($dossier['emis'])) {
                continue;
            }
            unset($dossier['emis']);
            $this->range($_ctx, $dossier);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Réception                                                             */
    /* --------------------------------------------------------------------- */

    private function recoitAnnonce($_ctx, $_identifiantTopic, $_payload) {
        $annonce = $this->json($_ctx, $_payload, 'announce');
        if ($annonce === null) {
            return;
        }
        $identifiant = $this->texte($annonce, 'id');
        if ($identifiant === '') {
            $identifiant = (string) $_identifiantTopic;
        }
        if ($identifiant === '') {
            $_ctx->log('warning', 'Shelly Gen1 : annonce sans identifiant, ignorée.');
            return;
        }
        if (!preg_match(self::ID_VALIDE, $identifiant)) {
            /* Voir ID_VALIDE : cet identifiant deviendrait la racine des topics
             * de l'équipement, en lecture comme en écriture. */
            $_ctx->log('warning', 'Shelly Gen1 : identifiant « ' . $this->citation($identifiant)
                . ' » refusé — il compose les topics de l\'équipement, et tout ce qui n\'est pas '
                . 'lettre, chiffre, point, tiret ou souligné y ferait un abonnement ou une '
                . 'publication que personne n\'a voulus. Annonce ignorée.');
            return;
        }
        $mac = $this->macDe($annonce, $identifiant);
        if ($mac === '') {
            /* L'uid se dérive de la MAC, et de rien d'autre : c'est ce qui fait
             * qu'un appareil retrouvé par l'adapter Gen2+ ou après un
             * changement d'IP reste le même équipement. Sans MAC, adopter
             * l'appareil reviendrait à créer un équipement neuf à chaque
             * découverte. */
            $_ctx->log('warning', 'Shelly Gen1 : annonce de « ' . $identifiant
                . ' » sans adresse MAC exploitable, ignorée.');
            return;
        }

        $dossier = $this->dossier($_ctx, $identifiant);
        $dossier['id'] = $identifiant;
        $dossier['announce'] = array(
            'model'  => $this->texte($annonce, 'model'),
            'mac'    => $mac,
            'ip'     => $this->texte($annonce, 'ip'),
            'fw_ver' => $this->texte($annonce, 'fw_ver'),
        );
        $dossier['vu'] = $this->maintenant($_ctx);
        $this->range($_ctx, $dossier);

        /* Pas d'attente si le info est déjà là : sur un redémarrage du démon,
         * le info retenu arrive souvent avant l'annonce. */
        if (!empty($dossier['info'])) {
            $this->emet($_ctx, $dossier);
        }
    }

    private function recoitInfo($_ctx, $_identifiant, $_payload) {
        $info = $this->json($_ctx, $_payload, 'info');
        if ($info === null || $_identifiant === '') {
            return;
        }
        /* Même contrôle que sur l'annonce, et pour la même raison. Ici
         * l'identifiant vient d'un niveau du topic reçu — un broker conforme ne
         * délivre pas de joker à cet endroit — mais un dossier ouvert sous un
         * identifiant biscornu consommerait une clé de mémoire sans jamais
         * pouvoir produire d'équipement, l'annonce, elle, étant refusée. */
        if (!preg_match(self::ID_VALIDE, (string) $_identifiant)) {
            $_ctx->log('debug', 'Shelly Gen1 : info reçu sous un identifiant refusé « '
                . $this->citation($_identifiant) . ' », ignoré.');
            return;
        }
        $dossier = $this->dossier($_ctx, $_identifiant);
        $dossier['id']   = $_identifiant;
        $dossier['info'] = $info;
        $dossier['vu']   = $this->maintenant($_ctx);
        $this->range($_ctx, $dossier);

        /* Un info seul ne suffit pas : il ne dit ni le modèle, ni l'IP, ni le
         * micrologiciel. Il est gardé, et l'annonce — provoquée au démarrage —
         * le complétera. */
        if (!empty($dossier['announce'])) {
            $this->emet($_ctx, $dossier);
        }
    }

    /*
     * Émission, si et seulement si quelque chose a changé.
     *
     * Les appareils republient leur info à chaque demande d'annonce, et les
     * messages retenus sont rejoués à chaque démarrage du démon : émettre sans
     * comparer ferait réécrire tout le parc en base plusieurs fois par jour.
     * L'empreinte du modèle ne couvre ni l'IP, ni le micrologiciel, ni la
     * confiance — d'où la confiance suivie à part : elle ne change qu'une fois,
     * quand un `probable` devient `certain`, et ce passage doit se voir.
     */
    private function emet($_ctx, $_dossier) {
        $modele = $this->construit($_ctx, $_dossier);
        if ($modele === null) {
            return false;
        }
        /* Même raison que dans le moteur : un nouveau bail DHCP doit franchir ce
         * cache, sans quoi l'adresse retenue par Jeedom serait celle du jour de
         * la découverte, pour toujours. */
        $empreinte = $modele->fingerprint() . '|' . $modele->volatileFingerprint();
        $emis = isset($_dossier['emis']) && is_array($_dossier['emis']) ? $_dossier['emis'] : array();
        if (isset($emis['fingerprint']) && $emis['fingerprint'] === $empreinte
            && isset($emis['confidence']) && $emis['confidence'] === $modele->confidence()) {
            return false;
        }

        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $_ctx->log('warning', 'Shelly Gen1 : modèle refusé pour « ' . $modele->uid() . ' » — '
                . implode(' ', $fautes));
            return false;
        }

        $_ctx->emit($modele);
        $_dossier['emis'] = array('fingerprint' => $empreinte, 'confidence' => $modele->confidence());
        $this->range($_ctx, $_dossier);
        $_ctx->log('info', 'Shelly Gen1 : ' . $modele->name() . ' (' . $modele->uid() . ') — '
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
        if (empty($_dossier['announce']) || !is_array($_dossier['announce'])) {
            return null;
        }
        $annonce     = $_dossier['announce'];
        $identifiant = $this->texte($_dossier, 'id');
        $info        = (isset($_dossier['info']) && is_array($_dossier['info'])) ? $_dossier['info'] : null;
        if ($identifiant === '') {
            return null;
        }
        $mac  = $this->texte($annonce, 'mac');
        $base = self::RACINE . '/' . $identifiant;
        $code = $this->texte($annonce, 'model');
        $ip   = $this->texte($annonce, 'ip');

        $modele = new MqttbeDeviceModel(array(
            'identity' => array(
                'adapter' => self::ID,
                /* Même forme d'uid que l'adapter Gen2+ : sur un parc mixte, un
                 * appareil migré ou vu par les deux adapters reste un seul
                 * équipement. La MAC en minuscules, toujours, sinon deux
                 * orthographes donneraient deux équipements. */
                'uid'     => 'shelly:' . strtolower($mac),
                'aliases' => array('mac:' . strtolower($mac), 'topic:' . $base),
                'confidence' => ($info === null) ? 'probable' : 'certain',
            ),
            'meta' => array(
                'name'         => $this->nomLisible($code, $mac),
                'manufacturer' => 'Shelly',
                'model'        => $code,
                'model_name'   => $this->nomCommercial($code),
                'generation'   => 1,
                'firmware'     => $this->texte($annonce, 'fw_ver'),
                'ip'           => $ip,
                /* La page de l'appareil, à un clic depuis Jeedom : c'est là que
                 * se règlent le mode d'entrée et les sondes externes. */
                'config_url'   => ($ip === '') ? '' : 'http://' . $ip . '/',
                'battery_powered' => $this->surPile($code),
                /*
                 * LE NOM QUE L'UTILISATEUR A DONNÉ À SON APPAREIL.
                 *
                 * La génération 1 ne le publie nulle part sur MQTT : ni
                 * l'annonce, ni le info ne le portent. Il n'existe que dans
                 * `/settings`, sur l'appareil lui-même — « Shelly 1 55670C »
                 * d'un côté, « chaudiere » de l'autre, et c'est le second qui
                 * dit à quoi sert l'équipement.
                 *
                 * Ce que l'adapter déclare ici, c'est le MOYEN de l'obtenir, et
                 * rien de plus : il ne fait aucune requête, ne connaît ni le
                 * délai d'expiration, ni le nombre de tentatives, ni ce qu'il
                 * advient quand l'appareil est éteint. Le moteur exécute une
                 * sonde qu'il ne comprend pas ; l'adapter la décrit sans savoir
                 * comment elle sera exécutée.
                 *
                 * Sans adresse IP, pas de sonde : une annonce sans `ip` existe
                 * (elle arrive d'un appareil que le broker a gardée en message
                 * retenu, avec une adresse périmée qu'il n'a pas republiée), et
                 * une adresse inventée ferait frapper à la porte d'un voisin.
                 *
                 * Un jour de validité : un nom ne change qu'au moment où
                 * quelqu'un renomme son appareil, et le bouton « relancer la
                 * découverte » redemande tout de suite.
                 */
                'probe'        => ($ip === '') ? array() : array(
                    'type' => 'http.json',
                    'url'  => 'http://' . $ip . '/settings',
                    'path' => 'name',
                    'ttl'  => 86400,
                ),
            ),
            /* `online` est retenu et publié par testament : c'est lui, et non
             * l'absence de messages, qui dit qu'un appareil a disparu. */
            'availability' => array(
                'topic'       => $base . '/online',
                'payload_on'  => 'true',
                'payload_off' => 'false',
            ),
        ));

        /* La disponibilité en commande visible, en plus du bloc `availability` :
         * l'un pilote l'équipement, l'autre se lit sur le tableau de bord. Ce
         * canal est le seul que porte un modèle `probable` — sans lui, un
         * appareil sans info serait un équipement sans une seule commande,
         * donc un modèle refusé. */
        $modele->addChannel(new MqttbeChannel(array(
            'key'        => 'online',
            'capability' => 'connectivity.online',
            'name'       => 'Connecté',
            'source'     => array('topic' => $base . '/online'),
            'value'      => array('transform' => array('map' => array('true' => '1', 'false' => '0'))),
        )));

        if ($info === null) {
            return $modele;
        }

        /*
         * Quatre familles, et une seule à la fois.
         *
         * Un Shelly 2.5 en mode volet publie encore `relays[]` et `meters[]`
         * dans son info — le matériel n'a pas changé — mais plus une seule
         * valeur sur `relay/<i>` : tout passe par `roller/0`. Lire `relays`
         * sans regarder `rollers` donne donc deux interrupteurs qui ne
         * commandent rien et deux mesures qui ne remontent jamais. De même, la
         * puissance d'un variateur est sous `light/<i>/power`, et un capteur
         * sur pile ne publie jamais `temperature` mais `sensor/temperature`.
         *
         * D'où cet aiguillage, qui est la seule chose que l'info permette de
         * trancher avec certitude — et non le code du modèle, qui ne dit pas
         * dans quel mode l'appareil est configuré aujourd'hui.
         */
        $relais    = $this->indices($info, 'relays');
        $volets    = $this->indices($info, 'rollers');
        $lumieres  = $this->indices($info, 'lights');
        $surPile   = $this->estCapteurSurPile($info, $code);

        if ($surPile) {
            $this->ajouteCapteurs($modele, $base, $info);
        } elseif (!empty($volets)) {
            $this->ajouteVolets($modele, $base, $info, $volets);
        } elseif (!empty($lumieres)) {
            $this->ajouteLumieres($modele, $base, $info, $lumieres, $code);
        } else {
            $this->ajouteRelais($modele, $base, $relais);
            $this->ajouteCompteurs($_ctx, $modele, $base, $info, $relais);
        }

        if (!$surPile) {
            $this->ajouteEmeters($modele, $base, $info);
            $this->ajouteTemperature($modele, $base, $info);
            $this->ajouteSondes($modele, $base, $info);
        }

        /* L'appui long n'existe que là où il y a quelque chose à commander :
         * voir ajouteEntrees(). */
        $actionneurs = !empty($relais) || !empty($volets) || !empty($lumieres);
        $this->ajouteEntrees($modele, $base, $info, $actionneurs && !$surPile);

        return $modele;
    }

    /* Relais : l'état, et les trois actions qui le pilotent. */
    private function ajouteRelais($_modele, $_base, $_indices) {
        $plusieurs = count($_indices) > 1;
        foreach ($_indices as $i) {
            /* Le numéro n'apparaît dans le nom que s'il y a de quoi confondre :
             * « État » sur un Shelly 1, « État 1 » et « État 2 » sur un 2.5.
             * Le nombre de relais est une propriété du matériel, il ne changera
             * pas sous l'équipement — le nom reste donc stable. */
            $suffixe = $plusieurs ? ' ' . ($i + 1) : '';
            $cleEtat = 'relay.' . $i . '.state';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleEtat,
                'capability' => 'switch.state',
                'name'       => 'État' . $suffixe,
                'source'     => array('topic' => $_base . '/relay/' . $i),
                /* Trois entrées et non deux : voir ETAT_RELAIS. */
                'value'      => array('transform' => array('map' => self::ETAT_RELAIS)),
            )));
            $actions = array(
                'on'     => array('switch.on',     'Allumer',  'on'),
                'off'    => array('switch.off',    'Éteindre', 'off'),
                'toggle' => array('switch.toggle', 'Basculer', 'toggle'),
            );
            foreach ($actions as $role => $action) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'relay.' . $i . '.' . $role,
                    'capability' => $action[0],
                    'name'       => $action[1] . $suffixe,
                    'sink'       => array(
                        'topic'   => $_base . '/relay/' . $i . '/command',
                        'payload' => $action[2],
                        'qos'     => 0,
                        'retain'  => false,
                    ),
                    'links'      => array('state' => $cleEtat),
                )));
            }
        }
    }

    /*
     * Compteurs de relais — le piège de la génération 1.
     *
     * Un SHSW-1, qui n'a aucun wattmètre, publie quand même un `meters[]` :
     * `{"power":0,"is_valid":true}`. Se fier au nombre d'entrées donnerait à la
     * moitié du parc une commande « Puissance » éternellement à zéro, et un
     * utilisateur qui en conclurait, très raisonnablement, que le plugin est
     * cassé. Un compteur réel porte `total`, `counters` et `timestamp` : c'est
     * cette différence, et elle seule, qui décide.
     */
    private function ajouteCompteurs($_ctx, $_modele, $_base, $_info, $_relais) {
        foreach ($this->indices($_info, 'meters') as $i) {
            $compteur = $this->entree($_info, 'meters', $i);
            if (!$this->compteurReel($compteur)) {
                continue;
            }
            if (!in_array($i, $_relais, true)) {
                /* Un compteur sans relais du même rang existe (variateur,
                 * RGBW2 : la puissance est sous light/<i>). Rien n'est inventé
                 * ici : le topic serait faux, et une commande muette vaut moins
                 * qu'une ligne de journal. */
                $_ctx->log('debug', 'Shelly Gen1 : compteur ' . $i . ' sans relais correspondant sur '
                    . $_base . ', topic de puissance inconnu pour ce modèle.');
                continue;
            }
            $suffixe = count($_relais) > 1 ? ' ' . ($i + 1) : '';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'relay.' . $i . '.power',
                'capability' => 'power.active',
                'name'       => 'Puissance' . $suffixe,
                'unit'       => 'W',
                'source'     => array('topic' => $_base . '/relay/' . $i . '/power'),
                'value'      => array('transform' => array('round' => 1)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'relay.' . $i . '.energy',
                'capability' => 'energy.total',
                'name'       => 'Consommation' . $suffixe,
                'unit'       => 'kWh',
                'source'     => array('topic' => $_base . '/relay/' . $i . '/energy'),
                /* Watt-minutes vers kWh. */
                'value'      => array('transform' => array(
                    'scale' => self::WATTMINUTE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
        }
    }

    /*
     * Volets roulants — SHSW-25 et SHSW-21 configurés en mode roller.
     *
     * Le même boîtier, la même info à un tableau près, et pourtant plus rien de
     * commun côté MQTT : `relay/<i>` se tait définitivement, et tout se joue
     * sur `roller/<i>`. Les topics, tels que le firmware les emploie :
     *
     *   roller/<i>            open | close | stop — le mouvement, pas la position
     *   roller/<i>/pos        0 à 100, ou -1 quand l'appareil n'est pas calibré
     *   roller/<i>/power      watts, moteur
     *   roller/<i>/energy     watt-minutes, comme un relais
     *   roller/<i>/command        ← open | close | stop
     *   roller/<i>/command/pos    ← 0 à 100
     *
     * La position est prise comme état — c'est elle que Jeedom montre sur le
     * widget de volet — et `roller/<i>` lui-même n'est pas lu : « ouvre »,
     * « ferme » ou « arrêté » ne se range dans aucune capacité du vocabulaire,
     * et la position dit déjà où en est le volet.
     */
    private function ajouteVolets($_modele, $_base, $_info, $_indices) {
        $plusieurs = count($_indices) > 1;
        foreach ($_indices as $i) {
            $suffixe = $plusieurs ? ' ' . ($i + 1) : '';
            $cleEtat = 'roller.' . $i . '.state';
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleEtat,
                'capability' => 'cover.state',
                'name'       => 'Position' . $suffixe,
                'unit'       => '%',
                'source'     => array('topic' => $_base . '/roller/' . $i . '/pos'),
                /* -1 n'est pas une position : c'est ce que publie un volet non
                 * calibré, qui sait ouvrir et fermer mais ignore où il en est.
                 * Laissé tel quel, il s'afficherait comme « -1 % » et le widget
                 * dessinerait un volet plus qu'ouvert. */
                'value'      => array('transform' => array(
                    'map'   => array('-1' => ''),
                    'round' => 0,
                )),
            )));
            $actions = array(
                'open'  => array('cover.open',  'Ouvrir', 'open'),
                'close' => array('cover.close', 'Fermer', 'close'),
                'stop'  => array('cover.stop',  'Stop',   'stop'),
            );
            foreach ($actions as $role => $action) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'roller.' . $i . '.' . $role,
                    'capability' => $action[0],
                    'name'       => $action[1] . $suffixe,
                    'sink'       => array(
                        'topic'   => $_base . '/roller/' . $i . '/command',
                        'payload' => $action[2],
                        'qos'     => 0,
                        'retain'  => false,
                    ),
                    'links'      => array('state' => $cleEtat),
                )));
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'roller.' . $i . '.position',
                'capability' => 'cover.position',
                'name'       => 'Régler la position' . $suffixe,
                'unit'       => '%',
                'sink'       => array(
                    /* Topic distinct, et non une charge utile particulière sur
                     * `command` : c'est ainsi que le firmware l'attend. */
                    'topic'   => $_base . '/roller/' . $i . '/command/pos',
                    'payload' => '#slider#',
                    'qos'     => 0,
                    'retain'  => false,
                ),
                'links'      => array('state' => $cleEtat),
            )));

            /* La mesure du moteur, au même titre que celle d'un relais : même
             * garde — un appareil sans wattmètre publie quand même un
             * `meters[]` — et mêmes watt-minutes. */
            $compteur = $this->entree($_info, 'meters', $i);
            if (!$this->compteurReel($compteur)) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'roller.' . $i . '.power',
                'capability' => 'power.active',
                'name'       => 'Puissance' . $suffixe,
                'unit'       => 'W',
                'source'     => array('topic' => $_base . '/roller/' . $i . '/power'),
                'value'      => array('transform' => array('round' => 1)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'roller.' . $i . '.energy',
                'capability' => 'energy.total',
                'name'       => 'Consommation' . $suffixe,
                'unit'       => 'kWh',
                'source'     => array('topic' => $_base . '/roller/' . $i . '/energy'),
                'value'      => array('transform' => array(
                    'scale' => self::WATTMINUTE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
        }
    }

    /*
     * Variateurs, ampoules et bandeaux — SHDM-1/2, SHRGBW2, SHBLB-1, SHCB-1,
     * SHBDUO-1, SHVIN-1, SHSPOT-1/2.
     *
     * Ils publient `lights[]` dans leur info et rien sous `relay/<i>` :
     *
     *   light/<i>             on | off
     *   light/<i>/status      l'état complet, en JSON
     *   light/<i>/power       watts
     *   light/<i>/energy      watt-minutes
     *   light/<i>/command         ← on | off | toggle
     *   light/<i>/set             ← {"turn":"on","brightness":0..100}
     *
     * Une exception, et elle est documentée : le RGBW2 n'emploie pas `light`
     * mais `color/0` en mode couleur et `white/<i>` en mode blanc. Le préfixe
     * se lit donc dans l'info, jamais deviné ; voir prefixeLumiere().
     */
    private function ajouteLumieres($_modele, $_base, $_info, $_indices, $_code) {
        $plusieurs = count($_indices) > 1;
        foreach ($_indices as $i) {
            $lumiere = $this->entree($_info, 'lights', $i);
            $lumiere = is_array($lumiere) ? $lumiere : array();
            $prefixe = $_base . '/' . $this->prefixeLumiere($_code, $_info, $lumiere, $i);
            $suffixe = $plusieurs ? ' ' . ($i + 1) : '';
            $cleEtat = 'light.' . $i . '.state';

            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => $cleEtat,
                'capability' => 'light.state',
                'name'       => 'État' . $suffixe,
                'source'     => array('topic' => $prefixe),
                'value'      => array('transform' => array('map' => array('on' => '1', 'off' => '0'))),
            )));
            $actions = array(
                'on'  => array('light.on',  'Allumer',  'on'),
                'off' => array('light.off', 'Éteindre', 'off'),
            );
            foreach ($actions as $role => $action) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'light.' . $i . '.' . $role,
                    'capability' => $action[0],
                    'name'       => $action[1] . $suffixe,
                    'sink'       => array(
                        'topic'   => $prefixe . '/command',
                        'payload' => $action[2],
                        'qos'     => 0,
                        'retain'  => false,
                    ),
                    'links'      => array('state' => $cleEtat),
                )));
            }

            /*
             * La luminosité. En mode couleur, le firmware ne l'appelle pas
             * `brightness` mais `gain` — le premier ne pilote alors que la voie
             * blanche, et un curseur qui ne fait rien bouger est pire qu'un
             * curseur absent.
             *
             * `turn:"on"` dans la même charge utile : régler la luminosité
             * d'une lampe éteinte, sur un tableau de bord, veut dire l'allumer
             * à ce niveau-là.
             */
            $champ = ($this->estModeCouleur($_info, $lumiere) && array_key_exists('gain', $lumiere))
                   ? 'gain' : 'brightness';
            if (array_key_exists($champ, $lumiere)) {
                $cleNiveau = 'light.' . $i . '.level';
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => $cleNiveau,
                    'capability' => 'light.brightness_state',
                    'name'       => 'Niveau' . $suffixe,
                    'unit'       => '%',
                    'source'     => array(
                        'topic'    => $prefixe . '/status',
                        'selector' => array('type' => 'json', 'path' => $champ),
                    ),
                    'value'      => array('transform' => array('round' => 0)),
                )));
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'light.' . $i . '.brightness',
                    'capability' => 'light.brightness',
                    'name'       => 'Luminosité' . $suffixe,
                    'unit'       => '%',
                    'sink'       => array(
                        'topic'   => $prefixe . '/set',
                        'payload' => '{"' . $champ . '":#slider#,"turn":"on"}',
                        'qos'     => 0,
                        'retain'  => false,
                    ),
                    'links'      => array('state' => $cleNiveau),
                )));
            }

            /* La température de couleur : un nombre de kelvins, que le curseur
             * porte tel quel. Présente sur les Duo, les Vintage et les Duo
             * RGBW, absente des variateurs. */
            if (array_key_exists('temp', $lumiere)) {
                $cleTemp = 'light.' . $i . '.color_temp_state';
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => $cleTemp,
                    'capability' => 'light.color_temp_state',
                    'name'       => 'Température de couleur (état)' . $suffixe,
                    'unit'       => 'K',
                    'source'     => array(
                        'topic'    => $prefixe . '/status',
                        'selector' => array('type' => 'json', 'path' => 'temp'),
                    ),
                    'value'      => array('transform' => array('round' => 0)),
                )));
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'light.' . $i . '.color_temp',
                    'capability' => 'light.color_temp',
                    'name'       => 'Température de couleur' . $suffixe,
                    'unit'       => 'K',
                    'sink'       => array(
                        'topic'   => $prefixe . '/set',
                        'payload' => '{"temp":#slider#,"turn":"on"}',
                        'qos'     => 0,
                        'retain'  => false,
                    ),
                    'links'      => array('state' => $cleTemp),
                )));
            }

            /* La mesure, s'il y en a une : même garde que pour un relais. */
            $compteur = $this->entree($_info, 'meters', $i);
            if (!$this->compteurReel($compteur)) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'light.' . $i . '.power',
                'capability' => 'power.active',
                'name'       => 'Puissance' . $suffixe,
                'unit'       => 'W',
                'source'     => array('topic' => $prefixe . '/power'),
                'value'      => array('transform' => array('round' => 1)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'light.' . $i . '.energy',
                'capability' => 'energy.total',
                'name'       => 'Consommation' . $suffixe,
                'unit'       => 'kWh',
                'source'     => array('topic' => $prefixe . '/energy'),
                'value'      => array('transform' => array(
                    'scale' => self::WATTMINUTE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
        }
    }

    /*
     * Le préfixe de topic d'une lampe.
     *
     * `light/<i>` partout, sauf sur le RGBW2 : celui-là parle sous `color/0`
     * quand il est en mode couleur et sous `white/<i>` quand il pilote quatre
     * blancs séparés. Le mode se lit dans l'info — au niveau de la lampe, ou à
     * la racine selon la version du firmware — et jamais dans le code du
     * modèle : c'est un réglage, il change du jour au lendemain.
     */
    private function prefixeLumiere($_code, $_info, $_lumiere, $_i) {
        if (strtoupper(trim((string) $_code)) !== 'SHRGBW2') {
            return 'light/' . $_i;
        }
        return ($this->estModeCouleur($_info, $_lumiere) ? 'color/' : 'white/') . $_i;
    }

    private function estModeCouleur($_info, $_lumiere) {
        $mode = $this->texte($_lumiere, 'mode');
        if ($mode === '') {
            $mode = $this->texte($_info, 'mode');
        }
        return strtolower($mode) === 'color';
    }

    /*
     * Capteurs sur pile — SHHT-1, SHWT-1, SHDW-1/2, SHSM-01/02, SHBTN-1/2,
     * SHMOS-01/02, et le SHGS-1 qui n'a pas de pile mais la même façon de
     * parler.
     *
     * Toute leur mesure passe sous `sensor/…`, et JAMAIS sous `temperature` :
     * un H&T traité comme un relais donne une « Température interne » branchée
     * sur un topic muet, sans humidité ni niveau de pile — c'est-à-dire un
     * équipement qui ne dit rien de ce pour quoi il a été acheté.
     *
     * Sur un H&T, la température est d'ailleurs celle de la pièce : l'appeler
     * « interne » induirait en erreur celui qui bâtit un thermostat dessus.
     */
    private function ajouteCapteurs($_modele, $_base, $_info) {
        $binaire = array('transform' => array('map' => array(
            'true' => '1', 'false' => '0', '1' => '1', '0' => '0',
        )));

        if ($this->aTemperatureCapteur($_info)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'temperature',
                'capability' => 'sensor.temperature',
                'name'       => 'Température',
                'unit'       => '°C',
                'source'     => array('topic' => $_base . '/sensor/temperature'),
                'value'      => array('transform' => array('round' => 1)),
            )));
        }
        if (isset($_info['hum'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'humidity',
                'capability' => 'sensor.humidity',
                'name'       => 'Humidité',
                'unit'       => '%',
                'source'     => array('topic' => $_base . '/sensor/humidity'),
                'value'      => array('transform' => array('round' => 1)),
            )));
        }
        if (array_key_exists('flood', $_info)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'flood',
                'capability' => 'alarm.water_leak',
                'name'       => 'Fuite d\'eau',
                'source'     => array('topic' => $_base . '/sensor/flood'),
                'value'      => $binaire,
            )));
        }
        if (isset($_info['sensor']['state'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'contact',
                'capability' => 'contact.open',
                'name'       => 'Ouverture',
                'source'     => array('topic' => $_base . '/sensor/state'),
                /* « open »/« close », et non 1/0 : le contact d'un Door/Window
                 * parle en toutes lettres. */
                'value'      => array('transform' => array('map' => array(
                    'open' => '1', 'close' => '0', 'closed' => '0',
                ))),
            )));
        }
        if (isset($_info['sensor']['motion']) || array_key_exists('motion', $_info)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'motion',
                'capability' => 'presence.detected',
                'name'       => 'Mouvement',
                'source'     => array('topic' => $_base . '/sensor/motion'),
                'value'      => $binaire,
            )));
        }
        if (isset($_info['lux'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'lux',
                'capability' => 'sensor.luminosity',
                'name'       => 'Luminosité',
                'unit'       => 'lx',
                'source'     => array('topic' => $_base . '/sensor/lux'),
                'value'      => array('transform' => array('round' => 0)),
            )));
        }
        if (array_key_exists('smoke', $_info)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'smoke',
                'capability' => 'alarm.smoke',
                'name'       => 'Fumée',
                'source'     => array('topic' => $_base . '/sensor/smoke'),
                'value'      => $binaire,
            )));
        }
        if (array_key_exists('gas', $_info) || array_key_exists('gas_sensor', $_info)) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'gas',
                'capability' => 'alarm.gas',
                'name'       => 'Gaz',
                'source'     => array('topic' => $_base . '/sensor/gas'),
                /* Le détecteur de gaz ne dit pas vrai ou faux mais l'état de
                 * son alarme, en toutes lettres. */
                'value'      => array('transform' => array('map' => array(
                    'none' => '0', 'mild' => '1', 'heavy' => '1', 'test' => '0',
                ))),
            )));
        }
        if (isset($_info['bat'])) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'battery',
                'capability' => 'battery.level',
                'name'       => 'Batterie',
                'unit'       => '%',
                'source'     => array('topic' => $_base . '/sensor/battery'),
                'value'      => array('transform' => array('round' => 0)),
            )));
        }
    }

    /*
     * Un capteur qui parle sous `sensor/`.
     *
     * La présence de `bat` est le discriminant le plus sûr : toute la gamme sur
     * pile la publie, et aucun appareil sur secteur ne l'a. Les autres clés
     * rattrapent les deux exceptions — un Gas ou un Sense branchés au secteur
     * publient tout de même sous `sensor/` — et le catalogue sert de dernier
     * recours pour un firmware qui aurait omis `bat`.
     */
    private function estCapteurSurPile($_info, $_code) {
        foreach (array('bat', 'hum', 'flood', 'lux', 'smoke', 'motion', 'gas', 'gas_sensor') as $cle) {
            if (array_key_exists($cle, $_info)) {
                return true;
            }
        }
        if (isset($_info['sensor']) && is_array($_info['sensor'])
            && (isset($_info['sensor']['state']) || isset($_info['sensor']['motion']))) {
            return true;
        }
        return $this->surPile($_code);
    }

    /* Un capteur sur pile publie sa température sous `sensor/temperature`, et
     * la porte dans `tmp` — jamais dans `temperature`, qui est réservé à la
     * chauffe du boîtier des appareils de puissance. */
    private function aTemperatureCapteur($_info) {
        if (!isset($_info['tmp']) || !is_array($_info['tmp'])) {
            return false;
        }
        return array_key_exists('tC', $_info['tmp']) || array_key_exists('value', $_info['tmp']);
    }

    /*
     * Compteurs d'énergie (SHEM, SHEM-3) : une voie de mesure par entrée.
     *
     * La puissance y est signée — négative quand une installation solaire
     * réinjecte. Aucune transformation ne doit donc borner cette valeur à
     * zéro : un plafonnement ferait disparaître exactement l'information pour
     * laquelle l'appareil a été acheté.
     */
    private function ajouteEmeters($_modele, $_base, $_info) {
        $indices = $this->indices($_info, 'emeters');
        foreach ($indices as $i) {
            $voie = ' voie ' . ($i + 1);
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'emeter.' . $i . '.power',
                'capability' => 'power.active',
                'name'       => 'Puissance' . $voie,
                'unit'       => 'W',
                'source'     => array('topic' => $_base . '/emeter/' . $i . '/power'),
                'value'      => array('transform' => array('round' => 1)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'emeter.' . $i . '.voltage',
                'capability' => 'power.voltage',
                'name'       => 'Tension' . $voie,
                'unit'       => 'V',
                'source'     => array('topic' => $_base . '/emeter/' . $i . '/voltage'),
                'value'      => array('transform' => array('round' => 1)),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'emeter.' . $i . '.energy',
                'capability' => 'energy.total',
                'name'       => 'Consommation' . $voie,
                'unit'       => 'kWh',
                'source'     => array('topic' => $_base . '/emeter/' . $i . '/total'),
                /* Watt-heures ici, et non watt-minutes : le même nom de clé
                 * (`total`) porte deux unités selon qu'il vient de `meters` ou
                 * d'`emeters`. */
                'value'      => array('transform' => array(
                    'scale' => self::WATTHEURE_VERS_KWH,
                    'round' => self::DECIMALES_ENERGIE,
                )),
            )));
            $compteur = $this->entree($_info, 'emeters', $i);

            /*
             * Facteur de puissance et puissance réactive : publiés par
             * l'appareil, et jusqu'ici lus par personne. Masqués par défaut —
             * ils encombreraient le tableau de bord de qui veut simplement voir
             * sa consommation — mais présents pour qui diagnostique une
             * installation ou surveille un onduleur.
             */
            if (is_array($compteur) && array_key_exists('pf', $compteur)) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'emeter.' . $i . '.pf',
                    'capability' => 'power.factor',
                    'name'       => 'Facteur de puissance' . $voie,
                    'source'     => array('topic' => $_base . '/emeter/' . $i . '/pf'),
                    'value'      => array('transform' => array('round' => 2)),
                )));
            }
            if (is_array($compteur) && array_key_exists('reactive', $compteur)) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'emeter.' . $i . '.reactive',
                    'capability' => 'power.reactive',
                    'name'       => 'Puissance réactive' . $voie,
                    'unit'       => 'var',
                    'source'     => array('topic' => $_base . '/emeter/' . $i . '/reactive_power'),
                    'value'      => array('transform' => array('round' => 1)),
                )));
            }

            /*
             * Le courant par phase, sur un 3EM. Gardé par array_key_exists,
             * comme le facteur de puissance : le SHEM à deux voies ne publie
             * pas `current`, et lui créer la commande donnerait un ampérage
             * éternellement vide sur la moitié du parc de compteurs.
             */
            if (is_array($compteur) && array_key_exists('current', $compteur)) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'emeter.' . $i . '.current',
                    'capability' => 'power.current',
                    'name'       => 'Courant' . $voie,
                    'unit'       => 'A',
                    'source'     => array('topic' => $_base . '/emeter/' . $i . '/current'),
                    'value'      => array('transform' => array('round' => 2)),
                )));
            }

            if (is_array($compteur) && array_key_exists('total_returned', $compteur)) {
                $_modele->addChannel(new MqttbeChannel(array(
                    'key'        => 'emeter.' . $i . '.returned',
                    /* Et non `energy.total` : ce compteur mesure ce qui PART
                     * vers le réseau. Étiqueté en consommation, il apparaîtrait
                     * dans la vue Maison comme une dépense, et le bilan d'une
                     * installation solaire serait exactement inversé. */
                    'capability' => 'energy.returned',
                    'name'       => 'Réinjection' . $voie,
                    'unit'       => 'kWh',
                    'source'     => array('topic' => $_base . '/emeter/' . $i . '/total_returned'),
                    'value'      => array('transform' => array(
                        'scale' => self::WATTHEURE_VERS_KWH,
                        'round' => self::DECIMALES_ENERGIE,
                    )),
                )));
            }
        }
    }

    /*
     * Entrées physiques : l'état du contact, l'événement, l'appui long.
     *
     * `input/<i>` donne « 0 » ou « 1 » — aucune correspondance à poser.
     * `input_event/<i>` porte du JSON, `{"event":"S","event_cnt":1377}` : seul
     * le champ `event` intéresse, mais deux appuis courts de suite donnent deux
     * fois « S ». En répétition `onchange`, le second serait avalé et le
     * scénario ne partirait pas : d'où `always`, qui est ici la seule politique
     * juste.
     */
    private function ajouteEntrees($_modele, $_base, $_info, $_avecAppuiLong = true) {
        foreach ($this->indices($_info, 'inputs') as $i) {
            $numero = ' ' . ($i + 1);
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'input.' . $i,
                'capability' => 'button.pressed',
                'name'       => 'Entrée' . $numero,
                'source'     => array('topic' => $_base . '/input/' . $i),
            )));
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'input.' . $i . '.event',
                'capability' => 'button.event',
                'name'       => 'Événement entrée' . $numero,
                'source'     => array(
                    'topic'    => $_base . '/input_event/' . $i,
                    'selector' => array('type' => 'json', 'path' => 'event'),
                ),
                'value'      => array('repeat' => array('mode' => 'always')),
            )));
            /*
             * L'appui long n'a pas de trace dans `info` : le firmware ne publie
             * `longpush/<i>` que lorsque l'entrée est configurée en bouton. Le
             * canal est donc créé pour chaque entrée d'un appareil qui commande
             * quelque chose — un topic qui ne publie jamais laisse une commande
             * à sa valeur par défaut, ce qui se voit et se masque ; une commande
             * absente, elle, ne se devine pas.
             *
             * Mais seulement là. Un appareil sans relais, sans volet et sans
             * lampe — le Shelly i3, le Button1 — n'a pas de `longpush` du tout :
             * il porte l'appui long dans `input_event/<i>`, sous la forme d'un
             * événement « L ». Trois commandes « Appui long » définitivement
             * vides sur un i3, c'est la moitié de son tableau de bord occupée
             * par du vide, et l'utilisateur qui cherche pourquoi elles ne
             * bougent jamais.
             */
            if (!$_avecAppuiLong) {
                continue;
            }
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'input.' . $i . '.longpush',
                'capability' => 'button.pressed',
                'name'       => 'Appui long' . $numero,
                'source'     => array('topic' => $_base . '/longpush/' . $i),
            )));
        }
    }

    /* Température interne du boîtier — présente sur les appareils qui chauffent
     * (relais à wattmètre, prises), absente ailleurs. */
    private function ajouteTemperature($_modele, $_base, $_info) {
        $aTemperature = array_key_exists('temperature', $_info)
            || (isset($_info['tmp']) && is_array($_info['tmp']) && array_key_exists('tC', $_info['tmp']));
        if (!$aTemperature) {
            return;
        }
        $_modele->addChannel(new MqttbeChannel(array(
            'key'        => 'temperature',
            'capability' => 'sensor.temperature',
            'name'       => 'Température interne',
            'unit'       => '°C',
            'source'     => array('topic' => $_base . '/temperature'),
            'value'      => array('transform' => array('round' => 1)),
        )));
    }

    /*
     * Sondes externes DS18B20 et DHT : un appareil en porte jusqu'à trois, et
     * l'on en trouve effectivement deux sur un même Shelly 1 du parc. Les
     * numéroter toujours, même seules : le rang est celui de la borne, il
     * désigne une sonde physique, et un appareil qui en reçoit une deuxième ne
     * doit pas voir la première changer de nom.
     */
    private function ajouteSondes($_modele, $_base, $_info) {
        foreach ($this->indices($_info, 'ext_temperature') as $i) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'ext.temperature.' . $i,
                'capability' => 'sensor.temperature',
                'name'       => 'Température externe ' . ($i + 1),
                'unit'       => '°C',
                'source'     => array('topic' => $_base . '/ext_temperature/' . $i),
                'value'      => array('transform' => array('round' => 1)),
            )));
        }
        foreach ($this->indices($_info, 'ext_humidity') as $i) {
            $_modele->addChannel(new MqttbeChannel(array(
                'key'        => 'ext.humidity.' . $i,
                'capability' => 'sensor.humidity',
                'name'       => 'Humidité externe ' . ($i + 1),
                'unit'       => '%',
                'source'     => array('topic' => $_base . '/ext_humidity/' . $i),
                'value'      => array('transform' => array('round' => 1)),
            )));
        }
    }

    /* --------------------------------------------------------------------- */
    /* Catalogue des noms commerciaux                                        */
    /* --------------------------------------------------------------------- */

    /*
     * « Shelly 1PM » plutôt que « SHSW-PM ». Le catalogue est décoratif : s'il
     * manque, ou si le modèle n'y figure pas, l'appareil est découvert de la
     * même façon et porte son code constructeur. Rien de ce qui compte — les
     * canaux — n'en dépend.
     */
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

    public function nomCommercial($_code) {
        $fiche = $this->fiche($_code);
        $nom = isset($fiche['name']) ? trim((string) $fiche['name']) : '';
        return ($nom === '') ? (string) $_code : $nom;
    }

    private function surPile($_code) {
        $fiche = $this->fiche($_code);
        return isset($fiche['battery']) && $fiche['battery'];
    }

    /*
     * « Shelly 1PM 1A0805 » : le nom commercial, puis les six derniers chiffres
     * de la MAC. Deux appareils du même modèle sont la règle et non
     * l'exception — six prises identiques dans une maison — et deux équipements
     * homonymes font échouer l'enregistrement du second (eqLogic (name,
     * object_id) est unique). Le suffixe est aussi ce que l'utilisateur lit sur
     * l'étiquette du boîtier et dans l'interface Shelly.
     */
    private function nomLisible($_code, $_mac) {
        $nom = $this->nomCommercial($_code);
        $mac = strtoupper((string) $_mac);
        if (strlen($mac) >= 6) {
            $nom .= ' ' . substr($mac, -6);
        }
        return $nom;
    }

    /* --------------------------------------------------------------------- */
    /* Mémoire de l'adapter                                                  */
    /* --------------------------------------------------------------------- */

    /*
     * Un dossier par appareil, plus un inventaire des identifiants : la mémoire
     * du contexte se lit par clé, elle ne s'énumère pas.
     */
    private function dossier($_ctx, $_identifiant) {
        $dossier = $this->lit($_ctx, 'dev:' . $_identifiant);
        if (!isset($dossier['id'])) {
            $dossier['id'] = (string) $_identifiant;
        }
        return $dossier;
    }

    private function range($_ctx, $_dossier) {
        $identifiant = $this->texte($_dossier, 'id');
        if ($identifiant === '') {
            return;
        }
        $this->ecrit($_ctx, 'dev:' . $identifiant, $_dossier);
        $inventaire = $this->inventaire($_ctx);
        if (!in_array($identifiant, $inventaire, true)) {
            $inventaire[] = $identifiant;
            $this->ecrit($_ctx, 'index', $inventaire);
        }
    }

    public function inventaire($_ctx) {
        $inventaire = $_ctx->recall(self::ID . ':index');
        return is_array($inventaire) ? array_values($inventaire) : array();
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
     * Une charge utile illisible est un incident ordinaire — message tronqué,
     * appareil en cours de redémarrage — et non une erreur de programme : elle
     * se journalise et se jette, elle ne lève pas.
     */
    private function json($_ctx, $_payload, $_quoi) {
        $decode = json_decode((string) $_payload, true);
        if (!is_array($decode)) {
            $_ctx->log('warning', 'Shelly Gen1 : ' . $_quoi . ' illisible, message ignoré.');
            return null;
        }
        return $decode;
    }

    /*
     * Les indices d'une liste du info (`relays`, `meters`, `inputs`…).
     *
     * Le firmware publie tantôt une liste JSON, tantôt un objet indexé par des
     * chiffres — `ext_temperature` se rencontre dans les deux formes selon la
     * version. Les deux donnent ici la même chose : une liste d'entiers triée,
     * qui est exactement le rang employé dans les topics.
     */
    private function indices($_info, $_cle) {
        if (!isset($_info[$_cle]) || !is_array($_info[$_cle])) {
            return array();
        }
        $indices = array();
        foreach (array_keys($_info[$_cle]) as $cle) {
            if (is_int($cle) || preg_match('/^\d+$/', (string) $cle)) {
                $indices[] = (int) $cle;
            }
        }
        sort($indices, SORT_NUMERIC);
        return $indices;
    }

    private function entree($_info, $_cle, $_indice) {
        if (!isset($_info[$_cle]) || !is_array($_info[$_cle])) {
            return null;
        }
        if (isset($_info[$_cle][$_indice])) {
            return $_info[$_cle][$_indice];
        }
        $texte = (string) $_indice;
        return isset($_info[$_cle][$texte]) ? $_info[$_cle][$texte] : null;
    }

    /* Un compteur qui compte vraiment. Voir ajouteCompteurs(). */
    private function compteurReel($_compteur) {
        if (!is_array($_compteur)) {
            return false;
        }
        return array_key_exists('total', $_compteur)
            || array_key_exists('counters', $_compteur)
            || array_key_exists('timestamp', $_compteur);
    }

    /*
     * La MAC, telle qu'elle est annoncée ou, à défaut, telle que l'identifiant
     * la porte : « shelly1pm-D8BFC01A0805 ». Les deux sources existent sur le
     * terrain, et l'identité de l'équipement en dépend entièrement.
     */
    private function macDe($_annonce, $_identifiant) {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $this->texte($_annonce, 'mac')));
        if (preg_match('/^[0-9A-F]{12}$/', $mac)) {
            return $mac;
        }
        $morceaux = explode('-', (string) $_identifiant);
        $dernier = strtoupper(end($morceaux));
        return preg_match('/^[0-9A-F]{12}$/', $dernier) ? $dernier : '';
    }

    private function texte($_tableau, $_cle) {
        return (isset($_tableau[$_cle]) && !is_array($_tableau[$_cle]))
            ? trim((string) $_tableau[$_cle]) : '';
    }

    /*
     * Une valeur venue du réseau, rendue citable dans une ligne de journal :
     * les caractères de contrôle ôtés — un retour à la ligne y fabriquerait une
     * fausse entrée de journal — et la longueur bornée, faute de quoi une
     * charge utile de deux cents caractères noierait le motif du refus.
     */
    private function citation($_valeur) {
        $texte = preg_replace('/[\x00-\x1F\x7F]/', '?', (string) $_valeur);
        return (strlen($texte) > 48) ? substr($texte, 0, 48) . '…' : $texte;
    }

    private function nombre($_tableau, $_cle) {
        return (isset($_tableau[$_cle]) && is_numeric($_tableau[$_cle])) ? (float) $_tableau[$_cle] : 0;
    }

    /* Le temps vient du contexte : un contrôle hors ligne rejoue une journée en
     * quelques millisecondes, et time() lui ferait manquer toutes les
     * expirations. */
    private function maintenant($_ctx) {
        $maintenant = $_ctx->now();
        return is_numeric($maintenant) ? (float) $maintenant : 0;
    }
}
