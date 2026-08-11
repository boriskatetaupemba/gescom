# InvoiceClosure — Cahier de tests manuels

Couvre les 25 scénarios obligatoires. Les scénarios purement métier sont aussi
automatisés dans `test/unit/InvoiceClosureTest.php` (PHPUnit) et les scénarios
API dans `test/api/test_invoiceclosure_api.sh` / `.ps1`.

Pré-requis : module **Clôture des factures** activé, droits attribués à
l'utilisateur de test (Lire, Clôturer, Rouvrir, Historique), une facture client
payée disponible.

| # | Scénario | Étapes | Résultat attendu | Automatisé |
|---|----------|--------|------------------|------------|
| 1 | Clôture d'une facture payée (reste à payer = 0) | Fiche facture payée → bouton **Clôturer** → note → Oui | Message succès, badge « Clôturée », bouton Clôturer masqué | PHPUnit `testCloseFullyPaidInvoice` |
| 2 | Refus facture brouillon | Ouvrir une facture brouillon | Aucun bouton Clôturer ; API → 409 | PHPUnit `testCloseDraftInvoiceRefused` |
| 3 | Refus facture validée impayée | Ouvrir une facture validée non payée | Aucun bouton Clôturer ; API → 409 | PHPUnit `testCloseUnpaidInvoiceRefused` |
| 4 | Refus facture abandonnée | Facture classée abandonnée | Aucun bouton Clôturer ; API → 409 | PHPUnit `testCloseAbandonedInvoiceRefused` |
| 5 | Refus droits insuffisants | Utilisateur sans droit « Clôturer » sur une facture payée | Aucun bouton ; API → 403 | PHPUnit `testCloseWithoutPermissionRefused` |
| 6 | Refus autre entité (multi-entité) | Utilisateur entité A, facture entité B (API avec DOLAPIENTITY) | 404/403, pas de clôture | PHPUnit `testCloseOtherEntityRefused` |
| 7 | Clôture depuis l'interface | Scénario 1 complet avec note | Enregistrement + historique CLOSE source UI | Manuel |
| 8 | Clôture depuis l'API | `POST /invoiceclosureapi/invoices/{id}/close` | 200, `already_closed=false`, historique source API | Script API étape 2 |
| 9 | Double clôture même request_id | Rejouer le même POST | 200, `already_closed=true`, PAS de nouvelle ligne d'historique, date initiale conservée | Script API étape 3 + PHPUnit `testCloseIdempotency` |
| 10 | Double clôture autre request_id | POST avec un request_id différent | 200, `already_closed=true` (comportement documenté) | Script API étape 4 + PHPUnit |
| 11 | Réouverture depuis l'interface | Facture clôturée → **Rouvrir la clôture** → note → Oui | Badge retiré, statut Dolibarr toujours « Payée » | Manuel |
| 12 | Réouverture depuis l'API | `POST /invoiceclosureapi/invoices/{id}/reopen` | 200, `business_status=0`, statut Dolibarr inchangé | Script API étape 7 |
| 13 | Historique complet | Clôturer, rouvrir, re-clôturer puis ouvrir la page Historique | 3 lignes : CLOSE, REOPEN, CLOSE avec dates/utilisateurs/notes | PHPUnit `testReopenAndHistory` |
| 14 | Badge sur la fiche | Fiche d'une facture clôturée | Badge « Clôturée » à côté du statut « Payée » dans la bannière + bloc d'infos (date, par, note) | Manuel |
| 15 | Colonne dans la liste | Liste des factures clients | Colonne « Clôture » : badge + date + utilisateur pour les clôturées, « Non clôturée » sinon | Manuel |
| 16 | Filtres de liste | Filtrer : Clôturées / Non clôturées / par dates / par utilisateur | Résultats cohérents, filtres conservés par la pagination et le tri | Manuel |
| 17 | Verrouillage | Facture clôturée (verrou actif), tenter « Réouvrir » standard (remise en impayé) ou suppression | Boutons standard remplacés ; en forçant l'URL, opération bloquée avec message ; rien en base | PHPUnit `testLockBlocksModifications` |
| 18 | Contournement admin | Utilisateur avec droit « Forcer » : refaire le scénario 17 | Opération autorisée, ligne WARNING dans syslog Dolibarr | Manuel |
| 19 | Utilisateur externe | Utilisateur externe lié au tiers X : API GET/POST sur facture d'un tiers Y | 403 ; sur son propre tiers : selon ses droits | Manuel |
| 20 | Multi-entité | Activer multicompany, factures dans 2 entités | Chaque entité ne voit/clôture que ses factures ; liste filtrée par entité | Manuel + PHPUnit scénario 6 |
| 21 | Transactions/rollback | Scénario 17 (trigger bloque) puis vérifier la base | Aucune écriture partielle : facture, paiements, closure intacts | PHPUnit `testLockBlocksModifications` |
| 22 | fk_statut inchangé | Avant/après clôture : `SELECT fk_statut, paye FROM qL_facture WHERE rowid=...` | Valeurs strictement identiques (2 / 1) | PHPUnit `testCloseFullyPaidInvoice` |
| 23 | Paiements intacts | Avant/après clôture+verrou : table `qL_paiement_facture` | Aucune modification | PHPUnit `testLockBlocksModifications` |
| 24 | API dans l'explorateur REST | Ouvrir `/api/index.php/explorer/`, chercher `invoiceclosureapi` | Les 5 routes documentées apparaissent | Manuel |
| 25 | Désactivation / réactivation | Désactiver puis réactiver le module | Tables et données conservées, constantes conservées, tout refonctionne (badge, boutons, API) | Manuel |

## Notes de vérification SQL utiles

```sql
-- Statut standard avant/après (doit être identique)
SELECT rowid, ref, fk_statut, paye, close_code FROM qL_facture WHERE rowid = <ID>;

-- Enregistrement de clôture
SELECT * FROM qL_invoiceclosure WHERE fk_facture = <ID>;

-- Historique complet
SELECT * FROM qL_invoiceclosure_log WHERE fk_facture = <ID> ORDER BY action_date;
```
