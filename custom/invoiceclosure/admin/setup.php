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
 * \file    custom/invoiceclosure/admin/setup.php
 * \ingroup invoiceclosure
 * \brief   Setup page for the InvoiceClosure module.
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
dol_include_once('/invoiceclosure/lib/invoiceclosure.lib.php');

$langs->loadLangs(array('admin', 'bills', 'invoiceclosure@invoiceclosure'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// List of the yes/no constants managed by this page (key => default value)
$booleanConstants = array(
	'INVOICECLOSURE_REQUIRE_ZERO_REMAIN' => '1',
	'INVOICECLOSURE_LOCK_CLOSED_INVOICES' => '1',
	'INVOICECLOSURE_ALLOW_REOPEN' => '1',
	'INVOICECLOSURE_REQUIRE_CLOSE_NOTE' => '0',
	'INVOICECLOSURE_REQUIRE_REOPEN_NOTE' => '1',
	'INVOICECLOSURE_CREATE_AGENDA_EVENT' => '1',
	'INVOICECLOSURE_SHOW_IN_INVOICE_LIST' => '1',
	'INVOICECLOSURE_SHOW_BADGE' => '1',
);

/*
 * Actions
 */

// The CSRF token of the POST is validated automatically by main.inc.php
if ($action == 'save') {
	$error = 0;
	foreach ($booleanConstants as $key => $default) {
		$value = (GETPOSTINT($key) ? '1' : '0');
		$res = dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity);
		if ($res < 0) {
			$error++;
		}
	}
	if (!$error) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('InvoiceClosureSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-invoiceclosure page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = invoiceclosureAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('InvoiceClosure'), -1, 'bill');

print '<span class="opacitymedium">'.$langs->trans('InvoiceClosureSetupDesc').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Parameter').'</td>';
print '<td class="right">'.$langs->trans('Value').'</td>';
print '</tr>';

foreach ($booleanConstants as $key => $default) {
	$current = getDolGlobalString($key, $default);
	print '<tr class="oddeven">';
	print '<td>'.$form->textwithpicto($langs->trans('Setup'.$key), $langs->trans('Setup'.$key.'Help')).'<br>';
	print '<span class="opacitymedium small">'.$key.'</span></td>';
	print '<td class="right">';
	print $form->selectyesno($key, ((int) $current ? 1 : 0), 1);
	print '</td>';
	print '</tr>';
}

print '</table>';

print '<div class="center margintoponly">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
