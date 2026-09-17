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

/* ============================================================== DÉCOUVERTE */

/*
 * Nom lisible d'un adapter.
 *
 * La table vient de la page, par sendVarToJS : elle est la même que celle des
 * vignettes, pour qu'un équipement ne s'appelle pas « Shelly Gen1 » d'un côté
 * et « shelly.gen1 » de l'autre. Un adapter inconnu de la table garde son
 * identifiant plutôt que de n'afficher rien.
 */
function mqttbeAdapterLabel(_id) {
  var id = String(init(_id, '')).trim()
  if (id === '') { return '' }
  if (typeof mqttbeAdapters !== 'undefined' && mqttbeAdapters !== null && isset(mqttbeAdapters[id])) {
    return String(mqttbeAdapters[id])
  }
  return id
}

/* Texte, jamais balisage : ces valeurs viennent de l'appareil, donc du réseau.
   Un modèle nommé <img onerror=…> écrirait dans la page. */
function mqttbeSetText(_id, _value) {
  var element = mqttbeEl(_id)
  if (element === null) { return }
  element.textContent = String(init(_value, ''))
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
  var uid = String(init(configuration['mqttbe::uid'], ''))
  var adapter = String(init(configuration['mqttbe::adapter'], ''))
  var manufacturer = String(init(configuration['mqttbe::manufacturer'], ''))
  var model = String(init(configuration['mqttbe::model'], ''))
  var ip = String(init(configuration['mqttbe::ip'], ''))
  var known = (uid !== '' || adapter !== '' || manufacturer !== '' || model !== '' || ip !== '')

  /* Un tiret plutôt qu'un vide : sur un appareil dont l'annonce seule est
     arrivée, la marque peut manquer, et une ligne vide se lirait comme une
     page mal chargée. */
  var label = mqttbeAdapterLabel(adapter)
  mqttbeSetText('span_mqttbeUid', uid === '' ? '—' : uid)
  mqttbeSetText('span_mqttbeAdapter', adapter === '' ? '—' : (label === adapter ? adapter : label + ' (' + adapter + ')'))
  mqttbeSetText('span_mqttbeManufacturer', manufacturer === '' ? '—' : manufacturer)
  mqttbeSetText('span_mqttbeModel', model === '' ? '—' : model)

  /*
     L'adresse en lien, parce que c'est par elle qu'on reconnaît l'appareil.
     Construite par createElement et non par une chaîne HTML : elle vient du
     réseau, et un appareil choisit ce qu'il annonce. La forme est contrôlée
     avant d'en faire un lien — sinon on affiche le texte, ce qui renseigne
     quand même sans rien exécuter.
  */
  var champIp = document.getElementById('span_mqttbeIp')
  if (champIp !== null) {
    while (champIp.firstChild !== null) {
      champIp.removeChild(champIp.firstChild)
    }
    if (/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/.test(ip) || /^[0-9a-fA-F:]{2,45}$/.test(ip)) {
      var lien = document.createElement('a')
      lien.href = 'http://' + ip
      lien.target = '_blank'
      lien.rel = 'noopener'
      lien.title = '{{Ouvrir la page de l\'appareil}}'
      lien.appendChild(document.createTextNode(ip))
      champIp.appendChild(lien)
    } else {
      champIp.appendChild(document.createTextNode(ip === '' ? '—' : ip))
    }
  }

  if (known) {
    identity.classList.remove('hidden')
    identity.style.display = ''
  } else {
    identity.classList.add('hidden')
    identity.style.display = 'none'
  }
}

/*
 * Ce que la découverte fait pendant qu'on regarde la page.
 *
 * Les vignettes datent du chargement : un équipement créé dix secondes plus
 * tard n'apparaîtrait qu'au prochain passage, et la relance de la découverte
 * semblerait n'avoir rien fait. Un compteur discret le dit, sans empiler une
 * alerte par appareil — un parc de vingt Shelly en produirait vingt d'un coup.
 */
var mqttbeDiscoveredCount = 0

