<?php
/* Le vocabulaire des capacités, confronté à la table du cœur.
 *
 *   php tests/check-capabilities.php
 *
 * core/config/capabilities.json est le seul endroit où le plugin traduit une
 * capacité en termes Jeedom. Une faute s'y écrit en trois secondes et ne se
 * voit nulle part : le plugin s'installe, l'équipement se crée, les commandes
 * apparaissent — et c'est plus tard, quand l'utilisateur cherche sa prise dans
 * l'application mobile ou qu'un widget reste vide, que le type générique
 * inventé se manifeste. Le cœur n'émet aucun avertissement : il range la
 * commande dans « Autre » et passe à la suivante.
 *
 * Ce contrôle lit la table du cœur telle quelle, dans le texte de
 * /var/www/html/core/config/jeedom.config.php. Ce fichier ne peut pas être
 * inclus hors Jeedom — il appelle __() à chaque ligne — mais il est du PHP
 * parfaitement analysable au tokenizer. Aucun Jeedom n'est donc chargé ici.
 *
 * JEEDOM_ROOT permet de désigner une autre installation ; en son absence, et
 * si /var/www/html n'existe pas (machine d'intégration continue), les contrôles
 * qui dépendent du cœur sont annoncés « non vérifiables » plutôt que faux. */

require_once __DIR__ . '/outils.php';

/* Couverture minimale exigée par le contrat du jalon 2. Une capacité peut être
 * ajoutée, jamais retirée en silence : les adapters la désignent par son nom,
 * et un nom qui disparaît fait retomber tout un appareil sur generic.value. */
function mqttbeCapacitesAttendues() {
    return array(
        'switch.state', 'switch.on', 'switch.off', 'switch.toggle',
        'light.state', 'light.on', 'light.off', 'light.brightness', 'light.color', 'light.color_temp',
        'cover.state', 'cover.open', 'cover.close', 'cover.stop', 'cover.position',
        'sensor.temperature', 'sensor.humidity', 'sensor.pressure', 'sensor.luminosity',
        'sensor.co2', 'sensor.noise', 'sensor.uv',
        'power.active', 'power.voltage', 'power.current',
        'energy.total', 'energy.daily',
        'contact.open', 'presence.detected',
        'alarm.smoke', 'alarm.water_leak', 'alarm.gas', 'alarm.sabotage',
        'battery.level', 'button.event', 'connectivity.online',
        'lock.state', 'lock.open', 'lock.close',
        'thermostat.temperature', 'thermostat.setpoint', 'thermostat.set_setpoint',
        'thermostat.mode', 'thermostat.set_mode',
        'fan.speed', 'generic.value',
    );
}

function mqttbeRacineJeedom() {
    $racine = getenv('JEEDOM_ROOT');
    return is_string($racine) && $racine !== '' ? rtrim($racine, '/') : '/var/www/html';
}

function mqttbeCheminCapacites() {
    return mqttbeRacine() . '/core/config/capabilities.json';
}

function mqttbeCheminConfigCoeur() {
    return mqttbeRacineJeedom() . '/core/config/jeedom.config.php';
}

/* --------------------------------------------------------------------------
 * Lecture de la table du cœur, au tokenizer
 *
 * Une expression régulière ne tiendrait pas : les valeurs sont des appels
 * __('…', __FILE__), certaines entrées n'ont pas de clé `subtype` du tout —
 * ce qui veut dire « aucune contrainte » et non « aucun sous-type » — et les
 * apostrophes échappées de « Fuite d'eau » coupent n'importe quel motif naïf.
 * ------------------------------------------------------------------------ */

/* Les tokens qui comptent, blancs et commentaires ôtés. */
function mqttbeTokensUtiles($_code) {
    $utiles = array();
    foreach (token_get_all($_code) as $token) {
        if (mqttbeEstBlanc($token)) {
            continue;
        }
        $utiles[] = $token;
    }
    return $utiles;
}

