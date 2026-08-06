<?php
/* Copyright (C) 2026 PosNova module
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
 * \file    custom/posnova/admin/pos_card.php
 * \ingroup posnova
 * \brief   Create / edit / view a POS terminal.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/posnova/core/lib/posnova.lib.php');
dol_include_once('/posnova/class/posnova.class.php');
dol_include_once('/posnova/class/posconfig.class.php');

$langs->loadLangs(array('admin', 'posnova@posnova', 'stocks', 'banks', 'companies'));

if (!$user->admin && !$user->hasRight('posnova', 'setup')) {
	accessforbidden();
}

$form = new Form($db);

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$selfUrl = dol_buildpath('/custom/posnova/admin/pos_card.php', 1);
$listUrl = dol_buildpath('/custom/posnova/admin/pos_list.php', 1);

$pos = new PosConfig($db);
if ($id > 0) {
	if ($pos->fetch($id) <= 0) {
		accessforbidden('NotFound');
	}
}

/*
 * Actions
 */
if ($action === 'add') {
	$pos->ref = GETPOST('ref', 'alphanohtml');
	$pos->label = GETPOST('label', 'alphanohtml');
	$pos->fk_warehouse = GETPOSTINT('fk_warehouse');
	$pos->default_payment_mode = GETPOST('default_payment_mode', 'aZ09');
	$pos->max_discount_percent = (float) price2num(GETPOST('max_discount_percent', 'alpha'), 'MU');
	$pos->allow_price_edit = GETPOSTINT('allow_price_edit');
	$pos->allow_sale_without_stock = GETPOSTINT('allow_sale_without_stock');
	$pos->transfer_enabled = GETPOSTINT('transfer_enabled');
	$pos->offline_mode = GETPOSTINT('offline_mode');
	$pos->allow_backdating = GETPOSTINT('allow_backdating');
	$pos->autoprint = GETPOSTINT('autoprint');
	$pos->print_format = GETPOST('print_format', 'aZ09');
	$pos->fk_default_customer = GETPOSTINT('fk_default_customer') ? GETPOSTINT('fk_default_customer') : null;
	$pos->low_stock_threshold = GETPOSTINT('low_stock_threshold');
	$pos->transfer_timeout_hours = GETPOSTINT('transfer_timeout_hours');
	$pos->cancel_escalation_min = GETPOSTINT('cancel_escalation_min');
	$pos->active = GETPOSTINT('active');

	$r = $pos->create($user);
	if ($r > 0) {
		setEventMessages($langs->trans('PosNovaTerminalCreated').' — '.$langs->trans('PosNovaConfigureAccountsNow'), null, 'mesgs');
		header('Location: '.$selfUrl.'?id='.((int) $pos->id).'&action=edit');
		exit;
	}
	setEventMessages($langs->trans($pos->error ? $pos->error : 'PosNovaGenericError'), null, 'errors');
	$action = 'create';
}

if ($action === 'update' && $id > 0) {
	$pos->ref = GETPOST('ref', 'alphanohtml');
	$pos->label = GETPOST('label', 'alphanohtml');
	$pos->fk_warehouse = GETPOSTINT('fk_warehouse');
	$pos->default_payment_mode = GETPOST('default_payment_mode', 'aZ09');
	$pos->max_discount_percent = (float) price2num(GETPOST('max_discount_percent', 'alpha'), 'MU');
	$pos->allow_price_edit = GETPOSTINT('allow_price_edit');
	$pos->allow_sale_without_stock = GETPOSTINT('allow_sale_without_stock');
	$pos->transfer_enabled = GETPOSTINT('transfer_enabled');
	$pos->offline_mode = GETPOSTINT('offline_mode');
	$pos->allow_backdating = GETPOSTINT('allow_backdating');
	$pos->autoprint = GETPOSTINT('autoprint');
	$pos->print_format = GETPOST('print_format', 'aZ09');
	$pos->fk_default_customer = GETPOSTINT('fk_default_customer') ? GETPOSTINT('fk_default_customer') : null;
	$pos->low_stock_threshold = GETPOSTINT('low_stock_threshold');
	$pos->transfer_timeout_hours = GETPOSTINT('transfer_timeout_hours');
	$pos->cancel_escalation_min = GETPOSTINT('cancel_escalation_min');
	$pos->active = GETPOSTINT('active');

	// Build the activated-account set from posted arrays.
	$accActive = GETPOST('acc_active', 'array');
	$accMode = GETPOST('acc_mode', 'array');
	$defaultAcc = GETPOSTINT('acc_default');
	$accounts = array();
	foreach ($pos->getWarehouseAccounts() as $wa) {
		$aid = (int) $wa['rowid'];
		$accounts[] = array(
			'fk_account' => $aid,
			'is_active' => !empty($accActive[$aid]) ? 1 : 0,
			'is_default' => ($aid === $defaultAcc) ? 1 : 0,
			'default_payment_mode' => isset($accMode[$aid]) ? preg_replace('/[^A-Z0-9]/', '', (string) $accMode[$aid]) : '',
		);
	}
	$pos->fk_default_account = $defaultAcc > 0 ? $defaultAcc : null;

	$db->begin();
	$r = $pos->update($user);
	if ($r > 0) {
		$r = $pos->setAccounts($accounts);
	}
	if ($r > 0) {
		$db->commit();
		setEventMessages($langs->trans('RecordModifiedSuccessfully'), null, 'mesgs');
		header('Location: '.$selfUrl.'?id='.((int) $pos->id));
		exit;
	}
	$db->rollback();
	setEventMessages($langs->trans($pos->error ? $pos->error : 'PosNovaGenericError'), null, 'errors');
	$action = 'edit';
}

