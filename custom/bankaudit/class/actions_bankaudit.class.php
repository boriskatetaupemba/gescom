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
 * \file    custom/bankaudit/class/actions_bankaudit.class.php
 * \ingroup bankaudit
 * \brief   Hook overloads for the BankAudit module.
 *
 * Handles, on the native bank pages:
 *  - bankline  : audit of line modifications, synchronization of the linked transfer line,
 *                anomaly detection, and display of the change history (Suivi).
 *  - banktransfer : server-side balance guard (P2) and client-side conversion / balance
 *                   assistant injected into transfer.php (P3 + P2).
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
dol_include_once('/bankaudit/class/bankaudit.class.php');
dol_include_once('/bankaudit/class/bankauditanomaly.class.php');


/**
 * Class ActionsBankaudit
 */
class ActionsBankaudit extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * @var array Hook results.
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook
	 */
	public $priority;

	/**
	 * Snapshots of bank lines captured before a modification, keyed by line id.
	 *
	 * @var array
	 */
	private static $pendingLineUpdate = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * completeTabsHead hook: add the "Suivi" (modification history) tab to a bank line.
	 *
	 * The native bankline_prepare_head() calls complete_head_from_modules() with a NULL object,
	 * so the descriptor's $this->tabs cannot substitute __ID__ (the link would point to an empty
	 * rowid, which produced the "Enregistrement non trouvé" page). We therefore add the tab here,
	 * reading the real rowid from the request, and let the descriptor only remove the native info tab.
	 *
	 * @param array        $parameters  Hook metadata (mode, head, object, ...)
	 * @param CommonObject $object      Current object (NULL for bank lines)
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to let standard processing continue
	 */
	public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (empty($parameters['mode']) || $parameters['mode'] != 'add') {
			return 0;
		}
		if (empty($parameters['head']) || !is_array($parameters['head'])) {
			return 0;
		}
		// Only act on the bank line tab set (its first tab code is 'bankline')
		if (empty($parameters['head'][0][2]) || $parameters['head'][0][2] !== 'bankline') {
			return 0;
		}
		if (!$user->hasRight('bankaudit', 'read')) {
			return 0;
		}

		$rowid = GETPOSTINT('rowid');
		if ($rowid <= 0) {
			$rowid = GETPOSTINT('id');
		}
		if ($rowid <= 0) {
			return 0;
		}

		$langs->load('bankaudit@bankaudit');
		$counter = count($parameters['head']);
		$parameters['head'][$counter][0] = dol_buildpath('/bankaudit/bankline_history.php', 1).'?rowid='.$rowid;
		$parameters['head'][$counter][1] = $langs->trans('BankAuditLineHistoryTab');
		$parameters['head'][$counter][2] = 'bankaudit_suivi';

		// On Dolibarr >= 14 the head array is modified by reference, no need to replace standard code.
		return 0;
	}

	/**
	 * doActions hook.
	 *  - bankline    : capture the old line values before the native update.
	 *  - banktransfer: server-side balance guard (blocks the native 'add' when insufficient).
	 *
	 * @param array        $parameters  Hook metadata (currentcontext, ...)
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 on success
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		$context = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];

		// Redirect the legacy "various payment" creation form to the modern entry form.
		if ($context == 'variouscard' && $action == 'create' && !GETPOSTINT('id')) {
			header('Location: '.dol_buildpath('/bankaudit/entry_card.php', 1).'?action=create');
			exit;
		}

		// Capture the line state before the native update of compta/bank/line.php
		if ($context == 'bankline') {
			if ($action == 'update' && $user->hasRight('banque', 'modifier')) {
				$lineid = GETPOSTINT('rowid');
				if ($lineid > 0) {
					$snapshot = $this->fetchBankLineSnapshot($lineid);
					if ($snapshot !== null) {
						self::$pendingLineUpdate[$lineid] = $snapshot;
					}
				}
			}
		}

		// Server-side balance guard on the internal transfer page (P2)
		if ($context == 'banktransfer') {
			if ($action == 'add' && $user->hasRight('banque', 'transfer')) {
				if ($this->checkTransferBalances($langs)) {
					// Prevent the native 'add' processing by neutralizing the action
					$action = '';
				}
			}
		}

		return 0;
	}

	/**
	 * formObjectOptions hook (bankline context): process the pending modification
	 * (logging + synchronization + anomaly detection). The change history itself is
	 * displayed on the dedicated "Suivi" tab (see bankline_history.php).
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      AccountLine being displayed
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 on success
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		$context = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];
		if ($context != 'bankline') {
			return 0;
		}
		if (!is_object($object) || empty($object->id) || (isset($object->element) && $object->element != 'bank')) {
			return 0;
		}

		$lineid = (int) $object->id;

		// Process the modification that has just been persisted by the native code
		if (isset(self::$pendingLineUpdate[$lineid])) {
			$old = self::$pendingLineUpdate[$lineid];
			unset(self::$pendingLineUpdate[$lineid]);
			$this->processLineModification($user, $lineid, $old);
		}

		return 0;
	}

	/**
	 * printCommonFooter hook: inject the JavaScript assistants.
	 *  - banktransfer: conversion / balance assistant + line tools (delete, ref display, cancel).
	 *  - bankline    : read-only panel of the linked transfer line + save confirmation.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 on success
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$context = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];
		if ($context == 'banktransfer') {
			print $this->getTransferAssistantJs($langs);
		}
		if ($context == 'bankline') {
			$lineid = GETPOSTINT('rowid');
			if ($lineid > 0) {
				$linkedPanel = $this->getLinkedLinePanelJs($langs, $lineid);
				print $linkedPanel;
				// When the line has no linked transfer, guard manual sign (debit/credit) changes.
				if ($linkedPanel === '') {
					print $this->getSensChangeGuardJs($langs, $lineid);
				}
			}
		}
		if ($context == 'warehousecard') {
			print $this->getWarehouseAccountsPanel($langs);
		}

		return 0;
	}

	/**
	 * Build the "linked bank accounts / cash registers" panel shown on a warehouse card.
	 * This is the reverse view of the optional bank_account->warehouse extrafield link.
	 * The panel is injected into the read view (just above the action bar) via JavaScript.
	 *
	 * @param Translate $langs Language object
	 * @return string          HTML/JS to print, or '' when nothing to show
	 */
	private function getWarehouseAccountsPanel($langs)
	{
		global $user;

		// Only on the read view of an existing warehouse
		$id = GETPOSTINT('id');
		if ($id <= 0) {
			return '';
		}
		$curaction = GETPOST('action', 'aZ09');
		if (!empty($curaction) && $curaction != 'view') {
			return '';
		}
		// The viewer must be allowed to read bank accounts
		if (!is_object($user) || !$user->hasRight('banque', 'lire')) {
			return '';
		}

		$langs->load('banks');
		$langs->load('bankaudit@bankaudit');

		// Accounts / cash registers linked to this warehouse through the 'warehouse' extrafield
		$sql = "SELECT ba.rowid, ba.ref, ba.label, ba.courant, ba.currency_code, ba.clos";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank_account as ba";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."bank_account_extrafields as ef ON ef.fk_object = ba.rowid";
		$sql .= " WHERE ef.warehouse = ".((int) $id);
		$sql .= " AND ba.entity IN (".getEntity('bank_account').")";
		$sql .= " ORDER BY ba.clos ASC, ba.label ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			// Extrafield column not created yet (module not reactivated) or other error: show nothing
			return '';
		}

		$typelib = array(
			0 => $langs->trans('BankType0'),
			1 => $langs->trans('BankType1'),
			2 => $langs->trans('BankType2'),
		);

		$rows = '';
		$count = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$count++;
			$accid = (int) $obj->rowid;
			$accurl = DOL_URL_ROOT.'/compta/bank/card.php?id='.$accid;
			$balance = BankAudit::getAccountBalance($this->db, $accid);
			$type = isset($typelib[(int) $obj->courant]) ? $typelib[(int) $obj->courant] : '';

			$rows .= '<tr class="oddeven">';
			$rows .= '<td class="nowraponall"><a href="'.$accurl.'">'.img_picto('', 'bank_account', 'class="paddingright"').dol_escape_htmltag($obj->ref).'</a></td>';
			$rows .= '<td>'.dol_escape_htmltag($obj->label).'</td>';
			$rows .= '<td>'.dol_escape_htmltag($type).'</td>';
			$rows .= '<td class="right amount nowraponall">'.price($balance, 0, $langs, 1, -1, -1, $obj->currency_code).'</td>';
			if (!empty($obj->clos)) {
				$rows .= '<td class="center"><span class="badge badge-status8">'.$langs->trans('Closed').'</span></td>';
			} else {
				$rows .= '<td class="center"><span class="badge badge-status4">'.$langs->trans('Opened').'</span></td>';
			}
			$rows .= '</tr>';
		}

		$title = dol_escape_htmltag($langs->trans('BankAuditWarehouseAccountsTitle')).' <span class="badge">'.$count.'</span>';

		$html = '<div class="fichecenter"><br>';
		$html .= '<div class="div-table-responsive-no-min">';
		$html .= '<table class="noborder centpercent">';
		$html .= '<tr class="liste_titre"><td colspan="5">'.$title.'</td></tr>';
		if ($count > 0) {
			$html .= '<tr class="liste_titre">';
			$html .= '<td>'.$langs->trans('Ref').'</td>';
			$html .= '<td>'.$langs->trans('Label').'</td>';
			$html .= '<td>'.$langs->trans('Type').'</td>';
			$html .= '<td class="right">'.$langs->trans('Balance').'</td>';
			$html .= '<td class="center">'.$langs->trans('Status').'</td>';
			$html .= '</tr>';
			$html .= $rows;
		} else {
			$html .= '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.dol_escape_htmltag($langs->trans('BankAuditNoLinkedAccount')).'</td></tr>';
		}
		$html .= '</table>';
		$html .= '</div>';
		$html .= '</div>';

		// Inject the panel into the read view, just before the action bar
		$out = "\n".'<!-- BankAudit: linked bank accounts on warehouse card -->'."\n";
		$out .= '<script>'."\n";
		$out .= 'jQuery(function() {'."\n";
		$out .= "\t".'var bankauditWhPanel = '.json_encode($html).';'."\n";
		$out .= "\t".'var bankauditAnchor = jQuery("div.tabsAction").first();'."\n";
		$out .= "\t".'if (bankauditAnchor.length) { bankauditAnchor.before(bankauditWhPanel); }'."\n";
		$out .= "\t".'else { jQuery("div.fiche").first().append(bankauditWhPanel); }'."\n";
		$out .= '});'."\n";
		$out .= '</script>'."\n";

		return $out;
	}

	/**
	 * Read the tracked fields of a bank line from the database.
	 *
	 * @param int $lineid Bank line rowid
	 * @return array|null  Snapshot array, or null if the line does not exist
	 */
	private function fetchBankLineSnapshot($lineid)
	{
		$sql = "SELECT rowid, fk_account, fk_type, amount, label, dateo, datev, num_chq, banque, emetteur, rappro";
		$sql .= " FROM ".MAIN_DB_PREFIX."bank WHERE rowid = ".((int) $lineid);
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			return null;
		}

		return array(
			'fk_account' => (int) $obj->fk_account,
			'fk_type' => (string) $obj->fk_type,
			'amount' => (float) $obj->amount,
			'label' => (string) $obj->label,
			'dateo' => (string) $obj->dateo,
			'datev' => (string) $obj->datev,
			'num_chq' => (string) $obj->num_chq,
			'banque' => (string) $obj->banque,
			'emetteur' => (string) $obj->emetteur,
			'rappro' => (int) $obj->rappro,
			'categs' => BankAudit::getLineCategories($this->db, $lineid),
		);
	}

	/**
	 * Compute the differences between the old and the new state of a bank line,
	 * log them, propagate the change to the linked transfer line and run anomaly checks.
	 *
	 * @param User $user   Acting user
	 * @param int  $lineid Bank line rowid
	 * @param array $old   Old snapshot
	 * @return void
	 */
	private function processLineModification($user, $lineid, $old)
	{
		$new = $this->fetchBankLineSnapshot($lineid);
		if ($new === null) {
			return;
		}

		// Scalar fields tracked for both logging and propagation
		$fields = array('fk_account', 'fk_type', 'amount', 'label', 'dateo', 'datev', 'num_chq', 'banque', 'emetteur');

		$changed = array();
		foreach ($fields as $field) {
			if ((string) $old[$field] !== (string) $new[$field]) {
				$changed[$field] = array($old[$field], $new[$field]);
				BankAudit::logChange($this->db, $user, 'bank_line', $lineid, 'UPDATE', $field, (string) $old[$field], (string) $new[$field]);
			}
		}

		// Categories
		$oldc = $old['categs'];
		$newc = $new['categs'];
		sort($oldc);
		sort($newc);
		$catsChanged = ($oldc != $newc);
		if ($catsChanged) {
			BankAudit::logChange($this->db, $user, 'bank_line', $lineid, 'UPDATE', 'categories', implode(',', $oldc), implode(',', $newc));
		}

		// Propagate to the linked transfer line
		if (!empty($changed) || $catsChanged) {
			$this->syncLinkedLine($user, $lineid, $new, $changed, $catsChanged);
		}

		// Anomaly detection on the modified line
		BankAuditAnomaly::checkLine($this->db, $user, $lineid);
	}

	/**
	 * Propagate the modified fields of a transfer line to the line it is linked to,
	 * applying the currency rule on the amount and avoiding infinite recursion.
	 *
	 * @param User  $user        Acting user
	 * @param int   $lineid      Source line rowid
	 * @param array $new         New snapshot of the source line
	 * @param array $changed     Map of changed scalar fields
	 * @param bool  $catsChanged Whether categories changed
	 * @return void
	 */
	private function syncLinkedLine($user, $lineid, $new, $changed, $catsChanged)
	{
		if (BankAudit::$syncInProgress) {
			return;
		}

		$linkedId = BankAudit::getLinkedTransferLine($this->db, $lineid);
		if ($linkedId <= 0 || $linkedId == $lineid) {
			return;
		}

		$linked = $this->fetchBankLineSnapshot($linkedId);
		if ($linked === null) {
			return;
		}

		BankAudit::$syncInProgress = true;

		$setparts = array();

		// Fields propagated identically
		foreach (array('fk_type', 'emetteur', 'banque', 'label') as $f) {
			if (isset($changed[$f])) {
				$setparts[$f] = $new[$f];
			}
		}

		// Dates and amount are only propagated if the linked line is not reconciled
		if (empty($linked['rappro'])) {
			if (isset($changed['dateo'])) {
				$setparts['dateo'] = $new['dateo'];
			}
			if (isset($changed['datev'])) {
				$setparts['datev'] = $new['datev'];
			}

			if (isset($changed['amount']) || isset($changed['fk_account'])) {
				$linkedAmount = $this->computeLinkedAmount($new['fk_account'], $linked['fk_account'], $new['amount']);
				if ($linkedAmount !== null) {
					$setparts['amount'] = $linkedAmount;
				}
			}
		}

		$ctx = json_encode(array('source' => 'auto_sync', 'trigger_line_id' => (int) $lineid));

		if (!empty($setparts)) {
			$frags = array();
			foreach ($setparts as $col => $val) {
				if ($col === 'dateo' || $col === 'datev') {
					$frags[] = $col." = '".$this->db->escape($val)."'";
				} elseif ($col === 'amount') {
					$frags[] = "amount = ".((float) $val);
				} else {
					$frags[] = $col." = ".($val === '' ? "NULL" : "'".$this->db->escape($val)."'");
				}
			}
			$sql = "UPDATE ".MAIN_DB_PREFIX."bank SET ".implode(', ', $frags)." WHERE rowid = ".((int) $linkedId);
			$this->db->query($sql);

			foreach ($setparts as $col => $val) {
				$oldval = isset($linked[$col]) ? (string) $linked[$col] : null;
				BankAudit::logChange($this->db, $user, 'bank_line', $linkedId, 'UPDATE', $col, $oldval, (string) $val, $ctx);
			}
		}

		if ($catsChanged) {
			$oldcats = isset($linked['categs']) ? implode(',', $linked['categs']) : null;
			BankAudit::syncLineCategories($this->db, $linkedId, $new['categs']);
			BankAudit::logChange($this->db, $user, 'bank_line', $linkedId, 'UPDATE', 'categories', $oldcats, implode(',', $new['categs']), $ctx);
		}

		BankAudit::$syncInProgress = false;
	}

	/**
	 * Compute the amount of the linked line from the source amount, applying the currency
	 * rule: opposite sign, multiplied by the conversion rate when currencies differ.
	 *
	 * @param int   $srcAccount Source account id
	 * @param int   $tgtAccount Target (linked) account id
	 * @param float $srcAmount  Source amount
	 * @return float|null        Linked amount, or null if the rate is unknown
	 */
	private function computeLinkedAmount($srcAccount, $tgtAccount, $srcAmount)
	{
		$srcCur = BankAudit::getAccountCurrency($this->db, $srcAccount);
		$tgtCur = BankAudit::getAccountCurrency($this->db, $tgtAccount);

		if ($srcCur === '' || $tgtCur === '') {
			return null;
		}
		if ($srcCur === $tgtCur) {
			return -1 * (float) $srcAmount;
		}

		$rate = BankAudit::getConversionRate($this->db, $srcCur, $tgtCur);
		if ($rate === null) {
			return null;
		}
		return round(-1 * (float) $srcAmount * $rate, 2);
	}

	/**
	 * Validate the source balance of each line of the internal transfer form.
	 *
	 * @param Translate $langs Language object
	 * @return bool             True if at least one line is blocked
	 */
	private function checkTransferBalances($langs)
	{
		global $conf;

		// Master switch: when the balance guard is disabled in setup, never block.
		if (!BankAudit::isBalanceGuardEnabled()) {
			return false;
		}

		$blocked = false;
		for ($n = 1; $n < 20; $n++) {
			$accFrom = GETPOSTINT($n.'_account_from');
			$amount = (float) price2num(GETPOST($n.'_amount', 'alpha'), 'MT', 2);
			if ($accFrom <= 0 || $amount <= 0) {
				continue;
			}

			$threshold = BankAudit::getMinBalanceForAccount($this->db, $accFrom);
			if ($threshold === null) {
				continue; // no threshold configured for this account => no blocking
			}

			$balance = BankAudit::getAccountBalance($this->db, $accFrom);
			$after = $balance - abs($amount);
			if ($after < $threshold) {
				$blocked = true;
				$cur = BankAudit::getAccountCurrency($this->db, $accFrom);
				$accName = BankAudit::getAccountLabel($this->db, $accFrom);
				$msg = $langs->trans('BalanceGuardBlocked').' (#'.$n.') - ';
				$msg .= $langs->trans('AccountLabel').': '.$accName.' - ';
				$msg .= $langs->trans('AvailableBalance').': '.price($balance, 0, $langs, 1, -1, -1, $cur).' / ';
				$msg .= $langs->trans('BalanceAfterTransfer').': '.price($after, 0, $langs, 1, -1, -1, $cur);
				setEventMessages($msg, null, 'errors');
			}
		}

		return $blocked;
	}

	/**
	 * Build the read-only panel of the linked transfer line displayed on the right of the
	 * bank line edit form, plus a confirmation prompt warning the user that saving will also
	 * impact the linked line.
	 *
	 * @param Translate $langs  Language object
	 * @param int       $lineid Bank line rowid being edited
	 * @return string            HTML (hidden source markup + script), or '' when no linked line
	 */
	private function getLinkedLinePanelJs($langs, $lineid)
	{
		global $conf;

		$langs->load('bankaudit@bankaudit');

		$linked = BankAudit::getLinkedLineDisplay($this->db, $lineid);
		if ($linked === null) {
			return '';
		}

		$amountStr = price($linked['amount'], 0, $langs, 1, -1, -1, $linked['currency_code']);
		$dateoStr = $linked['dateo'] ? dol_print_date($linked['dateo'], 'day') : '';
		$datevStr = $linked['datev'] ? dol_print_date($linked['datev'], 'day') : '';
		$lineUrl = dol_buildpath('/compta/bank/line.php', 1).'?rowid='.((int) $linked['id']);

		$card  = '<div class="bankaudit-linked-card" style="border:1px solid #ddd;border-radius:6px;padding:8px 10px;background:#fbfbfb">';
		$card .= '<div class="tabsAction" style="margin:0 0 6px 0;font-weight:bold">'.img_picto('', 'bank_account', 'class="paddingright"').dol_escape_htmltag($langs->trans('LinkedTransferLine')).'</div>';
		$card .= '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('LinkedTransferLineReadonly')).'</span>';
		$card .= '<table class="border centpercent tableforfield" style="margin-top:6px">';
		$card .= '<tr><td class="titlefield">'.$langs->trans('BankAccount').'</td><td>'.dol_escape_htmltag($linked['account_name']).'</td></tr>';
		$card .= '<tr><td>'.$langs->trans('Type').'</td><td>'.dol_escape_htmltag($linked['type']).'</td></tr>';
		$card .= '<tr><td>'.$langs->trans('DateOperationShort').'</td><td>'.dol_escape_htmltag($dateoStr).'</td></tr>';
		$card .= '<tr><td>'.$langs->trans('DateValueShort').'</td><td>'.dol_escape_htmltag($datevStr).'</td></tr>';
		$card .= '<tr><td>'.$langs->trans('Label').'</td><td>'.dol_escape_htmltag($linked['label']).'</td></tr>';
		$card .= '<tr><td>'.$langs->trans('Amount').'</td><td class="amount">'.$amountStr.'</td></tr>';
		$card .= '</table>';
		$card .= '<div class="center" style="margin-top:8px"><a class="butAction" href="'.dol_escape_htmltag($lineUrl).'">'.$langs->trans('ViewLinkedLine').'</a></div>';
		$card .= '</div>';

		$confirmMsg = $langs->transnoentitiesnoconv('LinkedLineUpdateConfirm', $linked['account_name']);

		$out  = "\n<!-- BankAudit linked line panel -->\n";
		$out .= '<div id="bankaudit_linked_src" style="display:none">'.$card.'</div>'."\n";
		$out .= '<script type="text/javascript">'."\n";
		$out .= "(function(){\n";
		$out .= "var confirmMsg=".json_encode($confirmMsg).";\n";
		$out .= "var confirmTitle=".json_encode($langs->transnoentitiesnoconv('LinkedLineUpdateTitle')).";\n";
		$out .= "var lblYes=".json_encode($langs->transnoentitiesnoconv('Yes')).";\n";
		$out .= "var lblNo=".json_encode($langs->transnoentitiesnoconv('No')).";\n";
		$out .= <<<'JS'
var form=document.querySelector('form[name="update"]');
var src=document.getElementById('bankaudit_linked_src');
if(form&&src){
	// Place the read-only linked-line panel on the SAME ROW as the editable fields table,
	// keeping everything inside the white content card (do not move the whole form/tabs).
	var table=form.querySelector('table.tableforfield') || form.querySelector('table.border');
	if(table){
		var wrap=document.createElement('div');
		wrap.className='bankaudit-flexwrap';
		wrap.style.display='flex'; wrap.style.flexWrap='wrap'; wrap.style.gap='16px'; wrap.style.alignItems='flex-start';
		table.parentNode.insertBefore(wrap, table);
		var left=document.createElement('div');
		left.style.flex='2 1 460px'; left.style.minWidth='300px';
		left.appendChild(table);
		wrap.appendChild(left);
		var panel=document.createElement('div');
		panel.style.flex='1 1 300px'; panel.style.minWidth='260px'; panel.style.maxWidth='430px';
		panel.innerHTML=src.innerHTML;
		wrap.appendChild(panel);
	}
	if(src.parentNode){ src.parentNode.removeChild(src); }
	// Confirmation before saving: use Dolibarr's jQuery UI dialog instead of a raw browser confirm.
	var confirmed=false;
	form.addEventListener('submit', function(e){
		if(confirmed){ return true; }
		e.preventDefault();
		if(window.jQuery && jQuery.fn && jQuery.fn.dialog){
			var $d=jQuery('<div></div>').html('<p style="padding-top:10px">'+confirmMsg+'</p>');
			jQuery('body').append($d);
			$d.dialog({
				title: confirmTitle, modal:true, resizable:false, width:440, closeOnEscape:true,
				buttons: [
					{ text: lblYes, "class":"button", click: function(){ confirmed=true; jQuery(this).dialog('close'); form.submit(); } },
					{ text: lblNo, "class":"button button-cancel", click: function(){ jQuery(this).dialog('close'); } }
				],
				close: function(){ jQuery(this).remove(); }
			});
		} else if(window.confirm(confirmMsg)){
			confirmed=true; form.submit();
		}
		return false;
	});
}
JS;
		$out .= "\n})();\n</script>\n";

		return $out;
	}

	/**
	 * Guard injected on the bank line edit page (lines without a linked transfer):
	 * if the user flips the sign of the amount (debit <-> credit), a Dolibarr jQuery UI
	 * dialog warns about the consequences and asks for confirmation before saving.
	 *
	 * @param Translate $langs  Language object
	 * @param int       $lineid Bank line rowid
	 * @return string            HTML <script> block
	 */
	private function getSensChangeGuardJs($langs, $lineid)
	{
		$langs->load('bankaudit@bankaudit');

		$out = "\n<!-- BankAudit sens-change guard -->\n";
		$out .= '<script type="text/javascript">'."\n";
		$out .= "(function(){\n";
		$out .= "var warnMsg=".json_encode($langs->transnoentitiesnoconv('BankAuditSensChangeWarning')).";\n";
		$out .= "var warnTitle=".json_encode($langs->transnoentitiesnoconv('BankAuditSensChangeTitle')).";\n";
		$out .= "var lblYes=".json_encode($langs->transnoentitiesnoconv('Yes')).";\n";
		$out .= "var lblNo=".json_encode($langs->transnoentitiesnoconv('No')).";\n";
		$out .= <<<'JS'
var form=document.querySelector('form[name="update"]');
if(form){
	var amt=form.querySelector('input[name="amount"]');
	if(amt){
		function signOf(v){
			if(v===null||v===undefined){ return 0; }
			var n=parseFloat((''+v).replace(/[^0-9.,\-]/g,'').replace(',', '.'));
			if(isNaN(n)){ return 0; }
			return n<0?-1:(n>0?1:0);
		}
		var originalSign=signOf(amt.value);
		var confirmed=false;
		form.addEventListener('submit', function(e){
			if(confirmed){ return true; }
			var newSign=signOf(amt.value);
			if(originalSign!==0 && newSign!==0 && newSign!==originalSign){
				e.preventDefault();
				if(window.jQuery && jQuery.fn && jQuery.fn.dialog){
					var $d=jQuery('<div></div>').html('<p style="padding-top:10px">'+warnMsg+'</p>');
					jQuery('body').append($d);
					$d.dialog({
						title: warnTitle, modal:true, resizable:false, width:460, closeOnEscape:true,
						buttons: [
							{ text: lblYes, "class":"button", click: function(){ confirmed=true; jQuery(this).dialog('close'); form.submit(); } },
							{ text: lblNo, "class":"button button-cancel", click: function(){ jQuery(this).dialog('close'); } }
						],
						close: function(){ jQuery(this).remove(); }
					});
				} else if(window.confirm(warnMsg)){
					confirmed=true; form.submit();
				}
				return false;
			}
			return true;
		});
	}
}
JS;
		$out .= "\n})();\n</script>\n";

		return $out;
	}

	/**
	 * Build the JavaScript assistant injected at the bottom of the internal transfer page:
	 *  - automatic destination amount conversion (P3) and live source balance check (P2),
	 *  - compact account dropdowns showing only the account reference,
	 *  - a delete icon to remove a line and a cancel button to go back to the list.
	 *
	 * @param Translate $langs Language object
	 * @return string           HTML <script> block
	 */
	private function getTransferAssistantJs($langs)
	{
		$langs->load('bankaudit@bankaudit');

		$out = "\n<!-- BankAudit transfer assistant -->\n";
		$out .= '<script type="text/javascript">'."\n";
		$out .= "(function(){\n";
		$out .= "var rateUrl=".json_encode(dol_buildpath('/bankaudit/ajax/get_conversion_rate.php', 1)).";\n";
		$out .= "var infoUrl=".json_encode(dol_buildpath('/bankaudit/ajax/get_account_info.php', 1)).";\n";
		$out .= "var listUrl=".json_encode(dol_buildpath('/compta/bank/bankentries_list.php', 1)).";\n";
		$out .= "var token=".json_encode(newToken()).";\n";
		$out .= "var guardEnabled=".(BankAudit::isBalanceGuardEnabled() ? 'true' : 'false').";\n";
		$out .= "var refMap=".json_encode(BankAudit::getAccountsRefMap($this->db)).";\n";
		$out .= "var L_insufficient=".json_encode($langs->transnoentitiesnoconv('InsufficientBalance')).";\n";
		$out .= "var L_available=".json_encode($langs->transnoentitiesnoconv('AvailableBalance')).";\n";
		$out .= "var L_after=".json_encode($langs->transnoentitiesnoconv('BalanceAfterTransfer')).";\n";
		$out .= "var L_cancel=".json_encode($langs->transnoentitiesnoconv('Cancel')).";\n";
		$out .= "var L_removeLine=".json_encode($langs->transnoentitiesnoconv('RemoveLine')).";\n";
		$out .= "var L_autoComputed=".json_encode($langs->transnoentitiesnoconv('AutoComputedField')).";\n";
		$out .= <<<'JS'
function selCur(sel){ if(!sel||sel.selectedIndex<0){return '';} var o=sel.options[sel.selectedIndex]; return o?(o.getAttribute('data-currency-code')||''):''; }
function num(v){ v=(''+v).replace(/\s/g,'').replace(',','.'); var f=parseFloat(v); return isNaN(f)?0:f; }
function fmt(n){ return (Math.round(n*100)/100).toFixed(2); }
function refreshSelect2(sel){ if(window.jQuery){ try{ jQuery(sel).trigger('change.select2'); }catch(e){} } }
var violations={};
var warnBox=document.createElement('div');
warnBox.id='bankaudit_transfer_warning';
warnBox.className='warning';
warnBox.style.display='none';
warnBox.style.margin='6px 0';
var form=document.querySelector('form[name="add"]');
if(form&&form.parentNode){ form.parentNode.insertBefore(warnBox, form); }
function renderWarn(){
	var msgs=[]; for(var k in violations){ if(violations[k]){ msgs.push(violations[k]); } }
	if(msgs.length){ warnBox.innerHTML=msgs.join('<br>'); warnBox.style.display='block'; }
	else { warnBox.innerHTML=''; warnBox.style.display='none'; }
}
// Compact account dropdowns: show only the account reference to save horizontal space.
function applyRefLabels(){
	var sels=document.querySelectorAll('select[name$="_account_from"], select[name$="_account_to"]');
	Array.prototype.forEach.call(sels, function(sel){
		Array.prototype.forEach.call(sel.options, function(o){
			if(o.value && refMap[o.value]){ o.textContent=refMap[o.value]; }
		});
		refreshSelect2(sel);
	});
}
// Remove (clear + hide) a transfer line so it is not processed on submit.
function clearRow(tr){
	var sels=tr.querySelectorAll('select[name$="_account_from"], select[name$="_account_to"]');
	Array.prototype.forEach.call(sels, function(s){ s.value='-1'; refreshSelect2(s); });
	var ins=tr.querySelectorAll('input[type="text"]');
	Array.prototype.forEach.call(ins, function(inp){ inp.value=''; });
	var sf=tr.querySelector('select[name$="_account_from"]');
	if(sf){ var p=sf.name.replace('_account_from',''); violations[p]=''; renderWarn(); }
	tr.style.display='none';
}
// Add the action column header and a delete icon on each transfer line.
function addRowTools(){
	var table=document.getElementById('tablemouvbank');
	if(!table){ return; }
	var thead=table.querySelector('tr.liste_titre');
	if(thead && !thead.querySelector('.bankaudit-actcol')){
		var th=document.createElement('th'); th.className='bankaudit-actcol center'; thead.appendChild(th);
	}
	var rows=table.querySelectorAll('tr.numvir');
	Array.prototype.forEach.call(rows, function(tr){
		if(tr.querySelector('.bankaudit-delcell')){ return; }
		var td=document.createElement('td'); td.className='bankaudit-delcell center';
		var a=document.createElement('a'); a.href='#'; a.title=L_removeLine; a.className='bankaudit-del reposition';
		a.innerHTML='<span class="fa fa-trash" style="color:#a00"></span>';
		a.addEventListener('click', function(ev){ ev.preventDefault(); clearRow(tr); });
		td.appendChild(a); tr.appendChild(td);
	});
}
// Add a cancel button next to the save button.
function addCancelButton(){
	var btncont=document.getElementById('btncont');
	if(btncont && !document.getElementById('bankaudit_cancel')){
		var cancel=document.createElement('a');
		cancel.id='bankaudit_cancel';
		cancel.className='button button-cancel';
		cancel.href=listUrl;
		cancel.style.marginLeft='10px';
		cancel.textContent=L_cancel;
		btncont.appendChild(cancel);
	}
}
applyRefLabels();
addRowTools();
addCancelButton();
var froms=document.querySelectorAll('select[name$="_account_from"]');
Array.prototype.forEach.call(froms, function(selFrom){
	var prefix=selFrom.name.replace('_account_from','');
	var selTo=document.querySelector('select[name="'+prefix+'_account_to"]');
	var inpAmount=document.querySelector('input[name="'+prefix+'_amount"]');
	var inpDest=document.querySelector('input[name="'+prefix+'_amountto"]');
	// Destination amount is computed automatically => read-only.
	if(inpDest){
		inpDest.readOnly=true;
		inpDest.setAttribute('readonly','readonly');
		inpDest.style.backgroundColor='#eee';
		inpDest.style.cursor='not-allowed';
		inpDest.title=L_autoComputed;
	}
	function updateConv(){
		if(!selTo||!inpAmount){ return; }
		var fromCur=selCur(selFrom), toCur=selCur(selTo), amt=num(inpAmount.value);
		if(!fromCur||!toCur||!amt){ if(inpDest){ inpDest.value=''; } return; }
		if(fromCur===toCur){ if(inpDest){ inpDest.value=fmt(amt); } return; }
		var url=rateUrl+'?token='+encodeURIComponent(token)+'&from='+encodeURIComponent(fromCur)+'&to='+encodeURIComponent(toCur);
		fetch(url,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
			if(d&&d.rate&&inpDest){ inpDest.value=fmt(amt*d.rate); }
		}).catch(function(){});
	}
	function checkBal(){
		if(!guardEnabled){ violations[prefix]=''; renderWarn(); return; }
		var accId=selFrom.value;
		var amt=Math.abs(num(inpAmount?inpAmount.value:0));
		if(!accId||parseInt(accId,10)<=0){ violations[prefix]=''; renderWarn(); return; }
		var url=infoUrl+'?token='+encodeURIComponent(token)+'&id='+encodeURIComponent(accId);
		fetch(url,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
			if(!d||d.threshold===null||typeof d.threshold==='undefined'){ violations[prefix]=''; renderWarn(); return; }
			var after=d.balance-amt;
			if(after<d.threshold){
				violations[prefix]=L_insufficient+' (#'+prefix+') - '+(d.name?d.name+' - ':'')+L_available+': '+fmt(d.balance)+' '+(d.currency||'')+' / '+L_after+': '+fmt(after)+' '+(d.currency||'');
			} else { violations[prefix]=''; }
			renderWarn();
		}).catch(function(){});
	}
	function onChange(){ updateConv(); checkBal(); }
	// Account selects are select2 widgets: bind via jQuery so their change events are caught,
	// otherwise the destination amount would only refresh when editing the amount field.
	if(window.jQuery){
		if(selFrom){ jQuery(selFrom).on('change', onChange); }
		if(selTo){ jQuery(selTo).on('change', updateConv); }
	} else {
		if(selFrom){ selFrom.addEventListener('change', onChange); }
		if(selTo){ selTo.addEventListener('change', updateConv); }
	}
	if(inpAmount){
		['change','input'].forEach(function(ev){ inpAmount.addEventListener(ev,onChange); });
	}
	// Initial computation for pre-filled rows.
	updateConv();
});
if(form){
	form.addEventListener('submit', function(e){
		for(var k in violations){ if(violations[k]){ e.preventDefault(); renderWarn(); if(warnBox.scrollIntoView){ warnBox.scrollIntoView(); } return false; } }
	});
}
JS;
		$out .= "\n})();\n</script>\n";

		return $out;
	}
}
