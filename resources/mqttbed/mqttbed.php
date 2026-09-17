#!/usr/bin/env php
<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ---------------------------------------------------------------- garde CLI ---
 * Sur une installation où Apache est en AllowOverride None, les .htaccess sont
 * ignorés et ce fichier est accessible en HTTP : cette garde est la seule
 * protection réelle contre un déclenchement depuis l'extérieur.
 */
if (php_sapi_name() != 'cli' || isset($_SERVER['REQUEST_METHOD']) || !isset($_SERVER['argc'])) {
    header('HTTP/1.0 404 Not Found');
    echo '<h1>404 Not Found</h1>';
    exit(1);
}

/* Les signaux sont livrés entre deux instructions PHP plutôt qu'aux points
 * d'appel de pcntl_signal_dispatch : la boucle passe l'essentiel de son temps
 * dans stream_select, et un SIGTERM reçu là doit être vu tout de suite. */
pcntl_async_signals(true);

/* -------------------------------------------------------------------- options */
$opt = getopt('', array('callback:', 'apikey:', 'pid:', 'socketport:', 'loglevel:'));

foreach (array('callback', 'pid') as $required) {
    if (!isset($opt[$required]) || trim((string) $opt[$required]) === '') {
        fwrite(STDERR, 'Argument manquant : --' . $required . PHP_EOL);
        fwrite(STDERR, 'Usage : mqttbed.php --callback <url> --pid <fichier> '
                     . '[--socketport <n>] [--loglevel <niveau>]  (clé d\'API sur STDIN)' . PHP_EOL);
        exit(1);
    }
}

/*
 * La clé d'API est lue sur STDIN quand elle n'est pas passée en argument : une
 * ligne de commande est visible par tout utilisateur local via `ps`, et cette
 * clé ouvre l'API de Jeedom en entier. --apikey ne reste accepté que pour les
 * essais à la main.
 */
if (!isset($opt['apikey'])) {
    $line = fgets(STDIN);
    $opt['apikey'] = ($line === false) ? '' : trim($line);
}
if (trim((string) $opt['apikey']) === '') {
    fwrite(STDERR, 'Clé d\'API absente (première ligne de STDIN, ou --apikey)' . PHP_EOL);
    exit(1);
}

/* Le port de la socket de commande a une valeur par défaut — celle du plugin —
 * alors que le fichier PID et l'URL de rappel n'en ont aucune qui ait un sens. */
$socketport = isset($opt['socketport']) ? (int) $opt['socketport'] : 55062;
if ($socketport < 1024 || $socketport > 65535) {
    fwrite(STDERR, 'Port de socket invalide : ' . $socketport . PHP_EOL);
    exit(1);
}

/* ----------------------------------------------------------------- chargement */
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/core/Log.php';
require_once __DIR__ . '/core/Config.php';
require_once __DIR__ . '/core/JeedomLink.php';
require_once __DIR__ . '/core/CommandSocket.php';
require_once __DIR__ . '/core/Loop.php';
require_once __DIR__ . '/mqtt/MqttTransport.php';
require_once __DIR__ . '/mqtt/PhpMqttTransport.php';

MqttbeLog::setLevel(isset($opt['loglevel']) ? $opt['loglevel'] : 'error');

/*
 * Le démon ne charge pas le coeur de Jeedom, donc jamais son
 * date_default_timezone_set : sans ce rattrapage, PHP en ligne de commande
 * date tout en UTC et le journal du démon est décalé de une ou deux heures par
 * rapport à celui de Jeedom, juste assez pour que personne ne rapproche plus
 * un événement de sa cause. On suit l'heure du système, que Jeedom suit aussi.
 */
$tz = mqttbedSystemTimezone();
if ($tz !== null) {
    date_default_timezone_set($tz);
}

function mqttbedSystemTimezone() {
    // Un php.ini qui tranche la question a toujours raison contre nous.
    if (trim((string) ini_get('date.timezone')) !== '') {
        return null;
    }
    if (is_readable('/etc/timezone')) {
        $tz = trim((string) @file_get_contents('/etc/timezone'));
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            return $tz;
        }
    }
    if (is_link('/etc/localtime')) {
        $target = (string) @readlink('/etc/localtime');
        $pos = strpos($target, 'zoneinfo/');
        if ($pos !== false) {
            $tz = substr($target, $pos + 9);
            if (in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        }
    }
    return null;
}

/* ------------------------------------------------------------- fichier PID ---
 * Deux démons sur le même broker, c'est un client MQTT dédoublé : le broker
 * n'accepte qu'une session par identifiant client et déconnecte le premier
 * arrivé, les deux processus se chassant alors l'un l'autre indéfiniment. Le
 * fichier PID est la seule protection contre un double lancement, et il ne
 * vaut que si l'on vérifie que le processus qu'il désigne vit encore : un
 * démon tué par l'OOM killer laisse son fichier derrière lui.
 */
function mqttbedIsAlive($_pid) {
    if (function_exists('posix_kill')) {
        /* Le signal 0 ne fait rien : il ne sert qu'à demander au noyau si le
         * processus existe et s'il nous appartient. */
        return @posix_kill($_pid, 0);
    }
    return is_dir('/proc/' . $_pid);
}

$pidFile = (string) $opt['pid'];
if (file_exists($pidFile)) {
    $previous = (int) trim((string) @file_get_contents($pidFile));
    if ($previous > 0 && $previous !== getmypid() && mqttbedIsAlive($previous)) {
        fwrite(STDERR, 'Un démon mqttbe tourne déjà (pid ' . $previous . ')' . PHP_EOL);
        exit(1);
    }
    MqttbeLog::warning('fichier PID résiduel (pid ' . $previous . ' absent), il est remplacé');
}
if (@file_put_contents($pidFile, getmypid() . PHP_EOL) === false) {
    fwrite(STDERR, 'Écriture du fichier PID impossible : ' . $pidFile . PHP_EOL);
    exit(1);
}

/* --------------------------------------------------------------- assemblage */
$config    = new MqttbeConfig();
$transport = new MqttbePhpMqttTransport($config);
$link      = new MqttbeJeedomLink(
    $opt['callback'],
    $opt['apikey'],
    /* uid = pid:port — Jeedom s'en sert pour reconnaître SON démon et ignorer
     * les messages d'un processus survivant d'un lancement précédent. */
    getmypid() . ':' . $socketport,
    0.2
);
$commands = new MqttbeCommandSocket($socketport, $opt['apikey']);
$loop     = new MqttbeLoop($config, $transport, $link, $commands, $pidFile);

/* ------------------------------------------------------------------ signaux */
$shutdown = function ($_signo) use ($loop) {
    MqttbeLog::info('signal ' . $_signo . ' reçu, arrêt en cours');
    $loop->stop();
};
pcntl_signal(SIGTERM, $shutdown);
pcntl_signal(SIGINT,  $shutdown);
pcntl_signal(SIGHUP,  $shutdown);

exit($loop->run());
