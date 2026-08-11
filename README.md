# gescom

**gescom** est une solution de gestion commerciale (ERP/CRM) basée sur
**[Dolibarr](https://www.dolibarr.org/) 20.0.4**, enrichie de modules et de
développements sur mesure pour la gestion d'une activité commerciale en
**République Démocratique du Congo**, avec une gestion **multi-devises USD / CDF**.

## Sommaire

- [Fonctionnalités sur mesure](#fonctionnalités-sur-mesure)
- [Structure du projet](#structure-du-projet)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [API REST](#api-rest)
- [Documentation](#documentation)
- [Licence](#licence)


## Fonctionnalités sur mesure

En plus des fonctions standard de Dolibarr (tiers, produits, stock, facturation,
banque, comptabilité, etc.), ce dépôt ajoute les développements suivants.

### PosNova — Point de vente multi-devises (`custom/posnova/`)

Module de caisse (POS) plein écran conçu pour la RDC :

- Multi-devises **USD / CDF** avec taux de change du jour.
- Architecture : entrepôt → caisse (`pos_config`) → session → ticket → facture.
- Sessions de caisse : ouverture, clôture, verrouillage et **rapport Z**.
- Paiements et rendu de monnaie multi-devises.
- Reçus thermiques 80 mm / 58 mm ou A4.
- Interface SPA plein écran (terminal de vente).
- Reporting dédié (factures, sessions).

Spécifications : [specs_module_pos.md](specs_module_pos.md)

### BankAudit — Audit et gestion des banques / caisses (`custom/bankaudit/`)

- Liaison compte bancaire ou caisse ↔ entrepôt (champ complémentaire).
- **Reporting bancaire** (rapports R01 à R15) avec favoris et exports
  **CSV / XLSX / PDF** (PDF au format corporate).
- Formulaire de **saisie rapide** d'écritures (basé sur `PaymentVarious`).
- Impression de **tickets** thermiques.

Documents : [reporting_banques_caisses.md](reporting_banques_caisses.md),
[prompt_banques_caisses_dolibarr.md](prompt_banques_caisses_dolibarr.md)

### InvoiceClosure et InvoicePlus (`custom/invoiceclosure/`, `custom/invoiceplus/`)

- Clôture métier, verrouillage et historique des factures clients.
- API de clôture autonome sous `/invoiceclosureapi`.
- API factures enrichie sous `/invoiceplus`, avec filtres par entrepôt ou
  comptes/caisses et données de clôture optionnelles.
- Aucun patch de `compta/facture/class/api_invoices.class.php`.

### StockQuickMove — Saisie rapide (`custom/stockquickmove/`)

- Formulaire unique pour **transfert / entrée / sortie**.
- Stock en temps réel, bouton « Max », garde anti-stock négatif.
- Journal des derniers mouvements et accessibilité (ARIA).
- Menu et bouton ajoutés par le module, sans modification de la liste native.

Document : [Gestion_des_Mouvements_de_Stock.md](Gestion_des_Mouvements_de_Stock.md)

## Structure du projet

Le dépôt correspond à la racine d'une installation Dolibarr (dossier *htdocs*) :

```text
gescom/
├── custom/
│   ├── bankaudit/       # Audit banques / caisses + contexte utilisateur
│   ├── invoiceclosure/  # Clôture métier des factures
│   ├── invoiceplus/     # API factures enrichie
│   ├── posnova/         # POS multi-devises
│   └── stockquickmove/  # Saisie rapide des mouvements de stock
├── api/              # API REST native Dolibarr
├── compta/           # Comptabilité, banque, facturation
├── conf/             # Configuration (conf.php)
├── install/          # Scripts d'installation et schéma SQL
├── core/, includes/  # Cœur Dolibarr et dépendances
└── ...               # Modules standard Dolibarr
```

## Prérequis

- **PHP** 7.1 à 8.3
- **MySQL** 5.x / **MariaDB** 10.x
- Serveur web **Apache** ou **Nginx**
- Extensions PHP usuelles : `mysqli`, `gd`, `curl`, `zip`, `intl`, `mbstring`

## Installation

Le projet s'installe comme une instance Dolibarr classique :

1. Cloner le dépôt :
   ```bash
   git clone https://github.com/boriskatetaupemba/gescom.git
   ```
2. Créer une base de données MySQL / MariaDB.
3. Configurer le serveur web pour servir le dossier du projet.
4. Renseigner `conf/conf.php` (identifiants de la base, chemins).
   Un modèle est fourni : `conf/conf.php.example`.
5. Accéder à l'application depuis le navigateur.
6. Activer les modules personnalisés nécessaires puis désactiver/réactiver le
   module REST si son cache de production doit être reconstruit.

> Note : `conf/conf.php` (identifiants de la base de données) est versionné dans
> ce dépôt privé. Adaptez-le à votre environnement de déploiement.

## API REST

Développements sur mesure exposés via l'API REST de Dolibarr :

- **Contexte après login** : `GET /bankauditapi/context`, après le login natif.
- **Factures par compte** : `GET /invoiceplus/byaccounts?account_ids=...`.
- **Factures enrichies** : `GET /invoiceplus`, `/invoiceplus/{id}` et
  `/invoiceplus/warehouse/{warehouse_id}`.
- **Clôture des factures** : routes `/invoiceclosureapi`.
- **BankAudit API** : écritures bancaires par entrepôt
  (`GET /bankauditapi/warehouse/{id}/entries`).
- La catégorie d'une ligne bancaire reste prise en charge par l'API native ;
  l'ancienne différence dans sa PHPDoc était redondante.

Document : [API_Login_Client.md](API_Login_Client.md)

## Documentation

| Document | Sujet |
| --- | --- |
| [specs_module_pos.md](specs_module_pos.md) | Spécifications du module POS PosNova |
| [reporting_banques_caisses.md](reporting_banques_caisses.md) | Rapports banques / caisses |
| [prompt_banques_caisses_dolibarr.md](prompt_banques_caisses_dolibarr.md) | Notes module banques / caisses |
| [Gestion_des_Mouvements_de_Stock.md](Gestion_des_Mouvements_de_Stock.md) | Saisie rapide des mouvements de stock |
| [API_Login_Client.md](API_Login_Client.md) | API de connexion client |
| [CORE_CUSTOMIZATIONS_AUDIT.md](CORE_CUSTOMIZATIONS_AUDIT.md) | Audit et extraction des modifications du cœur |

## Licence

Dolibarr est distribué sous licence **GPL v3+**. Les développements sur mesure de
ce dépôt suivent la même licence, sauf mention contraire.
