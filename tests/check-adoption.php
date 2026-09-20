<?php
/* La file d'adoption et les refus, mis à l'épreuve.
 *
 *   php tests/check-adoption.php
 *
 * Ce contrôle charge le VRAI mqttbeDaemon par-dessus le cœur de papier — les
 * autres contrôles emploient un double, celui-ci a besoin du code réel, parce
 * que c'est là que vit la file. Il ne peut donc pas tourner dans le même
 * processus que check-factory.php : tests/run.php le lance à part.
 *
 * Ce qui se joue ici n'a rien d'accessoire. La file d'adoption est le seul
 * endroit où le plugin demande quelque chose à l'utilisateur, et chacune de ses
 * pannes est silencieuse :
 *
 *   - un refus qui ne tient pas fait revenir l'appareil écarté à la trame
 *     suivante, indéfiniment, et le bouton « Ignorer » passe pour décoratif.
 *     C'est exactement ce qui se produisait : config::byKey() décode le JSON
 *     lui-même, le redécoder rendait null, et la liste des refus était
 *     éternellement vide ;
 *   - un refus qui ne vaut que contre la mise en file laisse créer d'office
 *     l'appareil écarté dès que sa confiance monte ;
 *   - un candidat déjà adopté qui repasse en file n'est plus jamais mis à jour :
 *     une mesure nouvellement décodée ne devient jamais une commande ;
 *   - une file coupée sans être triée sort le traceur qu'on voit depuis des
 *     heures au profit du téléphone d'un passant arrivé à l'instant ;
 *   - une file lue pendant qu'elle s'écrit paraît vide, et la réécrire par
 *     dessus efface tous les autres candidats.
 *
 * Aucune ne se voit à la relecture. Toutes se démontrent ici. */

require_once __DIR__ . '/outils.php';

/* --------------------------------------------------------------------------
 * Mise en place
 * ------------------------------------------------------------------------ */

function mqttbeAdoptionPrete() {
    static $pret = null;
    if ($pret !== null) {
        return $pret;
    }
    foreach (array('core/class/mqttbe.class.php',
                   'core/class/mqttbeDaemon.class.php',
                   'core/class/mqttbeFactory.class.php',
                   'resources/mqttbed/discovery/DeviceModel.php') as $requis) {
        if (!is_readable(mqttbeRacine() . '/' . $requis)) {
            return $pret = false;
        }
    }
    /* Dit au cœur de papier de charger le vrai démon à la place de son double :
     * c'est le code réel qu'il s'agit d'éprouver, pas un simulacre qui dirait
     * oui à tout. */
    if (!defined('MQTTBE_VRAI_DEMON')) {
        define('MQTTBE_VRAI_DEMON', true);
    }
    require_once __DIR__ . '/faux-coeur.php';
    mqttbeChargeClassesPlugin();
    $pret = class_exists('mqttbeDaemon')
         && method_exists('mqttbeDaemon', 'ignoredUids')
         && method_exists('mqttbeDaemon', 'onDiscovered');
    return $pret;
}

/* Table rase, et les deux réglages de découverte remis à leur état livré. */
function mqttbeAdoptionRAZ($_reglages = array()) {
    MqttbeFauxCoeur::reinitialise();
    $defauts = array('discovery::enabled' => 1, 'discovery::autoCreate' => 1);
    foreach (array_merge($defauts, $_reglages) as $cle => $valeur) {
        config::save($cle, $valeur, 'mqttbe');
    }
}

function mqttbeAdoptionVerdict($_titre, $_fautes, $_panne) {
    if (empty($_fautes)) {
        return mqttbeOk($_titre);
    }
    return mqttbeEchec($_titre, $_panne . "\n" . implode("\n", $_fautes));
}

/*
 * Un modèle de découverte tel que le démon l'envoie.
 *
 * La confiance est le pivot de tout ce fichier : « guess » va en file,
 * « probable » et « certain » sont créés. Le type d'adresse, lui, décide de ce
 * qui survit quand la file déborde.
 */