function mqttbeNoteDiscovered(_name) {
  var zone = mqttbeEl('div_mqttbeDiscoveryLive')
  if (zone === null) { return }
  mqttbeDiscoveredCount++
  var name = String(init(_name, '')).trim()
  var texte = mqttbeDiscoveredCount + ' {{équipement(s) découvert(s) ou mis à jour depuis l\'ouverture de cette page.}}'
  if (name !== '') { texte += ' {{Dernier :}} ' + name + '.' }
  texte += ' {{Rechargez la page pour les voir apparaître.}}'
  zone.textContent = texte
  zone.classList.remove('hidden')
}

/* =============================================================== ADOPTION */

/*
 * La file d'adoption : ce que la découverte a vu sans oser le créer.
 *
 * Deux cas l'alimentent. La création automatique décochée, d'abord : tout ce
 * qui est reconnu attend alors un accord. Le doute de l'adapter ensuite — une
 * passerelle Bluetooth voit passer le téléphone d'un visiteur, la montre du
 * voisin, un traceur d'objet, et « je vois quelque chose, je ne sais pas ce que
 * c'est » ne justifie pas un équipement. Dans les deux cas, c'est une question
 * posée à l'utilisateur, et il faut donc un endroit pour y répondre.
 *
 * Tout ce qui suit pose du texte, jamais du balisage : ces noms, ces modèles et
 * ces identifiants sont annoncés par des appareils qui écrivent ce qu'ils
 * veulent, et la page est celle d'un administrateur.
 */

function mqttbeAdoptionAjax(_data, _success, _failure) {
  $.ajax({
    type: 'POST',
    url: 'plugins/mqttbe/core/ajax/mqttbe.ajax.php',
    data: _data,
    dataType: 'json',
    timeout: 20000,
    error: function (_request, _status, _error) {
      /* Réseau coupé, session expirée, erreur 500 : le cœur sait dire lequel,
         et il le dit mieux que nous. */
      if (typeof _failure === 'function') { _failure('') }
      handleAjaxError(_request, _status, _error)
    },
    success: function (_data) {
      if (_data.state != 'ok') {
        var message = String(init(_data.result, ''))
        if (typeof _failure === 'function') { _failure(message) }
        jeedomUtils.showAlert({ message: message, level: 'danger' })
        return
      }
      if (typeof _success === 'function') { _success(init(_data.result, {})) }
    }
  })
}

/*
 * Durée lisible.
 *
 * « 259 200 secondes » ne décide de rien, « 3 jours » décide de tout : c'est la
 * durée de présence qui sépare l'objet de la maison du passant. Les paliers
 * gardent le nombre petit ; la date exacte, elle, reste en infobulle.
 */
function mqttbeDurationText(_seconds) {
  var s = Math.floor(_seconds)
  if (!isFinite(s) || s < 0) { s = 0 }
  if (s < 90) { return s + ' s' }
  if (s < 5400) { return Math.floor(s / 60) + ' min' }
  if (s < 172800) { return Math.floor(s / 3600) + ' h' }
  var d = Math.floor(s / 86400)
  return d + (d > 1 ? ' {{jours}}' : ' {{jour}}')
}

/*
 * L'instant de référence.
 *
 * Les horodatages viennent du serveur, la comparaison se fait dans le
 * navigateur : une horloge de poste en retard de dix minutes afficherait « vu
 * dans 9 min ». La trame la plus récente sert donc de plancher — elle a
 * forcément déjà eu lieu.
 */
function mqttbeAdoptionNow(_list) {
  var now = Math.floor(Date.now() / 1000)
  for (var i = 0; i < _list.length; i++) {
    var seen = parseInt(init(_list[i].seen, 0), 10)
    if (isFinite(seen) && seen > now) { now = seen }
  }
  return now
}

function mqttbeStampText(_timestamp) {
  var ts = parseInt(_timestamp, 10)
  if (!isFinite(ts) || ts <= 0) { return '' }
  return new Date(ts * 1000).toLocaleString()
}

/* Le compte rendu des décisions. Posé en texte : il porte le nom de
   l'équipement, et ce nom vient de l'appareil. */
function mqttbeAdoptionStatus(_text, _level) {
  var zone = mqttbeEl('div_mqttbeAdoptionStatus')
  if (zone === null) { return }
  var text = String(init(_text, ''))
  if (text === '') {
    zone.textContent = ''
    zone.className = 'hidden'
    return
  }
  zone.textContent = text
  zone.className = 'alert alert-' + init(_level, 'info')
  zone.style.margin = '0 0 10px 0'
  zone.style.padding = '8px 12px'
}

