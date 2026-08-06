<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/bankaudit/class/bankauditreports.class.php
 * \ingroup bankaudit
 * \brief   Centralized reporting service (R01..R15) with exports/favorites/history.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/bankaudit/class/bankaudit.class.php');

/**
 * Reporting service for the BankAudit module.
 */
class BankAuditReports
{
	/** @var DoliDB */
	public $db;

	/** @var Translate */
	public $langs;

	/** @var User */
	public $user;

	/** @var Conf */
	public $conf;

	/**
	 * Constructor.
	 *
	 * @param DoliDB    $db    Database handler
	 * @param Translate $langs Lang object
	 * @param User      $user  Current user
	 * @param Conf      $conf  Global conf
	 */
	public function __construct($db, $langs, $user, $conf)
	{
		$this->db = $db;
		$this->langs = $langs;
		$this->user = $user;
		$this->conf = $conf;
	}

	/**
	 * Catalog of supported reports (R01..R15 + REVIEW).
	 *
	 * @return array
	 */
	public function getCatalog()
	{
		return array(
			'R01' => array('title' => 'R01 - '.$this->langs->trans('ReportBankJournal'), 'icon' => 'list', 'tip' => $this->langs->trans('ReportTipR01')),
			'R02' => array('title' => 'R02 - '.$this->langs->trans('TreasuryDashboardTitle'), 'icon' => 'dashboard', 'tip' => $this->langs->trans('ReportTipR02')),
			'R03' => array('title' => 'R03 - '.$this->langs->trans('ReportCashFlow'), 'icon' => 'object_stats', 'tip' => $this->langs->trans('ReportTipR03')),
			'R04' => array('title' => 'R04 - '.$this->langs->trans('ReportInternalTransfers'), 'icon' => 'object_payment', 'tip' => $this->langs->trans('ReportTipR04')),
			'R05' => array('title' => 'R05 - '.$this->langs->trans('ReportExchangeGainLoss'), 'icon' => 'object_currency', 'tip' => $this->langs->trans('ReportTipR05')),
			'R06' => array('title' => 'R06 - '.$this->langs->trans('RateHistoryTitle'), 'icon' => 'multicurrency', 'tip' => $this->langs->trans('ReportTipR06')),
			'R07' => array('title' => 'R07 - '.$this->langs->trans('ReportTreasuryByWarehouse'), 'icon' => 'stock', 'tip' => $this->langs->trans('ReportTipR07')),
			'R08' => array('title' => 'R08 - '.$this->langs->trans('ReportActivityByWarehouse'), 'icon' => 'stock', 'tip' => $this->langs->trans('ReportTipR08')),
			'R09' => array('title' => 'R09 - '.$this->langs->trans('ReportByCategory'), 'icon' => 'category', 'tip' => $this->langs->trans('ReportTipR09')),
			'R10' => array('title' => 'R10 - '.$this->langs->trans('ReportCategoryNvsN1'), 'icon' => 'compare', 'tip' => $this->langs->trans('ReportTipR10')),
			'R11' => array('title' => 'R11 - '.$this->langs->trans('BankAuditLogTitle'), 'icon' => 'technic', 'tip' => $this->langs->trans('ReportTipR11')),
			'R12' => array('title' => 'R12 - '.$this->langs->trans('AnomaliesTitle'), 'icon' => 'warning', 'tip' => $this->langs->trans('ReportTipR12')),
			'R13' => array('title' => 'R13 - '.$this->langs->trans('ReportReconciliation'), 'icon' => 'reconciliation', 'tip' => $this->langs->trans('ReportTipR13')),
			'R14' => array('title' => 'R14 - '.$this->langs->trans('ReportCashClosing'), 'icon' => 'payment', 'tip' => $this->langs->trans('ReportTipR14')),
			'R15' => array('title' => 'R15 - '.$this->langs->trans('ReportUserActivity'), 'icon' => 'user', 'tip' => $this->langs->trans('ReportTipR15')),
			'R16' => array('title' => 'R16 - '.$this->langs->trans('ReportDailyCashMatrix'), 'icon' => 'bank_account', 'tip' => $this->langs->trans('ReportTipR16')),
			'REVIEW' => array('title' => $this->langs->trans('ReportReviewMode'), 'icon' => 'object_report', 'tip' => $this->langs->trans('ReportTipReview')),
		);
	}

	/**
	 * Return true if report is in audit family.
	 *
	 * @param string $reportCode Report code
	 * @return bool
	 */
	public function isAuditReport($reportCode)
	{
		return in_array($reportCode, array('R11', 'R12', 'R15'));
	}

	/**
	 * Access check for screen and export.
	 *
	 * @param string $reportCode Report code
	 * @param string $format     screen|pdf|excel|csv
	 * @return void
	 */
	public function checkAccess($reportCode, $format)
	{
		$canReadReports = !empty($this->user->rights->bankaudit->reports->read) || !empty($this->user->rights->bankaudit->read);
		$canExportReports = !empty($this->user->rights->bankaudit->reports->export);
		$canReadAudit = !empty($this->user->rights->bankaudit->audit->read) || !empty($this->user->rights->bankaudit->read);

		if ($this->isAuditReport($reportCode)) {
			if (!$canReadAudit) {
				accessforbidden();
			}
		} else {
			if (!$canReadReports) {
				accessforbidden();
			}
		}

		if ($format !== 'screen') {
			if ($this->isAuditReport($reportCode)) {
				if (!$canReadAudit && !$canExportReports) {
					accessforbidden($this->langs->trans('ExportNotAllowed'));
				}
			} else {
				if (!$canExportReports) {
					accessforbidden($this->langs->trans('ExportNotAllowed'));
				}
			}
		}
	}

	/**
	 * Normalize filters from request values.
	 *
	 * @param array $in Raw filters
	 * @return array
	 */
	public function normalizeFilters($in)
	{
		$start = empty($in['date_start']) ? dol_mktime(0, 0, 0, 1, 1, (int) dol_print_date(dol_now(), '%Y')) : (int) $in['date_start'];
		$end = empty($in['date_end']) ? dol_now() : (int) $in['date_end'];

		if ($end < $start) {
			$tmp = $end;
			$end = $start;
			$start = $tmp;
		}

		$out = array(
			'date_start' => $start,
			'date_end' => $end,
			'fk_account' => (isset($in['fk_account']) && !is_array($in['fk_account'])) ? (int) $in['fk_account'] : 0,
			'fk_entrepot' => (int) (empty($in['fk_entrepot']) ? 0 : $in['fk_entrepot']),
			'fk_user' => (int) (empty($in['fk_user']) ? 0 : $in['fk_user']),
			'devise' => empty($in['devise']) ? '' : trim($in['devise']),
			'type_compte' => (int) (empty($in['type_compte']) ? 0 : $in['type_compte']),
			'granularity' => empty($in['granularity']) ? 'month' : $in['granularity'],
			'inter_dev' => !empty($in['inter_dev']) ? 1 : 0,
			'compare_prev' => !empty($in['compare_prev']) ? 1 : 0,
			'show_closed' => !empty($in['show_closed']) ? 1 : 0,
			'montant_min' => price2num(empty($in['montant_min']) ? 0 : $in['montant_min'], 'MU'),
			'montant_max' => price2num(empty($in['montant_max']) ? 0 : $in['montant_max'], 'MU'),
			'ecart_min' => price2num(empty($in['ecart_min']) ? 0 : $in['ecart_min'], 'MU'),
			'rappro' => isset($in['rappro']) ? (int) $in['rappro'] : -1,
			'action_filter' => empty($in['action_filter']) ? '' : $in['action_filter'],
			'object_type' => empty($in['object_type']) ? '' : $in['object_type'],
			'label_search' => empty($in['label_search']) ? '' : trim($in['label_search']),
			'report_annotation' => empty($in['report_annotation']) ? '' : trim($in['report_annotation']),
		);

		if (!in_array($out['granularity'], array('week', 'month', 'quarter', 'year', 'period'))) {
			$out['granularity'] = 'month';
		}

		// Multi-account selection (array of ids); falls back to the legacy single fk_account.
		$accs = array();
		if (isset($in['fk_accounts']) && is_array($in['fk_accounts'])) {
			foreach ($in['fk_accounts'] as $a) {
				$a = (int) $a;
				if ($a > 0) {
					$accs[$a] = $a;
				}
			}
		}
		if (empty($accs) && !empty($out['fk_account'])) {
			$accs[$out['fk_account']] = $out['fk_account'];
		}
		$out['fk_accounts'] = array_values($accs);
		$out['fk_account'] = (count($out['fk_accounts']) === 1) ? $out['fk_accounts'][0] : 0;

		return $out;
	}

	/**
	 * Build one report.
	 *
	 * @param string $reportCode Report code (R01..R15 or REVIEW)
	 * @param array  $f          Normalized filters
	 * @return array
	 */
	public function buildReport($reportCode, $f)
	{
		if ($reportCode === 'REVIEW') {
			return $this->buildReviewReport($f);
		}

		$catalog = $this->getCatalog();
		if (empty($catalog[$reportCode])) {
			$reportCode = 'R01';
		}

		$out = array(
			'code' => $reportCode,
			'title' => $catalog[$reportCode]['title'],
			'tip' => $catalog[$reportCode]['tip'],
			'columns' => array(),
			'rows' => array(),
			'totals' => array(),
			'filtersLabel' => $this->buildFiltersLabel($f),
			'sections' => array(),
		);

		switch ($reportCode) {
			case 'R01':
				$out = array_merge($out, $this->reportR01Journal($f));
				break;
			case 'R02':
				$out = array_merge($out, $this->reportR02Dashboard($f));
				break;
			case 'R03':
				$out = array_merge($out, $this->reportR03FluxPeriode($f));
				break;
			case 'R04':
				$out = array_merge($out, $this->reportR04Transfers($f));
				break;
			case 'R05':
				$out = array_merge($out, $this->reportR05FxGainLoss($f));
				break;
			case 'R06':
				$out = array_merge($out, $this->reportR06RateHistory($f));
				break;
			case 'R07':
				$out = array_merge($out, $this->reportR07TreasuryWarehouse($f));
				break;
			case 'R08':
				$out = array_merge($out, $this->reportR08ActivityWarehouse($f));
				break;
			case 'R09':
				$out = array_merge($out, $this->reportR09CategoryFlow($f));
				break;
			case 'R10':
				$out = array_merge($out, $this->reportR10CategoryCompare($f));
				break;
			case 'R11':
				$out = array_merge($out, $this->reportR11AuditLog($f));
				break;
			case 'R12':
				$out = array_merge($out, $this->reportR12Anomalies($f));
				break;
			case 'R13':
				$out = array_merge($out, $this->reportR13Reconciliation($f));
				break;
			case 'R14':
				$out = array_merge($out, $this->reportR14CashClosing($f));
				break;
			case 'R15':
				$out = array_merge($out, $this->reportR15UserActivity($f));
				break;
			case 'R16':
				$out = array_merge($out, $this->reportR16DailyMatrix($f));
				break;
		}

		if (!empty($f['compare_prev']) && !in_array($reportCode, array('R10', 'R11', 'R12', 'R15', 'R16'))) {
			$out['comparison'] = $this->buildComparisonSummary($reportCode, $f, $out);
		}

		return $out;
	}

