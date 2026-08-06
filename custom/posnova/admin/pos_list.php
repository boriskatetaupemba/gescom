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
 * \file    custom/posnova/admin/pos_list.php
 * \ingroup posnova
 * \brief   List of POS terminals.
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
dol_include_once('/posnova/core/lib/posnova.lib.php');
dol_include_once('/posnova/class/posconfig.class.php');
dol_include_once('/posnova/class/possession.class.php');

$langs->loadLangs(array('admin', 'posnova@posnova', 'stocks'));

if (!$user->admin && !$user->hasRight('posnova', 'setup')) {
	accessforbidden();
}

/*
 * View
 */
llxHeader('', $langs->trans('PosNovaTerminals'), '', '', 0, 0, '', '', '', 'mod-posnova page-poslist');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('PosNovaSetup'), $linkback, 'title_setup');

$head = posnovaAdminPrepareHead();
print dol_get_fiche_head($head, 'terminals', $langs->trans('PosNovaSetup'), -1, 'cash-register');

$cardUrl = dol_buildpath('/custom/posnova/admin/pos_card.php', 1);

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_escape_htmltag($cardUrl.'?action=create').'">'.$langs->trans('PosNovaCreateTerminal').'</a>';
print '</div>';

$cfg = new PosConfig($db);
$terminals = $cfg->fetchAll(false);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Label').'</th>';
print '<th>'.$langs->trans('Warehouse').'</th>';
print '<th class="center">'.$langs->trans('PosNovaWorkingCurrency').'</th>';
print '<th class="center">'.$langs->trans('PosNovaSession').'</th>';
print '<th class="center">'.$langs->trans('Status').'</th>';
print '<th></th>';
print '</tr>';

if (empty($terminals)) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans('PosNovaNoTerminal').'</td></tr>';
} else {
	foreach ($terminals as $t) {
		$whlabel = '';
		$resw = $db->query("SELECT label, ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $t->fk_warehouse));
		if ($resw && $db->num_rows($resw)) {
			$ow = $db->fetch_object($resw);
			$whlabel = $ow->label ? $ow->label : $ow->ref;
		}
		$sess = new PosSession($db);
		$busy = $sess->fetchOpenForPos($t->id) > 0;
		$check = $t->validateForLaunch();

		print '<tr class="oddeven">';
		print '<td><a href="'.dol_escape_htmltag($cardUrl.'?id='.((int) $t->id)).'">'.dol_escape_htmltag($t->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag($t->label).'</td>';
		print '<td>'.dol_escape_htmltag($whlabel).'</td>';
		print '<td class="center"><span class="badge badge-status">'.dol_escape_htmltag($t->getWorkingCurrency()).'</span></td>';
		print '<td class="center">'.($busy ? '<span class="badge badge-status4 badge-status">'.$langs->trans('PosNovaSessionInProgress').'</span>' : '<span class="opacitymedium">-</span>').'</td>';
		print '<td class="center">';
		if (!$t->active) {
			print '<span class="badge badge-status8 badge-status">'.$langs->trans('Disabled').'</span>';
		} elseif (!$check['ok']) {
			print '<span class="badge badge-status1 badge-status" title="'.dol_escape_htmltag($langs->trans('PosNovaErrCannotLaunch')).'">'.$langs->trans('PosNovaIncomplete').'</span>';
		} else {
			print '<span class="badge badge-status4 badge-status">'.$langs->trans('Enabled').'</span>';
		}
		print '</td>';
		print '<td class="right"><a class="editfielda" href="'.dol_escape_htmltag($cardUrl.'?id='.((int) $t->id).'&action=edit').'">'.img_edit().'</a></td>';
		print '</tr>';
	}
}
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
