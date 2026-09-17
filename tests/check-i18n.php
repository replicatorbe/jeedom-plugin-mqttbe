<?php
/* Les traductions : toutes celles qu'il faut, et rien qui trompe.
 *
 *   php tests/check-i18n.php
 *
 * Jeedom traduit de deux façons, et le plugin emploie les deux :
 *
 *   - `{{Texte français}}` dans les pages et le JS, remplacé à l'affichage ;
 *   - `__('Texte français', __FILE__)` dans le code PHP.
 *
 * La clé du fichier de langue est le texte français exact — mais pas sous la
 * même forme dans les deux cas, et c'est le piège : les `{{}}` sont relevés dans
 * le fichier tel quel, échappements compris, tandis que `__()` déséchappe les
 * apostrophes avant de chercher (voir mqttbeArgumentsTraduits() plus bas). Une
 * virgule ajoutée, un mot corrigé, une apostrophe écrite sous l'autre forme : la
 * clé ne correspond plus, la traduction n'est jamais trouvée, et l'interface
 * anglaise affiche du français. Rien ne le signale — ni erreur, ni journal — et
 * un développeur francophone ne le verra jamais, puisque sa propre langue est la
 * langue source.
 *
 * Deux écarts, de gravité très différente :
 *
 *   - une chaîne du source SANS traduction : l'utilisateur anglophone lit du
 *     français. C'est un échec ;
 *   - une traduction SANS chaîne dans le source : elle ne sert plus à rien, mais
 *     elle ne casse rien. C'est signalé, sans faire échouer — d'autant qu'une
 *     purge trop zélée a déjà emporté ici quatre traductions bien vivantes, dont
 *     le seul tort était d'être écrites avec un échappement différent. Le
 *     ménage se fait à la main, après vérification. */

require_once __DIR__ . '/outils.php';

/** Les fichiers de langue du plugin, par code de langue. */
function mqttbeLangues() {
    $langues = array();
    foreach (glob(mqttbeRacine() . '/core/i18n/*.json') as $fichier) {
        $langues[basename($fichier, '.json')] = $fichier;
    }
    return $langues;
}

/**
 * Les textes à traduire d'un fichier source.
 *
 * Les deux syntaxes sont cherchées dans le même fichier : rien n'interdit à une
 * page de contenir les deux, et c'est le cas.
 */
function mqttbeTextesATraduire($_chemin) {
    $source = file_get_contents($_chemin);
    $textes = array();

    /* Syntaxe des pages et du JS. Le texte est pris tel qu'écrit, échappements
     * compris : c'est ainsi que Jeedom le cherchera dans le fichier de langue,
     * puisqu'il travaille sur le fichier avant que PHP n'ait rien interprété. */
    if (preg_match_all('/\{\{(.*?)\}\}/s', $source, $trouves)) {
        foreach ($trouves[1] as $texte) {
            $textes[$texte] = true;
        }
    }

    /* Syntaxe du code PHP. Le premier argument de __() doit être une chaîne
     * littérale — une variable ne pourrait pas être extraite par l'outil de
     * traduction de Jeedom non plus. On passe par le lexeur de PHP plutôt que
     * par une expression régulière : une apostrophe dans le texte ferait
     * trébucher la seconde, et c'est précisément ce que ces textes contiennent. */
    if (substr($_chemin, -4) === '.php') {
        foreach (mqttbeArgumentsTraduits($source) as $texte) {
            $textes[$texte] = true;
        }
    }
    return array_keys($textes);
}

/**
 * Premiers arguments littéraux des appels à __().
 *
 * Les deux syntaxes ne cherchent PAS la même clé, et c'est le piège de tout ce
 * fichier. `__()` (core/class/translate.class.php) commence par
 * `str_replace("\\'", "'", $_content)` : la clé cherchée est donc le texte
 * apostrophe DÉSÉCHAPPÉE, « n'est » et non « n\\'est ». Les `{{}}`, eux, sont
 * relevés dans le fichier tel quel, avant que PHP n'ait rien interprété, et
 * gardent leur échappement.
 *
 * Écrire une clé sous la mauvaise forme ne casse rien de visible : la
 * traduction est simplement introuvable, et l'anglophone lit du français.
 */
function mqttbeArgumentsTraduits($_code) {
    $tokens = token_get_all($_code);
    $textes = array();
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $token = $tokens[$i];
        /* __ est lexé comme un nom de fonction ordinaire. */
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__') {
            continue;
        }
        /* Suit une parenthèse, puis la chaîne. Les blancs et commentaires entre
         * les deux sont sautés ; tout le reste — une variable, une concaténation
         * — signifie que l'appel n'est pas extractible, et on passe. */
        $j = $i + 1;
        while ($j < $n && mqttbeEstBlanc($tokens[$j])) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '(') {
            continue;
        }
        $j++;
        while ($j < $n && mqttbeEstBlanc($tokens[$j])) {
            $j++;
        }
        if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
            /* Le même déséchappement que __(), et lui seul : pas question
             * d'interpréter la chaîne plus largement que ne le fait le cœur. */
            $textes[] = str_replace("\\'", "'", substr($tokens[$j][1], 1, -1));
        }
    }
    return $textes;
}

