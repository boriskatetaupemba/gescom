# Module Dolibarr — Clôture des factures (invoiceclosure)

Ajoute un statut métier **« Clôturée »** après le statut standard **« Payée »**
d'une facture client, **sans modifier aucun fichier du cœur de Dolibarr**.

- Version du module : **1.0.0**
- Développé et vérifié pour : **Dolibarr 20.0.4**, PHP ≥ 7.1, MySQL/MariaDB
- Numéro de module : **4920000**
- Répertoire : `htdocs/custom/invoiceclosure/`

## Workflow

```
Brouillon → Validée → Payée → Clôturée   (et retour possible : Clôturée → Payée)
```

Lorsqu'une facture est clôturée :

- son statut standard Dolibarr reste **« Payée »** (`llx_facture.fk_statut` et
  `paye` ne sont **jamais** modifiés par le module) ;
- le module enregistre la clôture (date, utilisateur, note) dans sa propre table ;
- un badge « Clôturée » est affiché dans la bannière de la fiche et dans la liste ;
- la facture peut être verrouillée contre les modifications (option) ;
- chaque clôture/réouverture est historisée définitivement.

---

## 1. Installation

1. Copier le répertoire `invoiceclosure/` dans `htdocs/custom/`.
2. Vérifier que `conf.php` déclare bien le répertoire alternatif custom
   (`$dolibarr_main_url_root_alt='/custom'` — déjà le cas sur cette instance).
3. Menu **Accueil → Configuration → Modules/Applications**, famille
   « Financier » : activer **Clôture des factures**.
   L'activation crée les tables `<prefix>invoiceclosure` et
   `<prefix>invoiceclosure_log`, les constantes et les droits.
4. Attribuer les droits aux utilisateurs/groupes
   (**Utilisateurs & Groupes → fiche → Permissions → Clôture des factures**) :
   - Lire les informations de clôture
   - Clôturer une facture
   - Rouvrir une facture clôturée
   - Consulter l'historique de clôture
   - Configurer le module
   - Forcer une opération sur une facture clôturée (administratif, journalisé)
5. Configurer le module : **Configuration → Modules → Clôture des factures → ⚙**.

### Constantes de configuration

| Constante | Défaut | Rôle |
|---|---|---|
| `INVOICECLOSURE_REQUIRE_ZERO_REMAIN` | 1 | Exiger facture totalement payée (reste à payer = 0). À 0 : toute facture au statut « Payée » (y compris classée payée avec escompte/perte/motif) est clôturable |
| `INVOICECLOSURE_LOCK_CLOSED_INVOICES` | 1 | Verrouiller les factures clôturées (voir §5) |
| `INVOICECLOSURE_ALLOW_REOPEN` | 1 | Autoriser la réouverture |
| `INVOICECLOSURE_REQUIRE_CLOSE_NOTE` | 0 | Note de clôture obligatoire |
| `INVOICECLOSURE_REQUIRE_REOPEN_NOTE` | 1 | Note de réouverture obligatoire |
| `INVOICECLOSURE_CREATE_AGENDA_EVENT` | 1 | Créer un événement agenda à chaque clôture/réouverture |
| `INVOICECLOSURE_SHOW_IN_INVOICE_LIST` | 1 | Colonne + filtres dans la liste des factures |
| `INVOICECLOSURE_SHOW_BADGE` | 1 | Badge « Clôturée » sur la fiche facture |

---

## 2. Mise à jour

1. Désactiver le module (les données et constantes sont **conservées**).
2. Remplacer le répertoire `htdocs/custom/invoiceclosure/` par la nouvelle version.
3. Réactiver le module : `init()` rejoue les scripts SQL (idempotents : les
   `CREATE TABLE` échouent silencieusement si les tables existent) et les
   futures migrations de structure seront livrées sous forme de fichiers
   `llx_invoiceclosure*-x.y.z.sql` exécutés par le même mécanisme
   (`_load_tables()`), conformément aux conventions Dolibarr.
4. Vider le cache API si nécessaire (supprimer `htdocs/api/temp/routes.php`).

## 3. Désinstallation

- **Désactivation simple** : aucune donnée supprimée. Les tables, l'historique
  et les constantes restent en base ; les droits et hooks sont retirés.
