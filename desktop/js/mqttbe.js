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

/* ================================================================== OUTILS */

function mqttbeEl(_id) {
  return document.getElementById(_id)
}

/*
 * Le cœur ne distribue pas les événements de plugin d'une seule façon : avec
 * jQuery chargé il fait $('body').trigger(nom, option), l'option arrivant en
 * second argument du gestionnaire ; sans jQuery il émet un CustomEvent, et
 * l'option est alors dans detail. Un plugin qui ne lit qu'une des deux formes
 * marche sur une page et pas sur l'autre.
 */
function mqttbeEventOption(_event, _option) {
  if (isset(_option)) { return _option }
  if (_event && isset(_event.detail)) { return _event.detail }
  if (_event && _event.originalEvent && isset(_event.originalEvent.detail)) { return _event.originalEvent.detail }
  return {}
}

/* Le texte d'un message d'erreur vient du broker : il est posé en texte, jamais
   en balisage, faute de quoi il pourrait écrire dans la page. */
function mqttbeSetBadge(_id, _text, _level, _title) {
  var badge = mqttbeEl(_id)
  if (badge === null) { return }
  badge.textContent = _text
  badge.className = 'label label-' + _level
  badge.title = isset(_title) ? _title : ''
}

/* Signale au cœur que la page porte des modifications non enregistrées : sans
   cela, quitter l'onglet après avoir ajouté une commande ne demanderait rien et
   la saisie serait perdue sans un mot. */
function mqttbeModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  modifyWithoutSave = true
}

/* ================================================================ PASTILLES */

function mqttbeDaemonState(_state) {
  if (_state) {
    mqttbeSetBadge('span_mqttbeDaemonState', '{{En marche}}', 'success')
    return
  }
  mqttbeSetBadge('span_mqttbeDaemonState', '{{Arrêté}}', 'danger')
  /* Démon arrêté, plus rien n'arrive du broker : laisser la pastille du broker
     au vert annoncerait une liaison qui n'existe plus. */
  mqttbeSetBadge('span_mqttbeBrokerState', '{{Déconnecté}}', 'default')
}

function mqttbeBrokerState(_state, _message) {
  if (_state == 'ok') {
    mqttbeSetBadge('span_mqttbeBrokerState', '{{Connecté}}', 'success', _message)
    return
  }
  /* Le motif du refus — identifiants, certificat, hôte injoignable — est la
     seule chose qui distingue un broker mal réglé d'un broker éteint : il est
     porté en infobulle plutôt que perdu dans le journal. */
  mqttbeSetBadge('span_mqttbeBrokerState', '{{Déconnecté}}', 'danger', _message)
}

/* ============================================================== ÉQUIPEMENT */

/*
 * Topic de base, terminé par une barre oblique.
 *
 * Il ne sert qu'à préremplir le topic des commandes ajoutées : le plugin
 * n'écoute et ne publie que sur les topics portés par les commandes, jamais sur
 * celui-ci. Une valeur vide n'ajoute donc rien et n'empêche rien.
 */
function mqttbeBaseTopic() {
  var input = mqttbeEl('in_mqttbeBaseTopic')
  if (input === null) { return '' }
  var value = input.value.trim()
  if (value === '') { return '' }
  return (value.charAt(value.length - 1) === '/') ? value : value + '/'
}

/* Rappel appelé par plugin.template.js une fois l'équipement chargé, avant que
   les lignes de commandes ne soient reconstruites. */
function printEqLogic(_eqLogic) {
  var identity = mqttbeEl('div_mqttbeIdentity')
  if (identity === null) { return }

  /* Ces champs viennent de la découverte automatique. Sur un équipement créé à
     la main ils sont tous vides : quatre lignes vides ne renseignent personne,
     le bloc entier reste alors caché. */
  var configuration = init(_eqLogic.configuration, {})
  var keys = ['mqttbe::uid', 'mqttbe::adapter', 'mqttbe::manufacturer', 'mqttbe::model']
  var known = false
  for (var i = 0; i < keys.length; i++) {
    if (init(configuration[keys[i]], '') !== '') { known = true }
  }
  if (known) {
    identity.classList.remove('hidden')
    identity.style.display = ''
  } else {
    identity.classList.add('hidden')
  }
}