/* Une valeur littérale PHP : chaîne, nombre, tableau, ou appel de fonction
 * dont on ne retient que le premier argument — c'est exactement ce que fait
 * __('Prise Etat', __FILE__) pour qui lit le fichier sans traduction. */
function mqttbeLitValeur($_tokens, &$_i) {
    $nombre = count($_tokens);
    $valeur = null;
    while ($_i < $nombre) {
        $token = $_tokens[$_i];
        if (is_array($token) && $token[0] === T_ARRAY) {
            $_i++;   /* le '(' est consommé par mqttbeLitTableau */
            $valeur = mqttbeLitTableau($_tokens, $_i, ')');
        } elseif ($token === '[') {
            $_i++;
            $valeur = mqttbeLitTableau($_tokens, $_i, ']');
        } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $valeur = stripcslashes(substr($token[1], 1, -1));
            $_i++;
        } elseif (is_array($token) && ($token[0] === T_LNUMBER || $token[0] === T_DNUMBER)) {
            $valeur = $token[1] + 0;
            $_i++;
        } elseif (mqttbeEstNom($token)) {
            $nom = $token[1];
            $_i++;
            if ($_i < $nombre && $_tokens[$_i] === '(') {
                $_i++;
                $arguments = mqttbeLitTableau($_tokens, $_i, ')');
                $valeur = count($arguments) > 0 ? reset($arguments) : $nom;
            } else {
                $valeur = $nom;
            }
        } else {
            break;
        }
        /* Concaténation : ' ' . __('Générique') donne bien une seule chaîne. */
        if ($_i < $nombre && $_tokens[$_i] === '.') {
            $_i++;
            $suite = mqttbeLitValeur($_tokens, $_i);
            $valeur = (is_scalar($valeur) ? (string) $valeur : '') . (is_scalar($suite) ? (string) $suite : '');
        }
        break;
    }
    return $valeur;
}

/* Le contenu d'un tableau, jusqu'à sa fermeture. */
function mqttbeLitTableau($_tokens, &$_i, $_fermeture) {
    $nombre = count($_tokens);
    $tableau = array();
    while ($_i < $nombre && $_tokens[$_i] === '(') {
        $_i++;
    }
    while ($_i < $nombre) {
        if ($_tokens[$_i] === $_fermeture) {
            $_i++;
            break;
        }
        if ($_tokens[$_i] === ',') {
            $_i++;
            continue;
        }
        $premier = mqttbeLitValeur($_tokens, $_i);
        if ($_i < $nombre && is_array($_tokens[$_i]) && $_tokens[$_i][0] === T_DOUBLE_ARROW) {
            $_i++;
            $tableau[$premier] = mqttbeLitValeur($_tokens, $_i);
            continue;
        }
        if ($premier === null && $_i < $nombre) {
            $_i++;   /* jeton imprévu : on avance, pour ne pas boucler */
            continue;
        }
        $tableau[] = $premier;
    }
    return $tableau;
}

/* La valeur associée à une clé donnée, où qu'elle soit dans le fichier. Les
 * deux clés utiles ici — 'generic_type' et 'widgets' — n'apparaissent qu'une
 * fois dans jeedom.config.php. */
function mqttbeValeurCoeur($_code, $_cle) {
    $tokens = mqttbeTokensUtiles($_code);
    $nombre = count($tokens);
    for ($i = 0; $i < $nombre - 1; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (substr($tokens[$i][1], 1, -1) !== $_cle) {
            continue;
        }
        if (!is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_ARROW) {
            continue;
        }
        $j = $i + 2;
        return mqttbeLitValeur($tokens, $j);
    }
    return null;
}

/* Les sous-types que le cœur sait afficher, déduits du nom de ses gabarits
 * (cmd.<type>.<sous-type>.<nom>.html) : le cœur n'en tient aucune liste
 * ailleurs — la colonne SQL est un simple varchar(45). */