	/**
	 * Build review report (global value-added: direction review mode).
	 *
	 * @param array $f Filters
	 * @return array
	 */
	private function buildReviewReport($f)
	{
		$codes = array('R02', 'R03', 'R07', 'R10');
		$sections = array();
		foreach ($codes as $code) {
			$sections[] = $this->buildReport($code, $f);
		}

		return array(
			'code' => 'REVIEW',
			'title' => $this->langs->trans('ReportReviewMode'),
			'tip' => $this->langs->trans('ReportTipReview'),
			'columns' => array(),
			'rows' => array(),
			'totals' => array(),
			'sections' => $sections,
			'filtersLabel' => $this->buildFiltersLabel($f),
		);
	}

	/**
	 * Build human readable filter summary.
	 *
	 * @param array $f Filters
	 * @return string
	 */
	public function buildFiltersLabel($f)
	{
		$parts = array();
		$parts[] = $this->langs->trans('ReportDateStart').': '.dol_print_date($f['date_start'], 'day');
		$parts[] = $this->langs->trans('ReportDateEnd').': '.dol_print_date($f['date_end'], 'day');
		if (!empty($f['fk_accounts'])) {
			$parts[] = $this->langs->trans('BankAccount').': '.$this->getAccountsDisplay($f['fk_accounts']);
		}
		if ($f['fk_entrepot'] > 0) {
			$parts[] = $this->langs->trans('Warehouse').': '.$this->getWarehouseRef($f['fk_entrepot']);
		}
		if (!empty($f['devise'])) {
			$parts[] = $this->langs->trans('Currency').': '.$f['devise'];
		}
		return implode(' | ', $parts);
	}

