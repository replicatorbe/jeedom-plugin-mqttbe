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

/* Une modale de plugin est incluse par index.php, qui n'a vérifié que la
 * connexion : le profil, lui, se contrôle ici, comme sur la page du plugin. */
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

/*
 * Cette page ne lit rien et n'écrit rien : elle pose la charpente, et le JS la
 * remplit depuis l'action ajax « pending ». C'est voulu — la liste change
 * pendant qu'on la regarde, une balise peut arriver ou disparaître entre
 * l'ouverture et la décision, et un contenu figé au chargement mentirait au
 * bout de quelques secondes. Tout ce qui vient d'un appareil est posé en texte
 * par le JS, jamais en balisage : ces noms et ces modèles sont annoncés par des
 * appareils qui écrivent ce qu'ils veulent.
 */
?>
<style>
/* L'en-tête reste visible pendant qu'on fait défiler : à la trentième ligne,
   on ne sait plus quelle colonne dit quoi, et la file d'adoption est justement
   longue quand elle sert. `background: inherit` suit le thème clair ou sombre
   sans le nommer. */
#table_mqttbePending thead th,
#table_mqttbeIgnored thead th {
    position: sticky;
    top: 0;
    background: inherit;
    z-index: 1;
}
</style>
<div id="div_mqttbeAdoption">

    <div class="alert alert-info" style="margin:0 0 10px 0;padding:8px 12px;">
        <b>{{Ces appareils se sont annoncés sur le broker et rien n'a été créé pour eux : ce n'est pas une panne, c'est une question.}}</b>
        {{La découverte reconnaît ce qu'elle sait reconnaître ; le reste, elle le met de côté plutôt que de présumer à votre place. Une passerelle Bluetooth voit passer le téléphone d'un visiteur, la montre du voisin et le traceur accroché à vos clés : seul vous savez lesquels méritent un équipement.}}
        <span class="help-block" style="margin:8px 0 0 0;">
            <i class="fas fa-fingerprint"></i>
            {{Une adresse Bluetooth aléatoire (random) est changée par l'appareil toutes les quinze minutes environ : c'est la marque d'un téléphone ou d'une montre de passage, et l'équipement créé deviendrait muet au prochain changement. Une adresse publique (public) ne bouge pas : c'est celle d'une balise ou d'un capteur, et elle mérite un équipement.}}
        </span>
        <span class="help-block" style="margin:6px 0 0 0;">
            <i class="fas fa-clock"></i>
            {{La durée de présence dit le reste : un objet de la maison est là depuis des jours, un passant depuis deux minutes.}}
        </span>
    </div>

    <div style="margin:0 0 8px 0;">
        <a class="btn btn-default btn-sm cursor mqttbeAdoptionAction" data-action="refresh">
            <i class="fas fa-sync"></i> {{Rafraîchir}}
        </a>
        <span class="help-block" style="display:inline-block;margin:0 0 0 10px;">
            {{La file se remplit au fil des annonces : ce qui manque ici n'a encore rien dit.}}
        </span>
    </div>

    <!-- Compte rendu des décisions : rempli en texte par le JS, jamais en
         balisage, et laissé en place après chaque action pour qu'on lise ce
         qui vient d'être créé. -->
    <div id="div_mqttbeAdoptionStatus" class="hidden"></div>

    <div id="div_mqttbeAdoptionEmpty" class="alert alert-info hidden" style="margin:0 0 10px 0;padding:8px 12px;">
        <b>{{Aucun appareil n'attend de décision.}}</b>
        {{Tout ce que la découverte a reconnu a été créé, et rien d'inconnu ne s'est annoncé depuis le démarrage du démon. Un appareil connecté depuis longtemps ne s'annonce plus de lui-même : le bouton « Relancer la découverte » de la configuration du plugin lui redemande de se présenter.}}
    </div>

    <div style="max-height:45vh;overflow:auto;">
        <table id="table_mqttbePending" class="table table-bordered table-condensed hidden">
            <thead>
                <tr>
                    <th style="width:24%;">{{Appareil}}</th>
                    <th>{{Ce qu'on en sait}}</th>
                    <th style="width:13%;">{{Présent depuis}}</th>
                    <th style="width:13%;">{{Dernière trame}}</th>
                    <th style="width:9%;">{{Commandes}}</th>
                    <th style="width:16%;">{{Décision}}</th>
                </tr>
            </thead>
            <tbody id="tbody_mqttbePending"></tbody>
        </table>
    </div>

    <!-- Ce qu'on a écarté. Replié : c'est une liste qu'on ne consulte que
         lorsqu'on s'est trompé de bouton, et elle ne doit pas encombrer la
         décision en cours. Masqué tant que rien n'a été écarté. -->
    <div id="div_mqttbeIgnoredBlock" class="hidden" style="margin-top:12px;">
        <a class="cursor mqttbeAdoptionAction" data-action="toggleIgnored" id="bt_mqttbeIgnoredToggle">
            <i class="fas fa-caret-right" id="i_mqttbeIgnoredCaret"></i>
            <span id="span_mqttbeIgnoredLabel">{{Appareils écartés}}</span>
        </a>
        <div id="div_mqttbeIgnoredList" class="hidden" style="margin-top:6px;">
            <span class="help-block" style="margin:0 0 6px 0;">
                <i class="fas fa-undo"></i>
                {{Écarter n'est pas définitif : remis dans le circuit, l'appareil reparaîtra dans la file à sa prochaine annonce — immédiatement s'il parle en continu, après une relance de la découverte s'il s'est tu.}}
            </span>
            <table id="table_mqttbeIgnored" class="table table-bordered table-condensed">
                <thead>
                    <tr>
                        <th>{{Appareil écarté}}</th>
                        <th style="width:20%;">{{Écarté}}</th>
                        <th style="width:20%;">{{Revenir en arrière}}</th>
                    </tr>
                </thead>
                <tbody id="tbody_mqttbeIgnored"></tbody>
            </table>
        </div>
    </div>

</div>
