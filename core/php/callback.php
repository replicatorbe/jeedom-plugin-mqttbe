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

/*
 * Le seul point d'entrée du démon vers Jeedom.
 *
 * Ce fichier est appelé pour chaque lot de messages : il est sur le chemin
 * chaud et doit rester court. Tout le travail de correspondance topic vers
 * commande a déjà été fait par le démon ; ici, on pose des valeurs et on tient
 * à jour l'état, rien de plus.
 *
 * Il ne doit surtout pas y avoir de .htaccess « Deny from all » dans ce
 * dossier : le démon appelle cette URL depuis l'extérieur d'Apache.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

if (!jeedom::apiAccess(init('apikey'), 'mqttbe')) {
    echo 'Unauthorized access.';
    $origine = sprintf(__('Accès non autorisé depuis %s', __FILE__), $_SERVER['REMOTE_ADDR']);
    if (init('apikey') != '') {
        /* Les huit premiers caractères suffisent à reconnaître la clé sans
         * l'écrire en clair dans un journal que d'autres peuvent lire. */
        $origine .= sprintf(__(", avec une clé commençant par %.8s…", __FILE__), init('apikey'));
    }
    log::add('mqttbe', 'error', $origine);
    die();
}

/* Le démon teste la joignabilité de cette URL par un simple GET au démarrage :
 * on répond et on s'arrête là. */
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    die();
}

require_once __DIR__ . '/../class/mqttbe.class.php';

$uid      = init('uid');
$entete   = __('Démon', __FILE__) . ' [' . $uid . '] : ';
$messages = json_decode(file_get_contents('php://input'), true);

if (!is_array($messages)) {
    log::add('mqttbe', 'error', $entete . __('corps de requête illisible', __FILE__));
    die();
}

foreach ($messages as $message) {
    if (!isset($message['cmd'])) {
        log::add('mqttbe', 'error', $entete . __('message sans clé cmd :', __FILE__) . ' ' . json_encode($message));
        continue;
    }

    /*
     * L'identité du démon est revalidée à chaque message et non une fois pour
     * toutes : un démon tué puis relancé pendant qu'un lot voyage encore doit
     * voir ses anciens messages refusés, sinon un démon fantôme continue
     * d'écrire dans Jeedom.
     *
     * 'daemonUp' fait exception : c'est précisément le message qui établit
     * cette identité.
     */
    $autorise = mqttbeDaemon::validUid($uid);
    if ($autorise !== true && $message['cmd'] != 'daemonUp') {
        log::add('mqttbe', 'debug', $entete . __('message refusé (démon non reconnu) :', __FILE__)
               . ' ' . $message['cmd']);
        continue;
    }

    try {
        switch ($message['cmd']) {
            case 'values':
                if (isset($message['items']) && is_array($message['items'])) {
                    mqttbeDaemon::onValues($message['items']);
                }
                break;

            case 'hb':
                mqttbeDaemon::onHeartbeat();
                break;

            case 'daemonUp':
                mqttbeDaemon::onDaemonUp($uid);
                break;

            case 'daemonDown':
                mqttbeDaemon::onDaemonDown();
                break;

            case 'brokerUp':
                mqttbeDaemon::onBrokerUp();
                break;

            case 'brokerDown':
                mqttbeDaemon::onBrokerDown(isset($message['message']) ? $message['message'] : '');
                break;

            default:
                log::add('mqttbe', 'error', $entete . __('commande inconnue :', __FILE__) . ' ' . $message['cmd']);
        }
    } catch (Throwable $e) {
        /* Un message fautif ne doit jamais empêcher le traitement des suivants :
         * le lot contient peut-être des valeurs parfaitement valides. */
        log::add('mqttbe', 'error', $entete . sprintf(
            __('le traitement de « %1$s » a levé : %2$s', __FILE__),
            $message['cmd'], $e->getMessage()
        ));
    }
}
