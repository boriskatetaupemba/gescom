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
 * \file    custom/posnova/pos.php
 * \ingroup posnova
 * \brief   POS launcher: pick a terminal, open / resume / lock / close a cash session.
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

$langs->loadLangs(array('posnova@posnova', 'main', 'cashdesk', 'stocks'));

$action = GETPOST('action', 'aZ09');
$posid = GETPOSTINT('id');
$cookieToken = empty($_COOKIE['posnova_token']) ? '' : preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE['posnova_token']);

$terminalUrl = dol_buildpath('/custom/posnova/terminal.php', 1);
$selfUrl = dol_buildpath('/custom/posnova/pos.php', 1);

/**
 * Drop a durable POS session cookie carrying the secret token.
 *
 * @param  string $token Session token (empty clears the cookie)
 * @return void
 */
function pn_set_cookie($token)
{
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	$expire = $token === '' ? (time() - 3600) : (time() + 3600 * getDolGlobalInt('POSNOVA_SESSION_LIFETIME_HOURS', 12));
	setcookie('posnova_token', $token, $expire, '/', '', $secure, true);
	$_COOKIE['posnova_token'] = $token;
}

$message = '';
$messageType = 'info';


/* ============================================================================
 * POST — open a session
 * ========================================================================= */
if ($action === 'open' && $posid > 0 && $user->hasRight('posnova', 'session')) {
	$pos = new PosConfig($db);
	if ($pos->fetch($posid) > 0) {
		$check = $pos->validateForLaunch();
		if (!$check['ok']) {
			$message = $langs->trans('PosNovaErrCannotLaunch');
			$messageType = 'err';
		} else {
			$rateInfo = PosNova::getDailyRate($db);
			if ((float) $rateInfo['rate'] <= 0) {
				$message = $langs->trans('PosNovaErrNoRate');
				$messageType = 'err';
			} else {
				$fundUsd = (float) price2num(GETPOST('fund_usd', 'alpha'), 'MU');
				$fundCdf = (float) price2num(GETPOST('fund_cdf', 'alpha'), 'MU');
				$session = new PosSession($db);
				$r = $session->open($user, $posid, (int) $pos->fk_warehouse, $fundUsd, $fundCdf, $rateInfo);
				if ($r > 0) {
					pn_set_cookie($session->session_token);
					header('Location: '.$terminalUrl);
					exit;
				}
				$message = $langs->trans($session->error ? $session->error : 'PosNovaGenericError');
				$messageType = 'err';
			}
		}
	}
}


/* ============================================================================
 * POST — unlock a paused session
 * ========================================================================= */
if ($action === 'unlock' && $posid > 0) {
	$session = new PosSession($db);
	if ($session->fetchOpenForPos($posid) > 0 && $session->status === 'LOCKED') {
		if ($session->fk_user_open == $user->id || $user->hasRight('posnova', 'session')) {
			$pin = GETPOST('pin', 'alphanohtml');
			$r = $session->unlock($pin);
			if ($r > 0) {
				pn_set_cookie($session->session_token);
				header('Location: '.$terminalUrl);
				exit;
			}
			$message = $langs->trans('PosNovaErrWrongPin');
			$messageType = 'err';
			$action = 'select';
		}
	}
}


/* ============================================================================
 * POST — lock the current session (from the terminal menu)
 * ========================================================================= */
if ($action === 'dolock') {
	$session = new PosSession($db);
	if ($cookieToken !== '' && $session->fetchByToken($cookieToken) > 0 && $session->status === 'OPEN') {
		$pin = GETPOST('pin', 'alphanohtml');
		if (dol_strlen($pin) >= 4) {
			$session->lock($pin);
			pn_set_cookie('');
			$message = $langs->trans('PosNovaSessionLocked');
			$messageType = 'info';
		} else {
			$message = $langs->trans('PosNovaErrPinTooShort');
			$messageType = 'err';
			$action = 'lock';
		}
	}
}


/* ============================================================================
 * POST — close the current session
 * ========================================================================= */
