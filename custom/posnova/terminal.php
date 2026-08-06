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
 * \file    custom/posnova/terminal.php
 * \ingroup posnova
 * \brief   Full-screen POS terminal (SPA shell). Requires an open cash session.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}

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

dol_include_once('/posnova/class/posnova.class.php');
dol_include_once('/posnova/class/posconfig.class.php');
dol_include_once('/posnova/class/possession.class.php');

if (!isModEnabled('posnova')) {
	accessforbidden();
}
if (!$user->hasRight('posnova', 'run')) {
	accessforbidden();
}

$langs->loadLangs(array('posnova@posnova', 'main', 'bills', 'companies', 'stocks'));

// Resolve the cash session from its durable token (cookie set by the launcher).
$token = GETPOST('token', 'aZ09');
if (empty($token) && !empty($_COOKIE['posnova_token'])) {
	$token = preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE['posnova_token']);
}

$session = new PosSession($db);
if (empty($token) || $session->fetchByToken($token) <= 0 || !in_array($session->status, array('OPEN', 'LOCKED'), true)) {
	header('Location: '.dol_buildpath('/custom/posnova/pos.php', 1));
	exit;
}

$pos = new PosConfig($db);
if ($pos->fetch($session->fk_pos) <= 0) {
	header('Location: '.dol_buildpath('/custom/posnova/pos.php', 1));
	exit;
}

$posCurrency = $pos->getWorkingCurrency();
$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);
$rateInfo = PosNova::getDailyRate($db);

// Active accounts of the terminal grouped for the payment form.
$accounts = array();
foreach ($pos->getAccounts(true) as $a) {
	$accounts[] = array(
		'id' => $a['fk_account'],
		'label' => $a['label'] ? $a['label'] : $a['ref'],
		'currency' => $a['currency_code'],
		'mode' => $a['default_payment_mode'] ? $a['default_payment_mode'] : $pos->default_payment_mode,
		'isDefault' => ($pos->fk_default_account && (int) $pos->fk_default_account === (int) $a['fk_account']) ? 1 : 0,
	);
}

// Payment modes (c_paiement).
$paymentModes = array();
$sqlpm = "SELECT code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE entity IN (".getEntity('c_paiement').") AND active = 1 ORDER BY position";
$respm = $db->query($sqlpm);
if ($respm) {
	while ($o = $db->fetch_object($respm)) {
		$paymentModes[] = array('code' => $o->code, 'label' => $langs->trans($o->libelle ? $o->libelle : $o->code));
	}
}

// Default (walk-in) customer.
$defaultCustomer = array('id' => 0, 'name' => $langs->trans('PosNovaWalkInCustomer'));
if (!empty($pos->fk_default_customer)) {
	$sqlc = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $pos->fk_default_customer);
	$resc = $db->query($sqlc);
	if ($resc && $db->num_rows($resc)) {
		$oc = $db->fetch_object($resc);
		$defaultCustomer = array('id' => (int) $oc->rowid, 'name' => $oc->nom);
	}
}

// Register a reconnection if the page is reloaded on an already-open session.
$session->registerReconnection();

$ajaxUrl = dol_buildpath('/custom/posnova/ajax/interface.php', 1);
$cssUrl = dol_buildpath('/custom/posnova/css/posnova.css', 1).'?v='.$pos->id.'1';
$jsUrl = dol_buildpath('/custom/posnova/js/posnova.js', 1).'?v=1';

$config = array(
	'ajaxUrl' => $ajaxUrl,
	'token' => $session->session_token,
	'csrf' => newToken(),
	'sessionId' => (int) $session->id,
	'sessionRef' => $session->ref,
	'posId' => (int) $pos->id,
	'posRef' => $pos->ref,
	'posLabel' => $pos->label,
	'vendor' => $user->getFullName($langs),
	'currency' => $posCurrency,
	'cdfCode' => $cdfCode,
	'rate' => (float) $rateInfo['rate'],
	'rateSource' => $rateInfo['source'],
	'accounts' => $accounts,
	'paymentModes' => $paymentModes,
	'allowPriceEdit' => (int) $pos->allow_price_edit,
	'allowSaleWithoutStock' => (int) $pos->allow_sale_without_stock,
	'maxDiscount' => (float) $pos->max_discount_percent,
	'transferEnabled' => (int) $pos->transfer_enabled,
	'lowStockThreshold' => (int) $pos->low_stock_threshold,
	'pollInterval' => (int) getDolGlobalInt('POSNOVA_POLL_INTERVAL_SEC', 30),
	'pageSize' => (int) getDolGlobalInt('POSNOVA_CATALOG_PAGE_SIZE', 50),
	'printFormat' => $pos->print_format,
	'autoprint' => (int) $pos->autoprint,
	'allowBackdating' => (int) $pos->allow_backdating,
	'defaultCustomer' => $defaultCustomer,
	'canDiscount' => $user->hasRight('posnova', 'discount') ? 1 : 0,
	'canTransfer' => $user->hasRight('posnova', 'transfer') ? 1 : 0,
);

