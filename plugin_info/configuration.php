<?php
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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/myfronius.class.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Adresse de l'onduleur}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse IP ou nom DNS de l&#39;onduleur Fronius (ex : 192.168.1.50). Sur GEN24, la Solar API doit être activée dans Communication > Solar API.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="host" placeholder="192.168.1.50"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Cadence de lecture (s)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Intervalle entre deux lectures de l&#39;onduleur. Minimum 5 s, maximum 60 s, recommandé 15 s.}}"></i></sup>
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="cycle" type="number" min="5" max="60" placeholder="15"/>
        <span class="help-block">{{Minimum : 5 s — Maximum : 60 s — Recommandé : 15 s}}</span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Port socket démon}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port TCP local utilisé par le démon pour recevoir les ordres de Jeedom (défaut : 55010)}}"></i></sup>
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="socketport" type="number" min="1024" max="65535" placeholder="55010"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Mode commandes par défaut}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Mode appliqué aux nouveaux équipements. Standard : commandes utiles au quotidien. Expert : ajoute les mesures techniques (MPPT, réactif, codes d&#39;état…).}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="cmd_mode_default">
          <option value="standard" selected>{{Standard}}</option>
          <option value="expert">{{Expert}}</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Pause des charges au branchement d'une voiture (s)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Quand une voiture est branchée sur une borne Wattpilot, les charges pilotées en automatique sont éteintes pendant cette durée pour laisser la borne monter sur le surplus ; elles ne se rallument ensuite que sur ce que la borne laisse. 0 = désactivé.}}"></i></sup>
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="ev_hold_delay" type="number" min="0" step="10" placeholder="300"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Pilotage de la batterie (Modbus TCP)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Permet de bloquer la charge ou la décharge, forcer la charge depuis le réseau et régler la réserve minimale. Sur l&#39;onduleur : Communication > Modbus, serveur TCP activé, « Autoriser la commande » activé, type de modèle int + SF ou float indifférent.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="modbus_enabled">
          <option value="0" selected>{{Désactivé}}</option>
          <option value="1">{{Activé}}</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Port Modbus / Unit ID}}</label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="modbus_port" type="number" min="1" max="65535" placeholder="502"/>
      </div>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="modbus_unit" type="number" min="1" max="247" placeholder="1"/>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <button type="button" class="btn btn-default" id="bt_testModbus">
          <i class="fas fa-battery-half"></i> {{Tester Modbus}}
        </button>
        <div id="testModbusResult" style="margin-top:8px;"></div>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <button type="button" class="btn btn-default" id="bt_testFronius">
          <i class="fas fa-plug"></i> {{Tester la connexion}}
        </button>
        <div id="testFroniusResult" style="margin-top:8px;"></div>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label">{{Historisation des commandes}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Les réglages d&#39;historique par défaut (historisation, lissage, purge, répétition) sont posés à la création des commandes et complétés automatiquement sans jamais écraser les vôtres. Ce bouton, lui, remet TOUTES les commandes du plugin sur ces défauts, y compris les réglages que vous avez modifiés.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <button type="button" class="btn btn-warning" id="bt_resetHistoryDefaults">
          <i class="fas fa-history"></i> {{Restaurer les défauts d'historisation}}
        </button>
        <div id="resetHistoryResult" style="margin-top:8px;"></div>
      </div>
    </div>

    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6" style="margin-top:16px;">
        <span class="text-muted">
          <i class="fas fa-info-circle"></i>
          {{Version du plugin}} : <strong><?= myfronius::getPluginVersion() ?></strong>
        </span>
      </div>
    </div>

  </fieldset>
</form>

<script>
/* Texte venant de l'appareil ou d'une erreur : échappé avant insertion HTML */
function mfEsc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }

$('#bt_testModbus').off('click').on('click', function () {
  $('#testModbusResult').html('<i class="fas fa-spinner fa-spin"></i> {{Lecture en cours…}}');
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: {
      action: 'testModbus',
      host: $('input.configKey[data-l1key="host"]').val(),
      port: $('input.configKey[data-l1key="modbus_port"]').val(),
      unit: $('input.configKey[data-l1key="modbus_unit"]').val()
    },
    dataType: 'json',
    error: function (request, status, error) {
      $('#testModbusResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + mfEsc(error) + '</span>');
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#testModbusResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + mfEsc(data.result) + '</span>');
        return;
      }
      var r = data.result;
      if (!r.storage) {
        $('#testModbusResult').html('<span class="text-warning"><i class="fas fa-exclamation-triangle"></i> {{Carte SunSpec lue, mais aucun modèle de stockage (124) : pas de batterie pilotable}}</span>');
        return;
      }
      var msg = '{{Modèle stockage trouvé en}} ' + mfEsc(r.base)
        + ' — {{référence}} ' + mfEsc(r.wchamax) + ' W — {{SOC}} ' + mfEsc(r.soc) + ' % — {{réserve}} ' + mfEsc(r.minrsv) + ' %'
        + ' — {{mode}} ' + mfEsc(r.storctl) + ' ({{charge}} ' + mfEsc(r.inwrte) + ' %, {{décharge}} ' + mfEsc(r.outwrte) + ' %)';
      $('#testModbusResult').html('<span class="text-success"><i class="fas fa-check-circle"></i> ' + msg + '</span>'
        + '<br><span class="text-muted">{{L&#39;autorisation d&#39;écriture ne se vérifie qu&#39;à la première consigne : surveillez « Modbus OK » sur l&#39;équipement Batterie.}}</span>');
    }
  });
});

