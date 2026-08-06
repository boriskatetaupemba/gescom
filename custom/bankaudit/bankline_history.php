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
 * \file    custom/bankaudit/bankline_history.php
 * \ingroup bankaudit
 * \brief   "Suivi" tab of a bank line: modification history (Spec 3 / req 2).
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';
dol_include_once('/bankaudit/class/bankaudit.class.php');

$langs->loadLangs(array('banks', 'compta', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
if (!$user->hasRight('bankaudit', 'read')) {
	accessforbidden();
}

$rowid = GETPOSTINT('rowid');
if ($rowid <= 0) {
	$rowid = GETPOSTINT('id');
}

$bankline = new AccountLine($db);
if ($rowid > 0) {
	$bankline->fetch($rowid);
}
if (empty($bankline->id)) {
	accessforbidden('ErrorRecordNotFound');
}

// Security: the user must be allowed to read the bank account holding this line
$acct = new Account($db);
$acct->fetch($bankline->fk_account);
restrictedArea($user, 'banque', $acct->id, 'bank_account');


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('LineRecord').' - '.$langs->trans('BankAuditLineHistoryTab');
llxHeader('', $title);

$head = bankline_prepare_head($rowid);

print dol_get_fiche_head($head, 'bankaudit_suivi', $langs->trans('LineRecord'), -1, 'accountline', 0);

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/bank/bankentries_list.php?restore_lastsearch_values=1'.($acct->id > 0 ? '&id='.$acct->id : '').'">'.$langs->trans("BackToList").'</a>';

dol_banner_tab($bankline, 'rowid', $linkback);

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

// Modification history of this bank line
$sql = "SELECT l.rowid, l.tms, l.action, l.field_name, l.old_value, l.new_value, l.context,";
$sql .= " l.fk_user, u.lastname, u.firstname, u.login";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_audit_log as l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
$sql .= " WHERE l.object_type = 'bank_line' AND l.object_id = ".((int) $rowid);
$sql .= " AND l.entity IN (".getEntity('bank_account').")";
$sql .= " ORDER BY l.tms DESC, l.rowid DESC";

$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th>'.$langs->trans('Action').'</th>';
print '<th>'.$langs->trans('Field').'</th>';
print '<th>'.$langs->trans('OldValue').'</th>';
print '<th>'.$langs->trans('NewValue').'</th>';
print '<th>'.$langs->trans('Source').'</th>';
print '</tr>';

$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;

		// Resolve the acting user link
		$userlabel = '';
		if (!empty($obj->fk_user)) {
			$tmpuser = new User($db);
			$tmpuser->id = (int) $obj->fk_user;
			$tmpuser->lastname = $obj->lastname;
			$tmpuser->firstname = $obj->firstname;
			$tmpuser->login = $obj->login;
			$userlabel = $tmpuser->getNomUrl(-1);
		} else {
			$userlabel = '<span class="opacitymedium">'.$langs->trans('System').'</span>';
		}

		// Detect automatic synchronization from the linked transfer line
		$autosync = false;
		if (!empty($obj->context)) {
			$ctx = json_decode($obj->context, true);
			if (is_array($ctx) && isset($ctx['source']) && $ctx['source'] == 'auto_sync') {
				$autosync = true;
			}
		}

		$actionlabel = $langs->trans('BankAuditAction'.ucfirst(strtolower($obj->action)));
		if ($actionlabel == 'BankAuditAction'.ucfirst(strtolower($obj->action))) {
			$actionlabel = $obj->action;
		}

		$fieldlabel = $obj->field_name;
		if (!empty($obj->field_name)) {
			$transfield = $langs->trans('BankAuditField'.ucfirst($obj->field_name));
			if ($transfield != 'BankAuditField'.ucfirst($obj->field_name)) {
				$fieldlabel = $transfield;
			}
		}

		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->tms), 'dayhoursec').'</td>';
		print '<td class="tdoverflowmax150">'.$userlabel.'</td>';
		print '<td>'.dol_escape_htmltag($actionlabel).'</td>';
		print '<td>'.dol_escape_htmltag($fieldlabel).'</td>';
		print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($obj->old_value).'">'.dol_escape_htmltag($obj->old_value).'</td>';
		print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($obj->new_value).'">'.dol_escape_htmltag($obj->new_value).'</td>';
		print '<td class="center">';
		if ($autosync) {
			print '<span class="badge badge-status4" title="'.dol_escape_htmltag($langs->trans('BankAuditAutoSyncHelp')).'">&#10227; '.$langs->trans('BankAuditAutoSync').'</span>';
		} else {
			print '<span class="opacitymedium">'.$langs->trans('BankAuditManual').'</span>';
		}
		print '</td>';
		print '</tr>';
	}
}

if (!$nb) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans('BankAuditNoHistory').'</td></tr>';
}

print '</table>';
print '</div>';

print '</div>'; // fichecenter

print dol_get_fiche_end();

llxFooter();
$db->close();
