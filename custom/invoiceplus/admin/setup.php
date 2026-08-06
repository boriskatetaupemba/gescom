<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/admin/setup.php
 * \ingroup invoiceplus
 * \brief   InvoicePlus configuration page.
 */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) {
	$res = include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
	$res = include '../../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/invoiceplus/lib/invoiceplus.lib.php');

$langs->loadLangs(array('admin', 'invoiceplus@invoiceplus'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$booleanConstants = array(
	'INVOICEPLUS_API_ENABLED' => 1,
	'INVOICEPLUS_ADD_WAREHOUSE_METADATA' => 1,
	'INVOICEPLUS_LOAD_CLOSURE_DATA' => 1,
);

if ($action === 'save') {
	$error = 0;
	$maxLimit = GETPOSTINT('INVOICEPLUS_MAX_API_LIMIT');
	if ($maxLimit < 1 || $maxLimit > 10000) {
		$error++;
		setEventMessages($langs->trans('InvoicePlusInvalidMaxLimit'), null, 'errors');
	}

	if (!$error) {
		foreach ($booleanConstants as $key => $default) {
			$value = GETPOSTINT($key) ? '1' : '0';
			if (dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity) < 0) {
				$error++;
			}
		}
		if (dolibarr_set_const($db, 'INVOICEPLUS_MAX_API_LIMIT', (string) $maxLimit, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

$form = new Form($db);
$title = $langs->trans('InvoicePlusSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-invoiceplus page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = invoiceplusAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('InvoicePlus'), -1, 'bill');
print '<span class="opacitymedium">'.$langs->trans('InvoicePlusSetupDesc').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="right">'.$langs->trans('Value').'</td></tr>';

foreach ($booleanConstants as $key => $default) {
	$current = getDolGlobalInt($key, $default);
	print '<tr class="oddeven">';
	print '<td>'.$form->textwithpicto($langs->trans('Setup'.$key), $langs->trans('Setup'.$key.'Help')).'<br>';
	print '<span class="opacitymedium small">'.$key.'</span></td>';
	print '<td class="right">'.$form->selectyesno($key, $current ? 1 : 0, 1).'</td>';
	print '</tr>';
}

print '<tr class="oddeven">';
print '<td>'.$form->textwithpicto($langs->trans('SetupINVOICEPLUS_MAX_API_LIMIT'), $langs->trans('SetupINVOICEPLUS_MAX_API_LIMITHelp')).'<br>';
print '<span class="opacitymedium small">INVOICEPLUS_MAX_API_LIMIT</span></td>';
print '<td class="right"><input type="number" min="1" max="10000" name="INVOICEPLUS_MAX_API_LIMIT" value="'.getDolGlobalInt('INVOICEPLUS_MAX_API_LIMIT', 1000).'" class="width100"></td>';
print '</tr>';
print '</table>';
print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';
print dol_get_fiche_end();

llxFooter();
$db->close();