if ($action === 'doclose') {
	$session = new PosSession($db);
	if ($cookieToken !== '' && $session->fetchByToken($cookieToken) > 0) {
		if ($session->fk_user_open == $user->id || $user->hasRight('posnova', 'session')) {
			$finalUsd = (float) price2num(GETPOST('final_usd', 'alpha'), 'MU');
			$finalCdf = (float) price2num(GETPOST('final_cdf', 'alpha'), 'MU');
			$summary = $session->getCashSummary();
			$r = $session->close($user, $finalUsd, $finalCdf);
			if ($r > 0) {
				pn_set_cookie('');
				$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);
				$expUsd = isset($summary['expected'][PosNova::USD]) ? (float) $summary['expected'][PosNova::USD] : 0;
				$expCdf = isset($summary['expected'][$cdfCode]) ? (float) $summary['expected'][$cdfCode] : 0;
				$_SESSION['posnova_zreport'] = array(
					'ref' => $session->ref,
					'expUsd' => $expUsd, 'expCdf' => $expCdf,
					'finalUsd' => $finalUsd, 'finalCdf' => $finalCdf,
					'diffUsd' => $finalUsd - $expUsd, 'diffCdf' => $finalCdf - $expCdf,
				);
				header('Location: '.$selfUrl.'?action=zreport');
				exit;
			}
			$message = $langs->trans($session->error ? $session->error : 'PosNovaGenericError');
			$messageType = 'err';
			$action = 'close';
		}
	}
}

$cdfCode = getDolGlobalString('POSNOVA_CDF_CURRENCY_CODE', PosNova::CDF);
$rateInfo = PosNova::getDailyRate($db);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo substr($langs->defaultlang, 0, 2); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>PosNova</title>
	<link rel="stylesheet" href="<?php echo dol_escape_htmltag(dol_buildpath('/custom/posnova/css/posnova.css', 1)); ?>?v=1">
</head>
<body class="pn-body">
<div class="pn-app">
	<header class="pn-topbar">
		<div class="pn-topcell pn-pos-id"><span class="pn-k">PosNova</span><span class="pn-v"><?php echo $langs->trans('PosNovaLaunch'); ?></span></div>
		<div class="pn-spacer"></div>
		<div class="pn-rate <?php echo $rateInfo['source'] === 'MANUAL' ? 'pn-rate-manual' : 'pn-rate-system'; ?>">
			<span class="pn-rate-ico">&#128176;</span>
			<div><div class="pn-rate-k">1 USD =</div><div class="pn-rate-v"><?php echo price($rateInfo['rate'], 0, $langs, 1, 0, 3); ?> <?php echo dol_escape_htmltag($cdfCode); ?></div></div>
		</div>
		<a class="pn-iconbtn" href="<?php echo dol_escape_htmltag(DOL_URL_ROOT.'/index.php'); ?>" style="text-decoration:none">&#10162; <?php echo $langs->trans('Back'); ?></a>
	</header>

	<div class="pn-center">
<?php
if ($message !== '') {
	echo '<div class="pn-alert pn-alert-'.($messageType === 'err' ? 'err' : ($messageType === 'warn' ? 'warn' : 'info')).'" style="max-width:480px">'.dol_escape_htmltag($message).'</div>';
}

/* ----------------------------------------------------------------------------
 * Z-report after closing
 * ------------------------------------------------------------------------- */
