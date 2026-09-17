<?php
/* Contrat démon ↔ Jeedom : le vocabulaire est-il le même des deux côtés ?
 *
 *   php tests/check-protocol.php
 *
 * Le démon et Jeedom sont deux processus séparés qui ne partagent aucun code :
 * ils ne se parlent que par des messages JSON dont la clé « cmd » porte un nom
 * convenu (voir CONTRAT.md). Rien dans PHP ne relie l'émetteur au récepteur —
 * ni l'éditeur, ni « php -l », ni le typage. Un « brokerUp » d'un côté contre
 * un « brokerOn » de l'autre passe donc toute la relecture, et ne se voit qu'à
 * l'exécution, sous la forme d'un état qui ne change jamais : le message part,
 * arrive, et tombe dans le cas par défaut du dispatch, en silence.
 *
 * Le contrôle est volontairement grossier — le nom est-il cité des deux côtés,
 * dans une chaîne et non dans un commentaire — parce que c'est exactement la
 * faute qu'il s'agit d'attraper : une divergence de vocabulaire. */

require_once __DIR__ . '/outils.php';

/* Le protocole de CONTRAT.md. La direction ne sert pas à décider si le nom doit
 * être présent — il doit l'être des deux côtés, l'un pour l'émettre, l'autre
 * pour le reconnaître — mais à nommer la panne quand il manque d'un côté. */
function mqttbeProtocole() {
    return array(
        array('nom' => 'daemonUp',   'sens' => 'demon',   'note' => ''),
        array('nom' => 'daemonDown', 'sens' => 'demon',   'note' => ''),
        array('nom' => 'hb',         'sens' => 'croise',  'note' => ''),
        array('nom' => 'brokerUp',   'sens' => 'demon',   'note' => ''),
        array('nom' => 'brokerDown', 'sens' => 'demon',   'note' => ''),
        /* « values » appartient au jalon 2 : absent des deux côtés, c'est
         * normal ; présent d'un seul, c'est déjà une divergence. */
        array('nom' => 'values',     'sens' => 'demon',   'note' => 'jalon 2'),
        array('nom' => 'setBroker',  'sens' => 'jeedom',  'note' => ''),
        array('nom' => 'subscribe',  'sens' => 'jeedom',  'note' => ''),
        array('nom' => 'unsubscribe', 'sens' => 'jeedom', 'note' => ''),
        array('nom' => 'publish',    'sens' => 'jeedom',  'note' => ''),
        array('nom' => 'loglevel',   'sens' => 'jeedom',  'note' => ''),
        array('nom' => 'stop',       'sens' => 'jeedom',  'note' => ''),
    );
}

/* Cherche un nom de commande dans les chaînes littérales d'un ensemble de
 * fichiers. Les délimiteurs de mot laissent passer aussi bien 'daemonUp' que
 * '{"cmd":"daemonUp"}', sans confondre « stop » avec « socketport ». */
function mqttbeCherche($_fichiers, $_nom) {
    $motif = '/(?<![A-Za-z0-9_])' . preg_quote($_nom, '/') . '(?![A-Za-z0-9_])/';
    $approchant = null;
    foreach ($_fichiers as $fichier) {
        foreach (mqttbeChaines(file_get_contents($fichier)) as $chaine) {
            if (preg_match($motif, $chaine)) {
                return array('etat' => 'exact', 'variante' => $_nom,
                             'fichier' => mqttbeRelatif($fichier));
            }
            if ($approchant === null && preg_match($motif . 'i', $chaine, $trouve)) {
                $approchant = array('etat' => 'casse', 'variante' => $trouve[0],
                                    'fichier' => mqttbeRelatif($fichier));
            }
        }
    }
    return $approchant !== null ? $approchant : array('etat' => 'absent');
}

/* $_cote désigne le côté où le nom manque. */
function mqttbePanne($_sens, $_cote) {
    if ($_cote === 'demon') {
        if ($_sens === 'demon') {
            return 'Jeedom attend ce message, mais le démon ne l\'émet nulle part : '
                 . 'l\'état correspondant restera figé sur sa valeur initiale.';
        }
        return 'Jeedom envoie cet ordre, mais le démon ne le reconnaît pas : il part, arrive, '
             . 'et tombe dans le cas par défaut du dispatch, en silence.';
    }
    if ($_sens === 'jeedom') {
        return 'le démon sait traiter cet ordre, mais Jeedom ne l\'envoie jamais : '
             . 'le traitement est mort, la fonction n\'est reliée à rien.';
    }
    return 'le démon émet ce message, mais le rappel Jeedom ne le reconnaît pas : '
         . 'il sera reçu puis ignoré, et l\'état affiché ne bougera pas.';
}

