<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('dreamebe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$accountSet = trim(config::byKey('username', 'dreamebe', '')) !== '';
/* Le JS s'en sert pour ne pas lancer une découverte vouée à l'échec, et
 * proposer d'aller renseigner le compte à la place. */
sendVarToJS('accountSet', $accountSet ? '1' : '');
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor logoPrimary" id="bt_dreamebeDiscoverMain">
				<i class="fas fa-search"></i>
				<br>
				<span>{{Découvrir les robots}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-list"></i> {{Mes robots}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun robot pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			if (!$accountSet) {
				echo '<li>{{Ouvrez la configuration et renseignez le compte de l\'application DreameHome, ainsi que la région choisie à sa création. Un compte Mi Home ne convient pas : ce sont deux univers séparés.}}</li>';
			}
			echo '<li>{{Utilisez « Tester le compte » dans la configuration : le plugin affiche les robots qu\'il voit, leur modèle et leur état de connexion, sans rien créer.}}</li>';
			echo '<li>{{Cliquez ensuite sur « Découvrir les robots » : chaque robot du compte devient un équipement Jeedom indépendant.}}</li>';
			echo '<li>{{Le plugin demande alors au robot ce qu\'il sait faire, et ne crée que les commandes correspondantes. Un robot éteint ne répond pas : rallumez-le puis relancez le sondage depuis sa fiche.}}</li>';
			echo '</ol>';
			echo '<span class="help-block" style="margin:8px 0 0 0;">{{Ce plugin n\'est pas affilié à Dreame. Il parle au cloud DreameHome comme le fait l\'application mobile.}}</span>';
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
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-robot" style="font-size:4em;"></i>';
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
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#roomtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-door-open"></i><span class="hidden-xs"> {{Pièces}}</span></a></li>
			<li role="presentation"><a href="#maptab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-map"></i><span class="hidden-xs"> {{Carte}}</span></a></li>
			<li role="presentation"><a href="#historytab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-history"></i><span class="hidden-xs"> {{Historique}}</span></a></li>
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
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Robot}}">
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
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-robot"></i> {{Robot}}</legend>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Identifiant DreameHome}}</label>
								<div class="col-sm-8">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="did" readonly>
									<span class="help-block" style="margin:4px 0 0 0;">{{Attribué par le cloud. Il ne se saisit pas : c'est la découverte qui le renseigne, et le modifier couperait le lien avec le robot.}}</span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Modèle}}</label>
								<div class="col-sm-8">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model_name" readonly>
									<span class="help-block" style="margin:4px 0 0 0;">{{Identifiant technique}} : <span class="eqLogicAttr" data-l1key="configuration" data-l2key="model"></span></span>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Micrologiciel}}</label>
								<div class="col-sm-8">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="firmware" readonly>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label"></label>
								<div class="col-sm-8">
									<div class="alert alert-info" id="div_dreamebeCapability" style="margin-bottom:8px;">{{Chargement…}}</div>
									<a class="btn btn-default btn-sm" id="bt_dreamebeProbe"><i class="fas fa-stethoscope"></i> {{Sonder les capacités}}</a>
									<a class="btn btn-default btn-sm" id="bt_dreamebeRefresh"><i class="fas fa-sync"></i> {{Actualiser maintenant}}</a>
									<span id="span_dreamebeStatus" style="margin-left:10px;"></span>
									<span class="help-block" style="margin:8px 0 0 0;">{{Le sondage demande au robot, propriété par propriété, ce à quoi il répond. Il n'existe aucune table par modèle : c'est la machine qui tranche, et seules les commandes correspondant à ses réponses sont créées. À relancer après une mise à jour de son micrologiciel.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ============================ PIÈCES ========================== -->
			<div role="tabpanel" class="tab-pane" id="roomtab">
				<br>
				<div class="col-xs-12">
					<span class="help-block">{{Les pièces ne sont exposées nulle part par le protocole : leurs noms n'existent que dans la carte, et c'est en la décodant que le plugin les retrouve. Chaque pièce donne une commande « Nettoyer : … », et la commande « Nettoyer des pièces » accepte plusieurs noms ou identifiants séparés par des virgules.}}</span>
					<a class="btn btn-default btn-sm" id="bt_dreamebeRooms"><i class="fas fa-sync"></i> {{Relire les pièces}}</a>
					<br><br>
					<table class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:80px;">{{Identifiant}}</th>
								<th>{{Nom}}</th>
								<th style="width:110px;">{{Surface}}</th>
								<th style="width:160px;">{{Centre (mm)}}</th>
								<th style="width:120px;">{{Aspiration}}</th>
								<th style="width:110px;">{{Passages}}</th>
							</tr>
						</thead>
						<tbody id="tbody_dreamebeRooms"></tbody>
					</table>
				</div>
			</div>

			<!-- ============================ CARTE =========================== -->
			<div role="tabpanel" class="tab-pane" id="maptab">
				<br>
				<div class="col-xs-12">
					<span class="help-block">{{Le sol coloré par pièce, les murs, la station et le robot avec son orientation. Les meubles, les obstacles détectés et le trajet ne sont pas représentés : leur rendu demanderait des ressources graphiques que ce plugin n'embarque pas.}}</span>
					<a class="btn btn-default btn-sm" id="bt_dreamebeMap"><i class="fas fa-sync"></i> {{Retélécharger la carte}}</a>
					<br><br>
					<img id="img_dreamebeMap" style="max-width:100%;border-radius:var(--border-radius);background:var(--background-color);display:none;">
					<div class="alert alert-info" id="div_dreamebeMapEmpty">{{Aucune carte pour le moment. Elle est téléchargée au cycle d'actualisation, ou tout de suite avec le bouton ci-dessus.}}</div>
				</div>
			</div>

			<!-- ========================== HISTORIQUE ======================== -->
			<div role="tabpanel" class="tab-pane" id="historytab">
				<br>
				<div class="col-xs-12">
					<span class="help-block">{{Il n'existe pas d'historique de nettoyage à proprement parler : ce que le cloud archive, ce sont les événements du robot, chacun portant une photographie de ses propriétés du moment. C'est de là que viennent ces lignes. Les pièces réellement nettoyées, elles, ne sont pas récupérables.}}</span>
					<a class="btn btn-default btn-sm" id="bt_dreamebeHistory"><i class="fas fa-sync"></i> {{Relire l'historique}}</a>
					<br><br>
					<table class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:180px;">{{Date}}</th>
								<th style="width:120px;">{{Durée}}</th>
								<th style="width:120px;">{{Surface}}</th>
								<th>{{Fin}}</th>
							</tr>
						</thead>
						<tbody id="tbody_dreamebeHistory"></tbody>
					</table>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================= -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:250px;">{{Nom}}</th>
								<th style="width:120px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'dreamebe', 'js', 'dreamebe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
