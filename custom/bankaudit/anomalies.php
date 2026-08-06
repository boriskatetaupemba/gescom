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
 * \file    custom/bankaudit/anomalies.php
 * \ingroup bankaudit
 * \brief   Anomaly notification center (Spec 8 / P12).
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
dol_include_once('/bankaudit/class/bankauditanomaly.class.php');

$langs->loadLangs(array('banks', 'bankaudit@bankaudit'));

if (!isModEnabled('bankaudit')) {
	accessforbidden();
}
if (!$user->hasRight('bankaudit', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$statusfilter = GETPOST('statusfilter', 'aZ09');
if ($statusfilter === '') {
	$statusfilter = 'new';
}

/*
 * Actions
 */

if ($action == 'ack' && $id > 0 && $user->hasRight('bankaudit', 'read')) {
	$sql = "UPDATE ".MAIN_DB_PREFIX."bank_anomaly_log SET statut = 'ack', fk_user_ack = ".((int) $user->id).", tms_ack = '".$db->idate(dol_now())."'";
	$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
	$db->query($sql);
	setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF'].'?statusfilter='.urlencode($statusfilter));
	exit;
}

if ($action == 'falsepositive' && $id > 0 && $user->hasRight('bankaudit', 'read')) {
	$sql = "UPDATE ".MAIN_DB_PREFIX."bank_anomaly_log SET statut = 'false_positive', fk_user_ack = ".((int) $user->id).", tms_ack = '".$db->idate(dol_now())."'";
	$sql .= " WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
	$db->query($sql);
	setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF'].'?statusfilter='.urlencode($statusfilter));
	exit;
}

if ($action == 'scan' && $user->hasRight('bankaudit', 'read')) {
	$nb = BankAuditAnomaly::scanRecentLines($db, $user, 30);
	setEventMessages($langs->trans('AnomaliesTitle').': '.$nb, null, 'mesgs');
	header("Location: ".$_SERVER['PHP_SELF'].'?statusfilter='.urlencode($statusfilter));
	exit;
}


/*
 * View
 */

llxHeader('', $langs->trans('AnomaliesTitle'), '', '', 0, 0, '', '', '', 'mod-bankaudit page-anomalies');

// New anomalies count
$nbNew = 0;
$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."bank_anomaly_log WHERE statut = 'new' AND entity = ".((int) $conf->entity);
$resql = $db->query($sql);
if ($resql && ($o = $db->fetch_object($resql))) {
	$nbNew = (int) $o->nb;
}

$title = $langs->trans('AnomaliesTitle');
if ($nbNew > 0) {
	$title .= ' <span class="badge badge-status8 badge-status">'.$langs->trans('NewAnomaliesCount', $nbNew).'</span>';
}

$scanbtn = '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=scan&statusfilter='.urlencode($statusfilter).'&token='.newToken().'">'.$langs->trans('AnomaliesMenu').' &#8635;</a>';
print load_fiche_titre($title, $scanbtn, 'bug');

// Status filter
$filters = array(
	'all' => $langs->trans('AnomalyAllStatus'),
	'new' => $langs->trans('AnomalyStatusNew'),
	'ack' => $langs->trans('AnomalyStatusAck'),
	'false_positive' => $langs->trans('AnomalyStatusFalsePositive'),
);
print '<div class="tabsAction">';
foreach ($filters as $key => $lab) {
	$cls = ($key === $statusfilter) ? 'butActionRefused' : 'butAction';
	print '<a class="'.$cls.'" href="'.$_SERVER['PHP_SELF'].'?statusfilter='.urlencode($key).'">'.$lab.'</a>';
}
print '</div>';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('AuditDateTime').'</th>';
print '<th>'.$langs->trans('AnomalyRule').'</th>';
print '<th>'.$langs->trans('BankAccount').'</th>';
print '<th>'.$langs->trans('AnomalyDetail').'</th>';
print '<th class="center">'.$langs->trans('AnomalyStatusNew').'</th>';
print '<th class="right">'.$langs->trans('Action').'</th>';
print '</tr>';

$sql = "SELECT l.rowid, l.tms, l.rule_code, l.fk_bank, l.fk_account, l.severity, l.detail, l.statut,";
$sql .= " ba.ref as account_ref, ba.label as account_label";
$sql .= " FROM ".MAIN_DB_PREFIX."bank_anomaly_log as l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."bank_account as ba ON ba.rowid = l.fk_account";
$sql .= " WHERE l.entity = ".((int) $conf->entity);
if ($statusfilter !== 'all') {
	$sql .= " AND l.statut = '".$db->escape($statusfilter)."'";
}
$sql .= " ORDER BY l.tms DESC, l.rowid DESC";

$resql = $db->query($sql);
$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;

		$rulelabel = $langs->trans('Rule'.$obj->rule_code);
		if ($rulelabel === 'Rule'.$obj->rule_code) {
			$rulelabel = $obj->rule_code;
		}

		// Severity dot
		$sevcolor = '#999';
		if ($obj->severity === 'warning') {
			$sevcolor = '#e8a33d';
		} elseif ($obj->severity === 'critical') {
			$sevcolor = '#cf2e2e';
		}
		$sevdot = '<span title="'.dol_escape_htmltag($langs->trans('AnomalySeverity'.ucfirst($obj->severity))).'" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'.$sevcolor.';margin-right:5px"></span>';

		// Detail JSON -> readable
		$detailtxt = '';
		if (!empty($obj->detail)) {
			$d = json_decode($obj->detail, true);
			if (is_array($d)) {
				$parts = array();
				foreach ($d as $k => $v) {
					$parts[] = dol_escape_htmltag($k).': '.dol_escape_htmltag(is_scalar($v) ? (string) $v : json_encode($v));
				}
				$detailtxt = implode(', ', $parts);
			} else {
				$detailtxt = dol_escape_htmltag($obj->detail);
			}
		}

		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->tms), 'dayhour').'</td>';
		print '<td>'.$sevdot.'<b>'.dol_escape_htmltag($obj->rule_code).'</b> - '.dol_escape_htmltag($rulelabel).'</td>';
		print '<td>'.($obj->fk_account ? dol_escape_htmltag($obj->account_ref.' - '.$obj->account_label) : '<span class="opacitymedium">-</span>').'</td>';
		print '<td class="small">'.$detailtxt.'</td>';

		// Status
		$statlabel = $langs->trans('AnomalyStatus'.($obj->statut === 'false_positive' ? 'FalsePositive' : ucfirst($obj->statut)));
		print '<td class="center">'.dol_escape_htmltag($statlabel).'</td>';

		// Actions
		print '<td class="right nowraponall">';
		if (!empty($obj->fk_bank)) {
			print '<a class="butAction small" href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $obj->fk_bank).'">'.$langs->trans('AnomalyViewLine').'</a>';
		}
		if ($obj->statut === 'new') {
			print '<a class="butAction small" href="'.$_SERVER['PHP_SELF'].'?action=ack&id='.((int) $obj->rowid).'&statusfilter='.urlencode($statusfilter).'&token='.newToken().'">'.$langs->trans('AnomalyAcknowledge').'</a>';
			print '<a class="butActionDelete small" href="'.$_SERVER['PHP_SELF'].'?action=falsepositive&id='.((int) $obj->rowid).'&statusfilter='.urlencode($statusfilter).'&token='.newToken().'">'.$langs->trans('AnomalyMarkFalsePositive').'</a>';
		}
		print '</td>';
		print '</tr>';
	}
}
if ($nb == 0) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans('NoAnomalyDetected').'</td></tr>';
}
print '</table>';
print '</div>';

// End of page
llxFooter();
$db->close();