/* Une ligne secondaire sous le titre d'une cellule : identifiant, modèle,
   adresse. Texte seulement, et rien du tout si la valeur est vide — une ligne
   vide se lit comme une page mal chargée. */
function mqttbeAdoptionLine(_parent, _text, _title) {
  var text = String(init(_text, '')).trim()
  if (text === '') { return null }
  var span = document.createElement('span')
  span.style.display = 'block'
  span.style.fontSize = '0.85em'
  span.style.opacity = '0.75'
  span.textContent = text
  if (isset(_title) && String(_title) !== '') { span.title = String(_title) }
  _parent.appendChild(span)
  return span
}

/* Un bouton d'action de la modale. Construit pièce par pièce : l'icône et le
   libellé sont des constantes, l'identifiant vient du réseau et reste un
   attribut, jamais un morceau de HTML. */
function mqttbeAdoptionButton(_action, _uid, _icon, _label, _className, _title) {
  var button = document.createElement('a')
  button.className = 'btn btn-sm ' + _className + ' cursor mqttbeAdoptionAction'
  button.setAttribute('data-action', _action)
  button.setAttribute('data-uid', _uid)
  if (isset(_title)) { button.title = _title }
  var icon = document.createElement('i')
  icon.className = _icon
  button.appendChild(icon)
  button.appendChild(document.createTextNode(' ' + _label))
  return button
}

/* Une ligne de candidat : ce qui permet de décider, et rien d'autre. */
/*
 * « il y a 8 min », et son équivalent anglais « 8 min ago ».
 *
 * La durée est composée dans une phrase entière plutôt que collée derrière un
 * préfixe traduit : l'anglais place « ago » APRÈS la durée, ce qu'un préfixe
 * rend impossible. Le marqueur %s dit où la durée se place, et chaque langue
 * en décide.
 */
function mqttbeAgo(_duree) {
  return '{{il y a %s}}'.replace('%s', _duree)
}

