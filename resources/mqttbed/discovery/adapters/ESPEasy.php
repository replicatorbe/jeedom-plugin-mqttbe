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
 * Découverte des appareils ESPEasy (firmware communautaire pour ESP8266/ESP32).
 *
 * ESPEasy n'a pas de topic d'annonce comme Shelly. Il n'y a RIEN qui dise
 * « bonjour, je suis un ESPEasy » : chaque appareil publie ses mesures sur une
 * racine composée de son nom système, et c'est tout. La seule chose fixe,
 * d'une installation à l'autre, est le testament :
 *
 *   <sysname>/status/LWT    → "Connected" / "Connection Lost" / "disconnect"
 *
 * Ce LWT est RETENU par le broker. Au démarrage du démon, dès l'abonnement, le
 * broker rejoue le dernier état de tous les ESPEasy du parc en une rafale. Il
 * n'y a donc pas besoin de « découverte active » : un simple abonnement passif
 * suffit, et un appareil connecté depuis des mois répond dans la seconde.
 *
 * CE QUE PUBLIE UN ESPEASY, EN DÉTAIL
 *
 *   <sysname>/status/LWT       disponibilité (testament MQTT, retenu).
 *   <sysname>/<task>/<value>   une valeur par topic, payload = nombre brut.
 *
 * Il n'y a pas de « capacités déclarées » : la seule façon de connaître les
 * capteurs est de les voir publier. L'adapter s'abonne donc à `<sysname>/+/+`
 * dès qu'il voit le LWT, laisse les messages retenus défiler, et compose le
 * modèle à partir des valeurs vues.
 *
 * CE QU'IL N'Y A PAS, VOLONTAIREMENT
 *
 *   AUCUN APPEL HTTP À L'APPAREIL. ESPEasy expose /json et /controllers, qui
 *   donnent le nom convivial et la liste précise des Tasks. Mais la règle n°1
 *   d'un adapter est de ne jamais bloquer la boucle du démon — et le JSON est
 *   déjà sur MQTT, en messages retenus. Pas besoin d'HTTP.
 *
 *   AUCUNE DEMANDE ACTIVE. Rien à publier sur le broker pour faire parler le
 *   parc : les retains font le travail à l'abonnement. onTick() ne provoque
 *   que l'émission des modèles, jamais un paquet sortant.
 *
 *   AUCUNE DÉPENDANCE À HOME ASSISTANT DISCOVERY. ESPEasy peut être configuré
 *   pour publier sous le préfixe `homeassistant/` (c'est le cas chez cet
 *   utilisateur, en plus du topic natif), avec des payloads Domoticz qui
 *   portent `%valname%` non substitué quand le controller est mal réglé. Ces
 *   topics sont IGNORÉS : seul le natif court est écouté.
 *
 * CAPACITÉS RECONNUES
 *
 * Mapping value → capacité, insensible à la casse. Les autres values (notées
 * `U` pour la tension ADC brute du MH-Z19, par exemple) sont ignorées plutôt
 * que de peupler un équipement de commandes dont personne ne saura quoi faire.
 * Un ESPEasy qui ne publie aucune value reconnue n'a qu'un canal de
 * disponibilité — c'est encore un équipement valide, et le modèle part en
 * `probable` pour que l'utilisateur tranche dans la file d'adoption.
 * ========================================================================== */
class MqttbeESPEasy implements MqttbeAdapter {

    const ID       = 'espeasy';
    /* 100 = natif. Un appareil vu à la fois ici et par un adapter générique
     * (le futur HA Discovery) doit être décrit par celui qui connaît ses
     * conventions de nommage, donc par celui-ci. */
    const PRIORITY = 100;

    /* Le testament : point d'entrée unique de la découverte. ESPEasy est le
     * seul firmware courant à utiliser `/status/LWT` ; Shelly publie `/online`
     * ou `/status`, OMG publie `/LWT` sans `/status/`. Pas de collision. */
    const TOPIC_LWT = '+/status/LWT';

