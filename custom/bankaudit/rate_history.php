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
 * \file    custom/bankaudit/rate_history.php
 * \ingroup bankaudit
 * \brief   Exchange rate history with variation alerts (Spec 6 / P4).
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

dol_include_once('/bankaudit/class/bankaudit.class.php');

$langs->loadLangs(array('banks', 'multicurrency', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
if (!$user->hasRight('bankaudit', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$pair = GETPOST('pair', 'alpha'); // format "FROM/TO"

$codeFrom = '';
$codeTo = '';
if ($pair !== '' && strpos($pair, '/') !== false) {
	list($codeFrom, $codeTo) = explode('/', $pair, 2);
	$codeFrom = preg_replace('/[^A-Za-z0-9]/', '', $codeFrom);
	$codeTo = preg_replace('/[^A-Za-z0-9]/', '', $codeTo);
}

// Build the WHERE clause shared by export and view
$where = " WHERE entity = ".((int) $conf->entity);
if ($codeFrom !== '' && $codeTo !== '') {
	$where .= " AND code_from = '".$db->escape($codeFrom)."' AND code_to = '".$db->escape($codeTo)."'";
}

// CSV export
if ($action == 'export') {
	$filename = 'rate_history_'.dol_print_date(dol_now(), '%Y%m%d%H%M').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');

	$out = "Date;From;To;OldRate;NewRate;VariationPct;Alert;Source\n";
	$sql = "SELECT tms, code_from, code_to, rate_old, rate_new, variation_pct, alerte, source";
	$sql .= " FROM ".MAIN_DB_PREFIX."bank_rate_history".$where;
	$sql .= " ORDER BY tms DESC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$out .= dol_print_date($db->jdate($obj->tms), 'dayhour').';';
			$out .= $obj->code_from.';'.$obj->code_to.';';
			$out .= ($obj->rate_old === null ? '' : $obj->rate_old).';';
			$out .= $obj->rate_new.';';
			$out .= ($obj->variation_pct === null ? '' : $obj->variation_pct).';';
			$out .= ($obj->alerte ? '1' : '0').';';
			$out .= $obj->source."\n";
		}
	}
	print $out;
	$db->close();
	exit;
}


/**
 * Build an inline SVG line chart from a numeric series.
 *
 * @param float[] $series Numeric points (chronological)
 * @param int     $w      Width
 * @param int     $h      Height
 * @return string         SVG markup
 */
function bankaudit_linechart($series, $w = 600, $h = 140)
{
	$n = count($series);
	if ($n < 2) {
		return '<span class="opacitymedium">-</span>';
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
		$y = round($h - (($series[$i] - $min) / $range) * ($h - 10) - 5, 2);
		$pts[] = $x.','.$y;
	}
	$color = ($series[$n - 1] >= $series[0]) ? '#27a544' : '#cf2e2e';
	$out = '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" style="border:1px solid #ddd;background:#fafafa">';
	$out .= '<polyline fill="none" stroke="'.$color.'" stroke-width="2" points="'.implode(' ', $pts).'"/>';
	$out .= '</svg>';
	return $out;
}


/*
 * View
 */

llxHeader('', $langs->trans('RateHistoryTitle'), '', '', 0, 0, '', '', '', 'mod-bankaudit page-ratehistory');

print load_fiche_titre($langs->trans('RateHistoryTitle'), '', 'multicurrency');

// Distinct pairs for the filter
$pairs = array();
$sql = "SELECT DISTINCT code_from, code_to FROM ".MAIN_DB_PREFIX."bank_rate_history";
$sql .= " WHERE entity = ".((int) $conf->entity)." ORDER BY code_from, code_to";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$pairs[] = $obj->code_from.'/'.$obj->code_to;
	}
}

// Filter form
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print '<span class="opacitymedium">'.$langs->trans('RateAlertThresholdPct').': <b>'.getDolGlobalString('BANKAUDIT_RATE_ALERT_THRESHOLD', '10').'%</b></span> &nbsp; ';
print $langs->trans('CurrencyPair').': ';
print '<select name="pair" class="flat" onchange="this.form.submit()">';
print '<option value="">'.$langs->trans('AllPairs').'</option>';
foreach ($pairs as $p) {
	print '<option value="'.dol_escape_htmltag($p).'"'.($p === $pair ? ' selected' : '').'>'.dol_escape_htmltag($p).'</option>';
}
print '</select>';
print '</form>';

print ' &nbsp; <a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=export'.($pair !== '' ? '&pair='.urlencode($pair) : '').'">'.$langs->trans('ExportCsv').'</a>';
print '<br><br>';

// 90-day evolution chart for the selected pair
if ($codeFrom !== '' && $codeTo !== '') {
	$series = array();
	$tsmin = $db->idate(dol_now() - (90 * 86400));
	$sql = "SELECT rate_new FROM ".MAIN_DB_PREFIX."bank_rate_history";
	$sql .= " WHERE entity = ".((int) $conf->entity);
	$sql .= " AND code_from = '".$db->escape($codeFrom)."' AND code_to = '".$db->escape($codeTo)."'";
	$sql .= " AND tms >= '".$tsmin."'";
	$sql .= " ORDER BY tms ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$series[] = (float) $obj->rate_new;
		}
	}
	print '<div class="opacitymedium">'.$langs->trans('RateEvolutionChart').' - '.dol_escape_htmltag($pair).'</div>';
	print bankaudit_linechart($series);
	print '<br><br>';
}

// History table
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('AuditDateTime').'</th>';
print '<th>'.$langs->trans('CurrencyPair').'</th>';
print '<th class="right">'.$langs->trans('OldRate').'</th>';
print '<th class="right">'.$langs->trans('NewRate').'</th>';
print '<th class="right">'.$langs->trans('RateVariation').'</th>';
print '<th class="center">'.$langs->trans('RateAlertFlag').'</th>';
print '</tr>';

$sql = "SELECT tms, code_from, code_to, rate_old, rate_new, variation_pct, alerte";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_rate_history".$where;
$sql .= " ORDER BY tms DESC, rowid DESC";
$resql = $db->query($sql);
$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;
		$rowstyle = !empty($obj->alerte) ? ' style="background-color:#fde8e8"' : '';
		print '<tr class="oddeven"'.$rowstyle.'>';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->tms), 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($obj->code_from.'/'.$obj->code_to).'</td>';
		print '<td class="right">'.($obj->rate_old === null ? '<span class="opacitymedium">-</span>' : price2num($obj->rate_old)).'</td>';
		print '<td class="right">'.price2num($obj->rate_new).'</td>';
		print '<td class="right">';
		if ($obj->variation_pct === null) {
			print '<span class="opacitymedium">-</span>';
		} else {
			$v = (float) $obj->variation_pct;
			print '<span style="color:'.(!empty($obj->alerte) ? '#cf2e2e' : 'inherit').'">'.price2num(round($v, 2)).'%</span>';
		}
		print '</td>';
		print '<td class="center">'.(!empty($obj->alerte) ? img_warning($langs->trans('RateAlertFlag')) : '').'</td>';
		print '</tr>';
	}
}
if ($nb == 0) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans('NoRateHistory').'</td></tr>';
}
print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
