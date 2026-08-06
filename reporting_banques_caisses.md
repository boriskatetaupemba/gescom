# Reporting Banques & Caisses — Quinley SARLU
## Dolibarr 20.0.4 — Module `bankaudit` — Lubumbashi, RDC

---

## Philosophie des rapports

Trois niveaux de lecture, trois audiences :

| Niveau | Audience | Fréquence | Format |
|---|---|---|---|
| **Opérationnel** | Comptables, caissiers | Quotidien / hebdo | Écran + PDF |
| **Gestion** | DAF, Responsables magasins | Hebdo / mensuel | PDF + Excel |
| **Stratégique** | Direction | Mensuel / trimestriel | PDF synthèse |

---

## Standards transversaux — applicables à TOUS les rapports

### Permissions d'accès

```php
// Droits à déclarer dans modBankAudit.class.php → $this->rights
$r = 0;
$this->rights[$r][0]  = ...; $this->rights[$r][1]  = 'Consulter les rapports financiers';
$this->rights[$r][3]  = 0;   $this->rights[$r][4]  = 'reports'; $this->rights[$r][5] = 'read';
$r++;
$this->rights[$r][0]  = ...; $this->rights[$r][1]  = 'Exporter les rapports (PDF / Excel / CSV)';
$this->rights[$r][3]  = 0;   $this->rights[$r][4]  = 'reports'; $this->rights[$r][5] = 'export';
$r++;
$this->rights[$r][0]  = ...; $this->rights[$r][1]  = 'Consulter le journal d\'audit';
$this->rights[$r][3]  = 1;   $this->rights[$r][4]  = 'audit';   $this->rights[$r][5] = 'read';
```

| Rapport | Droit minimum | Droit export |
|---|---|---|
| R01 Journal de caisse | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R02 Dashboard trésorerie | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R03 Flux net par période | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R04 Virements inter-comptes | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R05 Gains/pertes de change | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R06 Évolution des taux | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R07 Trésorerie par magasin | `bankaudit → reports → read` + `stock → lire` | `bankaudit → reports → export` |
| R08 Activité par magasin | `bankaudit → reports → read` + `stock → lire` | `bankaudit → reports → export` |
| R09 Flux par catégorie | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R10 Comparatif N vs N-1 | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R11 Journal d'audit | `bankaudit → audit → read` | `bankaudit → audit → read` |
| R12 Anomalies | `bankaudit → audit → read` | `bankaudit → audit → read` |
| R13 Rapprochement | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R14 Clôture de caisse | `bankaudit → reports → read` | `bankaudit → reports → export` |
| R15 Activité par utilisateur | `bankaudit → audit → read` | `bankaudit → audit → read` |

### En-tête PDF standard (toutes pages)

```
┌─────────────────────────────────────────────────────────────────────┐
│  [LOGO société]    QUINLEY SARLU                                    │
│                    Adresse : [adresse société Dolibarr]             │
│                    RCCM : [n° RCCM]  |  ID NAT : [id national]     │
│                    Tél : [téléphone] |  Email : [email]             │
├─────────────────────────────────────────────────────────────────────┤
│  TITRE DU RAPPORT                        Période : DD/MM/YYYY       │
│  Sous-titre / description courte          au      DD/MM/YYYY        │
│  Filtres appliqués : Compte=X | Devise=CDF | Magasin=DEGO          │
└─────────────────────────────────────────────────────────────────────┘
```

```php
// Données société depuis Dolibarr (à lire en PHP avant génération PDF)
$mysoc->name          // Raison sociale
$mysoc->address       // Adresse
$mysoc->logo          // Logo → DOL_DATA_ROOT.'/mycompany/logos/'.$mysoc->logo
$mysoc->phone         // Téléphone
$mysoc->email         // Email
$mysoc->idprof1       // RCCM
$mysoc->idprof2       // ID National
```