    /* Les sysnames qui ressemblent à ces préfixes réservés sont écartés : ils
     * désignent d'autres plugins ou namespaces, pas des ESPEasy. En minuscules,
     * puisque la comparaison se fait sur strtolower(sysname) — « $SYS » ici en
     * capitales ne filtrerait jamais, le broker ne nous le livre pas non plus
     * sur `+/status/LWT`, mais la liste doit dire ce qu'elle fait. */
    const PREFIXES_RESERVES = array(
        'homeassistant', 'jeedom', 'shellies', 'shelly', 'bt', 'omg',
        '$sys', 'mqttbe', 'tasmota', 'zigbee2mqtt', 'device_tracker', 'home',
    );

    /* Un sysname ESPEasy, tel qu'il peut composer sans danger la racine des
     * topics. Le même format que Shelly, à bon droit : ces caractères traversent
     * MQTT sans jamais produire un filtre ambigu. */
    const SYSNAME_VALIDE = '/^[A-Za-z0-9._-]{1,64}$/';

    /* Un segment de topic (task, valname) qui compose une clé de canal. Plus
     * court que le sysname : la clé est `task.<task>.<valname>`, soit au plus
     * 5 + MAX_SEGMENT + 1 + MAX_SEGMENT = 86 caractères pour MAX_SEGMENT=40.
     * MqttbeChannel::MAX_KEY vaut 127 — on reste à l'aise, et un ESPEasy avec
     * des noms plus longs est écarté plutôt que de voir son modèle refusé en
     * bloc par validate() pour une seule clé trop longue. */
    const SEGMENT_VALIDE = '/^[A-Za-z0-9._-]{1,40}$/';

    /* Délai, depuis le premier LWT reçu, au bout duquel on émet le modèle
     * courant. Doit être ASSEZ LONG pour laisser arriver la rafale initiale
     * des topics retenus (sysname/+/+), qui parviennent en moins d'une
     * seconde, et PAS calculé « depuis le dernier topic » — un ESPEasy publie
     * ses valeurs toutes les 5 à 60 secondes, et une stabilité « dernier
     * topic + N secondes » n'est alors jamais atteinte. 10 s suffisent pour
     * la rafale, et l'empreinte du modèle évite les ré-émissions tant que
     * rien n'a changé. */
    const DELAI_STABILITE = 10;

    /* Table des valeurs ESPEasy reconnues. La clé est le `valname` en
     * minuscules, tel qu'il apparaît dans le topic. */
    private static $CAPACITES = array(
        'ppm'           => array('capability' => 'sensor.co2',                  'unit' => 'ppm', 'name' => 'CO2'),
        'co2'           => array('capability' => 'sensor.co2',                  'unit' => 'ppm', 'name' => 'CO2'),
        'temperature'   => array('capability' => 'sensor.temperature',          'unit' => '°C',  'name' => 'Température'),
        'temp'          => array('capability' => 'sensor.temperature',          'unit' => '°C',  'name' => 'Température'),
        'temperatureco' => array('capability' => 'sensor.temperature_internal', 'unit' => '°C',  'name' => 'Température capteur'),
        'humidity'      => array('capability' => 'sensor.humidity',             'unit' => '%',   'name' => 'Humidité'),
        'hum'           => array('capability' => 'sensor.humidity',             'unit' => '%',   'name' => 'Humidité'),
        'pressure'      => array('capability' => 'sensor.pressure',             'unit' => 'hPa', 'name' => 'Pression'),
        'luminosity'    => array('capability' => 'sensor.luminosity',           'unit' => 'lx',  'name' => 'Luminosité'),
        'lux'           => array('capability' => 'sensor.luminosity',           'unit' => 'lx',  'name' => 'Luminosité'),
    );

