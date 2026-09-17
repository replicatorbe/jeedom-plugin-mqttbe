<?php
/* Simulacre du cœur de Jeedom, pour éprouver les classes qui écrivent en base.
 *
 * Les autres contrôles de tests/ lisent le texte des sources. Ceux-ci font
 * autre chose : ils font TOURNER mqttbeFactory, mqttbeRouting, mqttbe et
 * mqttbeCmd — les seules classes du plugin capables d'abîmer l'installation de
 * quelqu'un — et regardent ce qu'elles écrivent. Pour cela il faut un cœur, et
 * on ne peut pas prendre le vrai : il suppose une base MySQL, une session, un
 * Apache et un plugin installé.
 *
 * Ce fichier est donc un cœur de papier. Sa règle de conduite tient en une
 * phrase : il doit se tromper aux mêmes endroits que le vrai. Là où le vrai
 * cœur est laxiste, il l'est aussi ; là où MySQL refuse, il refuse. Un
 * simulacre plus permissif que la réalité laisserait passer exactement les
 * pannes qu'on cherche à attraper — et un simulacre plus sévère ferait échouer
 * du code parfaitement bon.
 *
 * Ce qui est reproduit fidèlement, et pourquoi :
 *
 *   - « cmd (eqLogic_id, name) » et « eqLogic (name, object_id) » sont UNIQUES.
 *     Un doublon lève, comme MySQL. C'est le défaut qui fait disparaître un
 *     équipement de l'interface sans un mot dans le journal du plugin ;
 *   - les longueurs de colonnes : eqLogic.name et eqLogic.logicalId en
 *     varchar(127), cmd.name en varchar(127), cmd.logicalId en varchar(1023),
 *     cmd.unite en varchar(45), cmd.value en varchar(255). Le cœur tronque les
 *     noms lui-même (setName) mais PAS le logicalId : un uid trop long part
 *     tel quel vers MySQL, qui le rejette. C'est la raison d'être du contrôle
 *     de longueur de la fabrique ;
 *   - une lecture rend toujours des objets NEUFS, reconstruits depuis les
 *     colonnes. Le vrai cœur relit la base à chaque requête : une modification
 *     faite en mémoire et jamais enregistrée est invisible pour tout le monde.
 *     Un simulacre qui rendrait le même objet PHP masquerait tout oubli
 *     d'enregistrement ;
 *   - getConfiguration() rend le défaut quand la valeur vaut la chaîne vide —
 *     utils::getJsonAttr() fait exactement cela, et c'est contre-intuitif ;
 *   - setConfiguration() ne marque l'objet « modifié » que si le JSON obtenu
 *     diffère du précédent (utils::attrChanged) : réécrire la même valeur ne
 *     déclenche aucune écriture, et l'idempotence de la fabrique repose
 *     là-dessus ;
 *   - cmd::save() impose un nom, un type, un sous-type et un équipement, force
 *     « core::default » comme widget et coupe l'historisation d'une action ;
 *   - eqLogic::getCmd($type, $logicalId) cherche par LOGICALID et non par nom.
 *     Le piège est vérifié dans /var/www/html/core/class/eqLogic.class.php.
 *
 * Ce qui est réduit au strict nécessaire : config, cache, log, event, message,
 * et un double du démon qui note ce qu'on lui envoie au lieu de l'envoyer.
 *
 * Le nombre d'écritures en base est compté. C'est ce compteur qui permet de
 * prouver l'idempotence : « rien n'a changé » ne se démontre pas en relisant
 * l'équipement, mais en constatant qu'aucun INSERT ni aucun UPDATE n'est parti.
 */

/* --------------------------------------------------------------------------
 * 1. Les fonctions globales du cœur
 *
 * Elles vivent dans core/php/utils.inc.php et le code du plugin les appelle
 * sans les inclure. cleanComponanteName() est recopiée à l'identique : la
 * fabrique calcule l'unicité des noms sur son résultat, et une version
 * approximative ici validerait une déduplication qui, en vrai, ne dédoublonne
 * pas.
 * ------------------------------------------------------------------------ */

if (!function_exists('__')) {
    function __($_texte, $_fichier = '') {
        return $_texte;
    }
}

if (!function_exists('cleanComponanteName')) {
    function cleanComponanteName($_name) {
        $return = strip_tags(str_replace(array('&', '#', ']', '[', '%', "\\", "/", "'", '"', "*"), '', $_name));
        return preg_replace('/\s+/', ' ', $return);
    }
}

if (!function_exists('is_json')) {
    function is_json($_string, $_default = null) {
        if ($_default !== null) {
            if (!is_string($_string)) {
                return $_default;
            }
            $return = json_decode($_string, true, 512, JSON_BIGINT_AS_STRING);
            return is_array($return) ? $return : $_default;
        }
        return (is_string($_string) && is_array(json_decode($_string, true, 512, JSON_BIGINT_AS_STRING)));
    }
}

/* --------------------------------------------------------------------------
 * 2. La base
 *
 * Deux tables, des colonnes typées par leur longueur, deux contraintes
 * d'unicité, un compteur d'écritures. Rien d'autre : ni jointure, ni
 * transaction — la fabrique n'en emploie pas.
 * ------------------------------------------------------------------------ */

class MqttbeFauxDb {

    /** Longueurs des colonnes, telles que install/database.json les décrit. */
    private static $_colonnes = array(
        'eqLogic' => array(
            'name' => 127, 'logicalId' => 127, 'eqType_name' => 127,
            'generic_type' => 255, 'tags' => 255,
        ),
        'cmd' => array(
            'eqType' => 127, 'logicalId' => 1023, 'name' => 127,
            'isHistorized' => 45, 'type' => 45, 'subType' => 45,
            'unite' => 45, 'value' => 255, 'generic_type' => 255,
        ),
    );

    /** Index uniques composites. */
    private static $_uniques = array(
        'eqLogic' => array('name', 'object_id'),
        'cmd'     => array('eqLogic_id', 'name'),
    );

    private static $_tables    = array('eqLogic' => array(), 'cmd' => array());
    private static $_sequence  = array('eqLogic' => 0, 'cmd' => 0);
    private static $_ecritures = 0;

