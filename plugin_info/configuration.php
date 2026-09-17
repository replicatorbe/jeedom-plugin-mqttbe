<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
    <fieldset>
        <legend><i class="fas fa-server"></i> {{Broker}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Adresse}}</label>
            <div class="col-sm-6">
                <input class="configKey form-control" data-l1key="broker::host" placeholder="192.168.1.10" />
            </div>
            <div class="col-sm-3">
                <span class="help-block" style="margin:0;">{{Adresse IP ou nom d'hôte du broker MQTT. C'est le seul réglage indispensable.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Port}}</label>
            <div class="col-sm-2">
                <input class="configKey form-control" data-l1key="broker::port" placeholder="1883" />
            </div>
            <div class="col-sm-7">
                <span class="help-block" style="margin:0;">{{1883 en clair, 8883 en TLS. Ce sont les ports d'usage, pas une obligation.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Identifiant}}</label>
            <div class="col-sm-4">
                <input class="configKey form-control" data-l1key="broker::username" autocomplete="off" />
            </div>
            <div class="col-sm-5">
                <span class="help-block" style="margin:0;">{{À laisser vide si le broker accepte les connexions anonymes.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Mot de passe}}</label>
            <div class="col-sm-4">
                <input type="password" class="configKey form-control" data-l1key="broker::password" autocomplete="new-password" />
            </div>
            <div class="col-sm-5">
                <span class="help-block" style="margin:0;">{{MQTT transmet le mot de passe en clair hors TLS : sur un réseau partagé, activez le chiffrement ci-dessous.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Intervalle de maintien (s)}}</label>
            <div class="col-sm-2">
                <input class="configKey form-control" data-l1key="broker::keepalive" placeholder="60" />
            </div>
            <div class="col-sm-7">
                <span class="help-block" style="margin:0;">{{Silence toléré par le broker avant qu'il ne déclare la connexion morte. Le démon lui envoie un signe de vie en deçà de ce délai.}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-lock"></i> {{Chiffrement}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Activer TLS}}</label>
            <div class="col-sm-2">
                <input type="checkbox" class="configKey" data-l1key="broker::tls" />
            </div>
            <div class="col-sm-7">
                <span class="help-block" style="margin:0;">{{Pensez à changer le port : un broker en TLS écoute rarement sur 1883.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Certificat de l'autorité}}</label>
            <div class="col-sm-4">
                <input class="configKey form-control" data-l1key="broker::caFile" placeholder="/etc/ssl/certs/ma-ca.crt" />
            </div>
            <div class="col-sm-5">
                <span class="help-block" style="margin:0;">{{Chemin d'un fichier CA sur la machine Jeedom, à renseigner si le certificat du broker n'est pas signé par une autorité publique. Vide, ce sont les autorités du système qui font foi.}}</span>
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Ne pas vérifier le certificat}}</label>
            <div class="col-sm-2">
                <input type="checkbox" class="configKey" data-l1key="broker::tlsInsecure" />
            </div>
            <div class="col-sm-7">
                <span class="help-block" style="margin:0;">{{Le chiffrement reste actif, mais l'identité du broker n'est plus contrôlée : n'importe quel serveur peut alors se faire passer pour lui. À réserver au certificat auto-signé d'un broker domestique.}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-cogs"></i> {{Démon}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Port des ordres}}</label>
            <div class="col-sm-2">
                <input class="configKey form-control" data-l1key="daemon::socketport" placeholder="55062" />
            </div>
            <div class="col-sm-7">
                <span class="help-block" style="margin:0;">{{Port local, écouté sur la boucle locale uniquement, par lequel Jeedom transmet ses ordres au démon. À changer seulement si ce port est déjà pris sur la machine.}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend><i class="fas fa-filter"></i> {{Topics}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Topics ignorés}}</label>
            <div class="col-sm-6">
                <textarea class="configKey form-control" data-l1key="topics::exclude" rows="4" placeholder="jeedom/#"></textarea>
            </div>
            <div class="col-sm-3">
                <span class="help-block" style="margin:0;">{{Un motif par ligne, avec les jokers MQTT + et #. Ce que Jeedom publie lui-même y figure d'office : le réabsorber reviendrait à découvrir ses propres équipements.}}</span>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <div class="form-group">
            <label class="col-sm-3 control-label"></label>
            <div class="col-sm-9">
                <a class="btn btn-success" id="bt_mqttbeTestConnection"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
                <span id="span_mqttbeTestResult" style="margin-left:10px;"></span>
            </div>
        </div>
    </fieldset>
</form>

<script>
    /*
     * La page de configuration d'un plugin est injectée en ajax : DOMContentLoaded
     * a déjà eu lieu quand ce script s'exécute, et l'écouteur doit donc être posé
     * directement, sans attendre d'événement de chargement.
     */
    $('#bt_mqttbeTestConnection').on('click', function () {
        var result = $('#span_mqttbeTestResult');
        result.attr('class', 'label label-info').text('{{Connexion en cours…}}');
        $.ajax({
            type: 'POST',
            url: 'plugins/mqttbe/core/ajax/mqttbe.ajax.php',
            /* On envoie ce qui est à l'écran, pas ce qui est enregistré :
             * tester puis sauvegarder est l'ordre naturel, et l'inverse oblige
             * à enregistrer une configuration dont on doute encore. */
            data: {
                action: 'testConnection',
                host: $('.configKey[data-l1key="broker::host"]').value(),
                port: $('.configKey[data-l1key="broker::port"]').value(),
                username: $('.configKey[data-l1key="broker::username"]').value(),
                password: $('.configKey[data-l1key="broker::password"]').value(),
                keepalive: $('.configKey[data-l1key="broker::keepalive"]').value(),
                tls: $('.configKey[data-l1key="broker::tls"]').value(),
                tlsInsecure: $('.configKey[data-l1key="broker::tlsInsecure"]').value(),
                caFile: $('.configKey[data-l1key="broker::caFile"]').value()
            },
            dataType: 'json',
            /* L'essai ouvre une vraie connexion TCP vers le broker : sur une
             * adresse injoignable, l'échec ne vient qu'au bout du délai réseau. */
            timeout: 20000,
            error: function (request, status, error) {
                result.attr('class', 'label label-danger').text('{{Échec}}');
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state != 'ok') {
                    result.attr('class', 'label label-danger').text('{{Échec}}');
                    $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                /* Le message du broker, quand il en donne un, dit bien plus que
                 * « connecté » : version, refus d'identifiants, certificat. */
                var message = (data.result && data.result.message) ? data.result.message : '{{Connexion au broker réussie.}}';
                result.attr('class', 'label label-success').text(message);
                $('#div_alert').showAlert({ message: message, level: 'success' });
            }
        });
    });
</script>