    /* Payloads LWT, dans leur forme ESPEasy. "Connected" / "Connection Lost"
     * sont les textes standard ; "disconnect" est publié avant un reboot
     * logiciel, et doit compter comme hors-ligne au même titre que le
     * testament. Le bloc `availability` du modèle ne porte qu'un seul
     * payload_off : on choisit "Connection Lost" (le plus durable), et une
     * table de transformation gère les trois formes pour le canal visible. */
    const PAYLOAD_ON  = 'Connected';
    const PAYLOAD_OFF = 'Connection Lost';

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
     * Un seul filtre à l'activation : les LWT. L'abonnement aux valeurs d'un
     * appareil se fait DYNAMIQUEMENT, par $ctx->subscribe(), au moment où son
     * LWT arrive — un unique `#` pour tout prendre serait un piège, voir
     * Adapter.php.
     */
    public function subscriptions() {
        return array(self::TOPIC_LWT);
    }

    public function onMessage($_topic, $_payload, $_retained, $_ctx) {
        $parties = explode('/', (string) $_topic);
        $n = count($parties);
        if ($n < 2) {
            return;
        }

        /* Cas 1 — le testament : <sysname>/status/LWT */
        if ($n === 3 && $parties[1] === 'status' && $parties[2] === 'LWT') {
            $this->voitLwt($_ctx, $parties[0], (string) $_payload, (bool) $_retained);
            return;
        }

        /* Cas 2 — une valeur : <sysname>/<task>/<valname>, et seulement pour
         * un sysname déjà vu via son LWT. Le filtrage par sysname connu évite
         * qu'un topic foreign (OMG `BTtoMQTT/<mac>`) ne crée un dossier. */
        if ($n === 3) {
            $this->voitValeur($_ctx, $parties[0], $parties[1], $parties[2], (string) $_payload);
            return;
        }
    }

    /*
     * onTick n'émet jamais de paquet sortant pour ESPEasy : rien à demander, les
     * retains font tout. Il sert à décider quand les canaux d'un dossier sont
     * assez stables pour que le modèle parte vers Jeedom.
     */
    public function onTick($_ctx) {
        $maintenant = $this->maintenant($_ctx);

        /* « Relancer la découverte » pour ESPEasy ne provoque aucun message
         * sur le broker — il n'y a rien à demander. On vide seulement les
         * empreintes émises pour que la prochaine émission reparte, comme chez
         * Shelly Gen1 (voir onTick de ShellyGen1 : un équipement supprimé par
         * erreur dans Jeedom doit revenir au bouton de relance). */
        if ($_ctx->rescan()) {
            $this->oublieEmissions($_ctx);
        }

        foreach ($this->inventaire($_ctx) as $sysname) {
            $dossier = $this->dossier($_ctx, $sysname);
            if (empty($dossier['vu_lwt'])) {
                /* Un sysname présent dans l'index mais sans LWT reçu : état
                 * incohérent, dossier ignoré jusqu'à ce qu'un LWT arrive. */
                continue;
            }
            /* Abonnement aux valeurs raté la première fois (plafond, liaison
             * absente) : on retente ici, autant de tours qu'il faut. Sans ce
             * retry, un `vu_lwt=true` persisté bloquerait l'abonnement pour
             * la vie du démon et le dossier resterait vide à jamais. */
            if (empty($dossier['abonne'])) {
                if ($this->abonneValeurs($_ctx, $sysname)) {
                    $dossier['abonne'] = true;
                    $this->range($_ctx, $dossier);
                }
            }
            $detecte = isset($dossier['detecte_at']) ? (float) $dossier['detecte_at'] : 0;
            if (($maintenant - $detecte) < self::DELAI_STABILITE) {
                /* La rafale retenue arrive en moins d'une seconde, mais on
                 * laisse DELAI_STABILITE à l'horloge le temps que les
                 * abonnements soient effectivement établis côté broker et que
                 * tous les canaux attendus aient été vus. */
                continue;
            }
            $this->emet($_ctx, $dossier);
        }
    }

