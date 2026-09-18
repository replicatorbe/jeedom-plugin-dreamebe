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

function dreamebeEl(_id) {
  return document.getElementById(_id)
}

function dreamebeText(_value) {
  return (_value === null || _value === undefined) ? '' : String(_value)
}

/* Tout ce qui vient du cloud est du texte, jamais du balisage. Un nom de robot
   ou de pièce est saisi dans l'application mobile — et sur un robot partagé, il
   l'est par quelqu'un d'autre. Il n'a pas à pouvoir écrire dans une page ouverte
   en session administrateur. À utiliser partout où la valeur finit dans du HTML,
   notamment dans bootbox, qui insère son message tel quel. */
function dreamebeEscape(_value) {
  var div = document.createElement('div')
  div.textContent = dreamebeText(_value)
  return div.innerHTML
}

function dreamebeAjax(_action, _data, _success) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/dreamebe/core/ajax/dreamebe.ajax.php',
    data: payload,
    dataType: 'json',
    /* Le callback d'erreur ne reçoit qu'un seul argument, contrairement à celui
       de jQuery : le passer à handleAjaxError, qui en attend quatre, afficherait
       « [object Object] : undefined /error: undefined ». */
    error: function (error) {
      dreamebeStatus('', null)
      jeedomUtils.showAlert({
        message: (error && error.message) ? error.message : dreamebeText(error),
        level: 'danger'
      })
    },
    /*
       Le point le plus important de ce fichier.

       ajax::error() du coeur répond en HTTP 200 avec { state: 'error' } : pour
       le transport, tout va bien. Sans ce contrôle, chaque échec du plugin
       remonte ici comme un succès, et l'interface annonce « À jour » ou
       « undefined propriétés » là où le serveur avait produit un message
       parfaitement clair — que personne ne verra jamais.
    */
    success: function (result) {
      if (!result || result.state !== 'ok') {
        dreamebeStatus('', null)
        jeedomUtils.showAlert({
          message: (result && result.result) ? dreamebeText(result.result) : '{{Erreur inconnue}}',
          level: 'danger'
        })
        return
      }
      _success(result)
    }
  })
}

