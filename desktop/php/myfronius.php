<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('myfronius');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<!-- Page d'accueil du plugin -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<div class="row">
			<div class="col-sm-10">
				<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
				<!-- Boutons de gestion du plugin -->
				<div class="eqLogicThumbnailContainer">
					<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
						<i class="fas fa-wrench"></i>
						<br>
						<span>{{Configuration}}</span>
					</div>
					<div class="cursor logoPrimary" id="bt_syncEquipments">
						<i class="fas fa-sync"></i>
						<br>
						<span>{{Synchroniser}}</span>
					</div>
					<div class="cursor logoSecondary" id="bt_addCharger" title="{{Ajoute une borne Wattpilot : renseignez ensuite son adresse et son mot de passe}}">
						<i class="fas fa-charging-station"></i>
						<br>
						<span>{{Ajouter une borne}}</span>
					</div>
					<div class="cursor logoSecondary" id="bt_addLoad" title="{{Ajoute une charge pilotée sur le surplus : renseignez ensuite ses commandes et sa puissance nominale}}">
						<i class="fas fa-plug"></i>
						<br>
						<span>{{Ajouter une charge}}</span>
					</div>
				</div>
			</div>
			<?php
			// à conserver
			// sera afficher uniquement si l'utilisateur est en version 4.4 ou supérieur
			$jeedomVersion  = jeedom::version() ?? '0';
			$displayInfoValue = version_compare($jeedomVersion, '4.4.0', '>=');
			if ($displayInfoValue) {
			?>
				<div class="col-sm-2">
					<legend><i class=" fas fa-comments"></i> {{Community}}</legend>
					<div class="eqLogicThumbnailContainer">
						<div class="cursor eqLogicAction logoSecondary" data-action="createCommunityPost">
							<i class="fas fa-ambulance"></i>
							<br>
							<span style="color:var(--txt-color)">{{Créer un post Community}}</span>
						</div>
					</div>
				</div>
			<?php
			}
			?>
		</div>
		<?php
		if (count($eqLogics) == 0) {
			echo '<legend><i class="fas fa-table"></i> {{Mes équipements}}</legend>';
			echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement trouvé. Configurez l\'adresse de l\'onduleur puis cliquez sur "Synchroniser".}}</div>';
		} else {
			// Champ de recherche
			echo '<div class="input-group" style="margin:5px;">';
			echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
			echo '<div class="input-group-btn">';
			echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
			echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
			echo '</div>';
			echo '</div>';

			// Mapping icône + couleur par type
			$typeIconMap = [
				'site'     => ['icon' => 'fa-solar-panel',    'color' => '#f0a030'],
				'battery'  => ['icon' => 'fa-battery-half',   'color' => '#3c9a5f'],
				'inverter' => ['icon' => 'fa-microchip',      'color' => '#4a6fa5'],
				'meter'    => ['icon' => 'fa-tachometer-alt', 'color' => '#6c757d'],
				'charger'  => ['icon' => 'fa-charging-station', 'color' => '#a855f7'],
				'load'     => ['icon' => 'fa-plug',             'color' => '#ef6c4d'],
			];
			$defaultIcon = ['icon' => 'fa-plug', 'color' => '#6c757d'];

			// Définition des zones (toutes optionnelles : masquées si vides)
			// Trois zones : l'installation (site, batterie, onduleurs, compteurs), les bornes, les charges
			$zones = [
				['key' => 'site',    'label' => '{{Site}}',               'icon' => 'fa-solar-panel',      'types' => ['site', 'battery', 'inverter', 'meter'], 'optional' => true],
				['key' => 'charger', 'label' => '{{Bornes de recharge}}', 'icon' => 'fa-charging-station', 'types' => ['charger'], 'optional' => true],
				['key' => 'load',    'label' => '{{Charges pilotées}}',   'icon' => 'fa-plug',             'types' => ['load'],    'optional' => true],
			];

			// Pré-indexer les eqLogics par type
			$byType = [];
			$uncategorized = [];
			foreach ($eqLogics as $eqLogic) {
				$t = trim((string) $eqLogic->getConfiguration('type', ''));
				if (isset($typeIconMap[$t])) {
					$byType[$t][] = $eqLogic;
				} else {
					$uncategorized[] = $eqLogic;   // type vide ou inconnu : toujours visible
				}
			}

			// Rendu des zones
			foreach ($zones as $zone) {
				$zoneEqs = [];
				foreach ($zone['types'] as $t) {
					if (!empty($byType[$t])) {
						$zoneEqs = array_merge($zoneEqs, $byType[$t]);
					}
				}
				if ($zone['optional'] && count($zoneEqs) === 0) {
					continue;
				}
				echo '<legend class="mfr-zone-toggle" data-zone="' . $zone['key'] . '">';
				echo '<i class="fas ' . $zone['icon'] . '"></i> ' . $zone['label'];
				echo ' <i class="fas fa-chevron-down mfr-zone-chevron"></i>';
				echo '</legend>';
				echo '<div class="mfr-zone-content" data-zone="' . $zone['key'] . '">';
				echo '<div class="eqLogicThumbnailContainer">';
				if (count($zoneEqs) === 0) {
					echo '<br><div class="text-center" style="font-size:1em;color:var(--txt-color-low);">{{Aucun équipement dans cette zone.}}</div>';
				} else {
					foreach ($zoneEqs as $eqLogic) {
						$mfrType  = $eqLogic->getConfiguration('type', '');
						$iconData = isset($typeIconMap[$mfrType]) ? $typeIconMap[$mfrType] : $defaultIcon;
						$opacity  = $eqLogic->getIsEnable() ? '' : 'disableCard';
						echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
						echo '<div class="mfr-card-icon" style="background-color:' . $iconData['color'] . ';">';
						echo '<i class="fas ' . $iconData['icon'] . '"></i>';
						echo '</div>';
						echo '<br>';
						echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
						echo '<span class="hiddenAsCard displayTableRight hidden">';
						echo ($eqLogic->getIsVisible() == 1)
							? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>'
							: '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
						echo '</span>';
						echo '</div>';
					}
				}
				echo '</div>'; // .eqLogicThumbnailContainer
				echo '</div>'; // .mfr-zone-content
			}

			// Zone de secours pour équipements sans type
			if (!empty($uncategorized)) {
				echo '<legend><i class="fas fa-question-circle"></i> {{Non classés}}</legend>';
				echo '<div class="eqLogicThumbnailContainer">';
				foreach ($uncategorized as $eqLogic) {
					$opacity = $eqLogic->getIsEnable() ? '' : 'disableCard';
					echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
					echo '<img src="' . $eqLogic->getImage() . '"/>';
					echo '<br>';
					echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
					echo '</div>';
				}
				echo '</div>';
			}
		}
		?>
	</div> <!-- /.eqLogicThumbnailDisplay -->

	<!-- Page de présentation de l'équipement -->
	<div class="col-xs-12 eqLogic" style="display: none;">
		<!-- barre de gestion de l'équipement -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
		</ul>
		<div class="tab-content">
			<!-- Onglet de configuration de l'équipement -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<!-- Partie gauche de l'onglet "Equipements" -->
				<!-- Paramètres généraux et spécifiques de l'équipement -->
				<form class="form-horizontal">
					<fieldset>
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										$options = '';
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											$options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										echo $options;
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-6">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" >' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-6">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
								</div>
							</div>

							<legend data-mfr-types="site,battery,inverter,meter"><i class="fas fa-cogs"></i> {{Informations Fronius}}</legend>
							<!-- Champs en lecture seule : des inputs readonly (et non des spans) pour que la
							     sauvegarde renvoie exactement la valeur, sans espaces ni altération. -->
							<div class="form-group" data-mfr-types="site,battery,inverter,meter">
								<label class="col-sm-4 control-label">{{Type}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" id="mfr_type_hidden" data-l1key="configuration" data-l2key="type" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="inverter,meter">
								<label class="col-sm-4 control-label">{{Identifiant}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_id" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="battery,inverter,meter">
								<label class="col-sm-4 control-label">{{Fabricant}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="manufacturer">
								</div>
							</div>
							<div class="form-group" data-mfr-types="battery,inverter,meter">
								<label class="col-sm-4 control-label">{{Modèle}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model">
								</div>
							</div>
							<div class="form-group" data-mfr-types="inverter">
								<label class="col-sm-4 control-label">{{Nom (onduleur)}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="custom_name">
								</div>
							</div>
							<div class="form-group" data-mfr-types="battery,inverter,meter">
								<label class="col-sm-4 control-label">{{N° de série}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="serial" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="inverter">
								<label class="col-sm-4 control-label">{{Type d'appareil}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_type" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="inverter">
								<label class="col-sm-4 control-label">{{MPPT présents}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mppt" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="inverter">
								<label class="col-sm-4 control-label">{{Puissance crête (W)}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="pv_power" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="inverter">
								<label class="col-sm-4 control-label">{{Firmware}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="firmware" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="meter">
								<label class="col-sm-4 control-label">{{Emplacement compteur}}</label>
								<div class="col-sm-6">
									<input type="text" readonly class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="location" style="font-family:monospace;">
								</div>
							</div>
							<div class="form-group" data-mfr-types="site,battery,inverter,meter,charger">
								<label class="col-sm-4 control-label">{{Mode commandes}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmd_mode">
										<option value="standard">{{Standard}}</option>
										<option value="expert">{{Expert}}</option>
									</select>
									<span id="cmd_mode_expert_warning" class="help-block text-warning" style="display:none;">
										{{En mode expert, toutes les commandes techniques Fronius seront créées. Sauvegardez pour déclencher la régénération automatique.}}
									</span>
									<span id="cmd_mode_standard_warning" class="help-block text-warning" style="display:none;">
										{{En repassant en mode standard, les commandes techniques (expert) et leur historique seront supprimés à la sauvegarde.}}
									</span>
								</div>
							</div>
							<div id="mfr_battery_only" style="display:none;">
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Capacité batterie (Wh)}}</label>
									<div class="col-sm-6">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="capacity_wh" placeholder="{{Ex. : 12636}}">
										<span class="help-block">{{Utilisée pour les estimations de temps si l'onduleur ne fournit pas la capacité}}</span>
									</div>
								</div>
							</div>
							<div id="mfr_charger_only" style="display:none;">
								<legend><i class="fas fa-charging-station"></i> {{Connexion à la borne}}</legend>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Adresse de la borne}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Adresse IP ou nom DNS du Wattpilot sur le réseau local}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="host" placeholder="192.168.1.60">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Mot de passe}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe de la borne défini dans l'application Wattpilot.Solar (pas le compte Solar.web)}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" autocomplete="new-password">
										<span class="help-block">{{Le démon redémarre automatiquement après modification de l'adresse, du mot de passe ou de l'activation de la borne.}}</span>
									</div>
								</div>
							</div>
							<div id="mfr_load_only" style="display:none;">
								<legend><i class="fas fa-plug"></i> {{Commandes de la charge}}</legend>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Puissance mesurée (W)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Commande info numérique de n&#39;importe quel plugin (ex. Shelly PM). Optionnelle : sans mesure, la puissance nominale est utilisée quand la charge est allumée.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<div class="input-group input-group-sm">
											<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmd_power" placeholder="{{Aucune}}">
											<span class="input-group-btn">
												<a class="btn btn-default mfr-pick" data-kind="info" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
												<a class="btn btn-default mfr-pick-clear" title="{{Retirer}}"><i class="fas fa-times"></i></a>
											</span>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{État (allumé / éteint)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Commande info binaire. Optionnelle : sans état, le plugin se fie à son dernier ordre.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<div class="input-group input-group-sm">
											<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmd_state" placeholder="{{Aucune}}">
											<span class="input-group-btn">
												<a class="btn btn-default mfr-pick" data-kind="info" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
												<a class="btn btn-default mfr-pick-clear" title="{{Retirer}}"><i class="fas fa-times"></i></a>
											</span>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Allumer}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Commande action qui allume la charge. Avec « Éteindre », rend la charge pilotable ; sans, elle est affichée en lecture seule.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<div class="input-group input-group-sm">
											<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmd_on" placeholder="{{Aucune}}">
											<span class="input-group-btn">
												<a class="btn btn-default mfr-pick" data-kind="action" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
												<a class="btn btn-default mfr-pick-clear" title="{{Retirer}}"><i class="fas fa-times"></i></a>
											</span>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Allumer — actions supplémentaires}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Optionnel. Actions exécutées dans l&#39;ordre, en arrière-plan, juste après « Allumer » (vanne, consigne, second relais, notification…). Les paramètres (valeur, liste, message) s&#39;affichent selon la commande choisie ; les actions de scénario (wait, variable…) sont aussi acceptées, une attente ne retarde pas le reste du plugin. Décocher une ligne la désactive sans la supprimer.}}"></i></sup>
									</label>
									<div class="col-sm-8">
										<a class="btn btn-default btn-xs mfr-action-add" data-type="on"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
										<div class="mfr-actions" data-type="on" style="margin-top:5px;"></div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Éteindre}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Commande action qui éteint la charge.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<div class="input-group input-group-sm">
											<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmd_off" placeholder="{{Aucune}}">
											<span class="input-group-btn">
												<a class="btn btn-default mfr-pick" data-kind="action" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
												<a class="btn btn-default mfr-pick-clear" title="{{Retirer}}"><i class="fas fa-times"></i></a>
											</span>
										</div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Éteindre — actions supplémentaires}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Optionnel. Actions exécutées dans l&#39;ordre, en arrière-plan, juste après « Éteindre » (vanne, consigne, second relais, notification…). Les paramètres (valeur, liste, message) s&#39;affichent selon la commande choisie ; les actions de scénario (wait, variable…) sont aussi acceptées, une attente ne retarde pas le reste du plugin. Décocher une ligne la désactive sans la supprimer.}}"></i></sup>
									</label>
									<div class="col-sm-8">
										<a class="btn btn-default btn-xs mfr-action-add" data-type="off"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
										<div class="mfr-actions" data-type="off" style="margin-top:5px;"></div>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Autorisation (commande ou formule)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Optionnel. Une commande ou une formule, avec la syntaxe des conditions de scénario : #[Objet][Équipement][Commande]# < 55 AND #[…][…][Présence]# == 1. Vide = toujours autorisée. Si la formule devient fausse, la charge est éteinte immédiatement. Le bouton insère une commande à l&#39;endroit du curseur.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<div class="input-group input-group-sm">
											<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmd_allow" placeholder="{{Aucune}}">
											<span class="input-group-btn">
												<a class="btn btn-default mfr-pick" data-kind="expr" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
												<a class="btn btn-default mfr-pick-clear" title="{{Retirer}}"><i class="fas fa-times"></i></a>
											</span>
										</div>
									</div>
								</div>
								<legend><i class="fas fa-sliders-h"></i> {{Règles de pilotage}}</legend>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Puissance nominale (W)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Puissance absorbée par la charge quand elle est allumée. Sert à la décision d&#39;allumage et de repli si pas de mesure.}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="nominal_w">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Priorité}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Ordre de service : 1 = servie en premier quand le surplus apparaît, délestée en dernier en cas de déficit.}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="1" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="priority">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Priorité au repos}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Optionnel. Priorité appliquée quand la charge est allumée mais que sa puissance mesurée est nulle (thermostat coupé, cycle terminé…), pour laisser passer les autres charges. Nécessite une commande de puissance renvoyant un nombre. Vide = désactivé.}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="1" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="idle_priority" placeholder="{{Aucune}}">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Marge de surplus (%)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Allumage si le surplus atteint nominale × marge / 100. Ex. 130 % : 2 600 W de surplus pour une charge de 2 000 W. Sous 100 %, la charge démarre sur un surplus incomplet et la batterie fournit le reste : elle s&#39;éteint si la décharge dépasse puissance − nominale × marge (ex. 80 % : 400 W).}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="50" max="500" step="5" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="start_factor">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Délai avant allumage (s)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Le surplus doit rester suffisant pendant ce délai avant d&#39;allumer.}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="start_delay">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Délai avant extinction (s)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{L&#39;import réseau (ou la décharge de la batterie au-delà du budget) doit dépasser la tolérance pendant ce délai avant d&#39;éteindre.}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="stop_delay">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Marche minimum (s)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Durée minimale de marche une fois allumée (anti-cycling).}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="min_on">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Arrêt minimum (s)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Durée minimale d&#39;arrêt avant de rallumer (anti-cycling).}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="min_off">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Tolérance d&#39;import (W)}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Import réseau toléré sans déclencher l&#39;extinction (bruit de mesure). S&#39;applique aussi à la décharge de la batterie, en plus du budget accordé par la marge.}}"></i></sup>
									</label>
									<div class="col-sm-3">
										<input type="number" min="0" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="import_tolerance">
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Peut utiliser la charge batterie}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Décide sur le surplus batterie incluse (export + charge batterie) au lieu du seul export : la charge est servie avant la batterie.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="use_battery">
											<option value="0">{{Non — le surplus va d&#39;abord à la batterie}}</option>
											<option value="1">{{Oui — la charge passe avant la batterie}}</option>
										</select>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Mode}}
										<sup><i class="fas fa-question-circle tooltips" title="{{Appliqué immédiatement par les commandes de mode (comme le widget), sans passer par Sauvegarder : une sauvegarde de la page ne modifie jamais le mode.}}"></i></sup>
									</label>
									<div class="col-sm-6">
										<!-- Hors eqLogicAttr : le mode est un état changé à chaud par les commandes ; lié au
										     formulaire, une page ouverte avant un forçage le rétablirait à la sauvegarde -->
										<select id="mfr_load_mode" class="form-control">
											<option value="auto">{{Automatique (surplus)}}</option>
											<option value="on">{{Forcé marche}}</option>
											<option value="off">{{Forcé arrêt}}</option>
										</select>
									</div>
								</div>
							</div>
							<div id="mfr_site_only" style="display:none;">
								<legend><i class="fas fa-th-large"></i> {{Widget}}</legend>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Indicateurs (surplus, autonomie…)}}</label>
									<div class="col-sm-6">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="widget_kpi">
											<option value="">{{Afficher}}</option>
											<option value="0">{{Masquer}}</option>
										</select>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Énergies du jour}}</label>
									<div class="col-sm-6">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="widget_day">
											<option value="">{{Afficher}}</option>
											<option value="0">{{Masquer}}</option>
										</select>
									</div>
								</div>
								<div class="form-group">
									<label class="col-sm-4 control-label">{{Modes (site, secours, communication)}}</label>
									<div class="col-sm-6">
										<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="widget_chips">
											<option value="">{{Afficher}}</option>
											<option value="0">{{Masquer}}</option>
										</select>
									</div>
								</div>
							</div>
							<div class="form-group" data-mfr-types="site,battery,inverter,meter,charger">
								<div class="col-sm-offset-4 col-sm-6">
									<a class="btn btn-warning btn-sm" id="bt_regenerateCommands">
										<i class="fas fa-redo"></i> {{Régénérer les commandes}}
									</a>
								</div>
							</div>
						</div>

						<!-- Partie droite de l'onglet "Équipement" -->
						<!-- Affiche un champ de commentaire par défaut mais vous pouvez y mettre ce que vous voulez -->
						<div class="col-lg-6">
							<legend><i class="fas fa-info"></i> {{Informations}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Description}}</label>
								<div class="col-sm-6">
									<textarea class="form-control eqLogicAttr autogrow" data-l1key="comment"></textarea>
								</div>
							</div>
						</div>
					</fieldset>
				</form>
			</div><!-- /.tabpanel #eqlogictab-->

			<!-- Onglet des commandes de l'équipement -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<a class="btn btn-default btn-sm pull-right cmdAction" data-action="add" style="margin-top:5px;"><i class="fas fa-plus-circle"></i> {{Ajouter une commande}}</a>
				<br><br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
								<th style="min-width:200px;width:350px;">{{Nom}}</th>
								<th>{{Type}}</th>
								<th style="min-width:260px;">{{Options}}</th>
								<th>{{Etat}}</th>
								<th style="min-width:80px;width:200px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody>
						</tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab-->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<!-- Inclusion du fichier javascript du plugin (dossier, nom_du_fichier, extension_du_fichier, id_du_plugin) -->
<?php include_file('desktop', 'myfronius', 'js', 'myfronius'); ?>
<?php include_file('desktop', 'myfronius', 'css', 'myfronius'); ?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js'); ?>