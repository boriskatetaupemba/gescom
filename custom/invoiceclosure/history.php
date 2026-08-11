<?php
/* Copyright (C) 2026 InvoiceClosure module
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
 * \file    custom/invoiceclosure/history.php
 * \ingroup invoiceclosure
 * \brief   Closure/reopen history of one customer invoice.
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

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
dol_include_once('/invoiceclosure/class/invoiceclosure.class.php');

$langs->loadLangs(array('bills', 'invoiceclosure@invoiceclosure'));

$id = GETPOSTINT('id');

// Security checks
if (!isModEnabled('invoiceclosure')) {
	accessforbidden();
}
if (!$user->hasRight('invoiceclosure', 'readhistory')) {
	accessforbidden();
}
if ($user->socid) {
	$socid = $user->socid;
}
$result = restrictedArea($user, 'facture', $id, '', '', 'fk_soc', 'rowid');

$object = new Facture($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	llxHeader('', $langs->trans('InvoiceClosureHistory'));
	print '<div class="error">'.$langs->trans('InvoiceClosureErrInvoiceNotFound').'</div>';
	llxFooter();
	$db->close();
	exit;
}

$closure = new InvoiceClosure($db);
$closureFound = ($closure->fetchByInvoice($object->id, (int) $object->entity) > 0);
$history = $closure->getHistory($object->id, (int) $object->entity);

/*
 * View
 */

$title = $langs->trans('InvoiceClosureHistory').' - '.$object->ref;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-invoiceclosure page-history');

print load_fiche_titre($title, '', 'bill');

// Summary block
print '<div class="fichecenter"><div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Bill').'</td><td>'.$object->getNomUrl(1).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceClosureBusinessStatus').'</td><td>';
if ($closureFound && (int) $closure->closure_status === InvoiceClosure::STATUS_CLOSED) {
	print '<span class="badge badge-status8 badge-status invoiceclosure-badge">'.$langs->trans('InvoiceClosed').'</span>';
	print ' <span class="opacitymedium">'.dol_print_date($closure->date_closure, 'dayhour', 'tzuser').' - '.dol_escape_htmltag($closure->closure_login).'</span>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('InvoiceNotClosed').'</span>';
}
print '</td></tr>';
print '</table></div>';

print '<br>';

// History table
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('Action').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('InvoiceClosureSource').'</td>';
print '<td>'.$langs->trans('Note').'</td>';
print '<td>'.$langs->trans('InvoiceClosureRequestId').'</td>';
print '</tr>';

if (is_array($history) && count($history) > 0) {
	foreach ($history as $line) {
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($line['action_date'], 'dayhour', 'tzuser').'</td>';
		print '<td>';
		if ($line['action_code'] === 'CLOSE') {
			print '<span class="badge badge-status8 badge-status">'.$langs->trans('InvoiceClosureActionClose').'</span>';
		} else {
			print '<span class="badge badge-status4 badge-status">'.$langs->trans('InvoiceClosureActionReopen').'</span>';
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag($line['user_fullname'] !== '' ? $line['user_fullname'] : $line['user_login']).'</td>';
		print '<td>'.dol_escape_htmltag($line['action_source']).'</td>';
		print '<td class="small">'.dol_nl2br(dol_escape_htmltag($line['action_note'])).'</td>';
		print '<td class="small opacitymedium">'.dol_escape_htmltag($line['request_id']).'</td>';
		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

print '</table>';
print '</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $object->id).'">'.$langs->trans('BackToCard').'</a>';
print '</div>';

llxFooter();
$db->close();
