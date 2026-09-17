<?php
/* Boîte à outils commune aux contrôles hors ligne du plugin mqttbe.
 *
 * Ces contrôles tournent sans Jeedom, sans base de données et sans broker :
 * ni core.inc.php, ni eqLogic, ni réflexion. Tout ce qu'ils savent, ils le
 * tirent du texte des sources, lu avec le tokenizer de PHP.
 *
 * Le tokenizer plutôt qu'une expression régulière : une propriété peut être
 * typée (« private ?string $_x »), un nom de méthode peut apparaître dans un
 * commentaire ou dans une chaîne, et la promotion de constructeur met des
 * variables dans une signature. Le tokenizer distingue tout cela ; grep, non.
 *
 * Le tokenizer plutôt que la réflexion : charger les classes du plugin
 * supposerait un Jeedom installé (elles étendent eqLogic), et une erreur de
 * syntaxe ou une classe absente ferait tomber le contrôle lui-même au lieu de
 * le faire échouer proprement. */

/* Les trois états possibles d'un contrôle. « non vérifiable » n'est pas un
 * échec : les sources du plugin s'écrivent en ce moment, et un fichier encore
 * absent ne prouve rien — ni dans un sens ni dans l'autre. */
define('MQTTBE_OK', 'ok');
define('MQTTBE_ECHEC', 'echec');
define('MQTTBE_INDECIS', 'indecis');

function mqttbeRacine() {
    return dirname(__DIR__);
}

function mqttbeResultat($_titre, $_etat, $_explication = '') {
    return array('titre' => $_titre, 'etat' => $_etat, 'explication' => $_explication);
}

function mqttbeOk($_titre) {
    return mqttbeResultat($_titre, MQTTBE_OK);
}

/* Un échec dit la panne qu'il prévient, pas seulement la règle enfreinte :
 * « propriété sans souligné » n'apprend rien, « Unknown column à la création
 * d'un équipement » fait gagner la demi-journée de recherche. */
function mqttbeEchec($_titre, $_explication) {
    return mqttbeResultat($_titre, MQTTBE_ECHEC, $_explication);
}

function mqttbeIndecis($_titre, $_raison) {
    return mqttbeResultat($_titre, MQTTBE_INDECIS, $_raison);
}

/* Largeur en caractères et non en octets : les titres sont accentués, et des
 * points de conduite calculés sur strlen() produiraient une colonne en dents
 * de scie. */
function mqttbeLongueur($_texte) {
    if (function_exists('mb_strlen')) {
        return mb_strlen($_texte, 'UTF-8');
    }
    return strlen(preg_replace('/[\x80-\xBF]/', '', $_texte));
}

function mqttbeAffiche($_resultats, $_largeur = 58) {
    foreach ($_resultats as $resultat) {
        $points = max(3, $_largeur - mqttbeLongueur($resultat['titre']));
        $etiquette = '  ' . $resultat['titre'] . ' ' . str_repeat('.', $points) . ' ';
        if ($resultat['etat'] === MQTTBE_OK) {
            echo $etiquette . "ok\n";
            continue;
        }
        $entete = ($resultat['etat'] === MQTTBE_ECHEC) ? 'ÉCHEC : ' : 'non vérifiable : ';
        $lignes = explode("\n", $resultat['explication']);
        echo $etiquette . $entete . array_shift($lignes) . "\n";
        foreach ($lignes as $ligne) {
            echo '        ' . $ligne . "\n";
        }
    }
}

function mqttbeBilan($_resultats) {
    $bilan = array(MQTTBE_OK => 0, MQTTBE_ECHEC => 0, MQTTBE_INDECIS => 0);
    foreach ($_resultats as $resultat) {
        $bilan[$resultat['etat']]++;
    }
    return $bilan;
}

/* Sortie autonome : chaque contrôle s'exécute aussi seul, pour le mettre au
 * point sans relancer toute la suite. */