if ($action === 'zreport' && !empty($_SESSION['posnova_zreport'])) {
	$z = $_SESSION['posnova_zreport'];
	unset($_SESSION['posnova_zreport']);
	echo '<div class="pn-card pn-card-lg">';
	echo '<h1>&#9989; '.$langs->trans('PosNovaSessionClosed').'</h1>';
	echo '<div class="pn-sub">'.dol_escape_htmltag($z['ref']).'</div>';
	echo '<table class="pn-stocktable"><thead><tr><th></th><th class="pn-num">'.$langs->trans('PosNovaExpected').'</th><th class="pn-num">'.$langs->trans('PosNovaCounted').'</th><th class="pn-num">'.$langs->trans('PosNovaDiscrepancy').'</th></tr></thead><tbody>';
	echo '<tr><td><span class="pn-curbadge pn-cur-USD">USD</span></td><td class="pn-num">'.PosNova::formatAmount($z['expUsd'], 'USD').'</td><td class="pn-num">'.PosNova::formatAmount($z['finalUsd'], 'USD').'</td><td class="pn-num" style="color:'.($z['diffUsd'] < 0 ? 'var(--pn-danger)' : 'var(--pn-ok)').'">'.PosNova::formatAmount($z['diffUsd'], 'USD').'</td></tr>';
	echo '<tr><td><span class="pn-curbadge pn-cur-CDF">'.dol_escape_htmltag($cdfCode).'</span></td><td class="pn-num">'.PosNova::formatAmount($z['expCdf'], $cdfCode).'</td><td class="pn-num">'.PosNova::formatAmount($z['finalCdf'], $cdfCode).'</td><td class="pn-num" style="color:'.($z['diffCdf'] < 0 ? 'var(--pn-danger)' : 'var(--pn-ok)').'">'.PosNova::formatAmount($z['diffCdf'], $cdfCode).'</td></tr>';
	echo '</tbody></table>';
	echo '<div style="margin-top:20px"><a class="pn-btn pn-btn-primary" style="text-decoration:none;display:inline-flex" href="'.dol_escape_htmltag($selfUrl).'">'.$langs->trans('PosNovaBackToTerminals').'</a></div>';
	echo '</div>';
}

/* ----------------------------------------------------------------------------
 * Lock form (ask PIN before pausing)
 * ------------------------------------------------------------------------- */
elseif ($action === 'lock') {
	echo '<div class="pn-card">';
	echo '<h1>&#128274; '.$langs->trans('PosNovaLockSession').'</h1>';
	echo '<div class="pn-sub">'.$langs->trans('PosNovaLockSessionHint').'</div>';
	echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'">';
	echo '<input type="hidden" name="token" value="'.newToken().'">';
	echo '<input type="hidden" name="action" value="dolock">';
	echo '<div class="pn-field"><label>'.$langs->trans('PosNovaPin').'</label><input type="password" name="pin" inputmode="numeric" autocomplete="off" autofocus required minlength="4"></div>';
	echo '<button class="pn-btn pn-btn-primary" style="width:100%">'.$langs->trans('PosNovaLock').'</button>';
	echo '</form></div>';
}

/* ----------------------------------------------------------------------------
 * Close form (count cash) — uses the current cookie session
 * ------------------------------------------------------------------------- */
elseif ($action === 'close') {
	$session = new PosSession($db);
	if ($cookieToken !== '' && $session->fetchByToken($cookieToken) > 0 && in_array($session->status, array('OPEN', 'LOCKED'), true)) {
		$summary = $session->getCashSummary();
		$expUsd = isset($summary['expected'][PosNova::USD]) ? (float) $summary['expected'][PosNova::USD] : 0;
		$expCdf = isset($summary['expected'][$cdfCode]) ? (float) $summary['expected'][$cdfCode] : 0;
		echo '<div class="pn-card pn-card-lg">';
		echo '<h1>&#128179; '.$langs->trans('PosNovaCloseSession').'</h1>';
		echo '<div class="pn-sub">'.dol_escape_htmltag($session->ref).' &middot; '.$langs->trans('PosNovaExpectedInDrawer').'</div>';
		echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'">';
		echo '<input type="hidden" name="token" value="'.newToken().'">';
		echo '<input type="hidden" name="action" value="doclose">';
		echo '<div class="pn-grid2">';
		echo '<div class="pn-field"><label><span class="pn-curbadge pn-cur-USD">USD</span> '.$langs->trans('PosNovaCountedCash').'<br><small>'.$langs->trans('PosNovaExpected').': '.PosNova::formatAmount($expUsd, 'USD').'</small></label><input type="text" name="final_usd" inputmode="decimal" value="'.price($expUsd, 0, $langs, 0, -1, 2).'"></div>';
		echo '<div class="pn-field"><label><span class="pn-curbadge pn-cur-CDF">'.dol_escape_htmltag($cdfCode).'</span> '.$langs->trans('PosNovaCountedCash').'<br><small>'.$langs->trans('PosNovaExpected').': '.PosNova::formatAmount($expCdf, $cdfCode).'</small></label><input type="text" name="final_cdf" inputmode="decimal" value="'.price($expCdf, 0, $langs, 0, -1, 0).'"></div>';
		echo '</div>';
		echo '<div class="pn-footbtns" style="margin-top:8px">';
		echo '<a class="pn-btn pn-btn-ghost" style="text-decoration:none" href="'.dol_escape_htmltag($terminalUrl).'">'.$langs->trans('Cancel').'</a>';
		echo '<button class="pn-btn pn-btn-primary">'.$langs->trans('PosNovaConfirmClose').'</button>';
		echo '</div>';
		echo '</form></div>';
	} else {
		echo '<div class="pn-card"><div class="pn-alert pn-alert-warn">'.$langs->trans('PosNovaErrSessionNotOpen').'</div><a class="pn-btn pn-btn-primary" style="text-decoration:none;display:inline-flex" href="'.dol_escape_htmltag($selfUrl).'">'.$langs->trans('PosNovaBackToTerminals').'</a></div>';
	}
}