if ($action === 'confirm_delete' && $id > 0 && GETPOST('confirm', 'aZ09') === 'yes') {
	if ($pos->hasSales()) {
		setEventMessages($langs->trans('PosNovaErrCannotDeleteHasSales'), null, 'errors');
	} else {
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."pos_config_account WHERE fk_pos = ".((int) $id));
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."pos_config WHERE rowid = ".((int) $id));
		PosNova::audit($db, $user, 'POS_DELETE', 'pos_config', $id, $pos->ref, null, $id);
		setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		header('Location: '.$listUrl);
		exit;
	}
}

/*
 * View
 */
llxHeader('', $langs->trans('PosNovaTerminal'), '', '', 0, 0, '', '', '', 'mod-posnova page-poscard');

$linkback = '<a href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans("BackToList").'</a>';

/**
 * Render the warehouse <select>.
 *
 * @param int  $selected Selected warehouse id
 * @param bool $disabled Lock the field
 * @return string
 */
function pn_warehouse_select($selected, $disabled = false)
{
	global $db, $langs;
	$out = '<select name="fk_warehouse" class="flat minwidth300"'.($disabled ? ' disabled' : '').'>';
	$out .= '<option value="">'.$langs->trans('SelectAWarehouse').'</option>';
	$sql = "SELECT rowid, ref, lieu FROM ".MAIN_DB_PREFIX."entrepot WHERE entity IN (".getEntity('stock').") AND statut = 1 ORDER BY ref ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$lbl = $o->ref;
			if (!empty($o->lieu)) {
				$lbl .= ' ('.$o->lieu.')';
			}
			$out .= '<option value="'.((int) $o->rowid).'"'.((int) $o->rowid === (int) $selected ? ' selected' : '').'>'.dol_escape_htmltag($lbl).'</option>';
		}
	}
	$out .= '</select>';
	return $out;
}

/**
 * Render a payment-mode <select>.
 *
 * @param string $name     Field name
 * @param string $selected Selected code
 * @return string
 */
function pn_payment_select($name, $selected)
{
	global $db, $langs;
	$out = '<select name="'.$name.'" class="flat">';
	$sql = "SELECT code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE entity IN (".getEntity('c_paiement').") AND active = 1 ORDER BY position";
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out .= '<option value="'.dol_escape_htmltag($o->code).'"'.($o->code === $selected ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans($o->libelle ? $o->libelle : $o->code)).'</option>';
		}
	}
	$out .= '</select>';
	return $out;
}

// Delete confirmation.
if ($action === 'delete' && $id > 0) {
	print $form->formconfirm($selfUrl.'?id='.((int) $id), $langs->trans('PosNovaDeleteTerminal'), $langs->trans('PosNovaConfirmDeleteTerminal', $pos->ref), 'confirm_delete', '', 0, 1);
}