function mqttbeSortieAutonome($_titre, $_resultats) {
    echo "\n== " . $_titre . " ==\n";
    mqttbeAffiche($_resultats);
    $bilan = mqttbeBilan($_resultats);
    printf("\n  ==> %d réussi(s), %d échec(s), %d non vérifiable(s)\n\n",
           $bilan[MQTTBE_OK], $bilan[MQTTBE_ECHEC], $bilan[MQTTBE_INDECIS]);
    exit($bilan[MQTTBE_ECHEC] === 0 ? 0 : 1);
}

function mqttbeAppelDirect($_fichier) {
    return isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath($_fichier);
}

/* Chemin relatif à la racine du dépôt : les messages d'échec désignent des
 * fichiers du dépôt, pas des chemins absolus propres à une machine. */
function mqttbeRelatif($_chemin) {
    $racine = mqttbeRacine() . DIRECTORY_SEPARATOR;
    if (strpos($_chemin, $racine) === 0) {
        return substr($_chemin, strlen($racine));
    }
    return $_chemin;
}

/* Les chemins qui ne sont pas du code du plugin : on ne leur applique aucune
 * de ces règles.
 *
 * tests/ contient justement les noms interdits, en toutes lettres, et se
 * dénoncerait lui-même. resources/mqttbed/lib/ est la bibliothèque MQTT
 * embarquée : ni écrite ni corrigeable ici, et son vocabulaire (« publish »,
 * « subscribe »…) ferait croire à tort que le démon parle le protocole du
 * plugin. */
function mqttbeCheminsExclus() {
    return array('tests', '.git', '.github', 'vendor', 'node_modules',
                 'resources/mqttbed/lib');
}

function mqttbeEstExclu($_chemin) {
    $relatif = str_replace(DIRECTORY_SEPARATOR, '/', mqttbeRelatif($_chemin));
    foreach (mqttbeCheminsExclus() as $exclu) {
        if ($relatif === $exclu || strpos($relatif, $exclu . '/') === 0) {
            return true;
        }
    }
    return false;
}

/* Tous les fichiers PHP du plugin, ou d'un de ses sous-dossiers. */
function mqttbeFichiersPhp($_sousDossier = '') {
    $base = rtrim(mqttbeRacine() . '/' . trim($_sousDossier, '/'), '/');
    if (!is_dir($base)) {
        return array();
    }
    $fichiers = array();
    $parcours = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($parcours as $entree) {
        if (!$entree->isFile() || strtolower($entree->getExtension()) !== 'php') {
            continue;
        }
        if (mqttbeEstExclu($entree->getPathname())) {
            continue;
        }
        $fichiers[] = $entree->getPathname();
    }
    sort($fichiers);
    return $fichiers;
}

function mqttbeClassesDuPlugin() {
    $fichiers = glob(mqttbeRacine() . '/core/class/*.class.php');
    return is_array($fichiers) ? $fichiers : array();
}

/* --------------------------------------------------------------------------
 * Analyse lexicale
 * ------------------------------------------------------------------------ */

function mqttbeEstNom($_token) {
    if (!is_array($_token)) {
        return false;
    }
    $noms = array(T_STRING);
    if (defined('T_NAME_QUALIFIED')) {
        $noms[] = T_NAME_QUALIFIED;
    }
    if (defined('T_NAME_FULLY_QUALIFIED')) {
        $noms[] = T_NAME_FULLY_QUALIFIED;
    }
    return in_array($_token[0], $noms, true);
}

