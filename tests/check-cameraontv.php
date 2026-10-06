<?php
/* L'adapter CameraOnTv, confronté au `state` réel de l'application.
 *
 *   php tests/check-cameraontv.php
 *
 * Ni Jeedom, ni broker : le `state` retenu d'une TV est rejoué dans un
 * contexte de papier. Ce que ces contrôles attrapent :
 *
 *   - l'écran courant change à chaque appui sur la télécommande, et le `state`
 *     est republié à chaque fois. S'il entrait dans l'empreinte, chaque
 *     changement de caméra réécrirait l'équipement ;
 *   - `+/state` est un filtre large : un autre appareil qui publie là ne doit
 *     pas devenir une TV ;
 *   - la clé d'une commande de caméra suit son canal : une caméra renommée
 *     dans l'enregistreur doit garder sa commande, donc ses scénarios. */

require_once __DIR__ . '/outils.php';

class MqttbeContexteEssaiCameraOnTv {
    public $modeles = array();
    public $journal = array();
    public $relance = false;
    private $memoire = array();

    public function publish($_topic, $_payload, $_qos = 0, $_retain = false) { return true; }
    public function subscribe($_topic) { return true; }
    public function remember($_cle, $_donnees) { $this->memoire[$_cle] = $_donnees; return true; }
    public function recall($_cle) { return array_key_exists($_cle, $this->memoire) ? $this->memoire[$_cle] : null; }
    public function forget($_cle) { unset($this->memoire[$_cle]); }
    public function emit($_modele) { $this->modeles[] = $_modele; return true; }
    public function log($_niveau, $_message) { $this->journal[] = $_niveau . ' : ' . $_message; }
    public function now() { return 1700000000.0; }
    public function rescan() { $demandee = $this->relance; $this->relance = false; return $demandee; }
}

function mqttbeEtatCameraOnTv($_surcharge = array()) {
    return json_encode(array_merge(array(
        'screen' => 'grid', 'camera' => null, 'cameraName' => null, 'ptzMode' => false, 'visible' => false,
        'cameras' => array(
            array('channel' => 5, 'name' => 'OUESTPTZ', 'ptz' => true),
            array('channel' => 6, 'name' => 'garage', 'ptz' => false),
            array('channel' => 7, 'name' => 'INTERCOM', 'ptz' => false),
        ),
    ), $_surcharge));
}

function mqttbeCanauxCameraOnTv($_modele) {
    $canaux = array();
    foreach ($_modele->toArray()['channels'] as $canal) {
        $canaux[$canal['key']] = $canal;
    }
    return $canaux;
}