function mqttbeCandidat($_uid, $_nom, $_confiance = 'guess', $_options = array()) {
    $modele = array(
        'identity' => array(
            'uid'        => $_uid,
            'adapter'    => isset($_options['adapter']) ? $_options['adapter'] : 'omg',
            'confidence' => $_confiance,
        ),
        'meta' => array(
            'name'         => $_nom,
            'manufacturer' => 'Fabricant',
            'model'        => 'BALISE-1',
        ),
        'channels' => array(
            array(
                'key'        => 'rssi',
                'capability' => 'connectivity.rssi',
                'source'     => array('topic' => 'bt/passerelle/' . $_uid,
                                      'selector' => array('type' => 'json', 'path' => 'rssi')),
            ),
        ),
    );
    if (isset($_options['address_type'])) {
        $modele['meta']['address_type'] = $_options['address_type'];
    }
    if (isset($_options['aliases'])) {
        $modele['identity']['aliases'] = $_options['aliases'];
    }
    return $modele;
}

/** Remet la file dans un état choisi, sans passer par la découverte. */
function mqttbeFilePosee($_entrees) {
    cache::set('mqttbe::pending', $_entrees);
}

/** Une entrée de file telle que rememberPending l'écrit. */
function mqttbeEntreeFile($_uid, $_nom, $_premier, $_vu, $_type = 'public') {
    return array(
        'name'     => $_nom,
        'adapter'  => 'omg',
        'model'    => mqttbeCandidat($_uid, $_nom, 'guess', array('address_type' => $_type)),
        'first'    => $_premier,
        'seen'     => $_vu,
        'channels' => 1,
    );
}

/* --------------------------------------------------------------------------
 * Les contrôles
 * ------------------------------------------------------------------------ */