function mqttbePendingRow(_candidate, _now) {
  var meta = init(_candidate.meta, {})
  if (meta === null || typeof meta !== 'object') { meta = {} }
  var uid = String(init(_candidate.uid, ''))
  var name = String(init(_candidate.name, '')).trim()

  var tr = document.createElement('tr')
  tr.setAttribute('data-uid', uid)

  /* ------------------------------------------------------------ appareil */
  var tdName = document.createElement('td')
  var titre = document.createElement('b')
  /* Son nom s'il en a un, sinon son identifiant : une ligne sans titre ne se
     désigne pas, et on ne peut pas décider de ce qu'on ne peut pas nommer. */
  titre.textContent = (name !== '' ? name : uid)
  tdName.appendChild(titre)
  if (name !== '') {
    mqttbeAdoptionLine(tdName, uid, '{{Identifiant retenu par la découverte : il ne bouge pas quand l\'appareil change d\'adresse.}}')
  }
  tr.appendChild(tdName)

  /* -------------------------------------------------- ce qu'on en sait */
  var tdMeta = document.createElement('td')
  var known = false

  var adapter = mqttbeAdapterLabel(String(init(_candidate.adapter, '')))
  if (adapter !== '') {
    known = (mqttbeAdoptionLine(tdMeta, adapter, '{{Adaptateur qui l\'a repéré}}') !== null) || known
  }

  var manufacturer = String(init(meta.manufacturer, '')).trim()
  var model = String(init(meta.model_name, '')).trim()
  if (model === '') { model = String(init(meta.model, '')).trim() }
  var identite = manufacturer
  if (model !== '') { identite = (identite === '' ? model : identite + ' ' + model) }
  if (identite !== '') {
    known = (mqttbeAdoptionLine(tdMeta, identite, '{{Marque et modèle tels que l\'appareil les annonce}}') !== null) || known
  }

  var deviceName = String(init(meta.device_name, '')).trim()
  if (deviceName !== '' && deviceName !== name) {
    known = (mqttbeAdoptionLine(tdMeta, deviceName, '{{Nom que l\'appareil annonce lui-même}}') !== null) || known
  }

  /*
   * Le type d'adresse est le renseignement le plus décisif sur une balise
   * Bluetooth : une adresse aléatoire est renouvelée toutes les quinze minutes
   * environ, et l'équipement créé serait muet dès le changement suivant.
   */
  var addressType = String(init(meta.address_type, '')).trim().toLowerCase()
  if (addressType === 'public' || addressType === 'random') {
    var badge = document.createElement('span')
    badge.className = 'label label-' + (addressType === 'public' ? 'success' : 'warning')
    badge.style.display = 'inline-block'
    badge.style.marginTop = '2px'
    if (addressType === 'public') {
      badge.textContent = '{{adresse publique}}'
      badge.title = '{{Une adresse publique ne change pas : l\'équipement créé restera valable.}}'
    } else {
      badge.textContent = '{{adresse aléatoire}}'
      badge.title = '{{Cette adresse est renouvelée toutes les quinze minutes environ — c\'est ainsi qu\'un téléphone se protège du pistage. L\'équipement créé deviendrait muet au prochain changement : c\'est rarement un bon candidat.}}'
    }
    tdMeta.appendChild(badge)
    known = true
  } else if (addressType !== '') {
    known = (mqttbeAdoptionLine(tdMeta, '{{Type d\'adresse :}} ' + addressType) !== null) || known
  }

  /* Le nombre de passerelles qui la voient : une balise vue par trois
     passerelles est chez vous, une balise vue par une seule passe peut-être
     dans la rue. */
  var gateways = meta.gateways
  var gatewayCount = null
  if (Array.isArray(gateways)) {
    gatewayCount = gateways.length
  } else if (gateways !== null && gateways !== undefined && String(gateways).trim() !== '') {
    var parsed = parseInt(gateways, 10)
    if (isFinite(parsed)) { gatewayCount = parsed }
  }
  if (gatewayCount !== null) {
    known = (mqttbeAdoptionLine(tdMeta, gatewayCount + ' {{passerelle(s) la voient}}',
      '{{Une balise vue par plusieurs passerelles est chez vous ; une balise vue par une seule, au bord du réseau, passe peut-être dans la rue.}}') !== null) || known
  }

  var ip = String(init(meta.ip, '')).trim()
  if (ip !== '') {
    known = (mqttbeAdoptionLine(tdMeta, ip, '{{Adresse annoncée par l\'appareil}}') !== null) || known
  }

  if (!known) {
    mqttbeAdoptionLine(tdMeta, '{{Rien d\'autre que son identifiant : il s\'est annoncé sans se présenter.}}')
  }
  tr.appendChild(tdMeta)

  /* --------------------------------------------------- présent depuis */
  var first = parseInt(init(_candidate.first, 0), 10)
  var tdFirst = document.createElement('td')
  if (isFinite(first) && first > 0) {
    tdFirst.textContent = '{{vu depuis}} ' + mqttbeDurationText(_now - first)
    tdFirst.title = mqttbeStampText(first)
  } else {
    tdFirst.textContent = '—'
  }
  tr.appendChild(tdFirst)

  /* --------------------------------------------------- dernière trame */
  var seen = parseInt(init(_candidate.seen, 0), 10)
  var tdSeen = document.createElement('td')
  if (isFinite(seen) && seen > 0) {
    tdSeen.textContent = mqttbeAgo(mqttbeDurationText(_now - seen))
    tdSeen.title = mqttbeStampText(seen)
  } else {
    tdSeen.textContent = '—'
  }
  tr.appendChild(tdSeen)

  /* ------------------------------------------------------- commandes */
  var channels = parseInt(init(_candidate.channels, 0), 10)
  if (!isFinite(channels) || channels < 0) { channels = 0 }
  var tdChannels = document.createElement('td')
  tdChannels.textContent = String(channels)
  tdChannels.title = '{{Nombre de commandes que la création produirait.}}'
  tr.appendChild(tdChannels)

  /* -------------------------------------------------------- décision */
  var tdActions = document.createElement('td')
  tdActions.appendChild(mqttbeAdoptionButton('adopt', uid, 'fas fa-plus-circle', '{{Créer}}', 'btn-success',
    '{{Crée l\'équipement et ses commandes, sans redemander quoi que ce soit à l\'appareil.}}'))
  tdActions.appendChild(document.createTextNode(' '))
  tdActions.appendChild(mqttbeAdoptionButton('ignore', uid, 'fas fa-eye-slash', '{{Ignorer}}', 'btn-default',
    '{{Il ne vous sera plus proposé. La liste des appareils écartés, en bas, permet de revenir dessus.}}'))
  tr.appendChild(tdActions)

  return tr
}

