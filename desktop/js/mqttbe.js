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
  var badge = document.getElementById(_id)
  if (badge === null) { return }
  badge.textContent = _text
  badge.className = 'label label-' + _level
  badge.title = isset(_title) ? _title : ''
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

/* =============================================================== ÉCOUTEURS */

/*
 * Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu
 * quand ce script s'exécute. Les abonnements sont donc posés directement sur
 * body, qui existe forcément, et non au chargement du document.
 */
$('body').off('mqttbe::daemonState').on('mqttbe::daemonState', function (_event, _option) {
  var option = mqttbeEventOption(_event, _option)
  mqttbeDaemonState(option.state === true || option.state === 1 || option.state === '1')
})

$('body').off('mqttbe::brokerState').on('mqttbe::brokerState', function (_event, _option) {
  var option = mqttbeEventOption(_event, _option)
  mqttbeBrokerState(init(option.state, 'nok'), init(option.message, ''))
})
