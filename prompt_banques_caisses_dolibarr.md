# Prompt Expert — Personnalisation Module Banques & Caisses — Dolibarr 20.0.4

## Contexte technique

- **ERP** : Dolibarr 20.0.4
- **Préfixe de tables** : `qL_` (sensible à la casse, Linux)
- **Module ciblé** : Banques & Caisses (`/compta/bank/`)
- **Fichiers natifs concernés** :
  - `/compta/bank/line.php` — fiche écriture bancaire
  - `/compta/bank/transfer.php` — virement interne
  - `/compta/bank/account.php` — fiche compte bancaire
  - `/compta/bank/bankentries_list.php` — liste des écritures
  - `/compta/bank/class/account.class.php` — classe métier Account & AccountLine
- **Principe** : ne jamais modifier le core Dolibarr — utiliser uniquement des **hooks**, des **triggers**, et des **surcharges** via le module custom existant ou un nouveau module dédié.

---

## Spécification 1 — Système de log / historique des modifications

### Objectif

Historiser toutes les **créations**, **modifications** et **suppressions** portant sur :
- les **comptes bancaires** (`qL_bank_account`)
- les **écritures bancaires** (`qL_bank`)

### Table de log à créer

```sql
CREATE TABLE qL_bank_audit_log (
    rowid         INT AUTO_INCREMENT PRIMARY KEY,
    tms           DATETIME     NOT NULL DEFAULT NOW(),
    fk_user       INT          NOT NULL,
    object_type   VARCHAR(50)  NOT NULL,   -- 'bank_account' | 'bank_line'
    object_id     INT          NOT NULL,   -- rowid de l'objet concerné
    action        VARCHAR(20)  NOT NULL,   -- 'CREATE' | 'UPDATE' | 'DELETE'
    field_name    VARCHAR(100) DEFAULT NULL,  -- champ modifié (NULL si CREATE/DELETE)
    old_value     TEXT         DEFAULT NULL,
    new_value     TEXT         DEFAULT NULL,
    ip            VARCHAR(45)  DEFAULT NULL,
    context       TEXT         DEFAULT NULL   -- JSON : infos complémentaires
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

### Mécanisme d'implémentation

1. Créer un **trigger Dolibarr** dans `/custom/bankaudit/core/triggers/interface_99_modBankAudit_BankAudit.class.php`
2. Écouter les événements natifs suivants :

| Événement Dolibarr | Action loggée |
|---|---|
| `BANKACCOUNT_CREATE` | CREATE sur compte |
| `BANKACCOUNT_MODIFY` | UPDATE sur compte |
| `BANKACCOUNT_DELETE` | DELETE sur compte |
| `BANKLINE_CREATE` | CREATE sur écriture |
| `BANKLINE_MODIFY` | UPDATE sur écriture |
| `BANKLINE_DELETE` | DELETE sur écriture |

3. Pour les **UPDATE**, comparer les valeurs avant/après champ par champ et insérer une ligne de log par champ modifié.
4. Logger systématiquement : `fk_user`, `tms` (NOW()), `ip` (`$_SERVER['REMOTE_ADDR']`).

### Affichage dans l'onglet "Suivi"

- Surcharger l'affichage de `line.php` via hook `formObjectOptions` ou injection directe dans le template
- Sur l'onglet **Suivi** (`?action=view&tab=tracking`) d'une écriture, afficher un tableau chronologique :

```
Date/heure       | Utilisateur    | Action  | Champ modifié | Ancienne valeur | Nouvelle valeur
22/06/2026 09:14 | BORIS SuperAdm | UPDATE  | montant       | 5 000,00        | 7 000,00
22/06/2026 09:12 | BORIS SuperAdm | CREATE  | —             | —               | —
```

---

## Spécification 2 — Synchronisation des écritures liées (virements inter-comptes)

### Contexte

Lors d'un **virement interne** entre deux comptes, Dolibarr crée deux écritures bancaires liées via la table `qL_bank_url` (ou `qL_bank_extrafields` selon la version) et par le champ `fk_account_transfer` ou le lien `bank_url` de type `transfert`. Les deux écritures doivent rester **toujours cohérentes**.

### Champs à synchroniser

Lorsqu'une écriture bancaire est **modifiée**, les champs suivants doivent être **répercutés automatiquement** sur l'écriture liée :

| Champ table `qL_bank` | Libellé UI | Règle de synchronisation |
|---|---|---|
| `fk_type` | Type (Espèce, Virement…) | Identique |
| `emetteur` | Émetteur | Identique |
| `banque` | Banque du chèque | Identique |
| `dateo` | Date opération | Identique |
| `datev` | Date valeur | Identique |
| `label` | Libellé | Identique |
| `amount` | Montant | **Voir règle devise ci-dessous** |
| Catégorie/Tag | `qL_category_bankline` | Identique |

### Règle de synchronisation du montant selon la devise

```
SI devise(compte_source) == devise(compte_destination)
    montant_écriture_liée = -montant_écriture_modifiée
    (signe opposé car débit/crédit)
SINON
    taux = taux_de_conversion(devise_source → devise_destination)
              lu depuis qL_multicurrency_rate
    montant_écriture_liée = -montant_écriture_modifiée × taux
    (arrondi à 2 décimales)
```

### Implémentation technique

1. **Trigger** `BANKLINE_MODIFY` :
   - Identifier l'écriture liée via :
     ```sql
     SELECT fk_bank_target FROM qL_bank_url
     WHERE fk_bank = :id AND type = 'transfert'
     -- ou selon la version :
     SELECT url_id FROM qL_bank_url
     WHERE fk_bank = :id AND type = 'company' AND label = 'transfert'
     ```
     > ⚠️ Vérifier la structure exacte de `qL_bank_url` sur l'instance cible avec `DESCRIBE qL_bank_url` avant d'implémenter.
   - Mettre à jour l'écriture liée avec les mêmes valeurs (hors montant = appliquer la règle devise)
   - Logger la modification dans `qL_bank_audit_log` avec `context = {"source": "auto_sync", "trigger_line_id": X}`

2. **Trigger** `BANKLINE_DELETE` :
   - Retrouver l'écriture liée (même méthode)
   - La supprimer via `AccountLine::delete()` pour déclencher les triggers natifs
   - Logger la suppression des deux écritures

3. **Garde-fou** : utiliser un flag statique `$_sync_in_progress` pour éviter la **récursion infinie** (la mise à jour de l'écriture liée ne doit pas re-déclencher une synchronisation).

```php
class BankAuditTriggers extends DolibarrTriggers {
    private static $syncInProgress = false;

    public function runTrigger($action, $object, $user, $langs, $conf) {
        if ($action === 'BANKLINE_MODIFY') {
            if (self::$syncInProgress) return 0; // anti-récursion
            self::$syncInProgress = true;
            $this->syncLinkedLine($object, $user);
            self::$syncInProgress = false;
        }
        // ... log
    }
}
```

---

## Spécification 3 — Conversion automatique du montant destination (virement interne)

### Contexte

Sur la page **Virement interne** (`/compta/bank/transfer.php`), quand les deux comptes sont dans des devises différentes, le champ **"Montant destination (en devise du compte de réception)"** doit se remplir automatiquement dès que l'utilisateur saisit le montant source.

### Source des taux de conversion

Les taux sont stockés dans Dolibarr dans :
```sql
-- Table principale des devises
SELECT rate FROM qL_multicurrency_rate
WHERE code_iso = :code_devise_destination
  AND code_from = :code_devise_source    -- ou la devise de référence du système
ORDER BY date_sync DESC
LIMIT 1
```
> Si la table utilise une devise pivot (ex: USD), le calcul peut nécessiter deux étapes :
> `montant_destination = montant_source / taux(source→pivot) × taux(destination→pivot)`

### Endpoint PHP à créer

Créer `/custom/bankaudit/ajax/get_conversion_rate.php` :

```php
<?php
// Retourne le taux de conversion entre deux devises
// GET params: from=USD&to=CDF
require '../../main.inc.php';
if (!$user->id) { http_response_code(403); exit; }

