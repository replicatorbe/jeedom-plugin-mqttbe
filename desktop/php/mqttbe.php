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

/*
 * Découverte : l'état des deux réglages décide de ce que la page a le droit de
 * laisser croire. Il est lu ici, côté serveur, et non par un aller-retour ajax :
 * une page qui annonce une découverte active pendant une seconde, avant de se
 * corriger, est pire qu'une page muette.
 */
$discoveryEnabled = (config::byKey('discovery::enabled', 'mqttbe', 1) == 1);
$discoveryAutoCreate = (config::byKey('discovery::autoCreate', 'mqttbe', 1) == 1);

/* Ce que la découverte a reconnu sans le créer, quand la création automatique
 * est désactivée. C'est du cache : son absence n'est pas une anomalie, elle dit
 * seulement que rien n'attend. */
$pending = array();
try {
    $valeur = cache::byKey('mqttbe::pending')->getValue(array());
    if (is_array($valeur)) {
        $pending = $valeur;
    }
} catch (Throwable $e) {
    $pending = array();
}

/*
 * Noms lisibles des adapters. La même table sert aux vignettes ci-dessous et au
 * bloc d'identité du panneau d'édition, côté JS : « shelly.gen1 » ne dit rien à
 * qui n'a pas lu le code, et c'est pourtant la seule chose qui distingue un
 * équipement venu tout seul d'un équipement saisi à la main.
 */