    /* Deux lectures successives ne rendent pas forcément les lignes dans le
     * même ordre : MySQL ne le garantit qu'avec un ORDER BY total, et le vrai
     * cœur trie sur des colonnes pleines d'ex æquo (ORDER BY ob.name, el.name).
     * Ce drapeau rend cette liberté visible : une construction de table de
     * routage qui dépend de l'ordre de la base se met alors à changer d'octets
     * sans que rien n'ait changé. */
    public static $ordreInstable = false;

    public static function reinitialise() {
        self::$_tables    = array('eqLogic' => array(), 'cmd' => array());
        self::$_sequence  = array('eqLogic' => 0, 'cmd' => 0);
        self::$_ecritures = 0;
        self::$ordreInstable = false;
    }

    /** Nombre d'INSERT, d'UPDATE et de DELETE depuis la réinitialisation. */
    public static function ecritures() {
        return self::$_ecritures;
    }

    public static function lignes($_table) {
        $lignes = array_values(self::$_tables[$_table]);
        if (self::$ordreInstable) {
            $lignes = array_reverse($lignes);
        }
        return $lignes;
    }

    public static function ligne($_table, $_id) {
        return isset(self::$_tables[$_table][$_id]) ? self::$_tables[$_table][$_id] : null;
    }

    public static function insere($_table, $_colonnes) {
        self::verifie($_table, $_colonnes, null);
        $id = ++self::$_sequence[$_table];
        $_colonnes['id'] = $id;
        self::$_tables[$_table][$id] = $_colonnes;
        self::$_ecritures++;
        return $id;
    }

    public static function metAJour($_table, $_id, $_colonnes) {
        self::verifie($_table, $_colonnes, $_id);
        $_colonnes['id'] = $_id;
        self::$_tables[$_table][$_id] = $_colonnes;
        self::$_ecritures++;
    }

    public static function supprime($_table, $_id) {
        unset(self::$_tables[$_table][$_id]);
        self::$_ecritures++;
    }

    /**
     * Ce que MySQL refuserait.
     *
     * Les messages reprennent les siens : c'est sous cette forme qu'ils
     * apparaissent dans /var/www/html/log/http.error, et un contrôle qui
     * échoue doit désigner la panne réelle, pas une paraphrase.
     */
    private static function verifie($_table, $_colonnes, $_id) {
        foreach (self::$_colonnes[$_table] as $colonne => $longueur) {
            if (!isset($_colonnes[$colonne]) || $_colonnes[$colonne] === null) {
                continue;
            }
            $valeur = (string) $_colonnes[$colonne];
            $taille = function_exists('mb_strlen') ? mb_strlen($valeur, 'UTF-8') : strlen($valeur);
            if ($taille > $longueur) {
                throw new Exception("SQLSTATE[22001]: String data, right truncated: 1406 "
                    . "Data too long for column '" . $colonne . "' (" . $taille . " > " . $longueur . ")");
            }
        }

        $cles = self::$_uniques[$_table];
        $signature = array();
        foreach ($cles as $cle) {
            $valeur = isset($_colonnes[$cle]) ? $_colonnes[$cle] : null;
            if ($valeur === null || $valeur === '') {
                /* Un index unique de MySQL laisse passer les NULL : deux lignes
                 * dont l'object_id est nul ne se gênent pas, même à nom égal.
                 * Le simuler autrement ferait échouer ici du code qui, en
                 * production, marche très bien. */
                return;
            }
            $signature[] = (string) $valeur;
        }
        $signature = implode("\0", $signature);
        foreach (self::$_tables[$_table] as $id => $ligne) {
            if ($_id !== null && $id == $_id) {
                continue;
            }
            $autre = array();
            foreach ($cles as $cle) {
                $valeur = isset($ligne[$cle]) ? $ligne[$cle] : null;
                if ($valeur === null || $valeur === '') {
                    continue 2;
                }
                $autre[] = (string) $valeur;
            }
            if (implode("\0", $autre) === $signature) {
                throw new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 "
                    . "Duplicate entry '" . str_replace("\0", '-', $signature) . "' for key '" . $_table . ".unique'");
            }
        }
    }
}

/* --------------------------------------------------------------------------
 * 3. eqLogic et cmd
 *
 * Les accesseurs sont ceux du vrai cœur, y compris leur façon de marquer
 * l'objet modifié : c'est ce drapeau que la fabrique interroge pour décider
 * d'enregistrer ou non, et le reproduire de travers ferait passer pour
 * idempotent du code qui ne l'est pas.
 * ------------------------------------------------------------------------ */

abstract class MqttbeFauxEntite {

    /* utils::attrChanged() : un tableau est comparé par son JSON, et le
     * drapeau, une fois levé, ne redescend qu'à l'enregistrement. */
    protected function attrChanged($_ancien, $_nouveau) {
        if ($this->_changed) {
            return true;
        }
        if (is_array($_ancien)) {
            $_ancien = json_encode($_ancien);
        }
        if (is_array($_nouveau)) {
            $_nouveau = json_encode($_nouveau);
        }
        return ($_ancien != $_nouveau);
    }

    /* utils::getJsonAttr() : la chaîne vide vaut absence, et la colonne est
     * décodée sur place à la première lecture. */
    protected function jsonGet(&$_attr, $_key = '', $_default = '') {
        if (!is_array($_attr)) {
            if ($_key == '') {
                return is_json($_attr, array());
            }
            if (empty($_attr)) {
                return $_default;
            }
            $_attr = json_decode($_attr, true);
            if (!is_array($_attr)) {
                $_attr = array();
            }
        }
        if ($_key == '') {
            return $_attr;
        }
        return (isset($_attr[$_key]) && $_attr[$_key] !== '') ? $_attr[$_key] : $_default;
    }

    /* utils::setJsonAttr() : une valeur nulle EFFACE la clé. */
    protected function jsonSet($_attr, $_key, $_value = null) {
        if (!is_array($_attr)) {
            $_attr = is_json($_attr, array());
        }
        if ($_value === null) {
            unset($_attr[$_key]);
        } else {
            $_attr[$_key] = $_value;
        }
        return $_attr;
    }

    protected function colonneJson($_valeur) {
        if (is_array($_valeur)) {
            return empty($_valeur) ? null : json_encode($_valeur);
        }
        return $_valeur === '' ? null : $_valeur;
    }
}

class eqLogic extends MqttbeFauxEntite {

