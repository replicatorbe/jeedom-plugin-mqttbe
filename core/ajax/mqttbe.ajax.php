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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* isConnect('admin') est une égalité stricte de profil : isConnect('user')
     * serait faux pour un administrateur. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    /* jeedomAutoload() ne sait résoudre que la classe portant le nom du plugin :
     * mqttbeDaemon n'est jamais autochargé et doit être inclus explicitement.
     * mqttbe.class.php s'en charge, et c'est lui qu'on inclut pour n'avoir qu'un
     * seul chemin d'entrée dans le code du plugin. */
    require_once __DIR__ . '/../class/mqttbe.class.php';

    if (init('action') == 'testConnection') {
        /*
         * Le test emprunte exactement le même client que le démon : il dit donc
         * la vérité sur ce que fera le démon, et non « le port est ouvert ».
         * Une sonde TCP répondrait « ok » sur un broker qui refuse ensuite les
         * identifiants — c'est le genre de faux positif qui coûte une soirée.
         */
        $autoload = __DIR__ . '/../../resources/mqttbed/lib/autoload.php';
        if (!file_exists($autoload)) {
            throw new Exception(__('La bibliothèque MQTT embarquée est introuvable : réinstallez le plugin', __FILE__));
        }
        require_once $autoload;

        /* Les réglages saisis priment sur ceux enregistrés : on peut ainsi
         * tester avant de sauvegarder, ce qui est l'ordre naturel. */
        $host = trim(init('host', config::byKey('broker::host', 'mqttbe', '')));
        $port = (int) init('port', config::byKey('broker::port', 'mqttbe', 1883));
        if ($host === '') {
            throw new Exception(__("Renseignez l'adresse du broker", __FILE__));
        }

        $settings = new \PhpMqtt\Client\ConnectionSettings();
        $settings = $settings
            ->setConnectTimeout(5)
            ->setSocketTimeout(5)
            ->setKeepAliveInterval((int) init('keepalive', config::byKey('broker::keepalive', 'mqttbe', 60)))
            ->setReconnectAutomatically(false);

        $username = init('username', config::byKey('broker::username', 'mqttbe', ''));
        $password = init('password', config::byKey('broker::password', 'mqttbe', ''));
        if ($username !== '') {
            $settings = $settings->setUsername($username)->setPassword($password === '' ? null : $password);
        }
        if (init('tls', config::byKey('broker::tls', 'mqttbe', 0)) == 1) {
            $settings = $settings->setUseTls(true);
            if (init('tlsInsecure', config::byKey('broker::tlsInsecure', 'mqttbe', 0)) == 1) {
                $settings = $settings->setTlsVerifyPeer(false)->setTlsVerifyPeerName(false)
                                     ->setTlsSelfSignedAllowed(true);
            }
            $caFile = trim(init('caFile', config::byKey('broker::caFile', 'mqttbe', '')));
            if ($caFile !== '') {
                $settings = $settings->setTlsCertificateAuthorityFile($caFile);
            }
        }

        /* Identifiant distinct de celui du démon : tester la connexion ne doit
         * jamais déconnecter le démon en cours de fonctionnement. Deux clients
         * portant le même identifiant s'expulsent mutuellement en boucle. */
        $clientId = substr(mqttbeDaemon::getClientId() . '-test', 0, 23);

        $client = new \PhpMqtt\Client\MqttClient(
            $host, $port, $clientId, \PhpMqtt\Client\MqttClient::MQTT_3_1_1
        );

        $client->connect($settings, true);

        /* Le broker publie sa version sur $SYS : quand elle est là, elle prouve
         * que la souscription fonctionne aussi, pas seulement la connexion. */
        $version = '';
        $client->subscribe('$SYS/broker/version', function ($topic, $message) use (&$version) {
            $version = $message;
        }, 0);
        $debut = microtime(true);
        while ($version === '' && (microtime(true) - $debut) < 1.5) {
            $client->loopOnce(microtime(true), true, 50000);
        }
        $client->disconnect();

        $resultat = sprintf(__('Connexion réussie à %1$s:%2$s', __FILE__), $host, $port);
        if ($version !== '') {
            $resultat .= ' — ' . $version;
        }
        /* La page attend un objet : elle affiche « message » tel quel, et la
         * version du broker en dit bien plus qu'un simple « connecté ». */
        ajax::success(array('message' => $resultat, 'version' => $version));
    }

    if (init('action') == 'daemonInfo') {
        /* La page principale affiche deux pastilles : démon et broker. Elles
         * sont rafraîchies par événement, mais il faut bien un état initial. */
        ajax::success(array(
            'daemon' => mqttbeDaemon::state(),
            'broker' => mqttbeDaemon::brokerState(),
            'host'   => config::byKey('broker::host', 'mqttbe', ''),
            'port'   => (int) config::byKey('broker::port', 'mqttbe', 1883),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Throwable $e) {
    /* catch (Exception) ne rattraperait pas les Error de PHP 8 : une méthode
     * inexistante produirait un HTTP 500 muet côté navigateur. */
    ajax::error(displayException($e), $e->getCode());
}
