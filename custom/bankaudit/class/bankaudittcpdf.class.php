<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/bankaudit/class/bankaudittcpdf.class.php
 * \ingroup bankaudit
 * \brief   Corporate TCPDF subclass (Quinley layout) for BankAudit report exports.
 *
 * IMPORTANT: The TCPDF class must already be loaded before this file is parsed.
 * Always call pdf_getInstance() once beforehand so TCPDF and its constants exist.
 */

/**
 * Corporate PDF document with a Quinley-styled repeating header and footer.
 */
class BankAuditTCPDF extends TCPDF
{
	/**
	 * @var array Corporate and report metadata used by the header/footer.
	 */
	public $bankauditMeta = array(
		'company_name' => '',
		'company_address' => '',
		'company_line2' => '',
		'company_line3' => '',
		'logo_path' => '',
		'report_title' => '',
		'report_period' => '',
		'report_filters' => '',
		'exported_by' => '',
		'exported_on' => '',
		'label_page' => 'Page',
		'confidential' => 'CONFIDENTIAL',
		'module_line' => '',
	);

	/**
	 * Inject corporate metadata used by the header/footer.
	 *
	 * @param array $meta Metadata merged into the defaults
	 * @return void
	 */
	public function setBankAuditMeta($meta)
	{
		if (is_array($meta)) {
			$this->bankauditMeta = array_merge($this->bankauditMeta, $meta);
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.NotCamelCaps
	/**
	 * Repeating page header (corporate layout).
	 *
	 * @return void
	 */
	public function Header()
	{
		// phpcs:enable
		$m = $this->bankauditMeta;
		$left = 12;
		$right = 198;
		$textX = $left;

		// Company logo
		if (!empty($m['logo_path']) && is_readable($m['logo_path'])) {
			$ext = strtolower(pathinfo($m['logo_path'], PATHINFO_EXTENSION));
			$type = ($ext === 'png') ? 'PNG' : (($ext === 'jpg' || $ext === 'jpeg') ? 'JPG' : '');
			$this->Image($m['logo_path'], $left, 8, 26, 0, $type, '', 'T', false, 300, '', false, false, 0);
			$textX = $left + 30;
		}

		// Company identity block
		$this->SetTextColor(0, 0, 0);
		$this->SetFont('helvetica', 'B', 11);
		$this->SetXY($textX, 8);
		$this->Cell($right - $textX, 5, $m['company_name'], 0, 1, 'L');

		$this->SetFont('helvetica', '', 8);
		if ($m['company_address'] !== '') {
			$this->SetXY($textX, 13);
			$this->Cell($right - $textX, 4, $m['company_address'], 0, 1, 'L');
		}
		if ($m['company_line2'] !== '') {
			$this->SetXY($textX, 17);
			$this->Cell($right - $textX, 4, $m['company_line2'], 0, 1, 'L');
		}
		if ($m['company_line3'] !== '') {
			$this->SetXY($textX, 21);
			$this->Cell($right - $textX, 4, $m['company_line3'], 0, 1, 'L');
		}

		// Separator
		$this->SetDrawColor(120, 120, 120);
		$this->Line($left, 26, $right, 26);

		// Report title
		$this->SetFont('helvetica', 'B', 12);
		$this->SetXY($left, 27.5);
		$this->Cell($right - $left, 6, $m['report_title'], 0, 1, 'C');

		// Period and filters band
		$y = 33;
		if ($m['report_period'] !== '') {
			$this->SetFont('helvetica', 'I', 8);
			$this->SetXY($left, $y);
			$this->Cell($right - $left, 4, $m['report_period'], 0, 1, 'C');
			$y += 4;
		}
		if ($m['report_filters'] !== '') {
			$filters = $m['report_filters'];
			if (function_exists('dol_trunc')) {
				$filters = dol_trunc($filters, 160);
			}
			$this->SetFont('helvetica', '', 7);
			$this->SetFillColor(240, 240, 240);
			$this->SetXY($left, $y);
			$this->Cell($right - $left, 4, $filters, 0, 1, 'C', true);
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.NotCamelCaps
	/**
	 * Repeating page footer (corporate layout).
	 *
	 * @return void
	 */
	public function Footer()
	{
		// phpcs:enable
		$m = $this->bankauditMeta;
		$left = 12;
		$right = 198;

		$this->SetY(-18);
		$this->SetDrawColor(120, 120, 120);
		$this->Line($left, $this->GetY(), $right, $this->GetY());

		$this->SetFont('helvetica', 'I', 7);
		$this->SetTextColor(70, 70, 70);
		$line1 = $m['exported_by'];
		$line1 .= '    |    '.$m['exported_on'];
		$line1 .= '    |    '.$m['label_page'].' '.$this->getAliasNumPage().' / '.$this->getAliasNbPages();
		$line1 .= '    |    '.$m['confidential'];
		$this->SetXY($left, -17);
		$this->Cell($right - $left, 4, $line1, 0, 1, 'C');

		$this->SetFont('helvetica', 'I', 6.5);
		$this->SetTextColor(120, 120, 120);
		$this->SetX($left);
		$this->Cell($right - $left, 4, $m['module_line'], 0, 0, 'C');
	}
}
