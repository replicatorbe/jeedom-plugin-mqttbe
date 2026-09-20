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

/*
 * Fabrique : un modèle de périphérique devient un équipement et ses commandes.
 *
 * Le modèle décrit un appareil en capacités ; rien ici ne connaît Shelly,
 * Tasmota ou Zigbee2MQTT. La traduction capacité → termes Jeedom est lue dans
 * core/config/capabilities.json et nulle part ailleurs : c'est le seul endroit
 * où une décision de présentation se prend, et le seul fichier à reprendre
 * quand le coeur ajoute un type générique. Une correspondance codée en dur ici
 * serait invisible le jour où il faudrait la corriger.
 *
 * Trois exigences gouvernent tout le reste :
 *
 *   - idempotence. Les messages de découverte sont retenus par le broker, donc
 *     rejoués à chaque démarrage du démon. Une empreinte identique doit coûter
 *     zéro écriture, sans quoi le parc entier serait réécrit à chaque
 *     redémarrage — et chaque écriture réveille les widgets, l'historique et
 *     les scénarios liés ;
 *
 *   - respect des retouches. Ce que l'utilisateur a changé à la main n'est
 *     jamais réécrit. La plomberie (topic, chemin JSON, logicalId) reste en
 *     revanche toujours à jour : c'est le câblage, pas la présentation ;
 *
 *   - unicité SQL traitée AVANT l'enregistrement. cmd (eqLogic_id, name) et
 *     eqLogic (name, object_id) sont uniques, et un doublon de nom fait échouer
 *     l'enregistrement de l'équipement ENTIER, pas seulement de la commande
 *     fautive. Le symptôme, côté interface, est un équipement qui disparaît.
 */
class mqttbeFactory {

    /* Configuration de l'équipement (contrat, section 5). */
    const CONF_UID          = 'mqttbe::uid';
    const CONF_ADAPTER      = 'mqttbe::adapter';
    const CONF_ALIASES      = 'mqttbe::aliases';
    const CONF_FINGERPRINT  = 'mqttbe::fingerprint';
    const CONF_MANUFACTURER = 'mqttbe::manufacturer';
    const CONF_MODEL        = 'mqttbe::model';
    /* Métadonnées volatiles : rafraîchies à chaque passage, y compris quand le
     * modèle est par ailleurs inchangé. L'adresse sert à ouvrir l'appareil
     * depuis Jeedom — c'est souvent le seul moyen de savoir lequel des dix-sept
     * « Shelly 1 » du couloir on est en train de configurer. */
    /*
     * Le nom lu dans l'appareil, mémorisé.
     *
     * Le démon ne le porte pas dans tous ses messages : la première émission
     * d'un appareil arrive avant que la sonde n'ait répondu, et une sonde qui
     * échoue quatre fois abandonne. Sans cette mémoire, l'équipement était
     * renommé en nom technique à chaque redémarrage du démon, puis renommé de
     * nouveau une seconde plus tard — deux écritures et deux événements par
     * appareil, sur dix-sept Shelly. Un vide n'efface jamais un nom acquis.
     */
    const CONF_DEVICE_NAME  = 'mqttbe::deviceName';
    const CONF_IP           = 'mqttbe::ip';
    const CONF_FIRMWARE     = 'mqttbe::firmware';
    const CONF_CONFIG_URL   = 'mqttbe::configUrl';
    const CONF_AVAILABILITY = 'mqttbe::availability';
    /* Nombre de commandes que la fabrique a laissées derrière elle au dernier
     * passage. Sans ce compte, le raccourci d'idempotence ne regarde que
     * l'empreinte : une commande supprimée à la main — par erreur, ou par la
     * page de l'équipement enregistrée pendant qu'un callback en créait une —
     * ne serait plus jamais recréée, le modèle n'ayant pas changé. */
    const CONF_CMDCOUNT     = 'mqttbe::cmdCount';

    /* Traçabilité de la fabrique, sur l'équipement comme sur les commandes. */
    const CONF_KEY        = 'mqttbe::key';
    const CONF_CAPABILITY = 'mqttbe::capability';
    const CONF_GENERATED  = 'mqttbe::generated';
    const CONF_ORPHAN     = 'mqttbe::orphan';
    const CONF_SELECTOR   = 'mqttbe::selector';

    /* Capacité de dernier recours : un canal dont la capacité est inconnue du
     * vocabulaire devient une information texte plutôt que rien du tout. */
    const CAPABILITY_FALLBACK = 'generic.value';

    /* Confiance qu'un modèle doit porter pour que la disparition d'un canal
     * soit tenue pour vraie. Un adapter annonce « probable » tant qu'il n'a pas
     * tout vu de l'appareil — Shelly Gen1 émet ainsi un modèle à un seul canal
     * quand l'annonce est arrivée mais pas encore le « info », et sa mémoire
     * étant en RAM, cela se reproduit à CHAQUE redémarrage du démon. Traiter ce
     * modèle dégradé comme une perte de canaux supprimerait des commandes
     * d'action, qui reviendraient avec de nouveaux identifiants : l'utilisateur
     * perdrait ses boutons de tableau de bord et leur place dans les vues. */
    const CONFIDENCE_TRUSTED = 'certain';

    /* Au-delà de cette proportion de commandes orphelines en un seul passage,
     * l'hypothèse « l'appareil a perdu ces canaux » devient moins vraisemblable
     * que « le modèle est incomplet » : le passage est journalisé en warning
     * pour que l'exploitation le voie sans avoir à relire la base. */
    const ORPHAN_ALERT_RATIO = 0.5;

    /* Champ de présentation trouvé sur une commande existante alors que la
     * fabrique ne le suivait pas encore : sa valeur appartient à l'utilisateur.
     * La liste de ces champs est rangée dans la trace elle-même, sous une clé
     * qui ne peut pas entrer en conflit avec un nom de champ. */
    const GENERATED_USER = '#user';

    /* eqLogic.name et cmd.name sont des varchar(127), eqLogic.logicalId aussi.
     * cmd.unite est un varchar(45) : un nom d'unité exotique tronqué vaut mieux
     * qu'un enregistrement refusé par la base. */
    const MAX_NAME     = 127;
    const MAX_UNIT     = 45;
    const MAX_LOGICALID = 127;

    /* Champs que la fabrique pose et que l'utilisateur peut reprendre. Ils sont
     * suivis un par un : renommer une commande ne doit pas geler la correction
     * d'unité qui viendra au firmware suivant. */
    private static $_presentationFields = array(
        'name', 'type', 'subType', 'generic_type', 'unite',
        'isVisible', 'isHistorized', 'order',
        'template::dashboard', 'template::mobile',
    );

    private static $_capabilities = null;
    private static $_index = null;
    private static $_discoveryLoaded = false;
    /* Réponse de pluginsMayReference() pour la requête en cours. */
    private static $_pluginsUsedBy = null;

    /* ------------------------------------------------------- point d'entrée */

    /**
     * Crée ou met à jour l'équipement décrit par le modèle.
     *
     * Rend un compte rendu plutôt qu'un booléen : l'appelant (adapter, page de
     * configuration manuelle, import JSON) doit pouvoir dire à l'utilisateur ce
     * qui vient de se passer, et l'exploitation doit pouvoir mesurer combien de
     * découvertes n'ont rien coûté.
     */
    public static function apply(MqttbeDeviceModel $_model) {
        return self::applyData(self::toArray($_model));
    }

    /**
     * Même travail à partir du modèle déjà réduit en tableau.
     *
     * C'est la forme qui arrive du démon (JSON décodé) et celle qu'emploient
     * les essais : la fabrique ne doit pas exiger l'objet pour fonctionner.
     */
    public static function applyData($_model) {
        $report = array(
            'status'     => 'error',
            'uid'        => '',
            'name'       => '',
            'eqLogic_id' => null,
            /* « failed » compte les écritures rattrapées, et non les canaux
             * écartés du modèle : c'est ce qui décide si l'empreinte peut être
             * posée. Une commande refusée par la base doit être retentée au
             * passage suivant, pas oubliée sous une empreinte à jour. */
            'cmd'        => array('created' => 0, 'updated' => 0, 'unchanged' => 0,
                                  'orphaned' => 0, 'removed' => 0, 'failed' => 0),
            'touched'    => 0,
            'messages'   => array(),
        );
        try {
            self::loadDiscovery();
            $model = self::toArray($_model);
            $report = self::build($model, $report);
        } catch (Throwable $e) {
            $report['status'] = 'error';
            $report['messages'][] = $e->getMessage();
            mqttbe::logger('error', __('Fabrique :', __FILE__) . ' ' . $e->getMessage());
        }
        return $report;
    }

    /**
     * Retrouve l'équipement qui porte cette identité, uid ou alias.
     *
     * Un même appareil se présente par plusieurs chemins : adresse MAC, adresse
     * IEEE, préfixe de topic, unique_id Home Assistant. Reconnaître un seul de
     * ces chemins suffit à faire une MISE À JOUR au lieu d'une création : c'est
     * tout l'anti-doublon du plugin, et le seul rempart contre un parc créé en
     * double le jour où Zigbee2MQTT publie aussi du Home Assistant Discovery.
     *
     * $_identity accepte un uid, un tableau identity{uid, aliases}, un modèle
     * complet ou l'objet MqttbeDeviceModel.
     */
    public static function findByIdentity($_identity) {
        $keys = self::identityKeys($_identity);
        if (empty($keys)) {
            return null;
        }
        /* Le uid est le logicalId de l'équipement : le chemin le plus court et
         * le plus sûr, il passe par un index de la base. */
        foreach ($keys as $key) {
            $eqLogic = eqLogic::byLogicalId($key, 'mqttbe');
            if (is_object($eqLogic)) {
                return $eqLogic;
            }
        }
        $index = self::aliasIndex();
        foreach ($keys as $key) {
            $normal = self::normalizeKey($key);
            if ($normal !== '' && isset($index[$normal])) {
                return $index[$normal];
            }
        }
        return null;
    }

    /* ----------------------------------------------------------- capacités */

    /**
     * Vocabulaire des capacités, lu une fois par requête.
     *
     * L'absence du fichier est une panne d'installation, pas un cas courant :
     * mieux vaut un échec explicite qu'une correspondance de secours codée ici,
     * qui créerait des commandes silencieusement fausses.
     */
    public static function capabilities() {
        if (self::$_capabilities !== null) {
            return self::$_capabilities;
        }
        $file = __DIR__ . '/../config/capabilities.json';
        if (!is_readable($file)) {
            throw new RuntimeException(__('Vocabulaire des capacités introuvable :', __FILE__)
                . ' core/config/capabilities.json');
        }
        $raw = json_decode(file_get_contents($file), true);
        if (!is_array($raw) || !isset($raw['capabilities']) || !is_array($raw['capabilities'])) {
            throw new RuntimeException(__('Vocabulaire des capacités illisible :', __FILE__)
                . ' core/config/capabilities.json');
        }
        self::$_capabilities = $raw['capabilities'];
        return self::$_capabilities;
    }