// i18n strings consumed by the JS layer.
$i18n = array(
	'search' => $langs->transnoentitiesnoconv('PosNovaSearchProduct'),
	'allCategories' => $langs->transnoentitiesnoconv('PosNovaAllCategories'),
	'inStockOnly' => $langs->transnoentitiesnoconv('PosNovaInStockOnly'),
	'emptyCart' => $langs->transnoentitiesnoconv('PosNovaEmptyCart'),
	'emptyCartHint' => $langs->transnoentitiesnoconv('PosNovaEmptyCartHint'),
	'product' => $langs->transnoentitiesnoconv('PosNovaColProduct'),
	'price' => $langs->transnoentitiesnoconv('PosNovaColPrice'),
	'qty' => $langs->transnoentitiesnoconv('PosNovaColQty'),
	'discount' => $langs->transnoentitiesnoconv('PosNovaColDiscount'),
	'total' => $langs->transnoentitiesnoconv('PosNovaColTotal'),
	'subtotal' => $langs->transnoentitiesnoconv('PosNovaSubtotal'),
	'totalDiscount' => $langs->transnoentitiesnoconv('PosNovaTotalDiscount'),
	'grandTotal' => $langs->transnoentitiesnoconv('PosNovaGrandTotal'),
	'pay' => $langs->transnoentitiesnoconv('PosNovaPayCheckout'),
	'clear' => $langs->transnoentitiesnoconv('PosNovaClearCart'),
	'addToCart' => $langs->transnoentitiesnoconv('PosNovaAddToCart'),
	'close' => $langs->transnoentitiesnoconv('Close'),
	'stockByWarehouse' => $langs->transnoentitiesnoconv('PosNovaStockByWarehouse'),
	'warehouse' => $langs->transnoentitiesnoconv('Warehouse'),
	'available' => $langs->transnoentitiesnoconv('PosNovaAvailable'),
	'reserved' => $langs->transnoentitiesnoconv('PosNovaReserved'),
	'physical' => $langs->transnoentitiesnoconv('PosNovaPhysical'),
	'paymentTitle' => $langs->transnoentitiesnoconv('PosNovaPaymentTitle'),
	'received' => $langs->transnoentitiesnoconv('PosNovaReceived'),
	'change' => $langs->transnoentitiesnoconv('PosNovaChange'),
	'remaining' => $langs->transnoentitiesnoconv('PosNovaRemaining'),
	'confirmPay' => $langs->transnoentitiesnoconv('PosNovaConfirmPayment'),
	'missing' => $langs->transnoentitiesnoconv('PosNovaMissing'),
	'covered' => $langs->transnoentitiesnoconv('PosNovaCovered'),
	'surplus' => $langs->transnoentitiesnoconv('PosNovaSurplus'),
	'reference' => $langs->transnoentitiesnoconv('PosNovaReference'),
	'mode' => $langs->transnoentitiesnoconv('PosNovaMode'),
	'saleDone' => $langs->transnoentitiesnoconv('PosNovaSaleDone'),
	'priceLocked' => $langs->transnoentitiesnoconv('PosNovaPriceLocked'),
	'discountOverCap' => $langs->transnoentitiesnoconv('PosNovaDiscountOverCap'),
	'outOfStock' => $langs->transnoentitiesnoconv('PosNovaOutOfStock'),
	'catalogUpdated' => $langs->transnoentitiesnoconv('PosNovaCatalogUpdated'),
	'online' => $langs->transnoentitiesnoconv('PosNovaOnline'),
	'offline' => $langs->transnoentitiesnoconv('PosNovaOffline'),
	'session' => $langs->transnoentitiesnoconv('PosNovaSession'),
	'lock' => $langs->transnoentitiesnoconv('PosNovaLock'),
	'closeSession' => $langs->transnoentitiesnoconv('PosNovaCloseSession'),
	'walkIn' => $langs->transnoentitiesnoconv('PosNovaWalkInCustomer'),
	'transfer' => $langs->transnoentitiesnoconv('PosNovaTransfer'),
	'tickets' => $langs->transnoentitiesnoconv('PosNovaTickets'),
	'genericError' => $langs->transnoentitiesnoconv('PosNovaGenericError'),
);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo substr($langs->defaultlang, 0, 2); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
	<meta name="robots" content="noindex,nofollow">
	<title>PosNova — <?php echo dol_escape_htmltag($pos->label); ?></title>
	<link rel="stylesheet" href="<?php echo dol_escape_htmltag($cssUrl); ?>">
