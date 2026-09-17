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

/*
 * Table de routage « topic -> commande », calculée par Jeedom, appliquée par le démon.
 *
 * C'est ici que se joue la différence avec les passerelles MQTT existantes.
 * Ailleurs, chaque message reçu déclenche dans le processus web une boucle sur
 * tous les équipements du broker et une comparaison de topics en PHP : le coût
 * croît avec le parc, et jMQTT s'alarme dans son propre journal au-delà de
 * 300 ms pour un seul message. Ici la correspondance est calculée une fois,
 * poussée au démon, et Jeedom ne reçoit plus que des couples (identifiant de
 * commande, valeur) déjà résolus.
 *
 * La contrepartie est que cette table porte toute la vérité : ce qui n'y figure
 * pas n'existe pas pour le démon, qui en déduit jusqu'à ses abonnements. D'où
 * les deux exigences qui gouvernent ce fichier — elle doit être complète, et
 * elle doit être déterministe, sans quoi la comparaison d'empreinte qui évite
 * les envois inutiles ne compare rien.
 */
class mqttbeRouting {

    /* Empreinte de la dernière table effectivement reçue par le démon, et
     * numéro de version déjà attribué. Dans le cache et non en configuration :
     * c'est un état d'exécution, pas un réglage, et plusieurs processus Apache
     * le lisent en même temps. */
    const CACHE_HASH    = 'mqttbe::routingHash';
    /*
     * La version vit dans la CONFIGURATION et non dans le cache.
     *
     * Un cache peut être vidé — mise à jour de Jeedom, nettoyage, redémarrage
     * de la machine — et la version repartait alors en arrière. Comme le démon
     * refuse toute version inférieure ou égale à celle qu'il applique, il
     * ignorait ensuite toutes les tables, en silence, jusqu'à son propre
     * redémarrage : l'installation devenait muette sans que rien ne le signale.
     * La configuration, elle, survit et part dans les sauvegardes.
     */
    const CONF_VERSION  = 'routing::version';

    const REPEAT_ONCHANGE = 'onchange';
    const REPEAT_ALWAYS   = 'always';

    /*
     * Défauts du contrat. Le keepalive mérite son explication : en mode
     * « onchange », un capteur parfaitement stable — une sonde de température
     * dans une cave, un contact de porte jamais ouverte — ne produit plus aucun
     * événement, et son champ « dernière communication » vieillit
     * indéfiniment. L'utilisateur y lit une panne là où il n'y a qu'une valeur
     * constante, et les scénarios de surveillance d'équipement se déclenchent
     * pour rien. Réémettre la valeur inchangée toutes les 300 s coûte un
     * événement par commande et par cinq minutes, et rend la colonne honnête.
     */
    const KEEPALIVE     = 300;
    const MIN_INTERVAL  = 0;

    /* ------------------------------------------------------------ fabrication */

    /**
     * Construit la table complète à partir des équipements mqttbe actifs.
     *
     * Rend une liste d'entrées telles que le contrat les décrit :
     *   [{"topic": "...", "targets": [{"cmdId": 42, "selector": {...},
     *                                  "transform": {...}, "repeat": {...}}]}]
     *
     * Plusieurs commandes peuvent viser le même topic — c'est même le cas
     * courant, un JSON dont on extrait cinq valeurs. Elles sont regroupées sous
     * une seule entrée : le démon n'analysera la charge utile qu'une fois, quel
     * que soit le nombre de commandes qui en dépendent.
     */
    public static function build() {
        $parTopic = array();

        foreach (eqLogic::byType('mqttbe', true) as $eqLogic) {
            /* Un équipement mal configuré ne doit pas emporter toute la table :
             * sans ce filet, une seule commande fautive priverait de routage
             * l'ensemble du parc, et le symptôme ne désignerait pas sa cause. */
            try {
                $cmds = cmd::byEqLogicId($eqLogic->getId(), 'info');
            } catch (Throwable $e) {
                mqttbe::logger('error', sprintf(
                    __('Routage : commandes illisibles sur %1$s (%2$s)', __FILE__),
                    $eqLogic->getHumanName(), $e->getMessage()
                ));
                continue;
            }

            foreach ($cmds as $cmd) {
                try {
                    $cible = self::target($cmd);
                } catch (Throwable $e) {
                    mqttbe::logger('error', sprintf(
                        __('Routage : commande %1$s ignorée (%2$s)', __FILE__),
                        $cmd->getHumanName(), $e->getMessage()
                    ));
                    continue;
                }
                if ($cible === null) {
                    continue;
                }
                /* Le topic sert de clé de regroupement puis disparaît de la
                 * cible : il est déjà porté par l'entrée. */
                $topic = $cible['topic'];
                unset($cible['topic']);
                if (!isset($parTopic[$topic])) {
                    $parTopic[$topic] = array();
                }
                $parTopic[$topic][] = $cible;
            }
        }

        /*
         * Déterminisme. L'ordre dans lequel la base rend les équipements n'est
         * garanti par rien : deux constructions successives sans changement
         * produiraient deux JSON différents, et l'empreinte cesserait de dire
         * quoi que ce soit. Le tri par topic puis par identifiant de commande
         * fixe l'ordre, et l'ordre des clés est fixé par la construction des
         * tableaux associatifs, que json_encode respecte.
         */
        ksort($parTopic, SORT_STRING);

        $entrees = array();
        foreach ($parTopic as $topic => $cibles) {
            usort($cibles, function ($_a, $_b) {
                return $_a['cmdId'] < $_b['cmdId'] ? -1 : ($_a['cmdId'] > $_b['cmdId'] ? 1 : 0);
            });
            $entrees[] = array(
                /* PHP transforme en entier une clé de tableau qui ressemble à un
                 * nombre : un topic « 0 » sortirait du JSON sans ses guillemets. */
                'topic'   => (string) $topic,
                'targets' => $cibles,
            );
        }
        return $entrees;
    }