    protected $id = '';
    protected $name = '';
    protected $logicalId = '';
    protected $object_id = null;
    protected $eqType_name = '';
    protected $configuration = null;
    protected $isVisible = 1;
    protected $isEnable = 1;
    protected $order = 0;
    protected $timeout = null;
    protected $category = null;
    protected $display = null;
    protected $comment = null;
    protected $generic_type = null;
    protected $tags = null;
    protected $status = null;
    protected $_changed = false;

    /* ------------------------------------------------------------ lectures */

    /**
     * Hydratation depuis une ligne.
     *
     * La classe instanciée est celle du plugin quand elle existe : le cœur fait
     * de même (PDO::FETCH_CLASS $_eqType_name), et c'est ce qui donne à
     * mqttbe::postSave() l'occasion de s'exécuter.
     */
    protected static function hydrate($_ligne) {
        $classe = isset($_ligne['eqType_name']) && class_exists((string) $_ligne['eqType_name'])
                ? (string) $_ligne['eqType_name'] : 'eqLogic';
        if (!is_subclass_of($classe, 'eqLogic') && $classe !== 'eqLogic') {
            $classe = 'eqLogic';
        }
        $objet = new $classe();
        foreach ($_ligne as $colonne => $valeur) {
            if (property_exists($objet, $colonne)) {
                $objet->$colonne = $valeur;
            }
        }
        $objet->_changed = false;
        return $objet;
    }

    public static function byId($_id) {
        $ligne = MqttbeFauxDb::ligne('eqLogic', $_id);
        return $ligne === null ? null : self::hydrate($ligne);
    }

    public static function byLogicalId($_logicalId, $_eqType_name, $_multiple = false) {
        $trouves = array();
        foreach (MqttbeFauxDb::lignes('eqLogic') as $ligne) {
            if ((string) $ligne['logicalId'] === (string) $_logicalId
                && (string) $ligne['eqType_name'] === (string) $_eqType_name) {
                $trouves[] = self::hydrate($ligne);
            }
        }
        if ($_multiple) {
            return $trouves;
        }
        return empty($trouves) ? null : $trouves[0];
    }

    public static function byType($_eqType_name, $_onlyEnable = false) {
        $trouves = array();
        foreach (MqttbeFauxDb::lignes('eqLogic') as $ligne) {
            if ((string) $ligne['eqType_name'] !== (string) $_eqType_name) {
                continue;
            }
            if ($_onlyEnable && (int) $ligne['isEnable'] !== 1) {
                continue;
            }
            $trouves[] = self::hydrate($ligne);
        }
        return $trouves;
    }

    public static function byObjectId($_object_id, $_onlyEnable = true, $_onlyVisible = false,
                                      $_eqType_name = null, $_logicalId = null) {
        $trouves = array();
        foreach (MqttbeFauxDb::lignes('eqLogic') as $ligne) {
            $objet = isset($ligne['object_id']) ? $ligne['object_id'] : null;
            if ($_object_id === null) {
                if ($objet !== null && $objet != -1) {
                    continue;
                }
            } elseif ($objet === null || $objet != $_object_id) {
                continue;
            }
            if ($_onlyEnable && (int) $ligne['isEnable'] !== 1) {
                continue;
            }
            if ($_onlyVisible && (int) $ligne['isVisible'] !== 1) {
                continue;
            }
            if ($_eqType_name !== null && (string) $ligne['eqType_name'] !== (string) $_eqType_name) {
                continue;
            }
            if ($_logicalId !== null && (string) $ligne['logicalId'] !== (string) $_logicalId) {
                continue;
            }
            $trouves[] = self::hydrate($ligne);
        }
        return $trouves;
    }

    public static function all($_onlyEnable = false) {
        $trouves = array();
        foreach (MqttbeFauxDb::lignes('eqLogic') as $ligne) {
            if ($_onlyEnable && (int) $ligne['isEnable'] !== 1) {
                continue;
            }
            $trouves[] = self::hydrate($ligne);
        }
        return $trouves;
    }

    public static function searchConfiguration($_configuration, $_eqType = null) {
        return array();
    }

    /* ----------------------------------------------------------- écriture */

    /**
     * Enregistrement.
     *
     * L'enchaînement est celui de DB::save() : preSave, l'écriture — sautée si
     * rien n'a changé —, postSave, puis le drapeau « modifié » redescendu.
     * C'est postSave() qui, côté plugin, programme l'envoi de la table de
     * routage : le sauter ici retirerait aux contrôles la moitié de ce qu'ils
     * observent.
     */
    public function save($_direct = false) {
        if ($this->getName() == '') {
            throw new Exception('Le nom de l\'équipement ne peut pas être vide');
        }
        if (!$_direct && method_exists($this, 'preSave')) {
            $this->preSave();
        }
        if ($this->id === '' || $this->id === null) {
            $this->id = MqttbeFauxDb::insere('eqLogic', $this->colonnes());
        } elseif ($this->_changed) {
            MqttbeFauxDb::metAJour('eqLogic', $this->id, $this->colonnes());
        }
        if (!$_direct && method_exists($this, 'postSave')) {
            $this->postSave();
        }
        $this->_changed = false;
        return true;
    }

    public function remove() {
        foreach ($this->getCmd() as $cmd) {
            $cmd->remove();
        }
        if ($this->id !== '' && $this->id !== null) {
            MqttbeFauxDb::supprime('eqLogic', $this->id);
        }
        if (method_exists($this, 'postRemove')) {
            $this->postRemove();
        }
        return true;
    }

    private function colonnes() {
        return array(
            'name'         => $this->name,
            'logicalId'    => $this->logicalId,
            'object_id'    => $this->object_id,
            'eqType_name'  => $this->eqType_name,
            'configuration' => $this->colonneJson($this->configuration),
            'isVisible'    => $this->isVisible,
            'isEnable'     => $this->isEnable,
            'order'        => $this->order,
            'timeout'      => $this->timeout,
            'category'     => $this->colonneJson($this->category),
            'display'      => $this->colonneJson($this->display),
            'comment'      => $this->comment,
            'generic_type' => $this->generic_type,
            'tags'         => $this->tags,
            'status'       => $this->colonneJson($this->status),
        );
    }

    /* ---------------------------------------------------------- commandes */

