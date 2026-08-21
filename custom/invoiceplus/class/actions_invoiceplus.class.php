<?php
/* Copyright (C) 2026 InvoicePlus module */

/**
 * \file    custom/invoiceplus/class/actions_invoiceplus.class.php
 * \ingroup invoiceplus
 * \brief   Non-core invoice, product and warehouse card hooks.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/invoiceplus/class/invoicepluspricelevelservice.class.php');

/** Enrich native invoice payment rows without changing native records. */
class ActionsInvoiceplus extends CommonHookActions
{
	/** Hidden marker proving a submission comes from the InvoicePlus create form. */
	const MULTIPRICES_FORM_MARKER = 'invoiceplus_multiprices_form';

	/** Key carrying the validated levels from the form hook to the create trigger. */
	const PRICE_LEVELS_CONTEXT_KEY = 'invoiceplus_price_levels';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array */
	public $errors = array();

	/** @var array */
	public $results = array();

	/** @var string|null */
	public $resprints;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Validate module-owned card submissions before the native processing.
	 *
	 * Returning a negative value makes the calling card skip its own action
	 * block, so nothing is created or updated when validation fails.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action, may be rewritten
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to keep the native action, -1 to refuse it
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		if (!isModEnabled('invoiceplus')) {
			return 0;
		}
		$context = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];

		if ($context === 'productcard' && $action === 'add') {
			return $this->prepareProductPriceLevels($object, $action);
		}
		if ($context === 'warehousecard' && ($action === 'add' || $action === 'update')) {
			return $this->normalizeWarehousePriceLevel($action);
		}

		return 0;
	}

	/**
	 * Print the module-owned rows of the product and warehouse cards.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current object
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 so the native extrafields keep printing
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		if (!isModEnabled('invoiceplus') || !InvoicePlusPriceLevelService::isMultiPriceEnabled()) {
			return 0;
		}
		$context = empty($parameters['currentcontext']) ? '' : $parameters['currentcontext'];

		// The native Prices tab remains the only edition interface, so the grid
		// is offered during creation only.
		if ($context === 'productcard' && $action === 'create') {
			$this->resprints = $this->buildProductPriceLevelRows($parameters);
			return 0;
		}
		if ($context === 'warehousecard') {
			$this->resprints = $this->buildWarehousePriceLevelRow($parameters, $object, $action);
			return 0;
		}

		return 0;
	}

	/**
	 * Validate the submitted grid and hand the upper levels to the trigger.
	 *
	 * Level 1 keeps flowing through the native price and price_base_type fields
	 * of Product::create(); only levels 2 to N are carried in the object context.
	 *
	 * @param CommonObject $object Product being created
	 * @param string       $action Current action, reset to create on error
	 * @return int                 0 to keep the native creation, -1 to refuse it
	 */
	private function prepareProductPriceLevels(&$object, &$action)
	{
		global $langs, $user;

		if (!is_object($object) || $object->element !== 'product') {
			return 0;
		}
		// The marker limits the treatment to this module's form. A product
		// created by the native API or by another module never carries it.
		if (GETPOSTINT(self::MULTIPRICES_FORM_MARKER) !== 1) {
			return 0;
		}
		if (!InvoicePlusPriceLevelService::isMultiPriceEnabled()) {
			return 0;
		}
		// The native create rights and the native CSRF token stay authoritative;
		// the hook only refuses, it never grants.
		$isService = (GETPOSTINT('type') === 1); // Product::TYPE_SERVICE
		if (!$user->hasRight($isService ? 'service' : 'produit', 'creer')) {
			return 0;
		}

		$limit = InvoicePlusPriceLevelService::getPriceLevelLimit();
		$submitted = self::collectSubmittedPriceLevels($limit);
		$parsed = self::parseSubmittedPriceLevels($submitted['prices'], $submitted['base_types'], $limit);

		if (count($parsed['errors']) > 0) {
			$langs->loadLangs(array('products', 'invoiceplus@invoiceplus'));
			foreach ($parsed['errors'] as $error) {
				$this->errors[] = $langs->trans($error['code'], $error['params'][0]);
			}
			// Go back to the create form; every submitted value is re-read from
			// the request by the native form and by this hook.
			$action = 'create';
			return -1;
		}

		$upperLevels = $parsed['levels'];
		unset($upperLevels[1]);
		if (count($upperLevels) === 0) {
			return 0;
		}
		if (!is_array($object->context)) {
			$object->context = array();
		}
		$object->context[self::PRICE_LEVELS_CONTEXT_KEY] = $upperLevels;

		return 0;
	}

	/**
	 * Validate and canonicalize the warehouse level before the native write.
	 *
	 * The value is persisted by the native extrafield API. Normalizing the
	 * request here keeps an empty selection stored as NULL by the native int
	 * handling instead of an implicit zero.
	 *
	 * @param string $action Current action, reset to the form action on error
	 * @return int           0 to keep the native action, -1 to refuse it
	 */
	private function normalizeWarehousePriceLevel(&$action)
	{
		global $langs, $user;

		if (!InvoicePlusPriceLevelService::isMultiPriceEnabled()) {
			return 0;
		}
		if (!$user->hasRight('stock', 'creer')) {
			return 0;
		}
		$field = 'options_'.InvoicePlusPriceLevelService::WAREHOUSE_LEVEL_FIELD;
		if (!GETPOSTISSET($field)) {
			return 0;
		}

		$limit = InvoicePlusPriceLevelService::getPriceLevelLimit();
		$validation = self::validateWarehousePriceLevelInput(GETPOST($field, 'alphanohtml'), $limit);
		if (!$validation['valid']) {
			$langs->loadLangs(array('stocks', 'invoiceplus@invoiceplus'));
			$this->errors[] = $langs->trans('InvoicePlusInvalidWarehousePriceLevel', $limit);
			$action = ($action === 'add') ? 'create' : 'edit';
			return -1;
		}

		// The native setOptionalsFromPost() reads this field through GETPOST, so
		// both superglobals are canonicalized. An empty selection then reaches
		// the native int handling as '' and is persisted as NULL.
		$canonical = ($validation['value'] === null) ? '' : (string) $validation['value'];
		if (isset($_GET[$field])) {
			$_GET[$field] = $canonical;
		}
		if (isset($_POST[$field])) {
			$_POST[$field] = $canonical;
		}

		return 0;
	}

	/**
	 * Read the raw grid values of the current request.
	 *
	 * @param int $limit Configured number of price levels
	 * @return array      prices and base_types indexed by level
	 */
	public static function collectSubmittedPriceLevels($limit)
	{
		$prices = array();
		$baseTypes = array();
		for ($level = 1; $level <= (int) $limit; $level++) {
			$priceField = self::getPriceFieldName($level);
			$baseField = self::getPriceBaseTypeFieldName($level);
			$prices[$level] = GETPOSTISSET($priceField) ? GETPOST($priceField, 'alphanohtml') : null;
			$baseTypes[$level] = GETPOSTISSET($baseField) ? GETPOST($baseField, 'aZ09') : null;
		}

		return array('prices' => $prices, 'base_types' => $baseTypes);
	}

	/**
	 * Native field name carrying the price of one level.
	 *
	 * @param int $level Price level
	 * @return string     POST field name
	 */
	public static function getPriceFieldName($level)
	{
		return ((int) $level === 1) ? 'price' : 'price_'.((int) $level);
	}

	/**
	 * Native field name carrying the HT/TTC base of one level.
	 *
	 * @param int $level Price level
	 * @return string     POST field name
	 */
	public static function getPriceBaseTypeFieldName($level)
	{
		return ((int) $level === 1) ? 'price_base_type' : 'multiprices_base_type_'.((int) $level);
	}

	/**
	 * Validate the whole submitted grid.
	 *
	 * Pure function. An empty field means "level not provided"; an explicit
	 * zero is a price. empty() is never used to make that decision.
	 *
	 * @param array $rawPrices    Raw price inputs indexed by level
	 * @param array $rawBaseTypes Raw HT/TTC inputs indexed by level
	 * @param int   $limit        Configured number of price levels
	 * @return array               levels and errors
	 */
	public static function parseSubmittedPriceLevels(array $rawPrices, array $rawBaseTypes, $limit)
	{
		$limit = (int) $limit;
		$levels = array();
		$errors = array();

		for ($level = 1; $level <= $limit; $level++) {
			$raw = array_key_exists($level, $rawPrices) ? $rawPrices[$level] : null;
			$parsed = self::normalizeMonetaryInput($raw);
			if (!$parsed['provided']) {
				continue;
			}
			if (!$parsed['valid']) {
				$errors[] = array('code' => 'InvoicePlusInvalidPriceLevelValue', 'params' => array($level));
				continue;
			}
			$rawBase = array_key_exists($level, $rawBaseTypes) ? $rawBaseTypes[$level] : null;
			$levels[$level] = array(
				'price' => $parsed['value'],
				'price_base_type' => self::normalizePriceBaseType($rawBase),
			);
		}

		$hasUpperLevel = false;
		foreach ($levels as $level => $unused) {
			if ($level > 1) {
				$hasUpperLevel = true;
				break;
			}
		}
		// Level 1 is the only fallback, so it must exist as soon as a higher
		// level is priced.
		if ($hasUpperLevel && !isset($levels[1])) {
			$errors[] = array('code' => 'InvoicePlusPriceLevelOneRequired', 'params' => array(1));
		}

		return array('levels' => $levels, 'errors' => $errors);
	}

	/**
	 * Normalize one submitted monetary field.
	 *
	 * Pure function returning three distinct states: absent, malformed and a
	 * valid finite amount, zero included.
	 *
	 * @param mixed $raw Raw request value
	 * @return array      provided, valid and normalized value
	 */
	public static function normalizeMonetaryInput($raw)
	{
		$undefined = array('provided' => false, 'valid' => true, 'value' => null);
		if ($raw === null || is_array($raw) || is_object($raw)) {
			return $undefined;
		}

		$candidate = trim(str_replace(array("\xC2\xA0", "\xE2\x80\xAF"), ' ', (string) $raw));
		if ($candidate === '') {
			return $undefined;
		}

		$invalid = array('provided' => true, 'valid' => false, 'value' => null);
		// Reject anything that is not a monetary character before delegating to
		// Dolibarr, which would otherwise silently coerce garbage to zero.
		if (preg_match('/[^0-9 .,+\-]/', $candidate) || !preg_match('/[0-9]/', $candidate)) {
			return $invalid;
		}

		$normalized = price2num($candidate, 'MU');
		if ($normalized === '' || $normalized === null || !is_numeric($normalized) || !is_finite((float) $normalized)) {
			return $invalid;
		}

		return array('provided' => true, 'valid' => true, 'value' => (string) $normalized);
	}

	/**
	 * Normalize an HT/TTC selection like the native product form does.
	 *
	 * @param mixed $rawBaseType Raw request value
	 * @return string             HT or TTC
	 */
	public static function normalizePriceBaseType($rawBaseType)
	{
		$candidate = strtoupper(trim((string) $rawBaseType));
		if (in_array($candidate, array('HT', 'TTC'), true)) {
			return $candidate;
		}

		$configured = strtoupper(getDolGlobalString('PRODUCT_PRICE_BASE_TYPE'));

		return $configured === 'TTC' ? 'TTC' : 'HT';
	}

	/**
	 * Validate the warehouse price level submitted by the warehouse card.
	 *
	 * Pure function. An empty selection is NULL; only 1 to N are accepted, so
	 * zero, negative values and N+1 are refused.
	 *
	 * @param mixed $raw   Raw request value
	 * @param int   $limit Configured number of price levels
	 * @return array        valid flag and normalized value
	 */
	public static function validateWarehousePriceLevelInput($raw, $limit)
	{
		if ($raw === null || is_array($raw) || is_object($raw)) {
			return array('valid' => false, 'value' => null);
		}
		$candidate = trim((string) $raw);
		if ($candidate === '') {
			return array('valid' => true, 'value' => null);
		}
		if (!preg_match('/^[0-9]+$/', $candidate)) {
			return array('valid' => false, 'value' => null);
		}

		$level = (int) $candidate;
		if ($level < 1 || $level > (int) $limit) {
			return array('valid' => false, 'value' => null);
		}

		return array('valid' => true, 'value' => $level);
	}

	/**
	 * Build the N dynamic price rows of the product creation form.
	 *
	 * @param array $parameters Hook metadata carrying the column layout
	 * @return string            Table rows, or an empty string
	 */
	private function buildProductPriceLevelRows($parameters)
	{
		global $langs, $form;

		$limit = InvoicePlusPriceLevelService::getPriceLevelLimit();
		if ($limit <= 0) {
			return '';
		}
		$langs->loadLangs(array('products', 'invoiceplus@invoiceplus'));
		$formBuilder = ($form instanceof Form) ? $form : new Form($this->db);
		$colspan = self::resolveValueColspan($parameters);
		$colspanAttr = $colspan > 0 ? ' colspan="'.$colspan.'"' : '';
		$defaultBaseType = self::normalizePriceBaseType(null);

		$out = '';
		for ($level = 1; $level <= $limit; $level++) {
			$priceField = self::getPriceFieldName($level);
			$baseField = self::getPriceBaseTypeFieldName($level);
			$priceValue = GETPOSTISSET($priceField) ? GETPOST($priceField, 'alphanohtml') : '';
			$baseValue = GETPOSTISSET($baseField) ? self::normalizePriceBaseType(GETPOST($baseField, 'aZ09')) : $defaultBaseType;

			$out .= '<tr class="invoiceplus_price_level invoiceplus_price_level_'.$level.'">';
			$out .= '<td class="titlefieldcreate">'.dol_escape_htmltag(InvoicePlusPriceLevelService::getPriceLevelLabel($level)).'</td>';
			$out .= '<td'.$colspanAttr.'>';
			if ($level === 1) {
				// Kept inside a cell so the marker stays part of the native form.
				$out .= '<input type="hidden" name="'.self::MULTIPRICES_FORM_MARKER.'" value="1">';
			}
			$out .= '<input type="text" class="maxwidth75" name="'.$priceField.'" id="invoiceplus_'.$priceField.'"';
			$out .= ' value="'.dol_escape_htmltag($priceValue).'"> ';
			$out .= $formBuilder->selectPriceBaseType($baseValue, $baseField);
			$out .= '</td></tr>';
		}

		return $out;
	}

	/**
	 * Build the warehouse price-level row and hide the native rendering.
	 *
	 * The list of levels is rebuilt from the current configuration on every
	 * request, so no option list is ever frozen in the extrafield definition.
	 *
	 * @param array        $parameters Hook metadata carrying the column layout
	 * @param CommonObject $object     Current warehouse
	 * @param string       $action     Current action
	 * @return string                   Table row, or an empty string
	 */
	private function buildWarehousePriceLevelRow($parameters, $object, $action)
	{
		global $langs, $extrafields;

		if (!is_object($object) || $object->element !== 'stock') {
			return '';
		}
		if (!$this->suppressNativeWarehouseLevelRendering($extrafields)) {
			return '';
		}

		$langs->loadLangs(array('stocks', 'products', 'invoiceplus@invoiceplus'));
		$limit = InvoicePlusPriceLevelService::getPriceLevelLimit();
		$field = 'options_'.InvoicePlusPriceLevelService::WAREHOUSE_LEVEL_FIELD;
		$colspan = self::resolveValueColspan($parameters);
		$colspanAttr = $colspan > 0 ? ' colspan="'.$colspan.'"' : '';
		$isForm = in_array($action, array('create', 'edit', 're-edit'), true);

		$stored = null;
		if (isset($object->array_options) && is_array($object->array_options) && array_key_exists($field, $object->array_options)) {
			$stored = InvoicePlusPriceLevelService::normalizeStoredLevel(
				$object->array_options[$field],
				$limit,
				'entrepot '.((int) $object->id)
			);
		}
		$selected = $stored;
		if ($isForm && GETPOSTISSET($field)) {
			$submitted = self::validateWarehousePriceLevelInput(GETPOST($field, 'alphanohtml'), $limit);
			$selected = $submitted['valid'] ? $submitted['value'] : null;
		}

		$out = '<tr class="invoiceplus_warehouse_price_level">';
		$out .= '<td class="titlefield">'.dol_escape_htmltag($langs->transnoentitiesnoconv('InvoicePlusWarehousePriceLevel')).'</td>';
		$out .= '<td'.$colspanAttr.'>';
		if ($isForm) {
			$out .= '<select class="flat minwidth150" name="'.$field.'" id="'.$field.'">';
			$out .= '<option value=""'.($selected === null ? ' selected' : '').'>';
			$out .= dol_escape_htmltag($langs->transnoentitiesnoconv('InvoicePlusNoPriceLevel'));
			$out .= '</option>';
			for ($level = 1; $level <= $limit; $level++) {
				$out .= '<option value="'.$level.'"'.($selected === $level ? ' selected' : '').'>';
				$out .= dol_escape_htmltag(InvoicePlusPriceLevelService::getPriceLevelLabel($level));
				$out .= '</option>';
			}
			$out .= '</select>';
		} else {
			$out .= dol_escape_htmltag(
				$selected === null
					? $langs->transnoentitiesnoconv('InvoicePlusNoPriceLevel')
					: InvoicePlusPriceLevelService::getPriceLevelLabel($selected)
			);
		}
		$out .= '</td></tr>';

		return $out;
	}

	/**
	 * Disable the native rendering of the module extrafield for this request.
	 *
	 * Only the in-memory definition is changed, and only during the display
	 * phase: the stored definition, the stored values and the native
	 * setOptionalsFromPost() persistence stay untouched.
	 *
	 * @param ExtraFields|null $extrafields Page extrafields handler
	 * @return bool                          True when the field is installed
	 */
	private function suppressNativeWarehouseLevelRendering($extrafields)
	{
		$key = InvoicePlusPriceLevelService::WAREHOUSE_LEVEL_FIELD;
		if (!is_object($extrafields) || !isset($extrafields->attributes['entrepot']['label'][$key])) {
			return false;
		}
		$extrafields->attributes['entrepot']['enabled'][$key] = '0';

		return true;
	}

	/**
	 * Reproduce the column span used by the native extrafield rows.
	 *
	 * @param array $parameters Hook metadata
	 * @return int               Colspan of the value cell, 0 when not applicable
	 */
	public static function resolveValueColspan($parameters)
	{
		if (!is_array($parameters)) {
			return 0;
		}
		if (array_key_exists('cols', $parameters)) {
			return max(0, (int) $parameters['cols']);
		}
		if (array_key_exists('colspanvalue', $parameters)) {
			return max(0, (int) $parameters['colspanvalue']);
		}
		$reg = array();
		if (array_key_exists('colspan', $parameters) && preg_match('/colspan="(\d+)"/', (string) $parameters['colspan'], $reg)) {
			return max(0, (int) $reg[1]);
		}

		return 0;
	}

	/**
	 * Add the physical cash amount immediately before the native Amount column.
	 *
	 * Dolibarr's standard payment table intentionally prints
	 * paiement_facture.amount in company currency. For a CDF cash account this
	 * is the USD equivalent, not the physical amount posted to bank.amount. The
	 * hook runs before the native table is printed, so it emits only a safe,
	 * idempotent DOM enrichment payload for that existing table.
	 *
	 * @param array        $parameters  Hook metadata
	 * @param CommonObject $object      Current customer invoice
	 * @param string       $action      Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int                      0 to preserve the native invoice card
	 */
	public function tabContentViewInvoice($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';
		// Physical ledger amounts remain restricted to internal users who may
		// read both the invoice and its bank account.
		if (!isModEnabled('invoiceplus')
			|| empty($user->id)
			|| !empty($user->socid)
			|| !$user->hasRight('facture', 'lire')
			|| !$user->hasRight('banque', 'lire')) {
			return 0;
		}
		if (empty($parameters['currentcontext']) || $parameters['currentcontext'] !== 'invoicecard') {
			return 0;
		}
		if (!is_object($object) || $object->element !== 'facture' || (int) $object->id <= 0) {
			return 0;
		}

		$sql = 'SELECT p.rowid AS payment_id, b.amount AS bank_amount,';
		$sql .= ' ba.currency_code AS account_currency';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'paiement_facture AS pf';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'paiement AS p ON p.rowid = pf.fk_paiement';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank AS b ON b.rowid = p.fk_bank';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bank_account AS ba ON ba.rowid = b.fk_account';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'invoiceplus_cash_settlement AS ics';
		$sql .= ' ON ics.entity = p.entity AND ics.fk_facture = pf.fk_facture';
		$sql .= " AND ics.status = 'completed'";
		$sql .= " AND ((p.ref_ext = CONCAT('invoiceplus:', ics.operation_id, ':cdf')";
		$sql .= " AND UPPER(ba.currency_code) = 'CDF')";
		$sql .= " OR (p.ref_ext = CONCAT('invoiceplus:', ics.operation_id, ':usd')";
		$sql .= " AND UPPER(ba.currency_code) = 'USD'))";
		$sql .= ' WHERE pf.fk_facture = '.((int) $object->id);
		$sql .= ' AND ics.fk_facture = '.((int) $object->id);
		$sql .= ' AND ics.entity = '.((int) $object->entity);
		$sql .= ' AND p.entity IN ('.getEntity('invoice').')';
		$sql .= ' AND ba.entity IN ('.getEntity('bank_account').')';
		$sql .= ' ORDER BY p.datep, p.tms, p.rowid';
		$result = $this->db->query($sql);
		if (!$result) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return 0;
		}
		if ($this->db->num_rows($result) === 0) {
			$this->db->free($result);
			return 0;
		}

		$langs->loadLangs(array('banks', 'invoiceplus@invoiceplus'));
		$physicalAmounts = array();
		while ($row = $this->db->fetch_object($result)) {
			$paymentId = (int) $row->payment_id;
			$accountCurrency = strtoupper((string) $row->account_currency);
			if ($paymentId <= 0
				|| !in_array($accountCurrency, array('CDF', 'USD'), true)
				|| !is_numeric($row->bank_amount)) {
				dol_syslog(__METHOD__.' skipped invalid physical amount for payment '.$paymentId, LOG_WARNING);
				continue;
			}
			$physicalAmounts[$paymentId] = price((float) $row->bank_amount, 0, $langs, 1, -1, -1, $accountCurrency);
		}
		$this->db->free($result);
		$script = $this->buildPhysicalAmountEnrichmentScript(
			$physicalAmounts,
			$langs->transnoentitiesnoconv('InvoicePlusPhysicalAmount')
		);

		// tabContentViewInvoice does not print hook resPrint in Dolibarr 20.0.4.
		// Direct output registers the enrichment; returning 0 preserves the card.
		if ($script !== '') {
			print $script;
		}
		return 0;
	}

	/**
	 * Build the safe inline payload that enriches Dolibarr's native table.
	 *
	 * @param array  $physicalAmounts Formatted physical amount by payment id
	 * @param string $headerLabel     Localized column label
	 * @return string                 Script element, or an empty string
	 */
	private function buildPhysicalAmountEnrichmentScript(array $physicalAmounts, $headerLabel)
	{
		$amounts = array();
		foreach ($physicalAmounts as $paymentId => $displayAmount) {
			$paymentId = (int) $paymentId;
			$displayAmount = trim((string) $displayAmount);
			$normalizedDisplayAmount = preg_replace('/[\p{Z}\s]+/u', ' ', $displayAmount);
			if ($normalizedDisplayAmount === false || $normalizedDisplayAmount === null) {
				// Preserve the safe JSON_INVALID_UTF8_SUBSTITUTE fallback when an
				// invalid byte sequence prevents Unicode whitespace normalization.
				dol_syslog(__METHOD__.' unable to normalize payment amount whitespace', LOG_WARNING);
				$normalizedDisplayAmount = $displayAmount;
			}
			$normalizedDisplayAmount = trim($normalizedDisplayAmount);
			if ($paymentId > 0 && $normalizedDisplayAmount !== '') {
				$amounts[$paymentId] = $normalizedDisplayAmount;
			}
		}
		if (empty($amounts)) {
			return '';
		}

		$config = json_encode(
			array(
				'amounts' => (object) $amounts,
				'label' => (string) $headerLabel,
			),
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			| JSON_INVALID_UTF8_SUBSTITUTE
		);
		if ($config === false) {
			dol_syslog(__METHOD__.' unable to encode invoice payment enrichment', LOG_ERR);
			return '';
		}

		$javascript = <<<'JAVASCRIPT'
(function () {
	'use strict';

	var config = __INVOICEPLUS_CONFIG__;
	var hasOwn = Object.prototype.hasOwnProperty;

	function paymentIdFromRow(row) {
		var links = row.getElementsByTagName('a');
		for (var i = 0; i < links.length; i++) {
			var href = links[i].getAttribute('href');
			if (!href) {
				continue;
			}
			try {
				var url = new URL(href, document.baseURI);
				var normalizedPath = url.pathname.replace(/\/{2,}/g, '/');
				if (!/\/compta\/paiement\/card\.php$/.test(normalizedPath)) {
					continue;
				}
				var paymentId = url.searchParams.get('id');
				if (/^[1-9][0-9]*$/.test(paymentId || '')) {
					return paymentId;
				}
			} catch (error) {
				// Ignore unrelated or malformed links from other hooks.
			}
		}
		return null;
	}

	function enrichTable(table) {
		if (table.getAttribute('data-invoiceplus-physical-enriched') === '1') {
			return;
		}

		var headerRow = table.querySelector('tr.liste_titre');
		if (!headerRow) {
			return;
		}

		var originalColumnCount = headerRow.cells.length;
		if (originalColumnCount < 5 || !headerRow.cells[originalColumnCount - 2].classList.contains('right')) {
			return;
		}

		// Preflight the complete native structure before changing any cell. Native
		// payment rows have the header width; every summary row has three DOM
		// cells and spans all columns preceding Amount. Unknown structures fail
		// closed so another module's markup is never shifted partially.
		var detailRows = [];
		var summaryRows = [];
		var hasMappedPayment = false;
		for (var i = 0; i < table.rows.length; i++) {
			var row = table.rows[i];
			if (row === headerRow) {
				continue;
			}
			if (row.classList.contains('oddeven')) {
				if (row.cells.length !== originalColumnCount) {
					return;
				}
				var paymentId = paymentIdFromRow(row);
				detailRows.push({row: row, paymentId: paymentId});
				if (paymentId !== null && hasOwn.call(config.amounts, paymentId)) {
					hasMappedPayment = true;
				}
			} else if (row.cells.length === 3 && row.cells[0].colSpan === originalColumnCount - 2) {
				summaryRows.push(row);
			} else {
				return;
			}
		}
		if (!hasMappedPayment || detailRows.length === 0) {
			return;
		}

		for (var j = 0; j < detailRows.length; j++) {
			var paymentRow = detailRows[j].row;
			var amountCell = paymentRow.cells[originalColumnCount - 2];
			var cell = document.createElement('td');
			cell.className = 'right invoiceplus-physical-amount';
			var amount = document.createElement('span');
			amount.className = 'amount';
			if (detailRows[j].paymentId !== null && hasOwn.call(config.amounts, detailRows[j].paymentId)) {
				amount.textContent = config.amounts[detailRows[j].paymentId];
			} else {
				amount.textContent = '\u2014';
			}
			cell.appendChild(amount);
			paymentRow.insertBefore(cell, amountCell);
		}

		// Native totals/remises have one leading colspan, followed by the native
		// Amount and action cells. Extend that colspan through the new column.
		for (var k = 0; k < summaryRows.length; k++) {
			summaryRows[k].cells[0].colSpan = originalColumnCount - 1;
		}

		var header = document.createElement('td');
		header.className = 'liste_titre right invoiceplus-physical-amount-header';
		header.setAttribute('scope', 'col');
		header.textContent = config.label;
		headerRow.insertBefore(header, headerRow.cells[originalColumnCount - 2]);

		table.setAttribute('data-invoiceplus-physical-enriched', '1');
	}

	function run() {
		var tables = document.querySelectorAll('table.paymenttable');
		for (var i = 0; i < tables.length; i++) {
			enrichTable(tables[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run, {once: true});
	} else {
		run();
	}
})();
JAVASCRIPT;

		return '<script nonce="'.dol_escape_htmltag(getNonce()).'" type="text/javascript">'."\n".str_replace('__INVOICEPLUS_CONFIG__', $config, $javascript)."\n".'</script>';
	}
}