if ($action === 'create') {
	print load_fiche_titre($langs->trans('PosNovaCreateTerminal'), $linkback, 'cash-register');
	print '<form method="POST" action="'.$selfUrl.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td><input type="text" name="ref" class="minwidth200" value="'.dol_escape_htmltag(GETPOST('ref', 'alphanohtml')).'" autofocus></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" name="label" class="minwidth300" value="'.dol_escape_htmltag(GETPOST('label', 'alphanohtml')).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$form->textwithpicto($langs->trans('Warehouse'), $langs->trans('PosNovaWarehouseHelp')).'</td><td>'.pn_warehouse_select(GETPOSTINT('fk_warehouse')).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaDefaultPaymentMode'), $langs->trans('PosNovaDefaultPaymentModeHelp')).'</td><td>'.pn_payment_select('default_payment_mode', 'LIQ').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaMaxDiscount'), $langs->trans('PosNovaMaxDiscountHelp')).'</td><td><input type="text" name="max_discount_percent" size="5" value="0"> %</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAllowPriceEdit'), $langs->trans('PosNovaAllowPriceEditHelp')).'</td><td>'.$form->selectyesno('allow_price_edit', 0, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAllowSaleWithoutStock'), $langs->trans('PosNovaAllowSaleWithoutStockHelp')).'</td><td>'.$form->selectyesno('allow_sale_without_stock', 0, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaTransferEnabled'), $langs->trans('PosNovaTransferEnabledHelp')).'</td><td>'.$form->selectyesno('transfer_enabled', 0, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaOfflineMode'), $langs->trans('PosNovaOfflineModeHelp')).'</td><td>'.$form->selectyesno('offline_mode', 1, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAllowBackdating'), $langs->trans('PosNovaAllowBackdatingHelp')).'</td><td>'.$form->selectyesno('allow_backdating', 0, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAutoprint'), $langs->trans('PosNovaAutoprintHelp')).'</td><td>'.$form->selectyesno('autoprint', 1, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaPrintFormat'), $langs->trans('PosNovaPrintFormatHelp')).'</td><td><select name="print_format" class="flat"><option value="80mm">80mm</option><option value="58mm">58mm</option><option value="A4">A4</option></select></td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaDefaultCustomer'), $langs->trans('PosNovaDefaultCustomerHelp')).'</td><td>'.$form->select_company(GETPOSTINT('fk_default_customer'), 'fk_default_customer', '', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaLowStockThreshold'), $langs->trans('PosNovaLowStockThresholdHelp')).'</td><td><input type="number" name="low_stock_threshold" size="5" value="5"></td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaTransferTimeout'), $langs->trans('PosNovaTransferTimeoutHelp')).'</td><td><input type="number" name="transfer_timeout_hours" size="5" value="24"> '.$langs->trans('Hours').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaCancelEscalation'), $langs->trans('PosNovaCancelEscalationHelp')).'</td><td><input type="number" name="cancel_escalation_min" size="5" value="30"> '.$langs->trans('Minutes').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('Status'), $langs->trans('PosNovaStatusHelp')).'</td><td>'.$form->selectyesno('active', 1, 1).'</td></tr>';
	print '</table>';

	print '<div class="center" style="margin-top:14px">';
	print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Create')).'">';
	print ' <a class="button button-cancel bordertransp" style="display:inline-block;padding:0.6em 0.9em;border-radius:3px;vertical-align:middle;" href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';
} elseif ($action === 'edit' && $id > 0) {
	print load_fiche_titre($langs->trans('PosNovaEditTerminal').' — '.dol_escape_htmltag($pos->ref), $linkback, 'cash-register');
	$locked = $pos->hasSales();
	print '<form method="POST" action="'.$selfUrl.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.((int) $pos->id).'">';
	if ($locked) {
		print '<input type="hidden" name="fk_warehouse" value="'.((int) $pos->fk_warehouse).'">';
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td><input type="text" name="ref" class="minwidth200" value="'.dol_escape_htmltag($pos->ref).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input type="text" name="label" class="minwidth300" value="'.dol_escape_htmltag($pos->label).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$form->textwithpicto($langs->trans('Warehouse'), $langs->trans('PosNovaWarehouseHelp')).'</td><td>'.pn_warehouse_select($pos->fk_warehouse, $locked).($locked ? ' <span class="opacitymedium small">'.$langs->trans('PosNovaWarehouseLockedHasSales').'</span>' : '').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaDefaultPaymentMode'), $langs->trans('PosNovaDefaultPaymentModeHelp')).'</td><td>'.pn_payment_select('default_payment_mode', $pos->default_payment_mode).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaMaxDiscount'), $langs->trans('PosNovaMaxDiscountHelp')).'</td><td><input type="text" name="max_discount_percent" size="5" value="'.price($pos->max_discount_percent, 0, $langs, 0, -1, 2).'"> %</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAllowPriceEdit'), $langs->trans('PosNovaAllowPriceEditHelp')).'</td><td>'.$form->selectyesno('allow_price_edit', $pos->allow_price_edit, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAllowSaleWithoutStock'), $langs->trans('PosNovaAllowSaleWithoutStockHelp')).'</td><td>'.$form->selectyesno('allow_sale_without_stock', $pos->allow_sale_without_stock, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaTransferEnabled'), $langs->trans('PosNovaTransferEnabledHelp')).'</td><td>'.$form->selectyesno('transfer_enabled', $pos->transfer_enabled, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaOfflineMode'), $langs->trans('PosNovaOfflineModeHelp')).'</td><td>'.$form->selectyesno('offline_mode', $pos->offline_mode, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAllowBackdating'), $langs->trans('PosNovaAllowBackdatingHelp')).'</td><td>'.$form->selectyesno('allow_backdating', $pos->allow_backdating, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaAutoprint'), $langs->trans('PosNovaAutoprintHelp')).'</td><td>'.$form->selectyesno('autoprint', $pos->autoprint, 1).'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaPrintFormat'), $langs->trans('PosNovaPrintFormatHelp')).'</td><td><select name="print_format" class="flat">';
	foreach (array('80mm', '58mm', 'A4') as $opt) {
		print '<option value="'.$opt.'"'.($pos->print_format === $opt ? ' selected' : '').'>'.$opt.'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaDefaultCustomer'), $langs->trans('PosNovaDefaultCustomerHelp')).'</td><td>'.$form->select_company($pos->fk_default_customer, 'fk_default_customer', '', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaLowStockThreshold'), $langs->trans('PosNovaLowStockThresholdHelp')).'</td><td><input type="number" name="low_stock_threshold" size="5" value="'.((int) $pos->low_stock_threshold).'"></td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaTransferTimeout'), $langs->trans('PosNovaTransferTimeoutHelp')).'</td><td><input type="number" name="transfer_timeout_hours" size="5" value="'.((int) $pos->transfer_timeout_hours).'"> '.$langs->trans('Hours').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('PosNovaCancelEscalation'), $langs->trans('PosNovaCancelEscalationHelp')).'</td><td><input type="number" name="cancel_escalation_min" size="5" value="'.((int) $pos->cancel_escalation_min).'"> '.$langs->trans('Minutes').'</td></tr>';
	print '<tr><td>'.$form->textwithpicto($langs->trans('Status'), $langs->trans('PosNovaStatusHelp')).'</td><td>'.$form->selectyesno('active', $pos->active, 1).'</td></tr>';
	print '</table>';

	// Accounts section.
	print '<br>';
	print load_fiche_titre($langs->trans('PosNovaCashAccounts'), '', '');
	$whAccounts = $pos->getWarehouseAccounts();
	$activeMap = array();
	foreach ($pos->getAccounts(false) as $a) {
		$activeMap[$a['fk_account']] = $a;
	}
	if (empty($whAccounts)) {
		print '<div class="warning">'.$langs->trans('PosNovaNoWarehouseAccount').'</div>';
	} else {
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th class="center">'.$langs->trans('Currency').'</th><th class="center">'.$langs->trans('PosNovaActive').'</th><th class="center">'.$langs->trans('PosNovaDefaultAccount').'</th><th>'.$langs->trans('PosNovaDefaultPaymentMode').'</th></tr>';
		foreach ($whAccounts as $wa) {
			$aid = (int) $wa['rowid'];
			$cur = $activeMap[$aid] ?? null;
			$isActive = $cur ? (int) $cur['is_active'] : 1;
			$mode = $cur && $cur['default_payment_mode'] ? $cur['default_payment_mode'] : 'LIQ';
			$isDefault = ((int) $pos->fk_default_account === $aid);
			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag($wa['label'] ? $wa['label'] : $wa['ref']).'</td>';
			print '<td class="center"><span class="badge badge-status">'.dol_escape_htmltag($wa['currency_code']).'</span></td>';
			print '<td class="center"><input type="checkbox" name="acc_active['.$aid.']" value="1"'.($isActive ? ' checked' : '').'></td>';
			print '<td class="center"><input type="radio" name="acc_default" value="'.$aid.'"'.($isDefault ? ' checked' : '').'></td>';
			print '<td>'.pn_payment_select('acc_mode['.$aid.']', $mode).'</td>';
			print '</tr>';
		}
		print '</table>';
		print '<span class="opacitymedium small">'.$langs->trans('PosNovaAccountsHelp').'</span>';
	}

	print '<div class="center" style="margin-top:14px">';
	print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
	print ' <a class="button button-cancel bordertransp" style="display:inline-block;padding:0.6em 0.9em;border-radius:3px;vertical-align:middle;" href="'.dol_escape_htmltag($selfUrl.'?id='.((int) $pos->id)).'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';
} elseif ($id > 0) {
	// View.
	print load_fiche_titre($langs->trans('PosNovaTerminal').' — '.dol_escape_htmltag($pos->ref), $linkback, 'cash-register');

	$check = $pos->validateForLaunch();
	if (!$check['ok']) {
		print '<div class="warning">'.$langs->trans('PosNovaErrCannotLaunch').'<ul>';
		foreach ($check['errors'] as $e) {
			print '<li>'.$langs->trans($e).'</li>';
		}
		print '</ul></div>';
	}

	$whlabel = '';
	$resw = $db->query("SELECT label, ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $pos->fk_warehouse));
	if ($resw && $db->num_rows($resw)) {
		$ow = $db->fetch_object($resw);
		$whlabel = $ow->label ? $ow->label : $ow->ref;
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('Label').'</td><td>'.dol_escape_htmltag($pos->label).'</td></tr>';
	print '<tr><td>'.$langs->trans('Warehouse').'</td><td>'.dol_escape_htmltag($whlabel).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaWorkingCurrency').'</td><td><span class="badge badge-status">'.dol_escape_htmltag($pos->getWorkingCurrency()).'</span></td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaMaxDiscount').'</td><td>'.price($pos->max_discount_percent, 0, $langs, 0, -1, 2).' %</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaAllowPriceEdit').'</td><td>'.yn($pos->allow_price_edit).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaAllowSaleWithoutStock').'</td><td>'.yn($pos->allow_sale_without_stock).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaTransferEnabled').'</td><td>'.yn($pos->transfer_enabled).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaAllowBackdating').'</td><td>'.yn($pos->allow_backdating).'</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.($pos->active ? '<span class="badge badge-status4 badge-status">'.$langs->trans('Enabled').'</span>' : '<span class="badge badge-status8 badge-status">'.$langs->trans('Disabled').'</span>').'</td></tr>';
	print '</table>';

	// Activated accounts.
	print '<br>'.load_fiche_titre($langs->trans('PosNovaCashAccounts'), '', '');
	$accs = $pos->getAccounts(false);
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Account').'</th><th class="center">'.$langs->trans('Currency').'</th><th class="center">'.$langs->trans('PosNovaActive').'</th><th class="center">'.$langs->trans('PosNovaDefaultAccount').'</th><th>'.$langs->trans('PosNovaDefaultPaymentMode').'</th></tr>';
	if (empty($accs)) {
		print '<tr class="oddeven"><td colspan="5" class="opacitymedium center">'.$langs->trans('PosNovaNoAccountConfigured').'</td></tr>';
	} else {
		foreach ($accs as $a) {
			print '<tr class="oddeven">';
			print '<td>'.dol_escape_htmltag($a['label'] ? $a['label'] : $a['ref']).'</td>';
			print '<td class="center"><span class="badge badge-status">'.dol_escape_htmltag($a['currency_code']).'</span></td>';
			print '<td class="center">'.yn($a['is_active']).'</td>';
			print '<td class="center">'.(((int) $pos->fk_default_account === (int) $a['fk_account']) ? img_picto('', 'check') : '').'</td>';
			print '<td>'.dol_escape_htmltag($a['default_payment_mode']).'</td>';
			print '</tr>';
		}
	}
	print '</table>';

	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.dol_escape_htmltag($selfUrl.'?id='.((int) $pos->id).'&action=edit').'">'.$langs->trans('Modify').'</a>';
	if (!$pos->hasSales()) {
		print '<a class="butActionDelete" href="'.dol_escape_htmltag($selfUrl.'?id='.((int) $pos->id).'&action=delete').'">'.$langs->trans('Delete').'</a>';
	}
	print '</div>';
}

llxFooter();
$db->close();
