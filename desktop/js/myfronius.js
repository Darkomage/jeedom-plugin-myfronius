/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* Jeedom 4.4 ré-exécute ce fichier à chaque loadPage (retour par le menu, succès d'une sauvegarde)
 * sans retirer les handlers délégués posés sur document : ils sont tous dans l'espace de noms
 * .mfr et retirés ici avant d'être reposés, sinon chaque clic les exécuterait N fois. */
$(document).off('.mfr');

/* Marque l'équipement « modifié sans sauvegarder » : le core 4.4 ne lit que ce drapeau
 * (une classe CSS sur .eqLogic n'a aucun effet), et ne le lève pas pour un .val() ni pour
 * les champs .expressionAttr des actions. */
function mfrSetModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true; }
  window.modifyWithoutSave = true;
}

/* Synchronisation des équipements depuis l'onduleur Fronius */
$('#bt_syncEquipments').off('click').on('click', function () {
  $('#div_alert').showAlert({
    message: '{{Synchronisation en cours…}}',
    level: 'warning'
  });
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: { action: 'syncEquipments' },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error);
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      var result = data.result;
      // Démon actif : la découverte est mise en file et la synchro s'effectue
      // via le callback du démon quelques secondes plus tard.
      if (result && result.queued) {
        $('#div_alert').showAlert({
          message: '{{Synchronisation mise en file du démon — mise à jour dans quelques secondes…}}',
          level: 'info'
        });
        setTimeout(function () { window.location.reload(); }, 6000);
        return;
      }
      // Démon arrêté : synchro directe, résultat immédiat.
      var msg = '{{Synchronisation terminée}} — '
        + result.created + ' {{créé(s)}}, '
        + result.updated + ' {{mis à jour}}';
      $('#div_alert').showAlert({ message: msg, level: 'success' });
      setTimeout(function () { window.location.reload(); }, 1500);
    }
  });
});

/* Zones enroulables avec mémorisation localStorage */
(function () {
  var STORAGE_KEY = 'myfronius_zone_state';

  function getStates() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); }
    catch (e) { return {}; }
  }

  function saveState(zone, collapsed) {
    var s = getStates();
    s[zone] = collapsed;
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(s)); }
    catch (e) { /* stockage indisponible : on ignore */ }
  }

  function initZones() {
    var states = getStates();
    $('.eqLogicThumbnailDisplay legend.mfr-zone-toggle').each(function () {
      var $legend = $(this);
      var zone = $legend.data('zone');
      var $content = $('.mfr-zone-content[data-zone="' + zone + '"]');
      var $chevron = $legend.find('.mfr-zone-chevron');

      if (states[zone] === true) {
        $content.addClass('mfr-hidden');
        $chevron.addClass('mfr-collapsed');
      }

      $legend.off('click.mfrzone').on('click.mfrzone', function () {
        if ($content.hasClass('mfr-hidden')) {
          $content.removeClass('mfr-hidden');
          $chevron.removeClass('mfr-collapsed');
          saveState(zone, false);
        } else {
          $content.addClass('mfr-hidden');
          $chevron.addClass('mfr-collapsed');
          saveState(zone, true);
        }
      });
    });
  }

  $(document).ready(function () { initZones(); });
})();

/* Affichage conditionnel du bloc batterie (capacity_wh) selon configuration.type.
 * Le type est porté par l'input readonly #mfr_type_hidden alimenté par setValues,
 * qui déclenche 'change'. On ajoute un repli sur le clic des cartes pour couvrir
 * les cas où le change ne serait pas émis. */
function mfrRefreshBatteryBlock() {
  var type = $('#mfr_type_hidden').val() || '';
  $('#mfr_battery_only').toggle(type === 'battery');
  $('#mfr_site_only').toggle(type === 'site');
  $('#mfr_charger_only').toggle(type === 'charger');
  $('#mfr_load_only').toggle(type === 'load');
  // Lignes réservées à certains types (informations matérielles, mode commandes)
  $('[data-mfr-types]').each(function () {
    var allowed = ($(this).attr('data-mfr-types') || '').split(',');
    $(this).toggle(allowed.indexOf(type) !== -1);
  });
}

