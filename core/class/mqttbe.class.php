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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autochargeur de Jeedom ne sait résoudre que la classe portant le nom du
 * plugin : les classes annexes doivent être incluses explicitement. */
require_once __DIR__ . '/mqttbeDaemon.class.php';
require_once __DIR__ . '/mqttbeRouting.class.php';
require_once __DIR__ . '/mqttbeFactory.class.php';

class mqttbe extends eqLogic {

    /*
     * Un seul envoi de table de routage par requête HTTP.
     *
     * Enregistrer un équipement écrit l'eqLogic puis chacune de ses commandes,
     * chaque écriture passant par ici : sans ce garde-fou, sauvegarder un
     * équipement de quinze commandes enverrait seize tables au démon, dont
     * quinze aussitôt périmées.
     */
    private static $_routingScheduled = false;

    /* Toute propriété d'instance doit commencer par un souligné : DB::save()
     * traite les autres comme des colonnes de la table eqLogic et l'ajout d'un
     * équipement échouerait sur « Unknown column ». */

    /* ------------------------------------------------------ rappels du coeur */

    public static function deamon_info() {
        return mqttbeDaemon::info();
    }

    public static function deamon_start() {
        return mqttbeDaemon::start();
    }

    public static function deamon_stop() {
        return mqttbeDaemon::stop();
    }

    /**
     * Chien de garde, appelé toutes les minutes par le coeur.
     *
     * Le coeur relance lui-même un démon dont deamon_info() dit qu'il est
     * arrêté : le travail d'ici est de faire dire la vérité à deamon_info(),
     * c'est-à-dire de détecter un démon vivant mais muet.
     */
    public static function cron() {
        if (!mqttbeDaemon::check()) {
            return;
        }
        mqttbeDaemon::syncLogLevel();
        /* Filet de sécurité : si un envoi de table a échoué parce que le démon
         * redémarrait, la minute suivante le rattrape sans que personne n'ait
         * à s'en apercevoir. */
        mqttbeRouting::push();
        mqttbeDaemon::logLatency();
    }

    /**
     * Page Santé du plugin.
     *
     * Les trois lignes répondent aux trois questions qu'on se pose quand rien
     * ne remonte : le broker est-il joignable, le démon tourne-t-il, et
     * a-t-il parlé récemment ?
     */
    public static function health() {
        $host = trim(config::byKey('broker::host', 'mqttbe', ''));
        $port = (int) config::byKey('broker::port', 'mqttbe', 1883);

        $return = array();
        $return[] = array(
            'test'       => __('Broker configuré', __FILE__),
            'result'     => $host !== '' ? $host . ':' . $port : __('Non renseigné', __FILE__),
            'advice'     => $host !== '' ? '' : __("Renseignez l'adresse du broker dans la configuration du plugin", __FILE__),
            'state'      => $host !== '',
        );

        $daemonUp = mqttbeDaemon::state();
        $return[] = array(
            'test'   => __('Démon', __FILE__),
            'result' => $daemonUp ? __('En fonctionnement', __FILE__) : __('Arrêté', __FILE__),
            'advice' => $daemonUp ? '' : __('Démarrez le démon depuis la configuration du plugin', __FILE__),
            'state'  => $daemonUp,
        );

        $brokerOk = mqttbeDaemon::brokerState() == 'ok';
        $return[] = array(
            'test'   => __('Connexion au broker', __FILE__),
            'result' => $brokerOk ? __('Établie', __FILE__) : __('Absente', __FILE__),
            'advice' => $brokerOk ? '' : __('Consultez le journal mqttbed', __FILE__),
            'state'  => $brokerOk,
        );
        return $return;
    }

    /**
     * Journalisation du plugin.
     *
     * Passer par un point unique évite d'avoir à répéter le nom du journal, et
     * laissera plus tard la possibilité d'y ajouter un contexte sans toucher
     * aux cent appels répartis dans le code.
     */
    public static function logger($_level, $_message) {
        log::add('mqttbe', $_level, $_message);
    }

