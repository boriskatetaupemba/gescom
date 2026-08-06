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
 * \file    custom/posnova/ajax/interface.php
 * \ingroup posnova
 * \brief   JSON API for the POS terminal SPA.
 *
 * Security model:
 *  - the user must be logged in and hold the 'posnova/run' permission;
 *  - every request carries the opaque session token (a 64-char secret) which both
 *    authenticates the cash session and acts as a CSRF guard (an attacker cannot know it);
 *  - all business rules (price lock, discount cap, stock guard, payment coverage) are
 *    re-checked server-side in PosTicket; the client is never trusted;
 *  - the catalog payload NEVER exposes cost price, supplier or margin data.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1'); // protected instead by the secret session token below
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
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
dol_include_once('/posnova/class/posticket.class.php');

/**
 * Emit a JSON response and stop.
 *
 * @param array $data Payload
 * @return void
 */
function pn_json($data)
{
	global $db;
	if (is_object($db)) {
		$db->close();
	}
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($data);
	exit;
}

if (!isModEnabled('posnova') || !$user->hasRight('posnova', 'run')) {
	pn_json(array('ok' => false, 'error' => 'AccessForbidden'));
}

$langs->loadLangs(array('posnova@posnova'));

$action = GETPOST('action', 'aZ09');
$posToken = GETPOST('pos_token', 'aZ09');

// Authenticate the cash session through its long-lived secret token.
$session = new PosSession($db);
if (empty($posToken) || $session->fetchByToken($posToken) <= 0 || !in_array($session->status, array('OPEN', 'LOCKED'), true)) {
	pn_json(array('ok' => false, 'error' => 'PosNovaErrSessionNotOpen'));
}

$pos = new PosConfig($db);
if ($pos->fetch($session->fk_pos) <= 0) {
	pn_json(array('ok' => false, 'error' => 'PosNovaErrPosNotFound'));
}

$posCurrency = $pos->getWorkingCurrency();
$companyCurrency = strtoupper(empty($conf->currency) ? PosNova::USD : $conf->currency);
$rateInfo = PosNova::getDailyRate($db);
$rate = (float) $rateInfo['rate'];


/* ============================================================================
 * CATALOG (search + pagination)
 * ========================================================================= */
if ($action === 'catalog') {
	$q = GETPOST('q', 'alphanohtml');
	$category = GETPOSTINT('category');
	$page = max(1, GETPOSTINT('page'));
	$pageSize = max(10, getDolGlobalInt('POSNOVA_CATALOG_PAGE_SIZE', 50));
	$offset = ($page - 1) * $pageSize;

	$sql = "SELECT p.rowid, p.ref, p.label, p.price, p.price_ttc, p.tva_tx, p.barcode, p.fk_product_type,";
	$sql .= " COALESCE(ps.reel, 0) as stock";
	$sql .= " FROM ".MAIN_DB_PREFIX."product p";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".((int) $pos->fk_warehouse);
	if ($category > 0) {
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie_product cp ON cp.fk_product = p.rowid AND cp.fk_categorie = ".$category;
	}
	$sql .= " WHERE p.entity IN (".getEntity('product').")";
	$sql .= " AND p.tosell = 1"; // hors-vente products are never listed
	if ($q !== '') {
		$qesc = $db->escape($db->escapeforlike($q));
		$sql .= " AND (p.ref LIKE '%".$qesc."%' OR p.label LIKE '%".$qesc."%' OR p.barcode LIKE '%".$qesc."%')";
	}
	$sql .= " ORDER BY p.label ASC";
	$sql .= $db->plimit($pageSize + 1, $offset);

	$items = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$ttcCompany = (float) $o->price_ttc;
			if ($ttcCompany <= 0) {
				$ttcCompany = (float) $o->price * (1 + (float) $o->tva_tx / 100);
			}
			$items[] = array(
				'id' => (int) $o->rowid,
				'ref' => $o->ref,
				'label' => $o->label,
				'price' => PosNova::convert($ttcCompany, $companyCurrency, $posCurrency, $rate),
				'tva' => (float) $o->tva_tx,
				'stock' => (float) $o->stock,
				'barcode' => $o->barcode,
				'priceOld' => 0,
			);
		}
	}
	$hasNext = count($items) > $pageSize;
	if ($hasNext) {
		array_pop($items);
	}

	$categories = array();
	if ($page === 1) {
		$sqlc = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie";
		$sqlc .= " WHERE type = 0 AND entity IN (".getEntity('category').")";
		$sqlc .= " ORDER BY label ASC".$db->plimit(40);
		$resc = $db->query($sqlc);
		if ($resc) {
			while ($oc = $db->fetch_object($resc)) {
				$categories[] = array('id' => (int) $oc->rowid, 'label' => $oc->label);
			}
		}
	}

	$session->touch();
	pn_json(array('ok' => true, 'items' => $items, 'hasNext' => $hasNext, 'page' => $page, 'categories' => $categories));
}


/* ============================================================================
 * PRODUCT INFO (detail + stock by warehouse) — no cost/supplier/margin data
 * ========================================================================= */