    /**
     * Les commandes de l'équipement.
     *
     * Le deuxième paramètre est un LOGICALID, jamais un nom : le piège est
     * classique, et un simulacre qui chercherait par nom rendrait vert un code
     * qui, en vrai, ne trouve rien.
     */
    public function getCmd($_type = null, $_logicalId = null, $_visible = null, $_multiple = false) {
        $cmds = cmd::byEqLogicId($this->id, $_type, $_visible, $this);
        if ($_logicalId === null) {
            return $cmds;
        }
        $trouves = array();
        foreach ($cmds as $cmd) {
            if ((string) $cmd->getLogicalId() === (string) $_logicalId) {
                $trouves[] = $cmd;
            }
        }
        if ($_multiple) {
            return $trouves;
        }
        return empty($trouves) ? null : $trouves[0];
    }

    public function getHumanName($_tag = false, $_prettify = false) {
        return '[' . $this->getName() . ']';
    }

    public function refreshWidget() {
        return true;
    }

    public function getIsEnable() {
        return $this->isEnable;
    }

    public function getIsVisible() {
        return $this->isVisible;
    }

    public function getId() {
        return $this->id;
    }

    public function setId($_id) {
        $this->_changed = $this->attrChanged($this->id, $_id);
        $this->id = $_id;
        return $this;
    }

    public function getName() {
        return $this->name;
    }

    /* Le cœur nettoie puis tronque à 127 : la fabrique calcule l'unicité des
     * noms sur ce résultat, pas sur ce qu'on lui a demandé. */
    public function setName($_name) {
        $_name = trim(substr(cleanComponanteName($_name), 0, 127));
        if ($_name != $this->name) {
            $this->_changed = true;
        }
        $this->name = $_name;
        return $this;
    }

    public function getLogicalId() {
        return $this->logicalId;
    }

    /* Aucune troncature ici, volontairement : le cœur n'en fait pas, et c'est
     * MySQL qui refuse un logicalId de plus de 127 caractères. */
    public function setLogicalId($_logicalId) {
        $this->_changed = $this->attrChanged($this->logicalId, $_logicalId);
        $this->logicalId = $_logicalId;
        return $this;
    }

    public function getObject_id() {
        return $this->object_id;
    }

    public function setObject_id($_object_id = null) {
        $_object_id = (!is_numeric($_object_id)) ? null : $_object_id;
        $this->_changed = $this->attrChanged($this->object_id, $_object_id);
        $this->object_id = $_object_id;
        return $this;
    }

    public function getEqType_name() {
        return $this->eqType_name;
    }

    public function setEqType_name($_eqType_name) {
        $this->_changed = $this->attrChanged($this->eqType_name, $_eqType_name);
        $this->eqType_name = $_eqType_name;
        return $this;
    }

    public function setIsVisible($_isVisible) {
        if ($this->isVisible != $_isVisible) {
            $this->_changed = true;
        }
        $this->isVisible = $_isVisible;
        return $this;
    }

    public function setIsEnable($_isEnable) {
        if ($this->isEnable != $_isEnable) {
            $this->_changed = true;
        }
        $this->isEnable = $_isEnable;
        return $this;
    }

    public function getOrder() {
        return $this->order;
    }

    public function setOrder($_order) {
        $this->_changed = $this->attrChanged($this->order, $_order);
        $this->order = $_order;
        return $this;
    }

    public function getConfiguration($_key = '', $_default = '') {
        return $this->jsonGet($this->configuration, $_key, $_default);
    }

    public function setConfiguration($_key, $_value) {
        $configuration = $this->jsonSet($this->configuration, $_key, $_value);
        $this->_changed = $this->attrChanged($this->configuration, $configuration);
        $this->configuration = $configuration;
        return $this;
    }

    public function getDisplay($_key = '', $_default = '') {
        return $this->jsonGet($this->display, $_key, $_default);
    }

    public function setDisplay($_key, $_value) {
        $display = $this->jsonSet($this->display, $_key, $_value);
        $this->_changed = $this->attrChanged($this->display, $display);
        $this->display = $display;
        return $this;
    }

    public function getStatus($_key = '', $_default = '') {
        return $this->jsonGet($this->status, $_key, $_default);
    }

    public function setStatus($_key, $_value = null) {
        if (is_array($_key)) {
            foreach ($_key as $cle => $valeur) {
                $this->setStatus($cle, $valeur);
            }
            return $this;
        }
        $this->status = $this->jsonSet($this->status, $_key, $_value);
        return $this;
    }

    public function getChanged() {
        return $this->_changed;
    }

    public function setChanged($_changed) {
        $this->_changed = $_changed;
        return $this;
    }
}

class cmd extends MqttbeFauxEntite {

    protected $id = '';
    protected $eqLogic_id = '';
    protected $eqType = '';
    protected $logicalId = '';
    protected $order = 0;
    protected $name = '';
    protected $configuration = null;
    protected $template = null;
    protected $isHistorized = 0;
    protected $type = '';
    protected $subType = '';
    protected $unite = '';
    protected $display = null;
    protected $isVisible = 1;
    protected $value = null;
    protected $alert = null;
    protected $generic_type = null;
    protected $_changed = false;

    /* ------------------------------------------------------------ lectures */

    /* Le cœur reconstruit la commande dans la classe du plugin (cmd::cast) :
     * c'est de là que mqttbeCmd::postSave() tire son existence. */
    protected static function hydrate($_ligne) {
        $classe = 'cmd';
        $candidat = (string) $_ligne['eqType'] . 'Cmd';
        if (class_exists($candidat) && is_subclass_of($candidat, 'cmd')) {
            $classe = $candidat;
        }
        $objet = new $classe();
        foreach ($_ligne as $colonne => $valeur) {
            if (property_exists($objet, $colonne)) {
                $objet->$colonne = $valeur;
            }
        }
        $objet->_changed = false;
        return $objet;
    }

    public static function byId($_id) {
        $ligne = MqttbeFauxDb::ligne('cmd', $_id);
        return $ligne === null ? null : self::hydrate($ligne);
    }

    public static function byEqLogicId($_eqLogic_id, $_type = null, $_visible = null, $_eqLogic = null) {
        $trouves = array();
        foreach (MqttbeFauxDb::lignes('cmd') as $ligne) {
            if ((string) $ligne['eqLogic_id'] !== (string) $_eqLogic_id) {
                continue;
            }
            if ($_type !== null && (string) $ligne['type'] !== (string) $_type) {
                continue;
            }
            if ($_visible !== null && (int) $ligne['isVisible'] !== 1) {
                continue;
            }
            $trouves[] = self::hydrate($ligne);
        }
        /* ORDER BY `order`, `name` : l'ordre de lecture des commandes est celui
         * de l'affichage, pas celui de la création. */
        usort($trouves, function ($_a, $_b) {
            $ordre = (int) $_a->getOrder() - (int) $_b->getOrder();
            return $ordre !== 0 ? $ordre : strcmp((string) $_a->getName(), (string) $_b->getName());
        });
        return $trouves;
    }

