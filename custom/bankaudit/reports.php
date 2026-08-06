<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/bankaudit/reports.php
 * \ingroup bankaudit
 * \brief   Unified reporting center R01..R15 + value-added features.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
dol_include_once('/bankaudit/class/bankauditreports.class.php');

$langs->loadLangs(array('main', 'dict', 'banks', 'compta', 'products', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$reportCode = GETPOST('report', 'aZ09');
if ($reportCode === '') {
	$reportCode = 'R01';
}
$format = GETPOST('format', 'aZ09');
if ($format === '') {
	$format = 'screen';
}

$form = new Form($db);
$service = new BankAuditReports($db, $langs, $user, $conf);
$catalog = $service->getCatalog();
if (empty($catalog[$reportCode])) {
	$reportCode = 'R01';
}

$favoriteId = GETPOSTINT('favorite_id');
$favoriteData = null;
if ($favoriteId > 0) {
	$favoriteData = $service->getFavorite($favoriteId);
	if (is_array($favoriteData) && !empty($favoriteData['report_code'])) {
		$reportCode = $favoriteData['report_code'];
	}
}

$rawFilters = array(
	'date_start' => dol_mktime(0, 0, 0, GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear')),
	'date_end' => dol_mktime(23, 59, 59, GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear')),
	'fk_accounts' => GETPOST('fk_account', 'array'),
	'fk_entrepot' => GETPOSTINT('fk_entrepot'),
	'fk_user' => GETPOSTINT('fk_user'),
	'devise' => GETPOST('devise', 'alpha'),
	'type_compte' => GETPOSTINT('type_compte'),
	'granularity' => GETPOST('granularity', 'aZ09'),
	'inter_dev' => GETPOSTINT('inter_dev'),
	'compare_prev' => GETPOSTINT('compare_prev'),
	'show_closed' => GETPOSTINT('show_closed'),
	'montant_min' => GETPOST('montant_min', 'alphanohtml'),
	'montant_max' => GETPOST('montant_max', 'alphanohtml'),
	'ecart_min' => GETPOST('ecart_min', 'alphanohtml'),
	'rappro' => GETPOSTINT('rappro'),
	'action_filter' => GETPOST('action_filter', 'aZ09'),
	'object_type' => GETPOST('object_type', 'aZ09'),
	'label_search' => GETPOST('label_search', 'alphanohtml'),
	'report_annotation' => GETPOST('report_annotation', 'restricthtml'),
);

if ($favoriteData && !empty($favoriteData['filters']) && is_array($favoriteData['filters'])) {
	$rawFilters = array_merge($rawFilters, $favoriteData['filters']);
}

$filters = $service->normalizeFilters($rawFilters);
$service->checkAccess($reportCode, $format);

if ($action === 'savefavorite') {
	$favLabel = GETPOST('favorite_label', 'alphanohtml');
	$resSave = $service->saveFavorite($favLabel, $reportCode, $filters);
	if ($resSave > 0) {
		setEventMessages($langs->trans('FavoriteSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?report='.urlencode($reportCode));
	exit;
}
if ($action === 'delfavorite' && GETPOSTINT('favorite_delete_id') > 0) {
	$service->deleteFavorite(GETPOSTINT('favorite_delete_id'));
	setEventMessages($langs->trans('FavoriteDeleted'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?report='.urlencode($reportCode));
	exit;
}

$report = $service->buildReport($reportCode, $filters);

if ($format !== 'screen') {
	$baseFilename = 'bankaudit_'.$reportCode.'_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	$service->logExport($reportCode, $format, $filters);
	if ($format === 'csv') {
		$service->exportCsv($report, $baseFilename.'.csv');
	} elseif ($format === 'excel') {
		$service->exportXlsx($report, $baseFilename.'.xlsx');
	} elseif ($format === 'pdf') {
		$service->exportPdf($report, $baseFilename.'.pdf', $filters['report_annotation']);
	}
	$db->close();
	exit;
}

$favorites = $service->listFavorites();
$recentExports = $service->getRecentExportLogs(12);

$accountOptions = array();
$sqlAcc = "SELECT rowid, ref, label FROM ".MAIN_DB_PREFIX."bank_account";
$sqlAcc .= " WHERE entity IN (".getEntity('bank_account').")";
$sqlAcc .= " ORDER BY ref";
$resAcc = $db->query($sqlAcc);
if ($resAcc) {
	while ($obj = $db->fetch_object($resAcc)) {
		$accountOptions[(int) $obj->rowid] = $obj->ref.' - '.$obj->label;
	}
}

$warehouseOptions = array(0 => $langs->trans('All'));
$sqlWh = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."entrepot";
$sqlWh .= " WHERE entity = ".((int) $conf->entity)." ORDER BY ref";
$resWh = $db->query($sqlWh);
if ($resWh) {
	while ($obj = $db->fetch_object($resWh)) {
		$warehouseOptions[(int) $obj->rowid] = $obj->ref;
	}
}

$userOptions = array(0 => $langs->trans('All'));
$sqlUsers = "SELECT rowid, login, firstname, lastname FROM ".MAIN_DB_PREFIX."user WHERE statut = 1 ORDER BY login";
$resUsers = $db->query($sqlUsers);
if ($resUsers) {
	while ($obj = $db->fetch_object($resUsers)) {
		$userOptions[(int) $obj->rowid] = dolGetFirstLastname($obj->firstname, $obj->lastname, true).' ('.$obj->login.')';
	}
}

$currencyOptions = array('' => $langs->trans('All'));
$sqlCur = "SELECT DISTINCT currency_code FROM ".MAIN_DB_PREFIX."bank_account";
$sqlCur .= " WHERE entity IN (".getEntity('bank_account').") AND currency_code IS NOT NULL AND currency_code <> '' ORDER BY currency_code";
$resCur = $db->query($sqlCur);
if ($resCur) {
	while ($obj = $db->fetch_object($resCur)) {
		$currencyOptions[$obj->currency_code] = $obj->currency_code;
	}
}

$reportSelector = array();
foreach ($catalog as $code => $meta) {
	$reportSelector[$code] = $meta['title'];
}

llxHeader('', $langs->trans('BankReportsTitle'), '', '', 0, 0, '', '', '', 'mod-bankaudit page-reports');

print load_fiche_titre($langs->trans('BankReportsTitle'), '', 'generic');
print '<div class="info"><strong>'.$langs->trans('ReportWhatFor').'</strong> '.dol_escape_htmltag($report['tip']).'</div>';
print '<br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="generate">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="8">'.$langs->trans('Filters').'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ReportType').'</td><td>'.$form->selectarray('report', $reportSelector, $reportCode, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth400').'</td>';
print '<td>'.$langs->trans('ReportDateStart').'</td><td>'.$form->selectDate($filters['date_start'], 'date_start', 0, 0, 0, '', 1, 0).'</td>';
print '<td>'.$langs->trans('ReportDateEnd').'</td><td>'.$form->selectDate($filters['date_end'], 'date_end', 0, 0, 0, '', 1, 0).'</td>';
print '<td>'.$langs->trans('BankAccount').'</td><td>'.$form->multiselectarray('fk_account', $accountOptions, $filters['fk_accounts'], 0, 0, 'maxwidth300', 0, 0).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('Warehouse').'</td><td>'.$form->selectarray('fk_entrepot', $warehouseOptions, $filters['fk_entrepot'], 0).'</td>';
print '<td>'.$langs->trans('AuditUser').'</td><td>'.$form->selectarray('fk_user', $userOptions, $filters['fk_user'], 0).'</td>';
print '<td>'.$langs->trans('Currency').'</td><td>'.$form->selectarray('devise', $currencyOptions, $filters['devise'], 0).'</td>';
print '<td>'.$langs->trans('Type').'</td>';
print '<td>'.$form->selectarray('type_compte', array(0 => $langs->trans('All'), 1 => $langs->trans('NbBankAccounts'), 2 => $langs->trans('NbCashAccounts')), $filters['type_compte'], 0).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('Granularity').'</td>';
print '<td>'.$form->selectarray('granularity', array('week' => $langs->trans('Week'), 'month' => $langs->trans('Month'), 'quarter' => $langs->trans('Quarter'), 'year' => $langs->trans('Year'), 'period' => $langs->trans('SelectedPeriod')), $filters['granularity'], 0).'</td>';
print '<td>'.$langs->trans('Label').'</td><td><input type="text" class="flat minwidth200" name="label_search" value="'.dol_escape_htmltag($filters['label_search']).'"></td>';
print '<td>'.$langs->trans('Min').' '.$langs->trans('Amount').'</td><td><input type="text" class="flat" name="montant_min" value="'.dol_escape_htmltag((string) $filters['montant_min']).'"></td>';
print '<td>'.$langs->trans('Max').' '.$langs->trans('Amount').'</td><td><input type="text" class="flat" name="montant_max" value="'.dol_escape_htmltag((string) $filters['montant_max']).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('Action').'</td>';
print '<td>'.$form->selectarray('action_filter', array('' => $langs->trans('All'), 'CREATE' => 'CREATE', 'UPDATE' => 'UPDATE', 'DELETE' => 'DELETE', 'RESTORE' => 'RESTORE'), $filters['action_filter'], 0).'</td>';
print '<td>'.$langs->trans('Type').'</td>';
print '<td>'.$form->selectarray('object_type', array('' => $langs->trans('All'), 'bank_account' => 'bank_account', 'bank_line' => 'bank_line'), $filters['object_type'], 0).'</td>';
print '<td>'.$langs->trans('Reconciled').'</td>';
print '<td>'.$form->selectarray('rappro', array(-1 => $langs->trans('All'), 1 => $langs->trans('Yes'), 0 => $langs->trans('No')), $filters['rappro'], 0).'</td>';
print '<td>'.$langs->trans('GapMinPct').'</td><td><input type="text" class="flat" name="ecart_min" value="'.dol_escape_htmltag((string) $filters['ecart_min']).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ReportAnnotation').'</td>';
print '<td colspan="5"><textarea class="flat centpercent" name="report_annotation" rows="2">'.dol_escape_htmltag($filters['report_annotation']).'</textarea></td>';
print '<td colspan="2" class="right">';
print '<label class="marginrightonly"><input type="checkbox" name="compare_prev" value="1"'.(!empty($filters['compare_prev']) ? ' checked' : '').'> '.$langs->trans('CompareWithPreviousPeriod').'</label>';
print '<label class="marginrightonly"><input type="checkbox" name="inter_dev" value="1"'.(!empty($filters['inter_dev']) ? ' checked' : '').'> '.$langs->trans('InterCurrencyOnly').'</label>';
print '<label><input type="checkbox" name="show_closed" value="1"'.(!empty($filters['show_closed']) ? ' checked' : '').'> '.$langs->trans('ShowClosedAccounts').'</label>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td colspan="8" class="right">';
print '<button class="button" type="submit">'.$langs->trans('GenerateReport').'</button>';
print '</td></tr>';

print '</table></div>';
print '</form>';

print '<br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savefavorite">';
print '<input type="hidden" name="report" value="'.dol_escape_htmltag($reportCode).'">';
print '<input type="text" class="flat" name="favorite_label" placeholder="'.$langs->trans('FavoriteLabel').'">';
print ' <button class="button" type="submit">'.$langs->trans('SaveFavorite').'</button>';
print '</form>';

if (!empty($favorites)) {
	print ' &nbsp; <span class="opacitymedium">'.$langs->trans('Favorites').':</span> ';
	foreach ($favorites as $fav) {
		print '<a class="badge marginleftonly" href="'.$_SERVER['PHP_SELF'].'?favorite_id='.(int) $fav['rowid'].'">'.dol_escape_htmltag($fav['label']).'</a>';
		print ' <a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=delfavorite&favorite_delete_id='.(int) $fav['rowid'].'&token='.newToken().'">'.img_delete().'</a> ';
	}
}

print '<br><br>';

$baseParams = 'report='.urlencode($reportCode);
$baseParams .= '&date_startmonth='.dol_print_date($filters['date_start'], '%m').'&date_startday='.dol_print_date($filters['date_start'], '%d').'&date_startyear='.dol_print_date($filters['date_start'], '%Y');
$baseParams .= '&date_endmonth='.dol_print_date($filters['date_end'], '%m').'&date_endday='.dol_print_date($filters['date_end'], '%d').'&date_endyear='.dol_print_date($filters['date_end'], '%Y');
foreach ($filters['fk_accounts'] as $bankaudit_aid) {
	$baseParams .= '&fk_account%5B%5D='.(int) $bankaudit_aid;
}
$baseParams .= '&fk_entrepot='.(int) $filters['fk_entrepot'];
$baseParams .= '&fk_user='.(int) $filters['fk_user'];
$baseParams .= '&devise='.urlencode($filters['devise']);
$baseParams .= '&type_compte='.(int) $filters['type_compte'];
$baseParams .= '&granularity='.urlencode($filters['granularity']);
$baseParams .= '&inter_dev='.(int) $filters['inter_dev'];
$baseParams .= '&compare_prev='.(int) $filters['compare_prev'];
$baseParams .= '&show_closed='.(int) $filters['show_closed'];
$baseParams .= '&montant_min='.urlencode((string) $filters['montant_min']);
$baseParams .= '&montant_max='.urlencode((string) $filters['montant_max']);
$baseParams .= '&ecart_min='.urlencode((string) $filters['ecart_min']);
$baseParams .= '&rappro='.(int) $filters['rappro'];
$baseParams .= '&action_filter='.urlencode($filters['action_filter']);
$baseParams .= '&object_type='.urlencode($filters['object_type']);
$baseParams .= '&label_search='.urlencode($filters['label_search']);
$baseParams .= '&report_annotation='.urlencode($filters['report_annotation']);

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?'.$baseParams.'&format=csv">CSV</a>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?'.$baseParams.'&format=excel">Excel</a>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?'.$baseParams.'&format=pdf">PDF</a>';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?'.$baseParams.'&report=REVIEW">'.$langs->trans('ReportReviewMode').'</a>';
print '</div>';

$renderTable = function ($rep) use ($langs) {
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	foreach ($rep['columns'] as $col) {
		print '<th>'.dol_escape_htmltag($col).'</th>';
	}
	print '</tr>';
	if (empty($rep['rows'])) {
		print '<tr class="oddeven"><td colspan="'.count($rep['columns']).'" class="center opacitymedium">'.$langs->trans('NoDataForPeriod').'</td></tr>';
	} else {
		foreach ($rep['rows'] as $row) {
			print '<tr class="oddeven">';
			foreach ($row as $cell) {
				print '<td>'.(string) $cell.'</td>';
			}
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';

	if (!empty($rep['totals'])) {
		print '<div class="marginbottomonly">';
		foreach ($rep['totals'] as $k => $v) {
			print '<div><span class="opacitymedium">'.dol_escape_htmltag($k).'</span> : <strong>'.dol_escape_htmltag($v).'</strong></div>';
		}
		print '</div>';
	}
};

if (!empty($report['sections'])) {
	foreach ($report['sections'] as $section) {
		print '<h3>'.dol_escape_htmltag($section['title']).'</h3>';
		$renderTable($section);
		print '<br>';
	}
} else {
	$renderTable($report);
}

if (!empty($report['comparison']) && is_array($report['comparison'])) {
	print '<div class="info">';
	print '<strong>'.$langs->trans('CompareWithPreviousPeriod').'</strong> : ';
	print dol_escape_htmltag($report['comparison']['label_previous']).' -> '.dol_escape_htmltag($report['comparison']['label_current']);
	print ' | '.$langs->trans('NbLines').': '.((int) $report['comparison']['rows_previous']).' -> '.((int) $report['comparison']['rows_current']);
	print ' (Delta '.((int) $report['comparison']['rows_delta']).')';
	print '</div>';
}

if (!empty($recentExports)) {
	print '<br><h3>'.$langs->trans('ExportHistory').'</h3>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('AuditUser').'</th><th>'.$langs->trans('ReportType').'</th><th>'.$langs->trans('OutputFormat').'</th><th>IP</th></tr>';
	foreach ($recentExports as $log) {
		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($log['tms'], 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($log['user']).'</td>';
		print '<td>'.dol_escape_htmltag($log['report_code']).'</td>';
		print '<td>'.dol_escape_htmltag($log['format']).'</td>';
		print '<td>'.dol_escape_htmltag($log['ip']).'</td>';
		print '</tr>';
	}
	print '</table></div>';
}

llxFooter();
$db->close();
