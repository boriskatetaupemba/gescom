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
 * \file    custom/posnova/rate.php
 * \ingroup posnova
 * \brief   Daily USD/CDF exchange-rate management.
 */

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

$langs->loadLangs(array('posnova@posnova', 'main', 'multicurrency'));

if (!isModEnabled('posnova')) {
	accessforbidden();
}
if (!$user->hasRight('posnova', 'run')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);

/*
 * Actions
 */
if ($action === 'setrate' && $user->hasRight('posnova', 'rate')) {
	$day = GETPOST('day', 'alpha');
	$rate = (float) price2num(GETPOST('rate', 'alpha'), 'MU');
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
		$day = dol_print_date(dol_now(), '%Y-%m-%d');
	}
	if ($rate <= 0) {
		setEventMessages($langs->trans('PosNovaErrInvalidRate'), null, 'errors');
	} else {
		$r = PosNova::setDailyRate($db, $user, $rate, $day);
		if ($r > 0) {
			setEventMessages($langs->trans('PosNovaRateSaved'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		setEventMessages($langs->trans('PosNovaGenericError'), null, 'errors');
	}
}

/*
 * View
 */
llxHeader('', $langs->trans('PosNovaDailyRate'), '', '', 0, 0, '', '', '', 'mod-posnova page-rate');

print load_fiche_titre($langs->trans('PosNovaDailyRate'), '', 'money-bill-alt');

$today = dol_print_date(dol_now(), '%Y-%m-%d');
$rateInfo = PosNova::getDailyRate($db);
$sysRate = PosNova::getMulticurrencyUsdCdf($db);

// Current rate banner.
print '<div class="center" style="margin:10px 0 20px">';
print '<div style="display:inline-block;padding:18px 32px;border-radius:16px;color:#fff;background:linear-gradient(135deg,#f59e0b,#f97316);box-shadow:0 8px 22px rgba(249,115,22,.34)">';
print '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;opacity:.85">'.$langs->trans('PosNovaRateOfTheDay').' &middot; '.dol_print_date(dol_now(), 'day').'</div>';
print '<div style="font-size:30px;font-weight:800">1 USD = '.price($rateInfo['rate'], 0, $langs, 1, 0, 3).' '.dol_escape_htmltag($cdfCode).'</div>';
print '<div style="font-size:12px;opacity:.9">'.($rateInfo['source'] === 'MANUAL' ? $langs->trans('PosNovaRateManual') : $langs->trans('PosNovaRateSystem')).'</div>';
print '</div></div>';

// Set-rate form.
if ($user->hasRight('posnova', 'rate')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="setrate">';
	print '<table class="noborder centpercent" style="max-width:640px;margin:auto">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('PosNovaSetManualRate').'</th></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('Date').'</td><td><input type="date" name="day" value="'.dol_escape_htmltag($today).'"></td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('PosNovaRateUsdCdf').'</td><td><input type="text" name="rate" inputmode="decimal" value="'.($rateInfo['rate'] > 0 ? price($rateInfo['rate'], 0, $langs, 0, -1, 3) : '').'" placeholder="2850.000"> '.dol_escape_htmltag($cdfCode).'</td></tr>';
	if ($sysRate > 0) {
		print '<tr class="oddeven"><td>'.$langs->trans('PosNovaSystemReference').'</td><td class="opacitymedium">1 USD = '.price($sysRate, 0, $langs, 1, 0, 3).' '.dol_escape_htmltag($cdfCode).' ('.$langs->trans('multicurrency').')</td></tr>';
	}
	print '</table>';
	print '<div class="center" style="margin-top:14px"><button class="button button-save">'.$langs->trans('Save').'</button></div>';
	print '</form>';
	print '<br>';
}

// History.
print load_fiche_titre($langs->trans('PosNovaRateHistory'), '', '');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Date').'</th>';
print '<th class="right">'.$langs->trans('PosNovaRateUsdCdf').'</th>';
print '<th class="center">'.$langs->trans('Source').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th class="center">'.$langs->trans('DateCreation').'</th>';
print '</tr>';

$sql = "SELECT er.rowid, er.day, er.rate_usd_cdf, er.source, er.fk_user, er.datec,";
$sql .= " u.lastname, u.firstname";
$sql .= " FROM ".MAIN_DB_PREFIX."pos_exchange_rate er";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = er.fk_user";
$sql .= " WHERE er.entity = ".((int) $conf->entity);
$sql .= " ORDER BY er.day DESC, er.rowid DESC".$db->plimit(60);
$resql = $db->query($sql);
$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;
		$badge = $obj->source === 'MANUAL'
			? '<span class="badge badge-status4 badge-status">'.$langs->trans('PosNovaRateManual').'</span>'
			: '<span class="badge badge-status1 badge-status">'.$langs->trans('PosNovaRateSystem').'</span>';
		$uname = trim(($obj->firstname ? $obj->firstname.' ' : '').$obj->lastname);
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->day, 'gmt'), 'day').'</td>';
		print '<td class="right"><b>'.price($obj->rate_usd_cdf, 0, $langs, 1, 0, 3).'</b> '.dol_escape_htmltag($cdfCode).'</td>';
		print '<td class="center">'.$badge.'</td>';
		print '<td>'.dol_escape_htmltag($uname ? $uname : '-').'</td>';
		print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->datec), 'dayhour').'</td>';
		print '</tr>';
	}
}
if ($nb == 0) {
	print '<tr class="oddeven"><td colspan="5" class="opacitymedium center">'.$langs->trans('PosNovaNoRateHistory').'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
