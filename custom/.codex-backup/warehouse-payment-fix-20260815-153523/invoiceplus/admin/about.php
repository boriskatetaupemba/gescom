<?php
/* Copyright (C) 2026 InvoicePlus module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/invoiceplus/admin/about.php
 * \ingroup invoiceplus
 * \brief   About page.
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
dol_include_once('/invoiceplus/core/modules/modInvoicePlus.class.php');

$langs->loadLangs(array('admin', 'invoiceplus@invoiceplus'));

if (!$user->admin) {
	accessforbidden();
}

$module = new modInvoicePlus($db);
$title = $langs->trans('InvoicePlusAbout');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-invoiceplus page-admin-about');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');
$head = invoiceplusAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans('InvoicePlus'), -1, 'bill');
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Name').'</td><td>'.$langs->trans('InvoicePlus').'</td></tr>';
print '<tr><td>'.$langs->trans('Version').'</td><td>'.dol_escape_htmltag($module->version).'</td></tr>';
print '<tr><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('ModuleInvoicePlusDescLong').'</td></tr>';
print '<tr><td>'.$langs->trans('InvoicePlusApiEndpoint').'</td><td>';
print '<code>/api/index.php/invoiceplus</code><br>';
print '<code>/api/index.php/invoiceplus/byaccounts</code><br>';
print '<code>/api/index.php/invoiceplus/warehouse/{warehouse_id}</code><br>';
print '<code>/api/index.php/invoiceplus/thirdparties</code>';
print '</td></tr>';
print '</table>';
print '<br><span class="opacitymedium">'.$langs->trans('InvoicePlusAboutArchitecture').'</span>';
print dol_get_fiche_end();

llxFooter();
$db->close();
