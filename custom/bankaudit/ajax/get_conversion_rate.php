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
 * \file    custom/bankaudit/ajax/get_conversion_rate.php
 * \ingroup bankaudit
 * \brief   Return the conversion factor between two currencies (Spec 3 / P3).
 *          amount_to = amount_from * rate
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

// Security: must be logged in, module enabled, and allowed to read bank data
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

$from = GETPOST('from', 'aZ09');
$to = GETPOST('to', 'aZ09');

if ($from === '' || $to === '') {
	http_response_code(400);
	echo json_encode(array('error' => 'missing params'));
	exit;
}

if ($from === $to) {
	echo json_encode(array('rate' => 1, 'from' => $from, 'to' => $to));
	exit;
}

$rate = BankAudit::getConversionRate($db, $from, $to);
if ($rate === null) {
	http_response_code(404);
	echo json_encode(array('error' => 'rate not found', 'from' => $from, 'to' => $to));
	exit;
}

echo json_encode(array('rate' => (float) $rate, 'from' => $from, 'to' => $to));

$db->close();
exit;