    public static function byEqLogicIdAndLogicalId($_eqLogic_id, $_logicalId, $_multiple = false, $_type = null) {
        $trouves = array();
        foreach (self::byEqLogicId($_eqLogic_id, $_type) as $cmd) {
            if ((string) $cmd->getLogicalId() === (string) $_logicalId) {
                $trouves[] = $cmd;
            }
        }
        if ($_multiple) {
            return $trouves;
        }
        return empty($trouves) ? null : $trouves[0];
    }

    public static function byValue($_value, $_type = null, $_onlyEnable = false) {
        return array();
    }

    public static function searchConfiguration($_configuration, $_type = null) {
        return array();
    }

    /* ----------------------------------------------------------- écriture */

    /**
     * Enregistrement.
     *
     * Les quatre refus du début sont ceux du vrai cmd::save() : ils lèvent une
     * exception, et la fabrique les rattrape commande par commande. Les
     * retouches qui suivent (widget par défaut, action jamais historisée) sont
     * elles aussi du cœur : sans elles, la trace que la fabrique garde de ce
     * qu'elle a écrit ne correspondrait pas à ce que la base contient, et la
     * passe suivante croirait l'utilisateur passé par là.
     */
    public function save($_direct = false) {
        if ($this->getName() == '') {
            throw new Exception('Le nom de la commande ne peut pas être vide');
        }
        if ($this->getType() == '') {
            throw new Exception('Le type de la commande ne peut pas être vide');
        }
        if ($this->getSubType() == '') {
            throw new Exception('Le sous-type de la commande ne peut pas être vide');
        }
        if ($this->getEqLogic_id() == '') {
            throw new Exception('Vous ne pouvez pas créer une commande sans la rattacher à un équipement');
        }
        if ($this->getEqType() == '') {
            $eqLogic = $this->getEqLogic();
            if (is_object($eqLogic)) {
                $this->setEqType($eqLogic->getEqType_name());
            }
        }
        if ($this->getTemplate('dashboard', '') == '') {
            $this->setTemplate('dashboard', 'core::default');
        }
        if ($this->getTemplate('mobile', '') == '') {
            $this->setTemplate('mobile', 'core::default');
        }
        if ($this->getType() == 'action' && $this->getIsHistorized() == 1) {
            $this->setIsHistorized(0);
        }
        if (!$_direct && method_exists($this, 'preSave')) {
            $this->preSave();
        }
        if ($this->id === '' || $this->id === null) {
            $this->id = MqttbeFauxDb::insere('cmd', $this->colonnes());
        } elseif ($this->_changed) {
            MqttbeFauxDb::metAJour('cmd', $this->id, $this->colonnes());
        }
        if (!$_direct && method_exists($this, 'postSave')) {
            $this->postSave();
        }
        $this->_changed = false;
        return true;
    }

    public function remove() {
        if ($this->id !== '' && $this->id !== null) {
            MqttbeFauxDb::supprime('cmd', $this->id);
            MqttbeFauxCoeur::oublieCommande($this->id);
        }
        if (method_exists($this, 'postRemove')) {
            $this->postRemove();
        }
        return true;
    }

    private function colonnes() {
        return array(
            'eqLogic_id'    => $this->eqLogic_id,
            'eqType'        => $this->eqType,
            'logicalId'     => $this->logicalId,
            'order'         => $this->order,
            'name'          => $this->name,
            'configuration' => $this->colonneJson($this->configuration),
            'template'      => $this->colonneJson($this->template),
            'isHistorized'  => $this->isHistorized,
            'type'          => $this->type,
            'subType'       => $this->subType,
            'unite'         => $this->unite,
            'display'       => $this->colonneJson($this->display),
            'isVisible'     => $this->isVisible,
            'value'         => $this->value,
            'alert'         => $this->colonneJson($this->alert),
            'generic_type'  => $this->generic_type,
        );
    }

    /* ------------------------------------------------- usages et historique */

    /**
     * Qui se sert de cette commande.
     *
     * La forme du tableau est celle du vrai cœur, rang « plugin » compris : la
     * fabrique le traite à part, et un simulacre qui l'oublierait ne
     * vérifierait pas ce traitement. Ce que les contrôles déclarent avec
     * MqttbeFauxCoeur::reference() apparaît ici.
     */
    public function getUsedBy($_array = false) {
        $return = array('cmd' => array(), 'eqLogic' => array(), 'scenario' => array(),
                        'plan' => array(), 'view' => array(), 'object' => array(),
                        'interactDef' => array(), 'plan3d' => array(), 'plugin' => array());
        foreach (MqttbeFauxCoeur::references($this->id) as $type) {
            if (!isset($return[$type])) {
                $return[$type] = array();
            }
            $return[$type][] = 'référence déclarée par le contrôle';
        }
        return $return;
    }

    public function getHistory($_dateStart = null, $_dateEnd = null, $_groupingType = null, $_addFirstPreviousValue = false) {
        return MqttbeFauxCoeur::historique($this->id);
    }

    public function getHumanName($_tag = false, $_prettify = false) {
        $eqLogic = $this->getEqLogic();
        $nom = is_object($eqLogic) ? $eqLogic->getName() : '?';
        return '[' . $nom . '][' . $this->getName() . ']';
    }

    public function getEqLogic() {
        return eqLogic::byId($this->eqLogic_id);
    }

    public function setEqLogic($_eqLogic) {
        return $this;
    }

    public function refreshWidget() {
        return true;
    }

    public function event($_value, $_datetime = null) {
        MqttbeFauxCoeur::ajouteEvenementCmd($this->id, $_value);
        return true;
    }

    public function execute($_options = array()) {
        return null;
    }

    /* ---------------------------------------------------------- accesseurs */

    public function getId() {
        return $this->id;
    }

    public function setId($_id = '') {
        $this->_changed = $this->attrChanged($this->id, $_id);
        $this->id = $_id;
        return $this;
    }

    public function getName() {
        return $this->name;
    }

    public function setName($_name) {
        $_name = trim(substr(cleanComponanteName($_name), 0, 127));
        if ($this->name != $_name) {
            $this->_changed = true;
        }
        $this->name = $_name;
        return $this;
    }