    /**
     * Traduit une commande d'information en cible de routage, ou null si elle
     * n'a rien à router.
     *
     * Publique parce qu'elle répond à la question qu'on se pose devant une
     * commande muette : « qu'est-ce que le démon a reçu pour celle-ci ? ».
     * Le topic figure dans le résultat ; build() l'en retire après regroupement.
     */
    public static function target($_cmd) {
        if (!is_object($_cmd) || $_cmd->getType() != 'info') {
            return null;
        }
        /* Le coeur ne donne pas d'interrupteur aux commandes — seuls les
         * équipements ont isEnable, et byType() les a déjà filtrés. On respecte
         * tout de même un « enable » posé sur la commande, pour que couper une
         * seule valeur bavarde n'oblige pas à supprimer la commande. */
        if ($_cmd->getConfiguration('enable', 1) == 0) {
            return null;
        }
        $topic = trim((string) $_cmd->getConfiguration('topic', ''));
        if ($topic === '') {
            /* Cas parfaitement normal : une commande d'information calculée, ou
             * une commande en cours de saisie. Rien à signaler. */
            return null;
        }
        return array(
            'topic'     => $topic,
            'cmdId'     => (int) $_cmd->getId(),
            'selector'  => self::selector($_cmd),
            'transform' => self::transform($_cmd),
            'repeat'    => self::repeat($_cmd),
        );
    }

    /**
     * Ce qu'il faut extraire de la charge utile.
     *
     * Sans chemin, la charge utile est la valeur : c'est le cas des topics
     * feuille, de loin le plus fréquent (« shellies/…/relay/0/power » porte un
     * nombre et rien d'autre).
     */
    private static function selector($_cmd) {
        $path = trim((string) $_cmd->getConfiguration('path', ''));
        if ($path === '') {
            return array('type' => 'raw');
        }
        return array('type' => 'json', 'path' => $path);
    }