/* Ajout manuel d'une borne Wattpilot (les bornes ne sont pas découvertes par la Solar API) */
$('#bt_addCharger').off('click').on('click', function () {
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: { action: 'addCharger' },
    dataType: 'json',
    error: function (request, status, error) { handleAjaxError(request, status, error); },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      $('#div_alert').showAlert({ message: '{{Borne créée}} : ' + data.result.name + ' — {{renseignez son adresse et son mot de passe}}', level: 'success' });
      setTimeout(function () { window.location.href = 'index.php?v=d&p=myfronius&m=myfronius&id=' + data.result.id; }, 800);
    }
  });
});

/* Sélection d'une commande Jeedom pour un champ de configuration (charges pilotées).
 * Le champ reçoit la forme lisible, convertie en identifiant côté serveur à chaque lecture. */
$(document).on('click.mfr', '.mfr-pick', function () {
  var $input = $(this).closest('.input-group').find('input.eqLogicAttr');
  var kind = $(this).attr('data-kind') || 'info';
  var filter = (kind === 'action') ? { cmd: { type: 'action' } } : { cmd: { type: 'info' } };
  jeedom.cmd.getSelectModal(filter, function (result) {
    // Forme lisible #[Objet][Équipement][Commande]#, résolue côté serveur par cmd::byString
    // (champ commande) ou jeedom::fromHumanReadable (champ formule)
    var human = result.human || ('#' + result.cmd.id + '#');
    if (kind === 'expr') {
      // Champ formule : insertion à la position du curseur (ou en fin), sans écraser le reste
      var el = $input.get(0), cur = $input.val() || '';
      var start = (el && typeof el.selectionStart === 'number') ? el.selectionStart : cur.length;
      var end = (el && typeof el.selectionEnd === 'number') ? el.selectionEnd : cur.length;
      var before = cur.slice(0, start), after = cur.slice(end);
      if (before.length && !/\s$/.test(before)) { before += ' '; }
      if (after.length && !/^\s/.test(after)) { after = ' ' + after; }
      $input.val(before + human + after);
    } else {
      $input.val(human);
    }
    mfrSetModified();
  });
});
$(document).on('click.mfr', '.mfr-pick-clear', function () {
  var $input = $(this).closest('.input-group').find('input.eqLogicAttr');
  $input.val('');
  mfrSetModified();
});

/* Actions supplémentaires des charges pilotées (allumage / extinction), sur le modèle des listes
 * d'actions du core : une ligne = commande + paramètres affichés selon son sous-type
 * (jeedom.cmd.displayActionOption), enregistrées dans configuration.actions_on / actions_off. */
function mfrAddAction(_action, _type) {
  _action = _action || {};
  var $row = $('<div class="mfr-action form-group" style="margin-bottom:5px;">'
    + '<div class="col-sm-1" style="padding-top:5px;"><input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Activer}}"></div>'
    + '<div class="col-sm-5"><div class="input-group input-group-sm">'
    + '<span class="input-group-btn"><a class="btn btn-default mfr-action-remove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a></span>'
    + '<input class="expressionAttr form-control mfr-action-cmd" data-l1key="cmd" placeholder="{{Commande ou action de scénario}}">'
    + '<span class="input-group-btn">'
    + '<a class="btn btn-default mfr-action-pick" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
    + '<a class="btn btn-default mfr-action-pick-other" title="{{Action de scénario (attente, variable…)}}"><i class="fas fa-tasks"></i></a>'
    + '</span></div></div>'
    + '<div class="col-sm-6 mfr-action-options"></div>'
    + '</div>');
  $('.mfr-actions[data-type="' + _type + '"]').append($row);
  $row.setValues(_action, '.expressionAttr');
  mfrRefreshActionOptions($row, _action.options);
}

function mfrRefreshActionOptions($row, _options) {
  var cmd = $row.find('.mfr-action-cmd').val() || '';
  if (cmd === '') {
    $row.find('.mfr-action-options').empty();
    return;
  }
  jeedom.cmd.displayActionOption(cmd, _options || '', function (html) {
    $row.find('.mfr-action-options').html(html);
    if (typeof taAutosize === 'function') { taAutosize(); }
  });
}

function mfrActionsModified() {
  mfrSetModified();
}

