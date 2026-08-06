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
 * \file    custom/posnova/sessions.php
 * \ingroup posnova
 * \brief   POS cash sessions list and Z-report detail.
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
dol_include_once('/posnova/class/possession.class.php');

$langs->loadLangs(array('posnova@posnova', 'main', 'users'));

if (!isModEnabled('posnova')) {
	accessforbidden();
}
if (!$user->hasRight('posnova', 'run')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);
$selfUrl = $_SERVER['PHP_SELF'];

/*
 * View
 */
llxHeader('', $langs->trans('PosNovaSessions'), '', '', 0, 0, '', '', '', 'mod-posnova page-sessions');

if ($id > 0) {
	// -------- Z-report detail --------
	$session = new PosSession($db);
	if ($session->fetch($id) <= 0) {
		accessforbidden('NotFound');
	}
	print load_fiche_titre($langs->trans('PosNovaZReport').' — '.dol_escape_htmltag($session->ref), '<a href="'.dol_escape_htmltag($selfUrl).'">'.$langs->trans('BackToList').'</a>', 'cash-register');

	$summary = $session->getCashSummary();
	$expUsd = isset($summary['expected'][PosNova::USD]) ? (float) $summary['expected'][PosNova::USD] : 0;
	$expCdf = isset($summary['expected'][$cdfCode]) ? (float) $summary['expected'][$cdfCode] : 0;

	// Ticket count + sales totals per currency.
	$nbTickets = 0;
	$salesByCur = array();
	$sqlt = "SELECT currency, COUNT(rowid) as nb, SUM(total_ttc) as tot FROM ".MAIN_DB_PREFIX."pos_ticket";
	$sqlt .= " WHERE fk_session = ".((int) $session->id)." AND status = 'VALIDATED' GROUP BY currency";
	$rest = $db->query($sqlt);
	if ($rest) {
		while ($o = $db->fetch_object($rest)) {
			$nbTickets += (int) $o->nb;
			$salesByCur[strtoupper($o->currency)] = (float) $o->tot;
		}
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('PosNovaTerminal').'</td><td>'.((int) $session->fk_pos).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaOpenedBy').'</td><td>'.dol_escape_htmltag(pn_username($db, $session->fk_user_open)).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.dol_print_date($session->date_open, 'dayhour').'</td></tr>';
	print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.($session->date_close ? dol_print_date($session->date_close, 'dayhour') : '<span class="opacitymedium">-</span>').'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaRateUsdCdf').'</td><td>1 USD = '.price($session->rate_usd_cdf, 0, $langs, 1, 0, 3).' '.dol_escape_htmltag($cdfCode).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaTickets').'</td><td>'.$nbTickets.'</td></tr>';
	print '<tr><td>'.$langs->trans('PosNovaReconnections').'</td><td>'.((int) $session->reconnection_count).'</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.pn_session_badge($session->status, $langs).'</td></tr>';
	print '</table>';

	print '<br>'.load_fiche_titre($langs->trans('PosNovaCashReconciliation'), '', '');
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th></th><th class="right">'.$langs->trans('PosNovaInitialFund').'</th><th class="right">'.$langs->trans('PosNovaExpected').'</th><th class="right">'.$langs->trans('PosNovaCounted').'</th><th class="right">'.$langs->trans('PosNovaDiscrepancy').'</th></tr>';

	$rows = array(
		array('USD', $session->fund_init_usd, $expUsd, $session->fund_final_usd),
		array($cdfCode, $session->fund_init_cdf, $expCdf, $session->fund_final_cdf),
	);
	foreach ($rows as $r) {
		list($cur, $init, $exp, $final) = $r;
		$diff = ($final !== null) ? ((float) $final - (float) $exp) : null;
		print '<tr class="oddeven">';
		print '<td><span class="badge badge-status">'.dol_escape_htmltag($cur).'</span></td>';
		print '<td class="right">'.PosNova::formatAmount($init, $cur).'</td>';
		print '<td class="right">'.PosNova::formatAmount($exp, $cur).'</td>';
		print '<td class="right">'.($final !== null ? PosNova::formatAmount($final, $cur) : '<span class="opacitymedium">-</span>').'</td>';
		print '<td class="right">'.($diff !== null ? '<b style="color:'.($diff < 0 ? '#cf2e2e' : '#27a544').'">'.PosNova::formatAmount($diff, $cur).'</b>' : '<span class="opacitymedium">-</span>').'</td>';
		print '</tr>';
	}
	print '</table>';
} else {
	// -------- Sessions list --------
	print load_fiche_titre($langs->trans('PosNovaSessions'), '', 'cash-register');

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Ref').'</th>';
	print '<th>'.$langs->trans('PosNovaTerminal').'</th>';
	print '<th>'.$langs->trans('PosNovaOpenedBy').'</th>';
	print '<th>'.$langs->trans('DateStart').'</th>';
	print '<th>'.$langs->trans('DateEnd').'</th>';
	print '<th class="center">'.$langs->trans('PosNovaTickets').'</th>';
	print '<th class="center">'.$langs->trans('Status').'</th>';
	print '<th></th>';
	print '</tr>';

	$sql = "SELECT s.rowid, s.ref, s.fk_user_open, s.date_open, s.date_close, s.status, pc.label as poslabel,";
	$sql .= " (SELECT COUNT(t.rowid) FROM ".MAIN_DB_PREFIX."pos_ticket t WHERE t.fk_session = s.rowid AND t.status = 'VALIDATED') as nbtickets";
	$sql .= " FROM ".MAIN_DB_PREFIX."pos_session s";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."pos_config pc ON pc.rowid = s.fk_pos";
	$sql .= " WHERE s.entity IN (".getEntity('posnova').")";
	$sql .= " ORDER BY s.rowid DESC".$db->plimit(100);
	$resql = $db->query($sql);
	$nb = 0;
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$nb++;
			print '<tr class="oddeven">';
			print '<td><a href="'.dol_escape_htmltag($selfUrl.'?id='.((int) $obj->rowid)).'"><b>'.dol_escape_htmltag($obj->ref).'</b></a></td>';
			print '<td>'.dol_escape_htmltag($obj->poslabel).'</td>';
			print '<td>'.dol_escape_htmltag(pn_username($db, $obj->fk_user_open)).'</td>';
			print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_open), 'dayhour').'</td>';
			print '<td class="nowraponall">'.($obj->date_close ? dol_print_date($db->jdate($obj->date_close), 'dayhour') : '<span class="opacitymedium">-</span>').'</td>';
			print '<td class="center">'.((int) $obj->nbtickets).'</td>';
			print '<td class="center">'.pn_session_badge($obj->status, $langs).'</td>';
			print '<td class="right"><a class="butActionRefused" style="cursor:pointer" href="'.dol_escape_htmltag($selfUrl.'?id='.((int) $obj->rowid)).'">'.$langs->trans('PosNovaZReport').'</a></td>';
			print '</tr>';
		}
	}
	if ($nb == 0) {
		print '<tr class="oddeven"><td colspan="8" class="opacitymedium center">'.$langs->trans('PosNovaNoSession').'</td></tr>';
	}
	print '</table></div>';
}

