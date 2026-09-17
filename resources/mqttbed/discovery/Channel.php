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
 * Un canal : une valeur qu'on lit, une commande qu'on publie, ou les deux.
 *
 * Structure de données pure. Aucune notion de Jeedom ici — ni eqLogic, ni cmd,
 * ni journal, ni configuration : ce fichier est chargé aussi bien par le démon,
 * qui n'a pas de Jeedom sous la main, que par le processus web. La traduction
 * en commande Jeedom est le travail de la fabrique, et d'elle seule.
 *
 * Le canal ne décide pas non plus d'un type Jeedom : il désigne une *capacité*
 * (`switch.state`, `sensor.temperature`), et c'est core/config/capabilities.json
 * qui traduit. Un adapter qui choisirait `info`/`binary` lui-même mettrait une
 * décision de présentation dans du code de protocole ; elle vivrait alors en
 * autant d'exemplaires qu'il y a d'adapters.
 * ========================================================================== */
class MqttbeChannel {

    /* `key` devient le logicalId de la commande. La colonne accepte 1023
     * caractères, mais la clé sert aussi d'identifiant affiché et de repère
     * dans les journaux : au-delà de 127 elle ne désigne plus rien de lisible,
     * et la même limite que l'uid évite d'avoir deux règles à retenir. */
    const MAX_KEY = 127;

    const QOS_MAX = 2;

    private $key        = '';
    private $capability = '';
    private $name       = '';
    private $unit       = '';

    /* source : {topic, selector{...}} — ce qu'on lit sur le broker.
     * sink   : {topic, payload, qos, retain, ...} — ce qu'on y publie.
     * value  : description de la valeur (type, bornes, arrondi…), laissée
     *          telle quelle : c'est le vocabulaire des adapters, qui grandira.
     * links  : rôle => clé d'un autre canal du même modèle (action -> info). */
    private $source = array();
    private $sink   = array();
    private $value  = array();
    private $links  = array();

    /* Vocabulaire des capacités connues, ou null tant que personne ne l'a
     * chargé. Le distinguer d'un vocabulaire vide est essentiel : « je ne sais
     * pas » ne doit pas se traduire par « toutes les capacités sont
     * inconnues », ce qui condamnerait tous les modèles du parc dès que le
     * fichier de vocabulaire n'est pas lisible. */
    private static $vocabulary = null;

    public function __construct($_values = array()) {
        if (!is_array($_values)) {
            return;
        }
        $this->key        = self::text($_values, 'key');
        $this->capability = self::text($_values, 'capability');
        $this->name       = self::text($_values, 'name');
        $this->unit       = self::text($_values, 'unit');

        if (isset($_values['source']) && is_array($_values['source'])) {
            $this->source = self::normalizeSource($_values['source']);
        }
        if (isset($_values['sink']) && is_array($_values['sink'])) {
            $this->sink = self::normalizeSink($_values['sink']);
        }
        if (isset($_values['value']) && is_array($_values['value'])) {
            $this->value = $_values['value'];
        }
        if (isset($_values['links']) && is_array($_values['links'])) {
            foreach ($_values['links'] as $role => $cible) {
                $this->links[(string) $role] = (string) $cible;
            }
        }
    }

    public static function fromArray($_values) {
        return new self($_values);
    }

    /* --------------------------------------------------------------------- */
    /* Lecture                                                               */
    /* --------------------------------------------------------------------- */

    public function key()        { return $this->key; }
    public function capability() { return $this->capability; }
    public function name()       { return $this->name; }
    public function unit()       { return $this->unit; }
    public function source()     { return $this->source; }
    public function sink()       { return $this->sink; }
    public function value()      { return $this->value; }
    public function links()      { return $this->links; }

    public function hasSource() { return isset($this->source['topic']) && $this->source['topic'] !== ''; }
    public function hasSink()   { return isset($this->sink['topic']) && $this->sink['topic'] !== ''; }