- **Désinstallation complète (volontaire et manuelle uniquement)** : le module
  ne supprime **jamais** ses tables automatiquement, pour ne jamais perdre
  l'historique silencieusement. Si vous souhaitez réellement tout supprimer :

```sql
-- ATTENTION : perte définitive de l'historique des clôtures
DROP TABLE <prefix>invoiceclosure;
DROP TABLE <prefix>invoiceclosure_log;
DELETE FROM <prefix>const WHERE name LIKE 'INVOICECLOSURE_%';
```

---

## 4. API REST

Base : `https://VOTRE-DOLIBARR/api/index.php/invoiceclosureapi`

> **Pourquoi `/invoiceclosureapi` et pas `/invoiceclosures` ?**
> Vérifié dans les sources 20.0.4 (`api/index.php`,
> `getModuleDirForApiClass()` dans `core/lib/functions2.lib.php`) : pour un
> module externe du répertoire `invoiceclosure`, le routage direct n'accepte
> que des noms d'endpoint qui redonnent le répertoire du module après
> suppression du suffixe `api`. Un endpoint pluriel `/invoiceclosures`
> renverrait HTTP 501 sans patch du cœur (interdit). L'endpoint est visible
> dans l'explorateur REST (`/api/index.php/explorer/`) sous
> **invoiceclosureapi**.

| Méthode | Route | Rôle | Droit requis |
|---|---|---|---|
| GET | `/invoiceclosureapi/invoices/{id}` | Statut de clôture | read |
| GET | `/invoiceclosureapi/invoices/{id}/history` | Historique | readhistory |
| POST | `/invoiceclosureapi/invoices/{id}/close` | Clôturer | close |
| POST | `/invoiceclosureapi/invoices/{id}/reopen` | Rouvrir | reopen |
| GET | `/invoiceclosureapi/` | Liste des clôtures (filtres `status`, `thirdparty_id`, `user_id`, `date_start`, `date_end`, `sortfield`, `sortorder`, `limit`, `page`, `sqlfilters`) | read |

Tous les appels exigent en plus le droit Dolibarr *facture → lire* et l'accès
à la facture (les utilisateurs externes ne voient que leur tiers).
Authentification : en-tête `DOLAPIKEY` (jamais dans l'URL) ; multi-entité :
en-tête `DOLAPIENTITY` standard.

### Idempotence

- `request_id` (facultatif, `[A-Za-z0-9._-]{1,64}`) : un `request_id` déjà
  consommé pour la même action n'est **jamais** rejoué (contrainte unique en
  base) → HTTP 200 avec `already_closed=true`, historique et date d'origine
  intacts.
- Facture déjà clôturée avec un `request_id` différent (ou sans) →
  **comportement documenté choisi : HTTP 200 avec `already_closed=true`**
  (demande déjà satisfaite), pas de 409.

### Codes HTTP

| Code | Cas |
|---|---|
| 200 | Succès ou action déjà effectuée (idempotence) |
| 400 | Paramètres invalides, note obligatoire absente, champ serveur interdit dans le corps |
| 401 | Clé API absente/invalide (géré par Dolibarr) |
| 403 | Droits insuffisants, accès facture refusé |
| 404 | Facture inexistante (ou entité non visible) |
| 409 | Non éligible : impayée, brouillon, abandonnée, reste à payer non nul, pas clôturée (reopen) |
| 500 | Erreur interne (détails uniquement dans syslog) |

### Exemples curl

```bash
# Clôturer
curl -X POST \
  -H "DOLAPIKEY: VOTRE_CLE_API" \
  -H "Content-Type: application/json" \
  -d '{
    "note": "Clôture après vérification de la caisse",
    "request_id": "CLOSE-2026-000001"
  }' \
  "https://VOTRE-DOLIBARR/api/index.php/invoiceclosureapi/invoices/123/close"

# Rouvrir
curl -X POST \
  -H "DOLAPIKEY: VOTRE_CLE_API" \
  -H "Content-Type: application/json" \
  -d '{
    "note": "Réouverture pour correction",
    "request_id": "REOPEN-2026-000001"
  }' \
  "https://VOTRE-DOLIBARR/api/index.php/invoiceclosureapi/invoices/123/reopen"

# Consulter le statut
curl -X GET -H "DOLAPIKEY: VOTRE_CLE_API" \
  "https://VOTRE-DOLIBARR/api/index.php/invoiceclosureapi/invoices/123"

# Historique
curl -X GET -H "DOLAPIKEY: VOTRE_CLE_API" \
  "https://VOTRE-DOLIBARR/api/index.php/invoiceclosureapi/invoices/123/history"

# Liste des factures clôturées
curl -X GET -H "DOLAPIKEY: VOTRE_CLE_API" \
  "https://VOTRE-DOLIBARR/api/index.php/invoiceclosureapi/?status=1&limit=50"
```

