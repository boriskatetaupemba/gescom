<?php
/* ============================================================
 * movement_create.php — Dolibarr 20.0.4
 * ============================================================ */

$res = 0;
if (!$res && file_exists('../../main.inc.php'))        { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php'))     { $res = @include '../../../main.inc.php'; }
if (!$res && file_exists('../main.inc.php'))           { $res = @include '../main.inc.php'; }
if (!$res && file_exists('../../../../main.inc.php'))  { $res = @include '../../../../main.inc.php'; }
if (!$res && !defined('DOL_DOCUMENT_ROOT')) { die('main.inc.php introuvable'); }

foreach (array(
    DOL_DOCUMENT_ROOT.'/product/class/product.class.php',
    DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php',
    DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php',
    DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php',
) as $f) { require_once $f; }

if (empty($user->rights->stock->mouvement->creer)) { accessforbidden(); }
$langs->loadLangs(array('products', 'stocks', 'errors'));

$tbl_prefix = MAIN_DB_PREFIX;
$entity_id  = (int)$conf->entity;

// ─── Endpoint AJAX : infos produit (stock par entrepôt + PMP / coût d'achat) ───
if (GETPOST('action', 'aZ09') === 'getproductinfo') {
    header('Content-Type: application/json; charset=UTF-8');
    $pid = GETPOSTINT('fk_product');
    $out = array('ok' => false, 'pmp' => 0, 'cost_price' => 0, 'stocks' => array(), 'total' => 0);
    if ($pid > 0) {
        $rp = $db->query("SELECT pmp, cost_price FROM ".$tbl_prefix."product WHERE rowid = ".((int) $pid)." AND entity IN (".getEntity('product').")");
        if ($rp && ($op = $db->fetch_object($rp))) {
            $out['ok'] = true;
            $out['pmp'] = (float) $op->pmp;
            $out['cost_price'] = (float) $op->cost_price;
            $db->free($rp);
            $rs = $db->query("SELECT fk_entrepot, reel FROM ".$tbl_prefix."product_stock WHERE fk_product = ".((int) $pid));
            if ($rs) {
                $tot = 0;
                while ($os = $db->fetch_object($rs)) {
                    $out['stocks'][(string) ((int) $os->fk_entrepot)] = (float) $os->reel;
                    $tot += (float) $os->reel;
                }
                $out['total'] = $tot;
                $db->free($rs);
            }
        }
    }
    echo json_encode($out);
    exit;
}

// ─── Effacement du mini-journal de session ───
if (GETPOSTINT('clearrecent')) {
    unset($_SESSION['trs_recent_movements']);
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

$list_entrepots = array();
$r = $db->query("SELECT rowid, ref, description FROM ".$tbl_prefix."entrepot WHERE statut = 1 AND entity = ".$entity_id." ORDER BY ref ASC");
if ($r) {
    while ($o = $db->fetch_object($r)) {
        $lbl = trim($o->ref.((!empty($o->description)) ? ' — '.$o->description : ''));
        $list_entrepots[] = array('id' => (int)$o->rowid, 'label' => $lbl);
    }
    $db->free($r);
}

$list_products = array();
$r2 = $db->query("SELECT rowid, ref, label FROM ".$tbl_prefix."product WHERE tosell = 1 AND entity = ".$entity_id." ORDER BY ref ASC");
if ($r2) {
    while ($o = $db->fetch_object($r2)) {
        $list_products[] = array('id' => (int)$o->rowid, 'ref' => $o->ref, 'label' => $o->label);
    }
    $db->free($r2);
}

$action        = GETPOST('action', 'aZ09');
$movement_type = GETPOST('movement_type', 'aZ09');
if (!in_array($movement_type, array('transfer', 'entry', 'exit'), true)) { $movement_type = 'transfer'; }
$fk_product    = GETPOSTINT('fk_product');
$fk_entrepot_s = GETPOSTINT('fk_entrepot_source');
$fk_entrepot_d = GETPOSTINT('fk_entrepot_destination');
$qty           = price2num(GETPOST('qty', 'alpha'), 'MS');
$date_mouv_str = GETPOST('date_mouvement', 'alpha');
$label_input   = trim(GETPOST('label_mouvement', 'alphanohtml'));
$prix_raw      = GETPOST('prix_achat', 'alpha');
$prix_achat    = price2num($prix_raw, 'MU');

$error = 0; $message = ''; $msgtype = '';
$msg_prod = $msg_qty = $msg_src = $msg_dst = $msg_code = $msg_date = '';
$msg_label = $msg_price = $msg_type = '';

if ($action === 'transfer') {
    // Validation commune à tous les types de mouvement
    if (!$error && empty($fk_product)) { $error++; $message = 'Veuillez sélectionner un produit.'; $msgtype = 'error'; }

    // Validations spécifiques au type de mouvement
    if (!$error && $movement_type === 'transfer') {
        if (empty($fk_entrepot_s)) { $error++; $message = "Veuillez sélectionner l'entrepôt source."; $msgtype = 'error'; }
        elseif (empty($fk_entrepot_d)) { $error++; $message = "Veuillez sélectionner l'entrepôt destination."; $msgtype = 'error'; }
        elseif ((int)$fk_entrepot_s === (int)$fk_entrepot_d) { $error++; $message = "Source et destination identiques."; $msgtype = 'error'; }
    } elseif (!$error && $movement_type === 'entry') {
        if (empty($fk_entrepot_d)) { $error++; $message = "Veuillez sélectionner l'entrepôt destination."; $msgtype = 'error'; }
        elseif ($label_input === '') { $error++; $message = 'Veuillez saisir le libellé / description.'; $msgtype = 'error'; }
        elseif ($prix_raw === '' || !is_numeric($prix_achat)) { $error++; $message = "Veuillez saisir le prix d'achat."; $msgtype = 'error'; }
        elseif ((float)$prix_achat < 0) { $error++; $message = "Le prix d'achat doit être positif."; $msgtype = 'error'; }
    } elseif (!$error && $movement_type === 'exit') {
        if (empty($fk_entrepot_s)) { $error++; $message = "Veuillez sélectionner l'entrepôt source."; $msgtype = 'error'; }
        elseif ($label_input === '') { $error++; $message = 'Veuillez saisir le libellé / description.'; $msgtype = 'error'; }
    }

    // Quantité (commune à tous les types)
    if (!$error && ($qty <= 0 || !is_numeric($qty))) { $error++; $message = 'La quantité doit être > 0.'; $msgtype = 'error'; }

    // Date : l'input est de type "date" (YYYY-MM-DD), pas datetime-local
    // On utilise le fuseau horaire du serveur pour éviter le décalage d'un jour
    $ts_mouv = dol_now();
    if (!empty($date_mouv_str)) {
        // Format reçu : "YYYY-MM-DD"
        // On construit midi heure locale pour éviter tout décalage DST
        $dn = $date_mouv_str.' 12:00:00';
        // On force l'interprétation en heure locale (pas UTC)
        $tp = strtotime($dn);
        if ($tp !== false && $tp > 0) { $ts_mouv = $tp; }
    }
    // Le transfert conserve son libellé fixe historique ; entrée/sortie utilisent la saisie utilisateur
    $label_mv = ($movement_type === 'transfer') ? 'Transfert' : $label_input;
    $codemouvement = 'ES-'.date('YmdHis'); // heure d'enregistrement réelle

    // Produit (commun à tous les types)
    if (!$error) { $product = new Product($db); if ($product->fetch($fk_product) <= 0) { $error++; $message = 'Produit introuvable.'; $msgtype = 'error'; } }

    // ── TRANSFERT — backend existant conservé sans modification ───────────────
    if (!$error && $movement_type === 'transfer') {
        $entrepot_s = new Entrepot($db); $entrepot_d = new Entrepot($db);
        if ($entrepot_s->fetch($fk_entrepot_s) <= 0) { $error++; $message = 'Entrepôt source introuvable.'; $msgtype = 'error'; }
        elseif ($entrepot_d->fetch($fk_entrepot_d) <= 0) { $error++; $message = 'Entrepôt destination introuvable.'; $msgtype = 'error'; }
        if (!$error) {
            $db->begin();
            $mouvS = new MouvementStock($db);
            $id_s  = $mouvS->_create($user, $fk_product, $fk_entrepot_s, (float)$qty * -1, 0, 0, $label_mv, $codemouvement);
            if ($id_s < 0) { $db->rollback(); $error++; $message = 'Erreur sortie : '.dol_escape_htmltag($mouvS->error); $msgtype = 'error'; }
            if (!$error) {
                $dsql = $db->idate($ts_mouv);
                $db->query("UPDATE ".$tbl_prefix."stock_mouvement SET datem='".$dsql."' WHERE rowid=".(int)$id_s);
                $mouvD = new MouvementStock($db);
                $id_d  = $mouvD->_create($user, $fk_product, $fk_entrepot_d, (float)$qty, 0, 0, $label_mv, $codemouvement);
                if ($id_d < 0) { $db->rollback(); $error++; $message = 'Erreur entrée : '.dol_escape_htmltag($mouvD->error); $msgtype = 'error'; }
                else { $db->query("UPDATE ".$tbl_prefix."stock_mouvement SET datem='".$dsql."' WHERE rowid=".(int)$id_d); }
            }
            if (!$error) {
                $db->commit();
                $msg_src = htmlspecialchars($entrepot_s->ref, ENT_QUOTES);
                $msg_dst = htmlspecialchars($entrepot_d->ref, ENT_QUOTES);
            }
        }
    }

    // ── ENTRÉE DE STOCK — mécanisme standard Dolibarr (reception) ────────────
    if (!$error && $movement_type === 'entry') {
        $entrepot_d = new Entrepot($db);
        if ($entrepot_d->fetch($fk_entrepot_d) <= 0) { $error++; $message = 'Entrepôt destination introuvable.'; $msgtype = 'error'; }
        if (!$error) {
            $db->begin();
            $mouv = new MouvementStock($db);
            $res  = $mouv->reception($user, $fk_product, $fk_entrepot_d, (float)$qty, (float)$prix_achat, $label_mv, '', '', '', $ts_mouv, 0, $codemouvement);
            if ($res <= 0) {
                $db->rollback(); $error++;
                $emsg = $mouv->error; if (empty($emsg) && !empty($mouv->errors)) { $emsg = implode(', ', $mouv->errors); }
                $message = "Erreur lors de l'entrée de stock : ".dol_escape_htmltag($emsg); $msgtype = 'error';
            } else {
                $db->commit();
                $msg_dst   = htmlspecialchars($entrepot_d->ref, ENT_QUOTES);
                $msg_price = price($prix_achat);
            }
        }
    }

    // ── SORTIE DE STOCK — mécanisme standard Dolibarr (livraison) ────────────
    if (!$error && $movement_type === 'exit') {
        $entrepot_s = new Entrepot($db);
        if ($entrepot_s->fetch($fk_entrepot_s) <= 0) { $error++; $message = 'Entrepôt source introuvable.'; $msgtype = 'error'; }
        // Garde-fou : interdit la sortie à découvert si le stock négatif n'est pas autorisé
        if (!$error && !getDolGlobalInt('STOCK_ALLOW_NEGATIVE')) {
            $avail = 0;
            $rq = $db->query("SELECT reel FROM ".$tbl_prefix."product_stock WHERE fk_product = ".((int) $fk_product)." AND fk_entrepot = ".((int) $fk_entrepot_s));
            if ($rq && ($oq = $db->fetch_object($rq))) { $avail = (float) $oq->reel; $db->free($rq); }
            if ((float) $qty > $avail) { $error++; $message = "Stock insuffisant : ".price($avail)." disponible(s) dans l'entrepôt source."; $msgtype = 'error'; }
        }
        if (!$error) {
            $db->begin();
            $mouv = new MouvementStock($db);
            $res  = $mouv->livraison($user, $fk_product, $fk_entrepot_s, (float)$qty, 0, $label_mv, $ts_mouv, '', '', '', 0, $codemouvement);
            if ($res <= 0) {
                $db->rollback(); $error++;
                $emsg = $mouv->error; if (empty($emsg) && !empty($mouv->errors)) { $emsg = implode(', ', $mouv->errors); }
                $message = "Erreur lors de la sortie de stock : ".dol_escape_htmltag($emsg); $msgtype = 'error';
            } else {
                $db->commit();
                $msg_src = htmlspecialchars($entrepot_s->ref, ENT_QUOTES);
            }
        }
    }

    // ── Succès commun : messages + réinitialisation ───────────────────────
    if (!$error && $msgtype !== 'error') {
        $msg_prod  = htmlspecialchars($product->ref.(!empty($product->label) ? ' – '.$product->label : ''), ENT_QUOTES);
        $msg_qty   = price($qty);
        $msg_code  = htmlspecialchars($codemouvement, ENT_QUOTES);
        $msg_date  = dol_print_date($ts_mouv, 'day');
        $msg_label = htmlspecialchars($label_mv, ENT_QUOTES);
        $libtype   = array('transfer' => 'Transfert', 'entry' => 'Entrée de stock', 'exit' => 'Sortie de stock');
        $msg_type  = $libtype[$movement_type];
        $msgtype   = 'ok';

        // Mini-journal de session : on conserve les 5 derniers mouvements
        if (empty($_SESSION['trs_recent_movements']) || !is_array($_SESSION['trs_recent_movements'])) { $_SESSION['trs_recent_movements'] = array(); }
        array_unshift($_SESSION['trs_recent_movements'], array(
            'type' => $movement_type,
            'tlbl' => $msg_type,
            'prod' => $product->ref.(!empty($product->label) ? ' – '.$product->label : ''),
            'pid'  => (int) $product->id,
            'qty'  => (float) $qty,
            'src'  => (isset($entrepot_s) && $movement_type !== 'entry') ? $entrepot_s->ref : '',
            'dst'  => (isset($entrepot_d) && $movement_type !== 'exit') ? $entrepot_d->ref : '',
            'code' => $codemouvement,
            'date' => $ts_mouv,
        ));
        $_SESSION['trs_recent_movements'] = array_slice($_SESSION['trs_recent_movements'], 0, 5);

        // Réinitialisation : uniquement produit, quantité et prix d'achat.
        // Type, date, entrepôts et libellé conservent leurs valeurs pour enchaîner rapidement.
        $qty = '';
        $fk_product = 0;
        $prix_raw = '';
        $prix_achat = '';
    }
}

// Pré-remplissage
$dp = $ds = $dd = '';
if ($fk_product > 0)    { foreach ($list_products  as $p) { if ($p['id'] === $fk_product)    { $dp = $p['ref'].(!empty($p['label']) ? ' — '.$p['label'] : ''); break; } } }
if ($fk_entrepot_s > 0) { foreach ($list_entrepots as $w) { if ($w['id'] === $fk_entrepot_s) { $ds = $w['label']; break; } } }
if ($fk_entrepot_d > 0) { foreach ($list_entrepots as $w) { if ($w['id'] === $fk_entrepot_d) { $dd = $w['label']; break; } } }

$dv  = !empty($date_mouv_str) ? $date_mouv_str : date('Y-m-d');
$dl  = htmlspecialchars($label_input, ENT_QUOTES);
$dpx = ($prix_raw !== '') ? htmlspecialchars($prix_raw, ENT_QUOTES) : '';
$dtype = $movement_type;
$jp = json_encode(array_values($list_products),  JSON_UNESCAPED_UNICODE);
$je = json_encode(array_values($list_entrepots), JSON_UNESCAPED_UNICODE);
$fa = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES);

llxHeader('', 'Mouvement de stock', '');
?>
<style>
/* ── Hérite les variables CSS du thème Dolibarr actif ─────────────
   Les couleurs utilisent les variables natives de Dolibarr (--colorblack,
   --colorbackbody, etc.) et se rabattent sur des valeurs neutres si absent. */
.trs {
    --t-primary:  var(--colortext, #2563eb);
    --t-bg:       var(--colorbackbody, #f9fafb);
    --t-surface:  var(--colorbacktitle, #ffffff);
    --t-border:   var(--colorseparator, #e5e7eb);
    --t-txt:      var(--colorblack, #111827);
    --t-sub:      var(--colortext, #6b7280);
    --t-green:    #16a34a;
    --t-red:      #e11d48;
    --t-acc:      var(--butactionbg, #2563eb);
    --t-acc-dk:   var(--butactionbghover, #1d4ed8);
    --t-acc-txt:  var(--butactiontxt, #ffffff);
    --t-acc-lt:   color-mix(in srgb, var(--t-acc) 10%, white);
    --t-acc-bd:   color-mix(in srgb, var(--t-acc) 25%, white);
    --r:  10px;
    --rs: 7px;
    font-family: var(--fontfamily, system-ui, -apple-system, sans-serif);
    color: var(--t-txt);
    max-width: 700px;
    margin: 0 auto;
}
.trs * { box-sizing: border-box; }

/* ── Segmented control : type de mouvement ──────────────────────── */
.trs-seg {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 4px;
    padding: 4px;
    background: var(--t-bg);
    border: 1.5px solid var(--t-border);
    border-radius: var(--rs);
    margin-bottom: 16px;
}
.trs-seg-b {
    height: 40px; border: 2px solid transparent; border-radius: 6px;
    background: transparent; cursor: pointer;
    font-size: .8rem; font-weight: 600; font-family: inherit;
    color: var(--t-sub); letter-spacing: -.01em;
    display: flex; align-items: center; justify-content: center;
    transition: background .14s, color .14s, box-shadow .14s, border-color .14s;
}
.trs-seg-b:hover { color: var(--t-txt); }
.trs-seg-b.on {
    background: var(--t-surface);
    color: var(--t-acc);
    border-color: var(--t-acc);
    box-shadow: 0 0 0 3px var(--t-acc-lt), 0 1px 3px rgba(0,0,0,.14);
    font-weight: 700;
}

/* ── Utilitaires d'affichage dynamique ──────────────────────────── */
.trs-hidden { display: none !important; }
.trs-wh.single { grid-template-columns: 1fr; }

/* ── Carte ──────────────────────────────────────────────────────── */
.trs-card {
    background: var(--t-surface);
    border: 1px solid var(--t-border);
    border-radius: var(--r);
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.06), 0 4px 20px rgba(0,0,0,.05);
    width: 100%;
}

/* ── Header ─────────────────────────────────────────────────────── */
.trs-hd {
    background: var(--t-acc);
    padding: 20px 28px;
    display: flex; align-items: center; gap: 14px;
    position: relative; overflow: hidden;
}
.trs-hd::after {
    content: ''; position: absolute; right: -30px; top: -30px;
    width: 150px; height: 150px; border-radius: 50%;
    background: rgba(255,255,255,.07); pointer-events: none;
}
.trs-hd-ic {
    width: 40px; height: 40px; border-radius: 9px; flex-shrink: 0;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
}
.trs-hd-ic svg { width: 20px; height: 20px; fill: var(--t-acc-txt); }
.trs-hd-t { font-size: 1rem; font-weight: 700; color: var(--t-acc-txt); margin: 0; line-height: 1.2; }
.trs-hd-s { font-size: .73rem; color: rgba(255,255,255,.65); margin: 2px 0 0; }

/* ── Body ───────────────────────────────────────────────────────── */
.trs-bd { padding: 22px 28px 4px; }

/* ── Toast succès ───────────────────────────────────────────────── */
.trs-ok {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: var(--rs);
    padding: 13px 16px;
    margin-bottom: 20px;
    animation: trs-pop .2s ease;
}
.trs-ok-h {
    display: flex; align-items: center; gap: 7px;
    font-size: .82rem; font-weight: 700; color: #15803d;
    cursor: pointer; list-style: none; user-select: none;
}
.trs-ok-h::-webkit-details-marker { display: none; }
.trs-ok-h svg { width: 15px; height: 15px; fill: currentColor; flex-shrink: 0; }
.trs-ok-h .trs-ok-chev {
    width: 16px; height: 16px; margin-left: auto;
    fill: #15803d; transition: transform .18s ease;
}
details.trs-ok[open] .trs-ok-chev { transform: rotate(180deg); }
.trs-ok-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px 12px;
    margin-top: 12px;
}
.trs-ok-cell { font-size: .78rem; }
.trs-ok-lbl {
    display: block; font-size: .68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--t-txt); margin-bottom: 1px;
}
.trs-ok-val { color: #14532d; font-weight: 600; font-size: .8rem; }

/* ── Toast erreur ───────────────────────────────────────────────── */
.trs-err {
    background: #fff1f2;
    border: 1px solid #fda4af;
    border-radius: var(--rs);
    padding: 11px 14px;
    margin-bottom: 20px;
    font-size: .82rem; color: #9f1239;
    display: flex; align-items: center; gap: 8px;
    animation: trs-pop .2s ease;
}
.trs-err svg { width: 15px; height: 15px; fill: currentColor; flex-shrink: 0; }
@keyframes trs-pop { from { opacity:0; transform:translateY(-5px); } to { opacity:1; transform:translateY(0); } }

/* ── Champs flottants ───────────────────────────────────────────── */
.trs-f { position: relative; margin-bottom: 14px; }
.trs-fi {
    width: 100%; height: 52px;
    padding: 20px 14px 6px;
    font-size: .875rem; font-family: inherit;
    color: var(--t-txt); background: var(--t-bg);
    border: 1.5px solid var(--t-border); border-radius: var(--rs);
    outline: none;
    transition: border-color .14s, box-shadow .14s, background .14s;
    -moz-appearance: textfield;
}
.trs-fi::-webkit-outer-spin-button,
.trs-fi::-webkit-inner-spin-button { -webkit-appearance: none; }
.trs-fi:hover { border-color: #9ca3af; }
.trs-fi:focus {
    border-color: var(--t-acc);
    background: var(--t-surface);
    box-shadow: 0 0 0 3px var(--t-acc-lt);
}
.trs-fl {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%);
    font-size: .875rem; color: var(--t-sub);
    pointer-events: none; transform-origin: left center;
    transition: transform .14s, color .14s, font-size .14s;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    max-width: calc(100% - 28px);
}
.trs-fl .r { color: var(--t-red); margin-left: 1px; }
/* Label monté */
.trs-fi:focus ~ .trs-fl,
.trs-fi.filled ~ .trs-fl {
    transform: translateY(-14px) scale(.72);
    color: var(--t-acc); font-weight: 600;
}
input[type="date"].trs-fi ~ .trs-fl {
    transform: translateY(-14px) scale(.72); color: var(--t-sub);
}
input[type="date"].trs-fi:focus ~ .trs-fl {
    color: var(--t-acc); font-weight: 600;
}
input[type="date"].trs-fi { color-scheme: light; }

/* ── Entrepôts ──────────────────────────────────────────────────── */
.trs-wh {
    display: grid;
    grid-template-columns: 1fr 36px 1fr;
    align-items: start;
    gap: 0 8px;
    margin-bottom: 14px;
}
.trs-wh .trs-f { margin-bottom: 0; }
.trs-wh-mid {
    display: flex; align-items: flex-start; justify-content: center;
    padding-top: 12px;
}
.trs-arr {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--t-acc-lt);
    border: 1.5px solid var(--t-acc-bd);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; cursor: pointer; padding: 0;
    transition: background .13s, border-color .13s, transform .25s ease;
}
.trs-arr:hover { background: var(--t-acc); border-color: var(--t-acc); }
.trs-arr:hover svg { fill: var(--t-acc-txt); }
.trs-arr:active { transform: rotate(180deg); }
.trs-arr svg { width: 16px; height: 16px; fill: var(--t-acc); transition: fill .13s; }

/* ── Autocomplete ───────────────────────────────────────────────── */
.trs-ac { position: relative; }
.trs-drop {
    display: none; position: absolute; top: calc(100% + 3px); left: 0; right: 0;
    z-index: 9999; background: var(--t-surface);
    border: 1.5px solid var(--t-acc); border-radius: var(--rs);
    max-height: 210px; overflow-y: auto;
    box-shadow: 0 6px 20px rgba(0,0,0,.1);
    animation: trs-pop .1s ease;
}
.trs-drop.on { display: block; }
.trs-di {
    padding: 9px 14px; font-size: .82rem; cursor: pointer;
    border-bottom: 1px solid var(--t-border);
    display: flex; align-items: center; gap: 8px;
    color: var(--t-txt); transition: background .09s;
}
.trs-di:last-child { border-bottom: none; }
.trs-di:hover, .trs-di.on2 { background: var(--t-acc-lt); color: var(--t-acc); }
.trs-di em { font-style: normal; font-weight: 700; color: var(--t-acc); }
.trs-ref {
    font-size: .67rem; font-weight: 700; flex-shrink: 0;
    background: var(--t-acc-lt); color: var(--t-acc);
    padding: 1px 6px; border-radius: 4px;
}
.trs-none { padding: 12px; font-size: .78rem; color: var(--t-sub); text-align: center; }

/* ── Stepper ────────────────────────────────────────────────────── */
.trs-qty-lbl {
    display: block; font-size: .72rem; font-weight: 600;
    color: var(--t-txt); margin-bottom: 6px; opacity: .75;
}
.trs-qty-lbl .r { color: var(--t-red); margin-left: 1px; }
.trs-step {
    display: inline-flex; align-items: center;
    height: 52px; border: 1.5px solid var(--t-border);
    border-radius: var(--rs); overflow: hidden; background: var(--t-bg);
    transition: border-color .14s, box-shadow .14s;
    width: 180px;
}
.trs-step:focus-within {
    border-color: var(--t-acc); background: var(--t-surface);
    box-shadow: 0 0 0 3px var(--t-acc-lt);
}
.trs-sb {
    width: 44px; height: 100%; border: none; background: transparent;
    font-size: 1.2rem; color: var(--t-sub); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .1s, color .1s; user-select: none; flex-shrink: 0;
}
.trs-sb:hover { background: var(--t-acc-lt); color: var(--t-acc); }
.trs-si {
    flex: 1; height: 100%; border: none; background: transparent;
    text-align: center; font-size: 1rem; font-weight: 700;
    font-family: inherit; color: var(--t-txt); outline: none;
    -moz-appearance: textfield; min-width: 0;
}
.trs-si::-webkit-outer-spin-button, .trs-si::-webkit-inner-spin-button { -webkit-appearance: none; }
.trs-qty-row { margin-bottom: 20px; }

/* ── Footer ─────────────────────────────────────────────────────── */
.trs-ft {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 28px 20px;
    border-top: 1px solid var(--t-border);
    background: var(--t-bg);
}
.trs-btn-p {
    display: inline-flex; align-items: center; gap: 8px;
    height: 44px; padding: 0 24px;
    background: var(--t-acc); color: var(--t-acc-txt);
    border: none; border-radius: var(--rs);
    font-size: .875rem; font-weight: 600; font-family: inherit;
    cursor: pointer; letter-spacing: -.01em;
    transition: background .13s, transform .1s, box-shadow .13s;
    box-shadow: 0 1px 3px rgba(0,0,0,.15), 0 3px 10px rgba(0,0,0,.08);
}
.trs-btn-p:hover { background: var(--t-acc-dk); transform: translateY(-1px); }
.trs-btn-p:active { transform: translateY(0); }
.trs-btn-p svg { width: 15px; height: 15px; fill: var(--t-acc-txt); }
.trs-btn-p .sp {
    display: none; width: 13px; height: 13px;
    border: 2px solid rgba(255,255,255,.3); border-top-color: #fff;
    border-radius: 50%; animation: trs-spin .5s linear infinite;
}
.submitting .sp { display: block; } .submitting .si { display: none; }
@keyframes trs-spin { to { transform: rotate(360deg); } }
.trs-btn-c {
    display: inline-flex; align-items: center; gap: 5px;
    height: 44px; padding: 0 16px;
    color: var(--t-sub); font-size: .8rem; font-weight: 500;
    text-decoration: none; border-radius: var(--rs);
    border: 1px solid transparent;
    transition: color .13s, background .13s, border-color .13s;
}
.trs-btn-c:hover { color: var(--t-txt); background: var(--t-border); border-color: var(--t-border); }
.trs-btn-c svg { width: 12px; height: 12px; fill: currentColor; }

/* ── Panneau stock temps réel ───────────────────────────────────── */
.trs-stock {
    margin: -4px 0 14px;
    background: var(--t-acc-lt);
    border: 1px solid var(--t-acc-bd);
    border-radius: var(--rs);
    padding: 9px 13px;
    animation: trs-pop .15s ease;
}
.trs-stock-row { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.trs-stock-ic { display: flex; flex-shrink: 0; }
.trs-stock-ic svg { width: 17px; height: 17px; fill: var(--t-acc); }
.trs-stock-main { font-size: .8rem; font-weight: 600; color: var(--t-txt); }
.trs-stock-main b { color: var(--t-acc); font-weight: 700; }
.trs-stock-main .neg { color: var(--t-red); }
.trs-stock-total {
    margin-left: auto; font-size: .72rem; font-weight: 600;
    color: var(--t-sub); white-space: nowrap;
}
.trs-stock-total b { color: var(--t-txt); }

/* ── Quantité : conteneur + bouton Max ──────────────────────────── */
.trs-qty-wrap { display: inline-flex; align-items: center; gap: 8px; }
.trs-max {
    height: 52px; padding: 0 14px;
    border: 1.5px solid var(--t-acc-bd); border-radius: var(--rs);
    background: var(--t-acc-lt); color: var(--t-acc);
    font-size: .78rem; font-weight: 700; font-family: inherit;
    cursor: pointer; white-space: nowrap;
    transition: background .12s, color .12s, border-color .12s;
}
.trs-max:hover { background: var(--t-acc); color: var(--t-acc-txt); border-color: var(--t-acc); }

/* ── Surlignage des champs en erreur (validation client) ────────── */
.trs-fi.trs-invalid, .trs-step.trs-invalid {
    border-color: var(--t-red) !important;
    box-shadow: 0 0 0 3px rgba(225,29,72,.14) !important;
}

/* ── Mini-journal des derniers mouvements ───────────────────────── */
.trs-journal {
    margin-top: 18px;
    background: var(--t-surface);
    border: 1px solid var(--t-border);
    border-radius: var(--r);
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.trs-journal-h {
    display: flex; align-items: center; justify-content: space-between;
    padding: 11px 16px; border-bottom: 1px solid var(--t-border);
    background: var(--t-bg);
}
.trs-journal-h > span {
    display: flex; align-items: center; gap: 7px;
    font-size: .76rem; font-weight: 700; color: var(--t-txt);
    text-transform: uppercase; letter-spacing: .04em;
}
.trs-journal-h svg { width: 15px; height: 15px; fill: var(--t-sub); }
.trs-journal-clr {
    font-size: .72rem; font-weight: 600; color: var(--t-sub);
    text-decoration: none; padding: 3px 8px; border-radius: 5px;
    transition: color .12s, background .12s;
}
.trs-journal-clr:hover { color: var(--t-red); background: #fff1f2; }
.trs-journal-l { list-style: none; margin: 0; padding: 0; }
.trs-journal-i {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 16px; border-bottom: 1px solid var(--t-border);
    font-size: .8rem;
}
.trs-journal-i:last-child { border-bottom: none; }
.trs-jb {
    flex-shrink: 0; font-size: .66rem; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: .03em;
}
.trs-jb-t { background: var(--t-acc-lt); color: var(--t-acc); }
.trs-jb-e { background: #dcfce7; color: #15803d; }
.trs-jb-x { background: #ffe4e6; color: #be123c; }
.trs-ji-main { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.trs-ji-prod {
    font-weight: 600; color: var(--t-txt);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.trs-ji-sub { font-size: .72rem; color: var(--t-sub); }
.trs-ji-qty { flex-shrink: 0; font-weight: 700; color: var(--t-txt); }
.trs-ji-date { flex-shrink: 0; font-size: .72rem; color: var(--t-sub); white-space: nowrap; }

@media (max-width: 600px) {
    .trs-wh { grid-template-columns: 1fr; gap: 10px 0; }
    .trs-wh-mid { display: none; }
    .trs-bd { padding: 18px 16px 4px; }
    .trs-ft { padding: 14px 16px 16px; flex-direction: column-reverse; gap: 8px; }
    .trs-btn-p { width: 100%; justify-content: center; }
    .trs-ok-grid { grid-template-columns: 1fr 1fr; }
    .trs-seg-b { font-size: .72rem; }
    .trs-ji-date { display: none; }
}
</style>

<?php
print '<div class="trs">';
print '<div class="trs-card">';

// Header
print '
<div class="trs-hd">
  <div class="trs-hd-ic">
    <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9 1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
  </div>
  <div>
    <p class="trs-hd-t" id="trs_title">Mouvement rapide de stock</p>
    <p class="trs-hd-s" id="trs_subtitle">Transfert, entrée ou sortie de marchandises</p>
  </div>
</div>';

print '<div class="trs-bd">';

// Toast succès — grille lisible adaptée au type de mouvement
if ($msgtype === 'ok') {
    $okhead = 'Mouvement enregistré avec succès';
    if ($movement_type === 'transfer')   { $okhead = 'Transfert enregistré avec succès'; }
    elseif ($movement_type === 'entry')  { $okhead = 'Entrée de stock enregistrée avec succès'; }
    elseif ($movement_type === 'exit')   { $okhead = 'Sortie de stock enregistrée avec succès'; }

    $cells = array();
    $cells[] = array('Type', $msg_type);
    $cells[] = array('Produit', $msg_prod);
    $cells[] = array('Quantité', $msg_qty);
    $cells[] = array('Date', $msg_date);
    if ($movement_type === 'transfer') {
        $cells[] = array('Source → Destination', $msg_src.' → '.$msg_dst);
    } elseif ($movement_type === 'entry') {
        $cells[] = array('Entrepôt destination', $msg_dst);
        $cells[] = array("Prix d'achat", $msg_price);
        $cells[] = array('Libellé', $msg_label);
    } elseif ($movement_type === 'exit') {
        $cells[] = array('Entrepôt source', $msg_src);
        $cells[] = array('Libellé', $msg_label);
    }
    $gridhtml = '';
    foreach ($cells as $c) {
        $gridhtml .= '<div class="trs-ok-cell"><span class="trs-ok-lbl">'.$c[0].'</span><span class="trs-ok-val">'.$c[1].'</span></div>';
    }
    $gridhtml .= '<div class="trs-ok-cell" style="grid-column:1/-1"><span class="trs-ok-lbl">Code mouvement</span><span class="trs-ok-val">'.$msg_code.'</span></div>';

    print '
    <details class="trs-ok">
      <summary class="trs-ok-h">
        <svg viewBox="0 0 20 20"><path d="M10 0C4.48 0 0 4.48 0 10s4.48 10 10 10 10-4.48 10-10S15.52 0 10 0zm-2 14.5-4-4 1.41-1.41L8 11.67l6.59-6.59L16 6.5l-8 8z"/></svg>
        '.$okhead.'
        <svg class="trs-ok-chev" viewBox="0 0 24 24"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
      </summary>
      <div class="trs-ok-grid">'.$gridhtml.'</div>
    </details>';
} elseif ($msgtype === 'error') {
    print '
    <div class="trs-err">
      <svg viewBox="0 0 20 20"><path d="M10 0C4.48 0 0 4.48 0 10s4.48 10 10 10 10-4.48 10-10S15.52 0 10 0zm1 15H9v-2h2v2zm0-4H9V5h2v6z"/></svg>
      '.$message.'
    </div>';
}

print '<form method="POST" action="'.$fa.'" name="formtransfer" id="formtransfer">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="transfer">';
print '<input type="hidden" name="movement_type" id="movement_type" value="'.htmlspecialchars($dtype, ENT_QUOTES).'">';
print '<input type="hidden" name="fk_product" id="fk_product" value="'.(int)$fk_product.'">';
print '<input type="hidden" name="fk_entrepot_source" id="fk_entrepot_source" value="'.(int)$fk_entrepot_s.'">';
print '<input type="hidden" name="fk_entrepot_destination" id="fk_entrepot_destination" value="'.(int)$fk_entrepot_d.'">';

// Type de mouvement (segmented control) — placé avant la date
print '<div class="trs-seg" role="tablist" aria-label="Type de mouvement">';
print '  <button type="button" class="trs-seg-b" data-type="transfer" role="tab" aria-selected="false" tabindex="-1">Transfert</button>';
print '  <button type="button" class="trs-seg-b" data-type="entry" role="tab" aria-selected="false" tabindex="-1">Entrée de stock</button>';
print '  <button type="button" class="trs-seg-b" data-type="exit" role="tab" aria-selected="false" tabindex="-1">Sortie de stock</button>';
print '</div>';

// Boîte d'erreur client (non bloquante) — masquée par défaut
print '<div class="trs-err trs-hidden" id="client_err" role="alert">';
print '  <svg viewBox="0 0 20 20"><path d="M10 0C4.48 0 0 4.48 0 10s4.48 10 10 10 10-4.48 10-10S15.52 0 10 0zm1 15H9v-2h2v2zm0-4H9V5h2v6z"/></svg>';
print '  <span id="client_err_txt"></span>';
print '</div>';

// Date
print '<div class="trs-f">';
print '<input type="date" name="date_mouvement" id="date_mouvement" class="trs-fi" value="'.htmlspecialchars($dv, ENT_QUOTES).'" placeholder=" ">';
print '<label class="trs-fl" for="date_mouvement">Date du mouvement <span class="r">*</span></label>';
print '</div>';

// Produit
print '<div class="trs-f trs-ac">';
print '<input type="text" id="txt_product" class="trs-fi" autocomplete="off" placeholder=" " value="'.htmlspecialchars($dp, ENT_QUOTES).'">';
print '<label class="trs-fl" for="txt_product">Produit <span class="r">*</span></label>';
print '<div class="trs-drop" id="dd_product"></div>';
print '</div>';

// Panneau de stock en temps réel (alimenté par AJAX) — masqué tant qu'aucun produit n'est choisi
print '<div class="trs-stock trs-hidden" id="stock_panel" aria-live="polite">';
print '  <div class="trs-stock-row">';
print '    <span class="trs-stock-ic"><svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm12 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg></span>';
print '    <span class="trs-stock-main" id="stock_main">—</span>';
print '    <span class="trs-stock-total" id="stock_total"></span>';
print '  </div>';
print '</div>';

// Entrepôts (source / destination — affichage dynamique selon le type)
print '<div class="trs-wh" id="wh_block">';
print '<div class="trs-f trs-ac" id="wrap_src">';
print '<input type="text" id="txt_entrepot_s" class="trs-fi" autocomplete="off" placeholder=" " value="'.htmlspecialchars($ds, ENT_QUOTES).'">';
print '<label class="trs-fl" for="txt_entrepot_s">Entrepôt source <span class="r">*</span></label>';
print '<div class="trs-drop" id="dd_entrepot_s"></div>';
print '</div>';
print '<div class="trs-wh-mid" id="wrap_arrow"><button type="button" class="trs-arr" id="btn_swap" title="Inverser source et destination" aria-label="Inverser source et destination"><svg viewBox="0 0 24 24"><path d="M7 7h11l-3.5-3.5L16 2l6 6-6 6-1.5-1.5L18 9H7V7zm10 10H6l3.5 3.5L8 22l-6-6 6-6 1.5 1.5L6 15h11v2z"/></svg></button></div>';
print '<div class="trs-f trs-ac" id="wrap_dst">';
print '<input type="text" id="txt_entrepot_d" class="trs-fi" autocomplete="off" placeholder=" " value="'.htmlspecialchars($dd, ENT_QUOTES).'">';
print '<label class="trs-fl" for="txt_entrepot_d">Entrepôt destination <span class="r">*</span></label>';
print '<div class="trs-drop" id="dd_entrepot_d"></div>';
print '</div>';
print '</div>';

// Libellé / Description (entrée & sortie)
print '<div class="trs-f trs-hidden" id="wrap_label">';
print '<input type="text" name="label_mouvement" id="label_mouvement" class="trs-fi" autocomplete="off" placeholder=" " value="'.$dl.'">';
print '<label class="trs-fl" for="label_mouvement">Libellé / Description <span class="r">*</span></label>';
print '</div>';

// Prix d'achat (entrée uniquement)
print '<div class="trs-f trs-hidden" id="wrap_price">';
print '<input type="text" inputmode="decimal" name="prix_achat" id="prix_achat" class="trs-fi" autocomplete="off" placeholder=" " value="'.$dpx.'">';
print '<label class="trs-fl" for="prix_achat">Prix d\'achat <span class="r">*</span></label>';
print '</div>';

// Quantité
print '<div class="trs-qty-row">';
print '<label class="trs-qty-lbl">Quantité <span class="r">*</span></label>';
print '<div class="trs-qty-wrap">';
print '<div class="trs-step">';
print '<button type="button" class="trs-sb" id="qm">−</button>';
print '<input type="number" name="qty" id="qty" class="trs-si" min="0.001" step="any" value="'.htmlspecialchars($qty !== '' ? $qty : '1', ENT_QUOTES).'">';
print '<button type="button" class="trs-sb" id="qp">+</button>';
print '</div>';
print '<button type="button" class="trs-max trs-hidden" id="btn_max" title="Utiliser tout le stock disponible">Max</button>';
print '</div>';
print '</div>';

print '</form>';
print '</div>'; // .trs-bd

// Footer
print '
<div class="trs-ft">
  <a href="movement_list.php" class="trs-btn-c">
    <svg viewBox="0 0 24 24"><path d="M20 11H7.83l4.88-4.88A1 1 0 1011.3 4.7l-6.59 6.59a1 1 0 000 1.41l6.59 6.59a1 1 0 001.42-1.41L7.83 13H20a1 1 0 100-2z"/></svg>
    Annuler
  </a>
  <button type="submit" form="formtransfer" class="trs-btn-p" id="btn-s">
    <span class="sp"></span>
    <svg class="si" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
    Enregistrer
  </button>
</div>';

print '</div>'; // .trs-card

// ── Mini-journal : les derniers mouvements enregistrés dans la session ─────
if (!empty($_SESSION['trs_recent_movements']) && is_array($_SESSION['trs_recent_movements'])) {
    $badge = array(
        'transfer' => array('lbl' => 'Transfert', 'cls' => 'trs-jb-t'),
        'entry'    => array('lbl' => 'Entrée',    'cls' => 'trs-jb-e'),
        'exit'     => array('lbl' => 'Sortie',    'cls' => 'trs-jb-x'),
    );
    print '<div class="trs-journal">';
    print '  <div class="trs-journal-h">';
    print '    <span><svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 00-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 117 7 6.96 6.96 0 01-4.95-2.05l-1.42 1.42A9 9 0 1013 3zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>Derniers mouvements</span>';
    print '    <a href="'.$fa.'?clearrecent=1" class="trs-journal-clr" title="Effacer la liste">Effacer</a>';
    print '  </div>';
    print '  <ul class="trs-journal-l">';
    foreach ($_SESSION['trs_recent_movements'] as $mv) {
        $bt   = isset($badge[$mv['type']]) ? $badge[$mv['type']] : array('lbl' => $mv['type'], 'cls' => '');
        $path = '';
        if ($mv['type'] === 'transfer')   { $path = dol_escape_htmltag($mv['src']).' → '.dol_escape_htmltag($mv['dst']); }
        elseif ($mv['type'] === 'entry')  { $path = '→ '.dol_escape_htmltag($mv['dst']); }
        elseif ($mv['type'] === 'exit')   { $path = dol_escape_htmltag($mv['src']).' →'; }
        print '    <li class="trs-journal-i">';
        print '      <span class="trs-jb '.$bt['cls'].'">'.dol_escape_htmltag($bt['lbl']).'</span>';
        print '      <span class="trs-ji-main">';
        print '        <span class="trs-ji-prod">'.dol_escape_htmltag($mv['prod']).'</span>';
        print '        <span class="trs-ji-sub">'.$path.'</span>';
        print '      </span>';
        print '      <span class="trs-ji-qty">'.price($mv['qty']).'</span>';
        print '      <span class="trs-ji-date">'.dol_print_date($mv['date'], 'day').'</span>';
        print '    </li>';
    }
    print '  </ul>';
    print '</div>';
}

print '</div>'; // .trs
?>

<script>
(function(){
    var P=<?php echo $jp; ?>,E=<?php echo $je; ?>;
    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function hi(s,q){if(!q)return esc(s);var i=s.toLowerCase().indexOf(q.toLowerCase());return i<0?esc(s):esc(s.slice(0,i))+'<em>'+esc(s.slice(i,i+q.length))+'</em>'+esc(s.slice(i+q.length));}

    function ac(ii,hi_,di,data,getText,getRow,onPick){
        var inp=document.getElementById(ii),hid=document.getElementById(hi_),drop=document.getElementById(di),ai=-1;
        function filt(q){return q?data.filter(function(x){return(x.ref?' '+(x.ref||'')+' ':'')+' '+(x.label||'').toLowerCase().indexOf(q.toLowerCase())!==-1||x.label&&x.label.toLowerCase().indexOf(q.toLowerCase())!==-1||(x.ref&&x.ref.toLowerCase().indexOf(q.toLowerCase())!==-1);}).slice(0,60):data.slice(0,40);}
        function render(q){
            var d=q?data.filter(function(x){var h=((x.ref||'')+' '+(x.label||x.ref||'')).toLowerCase();return h.indexOf(q.toLowerCase())!==-1;}).slice(0,60):data.slice(0,40);
            ai=-1;
            drop.innerHTML=d.length?d.map(function(x){return'<div class="trs-di" data-id="'+x.id+'">'+getRow(x,q)+'</div>';}).join(''):'<div class="trs-none">Aucun résultat</div>';
            drop.querySelectorAll('.trs-di').forEach(function(el){el.addEventListener('mousedown',function(e){e.preventDefault();pick(el.dataset.id);});});
            drop.classList.add('on');
        }
        function pick(id){
            var x=data.find(function(x){return String(x.id)===String(id);});
            if(x){inp.value=getText(x);hid.value=x.id;inp.classList.add('filled');inp.classList.remove('trs-invalid');}
            drop.classList.remove('on');ai=-1;
            if(onPick){ onPick(x?x.id:''); }
        }
        inp.addEventListener('input',function(){hid.value='';inp.classList.remove('filled');render(this.value.trim());if(onPick){onPick('');}});
        inp.addEventListener('focus',function(){render(this.value.trim());});
        inp.addEventListener('blur',function(){setTimeout(function(){drop.classList.remove('on');ai=-1;},200);});
        inp.addEventListener('keydown',function(e){
            var els=drop.querySelectorAll('.trs-di');
            if(e.key==='ArrowDown'){e.preventDefault();ai=Math.min(ai+1,els.length-1);}
            else if(e.key==='ArrowUp'){e.preventDefault();ai=Math.max(ai-1,0);}
            else if(e.key==='Enter'){e.preventDefault();if(ai>=0)pick(els[ai].dataset.id);return;}
            else if(e.key==='Escape'){drop.classList.remove('on');return;}
            els.forEach(function(el,i){el.classList.toggle('on2',i===ai);if(i===ai)el.scrollIntoView({block:'nearest'});});
        });
        if(inp.value)inp.classList.add('filled');
        return {pick:pick,input:inp,hidden:hid};
    }

    var acProd=ac('txt_product','fk_product','dd_product',P,
        function(x){return x.ref+(x.label?' — '+x.label:'');},
        function(x,q){return'<span class="trs-ref">'+esc(x.ref)+'</span>'+hi(x.label||x.ref,q);},
        function(){ refreshProductInfo(); }
    );
    var acSrc=ac('txt_entrepot_s','fk_entrepot_source','dd_entrepot_s',E,
        function(x){return x.label;},function(x,q){return hi(x.label,q);},
        function(){ renderStock(); }
    );
    var acDst=ac('txt_entrepot_d','fk_entrepot_destination','dd_entrepot_d',E,
        function(x){return x.label;},function(x,q){return hi(x.label,q);},
        function(){ renderStock(); }
    );

    // ── Type de mouvement : bascule dynamique des champs ────────────
    function show(el,on){ if(el){ el.classList.toggle('trs-hidden',!on); } }

    // ── Stock temps réel + pré-remplissage prix (via endpoint AJAX) ──
    var PINFO=null, PINFO_ID=0;
    var AJAX_URL=<?php echo json_encode($fa); ?>;
    function fmtNum(n){
        n=Math.round((parseFloat(n)||0)*1000)/1000;
        var s=n.toLocaleString('fr-FR',{maximumFractionDigits:3});
        return s;
    }
    function renderStock(){
        var panel=document.getElementById('stock_panel');
        var main=document.getElementById('stock_main');
        var tot=document.getElementById('stock_total');
        var maxBtn=document.getElementById('btn_max');
        var t=document.getElementById('movement_type').value;
        if(!PINFO||!PINFO.ok){ panel.classList.add('trs-hidden'); if(maxBtn)maxBtn.classList.add('trs-hidden'); return; }
        panel.classList.remove('trs-hidden');
        tot.innerHTML='Stock total : <b>'+fmtNum(PINFO.total)+'</b>';
        // L'entrepôt pertinent dépend du type : source pour transfert/sortie, destination pour entrée
        var whId='', whName='';
        if(t==='entry'){ whId=document.getElementById('fk_entrepot_destination').value; whName=acDst.input.value; }
        else { whId=document.getElementById('fk_entrepot_source').value; whName=acSrc.input.value; }
        if(whId){
            var avail=PINFO.stocks[String(whId)]||0;
            var cls=avail<=0?'neg':'';
            var lbl=(t==='entry')?'Stock destination':'Stock disponible';
            main.innerHTML=lbl+' ('+esc(whName)+') : <b class="'+cls+'">'+fmtNum(avail)+'</b>';
            // Bouton « Max » : utile pour sortie/transfert afin de vider l'entrepôt source
            if(maxBtn){
                if((t==='exit'||t==='transfer')&&avail>0){ maxBtn.classList.remove('trs-hidden'); maxBtn.dataset.max=avail; }
                else { maxBtn.classList.add('trs-hidden'); }
            }
        } else {
            main.innerHTML='Sélectionnez un entrepôt pour voir le stock';
            if(maxBtn)maxBtn.classList.add('trs-hidden');
        }
    }
    function refreshProductInfo(){
        var pid=document.getElementById('fk_product').value;
        if(!pid){ PINFO=null; PINFO_ID=0; renderStock(); return; }
        if(String(pid)===String(PINFO_ID)&&PINFO){ renderStock(); return; }
        var url=AJAX_URL+(AJAX_URL.indexOf('?')<0?'?':'&')+'action=getproductinfo&fk_product='+encodeURIComponent(pid);
        fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
                PINFO=d; PINFO_ID=pid;
                renderStock();
                // Pré-remplissage du prix d'achat en mode Entrée (PMP sinon coût d'achat)
                var t=document.getElementById('movement_type').value;
                if(t==='entry'&&d&&d.ok){
                    var px=document.getElementById('prix_achat');
                    if(px&&px.value.trim()===''){
                        var sug=(d.pmp>0)?d.pmp:(d.cost_price>0?d.cost_price:0);
                        if(sug>0){ px.value=(Math.round(sug*100)/100).toString().replace('.',','); px.classList.add('filled'); }
                    }
                }
            })
            .catch(function(){ PINFO=null; renderStock(); });
    }

    var HEAD={
        transfer:['Transfert rapide de stock','Déplacez des marchandises entre entrepôts'],
        entry:['Entrée de stock','Réception de marchandises en stock'],
        exit:['Sortie de stock','Déclassement ou consommation de stock']
    };
    function setType(t){
        if(t!=='transfer'&&t!=='entry'&&t!=='exit'){ t='transfer'; }
        document.getElementById('movement_type').value=t;
        document.querySelectorAll('.trs-seg-b').forEach(function(b){
            var on=b.dataset.type===t;
            b.classList.toggle('on',on);
            b.setAttribute('aria-selected',on?'true':'false');
            b.tabIndex=on?0:-1;
        });
        var isT=(t==='transfer'),isEn=(t==='entry');
        show(document.getElementById('wrap_src'),t==='transfer'||t==='exit');
        show(document.getElementById('wrap_dst'),t==='transfer'||t==='entry');
        show(document.getElementById('wrap_arrow'),isT);
        document.getElementById('wh_block').classList.toggle('single',!isT);
        show(document.getElementById('wrap_label'),!isT);
        show(document.getElementById('wrap_price'),isEn);
        var h=HEAD[t];
        document.getElementById('trs_title').textContent=h[0];
        document.getElementById('trs_subtitle').textContent=h[1];
        clearErrors();
        refreshProductInfo();
    }
    var SEGS=Array.prototype.slice.call(document.querySelectorAll('.trs-seg-b'));
    SEGS.forEach(function(b,idx){
        b.addEventListener('click',function(){ setType(b.dataset.type); });
        // Navigation clavier entre onglets (flèches ← →, Home, Fin) — pattern ARIA tablist
        b.addEventListener('keydown',function(e){
            var ni=-1;
            if(e.key==='ArrowRight'||e.key==='ArrowDown'){ ni=(idx+1)%SEGS.length; }
            else if(e.key==='ArrowLeft'||e.key==='ArrowUp'){ ni=(idx-1+SEGS.length)%SEGS.length; }
            else if(e.key==='Home'){ ni=0; }
            else if(e.key==='End'){ ni=SEGS.length-1; }
            if(ni>=0){ e.preventDefault(); setType(SEGS[ni].dataset.type); SEGS[ni].focus(); }
        });
    });
    setType(document.getElementById('movement_type').value);

    // ── Floating label pour les champs texte simples ────────────────
    function floatLabel(inp){
        if(!inp){ return; }
        function upd(){ inp.classList.toggle('filled',inp.value.trim()!==''); }
        inp.addEventListener('input',upd); upd();
    }
    floatLabel(document.getElementById('label_mouvement'));
    floatLabel(document.getElementById('prix_achat'));

    // Après un enregistrement réussi : focus sur le produit pour enchaîner rapidement
    if(document.querySelector('.trs-ok')){ var tpf=document.getElementById('txt_product'); if(tpf){ tpf.focus(); } }

    var qi=document.getElementById('qty');
    document.getElementById('qm').onclick=function(){var v=parseFloat(qi.value)||1;if(v>1)qi.value=parseFloat((v-1).toFixed(3));};
    document.getElementById('qp').onclick=function(){var v=parseFloat(qi.value)||0;qi.value=parseFloat((v+1).toFixed(3));};

    // ── Bouton « Inverser source ↔ destination » (mode Transfert) ────
    var swapBtn=document.getElementById('btn_swap');
    if(swapBtn){
        swapBtn.addEventListener('click',function(){
            var si=acSrc.input, di=acDst.input;
            var sh=document.getElementById('fk_entrepot_source'), dh=document.getElementById('fk_entrepot_destination');
            var tv=si.value; si.value=di.value; di.value=tv;
            var th=sh.value; sh.value=dh.value; dh.value=th;
            [si,di].forEach(function(el){ el.classList.toggle('filled',el.value.trim()!==''); el.classList.remove('trs-invalid'); });
            renderStock();
        });
    }

    // ── Bouton « Max » : remplit la quantité avec le stock disponible ─
    var maxBtn=document.getElementById('btn_max');
    if(maxBtn){
        maxBtn.addEventListener('click',function(){
            var m=parseFloat(maxBtn.dataset.max)||0;
            if(m>0){ qi.value=parseFloat(m.toFixed(3)); qi.focus(); }
        });
    }

    // ── Validation non bloquante : surlignage + bandeau d'erreur ─────
    var clientErr=document.getElementById('client_err'), clientErrTxt=document.getElementById('client_err_txt');
    function clearErrors(){
        if(clientErr){ clientErr.classList.add('trs-hidden'); }
        document.querySelectorAll('.trs-invalid').forEach(function(el){ el.classList.remove('trs-invalid'); });
    }
    function fail(msg,el,box){
        if(clientErrTxt){ clientErrTxt.textContent=msg; }
        if(clientErr){ clientErr.classList.remove('trs-hidden'); }
        var target=box||el;
        if(target){ target.classList.add('trs-invalid'); }
        if(el){ el.focus(); }
        if(clientErr){ clientErr.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    }

    var formEl=document.getElementById('formtransfer');
    function validateAndSubmit(){
        clearErrors();
        var t=document.getElementById('movement_type').value;
        var pid=document.getElementById('fk_product').value;
        var sid=document.getElementById('fk_entrepot_source').value;
        var did=document.getElementById('fk_entrepot_destination').value;
        var lbl=document.getElementById('label_mouvement').value.trim();
        var pxRaw=document.getElementById('prix_achat').value.trim();
        var px=parseFloat(pxRaw.replace(',','.'));
        var q=parseFloat(qi.value);
        var step=document.querySelector('.trs-step');
        if(!pid){ fail('Sélectionnez un produit.',acProd.input); return false; }
        if(t==='transfer'){
            if(!sid){ fail('Sélectionnez un entrepôt source.',acSrc.input); return false; }
            if(!did){ fail('Sélectionnez un entrepôt destination.',acDst.input); return false; }
            if(sid===did){ fail('La source et la destination doivent être différentes.',acDst.input); return false; }
        }else if(t==='entry'){
            if(!did){ fail('Sélectionnez un entrepôt destination.',acDst.input); return false; }
            if(!lbl){ fail('Saisissez le libellé / description.',document.getElementById('label_mouvement')); return false; }
            if(pxRaw===''||isNaN(px)||px<0){ fail("Saisissez un prix d'achat valide.",document.getElementById('prix_achat')); return false; }
        }else if(t==='exit'){
            if(!sid){ fail('Sélectionnez un entrepôt source.',acSrc.input); return false; }
            if(!lbl){ fail('Saisissez le libellé / description.',document.getElementById('label_mouvement')); return false; }
        }
        if(!q||q<=0){ fail('La quantité doit être supérieure à 0.',qi,step); return false; }
        document.getElementById('btn-s').classList.add('submitting');
        return true;
    }
    formEl.addEventListener('submit',function(e){ if(!validateAndSubmit()){ e.preventDefault(); } });

    // ── Raccourci clavier : Ctrl/⌘ + Entrée pour enregistrer ─────────
    formEl.addEventListener('keydown',function(e){
        if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){
            e.preventDefault();
            if(validateAndSubmit()){ formEl.submit(); }
        }
    });
})();
</script>
<?php
llxFooter();
$db->close();