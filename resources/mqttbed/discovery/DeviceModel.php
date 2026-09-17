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

require_once __DIR__ . '/Channel.php';

/* =============================================================================
 * Le modèle de périphérique : ce qu'un appareil sait faire, dit en termes de
 * MQTT et de capacités, jamais en termes de Jeedom.
 *
 * C'est le seul objet qui passe des adapters à la fabrique. Il est versionné
 * (`schema`) parce qu'il sera sérialisé et conservé : un modèle écrit
 * aujourd'hui doit pouvoir être relu par le plugin de l'an prochain.
 *
 * Aucune référence à Jeedom dans ce fichier : il est chargé par le démon, qui
 * tourne dans son propre processus sans core.inc.php, autant que par le
 * processus web. Les limites du cœur (127 caractères pour un logicalId
 * d'équipement) sont donc recopiées ici en constantes, et non lues quelque
 * part : le modèle doit pouvoir dire « ceci ne passera pas » sans Jeedom.
 * ========================================================================== */
class MqttbeDeviceModel {

    const SCHEMA = 1;

    /* eqLogic.logicalId est un varchar(127) : un uid plus long serait tronqué
     * en base, et l'appareil ne serait plus jamais retrouvé à la découverte
     * suivante — on créerait un doublon à chaque redémarrage du démon. */
    const MAX_UID = 127;

    const CONFIDENCES = array('certain', 'probable', 'guess');

    /*
     * Longueur maximale du nom que l'utilisateur a donné à son appareil.
     *
     * Ce nom vient du réseau : il est lu dans la réponse d'un appareil, c'est-à-
     * dire dans ce que n'importe qui sur le réseau local peut faire dire à un
     * appareil. Il finit dans le nom de l'équipement Jeedom, un varchar(127) que
     * la fabrique compose avec le nom technique — 64 caractères laissent la
     * place aux deux, et une chaîne de dix kilo-octets ne traverse pas le démon
     * pour aller se faire tronquer en base.
     */
    const MAX_DEVICE_NAME = 64;

    /*
     * Bornes de la durée de validité d'une sonde.
     *
     * Zéro serait un piège : le résultat expirerait à l'instant même où il est
     * obtenu, et le parc entier serait resondé à chaque découverte. Trente jours
     * suffisent pour un nom qu'on ne change qu'en renommant l'appareil.
     */
    const PROBE_TTL_DEFAULT = 86400;
    const PROBE_TTL_MIN     = 60;
    const PROBE_TTL_MAX     = 2592000;

    private $schema = self::SCHEMA;

    private $identity = array(
        'adapter'    => '',
        'uid'        => '',
        'aliases'    => array(),
        'confidence' => 'certain',
    );

    /*
     * `name` et `device_name` ne disent pas la même chose, et les confondre
     * ferait perdre l'un des deux :
     *
     *   name        le nom TECHNIQUE, composé par l'adapter à partir du modèle
     *               et de la MAC — « Shelly 1 55670C ». Unique, stable,
     *               reconnaissable sur l'étiquette du boîtier.
     *   device_name le nom que L'UTILISATEUR a donné à son appareil dans
     *               l'application du constructeur — « chaudiere ». C'est celui
     *               qui lui parle, et il n'est unique ni garanti présent.
     *
     * La fabrique compose les deux (« Shelly 1 55670C chaudiere ») ; vide, le
     * nom technique reste seul, exactement comme avant.
     *
     * `probe` est d'une autre nature : ce n'est pas une propriété de l'appareil
     * mais la DESCRIPTION DU MOYEN d'obtenir `device_name` quand l'adapter ne le
     * connaît pas. L'adapter la décrit sans savoir comment elle sera exécutée ;
     * le moteur l'exécute sans rien savoir du protocole. Un adapter dont le
     * message de découverte porte déjà le nom (Tasmota, Zigbee2MQTT, Home
     * Assistant) remplit `device_name` et ne déclare aucune sonde.
     */
    private $meta = array(
        'name'            => '',
        'device_name'     => '',
        'manufacturer'    => '',
        'model'           => '',
        'model_name'      => '',
        'generation'      => null,
        'firmware'        => '',
        'ip'              => '',
        'config_url'      => '',
        'battery_powered' => false,
        'probe'           => array(),
    );