function mqttbeControlesCameraOnTv() {
    $resultats = array();
    $chemin = mqttbeRacine() . '/resources/mqttbed/discovery/adapters/CameraOnTv.php';
    if (!is_readable($chemin)) {
        return array(mqttbeIndecis('adapter CameraOnTv', 'fichier absent'));
    }
    require_once $chemin;
    MqttbeChannel::loadCapabilities(mqttbeRacine() . '/core/config/capabilities.json');

    /* 1. Le `state` d'une TV donne un équipement complet et valide. */
    $titre = 'une TV devient un équipement : une commande par caméra, un preset par PTZ';
    $adapter = new MqttbeCameraOnTv();
    $ctx = new MqttbeContexteEssaiCameraOnTv();
    $adapter->onMessage('cameratv/state', mqttbeEtatCameraOnTv(), true, $ctx);
    $fautes = array();
    if (count($ctx->modeles) !== 1) {
        $fautes[] = count($ctx->modeles) . ' modèle(s) émis au lieu d\'un.';
    } else {
        $modele = $ctx->modeles[0];
        $canaux = mqttbeCanauxCameraOnTv($modele);
        foreach ($modele->validate() as $faute) {
            $fautes[] = $faute;
        }
        if ($modele->uid() !== 'cameraontv:cameratv' || $modele->name() !== 'Caméras TV') {
            $fautes[] = 'identité inattendue : ' . $modele->uid() . ' / ' . $modele->name();
        }
        foreach (array('online', 'state.camera_name', 'cmd.grid', 'cmd.exit', 'cmd.show',
                       'cmd.show.5', 'cmd.show.6', 'cmd.show.7', 'cmd.ptz.5.preset') as $cle) {
            if (!isset($canaux[$cle])) {
                $fautes[] = 'canal « ' . $cle . ' » absent.';
            }
        }
        if (isset($canaux['cmd.ptz.6.preset'])) {
            $fautes[] = 'un preset sur une caméra sans PTZ.';
        }
        if (isset($canaux['cmd.show.6']) && $canaux['cmd.show.6']['sink']['payload'] !== '{"camera":"garage"}') {
            $fautes[] = 'charge utile de « Afficher garage » : ' . $canaux['cmd.show.6']['sink']['payload'];
        }
        if (isset($canaux['cmd.ptz.5.preset'])
            && $canaux['cmd.ptz.5.preset']['sink']['payload'] !== '{"camera":5,"action":"preset","value":#slider#}') {
            $fautes[] = 'charge utile du preset : ' . $canaux['cmd.ptz.5.preset']['sink']['payload'];
        }
        if (isset($canaux['cmd.show']) && $canaux['cmd.show']['sink']['payload'] !== '#message#') {
            $fautes[] = 'la commande générique ne publie pas le message nu.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* 2. Changer d'écran ne réémet rien ; changer de caméras, si. */
    $titre = 'changer d\'écran ne réécrit pas l\'équipement';
    $adapter->onMessage('cameratv/state', mqttbeEtatCameraOnTv(array(
        'screen' => 'single', 'camera' => 7, 'cameraName' => 'INTERCOM', 'visible' => true)), false, $ctx);
    $fautes = array();
    if (count($ctx->modeles) !== 1) {
        $fautes[] = 'un changement d\'écran a réémis le modèle : la base serait réécrite à chaque appui.';
    }
    $renomme = json_decode(mqttbeEtatCameraOnTv(), true);
    $renomme['cameras'][1]['name'] = 'Garage';
    $adapter->onMessage('cameratv/state', json_encode($renomme), false, $ctx);
    if (count($ctx->modeles) !== 2) {
        $fautes[] = 'une caméra renommée n\'a pas réémis le modèle.';
    } else {
        $canaux = mqttbeCanauxCameraOnTv($ctx->modeles[1]);
        if (!isset($canaux['cmd.show.6']) || $canaux['cmd.show.6']['name'] !== 'Afficher Garage') {
            $fautes[] = 'la caméra renommée n\'a pas gardé sa clé (canal 6).';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* 3. Ce qui n'est pas une TV reste ignoré. */
    $titre = 'un autre « +/state » ne devient pas une TV';
    $ctx2 = new MqttbeContexteEssaiCameraOnTv();
    $adapter2 = new MqttbeCameraOnTv();
    $adapter2->onMessage('volet/state', '{"position":40}', false, $ctx2);
    $adapter2->onMessage('lampe/state', 'ON', false, $ctx2);
    $adapter2->onMessage('foo/state', '{"screen":"x","cameras":"non"}', false, $ctx2);
    $resultats[] = empty($ctx2->modeles) ? mqttbeOk($titre)
        : mqttbeEchec($titre, count($ctx2->modeles) . ' modèle(s) émis pour des appareils qui ne sont pas des TV.');

    /* 4. La relance réémet, depuis la mémoire, sans attendre l'application. */
    $titre = 'relancer la découverte réémet la TV connue';
    $avant = count($ctx->modeles);
    $ctx->relance = true;
    $adapter->onTick($ctx);
    $resultats[] = count($ctx->modeles) === $avant + 1 ? mqttbeOk($titre)
        : mqttbeEchec($titre, 'aucune réémission : un équipement supprimé par erreur ne reviendrait pas.');

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Découverte CameraOnTv', mqttbeControlesCameraOnTv());
}