    /**
     * Transformations, dans l'ordre imposé par le contrat : map, scale, offset,
     * round.
     *
     * Seul ce qui agit est émis. Une échelle de 1 ou un décalage de 0 sont des
     * opérations neutres : les transmettre ferait payer au démon un calcul par
     * message pour ne rien changer, et brouillerait la lecture de la table.
     */
    private static function transform($_cmd) {
        $transform = array();

        $map = self::map($_cmd);
        if ($map !== null) {
            $transform['map'] = $map;
        }

        $scale = self::number($_cmd->getConfiguration('scale', ''));
        if ($scale !== null && $scale != 1) {
            if ($scale == 0) {
                /* Un champ vide rend null et n'arrive pas ici : ce zéro a donc
                 * été tapé. On le respecte — mais il écrase toute valeur reçue,
                 * et découvrir cela sur un graphique plat coûte une soirée. */
                log::add('mqttbe', 'warning', sprintf(
                    __('Échelle nulle sur la commande %s : toutes ses valeurs seront ramenées à zéro', __FILE__),
                    $_cmd->getHumanName()
                ));
            }
            $transform['scale'] = $scale;
        }

        $offset = self::number($_cmd->getConfiguration('offset', ''));
        if ($offset !== null && $offset != 0) {
            $transform['offset'] = $offset;
        }

        /* « 0 décimale » est une consigne, pas une absence de consigne : on
         * distingue donc la chaîne vide du zéro, ce que self::number() ne
         * permettrait pas ici. */
        $round = $_cmd->getConfiguration('round', '');
        if (!is_array($round) && !is_object($round) && is_numeric(trim((string) $round))) {
            $transform['round'] = (int) trim((string) $round);
        }

        /* Un tableau PHP vide sort du JSON en « [] », un objet vide en « {} ».
         * Le champ doit garder la même forme qu'il soit rempli ou non, sinon un
         * démon qui décode en objets voit tantôt un objet, tantôt un tableau,
         * et la table n'est plus relisible d'une façon unique. */
        return empty($transform) ? new stdClass() : $transform;
    }

    /**
     * Table de correspondance saisie par l'utilisateur, en JSON.
     *
     * C'est le seul réglage du contrat qui soit un langage : l'utilisateur y
     * écrit lui-même une syntaxe, et se trompera donc de temps en temps. Une
     * accolade oubliée ne doit pas priver de routage les deux cents autres
     * commandes de l'installation — on journalise la commande fautive, on
     * ignore la correspondance, et la commande continue d'être routée sans elle.
     */
    private static function map($_cmd) {
        $brut = $_cmd->getConfiguration('map', '');

        if (is_array($brut)) {
            /* Déjà décodée : selon le champ de formulaire employé, Jeedom rend
             * tantôt la chaîne saisie, tantôt le tableau correspondant. */
            $map = $brut;
        } else {
            $brut = trim((string) $brut);
            if ($brut === '' || $brut === '{}' || $brut === '[]') {
                return null;
            }
            $map = json_decode($brut, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                mqttbe::logger('warning', sprintf(
                    __('Routage : la table de correspondance de %1$s est un JSON invalide (%2$s). Elle est ignorée, la commande reste routée sans elle.', __FILE__),
                    $_cmd->getHumanName(), json_last_error_msg()
                ));
                return null;
            }
            if (!is_array($map)) {
                mqttbe::logger('warning', sprintf(
                    __('Routage : la table de correspondance de %s doit être un objet JSON du genre {"on":"1","off":"0"}. Elle est ignorée, la commande reste routée sans elle.', __FILE__),
                    $_cmd->getHumanName()
                ));
                return null;
            }
        }

        $sortie = array();
        foreach ($map as $cle => $valeur) {
            if (is_array($valeur) || is_object($valeur)) {
                mqttbe::logger('warning', sprintf(
                    __('Routage : la correspondance « %1$s » de %2$s ne donne pas une valeur simple, elle est ignorée', __FILE__),
                    $cle, $_cmd->getHumanName()
                ));
                continue;
            }
            /* La comparaison se fait sur la charge utile MQTT, qui est toujours
             * du texte : les deux côtés sont ramenés à des chaînes. Les booléens
             * JSON deviennent 1 et 0, ce qu'attend une commande binaire. */
            if (is_bool($valeur)) {
                $valeur = $valeur ? '1' : '0';
            }
            $sortie[(string) $cle] = (string) $valeur;
        }
        return empty($sortie) ? null : $sortie;
    }

    /**
     * Politique de réémission.
     *
     * Toujours présente et toujours complète : le démon n'a ainsi aucun défaut à
     * connaître, et changer l'un de ces défauts ne demande pas de le redéployer.
     */
    private static function repeat($_cmd) {
        $mode = strtolower(trim((string) $_cmd->getConfiguration('repeat', '')));
        if ($mode !== self::REPEAT_ALWAYS) {
            if ($mode !== '' && $mode !== self::REPEAT_ONCHANGE) {
                mqttbe::logger('debug', sprintf(
                    __('Routage : mode de répétition « %1$s » inconnu sur %2$s, « onchange » est appliqué', __FILE__),
                    $mode, $_cmd->getHumanName()
                ));
            }
            $mode = self::REPEAT_ONCHANGE;
        }

        $keepalive = self::number($_cmd->getConfiguration('keepalive', ''));
        if ($keepalive === null) {
            $keepalive = self::KEEPALIVE;
        }
        $minInterval = self::number($_cmd->getConfiguration('minInterval', ''));
        if ($minInterval === null) {
            $minInterval = self::MIN_INTERVAL;
        }

        return array(
            'mode'        => $mode,
            /* Un keepalive négatif n'a pas de sens ; 0 en revanche en a un, il
             * désactive la réémission. */
            'keepalive'   => max(0, (int) $keepalive),
            'minInterval' => max(0, $minInterval),
        );
    }

