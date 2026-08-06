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
 * \file    custom/bankaudit/treasury_dashboard.php
 * \ingroup bankaudit
 * \brief   Real time treasury dashboard (Spec 4 / P1).
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
dol_include_once('/bankaudit/class/bankaudit.class.php');

$langs->loadLangs(array('banks', 'compta', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
if (!$user->hasRight('bankaudit', 'read') || !$user->hasRight('banque', 'lire')) {
	accessforbidden();
}

$mainCurrency = $conf->currency;
$now = dol_now();

/**
 * Build a small inline SVG sparkline from a numeric series.
 *
 * @param float[] $series Numeric points (chronological)
 * @param int     $w      Width
 * @param int     $h      Height
 * @return string         SVG markup
 */
function bankaudit_sparkline($series, $w = 120, $h = 26)
{
	$n = count($series);
	if ($n < 2) {
		return '';
	}
	$min = min($series);
	$max = max($series);
	$range = ($max - $min);
	if ($range == 0) {
		$range = 1;
	}
	$stepx = $w / ($n - 1);
	$pts = array();
	for ($i = 0; $i < $n; $i++) {
		$x = round($i * $stepx, 2);
		$y = round($h - (($series[$i] - $min) / $range) * ($h - 4) - 2, 2);
		$pts[] = $x.','.$y;
	}
	$color = ($series[$n - 1] >= $series[0]) ? '#27a544' : '#cf2e2e';
	$out = '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" style="vertical-align:middle">';
	$out .= '<polyline fill="none" stroke="'.$color.'" stroke-width="1.5" points="'.implode(' ', $pts).'"/>';
	$out .= '</svg>';
	return $out;
}


/*
 * View
 */

llxHeader('', $langs->trans('TreasuryDashboardTitle'), '', '', 0, 0, '', '', '', 'mod-bankaudit page-dashboard');

$refreshlink = '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('RefreshNow').'</a>';
print load_fiche_titre($langs->trans('TreasuryDashboardTitle'), $refreshlink, 'bank_account');

print '<span class="opacitymedium">'.$langs->trans('RefreshedOn').' '.dol_print_date($now, 'dayhour').'</span><br><br>';

// Gather accounts
$accounts = array();
$sql = "SELECT rowid, ref, label, currency_code, courant FROM ".MAIN_DB_PREFIX."bank_account";
$sql .= " WHERE entity IN (".getEntity('bank_account').") AND clos = 0";
$sql .= " ORDER BY ref";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$accounts[] = $obj;
	}
}

$totalMain = 0.0;
$totalMainKnown = true;
$nbCash = 0;
$nbBank = 0;
$rows = array();
$alerts = array();

foreach ($accounts as $acc) {
	$accId = (int) $acc->rowid;
	$cur = $acc->currency_code;
	$balance = BankAudit::getAccountBalance($db, $accId);

	$rate = BankAudit::getConversionRate($db, $cur, $mainCurrency);
	$equiv = ($rate === null) ? null : ($balance * $rate);
	if ($equiv === null) {
		$totalMainKnown = false;
	} else {
		$totalMain += $equiv;
	}

	if ((int) $acc->courant === 2) {
		$nbCash++;
	} else {
		$nbBank++;
	}

	// 7-day trend
	$balance7 = BankAudit::getBalanceAsOf($db, $accId, $now - (7 * 86400));
	$trend = null;
	if ($balance7 != 0) {
		$trend = ($balance - $balance7) / abs($balance7) * 100;
	}

	// Sparkline: weekly cumulative balance over the last 8 weeks
	$series = array();
	for ($k = 7; $k >= 0; $k--) {
		$series[] = BankAudit::getBalanceAsOf($db, $accId, $now - ($k * 7 * 86400));
	}

	// Threshold alert
	$threshold = BankAudit::getMinBalanceForAccount($db, $accId);
	if ($threshold !== null && $balance < $threshold) {
		$alerts[] = array('label' => $acc->ref.' - '.$acc->label, 'threshold' => $threshold, 'currency' => $cur);
	}

	$rows[] = array(
		'id' => $accId,
		'ref' => $acc->ref,
		'label' => $acc->label,
		'currency' => $cur,
		'balance' => $balance,
		'equiv' => $equiv,
		'trend' => $trend,
		'series' => $series,
		'iscash' => ((int) $acc->courant === 2),
	);
}