function dreamebeStatus(_text, _level) {
  var span = dreamebeEl('span_dreamebeStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

function dreamebeCurrentId() {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (input === null) ? '' : input.value
}

function dreamebeNeedsSave() {
  jeedomUtils.showAlert({ message: '{{Enregistrez le robot avant cette action.}}', level: 'warning' })
}

/* Une ligne de tableau construite en DOM : insertAdjacentHTML sur une table
   génère un <tbody> par insertion, et tout finirait dans la même ligne. */
function dreamebeRow(_cells) {
  var tr = document.createElement('tr')
  for (var i = 0; i < _cells.length; i++) {
    var td = document.createElement('td')
    td.textContent = dreamebeText(_cells[i])
    tr.appendChild(td)
  }
  return tr
}

/* ================================================================== ROBOT */

function dreamebeProbe() {
  var id = dreamebeCurrentId()
  if (id === '') { dreamebeNeedsSave(); return }
  dreamebeStatus('{{Sondage…}}', 'info')
  dreamebeAjax('probe', { id: id }, function (result) {
    dreamebeStatus(result.result.count + ' {{propriétés}}', 'success')
    /* Les commandes viennent d'être créées côté serveur, et la page ne le sait
       pas : un « Sauvegarder » depuis cet onglet les effacerait toutes, le coeur
       supprimant à l'enregistrement toute commande absente du tableau. On
       recharge donc la fiche, ce qui est aussi la seule façon de voir le
       résultat du sondage. */
    jeedomUtils.showAlert({
      message: '{{Le robot a répondu sur}} ' + result.result.count
             + ' {{propriétés. Les commandes ont été mises à jour ; la fiche se recharge.}}',
      level: 'success'
    })
    dreamebeReload(id)
  })
}

/*
   Recharge la fiche de l'équipement.

   Nécessaire après toute action qui crée ou retire des commandes côté serveur :
   le tableau de l'onglet « Commandes » est alors périmé, et le coeur supprime à
   l'enregistrement toute commande qui n'y figure pas.
*/
function dreamebeReload(_id) {
  if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.loadPage === 'function') {
    /* La forme attendue par le coeur porte « m= » autant que « p= » : c'est
       celle qu'il construit lui-même pour rouvrir un équipement. */
    jeedomUtils.loadPage('index.php?v=d&m=dreamebe&p=dreamebe&id=' + encodeURIComponent(_id))
    return
  }
  window.location.reload()
}

function dreamebeRefresh() {
  var id = dreamebeCurrentId()
  if (id === '') { dreamebeNeedsSave(); return }
  dreamebeStatus('{{Interrogation…}}', 'info')
  dreamebeAjax('refresh', { id: id }, function () {
    dreamebeStatus('{{À jour}}', 'success')
  })
}

/* ================================================================== PIÈCES */

function dreamebeRooms(_reload, _reloadPage) {
  var id = dreamebeCurrentId()
  var body = dreamebeEl('tbody_dreamebeRooms')
  if (body === null) { return }
  if (id === '') { dreamebeNeedsSave(); return }

  body.innerHTML = ''
  dreamebeAjax('rooms', { id: id, reload: _reload ? 1 : 0 }, function (result) {
    var rooms = result.result
    if (!Array.isArray(rooms)) { rooms = [] }
    if (!rooms || rooms.length === 0) {
      body.appendChild(dreamebeRow(['', '{{Aucune pièce connue. Le robot doit avoir une carte enregistrée, et la récupération de la carte doit être activée dans la configuration du plugin.}}', '', '', '', '']))
      return
    }
    /* Relire les pièces crée et retire des commandes : la fiche doit repartir
       d'un état frais, sinon un enregistrement les effacerait. */
    if (_reloadPage) {
      jeedomUtils.showAlert({
        message: rooms.length + ' {{pièce(s) reconnue(s). Les commandes ont été mises à jour ; la fiche se recharge.}}',
        level: 'success'
      })
      dreamebeReload(id)
      return
    }
    for (var i = 0; i < rooms.length; i++) {
      var room = rooms[i]
      body.appendChild(dreamebeRow([
        room.id,
        room.name,
        (room.area !== null && room.area !== undefined) ? room.area + ' m²' : '',
        room.x + ', ' + room.y,
        (room.suction !== undefined && room.suction !== null) ? room.suction : '',
        (room.repeats !== undefined && room.repeats !== null) ? room.repeats : ''
      ]))
    }
  })
}

/* =================================================================== CARTE */

function dreamebeMap(_reload) {
  var id = dreamebeCurrentId()
  var img = dreamebeEl('img_dreamebeMap')
  var empty = dreamebeEl('div_dreamebeMapEmpty')
  if (img === null) { return }
  if (id === '') { dreamebeNeedsSave(); return }

  var show = function (_url) {
    img.onerror = function () {
      img.style.display = 'none'
      if (empty !== null) { empty.style.display = '' }
    }
    img.onload = function () {
      img.style.display = ''
      if (empty !== null) { empty.style.display = 'none' }
    }
    img.src = _url
  }

  if (!_reload) {
    show('plugins/dreamebe/core/php/map.php?id=' + encodeURIComponent(id) + '&t=' + Date.now())
    return
  }
  dreamebeStatus('{{Téléchargement de la carte…}}', 'info')
  dreamebeAjax('map', { id: id }, function (result) {
    if (!result.result.rendered) {
      dreamebeStatus('', null)
      jeedomUtils.showAlert({
        message: '{{Le robot n\'a pas de carte exploitable pour le moment. S\'il est en train de nettoyer, il ne publie que des cartes partielles : réessayez une fois le cycle terminé.}}',
        level: 'warning'
      })
      return
    }
    dreamebeStatus('{{Carte à jour}}', 'success')
    show(result.result.url)
  })
}

/* ============================================================== HISTORIQUE */

function dreamebeHistory(_reload) {
  var id = dreamebeCurrentId()
  var body = dreamebeEl('tbody_dreamebeHistory')
  if (body === null) { return }
  if (id === '') { dreamebeNeedsSave(); return }

  body.innerHTML = ''
  dreamebeAjax('history', { id: id, reload: _reload ? 1 : 0 }, function (result) {
    var history = result.result
    if (!Array.isArray(history)) { history = [] }
    if (history.length === 0) {
      body.appendChild(dreamebeRow(['', '{{Aucun nettoyage archivé pour ce robot.}}', '', '']))
      return
    }
    for (var i = 0; i < history.length; i++) {
      var record = history[i]
      var date = new Date(record.date * 1000)
      body.appendChild(dreamebeRow([
        date.toLocaleString(),
        (record.duration !== null) ? record.duration + ' min' : '',
        (record.area !== null) ? record.area + ' m²' : '',
        (record.completed === true) ? '{{Terminé}}'
          : ((record.completed === false) ? '{{Interrompu}}' : '')
      ]))
    }
  })
}

/* Hook appelé par plugin.template.js une fois l'équipement chargé. */
function printEqLogic(_eqLogic) {
  var capability = dreamebeEl('div_dreamebeCapability')
  if (capability !== null) {
    var supported = _eqLogic.configuration.supported
    var model = init(_eqLogic.configuration.model_name, init(_eqLogic.configuration.model, ''))
    if (!supported || supported.length === 0) {
      capability.textContent = '{{Capacités jamais sondées. Lancez le sondage : sans lui, le plugin ne sait pas ce que ce robot sait faire, et ses commandes restent incomplètes.}}'
    } else {
      capability.textContent = (model !== '' ? model + ' — ' : '')
        + supported.length + ' {{propriétés reconnues}}'
        + (_eqLogic.configuration.probed_at
            ? ' ({{sondé le}} ' + new Date(_eqLogic.configuration.probed_at * 1000).toLocaleString() + ')'
            : '')
    }
  }

  var img = dreamebeEl('img_dreamebeMap')
  if (img !== null) {
    img.style.display = 'none'
    img.removeAttribute('src')
  }
  var empty = dreamebeEl('div_dreamebeMapEmpty')
  if (empty !== null) { empty.style.display = '' }

  dreamebeStatus('', null)
}

/* =============================================================== COMMANDES */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes :
     l'historique est perdu et les scénarios pointent dans le vide. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  /* L'ordre compte : changeType après setJeeValues, jamais l'inverse. */
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu
   quand ce script s'exécute. Les écouteurs sont donc posés par délégation sur
   un conteneur qui, lui, existe déjà. */
var dreamebeContainer = document.getElementById('div_pageContainer') || document.body

dreamebeContainer.addEventListener('click', function (_event) {
  var target = _event.target
  if (target === null) { return }

  if (target.closest('#bt_dreamebeDiscoverMain') !== null) {
    _event.preventDefault()
    /* Sans compte, la découverte ne peut rien donner : mieux vaut ouvrir la
       configuration que laisser l'utilisateur devant un message d'erreur pour
       son tout premier geste dans le plugin. */
    /* Comparaison explicite à « 1 » : sendVarToJS entoure toute valeur de
       guillemets, et la chaîne "0" serait vraie en JavaScript. */
    if (typeof accountSet !== 'undefined' && String(accountSet) !== '1') {
      jeedomUtils.showAlert({
        message: '{{Renseignez d\'abord le compte DreameHome dans la configuration du plugin.}}',
        level: 'warning'
      })
      return
    }
    dreamebeAjax('discover', {}, function (result) {
      var report = result.result
      var liste = function (_noms) {
        if (!_noms || _noms.length === 0) { return '{{aucun}}' }
        return _noms.map(dreamebeEscape).join(', ')
      }
      bootbox.alert('{{Robots ajoutés}} : ' + liste(report.created)
                    + '<br>{{Déjà connus}} : ' + liste(report.updated),
                    function () { window.location.reload() })
    })
    return
  }
  if (target.closest('#bt_dreamebeProbe') !== null) {
    _event.preventDefault(); dreamebeProbe(); return
  }
  if (target.closest('#bt_dreamebeRefresh') !== null) {
    _event.preventDefault(); dreamebeRefresh(); return
  }
  if (target.closest('#bt_dreamebeRooms') !== null) {
    _event.preventDefault(); dreamebeRooms(true, true); return
  }
  if (target.closest('#bt_dreamebeMap') !== null) {
    _event.preventDefault(); dreamebeMap(true); return
  }
  if (target.closest('#bt_dreamebeHistory') !== null) {
    _event.preventDefault(); dreamebeHistory(true); return
  }

  /* Les onglets ne se remplissent qu'au moment où on les regarde : chacun de
     ces contenus coûte un appel, et les charger tous à l'ouverture de la fiche
     en ferait trois pour rien. */
  if (target.closest('a[href="#roomtab"]') !== null) { dreamebeRooms(false); return }
  if (target.closest('a[href="#maptab"]') !== null) { dreamebeMap(false); return }
  if (target.closest('a[href="#historytab"]') !== null) { dreamebeHistory(false); return }
})