</head>
<body class="pn-body">
<div class="pn-app">

	<!-- ===================== TOP STATUS BAR ===================== -->
	<header class="pn-topbar">
		<div class="pn-topcell pn-pos-id">
			<span class="pn-k"><?php echo dol_escape_htmltag($pos->ref); ?></span>
			<span class="pn-v"><?php echo dol_escape_htmltag($pos->label); ?></span>
		</div>
		<div class="pn-topcell pn-hide-sm">
			<span class="pn-k"><?php echo $langs->trans('PosNovaVendor'); ?></span>
			<span class="pn-v"><?php echo dol_escape_htmltag($user->getFullName($langs)); ?></span>
		</div>

		<div class="pn-rate <?php echo $rateInfo['source'] === 'MANUAL' ? 'pn-rate-manual' : 'pn-rate-system'; ?>" id="pn-rate-box">
			<span class="pn-rate-ico">&#128176;</span>
			<div>
				<div class="pn-rate-k">1 USD =</div>
				<div class="pn-rate-v"><span id="pn-rate-val"><?php echo price($rateInfo['rate'], 0, $langs, 1, 0, 3); ?></span> <?php echo dol_escape_htmltag($cdfCode); ?></div>
			</div>
			<span class="pn-rate-src" id="pn-rate-src"><?php echo $rateInfo['source'] === 'MANUAL' ? $langs->trans('PosNovaRateManual') : $langs->trans('PosNovaRateSystem'); ?></span>
		</div>

		<div class="pn-topcell pn-hide-sm">
			<span class="pn-k"><?php echo $langs->trans('PosNovaWorkingCurrency'); ?></span>
			<span class="pn-v"><span class="pn-curbadge pn-cur-<?php echo dol_escape_htmltag($posCurrency); ?>"><?php echo dol_escape_htmltag($posCurrency); ?></span></span>
		</div>

		<div class="pn-spacer"></div>

		<?php if ($pos->transfer_enabled && $user->hasRight('posnova', 'transfer')) { ?>
		<button class="pn-iconbtn pn-hide-sm" id="pn-btn-transfer" title="<?php echo $langs->trans('PosNovaTransfer'); ?>">&#128260; <?php echo $langs->trans('PosNovaTransfer'); ?></button>
		<?php } ?>

		<button class="pn-iconbtn" id="pn-btn-alerts" title="<?php echo $langs->trans('PosNovaAlerts'); ?>">&#128276; <span class="pn-badge-n pn-hidden" id="pn-alert-count">0</span></button>

		<div class="pn-topcell pn-hide-sm">
			<span class="pn-k"><?php echo $langs->trans('PosNovaSession'); ?></span>
			<span class="pn-v"><span id="pn-sess-count">0</span> <?php echo $langs->trans('PosNovaTickets'); ?> &middot; <span id="pn-sess-clock">00:00</span></span>
		</div>

		<div class="pn-pill pn-online" id="pn-conn"><span class="pn-dot"></span><span id="pn-conn-label"><?php echo $langs->trans('PosNovaOnline'); ?></span></div>

		<button class="pn-iconbtn" id="pn-btn-menu" title="<?php echo $langs->trans('Menu'); ?>">&#9776;</button>
	</header>

	<!-- ===================== MAIN ===================== -->
	<div class="pn-main">

		<!-- Catalog -->
		<section class="pn-panel">
			<div class="pn-panel-head">
				<div class="pn-search">
					<span class="pn-search-ico">&#128269;</span>
					<input type="text" id="pn-search" autocomplete="off" placeholder="<?php echo dol_escape_htmltag($langs->trans('PosNovaSearchProduct')); ?>">
				</div>
			</div>
			<div class="pn-chiprow" id="pn-chips">
				<button class="pn-chip pn-active" data-cat="0"><?php echo $langs->trans('PosNovaAllCategories'); ?></button>
			</div>
			<div class="pn-catalog" id="pn-catalog"></div>
			<div class="pn-pager">
				<button id="pn-prev" disabled>&#8592; <?php echo $langs->trans('Previous'); ?></button>
				<span id="pn-pageinfo">1</span>
				<button id="pn-next"><?php echo $langs->trans('Next'); ?> &#8594;</button>
			</div>
		</section>

		<!-- Ticket -->
		<section class="pn-panel pn-ticket">
			<div class="pn-customer">
				<div class="pn-cust-ico">&#128100;</div>
				<div class="pn-cust-search">
					<input type="text" id="pn-customer" autocomplete="off" value="<?php echo dol_escape_htmltag($defaultCustomer['name']); ?>" placeholder="<?php echo dol_escape_htmltag($langs->trans('PosNovaSearchCustomer')); ?>">
				</div>
				<?php if ($pos->allow_backdating) { ?>
				<input type="date" id="pn-invdate" title="<?php echo $langs->trans('PosNovaInvoiceDate'); ?>" style="height:40px;border:1.5px solid var(--pn-border);border-radius:10px;padding:0 10px;">
				<?php } ?>
			</div>

			<div class="pn-tickwrap">
				<table class="pn-ttable">
					<thead>
						<tr>
							<th><?php echo $langs->trans('PosNovaColProduct'); ?></th>
							<th class="pn-num" style="width:120px"><?php echo $langs->trans('PosNovaColPrice'); ?></th>
							<th style="width:130px"><?php echo $langs->trans('PosNovaColQty'); ?></th>
							<th class="pn-num" style="width:110px"><?php echo $langs->trans('PosNovaColDiscount'); ?></th>
							<th class="pn-num" style="width:120px"><?php echo $langs->trans('PosNovaColTotal'); ?></th>
							<th style="width:90px"></th>
						</tr>
					</thead>
					<tbody id="pn-lines"></tbody>
				</table>
				<div class="pn-empty" id="pn-empty">
					<div class="pn-empty-ico">&#128722;</div>
					<div><?php echo $langs->trans('PosNovaEmptyCart'); ?></div>
					<div style="font-size:13px;margin-top:4px;"><?php echo $langs->trans('PosNovaEmptyCartHint'); ?></div>
				</div>
			</div>

			<div class="pn-footer">
				<div class="pn-totrow"><span><?php echo $langs->trans('PosNovaSubtotal'); ?></span><span id="pn-subtotal">—</span></div>
				<div class="pn-totrow"><span><?php echo $langs->trans('PosNovaTotalDiscount'); ?></span><span id="pn-discount">—</span></div>
				<div class="pn-totrow pn-grand"><span><?php echo $langs->trans('PosNovaGrandTotal'); ?></span><span id="pn-grand">—</span></div>
				<div class="pn-equiv" id="pn-equiv"></div>
				<div class="pn-footbtns">
					<button class="pn-btn pn-btn-ghost" id="pn-clear">&#128465;</button>
					<button class="pn-btn pn-btn-primary" id="pn-checkout" disabled>&#128179; <?php echo $langs->trans('PosNovaPayCheckout'); ?></button>
				</div>
			</div>
		</section>
	</div>
</div>

<div class="pn-toasts" id="pn-toasts"></div>
<div id="pn-modal-root"></div>

<script>
window.PN_CONFIG = <?php echo json_encode($config); ?>;
window.PN_I18N = <?php echo json_encode($i18n); ?>;
</script>
<script src="<?php echo dol_escape_htmltag($jsUrl); ?>"></script>
</body>
</html>
<?php
$db->close();
