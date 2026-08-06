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
 * \file    custom/posnova/admin/setup.php
 * \ingroup posnova
 * \brief   PosNova module configuration.
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

global $conf, $db, $langs, $user;

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/posnova/core/lib/posnova.lib.php');
dol_include_once('/posnova/class/posnova.class.php');

$langs->loadLangs(array('admin', 'posnova@posnova'));

if (!$user->admin && !$user->hasRight('posnova', 'setup')) {
	accessforbidden();
}

$form = new Form($db);

$action = GETPOST('action', 'aZ09');

$constList = array(
	'POSNOVA_ROUNDING_CDF' => 'alpha',
	'POSNOVA_CDF_CURRENCY_CODE' => 'alpha',
	'POSNOVA_SESSION_LIFETIME_HOURS' => 'int',
	'POSNOVA_CATALOG_PAGE_SIZE' => 'int',
	'POSNOVA_POLL_INTERVAL_SEC' => 'int',
	'POSNOVA_DEFAULT_PRINT_FORMAT' => 'alpha',
	'POSNOVA_TRANSFER_TIMEOUT_HOURS' => 'int',
	'POSNOVA_CANCEL_ESCALATION_MIN' => 'int',
	'POSNOVA_FALLBACK_TO_MULTICURRENCY' => 'int',
);

/*
 * Actions
 */
if ($action === 'update') {
	$error = 0;
	foreach ($constList as $name => $type) {
		$value = GETPOST(strtolower($name), $type === 'int' ? 'int' : 'alphanohtml');
		if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) <= 0) {
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
$help_url = '';
llxHeader('', $langs->trans('PosNovaSetup'), $help_url, '', 0, 0, '', '', '', 'mod-posnova page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('PosNovaSetup'), $linkback, 'title_setup');

$head = posnovaAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('PosNovaSetup'), -1, 'cash-register');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

// CDF rounding rule.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaRoundingCdf').'<br><span class="opacitymedium small">'.$langs->trans('PosNovaRoundingCdfHelp').'</span></td><td>';
$rounding = getDolGlobalString('POSNOVA_ROUNDING_CDF', 'DOWN_UNIT');
print '<select name="posnova_rounding_cdf" class="flat">';
foreach (array('DOWN_UNIT', 'NEAREST_UNIT', 'NEAREST_5') as $opt) {
	print '<option value="'.$opt.'"'.($rounding === $opt ? ' selected' : '').'>'.$langs->trans('PosNovaRounding_'.$opt).'</option>';
}
print '</select></td></tr>';

// CDF currency code.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaCdfCurrencyCode').'</td><td><input type="text" name="posnova_cdf_currency_code" size="6" value="'.dol_escape_htmltag(getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', 'CDF')).'"></td></tr>';

// Session lifetime.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaSessionLifetime').'</td><td><input type="number" name="posnova_session_lifetime_hours" min="1" value="'.getDolGlobalInt('POSNOVA_SESSION_LIFETIME_HOURS', 12).'"> '.$langs->trans('Hours').'</td></tr>';

// Catalog page size.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaCatalogPageSize').'</td><td><input type="number" name="posnova_catalog_page_size" min="10" value="'.getDolGlobalInt('POSNOVA_CATALOG_PAGE_SIZE', 50).'"></td></tr>';

// Poll interval.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaPollInterval').'</td><td><input type="number" name="posnova_poll_interval_sec" min="5" value="'.getDolGlobalInt('POSNOVA_POLL_INTERVAL_SEC', 30).'"> '.$langs->trans('Seconds').'</td></tr>';

// Default print format.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaDefaultPrintFormat').'</td><td>';
$pf = getDolGlobalString('POSNOVA_DEFAULT_PRINT_FORMAT', '80mm');
print '<select name="posnova_default_print_format" class="flat">';
foreach (array('80mm', '58mm', 'A4') as $opt) {
	print '<option value="'.$opt.'"'.($pf === $opt ? ' selected' : '').'>'.$opt.'</option>';
}
print '</select></td></tr>';

// Transfer timeout.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaTransferTimeout').'</td><td><input type="number" name="posnova_transfer_timeout_hours" min="1" value="'.getDolGlobalInt('POSNOVA_TRANSFER_TIMEOUT_HOURS', 24).'"> '.$langs->trans('Hours').'</td></tr>';

// Cancel escalation.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaCancelEscalation').'</td><td><input type="number" name="posnova_cancel_escalation_min" min="1" value="'.getDolGlobalInt('POSNOVA_CANCEL_ESCALATION_MIN', 30).'"> '.$langs->trans('Minutes').'</td></tr>';

// Fallback to multicurrency.
print '<tr class="oddeven"><td>'.$langs->trans('PosNovaFallbackMulticurrency').'<br><span class="opacitymedium small">'.$langs->trans('PosNovaFallbackMulticurrencyHelp').'</span></td><td>';
print $form->selectyesno('posnova_fallback_to_multicurrency', getDolGlobalInt('POSNOVA_FALLBACK_TO_MULTICURRENCY', 1), 1);
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin-top:14px"><button class="button button-save">'.$langs->trans('Save').'</button></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
