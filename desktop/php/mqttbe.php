<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('mqttbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/*
 * L'état affiché au chargement vient du serveur ; ensuite ce sont les
 * événements mqttbe::daemonState et mqttbe::brokerState qui entretiennent les
 * pastilles. Sans cet état initial, la page resterait vide jusqu'au premier
 * changement, c'est-à-dire potentiellement des heures sur une installation qui
 * tourne bien.
 */
$daemonRunning = false;
/* La page doit s'afficher même si la classe du plugin n'est pas chargeable :
 * c'est justement quand quelque chose ne va pas qu'on vient la consulter. */
if (class_exists('mqttbe') && method_exists('mqttbe', 'deamon_info')) {
    try {
        $daemonInfo = mqttbe::deamon_info();
        $daemonRunning = (isset($daemonInfo['state']) && $daemonInfo['state'] == 'ok');
    } catch (Throwable $e) {
        $daemonRunning = false;
    }
}
$brokerState = cache::byKey('mqttbe::brokerState')->getValue('nok');
$brokerHost = trim(config::byKey('broker::host', 'mqttbe', ''));
?>

<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter un équipement}}</span>
            </div>
            <div class="cursor logoSecondary" id="div_mqttbeDaemonTile">
                <i class="fas fa-heartbeat"></i>
                <br>
                <span>{{Démon}} <span id="span_mqttbeDaemonState" class="label label-default"><?php echo $daemonRunning ? '{{En marche}}' : '{{Arrêté}}'; ?></span></span>
            </div>
            <div class="cursor logoSecondary" id="div_mqttbeBrokerTile">
                <i class="fas fa-server"></i>
                <br>
                <span>{{Broker}} <span id="span_mqttbeBrokerState" class="label label-default"><?php echo ($brokerState == 'ok') ? '{{Connecté}}' : '{{Déconnecté}}'; ?></span></span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>

        <legend><i class="fas fa-plug"></i> {{Mes équipements MQTT}}</legend>
        <?php
        if ($brokerHost === '') {
            echo '<div class="alert alert-warning" style="margin:5px;">';
            echo '<b>{{Aucune adresse de broker n\'est renseignée.}}</b> ';
            echo '{{Ouvrez la configuration du plugin et saisissez l\'adresse de votre broker MQTT : c\'est le seul réglage nécessaire pour démarrer.}}';
            echo '</div>';
        }
        if (count($eqLogics) == 0) {
            echo '<div class="alert alert-info" style="margin:5px;">';
            echo '<b>{{Aucun équipement pour le moment. Pour en créer un à la main :}}</b>';
            echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
            echo '<li>{{Cliquez sur « Ajouter un équipement » et donnez-lui un nom.}}</li>';
            echo '<li>{{Indiquez son topic de base, par exemple shellies/shelly1pm-D8BFC01A0805 : il servira à préremplir le topic des commandes.}}</li>';
            echo '<li>{{Dans l\'onglet « Commandes », ajoutez une information pour lire une valeur, une action pour publier un message, puis enregistrez.}}</li>';
            echo '</ol>';
            echo '<span class="help-block" style="margin:8px 0 0 0;">{{La découverte automatique — Shelly, Tasmota, Zigbee2MQTT, Home Assistant Discovery — viendra plus tard. La création à la main restera de toute façon le moyen de traiter les cas que la découverte ne sait pas reconnaître.}}</span>';
            echo '</div>';
        }
        echo '<div class="input-group" style="margin:5px;">';
        echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
        echo '<div class="input-group-btn">';
        echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
        echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="eqLogicThumbnailContainer">';
        foreach ($eqLogics as $eqLogic) {
            $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
            $topic = trim((string) $eqLogic->getConfiguration('mqttbe::topic', ''));
            echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '"';
            echo ' title="' . ($topic === '' ? '{{Aucun topic de base}}' : htmlspecialchars($topic)) . '">';
            echo '<i class="fas fa-broadcast-tower" style="font-size:4em;"></i>';
            echo '<br>';
            echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
            echo '<span class="hiddenAsCard displayTableRight hidden">';
            echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
            echo '</span>';
            echo '</div>';
        }
        echo '</div>';
        ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
                <a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
        </ul>

        <div class="tab-content">
            <!-- ========================= ÉQUIPEMENT ========================= -->
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br>
                <div class="col-lg-6">
                    <form class="form-horizontal">
                        <fieldset>
                            <legend><i class="fas fa-tag"></i> {{Général}}</legend>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Nom}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Équipement MQTT}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        foreach ((jeeObject::buildTree(null, false)) as $object) {
                                            echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Catégorie}}</label>
                                <div class="col-sm-8">
                                    <?php
                                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline">';
                                        echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
                                        echo '</label>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Activer}}</label>
                                <div class="col-sm-8">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Visible}}</label>
                                <div class="col-sm-8">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Commentaire}}</label>
                                <div class="col-sm-8">
                                    <textarea class="eqLogicAttr form-control" data-l1key="comment" rows="2"></textarea>
                                </div>
                            </div>
                        </fieldset>
                    </form>
                </div>

                <div class="col-lg-6">
                    <form class="form-horizontal">
                        <fieldset>
                            <legend><i class="fas fa-broadcast-tower" style="font-size:1em;"></i> {{MQTT}}</legend>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Topic de base}}</label>
                                <div class="col-sm-5">
                                    <input type="text" class="eqLogicAttr form-control" id="in_mqttbeBaseTopic" data-l1key="configuration" data-l2key="mqttbe::topic" placeholder="shellies/shelly1pm-D8BFC01A0805">
                                </div>
                                <div class="col-sm-4">
                                    <span class="help-block" style="margin:0;">{{Le préfixe commun aux topics de cet équipement. Il sert à préremplir le topic des commandes que vous ajoutez : c'est le topic de chaque commande, et lui seul, qui décide de ce qui est écouté ou publié.}}</span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label">{{Dernière communication}}</label>
                                <div class="col-sm-5">
                                    <span class="eqLogicAttr label label-default" data-l1key="status" data-l2key="lastCommunication"></span>
                                </div>
                                <div class="col-sm-4">
                                    <span class="help-block" style="margin:0;">{{Date de la dernière valeur reçue sur une commande de cet équipement.}}</span>
                                </div>
                            </div>

                            <!-- Renseignée par la découverte automatique, vide tant qu'un
                                 équipement est créé à la main : le bloc reste caché dans ce
                                 cas plutôt que d'aligner quatre champs vides. -->
                            <div id="div_mqttbeIdentity" style="display:none;">
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Identifiant}}</label>
                                    <div class="col-sm-5">
                                        <span class="eqLogicAttr" data-l1key="configuration" data-l2key="mqttbe::uid"></span>
                                    </div>
                                    <div class="col-sm-4">
                                        <span class="help-block" style="margin:0;">{{Identité du périphérique telle que la découverte l'a reconnue.}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Origine}}</label>
                                    <div class="col-sm-5">
                                        <span class="eqLogicAttr" data-l1key="configuration" data-l2key="mqttbe::adapter"></span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Matériel}}</label>
                                    <div class="col-sm-5">
                                        <span class="eqLogicAttr" data-l1key="configuration" data-l2key="mqttbe::manufacturer"></span>
                                        <span class="eqLogicAttr" data-l1key="configuration" data-l2key="mqttbe::model"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-3 control-label"></label>
                                <div class="col-sm-9">
                                    <span class="help-block" style="margin:0;">{{Rien n'est écouté tant qu'aucune commande d'information ne porte de topic : c'est la liste des commandes qui décide des abonnements du démon.}}</span>
                                </div>
                            </div>
                        </fieldset>
                    </form>
                </div>
            </div>

            <!-- ========================== COMMANDES ========================== -->
            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br>
                <div class="col-xs-12">
                    <div style="margin-bottom:8px;">
                        <a class="btn btn-default btn-sm mqttbeAction" data-action="addInfoCmd"><i class="fas fa-plus-circle"></i> {{Ajouter une information}}</a>
                        <a class="btn btn-default btn-sm mqttbeAction" data-action="addActionCmd"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
                    </div>

                    <div class="alert alert-info" style="margin:5px 0 10px 0;">
                        <b>{{Une information lit un topic, une action publie sur un topic.}}</b>
                        {{Pour une information, indiquez le topic écouté ; si sa charge utile est du JSON, le chemin dit quelle valeur en extraire — par exemple}}
                        <code>emeter.0.power</code> {{dans}} <code>{"emeter":[{"power":128.4}]}</code>.
                        {{Laissez le chemin vide pour prendre la charge utile telle quelle. Pour une action, indiquez le topic et le message à publier.}}
                        <span class="help-block" style="margin:8px 0 0 0;">{{Les réglages fins — correspondance, échelle, arrondi, répétition — sont repliés derrière le bouton « Réglages fins » de chaque ligne d'information : le cas courant, un topic et une valeur, n'a pas besoin d'eux.}}</span>
                    </div>

                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th style="width:210px;">{{Nom}}</th>
                                <th style="width:130px;">{{Type}}</th>
                                <th>{{Topic}}</th>
                                <th style="width:300px;">{{Valeur}}</th>
                                <th style="width:230px;">{{Options}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_file('desktop', 'mqttbe', 'js', 'mqttbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