/* ----------------------------------------------------------------------------
 * Select a terminal: resume existing session or show the open form
 * ------------------------------------------------------------------------- */
elseif ($action === 'select' && $posid > 0) {
	$pos = new PosConfig($db);
	if ($pos->fetch($posid) <= 0) {
		echo '<div class="pn-card"><div class="pn-alert pn-alert-err">'.$langs->trans('PosNovaErrPosNotFound').'</div></div>';
	} else {
		$check = $pos->validateForLaunch();
		$session = new PosSession($db);
		$hasSession = $session->fetchOpenForPos($posid) > 0;

		echo '<div class="pn-card">';
		echo '<h1>&#128290; '.dol_escape_htmltag($pos->label).'</h1>';
		echo '<div class="pn-sub">'.dol_escape_htmltag($pos->ref).'</div>';

		if (!$check['ok']) {
			echo '<div class="pn-alert pn-alert-err">'.$langs->trans('PosNovaErrCannotLaunch').'</div><ul>';
			foreach ($check['errors'] as $e) {
				echo '<li>'.$langs->trans($e).'</li>';
			}
			echo '</ul>';
			echo '<a class="pn-btn pn-btn-ghost" style="text-decoration:none;display:inline-flex" href="'.dol_escape_htmltag($selfUrl).'">'.$langs->trans('Back').'</a>';
		} elseif ($hasSession) {
			$owner = ($session->fk_user_open == $user->id || $user->hasRight('posnova', 'session'));
			echo '<div class="pn-alert pn-alert-info">'.$langs->trans('PosNovaSessionInProgress').' &middot; '.dol_escape_htmltag($session->ref).'</div>';
			if (!$owner) {
				echo '<div class="pn-alert pn-alert-warn">'.$langs->trans('PosNovaErrSessionOtherUser').'</div>';
			} elseif ($session->status === 'LOCKED') {
				echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'">';
				echo '<input type="hidden" name="token" value="'.newToken().'">';
				echo '<input type="hidden" name="action" value="unlock">';
				echo '<input type="hidden" name="id" value="'.((int) $posid).'">';
				echo '<div class="pn-field"><label>&#128274; '.$langs->trans('PosNovaPin').'</label><input type="password" name="pin" inputmode="numeric" autocomplete="off" autofocus required></div>';
				echo '<button class="pn-btn pn-btn-primary" style="width:100%">'.$langs->trans('PosNovaUnlockResume').'</button>';
				echo '</form>';
			} else {
				echo '<a class="pn-btn pn-btn-primary" style="text-decoration:none;display:flex" href="'.dol_escape_htmltag($selfUrl.'?action=resume&id='.((int) $posid).'&token='.newToken()).'">'.$langs->trans('PosNovaResumeSession').'</a>';
			}
		} else {
			// Open a fresh session.
			echo '<form method="POST" action="'.dol_escape_htmltag($selfUrl).'">';
			echo '<input type="hidden" name="token" value="'.newToken().'">';
			echo '<input type="hidden" name="action" value="open">';
			echo '<input type="hidden" name="id" value="'.((int) $posid).'">';
			echo '<div class="pn-sub">'.$langs->trans('PosNovaInitialFunds').'</div>';
			echo '<div class="pn-grid2">';
			echo '<div class="pn-field"><label><span class="pn-curbadge pn-cur-USD">USD</span></label><input type="text" name="fund_usd" inputmode="decimal" value="0"></div>';
			echo '<div class="pn-field"><label><span class="pn-curbadge pn-cur-CDF">'.dol_escape_htmltag($cdfCode).'</span></label><input type="text" name="fund_cdf" inputmode="decimal" value="0"></div>';
			echo '</div>';
			if ((float) $rateInfo['rate'] <= 0) {
				echo '<div class="pn-alert pn-alert-warn">'.$langs->trans('PosNovaErrNoRate').' <a href="'.dol_escape_htmltag(dol_buildpath('/custom/posnova/rate.php', 1)).'">'.$langs->trans('PosNovaSetRate').'</a></div>';
			}
			echo '<button class="pn-btn pn-btn-primary" style="width:100%"'.((float) $rateInfo['rate'] <= 0 ? ' disabled' : '').'>&#9654; '.$langs->trans('PosNovaOpenSession').'</button>';
			echo '</form>';
		}
		echo '</div>';
	}
}