function mqttbeControlesAdoption() {
    if (!mqttbeAdoptionPrete()) {
        return array(mqttbeIndecis("file d'adoption",
            "core/class/mqttbeDaemon.class.php ou les classes de découverte n'existent pas encore."));
    }
    $resultats = array();

    /* ------------------------------------------------------------------ 1 ---
     * Un refus tient.
     *
     * Le contrôle qui manquait. config::byKey() décode le JSON lui-même, si
     * bien que le json_decode() qui suivait travaillait sur la chaîne « Array »
     * et rendait null : ignoredUids() rendait toujours un tableau vide. Rien ne
     * le disait — pas une ligne de journal — et le symptôme, « Ignorer ne fait
     * rien », ne désignait pas sa cause. */
    $titre = 'un refus survit à sa relecture';
    mqttbeAdoptionRAZ();
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000001');
    $fautes = array();
    $liste = mqttbeDaemon::ignoredUids();
    if (!isset($liste['omg:ble:aa0000000001'])) {
        $fautes[] = "l'identifiant écarté est absent de la liste relue : "
                  . var_export(array_keys($liste), true);
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        "Le bouton « Ignorer » n'a aucun effet durable : l'appareil écarté "
        . 'revient dans la file à la trame suivante, quelques secondes plus tard, '
        . 'indéfiniment.');

    /* ------------------------------------------------------------------ 2 ---
     * Deux refus coexistent. Le défaut précédent avait ce corollaire : chaque
     * refus relisait une liste vide et l'écrivait avec un seul élément, donc
     * effaçait tous les précédents. */
    $titre = 'un second refus n\'efface pas le premier';
    mqttbeAdoptionRAZ();
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000001');
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000002');
    $fautes = array();
    $liste = mqttbeDaemon::ignoredUids();
    foreach (array('omg:ble:aa0000000001', 'omg:ble:aa0000000002') as $attendu) {
        if (!isset($liste[$attendu])) {
            $fautes[] = $attendu . ' a disparu de la liste des refus.';
        }
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        "Écarter un appareil remet les précédents dans le circuit : l'utilisateur "
        . 'écarte trois balises et les voit revenir une à une.');

    /* ------------------------------------------------------------------ 3 ---
     * Le refus retient le nom sous lequel l'appareil a été vu.
     *
     * Sans lui, la liste des écartés n'affiche que « omg:ble:d4a3f2118c07 », et
     * revenir sur un refus demande de reconnaître ce sur quoi on revient : la
     * file, elle, n'a plus l'entrée — le refus l'en a retirée. */
    $titre = 'le refus retient le nom, pas seulement l\'identifiant';
    mqttbeAdoptionRAZ(array('discovery::autoCreate' => 0));
    mqttbeDaemon::onDiscovered(array(mqttbeCandidat('omg:ble:aa0000000003', 'Traceur des clés')));
    $fautes = array();
    $file = mqttbeDaemon::pendingQueue();
    if (!is_array($file) || !isset($file['omg:ble:aa0000000003'])) {
        $fautes[] = "le candidat n'est pas entré dans la file : rien à écarter.";
    } else {
        mqttbeDaemon::ignoreUid('omg:ble:aa0000000003');
        $liste = mqttbeDaemon::ignoredUids();
        $entree = isset($liste['omg:ble:aa0000000003']) ? $liste['omg:ble:aa0000000003'] : array();
        if (!isset($entree['name']) || $entree['name'] !== 'Traceur des clés') {
            $fautes[] = 'nom retenu : ' . var_export(isset($entree['name']) ? $entree['name'] : null, true)
                      . ' au lieu de « Traceur des clés ».';
        }
        if (!isset($entree['at']) || (int) $entree['at'] <= 0) {
            $fautes[] = 'aucune date de refus retenue : la liste ne saura pas dans quel ordre les montrer.';
        }
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        "La liste des appareils écartés n'affiche qu'un identifiant technique : "
        . 'on ne sait plus lequel on a refusé, et revenir sur un refus devient un pari.');

    /* ------------------------------------------------------------------ 4 ---
     * Les formes antérieures de la liste sont relues.
     *
     * Un refus est une décision : elle ne se perd pas parce que la forme de
     * stockage a changé entre deux versions du plugin. Les deux formes écrites
     * par le passé — la liste plate, et uid => horodatage — doivent encore
     * compter comme des refus. */
    $titre = 'les refus d\'une version antérieure comptent encore';
    $fautes = array();
    foreach (array(
        'liste plate'       => array('omg:ble:aa0000000004', 'omg:ble:aa0000000005'),
        'uid => horodatage' => array('omg:ble:aa0000000004' => 1700000000,
                                     'omg:ble:aa0000000005' => 1700000100),
    ) as $forme => $stockee) {
        mqttbeAdoptionRAZ();
        config::save('discovery::ignored', json_encode($stockee), 'mqttbe');
        $liste = mqttbeDaemon::ignoredUids();
        foreach (array('omg:ble:aa0000000004', 'omg:ble:aa0000000005') as $attendu) {
            if (!isset($liste[$attendu])) {
                $fautes[] = 'forme « ' . $forme . " » : $attendu n'est plus reconnu comme écarté.";
            }
        }
        /* Et le cœur qui ne décoderait pas — valeur relue en chaîne — doit
         * donner le même résultat. */
        mqttbeAdoptionRAZ();
        config::save('discovery::ignored', json_encode($stockee), 'mqttbe');
        $liste = mqttbeDaemon::ignoredUids();
        if (count($liste) !== 2) {
            $fautes[] = 'forme « ' . $forme . " » lue en chaîne : " . count($liste) . ' refus au lieu de 2.';
        }
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Une mise à jour du plugin remet dans le circuit tout ce que '
        . "l'utilisateur avait écarté : la file se remplit à nouveau de ce qu'il "
        . 'avait justement pris la peine de refuser.');

    /* ------------------------------------------------------------------ 5 ---
     * Un refus vaut aussi contre la création.
     *
     * Écarté ne veut pas dire « écarté tant que tu restes incertain ». Une
     * balise refusée que la passerelle se met à décoder monte en confiance, et
     * sans ce contrôle elle était créée d'office — tous les refus balayés d'un
     * coup le jour où l'utilisateur coche « adopter toutes les balises ». */
    $titre = 'un appareil écarté n\'est pas créé quand sa confiance monte';
    mqttbeAdoptionRAZ();
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000006');
    mqttbeDaemon::onDiscovered(array(
        mqttbeCandidat('omg:ble:aa0000000006', 'Balise refusée', 'certain'),
    ));
    $fautes = array();
    if (MqttbeFauxCoeur::equipement('omg:ble:aa0000000006') !== null) {
        $fautes[] = "l'équipement a été créé malgré le refus.";
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Un appareil explicitement écarté réapparaît en équipement dès que la '
        . "découverte en apprend davantage : le refus de l'utilisateur ne vaut "
        . 'que jusqu\'à la prochaine trame.');

    /* ------------------------------------------------------------------ 6 ---
     * Un équipement déjà là est mis à jour, pas remis en file.
     *
     * Un candidat adopté repasse par la découverte à chaque redémarrage du
     * démon, et l'adapter continue de l'annoncer « guess » : l'adoption n'a
     * forcé la confiance que du côté de Jeedom. Sans cette question posée avant
     * les autres, il repartait en file — et son équipement n'était PLUS JAMAIS
     * mis à jour, puisque plus aucun modèle n'atteignait la fabrique. */
    $titre = 'un équipement adopté continue d\'être mis à jour';
    mqttbeAdoptionRAZ();
    /* Adoption : la page force la confiance, la fabrique écrit. */
    $adopte = mqttbeCandidat('omg:ble:aa0000000007', 'Traceur adopté', 'certain');
    mqttbeFactory::resetCache();
    mqttbeFactory::applyData($adopte);
    $fautes = array();
    if (MqttbeFauxCoeur::equipement('omg:ble:aa0000000007') === null) {
        $fautes[] = "l'adoption n'a rien créé : le reste du contrôle ne veut rien dire.";
    } else {
        /* Le démon, lui, l'annonce toujours « guess » — et avec une mesure de
         * plus, que la fabrique doit écrire. */
        $revient = mqttbeCandidat('omg:ble:aa0000000007', 'Traceur adopté', 'guess');
        $revient['channels'][] = array(
            'key'        => 'battery',
            'capability' => 'device.battery',
            'source'     => array('topic' => 'bt/passerelle/omg:ble:aa0000000007',
                                  'selector' => array('type' => 'json', 'path' => 'batt')),
        );
        mqttbeDaemon::onDiscovered(array($revient));
        $file = mqttbeDaemon::pendingQueue();
        if (is_array($file) && isset($file['omg:ble:aa0000000007'])) {
            $fautes[] = "l'équipement existant est reparti dans la file d'adoption.";
        }
        $noms = array();
        foreach (mqttbeCommandesAdoption('omg:ble:aa0000000007') as $cmd) {
            $noms[] = $cmd->getLogicalId();
        }
        if (!in_array('battery', $noms, true)) {
            $fautes[] = 'la mesure nouvellement décodée n\'est pas devenue une commande : '
                      . implode(', ', $noms);
        }
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        "Un appareil adopté n'est plus jamais mis à jour : une mesure que la "
        . 'passerelle apprend à décoder, ou une passerelle de plus qui le voit, '
        . 'ne devient jamais une commande.');

    /* ------------------------------------------------------------------ 7 ---
     * « guess » attend, « probable » est créé.
     *
     * C'est la frontière même de la file : une passerelle Bluetooth voit tout
     * ce qui passe, et créer sur « je vois quelque chose, je ne sais pas ce que
     * c'est » serait présumer à la place de l'utilisateur. « probable » dit
     * autre chose — « je sais ce que c'est, je ne le connais pas encore tout à
     * fait » — et se crée. */
    $titre = 'la confiance décide seule de créer ou de demander';
    mqttbeAdoptionRAZ();
    mqttbeDaemon::onDiscovered(array(
        mqttbeCandidat('omg:ble:aa0000000008', 'Inconnu qui passe', 'guess'),
        mqttbeCandidat('omg:ble:aa0000000009', 'Capteur reconnu', 'probable'),
    ));
    $fautes = array();
    $file = mqttbeDaemon::pendingQueue();
    if (!is_array($file) || !isset($file['omg:ble:aa0000000008'])) {
        $fautes[] = '« guess » n\'a pas été mis en file.';
    }
    if (MqttbeFauxCoeur::equipement('omg:ble:aa0000000008') !== null) {
        $fautes[] = '« guess » a été créé sans rien demander.';
    }
    if (MqttbeFauxCoeur::equipement('omg:ble:aa0000000009') === null) {
        $fautes[] = '« probable » attend une décision au lieu d\'être créé.';
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Ou bien la file se remplit de ce qui devait être créé tout seul, ou bien '
        . 'chaque téléphone de passage devient un équipement qui deviendra muet '
        . "au prochain changement d'adresse.");

    /* ------------------------------------------------------------------ 8 ---
     * La file déborde : elle garde ce qu'il faut garder.
     *
     * array_slice(-N) garde la FIN du tableau. La coupe d'avant prétendait
     * garder les cinquante derniers insérés — sauf que réaffecter une clé
     * existante ne la déplace pas en fin de tableau en PHP. Le traceur vu
     * depuis des heures sortait donc en premier, au profit du téléphone d'un
     * passant arrivé à l'instant. */
    $titre = 'file pleine : la balise de la maison reste, les passants sortent';
    mqttbeAdoptionRAZ(array('discovery::autoCreate' => 0));
    $maintenant = time();
    /* La balise de la maison : adresse publique, vue depuis six heures. */
    $ancre = mqttbeEntreeFile('omg:ble:ancre000001', 'Traceur des clés',
                              $maintenant - 21600, $maintenant - 5, 'public');
    $posee = array('omg:ble:ancre000001' => $ancre);
    /* Et soixante passants, adresse aléatoire, aperçus à l'instant. */
    for ($i = 0; $i < 60; $i++) {
        $uid = sprintf('omg:ble:passant%04d', $i);
        $posee[$uid] = mqttbeEntreeFile($uid, 'Passant ' . $i,
                                        $maintenant - 20, $maintenant - 1, 'random');
    }
    mqttbeFilePosee($posee);
    /* Une trame de plus, qui force la coupe. */
    mqttbeDaemon::onDiscovered(array(
        mqttbeCandidat('omg:ble:passant9999', 'Dernier arrivé', 'guess',
                       array('address_type' => 'random')),
    ));
    $fautes = array();
    $file = mqttbeDaemon::pendingQueue();
    if (!is_array($file)) {
        $fautes[] = 'la file est devenue illisible.';
    } else {
        if (count($file) > 50) {
            $fautes[] = 'la file compte ' . count($file) . ' candidats : le plafond ne tient pas.';
        }
        if (!isset($file['omg:ble:ancre000001'])) {
            $fautes[] = 'la balise vue depuis six heures a été évincée par des passants.';
        }
        /* Et elle doit être en tête : c'est l'ordre dans lequel on décide. */
        $premier = key($file);
        if ($premier !== 'omg:ble:ancre000001') {
            $fautes[] = 'la file commence par ' . var_export($premier, true)
                      . ' : le passant se présente avant la balise de la maison.';
        }
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Sur une passerelle Bluetooth en ville, la file ne contient plus que des '
        . "adresses aléatoires vues une fois : l'objet qu'on voulait justement "
        . 'adopter en a été chassé, et la liste devient inutilisable.');

    /* ------------------------------------------------------------------ 9 ---
     * Un passant finit par s'oublier — ET EN QUELQUES HEURES.
     *
     * Le délai était d'une semaine, et il ne pouvait rien trier : la date qu'il
     * compare est posée à l'arrivée d'un modèle, or un modèle n'est réémis que
     * s'il a CHANGÉ. Elle datait donc la dernière modification et non la
     * dernière vue, si bien qu'une balise bien présente vieillissait exactement
     * comme un fantôme. Sur l'installation réelle : cinquante candidats — la
     * file pleine —, tous vus pour la dernière fois soixante-six heures plus
     * tôt, aucun revu depuis, et plus une place pour un appareil du jour.
     *
     * L'adapter envoie désormais une preuve de vie par quart d'heure pour
     * chaque candidate qu'il voit encore. La date veut enfin dire ce qu'elle
     * dit, et le délai peut être court. */
    $titre = 'le candidat qu\'on ne voit plus quitte la file';
    mqttbeAdoptionRAZ(array('discovery::autoCreate' => 0));
    $maintenant = time();
    mqttbeFilePosee(array(
        'omg:ble:vieux000001' => mqttbeEntreeFile('omg:ble:vieux000001', 'Vu il y a dix heures',
                                                  $maintenant - 40000, $maintenant - 36000),
        'omg:ble:frais000001' => mqttbeEntreeFile('omg:ble:frais000001', 'Vu il y a une heure',
                                                  $maintenant - 7200, $maintenant - 3600),
    ));
    $fautes = array();
    $file = mqttbeDaemon::pendingQueue();
    if (!is_array($file)) {
        $fautes[] = 'la file est devenue illisible.';
    } else {
        if (isset($file['omg:ble:vieux000001'])) {
            $fautes[] = 'le candidat vu il y a dix heures est toujours là : sur une passerelle '
                      . 'qui voit passer la rue, la file se remplit en une soirée de téléphones '
                      . 'qui ne reviendront jamais.';
        }
        if (!isset($file['omg:ble:frais000001'])) {
            $fautes[] = 'le candidat vu il y a une heure a disparu : la péremption mord trop tôt, '
                      . 'et la file s\'efface pendant qu\'on la regarde.';
        }
    }
    /* La file doit aussi survivre un moment à l'arrêt du démon : ses candidats
     * restent adoptables sans lui, et l'utilisateur qui coupe la découverte
     * pour regarder ne doit pas voir sa liste fondre. */
    if (mqttbeDaemon::PENDING_TTL < 4 * 3600) {
        $fautes[] = 'le délai est tombé sous quatre heures : la file ne survivrait plus à un '
                  . 'arrêt du démon, alors que ce qu\'elle contient reste parfaitement adoptable.';
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        "La file conserve à vie des appareils qui ne sont plus là, et ce sont eux "
        . 'qui font sortir ceux qui le sont.');

    /* ----------------------------------------------------------------- 10 ---
     * Une file illisible n'est pas écrasée.
     *
     * Le cache de Jeedom écrit sans verrou ni renommage atomique : un lecteur
     * qui tombe au milieu de l'écriture lit un fichier tronqué, et la file
     * paraît VIDE. La réécrire alors avec le seul candidat en cours effaçait
     * tous les autres — « je ne sais pas » n'est pas « elle est vide ». */
    $titre = 'une file illisible ne s\'efface pas d\'elle-même';
    mqttbeAdoptionRAZ(array('discovery::autoCreate' => 0));
    cache::set('mqttbe::pending', 'ceci n\'est pas un tableau');
    $fautes = array();
    if (mqttbeDaemon::pendingQueue() !== null) {
        $fautes[] = 'pendingQueue() affirme connaître une file qu\'elle n\'a pas pu lire.';
    }
    if (mqttbeDaemon::forgetPending('omg:ble:aa0000000010') !== false) {
        $fautes[] = 'forgetPending() dit avoir retiré un candidat d\'une file illisible.';
    }
    mqttbeDaemon::onDiscovered(array(mqttbeCandidat('omg:ble:aa0000000011', 'Nouveau')));
    $apres = cache::byKey('mqttbe::pending')->getValue(null);
    if (is_array($apres)) {
        $fautes[] = 'la file illisible a été remplacée par un tableau d\'un seul candidat : '
                  . 'les autres sont perdus.';
    }
    if (!MqttbeFauxCoeur::journalContient("File d'adoption illisible")) {
        $fautes[] = 'rien dans le journal : la perte serait passée inaperçue.';
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Une lecture malheureuse pendant que le démon écrit vide la file : '
        . "l'utilisateur perd d'un coup tous les appareils qui attendaient sa "
        . 'décision, sans qu\'une ligne ne le dise.');

    /* ----------------------------------------------------------------- 11 ---
     * Le plafond de création met en file au lieu de créer.
     *
     * La découverte crée à partir de ce qui passe sur le broker, et rien
     * n'oblige ce qui passe à être honnête : n'importe qui pouvant publier sur
     * le topic d'annonce engendre autant d'équipements qu'il invente
     * d'identifiants. */
    $titre = 'plafond atteint : on demande au lieu de créer';
    mqttbeAdoptionRAZ(array('discovery::maxDevices' => 2));
    mqttbeDaemon::onDiscovered(array(
        mqttbeCandidat('omg:ble:plafond0001', 'Premier', 'certain'),
        mqttbeCandidat('omg:ble:plafond0002', 'Deuxième', 'certain'),
    ));
    $fautes = array();
    if (MqttbeFauxCoeur::equipement('omg:ble:plafond0001') === null
     || MqttbeFauxCoeur::equipement('omg:ble:plafond0002') === null) {
        $fautes[] = 'les deux premiers auraient dû être créés : le plafond mord trop tôt.';
    }
    /* Le lot suivant arrive avec le plafond déjà atteint. */
    mqttbeDaemon::onDiscovered(array(
        mqttbeCandidat('omg:ble:plafond0003', 'Troisième', 'certain'),
    ));
    if (MqttbeFauxCoeur::equipement('omg:ble:plafond0003') !== null) {
        $fautes[] = 'le troisième a été créé malgré le plafond.';
    }
    $file = mqttbeDaemon::pendingQueue();
    if (!is_array($file) || !isset($file['omg:ble:plafond0003'])) {
        $fautes[] = 'le troisième n\'est ni créé ni mis en file : il est perdu.';
    }
    $vus = false;
    foreach (message::messages() as $message) {
        if (strpos($message['message'], 'plafond') !== false) {
            $vus = true;
        }
    }
    if (!$vus) {
        $fautes[] = 'aucun message du centre de messages : le plafond serait atteint en silence.';
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Un appareil bavard, ou malveillant, remplit Jeedom d\'équipements '
        . 'inventés jusqu\'à rendre la page du plugin inutilisable — ou bien le '
        . 'plafond jette les appareils au lieu de les proposer.');

    /* ----------------------------------------------------------------- 12 ---
     * Revenir sur un refus.
     *
     * Se tromper de bouton ne doit pas être définitif, et le retrait doit
     * vraiment retirer : un refus qui resterait inscrit ferait un bouton
     * « Reproposer » sans effet, le plus décourageant des symptômes. */
    $titre = 'un refus peut être annulé, et ne laisse rien derrière lui';
    mqttbeAdoptionRAZ();
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000012');
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000013');
    mqttbeDaemon::forgetIgnored('omg:ble:aa0000000012');
    $fautes = array();
    $liste = mqttbeDaemon::ignoredUids();
    if (isset($liste['omg:ble:aa0000000012'])) {
        $fautes[] = 'le refus annulé est toujours inscrit.';
    }
    if (!isset($liste['omg:ble:aa0000000013'])) {
        $fautes[] = 'annuler un refus a emporté les autres.';
    }
    /* Et l'appareil redevient adoptable. */
    mqttbeAdoptionRAZ(array('discovery::autoCreate' => 0));
    mqttbeDaemon::ignoreUid('omg:ble:aa0000000012');
    mqttbeDaemon::forgetIgnored('omg:ble:aa0000000012');
    mqttbeDaemon::onDiscovered(array(mqttbeCandidat('omg:ble:aa0000000012', 'Repropose-moi')));
    $file = mqttbeDaemon::pendingQueue();
    if (!is_array($file) || !isset($file['omg:ble:aa0000000012'])) {
        $fautes[] = "l'appareil remis dans le circuit ne revient pas dans la file.";
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Le bouton « Reproposer » ne fait rien : un refus est définitif alors '
        . "que l'interface affirme le contraire.");

    /* ----------------------------------------------------------------- 13 ---
     * La liste des refus est bornée, et garde les plus récents.
     *
     * Elle vit en configuration, donc dans la base et dans les sauvegardes :
     * une maison très passante ne doit pas la faire enfler sans fin. Mais la
     * coupe doit sortir les plus anciens, pas les derniers arrivés. */
    $titre = 'la liste des refus est bornée sans perdre les plus récents';
    mqttbeAdoptionRAZ();
    $stockee = array();
    for ($i = 0; $i < 500; $i++) {
        $stockee[sprintf('omg:ble:vieux%07d', $i)] = array('at' => 1000 + $i, 'name' => '');
    }
    config::save('discovery::ignored', json_encode($stockee), 'mqttbe');
    mqttbeDaemon::ignoreUid('omg:ble:toutdernier');
    $fautes = array();
    $liste = mqttbeDaemon::ignoredUids();
    if (count($liste) > 500) {
        $fautes[] = 'la liste compte ' . count($liste) . ' refus : elle n\'est pas bornée.';
    }
    if (!isset($liste['omg:ble:toutdernier'])) {
        $fautes[] = 'le refus qu\'on vient d\'exprimer a été le premier jeté.';
    }
    if (isset($liste['omg:ble:vieux0000000'])) {
        $fautes[] = 'le plus ancien refus est resté : la coupe ne sort pas les bons.';
    }
    $resultats[] = mqttbeAdoptionVerdict($titre, $fautes,
        'Ou bien la configuration du plugin enfle sans fin sur une passerelle en '
        . 'ville, ou bien le refus que l\'utilisateur vient d\'exprimer est '
        . 'précisément celui qui est oublié.');

    return $resultats;
}

/** Les commandes d'un équipement, par son identifiant de découverte. */
function mqttbeCommandesAdoption($_uid) {
    $eqLogic = MqttbeFauxCoeur::equipement($_uid);
    return $eqLogic === null ? array() : MqttbeFauxCoeur::commandes($eqLogic);
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome("File d'adoption et refus", mqttbeControlesAdoption());
}
