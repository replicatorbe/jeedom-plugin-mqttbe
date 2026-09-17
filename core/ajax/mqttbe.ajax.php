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

        /*
         * La version vient du broker, donc du réseau, et le message finit dans
         * `showAlert`, qui l'insère en `innerHTML` dans la page d'un
         * administrateur. Un broker hostile — ou simplement un broker dont les
         * ACL laissent publier sur $SYS — y placerait du script exécuté avec
         * tous les droits de Jeedom. On borne, on ôte les caractères de
         * contrôle, et on échappe.
         */
        if ($version !== '') {
            $version = preg_replace('/[\x00-\x1F\x7F]/', '', $version);
            $version = htmlspecialchars(mb_substr($version, 0, 60), ENT_QUOTES, 'UTF-8');
        }

        $resultat = sprintf(__('Connexion réussie à %1$s:%2$s', __FILE__),
                            htmlspecialchars($host, ENT_QUOTES, 'UTF-8'), (int) $port);
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

    if (init('action') == 'routingTable') {
        /*
         * Ce que le démon a réellement reçu, et non ce qu'on croit lui avoir
         * envoyé. Quand une commande reste muette, la première question est
         * « son topic est-il seulement dans la table ? » : y répondre sans
         * fouiller les journaux fait gagner beaucoup de temps.
         */
        $table = mqttbeRouting::build();
        $cibles = 0;
        foreach ($table as $entree) {
            $cibles += count($entree['targets']);
        }
        ajax::success(array(
            'version' => mqttbeRouting::version(),
            'topics'  => count($table),
            'cibles'  => $cibles,
            'table'   => $table,
        ));
    }

    if (init('action') == 'rescan') {
        /*
         * Redemande à tout le parc de se présenter.
         *
         * Un appareil connecté depuis des semaines ne s'annonce plus : il a
         * parlé une fois, bien avant que le plugin n'existe. Sans cette relance,
         * il resterait invisible alors qu'il publie ses valeurs en continu —
         * c'est le cas le plus courant sur une installation existante, et le
         * premier reproche qu'on ferait au plugin.
         */
        if (!mqttbeDaemon::state()) {
            throw new Exception(__("Le démon n'est pas démarré", __FILE__));
        }
        /*
         * Sans ce contrôle, la relance répondait « les appareils se présentent »
         * alors que le démon, découverte désactivée, n'active aucun adapter et
         * ne publie rien : la page affirmait le contraire de ce qui se passait.
         */
        if (config::byKey('discovery::enabled', 'mqttbe', 1) != 1) {
            throw new Exception(__("La découverte automatique est désactivée : réactivez-la et enregistrez avant de relancer.", __FILE__));
        }
        mqttbeDaemon::sendDiscoveryConfig(true);
        ajax::success(array('message' => __('Découverte relancée : les appareils se présentent, patientez quelques secondes.', __FILE__)));
    }

    if (init('action') == 'adopt') {
        /*
         * Adopter un candidat : c'est l'utilisateur qui tranche, et le modèle
         * est déjà là — on ne redemande rien à l'appareil, on applique ce que la
         * découverte avait mis de côté.
         */
        $uid = init('uid');
        $enAttente = mqttbeDaemon::pendingModel($uid);
        if ($enAttente === null) {
            throw new Exception(__("Ce candidat n'est plus dans la file : relancez la découverte.", __FILE__));
        }
        mqttbeFactory::loadDiscovery();
        /* fromArray() lève quand le modèle est illisible, elle ne rend jamais
         * null : le test « === null » d'avant était inatteignable. */
        $modele = MqttbeDeviceModel::fromArray($enAttente);
        /*
         * Revalidé, comme le fait importModel. Le modèle vient du cache : il a
         * pu y attendre une mise à jour du plugin, et ce que la découverte
         * acceptait la semaine dernière n'est pas forcément ce que la fabrique
         * saura écrire aujourd'hui. Mieux vaut un refus explicite qu'un
         * équipement à moitié construit.
         */
        $motifs = $modele->validate();
        if (!empty($motifs)) {
            throw new Exception(__('Modèle refusé :', __FILE__) . ' ' . implode(' ; ', $motifs));
        }
        /*
         * L'adoption vaut décision : le modèle était « guess », il devient
         * certain. Sans cela, la fabrique le remettrait aussitôt dans la file
         * et le bouton n'aurait aucun effet visible.
         */
        $donnees = $modele->toArray();
        $donnees['identity']['confidence'] = 'certain';
        $compte = mqttbeFactory::applyData($donnees);
        /*
         * applyData() n'échoue jamais par exception : elle rend status =
         * 'error' et les motifs dans messages[]. Ne pas regarder ce statut,
         * c'était retirer le candidat de la file — donc le perdre — puis
         * annoncer en vert « Équipement créé : sans nom — 0 commande(s) ».
         */
        if ($compte['status'] === 'error') {
            $motifs = isset($compte['messages']) && is_array($compte['messages'])
                    ? $compte['messages'] : array();
            throw new Exception(__("L'adoption a échoué :", __FILE__) . ' '
                . (empty($motifs) ? __('raison inconnue, voyez le journal du plugin.', __FILE__)
                                  : implode(' ; ', $motifs))
                . ' ' . __('Le candidat reste dans la file.', __FILE__));
        }
        /* Retiré de la file seulement maintenant : après une écriture réussie,
         * et pas avant. */
        mqttbeDaemon::forgetPending($uid);
        ajax::success($compte);
    }

    if (init('action') == 'ignore') {
        /* Écarté ne veut pas dire oublié : sans mémoire du refus, le candidat
         * reviendrait dans la file à la trame suivante, quelques secondes plus
         * tard, indéfiniment. */
        $uid = init('uid');
        mqttbeDaemon::ignoreUid($uid);
        /* Le refus est écrit en configuration : il tient même si la file, elle,
         * n'a pas pu être réécrite — le candidat ne reviendra pas. */
        $ignores = mqttbeDaemon::ignoredUids();
        if (!isset($ignores[(string) $uid])) {
            throw new Exception(__("Le refus n'a pas pu être enregistré : l'appareil serait reproposé.", __FILE__));
        }
        ajax::success(array('message' => __('Appareil écarté : il ne vous sera plus proposé.', __FILE__)));
    }

    if (init('action') == 'unignore') {
        $uid = init('uid');
        mqttbeDaemon::forgetIgnored($uid);
        $ignores = mqttbeDaemon::ignoredUids();
        if (isset($ignores[(string) $uid])) {
            throw new Exception(__("Le refus n'a pas pu être annulé.", __FILE__));
        }
        ajax::success(array('message' => __('Appareil de nouveau proposé à la prochaine découverte.', __FILE__)));
    }

    if (init('action') == 'pending') {
        /* Ce que la découverte a vu sans le créer, quand la création
         * automatique est désactivée. */
        $attente = mqttbeDaemon::pendingQueue();
        if ($attente === null) {
            /* La file n'a pas pu être lue. Répondre « vide » ferait croire que
             * plus rien n'attend, et le refus d'un candidat invisible est
             * impossible : mieux vaut le dire. */
            throw new Exception(__("La file d'adoption n'a pas pu être lue : réessayez dans un instant.", __FILE__));
        }
        $liste = array();
        foreach ($attente as $uid => $candidat) {
            $meta = isset($candidat['model']['meta']) ? $candidat['model']['meta'] : array();
            /* Le modèle complet n'a rien à faire dans la page : ce qui aide à
             * décider, c'est depuis quand on le voit et ce qu'on en sait. */
            $liste[] = array(
                'uid'      => $uid,
                'name'     => isset($candidat['name']) ? $candidat['name'] : $uid,
                'adapter'  => isset($candidat['adapter']) ? $candidat['adapter'] : '',
                'channels' => isset($candidat['channels']) ? (int) $candidat['channels'] : 0,
                'first'    => isset($candidat['first']) ? (int) $candidat['first'] : 0,
                'seen'     => isset($candidat['seen']) ? (int) $candidat['seen'] : 0,
                'meta'     => array_intersect_key($meta, array_flip(array(
                    'manufacturer', 'model', 'model_name', 'device_name',
                    'address_type', 'gateways', 'ip',
                ))),
            );
        }
        /* Plus de tri ici : la file arrive déjà dans l'ordre où l'on décide —
         * présence durable et adresse stable d'abord, c'est-à-dire l'ordre
         * même qui décide de ce qui reste quand elle déborde. Deux ordres
         * différents diraient deux choses différentes du même candidat. */
        ajax::success(array('pending' => $liste, 'ignored' => mqttbeDaemon::ignoredUids()));
    }

    if (init('action') == 'importModel') {
        /*
         * Applique un modèle de périphérique fourni en JSON. C'est le chemin
         * qu'emprunteront les adapters au jalon 3 ; l'exposer dès maintenant
         * permet de l'éprouver avec un modèle écrit à la main, et il restera
         * utile pour rejouer un appareil capturé chez un utilisateur.
         */
        mqttbeFactory::loadDiscovery();
        $modele = MqttbeDeviceModel::fromJson(init('model'));
        if ($modele === null) {
            throw new Exception(__('Modèle de périphérique illisible', __FILE__));
        }
        $motifs = $modele->validate();
        if (!empty($motifs)) {
            throw new Exception(__('Modèle refusé :', __FILE__) . ' ' . implode(' ; ', $motifs));
        }
        ajax::success(mqttbeFactory::apply($modele));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));

} catch (Throwable $e) {
    /* catch (Exception) ne rattraperait pas les Error de PHP 8 : une méthode
     * inexistante produirait un HTTP 500 muet côté navigateur. */
    ajax::error(displayException($e), $e->getCode());
}