/* ============================================================== COMMANDES */

/*
 * Deux champs ne peuvent pas passer par la mécanique cmdAttr du cœur.
 *
 * getJeeValues() transforme en objet toute valeur commençant par une accolade :
 * une correspondance {"on":"1"} ou une charge utile JSON seraient enregistrées
 * en objet, puis relues en un champ vide (setJeeValues ne sait pas reposer un
 * objet dans un champ texte), et le cast en chaîne côté PHP produirait
 * « Array ». Ces deux-là sont donc lus et écrits à la main, et restent des
 * chaînes de bout en bout.
 */
function mqttbeRawString(_value) {
  if (_value === null || _value === undefined) { return '' }
  if (typeof _value === 'object') { return JSON.stringify(_value) }
  return String(_value)
}

function mqttbeSetRaw(_row, _key, _value) {
  var input = _row.querySelector('.mqttbeRaw[data-key="' + _key + '"]')
  if (input === null) { return }
  input.value = mqttbeRawString(_value)
}

function mqttbeGetRaw(_row, _key) {
  var input = _row.querySelector('.mqttbeRaw[data-key="' + _key + '"]')
  if (input === null) { return '' }
  return input.value.trim()
}

/*
 * Contrôle de la correspondance.
 *
 * Une correspondance mal formée est ignorée sans bruit par le démon : la valeur
 * continue d'arriver brute et rien n'explique pourquoi. Le seul endroit où le
 * dire est ici, au moment où elle est tapée.
 */
function mqttbeMapIsValid(_value) {
  if (_value === '') { return true }
  try {
    var parsed = JSON.parse(_value)
    return (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed))
  } catch (e) {
    return false
  }
}

function mqttbeCheckMap(_row) {
  if (!_row) { return true }
  var input = _row.querySelector('.mqttbeRaw[data-key="map"]')
  var help = _row.querySelector('.mqttbeMapError')
  if (input === null || help === null) { return true }

  var valid = mqttbeMapIsValid(input.value.trim())
  if (valid) {
    input.style.borderColor = ''
    help.textContent = ''
    help.classList.add('hidden')
    return true
  }

  input.style.borderColor = '#a94442'
  help.textContent = '{{Ce n\'est pas un objet JSON : la correspondance sera ignorée. Exemple :}} {"on":"1","off":"0"}'
  help.classList.remove('hidden')

  /* Un avertissement sous un panneau replié n'avertit personne : la ligne
     rouvre d'elle-même son panneau, y compris en revenant sur l'équipement
     après l'avoir enregistré. */
  var advanced = _row.querySelector('.mqttbeAdvanced')
  if (advanced !== null) { advanced.classList.remove('hidden') }
  return false
}

/* Les réglages fins d'une commande d'information. Repliés par défaut : le cas
   courant est un topic et une valeur, et rien d'autre. */