$(document).on('click.mfr', '.mfr-action-add', function () {
  mfrAddAction({}, $(this).attr('data-type'));
  mfrActionsModified();
});
$(document).on('click.mfr', '.mfr-action-remove', function () {
  $(this).closest('.mfr-action').remove();
  mfrActionsModified();
});
$(document).on('click.mfr', '.mfr-action-pick', function () {
  var $row = $(this).closest('.mfr-action');
  jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
    $row.find('.mfr-action-cmd').val(result.human);
    mfrRefreshActionOptions($row, '');
    mfrActionsModified();
  });
});
$(document).on('click.mfr', '.mfr-action-pick-other', function () {
  var $row = $(this).closest('.mfr-action');
  jeedom.getSelectActionModal({}, function (result) {
    $row.find('.mfr-action-cmd').val(result.human);
    mfrRefreshActionOptions($row, '');
    mfrActionsModified();
  });
});
$(document).on('focusout.mfr', '.mfr-action-cmd', function () {
  var $row = $(this).closest('.mfr-action');
  var current = $row.getValues('.expressionAttr')[0] || {};
  mfrRefreshActionOptions($row, current.options);
});
$(document).on('change.mfr', '.mfr-actions .expressionAttr', mfrActionsModified);

/* Hooks du core : chargement et enregistrement de l'équipement */
/* Mode d'une charge : hors formulaire (une sauvegarde ne doit pas écraser un forçage fait entre-temps),
 * appliqué tout de suite par la commande action correspondante (mode_auto, mode_on, mode_off) */
var mfrLoadModeCmds = {};
var mfrLoadModeShown = 'auto';

$(document).on('change.mfr', '#mfr_load_mode', function () {
  var $select = $(this);
  var mode = $select.val();
  if (!mfrLoadModeCmds[mode]) {
    $select.val(mfrLoadModeShown);
    $('#div_alert').showAlert({ message: '{{Commande de mode introuvable : sauvegardez l\'équipement pour la recréer}}', level: 'danger' });
    return;
  }
  jeedom.cmd.execute({
    id: mfrLoadModeCmds[mode],
    error: function (error) {
      $select.val(mfrLoadModeShown);
      $('#div_alert').showAlert({ message: error.message, level: 'danger' });
    },
    success: function () {
      mfrLoadModeShown = mode;
      $('#div_alert').showAlert({ message: '{{Mode appliqué}}', level: 'success' });
    }
  });
});

function printEqLogic(_eqLogic) {
  $('.mfr-actions').empty();
  var conf = (_eqLogic && _eqLogic.configuration) || {};
  mfrLoadedCmdMode = conf.cmd_mode || 'standard';
  $('#cmd_mode_expert_warning, #cmd_mode_standard_warning').hide();
  mfrLoadModeCmds = {};
  ((_eqLogic && _eqLogic.cmd) || []).forEach(function (cmd) {
    var match = /^mode_(auto|on|off)$/.exec(cmd.logicalId || '');
    if (match) { mfrLoadModeCmds[match[1]] = cmd.id; }
  });
  mfrLoadModeShown = ['auto', 'on', 'off'].indexOf(conf.mode) !== -1 ? conf.mode : 'auto';
  $('#mfr_load_mode').val(mfrLoadModeShown);
  ['on', 'off'].forEach(function (type) {
    var list = conf['actions_' + type];
    if (Array.isArray(list)) {
      list.forEach(function (action) { mfrAddAction(action, type); });
    }
  });
}

function saveEqLogic(_eqLogic) {
  if ($('#mfr_type_hidden').val() !== 'load') { return _eqLogic; }
  if (!_eqLogic.configuration) { _eqLogic.configuration = {}; }
  ['on', 'off'].forEach(function (type) {
    var $list = $('.mfr-actions[data-type="' + type + '"] .mfr-action');
    _eqLogic.configuration['actions_' + type] = $list.length ? $list.getValues('.expressionAttr') : [];
  });
  return _eqLogic;
}

/* Ajout manuel d'une charge pilotée */
$('#bt_addLoad').off('click').on('click', function () {
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: { action: 'addLoad' },
    dataType: 'json',
    error: function (request, status, error) { handleAjaxError(request, status, error); },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      $('#div_alert').showAlert({ message: '{{Charge créée}} : ' + data.result.name + ' — {{renseignez ses commandes et sa puissance nominale}}', level: 'success' });
      setTimeout(function () { window.location.href = 'index.php?v=d&p=myfronius&m=myfronius&id=' + data.result.id; }, 800);
    }
  });
});

