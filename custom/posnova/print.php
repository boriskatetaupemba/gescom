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
 * \file    custom/posnova/print.php
 * \ingroup posnova
 * \brief   Thermal receipt for a POS ticket (80mm / 58mm / A4), auto-printed.
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

$langs->loadLangs(array('posnova@posnova', 'main', 'bills'));

if (!isModEnabled('posnova')) {
	accessforbidden();
}
if (!$user->hasRight('posnova', 'run')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
if ($id <= 0) {
	accessforbidden('NotFound');
}

// Ticket header.
$sql = "SELECT pt.rowid, pt.ref, pt.currency, pt.rate_usd_cdf, pt.total_ht, pt.total_tva, pt.total_ttc, pt.total_discount,";
$sql .= " pt.invoice_date, pt.datec, pt.fk_pos, pt.fk_user_creat,";
$sql .= " pc.label as poslabel, pc.print_format, s.nom as socname";
$sql .= " FROM ".MAIN_DB_PREFIX."pos_ticket pt";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."pos_config pc ON pc.rowid = pt.fk_pos";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = pt.fk_soc";
$sql .= " WHERE pt.rowid = ".((int) $id)." AND pt.entity IN (".getEntity('posnova').")";
$resql = $db->query($sql);
if (!$resql || !$db->num_rows($resql)) {
	accessforbidden('NotFound');
}
$t = $db->fetch_object($resql);
$cur = $t->currency;
$fmt = !empty($t->print_format) ? $t->print_format : getDolGlobalString('POSNOVA_DEFAULT_PRINT_FORMAT', '80mm');
$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);

// Lines.
$lines = array();
$resl = $db->query("SELECT label, qty, price_unit, discount_pct, total_ttc FROM ".MAIN_DB_PREFIX."pos_ticket_line WHERE fk_ticket = ".((int) $id)." ORDER BY position ASC, rowid ASC");
if ($resl) {
	while ($o = $db->fetch_object($resl)) {
		$lines[] = $o;
	}
}

// Payments.
$payments = array();
$resp = $db->query("SELECT amount, currency, mode FROM ".MAIN_DB_PREFIX."pos_payment WHERE fk_ticket = ".((int) $id)." ORDER BY rowid ASC");
if ($resp) {
	while ($o = $db->fetch_object($resp)) {
		$payments[] = $o;
	}
}

// Change given.
$changes = array();
$resc = $db->query("SELECT amount, currency FROM ".MAIN_DB_PREFIX."pos_change WHERE fk_ticket = ".((int) $id)." ORDER BY rowid ASC");
if ($resc) {
	while ($o = $db->fetch_object($resc)) {
		$changes[] = $o;
	}
}

// Cashier name.
$cashier = '';
if ($t->fk_user_creat > 0) {
	$resu = $db->query("SELECT firstname, lastname FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".((int) $t->fk_user_creat));
	if ($resu && $db->num_rows($resu)) {
		$u = $db->fetch_object($resu);
		$cashier = trim($u->firstname.' '.$u->lastname);
	}
}