function mqttbeAdvancedHtml() {
  var html = ''
  html += '<div class="mqttbeAdvanced hidden" style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(128,128,128,0.35);">'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Correspondance}}</span>'
  html += '<input class="mqttbeRaw form-control" data-key="map" placeholder="{&quot;on&quot;:&quot;1&quot;,&quot;off&quot;:&quot;0&quot;}" title="{{Traduit la charge utile reçue, à l\'identique et caractère pour caractère, en la valeur de droite. Appliquée avant l\'échelle, le décalage et l\'arrondi.}}">'
  html += '</div>'
  html += '<span class="help-block mqttbeMapError hidden" style="margin:0 0 4px 0;color:#a94442;"></span>'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Échelle}}</span>'
  html += '<input type="number" step="any" class="cmdAttr form-control" data-l1key="configuration" data-l2key="scale" placeholder="1" title="{{Multiplicateur appliqué à la valeur. Vide : aucune multiplication. Le séparateur décimal est le point.}}">'
  html += '</div>'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Décalage}}</span>'
  html += '<input type="number" step="any" class="cmdAttr form-control" data-l1key="configuration" data-l2key="offset" placeholder="0" title="{{Ajouté à la valeur après l\'échelle. Vide : aucun décalage.}}">'
  html += '</div>'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Arrondi}}</span>'
  html += '<input type="number" min="0" step="1" class="cmdAttr form-control" data-l1key="configuration" data-l2key="round" placeholder="{{aucun}}" title="{{Nombre de décimales conservées. Vide : la valeur est laissée telle quelle.}}">'
  html += '</div>'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Répétition}}</span>'
  html += '<select class="cmdAttr form-control" data-l1key="configuration" data-l2key="repeat" title="{{Une valeur identique à la précédente est normalement ignorée : cela évite d\'historiser cent fois la même chose.}}">'
  html += '<option value="onchange">{{Quand la valeur change}}</option>'
  html += '<option value="always">{{À chaque message}}</option>'
  html += '</select>'
  html += '</div>'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Rappel}}</span>'
  html += '<input type="number" min="0" step="1" class="cmdAttr form-control" data-l1key="configuration" data-l2key="keepalive" placeholder="300" title="{{Délai, en secondes, au bout duquel une valeur inchangée est réémise quand même. 0 : jamais.}}">'
  html += '<span class="input-group-addon">s</span>'
  html += '</div>'

  html += '<div class="input-group input-group-sm" style="margin-bottom:4px;">'
  html += '<span class="input-group-addon" style="min-width:90px;text-align:left;">{{Intervalle mini}}</span>'
  html += '<input type="number" min="0" step="1" class="cmdAttr form-control" data-l1key="configuration" data-l2key="minInterval" placeholder="0" title="{{Durée minimale entre deux émissions, en secondes. 0 : aucune limite.}}">'
  html += '<span class="input-group-addon">s</span>'
  html += '</div>'

  html += '<span class="help-block" style="margin:0;">{{Sans rappel, le champ « dernière communication » d\'un capteur stable vieillit indéfiniment.}}</span>'
  html += '</div>'
  return html
}

/*
 * Rappel appelé par plugin.template.js pour chaque commande de l'équipement, et
 * par les deux boutons d'ajout de cette page.
 */