/* Les deux boutons d'une ligne pendant que le serveur travaille : un double
   clic ne doit pas partir deux fois, et la ligne doit dire qu'il se passe
   quelque chose. */
function mqttbeAdoptionBusy(_row, _busy) {
  if (_row === null) { return }
  var buttons = _row.querySelectorAll('.mqttbeAdoptionAction')
  for (var i = 0; i < buttons.length; i++) {
    if (_busy) {
      buttons[i].classList.add('disabled')
    } else {
      buttons[i].classList.remove('disabled')
    }
  }
  _row.style.opacity = _busy ? '0.5' : ''
}

/* Le tableau et le message « rien n'attend » ne peuvent pas être affichés en
   même temps : l'un dit le contraire de l'autre. */
function mqttbeAdoptionEmptiness() {
  var body = mqttbeEl('tbody_mqttbePending')
  var table = mqttbeEl('table_mqttbePending')
  var empty = mqttbeEl('div_mqttbeAdoptionEmpty')
  if (body === null || table === null || empty === null) { return }
  if (body.querySelectorAll('tr').length > 0) {
    table.classList.remove('hidden')
    empty.classList.add('hidden')
    return
  }
  table.classList.add('hidden')
  empty.classList.remove('hidden')
}

function mqttbeRenderPending(_list) {
  var body = mqttbeEl('tbody_mqttbePending')
  /* La modale a pu être refermée pendant que la réponse voyageait : il n'y a
     alors plus rien à remplir, et ce n'est pas une anomalie. */
  if (body === null) { return }
  while (body.firstChild !== null) { body.removeChild(body.firstChild) }

  var list = Array.isArray(_list) ? _list : []
  var now = mqttbeAdoptionNow(list)
  for (var i = 0; i < list.length; i++) {
    body.appendChild(mqttbePendingRow(list[i], now))
  }
  mqttbeAdoptionEmptiness()
}

function mqttbeRenderIgnored(_map) {
  var body = mqttbeEl('tbody_mqttbeIgnored')
  var block = mqttbeEl('div_mqttbeIgnoredBlock')
  if (body === null || block === null) { return }
  while (body.firstChild !== null) { body.removeChild(body.firstChild) }

  var map = (_map !== null && typeof _map === 'object') ? _map : {}
  var uids = Object.keys(map)
  if (uids.length === 0) {
    /* Rien d'écarté : le repli n'a rien à replier. */
    block.classList.add('hidden')
    return
  }
  block.classList.remove('hidden')

  var label = mqttbeEl('span_mqttbeIgnoredLabel')
  if (label !== null) {
    label.textContent = '{{Appareils écartés}} (' + uids.length + ')'
  }

  /* Le refus le plus récent en premier : c'est celui qu'on vient de regretter. */
  uids.sort(function (_a, _b) {
    return (parseInt(map[_b], 10) || 0) - (parseInt(map[_a], 10) || 0)
  })

  var now = Math.floor(Date.now() / 1000)
  for (var i = 0; i < uids.length; i++) {
    var uid = String(uids[i])
    var when = parseInt(map[uid], 10)

    var tr = document.createElement('tr')
    tr.setAttribute('data-uid', uid)

    var tdUid = document.createElement('td')
    /* La file ne garde rien d'un appareil écarté : son identifiant est tout ce
       dont on dispose, et c'est par lui qu'on le reconnaît. */
    tdUid.textContent = uid
    tr.appendChild(tdUid)

    var tdWhen = document.createElement('td')
    if (isFinite(when) && when > 0) {
      tdWhen.textContent = mqttbeAgo(mqttbeDurationText(now < when ? 0 : now - when))
      tdWhen.title = mqttbeStampText(when)
    } else {
      tdWhen.textContent = '—'
    }
    tr.appendChild(tdWhen)

    var tdAction = document.createElement('td')
    tdAction.appendChild(mqttbeAdoptionButton('unignore', uid, 'fas fa-undo', '{{Reproposer}}', 'btn-default',
      '{{Il repassera dans la file à sa prochaine annonce.}}'))
    tr.appendChild(tdAction)

    body.appendChild(tr)
  }
}

