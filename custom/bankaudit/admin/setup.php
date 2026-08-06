<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    custom/bankaudit/admin/setup.php
 * \ingroup bankaudit
 * \brief   Setup page for the BankAudit module.
 */

$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/bankaudit/class/bankaudit.class.php');

$langs->loadLangs(array('admin', 'banks', 'bankaudit@bankaudit'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if ($action == 'savegeneral') {
	$keys = array(
		'BANKAUDIT_RATE_ALERT_THRESHOLD',
		'BANKAUDIT_ANOMALY_AVG_FACTOR',
		'BANKAUDIT_OLD_DATE_DAYS',
		'BANKAUDIT_INACTIVE_DAYS',
		'BANKAUDIT_DUPLICATE_DAYS',
	);
	foreach ($keys as $key) {
		$val = price2num(GETPOST($key, 'alpha'));
		dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity);
	}
	$guard = GETPOST('BANKAUDIT_ENABLE_BALANCE_GUARD', 'alpha') ? '1' : '0';
	dolibarr_set_const($db, 'BANKAUDIT_ENABLE_BALANCE_GUARD', $guard, 'chaine', 0, '', $conf->entity);
	$copies = (int) GETPOST('BANKAUDIT_TICKET_COPIES', 'int');
	if ($copies < 1) {
		$copies = 1;
	}
	dolibarr_set_const($db, 'BANKAUDIT_TICKET_COPIES', (string) $copies, 'chaine', 0, '', $conf->entity);
	$paymode = GETPOST('BANKAUDIT_DEFAULT_PAYMENT_MODE', 'aZ09');
	if ($paymode === '') {
		$paymode = 'LIQ';
	}
	dolibarr_set_const($db, 'BANKAUDIT_DEFAULT_PAYMENT_MODE', $paymode, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'saveaccounts') {
	$seuils = GETPOST('seuil_min', 'array');
	$devises = GETPOST('seuil_devise', 'array');

	if (is_array($seuils)) {
		foreach ($seuils as $accId => $rawval) {
			$accId = (int) $accId;
			if ($accId <= 0) {
				continue;
			}
			$rawval = trim($rawval);
			$dev = (isset($devises[$accId]) && $devises[$accId] !== '') ? $devises[$accId] : 'USD';

			$existsid = 0;
			$sqlc = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_dashboard_config";
			$sqlc .= " WHERE fk_account = ".$accId." AND entity = ".((int) $conf->entity);
			$resc = $db->query($sqlc);
			if ($resc && ($oc = $db->fetch_object($resc))) {
				$existsid = (int) $oc->rowid;
			}

			$seuilSql = ($rawval === '') ? "NULL" : ((float) price2num($rawval));

			if ($existsid > 0) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."bank_dashboard_config SET";
				$sql .= " seuil_min = ".$seuilSql.",";
				$sql .= " seuil_devise = '".$db->escape($dev)."'";
				$sql .= " WHERE rowid = ".$existsid;
			} else {
				// Only create a row when a threshold value is actually provided
				if ($rawval === '') {
					continue;
				}
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_dashboard_config";
				$sql .= " (fk_account, seuil_min, seuil_devise, entity)";
				$sql .= " VALUES (".$accId.", ".$seuilSql.", '".$db->escape($dev)."', ".((int) $conf->entity).")";
			}
			$db->query($sql);
		}
	}
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'saverules') {
	$active = GETPOST('rule_active', 'array');
	$seuils = GETPOST('rule_seuil', 'array');

	$sqlr = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_anomaly_rule WHERE entity = ".((int) $conf->entity);
	$resr = $db->query($sqlr);
	if ($resr) {
		while ($or = $db->fetch_object($resr)) {
			$rid = (int) $or->rowid;
			$isactive = (!empty($active[$rid])) ? 1 : 0;
			$rawseuil = isset($seuils[$rid]) ? trim($seuils[$rid]) : '';
			$seuilSql = ($rawseuil === '') ? "NULL" : ((float) price2num($rawseuil));

			$sql = "UPDATE ".MAIN_DB_PREFIX."bank_anomaly_rule SET";
			$sql .= " active = ".$isactive.",";
			$sql .= " seuil = ".$seuilSql;
			$sql .= " WHERE rowid = ".$rid;
			$db->query($sql);
		}
	}
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('BankAuditSetupTitle'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('BankAuditSetupTitle'), $linkback, 'title_setup');

print '<br>';

// --- General settings ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savegeneral">';

print load_fiche_titre($langs->trans('SettingsGeneral'), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="right">'.$langs->trans('Value').'</td><td>'.$langs->trans('Description').'</td></tr>';

$generalParams = array(
	'BANKAUDIT_RATE_ALERT_THRESHOLD' => 'RateAlertThresholdDesc',
	'BANKAUDIT_ANOMALY_AVG_FACTOR' => 'AnomalyAvgFactorDesc',
	'BANKAUDIT_OLD_DATE_DAYS' => 'OldDateDaysDesc',
	'BANKAUDIT_INACTIVE_DAYS' => 'InactiveDaysDesc',
	'BANKAUDIT_DUPLICATE_DAYS' => 'RuleR01',
);
foreach ($generalParams as $key => $descKey) {
	print '<tr class="oddeven">';
	print '<td>'.$key.'</td>';
	print '<td class="right"><input type="text" class="right width75" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'"></td>';
	print '<td class="opacitymedium">'.$langs->trans($descKey).'</td>';
	print '</tr>';
}
// Balance guard before internal transfer (on/off)
print '<tr class="oddeven">';
print '<td>BANKAUDIT_ENABLE_BALANCE_GUARD</td>';
print '<td class="right">'.$form->selectyesno('BANKAUDIT_ENABLE_BALANCE_GUARD', BankAudit::isBalanceGuardEnabled() ? 1 : 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('BalanceGuardDesc').'</td>';
print '</tr>';
// Number of 80mm cash ticket copies printed on "Save and print"
print '<tr class="oddeven">';
print '<td>BANKAUDIT_TICKET_COPIES</td>';
print '<td class="right"><input type="number" min="1" max="5" name="BANKAUDIT_TICKET_COPIES" value="'.((int) getDolGlobalString('BANKAUDIT_TICKET_COPIES', '1')).'" class="width50"></td>';
print '<td class="opacitymedium">'.$langs->trans('BankAuditTicketCopiesDesc').'</td>';
print '</tr>';
// Default payment mode for the new entry form
print '<tr class="oddeven">';
print '<td>BANKAUDIT_DEFAULT_PAYMENT_MODE</td>';
print '<td class="right">'.$form->select_types_paiements(getDolGlobalString('BANKAUDIT_DEFAULT_PAYMENT_MODE', 'LIQ'), 'BANKAUDIT_DEFAULT_PAYMENT_MODE', '', 2, 0, 1, 0, 1, 'minwidth150', 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('BankAuditDefaultPaymentModeDesc').'</td>';
print '</tr>';
print '</table></div>';
print '<div class="center" style="margin-top:8px"><input type="submit" class="button" value="'.$langs->trans('SaveSetup').'"></div>';
print '</form>';

print '<br>';

// --- Per-account minimum balance thresholds ---
$existingConfig = array();
$sql = "SELECT fk_account, seuil_min, seuil_devise FROM ".MAIN_DB_PREFIX."bank_dashboard_config";
$sql .= " WHERE entity = ".((int) $conf->entity);
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$existingConfig[(int) $obj->fk_account] = $obj;
	}
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saveaccounts">';

print load_fiche_titre($langs->trans('AccountThresholds'), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('BankAccount').'</td>';
print '<td>'.$langs->trans('AccountCurrency').'</td>';
print '<td class="right">'.$langs->trans('MinBalance').'</td>';
print '<td>'.$langs->trans('ThresholdCurrency').'</td>';
print '<td class="opacitymedium">'.$langs->trans('EmptyForNoBlocking').'</td>';
print '</tr>';

$sql = "SELECT rowid, ref, label, currency_code FROM ".MAIN_DB_PREFIX."bank_account";
$sql .= " WHERE entity IN (".getEntity('bank_account').") AND clos = 0";
$sql .= " ORDER BY ref";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$accId = (int) $obj->rowid;
		$cfg = isset($existingConfig[$accId]) ? $existingConfig[$accId] : null;
		$seuilVal = ($cfg && $cfg->seuil_min !== null) ? price2num($cfg->seuil_min) : '';
		$devVal = ($cfg && $cfg->seuil_devise) ? $cfg->seuil_devise : $obj->currency_code;

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($obj->ref).' - '.dol_escape_htmltag($obj->label).'</td>';
		print '<td>'.dol_escape_htmltag($obj->currency_code).'</td>';
		print '<td class="right"><input type="text" class="right width75" name="seuil_min['.$accId.']" value="'.dol_escape_htmltag($seuilVal).'"></td>';
		print '<td><input type="text" class="width50" name="seuil_devise['.$accId.']" value="'.dol_escape_htmltag($devVal).'" maxlength="3"></td>';
		print '<td></td>';
		print '</tr>';
	}
}
print '</table></div>';
print '<div class="center" style="margin-top:8px"><input type="submit" class="button" value="'.$langs->trans('SaveSetup').'"></div>';
print '</form>';

print '<br>';

// --- Anomaly rules ---
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="saverules">';

print load_fiche_titre($langs->trans('AnomalyRulesConfig'), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('AnomalyRule').'</td>';
print '<td class="center">'.$langs->trans('RuleActive').'</td>';
print '<td class="right">'.$langs->trans('RuleThreshold').'</td>';
print '<td>'.$langs->trans('RuleUnit').'</td>';
print '</tr>';

$sql = "SELECT rowid, code, label, active, seuil, seuil_unite FROM ".MAIN_DB_PREFIX."bank_anomaly_rule";
$sql .= " WHERE entity = ".((int) $conf->entity);
$sql .= " ORDER BY code";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rid = (int) $obj->rowid;
		$rulelabel = $langs->trans('Rule'.$obj->code);
		if ($rulelabel === 'Rule'.$obj->code) {
			$rulelabel = $obj->label;
		}
		print '<tr class="oddeven">';
		print '<td><b>'.dol_escape_htmltag($obj->code).'</b> - '.dol_escape_htmltag($rulelabel).'</td>';
		print '<td class="center"><input type="checkbox" name="rule_active['.$rid.']" value="1"'.(!empty($obj->active) ? ' checked' : '').'></td>';
		print '<td class="right"><input type="text" class="right width75" name="rule_seuil['.$rid.']" value="'.dol_escape_htmltag($obj->seuil !== null ? price2num($obj->seuil) : '').'"></td>';
		print '<td>'.dol_escape_htmltag($obj->seuil_unite).'</td>';
		print '</tr>';
	}
}
print '</table></div>';
print '<div class="center" style="margin-top:8px"><input type="submit" class="button" value="'.$langs->trans('SaveSetup').'"></div>';
print '</form>';

// End of page
llxFooter();
$db->close();
