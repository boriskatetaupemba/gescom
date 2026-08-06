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
 * \file    custom/invoiceclosure/admin/about.php
 * \ingroup invoiceclosure
 * \brief   About page of the InvoiceClosure module.
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
dol_include_once('/invoiceclosure/core/modules/modInvoiceClosure.class.php');

$langs->loadLangs(array('admin', 'invoiceclosure@invoiceclosure'));

if (!$user->admin) {
	accessforbidden();
}

/*
 * View
 */

$module = new modInvoiceClosure($db);

$title = $langs->trans('InvoiceClosureAbout');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-invoiceclosure page-admin-about');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = invoiceclosureAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans('InvoiceClosure'), -1, 'bill');

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Name').'</td><td>'.$langs->trans('InvoiceClosure').'</td></tr>';
print '<tr><td>'.$langs->trans('Version').'</td><td>'.dol_escape_htmltag($module->version).'</td></tr>';
print '<tr><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('ModuleInvoiceClosureDesc').'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceClosureApiEndpoint').'</td><td><code>/api/index.php/invoiceclosureapi</code></td></tr>';
print '</table>';

print '<br>';
print '<span class="opacitymedium">'.$langs->trans('InvoiceClosureAboutWorkflow').'</span>';

print dol_get_fiche_end();

llxFooter();
$db->close();
