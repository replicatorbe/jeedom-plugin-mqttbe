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

/* =============================================================================
 * Le contrat d'un adapter de découverte.
 *
 * Un adapter est la seule pièce du plugin qui ait le droit de connaître un
 * constructeur. Il regarde passer des messages MQTT, en pose lui-même quand il
 * faut provoquer une réponse, et rend des MqttbeDeviceModel — c'est-à-dire des
 * capacités et des topics, jamais des types Jeedom. Le moteur
 * (MqttbeDiscoveryEngine), lui, ne sait rien de Shelly, de Tasmota ni de
 * Zigbee2MQTT : il ne connaît que cette interface. Un cas particulier qui
 * remonterait jusqu'au moteur serait le signe que cette interface est mal
 * taillée, et c'est elle qu'il faudrait corriger.
 *
 * CE QUE VOUS AVEZ ENTRE LES MAINS EN ÉCRIVANT UN ADAPTER
 *
 * Un contexte (MqttbeDiscoveryContext), passé à chaque appel. Il porte tout ce
 * dont l'adapter a besoin, et l'adapter ne doit rien chercher ailleurs :
 *
 *   $ctx->publish($topic, $payload, $qos = 0, $retain = false)
 *          Poser un message sur le broker. C'est ce qui rend la découverte
 *          ACTIVE : un parc déjà connecté depuis des semaines n'émettra plus
 *          jamais son annonce de démarrage, et seul un appel explicite le fait
 *          se re-présenter. Rend false si le broker n'est pas joignable.
 *
 *   $ctx->subscribe($topic)
 *          S'abonner en cours de route, par exemple au topic de réponse d'un
 *          appel RPC dont le jeton vient d'être tiré. L'abonnement est tenu
 *          par le moteur, avec ceux de subscriptions(), et il sera résilié
 *          quand la découverte sera arrêtée — jamais tant qu'un autre
 *          mécanisme du démon en a besoin.
 *
 *   $ctx->remember($cle, $donnees) / $ctx->recall($cle) / $ctx->forget($cle)
 *          La mémoire de l'adapter entre deux messages : le candidat vu dont
 *          on attend encore les capacités, la corrélation d'un appel et de sa
 *          réponse, l'instant du dernier essai. Elle est CLOISONNÉE — deux
 *          adapters qui emploient la clé « announce » ne se marchent pas
 *          dessus — et BORNÉE : au-delà de MqttbeDiscoveryEngine::MEMORY_MAX
 *          clés, les nouvelles sont refusées et le fait est journalisé.
 *          Mémoriser un état PAR TOPIC VU fait donc buter sur ce plafond ; il
 *          faut mémoriser par appareil, dont le nombre est fini.
 *
 *   $ctx->emit($modele)
 *          Remettre au moteur un modèle terminé (MqttbeDeviceModel, ou le
 *          tableau équivalent). Un modèle invalide est refusé et journalisé,
 *          pas envoyé à Jeedom, où il échouerait sur une erreur SQL qui ne
 *          désigne pas sa cause. Émettre deux fois de suite un modèle
 *          identique ne coûte rien : le moteur compare les empreintes et
 *          n'envoie que ce qui a changé. C'est voulu, et c'est même le cas
 *          normal — les messages de découverte étant retenus, le broker les
 *          rejoue en entier à chaque démarrage du démon.
 *
 *   $ctx->log($niveau, $message)   // debug, info, warning, error
 *   $ctx->now()                    // instant courant, en secondes flottantes
 *   $ctx->rescan()                 // voir onTick() ci-dessous
 *
 * LES QUATRE RÈGLES
 *
 * 1. NE JAMAIS BLOQUER. L'adapter est appelé depuis la boucle principale du
 *    démon, celle qui lit aussi la socket du broker. Une requête HTTP
 *    synchrone de trois secondes, c'est trois secondes pendant lesquelles le
 *    keepalive MQTT court : le broker coupe la session, et tout le plugin
 *    s'arrête de fonctionner le temps de la reconnexion. Une sonde HTTP se
 *    lance et se relit plus tard, sur des tours suivants.
 *
 * 2. NE RIEN GARDER AILLEURS QUE DANS LE CONTEXTE. Une propriété d'objet
 *    survit aussi, mais elle n'est ni bornée, ni vidée à l'arrêt de la
 *    découverte, ni visible du moteur — et c'est ainsi qu'un démon grossit
 *    toute une nuit sans que personne ne sache pourquoi.
 *
 * 3. LE DRAPEAU `retained` N'EST PAS UN DÉTAIL. Il dit « état rejoué par le
 *    broker » et non « cela vient de se produire ». Un adapter qui l'ignore
 *    fabrique de faux événements à chaque démarrage : il croit voir un bouton
 *    pressé alors qu'il lit le souvenir de la dernière pression.
 *
 * 4. UNE EXCEPTION N'EST PAS UNE STRATÉGIE. Le moteur en attrape, les
 *    journalise et abandonne LE MESSAGE — jamais le démon, jamais l'adapter.
 *    Mais un adapter qui lève à chaque message ne découvre rien tout en
 *    remplissant le journal : une charge utile illisible est un cas ordinaire,
 *    qui se traite par un `return`.
 * ========================================================================== */
