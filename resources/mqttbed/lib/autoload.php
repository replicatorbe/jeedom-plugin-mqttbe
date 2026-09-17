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

/*
 * Chargeur PSR-4 des bibliothèques embarquées dans ce dossier.
 *
 * Le plugin n'installe rien chez l'utilisateur : ni apt, ni pip, ni npm, ni
 * composer. Une dépendance à installer, c'est un accès réseau, un compilateur
 * et des droits à obtenir sur une box qui n'en a pas toujours, et une panne de
 * plus à diagnostiquer à distance. Les trois bibliothèques sont donc copiées
 * dans le dépôt (voir VENDOR.md) et ce fichier remplace le vendor/autoload.php
 * que Composer aurait produit : douze lignes utiles contre un dossier vendor/.
 *
 * Les espaces de noms sont exclusifs au démon : aucun risque de collision avec
 * un chargeur du coeur de Jeedom, qui ne déclare rien sous ces préfixes.
 */
spl_autoload_register(function (string $class): void {
    static $prefixes = array(
        'PhpMqtt\\Client\\' => __DIR__ . '/php-mqtt/client/src/',
        'Psr\\Log\\'        => __DIR__ . '/psr/log/src/',
        'MyCLabs\\Enum\\'   => __DIR__ . '/myclabs/php-enum/src/',
    );
    foreach ($prefixes as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        // Un seul préfixe peut correspondre : inutile de poursuivre la boucle.
        return;
    }
});
