<?php
/* Copyright (C) 2026 BankAudit module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    custom/bankaudit/entry_card.php
 * \ingroup bankaudit
 * \brief   Fast "New bank entry" form (modern), built on PaymentVarious.
 *
 * Customizations vs native various_payment:
 *  - Title "New entry", default direction = Credit.
 *  - "Beneficiary" field after Label (shown + mandatory only for Debit, not stored for Credit).
 *  - "Tags/categories" field placed right after the direction (Sens).
 *  - "Payment date" relabelled "Entry date".
 *  - After saving, the page stays on the create form (only Amount is reset to 0) for fast chained entry.
 *  - "Save and print" button to print an 80mm cash ticket.
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
dol_include_once('/bankaudit/class/bankaudit.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
if (isModEnabled('accounting')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formaccounting.class.php';
}

$langs->loadLangs(array('main', 'banks', 'bills', 'compta', 'categories', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
restrictedArea($user, 'banque', '', '', '');
$permissiontoadd = $user->hasRight('banque', 'modifier');
if (!$permissiontoadd) {
	accessforbidden();
}

$form = new Form($db);
$formaccounting = isModEnabled('accounting') ? new FormAccounting($db) : null;

$action = GETPOST('action', 'aZ09');

// Read submitted values (kept across submissions for fast chained entry)
$datep = dol_now(); // Operation/entry date is always "now" and is not shown on the form.
$datev = dol_mktime(12, 0, 0, GETPOSTINT('datevmonth'), GETPOSTINT('datevday'), GETPOSTINT('datevyear'));
$label = GETPOST('label', 'restricthtml');
$beneficiaire = GETPOST('beneficiaire', 'alphanohtml');
$amount = GETPOST('amount', 'alphanohtml');
$accountid = GETPOSTINT('accountid');
$paymenttype = GETPOST('paymenttype', 'aZ09');
if ($paymenttype === '') {
	$paymenttype = getDolGlobalString('BANKAUDIT_DEFAULT_PAYMENT_MODE', 'LIQ');
}
$num_payment = GETPOST('num_payment', 'alpha');
$chqemetteur = GETPOST('chqemetteur', 'alphanohtml');
$chqbank = GETPOST('chqbank', 'alphanohtml');
$accountancy_code = GETPOST('accountancy_code', 'alpha');
$subledger_account = GETPOST('subledger_account', 'alpha');
$sens = GETPOSTISSET('sens') ? GETPOSTINT('sens') : 1; // default = Credit
$category_transaction = GETPOSTINT('category_transaction');
$doprint = GETPOSTISSET('saveprint') ? 1 : 0;

$savedOk = false;
$printId = 0;

/**
 * Persist a single-value extrafield on a bank line.
 *
 * @param DoliDB $db       Database handler
 * @param int    $lineid   Bank line rowid
 * @param string $field    Extrafield column name
 * @param string $value    Value
 * @return void
 */
function bankaudit_set_bank_extrafield($db, $lineid, $field, $value)
{
	$check = $db->query("SELECT fk_object FROM ".MAIN_DB_PREFIX."bank_extrafields WHERE fk_object = ".((int) $lineid));
	if ($check && $db->num_rows($check) > 0) {
		$sql = "UPDATE ".MAIN_DB_PREFIX."bank_extrafields SET ".$field." = '".$db->escape($value)."' WHERE fk_object = ".((int) $lineid);
	} else {
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."bank_extrafields (fk_object, ".$field.") VALUES (".((int) $lineid).", '".$db->escape($value)."')";
	}
	$db->query($sql);
}


/*
 * Actions
 */