function mqttbeEstBlanc($_token) {
    return is_array($_token)
        && in_array($_token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
}

function mqttbeTokenUtilePrecedent($_tokens, $_index) {
    for ($i = $_index - 1; $i >= 0; $i--) {
        if (!mqttbeEstBlanc($_tokens[$i])) {
            return $_tokens[$i];
        }
    }
    return null;
}

/* Rend, pour chaque classe déclarée dans le source, son nom, sa classe mère,
 * ses propriétés (nom, ligne, statique) et ses méthodes (nom, ligne,
 * visibilité, statique). */
function mqttbeAnalyseClasses($_code) {
    $tokens = token_get_all($_code);
    $nombre = count($tokens);
    $classes = array();

    for ($i = 0; $i < $nombre; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CLASS) {
            continue;
        }
        /* « Foo::class » porte lui aussi T_CLASS : le double deux-points qui le
         * précède le distingue d'une déclaration. */
        $precedent = mqttbeTokenUtilePrecedent($tokens, $i);
        if ($precedent !== null && is_array($precedent) && $precedent[0] === T_DOUBLE_COLON) {
            continue;
        }

        $nom = null;
        $parent = null;
        $attendParent = false;
        $debutCorps = null;
        for ($j = $i + 1; $j < $nombre; $j++) {
            $token = $tokens[$j];
            if (!is_array($token)) {
                if ($token === '{') {
                    $debutCorps = $j;
                    break;
                }
                continue;
            }
            if (mqttbeEstBlanc($token)) {
                continue;
            }
            if ($token[0] === T_EXTENDS) {
                $attendParent = true;
                continue;
            }
            if ($token[0] === T_IMPLEMENTS) {
                $attendParent = false;
                continue;
            }
            if (mqttbeEstNom($token)) {
                if ($nom === null) {
                    $nom = $token[1];
                } elseif ($attendParent && $parent === null) {
                    $parent = ltrim($token[1], '\\');
                    $attendParent = false;
                }
            }
        }
        if ($nom === null || $debutCorps === null) {
            continue;   /* classe anonyme ou déclaration tronquée */
        }

        $profondeur = 1;
        $parentheses = 0;
        $modificateurs = array();
        $proprietes = array();
        $methodes = array();
        for ($j = $debutCorps + 1; $j < $nombre; $j++) {
            $token = $tokens[$j];
            if (!is_array($token)) {
                if ($token === '{') {
                    $profondeur++;
                    continue;
                }
                if ($token === '}') {
                    $profondeur--;
                    if ($profondeur === 0) {
                        break;
                    }
                    continue;
                }
                if ($profondeur !== 1) {
                    continue;
                }
                if ($token === '(') {
                    $parentheses++;
                } elseif ($token === ')') {
                    $parentheses--;
                } elseif ($token === ';') {
                    $modificateurs = array();
                }
                continue;
            }
            /* Les accolades ouvertes à l'intérieur d'une chaîne interpolée se
             * referment avec une accolade ordinaire : sans les compter, la fin
             * de la classe serait détectée trop tôt. */
            if ($token[0] === T_CURLY_OPEN
                || (defined('T_DOLLAR_OPEN_CURLY_BRACES') && $token[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $profondeur++;
                continue;
            }
            if ($profondeur !== 1 || mqttbeEstBlanc($token)) {
                continue;
            }
            $texte = strtolower($token[1]);
            if (in_array($texte, array('public', 'private', 'protected', 'static',
                                       'var', 'readonly', 'abstract', 'final'), true)) {
                $modificateurs[] = $texte;
                continue;
            }
            if ($token[0] === T_FUNCTION) {
                $nomMethode = null;
                for ($k = $j + 1; $k < $nombre; $k++) {
                    if (mqttbeEstNom($tokens[$k])) {
                        $nomMethode = $tokens[$k][1];
                        break;
                    }
                    if (!is_array($tokens[$k]) && trim($tokens[$k]) !== '' && $tokens[$k] !== '&') {
                        break;   /* fonction anonyme : « function ( » */
                    }
                }
                if ($nomMethode !== null) {
                    $visibilite = 'public';
                    foreach (array('private', 'protected', 'public') as $mot) {
                        if (in_array($mot, $modificateurs, true)) {
                            $visibilite = $mot;
                        }
                    }
                    $methodes[] = array('nom' => $nomMethode, 'ligne' => $token[2],
                                        'visibilite' => $visibilite,
                                        'statique' => in_array('static', $modificateurs, true));
                }
                $modificateurs = array();
                continue;
            }
            /* $parentheses === 0 écarte la promotion de constructeur : les
             * paramètres promus ne sont pas des propriétés déclarées ici, et
             * Jeedom n'en crée de toute façon jamais. */
            if ($token[0] === T_VARIABLE && $parentheses === 0 && !empty($modificateurs)) {
                $proprietes[] = array('nom' => substr($token[1], 1), 'ligne' => $token[2],
                                      'statique' => in_array('static', $modificateurs, true));
            }
        }

        $classes[] = array('nom' => $nom, 'parent' => $parent, 'ligne' => $tokens[$i][2],
                           'proprietes' => $proprietes, 'methodes' => $methodes);
    }
    return $classes;
}

/* Les « catch » du source, avec la liste des types attrapés. */
function mqttbeCatchs($_code) {
    $tokens = token_get_all($_code);
    $nombre = count($tokens);
    $catchs = array();
    for ($i = 0; $i < $nombre; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
            continue;
        }
        $types = array();
        $ouvert = false;
        for ($j = $i + 1; $j < $nombre; $j++) {
            $token = $tokens[$j];
            if (!is_array($token)) {
                if ($token === '(') {
                    $ouvert = true;
                    continue;
                }
                if ($token === ')') {
                    break;
                }
                continue;
            }
            if ($ouvert && mqttbeEstNom($token)) {
                $types[] = ltrim($token[1], '\\');
            }
        }
        $catchs[] = array('types' => $types, 'ligne' => $tokens[$i][2]);
    }
    return $catchs;
}

/* Le contenu de toutes les chaînes littérales du source, guillemets ôtés.
 * On ne retient que les chaînes : un nom de commande cité dans un commentaire
 * ne prouve pas que le code sait le traiter. */
function mqttbeChaines($_code) {
    $tokens = token_get_all($_code);
    $chaines = array();
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $chaines[] = substr($token[1], 1, -1);
            continue;
        }
        if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $chaines[] = $token[1];
        }
    }
    return $chaines;
}