    /**
     * Nombre normalisé, ou null si le réglage est vide ou illisible.
     *
     * Le retour est entier chaque fois que la valeur en est un : sans cela, le
     * JSON porterait 0.0 là où le contrat écrit 0, et deux tables identiques
     * auraient deux empreintes différentes selon la façon dont l'utilisateur a
     * saisi le nombre.
     */
    private static function number($_valeur) {
        if (is_array($_valeur) || is_object($_valeur) || is_bool($_valeur)) {
            return null;
        }
        $valeur = trim((string) $_valeur);
        if ($valeur === '' || !is_numeric($valeur)) {
            return null;
        }
        $nombre = (float) $valeur;
        return ($nombre == (int) $nombre) ? (int) $nombre : $nombre;
    }

    /* ------------------------------------------------------------- expédition */

    /**
     * Pousse la table au démon.
     *
     * Rend true si un envoi a eu lieu. L'empreinte en cache évite l'aller-retour
     * inutile : enregistrer un équipement ne change le plus souvent rien au
     * routage — un nom, une icône, une catégorie — et le démon n'a aucune raison
     * de réindexer son arbre de topics pour cela. $_force passe outre, et c'est
     * ce que doit faire tout appelant qui sait que le démon a perdu la table :
     * un démarrage de démon, par exemple, où le cache dit « déjà envoyée » alors
     * que le processus qui l'avait reçue n'existe plus.
     */
    public static function push($_force = false) {
        $entrees = self::build();

        $json = self::encode($entrees);
        if ($json === null) {
            mqttbe::logger('error', sprintf(
                __('Routage : table non sérialisable (%s), aucun envoi. Un topic contient sans doute des octets qui ne sont pas de l\'UTF-8.', __FILE__),
                json_last_error_msg()
            ));
            return false;
        }

        $empreinte = md5($json);

        /*
         * Tout ce qui suit — comparer l'empreinte, attribuer une version,
         * envoyer, mémoriser — doit être indivisible.
         *
         * Sans ce verrou, deux processus simultanés (le cron de la minute et la
         * fonction d'arrêt d'un enregistrement, ou deux lots `discovered`
         * traités par deux processus Apache) obtenaient le MÊME numéro de
         * version : le démon rejette l'égalité, et comme les deux ont mémorisé
         * leur empreinte, plus personne ne renvoie rien. Le routage restait
         * périmé jusqu'à la prochaine modification d'un équipement.
         */
        $verrou = self::lock();

        try {

        if (!$_force && $empreinte === self::cachedValue(self::CACHE_HASH, '')) {
            mqttbe::logger('debug', __('Routage : table inchangée, rien n\'est envoyé au démon', __FILE__));
            return false;
        }

        $version = self::nextVersion();
        $envoye = mqttbeDaemon::send(array(
            'cmd'     => 'routing',
            'version' => $version,
            'entries' => $entrees,
        ), false);

        if (!$envoye) {
            /*
             * L'empreinte n'est mémorisée que sur un envoi réussi, et remise à
             * zéro sur un échec. Sans cela, une table que le démon n'a jamais
             * reçue passerait pour appliquée : le prochain appel la trouverait
             * identique, s'abstiendrait, et le routage resterait mort jusqu'à ce
             * que quelqu'un modifie un équipement.
             */
            cache::set(self::CACHE_HASH, '');
            mqttbe::logger('debug', __('Routage : le démon n\'a pas reçu la table, elle sera renvoyée au prochain appel', __FILE__));
            return false;
        }

        cache::set(self::CACHE_HASH, $empreinte);
        config::save(self::CONF_VERSION, $version, 'mqttbe');
        mqttbe::logger('info', sprintf(
            __('Routage : table version %1$s envoyée au démon (%2$s topic(s), %3$s commande(s))', __FILE__),
            $version, count($entrees), self::countTargets($entrees)
        ));
        return true;

        } finally {
            self::unlock($verrou);
        }
    }