    /* --------------------------------------------------------- cycle eqLogic */

    /*
     * Rien n'est créé automatiquement avant le jalon 3 : ces méthodes sont
     * volontairement minimales. postSave() poussera la table de routage au
     * démon quand elle existera (jalon 2).
     */

    public function preSave() {
        /* Ne jamais lever d'exception ici : le coeur crée l'équipement avec son
         * seul nom, et une validation stricte rendrait le bouton « Ajouter »
         * définitivement inopérant. */
    }

    public function postSave() {
        self::scheduleRoutingPush();
    }

    public function postRemove() {
        self::scheduleRoutingPush();
    }

    /**
     * Demande l'envoi de la table de routage à la fin de la requête en cours.
     *
     * Différer jusqu'à la fin garantit que toutes les commandes sont écrites en
     * base quand la table est construite : l'ordre d'enregistrement du coeur est
     * l'eqLogic d'abord, ses commandes ensuite, et une table calculée trop tôt
     * ignorerait la commande qu'on vient justement de créer.
     */
    public static function scheduleRoutingPush() {
        if (self::$_routingScheduled) {
            return;
        }
        self::$_routingScheduled = true;
        register_shutdown_function(function () {
            try {
                mqttbeRouting::push();
            } catch (Throwable $e) {
                /* La requête est finie et la page déjà rendue : rien ne doit en
                 * sortir, mais l'incident ne doit pas disparaître pour autant. */
                log::add('mqttbe', 'error', __('Envoi de la table de routage :', __FILE__)
                       . ' ' . $e->getMessage());
            }
        });
    }
}

/*
 * La classe de commande est obligatoire, même réduite : sans elle,
 * l'enregistrement d'un équipement échoue.
 *
 * Attention : aucune méthode ne doit s'appeler set + une clé de formulaire, et
 * en particulier jamais setCmd(). utils::a2o() appelle « set » . ucfirst(clé)
 * pour chaque clé reçue, et la page envoie toujours une clé « cmd ».
 */
class mqttbeCmd extends cmd {

    /*
     * Une commande enregistrée ou supprimée change la table de routage : le
     * démon écoute peut-être un topic qui ne sert plus, ou pas encore celui
     * qu'on vient de saisir. L'envoi est différé à la fin de la requête, donc
     * quinze commandes enregistrées d'affilée n'en déclenchent qu'un seul.
     *
     * postRemove et non preRemove : un preRemove qui rend false annulerait la
     * suppression, et une table de routage n'a pas à décider de cela.
     */
    public function postSave() {
        mqttbe::scheduleRoutingPush();
    }

    public function postRemove() {
        mqttbe::scheduleRoutingPush();
    }

    /**
     * Exécution d'une commande d'action : publication d'un message MQTT.
     *
     * Le contenu publié vient de la configuration de la commande ; le démon se
     * charge de l'émission réelle. La chaîne #slider# est remplacée par la
     * valeur du curseur, comme le veut l'usage Jeedom.
     */
    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return null;
        }
        $topic = trim($this->getConfiguration('topic', ''));
        if ($topic === '') {
            throw new Exception(__('Aucun topic défini sur la commande', __FILE__) . ' ' . $this->getHumanName());
        }

        $payload = (string) $this->getConfiguration('payload', '');
        if (isset($_options['slider'])) {
            $payload = str_replace('#slider#', $_options['slider'], $payload);
        }
        if (isset($_options['color'])) {
            $payload = str_replace('#color#', $_options['color'], $payload);
        }
        if (isset($_options['message'])) {
            $payload = str_replace('#message#', $_options['message'], $payload);
        }

        mqttbeDaemon::publish(
            $topic,
            $payload,
            (int) $this->getConfiguration('qos', 0),
            $this->getConfiguration('retain', 0) == 1
        );
        return null;
    }
}
