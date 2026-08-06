<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/bankaudit/entry_ticket.php
 * \ingroup bankaudit
 * \brief   Fast 80mm cash ticket for a bank entry (HTML, auto-print).
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

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

$langs->loadLangs(array('main', 'banks', 'compta', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
restrictedArea($user, 'banque', '', '', '');

$id = GETPOSTINT('id');
if ($id <= 0) {
	accessforbidden();
}

$object = new PaymentVarious($db);
if ($object->fetch($id) <= 0) {
	accessforbidden('ErrorRecordNotFound');
}

// Resolve the generated bank line + account
$bankLineId = (int) $object->fk_bank;
$accountRef = '';
$currency = $conf->currency;
$lineLabel = $object->label;
$lineDate = $object->datep;
$paymentCode = '';
$beneficiaire = '';

if ($bankLineId > 0) {
	$line = new AccountLine($db);
	if ($line->fetch($bankLineId) > 0) {
		$lineLabel = $line->label;
		$lineDate = $line->datev ? $line->datev : $object->datep;
		$paymentCode = $line->fk_type;
		$acc = new Account($db);
		if ($line->fk_account > 0 && $acc->fetch($line->fk_account) > 0) {
			$accountRef = $acc->ref;
			$currency = $acc->currency_code ? $acc->currency_code : $conf->currency;
		}
	}
	// Beneficiary extrafield (debit entries)
	$resef = $db->query("SELECT beneficiaire FROM ".MAIN_DB_PREFIX."bank_extrafields WHERE fk_object = ".((int) $bankLineId));
	if ($resef && ($objef = $db->fetch_object($resef))) {
		$beneficiaire = (string) $objef->beneficiaire;
	}
}

$isDebit = ((int) $object->sens === 0);
$sensLabel = $isDebit ? $langs->trans('Debit') : $langs->trans('Credit');
$amount = (float) $object->amount;

$copies = max(1, (int) getDolGlobalString('BANKAUDIT_TICKET_COPIES', '1'));

// Company data
$companyName = $mysoc->name;
$companyAddress = trim(preg_replace('/\s+/', ' ', (string) $mysoc->address));
$companyZipTown = trim($mysoc->zip.' '.$mysoc->town);
$companyPhone = $mysoc->phone;
$line2 = '';
if (!empty($mysoc->idprof1)) {
	$line2 .= 'RCCM : '.$mysoc->idprof1;
}
if (!empty($mysoc->idprof2)) {
	$line2 .= ($line2 !== '' ? ' | ' : '').'ID NAT : '.$mysoc->idprof2;
}

/**
 * Render one ticket block.
 *
 * @return string HTML
 */
function bankaudit_render_ticket($langs, $companyName, $companyAddress, $companyZipTown, $line2, $companyPhone, $object, $accountRef, $currency, $lineLabel, $lineDate, $paymentCode, $beneficiaire, $isDebit, $sensLabel, $amount, $user)
{
	$h = '<div class="ticket">';
	$h .= '<div class="center bold big">'.dol_escape_htmltag($companyName).'</div>';
	if ($companyAddress !== '') {
		$h .= '<div class="center small">'.dol_escape_htmltag($companyAddress).'</div>';
	}
	if ($companyZipTown !== '') {
		$h .= '<div class="center small">'.dol_escape_htmltag($companyZipTown).'</div>';
	}
	if ($line2 !== '') {
		$h .= '<div class="center small">'.dol_escape_htmltag($line2).'</div>';
	}
	if (!empty($companyPhone)) {
		$h .= '<div class="center small">'.$langs->trans('Phone').' : '.dol_escape_htmltag($companyPhone).'</div>';
	}

	$h .= '<div class="sep"></div>';
	$h .= '<div class="center bold">'.dol_escape_htmltag($langs->trans('BankAuditTicketTitle')).'</div>';
	$h .= '<div class="center small">N&deg; '.dol_escape_htmltag($object->ref ? $object->ref : $object->id).'</div>';
	$h .= '<div class="sep"></div>';

	$h .= '<table class="lines">';
	$h .= '<tr><td>'.dol_escape_htmltag($langs->trans('BankAuditEntryDate')).'</td><td class="right">'.dol_print_date($lineDate, 'dayhour').'</td></tr>';
	if ($accountRef !== '') {
		$h .= '<tr><td>'.dol_escape_htmltag($langs->trans('BankAccount')).'</td><td class="right">'.dol_escape_htmltag($accountRef).'</td></tr>';
	}
	$h .= '<tr><td>'.dol_escape_htmltag($langs->trans('Label')).'</td><td class="right">'.dol_escape_htmltag($lineLabel).'</td></tr>';
	if ($isDebit && $beneficiaire !== '') {
		$h .= '<tr><td>'.dol_escape_htmltag($langs->trans('BankAuditBeneficiary')).'</td><td class="right">'.dol_escape_htmltag($beneficiaire).'</td></tr>';
	}
	if ($paymentCode !== '') {
		$h .= '<tr><td>'.dol_escape_htmltag($langs->trans('PaymentMode')).'</td><td class="right">'.dol_escape_htmltag($paymentCode).'</td></tr>';
	}
	$h .= '<tr><td>'.dol_escape_htmltag($langs->trans('Sens')).'</td><td class="right">'.dol_escape_htmltag($sensLabel).'</td></tr>';
	$h .= '</table>';

	$h .= '<div class="sep"></div>';
	$h .= '<table class="amount"><tr><td class="bold">'.dol_escape_htmltag($langs->trans('Amount')).'</td>';
	$h .= '<td class="right bold big">'.price($amount, 1, $langs, 1, -1, -1, $currency).'</td></tr></table>';
	$h .= '<div class="sep"></div>';

	// Signatures: beneficiary and cashier on the same line
	$h .= '<table class="sign"><tr>';
	$h .= '<td class="center"><div class="sigline">&nbsp;</div>'.dol_escape_htmltag($langs->trans('BankAuditBeneficiary')).'</td>';
	$h .= '<td class="center"><div class="sigline">&nbsp;</div>'.dol_escape_htmltag($langs->trans('BankAuditCashier')).'</td>';
	$h .= '</tr></table>';

	$h .= '<div class="sep"></div>';
	$h .= '<div class="center small">'.dol_escape_htmltag($langs->trans('BankAuditPrintedOn')).' '.dol_print_date(dol_now(), 'dayhour').'</div>';
	$h .= '<div class="center small">'.dol_escape_htmltag($langs->trans('AuditUser')).' : '.dol_escape_htmltag($user->getFullName($langs)).'</div>';
	$h .= '<div class="center small">&nbsp;</div>';
	$h .= '</div>';
	return $h;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo dol_escape_htmltag($langs->trans('BankAuditTicketTitle')); ?></title>
<style>
@media print {
	@page { size: 80mm auto; margin: 0; }
	body { margin: 0; }
	.noprint { display: none !important; }
}
body { width: 80mm; margin: 0 auto; background: #fff; color: #000; font-family: 'Courier New', Courier, monospace; }
.ticket { width: 74mm; margin: 0 auto; padding: 3mm 2mm 6mm 2mm; page-break-after: always; }
.ticket:last-child { page-break-after: auto; }
.center { text-align: center; }
.right { text-align: right; }
.bold { font-weight: bold; }
.big { font-size: 1.25em; }
.small { font-size: 0.82em; }
.sep { border-top: 1px dashed #000; margin: 4px 0; }
table.lines, table.amount, table.sign { width: 100%; border-collapse: collapse; font-size: 0.9em; }
table.lines td { padding: 1px 0; vertical-align: top; }
table.amount td { padding: 2px 0; }
table.sign td { width: 50%; padding-top: 10px; vertical-align: bottom; }
.sigline { border-bottom: 1px solid #000; height: 18px; margin: 0 4px 2px 4px; }
.noprint { text-align: center; margin: 8px; }
</style>
</head>
<body>
<div class="noprint">
	<button onclick="window.print();"><?php echo dol_escape_htmltag($langs->trans('BankAuditPrint')); ?></button>
	<button onclick="window.close();"><?php echo dol_escape_htmltag($langs->trans('Close')); ?></button>
</div>
<?php
for ($c = 0; $c < $copies; $c++) {
	echo bankaudit_render_ticket($langs, $companyName, $companyAddress, $companyZipTown, $line2, $companyPhone, $object, $accountRef, $currency, $lineLabel, $lineDate, $paymentCode, $beneficiaire, $isDebit, $sensLabel, $amount, $user);
}
?>
<script>
window.onload = function() {
	window.focus();
	window.print();
};
window.onafterprint = function() {
	window.close();
};
</script>
</body>
</html>
<?php
$db->close();
