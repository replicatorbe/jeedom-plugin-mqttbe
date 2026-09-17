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
 * Le contexte d'un adapter : ce qui rend la découverte ACTIVE.
 *
 * Une découverte passive — écouter et espérer — ne trouve que les appareils
 * qui viennent de démarrer. Un Shelly branché il y a trois semaines a émis son
 * annonce bien avant que le plugin n'existe, et il ne la réémettra jamais :
 * sans publish(), il reste invisible pour toujours. C'est la raison d'être de
 * cet objet, et la différence de fond avec les plugins qui se contentent
 * d'écouter.
 *
 * Un contexte par adapter, construit une fois par le moteur et passé à chaque
 * appel. Il n'exécute rien lui-même : il porte l'identité de l'adapter qui
 * parle et délègue au moteur, qui sait, lui, à quel transport écrire et quelle
 * mémoire toucher. C'est ce qui rend le CLOISONNEMENT gratuit — l'adapter ne
 * peut pas désigner une autre mémoire que la sienne, puisqu'il ne nomme jamais
 * la sienne.
 *
 * Aucune notion de Jeedom ici : ce fichier est chargé par le démon comme par
 * le processus web (mqttbeFactory::loadDiscovery), qui n'a ni broker, ni
 * journal sur STDOUT.
 * ========================================================================== */
class MqttbeDiscoveryContext {

    private $engine;
    private $adapterId;

    public function __construct($_engine, $_adapterId) {
        $this->engine    = $_engine;
        $this->adapterId = (string) $_adapterId;
    }

    /* L'identifiant de l'adapter servi par ce contexte. Utile pour composer un
     * jeton de corrélation ou un message de journal sans le recopier. */
    public function adapter() {
        return $this->adapterId;
    }

    /* --------------------------------------------------------------- broker */

    /*
     * Poser un message sur le broker : provoquer une annonce, émettre un appel
     * RPC, interroger un appareil qui ne parle que lorsqu'on lui parle.
     *
     * Rend false quand la liaison est absente — ce n'est pas une erreur de
     * programme mais l'état ordinaire d'un démon qui attend son broker, et
     * l'adapter doit pouvoir réessayer au tour suivant plutôt que de tenir
     * l'appel pour fait.
     */
    public function publish($_topic, $_payload, $_qos = 0, $_retain = false) {
        return $this->engine->publishFor($this->adapterId, $_topic, $_payload, $_qos, $_retain);
    }

    /*
     * S'abonner en cours de route, quand le topic ne se connaissait pas à
     * l'activation : la réponse d'un appel RPC, la branche d'un préfixe qu'on
     * vient de découvrir.
     *
     * L'abonnement rejoint ceux de subscriptions() et suit leur sort : il est
     * posé tout de suite si le broker est là, rejoué après une coupure, et
     * résilié à l'arrêt de la découverte — sauf si le routage, lui, en a
     * besoin. Rend false si le filtre est illégal ou si le plafond
     * d'abonnements de découverte est atteint.
     */
    public function subscribe($_topic) {
        return $this->engine->subscribeFor($this->adapterId, $_topic);
    }

    /* -------------------------------------------------------------- mémoire */

    /*
     * Mémoire de l'adapter, cloisonnée et bornée (voir Adapter.php).
     *
     * remember() rend false quand le plafond est atteint et que la clé est
     * nouvelle : le moteur le journalise, et l'adapter peut en tenir compte.
     * Mettre à jour une clé déjà connue reste toujours possible — sans quoi un
     * adapter arrivé au plafond ne pourrait même plus rafraîchir ce qu'il sait.
     */
    public function remember($_cle, $_donnees) {
        return $this->engine->rememberFor($this->adapterId, $_cle, $_donnees);
    }

    /* Rend null pour une clé inconnue : null et « rien mémorisé » sont la même
     * chose, et distinguer les deux coûterait à chaque appel une question que
     * personne n'a jamais eu besoin de poser. */
    public function recall($_cle) {
        return $this->engine->recallFor($this->adapterId, $_cle);
    }

    public function forget($_cle) {
        return $this->engine->forgetFor($this->adapterId, $_cle);
    }

    /* Nombre de clés mémorisées par cet adapter. Sert surtout à ses propres
     * contrôles : un adapter qui approche du plafond mémorise par topic vu
     * plutôt que par appareil, et c'est un défaut de conception. */
    public function memoryCount() {
        return $this->engine->memoryCountFor($this->adapterId);
    }

    /* --------------------------------------------------------------- sortie */

    /*
     * Remettre un modèle terminé au moteur, qui le dédoublonne par empreinte
     * puis le fait porter à Jeedom. Accepte un MqttbeDeviceModel ou le tableau
     * équivalent. Rend false si le modèle est refusé (invalide) ; true aussi
     * bien quand il part que lorsqu'il est reconnu identique au précédent —
     * du point de vue de l'adapter, le modèle est arrivé à destination.
     */
    public function emit($_modele) {
        return $this->engine->emitFrom($this->adapterId, $_modele);
    }

    /*
     * Le journal du démon, préfixé de l'identifiant de l'adapter : sur une
     * installation où trois adapters travaillent en même temps, une ligne qui
     * ne dit pas qui parle n'apprend rien.
     *
     * Niveaux : debug, info, warning, error. La découverte est un événement
     * rare — un appareil trouvé mérite une ligne en info ; un message reçu,
     * non.
     */
    public function log($_niveau, $_message) {
        $this->engine->logFrom($this->adapterId, $_niveau, $_message);
    }

    /* ----------------------------------------------------------------- temps */

    /*
     * L'instant courant, en secondes flottantes, tel que le moteur le voit.
     *
     * Passer par le contexte plutôt que par microtime() n'est pas une
     * cérémonie : c'est ce qui permet d'éprouver hors ligne une expiration de
     * candidat ou une relance au bout de trente secondes, en faisant avancer
     * une horloge au lieu d'attendre une demi-minute par contrôle.
     */
    public function now() {
        return $this->engine->now();
    }

    /*
     * Vrai dans le onTick() qui suit l'activation de l'adapter ou une demande
     * de relance de l'utilisateur (`{"cmd":"discovery","rescan":true}`).
     *
     * Les deux cas appellent le même geste — provoquer une annonce générale du
     * parc — et se lisent donc par la même question. Le drapeau vaut pour tout
     * l'appel et retombe ensuite : le lire deux fois donne deux fois la même
     * réponse.
     */
    public function rescan() {
        return $this->engine->rescanFor($this->adapterId);
    }
}
