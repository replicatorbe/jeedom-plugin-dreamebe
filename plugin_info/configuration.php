<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-user"></i> {{Compte DreameHome}}</legend>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Identifiant du compte}}</label>
			<div class="col-lg-3">
				<input class="configKey form-control" data-l1key="username" placeholder="vous@exemple.com" />
			</div>
			<div class="col-lg-5">
				<span class="help-block">{{Le compte de l'application DreameHome, pas un compte Xiaomi ni Mi Home : ce sont deux univers séparés, et un compte Mi Home sera refusé. C'est généralement l'adresse électronique. Si le compte a été créé avec Google ou Apple, c'est en revanche l'identifiant affiché dans le profil de l'application qu'il faut saisir, et non l'adresse — celle-ci est alors refusée.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Mot de passe}}</label>
			<div class="col-lg-3">
				<input type="password" class="configKey form-control" data-l1key="password" autocomplete="new-password" />
			</div>
			<div class="col-lg-5">
				<span class="help-block">{{Il est conservé dans la base de Jeedom. Le plugin ne s'en sert qu'à la première connexion : ensuite il renouvelle sa session avec un jeton, ce qui évite de solliciter l'authentification, dont le nombre de tentatives est limité côté Dreame.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Région}}</label>
			<div class="col-lg-3">
				<select class="configKey form-control" data-l1key="region">
					<option value="eu">{{Europe}}</option>
					<option value="us">{{Amérique}}</option>
					<option value="cn">{{Chine}}</option>
					<option value="ru">{{Russie}}</option>
					<option value="sg">{{Singapour}}</option>
					<option value="kr">{{Corée}}</option>
				</select>
			</div>
			<div class="col-lg-5">
				<span class="help-block">{{Celle choisie à la création du compte. Un compte n'existe que dans une seule région, et se tromper donne « identifiants refusés » — pas « mauvaise région ». Si le serveur en indique une autre, le plugin la corrige tout seul.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label"></label>
			<div class="col-lg-8">
				<a class="btn btn-default" id="bt_dreamebeTestAccount"><i class="fas fa-plug"></i> {{Tester le compte}}</a>
				<a class="btn btn-default" id="bt_dreamebeDiscover"><i class="fas fa-search"></i> {{Découvrir les robots}}</a>
				<span id="span_dreamebeAccountStatus" style="margin-left:10px;"></span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-sync"></i> {{Actualisation}}</legend>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Intervalle (s)}}</label>
			<div class="col-lg-2">
				<input type="number" class="configKey form-control" data-l1key="polling_interval" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Entre deux lectures de l'état. Ramené à une minute pendant un nettoyage, pour que la progression avance sous les yeux. En dessous de 60 s, le cœur de Jeedom ne saurait de toute façon pas déclencher plus souvent.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Entretien et statistiques (s)}}</label>
			<div class="col-lg-2">
				<input type="number" class="configKey form-control" data-l1key="slow_interval" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Ces chiffres avancent d'un point par semaine : les relire à chaque cycle ne produirait que du trafic.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Historique des nettoyages}}</label>
			<div class="col-lg-2">
				<input type="checkbox" class="configKey" data-l1key="history_enable" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Renseigne la date, la durée et la surface du dernier nettoyage. Relu toutes les demi-heures, jamais plus souvent.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-map"></i> {{Carte}}</legend>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Récupérer la carte}}</label>
			<div class="col-lg-2">
				<input type="checkbox" class="configKey" data-l1key="map_enable" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{La carte est le seul endroit où figurent les noms des pièces : sans elle, le nettoyage par pièce n'est pas possible. Elle apporte aussi la position du robot et une image du plan.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Intervalle de la carte (s)}}</label>
			<div class="col-lg-2">
				<input type="number" class="configKey form-control" data-l1key="map_interval" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Une carte pèse plusieurs centaines de kilo-octets et demande trois requêtes. Elle est mise en cache sur le disque et n'est retéléchargée qu'à cet intervalle.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-network-wired"></i> {{Réseau}}</legend>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Délai de connexion (s)}}</label>
			<div class="col-lg-2">
				<input type="number" class="configKey form-control" data-l1key="connect_timeout" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Attente maximale pour joindre le serveur Dreame.}}</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-lg-4 control-label">{{Délai de réponse (s)}}</label>
			<div class="col-lg-2">
				<input type="number" class="configKey form-control" data-l1key="timeout" />
			</div>
			<div class="col-lg-6">
				<span class="help-block">{{Le cloud attend lui-même l'accusé du robot pendant quelques secondes avant d'abandonner : inutile de descendre trop bas, ni de monter très haut.}}</span>
			</div>
		</div>
	</fieldset>
</form>

<script>
/*
 * Le JS de la page du plugin n'est pas chargé dans la fenêtre de configuration :
 * ces deux boutons ont donc leur code ici, au plus près des champs qu'ils
 * utilisent.
 */
(function () {
  /*
   * Cette fenêtre est réinsérée — et ce script réexécuté — à chaque ouverture,
   * alors que l'écouteur, lui, vit sur « document » et n'est jamais retiré.
   * Sans ce verrou, ouvrir puis rouvrir la configuration doublerait chaque clic :
   * deux enregistrements, deux authentifications, deux fenêtres empilées. Or
   * c'est précisément l'authentification dont le nombre de tentatives est limité
   * côté Dreame.
   */
  if (window.dreamebeConfigBound) { return }
  window.dreamebeConfigBound = true

  function say(_text, _level) {
    var status = document.getElementById('span_dreamebeAccountStatus')
    if (status === null) { return }
    status.textContent = _text
    status.className = _level ? 'label label-' + _level : ''
  }

  /* Le nom d'un robot est saisi dans l'application mobile — et sur un robot
     partagé, par quelqu'un d'autre. bootbox insère son message tel quel : sans
     échappement, ce nom pourrait écrire dans une page ouverte en session
     administrateur. */
  function escape(_value) {
    var div = document.createElement('div')
    div.textContent = (_value === null || _value === undefined) ? '' : String(_value)
    return div.innerHTML
  }

  function call(_action, _onSuccess) {
    domUtils.ajax({
      type: 'POST',
      url: 'plugins/dreamebe/core/ajax/dreamebe.ajax.php',
      data: { action: _action },
      dataType: 'json',
      error: function (error) {
        say('', null)
        jeedomUtils.showAlert({
          message: (error && error.message) ? error.message : String(error),
          level: 'danger'
        })
      },
      /* ajax::error() du coeur répond en HTTP 200 avec state = error : sans ce
         contrôle, chaque échec remonterait comme un succès et le message que le
         serveur a pris soin de rédiger ne serait jamais affiché. */
      success: function (result) {
        if (!result || result.state !== 'ok') {
          say('', null)
          jeedomUtils.showAlert({
            message: (result && result.result) ? String(result.result) : '{{Erreur inconnue}}',
            level: 'danger'
          })
          return
        }
        _onSuccess(result)
      }
    })
  }

  /*
   * Enregistre le compte avant d'agir.
   *
   * Les identifiants viennent peut-être d'être saisis sans être enregistrés :
   * sans cette étape, le test porterait sur les anciens et l'on chercherait
   * longtemps pourquoi. La collecte est bornée au conteneur de la configuration
   * du plugin : la page porte aussi les champs du coeur — niveau de journal,
   * battement de coeur — qui n'ont rien à faire dans notre espace de noms.
   */
  function saveThen(_next) {
    var container = document.getElementById('div_plugin_configuration')
    if (container === null || typeof container.getJeeValues !== 'function') {
      _next()
      return
    }
    say('{{Enregistrement…}}', 'info')
    jeedom.config.save({
      plugin: 'dreamebe',
      configuration: container.getJeeValues('.configKey')[0],
      error: function (error) {
        say('', null)
        jeedomUtils.showAlert({
          message: (error && error.message) ? error.message : String(error),
          level: 'danger'
        })
      },
      success: function () {
        /* Le coeur remet ce drapeau après chacun de ses propres
           enregistrements ; sans cela, quitter la page déclencherait un
           avertissement « modifications non enregistrées » injustifié. */
        if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = false }
        _next()
      }
    })
  }

  document.addEventListener('click', function (_event) {
    var target = _event.target
    if (target === null) { return }

    if (target.closest('#bt_dreamebeTestAccount') !== null) {
      _event.preventDefault()
      saveThen(function () {
        say('{{Connexion…}}', 'info')
        call('testAccount', function (result) {
          var devices = result.result.devices
          if (!devices || devices.length === 0) {
            say('{{Compte accepté, aucun robot}}', 'warning')
            bootbox.alert('{{Le compte fonctionne, mais il ne porte aucun robot. Vérifiez que vous utilisez bien le compte de l\'application DreameHome, et la région où il a été créé.}}')
            return
          }
          var lines = []
          for (var i = 0; i < devices.length; i++) {
            lines.push(escape(devices[i].name) + ' — ' + escape(devices[i].model)
                       + ' (' + escape(devices[i].raw_model) + ') — '
                       + (devices[i].online ? '{{en ligne}}' : '{{hors ligne}}'))
          }
          say(devices.length + ' {{robot(s)}}', 'success')
          bootbox.alert(lines.join('<br>'))
        })
      })
      return
    }

    if (target.closest('#bt_dreamebeDiscover') !== null) {
      _event.preventDefault()
      /* Même enregistrement préalable que le bouton voisin : deux boutons côte à
         côte qui ne se comportent pas pareil laisseraient l'utilisateur devant
         un échec dont l'ordre des clics serait la seule cause. */
      saveThen(function () {
        say('{{Découverte…}}', 'info')
        call('discover', function (result) {
          var report = result.result
          var liste = function (_noms) {
            if (!_noms || _noms.length === 0) { return '{{aucun}}' }
            return _noms.map(escape).join(', ')
          }
          say(report.total + ' {{robot(s)}}', 'success')
          bootbox.alert('{{Robots ajoutés}} : ' + liste(report.created)
                        + '<br>{{Déjà connus}} : ' + liste(report.updated)
                        + '<br><br>{{Fermez cette fenêtre pour les voir apparaître dans la liste.}}')
        })
      })
    }
  })
})()
</script>