// KPI cards
print '<div class="fichecenter">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr>';

print '<td class="center" style="width:25%">';
print '<div class="opacitymedium">'.$langs->trans('TotalConsolidated').' ('.$mainCurrency.')</div>';
print '<div class="amount" style="font-size:1.4em">'.($totalMainKnown ? price($totalMain, 0, $langs, 1, -1, -1, $mainCurrency) : '<span class="opacitymedium">~ '.price($totalMain, 0, $langs, 1, -1, -1, $mainCurrency).'</span>').'</div>';
print '</td>';

print '<td class="center" style="width:25%">';
print '<div class="opacitymedium">'.$langs->trans('NbCashAccounts').'</div>';
print '<div style="font-size:1.4em">'.$nbCash.'</div>';
print '</td>';

print '<td class="center" style="width:25%">';
print '<div class="opacitymedium">'.$langs->trans('NbBankAccounts').'</div>';
print '<div style="font-size:1.4em">'.$nbBank.'</div>';
print '</td>';

print '<td class="center" style="width:25%">';
print '<div class="opacitymedium">'.$langs->trans('TreasuryAlerts').'</div>';
print '<div style="font-size:1.4em'.(count($alerts) ? ';color:#cf2e2e' : '').'">'.count($alerts).'</div>';
print '</td>';

print '</tr></table></div>';
print '</div>';

print '<br>';

// Alerts block
if (count($alerts)) {
	$msg = '';
	foreach ($alerts as $a) {
		$msg .= img_warning().' '.$langs->trans('AccountBelowThreshold', $a['label'], price($a['threshold'], 0, $langs, 1, -1, -1, $a['currency'])).'<br>';
	}
	print '<div class="warning">'.$msg.'</div><br>';
}

// Accounts table
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('BankAccount').'</th>';
print '<th>'.$langs->trans('Currency').'</th>';
print '<th class="right">'.$langs->trans('NativeBalance').'</th>';
print '<th class="right">'.$langs->trans('EquivalentMainCurrency', $mainCurrency).'</th>';
print '<th class="center">'.$langs->trans('Trend7Days').'</th>';
print '<th class="center">'.$langs->trans('RateEvolutionChart').'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans('NoOpenAccount').'</td></tr>';
}

foreach ($rows as $r) {
	$accurl = DOL_URL_ROOT.'/compta/bank/bankentries_list.php?id='.$r['id'];
	print '<tr class="oddeven">';
	print '<td>'.($r['iscash'] ? img_picto('', 'cash-register', 'class="paddingright"') : img_picto('', 'bank_account', 'class="paddingright"')).'<a href="'.$accurl.'">'.dol_escape_htmltag($r['ref'].' - '.$r['label']).'</a></td>';
	print '<td>'.dol_escape_htmltag($r['currency']).'</td>';
	print '<td class="right nowraponall amount">'.price($r['balance'], 0, $langs, 1, -1, -1, $r['currency']).'</td>';

	if ($r['equiv'] === null) {
		print '<td class="right opacitymedium" title="'.dol_escape_htmltag($langs->trans('RateMissingForCurrency', $r['currency'])).'">-</td>';
	} else {
		print '<td class="right nowraponall">'.price($r['equiv'], 0, $langs, 1, -1, -1, $mainCurrency).'</td>';
	}

	print '<td class="center nowraponall">';
	if ($r['trend'] === null) {
		print '<span class="opacitymedium">-</span>';
	} elseif ($r['trend'] >= 0) {
		print '<span style="color:#27a544">&#9650; +'.price2num(round($r['trend'], 1)).'%</span>';
	} else {
		print '<span style="color:#cf2e2e">&#9660; '.price2num(round($r['trend'], 1)).'%</span>';
	}
	print '</td>';

	print '<td class="center">'.bankaudit_sparkline($r['series']).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
