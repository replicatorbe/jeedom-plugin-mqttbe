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
require_once __DIR__ . '/check-discovery.php';
require_once __DIR__ . '/check-nameprobe.php';
require_once __DIR__ . '/check-shelly-gen1.php';
require_once __DIR__ . '/check-omg.php';
/* Ces deux-là ne lisent pas les sources : ils font tourner les classes qui
 * écrivent en base, sur le cœur de papier de tests/faux-coeur.php. */
require_once __DIR__ . '/check-factory.php';
require_once __DIR__ . '/check-routing.php';

$sections = array(
    'Conformité aux conventions du cœur Jeedom' => mqttbeControlesClasses(),
    'Contrat démon ↔ Jeedom'                    => mqttbeControlesProtocole(),
    'Clés de configuration'                     => mqttbeControlesConfig(),
    'Vocabulaire des capacités'                 => mqttbeControlesCapacites(),
    'Moteur de découverte'                      => mqttbeControlesDecouverte(),
    'Sondes de nom'                             => mqttbeControlesSondeNom(),
    'Découverte Shelly Gen1'                    => mqttbeControlesShellyGen1(),
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
printf("\n  ==> %d réussi(s), %d échec(s), %d non vérifiable(s)\n",
       $bilan[MQTTBE_OK], $bilan[MQTTBE_ECHEC], $bilan[MQTTBE_INDECIS]);
if ($bilan[MQTTBE_INDECIS] > 0) {
    echo "  (« non vérifiable » = le fichier concerné n'existe pas encore ; à revoir quand il sera écrit)\n";
}
echo "\n";
exit($bilan[MQTTBE_ECHEC] === 0 ? 0 : 1);