    private $availability = array();

    /* Tableau associatif clé de canal => MqttbeChannel, pour que le doublon de
     * clé se voie à l'ajout et non trois étapes plus loin, au moment où la
     * base refuse l'enregistrement de tout l'équipement. */
    private $channels = array();

    /* Les clés dupliquées rencontrées à la construction : gardées pour que
     * validate() puisse les dire, puisque le tableau, lui, ne peut pas les
     * garder toutes les deux. */
    private $duplicates = array();

    public function __construct($_values = array()) {
        if (!is_array($_values)) {
            return;
        }
        if (isset($_values['schema'])) {
            $this->schema = (int) $_values['schema'];
        }
        if (isset($_values['identity']) && is_array($_values['identity'])) {
            $identite = $_values['identity'];
            $this->identity['adapter'] = self::text($identite, 'adapter');
            $this->identity['uid']     = self::text($identite, 'uid');
            if (isset($identite['confidence']) && $identite['confidence'] !== '') {
                $this->identity['confidence'] = strtolower(trim((string) $identite['confidence']));
            }
            if (isset($identite['aliases']) && is_array($identite['aliases'])) {
                foreach ($identite['aliases'] as $alias) {
                    $alias = trim((string) $alias);
                    /* L'uid lui-même n'a pas à figurer dans les alias : il y
                     * serait cherché deux fois, et une liste dédoublonnée rend
                     * l'empreinte insensible à l'ordre de découverte. */
                    if ($alias !== '' && $alias !== $this->identity['uid']
                        && !in_array($alias, $this->identity['aliases'], true)) {
                        $this->identity['aliases'][] = $alias;
                    }
                }
            }
        }
        if (isset($_values['meta']) && is_array($_values['meta'])) {
            foreach ($this->meta as $cle => $defaut) {
                if (!array_key_exists($cle, $_values['meta'])) {
                    continue;
                }
                $valeur = $_values['meta'][$cle];
                if ($cle === 'battery_powered') {
                    $this->meta[$cle] = self::toBool($valeur);
                } elseif ($cle === 'generation') {
                    $this->meta[$cle] = ($valeur === null || $valeur === '') ? null : (int) $valeur;
                } elseif ($cle === 'probe') {
                    $this->meta[$cle] = self::normalizeProbe($valeur);
                } elseif ($cle === 'device_name') {
                    /* Nettoyé ici AUSSI, et pas seulement à la source : un
                     * adapter qui connaît le nom (Tasmota, Zigbee2MQTT) le tire
                     * lui aussi du réseau, et ne passera jamais par la sonde. */
                    $this->meta[$cle] = self::cleanDeviceName($valeur);
                } else {
                    $this->meta[$cle] = trim((string) $valeur);
                }
            }
        }
        if (isset($_values['availability']) && is_array($_values['availability'])) {
            $this->availability = self::normalizeAvailability($_values['availability']);
        }
        if (isset($_values['channels']) && is_array($_values['channels'])) {
            foreach ($_values['channels'] as $canal) {
                if ($canal instanceof MqttbeChannel) {
                    $this->addChannel($canal);
                } elseif (is_array($canal)) {
                    $this->addChannel(MqttbeChannel::fromArray($canal));
                }
            }
        }
    }

    public static function fromArray($_values) {
        return new self($_values);
    }

    /* Rend null plutôt que de lever : un modèle vient du réseau, et une charge
     * utile tronquée est un incident ordinaire, pas une erreur de programme. */
    public static function fromJson($_json) {
        $decode = json_decode((string) $_json, true);
        if (!is_array($decode)) {
            return null;
        }
        return new self($decode);
    }