/** Les sources susceptibles de contenir du texte à traduire. */
function mqttbeSourcesTraduisibles() {
    $chemins = array();
    foreach (array('core', 'desktop', 'plugin_info') as $dossier) {
        $racine = mqttbeRacine() . '/' . $dossier;
        if (!is_dir($racine)) {
            continue;
        }
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
        foreach ($iterateur as $fichier) {
            $chemin = $fichier->getPathname();
            if (!preg_match('/\.(php|js)$/', $chemin) || mqttbeEstExclu($chemin)) {
                continue;
            }
            $chemins[] = $chemin;
        }
    }
    sort($chemins);
    return $chemins;
}

function mqttbeControlesI18n() {
    $langues = mqttbeLangues();
    if (empty($langues)) {
        return array(mqttbeIndecis('traductions', "core/i18n/ ne contient aucun fichier de langue."));
    }

    $resultats = array();
    $sources = mqttbeSourcesTraduisibles();

    foreach ($langues as $langue => $fichier) {
        $brut = json_decode(file_get_contents($fichier), true);
        if (!is_array($brut)) {
            $resultats[] = mqttbeEchec('fichier de langue ' . $langue . ' lisible',
                $fichier . " n'est pas un JSON valide : Jeedom n'affichera aucune "
                . 'traduction, et ne le dira pas.');
            continue;
        }
        $resultats[] = mqttbeOk('fichier de langue ' . $langue . ' lisible');

        /* ------------------------------------------------------------ 1 ---
         * Chaque texte du source a sa traduction. */
        $manquantes = array();
        $compte = 0;
        foreach ($sources as $chemin) {
            $textes = mqttbeTextesATraduire($chemin);
            if (empty($textes)) {
                continue;
            }
            $compte += count($textes);
            $cle = 'plugins/mqttbe/' . mqttbeRelatif($chemin);
            $traduits = isset($brut[$cle]) && is_array($brut[$cle]) ? $brut[$cle] : array();
            foreach ($textes as $texte) {
                if (!array_key_exists($texte, $traduits)) {
                    $manquantes[] = mqttbeRelatif($chemin) . ' : « '
                        . mqttbeExtrait($texte) . ' »';
                }
            }
        }
        $titre = 'traductions ' . $langue . ' complètes (' . $compte . ' textes)';
        if (empty($manquantes)) {
            $resultats[] = mqttbeOk($titre);
        } else {
            $resultats[] = mqttbeEchec($titre,
                count($manquantes) . " texte(s) sans traduction : l'interface les affichera "
                . "en français à un utilisateur qui a choisi l'anglais, sans qu'aucune erreur "
                . "ne le signale.\n" . implode("\n", array_slice($manquantes, 0, 25))
                . (count($manquantes) > 25 ? "\n… et " . (count($manquantes) - 25) . ' autre(s).' : ''));
        }

        /* ------------------------------------------------------------ 2 ---
         * Les traductions qui ne correspondent plus à rien.
         *
         * Signalées, jamais fatales : une traduction orpheline n'abîme rien, et
         * la supprimer sur la foi d'une comparaison automatique a déjà emporté
         * ici quatre traductions vivantes dont le texte source ne différait que
         * par un échappement. Le doute profite à la traduction. */
        $orphelines = array();
        foreach ($brut as $cle => $traduits) {
            $chemin = mqttbeRacine() . '/' . preg_replace('#^plugins/mqttbe/#', '', $cle);
            if (!is_readable($chemin)) {
                $orphelines[] = $cle . ' : le fichier lui-même n\'existe plus.';
                continue;
            }
            $textes = array_flip(mqttbeTextesATraduire($chemin));
            $source = file_get_contents($chemin);
            foreach ($traduits as $texte => $traduction) {
                /* Deux filets : la correspondance exacte, et la simple présence
                 * du texte dans le fichier. Le second rattrape les formes que
                 * l'extraction ne sait pas reconnaître, et c'est lui qui évite
                 * de déclarer morte une traduction qui ne l'est pas. */
                if (!isset($textes[$texte]) && strpos($source, $texte) === false) {
                    $orphelines[] = preg_replace('#^plugins/mqttbe/#', '', $cle)
                        . ' : « ' . mqttbeExtrait($texte) . ' »';
                }
            }
        }
        $titre = 'traductions ' . $langue . ' sans texte source';
        $resultats[] = empty($orphelines) ? mqttbeOk($titre)
            : mqttbeIndecis($titre, count($orphelines) . " traduction(s) ne correspondent plus "
                . "à aucun texte du source. Sans gravité — elles ne sont jamais consultées — mais "
                . "à retirer après vérification à la main.\n"
                . implode("\n", array_slice($orphelines, 0, 15))
                . (count($orphelines) > 15 ? "\n… et " . (count($orphelines) - 15) . ' autre(s).' : ''));
    }

    return $resultats;
}

/** Un texte long réduit à ce qu'il faut pour le retrouver. */
function mqttbeExtrait($_texte) {
    $texte = preg_replace('/\s+/u', ' ', trim($_texte));
    return mb_strlen($texte) > 70 ? mb_substr($texte, 0, 70) . '…' : $texte;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Traductions', mqttbeControlesI18n());
}