function addCmdToTable(_cmd) {
  var table = mqttbeEl('table_cmd')
  if (table === null) { return }
  if (!isset(_cmd)) { _cmd = {} }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {} }

  var tr = ''

  /* ----------------------------------------------------------------- nom */
  tr += '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes :
     l'historique est perdu et les scénarios pointent dans le vide. */
  tr += '<input type="text" class="cmdAttr" data-l1key="id" style="display:none;">'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'

  /* ---------------------------------------------------------------- type */
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type, 'info') + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'

  /* --------------------------------------------------------------- topic */
  tr += '<td>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="topic" placeholder="shellies/shelly1pm-D8BFC01A0805/relay/0/power" title="{{Topic complet. Pour une information, celui que le plugin écoute ; pour une action, celui sur lequel il publie. Les jokers + et # sont acceptés en écoute.}}">'
  tr += '</td>'

  /* --------------------------------------------------------------- valeur */
  tr += '<td>'

  /* Information : où prendre la valeur dans la charge utile, et son unité. */
  tr += '<div class="mqttbeInfoOnly">'
  tr += '<div class="input-group input-group-sm">'
  tr += '<span class="input-group-addon" style="min-width:70px;text-align:left;">{{Chemin}}</span>'
  tr += '<input class="cmdAttr form-control" data-l1key="configuration" data-l2key="path" placeholder="{{vide = toute la charge utile}}" title="{{Chemin par points dans une charge utile JSON, par exemple emeter.0.power : un segment qui est un nombre désigne un rang dans une liste. Laissé vide, la charge utile est prise telle quelle.}}">'
  tr += '</div>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" style="margin-top:4px;" title="{{Unité affichée à côté de la valeur : W, °C, %…}}">'
  tr += '<a class="btn btn-default btn-xs mqttbeAction" data-action="toggleAdvanced" style="margin-top:4px;"><i class="fas fa-sliders-h"></i> {{Réglages fins}}</a>'
  tr += mqttbeAdvancedHtml()
  tr += '</div>'

  /* Action : ce qui est publié, et comment. */
  tr += '<div class="mqttbeActionOnly hidden">'
  tr += '<input class="mqttbeRaw form-control input-sm" data-key="payload" placeholder="{{Message publié}}" title="{{Contenu publié sur le topic. #slider# y est remplacé par la valeur du curseur, #message# par le texte saisi et #color# par la couleur choisie.}}">'
  tr += '<div style="margin-top:4px;">'
  tr += '<select class="cmdAttr input-sm" data-l1key="configuration" data-l2key="qos" style="width:80px;display:inline-block;" title="{{Qualité de service MQTT.}}">'
  tr += '<option value="0">QoS 0</option>'
  tr += '<option value="1">QoS 1</option>'
  tr += '<option value="2">QoS 2</option>'
  tr += '</select>'
  tr += '<label class="checkbox-inline" style="margin-left:10px;" title="{{Le broker conserve le dernier message et le redonne à tout nouvel abonné.}}">'
  tr += '<input type="checkbox" class="cmdAttr" data-l1key="configuration" data-l2key="retain">{{Retenu}}'
  tr += '</label>'
  tr += '</div>'
  tr += '</div>'

  tr += '</td>'

  /* -------------------------------------------------------------- options */
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  tr += '<div style="margin-top:4px;"><span class="cmdAttr" data-l1key="htmlstate"></span></div>'
  tr += '<div style="margin-top:4px;">'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure" title="{{Configuration avancée de la commande}}"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i>'
  tr += '</div>'
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table génère un <tbody> par
     insertion, et les commandes se retrouveraient réparties dans autant de
     corps de tableau. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  table.querySelector('tbody').appendChild(newRow)

  /* Les deux champs qui peuvent contenir du JSON sont posés à la main, avant
     setJeeValues qui, lui, ne les voit pas. */
  mqttbeSetRaw(newRow, 'payload', _cmd.configuration.payload)
  mqttbeSetRaw(newRow, 'map', _cmd.configuration.map)

  newRow.setJeeValues(_cmd, '.cmdAttr')
  /* L'ordre compte : changeType après setJeeValues, jamais l'inverse. */
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
  mqttbeApplyType(newRow)
  mqttbeCheckMap(newRow)
  mqttbeMarkAdvanced(newRow)
}

/*
 * Marque le bouton des réglages fins quand la ligne en porte.
 *
 * Replier ne doit pas revenir à cacher : une échelle à 0,001 posée six mois plus
 * tôt expliquerait à elle seule une valeur mille fois trop petite, et rien ne la
 * signalerait derrière un panneau fermé.
 */
function mqttbeMarkAdvanced(_row) {
  if (!_row) { return }
  var button = _row.querySelector('.mqttbeAction[data-action="toggleAdvanced"]')
  if (button === null) { return }

  /* Une case vide vaut le défaut : elle ne marque donc rien. */
  var defaults = {
    scale: '', offset: '', round: '',
    repeat: 'onchange', keepalive: '300', minInterval: '0'
  }
  var map = mqttbeGetRaw(_row, 'map')
  var custom = (map !== '')
  for (var key in defaults) {
    if (!Object.prototype.hasOwnProperty.call(defaults, key)) { continue }
    var field = _row.querySelector('.cmdAttr[data-l1key="configuration"][data-l2key="' + key + '"]')
    if (field === null) { continue }
    var value = String(field.value).trim()
    if (value !== '' && value !== defaults[key]) { custom = true }
  }

  button.classList.remove('btn-default')
  button.classList.remove('btn-primary')
  button.classList.remove('btn-danger')
  if (!mqttbeMapIsValid(map)) {
    button.classList.add('btn-danger')
    button.title = '{{La correspondance de cette commande n\'est pas un objet JSON valide.}}'
    return
  }
  button.classList.add(custom ? 'btn-primary' : 'btn-default')
  button.title = custom ? '{{Cette commande porte des réglages fins.}}' : ''
}

