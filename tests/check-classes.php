<?php
/* Conformité des classes du plugin aux conventions du cœur de Jeedom.
 *
 *   php tests/check-classes.php
 *
 * Ces pièges ont ceci de commun qu'ils sont invisibles à la relecture,
 * invisibles à « php -l » et invisibles au jeu d'essai fonctionnel : ils ne se
 * manifestent que dans un vrai Jeedom, et leur symptôme ne ressemble pas à leur
 * cause — un bouton « Ajouter » qui ne produit rien, une saisie qui disparaît,
 * un fichier de configuration inerte. Ils se lisent en revanche dans le texte
 * des sources, sans Jeedom, sans base et sans broker.
 *
 * Voir /home/smug/dev/STRUCTURE-PLUGIN-JEEDOM.md, section 8. */

require_once __DIR__ . '/outils.php';

/* Les clés qu'une page d'équipement envoie à l'enregistrement. utils::a2o()
 * construit « set » + clé et l'appelle sur l'objet du plugin : une méthode
 * portant l'un de ces noms sera donc appelée par le cœur, avec des arguments
 * qu'elle n'attend pas. */
function mqttbeMethodesInterdites() {
    return array('setId', 'setName', 'setLogicalId', 'setGeneric_type', 'setObject_id',
                 'setEqType_name', 'setIsVisible', 'setIsEnable', 'setConfiguration',
                 'setTimeout', 'setCategory', 'setDisplay', 'setOrder', 'setComment',
                 'setTags', 'setCmd');
}

/* Une classe est concernée si elle descend d'eqLogic ou de cmd, directement ou
 * par une classe intermédiaire du plugin : c'est l'héritage qui la fait passer
 * par DB::save() et par utils::a2o(). */
function mqttbeDescendDeEqLogicOuCmd($_nom, $_parents) {
    $vus = array();
    $courant = $_nom;
    while ($courant !== null && !isset($vus[strtolower($courant)])) {
        $vus[strtolower($courant)] = true;
        $parent = isset($_parents[strtolower($courant)]) ? $_parents[strtolower($courant)] : null;
        if ($parent === null) {
            return false;
        }
        if (in_array(strtolower($parent), array('eqlogic', 'cmd'), true)) {
            return true;
        }
        $courant = $parent;
    }
    return false;
}