	/**
	 * R01 - Journal de caisse / bancaire.
	 */
	private function reportR01Journal($f)
	{
		$hasBenef = $this->columnExists('bank_extrafields', 'beneficiaire');
		$cols = array('ID', $this->langs->trans('DateOperationShort'), $this->langs->trans('DateValueShort'), $this->langs->trans('Type'), $this->langs->trans('AccountLabel'), $this->langs->trans('Label'));
		if ($hasBenef) {
			$cols[] = $this->langs->trans('BankAuditBeneficiary');
		}
		$cols = array_merge($cols, array($this->langs->trans('Categories'), $this->langs->trans('TotalIn'), $this->langs->trans('TotalOut'), $this->langs->trans('NetBalance'), 'R'));
		$rows = array();
		$totin = 0;
		$totout = 0;
		$nbrappro = 0;
		$nb = 0;

		$openbal = 0;
		if (!empty($f['fk_accounts'])) {
			$sqlopen = "SELECT COALESCE(SUM(amount),0) as bal FROM ".MAIN_DB_PREFIX."bank";
			$sqlopen .= " WHERE fk_account IN (".$this->accountIdsCsv($f).")";
			$sqlopen .= " AND dateo < '".$this->db->idate($f['date_start'])."'";
			$resopen = $this->db->query($sqlopen);
			if ($resopen && ($objopen = $this->db->fetch_object($resopen))) {
				$openbal = (float) $objopen->bal;
			}
		}
		$running = $openbal;

		$sql = "SELECT b.rowid, b.dateo, b.datev, b.fk_type, b.label, b.amount, b.rappro, b.fk_account,";
		$sql .= " ba.ref as account_ref, ba.currency_code as cur,";
		if ($hasBenef) {
			$sql .= " ef.beneficiaire as beneficiaire,";
		}
		$sql .= " GROUP_CONCAT(c.label ORDER BY c.label SEPARATOR ', ') as categories";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		if ($hasBenef) {
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_extrafields as ef ON ef.fk_object = b.rowid";
		}
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_class as bc ON bc.lineid = b.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = bc.fk_categ AND c.type = 7";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sql .= $this->accountFilterSql($f, 'b.fk_account');
		if (!empty($f['label_search'])) {
			$sql .= " AND b.label LIKE '%".$this->db->escape($f['label_search'])."%'";
		}
		if ($f['montant_min'] > 0) {
			$sql .= " AND ABS(b.amount) >= ".((float) $f['montant_min']);
		}
		if ($f['montant_max'] > 0) {
			$sql .= " AND ABS(b.amount) <= ".((float) $f['montant_max']);
		}
		if ($f['rappro'] >= 0) {
			$sql .= " AND b.rappro = ".((int) $f['rappro']);
		}
		$sql .= " GROUP BY b.rowid, b.dateo, b.datev, b.fk_type, b.label, b.amount, b.rappro, b.fk_account, ba.ref, ba.currency_code";
		if ($hasBenef) {
			$sql .= ", ef.beneficiaire";
		}
		$sql .= " ORDER BY b.dateo ASC, b.rowid ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$nb++;
				$in = ((float) $obj->amount > 0) ? (float) $obj->amount : 0;
				$out = ((float) $obj->amount < 0) ? abs((float) $obj->amount) : 0;
				$totin += $in;
				$totout += $out;
				$running += (float) $obj->amount;
				if ((int) $obj->rappro === 1) {
					$nbrappro++;
				}
				$row = array(
					'<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $obj->rowid).'">'.((int) $obj->rowid).'</a>',
					dol_print_date($this->db->jdate($obj->dateo), 'day'),
					dol_print_date($this->db->jdate($obj->datev), 'day'),
					dol_escape_htmltag((string) $obj->fk_type),
					dol_escape_htmltag($obj->account_ref),
					dol_escape_htmltag($obj->label),
				);
				if ($hasBenef) {
					$row[] = dol_escape_htmltag((string) (isset($obj->beneficiaire) ? $obj->beneficiaire : ''));
				}
				$row[] = dol_escape_htmltag((string) $obj->categories);
				$row[] = $in > 0 ? price($in, 0, $this->langs, 1, -1, -1, $obj->cur) : '-';
				$row[] = $out > 0 ? price($out, 0, $this->langs, 1, -1, -1, $obj->cur) : '-';
				$row[] = price($running, 0, $this->langs, 1, -1, -1, $obj->cur);
				$row[] = ((int) $obj->rappro ? '&#10003;' : '&mdash;');
				$rows[] = $row;
			}
		}

		$currency = $this->detectReportCurrency($rows);
		$closbal = $openbal + $totin - $totout;
		$totals = array(
			$this->langs->trans('OpeningBalance') => price($openbal, 0, $this->langs, 1, -1, -1, $currency),
			$this->langs->trans('TotalIn') => price($totin, 0, $this->langs, 1, -1, -1, $currency),
			$this->langs->trans('TotalOut') => price($totout, 0, $this->langs, 1, -1, -1, $currency),
			$this->langs->trans('NetBalance') => price($totin - $totout, 0, $this->langs, 1, -1, -1, $currency),
			$this->langs->trans('ClosingBalance') => price($closbal, 0, $this->langs, 1, -1, -1, $currency),
			$this->langs->trans('ReconciliationRate') => ($nb > 0 ? price((100 * $nbrappro / $nb), 0, $this->langs, 0, 1, -1, '').'%' : '0%'),
		);

		return array('columns' => $cols, 'rows' => $rows, 'totals' => $totals);
	}

	/**
	 * R02 - Dashboard trésorerie consolidé.
	 */
	private function reportR02Dashboard($f)
	{
		$mainCurrency = $this->conf->currency;
		$cols = array($this->langs->trans('BankAccount'), $this->langs->trans('Type'), $this->langs->trans('Currency'), $this->langs->trans('NativeBalance'), $this->langs->trans('EquivalentMainCurrency', $mainCurrency), $this->langs->trans('Trend7Days'), $this->langs->trans('MinBalance'), $this->langs->trans('Warehouse'), $this->langs->trans('LastMovement'));
		$rows = array();

		$totUsd = 0;
		$totBank = 0;
		$totCash = 0;
		$below = 0;
		$nb = 0;

		$sql = "SELECT ba.rowid, ba.ref, ba.label, ba.courant, ba.currency_code, ba.clos,";
		$sql .= " COALESCE(SUM(b.amount),0) as solde_natif,";
		$sql .= " COALESCE(cfg.seuil_min, 0) as seuil_min,";
		$sql .= " ef.warehouse as fk_entrepot, e.ref as entrepot_ref,";
		$sql .= " (SELECT COALESCE(SUM(b2.amount),0) FROM ".MAIN_DB_PREFIX."bank as b2";
		$sql .= "   WHERE b2.fk_account = ba.rowid AND b2.dateo <= '".$this->db->idate($f['date_end'] - 7 * 86400)."') as solde_j7,";
		$sql .= " (SELECT MAX(b3.dateo) FROM ".MAIN_DB_PREFIX."bank as b3 WHERE b3.fk_account = ba.rowid) as last_mvt";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON b.fk_account = ba.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_dashboard_config as cfg ON cfg.fk_account = ba.rowid AND cfg.entity = ba.entity";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.fk_object = ba.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ef.warehouse";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= $this->accountFilterSql($f, 'ba.rowid');
		if (!$f['show_closed']) {
			$sql .= " AND ba.clos = 0";
		}
		if ($f['type_compte'] > 0) {
			$sql .= " AND ba.courant = ".((int) $f['type_compte']);
		}
		if ($f['fk_entrepot'] > 0) {
			$sql .= " AND ef.warehouse = ".((int) $f['fk_entrepot']);
		}
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		$sql .= " GROUP BY ba.rowid, ba.ref, ba.label, ba.courant, ba.currency_code, ba.clos, cfg.seuil_min, ef.warehouse, e.ref";
		$sql .= " ORDER BY ba.courant ASC, ba.ref ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$nb++;
				$native = (float) $obj->solde_natif;
				$rate = BankAudit::getConversionRate($this->db, $obj->currency_code, $mainCurrency);
				$usd = ($rate === null) ? 0 : ($native * $rate);
				$j7 = ($rate === null) ? 0 : (((float) $obj->solde_j7) * $rate);
				$trend = $usd - $j7;
				$totUsd += $usd;

				if ((int) $obj->courant === 2) {
					$totCash += $usd;
				} else {
					$totBank += $usd;
				}
				if ($usd < (float) $obj->seuil_min) {
					$below++;
				}

				$typeLabel = ((int) $obj->courant === 2) ? $this->langs->trans('NbCashAccounts') : $this->langs->trans('NbBankAccounts');
				$rows[] = array(
					dol_escape_htmltag($obj->ref),
					dol_escape_htmltag($typeLabel),
					dol_escape_htmltag($obj->currency_code),
					price($native, 0, $this->langs, 1, -1, -1, $obj->currency_code),
					price($usd, 0, $this->langs, 1, -1, -1, $mainCurrency),
					price($trend, 0, $this->langs, 1, -1, -1, $mainCurrency),
					price((float) $obj->seuil_min, 0, $this->langs, 1, -1, -1, $mainCurrency),
					dol_escape_htmltag((string) $obj->entrepot_ref),
					empty($obj->last_mvt) ? '-' : dol_print_date($this->db->jdate($obj->last_mvt), 'dayhour'),
				);
			}
		}

		$totals = array(
			$this->langs->trans('TotalConsolidated') => price($totUsd, 0, $this->langs, 1, -1, -1, $mainCurrency),
			$this->langs->trans('NbBankAccounts') => price($totBank, 0, $this->langs, 1, -1, -1, $mainCurrency),
			$this->langs->trans('NbCashAccounts') => price($totCash, 0, $this->langs, 1, -1, -1, $mainCurrency),
			$this->langs->trans('TreasuryAlerts') => (string) $below,
			$this->langs->trans('NbLines') => (string) $nb,
		);

		return array('columns' => $cols, 'rows' => $rows, 'totals' => $totals);
	}

	/**
	 * R03 - Flux net par période.
	 */
	private function reportR03FluxPeriode($f)
	{
		$cols = array($this->langs->trans('BankAccount'), $this->langs->trans('ReportPeriod'), $this->langs->trans('TotalIn'), $this->langs->trans('TotalOut'), $this->langs->trans('NetBalance'), $this->langs->trans('NbOperations'));
		$rows = array();

		$groupExpr = "DATE_FORMAT(b.dateo, '%Y-%m')";
		if ($f['granularity'] === 'week') {
			$groupExpr = "DATE_FORMAT(b.dateo, '%x-S%v')";
		} elseif ($f['granularity'] === 'quarter') {
			$groupExpr = "CONCAT(YEAR(b.dateo), '-T', QUARTER(b.dateo))";
		} elseif ($f['granularity'] === 'year') {
			$groupExpr = "YEAR(b.dateo)";
		} elseif ($f['granularity'] === 'period') {
			$groupExpr = "'".$this->db->escape($this->langs->trans('SelectedPeriod'))."'";
		}

		$sql = "SELECT ba.ref, ba.label, ba.currency_code as cur, ".$groupExpr." as periode,";
		$sql .= " SUM(CASE WHEN b.amount > 0 THEN b.amount ELSE 0 END) as total_in,";
		$sql .= " SUM(CASE WHEN b.amount < 0 THEN -b.amount ELSE 0 END) as total_out,";
		$sql .= " SUM(b.amount) as flux_net, COUNT(b.rowid) as nbop";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.fk_object = ba.rowid";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		if ($f['type_compte'] > 0) {
			$sql .= " AND ba.courant = ".((int) $f['type_compte']);
		}
		$sql .= $this->accountFilterSql($f, 'ba.rowid');
		if ($f['fk_entrepot'] > 0) {
			$sql .= " AND ef.warehouse = ".((int) $f['fk_entrepot']);
		}
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		$sql .= " GROUP BY ba.rowid, ba.ref, ba.label, ba.currency_code, ".$groupExpr;
		$sql .= " ORDER BY ba.ref ASC, periode ASC";

		$resql = $this->db->query($sql);
		$sumIn = 0;
		$sumOut = 0;
		$sumNet = 0;
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$sumIn += (float) $obj->total_in;
				$sumOut += (float) $obj->total_out;
				$sumNet += (float) $obj->flux_net;
				$rows[] = array(
					dol_escape_htmltag($obj->ref),
					dol_escape_htmltag((string) $obj->periode),
					price((float) $obj->total_in, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $obj->total_out, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $obj->flux_net, 0, $this->langs, 1, -1, -1, $obj->cur),
					(int) $obj->nbop,
				);
			}
		}

		$totals = array(
			$this->langs->trans('TotalIn') => price($sumIn),
			$this->langs->trans('TotalOut') => price($sumOut),
			$this->langs->trans('NetBalance') => price($sumNet),
		);

		return array('columns' => $cols, 'rows' => $rows, 'totals' => $totals);
	}

	/**
	 * R04 - Virements inter-comptes.
	 */
	private function reportR04Transfers($f)
	{
		$cols = array('ID src', 'ID dst', $this->langs->trans('DateOperationShort'), $this->langs->trans('From'), $this->langs->trans('to'), $this->langs->trans('Currency').' src', $this->langs->trans('Amount').' src', $this->langs->trans('Currency').' dst', $this->langs->trans('Amount').' dst', $this->langs->trans('RateVariation'), 'Ecart %', $this->langs->trans('Label'));
		$rows = array();

		$sql = "SELECT b1.rowid as idsrc, b2.rowid as iddst, b1.dateo, b1.label,";
		$sql .= " ba1.ref as src_ref, ba2.ref as dst_ref,";
		$sql .= " ba1.currency_code as src_cur, ba2.currency_code as dst_cur,";
		$sql .= " ABS(b1.amount) as src_amount, b2.amount as dst_amount";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b1";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_url as bu ON bu.fk_bank = b1.rowid AND bu.type = 'banktransfert'";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank as b2 ON b2.rowid = bu.url_id";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba1 ON ba1.rowid = b1.fk_account";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba2 ON ba2.rowid = b2.fk_account";
		$sql .= " WHERE ba1.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b1.amount < 0";
		$sql .= " AND b1.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		if (!empty($f['fk_accounts'])) {
			$ids = $this->accountIdsCsv($f);
			$sql .= " AND (b1.fk_account IN (".$ids.") OR b2.fk_account IN (".$ids."))";
		}
		if ($f['inter_dev']) {
			$sql .= " AND ba1.currency_code <> ba2.currency_code";
		}
		if ($f['montant_min'] > 0) {
			$sql .= " AND ABS(b1.amount) >= ".((float) $f['montant_min']);
		}
		$sql .= " ORDER BY b1.dateo DESC, b1.rowid DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$effective = ((float) $obj->src_amount > 0) ? ((float) $obj->dst_amount / (float) $obj->src_amount) : 0;
				$system = BankAudit::getConversionRate($this->db, $obj->src_cur, $obj->dst_cur);
				$gap = null;
				if ($system !== null && (float) $system != 0) {
					$gap = abs($effective - (float) $system) / abs((float) $system) * 100;
				}
				if ($f['ecart_min'] > 0 && $gap !== null && $gap < $f['ecart_min']) {
					continue;
				}
				$rows[] = array(
					(int) $obj->idsrc,
					(int) $obj->iddst,
					dol_print_date($this->db->jdate($obj->dateo), 'day'),
					dol_escape_htmltag($obj->src_ref),
					dol_escape_htmltag($obj->dst_ref),
					dol_escape_htmltag($obj->src_cur),
					price((float) $obj->src_amount, 0, $this->langs, 1, -1, -1, $obj->src_cur),
					dol_escape_htmltag($obj->dst_cur),
					price((float) $obj->dst_amount, 0, $this->langs, 1, -1, -1, $obj->dst_cur),
					price($effective, 0, $this->langs, 1, 6, -1, ''),
					($gap === null ? '-' : price($gap, 0, $this->langs, 1, 2, -1, '').'%'),
					dol_escape_htmltag($obj->label),
				);
			}
		}

		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R05 - Gains/pertes de change (approximation opérationnelle).
	 */
	private function reportR05FxGainLoss($f)
	{
		$cols = array($this->langs->trans('Date'), $this->langs->trans('BankAccount'), $this->langs->trans('Currency'), $this->langs->trans('Amount'), $this->langs->trans('RateAtOperation'), $this->langs->trans('CurrentRate'), $this->langs->trans('GainLoss'));
		$rows = array();
		$total = 0;
		$main = $this->conf->currency;

		$sql = "SELECT b.rowid, b.dateo, b.amount, ba.ref, ba.currency_code";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND ba.currency_code <> '".$this->db->escape($main)."'";
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sql .= $this->accountFilterSql($f, 'ba.rowid');
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		$sql .= " ORDER BY b.dateo ASC, b.rowid ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$curRate = BankAudit::getConversionRate($this->db, $obj->currency_code, $main);
				$opRate = $this->findRateAtDate($obj->currency_code, $main, $this->db->jdate($obj->dateo));
				if ($curRate === null || $opRate === null) {
					continue;
				}
				$gain = ((float) $obj->amount * ((float) $curRate - (float) $opRate));
				$total += $gain;
				$rows[] = array(
					dol_print_date($this->db->jdate($obj->dateo), 'day'),
					dol_escape_htmltag($obj->ref),
					dol_escape_htmltag($obj->currency_code),
					price((float) $obj->amount, 0, $this->langs, 1, -1, -1, $obj->currency_code),
					price((float) $opRate, 0, $this->langs, 1, 6, -1, ''),
					price((float) $curRate, 0, $this->langs, 1, 6, -1, ''),
					price($gain, 0, $this->langs, 1, -1, -1, $main),
				);
			}
		}

		return array('columns' => $cols, 'rows' => $rows, 'totals' => array($this->langs->trans('GainLoss') => price($total, 0, $this->langs, 1, -1, -1, $main)));
	}

	/**
	 * R06 - Historique des taux.
	 */
	private function reportR06RateHistory($f)
	{
		$cols = array($this->langs->trans('Date'), $this->langs->trans('CurrencyPair'), $this->langs->trans('OldRate'), $this->langs->trans('NewRate'), $this->langs->trans('RateVariation'), $this->langs->trans('AuditUser'), $this->langs->trans('Source'));
		$rows = array();

		$sql = "SELECT h.tms, h.code_from, h.code_to, h.rate_old, h.rate_new, h.source, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_rate_history as h";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = h.fk_user";
		$sql .= " WHERE h.entity = ".((int) $this->conf->entity);
		$sql .= " AND h.tms BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sql .= " ORDER BY h.tms DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$old = (float) $obj->rate_old;
				$new = (float) $obj->rate_new;
				$var = ($old != 0) ? (($new - $old) / abs($old) * 100) : 0;
				$rows[] = array(
					dol_print_date($this->db->jdate($obj->tms), 'dayhour'),
					dol_escape_htmltag($obj->code_from.'/'.$obj->code_to),
					price($old, 0, $this->langs, 1, 6, -1, ''),
					price($new, 0, $this->langs, 1, 6, -1, ''),
					price($var, 0, $this->langs, 1, 2, -1, '').'%',
					dol_escape_htmltag((string) $obj->login),
					dol_escape_htmltag((string) $obj->source),
				);
			}
		}

		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R07 - Trésorerie par entrepôt.
	 */
	private function reportR07TreasuryWarehouse($f)
	{
		$cols = array($this->langs->trans('Warehouse'), $this->langs->trans('BankAccount'), $this->langs->trans('Currency'), $this->langs->trans('Type'), $this->langs->trans('NativeBalance'), $this->langs->trans('EquivalentMainCurrency', $this->conf->currency), $this->langs->trans('LastMovement'));
		$rows = array();

		$sql = "SELECT e.rowid as e_id, e.ref as entrepot_ref, ba.rowid as ba_id, ba.ref as compte_ref, ba.currency_code as cur, ba.courant,";
		$sql .= " COALESCE(SUM(b.amount),0) as solde_natif,";
		$sql .= " (SELECT MAX(b2.dateo) FROM ".MAIN_DB_PREFIX."bank as b2 WHERE b2.fk_account = ba.rowid) as last_mvt";
		$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.warehouse = e.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = ef.fk_object";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON b.fk_account = ba.rowid";
		$sql .= " WHERE e.entity = ".((int) $this->conf->entity);
		if ($f['fk_entrepot'] > 0) {
			$sql .= " AND e.rowid = ".((int) $f['fk_entrepot']);
		}
		if ($f['type_compte'] > 0) {
			$sql .= " AND ba.courant = ".((int) $f['type_compte']);
		}
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		if (!$f['show_closed']) {
			$sql .= " AND ba.clos = 0";
		}
		$sql .= " GROUP BY e.rowid, e.ref, ba.rowid, ba.ref, ba.currency_code, ba.courant";
		$sql .= " ORDER BY e.ref ASC, ba.ref ASC";

		$totUsd = 0;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rate = BankAudit::getConversionRate($this->db, $obj->cur, $this->conf->currency);
				$usd = ($rate === null) ? 0 : ((float) $obj->solde_natif * $rate);
				$totUsd += $usd;
				$rows[] = array(
					dol_escape_htmltag($obj->entrepot_ref),
					dol_escape_htmltag($obj->compte_ref),
					dol_escape_htmltag($obj->cur),
					((int) $obj->courant === 2 ? $this->langs->trans('NbCashAccounts') : $this->langs->trans('NbBankAccounts')),
					price((float) $obj->solde_natif, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $usd, 0, $this->langs, 1, -1, -1, $this->conf->currency),
					empty($obj->last_mvt) ? '-' : dol_print_date($this->db->jdate($obj->last_mvt), 'dayhour'),
				);
			}
		}

		return array('columns' => $cols, 'rows' => $rows, 'totals' => array($this->langs->trans('TotalConsolidated') => price($totUsd, 0, $this->langs, 1, -1, -1, $this->conf->currency)));
	}

	/**
	 * R08 - Activité financière par entrepôt.
	 */
	private function reportR08ActivityWarehouse($f)
	{
		$cols = array($this->langs->trans('Warehouse'), $this->langs->trans('Currency'), $this->langs->trans('TotalIn'), $this->langs->trans('TotalOut'), $this->langs->trans('NetBalance'), $this->langs->trans('NbOperations'));
		$rows = array();
		$sql = "SELECT e.ref as entrepot_ref, ba.currency_code as cur,";
		$sql .= " SUM(CASE WHEN b.amount > 0 THEN b.amount ELSE 0 END) as total_in,";
		$sql .= " SUM(CASE WHEN b.amount < 0 THEN -b.amount ELSE 0 END) as total_out,";
		$sql .= " SUM(b.amount) as flux_net, COUNT(b.rowid) as nbop";
		$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.warehouse = e.rowid";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = ef.fk_object";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank as b ON b.fk_account = ba.rowid";
		$sql .= " WHERE e.entity = ".((int) $this->conf->entity);
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		if ($f['fk_entrepot'] > 0) {
			$sql .= " AND e.rowid = ".((int) $f['fk_entrepot']);
		}
		if ($f['type_compte'] > 0) {
			$sql .= " AND ba.courant = ".((int) $f['type_compte']);
		}
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		$sql .= " GROUP BY e.rowid, e.ref, ba.currency_code";
		$sql .= " ORDER BY e.ref ASC, ba.currency_code ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = array(
					dol_escape_htmltag($obj->entrepot_ref),
					dol_escape_htmltag($obj->cur),
					price((float) $obj->total_in, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $obj->total_out, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $obj->flux_net, 0, $this->langs, 1, -1, -1, $obj->cur),
					(int) $obj->nbop,
				);
			}
		}
		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R09 - Flux par catégorie.
	 */
	private function reportR09CategoryFlow($f)
	{
		$cols = array($this->langs->trans('Category'), $this->langs->trans('Currency'), $this->langs->trans('TotalIn'), $this->langs->trans('TotalOut'), $this->langs->trans('NetBalance'), $this->langs->trans('NbOperations'));
		$rows = array();
		$sql = "SELECT COALESCE(c.label, '".$this->db->escape($this->langs->trans('Undefined'))."') as cat, ba.currency_code as cur,";
		$sql .= " SUM(CASE WHEN b.amount > 0 THEN b.amount ELSE 0 END) as total_in,";
		$sql .= " SUM(CASE WHEN b.amount < 0 THEN -b.amount ELSE 0 END) as total_out,";
		$sql .= " SUM(b.amount) as flux_net, COUNT(b.rowid) as nbop";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_class as bc ON bc.lineid = b.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = bc.fk_categ AND c.type = 7";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.fk_object = ba.rowid";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sql .= $this->accountFilterSql($f, 'ba.rowid');
		if ($f['fk_entrepot'] > 0) {
			$sql .= " AND ef.warehouse = ".((int) $f['fk_entrepot']);
		}
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		$sql .= " GROUP BY COALESCE(c.label, '".$this->db->escape($this->langs->trans('Undefined'))."'), ba.currency_code";
		$sql .= " ORDER BY ABS(SUM(b.amount)) DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = array(
					dol_escape_htmltag($obj->cat),
					dol_escape_htmltag($obj->cur),
					price((float) $obj->total_in, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $obj->total_out, 0, $this->langs, 1, -1, -1, $obj->cur),
					price((float) $obj->flux_net, 0, $this->langs, 1, -1, -1, $obj->cur),
					(int) $obj->nbop,
				);
			}
		}
		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R10 - Comparatif catégories N vs N-1.
	 */
	private function reportR10CategoryCompare($f)
	{
		$cols = array($this->langs->trans('Category'), $this->langs->trans('PeriodN'), $this->langs->trans('PeriodN1'), $this->langs->trans('Gap'), $this->langs->trans('RateVariation'));
		$rows = array();

		$len = $f['date_end'] - $f['date_start'];
		$n1Start = $f['date_start'] - $len - 86400;
		$n1End = $f['date_end'] - $len - 86400;

		$current = $this->fetchCategoryNet($f['date_start'], $f['date_end'], $f);
		$prev = $this->fetchCategoryNet($n1Start, $n1End, $f);
		$allCats = array_unique(array_merge(array_keys($current), array_keys($prev)));
		sort($allCats);

		foreach ($allCats as $cat) {
			$n = isset($current[$cat]) ? (float) $current[$cat] : 0;
			$p = isset($prev[$cat]) ? (float) $prev[$cat] : 0;
			$gap = $n - $p;
			$var = ($p == 0) ? null : ($gap / abs($p) * 100);
			$rows[] = array(
				dol_escape_htmltag($cat),
				price($n),
				price($p),
				price($gap),
				($var === null ? '-' : price($var, 0, $this->langs, 1, 1, -1, '').'%'),
			);
		}

		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R11 - Journal d'audit.
	 */
	private function reportR11AuditLog($f)
	{
		$cols = array($this->langs->trans('Date'), $this->langs->trans('AuditUser'), $this->langs->trans('Type'), $this->langs->trans('AuditAction'), $this->langs->trans('Ref'), $this->langs->trans('AuditField'), $this->langs->trans('AuditOldValue'), $this->langs->trans('AuditNewValue'), 'IP');
		$rows = array();
		$sql = "SELECT l.tms, u.login, l.object_type, l.action, l.object_id, l.field_name, l.old_value, l.new_value, l.ip";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_audit_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity = ".((int) $this->conf->entity);
		$sql .= " AND l.tms BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		if (!empty($f['object_type'])) {
			$sql .= " AND l.object_type = '".$this->db->escape($f['object_type'])."'";
		}
		if (!empty($f['action_filter'])) {
			$sql .= " AND l.action = '".$this->db->escape($f['action_filter'])."'";
		}
		if ($f['fk_user'] > 0) {
			$sql .= " AND l.fk_user = ".((int) $f['fk_user']);
		}
		$sql .= " ORDER BY l.tms DESC, l.rowid DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = array(
					dol_print_date($this->db->jdate($obj->tms), 'dayhour'),
					dol_escape_htmltag((string) $obj->login),
					dol_escape_htmltag((string) $obj->object_type),
					dol_escape_htmltag((string) $obj->action),
					dol_escape_htmltag((string) $obj->object_id),
					dol_escape_htmltag((string) $obj->field_name),
					dol_escape_htmltag(dol_trunc((string) $obj->old_value, 60)),
					dol_escape_htmltag(dol_trunc((string) $obj->new_value, 60)),
					dol_escape_htmltag((string) $obj->ip),
				);
			}
		}
		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R12 - Anomalies.
	 */
	private function reportR12Anomalies($f)
	{
		$cols = array($this->langs->trans('Date'), $this->langs->trans('AnomalyRule'), $this->langs->trans('BankAccount'), $this->langs->trans('AnomalyDetail'), $this->langs->trans('Amount'), $this->langs->trans('Status'), $this->langs->trans('AuditUser'));
		$rows = array();
		$sql = "SELECT l.tms, l.rule_code, l.detail, l.amount, l.statut, ba.ref as account_ref, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_anomaly_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = l.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity = ".((int) $this->conf->entity);
		$sql .= " AND l.tms BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sql .= " ORDER BY l.tms DESC, l.rowid DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$st = $this->langs->trans('AnomalyStatusNew');
				if ((string) $obj->statut === 'ack') {
					$st = $this->langs->trans('AnomalyStatusAck');
				} elseif ((string) $obj->statut === 'false_positive') {
					$st = $this->langs->trans('AnomalyStatusFalsePositive');
				}
				$rows[] = array(
					dol_print_date($this->db->jdate($obj->tms), 'dayhour'),
					dol_escape_htmltag((string) $obj->rule_code),
					dol_escape_htmltag((string) $obj->account_ref),
					dol_escape_htmltag((string) $obj->detail),
					price((float) $obj->amount),
					dol_escape_htmltag($st),
					dol_escape_htmltag((string) $obj->login),
				);
			}
		}
		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R13 - Rapprochement bancaire.
	 */
	private function reportR13Reconciliation($f)
	{
		$cols = array($this->langs->trans('BankAccount'), $this->langs->trans('Currency'), $this->langs->trans('NbOperations'), $this->langs->trans('Reconciled'), $this->langs->trans('Unreconciled'), $this->langs->trans('ReconciliationRate'));
		$rows = array();
		$sql = "SELECT ba.rowid, ba.ref, ba.currency_code as cur, COUNT(b.rowid) as nb,";
		$sql .= " SUM(CASE WHEN b.rappro = 1 THEN 1 ELSE 0 END) as nb_r";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank as b ON b.fk_account = ba.rowid";
		$sql .= "   AND b.dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= $this->accountFilterSql($f, 'ba.rowid');
		$sql .= " GROUP BY ba.rowid, ba.ref, ba.currency_code";
		$sql .= " ORDER BY ba.ref ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$un = (int) $obj->nb - (int) $obj->nb_r;
				$rate = ((int) $obj->nb > 0) ? (100.0 * (int) $obj->nb_r / (int) $obj->nb) : 0;
				$rows[] = array(
					dol_escape_htmltag($obj->ref),
					dol_escape_htmltag($obj->cur),
					(int) $obj->nb,
					(int) $obj->nb_r,
					$un,
					price($rate, 0, $this->langs, 1, 1, -1, '').'%',
				);
			}
		}
		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R14 - Clôture de caisse journalière.
	 */
	private function reportR14CashClosing($f)
	{
		$rowsData = $this->reportR01Journal($f);
		$rowsData['title_suffix'] = $this->langs->trans('CashClosing');
		return $rowsData;
	}

	/**
	 * R15 - Activité par utilisateur.
	 */
	private function reportR15UserActivity($f)
	{
		$cols = array($this->langs->trans('AuditUser'), $this->langs->trans('AuditAction'), $this->langs->trans('NbOperations'), $this->langs->trans('FirstUseDate'), $this->langs->trans('LastUseDate'));
		$rows = array();
		$sql = "SELECT u.login, l.action, COUNT(l.rowid) as nb, MIN(l.tms) as min_t, MAX(l.tms) as max_t";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_audit_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity = ".((int) $this->conf->entity);
		$sql .= " AND l.tms BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		if (!empty($f['action_filter'])) {
			$sql .= " AND l.action = '".$this->db->escape($f['action_filter'])."'";
		}
		if ($f['fk_user'] > 0) {
			$sql .= " AND l.fk_user = ".((int) $f['fk_user']);
		}
		$sql .= " GROUP BY u.login, l.action";
		$sql .= " ORDER BY nb DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = array(
					dol_escape_htmltag((string) $obj->login),
					dol_escape_htmltag((string) $obj->action),
					(int) $obj->nb,
					dol_print_date($this->db->jdate($obj->min_t), 'dayhour'),
					dol_print_date($this->db->jdate($obj->max_t), 'dayhour'),
				);
			}
		}
		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * R16 - Daily multi-account cash matrix.
	 * Rows = each day of the period. Per account: inflow/outflow (native currency).
	 * Then daily total flows converted to the main currency, the consolidated end-of-day
	 * balance per currency, and the equivalent in the main currency. A final TOTAL row sums
	 * the period flows and shows the closing balances.
	 *
	 * @param array $f Filters
	 * @return array
	 */
	private function reportR16DailyMatrix($f)
	{
		$main = !empty($this->conf->currency) ? $this->conf->currency : 'USD';
		$inLabel = $this->langs->trans('BankAuditInflow');
		$outLabel = $this->langs->trans('BankAuditOutflow');

		// 1) Accounts in scope (respecting the shared filters)
		$accounts = array();
		$sqlA = "SELECT ba.rowid, ba.ref, ba.currency_code as cur";
		$sqlA .= " FROM ".MAIN_DB_PREFIX."bank_account as ba";
		$sqlA .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.fk_object = ba.rowid";
		$sqlA .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		if (!$f['show_closed']) {
			$sqlA .= " AND ba.clos = 0";
		}
		if ($f['type_compte'] > 0) {
			$sqlA .= " AND ba.courant = ".((int) $f['type_compte']);
		}
		if (!empty($f['devise'])) {
			$sqlA .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		if ($f['fk_entrepot'] > 0) {
			$sqlA .= " AND ef.warehouse = ".((int) $f['fk_entrepot']);
		}
		$sqlA .= $this->accountFilterSql($f, 'ba.rowid');
		$sqlA .= " ORDER BY ba.ref ASC";
		$resA = $this->db->query($sqlA);
		if ($resA) {
			while ($oa = $this->db->fetch_object($resA)) {
				$accounts[(int) $oa->rowid] = array('ref' => $oa->ref, 'cur' => ($oa->cur ? $oa->cur : $main));
			}
		}

		// Distinct currencies present + the secondary currency used for the daily "Taux" column
		$currencies = array();
		foreach ($accounts as $a) {
			$currencies[$a['cur']] = $a['cur'];
		}
		$currencies = array_keys($currencies);
		sort($currencies);
		$secCur = '';
		foreach ($currencies as $cur) {
			if ($cur !== $main) {
				$secCur = $cur;
				break;
			}
		}

		// Current conversion rates (fallback) + historical rate timelines (cur -> main)
		$currentRate = array();
		$timeline = array();
		foreach ($currencies as $cur) {
			if ($cur === $main) {
				$currentRate[$cur] = 1.0;
				$timeline[$cur] = array();
			} else {
				$cr = BankAudit::getConversionRate($this->db, $cur, $main);
				$currentRate[$cur] = ($cr === null) ? null : (float) $cr;
				$timeline[$cur] = $this->loadRateTimeline($cur, $main);
			}
		}

		// Columns: Date | Taux | (per account) In/Out | Total In/Out (main) | Balance per currency | Equivalent main
		$tauxHeader = $this->langs->trans('BankAuditRate');
		if ($secCur !== '') {
			$tauxHeader .= ' ('.$secCur.'/'.$main.')';
		}
		$cols = array($this->langs->trans('Date'), $tauxHeader);
		foreach ($accounts as $a) {
			$cols[] = $a['ref'].' '.$inLabel;
			$cols[] = $a['ref'].' '.$outLabel;
		}
		$cols[] = $this->langs->trans('TotalIn').' ('.$main.')';
		$cols[] = $this->langs->trans('TotalOut').' ('.$main.')';
		foreach ($currencies as $cur) {
			$cols[] = $this->langs->trans('Balance').' '.$cur;
		}
		$cols[] = $this->langs->trans('EquivalentMainCurrency', $main);

		if (empty($accounts)) {
			return array('columns' => $cols, 'rows' => array(), 'totals' => array());
		}
		$idsCsv = implode(',', array_map('intval', array_keys($accounts)));

		// 2) Opening balance per account (before the period)
		$running = array();
		foreach ($accounts as $id => $a) {
			$running[$id] = 0.0;
		}
		$sqlO = "SELECT fk_account, COALESCE(SUM(amount),0) as bal FROM ".MAIN_DB_PREFIX."bank";
		$sqlO .= " WHERE fk_account IN (".$idsCsv.") AND dateo < '".$this->db->idate($f['date_start'])."'";
		$sqlO .= " GROUP BY fk_account";
		$resO = $this->db->query($sqlO);
		if ($resO) {
			while ($oo = $this->db->fetch_object($resO)) {
				$running[(int) $oo->fk_account] = (float) $oo->bal;
			}
		}

		// 3) Daily inflow/outflow per account within the period (single query)
		$daily = array();
		$sqlD = "SELECT fk_account, DATE(dateo) as d,";
		$sqlD .= " SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as ent,";
		$sqlD .= " SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as sor";
		$sqlD .= " FROM ".MAIN_DB_PREFIX."bank";
		$sqlD .= " WHERE fk_account IN (".$idsCsv.")";
		$sqlD .= " AND dateo BETWEEN '".$this->db->idate($f['date_start'])."' AND '".$this->db->idate($f['date_end'])."'";
		$sqlD .= " GROUP BY fk_account, DATE(dateo)";
		$resD = $this->db->query($sqlD);
		if ($resD) {
			while ($od = $this->db->fetch_object($resD)) {
				$daily[$od->d][(int) $od->fk_account] = array('in' => (float) $od->ent, 'out' => (float) $od->sor);
			}
		}

		// Build one row per day
		$rows = array();
		$sumIn = array();
		$sumOut = array();
		foreach ($accounts as $id => $a) {
			$sumIn[$id] = 0.0;
			$sumOut[$id] = 0.0;
		}
		$totMainIn = 0.0;
		$totMainOut = 0.0;

		$dayTs = (int) $f['date_start'];
		$endTs = (int) $f['date_end'];
		$loop = 0;
		while ($dayTs <= $endTs && $loop < 400) {
			$loop++;
			$dayKey = date('Y-m-d', $dayTs);
			$dayEndTs = $dayTs + 86399;
			$dayRate = array();
			foreach ($currencies as $cur) {
				if ($cur === $main) {
					$dayRate[$cur] = 1.0;
				} else {
					$histr = $this->rateAtTimeline($timeline[$cur], $dayEndTs);
					$dayRate[$cur] = ($histr === null) ? $currentRate[$cur] : $histr;
				}
			}
			$tauxCell = '-';
			if ($secCur !== '' && isset($dayRate[$secCur]) && $dayRate[$secCur] !== null && $dayRate[$secCur] > 0) {
				$tauxCell = price(1.0 / $dayRate[$secCur], 0, $this->langs, 1, 2, -1, '');
			}
			$row = array(dol_print_date($dayTs, 'day'), $tauxCell);
			$dIn = 0.0;
			$dOut = 0.0;
			foreach ($accounts as $id => $a) {
				$in = isset($daily[$dayKey][$id]['in']) ? $daily[$dayKey][$id]['in'] : 0.0;
				$out = isset($daily[$dayKey][$id]['out']) ? $daily[$dayKey][$id]['out'] : 0.0;
				$row[] = ($in != 0.0) ? price($in, 0, $this->langs, 1, -1, -1, $a['cur']) : '';
				$row[] = ($out != 0.0) ? price($out, 0, $this->langs, 1, -1, -1, $a['cur']) : '';
				$sumIn[$id] += $in;
				$sumOut[$id] += $out;
				$running[$id] += ($in - $out);
				$rr = $dayRate[$a['cur']];
				if ($rr !== null) {
					$dIn += $in * $rr;
					$dOut += $out * $rr;
				}
			}
			$totMainIn += $dIn;
			$totMainOut += $dOut;
			$row[] = price($dIn, 0, $this->langs, 1, -1, -1, $main);
			$row[] = price($dOut, 0, $this->langs, 1, -1, -1, $main);
			$equiv = 0.0;
			foreach ($currencies as $cur) {
				$bal = 0.0;
				foreach ($accounts as $id => $a) {
					if ($a['cur'] === $cur) {
						$bal += $running[$id];
					}
				}
				$row[] = price($bal, 0, $this->langs, 1, -1, -1, $cur);
			}
			foreach ($accounts as $id => $a) {
				$rr = $dayRate[$a['cur']];
				if ($rr !== null) {
					$equiv += $running[$id] * $rr;
				}
			}
			$row[] = price($equiv, 0, $this->langs, 1, -1, -1, $main);
			$rows[] = $row;
			$dayTs += 86400;
		}

		// TOTAL row: period flows + closing balances
		$totalRow = array($this->langs->trans('Total'), '');
		foreach ($accounts as $id => $a) {
			$totalRow[] = ($sumIn[$id] != 0.0) ? price($sumIn[$id], 0, $this->langs, 1, -1, -1, $a['cur']) : '';
			$totalRow[] = ($sumOut[$id] != 0.0) ? price($sumOut[$id], 0, $this->langs, 1, -1, -1, $a['cur']) : '';
		}
		$totalRow[] = price($totMainIn, 0, $this->langs, 1, -1, -1, $main);
		$totalRow[] = price($totMainOut, 0, $this->langs, 1, -1, -1, $main);
		$equivClose = 0.0;
		foreach ($currencies as $cur) {
			$bal = 0.0;
			foreach ($accounts as $id => $a) {
				if ($a['cur'] === $cur) {
					$bal += $running[$id];
				}
			}
			$totalRow[] = price($bal, 0, $this->langs, 1, -1, -1, $cur);
		}
		foreach ($accounts as $id => $a) {
			$rr = $currentRate[$a['cur']];
			if ($rr !== null) {
				$equivClose += $running[$id] * $rr;
			}
		}
		$totalRow[] = price($equivClose, 0, $this->langs, 1, -1, -1, $main);
		$rows[] = $totalRow;

		return array('columns' => $cols, 'rows' => $rows, 'totals' => array());
	}

	/**
	 * Load the historical rate timeline (currency -> main) from the module rate history,
	 * trying the inverse direction (and inverting the value) when only that one is stored.
	 *
	 * @param string $cur  Currency code
	 * @param string $main Main currency code
	 * @return array       List of array('ts'=>int,'rate'=>float) sorted by ascending date
	 */
	private function loadRateTimeline($cur, $main)
	{
		$tl = array();
		$sql = "SELECT tms, rate_new FROM ".MAIN_DB_PREFIX."bank_rate_history";
		$sql .= " WHERE entity = ".((int) $this->conf->entity);
		$sql .= " AND code_from = '".$this->db->escape($cur)."' AND code_to = '".$this->db->escape($main)."'";
		$sql .= " ORDER BY tms ASC";
		$res = $this->db->query($sql);
		if ($res) {
			while ($o = $this->db->fetch_object($res)) {
				$tl[] = array('ts' => $this->db->jdate($o->tms), 'rate' => (float) $o->rate_new);
			}
		}
		if (!empty($tl)) {
			return $tl;
		}
		// Fallback: only the inverse direction (main -> cur) is stored; invert the value
		$sql = "SELECT tms, rate_new FROM ".MAIN_DB_PREFIX."bank_rate_history";
		$sql .= " WHERE entity = ".((int) $this->conf->entity);
		$sql .= " AND code_from = '".$this->db->escape($main)."' AND code_to = '".$this->db->escape($cur)."'";
		$sql .= " ORDER BY tms ASC";
		$res = $this->db->query($sql);
		if ($res) {
			while ($o = $this->db->fetch_object($res)) {
				$r = (float) $o->rate_new;
				if ($r > 0) {
					$tl[] = array('ts' => $this->db->jdate($o->tms), 'rate' => 1.0 / $r);
				}
			}
		}
		return $tl;
	}

	/**
	 * Return the latest rate in a timeline whose date is <= the given timestamp.
	 *
	 * @param array $timeline Timeline (sorted asc) from loadRateTimeline()
	 * @param int   $ts       Timestamp
	 * @return float|null     Rate, or null if none applies yet
	 */
	private function rateAtTimeline($timeline, $ts)
	{
		if (empty($timeline)) {
			return null;
		}
		$found = null;
		foreach ($timeline as $pt) {
			if ($pt['ts'] <= $ts) {
				$found = $pt['rate'];
			} else {
				break;
			}
		}
		return $found;
	}

	/**
	 * Build comparison summary with previous period (value-added).
	 *
	 * @param string $reportCode Report code
	 * @param array  $f          Filters
	 * @param array  $current    Current report
	 * @return array
	 */
	private function buildComparisonSummary($reportCode, $f, $current)
	{
		$delta = $f['date_end'] - $f['date_start'];
		$prevFilters = $f;
		$prevFilters['date_end'] = $f['date_start'] - 86400;
		$prevFilters['date_start'] = $prevFilters['date_end'] - $delta;
		$previous = $this->buildReport($reportCode, $prevFilters);

		$currentNb = is_array($current['rows']) ? count($current['rows']) : 0;
		$previousNb = is_array($previous['rows']) ? count($previous['rows']) : 0;

		return array(
			'label_current' => dol_print_date($f['date_start'], 'day').' - '.dol_print_date($f['date_end'], 'day'),
			'label_previous' => dol_print_date($prevFilters['date_start'], 'day').' - '.dol_print_date($prevFilters['date_end'], 'day'),
			'rows_current' => $currentNb,
			'rows_previous' => $previousNb,
			'rows_delta' => $currentNb - $previousNb,
		);
	}

	/**
	 * Save report favorite.
	 *
	 * @param string $label      Label
	 * @param string $reportCode Report code
	 * @param array  $filters    Normalized filters
	 * @return int               New rowid or <0
	 */
	public function saveFavorite($label, $reportCode, $filters)
	{
		$label = trim((string) $label);
		if ($label === '') {
			return -1;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_report_favorite";
		$sql .= "(entity, fk_user, label, report_code, filters_json, tms) VALUES (";
		$sql .= ((int) $this->conf->entity).", ".((int) $this->user->id).", '".$this->db->escape($label)."', '".$this->db->escape($reportCode)."',";
		$sql .= " '".$this->db->escape(json_encode($filters))."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			return -2;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'bank_report_favorite');
	}

	/**
	 * List favorites of current user.
	 *
	 * @return array
	 */
	public function listFavorites()
	{
		$list = array();
		$sql = "SELECT rowid, label, report_code, filters_json";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_report_favorite";
		$sql .= " WHERE entity = ".((int) $this->conf->entity)." AND fk_user = ".((int) $this->user->id);
		$sql .= " ORDER BY tms DESC, rowid DESC";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = array(
					'rowid' => (int) $obj->rowid,
					'label' => (string) $obj->label,
					'report_code' => (string) $obj->report_code,
					'filters' => json_decode($obj->filters_json, true),
				);
			}
		}
		return $list;
	}

	/**
	 * Get one favorite by id for current user.
	 *
	 * @param int $id Favorite id
	 * @return array|null
	 */
	public function getFavorite($id)
	{
		$sql = "SELECT rowid, label, report_code, filters_json";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_report_favorite";
		$sql .= " WHERE rowid = ".((int) $id);
		$sql .= " AND entity = ".((int) $this->conf->entity);
		$sql .= " AND fk_user = ".((int) $this->user->id);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array(
				'rowid' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'report_code' => (string) $obj->report_code,
				'filters' => json_decode($obj->filters_json, true),
			);
		}
		return null;
	}

	/**
	 * Delete favorite for current user.
	 *
	 * @param int $id Favorite id
	 * @return int
	 */
	public function deleteFavorite($id)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."bank_report_favorite WHERE rowid = ".((int) $id);
		$sql .= " AND entity = ".((int) $this->conf->entity)." AND fk_user = ".((int) $this->user->id);
		if (!$this->db->query($sql)) {
			return -1;
		}
		return 1;
	}

	/**
	 * Log an export operation for confidentiality/audit history.
	 *
	 * @param string $reportCode Report code
	 * @param string $format     Export format
	 * @param array  $filters    Filters
	 * @return int
	 */
	public function logExport($reportCode, $format, $filters)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_report_export_log";
		$sql .= "(entity, fk_user, report_code, export_format, filters_json, ip, tms) VALUES (";
		$sql .= ((int) $this->conf->entity).", ".((int) $this->user->id).", '".$this->db->escape($reportCode)."', '".$this->db->escape($format)."',";
		$sql .= " '".$this->db->escape(json_encode($filters))."', '".$this->db->escape(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			return -1;
		}
		return 1;
	}

	/**
	 * Recent export logs.
	 *
	 * @param int $limit Max rows
	 * @return array
	 */
	public function getRecentExportLogs($limit = 20)
	{
		$list = array();
		$sql = "SELECT l.tms, l.report_code, l.export_format, l.ip, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_report_export_log as l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.entity = ".((int) $this->conf->entity);
		$sql .= " ORDER BY l.tms DESC, l.rowid DESC";
		$sql .= " LIMIT ".((int) $limit);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = array(
					'tms' => $this->db->jdate($obj->tms),
					'report_code' => (string) $obj->report_code,
					'format' => (string) $obj->export_format,
					'ip' => (string) $obj->ip,
					'user' => (string) $obj->login,
				);
			}
		}
		return $list;
	}

	/**
	 * Export report as CSV stream.
	 *
	 * @param array  $report   Report structure
	 * @param string $filename File name
	 * @return void
	 */
	public function exportCsv($report, $filename)
	{
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		echo "\xEF\xBB\xBF";

		if (!empty($report['sections']) && is_array($report['sections'])) {
			foreach ($report['sections'] as $section) {
				echo '"'.str_replace('"', '""', $section['title'])."\"\n";
				$this->writeCsvTabular($section);
				echo "\n";
			}
		} else {
			$this->writeCsvTabular($report);
		}
	}

	/**
	 * Export report as a corporate PDF (Quinley layout) using a dedicated TCPDF subclass.
	 *
	 * @param array  $report     Report structure
	 * @param string $filename   Output filename
	 * @param string $annotation Optional annotation
	 * @return void
	 */
	public function exportPdf($report, $filename, $annotation = '')
	{
		global $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

		// Ensure the TCPDF class and its constants are loaded before our subclass file is parsed.
		$bootstrap = pdf_getInstance('A4');
		unset($bootstrap);
		dol_include_once('/bankaudit/class/bankaudittcpdf.class.php');

		if (!class_exists('BankAuditTCPDF')) {
			// Defensive fallback to CSV if the subclass cannot be loaded.
			$this->exportCsv($report, preg_replace('/\.pdf$/i', '.csv', $filename));
			return;
		}

		$logo = '';
		if (!empty($mysoc->logo)) {
			$candidate = DOL_DATA_ROOT.'/mycompany/logos/'.$mysoc->logo;
			if (is_readable($candidate)) {
				$logo = $candidate;
			}
		}

		$line2 = '';
		if (!empty($mysoc->idprof1)) {
			$line2 .= 'RCCM : '.$mysoc->idprof1;
		}
		if (!empty($mysoc->idprof2)) {
			$line2 .= ($line2 !== '' ? '   |   ' : '').'ID NAT : '.$mysoc->idprof2;
		}
		$line3 = '';
		if (!empty($mysoc->phone)) {
			$line3 .= $this->langs->trans('Phone').' : '.$mysoc->phone;
		}
		if (!empty($mysoc->email)) {
			$line3 .= ($line3 !== '' ? '   |   ' : '').$mysoc->email;
		}

		$pdf = new BankAuditTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
		$pdf->SetCreator('Dolibarr BankAudit');
		$pdf->SetAuthor($this->user->getFullName($this->langs));
		$pdf->SetTitle((string) $report['title']);
		$pdf->setBankAuditMeta(array(
			'company_name' => (string) $mysoc->name,
			'company_address' => trim(preg_replace('/\s+/', ' ', (string) $mysoc->address)),
			'company_line2' => $line2,
			'company_line3' => $line3,
			'logo_path' => $logo,
			'report_title' => (string) $report['title'],
			'report_period' => '',
			'report_filters' => (string) $report['filtersLabel'],
			'exported_by' => $this->langs->trans('ReportExportedBy').' : '.$this->user->getFullName($this->langs),
			'exported_on' => $this->langs->trans('ReportGeneratedOn').' : '.dol_print_date(dol_now(), 'dayhour'),
			'label_page' => $this->langs->trans('Page'),
			'confidential' => $this->langs->trans('ReportConfidential'),
			'module_line' => 'Module BankAudit - Dolibarr '.DOL_VERSION.' - '.(string) $mysoc->name,
		));
		$pdf->setPrintHeader(true);
		$pdf->setPrintFooter(true);
		$pdf->SetMargins(12, 42, 12);
		$pdf->SetHeaderMargin(5);
		$pdf->SetFooterMargin(16);
		$pdf->SetAutoPageBreak(true, 22);
		$pdf->AddPage();

		$html = '';
		if ($annotation !== '') {
			$html .= '<div style="border:1px solid #888888;padding:5px;font-size:9px"><b>'.dol_escape_htmltag($this->langs->trans('ReportAnnotation')).' :</b> '.nl2br(dol_escape_htmltag($annotation)).'</div><br>';
		}

		if (!empty($report['sections']) && is_array($report['sections'])) {
			foreach ($report['sections'] as $section) {
				$html .= '<h3>'.dol_escape_htmltag($section['title']).'</h3>';
				$html .= $this->buildHtmlTable($section);
				if (!empty($section['totals'])) {
					$html .= $this->buildTotalsHtml($section['totals']);
				}
				$html .= '<br>';
			}
		} else {
			$html .= $this->buildHtmlTable($report);
			if (!empty($report['totals'])) {
				$html .= $this->buildTotalsHtml($report['totals']);
			}
		}

		$pdf->writeHTML($html, true, false, true, false, '');
		$pdf->Output($filename, 'D');
	}

	/**
	 * Export report as native XLSX (PhpSpreadsheet) with a graceful CSV fallback.
	 *
	 * @param array  $report   Report structure
	 * @param string $filename Output filename (.xlsx)
	 * @return void
	 */
	public function exportXlsx($report, $filename)
	{
		$autoloader = DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
		$ready = false;
		if (class_exists('ZipArchive') && file_exists($autoloader)) {
			require_once $autoloader;
			if (file_exists(DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php')) {
				require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
			}
			if (defined('PHPEXCELNEW_PATH') && file_exists(PHPEXCELNEW_PATH.'Spreadsheet.php')) {
				require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
			}
			if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
				$ready = true;
			}
		}

		if (!$ready) {
			// Graceful fallback: stream CSV instead.
			$this->exportCsv($report, preg_replace('/\.xlsx$/i', '.csv', $filename));
			return;
		}

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$spreadsheet->getProperties()
			->setCreator($this->user->getFullName($this->langs))
			->setTitle((string) $report['title']);

		$sections = (!empty($report['sections']) && is_array($report['sections'])) ? $report['sections'] : array($report);
		$index = 0;
		foreach ($sections as $section) {
			$sheet = ($index === 0) ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
			$rawTitle = isset($section['code']) ? $section['code'] : ('Sheet'.($index + 1));
			$title = substr(preg_replace('/[^A-Za-z0-9_\- ]/', '', (string) $rawTitle), 0, 28);
			if ($title === '') {
				$title = 'Sheet'.($index + 1);
			}
			$sheet->setTitle($title);
			$this->fillXlsxSheet($sheet, $section);
			$index++;
		}
		$spreadsheet->setActiveSheetIndex(0);

		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment;filename="'.$filename.'"');
		header('Cache-Control: max-age=0');
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
		$writer->save('php://output');
	}

	/**
	 * Fill one worksheet from a report/section structure.
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet   Worksheet
	 * @param array                                        $section Report/section structure
	 * @return void
	 */
	private function fillXlsxSheet($sheet, $section)
	{
		global $mysoc;

		$columns = isset($section['columns']) ? $section['columns'] : array();
		$colCount = max(1, count($columns));
		$lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);
		$row = 1;

		$sheet->setCellValue('A'.$row, (string) $mysoc->name);
		$sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13);
		$row++;
		if (!empty($section['title'])) {
			$sheet->setCellValue('A'.$row, (string) $section['title']);
			$sheet->getStyle('A'.$row)->getFont()->setBold(true);
			$row++;
		}
		if (!empty($section['filtersLabel'])) {
			$sheet->setCellValue('A'.$row, (string) $section['filtersLabel']);
			$row++;
		}
		$row++;

		$headerRow = $row;
		for ($c = 0; $c < $colCount; $c++) {
			$coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1).$headerRow;
			$sheet->setCellValue($coord, isset($columns[$c]) ? (string) $columns[$c] : '');
		}
		$headerRange = 'A'.$headerRow.':'.$lastCol.$headerRow;
		$sheet->getStyle($headerRange)->getFont()->setBold(true);
		$sheet->getStyle($headerRange)->getFill()
			->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
			->getStartColor()->setRGB('D9E1F2');
		$row++;

		if (!empty($section['rows'])) {
			foreach ($section['rows'] as $dataRow) {
				$c = 1;
				foreach ($dataRow as $cell) {
					$value = html_entity_decode(strip_tags((string) $cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
					$coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c).$row;
					$sheet->setCellValueExplicit($coord, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
					$c++;
				}
				$row++;
			}
		}

		if (!empty($section['totals'])) {
			$row++;
			foreach ($section['totals'] as $k => $v) {
				$sheet->setCellValue('A'.$row, (string) $k);
				$sheet->getStyle('A'.$row)->getFont()->setBold(true);
				$sheet->setCellValue('B'.$row, html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
				$row++;
			}
		}

		for ($c = 1; $c <= $colCount; $c++) {
			$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
		}
	}

	/**
	 * Send scheduled report (cron hook).
	 *
	 * @param string $reportCode Report code
	 * @return int
	 */
	public function sendScheduledReport($reportCode)
	{
		// Lightweight cron placeholder: generation side is implemented, dispatch can be wired later.
		$f = $this->normalizeFilters(array());
		$report = $this->buildReport($reportCode, $f);
		return empty($report['rows']) ? 0 : 1;
	}

	/**
	 * Build HTML table from report rows.
	 *
	 * @param array $report Report
	 * @return string
	 */
	private function buildHtmlTable($report)
	{
		$html = '<table border="1" cellpadding="3" cellspacing="0" width="100%">';
		$html .= '<tr style="background-color:#f0f0f0;font-weight:bold">';
		foreach ($report['columns'] as $col) {
			$html .= '<td>'.dol_escape_htmltag($col).'</td>';
		}
		$html .= '</tr>';
		if (empty($report['rows'])) {
			$html .= '<tr><td colspan="'.count($report['columns']).'">'.$this->langs->trans('NoDataForPeriod').'</td></tr>';
		} else {
			foreach ($report['rows'] as $row) {
				$html .= '<tr>';
				foreach ($row as $cell) {
					$html .= '<td>'.dol_escape_htmltag(strip_tags((string) $cell)).'</td>';
				}
				$html .= '</tr>';
			}
		}
		$html .= '</table>';
		return $html;
	}

	/**
	 * Build totals html.
	 *
	 * @param array $totals Key/value totals
	 * @return string
	 */
	private function buildTotalsHtml($totals)
	{
		$html = '<br><table border="0" cellpadding="2" cellspacing="0" width="100%">';
		foreach ($totals as $k => $v) {
			$html .= '<tr><td width="50%"><b>'.dol_escape_htmltag($k).'</b></td><td width="50%" align="right">'.dol_escape_htmltag($v).'</td></tr>';
		}
		$html .= '</table>';
		return $html;
	}

	/**
	 * Write one tabular report as CSV lines.
	 *
	 * @param array $report Report
	 * @return void
	 */
	private function writeCsvTabular($report)
	{
		$head = array();
		foreach ($report['columns'] as $h) {
			$head[] = '"'.str_replace('"', '""', (string) $h).'"';
		}
		echo implode(';', $head)."\n";
		foreach ($report['rows'] as $row) {
			$line = array();
			foreach ($row as $cell) {
				$line[] = '"'.str_replace('"', '""', strip_tags((string) $cell)).'"';
			}
			echo implode(';', $line)."\n";
		}
		if (!empty($report['totals'])) {
			echo "\n";
			foreach ($report['totals'] as $k => $v) {
				echo '"'.str_replace('"', '""', $k).'";"'.str_replace('"', '""', $v).'"' . "\n";
			}
		}
	}

	/**
	 * Find exchange rate at a date from history table.
	 *
	 * @param string $from From currency
	 * @param string $to   To currency
	 * @param int    $ts   Timestamp
	 * @return float|null
	 */
	private function findRateAtDate($from, $to, $ts)
	{
		$sql = "SELECT rate_new FROM ".MAIN_DB_PREFIX."bank_rate_history";
		$sql .= " WHERE entity = ".((int) $this->conf->entity);
		$sql .= " AND code_from = '".$this->db->escape($from)."' AND code_to = '".$this->db->escape($to)."'";
		$sql .= " AND tms <= '".$this->db->idate($ts)."'";
		$sql .= " ORDER BY tms DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (float) $obj->rate_new;
		}
		return null;
	}

	/**
	 * Fetch category net amounts between 2 dates.
	 *
	 * @param int   $start Start timestamp
	 * @param int   $end   End timestamp
	 * @param array $f     Filters
	 * @return array
	 */
	private function fetchCategoryNet($start, $end, $f)
	{
		$data = array();
		$sql = "SELECT COALESCE(c.label, '".$this->db->escape($this->langs->trans('Undefined'))."') as cat, SUM(b.amount) as net";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = b.fk_account";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_class as bc ON bc.lineid = b.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = bc.fk_categ AND c.type = 7";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.fk_object = ba.rowid";
		$sql .= " WHERE ba.entity IN (".getEntity('bank_account').")";
		$sql .= " AND b.dateo BETWEEN '".$this->db->idate($start)."' AND '".$this->db->idate($end)."'";
		$sql .= $this->accountFilterSql($f, 'ba.rowid');
		if ($f['fk_entrepot'] > 0) {
			$sql .= " AND ef.warehouse = ".((int) $f['fk_entrepot']);
		}
		if (!empty($f['devise'])) {
			$sql .= " AND ba.currency_code = '".$this->db->escape($f['devise'])."'";
		}
		$sql .= " GROUP BY COALESCE(c.label, '".$this->db->escape($this->langs->trans('Undefined'))."')";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$data[(string) $obj->cat] = (float) $obj->net;
			}
		}
		return $data;
	}

	/**
	 * Account display label.
	 *
	 * @param int $accountId Account id
	 * @return string
	 */
	private function getAccountDisplay($accountId)
	{
		$sql = "SELECT ref, label FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".((int) $accountId);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return $obj->ref;
		}
		return (string) $accountId;
	}

	/**
	 * Display label for a list of account ids (comma-separated refs).
	 *
	 * @param int[] $ids Account ids
	 * @return string
	 */
	private function getAccountsDisplay($ids)
	{
		if (empty($ids) || !is_array($ids)) {
			return '';
		}
		$refs = array();
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid IN (".$this->accountIdsCsvFromArray($ids).") ORDER BY ref";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$refs[] = $obj->ref;
			}
		}
		return implode(', ', $refs);
	}

	/**
	 * Comma-separated sanitized list of the selected account ids (or '0' when none).
	 *
	 * @param array $f Filters
	 * @return string
	 */
	private function accountIdsCsv($f)
	{
		if (empty($f['fk_accounts'])) {
			return '0';
		}
		return $this->accountIdsCsvFromArray($f['fk_accounts']);
	}

	/**
	 * Comma-separated sanitized list from an array of ids.
	 *
	 * @param int[] $ids Ids
	 * @return string
	 */
	private function accountIdsCsvFromArray($ids)
	{
		$clean = array();
		foreach ((array) $ids as $a) {
			$a = (int) $a;
			if ($a > 0) {
				$clean[] = $a;
			}
		}
		return empty($clean) ? '0' : implode(',', $clean);
	}

	/**
	 * SQL fragment restricting a column to the selected accounts (empty when none = all).
	 *
	 * @param array  $f      Filters
	 * @param string $column Column to filter (e.g. 'b.fk_account', 'ba.rowid')
	 * @return string
	 */
	private function accountFilterSql($f, $column)
	{
		if (empty($f['fk_accounts'])) {
			return '';
		}
		return " AND ".$column." IN (".$this->accountIdsCsv($f).")";
	}

	/**
	 * Warehouse ref helper.
	 *
	 * @param int $warehouseId Warehouse id
	 * @return string
	 */
	private function getWarehouseRef($warehouseId)
	{
		$sql = "SELECT ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $warehouseId);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return $obj->ref;
		}
		return (string) $warehouseId;
	}

	/**
	 * Guess report currency for totals.
	 *
	 * @param array $rows Rows
	 * @return string
	 */
	private function detectReportCurrency($rows)
	{
		return !empty($this->conf->currency) ? $this->conf->currency : 'USD';
	}

	/**
	 * Check whether a column exists in a module/native table (current database).
	 *
	 * @param string $table Table name without prefix
	 * @param string $col   Column name
	 * @return bool
	 */
	private function columnExists($table, $col)
	{
		$sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS";
		$sql .= " WHERE TABLE_SCHEMA = DATABASE()";
		$sql .= " AND TABLE_NAME = '".$this->db->escape(MAIN_DB_PREFIX.$table)."'";
		$sql .= " AND COLUMN_NAME = '".$this->db->escape($col)."'";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}
}
