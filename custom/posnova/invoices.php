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
 * \file    custom/posnova/invoices.php
 * \ingroup posnova
 * \brief   List of POS tickets / invoices issued by the terminals.
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

dol_include_once('/posnova/class/posnova.class.php');
dol_include_once('/posnova/class/posconfig.class.php');

$langs->loadLangs(array('posnova@posnova', 'bills', 'main', 'companies'));

if (!isModEnabled('posnova')) {
	accessforbidden();
}
if (!$user->hasRight('posnova', 'run')) {
	accessforbidden();
}

$search_pos = GETPOSTINT('search_pos');
$search_status = GETPOST('search_status', 'aZ09');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTINT('page') < 0 ? 0 : GETPOSTINT('page');
$offset = $limit * $page;

/*
 * View
 */
llxHeader('', $langs->trans('PosNovaInvoices'), '', '', 0, 0, '', '', '', 'mod-posnova page-invoices');

print load_fiche_titre($langs->trans('PosNovaInvoices'), '', 'bill');

// Terminal filter.
$cfg = new PosConfig($db);
$terminals = $cfg->fetchAll(false);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print $langs->trans('PosNovaTerminal').': <select name="search_pos" class="flat" onchange="this.form.submit()">';
print '<option value="0">'.$langs->trans('All').'</option>';
foreach ($terminals as $t) {
	print '<option value="'.((int) $t->id).'"'.($search_pos === (int) $t->id ? ' selected' : '').'>'.dol_escape_htmltag($t->label).'</option>';
}
print '</select> &nbsp; ';
print $langs->trans('Status').': <select name="search_status" class="flat" onchange="this.form.submit()">';
$statuses = array('' => $langs->trans('All'), 'VALIDATED' => $langs->trans('PosNovaStatusValidated'), 'CANCELLED' => $langs->trans('PosNovaStatusCancelled'), 'CANCEL_PENDING' => $langs->trans('PosNovaStatusCancelPending'));
foreach ($statuses as $k => $v) {
	print '<option value="'.$k.'"'.($search_status === $k ? ' selected' : '').'>'.$v.'</option>';
}
print '</select>';
print '</form><br><br>';

$where = " WHERE pt.entity IN (".getEntity('posnova').")";
if ($search_pos > 0) {
	$where .= " AND pt.fk_pos = ".((int) $search_pos);
}
if ($search_status !== '') {
	$where .= " AND pt.status = '".$db->escape($search_status)."'";
}

// Count.
$total = 0;
$sqlc = "SELECT COUNT(pt.rowid) as nb FROM ".MAIN_DB_PREFIX."pos_ticket pt".$where;
$resc = $db->query($sqlc);
if ($resc && $db->num_rows($resc)) {
	$total = (int) $db->fetch_object($resc)->nb;
}

print_barre_liste($langs->trans('PosNovaInvoices'), $page, $_SERVER['PHP_SELF'], '', '', '', '', $total, $total, 'bill', 0, '', '', $limit);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('PosNovaTicketRef').'</th>';
print '<th>'.$langs->trans('Invoice').'</th>';
print '<th>'.$langs->trans('PosNovaTerminal').'</th>';
print '<th>'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th class="center">'.$langs->trans('Currency').'</th>';
print '<th class="right">'.$langs->trans('Total').'</th>';
print '<th class="center">'.$langs->trans('Status').'</th>';
print '</tr>';

$sql = "SELECT pt.rowid, pt.ref, pt.fk_facture, pt.currency, pt.total_ttc, pt.status, pt.invoice_date, pt.is_backdated,";
$sql .= " f.ref as fref, s.nom as socname, s.rowid as socid, pc.label as poslabel";
$sql .= " FROM ".MAIN_DB_PREFIX."pos_ticket pt";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = pt.fk_facture";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = pt.fk_soc";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."pos_config pc ON pc.rowid = pt.fk_pos";
$sql .= $where;
$sql .= " ORDER BY pt.rowid DESC";
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;
		$badge = '<span class="badge badge-status4 badge-status">'.$langs->trans('PosNovaStatusValidated').'</span>';
		if ($obj->status === 'CANCELLED') {
			$badge = '<span class="badge badge-status8 badge-status">'.$langs->trans('PosNovaStatusCancelled').'</span>';
		} elseif ($obj->status === 'CANCEL_PENDING') {
			$badge = '<span class="badge badge-status1 badge-status">'.$langs->trans('PosNovaStatusCancelPending').'</span>';
		}
		$invlink = '<span class="opacitymedium">-</span>';
		if ($obj->fk_facture > 0) {
			$invlink = '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $obj->fk_facture).'">'.dol_escape_htmltag($obj->fref ? $obj->fref : ('#'.$obj->fk_facture)).'</a>';
		}
		print '<tr class="oddeven">';
		print '<td class="nowraponall"><b>'.dol_escape_htmltag($obj->ref).'</b>'.($obj->is_backdated ? ' '.img_picto($langs->trans('PosNovaBackdated'), 'calendar') : '').'</td>';
		print '<td>'.$invlink.'</td>';
		print '<td>'.dol_escape_htmltag($obj->poslabel).'</td>';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->invoice_date, 'gmt'), 'day').'</td>';
		print '<td>'.($obj->socid ? '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $obj->socid).'">'.dol_escape_htmltag($obj->socname).'</a>' : '<span class="opacitymedium">-</span>').'</td>';
		print '<td class="center"><span class="badge badge-status">'.dol_escape_htmltag($obj->currency).'</span></td>';
		print '<td class="right">'.PosNova::formatAmount($obj->total_ttc, $obj->currency).'</td>';
		print '<td class="center">'.$badge.'</td>';
		print '</tr>';
	}
}
if ($nb == 0) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium center">'.$langs->trans('PosNovaNoInvoice').'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