function mqttbeControlesClasses() {
    $resultats = array();

    /* ---------------------------------------------------------------------
     * Lecture des classes du plugin. Les sources sont en cours d'écriture :
     * tant qu'elles n'existent pas, les trois premiers contrôles ne prouvent
     * rien — ils ne doivent donc ni réussir ni échouer.
     * ------------------------------------------------------------------- */
    $fichiers = mqttbeClassesDuPlugin();
    $classes = array();
    $parents = array();
    foreach ($fichiers as $fichier) {
        foreach (mqttbeAnalyseClasses(file_get_contents($fichier)) as $classe) {
            $classe['fichier'] = mqttbeRelatif($fichier);
            $classes[] = $classe;
            $parents[strtolower($classe['nom'])] = $classe['parent'];
        }
    }
    $concernees = array();
    foreach ($classes as $classe) {
        if (mqttbeDescendDeEqLogicOuCmd($classe['nom'], $parents)) {
            $concernees[] = $classe;
        }
    }

    /* ------------------------------------------------------------------ 1 ---
     * Toute propriété d'une classe eqLogic ou cmd doit commencer par un
     * souligné. DB::save() parcourt les propriétés de l'objet et traite comme
     * une colonne de la table toute propriété dont le nom ne commence pas par
     * « _ » (core/class/DB.class.php : if ('_' !== $name[0])). */
    $titre = 'propriétés préfixées par un souligné';
    if (empty($concernees)) {
        $resultats[] = mqttbeIndecis($titre, empty($fichiers)
            ? 'core/class/*.class.php n\'existe pas encore.'
            : 'aucune classe étendant eqLogic ou cmd n\'est encore déclarée.');
    } else {
        $fautes = array();
        foreach ($concernees as $classe) {
            foreach ($classe['proprietes'] as $propriete) {
                if (strpos($propriete['nom'], '_') !== 0) {
                    $fautes[] = $classe['fichier'] . ':' . $propriete['ligne'] . ' — '
                        . $classe['nom'] . '::$' . $propriete['nom'];
                }
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            'DB::save() prendra ces propriétés pour des colonnes de la table : la création '
            . 'd\'un équipement échouera sur « Unknown column », sans un mot dans le journal '
            . "du plugin.\n" . implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 2 ---
     * Aucune méthode ne doit porter le nom « set » + clé de formulaire. À
     * l'enregistrement, utils::a2o() appelle « set » + clé pour chaque clé
     * reçue, et la page envoie toujours une clé « cmd ». */
    $titre = 'aucune méthode « set » + clé de formulaire';
    if (empty($concernees)) {
        $resultats[] = mqttbeIndecis($titre, empty($fichiers)
            ? 'core/class/*.class.php n\'existe pas encore.'
            : 'aucune classe étendant eqLogic ou cmd n\'est encore déclarée.');
    } else {
        $interdites = array();
        foreach (mqttbeMethodesInterdites() as $nom) {
            $interdites[strtolower($nom)] = $nom;
        }
        $fautes = array();
        foreach ($concernees as $classe) {
            foreach ($classe['methodes'] as $methode) {
                $cle = strtolower($methode['nom']);
                if (isset($interdites[$cle])) {
                    $fautes[] = $classe['fichier'] . ':' . $methode['ligne'] . ' — '
                        . $classe['nom'] . '::' . $methode['nom'] . '()';
                }
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            'utils::a2o() appellera ces méthodes à chaque enregistrement, avec les données du '
            . 'formulaire : la sauvegarde meurt avant toute écriture, la page se rafraîchit et '
            . "la saisie disparaît (trace dans /var/www/html/log/http.error, pas dans le journal du plugin).\n"
            . implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 3 ---
     * La classe de commande est obligatoire, même vide : core/ajax/eqLogic.ajax.php
     * refuse de créer ou d'ouvrir un équipement si elle manque. */
    $titre = 'classe mqttbeCmd présente';
    if (empty($fichiers)) {
        $resultats[] = mqttbeIndecis($titre, 'core/class/*.class.php n\'existe pas encore.');
    } else {
        $trouvee = null;
        foreach ($classes as $classe) {
            if (strtolower($classe['nom']) === 'mqttbecmd') {
                $trouvee = $classe;
            }
        }
        if ($trouvee === null) {
            $resultats[] = mqttbeEchec($titre,
                'sans mqttbeCmd, la sauvegarde d\'un équipement échoue et la page de '
                . 'l\'équipement ne s\'ouvre pas.');
        } elseif ($trouvee['parent'] === null || strtolower($trouvee['parent']) !== 'cmd') {
            $resultats[] = mqttbeEchec($titre,
                'mqttbeCmd doit étendre cmd (elle étend ' . var_export($trouvee['parent'], true)
                . ') : le cœur instancie la classe de commande en attendant un objet cmd.');
        } else {
            $resultats[] = mqttbeOk($titre);
        }
    }

    /* ------------------------------------------------------------------ 4 ---
     * catch (Exception) n'attrape pas les Error de PHP 8. Un appel à une
     * méthode inexistante, un argument de mauvais type, une division par zéro
     * traversent alors le try et sortent en HTTP 500 muet — le démon, lui,
     * s'arrête sans rien dire. */
    $titre = 'catch (Throwable) et jamais catch (Exception) seul';
    $sources = mqttbeFichiersPhp();
    if (empty($sources)) {
        $resultats[] = mqttbeIndecis($titre, 'aucun fichier PHP dans le dépôt pour l\'instant.');
    } else {
        $fautes = array();
        foreach ($sources as $source) {
            foreach (mqttbeCatchs(file_get_contents($source)) as $catch) {
                $types = array_map('strtolower', $catch['types']);
                if (in_array('exception', $types, true) && !in_array('throwable', $types, true)) {
                    $fautes[] = mqttbeRelatif($source) . ':' . $catch['ligne']
                        . ' — catch (' . implode(' | ', $catch['types']) . ')';
                }
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            'catch (Exception) laisse passer les Error de PHP 8 : une méthode inexistante '
            . "produit un HTTP 500 muet côté Jeedom, et l'arrêt silencieux du démon.\n"
            . implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 5 ---
     * config::byKey() cherche les valeurs par défaut dans la section portant
     * l'identifiant du plugin. Nommée [default], la section est simplement
     * ignorée et le fichier entier devient inerte. */
    $titre = 'section [mqttbe] dans core/config/mqttbe.config.ini';
    $ini = mqttbeRacine() . '/core/config/mqttbe.config.ini';
    if (!is_readable($ini)) {
        $resultats[] = mqttbeIndecis($titre, 'core/config/mqttbe.config.ini n\'existe pas encore.');
    } else {
        $sections = mqttbeLitIni($ini);
        $nommees = array_keys($sections);
        if (!isset($sections['mqttbe'])) {
            $resultats[] = mqttbeEchec($titre,
                'section(s) trouvée(s) : ' . (empty($nommees) ? 'aucune' : implode(', ', $nommees))
                . '. Sans section [mqttbe], le fichier est totalement inerte : chaque '
                . 'config::byKey() retombe sur son défaut codé en dur, et les valeurs écrites '
                . 'ici ne s\'appliquent jamais.');
        } elseif (in_array('default', $nommees, true)) {
            $resultats[] = mqttbeEchec($titre,
                'une section [default] subsiste : ses clés sont inertes, alors que le fichier '
                . 'donne l\'impression de les définir.');
        } else {
            $resultats[] = mqttbeOk($titre);
        }
    }

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Conformité aux conventions du cœur Jeedom', mqttbeControlesClasses());
}