function mqttbeSousTypesCoeur() {
    $dossier = mqttbeRacineJeedom() . '/core/template/dashboard';
    $connus = array();
    if (is_dir($dossier)) {
        foreach (scandir($dossier) as $fichier) {
            $morceaux = explode('.', $fichier);
            if (count($morceaux) >= 4 && $morceaux[0] === 'cmd') {
                $connus[$morceaux[1]][$morceaux[2]] = true;
            }
        }
    }
    if (empty($connus)) {
        return null;
    }
    foreach ($connus as $type => $sousTypes) {
        $connus[$type] = array_keys($sousTypes);
    }
    return $connus;
}

/* Un gabarit « core::x » se résout soit par la table des widgets du cœur, soit
 * par un fichier cmd.<type>.<sous-type>.x.html. Le cœur ne dit rien quand il
 * ne trouve ni l'un ni l'autre : il affiche le widget par défaut, et on croit
 * à un oubli de configuration. */
function mqttbeGabaritCoeurExiste($_widgets, $_version, $_type, $_sousType, $_nom) {
    if (is_array($_widgets) && isset($_widgets[$_type][$_sousType][$_nom])) {
        return true;
    }
    $fichier = mqttbeRacineJeedom() . '/core/template/' . $_version
             . '/cmd.' . $_type . '.' . $_sousType . '.' . $_nom . '.html';
    return file_exists($fichier);
}

/* --------------------------------------------------------------------------
 * Les contrôles
 * ------------------------------------------------------------------------ */

