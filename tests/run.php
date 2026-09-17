<?php
/* Suite de contrôles hors ligne du plugin mqttbe.
 *
 *   php tests/run.php
 *
 * Ni Jeedom, ni base de données, ni broker : tout se lit dans le texte des
 * sources. Ce que ces contrôles attrapent n'a rien d'exotique — une propriété
 * sans souligné, une méthode qui porte un nom réservé par le cœur, un nom de
 * commande écrit différemment de part et d'autre du démon, une clé de
 * configuration sans défaut. Leur point commun est ailleurs : aucun ne se voit
 * à la relecture ni au « php -l », et tous se manifestent bien plus tard, sous
 * la forme d'un symptôme qui ne désigne pas sa cause.
 *
 * Code de retour non nul dès qu'un contrôle échoue : la suite est faite pour
 * tourner en intégration continue autant que sous les yeux de quelqu'un.
 *
 * Les sources du plugin s'écrivent encore. Un contrôle dont les fichiers
 * n'existent pas est annoncé « non vérifiable » et ne fait pas échouer la
 * suite : un contrôle qui ne peut rien affirmer ne doit pas non plus nier. */

require_once __DIR__ . '/outils.php';
require_once __DIR__ . '/check-classes.php';
require_once __DIR__ . '/check-protocol.php';
require_once __DIR__ . '/check-config.php';
require_once __DIR__ . '/check-capabilities.php';
require_once __DIR__ . '/check-i18n.php';
require_once __DIR__ . '/check-discovery.php';
require_once __DIR__ . '/check-nameprobe.php';
require_once __DIR__ . '/check-shelly-gen1.php';
require_once __DIR__ . '/check-shelly-gen2.php';
require_once __DIR__ . '/check-omg.php';
/* Ces deux-là ne lisent pas les sources : ils font tourner les classes qui
 * écrivent en base, sur le cœur de papier de tests/faux-coeur.php. */
require_once __DIR__ . '/check-factory.php';
require_once __DIR__ . '/check-routing.php';

/*
 * check-adoption.php, lui, n'est PAS inclus ici.
 *
 * Il charge le vrai mqttbeDaemon, là où les deux contrôles ci-dessus emploient
 * le double que faux-coeur.php définit sous ce même nom. Un seul des deux peut
 * exister par processus. Il est donc lancé à part, et son bilan replié dans
 * celui de la suite : « php tests/run.php » reste la seule commande à taper, et
 * un échec de la file d'adoption fait toujours échouer la suite entière.
 */
function mqttbeSousProcessus($_fichier) {
    $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $_fichier);
    $sortie = array();
    $code = 0;
    exec($commande . ' 2>&1', $sortie, $code);
    return array('sortie' => $sortie, 'code' => $code);
}

/* Relit le bilan que le contrôle a imprimé. On ne recompte pas : on lit ce
 * qu'il a annoncé, et l'absence de bilan est elle-même un échec — un contrôle
 * mort sur une erreur fatale n'a rien affirmé du tout. */
function mqttbeBilanLu($_sortie) {
    foreach ($_sortie as $ligne) {
        if (preg_match('/==>\s*(\d+) réussi\(s\), (\d+) échec\(s\), (\d+) non vérifiable/u', $ligne, $trouve)) {
            return array(MQTTBE_OK => (int) $trouve[1], MQTTBE_ECHEC => (int) $trouve[2],
                         MQTTBE_INDECIS => (int) $trouve[3]);
        }
    }
    return null;
}

$sections = array(
    'Conformité aux conventions du cœur Jeedom' => mqttbeControlesClasses(),
    'Contrat démon ↔ Jeedom'                    => mqttbeControlesProtocole(),
    'Clés de configuration'                     => mqttbeControlesConfig(),
    'Vocabulaire des capacités'                 => mqttbeControlesCapacites(),
    'Traductions'                               => mqttbeControlesI18n(),
    'Moteur de découverte'                      => mqttbeControlesDecouverte(),
    'Sondes de nom'                             => mqttbeControlesSondeNom(),
    'Découverte Shelly Gen1'                    => mqttbeControlesShellyGen1(),
    'Découverte Shelly Gen2+'                   => mqttbeControlesShellyGen2(),
    'Découverte OpenMQTTGateway'                => mqttbeControlesOmg(),
    'Fabrique : écriture en base'               => mqttbeControlesFabrique(),
    'Table de routage'                          => mqttbeControlesRoutage(),
);

$tous = array();
foreach ($sections as $titre => $resultats) {
    echo "\n== " . $titre . " ==\n";
    mqttbeAffiche($resultats);
    $tous = array_merge($tous, $resultats);
}

$bilan = mqttbeBilan($tous);

/* La file d'adoption, dans son propre processus. */
$aPart = mqttbeSousProcessus('check-adoption.php');
foreach ($aPart['sortie'] as $ligne) {
    /* Le bilan du sous-processus n'est pas réimprimé : il est replié dans
     * celui de la suite, et deux bilans se contrediraient à l'œil. */
    if (strpos($ligne, '==>') === false) {
        echo $ligne . "\n";
    }
}
$bilanAPart = mqttbeBilanLu($aPart['sortie']);
if ($bilanAPart === null) {
    echo "\n  ÉCHEC : check-adoption.php n'a rien annoncé (code de retour "
       . $aPart['code'] . ") — il est tombé avant de conclure.\n";
    $bilan[MQTTBE_ECHEC]++;
} else {
    foreach ($bilanAPart as $etat => $nombre) {
        $bilan[$etat] += $nombre;
    }
}

printf("\n  ==> %d réussi(s), %d échec(s), %d non vérifiable(s)\n",
       $bilan[MQTTBE_OK], $bilan[MQTTBE_ECHEC], $bilan[MQTTBE_INDECIS]);
if ($bilan[MQTTBE_INDECIS] > 0) {
    echo "  (« non vérifiable » = le fichier concerné n'existe pas encore ; à revoir quand il sera écrit)\n";
}
echo "\n";
exit($bilan[MQTTBE_ECHEC] === 0 ? 0 : 1);