    public function sourceTopic()    { return $this->hasSource() ? $this->source['topic'] : ''; }
    public function sourceSelector() { return isset($this->source['selector']) ? $this->source['selector'] : array('type' => 'raw'); }

    public function sinkTopic()   { return $this->hasSink() ? $this->sink['topic'] : ''; }
    public function sinkPayload() { return isset($this->sink['payload']) ? $this->sink['payload'] : ''; }
    public function sinkQos()     { return isset($this->sink['qos']) ? $this->sink['qos'] : 0; }
    public function sinkRetain()  { return isset($this->sink['retain']) ? $this->sink['retain'] : false; }

    /* Le canal que cette action pilote : Jeedom en fait `cmd.value`, et c'est
     * lui qui donne à un bouton « On » son état à l'écran. */
    public function link($_role = 'state') {
        return isset($this->links[$_role]) ? $this->links[$_role] : '';
    }

    /* --------------------------------------------------------------------- */
    /* Sérialisation                                                         */
    /* --------------------------------------------------------------------- */

    /*
     * Ordre des clés fixe et parties vides omises : deux canaux équivalents
     * doivent produire le même texte, sans quoi l'empreinte changerait au
     * gré de la façon dont l'adapter a rempli le tableau.
     */
    public function toArray() {
        $sortie = array('key' => $this->key, 'capability' => $this->capability);
        if ($this->name !== '') {
            $sortie['name'] = $this->name;
        }
        if ($this->unit !== '') {
            $sortie['unit'] = $this->unit;
        }
        if (!empty($this->source)) {
            $sortie['source'] = $this->source;
        }
        if (!empty($this->sink)) {
            $sortie['sink'] = $this->sink;
        }
        if (!empty($this->value)) {
            $sortie['value'] = $this->value;
        }
        if (!empty($this->links)) {
            $sortie['links'] = $this->links;
        }
        return $sortie;
    }

    /* --------------------------------------------------------------------- */
    /* Validation                                                            */
    /* --------------------------------------------------------------------- */

    /*
     * Rend la liste des motifs de refus, en clair, et ne lève jamais.
     *
     * Un modèle à moitié bon a de la valeur : on le montre à l'utilisateur avec
     * ce qui cloche, il corrige le topic ou la capacité et l'adopte. Une
     * exception, elle, ne laisserait que la trace d'un appareil disparu.
     */
    public function validate() {
        $fautes = array();
        /* Une clé fautive est souvent une clé démesurée : la citer en entier
         * noierait le motif de refus dans le bruit qu'il dénonce. */
        $court = MqttbeChannel::length($this->key) > 40
            ? mb_substr($this->key, 0, 40, 'UTF-8') . '…' : $this->key;
        $etiquette = $this->key !== '' ? 'canal « ' . $court . ' »' : 'canal sans clé';

        if ($this->key === '') {
            $fautes[] = $etiquette . ' : clé vide — c\'est elle qui devient le logicalId de la commande et qui la retrouve d\'une découverte à l\'autre.';
        } elseif (self::length($this->key) > self::MAX_KEY) {
            $fautes[] = $etiquette . ' : clé de ' . self::length($this->key) . ' caractères, ' . self::MAX_KEY . ' au maximum.';
        }

        if ($this->capability === '') {
            $fautes[] = $etiquette . ' : capacité absente — sans elle, rien ne dit quel type de commande Jeedom créer.';
        } elseif (self::$vocabulary !== null && !isset(self::$vocabulary[$this->capability])) {
            $fautes[] = $etiquette . ' : capacité « ' . $this->capability . ' » inconnue de capabilities.json — la commande serait créée sans type générique.';
        }

        if (!$this->hasSource() && !$this->hasSink()) {
            $fautes[] = $etiquette . ' : ni source ni destination — rien à lire, rien à publier, la commande ne ferait jamais rien.';
        }
        /* Un bloc présent mais sans topic est plus grave qu'un bloc absent :
         * l'adapter a cru décrire quelque chose. Sans ce contrôle, l'abonnement
         * se ferait sur la chaîne vide. */
        if (!empty($this->source) && !$this->hasSource()) {
            $fautes[] = $etiquette . ' : source sans topic.';
        }
        if (!empty($this->sink) && !$this->hasSink()) {
            $fautes[] = $etiquette . ' : destination sans topic.';
        }

        return $fautes;
    }