    /* S'abonner à `<sysname>/+/+` ; vrai si l'abonnement est accepté. Posé
     * ailleurs qu'en inline pour pouvoir rejouer la demande dans onTick. */
    private function abonneValeurs($_ctx, $_sysname) {
        $filtre = $_sysname . '/+/+';
        if ($_ctx->subscribe($filtre) !== true) {
            $_ctx->log('warning', 'ESPEasy : abonnement « ' . $filtre
                . ' » refusé — nouvelle tentative au prochain tour.');
            return false;
        }
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Réception                                                             */
    /* --------------------------------------------------------------------- */

    private function voitLwt($_ctx, $_sysname, $_payload, $_retained) {
        if (!$this->sysnameValide($_ctx, $_sysname, 'LWT')) {
            return;
        }

        $dossier = $this->dossier($_ctx, $_sysname);
        $premier = empty($dossier['vu_lwt']);
        $dossier['sysname'] = $_sysname;
        $dossier['vu_lwt']  = true;
        if (!isset($dossier['detecte_at'])) {
            $dossier['detecte_at'] = $this->maintenant($_ctx);
        }

        if ($premier) {
            /* Premier LWT : s'abonner aux valeurs de CET appareil, et seulement
             * de celui-là. Un `+/+/+` global serait tentant mais rapporterait
             * la totalité du trafic du broker à chaque message. L'échec est
             * mémorisé — onTick retentera jusqu'à succès. */
            $dossier['abonne'] = $this->abonneValeurs($_ctx, $_sysname);
            if ($dossier['abonne']) {
                $_ctx->log('info', 'ESPEasy : ' . $_sysname . ' détecté (LWT), abonnement aux valeurs posé.');
            }
        }
        $this->range($_ctx, $dossier);
    }

    private function voitValeur($_ctx, $_sysname, $_task, $_valname, $_payload) {
        if ($_task === 'status') {
            /* Le LWT a sa propre branche ; d'autres topics `<sysname>/status/*`
             * n'existent pas en ESPEasy standard, mais s'ils arrivaient un jour
             * on les laisse à qui saura les lire. */
            return;
        }
        if (!$this->sysnameValide($_ctx, $_sysname, 'valeur')) {
            return;
        }
        /* Un sysname qu'on n'a pas vu par son LWT n'est pas un candidat : le
         * LWT est la porte d'entrée, et sans lui on prendrait pour un ESPEasy
         * tout ce qui publie en `+/+/+`. */
        $dossier = $this->dossier($_ctx, $_sysname);
        if (empty($dossier['vu_lwt'])) {
            return;
        }

        /* Les templates ESPEasy non substitués (`%valname%`, `%tskname%`) sont
         * la signature d'un controller mal configuré. On refuse plutôt que de
         * créer une commande au nom imprononçable. */
        if (strpos($_task, '%') !== false || strpos($_valname, '%') !== false) {
            return;
        }
        /* Un segment plus court que le sysname (voir SEGMENT_VALIDE) : la clé
         * composée doit tenir sous MqttbeChannel::MAX_KEY, et un task ou un
         * valname démesuré rendrait tout le modèle refusé par validate(). */
        if (!preg_match(self::SEGMENT_VALIDE, $_task) || !preg_match(self::SEGMENT_VALIDE, $_valname)) {
            return;
        }

        $cle = 'task.' . strtolower($_task) . '.' . strtolower($_valname);
        if (!isset($dossier['canaux']) || !is_array($dossier['canaux'])) {
            $dossier['canaux'] = array();
        }
        /* Mémoriser PAR APPAREIL, pas par topic : dix capteurs à vingt values
         * tiennent dans la mémoire plafonnée du moteur ; un dossier par topic
         * y buterait. Le dossier porte la liste de ses canaux. */
        if (!isset($dossier['canaux'][$cle])) {
            $dossier['canaux'][$cle] = array(
                'key'     => $cle,
                'valname' => $_valname,
                'topic'   => $_sysname . '/' . $_task . '/' . $_valname,
            );
            /* On ne range que lorsque QUELQUE CHOSE A CHANGÉ : les valeurs
             * sont republiées toutes les quelques secondes, et toucher à la
             * mémoire du moteur à chaque message serait un gaspillage sans
             * utilité — le canal est déjà là. L'empreinte reste la même, le
             * modèle émis non plus. */
            $this->range($_ctx, $dossier);
        }
    }

    private function sysnameValide($_ctx, $_sysname, $_quoi) {
        if ($_sysname === '' || !preg_match(self::SYSNAME_VALIDE, $_sysname)) {
            $_ctx->log('debug', 'ESPEasy : ' . $_quoi . ' sous un sysname refusé « '
                . $this->citation($_sysname) . ' » — ignoré.');
            return false;
        }
        if (in_array(strtolower($_sysname), self::PREFIXES_RESERVES, true)) {
            return false;
        }
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Émission                                                              */
    /* --------------------------------------------------------------------- */

    private function emet($_ctx, $_dossier) {
        $modele = $this->construit($_dossier);
        if ($modele === null) {
            return false;
        }

        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $_ctx->log('warning', 'ESPEasy : modèle refusé pour « ' . $modele->uid() . ' » — '
                . implode(' ', $fautes));
            return false;
        }

        /* La confidence suit directement le nombre de canaux reconnus (voir
         * construit()) : un passage probable→certain ajoute au moins un canal,
         * donc change l'empreinte principale. Pas besoin de la suivre à part
         * comme chez Shelly Gen1, où confidence peut varier sans canal ajouté. */
        $empreinte = $modele->fingerprint() . '|' . $modele->volatileFingerprint();
        $emis = isset($_dossier['emis']) && is_array($_dossier['emis']) ? $_dossier['emis'] : array();
        if (isset($emis['fingerprint']) && $emis['fingerprint'] === $empreinte) {
            return false;
        }

        $_ctx->emit($modele);
        $_dossier['emis'] = array('fingerprint' => $empreinte);
        $this->range($_ctx, $_dossier);
        $_ctx->log('info', 'ESPEasy : ' . $modele->name() . ' (' . $modele->uid() . ') — '
            . $modele->countChannels() . ' canaux, confiance ' . $modele->confidence() . '.');
        return true;
    }

    private function oublieEmissions($_ctx) {
        foreach ($this->inventaire($_ctx) as $sysname) {
            $dossier = $this->dossier($_ctx, $sysname);
            if (!isset($dossier['emis'])) {
                continue;
            }
            unset($dossier['emis']);
            $this->range($_ctx, $dossier);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Construction du modèle                                                */
    /* --------------------------------------------------------------------- */

    private function construit($_dossier) {
        $sysname = isset($_dossier['sysname']) ? (string) $_dossier['sysname'] : '';
        if ($sysname === '') {
            return null;
        }

        $canaux = isset($_dossier['canaux']) && is_array($_dossier['canaux']) ? $_dossier['canaux'] : array();
        ksort($canaux, SORT_STRING);

        /* Un seul passage sur les canaux : on prépare les specs à ajouter, et
         * on compte les reconnus pour en déduire la confidence du modèle. */
        $specs    = array();
        $reconnus = 0;
        foreach ($canaux as $c) {
            $capacite = $this->capaciteDe($c['valname']);
            if ($capacite === null) {
                continue;
            }
            $specs[] = array(
                'key'        => $c['key'],
                'capability' => $capacite['capability'],
                'name'       => $capacite['name'],
                'unit'       => $capacite['unit'],
                'source'     => array('topic' => $c['topic']),
            );
            $reconnus++;
        }

        /* `certain` dès qu'au moins une value est reconnue : l'appareil est
         * créé automatiquement avec ses capteurs. `guess` sinon : le modèle
         * part en file d'adoption, et l'utilisateur tranche — pas de création
         * silencieuse d'un équipement sans aucune commande utile. Pas de
         * `probable`, qui créerait quand même l'équipement (voir
         * OpenMqttGateway sur la différence probable/guess). */
        $confiance = ($reconnus > 0) ? 'certain' : 'guess';

        $modele = new MqttbeDeviceModel(array(
            'identity' => array(
                'adapter' => self::ID,
                /* L'uid vient du sysname : ESPEasy ne publie pas sa MAC sur
                 * MQTT, et c'est ce même sysname qui compose les topics. Deux
                 * ESPEasy portant le même sysname seraient inséparables sur le
                 * broker — l'unicité est de la responsabilité de l'utilisateur
                 * côté firmware, pas du plugin. */
                'uid'     => 'espeasy:' . strtolower($sysname),
                'aliases' => array('topic:' . $sysname),
                'confidence' => $confiance,
            ),
            'meta' => array(
                /* Le sysname tient à la fois lieu de nom technique et de nom
                 * convivial : ESPEasy les confond, et le second ne vit nulle
                 * part sur MQTT. On ne provoque pas de requête HTTP pour le
                 * récupérer (règle n°1). L'utilisateur peut renommer après
                 * adoption dans Jeedom. */
                'name'         => 'ESPEasy ' . $sysname,
                'device_name'  => $sysname,
                'manufacturer' => 'ESPEasy',
            ),
            'availability' => array(
                'topic'       => $sysname . '/status/LWT',
                'payload_on'  => self::PAYLOAD_ON,
                'payload_off' => self::PAYLOAD_OFF,
            ),
        ));

        /* Canal « Connecté », visible sur le dashboard. Le bloc availability
         * ci-dessus pilote l'icône de disponibilité mais ne crée pas de
         * commande ; c'est ce canal qui, lui, est lisible comme un capteur
         * binaire. Il porte la table qui absorbe les trois formes possibles
         * (`Connected`, `Connection Lost`, `disconnect`). */
        $modele->addChannel(new MqttbeChannel(array(
            'key'        => 'online',
            'capability' => 'connectivity.online',
            'name'       => 'Connecté',
            'source'     => array('topic' => $sysname . '/status/LWT'),
            'value'      => array('transform' => array('map' => array(
                'Connected'        => '1',
                'Connection Lost'  => '0',
                'disconnect'       => '0',
            ))),
        )));
        foreach ($specs as $spec) {
            $modele->addChannel(new MqttbeChannel($spec));
        }

        return $modele;
    }

    private function capaciteDe($_valname) {
        $cle = strtolower((string) $_valname);
        return isset(self::$CAPACITES[$cle]) ? self::$CAPACITES[$cle] : null;
    }

    /* --------------------------------------------------------------------- */
    /* Mémoire                                                               */
    /* --------------------------------------------------------------------- */

    public function inventaire($_ctx) {
        $inventaire = $_ctx->recall(self::ID . ':index');
        return is_array($inventaire) ? array_values($inventaire) : array();
    }

    private function dossier($_ctx, $_sysname) {
        $dossier = $this->lit($_ctx, 'dev:' . $_sysname);
        if (!isset($dossier['sysname'])) {
            $dossier['sysname'] = (string) $_sysname;
        }
        return $dossier;
    }

    private function range($_ctx, $_dossier) {
        $sysname = isset($_dossier['sysname']) ? (string) $_dossier['sysname'] : '';
        if ($sysname === '') {
            return;
        }
        $this->ecrit($_ctx, 'dev:' . $sysname, $_dossier);
        $inventaire = $this->inventaire($_ctx);
        if (!in_array($sysname, $inventaire, true)) {
            $inventaire[] = $sysname;
            $this->ecrit($_ctx, 'index', $inventaire);
        }
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

    private function maintenant($_ctx) {
        $maintenant = $_ctx->now();
        return is_numeric($maintenant) ? (float) $maintenant : 0;
    }

    /* Un sysname fautif ne doit pas remplir les journaux avec sa charge utile
     * si c'est un ESP mal configuré qui publie des topics bizarres : on ne
     * recopie dans le journal qu'un extrait court et échappé. */
    private function citation($_valeur) {
        $texte = (string) $_valeur;
        if (function_exists('mb_strlen') && mb_strlen($texte, 'UTF-8') > 40) {
            $texte = mb_substr($texte, 0, 40, 'UTF-8') . '…';
        } elseif (strlen($texte) > 40) {
            $texte = substr($texte, 0, 40) . '…';
        }
        return addcslashes($texte, "\x00..\x1F\"");
    }
}