/* ----------------------------------------------------------------------------
 * GET resume (owner confirmed) — set cookie and jump to the terminal
 * ------------------------------------------------------------------------- */
elseif ($action === 'resume' && $posid > 0) {
	$session = new PosSession($db);
	if ($session->fetchOpenForPos($posid) > 0 && $session->status === 'OPEN'
		&& ($session->fk_user_open == $user->id || $user->hasRight('posnova', 'session'))) {
		pn_set_cookie($session->session_token);
		header('Location: '.$terminalUrl);
		exit;
	}
	echo '<div class="pn-card"><div class="pn-alert pn-alert-warn">'.$langs->trans('PosNovaErrSessionNotOpen').'</div></div>';
}

/* ----------------------------------------------------------------------------
 * Default: terminal grid
 * ------------------------------------------------------------------------- */
else {
	$cfg = new PosConfig($db);
	$terminals = $cfg->fetchAll(true);
	echo '<div class="pn-card pn-card-lg">';
	echo '<h1>'.$langs->trans('PosNovaChooseTerminal').'</h1>';
	echo '<div class="pn-sub">'.$langs->trans('PosNovaChooseTerminalHint').'</div>';
	if (empty($terminals)) {
		echo '<div class="pn-alert pn-alert-warn">'.$langs->trans('PosNovaNoTerminal').'</div>';
		if ($user->hasRight('posnova', 'setup')) {
			echo '<a class="pn-btn pn-btn-primary" style="text-decoration:none;display:inline-flex" href="'.dol_escape_htmltag(dol_buildpath('/custom/posnova/admin/pos_card.php', 1).'?action=create').'">'.$langs->trans('PosNovaCreateTerminal').'</a>';
		}
	} else {
		echo '<div class="pn-poslist">';
		foreach ($terminals as $t) {
			$sess = new PosSession($db);
			$busy = $sess->fetchOpenForPos($t->id) > 0;
			$whlabel = '';
			$resw = $db->query("SELECT label, ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $t->fk_warehouse));
			if ($resw && $db->num_rows($resw)) {
				$ow = $db->fetch_object($resw);
				$whlabel = $ow->label ? $ow->label : $ow->ref;
			}
			echo '<a class="pn-postile'.($busy ? ' pn-busy' : '').'" style="text-decoration:none;color:inherit" href="'.dol_escape_htmltag($selfUrl.'?action=select&id='.((int) $t->id)).'">';
			echo '<div class="pn-postile-ico">&#128290;</div>';
			echo '<div class="pn-postile-name">'.dol_escape_htmltag($t->label).'</div>';
			echo '<div class="pn-postile-wh">&#127978; '.dol_escape_htmltag($whlabel).'</div>';
			if ($busy) {
				echo '<div><span class="pn-tag">&#9679; '.$langs->trans('PosNovaSessionInProgress').'</span></div>';
			}
			echo '</a>';
		}
		echo '</div>';
	}
	echo '</div>';
}
?>
	</div>
</div>
</body>
</html>
<?php
$db->close();
