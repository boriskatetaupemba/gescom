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
 * \file    custom/bankaudit/audit_log.php
 * \ingroup bankaudit
 * \brief   Global audit log viewer of bank accounts/lines changes (req 4).
 *          Includes deletions, so a removed line can still be traced.
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

dol_include_once('/bankaudit/class/bankaudit.class.php');

$langs->loadLangs(array('banks', 'compta', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
if (!$user->hasRight('bankaudit', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Filters (the empty option of a combo is submitted as "-1": normalize it to "no filter")
$search_objecttype = GETPOST('search_objecttype', 'aZ09');
$search_objectaction = GETPOST('search_objectaction', 'aZ09');
if ($search_objecttype === '-1') {
	$search_objecttype = '';
}
if ($search_objectaction === '-1') {
	$search_objectaction = '';
}
$search_user = GETPOSTINT('search_user');
$search_objectid = GETPOSTINT('search_objectid');
$search_datestart = '';
$search_dateend = '';
if (GETPOSTINT('search_datestartyear')) {
	$search_datestart = dol_mktime(0, 0, 0, GETPOSTINT('search_datestartmonth'), GETPOSTINT('search_datestartday'), GETPOSTINT('search_datestartyear'));
}
if (GETPOSTINT('search_dateendyear')) {
	$search_dateend = dol_mktime(23, 59, 59, GETPOSTINT('search_dateendmonth'), GETPOSTINT('search_dateendday'), GETPOSTINT('search_dateendyear'));
}

// Pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_objecttype = '';
	$search_objectaction = '';
	$search_user = 0;
	$search_objectid = 0;
	$search_datestart = '';
	$search_dateend = '';
}


/*
 * Actions
 */

// Restore a deleted bank line (and its internal-transfer counterpart, if it was deleted too)
if ($action == 'restore' && GETPOSTINT('logid') > 0) {
	if (!$user->hasRight('banque', 'modifier')) {
		accessforbidden();
	}
	$result = BankAudit::restoreDeletedLine($db, $user, GETPOSTINT('logid'));
	if (!empty($result['error'])) {
		$errkey = 'BankAuditRestore'.$result['error'];
		$errmsg = $langs->trans($errkey);
		if ($errmsg === $errkey) {
			$errmsg = $result['error'];
		}
		setEventMessages($errmsg, null, 'errors');
	} else {
		$msg = $langs->trans('BankAuditRestoreDone', $result['newid']);
		if (!empty($result['newlinked'])) {
			$msg .= ' / '.$langs->trans('BankAuditRestoreDoneLinked', $result['newlinked']);
		}
		setEventMessages($msg, null, 'mesgs');
	}
	$urlparam = '';
	if ($search_objecttype !== '') {
		$urlparam .= '&search_objecttype='.urlencode($search_objecttype);
	}
	if ($search_objectaction !== '') {
		$urlparam .= '&search_objectaction='.urlencode($search_objectaction);
	}
	header("Location: ".$_SERVER['PHP_SELF'].'?restored=1'.$urlparam);
	exit;
}

// Build the shared WHERE clause
$where = " WHERE l.entity IN (".getEntity('bank_account').")";
if ($search_objecttype !== '') {
	$where .= " AND l.object_type = '".$db->escape($search_objecttype)."'";
}
if ($search_objectaction !== '') {
	$where .= " AND l.action = '".$db->escape($search_objectaction)."'";
}
if ($search_user > 0) {
	$where .= " AND l.fk_user = ".((int) $search_user);
}
if ($search_objectid > 0) {
	$where .= " AND l.object_id = ".((int) $search_objectid);
}
if (!empty($search_datestart)) {
	$where .= " AND l.tms >= '".$db->idate($search_datestart)."'";
}
if (!empty($search_dateend)) {
	$where .= " AND l.tms <= '".$db->idate($search_dateend)."'";
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('BankAuditLogTitle'), '', '', 0, 0, '', '', '', 'mod-bankaudit page-auditlog');

print load_fiche_titre($langs->trans('BankAuditLogTitle'), '', 'technic');

print '<span class="opacitymedium">'.$langs->trans('BankAuditLogDesc').'</span><br><br>';

// Count total
$nbtotal = 0;
$sqlc = "SELECT COUNT(l.rowid) as nb FROM ".MAIN_DB_PREFIX."bank_audit_log as l".$where;
$resc = $db->query($sqlc);
if ($resc && ($oc = $db->fetch_object($resc))) {
	$nbtotal = (int) $oc->nb;
}

$sql = "SELECT l.rowid, l.tms, l.object_type, l.object_id, l.action, l.field_name, l.old_value, l.new_value, l.context, l.ip,";
$sql .= " l.fk_user, u.lastname, u.firstname, u.login";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_audit_log as l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = l.fk_user";
$sql .= $where;
$sql .= " ORDER BY l.tms DESC, l.rowid DESC";
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$param = '';
if ($search_objecttype !== '') {
	$param .= '&search_objecttype='.urlencode($search_objecttype);
}
if ($search_objectaction !== '') {
	$param .= '&search_objectaction='.urlencode($search_objectaction);
}
if ($search_user > 0) {
	$param .= '&search_user='.((int) $search_user);
}
if ($search_objectid > 0) {
	$param .= '&search_objectid='.((int) $search_objectid);
}
if (!empty($search_datestart)) {
	$param .= '&search_datestartyear='.dol_print_date($search_datestart, '%Y').'&search_datestartmonth='.dol_print_date($search_datestart, '%m').'&search_datestartday='.dol_print_date($search_datestart, '%d');
}
if (!empty($search_dateend)) {
	$param .= '&search_dateendyear='.dol_print_date($search_dateend, '%Y').'&search_dateendmonth='.dol_print_date($search_dateend, '%m').'&search_dateendday='.dol_print_date($search_dateend, '%d');
}

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="formfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print_barre_liste('', $page, $_SERVER['PHP_SELF'], $param, '', '', '', $nbtotal, $nbtotal, 'technic', 0, '', '', $limit);

$typearray = array(
	'bank_account' => $langs->trans('BankAccount'),
	'bank_line' => $langs->trans('BankTransaction'),
);
$actionarray = array(
	'CREATE' => $langs->trans('BankAuditActionCreate'),
	'UPDATE' => $langs->trans('BankAuditActionUpdate'),
	'DELETE' => $langs->trans('BankAuditActionDelete'),
	'RESTORE' => $langs->trans('BankAuditActionRestore'),
);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
$dts = !empty($search_datestart) ? $search_datestart : -1;
$dte = !empty($search_dateend) ? $search_dateend : -1;
print $form->selectDate($dts, 'search_datestart', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print '<br>';
print $form->selectDate($dte, 'search_dateend', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
print '</td>';
print '<td class="liste_titre">'.$form->select_dolusers($search_user > 0 ? $search_user : '', 'search_user', 1, null, 0, '', '', (string) $conf->entity, 0, 0, '', 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre">'.$form->selectarray('search_objecttype', $typearray, $search_objecttype, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre"><input type="text" class="maxwidth50" name="search_objectid" value="'.($search_objectid > 0 ? (int) $search_objectid : '').'"></td>';
print '<td class="liste_titre">'.$form->selectarray('search_objectaction', $actionarray, $search_objectaction, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('User').'</th>';
print '<th>'.$langs->trans('Type').'</th>';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Action').'</th>';
print '<th>'.$langs->trans('Field').'</th>';
print '<th>'.$langs->trans('OldValue').' / '.$langs->trans('NewValue').'</th>';
print '<th class="center">'.$langs->trans('Source').'</th>';
print '<th class="center">'.$langs->trans('Action').'</th>';
print '</tr>';

$nb = 0;
$userscache = array();
while ($obj = $db->fetch_object($resql)) {
	$nb++;
	if ($nb > $limit) {
		break;
	}

	// User
	if (!empty($obj->fk_user)) {
		$tmpuser = new User($db);
		$tmpuser->id = (int) $obj->fk_user;
		$tmpuser->lastname = $obj->lastname;
		$tmpuser->firstname = $obj->firstname;
		$tmpuser->login = $obj->login;
		$userlabel = $tmpuser->getNomUrl(-1);
	} else {
		$userlabel = '<span class="opacitymedium">'.$langs->trans('System').'</span>';
	}

	// Type
	$typelabel = isset($typearray[$obj->object_type]) ? $typearray[$obj->object_type] : $obj->object_type;

	// Action + colour
	$actlabel = isset($actionarray[$obj->action]) ? $actionarray[$obj->action] : $obj->action;
	$actbadge = 'badge-status4';
	if ($obj->action == 'CREATE') {
		$actbadge = 'badge-status4';
	} elseif ($obj->action == 'UPDATE') {
		$actbadge = 'badge-status1';
	} elseif ($obj->action == 'DELETE') {
		$actbadge = 'badge-status8';
	} elseif ($obj->action == 'RESTORE') {
		$actbadge = 'badge-status4';
	}

	// Field
	$fieldlabel = $obj->field_name;
	if (!empty($obj->field_name)) {
		$transfield = $langs->trans('BankAuditField'.ucfirst($obj->field_name));
		if ($transfield != 'BankAuditField'.ucfirst($obj->field_name)) {
			$fieldlabel = $transfield;
		}
	}

	// Object link (only meaningful for non-deleted objects)
	$reflink = '#'.((int) $obj->object_id);
	if ($obj->object_type == 'bank_line' && $obj->action != 'DELETE') {
		$reflink = '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $obj->object_id).'">#'.((int) $obj->object_id).'</a>';
	} elseif ($obj->object_type == 'bank_account' && $obj->action != 'DELETE') {
		$reflink = '<a href="'.DOL_URL_ROOT.'/compta/bank/card.php?id='.((int) $obj->object_id).'">#'.((int) $obj->object_id).'</a>';
	}

	// Old/new value (for DELETE, old_value carries a JSON snapshot)
	$valtxt = '';
	if ($obj->action == 'DELETE' && !empty($obj->old_value)) {
		$d = json_decode($obj->old_value, true);
		if (is_array($d)) {
			$parts = array();
			foreach ($d as $k => $v) {
				$parts[] = dol_escape_htmltag($k).'='.dol_escape_htmltag(is_scalar($v) ? (string) $v : json_encode($v));
			}
			$valtxt = implode(', ', $parts);
		} else {
			$valtxt = dol_escape_htmltag($obj->old_value);
		}
	} elseif ($obj->action == 'UPDATE') {
		$valtxt = '<span class="opacitymedium">'.dol_escape_htmltag($obj->old_value).'</span> &rarr; <b>'.dol_escape_htmltag($obj->new_value).'</b>';
	} elseif ($obj->action == 'CREATE' && !empty($obj->new_value)) {
		$valtxt = dol_escape_htmltag($obj->new_value);
	}

	// Auto sync source
	$autosync = false;
	$restored = false;
	if (!empty($obj->context)) {
		$ctx = json_decode($obj->context, true);
		if (is_array($ctx) && isset($ctx['source']) && $ctx['source'] == 'auto_sync') {
			$autosync = true;
		}
		if (is_array($ctx) && !empty($ctx['restored'])) {
			$restored = true;
		}
	}

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->tms), 'dayhoursec').'</td>';
	print '<td class="tdoverflowmax150">'.$userlabel.'</td>';
	print '<td>'.dol_escape_htmltag($typelabel).'</td>';
	print '<td>'.$reflink.'</td>';
	print '<td><span class="badge '.$actbadge.'">'.dol_escape_htmltag($actlabel).'</span></td>';
	print '<td>'.dol_escape_htmltag($fieldlabel).'</td>';
	print '<td class="tdoverflowmax300" title="'.dol_escape_htmltag(dol_string_nohtmltag($valtxt)).'">'.$valtxt.'</td>';
	print '<td class="center">';
	if ($autosync) {
		print '<span class="badge badge-status4" title="'.dol_escape_htmltag($langs->trans('BankAuditAutoSyncHelp')).'">&#10227; '.$langs->trans('BankAuditAutoSync').'</span>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('BankAuditManual').'</span>';
	}
	print '</td>';
	// Restore action (only for line deletions that carry a full snapshot and were not restored yet)
	print '<td class="center nowraponall">';
	if ($obj->object_type == 'bank_line' && $obj->action == 'DELETE') {
		$snaptest = json_decode($obj->old_value, true);
		$canrestore = is_array($snaptest) && !empty($snaptest['bank']);
		if ($restored) {
			print '<span class="badge badge-status4">'.$langs->trans('BankAuditRestored').'</span>';
		} elseif ($canrestore && $user->hasRight('banque', 'modifier')) {
			print '<a class="butAction small" href="'.$_SERVER['PHP_SELF'].'?action=restore&logid='.((int) $obj->rowid).'&token='.newToken().'">'.$langs->trans('BankAuditRestoreLine').'</a>';
		} elseif (!$canrestore) {
			print '<span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('BankAuditRestoreInsufficientData')).'">-</span>';
		}
	}
	print '</td>';
	print '</tr>';
}

if ($nb == 0) {
	print '<tr class="oddeven"><td colspan="9" class="opacitymedium center">'.$langs->trans('BankAuditNoHistory').'</td></tr>';
}

print '</table>';
print '</div>';

print '</form>';

llxFooter();
$db->close();