### Pied de page PDF standard (toutes pages)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Exporté par : BORIS SUPERADMIN          Page 1 / 5                │
│  Le : 23/06/2026 à 14:37                 CONFIDENTIEL              │
│  Module BankAudit — Dolibarr 20.0.4 — Quinley SARLU               │
└─────────────────────────────────────────────────────────────────────┘
```

```php
// Footer TCPDF — méthode à surcharger
public function Footer() {
    $this->SetY(-18);
    $this->SetFont('helvetica', 'I', 7);
    $this->Cell(0, 4,
        'Exporté par : '.$user->getFullName($langs)
        .' | Le : '.dol_print_date(dol_now(), 'dayhour')
        .' | Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages()
        .' | CONFIDENTIEL',
        0, 0, 'C'
    );
}
```

### Bloc de description affiché sur chaque page de rapport (UI)

Chaque rapport affiche en haut de page un bloc informatif :

```html
<div class="report-description">
  <span class="report-icon">📊</span>
  <div>
    <strong>Ce rapport vous permet de…</strong>
    <p>[description contextuelle du rapport — voir chaque fiche ci-dessous]</p>
    <span class="report-tip">💡 Astuce : [conseil d'utilisation spécifique]</span>
  </div>
</div>
```

### Formats d'export disponibles

| Format | Contenu | Usage recommandé |
|---|---|---|
| **PDF A4** | Mise en forme complète, logo, en-tête, pied de page numéroté | Archivage, signature, envoi direction |
| **Excel (.xlsx)** | Données brutes + mise en forme (couleurs, totaux, formats monétaires) | Retraitement, tableaux croisés |
| **CSV UTF-8** | Données brutes séparées par `;` avec BOM Excel | Import dans d'autres systèmes |

---

## CATÉGORIE 1 — Rapports de trésorerie et de flux

---

### R01 — Journal de caisse / Journal bancaire

**Audience** : Comptables, caissiers
**Fréquence** : Quotidien
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** consulter l'ensemble des mouvements d'un compte ou d'une caisse sur une période donnée, avec le solde progressif calculé après chaque opération. Il constitue le journal officiel des mouvements financiers, utilisable comme pièce comptable.
> 💡 **Astuce** : sélectionnez une seule journée pour générer la clôture de caisse du jour, ou un mois complet pour le contrôle mensuel.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut | Obligatoire |
|---|---|---|---|
| Compte / Caisse | `select` (liste des comptes actifs) | Premier compte disponible | ✅ |
| Date début | `date` | 1er du mois courant | ✅ |
| Date fin | `date` | Aujourd'hui | ✅ |
| Type d'opération | `select` (Espèce, Virement, Chèque, CB…) | Tous | ❌ |
| Catégorie / Tag | `select` (liste des catégories type=7) | Toutes | ❌ |
| Libellé | `text` (recherche partielle `LIKE`) | — | ❌ |
| Montant min | `number` | — | ❌ |
| Montant max | `number` | — | ❌ |
| Rapproché | `select` (Tous / Oui / Non) | Tous | ❌ |

#### Requête SQL principale

```sql
SELECT
    b.rowid                                              AS id,
    b.dateo                                              AS date_operation,
    b.datev                                              AS date_valeur,
    b.fk_type                                            AS type_operation,
    b.emetteur,
    b.label                                              AS libelle,
    CASE WHEN b.amount > 0 THEN b.amount  ELSE NULL END  AS entree,
    CASE WHEN b.amount < 0 THEN ABS(b.amount) ELSE NULL END AS sortie,
    SUM(b.amount) OVER (
        PARTITION BY b.fk_account
        ORDER BY b.dateo, b.rowid
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    )                                                    AS solde_progressif,
    GROUP_CONCAT(cat.label ORDER BY cat.label SEPARATOR ', ')
                                                         AS categories,
    ba.currency_code                                     AS devise,
    b.rappro                                             AS rapproche
FROM qL_bank b
JOIN qL_bank_account ba        ON ba.rowid = b.fk_account
LEFT JOIN qL_category_bankline cbl ON cbl.fk_bank = b.rowid
LEFT JOIN qL_category cat      ON cat.rowid = cbl.fk_category AND cat.type = 7
WHERE ba.entity    = :entity
  AND b.fk_account = :fk_account
  AND b.dateo BETWEEN :date_debut AND :date_fin
  -- Filtres optionnels
  AND (:fk_type  = ''  OR b.fk_type = :fk_type)
  AND (:label    = ''  OR b.label LIKE CONCAT('%', :label, '%'))
  AND (:cat_id   = 0   OR cbl.fk_category = :cat_id)
  AND (:montant_min = 0 OR ABS(b.amount) >= :montant_min)
  AND (:montant_max = 0 OR ABS(b.amount) <= :montant_max)
  AND (:rappro   = -1  OR b.rappro = :rappro)
GROUP BY b.rowid
ORDER BY b.dateo ASC, b.rowid ASC;
```

#### Colonnes du tableau

| ID | Date op. | Date valeur | Type | Émetteur | Libellé | Catégorie | Entrée | Sortie | Solde | R |
|---|---|---|---|---|---|---|---|---|---|---|
| 42 | 22/06/2026 | 22/06/2026 | Espèce | — | VERSEMENT | — | 7 000,00 | — | 42 500,00 | ✓ |
| 41 | 21/06/2026 | 22/06/2026 | Virement | DEGO | Transfert | — | — | 5 000,00 | 35 500,00 | — |

> `R` = colonne Rapproché (✓ / —)
> `ID` est cliquable → redirige vers la fiche écriture `line.php?rowid=X`

#### Ligne de totaux (pied de tableau + pied PDF)

```
Solde d'ouverture : XX XXX,XX CDF
Total entrées     : XX XXX,XX CDF     (N opérations)
Total sorties     : XX XXX,XX CDF     (N opérations)
Flux net          : XX XXX,XX CDF
Solde de clôture  : XX XXX,XX CDF
Taux rapprochement: XX,X %
```

#### Mise en forme PDF spécifique

- Alternance de couleurs lignes (blanc / gris très clair)
- Entrées en **vert foncé**, sorties en **rouge**, solde négatif en **rouge gras**
- Saut de page automatique avec répétition de l'en-tête de tableau
- Si > 1 page : sous-total "Report" en bas + "À reporter" en haut de page suivante
- Colonne ID tronquée à 6 caractères si l'espace manque

#### Valeur ajoutée suggérée ✨
- **Bouton "Envoyer par email"** : génère le PDF et l'envoie directement à l'adresse configurée sur le compte bancaire ou à un destinataire saisi à la volée
- **Mode impression rapide** : bouton `Ctrl+P` optimisé (CSS `@media print`) sans le menu latéral Dolibarr
- **Surlignage des montants > seuil** : les écritures dépassant un montant configurable sont automatiquement surlignées en jaune dans le PDF

---

### R02 — Tableau de bord de trésorerie consolidé

**Audience** : DAF, Direction
**Fréquence** : Temps réel
**Formats export** : PDF A4 (snapshot du tableau de bord)

#### Description (bloc UI)
> **Ce rapport vous permet de** visualiser en temps réel le solde de tous vos comptes et caisses, consolidé en USD au taux du jour. Il affiche les tendances sur 7 jours, les comptes sous seuil d'alerte et la répartition de votre trésorerie par type de compte.
> 💡 **Astuce** : filtrez par magasin pour voir la trésorerie d'un point de vente spécifique. La vue se rafraîchit automatiquement toutes les 5 minutes.

#### Filtres interactifs (sans rechargement de page — JavaScript)

| Filtre | Type | Comportement |
|---|---|---|
| Compte(s) | `multiselect` | Affiche/masque les lignes du tableau |
| Type de compte | `radio` (Tous / Banque / Caisse) | Filtre les cartes KPI et le tableau |
| Magasin / Entrepôt | `select` | Filtre sur `qL_bank_account_extrafields.warehouse` |
| Devise | `select` (Toutes / USD / CDF / …) | Filtre les colonnes et recalcule les totaux |
| Afficher comptes fermés | `toggle` (OFF par défaut) | Inclut/exclut `ba.clos = 1` |

#### Requête SQL principale

```sql
SELECT
    ba.rowid,
    ba.ref,
    ba.label,
    ba.currency_code                                        AS devise,
    ba.type,                                               -- 1=Banque, 2=Caisse
    ba.clos,
    COALESCE(SUM(b.amount), 0)                              AS solde_natif,
    COALESCE(SUM(b.amount), 0) * COALESCE(mr.rate, 1)      AS solde_usd,
    -- Solde J-7
    COALESCE((
        SELECT SUM(b2.amount) FROM qL_bank b2
        WHERE b2.fk_account = ba.rowid
          AND b2.dateo <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ), 0) * COALESCE(mr.rate, 1)                           AS solde_usd_j7,
    -- Dernier mouvement
    (SELECT MAX(b3.dateo) FROM qL_bank b3
     WHERE b3.fk_account = ba.rowid)                       AS derniere_operation,
    -- Seuil min configuré
    COALESCE(cfg.seuil_min, 0)                             AS seuil_min,
    ef.warehouse                                           AS fk_entrepot,
    e.ref                                                  AS entrepot_ref
FROM qL_bank_account ba
LEFT JOIN qL_bank b              ON b.fk_account = ba.rowid
LEFT JOIN qL_multicurrency_rate mr ON mr.code_iso = ba.currency_code
    AND mr.date_sync = (SELECT MAX(date_sync) FROM qL_multicurrency_rate
                        WHERE code_iso = ba.currency_code)
LEFT JOIN qL_bank_dashboard_config cfg ON cfg.fk_account = ba.rowid
LEFT JOIN qL_bank_account_extrafields ef ON ef.fk_object = ba.rowid
LEFT JOIN qL_entrepot e          ON e.rowid = ef.warehouse
WHERE ba.entity = :entity
  AND (:show_closed = 1 OR ba.clos = 0)
  AND (:fk_type = 0     OR ba.type = :fk_type)
  AND (:fk_entrepot = 0 OR ef.warehouse = :fk_entrepot)
  AND (:devise = ''     OR ba.currency_code = :devise)
GROUP BY ba.rowid, mr.rate, cfg.seuil_min, ef.warehouse, e.ref
ORDER BY ba.type, solde_usd DESC;
```

#### KPI cards (recalculées dynamiquement selon les filtres)

| KPI | Calcul | Couleur |
|---|---|---|
| Trésorerie totale USD | Σ(solde_natif × taux) comptes filtrés | Bleu |
| Trésorerie bancaire | Σ type=1 | Vert |
| Trésorerie caisses | Σ type=2 | Violet |
| Comptes sous seuil | COUNT(solde_usd < seuil_min) | Rouge si > 0 |
| Variation 7 jours | (solde_usd - solde_usd_j7) / solde_usd_j7 × 100 | Vert/Rouge |
| Nb comptes affichés | COUNT(ba.rowid) filtres actifs | Gris |

#### Valeur ajoutée suggérée ✨
- **Alertes push navigateur** : si un compte passe sous seuil pendant la navigation, notification navigateur sans rechargement
- **Comparaison J-1 / J-7 / J-30** : switch rapide pour changer la référence temporelle de la tendance
- **Export "Snapshot PDF"** : génère une capture PDF du tableau de bord à l'instant T, avec les KPI cards et le tableau — utile pour les reporting de direction

---

### R03 — Rapport de flux net par période

**Audience** : DAF, Comptables
**Fréquence** : Mensuel (ou selon granularité choisie)
**Formats export** : PDF A4

#### Description (bloc UI)
> **Ce rapport vous permet de** analyser les entrées, sorties et le flux net de chaque compte sur une période, avec la granularité de votre choix (semaine, mois, trimestre, année). Il met en évidence les périodes de tension de trésorerie et les tendances de flux.
> 💡 **Astuce** : choisissez la granularité "Semaine" pour identifier les jours de forte activité, ou "Mois" pour le reporting mensuel de direction.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | 1er janvier de l'année courante |
| Date fin | `date` | Aujourd'hui |
| Granularité | `radio` : Semaine / Mois / Trimestre / Année / Périodique | Mois |
| Compte(s) | `multiselect` (tous les comptes actifs) | Tous |
| Type de compte | `select` (Tous / Banque=1 / Caisse=2) | Tous |
| Devise | `select` | Toutes |
| Magasin | `select` | Tous |

> **Mode "Périodique"** : l'utilisateur saisit lui-même une date début et une date fin, le rapport agrège tout sur cette unique période sans découpage.

#### Requête SQL — granularité paramétrable

```sql
-- Expression de regroupement selon la granularité choisie (:granularite)
-- 'week'    → DATE_FORMAT(b.dateo, '%x-S%v')   ex: 2026-S26
-- 'month'   → DATE_FORMAT(b.dateo, '%Y-%m')     ex: 2026-06
-- 'quarter' → CONCAT(YEAR(b.dateo), '-T', QUARTER(b.dateo)) ex: 2026-T2
-- 'year'    → YEAR(b.dateo)                     ex: 2026
-- 'period'  → 'Période sélectionnée'            (une seule ligne)

SELECT
    ba.ref                                             AS compte,
    ba.label                                           AS compte_label,
    ba.type                                            AS type_compte,
    ba.currency_code                                   AS devise,
    -- Groupe calculé côté PHP avant injection SQL (pas d'injection dynamique)
    :groupe_expr                                       AS periode,
    SUM(CASE WHEN b.amount > 0 THEN b.amount  ELSE 0 END) AS total_entrees,
    SUM(CASE WHEN b.amount < 0 THEN ABS(b.amount) ELSE 0 END) AS total_sorties,
    SUM(b.amount)                                      AS flux_net,
    COUNT(b.rowid)                                     AS nb_operations,
    -- Flux net de la période précédente (window function)
    LAG(SUM(b.amount)) OVER (
        PARTITION BY b.fk_account
        ORDER BY :groupe_expr
    )                                                  AS flux_net_periode_prec,
    -- Variation %
    CASE
        WHEN LAG(SUM(b.amount)) OVER (PARTITION BY b.fk_account ORDER BY :groupe_expr) = 0
        THEN NULL
        ELSE ROUND(
            (SUM(b.amount) - LAG(SUM(b.amount)) OVER (PARTITION BY b.fk_account ORDER BY :groupe_expr))
            / ABS(LAG(SUM(b.amount)) OVER (PARTITION BY b.fk_account ORDER BY :groupe_expr)) * 100,
            1
        )
    END                                                AS variation_pct
FROM qL_bank b
JOIN qL_bank_account ba ON ba.rowid = b.fk_account
LEFT JOIN qL_bank_account_extrafields ef ON ef.fk_object = ba.rowid
WHERE ba.entity = :entity
  AND b.dateo BETWEEN :date_debut AND :date_fin
  AND (:type_compte  = 0 OR ba.type = :type_compte)
  AND (:fk_entrepot  = 0 OR ef.warehouse = :fk_entrepot)
  AND (:devise = ''       OR ba.currency_code = :devise)
  AND (:fk_account   = 0 OR b.fk_account = :fk_account)
GROUP BY b.fk_account, :groupe_expr
ORDER BY ba.ref, periode;
```

#### Présentation tableau

| Compte | Période | Entrées | Sorties | Flux net | Var. % | Nb op. |
|---|---|---|---|---|---|---|
| CAISSE USD DEGO | 2026-05 | 12 000,00 | 8 500,00 | +3 500,00 | — | 14 |
| CAISSE USD DEGO | 2026-06 | 9 200,00 | 11 000,00 | **-1 800,00** | -151% | 11 |

- Flux net **positif** → vert | **négatif** → rouge gras
- Ligne de total en bas : totaux globaux sur toute la période

#### Mise en forme PDF spécifique
- Tableau croisé si un seul compte sélectionné : colonnes = périodes, lignes = Entrées / Sorties / Flux net
- Graphique à barres groupées (Entrées vs Sorties) généré en SVG et intégré dans le PDF
- Sous-totaux par type de compte (Banques | Caisses) si "Tous" sélectionné

#### Valeur ajoutée suggérée ✨
- **Projection** : si granularité = Mois et période = année en cours, calculer la moyenne des mois passés et projeter les mois futurs avec une ligne pointillée
- **Seuil de flux** : afficher une ligne rouge horizontale sur le graphique si le flux net passe sous un seuil configurable

---

### R04 — Rapport des virements inter-comptes

**Audience** : DAF, Comptables
**Fréquence** : Hebdomadaire / à la demande
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** suivre tous les virements internes entre vos comptes et caisses. Pour chaque virement, il affiche le compte source, le compte destination, les montants dans les deux devises, le taux de change effectivement appliqué et l'écart éventuel avec le taux système. Indispensable pour contrôler la cohérence des opérations de change.
> 💡 **Astuce** : filtrez sur "Écart taux > 1%" pour identifier rapidement les virements où le taux appliqué s'écarte du taux système — signe possible d'une erreur de saisie.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date opération début | `date` | 1er du mois courant |
| Date opération fin | `date` | Aujourd'hui |
| Compte source | `select` (tous les comptes) | Tous |
| Compte destination | `select` (tous les comptes) | Tous |
| Type de compte source | `select` (Tous / Banque / Caisse) | Tous |
| Type de compte destination | `select` (Tous / Banque / Caisse) | Tous |
| Devise source | `select` | Toutes |
| Devise destination | `select` | Toutes |
| Virements inter-devises uniquement | `toggle` | OFF |
| Écart taux > | `number` (%) | — |
| Montant min (devise source) | `number` | — |

#### Requête SQL principale

```sql
SELECT
    b1.rowid                                           AS id_ecriture_source,
    b2.rowid                                           AS id_ecriture_destination,
    b1.dateo                                           AS date_operation,
    b1.datev                                           AS date_valeur,
    -- Source
    ba1.ref                                            AS compte_source_ref,
    ba1.type                                           AS type_compte_source,
    ba1.currency_code                                  AS devise_source,
    ABS(b1.amount)                                     AS montant_source,
    -- Destination
    ba2.ref                                            AS compte_dest_ref,
    ba2.type                                           AS type_compte_dest,
    ba2.currency_code                                  AS devise_dest,
    b2.amount                                          AS montant_destination,
    -- Taux effectif appliqué
    CASE
        WHEN ba1.currency_code = ba2.currency_code THEN 1
        ELSE ROUND(b2.amount / ABS(b1.amount), 6)
    END                                                AS taux_effectif,
    -- Taux système au moment du virement (depuis historique P4)
    (SELECT rate_new FROM qL_bank_rate_history
     WHERE code_from = ba2.currency_code
       AND code_to   = ba1.currency_code
       AND tms <= b1.dateo
     ORDER BY tms DESC LIMIT 1)                        AS taux_systeme,
    -- Écart entre taux effectif et taux système
    ABS(
        CASE WHEN ba1.currency_code = ba2.currency_code THEN 1
             ELSE ROUND(b2.amount / ABS(b1.amount), 6) END
        -
        (SELECT rate_new FROM qL_bank_rate_history
         WHERE code_from = ba2.currency_code
           AND code_to   = ba1.currency_code
           AND tms <= b1.dateo
         ORDER BY tms DESC LIMIT 1)
    ) / NULLIF(
        (SELECT rate_new FROM qL_bank_rate_history
         WHERE code_from = ba2.currency_code
           AND code_to   = ba1.currency_code
           AND tms <= b1.dateo
         ORDER BY tms DESC LIMIT 1), 0
    ) * 100                                            AS ecart_taux_pct,
    b1.label                                           AS libelle
FROM qL_bank b1
JOIN qL_bank_url bu   ON bu.fk_bank = b1.rowid AND bu.type = 'banktransfert'
JOIN qL_bank b2       ON b2.rowid = bu.url_id
JOIN qL_bank_account ba1 ON ba1.rowid = b1.fk_account
JOIN qL_bank_account ba2 ON ba2.rowid = b2.fk_account
WHERE ba1.entity = :entity
  AND b1.amount  < 0
  AND b1.dateo BETWEEN :date_debut AND :date_fin
  AND (:compte_src  = 0  OR b1.fk_account = :compte_src)
  AND (:compte_dst  = 0  OR b2.fk_account = :compte_dst)
  AND (:type_src    = 0  OR ba1.type = :type_src)
  AND (:type_dst    = 0  OR ba2.type = :type_dst)
  AND (:devise_src  = ''  OR ba1.currency_code = :devise_src)
  AND (:devise_dst  = ''  OR ba2.currency_code = :devise_dst)
  AND (:inter_dev   = 0  OR ba1.currency_code != ba2.currency_code)
  AND (:montant_min = 0  OR ABS(b1.amount) >= :montant_min)
  AND (:ecart_min   = 0  OR ABS(ROUND(b2.amount / ABS(b1.amount), 6) - ...) / ... * 100 >= :ecart_min)
ORDER BY b1.dateo DESC;
```

#### Colonnes du tableau

| ID src | ID dst | Date op. | Cte source | Cte dest | Devise src | Montant src | Devise dst | Montant dst | Taux effectif | Taux système | Écart % | Libellé |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 41 | 42 | 21/06/26 | CAISSE USD | TMB CDF | USD | 100,00 | CDF | 280 000 | 2 800,00 | 2 800,00 | 0,00% | VERSEMENT |

- Colonne **Écart %** : `< 0,5%` → vert | `0,5–1%` → orange | `> 1%` → rouge gras

#### Valeur ajoutée suggérée ✨
- **Alerte automatique** : si l'écart de taux dépasse 2% lors de la saisie, afficher un avertissement avant enregistrement (côté `transfer.php`)
- **Calcul de la commission de change** : si des frais bancaires sont associés au virement (tag spécifique), les afficher en colonne additionnelle

---

## CATÉGORIE 2 — Rapports de change et multi-devises

### R05 — Rapport des gains et pertes de change réalisés

**Audience** : DAF, Direction
**Fréquence** : Mensuel
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** mesurer l'impact des fluctuations de change sur votre trésorerie. Pour chaque opération en devise étrangère, il calcule la différence entre la valeur en USD au taux utilisé lors de l'opération et la valeur au taux actuel. Le total vous donne votre position nette de change sur la période.
> 💡 **Astuce** : un gain de change apparaît quand le CDF s'est déprécié après votre encaissement en CDF (votre USD vaut plus). Une perte apparaît dans le cas inverse.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | 1er du mois courant |
| Date fin | `date` | Aujourd'hui |
| Compte(s) | `multiselect` | Tous (hors USD) |
| Devise | `select` | CDF |
| Afficher gains uniquement | `toggle` | OFF |
| Afficher pertes uniquement | `toggle` | OFF |
| Montant min écart | `number` (USD) | — |

---

### R06 — Suivi de l'évolution des taux de change

**Audience** : DAF, Direction
**Fréquence** : Hebdomadaire
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** suivre l'historique de tous les taux de change saisis dans le système. Il identifie les variations anormales (signalées en rouge), indique qui a modifié quel taux et quand, et affiche les statistiques de volatilité sur la période.
> 💡 **Astuce** : vérifiez ce rapport après chaque mise à jour de taux pour valider que la variation est cohérente avec le marché réel.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | J-30 |
| Date fin | `date` | Aujourd'hui |
| Paire de devises | `select` (USD/CDF, USD/EUR…) | Toutes |
| Anomalies uniquement | `toggle` | OFF |
| Utilisateur | `select` | Tous |
| Source | `select` (Tous / Manuel / API) | Tous |

---

## CATÉGORIE 3 — Rapports par magasin / entrepôt

---

### R07 — Trésorerie par magasin / entrepôt

**Audience** : Responsables magasins, DAF
**Fréquence** : Quotidien / hebdomadaire
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** visualiser la trésorerie de chaque magasin ou entrepôt en agrégeant tous les comptes et caisses qui lui sont rattachés. Il vous donne une lecture financière par point de vente, indispensable pour piloter votre réseau de magasins.
> 💡 **Astuce** : comparez les soldes USD de vos différents magasins pour identifier ceux qui ont besoin d'un approvisionnement en liquidités.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Magasin / Entrepôt | `select` | Tous |
| Type de compte | `select` (Tous / Banque / Caisse) | Tous |
| Devise | `select` | Toutes |
| Afficher comptes sans magasin | `toggle` | OFF |

#### Requête SQL principale

```sql
SELECT
    e.ref                                                AS magasin_ref,
    ba.ref                                               AS compte_ref,
    ba.currency_code                                     AS devise,
    ba.type                                              AS type_compte,
    COALESCE(SUM(b.amount), 0)                           AS solde_natif,
    COALESCE(SUM(b.amount), 0) * COALESCE(mr.rate, 1)   AS solde_usd,
    (SELECT MAX(b2.dateo) FROM qL_bank b2
     WHERE b2.fk_account = ba.rowid)                     AS derniere_operation
FROM qL_entrepot e
JOIN qL_bank_account_extrafields ef  ON ef.warehouse = e.rowid
JOIN qL_bank_account ba              ON ba.rowid = ef.fk_object AND ba.clos = 0
LEFT JOIN qL_bank b                  ON b.fk_account = ba.rowid
LEFT JOIN qL_multicurrency_rate mr   ON mr.code_iso = ba.currency_code
    AND mr.date_sync = (SELECT MAX(date_sync) FROM qL_multicurrency_rate
                        WHERE code_iso = ba.currency_code)
WHERE e.entity = :entity
  AND (:fk_entrepot  = 0 OR e.rowid = :fk_entrepot)
  AND (:type_compte  = 0 OR ba.type = :type_compte)
  AND (:devise = ''       OR ba.currency_code = :devise)
GROUP BY e.rowid, ba.rowid, mr.rate
ORDER BY e.ref, ba.ref;
```

#### Colonnes du tableau (format compact)

| Magasin | Compte | Devise | Type | Solde natif | Équiv. USD | Dernière op. |
|---|---|---|---|---|---|---|
| DEGO | CSH-USD-DEGO | USD | Caisse | 7 000,00 | 7 000,00 | 22/06/2026 |
| DEGO | TMB-DEGO | CDF | Banque | 3 500 000,00 | 1 250,00 | 20/06/2026 |
| DEVI | CSH-CDF-DEVI | CDF | Caisse | 8 960 000,00 | 3 200,00 | 21/06/2026 |

> Réf. compte = `ba.ref` | Réf. magasin = `e.ref` — pas de libellé long pour garder le tableau compact

#### Lignes de sous-total et total

```
── Sous-total DEGO ─────────────────────── 8 250,00 USD
── Sous-total DEVI ─────────────────────── 3 200,00 USD
── Sous-total KENYA ────────────────────── 8 100,00 USD
══ TOTAL TOUS MAGASINS ═════════════════ 19 550,00 USD
```

- Sous-total par magasin (en USD) + total général toutes devises converties
- Total natif par devise : `Σ CDF = XX XXX XXX,00 | Σ USD = XX XXX,00`

#### Valeur ajoutée suggérée ✨
- **Carte géographique** : si les coordonnées GPS sont renseignées sur les entrepôts, afficher une carte avec les bulles de trésorerie proportionnelles au solde
- **Comparaison inter-magasins** : graphique à barres horizontales côte à côte pour comparer les soldes USD de chaque magasin en un coup d'œil

---

### R08 — Activité financière par magasin sur une période

**Audience** : Responsables magasins, DAF
**Fréquence** : Mensuel
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** comparer l'activité financière (entrées, sorties, flux net) de chaque magasin sur une période donnée. Il permet d'identifier les magasins les plus actifs et de détecter des anomalies de flux par rapport aux périodes précédentes.
> 💡 **Astuce** : utilisez ce rapport en fin de mois pour valider que les flux de chaque magasin correspondent aux ventes et achats enregistrés dans Dolibarr.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | 1er du mois courant |
| Date fin | `date` | Aujourd'hui |
| Magasin | `select` | Tous |
| Type de compte | `select` (Tous / Banque / Caisse) | Tous |
| Devise | `select` | Toutes |

---

## CATÉGORIE 4 — Rapports par catégorie / tag

### R09 — Analyse des flux par catégorie

**Audience** : DAF, Comptables
**Fréquence** : Mensuel
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** analyser la répartition de vos entrées et sorties par catégorie comptable (tags Dolibarr). Il identifie vos principales sources de revenus et postes de dépenses, et calcule la part de chaque catégorie dans le total.
> 💡 **Astuce** : créez des catégories précises (Loyer, Carburant, Salaires, Ventes comptoir…) pour que ce rapport devienne votre outil de pilotage des charges et produits.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | 1er du mois courant |
| Date fin | `date` | Aujourd'hui |
| Compte(s) | `multiselect` | Tous |
| Type de compte | `select` | Tous |
| Magasin | `select` | Tous |
| Devise | `select` | Toutes |
| Catégorie parente | `select` | Toutes |
| Afficher sans catégorie | `toggle` | ON |

---

### R10 — Comparatif catégories N vs N-1

**Audience** : DAF, Direction
**Fréquence** : Mensuel / annuel
**Formats export** : PDF A4 | Excel

#### Description (bloc UI)
> **Ce rapport vous permet de** comparer vos flux par catégorie entre deux périodes (ex : ce mois-ci vs même mois l'an dernier, ou 2026 vs 2025). Les écarts en valeur absolue et en pourcentage sont calculés automatiquement et mis en évidence selon leur importance.
> 💡 **Astuce** : utilisez ce rapport pour votre revue mensuelle de direction — il montre immédiatement quelles catégories ont évolué significativement.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Période N début | `date` | 1er du mois courant |
| Période N fin | `date` | Aujourd'hui |
| Période N-1 début | `date` | Même période -1 an |
| Période N-1 fin | `date` | Calculé automatiquement |
| Compte(s) | `multiselect` | Tous |
| Devise | `select` | Toutes |
| Seuil d'écart (%) pour mise en évidence | `number` | 10% |

---

## CATÉGORIE 5 — Rapports d'audit et de contrôle interne

### R11 — Journal d'audit des modifications

**Audience** : DAF, Auditeur, Direction
**Fréquence** : À la demande / mensuel
**Formats export** : PDF A4 | Excel | CSV

#### Description (bloc UI)
> **Ce rapport vous permet de** consulter l'historique complet de toutes les créations, modifications et suppressions effectuées sur les écritures et comptes bancaires. Chaque action est tracée avec l'utilisateur, l'heure, l'adresse IP et les valeurs avant/après modification. C'est votre piste d'audit réglementaire.
> 💡 **Astuce** : filtrez sur l'action "DELETE" pour retrouver toutes les écritures supprimées — et utilisez le bouton "Restaurer" si la suppression était accidentelle.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | J-30 |
| Date fin | `date` | Aujourd'hui |
| Type d'objet | `select` (Tous / Compte / Écriture) | Tous |
| Action | `select` (Tous / CREATE / UPDATE / DELETE / RESTORE) | Tous |
| Utilisateur | `select` | Tous |
| Champ modifié | `text` | — |
| Adresse IP | `text` | — |

---

### R12 — Rapport des anomalies détectées

**Audience** : DAF, Auditeur
**Fréquence** : Quotidien (alertes) / Mensuel (synthèse)
**Formats export** : PDF A4 | Excel

#### Description (bloc UI)
> **Ce rapport vous permet de** consulter toutes les anomalies détectées automatiquement par le moteur de règles : doublons probables, montants inhabituels, taux de change anormaux, soldes négatifs, etc. Chaque anomalie peut être acquittée (traitée) ou marquée comme faux positif.
> 💡 **Astuce** : consultez ce rapport chaque matin pour traiter les nouvelles anomalies (badge rouge dans le menu). Un taux élevé de faux positifs signale que le seuil d'une règle est trop sensible — ajustez-le dans la configuration.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date détection début | `date` | J-7 |
| Date détection fin | `date` | Aujourd'hui |
| Règle | `select` | Toutes |
| Statut | `select` (Tous / Nouvelles / Acquittées / Faux positifs) | Nouvelles |
| Compte | `select` | Tous |

---

### R13 — Rapport de rapprochement bancaire

**Audience** : Comptables, DAF
**Fréquence** : Mensuel
**Formats export** : PDF A4 | Excel

#### Description (bloc UI)
> **Ce rapport vous permet de** mesurer le taux de rapprochement de chaque compte bancaire sur une période. Il liste les écritures non rapprochées qui nécessitent une action, et calcule l'écart entre le solde Dolibarr et le solde du relevé de banque.
> 💡 **Astuce** : exportez la liste des écritures non rapprochées en Excel pour la comparer avec votre relevé de banque et effectuer le rapprochement manuellement.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Compte | `select` | Tous |
| Date début | `date` | 1er du mois courant |
| Date fin | `date` | Aujourd'hui |
| Statut | `select` (Tous / Rapprochés / Non rapprochés) | Non rapprochés |

---

## CATÉGORIE 6 — Rapports opérationnels quotidiens

### R14 — Clôture de caisse journalière

**Audience** : Caissiers, Responsables magasins
**Fréquence** : Quotidien (fin de journée)
**Formats export** : PDF A4 (format impression)

#### Description (bloc UI)
> **Ce rapport vous permet de** générer le document officiel de clôture de caisse pour une journée donnée. Il liste tous les mouvements de la journée avec le solde progressif, les totaux et les zones de signature pour le caissier et le responsable. À imprimer et conserver dans les archives physiques.
> 💡 **Astuce** : imprimez ce rapport en fin de journée, faites-le signer par le caissier et le responsable, et conservez-en une copie numérique (PDF) dans la GED Dolibarr.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Caisse | `select` (comptes type=2 uniquement) | — (obligatoire) |
| Date | `date` | Aujourd'hui |

---

### R15 — Activité de saisie par utilisateur

**Audience** : DAF, Responsables
**Fréquence** : Hebdomadaire
**Formats export** : PDF A4 | Excel

#### Description (bloc UI)
> **Ce rapport vous permet de** analyser l'activité de saisie de chaque utilisateur sur les écritures bancaires : nombre d'opérations créées, volume total saisi, montant moyen, plages horaires de saisie. Il aide à détecter les comportements inhabituels et à mesurer la charge de travail de l'équipe comptable.
> 💡 **Astuce** : des saisies en dehors des horaires habituels (nuit, week-end) ou des montants moyens très élevés pour un utilisateur donné peuvent signaler une anomalie à investiguer.

#### Filtres interactifs

| Filtre | Type | Valeur par défaut |
|---|---|---|
| Date début | `date` | J-30 |
| Date fin | `date` | Aujourd'hui |
| Utilisateur | `select` | Tous |
| Action | `select` (CREATE / UPDATE / DELETE) | Tous |

---

## Récapitulatif des 15 rapports — version finale

| # | Rapport | Catégorie | Audience | Fréquence | Export | Permission |
|---|---|---|---|---|---|---|
| R01 | Journal de caisse / bancaire | Flux | Comptable | Quotidien | PDF · Excel · CSV | `reports → read` |
| R02 | Dashboard trésorerie | Trésorerie | DAF / Direction | Temps réel | PDF snapshot | `reports → read` |
| R03 | Flux net par période | Trésorerie | DAF | Mensuel | PDF | `reports → read` |
| R04 | Virements inter-comptes | Flux | DAF | Hebdo | PDF · Excel · CSV | `reports → read` |
| R05 | Gains/pertes de change | Change | DAF / Direction | Mensuel | PDF · Excel · CSV | `reports → read` |
| R06 | Évolution des taux | Change | DAF | Hebdo | PDF · Excel · CSV | `reports → read` |
| R07 | Trésorerie par magasin | Magasin | Resp. magasin / DAF | Quotidien | PDF · Excel · CSV | `reports → read` + `stock` |
| R08 | Activité par magasin | Magasin | Resp. magasin | Mensuel | PDF · Excel · CSV | `reports → read` + `stock` |
| R09 | Flux par catégorie | Catégorie | DAF / Comptable | Mensuel | PDF · Excel · CSV | `reports → read` |
| R10 | Comparatif N vs N-1 | Catégorie | DAF / Direction | Annuel | PDF · Excel | `reports → read` |
| R11 | Journal d'audit | Audit | DAF / Auditeur | À la demande | PDF · Excel · CSV | `audit → read` |
| R12 | Anomalies détectées | Audit | DAF / Auditeur | Quotidien | PDF · Excel | `audit → read` |
| R13 | Rapprochement bancaire | Contrôle | Comptable / DAF | Mensuel | PDF · Excel | `reports → read` |
| R14 | Clôture de caisse | Opérationnel | Caissier | Quotidien | PDF A4 | `reports → read` |
| R15 | Activité par utilisateur | Audit | DAF / Resp. | Hebdo | PDF · Excel | `audit → read` |

---

## Architecture technique

### URL et routing

```
/custom/bankaudit/reports.php?report=R01&...filtres...&format=screen|pdf|excel|csv
```

### Classe centrale des rapports

```php
// /custom/bankaudit/class/BankAuditReports.class.php

class BankAuditReports {

    // Vérification des permissions avant tout rendu
    public function checkAccess($report_code, $export_format = 'screen') {
        global $user;
        $read_perm   = !empty($user->rights->bankaudit->reports->read);
        $export_perm = !empty($user->rights->bankaudit->reports->export);
        $audit_perm  = !empty($user->rights->bankaudit->audit->read);

        $audit_reports = array('R11', 'R12', 'R15');
        if (in_array($report_code, $audit_reports) && !$audit_perm) {
            accessforbidden();
        }
        if (!in_array($report_code, $audit_reports) && !$read_perm) {
            accessforbidden();
        }
        if ($export_format !== 'screen' && !$export_perm && !$audit_perm) {
            accessforbidden('ExportNotAllowed');
        }
    }

    // Génération PDF avec en-tête et pied de page standards
    public function generatePDF($title, $subtitle, $filters_label, $data, $columns, $totals = array()) {
        global $mysoc, $user, $langs;
        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';

        $pdf = pdf_getInstance(array(0, 0, 210, 297)); // A4 portrait
        $pdf->SetCreator('Dolibarr BankAudit — '.$mysoc->name);
        $pdf->SetAuthor($user->getFullName($langs));
        $pdf->SetTitle($title);
        $pdf->SetMargins(12, 45, 12);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(15);
        $pdf->SetAutoPageBreak(true, 25);

        // En-tête personnalisée
        $pdf->setHeaderCallback(function($pdf) use ($mysoc, $title, $subtitle, $filters_label) {
            // Logo
            if ($mysoc->logo && file_exists(DOL_DATA_ROOT.'/mycompany/logos/'.$mysoc->logo)) {
                $pdf->Image(DOL_DATA_ROOT.'/mycompany/logos/'.$mysoc->logo, 12, 5, 25, 0, '', '', 'T');
            }
            // Données société
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetXY(40, 5);
            $pdf->Cell(0, 5, $mysoc->name, 0, 1);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->SetX(40);
            $pdf->Cell(0, 4, $mysoc->address, 0, 1);
            $pdf->SetX(40);
            $pdf->Cell(0, 4, 'RCCM: '.$mysoc->idprof1.' | ID NAT: '.$mysoc->idprof2.' | '.$mysoc->phone, 0, 1);
            // Titre rapport
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetXY(12, 28);
            $pdf->Cell(0, 6, $title, 0, 1, 'C');
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetX(12);
            $pdf->Cell(0, 4, $subtitle, 0, 1, 'C');
            if ($filters_label) {
                $pdf->SetFont('helvetica', '', 7);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->SetX(12);
                $pdf->Cell(0, 4, 'Filtres : '.$filters_label, 0, 1, 'C', true);
            }
            // Ligne de séparation
            $pdf->Line(12, 42, 198, 42);
        });

        // Pied de page
        $pdf->setFooterCallback(function($pdf) use ($user, $langs) {
            $pdf->SetY(-18);
            $pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
            $pdf->SetFont('helvetica', 'I', 7);
            $pdf->Cell(0, 4,
                'Exporté par : '.$user->getFullName($langs)
                .' | '.dol_print_date(dol_now(), 'dayhour')
                .' | Page '.$pdf->getAliasNumPage().' / '.$pdf->getAliasNbPages()
                .' | CONFIDENTIEL',
                0, 0, 'C'
            );
        });

        $pdf->AddPage();
        // ... rendu du tableau de données
        return $pdf;
    }

    // Export Excel avec mise en forme
    public function generateExcel($title, $data, $columns, $totals = array()) {
        // PhpSpreadsheet si disponible
        // Sinon : CSV UTF-8 avec BOM pour compatibilité Excel
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.slugify($title).'_'.date('Ymd_His').'.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8
        // ... export CSV
    }
}
```

### Scheduler Dolibarr (envoi automatique)

```php
// Dans modBankAudit.class.php → $this->cronjobs
$this->cronjobs = array(
    1 => array(
        'label'          => 'Rapport trésorerie quotidien (R02) — email direction',
        'jobtype'        => 'method',
        'class'          => '/custom/bankaudit/class/BankAuditReports.class.php',
        'objectname'     => 'BankAuditReports',
        'method'         => 'sendScheduledReport',
        'parameters'     => 'R02',
        'comment'        => 'Envoie le snapshot PDF du dashboard trésorerie chaque matin à 7h',
        'frequency'      => 1,
        'unitfrequency'  => 86400,
        'priority'       => 50,
        'datestart'      => -1,
        'enabled'        => '$conf->bankaudit->enabled',
    ),
    2 => array(
        'label'          => 'Rapport anomalies hebdomadaire (R12) — email DAF',
        'jobtype'        => 'method',
        'class'          => '/custom/bankaudit/class/BankAuditReports.class.php',
        'objectname'     => 'BankAuditReports',
        'method'         => 'sendScheduledReport',
        'parameters'     => 'R12',
        'comment'        => 'Synthèse hebdomadaire des anomalies non acquittées',
        'frequency'      => 7,
        'unitfrequency'  => 86400,
        'priority'       => 50,
        'datestart'      => -1,
        'enabled'        => '$conf->bankaudit->enabled',
    ),
);
```

---

## Valeurs ajoutées globales suggérées ✨

| Fonctionnalité | Description | Impact |
|---|---|---|
| **Favoris de rapports** | Sauvegarder une combinaison de filtres sous un nom (ex : "Clôture DEGO juin") et la rappeler en 1 clic | ⭐⭐⭐⭐⭐ |
| **Comparaison rapide** | Bouton "Comparer avec la période précédente" disponible sur R01, R03, R09 — affiche les deux périodes côte à côte | ⭐⭐⭐⭐ |

| **Mode "Revue de direction"** | Bouton qui génère un PDF consolidé R02 + R03 + R07 + R10 en un seul document, prêt pour la réunion mensuelle | ⭐⭐⭐⭐⭐ |
| **Annotations sur rapport PDF** | Permettre d'ajouter un commentaire manuscrit (zone de texte) avant génération du PDF — apparaît en encadré sur la 1ère page | ⭐⭐⭐ |
| **Historique des exports** | Conserver une trace de qui a exporté quel rapport, quand, avec quels filtres → utile pour l'audit de confidentialité | ⭐⭐⭐⭐ |