    /* --------------------------------------------------------------------- */
    /* Lecture                                                               */
    /* --------------------------------------------------------------------- */

    public function schema()      { return $this->schema; }
    public function adapter()     { return $this->identity['adapter']; }
    public function uid()         { return $this->identity['uid']; }
    public function aliases()     { return $this->identity['aliases']; }
    public function confidence()  { return $this->identity['confidence']; }
    public function identity()    { return $this->identity; }

    public function meta($_cle = null) {
        if ($_cle === null) {
            return $this->meta;
        }
        return array_key_exists($_cle, $this->meta) ? $this->meta[$_cle] : null;
    }

    public function name()           { return $this->meta['name']; }
    public function deviceName()     { return $this->meta['device_name']; }
    public function probe()          { return $this->meta['probe']; }
    public function hasProbe()       { return !empty($this->meta['probe']); }
    public function manufacturer()   { return $this->meta['manufacturer']; }
    public function model()          { return $this->meta['model']; }
    public function modelName()      { return $this->meta['model_name']; }
    public function generation()     { return $this->meta['generation']; }
    public function firmware()       { return $this->meta['firmware']; }
    public function ip()             { return $this->meta['ip']; }
    public function configUrl()      { return $this->meta['config_url']; }
    public function isBatteryPowered() { return $this->meta['battery_powered']; }

    public function availability()    { return $this->availability; }
    public function hasAvailability() { return isset($this->availability['topic']) && $this->availability['topic'] !== ''; }

    /* @return MqttbeChannel[] */
    public function channels()   { return array_values($this->channels); }
    public function channelKeys() { return array_keys($this->channels); }
    public function countChannels() { return count($this->channels); }

    public function channel($_cle) {
        return isset($this->channels[$_cle]) ? $this->channels[$_cle] : null;
    }

    /* Tous les topics à écouter pour ce modèle, disponibilité comprise : c'est
     * ce que le démon transforme en abonnements. */
    public function sourceTopics() {
        $topics = array();
        if ($this->hasAvailability()) {
            $topics[] = $this->availability['topic'];
        }
        foreach ($this->channels as $canal) {
            if ($canal->hasSource() && !in_array($canal->sourceTopic(), $topics, true)) {
                $topics[] = $canal->sourceTopic();
            }
        }
        return $topics;
    }

    /* --------------------------------------------------------------------- */
    /* Écriture                                                              */
    /* --------------------------------------------------------------------- */

    /*
     * Un canal dont la clé est déjà prise n'écrase pas le premier : on garde
     * l'original et on retient le doublon pour le signaler. Écraser
     * silencieusement ferait disparaître une commande de l'équipement sans
     * qu'aucun message ne le dise.
     */
    public function addChannel($_canal) {
        if (!($_canal instanceof MqttbeChannel)) {
            return $this;
        }
        $cle = $_canal->key();
        if ($cle !== '' && isset($this->channels[$cle])) {
            $this->duplicates[] = $cle;
            return $this;
        }
        /* Une clé vide est fautive, mais elle doit survivre jusqu'à validate()
         * pour y être dite ; elle est rangée sous un indice numérique. */
        if ($cle === '') {
            $this->channels[] = $_canal;
            return $this;
        }
        $this->channels[$cle] = $_canal;
        return $this;
    }