    /**
     * Description d'une capacité, valeurs par défaut comprises.
     *
     * Une capacité absente du vocabulaire retombe sur generic.value : l'appareil
     * reste exploitable, la valeur reste visible, et la trace au journal dit
     * quelle entrée manque au fichier.
     */
    public static function capability($_capability) {
        $all = self::capabilities();
        $key = trim((string) $_capability);
        if ($key !== '' && isset($all[$key]) && is_array($all[$key])) {
            return self::completeCapability($key, $all[$key]);
        }
        if (isset($all[self::CAPABILITY_FALLBACK]) && is_array($all[self::CAPABILITY_FALLBACK])) {
            mqttbe::logger('warning', __('Capacité inconnue du vocabulaire, repli sur generic.value :', __FILE__)
                . ' ' . $key);
            return self::completeCapability(self::CAPABILITY_FALLBACK, $all[self::CAPABILITY_FALLBACK]);
        }
        return null;
    }

    private static function completeCapability($_key, $_entry) {
        $entry = array_merge(array(
            'capability'   => $_key,
            'name'         => $_key,
            'type'         => 'info',
            'subType'      => 'string',
            'generic_type' => '',
            'unit'         => '',
            'isVisible'    => 1,
            'isHistorized' => 0,
            'order'        => 0,
            'template'     => array(),
            'links'        => '',
            'configuration' => array(),
        ), $_entry);
        $entry['capability'] = $_key;
        if (!is_array($entry['template'])) {
            $entry['template'] = array();
        }
        if (!is_array($entry['configuration'])) {
            $entry['configuration'] = array();
        }
        return $entry;
    }

    /* ------------------------------------------------- fabrication réelle */

    private static function build($_model, $report) {
        $identity = isset($_model['identity']) && is_array($_model['identity'])
                  ? $_model['identity'] : array();
        $uid = self::str($identity, 'uid');
        if ($uid === '') {
            throw new RuntimeException(__("Le modèle ne porte pas d'identifiant (identity.uid)", __FILE__));
        }
        if (strlen($uid) > self::MAX_LOGICALID) {
            /* Tronquer silencieusement fabriquerait des collisions d'identité :
             * deux appareils différents partageraient le même logicalId. */
            throw new RuntimeException(__("L'identifiant dépasse 127 caractères :", __FILE__) . ' ' . $uid);
        }
        $report['uid'] = $uid;

        /* Le vocabulaire est lu avant toute écriture : s'il manque, rien ne doit
         * être créé à moitié. */
        self::capabilities();

        $meta        = isset($_model['meta']) && is_array($_model['meta']) ? $_model['meta'] : array();
        $channels    = isset($_model['channels']) && is_array($_model['channels']) ? $_model['channels'] : array();

        /* Un modèle arrivé en tableau peut n'avoir pas d'empreinte : la page de
         * configuration manuelle et les imports JSON écrits à la main n'en
         * mettent pas. Sans elle, le raccourci d'idempotence ne se déclenche
         * jamais et l'empreinte n'est jamais posée : tout l'équipement serait
         * repassé en revue à chaque message retenu, en silence. Elle est donc
         * recalculée ici, par le même code que celui du démon — à défaut de
         * quoi le modèle est refusé, car une empreinte fausse vaudrait pire que
         * pas d'empreinte du tout. */
        $fingerprint = self::str($_model, 'fingerprint');
        if ($fingerprint === '') {
            $fingerprint = self::fingerprintOf($_model);
        }

        /* La confiance dit si l'absence d'un canal est une information ou un
         * simple silence : elle voyage avec l'identité et gouverne le sort des
         * commandes orphelines. Un modèle qui n'en porte pas est réputé certain,
         * comme le défaut de MqttbeDeviceModel. */
        $confidence = strtolower(self::str($identity, 'confidence'));
        if ($confidence === '') {
            $confidence = self::CONFIDENCE_TRUSTED;
        }

        $eqLogic = self::findByIdentity($identity);
        $isNew   = !is_object($eqLogic);

        /* Idempotence : même empreinte ET même nombre de commandes, aucune
         * écriture. C'est le cas de loin le plus fréquent — à chaque démarrage
         * du démon, tout le parc repasse ici. Le compte est indispensable :
         * l'empreinte ne décrit que le modèle, elle ne dit rien de ce que la
         * base contient réellement, et une commande supprimée à la main ne
         * serait jamais recréée. Un équipement d'avant ce compte n'en a pas :
         * il repasse une fois par la voie longue, le temps de l'acquérir. */
        if (!$isNew && $fingerprint !== ''
            && (string) $eqLogic->getConfiguration(self::CONF_FINGERPRINT, '') === $fingerprint
            && self::countMatches($eqLogic)) {
            /*
             * Une seule exception au « rien à écrire » : les métadonnées
             * volatiles. Un appareil qui a changé d'adresse arrive ici avec la
             * même empreinte — elle les exclut délibérément — et c'est donc le
             * seul endroit où l'adresse peut être rafraîchie. L'écriture n'a
             * lieu que si quelque chose a réellement changé.
             */
            if (self::refreshVolatile($eqLogic, $meta)) {
                $eqLogic->save();
                $report['touched'] = 1;
            }
            $report['status']     = 'unchanged';
            $report['name']       = $eqLogic->getName();
            $report['eqLogic_id'] = $eqLogic->getId();
            mqttbe::logger('debug', __('Modèle inchangé, rien à écrire :', __FILE__) . ' ' . $uid);
            return $report;
        }

        if ($isNew) {
            $eqLogic = new mqttbe();
            $eqLogic->setEqType_name('mqttbe');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
        }
        self::applyEqLogic($eqLogic, $isNew, $uid, $identity, $meta, $_model);

        $changed = $isNew || $eqLogic->getChanged();
        if ($changed) {
            /* L'équipement est enregistré avant ses commandes : cmd::save()
             * refuse une commande sans eqLogic_id. */
            $eqLogic->save();
        }
        $report['eqLogic_id'] = $eqLogic->getId();
        $report['name']       = $eqLogic->getName();
        if ($isNew) {
            self::indexEqLogic($eqLogic);
        }

        $report = self::applyChannels($eqLogic, $channels, $report, $confidence);

        /* L'empreinte n'est posée qu'une fois tout le reste écrit : interrompue
         * en cours de route, la fabrique doit repasser au prochain message, pas
         * se croire à jour sur un équipement à moitié construit.
         *
         * Et elle n'est pas posée du tout si une écriture a échoué : l'échec est
         * rattrapé commande par commande pour ne pas emporter les autres, mais
         * une empreinte à jour poserait dessus un couvercle définitif — au
         * passage suivant le raccourci conclurait « inchangé » et la commande
         * manquante ne serait jamais retentée. */
        $failed = (int) $report['cmd']['failed'];
        if ($fingerprint !== '' && $failed === 0) {
            $count = self::countCmd($eqLogic);
            if ((string) $eqLogic->getConfiguration(self::CONF_FINGERPRINT, '') !== $fingerprint
                || (string) $eqLogic->getConfiguration(self::CONF_CMDCOUNT, '') !== (string) $count) {
                $eqLogic->setConfiguration(self::CONF_FINGERPRINT, $fingerprint);
                $eqLogic->setConfiguration(self::CONF_CMDCOUNT, $count);
                $eqLogic->save();
                $changed = true;
            }
        } elseif ($failed > 0) {
            mqttbe::logger('warning', sprintf(
                __('Équipement %1$s : %2$d écriture(s) refusée(s), empreinte non posée — le modèle sera repassé en revue au prochain message', __FILE__),
                $uid, $failed));
        }

        $touched = $report['cmd']['created'] + $report['cmd']['updated']
                 + $report['cmd']['orphaned'] + $report['cmd']['removed'];
        $report['touched'] = $touched;
        if ($isNew) {
            $report['status'] = 'created';
        } elseif ($changed || $touched > 0) {
            $report['status'] = 'updated';
        } else {
            $report['status'] = 'unchanged';
        }

        if ($report['status'] !== 'unchanged') {
            mqttbe::logger('info', sprintf(
                __('Équipement %1$s (%2$s) : %3$s, %4$d commande(s) touchée(s)', __FILE__),
                $eqLogic->getName(), $uid, $report['status'], $touched));
            /* La table de routage doit suivre les commandes qui viennent de
             * changer. L'envoi est différé à la fin de la requête par le coeur
             * du plugin : appeler ici ne coûte rien de plus qu'un drapeau. */
            if (method_exists('mqttbe', 'scheduleRoutingPush')) {
                mqttbe::scheduleRoutingPush();
            }
        }
        return $report;
    }

    /**
     * Champs et configuration de l'équipement.
     *
     * Le nom suit la même règle que celui des commandes : posé à la création,
     * laissé tel quel dès que l'utilisateur y a touché. L'objet parent
     * (object_id) n'est jamais fixé par la fabrique — le rangement des pièces
     * appartient à l'utilisateur.
     */
    /**
     * Range les métadonnées qui changent sans que l'appareil change.
     *
     * Rend true si l'une d'elles a bougé, pour que l'appelant décide d'écrire.
     * Une valeur vide n'efface jamais une valeur connue : un modèle dégradé —
     * l'annonce sans le `info` — ne doit pas faire perdre l'adresse acquise.
     */
    private static function refreshVolatile($_eqLogic, $_meta) {
        $change = false;
        foreach (array('ip'         => self::CONF_IP,
                       'firmware'   => self::CONF_FIRMWARE,
                       'config_url' => self::CONF_CONFIG_URL) as $champ => $conf) {
            $valeur = self::str($_meta, $champ);
            if ($valeur === '') {
                continue;
            }
            if ((string) $_eqLogic->getConfiguration($conf, '') !== $valeur) {
                $_eqLogic->setConfiguration($conf, $valeur);
                $change = true;
            }
        }
        return $change;
    }