$width = ($fmt === '58mm') ? '58mm' : (($fmt === 'A4') ? '180mm' : '80mm');
$pad = ($fmt === '58mm') ? '2mm' : '4mm';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo substr($langs->defaultlang, 0, 2); ?>">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<title><?php echo dol_escape_htmltag($t->ref); ?></title>
<style>
	* { box-sizing: border-box; }
	html, body { margin: 0; padding: 0; background: #f1f5f9; }
	body { font-family: "Courier New", ui-monospace, monospace; color: #111; }
	.r { width: <?php echo $width; ?>; margin: 8px auto; background: #fff; padding: <?php echo $pad; ?>; }
	.c { text-align: center; }
	.b { font-weight: 700; }
	.big { font-size: 1.25em; }
	.muted { color: #555; font-size: .82em; }
	hr { border: none; border-top: 1px dashed #999; margin: 6px 0; }
	table { width: 100%; border-collapse: collapse; font-size: .86em; }
	td { padding: 1px 0; vertical-align: top; }
	.right { text-align: right; white-space: nowrap; }
	.row { display: flex; justify-content: space-between; font-size: .9em; }
	.tot { font-size: 1.15em; font-weight: 700; }
	.toolbar { text-align: center; margin: 10px 0; }
	.toolbar button { font: inherit; padding: 8px 18px; border: 0; border-radius: 8px; background: #4f46e5; color: #fff; cursor: pointer; }
	@media print {
		body { background: #fff; }
		.toolbar { display: none; }
		.r { margin: 0; width: auto; }
		@page { margin: 0; }
	}
</style>
</head>
<body>
<div class="r">
	<div class="c b big"><?php echo dol_escape_htmltag($mysoc->name); ?></div>
	<?php if (!empty($mysoc->address)) { ?><div class="c muted"><?php echo dol_escape_htmltag($mysoc->address); ?></div><?php } ?>
	<?php if (!empty($mysoc->town)) { ?><div class="c muted"><?php echo dol_escape_htmltag(trim($mysoc->zip.' '.$mysoc->town)); ?></div><?php } ?>
	<?php if (!empty($mysoc->phone)) { ?><div class="c muted">Tel: <?php echo dol_escape_htmltag($mysoc->phone); ?></div><?php } ?>
	<hr>
	<div class="row"><span class="b"><?php echo dol_escape_htmltag($t->ref); ?></span><span><?php echo dol_print_date($db->jdate($t->datec ? $t->datec : $t->invoice_date), 'dayhour'); ?></span></div>
	<div class="muted"><?php echo dol_escape_htmltag($t->poslabel); ?><?php echo $cashier !== '' ? ' &middot; '.dol_escape_htmltag($cashier) : ''; ?></div>
	<?php if (!empty($t->socname)) { ?><div class="muted"><?php echo $langs->trans('ThirdParty'); ?>: <?php echo dol_escape_htmltag($t->socname); ?></div><?php } ?>
	<hr>
	<table>
		<?php foreach ($lines as $l) {
			$q = price($l->qty, 0, $langs, 0, -1, (floor($l->qty) == $l->qty ? 0 : 3));
			?>
		<tr>
			<td colspan="2" class="b"><?php echo dol_escape_htmltag($l->label); ?></td>
		</tr>
		<tr>
			<td class="muted"><?php echo $q.' x '.PosNova::formatAmount($l->price_unit, $cur); echo ($l->discount_pct > 0) ? ' (-'.price($l->discount_pct, 0, $langs, 0, -1, 2).'%)' : ''; ?></td>
			<td class="right"><?php echo PosNova::formatAmount($l->total_ttc, $cur); ?></td>
		</tr>
		<?php } ?>
	</table>
	<hr>
	<?php if ($t->total_discount > 0) { ?>
	<div class="row"><span><?php echo $langs->trans('PosNovaTotalDiscount'); ?></span><span>-<?php echo PosNova::formatAmount($t->total_discount, $cur); ?></span></div>
	<?php } ?>
	<div class="row tot"><span><?php echo $langs->trans('PosNovaGrandTotal'); ?></span><span><?php echo PosNova::formatAmount($t->total_ttc, $cur); ?></span></div>
	<?php if ($cur === PosNova::USD && $t->rate_usd_cdf > 0) { ?>
	<div class="row muted"><span><?php echo dol_escape_htmltag($cdfCode); ?></span><span><?php echo PosNova::formatAmount($t->total_ttc * $t->rate_usd_cdf, $cdfCode); ?></span></div>
	<?php } ?>
	<hr>
	<?php foreach ($payments as $p) { ?>
	<div class="row"><span><?php echo $langs->trans('PosNovaReceived'); ?> (<?php echo dol_escape_htmltag($p->mode); ?>)</span><span><?php echo PosNova::formatAmount($p->amount, $p->currency); ?></span></div>
	<?php } ?>
	<?php foreach ($changes as $ch) { ?>
	<div class="row"><span><?php echo $langs->trans('PosNovaChange'); ?></span><span><?php echo PosNova::formatAmount($ch->amount, $ch->currency); ?></span></div>
	<?php } ?>
	<?php if ($t->rate_usd_cdf > 0) { ?>
	<hr>
	<div class="c muted">1 USD = <?php echo price($t->rate_usd_cdf, 0, $langs, 1, 0, 2).' '.dol_escape_htmltag($cdfCode); ?></div>
	<?php } ?>
	<hr>
	<div class="c b"><?php echo $langs->trans('PosNovaSaleDone'); ?> &middot; Merci !</div>
</div>
<div class="toolbar">
	<button onclick="window.print()"><?php echo $langs->trans('PrintFile'); ?></button>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 200); });</script>
</body>
</html>
<?php
$db->close();
