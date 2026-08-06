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
 * \file    custom/bankaudit/ajax/get_account_info.php
 * \ingroup bankaudit
 * \brief   Return balance, minimum threshold and currency of a bank account (Spec 5 / P2).
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/bankaudit/class/bankaudit.class.php');

top_httphead('application/json');

if (empty($user->id)) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit;
}
if (!isModEnabled('bankaudit')) {
	http_response_code(403);
	echo json_encode(array('error' => 'module disabled'));
	exit;
}
if (!$user->hasRight('banque', 'lire')) {
	http_response_code(403);
	echo json_encode(array('error' => 'forbidden'));
	exit;
}

$id = GETPOSTINT('id');
if ($id <= 0) {
	http_response_code(400);
	echo json_encode(array('error' => 'missing id'));
	exit;
}

// Ensure the account belongs to an accessible entity
$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".((int) $id);
$sql .= " AND entity IN (".getEntity('bank_account').")";
$resql = $db->query($sql);
if (!$resql || !$db->fetch_object($resql)) {
	http_response_code(404);
	echo json_encode(array('error' => 'not found'));
	exit;
}

$currency = BankAudit::getAccountCurrency($db, $id);
$balance = BankAudit::getAccountBalance($db, $id);
$threshold = BankAudit::getMinBalanceForAccount($db, $id);
$name = BankAudit::getAccountLabel($db, $id);

// When the balance guard is globally disabled, return no threshold so the client skips the check
if (!BankAudit::isBalanceGuardEnabled()) {
	$threshold = null;
}

echo json_encode(array(
	'id' => $id,
	'name' => $name,
	'currency' => $currency,
	'balance' => (float) $balance,
	'threshold' => ($threshold === null ? null : (float) $threshold),
));

$db->close();
exit;
