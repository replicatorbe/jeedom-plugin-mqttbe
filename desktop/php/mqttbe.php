<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('mqttbe');
sendVarToJS('eqType', $plugin->getId());

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
        ?>
        <div class="alert alert-info" style="margin:5px;">
            <b>{{Le plugin n'en est qu'à sa connexion au broker.}}</b>
            {{À ce stade, il établit et maintient la liaison, et affiche ci-dessus l'état du démon et celui du broker. La découverte automatique des équipements — Shelly, Tasmota, Zigbee2MQTT, Home Assistant Discovery — arrive au jalon suivant : aucun équipement n'est créé pour le moment, et cette page en restera vide jusque-là.}}
            <span class="help-block" style="margin:8px 0 0 0;">{{Les deux pastilles disent des choses différentes : le démon peut tourner sans que le broker réponde, et c'est alors du côté de l'adresse, du port ou des identifiants qu'il faut chercher.}}</span>
        </div>

        <!-- Les équipements découverts viendront se ranger ici, en vignettes,
             dès que la découverte sera en place. Le conteneur existe déjà pour
             que le cœur y trouve sa liste et que la mise en page ne change pas
             le jour où elle se remplira. -->
        <div class="eqLogicThumbnailContainer"></div>
    </div>
</div>

<?php include_file('desktop', 'mqttbe', 'js', 'mqttbe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