Les chemins exacts sont vérifiables dans l'explorateur REST après installation.

### Intégration avec l'API native `/invoices` (personnalisation de ce fork)

Lorsque le module est **activé** et que l'utilisateur API possède le droit
*Lire les informations de clôture*, toutes les routes natives qui retournent
des factures clients (`GET /invoices`, `GET /invoices/{id}`,
`GET /invoices/ref/{ref}`, `GET /invoices/ref_ext/{ref_ext}`,
`GET /invoices/byaccounts`, ainsi que les retours de `PUT /invoices/{id}`,
`validate`, `settopaid`, `settounpaid`, `settodraft`, `createfromorder`,
`createfromcontract`, …) incluent une propriété supplémentaire :

```json
"invoiceclosure": {
  "business_status": 1,
  "business_status_code": "closed",
  "business_status_label": "Clôturée",
  "locked": 1,
  "closed_at": 1786012560,
  "closed_at_iso": "2026-08-06T14:36:00+02:00",
  "closed_by": { "id": 15, "login": "bkateta" },
  "closure_note": "Clôture après vérification de la caisse",
  "reopened_at": null,
  "reopened_at_iso": null,
  "reopened_by": null,
  "reopen_note": ""
}
```

Implémentation : enrichissement centralisé dans l'override
`Invoices::_cleanObjectDatas()` de `compta/facture/class/api_invoices.class.php`
(fichier déjà personnalisé sur cette instance — voir §7). Module désactivé ou
droit absent → la propriété est simplement omise, comportement natif inchangé.

---

## 5. Verrouillage des factures clôturées

Quand `INVOICECLOSURE_LOCK_CLOSED_INVOICES=1`, le trigger du module bloque
côté serveur (retour négatif → **rollback complet par le cœur**, mécanisme
vérifié dans les sources 20.0.4) :

| Opération | Événement bloqué | Couverture |
|---|---|---|
| Modification en-tête (dates, conditions, remises, banque…) | `BILL_MODIFY` | UI + API + masse |
| Remise en impayé (« Réouvrir » standard) | `BILL_UNPAYED` | UI + API |
| Classement abandonnée | `BILL_CANCEL` | UI |
| Repassage en brouillon | `BILL_UNVALIDATE` | UI |
| Suppression de la facture | `BILL_DELETE` | UI + API + masse |
| Ajout / modification / suppression de lignes | `LINEBILL_INSERT/MODIFY/DELETE` | UI + API |
| Suppression d'un paiement lié | `PAYMENT_CUSTOMER_DELETE` | UI + API (double protection : le cœur refuse déjà la suppression d'un paiement d'une facture classée) |

Côté interface, les boutons d'action standard sont remplacés par un sous-ensemble
sûr (Envoyer courriel, Cloner, Rouvrir la clôture) pour les utilisateurs sans
droit « Forcer ».

Le droit **« Forcer une opération »** contourne le verrou ; chaque opération
forcée est tracée en `LOG_WARNING` dans le syslog Dolibarr.

### Limitations connues (Dolibarr 20.0.4 — documentées, cœur non modifié)

1. **Modification de la date/du numéro d'un paiement existant** :
   `Paiement::update_date()` / `update_num()` n'appellent **aucun trigger**
   dans cette version. Impossible à bloquer proprement sans modifier le cœur.
   Atténuation : la suppression du paiement est bloquée, et la facture reste
   « Payée » ; l'édition de la date d'un paiement ne change ni les montants ni
   le statut.
2. **Opérations appelées avec `notrigger=1`** par du code tiers : par
   définition, aucun trigger n'est exécuté. Le cœur n'utilise pas ce mode pour
   les actions utilisateur listées ci-dessus.