    /*
     * Poser le nom obtenu pour l'appareil — c'est ce que fait le moteur quand
     * une sonde a répondu.
     *
     * Elle ne s'appelle pas setDeviceName() à dessein : côté Jeedom,
     * utils::a2o() appelle « set » + le nom de chaque clé reçue du formulaire,
     * et l'habitude de nommer ainsi finit par produire la méthode qui tue un
     * enregistrement sur une erreur fatale, avant toute écriture.
     *
     * UN VIDE N'EFFACE JAMAIS UN NOM CONNU. C'est la règle qui rend une panne
     * réseau inoffensive : l'appareil est éteint au moment où la découverte
     * repasse, la sonde échoue, et l'équipement doit garder le nom qu'il porte
     * depuis des mois plutôt que de le perdre le temps d'une coupure.
     *
     * @return bool vrai si le nom a changé.
     */
    public function applyDeviceName($_nom) {
        $nom = self::cleanDeviceName($_nom);
        if ($nom === '' || $nom === $this->meta['device_name']) {
            return false;
        }
        $this->meta['device_name'] = $nom;
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Sérialisation                                                         */
    /* --------------------------------------------------------------------- */

    /*
     * Forme stable et réversible : fromArray(toArray($m)) rend un modèle dont
     * le toArray() est identique, au caractère près. C'est la condition pour
     * qu'un modèle conservé en base et relu au démarrage ne se distingue pas
     * de celui que l'adapter vient de produire.
     *
     * `fingerprint` est écrite pour le lecteur humain et pour les outils, mais
     * elle est recalculée à la relecture : une empreinte transportée dans le
     * document qu'elle décrit ne serait plus une preuve de rien.
     */
    public function toArray() {
        $sortie = array(
            'schema'   => $this->schema,
            'identity' => array(
                'adapter'    => $this->identity['adapter'],
                'uid'        => $this->identity['uid'],
                'aliases'    => array_values($this->identity['aliases']),
                'confidence' => $this->identity['confidence'],
            ),
            'meta'     => $this->meta,
        );
        if (!empty($this->availability)) {
            $sortie['availability'] = $this->availability;
        }
        $canaux = array();
        foreach ($this->channels as $canal) {
            $canaux[] = $canal->toArray();
        }
        $sortie['channels'] = $canaux;
        $sortie['fingerprint'] = $this->fingerprint();
        return $sortie;
    }

    public function toJson($_lisible = false) {
        $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($_lisible) {
            $options |= JSON_PRETTY_PRINT;
        }
        return json_encode($this->toArray(), $options);
    }

    /* --------------------------------------------------------------------- */
    /* Empreinte                                                             */
    /* --------------------------------------------------------------------- */

    /*
     * Empreinte déterministe de ce qui, dans ce modèle, se traduit par une
     * écriture en base côté Jeedom.
     *
     * Elle sert à un seul usage : ne rien réécrire quand un message de
     * découverte retenu est rejoué au démarrage du démon. Tout le parc étant
     * redécouvert à chaque redémarrage, une empreinte trop sensible reviendrait
     * à réécrire la base entière plusieurs fois par jour.
     *
     * Sont donc volontairement EXCLUS :
     *   - meta.ip et meta.config_url : ils changent au gré du bail DHCP. Un
     *     nouveau bail ne change rien à ce que l'appareil sait faire, et la
     *     fabrique peut rafraîchir ces champs sans que l'empreinte bouge.
     *   - meta.firmware : une campagne de mise à jour réécrirait sinon tout le
     *     parc, alors que la version du micrologiciel ne pilote aucune
     *     commande. Si une mise à jour ajoute vraiment une fonction, elle
     *     ajoute un canal — et là, l'empreinte change.
     *   - identity.confidence : c'est une décision d'adoption, prise une fois,
     *     pas une propriété de l'appareil. Passer de `probable` à `certain`
     *     après une confirmation ne doit pas déclencher de réécriture.
     *   - l'ordre des canaux et celui des clés : deux adapters qui décrivent le
     *     même appareil dans un ordre différent le décrivent pareil.
     *   - les valeurs vides : `"unit": ""` et l'absence d'unité disent la même
     *     chose et doivent donner la même empreinte.
     *
     * Sont INCLUS le schéma, l'identité durable (adapter, uid, alias), le nom,
     * le fabricant, le modèle, la génération, l'alimentation par pile, la
     * disponibilité et l'intégralité des canaux : chacun de ces champs
     * détermine un nom, un type, un topic ou une commande dans Jeedom.
     */
    /**
     * Empreinte des métadonnées volatiles : adresse IP, micrologiciel, URL.
     *
     * Elles sont délibérément absentes de l'empreinte principale — un nouveau
     * bail DHCP ou une campagne de mise à jour ne doit pas faire réécrire tout
     * le parc en base. Mais elles ne doivent pas geler pour autant : sans cette
     * seconde empreinte, l'adresse retenue restait celle du jour de la
     * découverte, et le lien « ouvrir l'appareil » menait des mois plus tard à
     * une autre machine du réseau.
     *
     * Le moteur s'en sert pour décider d'une RÉÉMISSION, qui ne coûte qu'un
     * message ; la fabrique continue de s'appuyer sur l'empreinte principale
     * pour décider d'une RÉÉCRITURE, qui coûte une base de données.
     */
    public function volatileFingerprint() {
        return sha1(json_encode(array(
            'ip'         => (string) $this->meta['ip'],
            'firmware'   => (string) $this->meta['firmware'],
            'config_url' => (string) $this->meta['config_url'],
        )));
    }

    public function fingerprint() {
        return 'sha1:' . sha1(json_encode($this->fingerprintData(),
                                          JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function fingerprintData() {
        $alias = array_values($this->identity['aliases']);
        sort($alias, SORT_STRING);

        $canaux = array();
        foreach ($this->channels as $canal) {
            $canaux[] = self::canonical($canal->toArray());
        }
        /* Tri par la forme canonique et non par la clé : deux canaux sans clé
         * (donc fautifs, mais présentables) garderaient sinon l'ordre du
         * tableau d'origine. */
        usort($canaux, array(__CLASS__, 'compareCanonical'));

        return self::canonical(array(
            'schema'       => $this->schema,
            'identity'     => array(
                'adapter' => $this->identity['adapter'],
                'uid'     => $this->identity['uid'],
                'aliases' => $alias,
            ),
            'meta'         => array(
                'name'            => $this->meta['name'],
                /*
                 * DANS l'empreinte principale, et non dans la volatile : c'est
                 * un champ qui s'écrit en base, au même titre que le nom
                 * technique. Une sonde qui finit par répondre au bout de trois
                 * minutes doit faire repartir le modèle vers Jeedom, sinon le
                 * nom obtenu n'est jamais appliqué et la sonde n'aura servi à
                 * rien. Le coût est nul dans l'autre sens : un nom qui ne change
                 * pas donne la même empreinte, et rien n'est réécrit.
                 *
                 * `probe`, lui, n'entre dans AUCUNE des deux empreintes : il ne
                 * décrit pas l'appareil mais la façon de l'interroger, il porte
                 * l'adresse IP — qui change au gré du bail DHCP — et Jeedom n'en
                 * écrit rien. L'y mettre ferait réécrire tout le parc à chaque
                 * renouvellement de bail.
                 */
                'device_name'     => $this->meta['device_name'],
                'manufacturer'    => $this->meta['manufacturer'],
                'model'           => $this->meta['model'],
                'model_name'      => $this->meta['model_name'],
                'generation'      => $this->meta['generation'],
                'battery_powered' => $this->meta['battery_powered'],
            ),
            'availability' => $this->availability,
            'channels'     => $canaux,
        ));
    }

    /* --------------------------------------------------------------------- */
    /* Validation                                                            */
    /* --------------------------------------------------------------------- */

    /*
     * Rend la liste des motifs de refus, en clair, et ne lève jamais : un
     * modèle à moitié bon doit pouvoir être présenté à l'utilisateur, qui
     * corrigera ce que l'adapter n'a pas su deviner.
     *
     * Ce qui est refusé ici est exactement ce qui casserait plus loin, et plus
     * loin veut dire : au moment de l'enregistrement en base, sur une erreur
     * SQL qui ne désigne pas sa cause.
     */
    public function validate() {
        $fautes = array();

        if ($this->schema > self::SCHEMA) {
            $fautes[] = 'modèle de schéma ' . $this->schema . ', alors que ce plugin en connaît '
                      . self::SCHEMA . ' : il a été écrit par une version plus récente.';
        }
        if ($this->identity['uid'] === '') {
            $fautes[] = 'identité : uid vide — c\'est le logicalId de l\'équipement, et sans lui '
                      . 'chaque découverte créerait un nouvel équipement.';
        } elseif (MqttbeChannel::length($this->identity['uid']) > self::MAX_UID) {
            $fautes[] = 'identité : uid de ' . MqttbeChannel::length($this->identity['uid'])
                      . ' caractères, ' . self::MAX_UID . ' au maximum (eqLogic.logicalId est un varchar(127), '
                      . 'au-delà il est tronqué en base et l\'appareil n\'est plus retrouvé).';
        }
        if ($this->identity['adapter'] === '') {
            $fautes[] = 'identité : adapter vide — on ne saurait plus qui a produit ce modèle.';
        }
        if (!in_array($this->identity['confidence'], self::CONFIDENCES, true)) {
            $fautes[] = 'identité : confiance « ' . $this->identity['confidence'] . ' » inconnue, attendu '
                      . implode(', ', self::CONFIDENCES) . '.';
        }
        if (empty($this->channels)) {
            $fautes[] = 'aucun canal : l\'équipement serait créé sans une seule commande.';
        }
        foreach (array_unique($this->duplicates) as $doublon) {
            $fautes[] = 'canal « ' . $doublon . ' » : clé en double — deux canaux ne peuvent pas '
                      . 'porter le même logicalId sur le même équipement.';
        }
        foreach ($this->channels as $canal) {
            $fautes = array_merge($fautes, $canal->validate());
        }
        return $fautes;
    }

    public function isValid() {
        return count($this->validate()) === 0;
    }

    /* --------------------------------------------------------------------- */
    /* Outils internes                                                       */
    /* --------------------------------------------------------------------- */

    /*
     * Forme canonique : clés triées à tous les niveaux, valeurs vides ôtées.
     *
     * C'est ce qui rend l'empreinte insensible à l'ordre et aux champs remplis
     * « à vide » par un adapter consciencieux. Le zéro et le faux, eux, sont
     * conservés : `retain: false` et `qos: 0` sont des décisions, pas des
     * absences.
     */
    private static function canonical($_valeur) {
        if (!is_array($_valeur)) {
            return $_valeur;
        }
        $sortie = array();
        foreach ($_valeur as $cle => $valeur) {
            $valeur = self::canonical($valeur);
            if ($valeur === null || $valeur === '' || $valeur === array()) {
                continue;
            }
            $sortie[$cle] = $valeur;
        }
        if (self::isList($_valeur)) {
            return array_values($sortie);
        }
        ksort($sortie, SORT_STRING);
        return $sortie;
    }

    private static function isList($_tableau) {
        return $_tableau === array() || array_keys($_tableau) === range(0, count($_tableau) - 1);
    }

    private static function compareCanonical($_a, $_b) {
        return strcmp(json_encode($_a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                      json_encode($_b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeAvailability($_valeurs) {
        $topic = isset($_valeurs['topic']) ? trim((string) $_valeurs['topic']) : '';
        if ($topic === '') {
            return array();
        }
        /* Les deux charges utiles ont un défaut parce que la moitié des
         * appareils ne publient que « online » et laissent le testament MQTT
         * dire le contraire : sans défaut, la commande de disponibilité serait
         * créée sans savoir ce qui vaut « joignable ». */
        return array(
            'topic'       => $topic,
            'payload_on'  => isset($_valeurs['payload_on']) ? (string) $_valeurs['payload_on'] : 'online',
            'payload_off' => isset($_valeurs['payload_off']) ? (string) $_valeurs['payload_off'] : 'offline',
        );
    }

    /*
     * Le nom d'un appareil, tel qu'il est utilisable — la première défense, et
     * la seule qui soit à la source.
     *
     * Ce qui arrive ici vient d'une réponse d'appareil sur le réseau local :
     * une chaîne que l'appareil compose comme il veut, et que rien n'oblige à
     * être honnête. Quatre gestes, dans cet ordre :
     *
     *   - SEULE UNE CHAÎNE est acceptée. Un objet, un tableau, un booléen ou un
     *     nombre ne sont pas un nom : `{"name": {"fr": "chaudière"}}` donnerait
     *     « Array », qui s'écrirait tel quel dans le nom de l'équipement.
     *   - Les balises sont ôtées. La fabrique et l'interface échappent ce
     *     qu'elles affichent, mais un nom qui traverse le démon, la base et
     *     trois gabarits finit toujours par ressortir quelque part sans échappe.
     *   - Les caractères de contrôle deviennent des espaces, et les espaces se
     *     réduisent : un retour à la ligne dans un nom fabrique une fausse
     *     entrée dans tous les journaux qui le citent.
     *   - La longueur est bornée, sur des CARACTÈRES et non des octets : une
     *     coupe à 64 octets au milieu d'un « é » produit une chaîne qui n'est
     *     plus de l'UTF-8, et json_encode rend alors `false` pour le lot entier.
     */
    public static function cleanDeviceName($_valeur) {
        if (!is_string($_valeur)) {
            return '';
        }
        $nom = strip_tags($_valeur);
        $nom = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $nom);
        if ($nom === null) {
            /* Entrée non-UTF-8 : preg_replace rend null plutôt que de couper au
             * milieu d'un caractère. Un nom illisible vaut pas de nom. */
            return '';
        }
        $nom = trim(preg_replace('/\s+/u', ' ', $nom));
        if ($nom === '') {
            return '';
        }
        if (MqttbeChannel::length($nom) > self::MAX_DEVICE_NAME) {
            $nom = function_exists('mb_substr')
                 ? mb_substr($nom, 0, self::MAX_DEVICE_NAME, 'UTF-8')
                 : substr($nom, 0, self::MAX_DEVICE_NAME);
            $nom = trim($nom);
        }
        return $nom;
    }

    /*
     * Une sonde, réduite à ce que le moteur saura exécuter.
     *
     * `type` est le seul champ obligatoire, et il n'est PAS validé contre une
     * liste : c'est lui qui permettra à `mqtt.rpc` (Shelly Gen2+) de s'ajouter
     * sans toucher à ce fichier. Un moteur qui ne connaît pas un type l'ignore ;
     * un modèle qui porte une sonde d'un type inconnu reste un modèle valide, et
     * l'appareil est découvert sans son nom d'usage plutôt que pas du tout.
     *
     * Les clés que ce fichier ne connaît pas sont conservées si elles sont
     * scalaires — un type à venir aura ses propres paramètres — mais jamais les
     * structures imbriquées : elles ne serviraient à personne et feraient
     * voyager n'importe quoi jusqu'à Jeedom.
     */
    public static function normalizeProbe($_valeur) {
        if (!is_array($_valeur) || empty($_valeur)) {
            return array();
        }
        $type = isset($_valeur['type']) && !is_array($_valeur['type'])
              ? strtolower(trim((string) $_valeur['type'])) : '';
        if ($type === '') {
            return array();
        }
        $sonde = array('type' => $type);
        foreach ($_valeur as $cle => $valeur) {
            $cle = (string) $cle;
            if ($cle === 'type' || $cle === 'ttl' || is_array($valeur) || is_object($valeur)) {
                continue;
            }
            $sonde[$cle] = trim((string) $valeur);
        }
        $ttl = isset($_valeur['ttl']) && is_numeric($_valeur['ttl'])
             ? (int) $_valeur['ttl'] : self::PROBE_TTL_DEFAULT;
        $sonde['ttl'] = max(self::PROBE_TTL_MIN, min(self::PROBE_TTL_MAX, $ttl));
        ksort($sonde, SORT_STRING);
        return $sonde;
    }

    private static function text($_valeurs, $_cle) {
        return isset($_valeurs[$_cle]) ? trim((string) $_valeurs[$_cle]) : '';
    }

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