    /**
     * Le nom technique contient-il déjà le nom de l'appareil ?
     *
     * La comparaison se fait sur la forme réduite — minuscules, sans séparateurs
     * — parce qu'un appareil jamais renommé porte son propre nom d'hôte :
     * « Shelly EM 777B35 » et « shellyemtableauelectrique » se ressemblent assez
     * pour que la redite saute aux yeux, alors qu'une comparaison littérale ne
     * la voit pas.
     */
    private static function containsName($_technique, $_appareil) {
        $reduit = function ($_texte) {
            return preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $_texte, 'UTF-8'));
        };
        $a = $reduit($_appareil);
        return $a !== '' && strpos($reduit($_technique), $a) !== false;
    }

    private static function applyEqLogic($_eqLogic, $_isNew, $_uid, $_identity, $_meta, $_model) {
        /* L'identifiant d'hier est exactement un des chemins par lesquels
         * l'appareil peut se représenter : un adapter qui change de clé entre
         * deux versions, ou un appareil vu tantôt par sa MAC tantôt par son
         * préfixe de topic, revient par l'ancien uid. Le remplacer sans le
         * ranger dans les alias rendrait l'équipement méconnaissable par le
         * chemin qui l'a fait naître, et la découverte suivante en créerait un
         * second — l'anti-doublon pris à revers. */
        $previousKeys = array();
        if (!$_isNew) {
            foreach (array((string) $_eqLogic->getLogicalId(),
                           (string) $_eqLogic->getConfiguration(self::CONF_UID, '')) as $old) {
                if ($old !== '' && $old !== $_uid) {
                    $previousKeys[$old] = true;
                }
            }
        }
        $_eqLogic->setLogicalId($_uid);
        $_eqLogic->setConfiguration(self::CONF_UID, $_uid);

        $adapter = self::str($_identity, 'adapter');
        if ($adapter !== '') {
            $previous = (string) $_eqLogic->getConfiguration(self::CONF_ADAPTER, '');
            if (!$_isNew && $previous !== '' && $previous !== $adapter) {
                /* L'arbitrage par priorité entre adapters appartient au jalon 3 ;
                 * la trace au journal évite qu'une reprise silencieuse passe
                 * inaperçue en attendant. */
                mqttbe::logger('info', sprintf(
                    __('Équipement %1$s repris par l\'adapter %2$s (précédemment %3$s)', __FILE__),
                    $_uid, $adapter, $previous));
            }
            $_eqLogic->setConfiguration(self::CONF_ADAPTER, $adapter);
        }

        /* Les alias sont cumulés et jamais retirés : un appareil qui cesse
         * d'annoncer son préfixe de topic doit rester reconnaissable par lui,
         * sinon la découverte suivante le recrée en double. */
        $aliases = $_eqLogic->getConfiguration(self::CONF_ALIASES, array());
        if (!is_array($aliases)) {
            $aliases = array();
        }
        foreach (array_merge(self::identityKeys($_identity), array_keys($previousKeys)) as $alias) {
            if ($alias !== $_uid && !in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }
        $_eqLogic->setConfiguration(self::CONF_ALIASES, array_values($aliases));

        self::refreshVolatile($_eqLogic, $_meta);

        foreach (array('manufacturer' => self::CONF_MANUFACTURER,
                       'model'        => self::CONF_MODEL) as $field => $conf) {
            $value = self::str($_meta, $field);
            if ($value !== '') {
                $_eqLogic->setConfiguration($conf, $value);
            }
        }
        $availability = isset($_model['availability']) && is_array($_model['availability'])
                      ? $_model['availability'] : array();
        if (!empty($availability)) {
            $_eqLogic->setConfiguration(self::CONF_AVAILABILITY, $availability);
        }

        $desired = self::str($_meta, 'name');
        if ($desired === '') {
            $desired = self::str($_meta, 'model_name');
        }
        if ($desired === '') {
            $desired = $_uid;
        }

        /*
         * Le nom que l'utilisateur a donné à l'appareil, quand on a pu le lire.
         *
         * Il vient après le nom technique et ne le remplace pas : « Shelly 1
         * 55670C chaudiere ». Le premier reste unique et permet de retrouver
         * l'appareil dans le parc, le second dit enfin ce qu'il commande — sans
         * lui, dix-sept équipements se ressemblent à six chiffres près.
         *
         * Absent, vide, ou sonde en échec : le nom technique seul, exactement
         * comme avant. Un appareil injoignable ne doit jamais dégrader ce qui
         * existe déjà.
         */
        $deviceName = self::str($_meta, 'device_name');
        if ($deviceName !== '') {
            $_eqLogic->setConfiguration(self::CONF_DEVICE_NAME, $deviceName);
        } else {
            /* Rien dans ce message : on reprend ce qu'on savait déjà. */
            $deviceName = (string) $_eqLogic->getConfiguration(self::CONF_DEVICE_NAME, '');
        }
        if ($deviceName !== '' && !self::containsName($desired, $deviceName)) {
            /* Un tiret cadratin plutôt qu'une espace : « Shelly EM 777B35
             * shellyemtableauelectrique » se lit comme un seul mot, et la
             * vignette le tronque au pire endroit. */
            $desired .= ' — ' . $deviceName;
        }
        $generated = $_eqLogic->getConfiguration(self::CONF_GENERATED, array());
        if (!is_array($generated)) {
            $generated = array();
        }
        $owned = $_isNew
              || (isset($generated['name'])
                  && (string) $generated['name'] === (string) $_eqLogic->getName());
        if ($owned) {
            $_eqLogic->setName(self::uniqueEqLogicName($desired, $_eqLogic));
            /* On relit le nom après coup : le coeur nettoie et tronque, et
             * l'empreinte doit porter ce qui est réellement en base, sinon la
             * prochaine passe croira l'utilisateur passé par là. */
            $generated['name'] = $_eqLogic->getName();
            $_eqLogic->setConfiguration(self::CONF_GENERATED, $generated);
        }
    }

    /* ------------------------------------------------------------ commandes */

    /**
     * Crée ou met à jour les commandes, puis résout les liens action → info.
     *
     * Les informations passent d'abord, les actions ensuite : le lien
     * (cmd.value) réclame l'identifiant de la commande d'information, qui
     * n'existe qu'une fois celle-ci enregistrée. Faire l'inverse obligerait à
     * enregistrer chaque action deux fois.
     */
    private static function applyChannels($_eqLogic, $_channels, $report, $_confidence = self::CONFIDENCE_TRUSTED) {
        $wanted  = array();
        $ordered = array('info' => array(), 'action' => array());
        $index   = 0;
        foreach ($_channels as $channel) {
            $channel = self::toArray($channel);
            $key = self::str($channel, 'key');
            if ($key === '') {
                $report['messages'][] = __('Canal sans clé ignoré', __FILE__);
                continue;
            }
            if (isset($wanted[$key])) {
                /* La clé est le logicalId de la commande : deux canaux de même
                 * clé produiraient deux commandes indiscernables, et la seconde
                 * écraserait la première à chaque passage. */
                $report['messages'][] = __('Canal en double, ignoré :', __FILE__) . ' ' . $key;
                continue;
            }
            $capability = self::capability(self::str($channel, 'capability'));
            if ($capability === null) {
                $report['messages'][] = __('Canal sans capacité exploitable, ignoré :', __FILE__) . ' ' . $key;
                continue;
            }
            $type = ($capability['type'] === 'action') ? 'action' : 'info';
            $wanted[$key] = true;
            $ordered[$type][] = array('channel' => $channel, 'capability' => $capability,
                                      'key' => $key, 'index' => $index);
            $index++;
        }

        /* Les noms déjà pris sur l'équipement, commandes manuelles comprises :
         * la déduplication doit se faire avant l'enregistrement, faute de quoi
         * la contrainte cmd (eqLogic_id, name) rejette l'équipement entier. */
        $taken = array();
        $existing = $_eqLogic->getId() != '' ? cmd::byEqLogicId($_eqLogic->getId()) : array();
        if (!is_array($existing)) {
            $existing = array();
        }
        /* Les commandes existantes sont lues une seule fois : une recherche par
         * canal ferait autant d'allers-retours en base qu'un appareil a de
         * canaux, à chaque message de découverte rejoué. */
        $known = array('logicalId' => array(), 'key' => array());
        foreach ($existing as $cmd) {
            $taken[self::normalizeKey($cmd->getName())] = 'id:' . $cmd->getId();
            $known['logicalId'][(string) $cmd->getLogicalId()] = $cmd;
            $cmdKey = (string) $cmd->getConfiguration(self::CONF_KEY, '');
            if ($cmdKey !== '') {
                $known['key'][$cmdKey] = $cmd;
            }
        }

        $infoByKey        = array();
        $infoByCapability = array();
        foreach ($ordered['info'] as $entry) {
            $result = self::applyChannel($_eqLogic, $entry, $taken, $report, $known);
            if ($result === null) {
                continue;
            }
            $infoByKey[$entry['key']] = $result;
            $capName = $entry['capability']['capability'];
            if (!isset($infoByCapability[$capName])) {
                $infoByCapability[$capName] = array();
            }
            $infoByCapability[$capName][$entry['key']] = $result;
        }
        foreach ($ordered['action'] as $entry) {
            self::applyChannel($_eqLogic, $entry, $taken, $report, $known,
                               $infoByKey, $infoByCapability);
        }

        /* Un modèle qui n'est pas donné pour certain ne prouve rien par ce qu'il
         * tait. L'adapter Gen1 annonce « probable » tant que le « info » n'est
         * pas arrivé et ne décrit alors qu'un canal : appliquer le sort des
         * orphelins à ce modèle-là détruirait les commandes d'action de tout
         * appareil un peu lent, à chaque redémarrage du démon. On laisse donc
         * les commandes en place et l'on attend le modèle complet. */
        if ($_confidence !== self::CONFIDENCE_TRUSTED) {
            $candidates = self::orphanCandidates($wanted, $existing);
            if (!empty($candidates)) {
                mqttbe::logger('info', sprintf(
                    __('Modèle de confiance « %1$s » : %2$d commande(s) absente(s) laissée(s) en place sur %3$s', __FILE__),
                    $_confidence, count($candidates), $_eqLogic->getName()));
            }
            return $report;
        }

        return self::handleOrphans($_eqLogic, $wanted, $report, $existing);
    }

    /**
     * Une commande, de bout en bout.
     *
     * @return cmd|null la commande enregistrée, null en cas d'échec sur celle-ci.
     */
    private static function applyChannel($_eqLogic, $_entry, &$_taken, &$report, $_known,
                                         $_infoByKey = array(), $_infoByCapability = array()) {
        $channel    = $_entry['channel'];
        $capability = $_entry['capability'];
        $key        = $_entry['key'];

        $cmd   = self::findCmd($_known, $key);
        $isNew = !is_object($cmd);
        if ($isNew) {
            $cmd = new mqttbeCmd();
            $cmd->setEqLogic_id($_eqLogic->getId());
            $cmd->setEqType('mqttbe');
        }
        $cmd->setLogicalId($key);
        $cmd->setConfiguration(self::CONF_KEY, $key);
        $cmd->setConfiguration(self::CONF_CAPABILITY, $capability['capability']);

        /* Un canal réapparu redevient une commande ordinaire : l'utilisateur
         * retrouve son historique et ses scénarios intacts. */
        if (!$isNew && (string) $cmd->getConfiguration(self::CONF_ORPHAN, '') !== '') {
            $cmd->setConfiguration(self::CONF_ORPHAN, '');
        }

        self::applyPlumbing($cmd, $channel, $capability, $isNew);

        /* Réglages que le vocabulaire peut attacher à une capacité (bornes d'un
         * curseur, par exemple) : posés à la création et plus jamais retouchés.
         * Ils passent par le fichier plutôt que par du code ici, pour que
         * capabilities.json reste le seul fichier à reprendre. */
        if ($isNew) {
            foreach ($capability['configuration'] as $confKey => $confValue) {
                $cmd->setConfiguration($confKey, $confValue);
            }
        }

        $desiredName = self::str($channel, 'name');
        if ($desiredName === '') {
            $desiredName = (string) $capability['name'];
        }
        if ($desiredName === '') {
            $desiredName = $key;
        }
        $unit = self::str($channel, 'unit');
        if ($unit === '') {
            $unit = (string) $capability['unit'];
        }
        $order = isset($channel['order']) ? (int) $channel['order'] : (int) $capability['order'];
        if ($order === 0) {
            $order = $_entry['index'] + 1;
        }
        $desired = array(
            'name'         => $desiredName,
            'type'         => $capability['type'],
            'subType'      => $capability['subType'],
            'generic_type' => (string) $capability['generic_type'],
            /* cmd.unite est un varchar(45) et le coeur ne tronque pas : la
             * coupe se fait en caractères, sinon une unité accentuée serait
             * tranchée au milieu d'un caractère et rendue invalide. */
            'unite'        => function_exists('mb_substr')
                            ? mb_substr($unit, 0, self::MAX_UNIT, 'UTF-8')
                            : substr($unit, 0, self::MAX_UNIT),
            'isVisible'    => (string) ((int) $capability['isVisible']),
            'isHistorized' => (string) ((int) $capability['isHistorized']),
            'order'        => (string) $order,
        );
        foreach (array('dashboard', 'mobile') as $version) {
            $template = isset($capability['template'][$version])
                      ? trim((string) $capability['template'][$version]) : '';
            if ($template !== '') {
                $desired['template::' . $version] = $template;
            }
        }
        self::applyPresentation($cmd, $desired, $isNew, $_taken);

        if ($capability['type'] === 'action') {
            $value = self::resolveLink($channel, $capability, $key, $_infoByKey, $_infoByCapability);
            if ($value !== null && (string) $cmd->getValue() !== (string) $value) {
                $cmd->setValue($value);
            }
        }

        try {
            if ($isNew) {
                $cmd->save();
                $report['cmd']['created']++;
            } elseif ($cmd->getChanged()) {
                $cmd->save();
                $report['cmd']['updated']++;
            } else {
                $report['cmd']['unchanged']++;
            }
        } catch (Throwable $e) {
            /* Une commande refusée ne doit pas emporter les autres : le reste de
             * l'équipement est utilisable, et le journal dit laquelle manque.
             * L'échec est compté : il interdira de poser l'empreinte, sans quoi
             * cette commande ne serait jamais retentée. */
            $report['cmd']['failed']++;
            $report['messages'][] = $key . ' : ' . $e->getMessage();
            mqttbe::logger('error', __('Commande refusée :', __FILE__) . ' ' . $key
                . ' — ' . $e->getMessage());
            return null;
        }
        return $cmd;
    }

    /**
     * Retrouve la commande d'un canal.
     *
     * Par logicalId d'abord, par la clé mémorisée ensuite : un utilisateur qui a
     * modifié le logicalId à la main ne doit pas provoquer la création d'un
     * doublon, la clé d'origine reste la référence.
     */
    private static function findCmd($_known, $_key) {
        if (isset($_known['logicalId'][$_key])) {
            return $_known['logicalId'][$_key];
        }
        if (isset($_known['key'][$_key])) {
            return $_known['key'][$_key];
        }
        return null;
    }

    /**
     * La plomberie : ce que la fabrique réécrit toujours.
     *
     * Topic, chemin JSON et charge utile décrivent le câblage, pas la
     * présentation : un firmware qui déplace une valeur doit être suivi, sinon
     * la commande cesse de remonter quoi que ce soit sans que rien ne le dise.
     *
     * Les réglages fins (map, échelle, arrondi, répétition) suivent une règle
     * différente : posés à la création, ils ne sont ensuite réécrits que si le
     * modèle en parle explicitement. Un utilisateur qui allonge le keepalive
     * d'un capteur bavard garde son réglage.
     */
    private static function applyPlumbing($_cmd, $_channel, $_capability, $_isNew) {
        if ($_capability['type'] === 'action') {
            $sink = isset($_channel['sink']) && is_array($_channel['sink']) ? $_channel['sink'] : array();
            $_cmd->setConfiguration('topic', self::str($sink, 'topic'));

            $payload = isset($sink['payload']) ? $sink['payload'] : '';
            if (is_array($payload)) {
                /* Une charge utile structurée (RPC Shelly) est rangée telle
                 * quelle : mqttbeCmd::execute() publie une chaîne. */
                $payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $_cmd->setConfiguration('payload', (string) $payload);

            $encoding = self::str($sink, 'encoding');
            if ($encoding !== '') {
                $_cmd->setConfiguration('encoding', $encoding);
            }
            self::applyTuning($_cmd, array(
                'qos'    => isset($sink['qos']) ? (int) $sink['qos'] : null,
                'retain' => isset($sink['retain']) ? (int) ((bool) $sink['retain']) : null,
            ), array('qos' => 0, 'retain' => 0), $_isNew);
            return;
        }

        $source   = isset($_channel['source']) && is_array($_channel['source']) ? $_channel['source'] : array();
        $selector = isset($source['selector']) && is_array($source['selector']) ? $source['selector'] : array();
        $type     = self::str($selector, 'type');
        if ($type === '') {
            $type = 'raw';
        }
        $_cmd->setConfiguration('topic', self::str($source, 'topic'));
        $_cmd->setConfiguration('path', $type === 'json' ? self::str($selector, 'path') : '');
        if ($type !== 'raw' && $type !== 'json') {
            /* Sélecteur d'un jalon ultérieur : il est conservé intact pour que
             * rien ne soit perdu, mais le démon ne saura pas encore l'appliquer. */
            $_cmd->setConfiguration(self::CONF_SELECTOR,
                json_encode($selector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            mqttbe::logger('warning', __('Sélecteur non pris en charge :', __FILE__)
                . ' ' . $type . ' (' . self::str($_channel, 'key') . ')');
        }

        /* Transformation et répétition sont cherchées à plusieurs endroits : le
         * canal les porte tantôt à sa racine, tantôt dans « value » ou dans
         * « source », selon l'adapter qui l'a produit. Seuls source, sink et
         * value traversent MqttbeChannel intacts — chercher au seul endroit du
         * contrat ferait perdre en silence l'arrondi d'un capteur. */
        $value     = isset($_channel['value']) && is_array($_channel['value'])
                   ? $_channel['value'] : array();
        $transform = self::firstArray(array($_channel, $value, $source), 'transform');
        $repeat    = self::firstArray(array($_channel, $value, $source), 'repeat');

        $map = null;
        if (isset($transform['map']) && is_array($transform['map'])) {
            $map = $transform['map'];
        } elseif (isset($value['map']) && is_array($value['map'])) {
            $map = $value['map'];
        } elseif (self::str($value, 'type') === 'bool'
                  && (isset($value['true']) || isset($value['false']))) {
            $map = self::booleanMap($value);
        }

        self::applyTuning($_cmd, array(
            'map'         => $map === null ? null : json_encode($map, JSON_UNESCAPED_UNICODE),
            'scale'       => self::pick(array($transform, $value), 'scale'),
            'offset'      => self::pick(array($transform, $value), 'offset'),
            'round'       => self::pick(array($transform, $value), 'round'),
            'repeat'      => self::pick(array($repeat), 'mode'),
            'keepalive'   => self::pick(array($repeat), 'keepalive'),
            'minInterval' => self::pick(array($repeat), 'minInterval'),
        ), array(
            /* Aucun défaut pour map, scale, offset et round : une clé écrite à
             * vide n'est pas une clé absente, et un lecteur qui interroge
             * getConfiguration('scale', 1) recevrait la chaîne vide au lieu de
             * son propre défaut. */
            'repeat'      => 'onchange',
            /* 300 s par défaut : sans réémission, le champ « dernière
             * communication » d'un capteur stable vieillit indéfiniment et
             * l'équipement finit par passer en timeout sans raison. */
            'keepalive'   => 300,
            'minInterval' => 0,
        ), $_isNew);
    }

    /**
     * Une charge utile binaire s'écrit de plusieurs façons selon les firmwares.
     *
     * Le modèle dit quelle valeur Jeedom correspond au vrai et au faux ; les
     * orthographes rencontrées sur le terrain (JSON true/false, Shelly Gen1
     * on/off, 1/0) sont toutes rangées vers ces deux valeurs. La correspondance
     * étant une égalité exacte de chaînes, une entrée inutile ne coûte rien.
     */
    private static function booleanMap($_value) {
        $true  = isset($_value['true'])  ? (string) $_value['true']  : '1';
        $false = isset($_value['false']) ? (string) $_value['false'] : '0';
        return array(
            'true' => $true,  'false' => $false,
            'True' => $true,  'False' => $false,
            '1'    => $true,  '0'     => $false,
            'on'   => $true,  'off'   => $false,
            'ON'   => $true,  'OFF'   => $false,
        );
    }

    private static function applyTuning($_cmd, $_wanted, $_defaults, $_isNew) {
        foreach ($_wanted as $key => $value) {
            if ($value !== null && $value !== '') {
                $_cmd->setConfiguration($key, $value);
                continue;
            }
            if ($_isNew && array_key_exists($key, $_defaults)) {
                $_cmd->setConfiguration($key, $_defaults[$key]);
            }
        }
    }

    /**
     * Les champs de présentation, champ par champ.
     *
     * mqttbe::generated garde ce que la fabrique a écrit la dernière fois. Au
     * passage suivant, un champ dont la valeur en base diffère de cette trace a
     * été repris par l'utilisateur : il n'est plus jamais réécrit, et la trace
     * n'est pas mise à jour — sans quoi la fabrique se croirait de nouveau
     * propriétaire au passage d'après.
     *
     * Le suivi est fait champ par champ, et non par une empreinte globale :
     * renommer une commande ne doit pas figer l'unité ni le type générique, qui
     * peuvent encore être corrigés par une version du vocabulaire.
     *
     * Une commande sans trace et déjà existante est réputée entièrement
     * manuelle : un équipement créé à la main puis repris par la découverte
     * garde sa présentation, seule sa plomberie est rafraîchie.
     */
    private static function applyPresentation($_cmd, $_desired, $_isNew, &$_taken) {
        $generated = $_cmd->getConfiguration(self::CONF_GENERATED, array());
        if (!is_array($generated)) {
            $generated = array();
        }
        /* Champs adoptés : rencontrés garnis sur une commande existante alors
         * que la fabrique ne les suivait pas encore. Ils sont rangés à part
         * parce que la trace, seule, ne saurait pas les distinguer de ce que la
         * fabrique a écrit — et les confondre reviendrait à s'en dire
         * propriétaire au passage suivant, c'est-à-dire à les écraser. */
        $user = isset($generated[self::GENERATED_USER]) && is_array($generated[self::GENERATED_USER])
              ? $generated[self::GENERATED_USER] : array();
        $trace     = $generated;
        unset($trace[self::GENERATED_USER]);
        $hasTrace  = !empty($trace);
        $ownerKey = $_cmd->getId() != '' ? 'id:' . $_cmd->getId() : 'new:' . $_cmd->getLogicalId();

        foreach (self::$_presentationFields as $field) {
            if (!isset($_desired[$field])) {
                continue;
            }
            $current = self::presentationValue($_cmd, $field);
            if ($_isNew) {
                $owned = true;
            } elseif (!$hasTrace) {
                $owned = false;
            } elseif (isset($user[$field])) {
                $owned = false;
            } elseif (array_key_exists($field, $generated)) {
                $owned = ((string) $generated[$field] === (string) $current);
            } elseif (!self::presentationIsUnset($field, $current)
                      && (string) $current !== (string) $_desired[$field]) {
                /* Champ suivi depuis peu, déjà garni, et garni autrement que ce
                 * que la fabrique poserait : personne d'autre que l'utilisateur
                 * n'a pu l'écrire. On enregistre la valeur courante SANS
                 * l'écrire — le jour où une capacité gagne un « template », le
                 * widget personnalisé doit survivre, et pas seulement d'un
                 * passage. */
                $generated[$field] = $current;
                $user[$field] = true;
                $owned = false;
            } else {
                /* Champ suivi depuis peu et vide, ou déjà conforme : il n'y a
                 * rien de l'utilisateur à protéger, la fabrique le prend en
                 * charge comme les autres. */
                $owned = true;
            }
            if (!$owned) {
                if ($field === 'name') {
                    $_taken[self::normalizeKey($current)] = $ownerKey;
                }
                continue;
            }
            $value = $_desired[$field];
            if ($field === 'name') {
                $value = self::uniqueCmdName($value, $_taken, $ownerKey, $current);
            }
            self::presentationApply($_cmd, $field, $value);
            /* Relu après écriture : le coeur nettoie et tronque les noms, et la
             * trace doit porter ce qui est réellement en base. */
            $generated[$field] = self::presentationValue($_cmd, $field);
            if ($field === 'name') {
                $_taken[self::normalizeKey($generated[$field])] = $ownerKey;
            }
        }
        if (!empty($user)) {
            $generated[self::GENERATED_USER] = $user;
        }
        $_cmd->setConfiguration(self::CONF_GENERATED, $generated);
    }

    /**
     * Ce champ est-il vide de toute décision ?
     *
     * Un champ vide n'a été choisi par personne : la fabrique peut le prendre en
     * charge le jour où le vocabulaire le garnit. « core::default » est le vide
     * du cœur, qui remplit lui-même les deux widgets à l'enregistrement quand
     * on ne lui en donne pas : le prendre pour un choix de l'utilisateur
     * gèlerait à jamais l'affichage de toutes les commandes déjà créées.
     */
    private static function presentationIsUnset($_field, $_value) {
        $value = (string) $_value;
        if ($value === '') {
            return true;
        }
        return strpos($_field, 'template::') === 0 && $value === 'core::default';
    }

    private static function presentationValue($_cmd, $_field) {
        switch ($_field) {
            case 'name':         return (string) $_cmd->getName();
            case 'type':         return (string) $_cmd->getType();
            case 'subType':      return (string) $_cmd->getSubType();
            case 'generic_type': return (string) $_cmd->getGeneric_type();
            case 'unite':        return (string) $_cmd->getUnite();
            case 'isVisible':    return (string) ((int) $_cmd->getIsVisible());
            case 'isHistorized': return (string) ((int) $_cmd->getIsHistorized());
            case 'order':        return (string) ((int) $_cmd->getOrder());
            case 'template::dashboard': return (string) $_cmd->getTemplate('dashboard', '');
            case 'template::mobile':    return (string) $_cmd->getTemplate('mobile', '');
        }
        return '';
    }

    private static function presentationApply($_cmd, $_field, $_value) {
        switch ($_field) {
            case 'name':         $_cmd->setName($_value); break;
            case 'type':         $_cmd->setType($_value); break;
            case 'subType':      $_cmd->setSubType($_value); break;
            case 'generic_type': $_cmd->setGeneric_type($_value); break;
            case 'unite':        $_cmd->setUnite($_value); break;
            case 'isVisible':    $_cmd->setIsVisible((int) $_value); break;
            case 'isHistorized': $_cmd->setIsHistorized((int) $_value); break;
            case 'order':        $_cmd->setOrder((int) $_value); break;
            case 'template::dashboard': $_cmd->setTemplate('dashboard', $_value); break;
            case 'template::mobile':    $_cmd->setTemplate('mobile', $_value); break;
        }
    }

    /* ------------------------------------------------------- canaux disparus */

    /**
     * Commandes dont le canal a disparu du modèle.
     *
     * Elles ne sont pas supprimées : un identifiant de commande est référencé
     * par les scénarios, les vues, les plans et les interactions, et le
     * supprimer casse tout cela sans avertissement et sans retour possible. Une
     * disparition peut d'ailleurs n'être qu'un firmware qui a cessé d'annoncer
     * un composant le temps d'un redémarrage.
     *
     * La commande est donc retirée du tableau de bord et marquée : l'utilisateur
     * la retrouve dans la page de l'équipement, avec la date à laquelle elle a
     * cessé d'exister. La table cmd n'a pas de colonne isEnable — isVisible est
     * la seule mise en sommeil possible au niveau d'une commande.
     *
     * Seul cas de suppression : ni historique, ni référence nulle part. Rien ne
     * peut alors casser, et laisser traîner des commandes mortes finirait par
     * rendre la page de l'équipement illisible.
     */
    private static function handleOrphans($_eqLogic, $_wanted, $report, $_existing = null) {
        if ($_eqLogic->getId() == '') {
            return $report;
        }
        /* Les commandes ont déjà été lues par applyChannels : les relire ferait
         * un aller-retour de plus en base à chaque message de découverte. */
        $cmds = is_array($_existing) ? $_existing : cmd::byEqLogicId($_eqLogic->getId());
        if (!is_array($cmds)) {
            return $report;
        }
        $candidates = self::orphanCandidates($_wanted, $cmds);
        if (empty($candidates)) {
            return $report;
        }

        /* Perdre d'un coup la moitié de ses canaux ressemble moins à un appareil
         * amputé qu'à un modèle incomplet — un adapter qui n'a pas tout vu, un
         * firmware qui répond mal. Le sort des orphelins reste appliqué (le
         * modèle se dit certain), mais la trace en warning donne à
         * l'exploitation le moyen de reconnaître le cas sans relire la base. */
        $owned = 0;
        foreach ($cmds as $cmd) {
            if ((string) $cmd->getConfiguration(self::CONF_KEY, '') !== '') {
                $owned++;
            }
        }
        if ($owned > 0 && count($candidates) >= 2
            && count($candidates) >= $owned * self::ORPHAN_ALERT_RATIO) {
            mqttbe::logger('warning', sprintf(
                __('Équipement %1$s : %2$d commande(s) sur %3$d n\'apparaissent plus dans le modèle — modèle incomplet ?', __FILE__),
                $_eqLogic->getName(), count($candidates), $owned));
        }

        foreach ($candidates as $cmd) {
            $key = (string) $cmd->getConfiguration(self::CONF_KEY, '');
            if (!self::isReferenced($cmd) && !self::hasHistory($cmd)) {
                try {
                    $cmd->remove();
                    $report['cmd']['removed']++;
                    continue;
                } catch (Throwable $e) {
                    $report['cmd']['failed']++;
                    $report['messages'][] = $key . ' : ' . $e->getMessage();
                }
            }
            $cmd->setConfiguration(self::CONF_ORPHAN, date('Y-m-d H:i:s'));
            $cmd->setIsVisible(0);
            /* La trace suit la mise en sommeil : sans cela, le retrait du
             * tableau de bord passerait au prochain passage pour une retouche de
             * l'utilisateur, et un canal réapparu resterait invisible. */
            $generated = $cmd->getConfiguration(self::CONF_GENERATED, array());
            if (is_array($generated) && isset($generated['isVisible'])) {
                $generated['isVisible'] = '0';
                $cmd->setConfiguration(self::CONF_GENERATED, $generated);
            }
            try {
                $cmd->save();
                $report['cmd']['orphaned']++;
                mqttbe::logger('info', __('Canal disparu, commande désactivée :', __FILE__)
                    . ' ' . $cmd->getHumanName());
            } catch (Throwable $e) {
                $report['cmd']['failed']++;
                $report['messages'][] = $key . ' : ' . $e->getMessage();
            }
        }
        return $report;
    }

    /**
     * Commandes de la fabrique dont le canal n'est plus dans le modèle.
     *
     * Elle sert deux fois : à décider quoi endormir, et à mesurer l'ampleur de
     * ce qui disparaît — ce que la fabrique doit savoir avant d'y toucher.
     */
    private static function orphanCandidates($_wanted, $_cmds) {
        $candidates = array();
        foreach ($_cmds as $cmd) {
            $key = (string) $cmd->getConfiguration(self::CONF_KEY, '');
            if ($key === '' || isset($_wanted[$key])) {
                continue;
            }
            /* Déjà endormie à un passage précédent : elle ne compte plus comme
             * une disparition, sinon chaque passage la recompterait. */
            if ((string) $cmd->getConfiguration(self::CONF_ORPHAN, '') !== '') {
                continue;
            }
            $candidates[] = $cmd;
        }
        return $candidates;
    }

    /**
     * La commande est-elle citée quelque part ?
     *
     * En cas de doute — une exception, un plugin tiers qui répond mal — on
     * répond oui : conserver une commande inutile est sans conséquence,
     * supprimer une commande utilisée casse un scénario.
     */
    private static function isReferenced($_cmd) {
        /* Les cas tranchés d'abord, par des requêtes ciblées : getUsedBy()
         * interroge une dizaine de classes, réveille tous les plugins actifs et
         * coûte 44 ms à froid. Multiplié par les commandes disparues d'un lot de
         * découverte, cela se compte en secondes dans une seule requête HTTP —
         * le démon attend, et un dépassement de max_execution_time laisserait la
         * base à moitié réécrite. */
        $settled = self::referenceProbe($_cmd);
        if ($settled !== null) {
            return $settled;
        }
        try {
            $usedBy = $_cmd->getUsedBy();
        } catch (Throwable $e) {
            return true;
        }
        if (!is_array($usedBy)) {
            return true;
        }
        foreach ($usedBy as $type => $items) {
            if (!is_array($items)) {
                continue;
            }
            if ($type === 'plugin') {
                /* Ce rang est un tableau par plugin, toujours non vide : ce sont
                 * ses contenus qu'il faut regarder. */
                foreach ($items as $sub) {
                    if (is_array($sub) && count($sub) > 0) {
                        return true;
                    }
                }
                continue;
            }
            if (count($items) > 0) {
                return true;
            }
        }
        return false;
    }

    private static function hasHistory($_cmd) {
        if ($_cmd->getType() != 'info') {
            return false;
        }
        if ($_cmd->getIsHistorized() == 1) {
            return true;
        }
        /* Une commande dont l'historisation a été coupée conserve ses relevés :
         * les effacer serait une perte définitive. Savoir s'il en existe ne
         * demande qu'une ligne — getHistory() les charge TOUS, sans bornes, et
         * un capteur historisé depuis un an en compte des dizaines de milliers
         * qu'on ne fait que compter. */
        $settled = self::historyProbe($_cmd);
        if ($settled !== null) {
            return $settled;
        }
        try {
            $history = $_cmd->getHistory();
        } catch (Throwable $e) {
            return true;
        }
        return is_array($history) && count($history) > 0;
    }

    /**
     * Sonde de référence : une requête, une réponse tranchée, ou rien.
     *
     * Rend true si un enregistrement cite la commande, false si aucune des
     * tables qui peuvent la citer n'en porte trace, et null quand la sonde ne
     * peut pas conclure — pas de base sous la main, requête refusée, ou un
     * plugin capable de citer la commande dans ses propres tables. L'appelant
     * reprend alors le chemin long, qui, lui, sait interroger les plugins.
     *
     * La première ligne de la requête est un témoin : elle interroge la commande
     * elle-même, dont on sait qu'elle existe. Si elle ne revient pas, c'est que
     * la sonde ne voit pas la base attendue, et son silence sur les autres
     * tables ne prouve alors rien — mieux vaut ne rien conclure que supprimer
     * une commande sur une réponse vide.
     */
    private static function referenceProbe($_cmd) {
        $id = (int) $_cmd->getId();
        if ($id <= 0) {
            return true;
        }
        $origins = self::probe($id, array(
            'cmd'          => 'SELECT \'cmd\' AS origine FROM `cmd` WHERE (`value` = :id OR `configuration` LIKE :token) AND `id` != :id LIMIT 1',
            'eqLogic'      => 'SELECT \'eqLogic\' AS origine FROM `eqLogic` WHERE `configuration` LIKE :token LIMIT 1',
            'object'       => 'SELECT \'object\' AS origine FROM `object` WHERE `configuration` LIKE :token LIMIT 1',
            'scenario'     => 'SELECT \'scenario\' AS origine FROM `scenario` WHERE `trigger` LIKE :token LIMIT 1',
            'scenarioExpression' => 'SELECT \'scenarioExpression\' AS origine FROM `scenarioExpression` WHERE `expression` LIKE :token OR `options` LIKE :token LIMIT 1',
            'viewData'     => 'SELECT \'viewData\' AS origine FROM `viewData` WHERE (`type` = \'cmd\' AND `link_id` = :id) OR `configuration` LIKE :token LIMIT 1',
            'plan'         => 'SELECT \'plan\' AS origine FROM `plan` WHERE (`link_type` = \'cmd\' AND `link_id` = :id) OR `configuration` LIKE :token LIMIT 1',
            'plan3d'       => 'SELECT \'plan3d\' AS origine FROM `plan3d` WHERE (`link_type` = \'cmd\' AND `link_id` = :id) OR `configuration` LIKE :token LIMIT 1',
            'interactDef'  => 'SELECT \'interactDef\' AS origine FROM `interactDef` WHERE `actions` LIKE :token OR `reply` LIKE :token LIMIT 1',
            'interactQuery' => 'SELECT \'interactQuery\' AS origine FROM `interactQuery` WHERE `actions` LIKE :token LIMIT 1',
        ));
        if ($origins === null) {
            return null;
        }
        if (!empty($origins)) {
            mqttbe::logger('debug', __('Commande citée, conservée :', __FILE__)
                . ' ' . $_cmd->getHumanName() . ' (' . implode(', ', $origins) . ')');
            return true;
        }
        /* Un plugin peut citer la commande dans ses propres tables, que la sonde
         * ne connaît pas : dès qu'il en existe un, seul getUsedBy() peut
         * répondre. Sur une installation ordinaire il n'y en a aucun. */
        return self::pluginsMayReference() ? null : false;
    }

    /** Existe-t-il au moins un relevé pour cette commande ? */
    private static function historyProbe($_cmd) {
        $id = (int) $_cmd->getId();
        if ($id <= 0) {
            return null;
        }
        $origins = self::probe($id, array(
            'history'     => 'SELECT \'history\' AS origine FROM `history` WHERE `cmd_id` = :id LIMIT 1',
            'historyArch' => 'SELECT \'historyArch\' AS origine FROM `historyArch` WHERE `cmd_id` = :id LIMIT 1',
        ));
        if ($origins === null) {
            return null;
        }
        return !empty($origins);
    }

    /**
     * Exécute les sondes en une seule requête, témoin compris.
     *
     * @return array|null les origines trouvées, ou null si l'on ne peut rien
     *                    conclure.
     */
    private static function probe($_id, $_queries) {
        if (!class_exists('DB') || !method_exists('DB', 'Prepare')) {
            return null;
        }
        /* Chaque branche est parenthésée : MySQL refuse un LIMIT dans un membre
         * d'UNION qui n'est pas entre parenthèses (42000/1064), et sans le
         * LIMIT chaque branche ramènerait toute la table. */
        $sql = '(SELECT \'self\' AS origine FROM `cmd` WHERE `id` = :id LIMIT 1)';
        foreach ($_queries as $query) {
            $sql .= ' UNION ALL (' . $query . ')';
        }
        /* Seuls les paramètres réellement cités sont liés : PDO refuse la
         * requête entière (HY093) dès qu'on lui en donne un de trop. */
        $params = array('id' => (int) $_id);
        if (strpos($sql, ':token') !== false) {
            $params['token'] = '%#' . (int) $_id . '#%';
        }
        try {
            $rows = DB::Prepare($sql, $params, DB::FETCH_TYPE_ALL);
        } catch (Throwable $e) {
            /* Une table absente (un cœur plus ancien, une installation
             * partielle) ne doit pas faire échouer la découverte : on rend la
             * main au chemin long. */
            mqttbe::logger('debug', __('Sonde de référence indisponible :', __FILE__) . ' ' . $e->getMessage());
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }
        $origins = array();
        $witness = false;
        foreach ($rows as $row) {
            $row = is_object($row) ? get_object_vars($row) : (array) $row;
            $origin = isset($row['origine']) ? (string) $row['origine'] : '';
            if ($origin === 'self') {
                $witness = true;
                continue;
            }
            if ($origin !== '') {
                $origins[$origin] = $origin;
            }
        }
        if (!$witness) {
            return null;
        }
        return array_values($origins);
    }

    /**
     * Un plugin peut-il citer une commande sans que la base ne le dise ?
     *
     * La réponse ne change pas dans une requête : la question est posée une
     * fois. En cas de doute, oui — c'est le chemin long qui tranchera.
     */
    private static function pluginsMayReference() {
        if (self::$_pluginsUsedBy !== null) {
            return self::$_pluginsUsedBy;
        }
        self::$_pluginsUsedBy = true;
        if (!class_exists('plugin') || !method_exists('plugin', 'listPlugin')) {
            return true;
        }
        try {
            $plugins = plugin::listPlugin(true, false, true, true);
        } catch (Throwable $e) {
            return true;
        }
        if (!is_array($plugins)) {
            return true;
        }
        foreach ($plugins as $plugin) {
            if (is_string($plugin) && method_exists($plugin, 'customUsedBy')) {
                return true;
            }
        }
        self::$_pluginsUsedBy = false;
        return false;
    }

    /**
     * Nombre de commandes portées par l'équipement.
     *
     * Un COUNT quand la base est là : construire les objets pour les compter
     * coûterait, sur le chemin du callback, autant que tout le reste de la
     * passe.
     */
    private static function countCmd($_eqLogic) {
        $id = (int) $_eqLogic->getId();
        if ($id <= 0) {
            return 0;
        }
        if (class_exists('DB') && method_exists('DB', 'Prepare')) {
            try {
                $row = DB::Prepare('SELECT COUNT(*) AS total FROM `cmd` WHERE `eqLogic_id` = :id',
                                   array('id' => $id), DB::FETCH_TYPE_ROW);
                $row = is_object($row) ? get_object_vars($row) : $row;
                if (is_array($row) && isset($row['total'])) {
                    return (int) $row['total'];
                }
            } catch (Throwable $e) {
                /* On retombe sur la lecture ordinaire. */
            }
        }
        $cmds = cmd::byEqLogicId($id);
        return is_array($cmds) ? count($cmds) : 0;
    }

    /**
     * La base porte-t-elle encore le nombre de commandes du dernier passage ?
     *
     * Un équipement qui n'a pas encore ce compte (créé avant qu'il existe)
     * répond non : il repassera une fois par la voie longue, ce qui est
     * exactement l'occasion de l'acquérir.
     */
    private static function countMatches($_eqLogic) {
        $stored = (string) $_eqLogic->getConfiguration(self::CONF_CMDCOUNT, '');
        if ($stored === '') {
            return false;
        }
        return (int) $stored === self::countCmd($_eqLogic);
    }

    /**
     * Empreinte d'un modèle qui n'en porte pas.
     *
     * Calculée par la classe du modèle, celle-là même qu'emploie le démon :
     * deux chemins d'entrée doivent donner la même empreinte, sans quoi un
     * appareil importé à la main serait réécrit à chaque message retenu. Si les
     * classes de découverte manquent, le modèle est refusé — une empreinte
     * inventée ici serait pire que pas d'empreinte du tout, elle figerait
     * l'équipement sur un état que rien ne décrit.
     */
    private static function fingerprintOf($_model) {
        self::loadDiscovery();
        if (!class_exists('MqttbeDeviceModel')) {
            throw new RuntimeException(__("Le modèle ne porte pas d'empreinte et les classes de découverte sont introuvables", __FILE__));
        }
        $model = MqttbeDeviceModel::fromArray($_model);
        $fingerprint = (string) $model->fingerprint();
        if ($fingerprint === '') {
            throw new RuntimeException(__("Le modèle ne porte pas d'empreinte et elle n'a pas pu être recalculée", __FILE__));
        }
        return $fingerprint;
    }

    /* ------------------------------------------------------ liens et noms */

    /**
     * Commande d'information pilotée par une action (cmd.value).
     *
     * Le modèle est prioritaire : il désigne une clé de canal, donc exactement
     * la bonne information. À défaut, le vocabulaire désigne une capacité, et
     * l'on choisit alors l'information du même composant — « switch:0.on »
     * pilote « switch:0.output » et non le relais voisin.
     */
    private static function resolveLink($_channel, $_capability, $_key, $_infoByKey, $_infoByCapability) {
        $links = isset($_channel['links']) ? $_channel['links'] : null;
        if (is_string($links) && isset($_infoByKey[$links])) {
            return $_infoByKey[$links]->getId();
        }
        if (is_array($links)) {
            /* links est un rôle => clé de canal. Plusieurs rôles peuvent être
             * donnés (« state », « position », « power »…) et prendre le premier
             * venu revient à laisser l'ordre d'écriture du firmware décider ce
             * que le bouton pilote : cmd.value n'accepte qu'une information.
             * « state » est le rôle que Jeedom attend derrière une action — le
             * retour d'état de ce que l'action commande ; les autres ne viennent
             * qu'ensuite, dans un ordre stable pour que deux appareils
             * identiques donnent le même résultat. */
            $roles = array_keys($links);
            sort($roles, SORT_STRING);
            array_unshift($roles, 'state');
            foreach ($roles as $role) {
                if (!isset($links[$role])) {
                    continue;
                }
                $target = $links[$role];
                if (is_string($target) && isset($_infoByKey[$target])) {
                    return $_infoByKey[$target]->getId();
                }
            }
        }
        $capability = trim((string) $_capability['links']);
        if ($capability === '' || !isset($_infoByCapability[$capability])) {
            return null;
        }
        $candidates = $_infoByCapability[$capability];
        $prefix = strrpos($_key, '.') === false ? '' : substr($_key, 0, strrpos($_key, '.') + 1);
        if ($prefix !== '') {
            foreach ($candidates as $key => $cmd) {
                if (strpos($key, $prefix) === 0) {
                    return $cmd->getId();
                }
            }
        }
        $first = reset($candidates);
        return is_object($first) ? $first->getId() : null;
    }

    /**
     * Nom de commande unique sur l'équipement.
     *
     * cmd (eqLogic_id, name) est unique : deux canaux nommés « Température »
     * feraient échouer l'enregistrement de l'équipement entier, et l'utilisateur
     * verrait son appareil disparaître sans explication.
     */
    private static function uniqueCmdName($_desired, &$_taken, $_ownerKey, $_current) {
        $base = self::cleanName($_desired);
        if ($base === '') {
            $base = $_ownerKey;
        }
        $previous = self::normalizeKey($_current);
        if ($previous !== '' && isset($_taken[$previous]) && $_taken[$previous] === $_ownerKey) {
            unset($_taken[$previous]);
        }

        /* Un suffixe attribué ne se raccourcit jamais tant que la commande
         * existe. Sans cette règle, il suffit que l'utilisateur renomme
         * « Température » en « Cave » pour que la place se libère et que la
         * sonde EXTERNE, jusque-là « Température 2 », prenne le nom
         * « Température » au passage suivant : toute référence par nom — un
         * scénario, une vue, une interaction vocale — désigne alors un autre
         * capteur physique, sans rien qui le signale. */
        if ($previous !== '' && !isset($_taken[$previous])
            && self::isSuffixedFrom($_current, $base)) {
            $_taken[$previous] = $_ownerKey;
            return (string) $_current;
        }

        $candidate = $base;
        $suffix    = 2;
        while (isset($_taken[self::normalizeKey($candidate)])
               && $_taken[self::normalizeKey($candidate)] !== $_ownerKey) {
            $candidate = self::suffixedName($base, $suffix);
            $suffix++;
        }
        return $candidate;
    }

    /* Nom dédoublonné : la base rognée de quoi loger le marqueur, puis le
     * marqueur. La coupe passe par cut() et non par substr() — une base coupée
     * au milieu d'un caractère accentué serait refusée par la base de données,
     * et ce serait tout l'équipement qui ne s'enregistrerait pas. */
    private static function suffixedName($_base, $_suffix) {
        $marker = ' ' . $_suffix;
        return self::cleanName(self::cut($_base, self::MAX_NAME - strlen($marker))) . $marker;
    }

    /* Le nom porté aujourd'hui est-il celui que cette base a produit une fois
     * dédoublonnée ? La comparaison se fait sur la base rognée pour ce
     * marqueur-là : un nom long est tronqué avant de recevoir son suffixe. */
    private static function isSuffixedFrom($_current, $_base) {
        $current = trim((string) $_current);
        if (!preg_match('/^(.*[^\s])\s+(\d+)$/u', $current, $found)) {
            return false;
        }
        if ((int) $found[2] < 2) {
            return false;
        }
        return self::normalizeKey($current) === self::normalizeKey(self::suffixedName($_base, $found[2]));
    }

    /**
     * Nom d'équipement unique dans son objet parent.
     *
     * eqLogic (name, object_id) est unique, tous types confondus : le conflit
     * peut donc venir d'un équipement d'un autre plugin rangé dans la même pièce.
     */
    private static function uniqueEqLogicName($_desired, $_eqLogic) {
        $base = self::cleanName($_desired);
        if ($base === '') {
            $base = (string) $_eqLogic->getLogicalId();
        }
        $objectId = $_eqLogic->getObject_id();
        if ($objectId === '' || $objectId === false) {
            $objectId = null;
        }
        $taken = array();
        $siblings = eqLogic::byObjectId($objectId, false, false);
        if (is_array($siblings)) {
            foreach ($siblings as $sibling) {
                if ($_eqLogic->getId() != '' && $sibling->getId() == $_eqLogic->getId()) {
                    continue;
                }
                $taken[self::normalizeKey($sibling->getName())] = true;
            }
        }
        $candidate = $base;
        $suffix    = 2;
        while (isset($taken[self::normalizeKey($candidate)])) {
            $candidate = self::suffixedName($base, $suffix);
            $suffix++;
        }
        return $candidate;
    }

    /**
     * Nettoyage identique à celui du coeur.
     *
     * setName() applique cleanComponanteName() puis tronque : calculer
     * l'unicité sur le nom brut vérifierait une chaîne que la base ne verra
     * jamais, et laisserait passer le doublon qu'on cherche à éviter.
     */
    private static function cleanName($_name) {
        $name = (string) $_name;
        if (function_exists('cleanComponanteName')) {
            $name = cleanComponanteName($name);
        } else {
            $name = strip_tags(str_replace(array('&', '#', ']', '[', '%', "\\", '/', "'", '"', '*'), '', $name));
            $name = preg_replace('/\s+/', ' ', $name);
        }
        return trim(self::cut($name, self::MAX_NAME));
    }

    /**
     * Coupe qui ne casse pas un caractère.
     *
     * substr() compte des octets et la colonne compte des caractères : couper à
     * 127 octets un nom accentué tranche au milieu d'un caractère UTF-8 et
     * produit une chaîne que MySQL refuse — « Incorrect string value » (22007,
     * 1366) sur l'INSERT de l'ÉQUIPEMENT ENTIER, pas seulement du nom fautif.
     * La coupe se fait donc en caractères.
     *
     * Le texte est ensuite rogné jusqu'à tenir aussi en $_max octets : le cœur
     * refait sa propre troncature en octets (substr) dans setName(), juste
     * avant l'écriture, et ce second coup de ciseaux retomberait exactement
     * dans le piège qu'on vient d'éviter.
     */
    private static function cut($_text, $_max) {
        $text = (string) $_text;
        if ($_max <= 0) {
            return '';
        }
        if (!function_exists('mb_substr') || !function_exists('mb_strlen')) {
            return substr($text, 0, $_max);
        }
        $count = mb_strlen($text, 'UTF-8');
        if ($count > $_max) {
            $count = $_max;
            $text  = mb_substr($text, 0, $count, 'UTF-8');
        }
        while ($count > 0 && strlen($text) > $_max) {
            $count--;
            $text = mb_substr($text, 0, $count, 'UTF-8');
        }
        return $text;
    }

    /* ------------------------------------------------- index d'identité */

    /**
     * Index alias → équipement, construit une fois par requête.
     *
     * Une découverte complète repasse par ici pour chaque appareil du parc : une
     * requête par alias et par appareil ferait des centaines d'allers-retours là
     * où une seule lecture suffit.
     */
    private static function aliasIndex() {
        if (self::$_index !== null) {
            return self::$_index;
        }
        self::$_index = array();
        $all = eqLogic::byType('mqttbe');
        if (is_array($all)) {
            foreach ($all as $eqLogic) {
                self::indexEqLogic($eqLogic);
            }
        }
        return self::$_index;
    }

    private static function indexEqLogic($_eqLogic) {
        if (self::$_index === null) {
            self::$_index = array();
        }
        $keys = array((string) $_eqLogic->getLogicalId(),
                      (string) $_eqLogic->getConfiguration(self::CONF_UID, ''));
        $aliases = $_eqLogic->getConfiguration(self::CONF_ALIASES, array());
        if (is_array($aliases)) {
            $keys = array_merge($keys, $aliases);
        }
        foreach ($keys as $key) {
            $normal = self::normalizeKey($key);
            if ($normal !== '' && !isset(self::$_index[$normal])) {
                self::$_index[$normal] = $_eqLogic;
            }
        }
    }

    /**
     * Oublie ce qui a été mis en cache dans la requête.
     *
     * Utile aux essais et à un appelant qui supprime des équipements entre deux
     * fabrications : l'index garderait sinon des objets disparus.
     */
    public static function resetCache() {
        self::$_capabilities = null;
        self::$_index = null;
        self::$_pluginsUsedBy = null;
    }

    /**
     * Identités possibles d'un modèle, uid en tête.
     *
     * L'ordre compte : le uid est l'identité, les alias ne sont que des chemins
     * par lesquels l'appareil peut aussi se présenter.
     */
    private static function identityKeys($_identity) {
        $identity = $_identity;
        if (is_object($identity)) {
            $identity = self::toArray($identity);
        }
        if (is_string($identity)) {
            $identity = array('uid' => $identity);
        }
        if (!is_array($identity)) {
            return array();
        }
        if (isset($identity['identity']) && is_array($identity['identity'])) {
            $identity = $identity['identity'];
        }
        $keys = array();
        $uid = self::str($identity, 'uid');
        if ($uid !== '') {
            $keys[$uid] = true;
        }
        if (isset($identity['aliases']) && is_array($identity['aliases'])) {
            foreach ($identity['aliases'] as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $keys[$alias] = true;
                }
            }
        }
        return array_keys($keys);
    }

    /* Comparaison insensible à la casse : une adresse MAC s'écrit aussi bien en
     * majuscules qu'en minuscules selon le firmware qui l'annonce, et c'est le
     * même appareil. */
    private static function normalizeKey($_key) {
        return strtolower(trim((string) $_key));
    }

    /* ------------------------------------------------------------- outillage */

    /**
     * Charge les classes du modèle de périphérique.
     *
     * L'autochargeur de Jeedom ne sait résoudre que la classe portant le nom du
     * plugin : ces classes, partagées avec le démon, s'incluent à la main. Le
     * dossier peut ne pas exister — la découverte est un jalon ultérieur, et la
     * fabrique doit fonctionner sur un modèle fourni en JSON.
     */
    public static function loadDiscovery() {
        if (self::$_discoveryLoaded) {
            return;
        }
        self::$_discoveryLoaded = true;
        $dir = __DIR__ . '/../../resources/mqttbed/discovery';
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.php');
        if (!is_array($files)) {
            return;
        }
        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }
    }

    /* ------------------------------------------------- balayage des anonymes */

    /*
     * Silence au bout duquel une balise anonyme est tenue pour morte.
     *
     * Vingt-quatre heures, et c'est le même délai que celui au bout duquel le
     * démon lui-même oublie une balise décodée : au-delà, plus rien ne la
     * rafraîchira, et son équipement resterait figé sur sa dernière valeur pour
     * toujours. Une balise vivante, elle, est collectée en permanence — ne
     * serait-ce que par sa présence, republiée à la minute.
     */
    const SWEEP_SILENCE = 86400;

    /*
     * Préfixe des canaux que le démon CALCULE au lieu de les recevoir.
     *
     * Ils ne prouvent rien sur la vie d'une balise : ils voyagent en messages
     * retenus, et le broker les rejoue tels quels au démarrage du démon. Voir
     * sweepUnidentified().
     */
    const SWEEP_COMPUTED = 'state.';

    /*
     * Suppressions par appel.
     *
     * UNE REQUÊTE HTTP N'EST PAS ÉTERNELLE. Le premier balayage réel a porté
     * sur cent vingt-quatre équipements et quatorze cents commandes, chacune
     * sondée avant d'être touchée : il est passé, en deux minutes environ. Un
     * parc quatre fois plus grand, lui, dépasserait le délai du serveur web, et
     * la requête serait coupée en chemin — les suppressions déjà faites
     * resteraient, mais la page n'apprendrait rien de ce qui vient de
     * disparaître. On rend donc la main après cinquante, en disant qu'il en
     * reste ; c'est la page qui redemande, et chaque lot laisse sa ligne au
     * journal.
     */
    const SWEEP_LOT = 50;

    /**
     * Balaye les balises Bluetooth que personne n'a jamais pu identifier.
     *
     * POURQUOI CE BALAYAGE EXISTE. Une passerelle Bluetooth voit tout ce qui
     * passe, et elle sait lire des FORMATS d'annonce — iBeacon et ses
     * semblables — qu'émettent les téléphones. L'adapter y a vu des appareils
     * reconnus : cent quatre-vingt-dix équipements en trois jours sur une
     * installation réelle, trois par heure, aucun n'ayant jamais reçu la
     * moindre valeur — à la trame suivante, l'adresse avait déjà changé. La
     * découverte ne les fabrique plus (voir l'adapter OpenMQTTGateway), mais
     * ceux qui sont en base y restent, et le plafond de création les compte.
     *
     * CE QU'IL NE TOUCHE JAMAIS, et c'est l'essentiel :
     *
     *   — ce qui n'est pas une balise : Shelly, passerelles, équipements créés
     *     à la main ne sont même pas regardés ;
     *   — ce que la passerelle a su nommer : une marque, et l'équipement reste,
     *     fût-il muet ;
     *   — CE QUI VIT. Une seule commande rafraîchie dans les dernières
     *     vingt-quatre heures, et l'équipement est épargné. C'est le critère
     *     qui décide, et c'est le seul qu'un appareil ne peut pas simuler :
     *     une balise qu'on reçoit encore est une balise qui existe.
     *     LA FRAÎCHEUR SE JUGE SUR CE QUE L'APPAREIL PUBLIE, jamais sur les
     *     trois états que le démon CALCULE (présence, pièce, dernière vue) :
     *     ceux-là sont posés retenus sur le broker, et le broker les rejoue au
     *     démarrage du démon. Sur l'installation réelle, cent vingt-sept
     *     balises mortes depuis trois jours paraissaient ainsi avoir parlé à
     *     la seconde près — toutes à l'heure du dernier démarrage, et leurs
     *     commandes de signal, elles, n'avaient jamais rien reçu.
     *   — ce que vous avez renommé. La fabrique retient le nom qu'elle a écrit ;
     *     s'il a changé, c'est que quelqu'un s'est penché dessus, et personne
     *     ne renomme un équipement dont il ne veut pas ;
     *   — ce que vous avez complété : une commande ajoutée à la main, que la
     *     fabrique n'a pas écrite, vaut décision ;
     *   — ce qui est cité ailleurs : un scénario, une vue, un design, une autre
     *     commande. En cas de doute — une sonde qui ne conclut pas, un plugin
     *     qui répond mal —, l'équipement est CONSERVÉ.
     *
     * CE QUI NE LE RETIENT PAS, ET POURQUOI. « Mesurer quelque chose » a
     * d'abord été un critère, et il a échoué : sur l'installation réelle, la
     * passerelle tirait de ces trames une tension — 10,9 V sur une balise —,
     * et cette valeur de fantaisie protégeait cent cinq équipements morts.
     * Ce qu'une balise anonyme prétend mesurer ne prouve rien ; qu'on la
     * reçoive encore, si.
     *
     * Les relevés d'historique, eux, n'arrêtent pas le balayage : sur ces
     * équipements-là, ils ne sont que la trace du défaut — deux ou trois points
     * de présence, le temps que l'adresse tourne. Ils sont comptés et annoncés
     * AVANT toute suppression ; c'est l'aperçu qui les fait lire, et
     * l'utilisateur qui tranche.
     *
     * LE TRAVAIL EST BORNÉ (voir SWEEP_LOT) : l'aperçu compte tout, la
     * suppression s'arrête après un lot et dit qu'il en reste. L'appelant
     * redemande jusqu'à ce que `remaining` soit faux.
     *
     * @param bool $_apply false : on regarde. true : on supprime.
     */
    public static function sweepUnidentified($_apply = false) {
        $report = array(
            'applied'   => (bool) $_apply,
            'scanned'   => 0,
            'matched'   => 0,
            'removed'   => 0,
            'failed'    => 0,
            'cmd'       => 0,
            'history'   => 0,
            'remaining' => false,
            'devices'   => array(),
            'messages'  => array(),
        );
        $all = eqLogic::byType('mqttbe');
        if (!is_array($all)) {
            return $report;
        }
        $limite = time() - self::SWEEP_SILENCE;
        foreach ($all as $eqLogic) {
            $report['scanned']++;
            $uid = (string) $eqLogic->getConfiguration(self::CONF_UID, '');
            if (strpos($uid, 'ble:') !== 0) {
                continue;
            }
            /* Une marque, et c'est un appareil : la passerelle a su dire ce que
             * c'était. « GENERIC » est au contraire son aveu qu'elle ne le sait
             * pas, et un champ vide n'en dit pas davantage. */
            $marque = strtoupper(trim((string) $eqLogic->getConfiguration(self::CONF_MANUFACTURER, '')));
            if ($marque !== '' && $marque !== 'GENERIC') {
                continue;
            }
            /* Le nom est-il encore celui de la fabrique ? */
            $generated = $eqLogic->getConfiguration(self::CONF_GENERATED, array());
            if (!is_array($generated) || !isset($generated['name'])
                || (string) $generated['name'] !== (string) $eqLogic->getName()) {
                continue;
            }
            $cmds = cmd::byEqLogicId($eqLogic->getId());
            if (!is_array($cmds) || empty($cmds)) {
                continue;
            }
            $vivante = false;
            $ajoutee = false;
            $cite    = false;
            $releves = 0;
            foreach ($cmds as $cmd) {
                $cle = (string) $cmd->getConfiguration(self::CONF_KEY, '');
                /* Une commande que la fabrique n'a pas écrite a été ajoutée à
                 * la main : l'équipement a servi à quelqu'un. */
                if ($cle === '') {
                    $ajoutee = true;
                    break;
                }
                if (strpos($cle, self::SWEEP_COMPUTED) !== 0 && self::sweepFresh($cmd, $limite)) {
                    $vivante = true;
                    break;
                }
                if (self::isReferenced($cmd)) {
                    $cite = true;
                    break;
                }
                if (self::historyProbe($cmd) === true) {
                    $releves++;
                }
            }
            if ($vivante || $ajoutee || $cite) {
                continue;
            }
            $report['matched']++;
            $report['cmd']     += count($cmds);
            $report['history'] += $releves;
            $report['devices'][] = array(
                'id'      => (int) $eqLogic->getId(),
                'name'    => (string) $eqLogic->getName(),
                'uid'     => $uid,
                'cmd'     => count($cmds),
                'history' => $releves,
            );
            if (!$_apply) {
                continue;
            }
            try {
                $eqLogic->remove();
                $report['removed']++;
            } catch (Throwable $e) {
                $report['failed']++;
                $report['messages'][] = $eqLogic->getName() . ' : ' . $e->getMessage();
            }
            /* Le lot est plein : on rend la main plutôt que de se faire couper
             * au milieu d'une suppression, sans trace de ce qui est parti. */
            if ($report['removed'] + $report['failed'] >= self::SWEEP_LOT) {
                $report['remaining'] = true;
                break;
            }
        }
        if ($_apply) {
            /* L'index de la fabrique garde des objets qui n'existent plus : la
             * découverte suivante croirait reconnaître ce qui vient d'être
             * supprimé, et n'écrirait rien. */
            self::resetCache();
            mqttbe::logger('info', sprintf(
                __('Balayage des balises non identifiées : %1$d supprimé(s), %2$d échec(s)%3$s.', __FILE__),
                $report['removed'], $report['failed'],
                $report['remaining'] ? __(", d'autres restent à traiter", __FILE__) : ''));
        }
        return $report;
    }

    /**
     * Cette commande a-t-elle reçu quelque chose depuis la limite ?
     *
     * Les dates de collecte vivent dans le cache du coeur, et on les y lit
     * directement : getCollectDate() exécute la commande quand la date est vide
     * — ce qui, sur deux cents équipements morts, ferait deux mille exécutions
     * pour apprendre que rien n'est arrivé.
     *
     * UNE DATE ILLISIBLE VAUT « VIVANTE ». Un cache indisponible, une date que
     * strtotime() refuse : il vaut cent fois mieux conserver un équipement mort
     * que supprimer un capteur qui parlait.
     */
    private static function sweepFresh($_cmd, $_limite) {
        foreach (array('collectDate', 'valueDate') as $champ) {
            try {
                $date = (string) $_cmd->getCache($champ, '');
            } catch (Throwable $e) {
                return true;
            }
            if (trim($date) === '') {
                continue;
            }
            $quand = strtotime($date);
            if ($quand === false) {
                return true;
            }
            if ($quand >= $_limite) {
                return true;
            }
        }
        return false;
    }

    /**
     * Réduit un modèle ou un canal en tableau.
     *
     * La fabrique travaille sur des tableaux et non sur les accesseurs des
     * classes de découverte : elle est ainsi la même qu'on lui passe l'objet,
     * le JSON décodé du démon ou un modèle écrit à la main dans la page de
     * configuration manuelle.
     */
    private static function toArray($_value) {
        if (is_array($_value)) {
            return $_value;
        }
        if (!is_object($_value)) {
            throw new RuntimeException(__('Modèle de périphérique illisible', __FILE__));
        }
        $data = null;
        if (method_exists($_value, 'toArray')) {
            $data = $_value->toArray();
        } elseif ($_value instanceof JsonSerializable) {
            $data = $_value->jsonSerialize();
        } else {
            $data = json_decode(json_encode($_value), true);
        }
        if (!is_array($data)) {
            throw new RuntimeException(__('Modèle de périphérique illisible', __FILE__));
        }
        if (self::str($data, 'fingerprint') === '' && method_exists($_value, 'fingerprint')) {
            $data['fingerprint'] = (string) $_value->fingerprint();
        }
        return $data;
    }

    private static function str($_array, $_key) {
        if (!is_array($_array) || !isset($_array[$_key]) || is_array($_array[$_key])) {
            return '';
        }
        return trim((string) $_array[$_key]);
    }

    /* Premier sous-tableau portant cette clé, parmi plusieurs tableaux. */
    private static function firstArray($_sources, $_key) {
        foreach ($_sources as $source) {
            if (is_array($source) && isset($source[$_key]) && is_array($source[$_key])) {
                return $source[$_key];
            }
        }
        return array();
    }

    /* Première valeur non vide parmi plusieurs tableaux : le modèle peut ranger
     * une transformation dans « transform » comme dans « value ». */
    private static function pick($_sources, $_key) {
        foreach ($_sources as $source) {
            if (is_array($source) && isset($source[$_key]) && !is_array($source[$_key])
                && (string) $source[$_key] !== '') {
                return $source[$_key];
            }
        }
        return null;
    }
}