/* Montre les champs du type choisi et cache ceux de l'autre. Appelée à la
   création de la ligne et à chaque changement du sélecteur de type. */
function mqttbeApplyType(_row) {
  if (!_row) { return }
  var type = _row.querySelector('.cmdAttr[data-l1key="type"]')
  var isAction = (type !== null && type.value === 'action')

  var blocks = _row.querySelectorAll('.mqttbeInfoOnly')
  var i
  for (i = 0; i < blocks.length; i++) {
    if (isAction) { blocks[i].classList.add('hidden') } else { blocks[i].classList.remove('hidden') }
  }
  blocks = _row.querySelectorAll('.mqttbeActionOnly')
  for (i = 0; i < blocks.length; i++) {
    if (isAction) { blocks[i].classList.remove('hidden') } else { blocks[i].classList.add('hidden') }
  }
}

/* Ajout d'une commande depuis les deux boutons de l'onglet.
 *
 * Les réglages qui ont un défaut sont posés explicitement plutôt que laissés
 * vides : une case vide se lirait 0 côté démon, et un rappel à 0 veut dire
 * « jamais », soit exactement le contraire du défaut annoncé. */
function mqttbeAddCmd(_type) {
  var cmd = {
    type: _type,
    subType: (_type === 'action') ? 'other' : 'string',
    isVisible: '1',
    isHistorized: '0',
    configuration: {}
  }
  var base = mqttbeBaseTopic()
  if (base !== '') { cmd.configuration.topic = base }
  if (_type === 'action') {
    cmd.configuration.qos = '0'
    cmd.configuration.retain = '0'
  } else {
    cmd.configuration.repeat = 'onchange'
    cmd.configuration.keepalive = '300'
    cmd.configuration.minInterval = '0'
  }
  addCmdToTable(cmd)
  mqttbeModified()

  /* La ligne qui vient d'être ajoutée est la dernière : y amener le curseur
     évite de la chercher en bas d'un tableau de trente commandes. */
  var rows = document.querySelectorAll('#table_cmd tbody tr.cmd')
  if (rows.length > 0) {
    var name = rows[rows.length - 1].querySelector('.cmdAttr[data-l1key="name"]')
    if (name !== null) { name.focus() }
  }
}

/*
 * Rappel appelé par plugin.template.js juste avant l'envoi.
 *
 * Il reporte dans l'objet enregistré les deux champs que la mécanique cmdAttr
 * ne sait pas transporter. L'ordre des lignes du tableau est celui du tableau
 * des commandes collecté par le cœur : les deux viennent du même
 * querySelectorAll('.cmd').
 */