$from = GETPOST('from', 'aZ09');
$to   = GETPOST('to',   'aZ09');

// Cas trivial : même devise
if ($from === $to) {
    echo json_encode(['rate' => 1, 'from' => $from, 'to' => $to]);
    exit;
}

// Lecture du taux dans qL_multicurrency_rate
$sql = "SELECT rate FROM ".MAIN_DB_PREFIX."multicurrency_rate
        WHERE code_iso = '".$db->escape($to)."'
        ORDER BY date_sync DESC LIMIT 1";
$r = $db->query($sql);
if ($r && $obj = $db->fetch_object($r)) {
    echo json_encode(['rate' => (float)$obj->rate, 'from' => $from, 'to' => $to]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Taux non trouvé']);
}
exit;
```

### Injection JavaScript dans transfer.php

Via un **hook** `formObjectOptions` ou une **surcharge de template**, injecter dans la page `transfer.php` le script suivant :

```javascript
(function () {
    // Détecter les selects de comptes et le champ montant
    // Les sélecteurs exacts dépendent du markup natif de transfer.php :
    // à inspecter avec DevTools sur l'instance réelle avant d'implémenter
    var selFrom   = document.querySelector('select[name="account_from"]');   // à adapter
    var selTo     = document.querySelector('select[name="account_to"]');     // à adapter
    var inpAmount = document.querySelector('input[name="amount"]');          // montant source
    var inpDest   = document.querySelector('input[name="amount_to"]');       // montant destination

    if (!selFrom || !selTo || !inpAmount) return;

    // Mapping id_compte → code_devise (injecté depuis PHP)
    var accountCurrencies = <?php echo json_encode($account_currencies_map); ?>;
    // $account_currencies_map = ['id' => 'USD', ...] construit côté PHP

    var lastRate = null;

    function fetchRate(from, to, callback) {
        if (from === to) { callback(1); return; }
        fetch('/custom/bankaudit/ajax/get_conversion_rate.php?from='
              + encodeURIComponent(from) + '&to=' + encodeURIComponent(to))
            .then(function(r){ return r.json(); })
            .then(function(d){ callback(d.rate || null); })
            .catch(function(){ callback(null); });
    }

    function updateDestAmount() {
        var fromId = selFrom.value;
        var toId   = selTo.value;
        var amt    = parseFloat(inpAmount.value);
        if (!fromId || !toId || isNaN(amt)) return;

        var fromCur = accountCurrencies[fromId];
        var toCur   = accountCurrencies[toId];
        if (!fromCur || !toCur) return;

        if (fromCur === toCur) {
            if (inpDest) inpDest.value = amt.toFixed(2);
            return;
        }

        fetchRate(fromCur, toCur, function(rate) {
            if (rate === null) return;
            lastRate = rate;
            if (inpDest) inpDest.value = (amt * rate).toFixed(2);
        });
    }

    ['change', 'input'].forEach(function(ev) {
        if (selFrom)   selFrom.addEventListener(ev,   updateDestAmount);
        if (selTo)     selTo.addEventListener(ev,     updateDestAmount);
        if (inpAmount) inpAmount.addEventListener(ev, updateDestAmount);
    });
})();
```

> ⚠️ **Les noms des champs HTML** (`account_from`, `account_to`, `amount`, `amount_to`) et la **structure du formulaire** de `transfer.php` doivent être **inspectés sur l'instance réelle** avec les DevTools du navigateur avant de câbler les sélecteurs JavaScript. Ces noms peuvent différer de la version standard.

---

## Plan d'implémentation recommandé

### Étape 1 — Audit préalable (1 jour)

- [ ] `DESCRIBE qL_bank` — identifier tous les champs de l'écriture
- [ ] `DESCRIBE qL_bank_url` — comprendre la structure de liaison inter-écritures
- [ ] `DESCRIBE qL_multicurrency_rate` — valider la structure des taux
- [ ] Inspecter `transfer.php` avec DevTools : noms des champs du formulaire
- [ ] Vérifier si les événements `BANKLINE_MODIFY` / `BANKLINE_DELETE` sont bien émis par le core (`account.class.php` → `call_trigger()`)

### Étape 2 — Création du module custom `bankaudit` (2 jours)

- [ ] Scaffold du module : `/custom/bankaudit/core/modules/modBankAudit.class.php`
- [ ] Script SQL d'installation : création de `qL_bank_audit_log`
- [ ] Trigger : `/custom/bankaudit/core/triggers/interface_99_modBankAudit_BankAudit.class.php`
- [ ] Activer le module depuis **Configuration → Modules**

### Étape 3 — Log & synchronisation (2 jours)

- [ ] Implémenter le log CREATE/UPDATE/DELETE dans le trigger
- [ ] Implémenter la synchronisation des écritures liées avec anti-récursion
- [ ] Implémenter la suppression en cascade de l'écriture liée
- [ ] Tests : créer un virement interne, modifier, supprimer → vérifier `qL_bank_audit_log`

### Étape 4 — Affichage onglet Suivi (1 jour)

- [ ] Hook `formObjectOptions` sur `line.php` pour injecter l'historique
- [ ] Ou surcharge directe : copie de `line.php` dans `/custom/bankaudit/` avec `$hookmanager->executeHooks()`
- [ ] Tableau de log trié par `tms DESC` filtré sur `object_id = $object->id`

### Étape 5 — Conversion automatique (1 jour)

- [ ] Créer l'endpoint AJAX `/custom/bankaudit/ajax/get_conversion_rate.php`
- [ ] Injecter le script JS dans `transfer.php` via hook `formObjectOptions`
- [ ] Construire `$account_currencies_map` (requête sur `qL_bank_account`)
- [ ] Test : virement USD → CDF avec taux configuré → vérifier le calcul automatique

### Étape 6 — Recette & mise en production (1 jour)

- [ ] Tests de régression sur les virements intra-devise
- [ ] Tests de régression sur les virements inter-devises
- [ ] Vérification que les triggers natifs Dolibarr ne sont pas cassés
- [ ] Déploiement sur l'environnement de production

---

## Points d'attention critiques

| Risque | Mitigation |
|---|---|
| Récursion infinie sur la synchro | Flag statique `$syncInProgress` dans le trigger |
| Nom des champs HTML dans `transfer.php` | Audit DevTools obligatoire avant d'écrire le JS |
| Structure de `qL_bank_url` non documentée | `DESCRIBE qL_bank_url` + lecture du code source `account.class.php` |
| Trigger `BANKLINE_MODIFY` peut ne pas exister | Vérifier avec `grep -r "BANKLINE" /path/to/dolibarr/htdocs/compta/bank/` |
| Taux de conversion absent ou obsolète | Afficher un avertissement UI si le taux est introuvable ou > 7 jours |
| Droits utilisateur sur la table de log | Le module doit créer la table avec les bons grants |
| Performance sur gros volume de logs | Index sur `(object_type, object_id)` et `(tms)` dans la table de log |

---

## Références utiles

- Code source : `/htdocs/compta/bank/class/account.class.php` — méthodes `create()`, `update()`, `delete()`, `addline()`, `deleteline()`
- Documentation hooks Dolibarr : `https://wiki.dolibarr.org/index.php/Hooks_system`
- Documentation triggers Dolibarr : `https://wiki.dolibarr.org/index.php/Triggers`
- Table des devises : `/htdocs/multicurrency/class/multicurrency.class.php`
- Exemple de module custom : `/htdocs/modulebuilder/` ou `https://github.com/Dolibarr/dolibarr-module-template`

---

---

# Spécifications additionnelles — P1, P2, P4, P9, P12

---

## Spécification 4 — P1 : Tableau de bord de trésorerie en temps réel

### Objectif

Créer une page `/custom/bankaudit/treasury_dashboard.php` offrant une vision consolidée et instantanée de la trésorerie sur tous les comptes, avec conversion en devise de référence (USD).

### Tables SQL impliquées

```sql
-- Solde courant par compte (calculé à la volée)
SELECT
    ba.rowid,
    ba.ref,
    ba.label,
    ba.currency_code,
    ba.account_number,
    ba.type,                          -- 1=banque, 2=caisse, 3=livret
    COALESCE(SUM(b.amount), 0) AS solde_natif
FROM qL_bank_account ba
LEFT JOIN qL_bank b ON b.fk_account = ba.rowid
WHERE ba.entity = :entity_id
  AND ba.clos = 0                     -- comptes ouverts uniquement
GROUP BY ba.rowid;

-- Taux de conversion pour la consolidation USD
SELECT code_iso, rate, date_sync
FROM qL_multicurrency_rate
WHERE date_sync = (SELECT MAX(date_sync) FROM qL_multicurrency_rate mr2
                   WHERE mr2.code_iso = qL_multicurrency_rate.code_iso);

-- Évolution du solde sur N jours (pour sparklines)
SELECT
    DATE(b.dateo)       AS jour,
    SUM(b.amount)       AS mouvement_jour,
    SUM(SUM(b.amount)) OVER (ORDER BY DATE(b.dateo)) AS solde_cumul
FROM qL_bank b
WHERE b.fk_account = :account_id
  AND b.dateo >= DATE_SUB(NOW(), INTERVAL 90 DAY)
GROUP BY DATE(b.dateo)
ORDER BY jour;
```

### Structure de la page dashboard

```
┌─────────────────────────────────────────────────────────────────┐
│  TABLEAU DE BORD TRÉSORERIE          Actualisé le 22/06/2026   │
├──────────────┬──────────────┬──────────────┬───────────────────┤
│ TOTAL USD    │ TOTAL CDF    │ CAISSES      │ COMPTES BANCAIRES │
│ 42 500,00 $  │ 85 000 000 FC│ 3 comptes    │ 5 comptes         │
├──────────────┴──────────────┴──────────────┴───────────────────┤
│ Compte          │ Devise │ Solde natif  │ Équiv. USD │ Trend   │
│─────────────────│────────│──────────────│────────────│─────────│
│ CAISSE USD DEVI │ USD    │ 7 000,00     │ 7 000,00   │ ▲ +12%  │
│ TMB CDF         │ CDF    │ 3 500 000,00 │ 1 250,00   │ ▼ -3%   │
│ COMPTE TMB QLY  │ USD    │ 34 250,00    │ 34 250,00  │ ▲ +5%   │
│ ...             │        │              │            │         │
├─────────────────────────────────────────────────────────────────┤
│ ⚠️  ALERTES : CAISSE CDF < seuil (500 USD)                     │
└─────────────────────────────────────────────────────────────────┘
```

### Seuils d'alerte configurables

Créer une table de configuration des seuils :

```sql
CREATE TABLE qL_bank_dashboard_config (
    rowid        INT AUTO_INCREMENT PRIMARY KEY,
    fk_account   INT          NOT NULL,
    seuil_min    DECIMAL(24,8) NOT NULL DEFAULT 0,
    seuil_devise VARCHAR(3)   NOT NULL DEFAULT 'USD',
    fk_user_notif INT         NULL,     -- utilisateur à notifier
    entity       INT          NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

### Calcul de la tendance (sparkline)

- Comparer le solde actuel au solde d'il y a 7 jours
- Afficher `▲ +X%` en vert ou `▼ -X%` en rouge
- Sparkline SVG inline générée en PHP (7 points = 7 jours) sans dépendance JS externe

### Implémentation technique

- **Entrée de menu** : via `modBankAudit.class.php`, ajouter un menu sous `Banques | Caisses`
- **Rafraîchissement automatique** : meta-refresh toutes les 5 minutes ou bouton "Actualiser"
- **Responsive** : cartes CSS adaptées mobile (tablette terrain)
- **Droits** : `$user->rights->banque->lire` requis

---

## Spécification 5 — P2 : Contrôle de solde avant virement

### Objectif

Bloquer l'enregistrement d'un virement si le solde du compte source est insuffisant, avec affichage du solde disponible.

### Logique de contrôle

```php
// Dans le trigger BANKLINE_CREATE, avant l'insertion
function checkSoldeAvantVirement($fk_account_source, $montant, $seuil_min = 0) {
    global $db;

    $sql = "SELECT COALESCE(SUM(amount), 0) AS solde
            FROM ".MAIN_DB_PREFIX."bank
            WHERE fk_account = ".(int)$fk_account_source;
    $r   = $db->query($sql);
    $obj = $db->fetch_object($r);
    $solde_actuel = (float)$obj->solde;

    $solde_apres = $solde_actuel - abs($montant);

    if ($solde_apres < $seuil_min) {
        // Bloquer : retourner un message d'erreur explicite
        return array(
            'allowed'       => false,
            'solde_actuel'  => $solde_actuel,
            'montant'       => abs($montant),
            'solde_apres'   => $solde_apres,
            'seuil_min'     => $seuil_min,
            'message'       => 'Solde insuffisant. Disponible : '.price($solde_actuel)
                               .' — Après virement : '.price($solde_apres)
        );
    }
    return array('allowed' => true, 'solde_apres' => $solde_apres);
}
```

### Intégration dans le formulaire transfer.php (validation côté client)

En complément du contrôle serveur (trigger), ajouter une **vérification JavaScript temps réel** :

```javascript
// Seuils et soldes injectés depuis PHP
var accountSoldes = <?php echo json_encode($account_soldes_map); ?>;
// { "42": { "solde": 7000.00, "devise": "USD", "seuil_min": 0 }, ... }

function checkSolde() {
    var fromId  = selFrom.value;
    var montant = parseFloat(inpAmount.value) || 0;
    if (!fromId || !accountSoldes[fromId]) return;

    var info = accountSoldes[fromId];
    var apres = info.solde - montant;

    var zone = document.getElementById('solde-warning');
    if (!zone) return;

    if (apres < info.seuil_min) {
        zone.innerHTML = '⚠️ Solde insuffisant — Disponible : '
            + info.solde.toFixed(2) + ' ' + info.devise
            + ' — Après virement : ' + apres.toFixed(2) + ' ' + info.devise;
        zone.style.display = 'block';
        document.getElementById('btn-submit').disabled = true;
    } else {
        zone.style.display = 'none';
        document.getElementById('btn-submit').disabled = false;
    }
}
```

### Configuration des seuils par compte

Réutiliser la table `qL_bank_dashboard_config` (créée en P1) :
- Le champ `seuil_min` définit le solde minimum autorisé après opération
- Valeur par défaut : `0` (pas de solde négatif autorisé)
- Configurable compte par compte depuis une page d'administration du module

### Points d'attention

> ⚠️ Le contrôle côté serveur (trigger PHP) est **obligatoire** — le contrôle JS est uniquement une aide à la saisie et peut être contourné. Les deux niveaux sont nécessaires.

> ⚠️ Pour les virements **inter-devises**, le montant à comparer est le montant **dans la devise du compte source** (pas le montant destination converti).

---

## Spécification 6 — P4 : Historique des taux de change avec alertes

### Objectif

Tracer chaque modification de taux de change, visualiser l'évolution et alerter en cas d'écart suspect.

### Table à créer

```sql
CREATE TABLE qL_bank_rate_history (
    rowid        INT AUTO_INCREMENT PRIMARY KEY,
    tms          DATETIME      NOT NULL DEFAULT NOW(),
    fk_user      INT           NOT NULL,
    code_from    VARCHAR(3)    NOT NULL,   -- devise source (ex: USD)
    code_to      VARCHAR(3)    NOT NULL,   -- devise cible (ex: CDF)
    rate_old     DECIMAL(24,8)     NULL,   -- taux précédent
    rate_new     DECIMAL(24,8) NOT NULL,   -- nouveau taux
    variation_pct DECIMAL(8,4)    NULL,   -- écart en % vs taux précédent
    alerte       TINYINT(1)    NOT NULL DEFAULT 0,  -- 1 si écart > seuil
    ip           VARCHAR(45)       NULL,
    source       VARCHAR(50)   NOT NULL DEFAULT 'manual'  -- 'manual' | 'api'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE INDEX idx_rate_hist_codes ON qL_bank_rate_history (code_from, code_to, tms);
```

### Trigger Dolibarr à écouter

```
MULTICURRENCY_RATE_CREATE
MULTICURRENCY_RATE_MODIFY
```

> ⚠️ Vérifier l'existence de ces événements avec :
> `grep -r "call_trigger" /htdocs/multicurrency/class/multicurrency.class.php`
> Si absents, utiliser un **hook** sur la page de mise à jour des taux.

### Logique d'enregistrement et d'alerte

```php
function logRateChange($code_from, $code_to, $rate_new, $user) {
    global $db;

    // Récupérer le taux précédent
    $sql = "SELECT rate_new AS rate_old FROM ".MAIN_DB_PREFIX."bank_rate_history
            WHERE code_from = '".$db->escape($code_from)."'
              AND code_to   = '".$db->escape($code_to)."'
            ORDER BY tms DESC LIMIT 1";
    $r = $db->query($sql);
    $rate_old = $r && ($o = $db->fetch_object($r)) ? (float)$o->rate_old : null;

    // Calcul de la variation
    $variation_pct = null;
    $alerte        = 0;
    $seuil_alerte  = 10; // % — paramétrable via constante Dolibarr

    if ($rate_old !== null && $rate_old > 0) {
        $variation_pct = abs(($rate_new - $rate_old) / $rate_old * 100);
        if ($variation_pct > $seuil_alerte) {
            $alerte = 1;
            // Envoyer une notification interne Dolibarr
            // + email au responsable financier configuré
        }
    }

    // Insertion dans l'historique
    $db->query("INSERT INTO ".MAIN_DB_PREFIX."bank_rate_history
                (fk_user, code_from, code_to, rate_old, rate_new, variation_pct, alerte, ip)
                VALUES (".(int)$user->id.", '".$db->escape($code_from)."',
                '".$db->escape($code_to)."', ".($rate_old ?? 'NULL').",
                ".(float)$rate_new.", ".($variation_pct ?? 'NULL').",
                $alerte, '".$db->escape($_SERVER['REMOTE_ADDR'] ?? '')."')");
}
```

### Page de visualisation

Créer `/custom/bankaudit/rate_history.php` :

```
┌────────────────────────────────────────────────────────────────┐
│  HISTORIQUE DES TAUX DE CHANGE        Filtrer : [USD/CDF ▼]   │
├──────────────────────────────────────────────────────────────────┤
│  [Graphique évolution USD/CDF sur 90 jours — SVG inline]       │
├──────────────────────────────────────────────────────────────────┤
│ Date/heure        │ Paire  │ Ancien  │ Nouveau │ Variation │ ⚠️ │
│ 22/06/2026 09:00  │ USD/CDF│ 2 750   │ 2 800   │ +1,82%    │    │
│ 15/06/2026 14:30  │ USD/CDF│ 3 100   │ 2 750   │ -11,29%   │ ⚠️ │
└──────────────────────────────────────────────────────────────────┘
```

- Les lignes avec `alerte = 1` sont affichées en **rouge/orange**
- Export CSV de l'historique complet
- Paramétrage du seuil d'alerte depuis la même page (stocké dans `qL_const`)

---

## Spécification 7 — P9 : Reporting financier avancé

### Objectif

Générer des rapports exportables (PDF/Excel) sur les flux bancaires : mouvements par période, par compte, par catégorie, avec gestion des devises multiples.

### Tables impliquées

```sql
-- Rapport de flux par période et par compte
SELECT
    ba.ref                          AS compte,
    ba.currency_code                AS devise,
    DATE_FORMAT(b.dateo, '%Y-%m')   AS periode,
    SUM(CASE WHEN b.amount > 0 THEN b.amount ELSE 0 END) AS total_entrees,
    SUM(CASE WHEN b.amount < 0 THEN ABS(b.amount) ELSE 0 END) AS total_sorties,
    SUM(b.amount)                   AS solde_net
FROM qL_bank b
JOIN qL_bank_account ba ON ba.rowid = b.fk_account
WHERE ba.entity = :entity
  AND b.dateo BETWEEN :date_debut AND :date_fin
GROUP BY ba.rowid, DATE_FORMAT(b.dateo, '%Y-%m')
ORDER BY ba.ref, periode;

-- Rapport par catégorie
SELECT
    cat.label                       AS categorie,
    ba.currency_code                AS devise,
    COUNT(b.rowid)                  AS nb_operations,
    SUM(b.amount)                   AS montant_net,
    AVG(b.amount)                   AS montant_moyen
FROM qL_bank b
JOIN qL_bank_account ba ON ba.rowid = b.fk_account
JOIN qL_category_bankline cbl ON cbl.fk_bank = b.rowid
JOIN qL_category cat ON cat.rowid = cbl.fk_category
WHERE ba.entity = :entity
  AND b.dateo BETWEEN :date_debut AND :date_fin
GROUP BY cat.rowid, ba.currency_code
ORDER BY ABS(SUM(b.amount)) DESC;

-- Rapport multi-devises (gain/perte de change)
-- Compare le montant converti au taux historique vs taux actuel
SELECT
    b.rowid,
    b.dateo,
    ba.currency_code                            AS devise,
    b.amount                                    AS montant_natif,
    rh.rate_new                                 AS taux_historique,
    mc.rate                                     AS taux_actuel,
    b.amount * rh.rate_new                      AS valeur_usd_historique,
    b.amount * mc.rate                          AS valeur_usd_actuelle,
    b.amount * (mc.rate - rh.rate_new)          AS gain_perte_change
FROM qL_bank b
JOIN qL_bank_account ba ON ba.rowid = b.fk_account
JOIN qL_bank_rate_history rh
    ON rh.code_from = ba.currency_code
    AND rh.code_to = 'USD'
    AND rh.tms = (
        SELECT MAX(tms) FROM qL_bank_rate_history
        WHERE code_from = ba.currency_code
          AND code_to = 'USD'
          AND tms <= b.dateo
    )
JOIN qL_multicurrency_rate mc ON mc.code_iso = ba.currency_code
WHERE ba.entity = :entity
  AND ba.currency_code != 'USD'
  AND b.dateo BETWEEN :date_debut AND :date_fin;
```

### Rapports disponibles

| Rapport | Description | Format |
|---|---|---|
| Flux de trésorerie | Entrées/sorties par compte et par mois | PDF / Excel |
| Analyse par catégorie | Répartition des dépenses par tag | PDF / Excel |
| Gain/perte de change | Réévaluation des soldes en devise de référence | PDF / Excel |
| Journal bancaire | Toutes les écritures sur une période, triées par date | PDF / Excel |
| Récapitulatif consolidé | Tous comptes, toutes devises → équivalent USD | PDF |

### Implémentation

- Page `/custom/bankaudit/reports.php` avec formulaire de paramétrage (dates, compte, devise)
- Génération PDF via **TCPDF** (inclus dans Dolibarr) : `require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php'`
- Export Excel via **PhpSpreadsheet** ou **CSV simple** selon disponibilité
- Planification : possibilité d'envoyer le rapport mensuel par email automatiquement via le scheduler Dolibarr (`/core/class/CMailFile.class.php`)

---

## Spécification 8 — P12 : Détection d'anomalies et alertes intelligentes

### Objectif

Un moteur de règles s'exécutant après chaque création/modification d'écriture, détectant les situations anormales et notifiant les responsables.

### Table de configuration des règles

```sql
CREATE TABLE qL_bank_anomaly_rule (
    rowid        INT AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(50)   NOT NULL UNIQUE,  -- identifiant de la règle
    label        VARCHAR(200)  NOT NULL,
    active       TINYINT(1)    NOT NULL DEFAULT 1,
    seuil        DECIMAL(24,8)     NULL,          -- valeur de déclenchement
    seuil_unite  VARCHAR(20)       NULL,          -- 'USD', '%', 'jours'
    fk_account   INT               NULL,          -- NULL = tous les comptes
    action_email TINYINT(1)    NOT NULL DEFAULT 1,
    action_notif TINYINT(1)    NOT NULL DEFAULT 1,
    fk_user_notif INT              NULL,
    entity       INT           NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Table des anomalies détectées (historique)
CREATE TABLE qL_bank_anomaly_log (
    rowid        INT AUTO_INCREMENT PRIMARY KEY,
    tms          DATETIME      NOT NULL DEFAULT NOW(),
    fk_rule      INT           NOT NULL,
    fk_bank      INT               NULL,   -- écriture concernée
    fk_account   INT               NULL,   -- compte concerné
    detail       TEXT              NULL,   -- JSON : valeurs ayant déclenché
    statut       VARCHAR(20)   NOT NULL DEFAULT 'new',  -- 'new'|'ack'|'false_positive'
    fk_user_ack  INT               NULL,
    tms_ack      DATETIME          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE INDEX idx_anomaly_log_statut ON qL_bank_anomaly_log (statut, tms);
```

### Règles de détection implémentées

```php
class BankAnomalyEngine {

    // R01 — Doublon probable
    public function checkDoublon($line) {
        $sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."bank
                WHERE fk_account = ".(int)$line->fk_account."
                  AND ABS(amount) = ".abs($line->amount)."
                  AND dateo BETWEEN DATE_SUB('".$line->dateo."', INTERVAL 1 DAY)
                                AND DATE_ADD('".$line->dateo."', INTERVAL 1 DAY)
                  AND rowid != ".(int)$line->rowid;
        // → alerte si nb > 0
    }

    // R02 — Montant inhabituel (> 3× la moyenne des 30 derniers jours)
    public function checkMontantInhabituel($line) {
        $sql = "SELECT AVG(ABS(amount)) AS moyenne
                FROM ".MAIN_DB_PREFIX."bank
                WHERE fk_account = ".(int)$line->fk_account."
                  AND dateo >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  AND rowid != ".(int)$line->rowid;
        // → alerte si ABS(line->amount) > 3 × moyenne
    }

    // R03 — Écriture à date très ancienne (> 30 jours dans le passé)
    public function checkDateAncienne($line) {
        $jours = (time() - strtotime($line->dateo)) / 86400;
        // → alerte si $jours > seuil configuré (défaut 30)
    }

    // R04 — Taux de change anormal (écart > seuil vs moyenne 7 jours)
    // Déclenché lors d'un MULTICURRENCY_RATE_MODIFY
    public function checkTauxAnormal($rate_new, $code_from, $code_to) {
        $sql = "SELECT AVG(rate_new) AS moy FROM ".MAIN_DB_PREFIX."bank_rate_history
                WHERE code_from = '".$code_from."' AND code_to = '".$code_to."'
                  AND tms >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        // → alerte si écart > seuil% (géré par P4, réutilisé ici)
    }

    // R05 — Solde négatif
    public function checkSoldeNegatif($fk_account) {
        $sql = "SELECT COALESCE(SUM(amount), 0) AS solde
                FROM ".MAIN_DB_PREFIX."bank WHERE fk_account = ".(int)$fk_account;
        // → alerte si solde < seuil_min du compte (table qL_bank_dashboard_config)
    }

    // R06 — Compte inactif mouvementé
    public function checkCompteInactif($fk_account, $line) {
        $sql = "SELECT MAX(dateo) AS derniere_op FROM ".MAIN_DB_PREFIX."bank
                WHERE fk_account = ".(int)$fk_account."
                  AND rowid != ".(int)$line->rowid;
        // → alerte si derniere_op < DATE_SUB(NOW(), INTERVAL 90 DAY)
    }
}
```

### Centre de notifications

Créer une page `/custom/bankaudit/anomalies.php` :

```
┌─────────────────────────────────────────────────────────────────┐
│  ANOMALIES DÉTECTÉES                  🔴 3 nouvelles           │
├─────────────────────────────────────────────────────────────────┤
│ [Filtrer : Toutes | Nouvelles | Acquittées | Faux positifs]    │
├──────────┬────────────────────────────┬──────────┬─────────────┤
│ Date     │ Règle                      │ Compte   │ Détail      │
│ 22/06 09 │ 🔴 Doublon probable        │ TMB CDF  │ 1 000 CDF   │
│ 22/06 08 │ 🟠 Montant inhabituel      │ CAISSE   │ 85 000 USD  │
│ 21/06 14 │ 🟡 Taux change anormal     │ —        │ USD/CDF -11%│
├──────────┴────────────────────────────┴──────────┴─────────────┤
│ [Acquitter] [Marquer faux positif] [Voir l'écriture]           │
└─────────────────────────────────────────────────────────────────┘
```

- Icône dans la barre de navigation Dolibarr avec **badge rouge** si anomalies non lues
- Notification email groupée (1 email par heure maximum, pas 1 par anomalie)
- Possibilité d'**acquitter** ou de marquer en "faux positif" avec commentaire

---

## Modifications de base de données — Récapitulatif complet (Spéc. 1 à 8)

### Tables à créer (dans l'ordre)

```sql
-- 1. Log d'audit général (Spéc. 1 — Logs)
CREATE TABLE qL_bank_audit_log ( ... );          -- voir Spéc. 1

-- 2. Configuration dashboard et seuils (Spéc. 4 — P1 + Spéc. 5 — P2)
CREATE TABLE qL_bank_dashboard_config (
    rowid        INT AUTO_INCREMENT PRIMARY KEY,
    fk_account   INT           NOT NULL,
    seuil_min    DECIMAL(24,8) NOT NULL DEFAULT 0,
    seuil_devise VARCHAR(3)    NOT NULL DEFAULT 'USD',
    fk_user_notif INT              NULL,
    entity       INT           NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 3. Historique des taux de change (Spéc. 6 — P4)
CREATE TABLE qL_bank_rate_history ( ... );       -- voir Spéc. 6

-- 4. Règles de détection d'anomalies (Spéc. 8 — P12)
CREATE TABLE qL_bank_anomaly_rule ( ... );       -- voir Spéc. 8

-- 5. Log des anomalies détectées (Spéc. 8 — P12)
CREATE TABLE qL_bank_anomaly_log ( ... );        -- voir Spéc. 8
```

### Index à créer

```sql
-- qL_bank_audit_log
CREATE INDEX idx_bank_audit_object ON qL_bank_audit_log (object_type, object_id);
CREATE INDEX idx_bank_audit_tms    ON qL_bank_audit_log (tms);
CREATE INDEX idx_bank_audit_user   ON qL_bank_audit_log (fk_user);

-- qL_bank_rate_history
CREATE INDEX idx_rate_hist_codes   ON qL_bank_rate_history (code_from, code_to, tms);

-- qL_bank_anomaly_log
CREATE INDEX idx_anomaly_log_statut ON qL_bank_anomaly_log (statut, tms);
```

---

## Plan d'implémentation mis à jour — Spécifications 1 à 8

| Sprint | Semaine | Spécifications | Charge estimée |
|---|---|---|---|
| 1 | S1 | Audit BDD + Scaffold module `bankaudit` | 1 jour |
| 1 | S1–S2 | Spéc. 1 — Logs & onglet Suivi | 3 jours |
| 1 | S2 | Spéc. 2 — Synchro écritures liées | 2 jours |
| 1 | S2 | Spéc. 3 — Conversion taux auto | 1 jour |
| 2 | S3 | Spéc. 5 — P2 : Blocage solde insuffisant | 1 jour |
| 2 | S3 | Spéc. 6 — P4 : Historique taux de change | 1,5 jour |
| 2 | S3–S4 | Spéc. 4 — P1 : Dashboard trésorerie | 3 jours |
| 3 | S5–S6 | Spéc. 8 — P12 : Détection anomalies | 3 jours |
| 4 | S7–S8 | Spéc. 7 — P9 : Reporting avancé | 5 jours |
| — | S8 | Recette globale & déploiement production | 1,5 jour |
| **Total** | | | **~22 jours** |

---

## Points d'attention additionnels

| Risque | Mitigation |
|---|---|
| Performance dashboard sur gros volume | Matérialiser les soldes dans une table de cache mise à jour par trigger |
| Doublon détecté = faux positif fréquent | Seuil de délai ajustable (défaut ±1 jour) + bouton "faux positif" |
| Rapport PDF lent sur longue période | Pagination + génération asynchrone avec lien de téléchargement |
| `qL_bank_rate_history` absente au démarrage | Le trigger P4 initialise la ligne `rate_old = NULL` à la première saisie |
| Alertes trop nombreuses = ignorées | Groupement des notifications (max 1 email/heure) + filtrage par gravité |
| Blocage solde (P2) trop restrictif | Configurer `seuil_min = NULL` pour désactiver le blocage sur un compte donné |

---

# Journal d'implémentation — Module `bankaudit` (réalisé)

> Cette section documente **ce qui a réellement été développé** dans le module `/custom/bankaudit/`, au-delà de la spécification théorique ci-dessus. Elle reflète l'état du code après implémentation initiale **et** les itérations de correction issues des tests en conditions réelles.

## Arborescence du module

```
custom/bankaudit/
├── core/
│   ├── modules/modBankAudit.class.php          # Descripteur du module (onglets, menus, permissions, constantes)
│   └── triggers/interface_99_modBankAudit_BankAudit.class.php  # Trigger sur événements natifs
├── class/
│   ├── bankaudit.class.php                      # Helpers statiques (log, snapshot, restauration, soldes…)
│   └── actions_bankaudit.class.php              # Hooks sur les pages bancaires natives
├── sql/
│   ├── llx_bank_audit_log.sql
│   └── llx_bank_rate_history.sql
├── ajax/
│   └── get_account_info.php                     # Renvoie en JSON : devise, solde, seuil d'un compte
├── admin/
│   └── setup.php                                # Page de configuration du module
├── langs/
│   ├── fr_FR/bankaudit.lang
│   └── en_US/bankaudit.lang
├── audit_log.php                                # Journal d'audit global (tous objets)
├── bankline_history.php                         # Onglet « Suivi » d'une écriture
├── dashboard.php                                # Tableau de bord trésorerie (P1)
├── anomalies.php                                # Détection d'anomalies (P12)
├── rate_history.php                             # Historique des taux (P4)
└── reports.php                                  # Reporting financier (P9)
```

## Constantes de configuration

| Constante | Défaut | Rôle |
|---|---|---|
| `BANKAUDIT_ENABLE_BALANCE_GUARD` | `1` | Active/désactive le contrôle de solde avant virement (P2). Configurable via `admin/setup.php` (oui/non). |

## Hooks utilisés (`actions_bankaudit.class.php`)

| Hook | Contexte | Rôle |
|---|---|---|
| `completeTabsHead` | `bankcard` | **Ajoute l'onglet « Suivi »** sur la fiche écriture en lisant `rowid` depuis l'URL (voir correctif n°3). |
| `doActions` | bankline / banktransfer | Interception des actions de modification. |
| `formObjectOptions` | bankline | Injection du panneau « écriture liée » + JS d'assistance virement. |
| `printCommonFooter` | bankline / banktransfer | Injection du JS de confirmation et de recalcul. |

## Triggers écoutés (`interface_99_...`)

| Événement | Action |
|---|---|
| `BANKACCOUNT_CREATE` / `BANKACCOUNT_MODIFY` | Log CREATE / UPDATE compte |
| `BANKACCOUNTLINE_DELETE` | **Capture d'un snapshot complet JSON** de la ligne avant suppression en cascade (voir correctif n°4) |
| `CURRENCYRATE_CREATE` / `CURRENCYRATE_MODIFY` | Log de l'historique des taux (P4) |

---

## Correctifs issus des tests en conditions réelles

Les 6 ajustements suivants ont été apportés après une session de test avec captures d'écran.

### Correctif 1 — Panneau « écriture liée » sur la même ligne que l'édition

- **Problème** : le panneau d'aperçu de l'écriture liée se positionnait en haut à droite, en dehors de la carte de contenu blanche, cassant la mise en page.
- **Solution** (`getLinkedLinePanelJs`) : le JS enveloppe désormais **uniquement la table `table.tableforfield`** du formulaire `form[name="update"]` dans un conteneur flex (champs à gauche `flex: 2 1 460px`, panneau à droite `flex: 1 1 300px`). Le panneau reste ainsi **à l'intérieur de la carte**, aligné avec les champs en édition, sans casser le design responsive.

### Correctif 2 — Dialog de confirmation natif Dolibarr

- **Problème** : la confirmation de répercussion sur l'écriture liée utilisait un `window.confirm()` basique.
- **Solution** : utilisation du **dialog jQuery UI** de Dolibarr. À la soumission, `e.preventDefault()` puis ouverture d'un `jQuery('<div>').dialog({ modal: true, buttons: [Oui, Non] })`. Le bouton « Oui » repasse un drapeau `confirmed = true` et resoumet le formulaire ; « Non » ferme le dialog. Repli sur `window.confirm()` si jQuery UI indisponible. Titre : clé `LinkedLineUpdateTitle`.

### Correctif 3 — Onglet « Suivi » inaccessible (rowid vide)

- **Cause racine** : ce **n'était pas** un problème de permission. L'onglet était déclaré dans le descripteur via `complete_head_from_modules`, qui recevait un objet `NULL` depuis `bankline_prepare_head` → le jeton `__ID__` n'était jamais remplacé → l'URL contenait un `rowid` vide → `bankline_history.php` déclenchait `accessforbidden('ErrorRecordNotFound')` (page « Enregistrement non trouvé »).
- **Solution** :
  1. **Suppression** de la ligne d'onglet `+bankaudit_suivi` du descripteur `$this->tabs`.
  2. Ajout du hook **`completeTabsHead`** dans `actions_bankaudit.class.php` qui lit `GETPOSTINT('rowid')` (repli sur `id`) directement depuis la requête et construit l'URL correcte de l'onglet `bankaudit_suivi`.
- **Note** : les permissions du module (2 droits sous « Audit Banques & Caisses ») existaient déjà et fonctionnaient.

### Correctif 4 — Log de suppression avec restauration + filtre du journal

- **Snapshot complet** (`BankAudit::captureLineSnapshot`) : à la suppression d'une écriture, le trigger enregistre dans `old_value` un **JSON complet** de l'objet : ligne `llx_bank` entière, catégories (`bank_class`), URLs liées (`bank_url`) et `linked_line_id` (écriture de virement liée).
- **Restauration** (`BankAudit::restoreDeletedLine`) :
  - Réinsère la ligne depuis le snapshot (`insertLineFromSnapshot`, colonnes en liste blanche, `rappro = 0` forcé).
  - Si l'écriture supprimée avait une **écriture liée** elle-même supprimée, **les deux** sont restaurées et le lien `banktransfert` est recréé (remappage des `url_id`).
  - Restaure les catégories et les URLs des deux côtés.
  - Marque les logs d'origine comme restaurés (`context.restored = 1`, `restored_to`, `restored_at`) pour éviter les doubles restaurations.
  - Trace des actions `RESTORE` dans le journal.
  - Le tout encapsulé dans une **transaction**.
- **Interface** (`audit_log.php`) : nouvelle colonne « Action » avec un bouton **Restaurer** sur les entrées `DELETE` de type `bank_line` disposant d'un snapshot exploitable (droit `banque → modifier` requis). Affiche un badge « Restaurée » si déjà fait, ou « - » si données insuffisantes (suppression antérieure à la mise à jour du module).
- **Correctif du filtre** :
  - **Cause racine** : la valeur de l'option vide d'un `selectarray` est `"-1"` et non `""`. Laisser un filtre vide injectait `object_type = '-1'` / `action = '-1'`, ne correspondant à aucune ligne → résultats toujours vides.
  - **Solution** : normalisation `=== '-1'` → `''` à la lecture des filtres ; les dates ne sont prises en compte que si l'année est renseignée ; les filtres (y compris dates) sont propagés dans `$param` pour préserver la pagination.

### Correctif 5 — Montant destination du virement : recalcul + lecture seule

- **Problème** : le montant destination ne se recalculait pas lors du **changement de compte**, et restait éditable.
- **Cause racine** : les listes de comptes sont des `select2` ; un `addEventListener('change')` natif ne se déclenche pas sur le `change` jQuery émis par select2.
- **Solution** (`getTransferAssistantJs`) :
  - Liaison des listes via **`jQuery(sel).on('change', …)`** (repli `addEventListener`).
  - Le champ montant destination est passé en **lecture seule** (`readOnly`, fond grisé, curseur `not-allowed`, info-bulle clé `AutoComputedField`).
  - Recalcul automatique du montant converti à chaque changement de compte ou de montant source ; remise à zéro si aucun taux/devise disponible.

### Correctif 6 — Mise à jour de ce fichier `.md`

- Ajout de la présente section « Journal d'implémentation ».

---

# Spécification 9 — Lien compte/caisse ↔ entrepôt/magasin

## Objectif

Permettre de **lier optionnellement** un compte bancaire / une caisse à un **entrepôt/magasin** (`llx_entrepot`). Un entrepôt/magasin peut être associé à **plusieurs** comptes/caisses (relation *n comptes → 1 entrepôt*).

## Implémentation (sans hook, sans modification du core)

- Mécanisme : **extrafield** Dolibarr sur l'élément `bank_account` (stocké dans `llx_bank_account_extrafields`).
- Champ : `warehouse`, type **`sellist`**, paramètre `entrepot:ref:rowid::statut=1` (seuls les entrepôts ouverts sont proposés).
- Création **automatique à l'activation** du module via `modBankAudit::loadExtraFields()` (appelée dans `init()`), idempotente (ne recrée pas si le champ existe déjà pour l'entité).
- Intégration **native** : la fiche compte (`compta/bank/card.php`) gère déjà les extrafields (`showOptionals` + `setOptionalsFromPost`) → le sélecteur apparaît automatiquement sur les formulaires de **création**, d'**édition** et la **vue** du compte.
- Caractère **optionnel** : `required = 0`, valeur vide autorisée.

| Propriété extrafield | Valeur |
|---|---|
| `name` | `warehouse` |
| `elementtype` | `bank_account` |
| `type` | `sellist` |
| `param` | `entrepot:ref:rowid::statut=1` |
| `required` | 0 (optionnel) |
| `list` | 1 (liste + formulaires + vue) |
| `langfile` | `bankaudit@bankaudit` |

> Les valeurs saisies ne sont **pas supprimées** à la désactivation du module (aucune perte de données). Il faut **désactiver puis réactiver** le module pour déclencher la création de l'extrafield.

## Vue inverse — comptes/caisses rattachés sur la fiche entrepôt

Sur la **fiche d'un entrepôt/magasin** (`product/stock/card.php`), un panneau liste les comptes/caisses qui lui sont rattachés.

- **Sans modification du core** : hook **`printCommonFooter`** (contexte `warehousecard`, déclaré dans `module_parts['hooks']`) → méthode `ActionsBankaudit::getWarehouseAccountsPanel()`.
- La vue en lecture de la fiche entrepôt n'expose pas de hook d'injection dédié : le panneau (rendu en PHP) est inséré dans le DOM **via JavaScript**, juste au-dessus de la barre d'actions (`div.tabsAction`) — même approche que les autres panneaux du module.
- Requête : jointure `llx_bank_account` ↔ `llx_bank_account_extrafields` sur `ef.warehouse = <id entrepôt>`, filtrée par entité.
- Colonnes : Référence (lien vers la fiche compte), Libellé, Type (`BankType0/1/2`), Solde (`BankAudit::getAccountBalance`), Statut (ouvert/fermé).
- Visible uniquement pour les utilisateurs ayant le droit `banque → lire` ; affiche « Aucun compte… » lorsque l'entrepôt n'a aucun rattachement.

---

# Spécification 10 — Centre de rapports financiers (R01–R16)

## Objectif

Offrir un **centre de rapports unifié** couvrant la trésorerie, les flux, le change, les magasins, les catégories, l'audit et le contrôle interne, avec exports et fonctions à valeur ajoutée.

## Architecture

- Page unique `reports.php` pilotée par un **service central** `BankAuditReports` (`class/bankauditreports.class.php`).
- Routage : `?report=<code>&format=screen|csv|excel|pdf`. Filtres communs normalisés (`normalizeFilters`) : période, compte, magasin, utilisateur, devise, type de compte, granularité, montants, écart de taux, rapproché, action, etc.
- Permissions à granularité fine : `bankaudit→reports→read` (consultation), `bankaudit→reports→export` (export), `bankaudit→audit→read` (rapports d'audit R11/R12/R15). Repli sur `bankaudit→read`.

## Les 15 rapports

| Code | Rapport | Famille |
|---|---|---|
| R01 | Journal de caisse / bancaire (solde progressif) | Flux |
| R02 | Tableau de bord de trésorerie consolidé | Trésorerie |
| R03 | Flux net par période (granularité) | Trésorerie |
| R04 | Virements inter-comptes (écart de taux) | Flux |
| R05 | Gains/pertes de change | Change |
| R06 | Historique des taux de change | Change |
| R07 | Trésorerie par magasin/entrepôt | Magasin |
| R08 | Activité financière par magasin | Magasin |
| R09 | Flux par catégorie | Catégorie |
| R10 | Comparatif catégories N vs N-1 | Catégorie |
| R11 | Journal d'audit | Audit |
| R12 | Anomalies détectées | Audit |
| R13 | Rapprochement bancaire | Contrôle |
| R14 | Clôture de caisse journalière | Opérationnel |
| R15 | Activité de saisie par utilisateur | Audit |
| R16 | Tableau de trésorerie journalier par compte (matrice, avec taux du jour) | Trésorerie |
| REVIEW | Mode « Revue de direction » (R02 + R03 + R07 + R10) | Synthèse |

## Exports

- **CSV** UTF-8 (BOM Excel), multi-sections.
- **XLSX natif** via **PhpSpreadsheet** (`exportXlsx`) : en-têtes en gras + remplissage, totaux, auto-largeur, **un onglet par section** (mode Revue). **Repli automatique en CSV** si PhpSpreadsheet/`ZipArchive` indisponible.
- **PDF corporate** via la sous-classe `BankAuditTCPDF` (`class/bankaudittcpdf.class.php`) : en-tête (logo, société, RCCM/ID NAT, tél/email, titre, filtres) et pied (« Exporté par … | Le … | Page X/Y | CONFIDENTIEL » + ligne module) répétés sur chaque page.

## Fonctions à valeur ajoutée

- **Favoris de rapports** par utilisateur (`llx_bank_report_favorite`) : enregistrer une combinaison de filtres et la rappeler en un clic.
- **Comparaison avec la période précédente** (`compare_prev`).
- **Mode Revue de direction** (`REVIEW`) : document consolidé multi-rapports.
- **Annotation** libre intégrée à l'export PDF.
- **Historique des exports** (`llx_bank_report_export_log`) : qui a exporté quoi, quand, avec quel format et quelle IP (audit de confidentialité).
- **Tâches planifiées** : cronjobs `R02` (quotidien) et `R12` (hebdomadaire) via `BankAuditReports::sendScheduledReport()`.

## Affinages UX

- **Référence seule** des comptes/caisses affichée dans les tableaux des rapports (et l'en-tête PDF) pour gagner en place ; le libellé complet reste disponible dans les listes déroulantes de filtre.
- **Descriptions de rapport enrichies** : chaque rapport affiche en haut de page un bloc explicatif clair (clés `ReportTipR01`…`ReportTipR15`, `ReportTipReview`) introduit par « À quoi sert ce rapport ? » (`ReportWhatFor`).
- **Sélection multi-comptes** dans la zone de filtre (widget *multiselect* `fk_account[]`) : tous les rapports filtrent via `IN (...)` ; aucune sélection = tous les comptes.

## Tables créées

| Table | Rôle |
|---|---|
| `llx_bank_report_favorite` | Favoris de filtres par utilisateur |
| `llx_bank_report_export_log` | Journal des exports |

---

# Spécification 11 — Saisie rapide d'écritures + ticket de caisse 80 mm

## Objectif

Offrir un formulaire de **saisie rapide d'écritures** (basé sur le paiement divers natif) optimisé pour la caisse, avec impression d'un ticket 80 mm.

## Page de saisie (`entry_card.php`)

- Menu **« Nouvelle Écriture »** rattaché au **groupe « Banques | Caisses »** (enfant `fk_leftmenu=bank`, plus de bloc isolé en gras).
- Titre **« Nouvelle Écriture »** ; **Sens par défaut = Crédit**.
- **Compte/Caisse affiché par Référence seule** (liste des comptes ouverts, sans le libellé long).
- **Mode de règlement par défaut configurable** via la constante **`BANKAUDIT_DEFAULT_PAYMENT_MODE`** (défaut `LIQ` = Espèce), réglable dans la configuration du module.
- Champ **Bénéficiaire** après le Libellé : visible et **obligatoire uniquement pour le Sens Débit** (masqué et non enregistré pour le Crédit) — bascule en JavaScript selon le Sens.
- Champ **Tags/catégories des transactions** placé juste après le Sens.
- **Date d'écriture/opération = maintenant**, **non affichée** ; **Date valeur obligatoire**, par défaut = maintenant.
- Création via la classe native `PaymentVarious` (intégration comptable + ligne bancaire). Le bénéficiaire est stocké dans un **extrafield `beneficiaire`** sur l'élément `bank`.
- **Journalisation systématique** : chaque création est tracée dans `bank_audit_log` (`BankAudit::logChange`, action `CREATE`).
- **Saisie en rafale** : après validation, on **reste sur le formulaire** ; seul le **Montant est remis à 0**, les autres champs conservent leurs valeurs (modifiables). **Un montant de 0 est refusé.**
- **Redirection de l'ancien formulaire** : `compta/bank/various_payment/card.php?action=create` est redirigé vers ce formulaire (hook `doActions`, contexte `variouscard`).
- Boutons : **Enregistrer** et **Enregistrer et Imprimer l'écriture**.

## Ticket de caisse 80 mm (`entry_ticket.php`)

- Rendu **HTML** (impression rapide, sans génération PDF), largeur **80 mm**, police monospace.
- **En-tête** : raison sociale, adresse, RCCM/ID NAT, téléphone.
- **Corps** : date écriture, compte/caisse, libellé, bénéficiaire (si débit), mode de règlement, sens, **montant** mis en avant.
- **Signatures** : *Bénéficiaire* et *Caissier* sur la **même ligne**.
- **Pied** : date et heure d'impression + utilisateur.
- **Auto-impression** (`window.print()`), fermeture après impression.
- **Nombre de copies** configurable via la constante **`BANKAUDIT_TICKET_COPIES`** (page de configuration). Les copies sont répétées dans un seul travail d'impression.

## Garde-fou sur le changement de sens (édition d'écriture)

- Sur `compta/bank/line.php`, si l'utilisateur **inverse le signe du montant** (débit ⟷ crédit), un **dialog Dolibarr (jQuery UI)** avertit des répercussions (solde, rapprochements, rapports) et demande confirmation avant l'enregistrement.
- Injecté via le hook `printCommonFooter` (contexte `bankline`) **uniquement pour les lignes sans virement lié** (les lignes liées affichent déjà le dialog de répercussion sur l'écriture liée).

## Affichage du bénéficiaire et des tags

- **Bénéficiaire** : extrafield sur `bank` → colonne **disponible automatiquement** dans la *Liste des écritures* (`bankentries_list.php`, sélecteur de colonnes) et **ajouté au rapport R01** (Journal).
- **Tags/catégories** : filtre natif de la *Liste des écritures* + page native *Liste écritures/catégories* ; déjà présents dans les rapports R01 et R09.

## Constante ajoutée

| Constante | Défaut | Rôle |
|---|---|---|
| `BANKAUDIT_TICKET_COPIES` | `1` | Nombre de copies du ticket 80 mm imprimées |

---

## ⚠️ Procédure de déploiement obligatoire

> **Après toute modification du descripteur** (`modBankAudit.class.php` : onglets, menus, constantes) et des **hooks**, il faut **désactiver puis réactiver le module** dans *Accueil → Configuration → Modules*. Les onglets, menus et constantes ne sont relus qu'à l'activation, et l'enregistrement des `module_parts` (hooks) en dépend également.

## Récapitulatif des clés de langue ajoutées

`LinkedLineUpdateTitle`, `AutoComputedField`, `BankAuditActionRestore`, `BankAuditRestoreLine`, `BankAuditRestored`, `BankAuditRestoreDone` (`%s`), `BankAuditRestoreDoneLinked` (`%s`), `BankAuditRestoreInsufficientData`, `BankAuditRestoreNotFound`, `BankAuditRestoreAlreadyRestored`, `BankAuditRestoreInsertFailed`, `BankAuditLinkedWarehouse`, `BankAuditLinkedWarehouseHelp`, `BankAuditWarehouseAccountsTitle`, `BankAuditNoLinkedAccount`.

> Centre de rapports (Spéc. 10) : `ReportWhatFor`, `ReportTipR01`…`ReportTipR15`, `ReportTipReview`, `ReportExportedBy`, `ReportGeneratedOn`, `ReportConfidential`, `ReportInternalTransfers`, `ReportTreasuryByWarehouse`, `ReportActivityByWarehouse`, `ReportCategoryNvsN1`, `ReportReconciliation`, `ReportCashClosing`, `ReportUserActivity`, `ReportReviewMode`, `FavoriteLabel`, `SaveFavorite`, `CompareWithPreviousPeriod`, `Granularity`, `ExportHistory`, etc. (+ permissions `Permission4900003/4/5`).

> Saisie rapide (Spéc. 11) : `BankAuditNewEntry`, `BankAuditEntryDate`, `BankAuditBeneficiary`, `BankAuditBeneficiaryHelp`, `BankAuditTransactionTags`, `BankAuditAmountMustBePositive`, `BankAuditEntrySaved` (`%s`), `BankAuditSaveAndPrint`, `BankAuditSensChangeTitle`, `BankAuditSensChangeWarning`, `BankAuditTicketTitle`, `BankAuditCashier`, `BankAuditPrintedOn`, `BankAuditPrint`, `BankAuditTicketCopies`, `BankAuditTicketCopiesDesc`.

> Les libellés génériques (`Yes`, `No`, `Cancel`, `From`, `to`, `Field`, `User`, `Date`, `Action`, `Type`, `Ref`, `OldValue`, `NewValue`, `BankAccount`, `Label`, `Amount`…) réutilisent les clés natives de Dolibarr et ne sont pas redéfinis.
