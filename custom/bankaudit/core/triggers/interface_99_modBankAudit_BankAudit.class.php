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
 * \file    custom/bankaudit/core/triggers/interface_99_modBankAudit_BankAudit.class.php
 * \ingroup bankaudit
 * \brief   Triggers for the BankAudit module.
 *
 * Handled native events:
 *  - BANKACCOUNT_CREATE / BANKACCOUNT_MODIFY  : audit log of bank accounts
 *  - BANKACCOUNTLINE_DELETE                   : audit log + cascade delete of the linked transfer line
 *  - CURRENCYRATE_CREATE / CURRENCYRATE_MODIFY: exchange rate history + abnormal rate detection
 *
 * Note: the Dolibarr core does NOT emit a BANKLINE_CREATE/BANKLINE_MODIFY trigger,
 * nor a BANKACCOUNT_DELETE trigger. Line create/modify auditing and the transfer
 * value synchronization are handled by the hook class (class/actions_bankaudit.class.php).
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/bankaudit/class/bankaudit.class.php');
dol_include_once('/bankaudit/class/bankauditanomaly.class.php');


/**
 * Class of triggers for the BankAudit module
 */
class InterfaceBankAudit extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "financial";
		$this->description = "BankAudit triggers (audit log, transfer synchronization, rate history).";
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'bank_account';
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 *
	 * @param string       $action Event action code
	 * @param CommonObject $object Object
	 * @param User         $user   Object user
	 * @param Translate    $langs  Object langs
	 * @param Conf         $conf   Object conf
	 * @return int                 Return integer <0 if KO, 0 if no trigger ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('bankaudit')) {
			return 0;
		}

		switch ($action) {
			case 'BANKACCOUNT_CREATE':
				return $this->onBankAccountCreate($object, $user);

			case 'BANKACCOUNT_MODIFY':
				return $this->onBankAccountModify($object, $user);

			case 'BANKACCOUNTLINE_DELETE':
				return $this->onBankLineDelete($object, $user);

			case 'CURRENCYRATE_CREATE':
			case 'CURRENCYRATE_MODIFY':
				return $this->onCurrencyRateChange($object, $user);

			default:
				return 0;
		}
	}

	/**
	 * Log the creation of a bank account.
	 *
	 * @param Account $object Bank account
	 * @param User    $user   Acting user
	 * @return int            >0 if OK
	 */
	private function onBankAccountCreate($object, $user)
	{
		$snapshot = json_encode(array(
			'ref' => $object->ref,
			'label' => $object->label,
			'currency_code' => $object->currency_code,
		));
		BankAudit::logChange($this->db, $user, 'bank_account', (int) $object->id, 'CREATE', null, null, $snapshot);
		return 1;
	}

	/**
	 * Log the modification of a bank account (one row per changed field when the old
	 * copy is available, otherwise a single snapshot row).
	 *
	 * @param Account $object Bank account
	 * @param User    $user   Acting user
	 * @return int            >0 if OK
	 */
	private function onBankAccountModify($object, $user)
	{
		$trackedFields = array('ref', 'label', 'bank', 'number', 'currency_code', 'account_number', 'clos', 'note_public');

		if (!empty($object->oldcopy) && is_object($object->oldcopy)) {
			$nb = 0;
			foreach ($trackedFields as $field) {
				$old = isset($object->oldcopy->$field) ? $object->oldcopy->$field : null;
				$new = isset($object->$field) ? $object->$field : null;
				if ((string) $old !== (string) $new) {
					BankAudit::logChange($this->db, $user, 'bank_account', (int) $object->id, 'UPDATE', $field, (string) $old, (string) $new);
					$nb++;
				}
			}
			if ($nb === 0) {
				// Nothing detected via diff, still keep a trace of the update event
				BankAudit::logChange($this->db, $user, 'bank_account', (int) $object->id, 'UPDATE', null, null, null);
			}
		} else {
			$snapshot = json_encode(array(
				'ref' => $object->ref,
				'label' => $object->label,
				'currency_code' => $object->currency_code,
				'clos' => $object->clos,
			));
			BankAudit::logChange($this->db, $user, 'bank_account', (int) $object->id, 'UPDATE', null, null, $snapshot);
		}
		return 1;
	}

	/**
	 * Log a bank line deletion and, for internal transfers, cascade-delete the linked line.
	 *
	 * @param AccountLine $object Bank line being deleted
	 * @param User        $user   Acting user
	 * @return int                >0 if OK, <0 to abort the deletion
	 */
	private function onBankLineDelete($object, $user)
	{
		$lineid = (int) (!empty($object->id) ? $object->id : $object->rowid);

		// Capture a complete snapshot (line + categories + links + linked id) so the line can be
		// restored later from the audit log. Must be done before the rows are physically deleted.
		$snapshot = BankAudit::captureLineSnapshot($this->db, $lineid);
		$snapshotJson = ($snapshot !== null)
			? json_encode($snapshot)
			: json_encode(array('amount' => $object->amount, 'label' => $object->label, 'fk_account' => $object->fk_account));

		// If we are already inside a propagated synchronization, just log and return
		// to avoid an infinite recursion between the two linked lines.
		if (BankAudit::$syncInProgress) {
			BankAudit::logChange(
				$this->db,
				$user,
				'bank_line',
				$lineid,
				'DELETE',
				null,
				$snapshotJson,
				null,
				json_encode(array('source' => 'auto_sync'))
			);
			return 1;
		}

		// Log the deletion of the current line
		BankAudit::logChange(
			$this->db,
			$user,
			'bank_line',
			$lineid,
			'DELETE',
			null,
			$snapshotJson,
			null
		);

		// Cascade to the linked transfer line, if any
		$linkedId = BankAudit::getLinkedTransferLine($this->db, $lineid);
		if ($linkedId > 0 && $linkedId !== $lineid) {
			require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

			$linkedLine = new AccountLine($this->db);
			if ($linkedLine->fetch($linkedId) > 0) {
				BankAudit::$syncInProgress = true;
				$res = $linkedLine->delete($user);
				BankAudit::$syncInProgress = false;

				if ($res < 0) {
					// Keep both lines consistent: abort the whole deletion
					$this->errors[] = 'BankAudit: failed to cascade-delete the linked transfer line ('.$linkedId.')';
					return -1;
				}
			}
		}

		return 1;
	}

	/**
	 * Record an exchange rate change and detect abnormal variations.
	 *
	 * @param CurrencyRate $object Currency rate object
	 * @param User         $user   Acting user
	 * @return int                 >0 if OK
	 */
	private function onCurrencyRateChange($object, $user)
	{
		global $conf;

		if (empty($object->fk_multicurrency) || !isset($object->rate)) {
			return 0;
		}

		$codeTo = BankAudit::getCurrencyCodeById($this->db, (int) $object->fk_multicurrency);
		if ($codeTo === '') {
			return 0;
		}
		$codeFrom = $conf->currency; // main currency: rate = foreign units per 1 main currency
		$rateNew = (float) $object->rate;

		$res = BankAudit::logRateChange($this->db, $user, $codeFrom, $codeTo, $rateNew, 'manual');

		// Abnormal variation detection (R04)
		if (!empty($res['variation'])) {
			BankAuditAnomaly::checkTauxAnormal($this->db, $codeFrom, $codeTo, $rateNew, $res['variation']);
		}

		return 1;
	}
}