/* Relit la file entière. Le compte rendu de la dernière décision est conservé
   quand on le demande : il dit ce qui vient d'être créé, et une liste qui se
   rafraîchit ne doit pas l'effacer sous les yeux. */
function mqttbeAdoptionLoad(_keepStatus) {
  if (mqttbeEl('tbody_mqttbePending') === null) { return }
  /* Le tableau et le message « rien n'attend » sont tous deux cachés tant que
     la réponse n'est pas là : sans un mot, l'ouverture semblerait vide. */
  if (_keepStatus !== true) { mqttbeAdoptionStatus('{{Lecture de la file…}}', 'info') }
  mqttbeAdoptionAjax({ action: 'pending' }, function (_result) {
    if (_keepStatus !== true) { mqttbeAdoptionStatus('') }
    mqttbeRenderPending(init(_result.pending, []))
    mqttbeRenderIgnored(init(_result.ignored, {}))
  }, function (_message) {
    mqttbeAdoptionStatus(_message === '' ? '{{La file n\'a pas pu être lue.}}' : _message, 'danger')
  })
}

/* Ce que la fabrique a réellement écrit, et non « c'est fait » : un équipement
   créé sans commande est un échec silencieux, et il vaut mieux l'apprendre
   ici. */
function mqttbeAdoptionReport(_result) {
  var result = init(_result, {})
  var cmd = init(result.cmd, {})
  var created = parseInt(init(cmd.created, 0), 10) || 0
  var updated = parseInt(init(cmd.updated, 0), 10) || 0
  var failed = parseInt(init(cmd.failed, 0), 10) || 0
  var name = String(init(result.name, '')).trim()

  var texte = '{{Équipement créé :}} ' + (name === '' ? '{{sans nom}}' : name)
  texte += ' — ' + created + ' {{commande(s) créée(s)}}'
  if (updated > 0) { texte += ', ' + updated + ' {{mise(s) à jour}}' }
  if (failed > 0) { texte += ', ' + failed + ' {{refusée(s) par la base}}' }
  texte += '. {{Rechargez la page du plugin pour le voir apparaître parmi les équipements.}}'
  mqttbeAdoptionStatus(texte, failed > 0 ? 'warning' : 'success')
}

function mqttbeAdopt(_button) {
  var uid = _button.getAttribute('data-uid')
  var row = _button.closest('tr')
  mqttbeAdoptionBusy(row, true)
  mqttbeAdoptionStatus('{{Création en cours…}}', 'info')
  mqttbeAdoptionAjax({ action: 'adopt', uid: uid }, function (_result) {
    /* Le candidat a quitté la file côté serveur : la ligne s'en va aussi,
       plutôt que de recharger une page dont le reste n'a pas bougé. */
    if (row !== null && row.parentNode !== null) { row.parentNode.removeChild(row) }
    mqttbeAdoptionEmptiness()
    mqttbeAdoptionReport(_result)
  }, function (_message) {
    mqttbeAdoptionBusy(row, false)
    mqttbeAdoptionStatus(_message === '' ? '{{La création a échoué.}}' : _message, 'danger')
    /* Un candidat disparu de la file — démon redémarré, cache vidé — ne
       reviendra pas d'un second clic : la liste est relue pour dire la vérité
       plutôt que de laisser un bouton sans effet. */
    if (_message !== '') { mqttbeAdoptionLoad(true) }
  })
}