if ($action === 'productinfo') {
	$id = GETPOSTINT('id');
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	$product = new Product($db);
	if ($id <= 0 || $product->fetch($id) <= 0) {
		pn_json(array('ok' => false, 'error' => 'PosNovaErrProductNotFound'));
	}

	$ttcCompany = (float) $product->price_ttc;
	if ($ttcCompany <= 0) {
		$ttcCompany = (float) $product->price * (1 + (float) $product->tva_tx / 100);
	}

	// Stock for every active warehouse, with quantities reserved by pending POS transfers.
	$stocks = array();
	$stockCurrent = 0;
	$sqls = "SELECT e.rowid, e.ref, e.label, COALESCE(ps.reel, 0) as reel";
	$sqls .= " FROM ".MAIN_DB_PREFIX."entrepot e";
	$sqls .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock ps ON ps.fk_entrepot = e.rowid AND ps.fk_product = ".((int) $id);
	$sqls .= " WHERE e.entity IN (".getEntity('stock').") AND e.statut = 1";
	$sqls .= " ORDER BY e.label ASC";
	$ress = $db->query($sqls);
	if ($ress) {
		while ($os = $db->fetch_object($ress)) {
			$physical = (float) $os->reel;
			$reserved = 0.0;
			$sqlr = "SELECT COALESCE(SUM(qty),0) as r FROM ".MAIN_DB_PREFIX."pos_transfer";
			$sqlr .= " WHERE fk_product = ".((int) $id)." AND wh_from = ".((int) $os->rowid)." AND status = 'PENDING'";
			$resr = $db->query($sqlr);
			if ($resr && $db->num_rows($resr)) {
				$reserved = (float) $db->fetch_object($resr)->r;
			}
			$isCurrent = ((int) $os->rowid === (int) $pos->fk_warehouse);
			if ($isCurrent) {
				$stockCurrent = $physical - $reserved;
			}
			$stocks[] = array(
				'warehouse' => $os->label ? $os->label : $os->ref,
				'physical' => $physical,
				'reserved' => $reserved,
				'available' => $physical - $reserved,
				'current' => $isCurrent,
			);
		}
	}

	$category = '';

	$out = array(
		'id' => (int) $product->id,
		'ref' => $product->ref,
		'label' => $product->label,
		'barcode' => $product->barcode,
		'description' => dol_string_nohtmltag($product->description),
		'tva' => (float) $product->tva_tx,
		'price' => PosNova::convert($ttcCompany, $companyCurrency, $posCurrency, $rate),
		'priceOld' => 0,
		'category' => $category,
		'photo' => '',
		'stockCurrent' => $stockCurrent,
	);
	pn_json(array('ok' => true, 'product' => $out, 'stocks' => $stocks));
}


/* ============================================================================
 * CUSTOMER SEARCH
 * ========================================================================= */
if ($action === 'customer_search') {
	$q = GETPOST('q', 'alphanohtml');
	$items = array();
	if (dol_strlen($q) >= 2) {
		$qesc = $db->escape($db->escapeforlike($q));
		$sql = "SELECT rowid, nom, code_client, town, email FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE entity IN (".getEntity('societe').") AND client IN (1, 2, 3) AND status = 1";
		$sql .= " AND (nom LIKE '%".$qesc."%' OR code_client LIKE '%".$qesc."%' OR phone LIKE '%".$qesc."%')";
		$sql .= " ORDER BY nom ASC".$db->plimit(12);
		$resql = $db->query($sql);
		if ($resql) {
			while ($o = $db->fetch_object($resql)) {
				$detail = trim(($o->code_client ? $o->code_client : '').' '.($o->town ? '· '.$o->town : ''));
				$items[] = array('id' => (int) $o->rowid, 'name' => $o->nom, 'detail' => $detail);
			}
		}
	}
	pn_json(array('ok' => true, 'items' => $items));
}


/* ============================================================================
 * SALE (atomic, fully re-validated server-side)
 * ========================================================================= */
if ($action === 'sale') {
	if ($session->status !== 'OPEN') {
		pn_json(array('ok' => false, 'error' => $langs->trans('PosNovaErrSessionNotOpen')));
	}
	$payloadRaw = GETPOST('payload', 'restricthtml');
	$payload = json_decode($payloadRaw, true);
	if (!is_array($payload)) {
		pn_json(array('ok' => false, 'error' => $langs->trans('PosNovaGenericError')));
	}

	$ticket = new PosTicket($db);
	$result = $ticket->validateSale($user, $session, $pos, $payload);
	if (!empty($result['ok'])) {
		pn_json(array('ok' => true, 'ref' => $result['ref'], 'ticket_id' => (int) $result['ticket_id'], 'facture_id' => (int) $result['facture_id']));
	}
	$code = isset($result['error']) ? $result['error'] : 'PosNovaGenericError';
	// Translate known keys; leave raw DB errors as-is.
	$msg = (strpos($code, 'PosNova') === 0) ? $langs->trans($code) : $code;
	pn_json(array('ok' => false, 'error' => $msg, 'code' => $code));
}


/* ============================================================================
 * GET RATE
 * ========================================================================= */
if ($action === 'getrate') {
	pn_json(array('ok' => true, 'rate' => $rate, 'source' => $rateInfo['source'], 'day' => $rateInfo['day']));
}


/* ============================================================================
 * CATALOG CHANGES (differential polling) + live rate
 * ========================================================================= */
if ($action === 'catalog_changes') {
	$since = GETPOSTINT('since');
	$sinceDate = $since > 0 ? $db->idate($since) : $db->idate(dol_now() - 3600);

	$changed = 0;
	$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."product";
	$sql .= " WHERE entity IN (".getEntity('product').") AND tms > '".$db->escape($sinceDate)."'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql)) {
		$changed = (int) $db->fetch_object($resql)->nb;
	}

	pn_json(array(
		'ok' => true,
		'changed' => $changed,
		'now' => (int) dol_now(),
		'rate' => array('rate' => $rate, 'source' => $rateInfo['source']),
	));
}

pn_json(array('ok' => false, 'error' => 'UnknownAction'));