    public function getType() {
        return $this->type;
    }

    public function setType($_type) {
        if ($this->type != $_type) {
            $this->_changed = true;
        }
        $this->type = $_type;
        return $this;
    }

    public function getSubType() {
        return $this->subType;
    }

    public function setSubType($_subType) {
        if ($this->subType != $_subType) {
            $this->_changed = true;
        }
        $this->subType = $_subType;
        return $this;
    }

    public function getGeneric_type() {
        return $this->generic_type;
    }

    public function setGeneric_type($_generic_type) {
        $this->_changed = $this->attrChanged($this->generic_type, $_generic_type);
        $this->generic_type = $_generic_type;
        return $this;
    }

    public function getEqLogic_id() {
        return $this->eqLogic_id;
    }

    public function setEqLogic_id($_eqLogic_id) {
        $this->_changed = $this->attrChanged($this->eqLogic_id, $_eqLogic_id);
        $this->eqLogic_id = $_eqLogic_id;
        return $this;
    }

    public function getEqType() {
        return $this->eqType;
    }

    public function setEqType($_eqType) {
        $this->_changed = $this->attrChanged($this->eqType, $_eqType);
        $this->eqType = $_eqType;
        return $this;
    }

    public function getIsHistorized() {
        return $this->isHistorized;
    }

    public function setIsHistorized($_isHistorized) {
        $this->_changed = $this->attrChanged($this->isHistorized, $_isHistorized);
        $this->isHistorized = $_isHistorized;
        return $this;
    }

    public function getUnite() {
        return $this->unite;
    }

    public function setUnite($_unite) {
        if ($this->unite != $_unite) {
            $this->_changed = true;
        }
        $this->unite = $_unite;
        return $this;
    }

    public function getIsVisible() {
        return $this->isVisible;
    }

    public function setIsVisible($_isVisible) {
        if ($this->isVisible != $_isVisible) {
            $this->_changed = true;
        }
        $this->isVisible = $_isVisible;
        return $this;
    }

    public function getOrder() {
        return $this->order == '' ? 0 : $this->order;
    }

    public function setOrder($_order) {
        if ($this->order != $_order) {
            $this->_changed = true;
        }
        $this->order = $_order;
        return $this;
    }

    public function getLogicalId() {
        return $this->logicalId;
    }

    public function setLogicalId($_logicalId) {
        $this->_changed = $this->attrChanged($this->logicalId, $_logicalId);
        $this->logicalId = $_logicalId;
        return $this;
    }

    public function getValue() {
        return $this->value;
    }

    public function setValue($_value) {
        $this->_changed = $this->attrChanged($this->value, $_value);
        $this->value = $_value;
        return $this;
    }

    public function getConfiguration($_key = '', $_default = '') {
        return $this->jsonGet($this->configuration, $_key, $_default);
    }

    public function setConfiguration($_key, $_value) {
        $configuration = $this->jsonSet($this->configuration, $_key, $_value);
        $this->_changed = $this->attrChanged($this->configuration, $configuration);
        $this->configuration = $configuration;
        return $this;
    }

    /* setTemplate() préfixe « core:: » quand le widget n'est pas qualifié : la
     * trace de la fabrique doit porter la valeur préfixée, sinon elle croira
     * l'utilisateur passé par là au passage suivant. */
    public function getTemplate($_key = '', $_default = '') {
        return $this->jsonGet($this->template, $_key, $_default);
    }

    public function setTemplate($_key, $_value) {
        if (($_key == 'dashboard' || $_key == 'mobile') && strpos((string) $_value, '::') === false) {
            $_value = 'core::' . $_value;
        }
        $template = $this->jsonSet($this->template, $_key, $_value);
        $this->_changed = $this->attrChanged($this->template, $template);
        $this->template = $template;
        return $this;
    }

    public function getDisplay($_key = '', $_default = '') {
        return $this->jsonGet($this->display, $_key, $_default);
    }

    public function setDisplay($_key, $_value) {
        $display = $this->jsonSet($this->display, $_key, $_value);
        $this->_changed = $this->attrChanged($this->display, $display);
        $this->display = $display;
        return $this;
    }

    public function getChanged() {
        return $this->_changed;
    }

    public function setChanged($_changed) {
        $this->_changed = $_changed;
        return $this;
    }
}

/* --------------------------------------------------------------------------
 * 4. config, cache, log, event, message
 *
 * Réduits au nécessaire, mais avec les particularités qui comptent :
 * cache::byKey() ne lève jamais et rend un objet même pour une clé jamais
 * écrite, et getValue() rend le défaut quand la valeur est vide — le routage
 * s'appuie sur les deux.
 * ------------------------------------------------------------------------ */

class config {

    private static $_valeurs = array();

    public static function reinitialise() {
        self::$_valeurs = array();
    }

    /**
     * Comme le cœur — y compris là où c'est déroutant.
     *
     * Le vrai byKey() fait passer la valeur lue par is_json() (core/php/
     * utils.inc.php) : une chaîne qui se décode en TABLEAU est rendue décodée,
     * et non telle qu'elle a été écrite. Une chaîne quelconque, un nombre, un
     * booléen JSON ressortent tels quels — is_json() n'accepte que le tableau.
     *
     * Ce détail n'en est pas un. Un simulacre qui rendait la chaîne laissait
     * passer un json_decode() de trop dans le plugin : le double décodage
     * donnait null, la liste des refus d'adoption était éternellement vide, et
     * le bouton « Ignorer » n'a jamais rien fait. Le contrôle écrit pour
     * l'attraper le laissait passer aussi, puisqu'il éprouvait un cœur plus
     * accommodant que le vrai. C'est la raison d'être de cette fidélité-là.
     */
    public static function byKey($_key, $_plugin = 'core', $_default = '', $_forceFresh = false) {
        $cle = $_plugin . '::' . $_key;
        if (!isset(self::$_valeurs[$cle]) || self::$_valeurs[$cle] === '') {
            return $_default;
        }
        $valeur = self::$_valeurs[$cle];
        if (is_string($valeur)) {
            $decodee = json_decode($valeur, true, 512, JSON_BIGINT_AS_STRING);
            if (is_array($decodee)) {
                return $decodee;
            }
        }
        return $valeur;
    }

