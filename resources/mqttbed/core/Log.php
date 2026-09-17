<?php
/* This file is part of the mqttbe plugin for Jeedom.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/* =============================================================================
 * Journal du démon.
 *
 * Tout part sur STDOUT : c'est le lanceur (mqttbe::deamon_start) qui redirige
 * la sortie vers log::getPathToLog('mqttbed'). Le démon n'ouvre donc aucun
 * fichier lui-même — il n'a ni à connaître le chemin des journaux de Jeedom,
 * ni à gérer la rotation, ni à découvrir qu'il n'a pas le droit d'écrire là où
 * il croyait pouvoir.
 *
 * Statique, comme dans le plugin Dahua VTO voisin : un journal est un service
 * global, et le passer en paramètre à chaque objet n'apporterait rien ici.
 * ========================================================================== */
class MqttbeLog {

    const LEVELS = array('debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'none' => 99);

    /* Par défaut le démon ne dit que ses erreurs : un niveau bavard choisi par
     * défaut remplirait le journal d'une installation qui n'a rien demandé. */
    private static $min = 3;

    public static function setLevel($_level) {
        $level = strtolower(trim((string) $_level));
        if (!isset(self::LEVELS[$level])) {
            return false;
        }
        self::$min = self::LEVELS[$level];
        return true;
    }

    public static function level() {
        foreach (self::LEVELS as $name => $value) {
            if ($value === self::$min) {
                return $name;
            }
        }
        return 'error';
    }

    /*
     * Interrogé avant de fabriquer un message coûteux : sur un broker chargé,
     * concaténer le topic et la charge utile de plusieurs milliers de messages
     * par seconde coûte plus cher que de les écrire, et ce travail serait jeté
     * juste après par write().
     */
    public static function isDebug() {
        return self::$min <= 0;
    }

    public static function write($_level, $_msg) {
        $weight = isset(self::LEVELS[$_level]) ? self::LEVELS[$_level] : 3;
        if ($weight < self::$min) {
            return;
        }
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '][' . strtoupper($_level) . '] ' . $_msg . PHP_EOL);
    }

    public static function debug($_m)   { self::write('debug', $_m); }
    public static function info($_m)    { self::write('info', $_m); }
    public static function warning($_m) { self::write('warning', $_m); }
    public static function error($_m)   { self::write('error', $_m); }
}