interface MqttbeAdapter {

    /*
     * L'identifiant de l'adapter : « shelly.gen1 », « tasmota », « z2m ».
     *
     * Il voyage jusque dans la configuration de l'équipement Jeedom
     * (`mqttbe::adapter`) et dans l'ordre `discovery` qui active les adapters
     * un à un : le changer revient à faire oublier à Jeedom qui a découvert
     * quoi. Minuscules, sans espace, stable dans le temps.
     */
    public function id();

    /*
     * Ce qui tranche quand deux adapters revendiquent le même appareil :
     * 100 = protocole natif du constructeur, 50 = Home Assistant Discovery,
     * 10 = déduction générique. Le plus grand l'emporte ; à égalité, l'ordre
     * alphabétique des identifiants, pour que la découverte donne le même
     * résultat d'une exécution à l'autre.
     */
    public function priority();

    /*
     * Les topics à écouter pour découvrir, et eux seuls.
     *
     * Deux formes acceptées : une liste de filtres MQTT
     * (`array('shellies/announce', 'shellies/+/online')`) ou un tableau
     * filtre => QoS. Les jokers `+` et `#` sont permis ; un filtre illégal au
     * sens de l'OASIS 3.1.1 §4.7.1 est refusé et journalisé, parce que le
     * broker ne lui donnerait pas le sens qu'on croit.
     *
     * S'abonner à `#` « pour trier ensuite » est le piège à éviter : le démon
     * traverserait alors la totalité du trafic du broker — avec une passerelle
     * Zigbee, des dizaines de milliers de messages par heure dont pas un ne
     * concerne la découverte.
     *
     * Appelée à l'activation de l'adapter, pas à chaque message : la liste est
     * tenue pour fixe. Un topic qui ne se connaît qu'en cours de route se
     * demande par $ctx->subscribe().
     */
    public function subscriptions();

    /*
     * Un message reçu sur l'un des topics ci-dessus.
     *
     * @param string $_topic     topic exact, jokers déjà résolus
     * @param string $_payload   charge utile brute, non décodée
     * @param bool   $_retained  état rejoué par le broker (voir règle 3)
     * @param MqttbeDiscoveryContext $_ctx
     *
     * Rien n'est attendu en retour : ce qu'il y a à dire se dit par
     * $ctx->emit(), $ctx->publish() ou $ctx->remember().
     */
    public function onMessage($_topic, $_payload, $_retained, $_ctx);

    /*
     * Le battement de la découverte : au plus une fois par seconde, et
     * seulement tant que l'adapter est actif. C'est ici que se font les
     * relances, les expirations de candidats et les sondes différées.
     *
     * $ctx->rescan() y vaut true dans deux cas, et un seul traitement les
     * couvre tous les deux : l'adapter vient d'être activé, ou l'utilisateur
     * a demandé de relancer la découverte. C'est le moment de provoquer une
     * annonce générale — sans quoi un parc déjà connecté reste invisible.
     *
     *     public function onTick($_ctx) {
     *         if ($_ctx->rescan()) {
     *             $_ctx->publish('<le topic de commande du protocole>', 'announce');
     *         }
     *     }
     *
     * Le drapeau reste vrai pendant tout l'appel et retombe ensuite : le lire
     * deux fois donne deux fois la même réponse.
     */
    public function onTick($_ctx);
}
