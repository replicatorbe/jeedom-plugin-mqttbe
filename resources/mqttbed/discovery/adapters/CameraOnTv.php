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
 * Découverte de CameraOnTv, l'application qui affiche les caméras sur une TV.
 *
 * L'application tourne sur la télévision (Android TV) et se pilote par MQTT
 * sous une racine choisie dans ses réglages, `cameratv` par défaut :
 *
 *   <racine>/online       "true" / "false", retenu (testament de l'appli).
 *   <racine>/state        JSON retenu : l'écran courant ET la liste des
 *                         caméras de l'enregistreur.
 *   <racine>/cmd/show     {"camera":"garage"[,"duration":30]}, ou le nom nu.
 *   <racine>/cmd/grid     charge utile vide : la mosaïque.
 *   <racine>/cmd/exit     charge utile vide : rendre l'écran à la TV.
 *   <racine>/cmd/ptz      {"camera":5,"action":"preset","value":1}
 *
 * Le `state` ressemble à ceci :
 *
 *   {"screen":"grid","camera":null,"cameraName":null,"ptzMode":false,
 *    "visible":false,"cameras":[{"channel":1,"name":"NORD","ptz":true}, …]}
 *
 * C'est lui la signature : aucun autre appareil du parc ne publie, sur un
 * topic à deux niveaux finissant par `/state`, un objet qui porte à la fois
 * `screen` et un tableau `cameras` de {channel, name}. La reconnaissance se
 * fait donc sur la FORME du JSON, et pas sur le nom de la racine : une
 * deuxième TV réglée sur `cameratv-chambre` est découverte de la même façon.
 *
 * Et c'est lui qui fait la richesse du modèle : la liste des caméras y est,
 * avec leur capacité PTZ. Un bouton « Afficher <caméra> » par caméra, un
 * réglage de preset par caméra motorisée, sans que l'utilisateur ait à
 * recopier un seul nom.
 *
 * CE QUI N'ENTRE PAS DANS L'EMPREINTE
 *
 * L'écran courant change à chaque appui sur la télécommande, et le `state`
 * est republié à chaque fois. Seule la liste des caméras décrit l'appareil :
 * le reste est de la valeur, lue par le routage comme n'importe quel capteur.
 * Sans cette séparation, chaque changement de caméra réécrirait l'équipement.
 * ========================================================================== */
class MqttbeCameraOnTv implements MqttbeAdapter {

    const ID       = 'cameraontv';
    /* Protocole propre à l'application, donc « natif ». */
    const PRIORITY = 100;

    /* Deux niveaux exactement : `<racine>/state`. Bien plus étroit qu'un
     * `+/#`, et les firmwares courants publient leur état plus profond
     * (`shellies/<id>/…`, `stat/<id>/STATE`, `zigbee2mqtt/<nom>`). */
    const TOPIC_STATE = '+/state';

    /* Même format que les sysnames ESPEasy : une racine qui compose des
     * topics de publication sans jamais y glisser de joker. */
    const RACINE_VALIDE = '/^[A-Za-z0-9._-]{1,64}$/';

    /* Au-delà, ce n'est plus un enregistreur de maison mais une charge utile
     * aberrante : on n'en fait pas cent boutons. */
    const CAMERAS_MAX = 64;

    /* Un nom de caméra devient un nom de commande (varchar 127 avec le
     * préfixe « Afficher ») et une valeur de charge utile. */
    const NOM_MAX = 60;

    /* Les presets d'une caméra PTZ : l'application ne dit pas combien
     * l'enregistreur en connaît. 1 à 10 couvre l'usage courant ; les bornes
     * se retouchent sur la commande, et un modèle qui se tait n'y touche plus
     * (voir mqttbeFactory::applyPlumbing). */
    const PRESET_MIN = 1;
    const PRESET_MAX = 10;

    /* --------------------------------------------------------------------- */
    /* Interface MqttbeAdapter                                               */
    /* --------------------------------------------------------------------- */

    public function id() {
        return self::ID;
    }

    public function priority() {
        return self::PRIORITY;
    }

    public function subscriptions() {
        return array(self::TOPIC_STATE);
    }

    public function onMessage($_topic, $_payload, $_retained, $_ctx) {
        $parties = explode('/', (string) $_topic);
        if (count($parties) !== 2 || $parties[1] !== 'state') {
            return;
        }
        $racine = $parties[0];
        if (!preg_match(self::RACINE_VALIDE, $racine)) {
            return;
        }
        $etat = json_decode((string) $_payload, true);
        if (!is_array($etat) || !array_key_exists('screen', $etat)
            || !isset($etat['cameras']) || !is_array($etat['cameras'])) {
            /* Le `state` d'un autre appareil, ou une charge utile qui n'est pas
             * du JSON : ce n'est pas une TV, rien à dire. */
            return;
        }

        $cameras = $this->cameras($etat['cameras']);
        $dossier = $this->dossier($_ctx, $racine);
        if (!isset($dossier['cameras']) || $dossier['cameras'] !== $cameras) {
            if (!isset($dossier['cameras'])) {
                $_ctx->log('info', 'CameraOnTv : TV détectée sous « ' . $racine . ' » ('
                    . count($cameras) . ' caméras).');
            }
            $dossier['cameras'] = $cameras;
            $this->range($_ctx, $dossier);
        }
        $this->emet($_ctx, $dossier);
    }

    /*
     * Rien à demander à l'application : son `state` est retenu, le broker le
     * rejoue à l'abonnement. Une relance de la découverte ne fait qu'oublier
     * les empreintes émises, pour qu'un équipement supprimé par erreur dans
     * Jeedom revienne au bouton — avec la liste de caméras déjà en mémoire.
     */
    public function onTick($_ctx) {
        if (!$_ctx->rescan()) {
            return;
        }
        foreach ($this->inventaire($_ctx) as $racine) {
            $dossier = $this->dossier($_ctx, $racine);
            unset($dossier['emis']);
            $this->range($_ctx, $dossier);
            $this->emet($_ctx, $dossier);
        }
    }

    /* --------------------------------------------------------------------- */
    /* Lecture de la liste des caméras                                       */
    /* --------------------------------------------------------------------- */

    /*
     * La liste normalisée, triée par canal : l'ordre de publication de
     * l'application ne doit pas changer l'empreinte. Une entrée sans canal
     * entier ou sans nom est écartée, un canal en double aussi (le premier
     * l'emporte) : la clé de commande en dépend.
     */
    private function cameras($_liste) {
        $cameras = array();
        foreach ($_liste as $camera) {
            if (count($cameras) >= self::CAMERAS_MAX) {
                break;
            }
            if (!is_array($camera) || !isset($camera['channel']) || !isset($camera['name'])) {
                continue;
            }
            if (!is_int($camera['channel']) && !(is_string($camera['channel']) && ctype_digit($camera['channel']))) {
                continue;
            }
            $canal = (int) $camera['channel'];
            $nom   = trim((string) $camera['name']);
            if ($canal < 0 || $nom === '' || isset($cameras[$canal])) {
                continue;
            }
            if (MqttbeChannel::length($nom) > self::NOM_MAX) {
                $nom = mb_substr($nom, 0, self::NOM_MAX, 'UTF-8');
            }
            $cameras[$canal] = array(
                'channel' => $canal,
                'name'    => $nom,
                'ptz'     => !empty($camera['ptz']),
            );
        }
        ksort($cameras, SORT_NUMERIC);
        return array_values($cameras);
    }

    /* --------------------------------------------------------------------- */
    /* Émission                                                              */
    /* --------------------------------------------------------------------- */

    private function emet($_ctx, $_dossier) {
        $modele = $this->construit($_dossier);
        $fautes = $modele->validate();
        if (!empty($fautes)) {
            $_ctx->log('warning', 'CameraOnTv : modèle refusé pour « ' . $modele->uid() . ' » — '
                . implode(' ', $fautes));
            return false;
        }

        $empreinte = $modele->fingerprint() . '|' . $modele->volatileFingerprint();
        if (isset($_dossier['emis']) && $_dossier['emis'] === $empreinte) {
            return false;
        }
        $_ctx->emit($modele);
        $_dossier['emis'] = $empreinte;
        $this->range($_ctx, $_dossier);
        $_ctx->log('info', 'CameraOnTv : ' . $modele->name() . ' (' . $modele->uid() . ') — '
            . $modele->countChannels() . ' canaux.');
        return true;
    }

    /* --------------------------------------------------------------------- */
    /* Construction du modèle                                                */
    /* --------------------------------------------------------------------- */

    private function construit($_dossier) {
        $racine  = (string) $_dossier['racine'];
        $cameras = isset($_dossier['cameras']) && is_array($_dossier['cameras']) ? $_dossier['cameras'] : array();
        $state   = $racine . '/state';
        $show    = $racine . '/cmd/show';

        $modele = new MqttbeDeviceModel(array(
            'identity' => array(
                'adapter'    => self::ID,
                /* La racine est la seule identité que l'application publie, et
                 * c'est elle qui compose tous les topics. */
                'uid'        => self::ID . ':' . strtolower($racine),
                'aliases'    => array('topic:' . $racine),
                'confidence' => 'certain',
            ),
            'meta' => array(
                /* Pas de `device_name` : il désigne le nom donné par
                 * l'utilisateur, que la fabrique accole au nom technique.
                 * La racine n'en est pas un, et « Caméras TV — cameratv »
                 * ne dirait rien de plus. */
                'name'         => $racine === 'cameratv' ? 'Caméras TV' : 'Caméras TV ' . $racine,
                'manufacturer' => 'CameraOnTv',
                'model'        => 'Android TV',
            ),
            'availability' => array(
                'topic'       => $racine . '/online',
                'payload_on'  => 'true',
                'payload_off' => 'false',
            ),
        ));

        /* --- Informations (TV → Jeedom) ----------------------------------- */

        $modele->addChannel(new MqttbeChannel(array(
            'key'        => 'online',
            'capability' => 'connectivity.online',
            'name'       => 'Connecté',
            'source'     => array('topic' => $racine . '/online'),
            'value'      => array('transform' => array('map' => array(
                'true' => '1', 'false' => '0', 'online' => '1', 'offline' => '0',
            ))),
        )));
        $infos = array(
            /* clé            capacité          nom                 champ */
            array('state.visible',     'generic.binary',  'Affiché',           'visible'),
            array('state.screen',      'generic.value',   'Écran',             'screen'),
            array('state.camera',      'generic.numeric', 'Canal affiché',     'camera'),
            array('state.camera_name', 'generic.value',   'Caméra affichée',   'cameraName'),
            array('state.ptz_mode',    'generic.binary',  'Mode PTZ',          'ptzMode'),
        );
        foreach ($infos as $info) {
            $modele->addChannel(new MqttbeChannel(array(
                'key'        => $info[0],
                'capability' => $info[1],
                'name'       => $info[2],
                'source'     => array('topic' => $state, 'selector' => array('type' => 'json', 'path' => $info[3])),
            )));
        }

        /* --- Actions (Jeedom → TV) ---------------------------------------- */

        $modele->addChannel(new MqttbeChannel(array(
            'key'        => 'cmd.grid',
            'capability' => 'generic.action',
            'name'       => 'Grille',
            'sink'       => array('topic' => $racine . '/cmd/grid', 'payload' => ''),
        )));
        $modele->addChannel(new MqttbeChannel(array(
            'key'        => 'cmd.exit',
            'capability' => 'generic.action',
            'name'       => 'Quitter',
            'sink'       => array('topic' => $racine . '/cmd/exit', 'payload' => ''),
        )));
        /* La commande générique des scénarios : le message est le nom de la
         * caméra (« INTERCOM », « garage ») ou son canal (« 3 »), que
         * l'application accepte sous cette forme courte. */
        $modele->addChannel(new MqttbeChannel(array(
            'key'        => 'cmd.show',
            'capability' => 'generic.text',
            'name'       => 'Afficher caméra',
            'sink'       => array('topic' => $show, 'payload' => '#message#'),
            'links'      => array('state' => 'state.camera_name'),
        )));

        foreach ($cameras as $camera) {
            $canal = (int) $camera['channel'];
            $modele->addChannel(new MqttbeChannel(array(
                /* La clé suit le CANAL, pas le nom : une caméra renommée dans
                 * l'enregistreur garde sa commande, donc ses scénarios. */
                'key'        => 'cmd.show.' . $canal,
                'capability' => 'generic.action',
                'name'       => 'Afficher ' . $camera['name'],
                'sink'       => array('topic' => $show, 'payload' => json_encode(
                    array('camera' => $camera['name']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            )));
            if (empty($camera['ptz'])) {
                continue;
            }
            $modele->addChannel(new MqttbeChannel(array(
                'key'        => 'cmd.ptz.' . $canal . '.preset',
                'capability' => 'generic.slider',
                'name'       => 'Preset ' . $camera['name'],
                'sink'       => array(
                    'topic'   => $racine . '/cmd/ptz',
                    'payload' => '{"camera":' . $canal . ',"action":"preset","value":#slider#}',
                ),
                'value'      => array('slider' => array(
                    'min' => self::PRESET_MIN, 'max' => self::PRESET_MAX,
                )),
            )));
        }

        return $modele;
    }

    /* --------------------------------------------------------------------- */
    /* Mémoire : un dossier par TV, dont le nombre est fini                  */
    /* --------------------------------------------------------------------- */

    public function inventaire($_ctx) {
        $inventaire = $_ctx->recall(self::ID . ':index');
        return is_array($inventaire) ? array_values($inventaire) : array();
    }

    private function dossier($_ctx, $_racine) {
        $dossier = $_ctx->recall(self::ID . ':tv:' . $_racine);
        if (!is_array($dossier)) {
            $dossier = array();
        }
        $dossier['racine'] = (string) $_racine;
        return $dossier;
    }

    private function range($_ctx, $_dossier) {
        $racine = (string) $_dossier['racine'];
        $_ctx->remember(self::ID . ':tv:' . $racine, $_dossier);
        $inventaire = $this->inventaire($_ctx);
        if (!in_array($racine, $inventaire, true)) {
            $inventaire[] = $racine;
            $_ctx->remember(self::ID . ':index', $inventaire);
        }
    }
}