$(document).on('change.mfr', '#mfr_type_hidden', function () {
  mfrRefreshBatteryBlock();
});

$(document).on('click.mfr', '.eqLogicDisplayCard', function () {
  setTimeout(mfrRefreshBatteryBlock, 300);
});

/* Avertissement quand le mode commandes choisi diffère du mode enregistré (mémorisé par
 * printEqLogic, qui masque aussi les avertissements au chargement d'un équipement : les 'change'
 * programmatiques de setJeeValues ne laissent donc rien d'affiché). La classe PHP resynchronise
 * les commandes à la sauvegarde, et le rechargement de page du core après une sauvegarde
 * réussie affiche le résultat. */
var mfrLoadedCmdMode = null;

$(document).on('change.mfr', '.eqLogicAttr[data-l2key="cmd_mode"]', function () {
  if (mfrLoadedCmdMode === null) return;
  var mode = $(this).val() || 'standard';
  $('#cmd_mode_expert_warning').toggle(mode === 'expert' && mfrLoadedCmdMode !== 'expert');
  $('#cmd_mode_standard_warning').toggle(mode === 'standard' && mfrLoadedCmdMode === 'expert');
});

/* Bouton Régénérer les commandes — confirmation simple */
$('#bt_regenerateCommands').off('click').on('click', function () {
  bootbox.confirm({
    title: '{{Régénérer les commandes}}',
    message: '{{Toutes les commandes de cet équipement seront supprimées et recréées selon le mode sélectionné.<br>' +
             '<strong>Leur historique sera définitivement effacé et elles auront de nouveaux identifiants : les scénarios, virtuels, designs, vues ou charges pilotées qui les utilisent devront être reconfigurés. Les noms, visibilités et historisations personnalisés seront aussi perdus.</strong>}}',
    buttons: {
      cancel: { label: '{{Annuler}}', className: 'btn-default' },
      confirm: { label: '<i class="fas fa-redo"></i> {{Régénérer}}', className: 'btn-warning' }
    },
    callback: function (result) {
      if (result) { doRegenerate(); }
    }
  });
});

function doRegenerate() {
  var eqLogicId = $('.eqLogicAttr[data-l1key=id]').value() || 0;
  // Mode sélectionné dans le formulaire (même non sauvegardé) : il est appliqué avant la régénération
  var cmdMode = $('.eqLogicAttr[data-l2key="cmd_mode"]').val() || 'standard';
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: { action: 'regenerateCommands', eqLogicId: eqLogicId, cmdMode: cmdMode },
    dataType: 'json',
    error: function (request, status, error) { handleAjaxError(request, status, error); },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      $('#div_alert').showAlert({ message: '{{Régénération en cours — mise à jour dans quelques secondes…}}', level: 'info' });
      setTimeout(function () { window.location.reload(); }, 5000);
    }
  });
}

/* Fonction permettant l'affichage des commandes dans l'équipement */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} };
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {};
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  tr += '<td class="hidden-xs">';
  tr += '<span class="cmdAttr" data-l1key="id"></span>';
  tr += '</td>';
  tr += '<td>';
  tr += '<div class="input-group">';
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">';
  tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>';
  tr += '</div>';
  tr += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;" title="{{Commande info liée}}">';
  tr += '<option value="">{{Aucune}}</option>';
  tr += '</select>';
  tr += '</td>';
  tr += '<td>';
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>';
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>';
  tr += '</td>';
  tr += '<td>';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> ';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> ';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> ';
  tr += '<div style="margin-top:7px;">';
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">';
  tr += '</div>';
  tr += '</td>';
  tr += '<td>';
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
  tr += '</td>';
  tr += '<td>';
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i></td>';
  tr += '</tr>';
  $('#table_cmd tbody').append(tr);
  var tr = $('#table_cmd tbody tr').last();
  jeedom.eqLogic.buildSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: { type: 'info' },
    error: function (error) {
      $('#div_alert').showAlert({ message: error.message, level: 'danger' });
    },
    success: function (result) {
      tr.find('.cmdAttr[data-l1key=value]').append(result);
      tr.setValues(_cmd, '.cmdAttr');
      jeedom.cmd.changeType(tr, init(_cmd.subType));
    }
  });
}
