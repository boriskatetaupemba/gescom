# MODULE POINT DE VENTE PERSONNALISÉ
## Spécifications Fonctionnelles & Techniques
### Intégration Dolibarr ERP — Architecture Entrepôt / Caisse / Multi-Devises

| Champ | Valeur |
|---|---|
| Version | 1.3 — Final consolidé |
| Date | Juin 2026 |
| Statut | Document de référence principal |
| Domaine | Commerce / ERP Dolibarr |
| Devises | USD & CDF (Franc Congolais) — multi-devises natif |

---

## Table des matières

1. [Vision & Objectifs](#1-vision--objectifs)
2. [Architecture Fonctionnelle](#2-architecture-fonctionnelle)
3. [Interface Utilisateur du POS](#3-interface-utilisateur-du-pos)
4. [Processus de Paiement Multi-Devises](#4-processus-de-paiement-multi-devises)
5. [Gestion des Sessions de Caisse](#5-gestion-des-sessions-de-caisse)
6. [Impact sur le Stock & Transferts](#6-impact-sur-le-stock--transferts)
7. [Gestion des Factures POS & Annulations](#7-gestion-des-factures-pos--annulations)
8. [Rôles & Sécurité](#8-rôles--sécurité)
9. [Impression & Documents](#9-impression--documents)
10. [Reporting & Tableaux de Bord](#10-reporting--tableaux-de-bord)
11. [Intégration avec Dolibarr ERP](#11-intégration-avec-dolibarr-erp)
12. [Fonctionnalités à Valeur Ajoutée](#12-fonctionnalités-à-valeur-ajoutée)
13. [Spécifications Techniques](#13-spécifications-techniques)
14. [Plan d'Implémentation](#14-plan-dimplémentation)
15. [Points d'Attention & Risques](#15-points-dattention--risques)

---

## 1. Vision & Objectifs

Le module Point de Vente personnalisé constitue la couche frontale de la chaîne commerciale intégrée dans Dolibarr. Il orchestre en temps réel les flux de vente, de stock et de trésorerie dans un contexte **bi-devise natif (USD / CDF)**, avec taux de change configurable au quotidien.

> **Architecture centrale : Entrepôt → Caisse/Compte → POS**
> - Chaque Point de Vente (POS) est rattaché à un **Entrepôt unique**
> - Chaque Entrepôt dispose de ses propres **comptes/caisses** activables par POS
> - Un POS doit avoir **au minimum un compte USD et un compte CDF** (les deux devises légales en RDC)
> - Le POS fonctionne dans la **devise de son compte/caisse par défaut** (prix, remises, totaux, tickets)
> - Les prix du catalogue sont **convertis en temps réel** selon le taux du jour affiché
> - La traçabilité financière est garantie par entrepôt, par caisse, par devise et par session

### 1.1 Objectifs métier

- Fluidifier les ventes au comptoir avec une interface moderne, rapide et tactile
- Gérer nativement les deux devises du marché congolais : **USD et CDF**
- Afficher et appliquer le **taux de change du jour** de manière transparente
- Permettre au client de **payer en plusieurs devises** et **rendre la monnaie en plusieurs devises**
- Assurer la concordance stock / ventes / comptabilité en temps réel
- Sécuriser les encaissements par session de caisse horodatée
- Produire des rapports de caisse en USD et CDF avec équivalences
- **Résister aux coupures de courant et de connexion** : la session de caisse survit aux interruptions techniques

---

## 2. Architecture Fonctionnelle

### 2.1 Hiérarchie des entités

| Entité | Cardinalité | Description |
|---|---|---|
| Société / Entreprise | 1 | Racine du système |
| Entrepôt (Warehouse) | N par société | Centre logistique et comptable |
| Compte / Caisse | N par entrepôt | Moyen de paiement avec devise et mode associés |
| Point de Vente (POS) | N par entrepôt | Terminal de vente rattaché à l'entrepôt |
| Session de caisse | N par POS | Période d'ouverture/fermeture d'un POS — indépendante des sessions HTTP |
| Ticket / Facture POS | N par session | Transaction de vente enregistrée — séparée des factures normales Dolibarr |
| Taux de change | 1 par jour | Taux USD/CDF saisi manuellement — fallback sur taux système multi-devises |

### 2.2 Règles de liaison strictes

> - `1 POS → 1 Entrepôt` — liaison obligatoire, non modifiable après création
> - `1 Entrepôt → N Comptes/Caisses` — chaque compte a une devise (USD ou CDF) et un mode de paiement
> - `1 POS → N Comptes activés` — l'admin sélectionne les comptes autorisés sur ce POS (parmi ceux de l'entrepôt)
> - **Obligation** : au moins 1 compte USD + 1 compte CDF actif sur chaque POS
> - `1 POS → 1 Compte par défaut` — la devise de ce compte définit la **devise de travail du POS**
> - Transfert inter-entrepôts : activable/désactivable par POS (voir §6.3)

### 2.3 Configuration des Points de Vente

> Cette section est réservée aux **Administrateurs**. Toute modification est tracée dans le journal d'audit.

| Paramètre | Description / Valeurs |
|---|---|
| **Nom du POS** | Libellé unique (ex : Caisse Centrale Kinshasa) |
| **Entrepôt lié** | Sélection parmi les entrepôts actifs — obligatoire, non modifiable après première vente |
| **Comptes/Caisses actifs** | Sélection multiple parmi les comptes de l'entrepôt — admin uniquement |
| **Compte/Caisse par défaut** | Compte pré-sélectionné à l'ouverture du paiement — **sa devise devient la devise du POS** |
| **Mode de paiement par défaut** | Configuré directement sur le POS (Espèces / Mobile Money / Virement…) |
| **Remise max autorisée** | Plafond en % — tout dépassement nécessite approbation superviseur |
| **Modification des prix** | `AUTORISÉE` / `INTERDITE` — si interdite, le prix catalogue est verrouillé en lecture seule côté serveur |
| **Vente sans stock** | `AUTORISÉE` / `INTERDITE` — si interdite, article à stock nul non vendable (même par superviseur) |
| **Transfert inter-entrepôts** | `ACTIVÉ` / `DÉSACTIVÉ` — si activé, un bouton dédié apparaît sur le POS (voir §6.3) |
| **Utilisateurs autorisés** | Liste de vendeurs habilités sur ce POS |
| **Format d'impression** | `80 mm` (défaut) / `A5` / `A4` |
| **Impression auto** | Impression automatique après validation — ON/OFF |
| **Client par défaut** | CLIENT DE PASSAGE (walk-in) pré-renseigné |
| **Numérotation tickets** | Séquence propre au POS (ex : POS001-2026-00001) — sans trou — générée côté serveur uniquement |
| **Ventes antidatées** | `AUTORISÉES` / `INTERDITES` — si autorisées, date de facture modifiable par le vendeur |
| **Mode hors-ligne** | Buffer local si perte de connexion — ON/OFF |
| **Timeout réservation transfert** | Durée max d'une réservation stock en attente d'approbation (défaut : 24h) |
| **Timeout escalade annulation** | Délai avant escalade admin si superviseur ne répond pas (défaut : 30 min) |

> ⚠️ **Règle de lancement** : Un POS ne peut pas démarrer s'il n'a pas d'entrepôt lié ET au moins un compte/caisse actif. Le système affiche un message d'erreur explicite invitant l'administrateur à compléter la configuration.

---

## 3. Interface Utilisateur du POS

### 3.1 Structure de l'écran principal

L'interface se compose d'une **barre de statut supérieure** permanente et de **deux panneaux principaux** (catalogue à gauche, ticket à droite). L'édition se fait directement dans les lignes du tableau du ticket (voir §3.3).

#### Barre de statut supérieure (toujours visible)

| Zone | Contenu |
|---|---|
| POS & Vendeur | Nom du POS, nom du vendeur connecté, heure de la session |
| **Taux du jour** | 🔶 `1 USD = X.XXX CDF` — grand encadré coloré, mis à jour en temps réel |
| Devise active | Badge coloré indiquant la devise de travail du POS (USD ou CDF) |
| Statut session | Durée de la session, nb de tickets validés |
| Alertes | 🔔 badge rouge si : annulation en attente / remise à approuver / stock faible / transfert à approuver |
| Transfert stock | Bouton 🔄 **Transférer** visible uniquement si transfert inter-entrepôts activé sur le POS |
| Statut connexion | 🟢 En ligne / 🟡 Hors-ligne (mode local actif) |

> **Le taux du jour est le pivot central de toutes les conversions.** Il est saisi par un admin/responsable en début de journée. Si aucun taux n'est saisi, le système utilise automatiquement le **taux configuré dans le module multi-devises de Dolibarr** (avec un indicateur visuel jaune `⚠ Taux système utilisé`). Toute modification du taux en cours de session est tracée et nécessite confirmation.

#### Les deux panneaux principaux

| Panneau | Position | Contenu |
|---|---|---|
| Catalogue produits | Gauche (40%) | Liste live des articles — prix en devise du POS |
| Ticket en cours | Droite (60%) | Tableau lignes éditable inline + en-tête client + pied total |

### 3.2 Catalogue Produits

#### Règles d'affichage

- **Les articles en statut « hors vente » ne sont jamais affichés** dans le catalogue POS, quelle que soit la configuration (ce filtre s'applique aussi au scan code-barres : un article hors vente ne peut pas être ajouté même s'il est scanné)
- Affichage par article : Nom — Référence — Stock (entrepôt du POS) — **Prix converti dans la devise du POS**
  - Devise POS = USD → prix affiché en USD (converti via taux du jour si prix source en CDF)
  - Devise POS = CDF → prix affiché en CDF (conversion inverse)
- Indicateur visuel stock : 🟢 OK | 🟠 Faible (< seuil config.) | 🔴 Nul
- Si **Vente sans stock = INTERDITE** : les articles à stock nul sont grisés et non sélectionnables

#### Recherche & filtres

- Champ de recherche full-text : nom, référence, code-barres (scan USB/Bluetooth)
- Filtres rapides : catégorie, fournisseur, stock disponible uniquement
- Pagination serveur : navigation Préc / Suiv + saut de page

#### Stratégie d'actualisation live (sans rechargement de page)

Le catalogue est **maintenu à jour en temps réel** sans jamais recharger la page du navigateur, grâce à une architecture de mise à jour incrémentale :

```
Stratégie : Polling différentiel + WebSocket (selon infra disponible)

Option A — WebSocket (recommandé) :
  Le serveur pousse un événement aux POS connectés dès qu'un :
    • Prix de produit change
    • Stock d'un article de l'entrepôt est modifié (vente, réception, transfert)
    • Taux de change est mis à jour
    • Article passe en/hors vente
  Le client met à jour uniquement la ligne concernée (patch partiel du DOM).

Option B — Polling court (fallback) :
  Le POS envoie une requête légère toutes les 30 secondes :
    GET /api/pos/catalog/changes?since={last_sync_timestamp}&warehouse={id}
  Le serveur retourne uniquement les articles modifiés depuis le dernier sync.
  Le client applique le diff (mise à jour prix, stock, statut) sans rechargement.

Dans les deux cas :
  - L'utilisateur voit une animation discrète (flash vert sur la ligne mise à jour)
  - Un toast non-bloquant indique "Catalogue mis à jour" si > 3 articles changent
  - Le bouton Actualiser reste disponible pour forcer une synchro manuelle complète
```

#### Dialog d'information produit (double-clic)

Au **double-clic** sur une ligne du catalogue, un **dialog moderne** s'affiche avec les informations utiles au vendeur. Ce dialog est strictement limité aux données nécessaires à l'acte de vente.

**Champs affichés :**

| Champ | Source Dolibarr |
|---|---|
| Photo du produit (si disponible) | `llx_product` (fichier joint) |
| Référence interne | `llx_product.ref` |
| Nom complet | `llx_product.label` |
| Code-barres | `llx_product.barcode` |
| Catégorie | `llx_categorie` |
| Description courte | `llx_product.description` |
| Prix de vente (devise du POS, converti au taux du jour) | `llx_product_price` |
| Remise promotionnelle en cours (si applicable) | `llx_product_pricerules` |
| Unité de vente | `llx_product.fk_unit` |
| **Tableau des stocks par entrepôt** | `llx_product_stock` |

**Tableau des stocks par entrepôt :**

| Entrepôt | Stock disponible | Stock réservé | Stock physique |
|---|---|---|---|
| Entrepôt Principal (POS actuel) ⭐ | **42** | 3 | 45 |
| Entrepôt Secondaire | 12 | 0 | 12 |
| Dépôt Nord | 0 | 0 | 0 |

- Le stock de l'entrepôt du POS actuel est mis en surbrillance (⭐)
- Ce tableau permet au vendeur d'informer le client sur la disponibilité globale et d'orienter un éventuel transfert
- Si transfert inter-entrepôts activé et utilisateur habilité : bouton **🔄 Demander un transfert depuis cet entrepôt** directement dans le dialog

> ⛔ **Champs explicitement exclus du dialog POS :**
> - Prix d'achat (`cost_price`)
> - Fournisseur principal (`fk_soc`)
> - Marge brute / marge nette
> - Tout champ financier interne (coûts, tarifs fournisseur, conditions d'achat)
>
> Ces données restent accessibles uniquement depuis le back-office Dolibarr avec les droits appropriés. Le verrou est appliqué côté serveur — l'API ne retourne pas ces champs aux endpoints POS, quelle que soit la requête.

**Pied du dialog :**
- Bouton **✕ Fermer**
- Bouton **➕ Ajouter au ticket** (raccourci direct)

### 3.3 Tableau de ticket — Édition inline des lignes

L'édition se fait **directement dans le tableau du ticket**, pour une expérience plus fluide et moins de clics.

#### Comportement à la sélection d'un article

1. L'utilisateur **clique** (ou scanne) un article dans le catalogue
2. Une **nouvelle ligne** apparaît en **haut du tableau** du ticket, en mode édition (mise en évidence visuelle — fond coloré, bordure active)
3. La ligne est pré-remplie avec les données du produit

#### Structure d'une ligne en mode édition

| Colonne | Comportement en édition |
|---|---|
| **Produit** | `<select>` avec recherche — permet de changer l'article sans supprimer la ligne |
| **Prix** | Champ numérique — éditable si `Modification des prix = AUTORISÉE`, sinon verrouillé 🔒 côté serveur |
| **Qté** | Champ numérique + boutons **−** et **+** tactiles |
| **Remise** | Champ en % OU en montant (bascule) — indicateur rouge si > plafond |
| **Total** | Recalcul instantané — lecture seule |
| **Actions** | ✅ **Entrée** / bouton **Ajouter** → valide la ligne \| ✖ **Échap** / bouton **Retirer** → annule la ligne |

#### Règles UX de la ligne éditable

- **Touche `Entrée`** : valide et confirme la ligne — elle passe en mode lecture
- **Touche `Échap`** : annule la ligne en cours d'édition
- La **navigation au clavier** (`Tab`) circule entre Prix → Qté → Remise → bouton Ajouter
- Une seule ligne peut être en mode édition à la fois
- Si le vendeur clique sur une ligne déjà validée → elle repasse en mode édition
- Si `Modification des prix = INTERDITE` : la colonne Prix est affichée en grisé avec icône 🔒 — le verrou est côté serveur, pas uniquement côté UI
- Si remise > plafond : le champ remise vire au rouge, un tooltip s'affiche, validation bloquée jusqu'à approbation superviseur

#### En-tête du ticket

- **Client** : champ de recherche intelligente (nom, téléphone, code) + bouton « + Nouveau client »
  - Par défaut : CLIENT DE PASSAGE
  - Si ventes antidatées = INTERDITES : date = aujourd'hui, non modifiable
  - Si ventes antidatées = AUTORISÉES : date sélectionnable (calendrier), avec badge `📅 ANTIDATÉE` si date ≠ aujourd'hui

#### Pied du ticket

- Sous-total, remises, **TOTAL dans la devise du POS**
- Ligne équivalence : `≈ X.XXX CDF au taux 2.900` (ou USD si devise POS = CDF)
- Option ☑ Imprimer après validation
- Boutons : **🗑 Vider le ticket** | **💳 Valider & Encaisser**

---

## 4. Processus de Paiement Multi-Devises

### 4.1 Formulaire de paiement — Principes

Le formulaire de paiement est le cœur de l'expérience caisse. Il doit être **intuitif, rapide et guider le vendeur** sans friction, même pour des situations complexes (multi-comptes, multi-devises, rendu en plusieurs monnaies).

**Comportement par défaut à l'ouverture du formulaire :**
1. Montant total de la facture affiché dans la **devise du POS** + équivalence dans l'autre devise
2. **Proposition automatique** : le total est pré-rempli sur le **compte par défaut** avec le **mode de paiement par défaut configuré sur le POS**
3. Le vendeur peut valider immédiatement (cas simple) ou décomposer (cas multi-devises)

### 4.2 Répartition multi-comptes / multi-devises

Le formulaire liste tous les **comptes actifs du POS** organisés par devise. Chaque compte affiche son mode de paiement et permet la saisie du montant encaissé dans sa devise.

```
╔══════════════════════════════════════════════════════════════╗
║  TOTAL FACTURE : 125,00 USD  │  ≈ 362.500 CDF (taux 2.900) ║
╠══════════════════════════════════════════════════════════════╣
║  COMPTES USD                                                 ║
║  ┌──────────────────────────────────────────────────────┐   ║
║  │ 💵 Caisse USD Espèces        Mode : [Espèces     ▾] │   ║
║  │    Encaissé  : [  100,00 ] USD                       │   ║
║  └──────────────────────────────────────────────────────┘   ║
║                                                              ║
║  COMPTES CDF                                                 ║
║  ┌──────────────────────────────────────────────────────┐   ║
║  │ 📱 Caisse CDF M-PESA         Mode : [Mobile Money ▾]│   ║
║  │    Encaissé  : [ 72.500 ] CDF  ≈ 25,00 USD          │   ║
║  │    Réf. transaction : [MP-2026-XXXXX         ]       │   ║
║  └──────────────────────────────────────────────────────┘   ║
║                                                              ║
║  RESTE À COUVRIR : 0,00 USD  ✅                              ║
║  TOTAL ENCAISSÉ  : 125,00 USD (100 USD + 72.500 CDF)        ║
╠══════════════════════════════════════════════════════════════╣
║  MONNAIE À RENDRE AU CLIENT                                  ║
║  ┌──────────────────────────────────────────────────────┐   ║
║  │ Caisse USD Espèces : [   0,00 ] USD  ✏️             │   ║
║  │ Caisse CDF M-PESA  : [   0   ] CDF  ✏️             │   ║
║  └──────────────────────────────────────────────────────┘   ║
║  💡 Rendu total : 0,00 USD + 0 CDF                          ║
╚══════════════════════════════════════════════════════════════╝
                              [ ✅ CONFIRMER LE PAIEMENT ]
```

#### Logique de répartition assistée

- Dès que le vendeur saisit un montant sur un compte, le **solde restant** est calculé et proposé automatiquement sur le/les autre(s) compte(s) actif(s), en tenant compte de la conversion via le taux du jour
- Si plusieurs comptes de la même devise sont actifs : la proposition va sur le compte par défaut de cette devise
- Le vendeur reste libre de modifier n'importe quel montant à tout moment
- Indicateur visuel temps réel : 🔴 Manquant | 🟡 Couvert exactement | 🟢 Excédent → rendu calculé

#### Gestion du rendu de monnaie

- Le rendu est calculé automatiquement : `montant encaissé par compte − part du total imputée à ce compte`
- Les champs de rendu sont **éditables** ✏️ : le vendeur peut ajuster la répartition du rendu
- Le rendu peut être distribué sur **plusieurs devises** selon ce que le vendeur a en caisse
- Le système vérifie **côté serveur** que le rendu total (converti) ≤ excédent encaissé
- Le rendu final est affiché clairement avant confirmation et **imprimé sur le ticket**
- Boutons rapides de billets : `+1$` `+5$` `+10$` `+20$` `+50$` `+100$` | `+1.000 FC` `+5.000 FC` `+10.000 FC`

### 4.3 Modes de paiement par compte

| Type | Exemples | Saisie complémentaire |
|---|---|---|
| Espèces (USD) | Billets USD | Aucune |
| Espèces (CDF) | Billets CDF | Aucune |
| Mobile Money | M-PESA, Airtel, Orange Money | Référence transaction (obligatoire) |
| Virement bancaire | Rawbank, Equity, TMB | N° virement (obligatoire) — rapprochement J+1 |
| Chèque | Chèque bancaire | N° chèque + banque (obligatoire) |
| Crédit client | Compte client Dolibarr | Solde vérifié automatiquement avant validation |

### 4.4 Règles de validation du paiement

- ✅ **Montant total couvert** : somme de tous les comptes (convertis en devise POS) ≥ total facture
- ✅ **Au moins un compte** avec un montant > 0 renseigné
- ✅ **Session ouverte** sur le POS
- ✅ **Taux de change** : si aucun taux n'a été saisi pour la journée → le système utilise automatiquement le **taux configuré dans le module multi-devises de Dolibarr** (indicateur visuel `⚠ Taux système en vigueur : 1 USD = X.XXX CDF`)
- ✅ **Rendu vérifié côté serveur** : rendu total édité ≤ excédent encaissé
- ❌ **POS sans entrepôt ou sans caisse active** : démarrage impossible
- ❌ **Remise > plafond** : blocage sauf approbation superviseur tracée
- ❌ **Prix modifié sans autorisation** : vérification côté serveur — pas uniquement côté UI
- ⚠️ **Stock insuffisant** : alerte — vendeur bloqué si `Vente sans stock = INTERDITE`, peut forcer si AUTORISÉE (tracé dans l'audit)

---

## 5. Gestion des Sessions de Caisse

### 5.1 Principe fondamental — Session caisse vs session web

> ⚠️ **Règle critique dans le contexte de coupures fréquentes (courant / connexion) :**
>
> La **session de caisse est totalement découplée de la session HTTP/navigateur**.
> Une coupure de courant, de réseau, ou une fermeture accidentelle du navigateur **ne ferme jamais la session de caisse**.
> La session de caisse ne se ferme **que par action explicite du vendeur** (bouton "Fermer la caisse") ou par intervention d'un Administrateur depuis le back-office.

### 5.2 Mécanisme de persistance de session

À l'ouverture de la session de caisse, un **token de session POS** distinct du token Dolibarr est émis :

| Propriété | Valeur |
|---|---|
| Durée de vie | 12 heures (ou jusqu'à fermeture explicite) |
| Stockage client | localStorage + cookie HttpOnly sécurisé |
| Non invalidé par | Fermeture navigateur, coupure réseau, déconnexion HTTP |
| Invalidé par | Fermeture explicite de session OU expiration des 12h OU action admin |

### 5.3 Comportement selon la situation de reconnexion

| Situation | Comportement du système |
|---|---|
| Fermeture navigateur / crash | Session de caisse reste **ouverte côté serveur** |
| Coupure réseau temporaire | Mode hors-ligne activé automatiquement (Service Worker + IndexedDB) |
| Reconnexion après interruption | Système détecte le token de session — **reprend la session sans redemander l'ouverture de caisse** |
| Reprise de session | Tous les tickets de la session sont restaurés, compteurs maintenus, catalogue resynchronisé |
| Même POS, autre vendeur tente de s'y connecter | Système bloque et affiche : *"Session active — [Nom vendeur] depuis [HH:MM] — contactez un superviseur"* |
| Session restée ouverte > 12h sans activité | Alerte admin — clôture forcée possible depuis le back-office uniquement |

### 5.4 Gestion des tickets créés hors-ligne

```
1. Réseau disponible    → Ticket envoyé au serveur en temps réel → numéroté côté serveur
2. Réseau indisponible  → Ticket stocké localement (IndexedDB) — statut : PENDING_SYNC
                          Indicateur 🟡 visible sur le ticket local
3. Reconnexion          → File d'attente envoyée dans l'ordre chronologique
                          Numérotation attribuée côté serveur (jamais côté client)
4. Conflit détecté      → Alerte vendeur / superviseur pour arbitrage
   (ex: stock épuisé    Ticket mis en attente — vendeur choisit : confirmer, modifier, annuler
    entre temps)
```

> ⚠️ La **numérotation des tickets est toujours générée côté serveur**, jamais côté client, même en mode hors-ligne. Les tickets hors-ligne reçoivent leur numéro définitif à la synchronisation, garantissant la séquence sans trou exigée fiscalement.

### 5.5 Cycle de vie d'une session

```
OUVERTURE  → Identification vendeur + fonds initial par caisse (USD et CDF séparément)
             Taux du jour vérifié (manuel ou système)
EN COURS   → Ventes, encaissements multi-devises, remises, annulations, transferts
             Interruptions possibles (réseau, courant) — session maintenue côté serveur
VERROUILLÉE→ Vendeur met en pause sans fermer (PIN pour déverrouiller)
FERMETURE  → Action explicite du vendeur
             Comptage physique par caisse/devise
             Comparaison attendu/réel — écarts signalés
CLÔTURÉE   → Session verrouillée, Z-Report généré (format du POS), données → comptabilité
```

### 5.6 Données enregistrées par session

| Donnée | Description |
|---|---|
| ID Session | Identifiant unique (POS + date + séquence) |
| POS lié | Référence au point de vente |
| Entrepôt lié | Hérité du POS |
| Vendeur | Utilisateur ayant ouvert la session |
| Taux du jour appliqué | Taux USD/CDF — saisi ou issu du système multi-devises |
| Source du taux | `MANUEL` ou `SYSTÈME` |
| Date/heure ouverture | Horodatage précis |
| Fonds initial USD | Espèces USD au démarrage |
| Fonds initial CDF | Espèces CDF au démarrage |
| Nb reconnexions | Compteur d'interruptions/reprises de session |
| Total ventes (devise POS) | Cumul des tickets validés |
| Total par compte/caisse | Détail encaissements par compte, par devise, par mode |
| Total remises | Cumul des remises accordées |
| Total rendu monnaie | Cumul rendu clients par devise |
| Nb transactions | Nombre de tickets validés |
| Nb annulations | Tickets annulés (approuvés) dans la session |
| Nb tickets hors-ligne synchros | Tickets créés hors connexion et synchronisés |
| Fonds final attendu (USD/CDF) | Calculé par le système |
| Fonds final réel (USD/CDF) | Saisi par le vendeur à la fermeture |
| Écart USD / Écart CDF | Différence (+ = excédent, − = manquant) |
| Date/heure fermeture | Horodatage de clôture |
| Statut | `OUVERTE` / `FERMÉE` / `ANNULÉE` |

### 5.7 Contrôles à la fermeture

- Alerte si écart (USD ou CDF) dépasse un seuil configurable par devise
- Z-Report auto-généré dans le **format configuré pour ce POS** (80 mm / A5 / A4)
- Tickets hors-ligne non synchronisés : synchronisation forcée avant autorisation de fermeture
- Tickets non validés : proposition d'annulation ou de report sur prochaine session
- Aucune vente possible sur session fermée

---

## 6. Impact sur le Stock & Transferts

### 6.1 Mouvements automatiques

- Validation ticket → **sortie de stock** sur l'entrepôt du POS pour chaque ligne
- Sortie enregistrée avec : date, heure, référence ticket, quantité, utilisateur, POS
- Retour/avoir approuvé → **entrée de stock** sur le même entrepôt
- Annulation ticket approuvée → **ré-entrée de stock** automatique

### 6.2 Alertes stock

| Niveau stock | Comportement (Vente sans stock = INTERDITE) | Comportement (Vente sans stock = AUTORISÉE) |
|---|---|---|
| Stock normal | Vente libre 🟢 | Vente libre 🟢 |
| Stock faible | Alerte 🟠 — vente possible | Alerte 🟠 — vente possible |
| Stock nul | Article grisé — vente bloquée ❌ | Badge 🔴 — warning — vente possible avec confirmation |
| Stock négatif | Impossible (bloqué) | Possible si confirmé (tracé dans l'audit) |

### 6.3 Transfert inter-entrepôts depuis le POS

Le transfert est **activable/désactivable** par POS dans la configuration (§2.3).

#### Règles fondamentales

> - Un utilisateur **ne peut initier un transfert que depuis l'entrepôt auquel son POS est rattaché**
> - Le champ `wh_from` (entrepôt source) est **automatiquement fixé à l'entrepôt du POS** — non modifiable par l'utilisateur
> - Ce verrou est appliqué **côté serveur** — l'API refuse toute demande avec un `wh_from` différent de l'entrepôt du POS de l'initiateur
> - Un stock en attente d'approbation est mis en **RÉSERVÉ** : il n'est pas disponible à la vente ni à un autre transfert

#### Activation et visibilité

- Si **ACTIVÉ** : un bouton **🔄 Transférer un stock** apparaît dans la barre de statut supérieure du POS
- Si **DÉSACTIVÉ** : le bouton est masqué — aucune fonctionnalité de transfert n'est accessible depuis ce POS

#### Privilège requis

| Profil | Initier un transfert | Approuver un transfert |
|---|---|---|
| Caissier | ❌ Non autorisé | ❌ Non autorisé |
| Superviseur POS | ✅ Oui (entrepôt de son POS) | ✅ Oui (entrepôt de son POS) |
| Responsable entrepôt | ✅ Oui (son entrepôt) | ✅ Oui (son entrepôt) |
| Administrateur | ✅ Oui (tous) | ✅ Oui (tous) |

#### Workflow d'approbation du transfert

```
1. INITIATEUR (Superviseur/Responsable — entrepôt source)
   → Ouvre le dialog de transfert
   → Entrepôt source : automatiquement renseigné (POS actuel) — non modifiable
   → Sélectionne : produit, entrepôt destination, quantité, motif
   → Soumet la demande
   Statut : ⏳ EN ATTENTE D'APPROBATION

   Le stock sélectionné passe en RÉSERVÉ côté source (quantité disponible réduite)
   Timeout : si aucune décision sous [X]h configurées → réservation libérée automatiquement + alerte

2. NOTIFICATION TEMPS RÉEL
   → Alerte envoyée aux utilisateurs habilités de l'entrepôt destinataire
   → Badge 🔔 dans leur barre de statut POS + notification push si activée
   → Accessible aussi depuis la page "Transferts" du POS

3. APPROBATEUR (Superviseur/Responsable — entrepôt destinataire)
   → Consulte la demande : initiateur, produit, quantité, entrepôt source, motif
   → Voit l'impact concret sur son propre stock (stock actuel → stock après transfert)
   → Deux actions :
      ✅ APPROUVER  → Le transfert est exécuté (étape 4a)
      ❌ REFUSER    → Motif obligatoire (étape 4b)

4a. APPROUVÉ
   → Sortie de stock effectuée sur l'entrepôt source
   → Entrée de stock enregistrée sur l'entrepôt destinataire
   → Mouvement de stock créé dans Dolibarr (traçable, horodaté)
   → Réservation levée
   → Notification à l'initiateur : "Transfert approuvé et effectué"
   → Catalogue live mis à jour sur les deux entrepôts
   → Trace complète dans le journal d'audit (initiateur, approbateur, dates, quantité)

4b. REFUSÉ
   → Réservation levée — stock remis disponible sur l'entrepôt source
   → Notification à l'initiateur avec le motif du refus
   → Trace dans le journal d'audit

ANNULATION PAR L'INITIATEUR
   → Possible tant que statut = EN ATTENTE
   → Réservation libérée immédiatement
   → Trace dans le journal d'audit
```

#### Dialog de transfert (initiateur)

```
┌──────────────────────────────────────────────────────┐
│  🔄  DEMANDE DE TRANSFERT DE STOCK                   │
├──────────────────────────────────────────────────────┤
│  Entrepôt source  : [Entrepôt Principal KIN   ] 🔒   │
│  (entrepôt du POS — verrouillé côté serveur)         │
│                                                      │
│  Produit          : [Recherche ou scan...     ]      │
│  Stock disponible : 42 unités  (dont 5 réservés)     │
│  Qté transférable : 37 unités                        │
│                                                      │
│  Entrepôt dest.   : [Sélectionner...          ▾]    │
│  Quantité         : [    10    ]  unités              │
│                                                      │
│  Motif            : [                         ]      │
│                     (obligatoire)                    │
├──────────────────────────────────────────────────────┤
│  ⚠ Ce transfert sera effectif après approbation      │
│    d'un responsable de l'entrepôt destinataire.      │
│    Timeout d'expiration : 24h                        │
├──────────────────────────────────────────────────────┤
│           [ ✕ Annuler ]  [ 📤 Envoyer la demande ]   │
└──────────────────────────────────────────────────────┘
```

#### Dialog d'approbation (approbateur — entrepôt destinataire)

```
┌──────────────────────────────────────────────────────┐
│  🔔  DEMANDE DE TRANSFERT À APPROUVER                │
├──────────────────────────────────────────────────────┤
│  De        : Entrepôt Principal KIN                  │
│  Vers      : Entrepôt Secondaire (votre entrepôt)    │
│  Produit   : CHAUSSURE DE SEC. ROCK WINNER N°45      │
│  Référence : 6067                                    │
│  Quantité  : 10 unités                               │
│  Demandé   : Jean LUKA — 23/06/2026 à 14:32          │
│  Motif     : Rupture stock prévue demain             │
├──────────────────────────────────────────────────────┤
│  Stock actuel chez vous : 12 unités                  │
│  Stock après transfert  : 22 unités                  │
├──────────────────────────────────────────────────────┤
│  [ ❌ Refuser (motif obligatoire) ]  [ ✅ Approuver ] │
└──────────────────────────────────────────────────────┘
```

### 6.4 Page "Mes Transferts" — Suivi depuis le POS

Un menu dédié **"Transferts"** dans l'interface POS permet à chaque utilisateur habilité de suivre l'état de ses demandes et d'approuver celles qui lui sont soumises.

#### Onglets de la page

| Onglet | Contenu |
|---|---|
| ⏳ En attente | Transferts initiés par l'utilisateur, pas encore traités — bouton "Annuler" disponible |
| 🔔 À approuver | Transferts en attente d'approbation sur l'entrepôt de l'utilisateur |
| ✅ Approuvés | Transferts validés — mouvement de stock effectué |
| ❌ Refusés | Transferts rejetés avec motif de refus |
| 📦 Historique | Vue consolidée — filtre par date / produit / initiateur |

#### Alertes temps réel liées aux transferts

- Le vendeur/superviseur reçoit une notification 🔔 dans la barre de statut dès qu'une décision est prise sur l'un de ses transferts
- L'approbateur reçoit une alerte dès qu'une nouvelle demande lui est soumise
- Depuis le back-office Dolibarr : un menu dédié liste toutes les demandes en attente, accessible aux responsables et admins

---

## 7. Gestion des Factures POS & Annulations

### 7.1 Séparation des factures POS et des factures normales Dolibarr

> ⚠️ **Règle absolue : les tickets POS ne doivent jamais apparaître dans la liste des factures clients standard de Dolibarr.**

#### Architecture de séparation

- Chaque ticket POS validé crée bien une `llx_facture` dans Dolibarr (pour la cohérence comptable), mais est systématiquement marqué avec `origin = 'pos'` et `fk_pos_ticket = [id]`
- Les listes de factures clients standard Dolibarr filtrent automatiquement et excluent les factures d'origine POS
- Les factures POS sont accessibles **uniquement** via les interfaces dédiées POS

#### Menu "Factures POS" dans Dolibarr

Un menu propre est ajouté dans la barre de navigation Dolibarr :

| Élément | Description |
|---|---|
| **Emplacement** | Menu principal Dolibarr → section POS → "Factures POS" |
| **Accès vendeur** | Tickets de ses sessions uniquement |
| **Accès superviseur** | Tous les tickets de son entrepôt |
| **Accès admin/comptable** | Tous les tickets, tous entrepôts |
| **Fonctionnalités** | Consultation, ré-impression, recherche par numéro / client / montant / date |

> Ce menu est le **seul point d'entrée** pour consulter les factures issues du POS. Les vendeurs n'ont pas accès aux modules comptables standard de Dolibarr.

### 7.2 Ventes antidatées (backdating)

| Config POS | Comportement |
|---|---|
| **Ventes antidatées = INTERDITES** (défaut) | Date = aujourd'hui, non modifiable par le vendeur |
| **Ventes antidatées = AUTORISÉES** | Date modifiable — calendrier sélectionnable |

- Paramètre modifiable uniquement par un **Administrateur**
- Toute facture antidatée est marquée `📅 ANTIDATÉE` dans Dolibarr et dans tous les rapports
- Limite de période recommandée : max J-7 (au-delà, double validation obligatoire)
- Trace d'audit : utilisateur, date réelle de saisie, date de la facture, motif

### 7.3 Workflow d'annulation de facture

```
1. VENDEUR       → Clique « Demander annulation » sur le ticket
                   Sélectionne un motif (liste) + champ libre
                   Statut ticket : ⏳ ANNULATION EN ATTENTE

2. NOTIFICATION  → Alerte temps réel aux superviseurs/admins du POS
                   (badge rouge dans barre de statut + notification push si activée)

3. SUPERVISEUR   → Consulte la demande : vendeur, ticket, montant, motif
                   → ✅ APPROUVER  |  ❌ REFUSER (motif obligatoire)
                   Timeout : si aucune réponse sous 30 min → escalade automatique vers l'admin

4a. APPROUVÉ     → Ticket annulé — avoir créé dans Dolibarr
                   Stock ré-intégré sur l'entrepôt
                   Remboursement enregistré sur le(s) compte(s) concerné(s)
                   Trace complète dans le journal d'audit

4b. REFUSÉ       → Ticket reste valide — vendeur notifié avec le motif du refus
```

**Motifs d'annulation (configurables) :**
Erreur de produit | Erreur de quantité | Erreur de prix | Client a changé d'avis | Doublon | Autre (champ libre obligatoire)

### 7.4 Avoir et remboursement

- Avoir créé automatiquement dans Dolibarr à l'approbation
- Remboursement en espèces (caisse originale) ou crédit client
- L'avoir peut être utilisé sur une prochaine vente du même client
- L'avoir apparaît dans la page "Factures POS" — pas dans les avoirs clients standard

---

## 8. Rôles & Sécurité

### 8.1 Profils utilisateurs

| Profil | Permissions POS | Restrictions |
|---|---|---|
| **Caissier** | Vendre, encaisser, imprimer, demander annulation, consulter ses factures POS | Remise plafonnée — prix verrouillé si config — ne voit que son POS — pas de transfert stock |
| **Superviseur POS** | Caissier + approuver annulations, forcer remise, corriger ligne, initier/approuver transfert stock, voir toutes sessions de son entrepôt | Limité à son/ses entrepôts |
| **Responsable entrepôt** | Superviseur + config caisses, ouv/ferm. session, rapports, taux du jour, transfert stock | Limité à son entrepôt |
| **Administrateur** | Accès complet — config POS, caisses, paramètres, taux, tous entrepôts, fermeture session forcée | Aucune restriction |
| **Comptable** | Consultation rapports, export, rapprochement — lecture seule — accès page Factures POS en lecture | Pas d'accès caisse frontale |

### 8.2 Règles de sécurité

- Authentification obligatoire avant ouverture de session (login + PIN ou mot de passe)
- Un caissier ne peut pas ouvrir deux sessions simultanées sur deux POS différents
- Remise > plafond → workflow d'approbation (superviseur confirme avec son PIN unique — pas de PIN partagé)
- Annulation de ticket → motif obligatoire + approbation superviseur
- Modification de prix → vérification **côté serveur** — pas uniquement côté UI
- Vente stock nul → bloquée si `Vente sans stock = INTERDITE` — vérification côté serveur
- Taux du jour → saisie réservée aux responsables/admins — modification en session tracée
- Transfert stock → entrepôt source fixé côté serveur au POS de l'initiateur
- Prix d'achat, fournisseur, marges → **non retournés par l'API POS**, quelle que soit la requête
- Factures POS → filtrées hors des listes Dolibarr standard côté serveur
- **Journal d'audit immuable** : toute action tracée (qui, quoi, quand, avant/après)
- Activation/désactivation des paramètres du POS : admin uniquement

### 8.3 Verrouillage de session (pause caisse)

- Vendeur verrouille son POS sans fermer la session (pause)
- Déverrouillage par PIN — aucune vente possible sur POS verrouillé
- Session continue de courir et d'être tracée
- En cas de départ imprévu (coupure courant), le POS reste verrouillé à la reconnexion — seul le vendeur ou un admin peut reprendre

---

## 9. Impression & Documents

### 9.1 Formats supportés

| Format | Usage | Particularités |
|---|---|---|
| **80 mm** (défaut) | Ticket thermique rapide | ESC/POS — imprimante thermique — sans marge |
| **A5** | Ticket semi-professionnel | PDF ou laser — devis, client régulier |
| **A4** | Facture professionnelle | PDF avec logo, en-tête légal, QR code |

Le **format par défaut est le 80 mm** (petit ticket thermique). Il est configurable par POS dans le back-office.

### 9.2 Contenu des documents imprimés

**Ticket 80 mm :**
```
================================
    [NOM SOCIÉTÉ / LOGO réduit]
================================
POS : Caisse Centrale KIN
Date : 23/06/2026  14:32
Ticket : POS001-2026-00847
Vendeur : Jean LUKA
--------------------------------
CLIENT DE PASSAGE
--------------------------------
PRODUIT         QTÉ   TOTAL
Paint White...    2   70.110 FC
Chaussure Sec.    1   35.055 FC
--------------------------------
SOUS-TOTAL       105.165 CDF
REMISE (2%)        2.103 CDF
TOTAL            103.062 CDF
  ≈ 35,54 USD (taux 2.900)
================================
PAIEMENT
Espèces CDF     120.000 CDF
RENDU            16.938 CDF
================================
   Merci pour votre achat !
================================
```

**Facture A4 / A5 :**
- En-tête : logo, coordonnées société, N° RCCM, N° Impôt
- Corps : tableau produits (prix unitaire, qté, remise, total ligne)
- Pied : sous-total, remises, total TTC devise POS + équivalence, taux du jour
- Paiement détaillé par compte/devise/mode + rendu de monnaie par devise
- Source du taux (`MANUEL` ou `SYSTÈME`) + valeur du taux
- Badge `ANTIDATÉE` si applicable
- Zone signature/cachet sur A4
- QR code de vérification (optionnel)

### 9.3 Configuration impression par POS

- Format configuré dans le back-office POS par l'admin
- Impression auto : ON/OFF — format défini dans config
- Re-impression : session en cours (vendeur) | toutes sessions (superviseur)
- **Archive PDF systématique** : chaque ticket est archivé en PDF dans Dolibarr, indépendamment de l'impression physique

---

## 10. Reporting & Tableaux de Bord

### 10.1 Rapport de session — Z-Report

- Généré **automatiquement** à la fermeture de chaque session
- **Format configurable par POS** : 80 mm / A5 / A4
- Contenu :
  - Résumé session (POS, vendeur, heures, durée, nb reconnexions)
  - Taux appliqué + source (Manuel / Système)
  - Nb transactions, nb annulations, nb tickets hors-ligne synchronisés
  - Top 10 produits vendus
  - Encaissements par compte, par devise, par mode
  - Total remises, total rendu monnaie (par devise)
  - Fonds initial / final attendu / réel / écart — par devise
  - Nb transferts stock effectués (si activé)
  - Zone signature vendeur + responsable
- Archivage PDF automatique lié à la session

### 10.2 Rapports de synthèse

| Rapport | Périmètre / Fréquence |
|---|---|
| CA par POS | Journalier / Hebdo / Mensuel / Annuel — USD et CDF |
| CA par entrepôt consolidé | Multi-POS d'un même entrepôt |
| Encaissements par caisse | Par devise, mode, période |
| Remises accordées | Par vendeur, POS, produit |
| Annulations | Liste avec motifs, approbateurs, impact stock/CA |
| Ventes antidatées | Liste des factures à date passée + contexte |
| Sessions de caisse | Statut, écarts USD/CDF, vendeurs, nb reconnexions |
| Top produits vendus | Qtés ou CA — par POS ou global |
| Analyse stock vs ventes | Rotation par entrepôt |
| Transferts inter-entrepôts | Historique par POS, par produit, par utilisateur, par statut |
| Modifications de prix | Liste des lignes où le prix a été modifié manuellement |
| Performance vendeur | CA, tickets, remises, annulations par vendeur et session |
| Évolution taux USD/CDF | Historique taux — source manuel vs système |
| Tickets hors-ligne | Nombre, valeur, délai de synchronisation |
| Journal des transactions | Audit complet — export CSV / Excel / PDF |

### 10.3 Tableau de bord temps réel

- CA du jour par POS et entrepôt — USD et CDF (actualisation toutes les 5 min)
- Taux du jour en vigueur avec indicateur source (Manuel ✅ / Système ⚠)
- Sessions ouvertes (vendeur, heure, CA en cours)
- Alertes actives : 🔴 Stock faible | 🟡 Remise à approuver | 🔔 Annulation en attente | 🔄 Transfert en attente
- Comparaison J vs J-1 et M vs M-1
- POS hors-ligne en cours : liste des terminaux sans connexion active

---

## 11. Intégration avec Dolibarr ERP

### 11.1 Modules Dolibarr impactés

| Module Dolibarr | Interaction POS |
|---|---|
| Factures clients (`invoice`) | Ticket validé → facture Dolibarr statut Payée — marquée `origin=pos` — exclue des listes standard |
| Paiements (`payment`) | Encaissement par compte/devise — un paiement par compte utilisé |
| Produits/Services (`product`) | Catalogue POS — filtre `hors vente` exclu — prix convertis — prix d'achat/fournisseur non exposés |
| Stock/Entrepôts (`stock`) | Sorties auto à la validation — entrées sur annulation/retour |
| Mouvements de stock (`stock_mouvement`) | Transferts inter-entrepôts depuis le POS |
| Tiers/Clients (`thirdparty`) | Recherche client, crédits, avances |
| Banques/Caisses (`bank`) | Comptes USD et CDF par entrepôt |
| Comptabilité (`accounting`) | Écritures par devise — plan comptable multi-devises |
| Utilisateurs (`user`) | Droits et profils POS |
| Multi-devises (`multicurrency`) | **Taux système de fallback** si aucun taux journalier saisi |

### 11.2 Flux de données — ticket validé

1. Ticket validé → **Facture Dolibarr** (statut : Payée, devise POS, `origin=pos`)
2. Lignes ticket → Lignes facture (références, quantités, prix, remises)
3. Encaissements → **Paiements Dolibarr** par compte/caisse/devise
4. Stock → **Sorties** sur l'entrepôt du POS
5. Comptabilité → Écritures selon plan comptable + conversion si multi-devises
6. Session fermée → Z-Report archivé + données transmises au module Banques/Caisses

### 11.3 Synchronisation & Cohérence

- **Transactions atomiques** : ticket validé = toutes opérations ou rollback complet
- Verrou optimiste sur le stock — prévient la survente en multi-POS
- Catalogue live : WebSocket ou polling différentiel — mise à jour sans rechargement
- Numérotation séquentielle sans trou — générée **côté serveur uniquement**
- File d'attente hors-ligne → synchronisation à la reconnexion avec détection de conflits
- Cohérence multi-POS : stratégie de verrous avec retry automatique et alerte si conflit non résolu

---

## 12. Fonctionnalités à Valeur Ajoutée

### 12.1 🔍 Scan code-barres / QR code

- Scanner USB, Bluetooth (douchette) ou caméra (mode tablette)
- Scan → ajout immédiat au ticket (quantité 1, éditable)
- Scan d'un article hors vente → bloqué avec message explicite (même règle que le catalogue)
- Scan d'un ticket existant → ré-impression ou consultation rapide

### 12.2 💰 Calculateur de rendu intelligent

- Boutons billets rapides : `+1$` `+5$` `+10$` `+20$` `+50$` `+100$` | `+1.000 FC` `+5.000 FC` `+10.000 FC`
- Optimisation du rendu : combinaison de devises minimisant les coupures
- Rendu éditable et distribuable en plusieurs devises
- Vérification du rendu côté serveur avant confirmation

### 12.3 👤 Profil client enrichi au POS

- Fiche client : historique achats, solde crédit, remise négociée, badge VIP
- Recherche rapide : nom, téléphone, code client
- Création rapide d'un client depuis le POS (nom + téléphone minimum)

### 12.4 🎁 Promotions & prix de groupe

- Prix spéciaux par POS ou par entrepôt (prix gros, prix détail)
- Promotions temporaires : réduction auto sur un produit entre deux dates
- Affichage prix barré + prix promo dans le catalogue

### 12.5 📦 Commande en attente (Layaway)

- Mise en attente d'un ticket sans validation
- Stock réservé pendant une durée définie (configurable)
- Rappel du ticket à la prochaine venue du client

### 12.6 🔄 Échange / Retour produit au POS

- Workflow : sélection ticket original → articles à retourner → motif
- Avoir Dolibarr + ré-intégration stock automatiques
- Avoir accessible dans la page "Factures POS" — pas dans les avoirs clients standard
- Option : échange direct contre un autre produit (nouveau ticket lié à l'avoir)

### 12.7 📊 Mini-dashboard vendeur en session

- CA de la session, nb tickets, panier moyen — visible en bas de l'écran
- Objectif journalier + barre de progression (configurable par vendeur)

### 12.8 🧾 Devis rapide depuis le POS

- Génère un devis (non encaissé) enregistré dans Dolibarr
- Convertible en facture depuis le POS ou le back-office

### 12.9 🔔 Notifications temps réel

Push notifications dans l'interface (sans rechargement) :
- Annulation approuvée/refusée
- Remise accordée par superviseur
- Stock épuisé sur produit en cours de vente
- Nouveau taux de change saisi
- Transfert stock approuvé/refusé
- Transfert stock effectué par un collègue sur le même entrepôt
- Reconnexion après hors-ligne : nb tickets synchronisés

### 12.10 📱 Mode tablette / POS mobile

- Interface responsive — tablette 10" minimum recommandée
- Clavier virtuel intégré pour saisie montants
- Mode caissier mobile pour grandes surfaces ou marchés

### 12.11 🔍 Historique tickets depuis le POS

- Vendeur : tickets de sa session en cours — accessible via menu "Factures POS"
- Superviseur : toutes sessions de son entrepôt
- Recherche par numéro, client, montant, date — ré-impression directe

### 12.12 📈 Rapport performance vendeur

- CA, nb transactions, remises, annulations — par session ou période
- Classement vendeurs par CA (visible responsable)
- Alerte si taux d'annulation > seuil configurable

### 12.13 🔐 Verrouillage de session (pause caisse)

- Vendeur verrouille son POS sans fermer la session (pause)
- Déverrouillage par PIN — aucune vente possible sur POS verrouillé
- Session continue de courir et d'être tracée côté serveur

### 12.14 💾 Partage de ticket (WhatsApp / Email / SMS)

- Lien PDF du ticket envoyé au client directement depuis le POS
- Utile pour les clients sans imprimante ou voulant la facture sur leur téléphone

---

## 13. Spécifications Techniques

### 13.1 Architecture applicative

- **Frontend POS** : SPA (Vue.js ou React) — responsive mobile / tablette / desktop
- **Backend** : Module PHP custom Dolibarr — API REST sécurisée (token par session POS — durée longue)
- **Base de données** : MySQL/MariaDB — tables dédiées avec FK vers `llx_*` Dolibarr
- **Cache** : Redis pour catalogue produits et taux (TTL 30s)
- **Temps réel** : WebSocket (recommandé) ou polling différentiel 30s (fallback)
- **Impression** : ESC/POS via WebUSB (80 mm) + TCPDF/mPDF pour A5/A4
- **Offline** : Service Worker + IndexedDB — sync à la reconnexion avec file d'attente ordonnée
- **Session POS** : token longue durée (12h) — stocké localStorage + cookie HttpOnly — indépendant de la session Dolibarr
- **Taux de change** : lecture prioritaire `pos_exchange_rate` (saisi manuellement) → fallback `llx_multicurrency_rate` (Dolibarr)

### 13.2 Tables de données principales

| Table | Clés principales | Relations |
|---|---|---|
| `pos_config` | id, warehouse_id, name, currency_account_id, print_format, transfer_enabled, offline_mode, backdating, transfer_timeout_hours, cancel_escalation_minutes | FK → `llx_entrepot`, `llx_bank_account` |
| `pos_config_account` | id, pos_id, account_id, is_default, is_active, default_payment_mode | FK → `pos_config`, `llx_bank_account` |
| `pos_exchange_rate` | id, date, rate_usd_cdf, user_id, source (`MANUAL`/`SYSTEM`) | — |
| `pos_session` | id, pos_id, user_id, date_open, rate_id, fund_init_usd, fund_init_cdf, session_token, reconnection_count, last_activity | FK → `pos_config`, `llx_user`, `pos_exchange_rate` |
| `pos_ticket` | id, session_id, fk_facture, currency, is_backdated, status, sync_status (`SYNCED`/`PENDING_SYNC`), created_offline | FK → `pos_session`, `llx_facture` |
| `pos_ticket_line` | id, ticket_id, product_id, qty, price, price_modified, discount, total | FK → `pos_ticket`, `llx_product` |
| `pos_payment` | id, ticket_id, account_id, amount, currency, mode, ref | FK → `pos_ticket`, `llx_bank_account` |
| `pos_change` | id, ticket_id, account_id, amount, currency | Rendu monnaie par compte/devise |
| `pos_cancellation` | id, ticket_id, user_req, motif, user_approver, status, date, escalated_at | Workflow annulations avec escalade |
| `pos_transfer` | id, pos_id, user_initiator, product_id, qty, wh_from, wh_to, motif, status, user_approver, motif_refus, date_request, date_decision, expires_at | Transferts — `wh_from` verrouillé côté serveur |
| `pos_offline_queue` | id, session_id, ticket_data_json, created_at, synced_at, sync_status, conflict_detail | File d'attente hors-ligne |
| `pos_audit_log` | id, pos_id, user_id, action, before, after, timestamp | Journal immuable |

### 13.3 Sécurité API — Règles d'exposition des données

| Donnée | Endpoint POS | Endpoint Back-office |
|---|---|---|
| Prix de vente | ✅ Exposé (converti) | ✅ Exposé |
| Prix d'achat (`cost_price`) | ❌ **Jamais retourné** | ✅ Selon droits |
| Fournisseur principal | ❌ **Jamais retourné** | ✅ Selon droits |
| Marge | ❌ **Jamais retournée** | ✅ Selon droits |
| Stock par entrepôt | ✅ Exposé | ✅ Exposé |
| Factures POS (`origin=pos`) | ✅ Via endpoint POS uniquement | ✅ Via endpoint dédié |
| Factures normales | ❌ Non accessibles depuis POS | ✅ Via modules standard |

### 13.4 Performance & Scalabilité

- Catalogue : pagination serveur 50 articles/page — recherche full-text indexée
- Mise à jour live : patch partiel DOM (pas de rechargement complet)
- Temps de réponse cible : < 300 ms pour ajout d'un article
- Validation ticket : < 1 s (transaction atomique : facture + paiements + stock)
- Multi-POS simultanés : verrous optimistes sur stock sans deadlock
- Rate limiting par POS sur les endpoints WebSocket/polling pour éviter la surcharge en cas de nombreux terminaux

---

## 14. Plan d'Implémentation

| Phase | Périmètre | Durée |
|---|---|---|
| Phase 1 — Fondations | Config POS, entrepôt, comptes, taux jour, fallback multi-devises | 3 sem. |
| Phase 2 — Interface POS | Barre statut, catalogue live, édition inline lignes ticket | 3 sem. |
| Phase 3 — Paiement multi-devises | Formulaire répartition, rendu éditable multi-devises, billets rapides | 3 sem. |
| Phase 4 — Sessions & Impression | Session ouverte/fermée, persistance session POS, token longue durée, Z-Report, formats 80mm/A5/A4 | 3 sem. |
| Phase 5 — Mode hors-ligne | Service Worker, IndexedDB, file d'attente, sync à reconnexion, gestion conflits | 2 sem. |
| Phase 6 — Contrôles & Règles | Prix verrouillé (côté serveur), vente sans stock, antidatage, validation paiement | 2 sem. |
| Phase 7 — Transferts stock | Dialog transfert, règle wh_from verrouillé serveur, workflow approbation, page Mes Transferts, alertes | 2 sem. |
| Phase 8 — Annulations | Workflow approbation, escalade, avoirs, remboursements | 2 sem. |
| Phase 9 — Séparation factures | Filtrage factures POS, menu Factures POS Dolibarr, droits par profil | 1 sem. |
| Phase 10 — Dialog produit | Champs filtrés (sans prix achat/fournisseur), tableau stocks entrepôts, verrou API | 1 sem. |
| Phase 11 — Intégration ERP | Factures, paiements, stock, comptabilité, multi-devises Dolibarr | 2 sem. |
| Phase 12 — Sécurité & Rôles | Profils, audit log, PIN unique, verrouillage, approbations, sécurité API | 1 sem. |
| Phase 13 — Reporting | Z-Report, rapports synthèse, dashboard temps réel, rapport hors-ligne | 2 sem. |
| Phase 14 — Valeur ajoutée | Scan, fidélité, devis, layaway, export WhatsApp, notifications | 3 sem. |
| Phase 15 — Tests & Déploiement | UAT (incluant tests coupures réseau/courant), formation caissiers + admins, mise en production | 2 sem. |

**Durée totale estimée : 29 semaines (~7 mois)**

---

## 15. Points d'Attention & Risques

> ⚠️ **Risques identifiés à traiter impérativement**

### Contexte infrastructure (priorité maximale)

- **Coupures de courant et connexion** : c'est le risque opérationnel numéro un. Le mode hors-ligne (Phase 5) est critique et non optionnel. Tester intensivement les scénarios de coupure en cours de transaction, coupure pendant la synchronisation, et coupure lors de la fermeture de session. Prévoir un UPS (onduleur) sur les terminaux POS prioritaires.
- **Numérotation sans trou** : générée côté serveur uniquement — pas de numéro réservé côté client en mode offline. Les tickets hors-ligne reçoivent leur numéro définitif à la synchronisation.
- **Réservation orpheline (transfert)** : si un transfert est soumis et que l'initiateur perd la connexion ou quitte, la réservation doit expirer proprement selon le timeout configuré. Prévoir un job CRON de nettoyage des réservations expirées.

### Données et sécurité

- **Prix d'achat et fournisseur** : le verrou doit être côté serveur — l'API ne doit pas retourner ces champs aux endpoints POS, même en cas d'inspection technique du réseau.
- **Modification de prix** : si `Modification des prix = INTERDITE`, le verrou est côté serveur — un utilisateur technique ne peut pas contourner via l'API.
- **Rendu éditable** : vérifier côté serveur que le rendu ≤ excédent encaissé — jamais uniquement côté client.
- **Séparation factures POS** : le filtre `origin=pos` doit être appliqué côté serveur dans toutes les requêtes de listing factures standard.

### Multi-devises et arrondis

- **Taux de change manquant** : le fallback sur le taux système Dolibarr doit être fiable — vérifier que ce taux est maintenu à jour. Afficher clairement sur le POS quel taux est utilisé et sa source.
- **Arrondis de conversion USD/CDF** : définir la règle d'arrondi stricte (ex : CDF arrondi à l'unité inférieure) — appliquer uniformément à toutes les conversions pour éviter les écarts de centime. Documenter la règle dans la config.

### Stock et cohérence

- **Cohérence stock multi-POS** : verrous optimistes obligatoires — définir la stratégie de gestion des conflits (retenter, rejeter, alerter). Tester les scénarios de vente simultanée du même article sur deux POS.
- **Catalogue live & performances** : en cas de nombreux POS simultanés, le WebSocket ou le polling doivent être optimisés — penser au rate limiting par POS.
- **Articles hors vente** : le filtre doit s'appliquer aussi au scan code-barres — un article hors vente ne peut pas être ajouté même s'il est scanné.

### Workflow et opérationnel

- **Workflow annulation sans superviseur disponible** : timeout d'escalade (30 min) → escalade admin — définir qui est admin de dernier recours disponible en dehors des heures ouvrées.
- **Ventes antidatées** : limiter la période autorisée (ex : max J-7) pour éviter les manipulations comptables — double validation recommandée au-delà de J-7.
- **PIN superviseur** : le PIN d'approbation doit être unique par superviseur — ne pas permettre un PIN partagé.

### Technique et déploiement

- **Impression 80 mm** : tester la compatibilité ESC/POS avec les imprimantes thermiques disponibles sur site (drivers, codepage pour caractères français, montants en CDF).
- **Migration** : définir la reprise d'historique et la continuité de numérotation si un système POS précédent existe.
- **Tests coupures** : inclure dans le plan de test UAT des scénarios de coupure réseau et courant à chaque phase critique (en cours de paiement, pendant la sync hors-ligne, à la fermeture de session).

---

*Document de référence principal — Module POS Personnalisé Dolibarr — v1.3 Final consolidé — Juin 2026*
