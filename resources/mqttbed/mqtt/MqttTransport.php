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

/* =============================================================================
 * LA frontière avec la bibliothèque MQTT.
 *
 * Le reste du démon — boucle, socket de commande, liaison Jeedom, et demain le
 * routeur et les adapters — ne connaît que cette interface. Aucun `use
 * PhpMqtt\...` ailleurs que dans son implémentation, aucune exception de la
 * bibliothèque qui remonte, aucun objet de la bibliothèque qui circule.
 *
 * Ce n'est pas de l'abstraction pour l'abstraction. La branche 1.x de
 * php-mqtt/client a été abandonnée ; la 2.x le sera un jour. Le fil binaire
 * MQTT, lui, ne bougera pas. Quand il faudra changer de bibliothèque, ou
 * passer à MQTT 5, le travail doit tenir dans un seul fichier — celui qui
 * implémente cette interface — et non se répandre dans le routage, où se
 * trouve la vraie valeur du plugin.
 *
 * Conventions de cette interface :
 *
 * - AUCUNE MÉTHODE NE LÈVE D'EXCEPTION. Elles rendent un booléen ; le détail
 *   du dernier échec est lisible par lastError(). Une bibliothèque qui lève
 *   trois exceptions différentes pour « le broker ne répond pas » n'a pas à
 *   imposer ses catch à tout le démon.
 * - AUCUNE MÉTHODE NE BLOQUE, sauf connect(), qui ouvre une socket et attend
 *   le CONNACK. C'est le seul moment où la boucle principale s'arrête, et la
 *   raison pour laquelle les tentatives de connexion sont espacées.
 * - LE DRAPEAU `retained` EST TRANSMIS TEL QUEL au gestionnaire de réception.
 *   C'est lui qui distingue « état que le broker rejoue à l'abonnement » de
 *   « quelque chose vient de se produire ». Une découverte qui l'ignore
 *   fabrique de faux événements à chaque démarrage du démon.
 * ========================================================================== */
interface MqttbeTransport {

    /*
     * Ouvre la liaison et rejoue les abonnements demandés jusque-là.
     *
     * L'implémentation garde la liste des abonnements souhaités : après une
     * coupure, le démon rappelle connect() et retrouve ses topics sans avoir à
     * se souvenir de rien. Rend false sur échec, lastError() dit pourquoi.
     */
    public function connect();

    /* Ferme proprement (trame DISCONNECT puis socket). Ne se plaint pas si la
     * liaison est déjà tombée : on l'appelle justement quand on ne sait plus. */
    public function disconnect();

    public function isConnected();

    /* Enregistre le topic comme souhaité, et l'envoie au broker s'il y a une
     * liaison. Un abonnement demandé hors connexion n'est donc pas perdu. */
    public function subscribe($_topic, $_qos = 0);

    public function unsubscribe($_topic);

    public function publish($_topic, $_payload, $_qos = 0, $_retain = false);

    /*
     * Un tour de moteur : lecture de ce qui est arrivé, ping, réémissions.
     * À appeler à chaque tour de boucle, que le descripteur soit prêt ou non —
     * le keepalive et les réémissions ne dépendent pas du trafic entrant.
     * Rend false quand la liaison est perdue.
     */
    public function tick();

    /*
     * Le descripteur de la socket du broker, pour le stream_select de la
     * boucle principale, ou null s'il n'y en a pas. C'est le seul détail
     * d'implémentation que cette interface laisse filtrer, et il n'est là que
     * pour le multiplexage : personne ne lit ni n'écrit dessus.
     */
    public function stream();

    /*
     * LE POINT D'EXTENSION du plugin.
     *
     * Appelé pour chaque message reçu : ($topic, $payload, $qos, $retained).
     * Aux jalons 0 et 1 il ne fait que compter et journaliser. C'est ici que
     * viendra se brancher le routeur du jalon 2, puis la découverte : le
     * transport, lui, n'aura pas à changer.
     */
    public function onMessage($_handler);

    /* Appelé après chaque connexion établie, une fois les abonnements rejoués. */
    public function onConnected($_handler);

    /* Dernier échec en clair, destiné au journal et au message `brokerDown`
     * poussé vers Jeedom — donc lisible par un utilisateur, pas une trace. */
    public function lastError();
}