3. **Génération de PDF / envoi d'email** : volontairement autorisés (aucune
   modification comptable de la facture).
4. **Extrafields de la facture** : la mise à jour d'un extrafield seul passe
   par `update_extrafields()` sans trigger `BILL_MODIFY` systématique selon le
   chemin d'appel ; le formulaire étant masqué sur facture clôturée verrouillée
   (boutons remplacés), le risque résiduel est limité à des appels directs.

---

## 6. Base de données

- `<prefix>invoiceclosure` — état courant (1 ligne max par facture/entité,
  contrainte unique `(entity, fk_facture)`, FK vers `facture` et `user`).
- `<prefix>invoiceclosure_log` — historique complet (CLOSE/REOPEN, date,
  utilisateur, note, source UI/API/IMPORT/OTHER, request_id). **Pas de FK sur
  fk_facture** : l'audit survit à la suppression d'une facture. Contrainte
  unique `(entity, action_code, request_id)` pour l'idempotence.
- `sql/data.sql` absent : aucune donnée initiale à insérer (fichier de données
  vide exclu volontairement, conformément au fonctionnement de `_load_tables()`).

## 7. Fichiers du cœur inspectés (aucun modifié par le module)

> Note propre à ce fork : `compta/facture/class/api_invoices.class.php` était
> **déjà personnalisé** sur cette instance (route `byaccounts`). L'intégration
> « statut de clôture dans l'API native » (§4) y a été ajoutée dans la même
> logique. Le module lui-même reste 100 % autonome : si ce fichier est écrasé
> par une mise à jour Dolibarr, seul l'enrichissement de l'API native disparaît,
> le module et son API `/invoiceclosureapi` continuent de fonctionner.

- `filefunc.inc.php` (version 20.0.4), `conf/conf.php` (SGBD mysqli, préfixe)
- `compta/facture/card.php` (contexts/hook `invoicecard`, actions, boutons, formConfirm)
- `compta/facture/list.php` (context `invoicelist`, hooks de liste, massactions)
- `compta/facture/class/facture.class.php` (statuts, setPaid/setUnpaid/delete, triggers BILL_*/LINEBILL_*)
- `compta/facture/class/api_invoices.class.php` (conventions API)
- `core/class/commoninvoice.class.php` (`getRemainToPay`)
- `compta/paiement/class/paiement.class.php` (triggers paiement, protections natives)
- `core/class/hookmanager.class.php` (conventions de chargement des hooks)
- `core/class/interfaces.class.php` (conventions triggers)
- `core/lib/functions.lib.php` (`dol_banner_tab` / hook `formDolBanner`)
- `core/lib/functions2.lib.php` (`getModuleDirForApiClass`)
- `core/modules/DolibarrModules.class.php` (`_load_tables`, `_init`, `_remove`)
- `core/tpl/extrafields_view.tpl.php` (hook `formObjectOptions` en vue)
- `api/index.php` (découverte / routage des API)
- `core/class/html.form.class.php` (formconfirm avec textarea, selectDate)

## 8. Hooks utilisés

Contexte `invoicecard` : `doActions`, `formConfirm`, `addMoreActionsButtons`,
`formObjectOptions`, `formDolBanner`.
Contexte `invoicelist` : `printFieldListSelect`, `printFieldListFrom`,
`printFieldListWhere`, `printFieldListSearchParam`, `printFieldListOption`,
`printFieldListTitle`, `printFieldListValue`.
Triggers écoutés : `BILL_MODIFY`, `BILL_UNPAYED`, `BILL_CANCEL`,
`BILL_UNVALIDATE`, `BILL_DELETE`, `LINEBILL_INSERT`, `LINEBILL_MODIFY`,
`LINEBILL_DELETE`, `PAYMENT_CUSTOMER_DELETE`.
Triggers émis : `INVOICECLOSURE_CLOSE`, `INVOICECLOSURE_REOPEN` (dans la
transaction métier, rollback si un listener retourne < 0).

## 9. Tests

- `test/unit/InvoiceClosureTest.php` — PHPUnit (nécessite un poste avec PHP CLI).
- `test/api/test_invoiceclosure_api.sh` / `.ps1` — tests API bout en bout.
- `test/MANUAL_TESTS.md` — cahier des 25 scénarios d'acceptation.