    public static function save($_key, $_value, $_plugin = 'core') {
        self::$_valeurs[$_plugin . '::' . $_key] = $_value;
        return true;
    }

    public static function byKeys($_keys, $_plugin = 'core', $_default = '') {
        $return = array();
        foreach ($_keys as $cle) {
            $return[$cle] = self::byKey($cle, $_plugin, $_default);
        }
        return $return;
    }
}

class cache {

    private static $_valeurs = array();

    private $key = '';
    private $value = null;
    private $lifetime = 0;

    public static function reinitialise() {
        self::$_valeurs = array();
    }

    public static function set($_key, $_value, $_lifetime = 0) {
        self::$_valeurs[$_key] = $_value;
        return true;
    }

    public static function delete($_key) {
        unset(self::$_valeurs[$_key]);
    }

    /* Ne lève jamais : une clé jamais écrite rend un objet vide, dont
     * getValue() donnera le défaut. */
    public static function byKey($_key) {
        $cache = new self();
        $cache->key = $_key;
        if (array_key_exists($_key, self::$_valeurs)) {
            $cache->value = self::$_valeurs[$_key];
        }
        return $cache;
    }

    public static function exist($_key) {
        return self::byKey($_key)->getValue(null) !== null;
    }

    public function getKey() {
        return $this->key;
    }

    /* La chaîne vide vaut absence, comme dans le vrai cache. */
    public function getValue($_default = '') {
        return ($this->value === null || (is_string($this->value) && trim($this->value) === ''))
             ? $_default : $this->value;
    }

    public function setValue($_value) {
        $this->value = $_value;
        return $this;
    }

    public function getLifetime() {
        return $this->lifetime;
    }

    public function save() {
        self::$_valeurs[$this->key] = $this->value;
        return true;
    }

    public function remove() {
        unset(self::$_valeurs[$this->key]);
        return true;
    }
}

/**
 * Le peu du cœur qui manque encore.
 *
 * mqttbeRouting::push() pose un verrou de fichier pour sérialiser deux
 * processus web, et demande au cœur où écrire. Le dossier temporaire du système
 * fait l'affaire : c'est là que Jeedom range le sien, et le dépôt ne reçoit
 * rien.
 */
class jeedom {

    public static function getTmpFolder($_plugin = '') {
        $dossier = sys_get_temp_dir() . '/mqttbe-essai';
        if ($_plugin !== '') {
            $dossier .= '/' . $_plugin;
        }
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        return $dossier;
    }

    public static function addRemoveHistory($_history) {
        return true;
    }
}

class log {

    private static $_lignes = array();

    public static function reinitialise() {
        self::$_lignes = array();
    }

    public static function add($_log, $_type, $_message, $_logicalId = '') {
        self::$_lignes[] = array('journal' => $_log, 'niveau' => $_type, 'message' => $_message);
    }

    public static function lignes() {
        return self::$_lignes;
    }

    public static function getLogLevel($_log) {
        return 100;
    }

    public static function remove($_log) {
        return true;
    }
}

class event {

    private static $_evenements = array();

    public static function reinitialise() {
        self::$_evenements = array();
    }

    public static function add($_event, $_option = array(), $_clean = true) {
        self::$_evenements[] = array('evenement' => $_event, 'option' => $_option);
    }

    public static function evenements() {
        return self::$_evenements;
    }
}

class message {

    private static $_messages = array();

    public static function reinitialise() {
        self::$_messages = array();
    }

    public static function add($_type, $_message, $_action = '', $_logicalId = '') {
        self::$_messages[] = array('type' => $_type, 'message' => $_message,
                                   'logicalId' => (string) $_logicalId);
    }

    /* Le cœur rend un tableau, éventuellement vide. mqttbeDaemon s'en sert pour
     * ne pas répéter le même avertissement à chaque lot reçu. */
    public static function byPluginLogicalId($_plugin, $_logicalId) {
        $trouves = array();
        foreach (self::$_messages as $message) {
            if ($message['logicalId'] === (string) $_logicalId) {
                $trouves[] = $message;
            }
        }
        return $trouves;
    }

    public static function messages() {
        return self::$_messages;
    }

    public static function removeAll($_plugin) {
        self::$_messages = array();
    }
}

/* --------------------------------------------------------------------------
 * 5. Le double du démon
 *
 * mqttbeDaemon parle à un processus par une socket TCP et lit des fichiers de
 * PID : rien de tout cela n'existe pendant un contrôle. Le double note ce
 * qu'on lui envoie et rend ce que le contrôle lui a demandé de rendre — un
 * envoi qui échoue est un cas que le routage doit traiter, pas un accident.
 *
 * Il est défini AVANT le chargement des classes du plugin : c'est ce qui le
 * substitue à la vraie classe, dont le require_once est neutralisé.
 * ------------------------------------------------------------------------ */

/*
 * Le démon, en simulacre — ou en vrai.
 *
 * La fabrique et le routage n'ont besoin que de savoir si un ordre est parti :
 * ce simulacre le note et n'ouvre aucune socket. Mais la file d'adoption, elle,
 * VIT dans mqttbeDaemon, et la contrôler demande le vrai code. Un contrôle qui
 * veut l'éprouver définit MQTTBE_VRAI_DEMON avant d'inclure ce fichier : la
 * classe réelle est alors chargée, telle quelle, et c'est bien elle qui est
 * mise à l'épreuve.
 *
 * Les deux ne peuvent pas cohabiter — un seul nom de classe — et c'est voulu :
 * un contrôle éprouve la vraie file, ou la fabrique par-dessus un démon muet,
 * jamais les deux dans le même processus.
 */
if (defined('MQTTBE_VRAI_DEMON') && MQTTBE_VRAI_DEMON) {
    require_once dirname(__DIR__) . '/core/class/mqttbeDaemon.class.php';
} else {

class mqttbeDaemon {

    public static $envois       = array();
    public static $publications = array();
    public static $reponse      = true;

    public static function reinitialise() {
        self::$envois = array();
        self::$publications = array();
        self::$reponse = true;
    }

    public static function send($_params, $_throw = true) {
        self::$envois[] = $_params;
        if (!self::$reponse && $_throw) {
            throw new Exception('Démon injoignable');
        }
        return self::$reponse;
    }

    public static function publish($_topic, $_payload, $_qos = 0, $_retain = false) {
        self::$publications[] = array('topic' => $_topic, 'payload' => $_payload,
                                      'qos' => $_qos, 'retain' => $_retain);
        return true;
    }

    public static function state() {
        return true;
    }

    public static function brokerState() {
        return 'ok';
    }

    public static function check() {
        return true;
    }

    public static function info() {
        return array('state' => 'ok', 'launchable' => 'ok');
    }

    public static function start() {
        return true;
    }

    public static function stop() {
        return true;
    }

    public static function syncLogLevel() {
        return true;
    }

    public static function logLatency() {
        return true;
    }
}

}