function mqttbeControlesProtocole() {
    $resultats = array();

    /* Le démon d'un côté, le rappel et les classes de l'autre : ce sont les
     * deux seuls endroits où le vocabulaire du protocole a le droit d'être
     * écrit. La bibliothèque MQTT embarquée est exclue (voir outils.php) : elle
     * emploie « publish » et « subscribe » pour tout autre chose. */
    $entree = mqttbeRacine() . '/resources/mqttbed/mqttbed.php';
    $callback = mqttbeRacine() . '/core/php/callback.php';
    $demon = is_readable($entree) ? mqttbeFichiersPhp('resources/mqttbed') : array();
    $jeedom = is_readable($callback)
        ? array_merge(array($callback), mqttbeClassesDuPlugin()) : array();

    /* Tant qu'un des deux côtés n'a pas son point d'entrée, il n'y a pas de
     * divergence à constater : un vocabulaire manque parce que le programme qui
     * le parle n'est pas encore écrit. Comparer maintenant ne produirait que du
     * bruit, et douze échecs qu'on apprendrait à ignorer. */
    if (empty($demon) || empty($jeedom)) {
        $manquant = array();
        if (empty($demon)) {
            $manquant[] = 'resources/mqttbed/mqttbed.php';
        }
        if (empty($jeedom)) {
            $manquant[] = 'core/php/callback.php';
        }
        return array(mqttbeIndecis('vocabulaire du protocole des deux côtés',
            'rien à comparer : ' . implode(' et ', $manquant) . ' — pas encore écrit.'));
    }

    foreach (mqttbeProtocole() as $commande) {
        $titre = 'commande « ' . $commande['nom'] . ' » des deux côtés';
        $cotes = array('demon' => mqttbeCherche($demon, $commande['nom']),
                       'jeedom' => mqttbeCherche($jeedom, $commande['nom']));

        if ($cotes['demon']['etat'] === 'absent' && $cotes['jeedom']['etat'] === 'absent') {
            /* Absente partout : personne ne l'emploie, donc personne ne se
             * trompe. Reste à l'écrire, ce n'est pas un défaut de cohérence. */
            $resultats[] = mqttbeIndecis($titre, 'inconnue des deux côtés'
                . ($commande['note'] !== '' ? ' (' . $commande['note'] . ')' : '')
                . ' — pas encore implémentée.');
            continue;
        }

        /*
         * Une commande annoncée pour un jalon ultérieur peut légitimement être
         * déjà reconnue par le destinataire alors que l'émetteur ne l'envoie
         * pas encore : préparer l'oreille avant la voix est le bon ordre, et
         * c'est ce qui permet de livrer le jalon suivant sans toucher au
         * callback. L'inverse — un émetteur qui parle dans le vide — reste un
         * échec, parce que les messages seraient perdus sans que rien ne le dise.
         */
        if ($commande['note'] !== ''
            && $cotes[$commande['sens'] === 'demon' ? 'demon' : 'jeedom']['etat'] === 'absent'
            && $cotes[$commande['sens'] === 'demon' ? 'jeedom' : 'demon']['etat'] === 'exact') {
            $resultats[] = mqttbeIndecis($titre, 'reconnue par le destinataire, pas encore émise ('
                . $commande['note'] . ').');
            continue;
        }

        $fautes = array();
        foreach ($cotes as $cote => $trouvaille) {
            $autre = ($cote === 'demon') ? 'côté démon' : 'côté Jeedom';
            if ($trouvaille['etat'] === 'absent') {
                $fautes[] = 'introuvable ' . $autre . ' : ' . mqttbePanne($commande['sens'], $cote);
            } elseif ($trouvaille['etat'] === 'casse') {
                $fautes[] = 'écrit « ' . $trouvaille['variante'] . ' » ' . $autre . ' ('
                    . $trouvaille['fichier'] . ') : la clé « cmd » se compare caractère pour '
                    . 'caractère, une majuscule de travers suffit à perdre le message.';
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre)
            : mqttbeEchec($titre, implode("\n", $fautes));
    }

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Contrat démon ↔ Jeedom', mqttbeControlesProtocole());
}