/* Les identifiants du source (noms de fonctions, de méthodes, de constantes) :
 * ils servent à distinguer « absent » de « écrit autrement », pour donner un
 * message d'échec utile plutôt qu'un simple « introuvable ». */
function mqttbeIdentifiants($_code) {
    $tokens = token_get_all($_code);
    $noms = array();
    foreach ($tokens as $token) {
        if (mqttbeEstNom($token)) {
            $noms[] = $token[1];
        }
    }
    return $noms;
}

/* Lecture d'un fichier ini sans parse_ini_file() : les clés du plugin
 * contiennent « :: », plusieurs valeurs sont des mots que le lecteur d'ini
 * convertirait en booléens, et on veut aussi voir les sections telles
 * qu'écrites — y compris [default], qu'il s'agit précisément de détecter. */
function mqttbeLitIni($_chemin) {
    $sections = array();
    $courante = '';
    foreach (file($_chemin, FILE_IGNORE_NEW_LINES) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || $ligne[0] === ';' || $ligne[0] === '#') {
            continue;
        }
        if ($ligne[0] === '[' && substr($ligne, -1) === ']') {
            $courante = trim(substr($ligne, 1, -1));
            if (!isset($sections[$courante])) {
                $sections[$courante] = array();
            }
            continue;
        }
        $separateur = strpos($ligne, '=');
        if ($separateur === false) {
            continue;
        }
        $cle = trim(substr($ligne, 0, $separateur));
        $valeur = trim(substr($ligne, $separateur + 1));
        $valeur = trim($valeur, "\"'");
        $sections[$courante][$cle] = $valeur;
    }
    return $sections;
}
