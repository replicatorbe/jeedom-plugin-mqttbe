<?php
/* Les clés de configuration : celles qu'on lit et celles qu'on déclare.
 *
 *   php tests/check-config.php
 *
 * core/config/mqttbe.config.ini n'est pas de la documentation : c'est la source
 * des valeurs que config::byKey() rend tant que l'utilisateur n'a rien saisi.
 * Les deux écarts possibles sont silencieux tous les deux.
 *
 * Une clé lue sans défaut déclaré prend la valeur du troisième argument de
 * l'appel — ou la chaîne vide s'il est absent. Le réglage existe alors en
 * plusieurs exemplaires, un par appel, et changer le fichier ini n'y fait rien :
 * on croit régler le plugin, on ne règle rien.
 *
 * Une clé déclarée que personne ne lit est une promesse creuse : elle apparaît
 * dans le fichier, l'utilisateur la modifie, et il ne se passe strictement
 * rien — c'est typiquement ce que devient une clé après un renommage. */

require_once __DIR__ . '/outils.php';

/* Les appels config::byKey(<clé>, <plugin>) du source, relevés au tokenizer :
 * un grep confondrait cache::byKey(), qui n'a rien à voir avec ce fichier. */
function mqttbeAppelsByKey($_code) {
    $tokens = token_get_all($_code);
    $nombre = count($tokens);
    $appels = array();

    for ($i = 0; $i < $nombre; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING
            || strtolower($tokens[$i][1]) !== 'config') {
            continue;
        }
        $suite = array();
        for ($j = $i + 1; $j < $nombre && count($suite) < 2; $j++) {
            if (!mqttbeEstBlanc($tokens[$j])) {
                $suite[] = $j;
            }
        }
        if (count($suite) < 2) {
            continue;
        }
        $deuxPoints = $tokens[$suite[0]];
        $methode = $tokens[$suite[1]];
        if (!is_array($deuxPoints) || $deuxPoints[0] !== T_DOUBLE_COLON
            || !is_array($methode) || strtolower($methode[1]) !== 'bykey') {
            continue;
        }

        /* Découpage des arguments : seule la profondeur des parenthèses et des
         * crochets compte, pour ne pas couper sur la virgule d'un appel
         * imbriqué comme byKey('x', 'mqttbe', max(1, $n)). */
        $arguments = array();
        $courant = array();
        $profondeur = 0;
        for ($j = $suite[1] + 1; $j < $nombre; $j++) {
            $token = $tokens[$j];
            if (!is_array($token)) {
                if ($token === '(' || $token === '[') {
                    $profondeur++;
                    if ($profondeur === 1) {
                        continue;
                    }
                } elseif ($token === ')' || $token === ']') {
                    $profondeur--;
                    if ($profondeur === 0) {
                        $arguments[] = $courant;
                        break;
                    }
                } elseif ($token === ',' && $profondeur === 1) {
                    $arguments[] = $courant;
                    $courant = array();
                    continue;
                }
            }
            if ($profondeur >= 1 && !mqttbeEstBlanc($token)) {
                $courant[] = $token;
            }
        }

        $litteral = function ($_argument) {
            if (count($_argument) !== 1 || !is_array($_argument[0])
                || $_argument[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            return stripslashes(substr($_argument[0][1], 1, -1));
        };
        $appels[] = array(
            'cle'    => isset($arguments[0]) ? $litteral($arguments[0]) : null,
            'plugin' => isset($arguments[1]) ? $litteral($arguments[1]) : null,
            'ligne'  => $tokens[$i][2],
        );
    }
    return $appels;
}

/* Une clé est « employée » dès qu'elle apparaît quelque part dans le plugin :
 * un config::byKey(), mais aussi un data-l1key du formulaire de configuration
 * ou un appel côté JavaScript. Le contrôle des clés mortes ne doit dénoncer que
 * les clés dont plus rien ne parle. */
function mqttbeSourcesCompletes() {
    $fichiers = array();
    $parcours = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(mqttbeRacine(), FilesystemIterator::SKIP_DOTS));
    foreach ($parcours as $entree) {
        if (!$entree->isFile() || mqttbeEstExclu($entree->getPathname())) {
            continue;
        }
        /* Les .md sont volontairement absents : une clé citée dans la
         * documentation n'est pas une clé lue, et les y chercher ferait passer
         * pour vivante toute clé renommée dont l'ancien nom traîne encore dans
         * un document d'architecture. */
        if (!in_array(strtolower($entree->getExtension()),
                      array('php', 'js', 'html', 'json'), true)) {
            continue;
        }
        $fichiers[] = $entree->getPathname();
    }
    return $fichiers;
}