function mqttbeControlesCapacites() {
    $resultats = array();
    $chemin = mqttbeCheminCapacites();

    if (!file_exists($chemin)) {
        return array(mqttbeIndecis('core/config/capabilities.json présent',
            'fichier absent : le vocabulaire des capacités n\'est pas encore écrit.'));
    }

    $brut = file_get_contents($chemin);
    $document = json_decode($brut, true);
    if (!is_array($document)) {
        return array(mqttbeEchec('capabilities.json bien formé',
            'JSON invalide (' . json_last_error_msg() . ') : le fichier est lu par le démon comme '
            . 'par le processus web, et un fichier illisible fait retomber toute découverte sur '
            . 'generic.value, sans un mot dans le journal.'));
    }
    $resultats[] = mqttbeOk('capabilities.json bien formé');

    $titre = 'schéma et section capabilities';
    if (!isset($document['schema']) || !is_int($document['schema']) || $document['schema'] < 1) {
        $resultats[] = mqttbeEchec($titre, 'clé « schema » absente ou non entière : sans numéro de '
            . 'version, un fichier plus récent que le plugin serait lu comme s\'il était compatible.');
    } elseif (!isset($document['capabilities']) || !is_array($document['capabilities'])
              || empty($document['capabilities'])) {
        $resultats[] = mqttbeEchec($titre, 'section « capabilities » absente ou vide.');
    } else {
        $resultats[] = mqttbeOk($titre);
    }
    $capacites = (isset($document['capabilities']) && is_array($document['capabilities']))
        ? $document['capabilities'] : array();
    if (empty($capacites)) {
        return $resultats;
    }

    /* -- champs obligatoires ------------------------------------------- */
    $titre = 'champs obligatoires de chaque capacité';
    $fautes = array();
    foreach ($capacites as $cle => $capacite) {
        if (!is_array($capacite)) {
            $fautes[] = $cle . ' : la description n\'est pas un objet.';
            continue;
        }
        foreach (array('name', 'type', 'subType', 'generic_type') as $champ) {
            if (!isset($capacite[$champ]) || trim((string) $capacite[$champ]) === '') {
                $fautes[] = $cle . ' : « ' . $champ .' » absent ou vide.';
            }
        }
        if (isset($capacite['type']) && !in_array($capacite['type'], array('info', 'action'), true)) {
            $fautes[] = $cle . ' : type « ' . $capacite['type'] . ' », attendu info ou action.';
        }
        if (isset($capacite['order']) && !is_int($capacite['order'])) {
            $fautes[] = $cle . ' : « order » doit être un entier.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "une commande créée sans type ni sous-type est rejetée par le cœur à l'enregistrement, "
        . "et c'est tout l'équipement qui ne s'enregistre pas.\n" . implode("\n", $fautes));

    /* -- sous-types connus du cœur -------------------------------------- */
    $titre = 'sous-types connus du cœur';
    $sousTypes = mqttbeSousTypesCoeur();
    if ($sousTypes === null) {
        $resultats[] = mqttbeIndecis($titre, 'gabarits du cœur introuvables sous '
            . mqttbeRacineJeedom() . '/core/template : contrôle impossible sans installation Jeedom.');
    } else {
        $fautes = array();
        foreach ($capacites as $cle => $capacite) {
            $type = isset($capacite['type']) ? $capacite['type'] : '';
            $sousType = isset($capacite['subType']) ? $capacite['subType'] : '';
            if ($type === '' || $sousType === '' || !isset($sousTypes[$type])) {
                continue;
            }
            if (!in_array($sousType, $sousTypes[$type], true)) {
                $fautes[] = $cle . ' : sous-type « ' . $sousType . ' » inconnu pour une commande '
                    . $type . ' (le cœur connaît : ' . implode(', ', $sousTypes[$type]) . ').';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "le cœur n'a aucun gabarit pour ce couple : la commande s'affiche vide.\n"
            . implode("\n", $fautes));
    }

    /* -- la table des types génériques du cœur -------------------------- */
    $cheminCoeur = mqttbeCheminConfigCoeur();
    $generiques = null;
    $widgets = null;
    if (is_readable($cheminCoeur)) {
        $codeCoeur = file_get_contents($cheminCoeur);
        $generiques = mqttbeValeurCoeur($codeCoeur, 'generic_type');
        $widgets = mqttbeValeurCoeur($codeCoeur, 'widgets');
    }

    if (!is_array($generiques) || count($generiques) < 100) {
        $resultats[] = mqttbeIndecis('types génériques existants',
            'table du cœur illisible dans ' . $cheminCoeur . ' : contrôle impossible sans '
            . 'installation Jeedom (JEEDOM_ROOT désigne une autre racine).');
        $resultats[] = mqttbeIndecis('cohérence type / sous-type / type générique', 'idem.');
        $resultats[] = mqttbeIndecis('gabarits de widget résolvables', 'idem.');
    } else {
        /* -- existence ------------------------------------------------- */
        $titre = 'types génériques existants (' . count($generiques) . ' dans le cœur)';
        $fautes = array();
        foreach ($capacites as $cle => $capacite) {
            $generique = isset($capacite['generic_type']) ? $capacite['generic_type'] : '';
            if ($generique !== '' && !isset($generiques[$generique])) {
                $fautes[] = $cle . ' : type générique « ' . $generique . ' » absent de la table du cœur.';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "un type générique inventé n'est pas signalé par le cœur : la commande est simplement "
            . "rangée dans « Autre », invisible des plugins qui s'appuient dessus (Alarme, "
            . "Thermostat, l'assistant vocal).\n" . implode("\n", $fautes));

        /* -- cohérence type / sous-type -------------------------------- */
        $titre = 'cohérence type / sous-type / type générique';
        $fautes = array();
        foreach ($capacites as $cle => $capacite) {
            $generique = isset($capacite['generic_type']) ? $capacite['generic_type'] : '';
            if ($generique === '' || !isset($generiques[$generique])) {
                continue;
            }
            $entree = $generiques[$generique];
            $type = isset($capacite['type']) ? $capacite['type'] : '';
            $sousType = isset($capacite['subType']) ? $capacite['subType'] : '';
            $attendu = isset($entree['type']) ? strtolower((string) $entree['type']) : '';
            if ($attendu !== '' && $attendu !== 'all' && $attendu !== $type) {
                $fautes[] = $cle . ' : ' . $generique . ' est un type générique de commande '
                    . $attendu . ', déclaré ici en ' . ($type === '' ? '(vide)' : $type) . '.';
                continue;
            }
            /* Pas de clé `subtype` dans l'entrée du cœur = aucune contrainte
             * (GENERIC_INFO, WATER_LEAK, FLAP_STOP…). L'absence et la liste
             * vide ne veulent pas dire la même chose. */
            if (isset($entree['subtype']) && is_array($entree['subtype']) && !empty($entree['subtype'])
                && !in_array($sousType, $entree['subtype'], true)) {
                $fautes[] = $cle . ' : ' . $generique . ' n\'admet que ' . implode(', ', $entree['subtype'])
                    . ', déclaré ici en « ' . $sousType . ' ».';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "le cœur n'impose pas ce couple, il le suppose : les écrans qui regroupent les commandes "
            . "par type générique (résumé d'objet, application mobile, widgets) lisent un sous-type "
            . "qu'ils n'attendent pas et n'affichent rien.\n" . implode("\n", $fautes));

        /* -- gabarits -------------------------------------------------- */
        $titre = 'gabarits de widget résolvables';
        $fautes = array();
        foreach ($capacites as $cle => $capacite) {
            if (!isset($capacite['template']) || !is_array($capacite['template'])) {
                continue;
            }
            $type = isset($capacite['type']) ? $capacite['type'] : '';
            $sousType = isset($capacite['subType']) ? $capacite['subType'] : '';
            foreach ($capacite['template'] as $version => $gabarit) {
                if (!in_array($version, array('dashboard', 'mobile'), true)) {
                    $fautes[] = $cle . ' : « ' . $version . ' » n\'est pas une version d\'affichage '
                        . '(dashboard ou mobile).';
                    continue;
                }
                if (strpos($gabarit, 'core::') !== 0) {
                    continue;   /* gabarit du plugin : hors de portée de ce contrôle */
                }
                $nom = substr($gabarit, strlen('core::'));
                if (!mqttbeGabaritCoeurExiste($widgets, $version, $type, $sousType, $nom)) {
                    $fautes[] = $cle . ' (' . $version . ') : le cœur n\'a pas de gabarit « ' . $nom
                        . ' » pour une commande ' . $type . '/' . $sousType . '.';
                }
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
            "un gabarit introuvable ne provoque aucune erreur : le cœur retombe silencieusement sur "
            . "le widget par défaut, et la commande a l'air mal configurée.\n" . implode("\n", $fautes));
    }

    /* -- links ----------------------------------------------------------- */
    $titre = 'links vers une capacité d\'information';
    $fautes = array();
    foreach ($capacites as $cle => $capacite) {
        if (!isset($capacite['links']) || $capacite['links'] === '') {
            continue;
        }
        $cible = (string) $capacite['links'];
        if (!isset($capacites[$cible])) {
            $fautes[] = $cle . ' : links désigne « ' . $cible . ' », qui n\'existe pas ici.';
            continue;
        }
        if (!isset($capacites[$cible]['type']) || $capacites[$cible]['type'] !== 'info') {
            $fautes[] = $cle . ' : links désigne « ' . $cible . ' », qui est une action ; '
                . 'cmd.value attend une commande d\'information.';
        }
        if (isset($capacite['type']) && $capacite['type'] !== 'action') {
            $fautes[] = $cle . ' : links sur une commande d\'information, qui ne pilote rien.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "links devient cmd.value : mal rempli, le bouton « On » n'a plus d'état à afficher, et le "
        . "cœur écrit un identifiant de commande qui ne mène nulle part.\n" . implode("\n", $fautes));

    /* -- couverture minimale --------------------------------------------- */
    $titre = 'couverture minimale du contrat';
    $manquantes = array();
    foreach (mqttbeCapacitesAttendues() as $attendue) {
        if (!isset($capacites[$attendue])) {
            $manquantes[] = $attendue;
        }
    }
    $resultats[] = empty($manquantes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "les adapters désignent ces capacités par leur nom : une capacité absente fait retomber "
        . "la valeur sur generic.value, sans type générique et sans unité.\n"
        . implode(', ', $manquantes));

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Vocabulaire des capacités', mqttbeControlesCapacites());
}