/* --------------------------------------------------------------------------
 * 6. Chargement des classes du plugin
 *
 * mqttbeFactory et mqttbeRouting s'incluent normalement : elles ne référencent
 * pas core.inc.php, et la fabrique a besoin de son vrai __DIR__ pour trouver
 * core/config/capabilities.json — la charger autrement lui ferait lire un
 * vocabulaire qui n'est pas celui du dépôt.
 *
 * mqttbe.class.php, elle, commence par quatre require_once : le cœur de Jeedom
 * — absent — puis ses classes sœurs. On lit donc son texte, on retire ces
 * lignes, et on l'évalue. Deux raisons de ne pas s'y prendre autrement :
 *
 *   - fabriquer un faux core.inc.php à l'emplacement attendu supposerait
 *     d'écrire hors du dépôt (le chemin remonte quatre dossiers) ;
 *   - le fichier n'emploie __DIR__ que dans ces require_once, et __FILE__ que
 *     comme second argument de __(), qui l'ignore : l'évaluation ne lui retire
 *     donc rien. Le code exécuté est bien celui du dépôt, à la ligne près.
 *
 * Le seul effet de bord est que les numéros de ligne d'une erreur fatale dans
 * mqttbe.class.php seront ceux de l'évaluation.
 * ------------------------------------------------------------------------ */

function mqttbeChargeClassesPlugin() {
    if (class_exists('mqttbeFactory')) {
        return;
    }
    $classes = dirname(__DIR__) . '/core/class';
    $source = file_get_contents($classes . '/mqttbe.class.php');
    $source = preg_replace('#^\s*require_once\s+__DIR__[^;]*;\s*$#m', '', $source);
    eval('?>' . $source);
    require_once $classes . '/mqttbeRouting.class.php';
    require_once $classes . '/mqttbeFactory.class.php';
}

/* --------------------------------------------------------------------------
 * 7. Ce dont les contrôles se servent
 * ------------------------------------------------------------------------ */

class MqttbeFauxCoeur {

    private static $_references   = array();
    private static $_historique   = array();
    private static $_evenementsCmd = array();

    /**
     * Table rase entre deux contrôles.
     *
     * Y compris les caches de requête de la fabrique : self::$_index garde des
     * objets équipements, et un contrôle hériterait sinon du parc du
     * précédent.
     */
    public static function reinitialise() {
        MqttbeFauxDb::reinitialise();
        config::reinitialise();
        cache::reinitialise();
        log::reinitialise();
        event::reinitialise();
        message::reinitialise();
        /* Le vrai mqttbeDaemon — celui que charge MQTTBE_VRAI_DEMON — n'a rien
         * à remettre à zéro : son état vit dans le cache et la configuration,
         * que les deux lignes ci-dessus viennent de vider. */
        if (method_exists('mqttbeDaemon', 'reinitialise')) {
            mqttbeDaemon::reinitialise();
        }
        self::$_references = array();
        self::$_historique = array();
        self::$_evenementsCmd = array();
        if (class_exists('mqttbeFactory')) {
            mqttbeFactory::resetCache();
        }
    }

    public static function ecritures() {
        return MqttbeFauxDb::ecritures();
    }

    /** Les lignes de journal, éventuellement filtrées par niveau. */
    public static function journal($_niveau = null) {
        $lignes = array();
        foreach (log::lignes() as $ligne) {
            if ($_niveau === null || $ligne['niveau'] === $_niveau) {
                $lignes[] = $ligne['message'];
            }
        }
        return $lignes;
    }

    public static function journalContient($_fragment, $_niveau = null) {
        foreach (self::journal($_niveau) as $message) {
            if (strpos($message, $_fragment) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Déclare que cette commande est citée ailleurs (scénario, vue, plugin…). */
    public static function reference($_cmdId, $_type = 'scenario') {
        if (!isset(self::$_references[$_cmdId])) {
            self::$_references[$_cmdId] = array();
        }
        self::$_references[$_cmdId][] = $_type;
    }

    public static function references($_cmdId) {
        return isset(self::$_references[$_cmdId]) ? self::$_references[$_cmdId] : array();
    }

    /** Donne un historique à une commande : elle ne pourra plus être supprimée. */
    public static function ajouteHistorique($_cmdId, $_nombre = 3) {
        self::$_historique[$_cmdId] = array_fill(0, $_nombre,
            array('datetime' => date('Y-m-d H:i:s'), 'value' => 1));
    }

    public static function historique($_cmdId) {
        return isset(self::$_historique[$_cmdId]) ? self::$_historique[$_cmdId] : array();
    }

    public static function oublieCommande($_cmdId) {
        unset(self::$_references[$_cmdId], self::$_historique[$_cmdId]);
    }

    public static function ajouteEvenementCmd($_cmdId, $_valeur) {
        self::$_evenementsCmd[] = array('cmdId' => $_cmdId, 'valeur' => $_valeur);
    }

    public static function evenementsCmd() {
        return self::$_evenementsCmd;
    }

    /* ------------------------------------------------- lectures d'assertion */

    /** L'équipement mqttbe portant ce uid, relu depuis la base. */
    public static function equipement($_uid) {
        return eqLogic::byLogicalId($_uid, 'mqttbe');
    }

    /** Les commandes d'un équipement, rangées par logicalId. */
    public static function commandes($_eqLogic) {
        $parCle = array();
        if (!is_object($_eqLogic) || $_eqLogic->getId() == '') {
            return $parCle;
        }
        foreach (cmd::byEqLogicId($_eqLogic->getId()) as $cmd) {
            $parCle[(string) $cmd->getLogicalId()] = $cmd;
        }
        return $parCle;
    }

    public static function nombreEquipements() {
        return count(MqttbeFauxDb::lignes('eqLogic'));
    }

    public static function nombreCommandes() {
        return count(MqttbeFauxDb::lignes('cmd'));
    }
}

mqttbeChargeClassesPlugin();