    public function isValid() {
        return count($this->validate()) === 0;
    }

    /* --------------------------------------------------------------------- */
    /* Vocabulaire des capacités                                             */
    /* --------------------------------------------------------------------- */

    /*
     * Accepte indifféremment le contenu de capabilities.json, sa section
     * `capabilities`, ou une simple liste de clés : l'appelant (démon ou
     * processus web) n'a pas à connaître la forme du fichier pour armer la
     * validation.
     */
    public static function useCapabilities($_source) {
        if ($_source === null) {
            self::$vocabulary = null;
            return;
        }
        if (!is_array($_source)) {
            return;
        }
        if (isset($_source['capabilities']) && is_array($_source['capabilities'])) {
            $_source = $_source['capabilities'];
        }
        $vocabulaire = array();
        foreach ($_source as $cle => $valeur) {
            $vocabulaire[is_array($valeur) ? (string) $cle : (string) $valeur] = true;
        }
        self::$vocabulary = $vocabulaire;
    }

    /* Le chemin est fourni par l'appelant : ce fichier ne sait pas où Jeedom
     * range ses plugins, et ne doit pas l'apprendre. */
    public static function loadCapabilities($_chemin) {
        if (!is_string($_chemin) || !is_readable($_chemin)) {
            return false;
        }
        $brut = file_get_contents($_chemin);
        if ($brut === false) {
            return false;
        }
        $decode = json_decode($brut, true);
        if (!is_array($decode)) {
            return false;
        }
        self::useCapabilities($decode);
        return true;
    }

    public static function vocabulary() {
        return self::$vocabulary;
    }

    /* --------------------------------------------------------------------- */
    /* Normalisation                                                         */
    /* --------------------------------------------------------------------- */

    private static function normalizeSource($_source) {
        $source = $_source;
        $source['topic'] = isset($_source['topic']) ? trim((string) $_source['topic']) : '';
        /* Un sélecteur absent vaut « la charge utile telle quelle » : c'est le
         * cas le plus courant (Shelly Gen1 publie une valeur par topic), et le
         * rendre explicite évite que chaque consommateur réinvente ce défaut. */
        $source['selector'] = (isset($_source['selector']) && is_array($_source['selector']))
            ? $_source['selector'] : array('type' => 'raw');
        return $source;
    }

    private static function normalizeSink($_sink) {
        $sink = $_sink;
        $sink['topic'] = isset($_sink['topic']) ? trim((string) $_sink['topic']) : '';
        if (!array_key_exists('payload', $sink)) {
            $sink['payload'] = '';
        }
        /* qos et retain arrivent tantôt en JSON, tantôt en "1"/"0" d'un champ
         * de formulaire : sans normalisation, le même canal donnerait deux
         * empreintes selon son origine, et la fabrique réécrirait la base. */
        $qos = isset($_sink['qos']) ? (int) $_sink['qos'] : 0;
        $sink['qos'] = ($qos < 0 || $qos > self::QOS_MAX) ? 0 : $qos;
        $sink['retain'] = isset($_sink['retain']) ? self::toBool($_sink['retain']) : false;
        return $sink;
    }

    private static function text($_values, $_cle) {
        return isset($_values[$_cle]) ? trim((string) $_values[$_cle]) : '';
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

    /* Longueur en caractères : les limites de Jeedom sont des varchar, donc
     * des caractères, et une clé accentuée compterait double en octets. */
    public static function length($_texte) {
        if (function_exists('mb_strlen')) {
            return mb_strlen($_texte, 'UTF-8');
        }
        return strlen(preg_replace('/[\x80-\xBF]/', '', $_texte));
    }
}