function mqttbeIgnore(_button) {
  var uid = _button.getAttribute('data-uid')
  var row = _button.closest('tr')
  mqttbeAdoptionBusy(row, true)
  mqttbeAdoptionAjax({ action: 'ignore', uid: uid }, function (_result) {
    mqttbeAdoptionStatus(String(init(_result.message, '{{Appareil écarté.}}')), 'info')
    /* La file et la liste des refus changent ensemble : on relit les deux, et
       les appareils annoncés entre-temps apparaissent au passage. */
    mqttbeAdoptionLoad(true)
  }, function (_message) {
    mqttbeAdoptionBusy(row, false)
    mqttbeAdoptionStatus(_message === '' ? '{{L\'appareil n\'a pas pu être écarté.}}' : _message, 'danger')
  })
}

function mqttbeUnignore(_button) {
  var uid = _button.getAttribute('data-uid')
  var row = _button.closest('tr')
  mqttbeAdoptionBusy(row, true)
  mqttbeAdoptionAjax({ action: 'unignore', uid: uid }, function (_result) {
    mqttbeAdoptionStatus(String(init(_result.message, '{{Appareil de nouveau proposé à la prochaine découverte.}}')), 'info')
    mqttbeAdoptionLoad(true)
  }, function (_message) {
    mqttbeAdoptionBusy(row, false)
    mqttbeAdoptionStatus(_message === '' ? '{{Le refus n\'a pas pu être annulé.}}' : _message, 'danger')
  })
}

/*
 * Ouvre la file.
 *
 * jeeDialog charge la modale en ajax puis appelle `callback` : c'est le seul
 * moment où les éléments de la modale existent, et donc le seul moment où l'on
 * peut la remplir.
 */
function mqttbeOpenAdoption() {
  if (typeof jeeDialog === 'undefined') {
    jeedomUtils.showAlert({ message: '{{Cette version de Jeedom ne sait pas ouvrir la fenêtre d\'adoption.}}', level: 'danger' })
    return
  }
  jeeDialog.dialog({
    id: 'jee_modal',
    title: '{{Appareils vus et pas créés}}',
    contentUrl: 'index.php?v=d&plugin=mqttbe&modal=adoption',
    callback: function () { mqttbeAdoptionLoad() }
  })
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

/* Émis par mqttbeDaemon::onDiscovered pour chaque modèle qui a réellement
   changé quelque chose : les modèles identiques, eux, ne coûtent rien et ne
   disent rien. */
$('body').off('mqttbe::discovered').on('mqttbe::discovered', function (_event, _option) {
  var option = mqttbeEventOption(_event, _option)
  mqttbeNoteDiscovered(init(option.name, ''))
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
  /* Le bandeau « N appareils vus et pas créés » de la page principale : sans
     lui, la file d'adoption n'avait aucune porte d'entrée. */
  if (name === 'openAdoption') {
    mqttbeOpenAdoption()
    return
  }
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

/*
 * Les actions de la modale d'adoption.
 *
 * La modale est posée à la racine du document, hors du conteneur de page :
 * l'écouteur du conteneur ne verrait jamais ses clics. Il est donc délégué sur
 * body, et nommé — off() puis on() — parce que les pages de plugin sont
 * chargées en ajax : revenir sur la page réexécute ce script, et un écouteur
 * anonyme s'empilerait, envoyant deux créations pour un seul clic.
 */
$('body').off('click.mqttbeAdoption').on('click.mqttbeAdoption', '.mqttbeAdoptionAction', function (_event) {
  _event.preventDefault()
  /* Une action en cours : le second clic ne doit pas partir. */
  if (this.classList.contains('disabled')) { return }

  var name = this.getAttribute('data-action')
  if (name === 'refresh') {
    mqttbeAdoptionLoad()
    return
  }
  if (name === 'toggleIgnored') {
    var liste = mqttbeEl('div_mqttbeIgnoredList')
    var caret = mqttbeEl('i_mqttbeIgnoredCaret')
    if (liste === null) { return }
    liste.classList.toggle('hidden')
    if (caret !== null) {
      var ouvert = !liste.classList.contains('hidden')
      caret.className = ouvert ? 'fas fa-caret-down' : 'fas fa-caret-right'
    }
    return
  }
  if (name === 'adopt') {
    mqttbeAdopt(this)
    return
  }
  if (name === 'ignore') {
    mqttbeIgnore(this)
    return
  }
  if (name === 'unignore') {
    mqttbeUnignore(this)
    return
  }
})