function saveEqLogic(_eqLogic) {
  /* Le cœur ne collecte que le panneau visible : prendre le même, faute de quoi
     les lignes lues ici ne seraient pas celles qu'il vient d'envoyer. */
  var panels = document.querySelectorAll('.eqLogic')
  var panel = null
  for (var p = 0; p < panels.length; p++) {
    if (panels[p].isVisible()) { panel = panels[p]; break }
  }
  if (panel === null) { panel = document.querySelector('.eqLogic') }
  if (panel === null) { return _eqLogic }
  var rows = panel.querySelectorAll('.cmd')
  var cmds = isset(_eqLogic.cmd) ? _eqLogic.cmd : []
  var invalid = []

  for (var i = 0; i < rows.length && i < cmds.length; i++) {
    if (!isset(cmds[i].configuration)) { cmds[i].configuration = {} }
    var type = rows[i].querySelector('.cmdAttr[data-l1key="type"]')
    if (type !== null && type.value === 'action') {
      cmds[i].configuration.payload = mqttbeGetRaw(rows[i], 'payload')
      continue
    }
    cmds[i].configuration.map = mqttbeGetRaw(rows[i], 'map')
    if (!mqttbeCheckMap(rows[i])) {
      var label = init(cmds[i].name, '')
      invalid.push(label === '' ? '{{commande}} ' + (i + 1) : label)
    }
  }

  /* L'enregistrement n'est pas bloqué : la saisie est conservée telle quelle,
     mais personne ne doit repartir en croyant que sa correspondance s'applique. */
  if (invalid.length > 0) {
    jeedomUtils.showAlert({
      message: '{{Correspondance ignorée, faute d\'être un objet JSON valide, sur :}} ' + invalid.join(', '),
      level: 'warning'
    })
  }
  return _eqLogic
}

/* =============================================================== ÉCOUTEURS */

/*
 * Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu
 * quand ce script s'exécute. Les abonnements sont donc posés directement sur
 * body et sur le conteneur de page, qui existent forcément, et non au
 * chargement du document.
 */
$('body').off('mqttbe::daemonState').on('mqttbe::daemonState', function (_event, _option) {
  var option = mqttbeEventOption(_event, _option)
  mqttbeDaemonState(option.state === true || option.state === 1 || option.state === '1')
})

$('body').off('mqttbe::brokerState').on('mqttbe::brokerState', function (_event, _option) {
  var option = mqttbeEventOption(_event, _option)
  mqttbeBrokerState(init(option.state, 'nok'), init(option.message, ''))
})

var mqttbeContainer = mqttbeEl('div_pageContainer') || document.body

mqttbeContainer.addEventListener('click', function (_event) {
  var target = _event.target
  if (target === null || typeof target.closest !== 'function') { return }

  /* Les actions du cœur portent la classe cmdAction ou eqLogicAction ; celles
     du plugin ont la leur, pour qu'aucune des deux ne réponde à l'autre. */
  var action = target.closest('.mqttbeAction')
  if (action === null) { return }
  _event.preventDefault()

  var name = action.getAttribute('data-action')
  if (name === 'addInfoCmd') {
    mqttbeAddCmd('info')
    return
  }
  if (name === 'addActionCmd') {
    mqttbeAddCmd('action')
    return
  }
  if (name === 'toggleAdvanced') {
    var row = action.closest('tr')
    if (row === null) { return }
    var advanced = row.querySelector('.mqttbeAdvanced')
    if (advanced === null) { return }
    advanced.classList.toggle('hidden')
    return
  }
})

mqttbeContainer.addEventListener('change', function (_event) {
  var target = _event.target
  if (target === null || typeof target.matches !== 'function') { return }
  if (target.matches('.cmd select.cmdAttr[data-l1key="type"]')) {
    mqttbeApplyType(target.closest('tr'))
    return
  }
  if (target.closest('.mqttbeAdvanced') !== null) {
    mqttbeMarkAdvanced(target.closest('tr'))
  }
})

/* La correspondance est contrôlée à la frappe : attendre l'enregistrement pour
   signaler un JSON de travers oblige à revenir sur ses pas. */
mqttbeContainer.addEventListener('input', function (_event) {
  var target = _event.target
  if (target === null || typeof target.matches !== 'function') { return }

  if (target.matches('.cmd .mqttbeRaw[data-key="map"]')) {
    mqttbeCheckMap(target.closest('tr'))
  }
  /* Les deux champs hors cmdAttr n'ont pas d'écouteur de modification du cœur :
     sans cela, ne changer qu'une charge utile laisserait croire la page
     inchangée. */
  if (target.matches('.cmd .mqttbeRaw')) {
    mqttbeModified()
  }
  if (target.closest('.mqttbeAdvanced') !== null) {
    mqttbeMarkAdvanced(target.closest('tr'))
  }
})