function mqttbeControlesConfig() {
    $resultats = array();
    $ini = mqttbeRacine() . '/core/config/mqttbe.config.ini';
    $sources = mqttbeFichiersPhp();

    if (!is_readable($ini) || empty($sources)) {
        $manquant = !is_readable($ini)
            ? 'core/config/mqttbe.config.ini n\'existe pas encore.'
            : 'aucun fichier PHP à analyser pour l\'instant.';
        return array(mqttbeIndecis('clés lues et clés déclarées', $manquant));
    }

    $sections = mqttbeLitIni($ini);
    $declarees = isset($sections['mqttbe']) ? array_keys($sections['mqttbe']) : array();

    $lues = array();
    $calculees = array();
    foreach ($sources as $source) {
        foreach (mqttbeAppelsByKey(file_get_contents($source)) as $appel) {
            /* Les clés d'un autre plugin ou du cœur (plugin absent) ne relèvent
             * pas de ce fichier ini. */
            if ($appel['plugin'] !== 'mqttbe') {
                continue;
            }
            if ($appel['cle'] === null) {
                $calculees[] = mqttbeRelatif($source) . ':' . $appel['ligne'];
                continue;
            }
            if (!isset($lues[$appel['cle']])) {
                $lues[$appel['cle']] = array();
            }
            $lues[$appel['cle']][] = mqttbeRelatif($source) . ':' . $appel['ligne'];
        }
    }

    /* ------------------------------------------------------------------ 1 ---
     * Toute clé lue doit avoir son défaut dans le fichier ini. */
    $titre = 'toute clé lue a un défaut dans le fichier ini';
    if (empty($lues)) {
        $resultats[] = mqttbeIndecis($titre, 'aucun config::byKey(…, \'mqttbe\') dans le code pour l\'instant.');
    } else {
        $fautes = array();
        foreach ($lues as $cle => $endroits) {
            if (!in_array($cle, $declarees, true)) {
                $fautes[] = $cle . ' — lue en ' . implode(', ', $endroits);
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            'ces clés n\'ont pas de valeur par défaut : chaque appel impose la sienne, le '
            . "fichier ini n'a plus prise sur elles, et un appel qui oublie son troisième argument rend la chaîne vide.\n"
            . implode("\n", $fautes));
    }

    /* ------------------------------------------------------------------ 2 ---
     * Et réciproquement : aucune clé déclarée ne doit être morte. */
    $titre = 'aucune clé morte dans le fichier ini';
    if (empty($declarees)) {
        $resultats[] = mqttbeIndecis($titre, 'la section [mqttbe] est vide ou absente.');
    } else {
        $textes = array();
        foreach (mqttbeSourcesCompletes() as $fichier) {
            $textes[] = file_get_contents($fichier);
        }
        $fautes = array();
        foreach ($declarees as $cle) {
            $employee = false;
            foreach ($textes as $texte) {
                if (strpos($texte, $cle) !== false) {
                    $employee = true;
                    break;
                }
            }
            if (!$employee) {
                $fautes[] = $cle;
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            'ces clés ne sont lues nulle part : le fichier promet un réglage qui ne produit '
            . "aucun effet — reste d'un renommage, la plupart du temps.\n" . implode("\n", $fautes));
    }

    /* Une clé construite à l'exécution ne peut pas être confrontée au fichier
     * ini : on le dit, plutôt que de laisser croire que tout a été vérifié. */
    if (!empty($calculees)) {
        $resultats[] = mqttbeIndecis('clés lues toutes littérales',
            count($calculees) . ' appel(s) à clé calculée, hors de portée de ce contrôle : '
            . implode(', ', $calculees));
    }

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Clés de configuration', mqttbeControlesConfig());
}