llxFooter();
$db->close();

/**
 * Resolve a user's display name.
 *
 * @param  DoliDB $db  Database handler
 * @param  int    $uid User id
 * @return string      Full name or '-'
 */
function pn_username($db, $uid)
{
	if ($uid <= 0) {
		return '-';
	}
	$resql = $db->query("SELECT firstname, lastname FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".((int) $uid));
	if ($resql && $db->num_rows($resql)) {
		$o = $db->fetch_object($resql);
		$n = trim(($o->firstname ? $o->firstname.' ' : '').$o->lastname);
		return $n !== '' ? $n : '-';
	}
	return '-';
}

/**
 * Render a status badge for a session.
 *
 * @param  string $status Session status
 * @param  Translate $langs Lang handler
 * @return string
 */
function pn_session_badge($status, $langs)
{
	switch ($status) {
		case 'OPEN':
			return '<span class="badge badge-status4 badge-status">'.$langs->trans('PosNovaStatusOpen').'</span>';
		case 'LOCKED':
			return '<span class="badge badge-status1 badge-status">'.$langs->trans('PosNovaStatusLocked').'</span>';
		case 'CLOSED':
			return '<span class="badge badge-status6 badge-status">'.$langs->trans('PosNovaStatusClosed').'</span>';
		case 'CANCELLED':
			return '<span class="badge badge-status8 badge-status">'.$langs->trans('PosNovaStatusCancelled').'</span>';
		default:
			return dol_escape_htmltag($status);
	}
}
