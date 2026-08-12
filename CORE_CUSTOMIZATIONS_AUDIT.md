# Audit des personnalisations du cœur Dolibarr

Date de l'audit : 11 août 2026  
Version locale : Dolibarr 20.0.4  
Référence officielle : tag `20.0.4`, commit
`030357e87be3d076eefea2a8f9999bc0afa0ee47`

## Méthode

Le premier commit applicatif contenait déjà Dolibarr et les personnalisations ;
l'historique Git du projet ne suffisait donc pas. L'audit a combiné :

- la comparaison des blobs Git de toute l'arborescence locale avec
  `htdocs/` du tag officiel 20.0.4 ;
- le manifeste de distribution `install/filelist-20.0.4.xml`, avec
  normalisation CRLF/LF ;
- l'historique depuis l'import initial `5f14b2a` ;
- une recherche des marqueurs, sauvegardes, rejets et fichiers ajoutés.

Les absences de fichiers de documentation provenant du conditionnement des
dépendances (`includes/`, README de langues, `.gitignore`, tests publics) ne
sont pas des personnalisations métier et ne sont pas incluses ci-dessous.

## Inventaire exhaustif trouvé

| Fichier du cœur avant extraction | Modification trouvée | Destination |
|---|---|---|
| `api/class/api_login.class.php` | Ajout de `success.default_warehouse`, comptes/caisses, soldes et taux de change | `custom/bankaudit/class/bankauditusercontext.class.php` et `GET /bankauditapi/context` |
| `compta/bank/class/api_bankaccounts.class.php` | Ajout de `{@from body}` à la PHPDoc du paramètre `category` | Aucun code à déplacer : Restler traite déjà ce paramètre POST dans le corps ; fichier restauré |
| `compta/facture/class/api_invoices.class.php` | Route `GET /invoices/byaccounts` | `GET /invoiceplus/byaccounts` |
| `compta/facture/class/api_invoices.class.php` | Injection de `invoiceclosure` dans `_cleanObjectDatas()` | Wrappers `/invoiceplus` et enrichissement dans `InvoicePlusInvoiceService` |
| `product/stock/movement_list.php` | Boutons natifs masqués, lien vers une page ajoutée et formulaire rapide sans gestionnaire | Fichier restauré ; menu et hook du module StockQuickMove |
| `product/stock/movement_create.php` | Page complète transfert/entrée/sortie, absente de Dolibarr | `custom/stockquickmove/quickmovement.php` |

Le seul changement du cœur identifiable après l'import initial est le commit
`da596ef`, qui avait ajouté l'enrichissement InvoiceClosure à l'API native.

## Changements de routes à appliquer aux clients

| Avant | Après extraction |
|---|---|
| `POST /login`, puis lecture de `success.default_warehouse` | `POST /login`, puis `GET /bankauditapi/context` avec `DOLAPIKEY` |
| `GET /invoices/byaccounts?account_ids=...` | `GET /invoiceplus/byaccounts?account_ids=...` |
| `GET /invoices...` avec propriété `invoiceclosure` injectée | `GET /invoiceplus...`, ou API canonique `/invoiceclosureapi` |
| `/product/stock/movement_create.php` | `/custom/stockquickmove/quickmovement.php` |

Ces changements d'URL sont nécessaires : Dolibarr 20.0.4 ne fournit aucun
hook permettant à un module externe d'enrichir la réponse de `/login`, de
rajouter une méthode sous la base `/invoices`, ou d'injecter une propriété dans
`Invoices::_cleanObjectDatas()`.

## Modules concernés

- **BankAudit 1.1.0** : contexte de l'utilisateur interne authentifié sous
  `/bankauditapi/context`. La route exige les droits de lecture Stock et
  Banque/Caisse et contrôle l'accès à l'entrepôt. Le module dépend désormais
  explicitement des modules Banque/Caisse et Stock.
- **InvoicePlus 1.2.0** : wrappers natifs, filtres par comptes et entrepôt,
  tiers affectés au commercial authentifié et ajout optionnel des informations
  InvoiceClosure.
- **InvoiceClosure** : reste la source métier canonique du statut et conserve
  ses routes `/invoiceclosureapi`.
- **StockQuickMove 1.0.1** : page autonome, menu Produits > Entrepôts et hooks
  `stockmovementlist` sans remplacement des actions natives.

Après déploiement, activer StockQuickMove, réactiver BankAudit et InvoicePlus,
puis reconstruire le cache REST si `API_PRODUCTION_MODE` est actif.

## Vérifications réalisées

- syntaxe de 34 fichiers PHP contrôlée avec PHP 8.2.12, ainsi que celle des
  scripts de recette PowerShell et POSIX shell ;
- comparaison des quatre fichiers officiels modifiés avec le tag 20.0.4 :
  aucune différence de contenu après normalisation des fins de ligne ;
- suppression du fichier métier ajouté sous `product/stock/` ;
- test de chargement des services InvoicePlus, validation des filtres SQL et
  visibilité publique du pont vers les contrôles d'accès natifs ;
- conservation des changements existants sous `custom/` et absence de remise
  à zéro destructive du dépôt.

Les tests HTTP complets nécessitent une instance Dolibarr active, une base de
données de test et une clé API. Les cahiers de recette se trouvent sous
`custom/invoiceclosure/test/`, `custom/invoiceplus/test/` et dans le README de
StockQuickMove.

## Hors périmètre mais important

`conf/conf.php` et `conf/conf.php.old` sont suivis par Git et peuvent contenir
des identifiants de base de données. Ils n'ont pas été modifiés pendant cet
audit ; leur retrait de l'historique et la rotation des secrets doivent faire
l'objet d'une opération séparée et explicitement autorisée.
