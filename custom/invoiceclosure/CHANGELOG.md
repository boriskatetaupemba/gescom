# CHANGELOG — Module Clôture des factures (invoiceclosure)

## 1.0.0 — 2026-08-06

Première version. Développée et vérifiée pour Dolibarr 20.0.4 (MySQL/MariaDB).

### Ajouts
- Statut métier « Clôturée » complémentaire du statut standard « Payée »
  (le statut Dolibarr `llx_facture.fk_statut` n'est jamais modifié).
- Tables `llx_invoiceclosure` (état, unique par facture/entité, FK facture et
  utilisateurs) et `llx_invoiceclosure_log` (historique permanent CLOSE/REOPEN,
  idempotence par contrainte unique sur request_id).
- Classe métier centralisée `InvoiceClosure` (fetch, fetchByInvoice, isClosed,
  getClosureStatus, canClose, canReopen, close, reopen, getHistory,
  deleteByInvoice) — logique unique partagée par l'UI et l'API, transactions
  complètes avec rollback.
- Fiche facture (hooks `invoicecard`) : boutons « Clôturer » / « Rouvrir la
  clôture » avec confirmation et note, badge « Clôturée » dans la bannière
  (hook `formDolBanner`), bloc d'informations de clôture, lien historique.
- Liste des factures (hooks `invoicelist`) : colonne « Clôture » (badge, date,
  utilisateur) et filtres (statut, utilisateur, période de clôture),
  compatibles pagination/tri, filtrés par entité.
- Verrouillage serveur des factures clôturées par triggers (BILL_MODIFY,
  BILL_UNPAYED, BILL_CANCEL, BILL_UNVALIDATE, BILL_DELETE, LINEBILL_*,
  PAYMENT_CUSTOMER_DELETE) avec rollback natif ; droit « Forcer » journalisé
  en WARNING.
- Événements métier `INVOICECLOSURE_CLOSE` / `INVOICECLOSURE_REOPEN` déclenchés
  dans la transaction ; événement agenda automatique optionnel.
- API REST `/api/index.php/invoiceclosureapi` : close, reopen, statut,
  historique, liste avec filtres ; idempotence par `request_id` ; codes HTTP
  normalisés ; visible dans l'explorateur REST.
- 6 droits utilisateurs (lire, clôturer, rouvrir, historique, configurer, forcer).
- Page de configuration (8 options), page À propos, page historique par facture.
- Traductions fr_FR et en_US complètes.
- Tests PHPUnit, scripts de tests API (bash + PowerShell), cahier de tests manuels.

### Limitations documentées (voir README §5)
- La modification de la date/du numéro d'un paiement existant ne déclenche
  aucun trigger dans Dolibarr 20.0.4 et ne peut pas être bloquée sans modifier
  le cœur.
- Endpoint API `invoiceclosureapi` (singulier + suffixe api) imposé par le
  routeur API de Dolibarr 20.0.4 pour un module du répertoire `invoiceclosure`.
