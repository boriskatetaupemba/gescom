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
 * \file    custom/posnova/class/posticket.class.php
 * \ingroup posnova
 * \brief   POS ticket: atomic sale = Dolibarr invoice + payments + warehouse stock-out.
 *
 * Currency model: the Dolibarr invoice is kept in the company currency (USD in the RDC
 * context); the POS working currency (USD or CDF) is a presentation/cash layer converted
 * through the daily rate. The exact per-account, per-currency breakdown is stored in the
 * dedicated POS tables, which are the source of truth for the cash drawer.
 */

dol_include_once('/posnova/class/posnova.class.php');
dol_include_once('/posnova/class/posconfig.class.php');


/**
 * Build and persist a POS ticket within a single database transaction.
 */
class PosTicket
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	public $id;
	public $ref;
	public $fk_facture;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Validate a sale atomically.
	 *
	 * Server-side enforcement (never trust the client):
	 *  - price lock when the terminal forbids price edition;
	 *  - discount cap unless the user holds the 'discount' right;
	 *  - stock guard unless the terminal allows sale without stock;
	 *  - payment coverage and change <= surplus.
	 *
	 * @param  User       $user    Acting vendor
	 * @param  PosSession $session Open session
	 * @param  PosConfig  $pos     Terminal configuration
	 * @param  array      $payload socid, invoice_date, lines[], payments[], changes[], force_negative
	 * @return array               array('ok'=>bool, 'ticket_id'=>int, 'ref'=>string, 'facture_id'=>int, 'error'=>string)
	 */
	public function validateSale($user, $session, $pos, $payload)
	{
		global $conf, $langs, $mysoc;

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$fail = function ($msg) {
			$this->db->rollback();
			return array('ok' => false, 'ticket_id' => 0, 'ref' => '', 'facture_id' => 0, 'error' => $msg);
		};

		$posCurrency = $pos->getWorkingCurrency();
		$companyCurrency = strtoupper(empty($conf->currency) ? PosNova::USD : $conf->currency);
		$rateInfo = PosNova::getDailyRate($this->db);
		$rate = (float) $rateInfo['rate'];

		$lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : array();
		$payments = isset($payload['payments']) && is_array($payload['payments']) ? $payload['payments'] : array();
		$changes = isset($payload['changes']) && is_array($payload['changes']) ? $payload['changes'] : array();
		if (empty($lines)) {
			return array('ok' => false, 'error' => 'PosNovaErrEmptyCart', 'ticket_id' => 0, 'ref' => '', 'facture_id' => 0);
		}

		// Resolve the customer (cart value or terminal default).
		$socid = !empty($payload['socid']) ? (int) $payload['socid'] : (int) $pos->fk_default_customer;
		if ($socid <= 0) {
			return array('ok' => false, 'error' => 'PosNovaErrNoCustomer', 'ticket_id' => 0, 'ref' => '', 'facture_id' => 0);
		}

		$canForceDiscount = $user->hasRight('posnova', 'discount');
		$priceOverrideUsed = false;
		$discountOverrideUsed = false;

		$this->db->begin();

		// --- Build the invoice (company currency) ---
		$facture = new Facture($this->db);
		$facture->socid = $socid;
		$facture->type = Facture::TYPE_STANDARD;
		$facture->date = dol_now();
		if ($pos->allow_backdating && !empty($payload['invoice_date'])) {
			$bd = dol_stringtotime($payload['invoice_date']);
			if ($bd > 0) {
				$facture->date = $bd;
			}
		}
		$facture->cond_reglement_id = 0;
		$facture->mode_reglement_id = dol_getIdFromCode($this->db, $pos->default_payment_mode, 'c_paiement', 'code', 'id', 1);
		$facture->module_source = 'posnova';
		$facture->pos_source = (string) $pos->id;
		$facture->fk_warehouse = (int) $pos->fk_warehouse;

		if ($facture->create($user) <= 0) {
			return $fail($facture->error ? $facture->error : 'PosNovaErrInvoiceCreate');
		}

		// Compute POS-currency totals as we add lines, for coverage control.
		$posTotalTtc = 0.0;
		$posTotalHtRaw = 0.0;
		$preparedLines = array();

		foreach ($lines as $ln) {
			$pid = (int) (isset($ln['product_id']) ? $ln['product_id'] : 0);
			$qty = (float) (isset($ln['qty']) ? $ln['qty'] : 0);
			$discount = (float) (isset($ln['discount']) ? $ln['discount'] : 0);
			if ($pid <= 0 || $qty <= 0) {
				return $fail('PosNovaErrBadLine');
			}

			$product = new Product($this->db);
			if ($product->fetch($pid) <= 0) {
				return $fail('PosNovaErrProductNotFound');
			}
			// Hors-vente products can never be sold, even if forced from the client.
			if ($product->status == 0) {
				return $fail('PosNovaErrProductNotForSale');
			}

			$tva_tx = (float) $product->tva_tx;
			$catalogTtcCompany = (float) $product->price_ttc; // TTC in company currency
			if ($catalogTtcCompany <= 0) {
				$catalogTtcCompany = (float) $product->price * (1 + $tva_tx / 100);
			}

			// Price the line in POS currency (TTC: the displayed all-in price the customer pays).
			$catalogTtcPos = PosNova::convert($catalogTtcCompany, $companyCurrency, $posCurrency, $rate);
			$priceModified = 0;
			$lineTtcPosUnit = $catalogTtcPos;

			if ($pos->allow_price_edit && isset($ln['price']) && $ln['price'] !== '' && (float) $ln['price'] >= 0) {
				$clientPos = (float) $ln['price'];
				if (abs($clientPos - $catalogTtcPos) > 0.00001) {
					$lineTtcPosUnit = $clientPos;
					$priceModified = 1;
					$priceOverrideUsed = true;
				}
			}
			// Server-side price lock: any client price is ignored when edition is disabled.
			$lineTtcCompany = PosNova::convert($lineTtcPosUnit, $posCurrency, $companyCurrency, $rate);

			// Discount cap enforcement.
			if ($discount > 0 && $discount > (float) $pos->max_discount_percent) {
				if (!$canForceDiscount) {
					return $fail('PosNovaErrDiscountOverCap');
				}
				$discountOverrideUsed = true;
			}

			// Stock guard on the POS warehouse.
			$available = $this->getWarehouseStock($pid, (int) $pos->fk_warehouse);
			if (!$pos->allow_sale_without_stock && $available < $qty) {
				return $fail('PosNovaErrInsufficientStock');
			}

			// Add the invoice line (company currency, TTC base so the customer pays the displayed price).
			$res = $facture->addline(
				$product->label,
				0,
				$qty,
				$tva_tx,
				0,
				0,
				$pid,
				$discount,
				'',
				'',
				0,
				0,
				'',
				'TTC',
				$lineTtcCompany
			);
			if ($res <= 0) {
				return $fail($facture->error ? $facture->error : 'PosNovaErrAddLine');
			}

			$lineTtcPos = PosNova::roundAmount($lineTtcPosUnit * $qty * (1 - $discount / 100), $posCurrency);
			$lineHtPos = $tva_tx > 0 ? ($lineTtcPos / (1 + $tva_tx / 100)) : $lineTtcPos;
			$posTotalTtc += $lineTtcPos;
			$posTotalHtRaw += $lineHtPos;

			$preparedLines[] = array(
				'fk_product' => $pid,
				'label' => $product->label,
				'qty' => $qty,
				'price_unit' => $lineTtcPosUnit,
				'price_modified' => $priceModified,
				'discount_pct' => $discount,
				'tva_tx' => $tva_tx,
				'total_ht' => PosNova::roundAmount($lineHtPos, $posCurrency),
				'total_ttc' => $lineTtcPos,
			);
		}

		$posTotalTtc = PosNova::roundAmount($posTotalTtc, $posCurrency);
		$posTotalHt = PosNova::roundAmount($posTotalHtRaw, $posCurrency);
		$posTotalTva = PosNova::roundAmount($posTotalTtc - $posTotalHt, $posCurrency);

		// --- Payment coverage control (POS currency) ---
		$paidPos = 0.0;
		foreach ($payments as $p) {
			$amt = (float) (isset($p['amount']) ? $p['amount'] : 0);
			$cur = strtoupper((string) (isset($p['currency']) ? $p['currency'] : $posCurrency));
			if ($amt <= 0) {
				continue;
			}
			$paidPos += PosNova::convert($amt, $cur, $posCurrency, $rate);
		}
		$paidPos = PosNova::roundAmount($paidPos, $posCurrency);

		$changePos = 0.0;
		foreach ($changes as $c) {
			$amt = (float) (isset($c['amount']) ? $c['amount'] : 0);
			$cur = strtoupper((string) (isset($c['currency']) ? $c['currency'] : $posCurrency));
			if ($amt <= 0) {
				continue;
			}
			$changePos += PosNova::convert($amt, $cur, $posCurrency, $rate);
		}
		$changePos = PosNova::roundAmount($changePos, $posCurrency);

		// Coverage: paid must cover the total; change cannot exceed the surplus.
		if ($paidPos + 0.00001 < $posTotalTtc) {
			return $fail('PosNovaErrPaymentNotCovered');
		}
		$surplus = PosNova::roundAmount($paidPos - $posTotalTtc, $posCurrency);
		if ($changePos > $surplus + 0.00001) {
			return $fail('PosNovaErrChangeTooHigh');
		}

		// --- Validate the invoice (no auto stock: we manage it on the POS warehouse) ---
		if ($facture->validate($user, '', 0) <= 0) {
			return $fail($facture->error ? $facture->error : 'PosNovaErrInvoiceValidate');
		}

		// --- Stock out on the POS warehouse ---
		foreach ($preparedLines as $pl) {
			$mv = new MouvementStock($this->db);
			$mv->origin = $facture;
			$label = $langs->trans('PosNovaStockOutLabel');
			$resmv = $mv->livraison($user, $pl['fk_product'], (int) $pos->fk_warehouse, $pl['qty'], 0, $label, dol_now(), '', '', '', 0, 'POSNOVA');
			if ($resmv < 0) {
				return $fail($mv->error ? $mv->error : 'PosNovaErrStockOut');
			}
		}

		// --- Persist the POS ticket ---
		$ref = $this->buildRef($pos);
		$now = dol_now();
		$isBackdated = ($pos->allow_backdating && !empty($payload['invoice_date']) && dol_print_date($facture->date, '%Y-%m-%d') !== dol_print_date($now, '%Y-%m-%d')) ? 1 : 0;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_ticket";
		$sql .= " (entity, ref, fk_session, fk_pos, fk_facture, fk_soc, currency, rate_usd_cdf,";
		$sql .= " total_ht, total_tva, total_ttc, total_discount, is_backdated, invoice_date, status, sync_status, created_offline, fk_user_creat, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', ".((int) $session->id).", ".((int) $pos->id).", ".((int) $facture->id).", ".((int) $socid).",";
		$sql .= " '".$this->db->escape($posCurrency)."', ".((float) $rate).",";
		$sql .= " ".((float) $posTotalHt).", ".((float) $posTotalTva).", ".((float) $posTotalTtc).", 0, ".$isBackdated.",";
		$sql .= " '".$this->db->escape(dol_print_date($facture->date, '%Y-%m-%d'))."', 'VALIDATED', 'SYNCED', 0, ".((int) $user->id).", '".$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			return $fail($this->db->lasterror());
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."pos_ticket");
		$this->ref = $ref;
		$this->fk_facture = (int) $facture->id;

		$pos_pos = 0;
		foreach ($preparedLines as $pl) {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_ticket_line";
			$sql .= " (fk_ticket, fk_product, label, qty, price_unit, price_modified, discount_pct, tva_tx, total_ht, total_ttc, position)";
			$sql .= " VALUES (".((int) $this->id).", ".((int) $pl['fk_product']).", '".$this->db->escape($pl['label'])."', ".((float) $pl['qty']).",";
			$sql .= " ".((float) $pl['price_unit']).", ".((int) $pl['price_modified']).", ".((float) $pl['discount_pct']).", ".((float) $pl['tva_tx']).",";
			$sql .= " ".((float) $pl['total_ht']).", ".((float) $pl['total_ttc']).", ".($pos_pos++).")";
			if (!$this->db->query($sql)) {
				return $fail($this->db->lasterror());
			}
		}

		// --- Persist payments + create Dolibarr payments posted to the bank accounts ---
		foreach ($payments as $p) {
			$amt = (float) (isset($p['amount']) ? $p['amount'] : 0);
			if ($amt <= 0) {
				continue;
			}
			$accId = (int) (isset($p['account_id']) ? $p['account_id'] : 0);
			$cur = strtoupper((string) (isset($p['currency']) ? $p['currency'] : $posCurrency));
			$mode = (string) (isset($p['mode']) ? $p['mode'] : $pos->default_payment_mode);
			$refExt = isset($p['ref']) ? (string) $p['ref'] : '';

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_payment (fk_ticket, fk_account, amount, currency, mode, ref_ext, datec)";
			$sql .= " VALUES (".((int) $this->id).", ".$accId.", ".((float) $amt).", '".$this->db->escape($cur)."', '".$this->db->escape($mode)."',";
			$sql .= " ".($refExt === '' ? "NULL" : "'".$this->db->escape($refExt)."'").", '".$this->db->idate($now)."')";
			if (!$this->db->query($sql)) {
				return $fail($this->db->lasterror());
			}
			$posPaymentId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."pos_payment");

			// Settle the invoice (company currency). The exact account currency amount lives above.
			if ($accId > 0) {
				$amtCompany = PosNova::convert($amt, $cur, $companyCurrency, $rate);
				$amtCompany = min($amtCompany, (float) $facture->total_ttc); // never over-pay the invoice line
				if ($amtCompany > 0) {
					$paiement = new Paiement($this->db);
					$paiement->datepaye = $now;
					$paiement->amounts = array($facture->id => $amtCompany);
					$paiement->paiementid = dol_getIdFromCode($this->db, $mode, 'c_paiement', 'code', 'id', 1);
					$paiement->num_payment = $refExt;
					$pid = $paiement->create($user);
					if ($pid > 0) {
						$paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $accId, '', '');
						$this->db->query("UPDATE ".MAIN_DB_PREFIX."pos_payment SET fk_payment = ".((int) $pid)." WHERE rowid = ".$posPaymentId);
					}
				}
			}
		}

		// --- Persist change given ---
		foreach ($changes as $c) {
			$amt = (float) (isset($c['amount']) ? $c['amount'] : 0);
			if ($amt <= 0) {
				continue;
			}
			$accId = (int) (isset($c['account_id']) ? $c['account_id'] : 0);
			$cur = strtoupper((string) (isset($c['currency']) ? $c['currency'] : $posCurrency));
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."pos_change (fk_ticket, fk_account, amount, currency)";
			$sql .= " VALUES (".((int) $this->id).", ".$accId.", ".((float) $amt).", '".$this->db->escape($cur)."')";
			if (!$this->db->query($sql)) {
				return $fail($this->db->lasterror());
			}
		}

		// Mark the invoice paid.
		$facture->fetch($facture->id);
		$remain = $facture->getRemainToPay();
		if ($remain <= 0) {
			$facture->setPaid($user);
		}

		// --- Audit ---
		PosNova::audit($this->db, $user, 'SALE', 'pos_ticket', $this->id, null, $ref.' / '.PosNova::formatAmount($posTotalTtc, $posCurrency), $pos->id);
		if ($priceOverrideUsed) {
			PosNova::audit($this->db, $user, 'PRICE_OVERRIDE', 'pos_ticket', $this->id, null, $ref, $pos->id);
		}
		if ($discountOverrideUsed) {
			PosNova::audit($this->db, $user, 'DISCOUNT_OVERRIDE', 'pos_ticket', $this->id, null, $ref, $pos->id);
		}

		$session->touch();
		$this->db->commit();

		return array('ok' => true, 'ticket_id' => $this->id, 'ref' => $ref, 'facture_id' => (int) $facture->id, 'error' => '');
	}

	/**
	 * Read the available (reel) stock of a product on a warehouse.
	 *
	 * @param  int $pid  Product id
	 * @param  int $whid Warehouse id
	 * @return float     Available quantity
	 */
	public function getWarehouseStock($pid, $whid)
	{
		$sql = "SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $pid)." AND fk_entrepot = ".((int) $whid);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql)) {
			return (float) $this->db->fetch_object($resql)->reel;
		}
		return 0.0;
	}

	/**
	 * Build a gap-free per-terminal ticket reference: {posref}-{YYYY}-{NNNNN}.
	 * Generated server-side only, even for offline tickets at sync time.
	 *
	 * @param  PosConfig $pos Terminal
	 * @return string         Reference
	 */
	private function buildRef($pos)
	{
		global $conf;

		$year = dol_print_date(dol_now(), '%Y');
		$prefix = $pos->ref.'-'.$year.'-';
		$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."pos_ticket";
		$sql .= " WHERE fk_pos = ".((int) $pos->id)." AND entity = ".((int) $conf->entity);
		$sql .= " AND ref LIKE '".$this->db->escape($prefix)."%'";
		$resql = $this->db->query($sql);
		$seq = 1;
		if ($resql && $this->db->num_rows($resql)) {
			$seq = ((int) $this->db->fetch_object($resql)->nb) + 1;
		}
		return $prefix.sprintf('%05d', $seq);
	}
}