if ($action == 'add' && $permissiontoadd) {
	$error = 0;

	$datep = dol_now(); // entry/operation date forced to now

	$amountnum = (float) price2num($amount);

	if (empty($datev)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('DateValue')), null, 'errors');
		$error++;
	}
	if (empty($label)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Label')), null, 'errors');
		$error++;
	}
	if ($amountnum <= 0) {
		setEventMessages($langs->trans('BankAuditAmountMustBePositive'), null, 'errors');
		$error++;
	}
	if ($accountid <= 0) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('BankAccount')), null, 'errors');
		$error++;
	}
	if (empty($paymenttype)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('PaymentMode')), null, 'errors');
		$error++;
	}
	if ($sens < 0) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Sens')), null, 'errors');
		$error++;
	}
	// Beneficiary is mandatory for a debit entry only
	if ($sens === 0 && trim($beneficiaire) === '') {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('BankAuditBeneficiary')), null, 'errors');
		$error++;
	}
	if (isModEnabled('accounting') && empty($accountancy_code)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('AccountAccounting')), null, 'errors');
		$error++;
	}

	if (!$error) {
		$object = new PaymentVarious($db);
		$object->accountid = $accountid;
		$object->datev = $datev;
		$object->datep = $datep;
		$object->amount = $amountnum;
		$object->label = $label;
		$object->type_payment = dol_getIdFromCode($db, $paymenttype, 'c_paiement', 'code', 'id', 1);
		$object->num_payment = $num_payment;
		$object->chqemetteur = $chqemetteur;
		$object->chqbank = $chqbank;
		$object->fk_user_author = $user->id;
		$object->category_transaction = $category_transaction;
		$object->accountancy_code = isModEnabled('accounting') ? $accountancy_code : '';
		$object->subledger_account = $subledger_account;
		$object->sens = $sens;

		$ret = $object->create($user);
		if ($ret > 0) {
			// Retrieve the generated bank line to attach the beneficiary (debit only)
			$object->fetch($ret);
			$bankLineId = (int) $object->fk_bank;
			if ($sens === 0 && $bankLineId > 0 && trim($beneficiaire) !== '') {
				bankaudit_set_bank_extrafield($db, $bankLineId, 'beneficiaire', $beneficiaire);
			}

			// Always trace the creation in the audit history
			if ($bankLineId > 0) {
				$logctx = json_encode(array('source' => 'entry_card', 'amount' => $amountnum, 'fk_account' => (int) $accountid, 'sens' => (int) $sens));
				BankAudit::logChange($db, $user, 'bank_line', $bankLineId, 'CREATE', null, null, $label, $logctx);
			}

			setEventMessages($langs->trans('BankAuditEntrySaved', $ret), null, 'mesgs');
			$savedOk = true;
			if ($doprint) {
				$printId = $ret;
			}

			// Stay on the page for fast chained entry: only the amount is reset.
			$amount = '';
			if ($sens !== 0) {
				$beneficiaire = '';
			}
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
}


/*
 * View
 */

// Open accounts (banks + cash registers), displayed by reference only
$accountList = array();
$sqlacc = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."bank_account WHERE entity IN (".getEntity('bank_account').") AND clos = 0 ORDER BY ref";
$resacc = $db->query($sqlacc);
if ($resacc) {
	while ($oa = $db->fetch_object($resacc)) {
		$accountList[(int) $oa->rowid] = $oa->ref;
	}
}

$title = $langs->trans('BankAuditNewEntry');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-bankaudit page-entry-card');

// Lightweight modern styling that reuses the system look & feel.
print '<style>
.bankaudit-entry .tableforfield td { padding-top:7px; padding-bottom:7px; }
.bankaudit-entry .titlefieldcreate { width:230px; }
.bankaudit-entry .be-amount input { font-size:1.2em; font-weight:bold; }
.bankaudit-entry .be-section { margin:0 0 6px 0; font-weight:bold; color:#555; border-bottom:1px solid #eee; padding-bottom:3px; }
.bankaudit-entry .be-card { background:#fff; border:1px solid #e7e7e7; border-radius:8px; padding:14px 18px; box-shadow:0 1px 3px rgba(0,0,0,.05); max-width:760px; }
</style>';

print load_fiche_titre($title, '', 'bank_account');

print '<div class="bankaudit-entry">';
print '<div class="be-card">';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="entryform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="add">';

print '<table class="border centpercent tableforfield">';

// Value date (required, defaults to now). The operation date is always "now" (hidden).
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('DateValue').'</td><td>';
print $form->selectDate((empty($datev) ? dol_now() : $datev), 'datev', 0, 0, 0, 'entryform', 1, 1);
print '</td></tr>';

// Label
print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td>';
print '<input name="label" id="label" class="minwidth300 maxwidth150onsmartphone" value="'.dol_escape_htmltag($label).'">';
print '</td></tr>';

// Beneficiary (debit only, toggled by JS)
print '<tr id="tr_beneficiaire"><td><span class="fieldrequired">'.$langs->trans('BankAuditBeneficiary').'</span></td><td>';
print '<input name="beneficiaire" id="beneficiaire" class="minwidth300 maxwidth150onsmartphone" value="'.dol_escape_htmltag($beneficiaire).'">';
print ' <span class="opacitymedium small">'.$langs->trans('BankAuditBeneficiaryHelp').'</span>';
print '</td></tr>';

// Amount
print '<tr class="be-amount"><td class="fieldrequired">'.$langs->trans('Amount').'</td><td>';
print '<input name="amount" id="amount" class="width100" value="'.dol_escape_htmltag($amount).'"> ';
print '<span class="opacitymedium small">'.$langs->trans('BankAuditAmountMustBePositive').'</span>';
print '</td></tr>';

// Bank account / cash register (reference only)
print '<tr><td class="fieldrequired">'.$langs->trans('BankAccount').'</td><td>';
print img_picto('', 'bank_account', 'class="pictofixedwidth"');
print $form->selectarray('accountid', $accountList, $accountid, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td></tr>';

// Payment mode
print '<tr><td class="fieldrequired">'.$langs->trans('PaymentMode').'</td><td>';
$form->select_types_paiements($paymenttype, 'paymenttype', '', 2);
print '</td></tr>';

// Number
print '<tr><td><label for="num_payment">'.$langs->trans('Numero').' <em>('.$langs->trans('ChequeOrTransferNumber').')</em></label></td>';
print '<td><input name="num_payment" id="num_payment" class="maxwidth150onsmartphone" type="text" value="'.dol_escape_htmltag($num_payment).'"></td></tr>';

// Issuer
print '<tr><td><label for="chqemetteur">'.$langs->trans('CheckTransmitter').'</label></td>';
print '<td><input id="chqemetteur" name="chqemetteur" size="30" type="text" value="'.dol_escape_htmltag($chqemetteur).'"></td></tr>';

// Bank
print '<tr><td><label for="chqbank">'.$langs->trans('Bank').'</label></td>';
print '<td><input id="chqbank" name="chqbank" size="30" type="text" value="'.dol_escape_htmltag($chqbank).'"></td></tr>';

// Accounting account (if accounting module enabled)
if (isModEnabled('accounting')) {
	print '<tr><td class="fieldrequired">'.$langs->trans('AccountAccounting').'</td><td>';
	print $formaccounting->select_account($accountancy_code, 'accountancy_code', 1, null, 1, 1);
	print '</td></tr>';
}

// Direction (Sens) - default Credit
print '<tr><td class="fieldrequired">';
print $form->textwithpicto($langs->trans('Sens'), $langs->trans('AccountingDirectionHelp'));
print '</td><td>';
$sensarray = array('0' => $langs->trans('Debit'), '1' => $langs->trans('Credit'));
print $form->selectarray('sens', $sensarray, $sens, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100');
print '</td></tr>';

// Tags / categories (right after Sens)
print '<tr><td>'.$langs->trans('BankAuditTransactionTags').'</td><td>';
print img_picto('', 'category', 'class="pictofixedwidth"');
print $form->select_all_categories(Categorie::TYPE_BANK_LINE, $category_transaction, 'category_transaction', 64, 0, 0, 0, 'minwidth200', 1);
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin-top:14px">';
print '<input type="submit" name="save" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
print ' &nbsp; ';
print '<input type="submit" name="saveprint" class="button" value="'.dol_escape_htmltag($langs->trans('BankAuditSaveAndPrint')).'">';
print ' &nbsp; ';
print '<a class="button button-cancel" href="'.DOL_URL_ROOT.'/compta/bank/bankentries_list.php">'.$langs->trans('Cancel').'</a>';
print '</div>';

print '</form>';
print '</div>'; // be-card
print '</div>'; // bankaudit-entry

// Beneficiary visibility logic + focus management for fast entry
print '<script>
jQuery(document).ready(function() {
	function bankauditToggleBenef() {
		var s = jQuery("#sens").val();
		if (s === "0") {
			jQuery("#tr_beneficiaire").show();
			jQuery("#beneficiaire").prop("required", true);
		} else {
			jQuery("#tr_beneficiaire").hide();
			jQuery("#beneficiaire").prop("required", false).val("");
		}
	}
	bankauditToggleBenef();
	jQuery("#sens").on("change", bankauditToggleBenef);
	jQuery("#amount").trigger("focus");
});
</script>';

// Fast print: open the 80mm cash ticket in a new window after a "Save and print"
if ($printId > 0) {
	$ticketurl = dol_buildpath('/bankaudit/entry_ticket.php', 1).'?id='.((int) $printId);
	print '<script>window.open('.json_encode($ticketurl).', "_blank");</script>';
}

llxFooter();
$db->close();