/* Restaurer les défauts d'historisation : aperçu (apply=0), avertissement et confirmation, puis
   application (apply=1). Écrase les réglages de l'utilisateur, d'où la confirmation explicite. */
function mfResetHistory(apply, done) {
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: { action: 'resetHistoryDefaults', apply: apply ? '1' : '0' },
    dataType: 'json',
    error: function (request, status, error) {
      $('#resetHistoryResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + mfEsc(error) + '</span>');
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#resetHistoryResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + mfEsc(data.result) + '</span>');
        return;
      }
      done(data.result);
    }
  });
}
function mfConfirm(message, onYes) {
  var cb = function (result) { if (result) { onYes(); } };
  if (typeof jeeDialog !== 'undefined' && jeeDialog.confirm) {
    jeeDialog.confirm({ title: '{{Restaurer les défauts d&#39;historisation}}', message: message }, cb);
  } else if (typeof bootbox !== 'undefined') {
    bootbox.confirm(message, cb);
  } else {
    cb(window.confirm($('<div>').html(message).text()));
  }
}
$('#bt_resetHistoryDefaults').off('click').on('click', function () {
  $('#resetHistoryResult').html('<i class="fas fa-spinner fa-spin"></i> {{Analyse des commandes…}}');
  mfResetHistory(false, function (preview) {
    if (!preview.changed) {
      $('#resetHistoryResult').html('<span class="text-success"><i class="fas fa-check-circle"></i> {{Toutes les commandes sont déjà sur les réglages par défaut.}}</span>');
      return;
    }
    $('#resetHistoryResult').html('');
    var msg = '<p><strong>' + mfEsc(preview.changed) + ' {{commande(s)}}</strong> {{vont être remises sur les réglages par défaut du plugin (historisation, lissage, purge, répétition).}}</p>'
      + '<p class="text-warning"><i class="fas fa-exclamation-triangle"></i> {{Les réglages que vous avez modifiés sur ces commandes seront écrasés.}}</p>';
    if (preview.disabled.length) {
      msg += '<p class="text-danger"><i class="fas fa-exclamation-triangle"></i> <strong>{{Historisation retirée sur}} ' + mfEsc(preview.disabled.length)
        + ' {{commande(s) : Jeedom supprimera tout leur historique au prochain archivage, sans retour possible.}}</strong></p><ul>';
      $.each(preview.disabled, function (i, name) { msg += '<li>' + mfEsc(name) + '</li>'; });
      msg += '</ul>';
    }
    msg += '<p>{{Continuer ?}}</p>';
    mfConfirm(msg, function () {
      $('#resetHistoryResult').html('<i class="fas fa-spinner fa-spin"></i> {{Application…}}');
      mfResetHistory(true, function (r) {
        $('#resetHistoryResult').html('<span class="text-success"><i class="fas fa-check-circle"></i> {{Défauts restaurés sur}} ' + mfEsc(r.changed) + ' {{commande(s)}}</span>');
      });
    });
  });
});

$('#bt_testFronius').off('click').on('click', function () {
  $('#testFroniusResult').html('<i class="fas fa-spinner fa-spin"></i> {{Connexion en cours…}}');
  $.ajax({
    type: 'POST',
    url: 'plugins/myfronius/core/ajax/myfronius.ajax.php',
    data: {
      action: 'testConnection',
      host:   $('input.configKey[data-l1key="host"]').val()
    },
    dataType: 'json',
    error: function (request, status, error) {
      $('#testFroniusResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + mfEsc(error) + '</span>');
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#testFroniusResult').html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + mfEsc(data.result) + '</span>');
        return;
      }
      var r = data.result;
      var msg = '{{Solar API}} v' + mfEsc(r.apiVersion) + (r.compatibility ? ' (' + mfEsc(r.compatibility) + ')' : '')
        + ' — {{mode}} : ' + mfEsc(r.mode)
        + ' — {{onduleur(s)}} : ' + mfEsc(r.inverters)
        + ' — {{compteur(s)}} : ' + mfEsc(r.meters)
        + ' — {{batterie}} : ' + (r.battery ? ('{{oui}}' + (r.storageOk ? ' ({{détails disponibles}})' : ' ({{détails indisponibles}})')) : '{{non}}');
      if (r.daemonStarted) {
        msg += ' — <strong>{{Démon démarré automatiquement}}</strong>';
      }
      $('#testFroniusResult').html('<span class="text-success"><i class="fas fa-check-circle"></i> ' + msg + '</span>');
    }
  });
});
</script>
