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


require_once __DIR__ . '/../../../core/php/core.inc.php';

/*
 * L'identifiant client est la signature de Jeedom auprès du broker. Deux
 * clients portant le même identifiant se chassent l'un l'autre en boucle : le
 * broker ferme la session précédente à chaque connexion. Il est donc engendré
 * une fois puis mémorisé, et jamais recalculé à chaque démarrage du démon.
 */
function mqttbe_ensureClientId() {
    if (trim(config::byKey('broker::clientId', 'mqttbe', '')) !== '') {
        return;
    }
    config::save('broker::clientId', 'jeedom-mqttbe-' . substr(sha1(uniqid('', true)), 0, 12), 'mqttbe');
}

function mqttbe_install() {
    mqttbe_ensureClientId();
    /* Le callback du démon ne parle qu'à un processus local : rien ne justifie
     * de le rendre joignable depuis l'extérieur. */
    config::save('api::mqttbe::mode', 'localhost', 'core');
}

/*
 * Appelée à chaque mise à jour, dans la requête HTTP et sans être détachée :
 * aucun appel réseau ici, sous peine de faire expirer la page « Gestion des
 * plugins ». Tout ce qui s'y trouve doit être hors ligne et rapide.
 */
function mqttbe_update() {

    /*
     * Rechiffrement du mot de passe du broker.
     *
     * Le chiffrement dépend de la déclaration `$_encryptConfigKey`, apparue
     * après les premières versions : une valeur enregistrée avant est restée en
     * clair dans la table `config`. La réécrire suffit à la faire chiffrer, et
     * l'opération est sans effet si elle l'est déjà.
     */
    try {
        $motDePasse = config::byKey('broker::password', 'mqttbe', '');
        if ($motDePasse !== '') {
            config::save('broker::password', $motDePasse, 'mqttbe');
        }
    } catch (Throwable $e) {
        log::add('mqttbe', 'warning', __('Rechiffrement du mot de passe :', __FILE__) . ' ' . $e->getMessage());
    }
    try {
        mqttbe_ensureClientId();
        config::save('api::mqttbe::mode', 'localhost', 'core');
    } catch (Throwable $e) {
        log::add('mqttbe', 'error', __('Mise à jour du plugin :', __FILE__) . ' ' . $e->getMessage());
    }
}

/*
 * Appelée aussi à la simple désactivation du plugin, pas seulement à sa
 * désinstallation : ne rien y détruire d'irrécupérable, et surtout pas
 * l'identifiant client ni les équipements découverts.
 */
function mqttbe_remove() {
    try {
        /* La classe du plugin n'est pas encore écrite au premier jalon, et le
         * cœur appelle cette fonction quoi qu'il arrive : sans ce garde-fou, la
         * désactivation échouerait sur une classe introuvable. */
        if (class_exists('mqttbe') && method_exists('mqttbe', 'deamon_stop')) {
            mqttbe::deamon_stop();
        }
    } catch (Throwable $e) {
        log::add('mqttbe', 'error', __('Arrêt du démon :', __FILE__) . ' ' . $e->getMessage());
    }
    try {
        /* Les messages du centre de notifications survivent au plugin qui les a
         * posés : ils resteraient affichés, sans moyen de savoir d'où ils
         * viennent une fois le plugin désactivé. */
        message::removeAll('mqttbe');
    } catch (Throwable $e) {
        log::add('mqttbe', 'error', __('Retrait des messages :', __FILE__) . ' ' . $e->getMessage());
    }
}