    /**
     * Verrou d'exclusion entre processus web.
     *
     * flock sur un fichier du dossier temporaire du plugin : c'est le seul
     * moyen simple de sérialiser deux processus Apache, le cache de Jeedom
     * n'offrant aucune opération atomique. Rend null si le verrou n'a pas pu
     * être posé — on préfère alors envoyer sans garantie plutôt que de ne rien
     * envoyer du tout.
     */
    private static function lock() {
        $chemin = jeedom::getTmpFolder('mqttbe') . '/routing.lock';
        $fichier = @fopen($chemin, 'c');
        if ($fichier === false) {
            return null;
        }
        if (!@flock($fichier, LOCK_EX)) {
            fclose($fichier);
            return null;
        }
        return $fichier;
    }

    private static function unlock($_verrou) {
        if ($_verrou === null) {
            return;
        }
        @flock($_verrou, LOCK_UN);
        fclose($_verrou);
    }

    /**
     * Oublie l'empreinte mémorisée.
     *
     * À appeler quand la table appliquée par le démon disparaît sans qu'aucun
     * équipement ne change — arrêt du démon, nettoyage après un démon mort. Le
     * prochain push repartira alors même si rien n'a bougé côté Jeedom.
     */
    public static function forget() {
        cache::set(self::CACHE_HASH, '');
    }

    /** Version de la dernière table envoyée, 0 si aucune. */
    public static function version() {
        return (int) config::byKey(self::CONF_VERSION, 'mqttbe', 0);
    }

    /**
     * Empreinte de la table courante, pour comparer sans envoyer.
     */
    public static function fingerprint($_entrees = null) {
        if ($_entrees === null) {
            $_entrees = self::build();
        }
        $json = self::encode($_entrees);
        return $json === null ? '' : md5($json);
    }

    /**
     * Numéro de version, strictement croissant.
     *
     * L'horodatage sert de plancher : les messages vers le démon peuvent se
     * doubler, et il ignore toute table plus ancienne que celle qu'il applique.
     * Un cache vidé — ce qui arrive à chaque mise à jour de Jeedom — repartirait
     * de 1, et le démon rejetterait alors silencieusement tout ce qu'on lui
     * envoie jusqu'à son prochain redémarrage. Avec time() pour plancher, la
     * suite ne peut pas régresser, quoi qu'il arrive au cache.
     */
    /**
     * Prochain numéro de version.
     *
     * N'écrit RIEN : la version n'est mémorisée qu'après un envoi réussi, sinon
     * `routingTable` afficherait un numéro que le démon n'a jamais reçu — celui
     * qu'on regarde justement quand on cherche pourquoi une commande est muette.
     * L'appel est protégé par le verrou de push().
     */
    private static function nextVersion() {
        /*
         * Le plancher horodaté ne suffit pas.
         *
         * `time()` ne progresse qu'une fois par seconde, alors que plusieurs
         * envois peuvent partir dans la même — une rafale de découverte en
         * produit un par requête. Le compteur passe alors devant l'horloge, et
         * si le cache disparaît ensuite (mise à jour de Jeedom, nettoyage), la
         * version repart EN ARRIÈRE : le démon, qui refuse toute version
         * inférieure ou égale à celle qu'il applique, ignore silencieusement
         * toutes les tables suivantes jusqu'à son propre redémarrage.
         *
         * La version que le démon applique réellement nous revient dans sa
         * réponse au battement : elle sert donc de troisième plancher, et
         * referme le seul cas où Jeedom pouvait parler dans le vide.
         */
        $applique = (int) self::cachedValue('mqttbe::daemonRouting', 0);
        return max(self::version() + 1, time(), $applique + 1);
    }

    /**
     * Encodage de référence, celui sur lequel l'empreinte est calculée.
     *
     * Les mêmes options à chaque appel, sinon deux tables identiques pourraient
     * ne pas produire le même texte. Rend null si l'encodage échoue.
     */
    private static function encode($_entrees) {
        $json = json_encode($_entrees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private static function countTargets($_entrees) {
        $total = 0;
        foreach ($_entrees as $entree) {
            $total += count($entree['targets']);
        }
        return $total;
    }

    private static function cachedValue($_key, $_default) {
        try {
            return cache::byKey($_key)->getValue($_default);
        } catch (Throwable $e) {
            /* Clé jamais écrite : c'est le cas au premier passage. */
            return $_default;
        }
    }
}