$mqttbeAdapters = array(
    'shelly.gen1'   => 'Shelly Gen1',
    'shelly.gen2'   => 'Shelly Gen2+',
    'tasmota'       => 'Tasmota',
    'zigbee2mqtt'   => 'Zigbee2MQTT',
    'homeassistant' => 'Home Assistant Discovery',
);
sendVarToJS('mqttbeAdapters', $mqttbeAdapters);
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
        /*
         * Découverte arrêtée : un bandeau discret, jamais une alerte rouge.
         * C'est un réglage volontaire dans bien des cas ; ce qu'il faut éviter,
         * c'est qu'on attende en vain des équipements qui ne viendront pas.
         */
        if (!$discoveryEnabled) {
            echo '<div class="alert alert-info" style="margin:5px;padding:6px 10px;">';
            echo '<i class="fas fa-eye-slash"></i> ';
            echo '{{La découverte automatique est désactivée : les appareils qui s\'annoncent sur le broker ne créent plus rien, seules les commandes saisies à la main restent écoutées.}} ';
            echo '<a class="cursor eqLogicAction" data-action="gotoPluginConf"><b>{{Ouvrir la configuration du plugin}}</b></a>';
            echo '</div>';
        } elseif (!$discoveryAutoCreate) {
            echo '<div class="alert alert-info" style="margin:5px;padding:6px 10px;">';
            echo '<i class="fas fa-user-check"></i> ';
            echo '{{La découverte reconnaît les appareils mais ne crée aucun équipement : la création automatique est désactivée.}} ';
            if (count($pending) > 0) {
                $noms = array();
                foreach ($pending as $candidat) {
                    $nom = isset($candidat['name']) ? trim((string) $candidat['name']) : '';
                    if ($nom !== '') {
                        $noms[] = htmlspecialchars($nom);
                    }
                    if (count($noms) >= 5) {
                        break;
                    }
                }
                echo '<b>' . count($pending) . ' {{appareil(s) vu(s) depuis le dernier démarrage du démon}}</b>';
                if (count($noms) > 0) {
                    echo ' : ' . implode(', ', $noms);
                    if (count($pending) > count($noms)) {
                        echo '…';
                    }
                }
                echo '. ';
            }
            echo '<a class="cursor eqLogicAction" data-action="gotoPluginConf"><b>{{Ouvrir la configuration du plugin}}</b></a>';
            echo '</div>';
        }
        /* Rempli par le JS quand un équipement est découvert page ouverte : la
         * liste des vignettes, elle, date du chargement. */
        echo '<div id="div_mqttbeDiscoveryLive" class="alert alert-success hidden" style="margin:5px;padding:6px 10px;"></div>';
        if (count($eqLogics) == 0) {
            echo '<div class="alert alert-info" style="margin:5px;">';
            if ($discoveryEnabled) {
                echo '<b>{{Aucun équipement pour le moment.}}</b> ';
                echo '{{La découverte écoute : un appareil qui s\'annonce apparaît ici tout seul. Un appareil connecté depuis longtemps, lui, ne s\'annonce plus — demandez-lui de se présenter avec le bouton « Relancer la découverte » de la configuration du plugin.}}';
                echo '<br><br>';
            }
            echo '<b>{{Pour créer un équipement à la main :}}</b>';
            echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
            echo '<li>{{Cliquez sur « Ajouter un équipement » et donnez-lui un nom.}}</li>';
            echo '<li>{{Indiquez son topic de base, par exemple shellies/shelly1pm-D8BFC01A0805 : il servira à préremplir le topic des commandes.}}</li>';
            echo '<li>{{Dans l\'onglet « Commandes », ajoutez une information pour lire une valeur, une action pour publier un message, puis enregistrez.}}</li>';
            echo '</ol>';
            echo '<span class="help-block" style="margin:8px 0 0 0;">{{La création à la main reste le moyen de traiter ce que la découverte ne sait pas encore reconnaître : elle ne connaît pour l\'instant que les Shelly Gen1.}}</span>';
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
            /* L'origine d'un équipement explique tout le reste : pourquoi ses
             * commandes sont apparues seules, pourquoi elles se remettront à
             * jour, et à qui s'en prendre quand quelque chose manque. */
            $adapter = trim((string) $eqLogic->getConfiguration('mqttbe::adapter', ''));
            $uid = trim((string) $eqLogic->getConfiguration('mqttbe::uid', ''));
            $adapterLabel = ($adapter !== '' && isset($mqttbeAdapters[$adapter])) ? $mqttbeAdapters[$adapter] : $adapter;
            $infobulle = ($topic === '' ? '{{Aucun topic de base}}' : htmlspecialchars($topic));
            if ($uid !== '') {
                $infobulle .= ' — ' . htmlspecialchars($uid);
            }
            echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '"';
            echo ' title="' . $infobulle . '">';
            echo '<i class="fas fa-broadcast-tower" style="font-size:4em;"></i>';
            echo '<br>';
            echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
            /* Une vignette est étroite : le texte est court et l'infobulle porte
             * la phrase entière plutôt que de la faire couper par l'ellipse. */
            if ($adapter !== '') {
                echo '<span class="mqttbeOrigin" title="{{Équipement créé par la découverte automatique}} — ' . htmlspecialchars($adapterLabel) . '"';
            } else {
                echo '<span class="mqttbeOrigin" title="{{Équipement créé à la main : la découverte ne le touche pas}}"';
            }
            echo ' style="display:block;font-size:0.8em;opacity:0.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">';
            if ($adapter !== '') {
                echo '<i class="fas fa-magic"></i> {{Découvert}} · ' . htmlspecialchars($adapterLabel);
            } else {
                echo '<i class="fas fa-hand-pointer"></i> {{Créé à la main}}';
            }
            echo '</span>';
            /*
             * L'adresse de l'appareil, cliquable.
             *
             * Dix-sept équipements nommés « Shelly 1 » suivis de six chiffres
             * ne disent rien de ce qu'ils commandent. Ouvrir la page de
             * l'appareil est le moyen le plus court de reconnaître lequel on
             * tient : on y voit son nom, on peut le faire clignoter, et on
             * revient le renommer dans Jeedom en connaissance de cause.
             *
             * `stopPropagation` est indispensable : la vignette entière est
             * cliquable pour ouvrir l'équipement, et sans cela le lien
             * ouvrirait les deux. Le lien n'est fabriqué que si l'adresse en
             * est réellement une — elle vient du réseau.
             */
            $ip = trim((string) $eqLogic->getConfiguration('mqttbe::ip', ''));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                echo '<span style="display:block;font-size:0.8em;opacity:0.7;">';
                echo '<a href="http://' . htmlspecialchars($ip) . '" target="_blank" rel="noopener"';
                echo ' onclick="event.stopPropagation();"';
                echo ' title="{{Ouvrir la page de l\'appareil dans un nouvel onglet : c\'est le moyen le plus sûr de savoir lequel c\'est}}">';
                echo '<i class="fas fa-external-link-alt"></i> ' . htmlspecialchars($ip);
                echo '</a></span>';
            }
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

                            <!-- Identité posée par la découverte, vide sur un équipement créé
                                 à la main : le bloc reste alors caché plutôt que d'aligner
                                 quatre champs vides.

                                 Ces champs ne portent volontairement pas la classe
                                 eqLogicAttr. Le cœur les remplirait en innerHTML avec des
                                 chaînes venues de l'appareil, donc du réseau, et les
                                 renverrait telles quelles à l'enregistrement : la page
                                 réécrirait alors une identité dont le serveur est seul
                                 propriétaire. printEqLogic les pose en texte, et rien ne
                                 repart. -->
                            <div id="div_mqttbeIdentity" style="display:none;">
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Identifiant}}</label>
                                    <div class="col-sm-5">
                                        <span id="span_mqttbeUid"></span>
                                    </div>
                                    <div class="col-sm-4">
                                        <span class="help-block" style="margin:0;">{{Identité du périphérique telle que la découverte l'a reconnue. Elle ne bouge pas quand l'appareil change d'adresse IP : c'est elle qui évite les doublons.}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Adaptateur}}</label>
                                    <div class="col-sm-5">
                                        <span id="span_mqttbeAdapter"></span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Marque}}</label>
                                    <div class="col-sm-5">
                                        <span id="span_mqttbeManufacturer"></span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Modèle}}</label>
                                    <div class="col-sm-5">
                                        <span id="span_mqttbeModel"></span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label">{{Adresse}}</label>
                                    <div class="col-sm-5">
                                        <span id="span_mqttbeIp"></span>
                                    </div>
                                    <div class="col-sm-4">
                                        <span class="help-block" style="margin:0;">{{Telle que l'appareil l'a annoncée. Ouvrez-la pour voir de quel appareil il s'agit, puis renommez-le ici.}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-3 control-label"></label>
                                    <div class="col-sm-9">
                                        <span class="help-block" style="margin:0;"><i class="fas fa-unlock"></i> {{Cet équipement a été créé par la découverte, et il reste le vôtre : renommez ses commandes, corrigez une unité, masquez ce qui ne vous sert pas. La découverte suivante ne remet à jour que la plomberie — topic écouté et chemin dans la charge utile — et laisse vos retouches en place.}}</span>
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
