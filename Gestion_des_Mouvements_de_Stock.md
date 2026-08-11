# Gestion des Mouvements de Stock

## Objectif

Faire évoluer la page de transfert rapide afin qu'elle permette non seulement les **transferts de stock** (fonctionnalité déjà implémentée), mais également la création de **mouvements d'entrée** et de **sortie de stock**.

> **État : ✅ Implémenté dans un module externe** — Fichier
> `custom/stockquickmove/quickmovement.php`.
> Le formulaire unique gère les trois types de mouvement (Transfert / Entrée / Sortie) et a été enrichi de plusieurs améliorations d'ergonomie et de fonctionnalités à valeur ajoutée — voir les sections « Améliorations de l'interface (ergonomie) », « Fonctionnalités avancées » et « Détails techniques d'implémentation ».

Le module **StockQuickMove** doit être activé. Il ajoute une entrée sous
Produits > Entrepôts et un bouton sur les listes de mouvements associées à un
entrepôt, sans modifier `product/stock/movement_list.php`.

---

## Types de mouvements

### 1. Transfert de stock
Fonctionnalité existante à conserver.

- Nécessite un **entrepôt source** et un **entrepôt de destination**
- Le champ **Libellé / Description** ne doit **pas** être affiché
- Le champ **Prix d'achat** ne doit **pas** être affiché

### 2. Entrée de stock
Utilisée pour les réceptions de marchandises : achat, régularisation, inventaire initial, etc.

- Aucun entrepôt source n'est requis
- Seul l'**entrepôt de destination** doit être renseigné
- Le champ **Libellé / Description** doit être affiché et **obligatoire**
- Le champ **Prix d'achat** doit être affiché et **obligatoire**
- L'utilisateur doit saisir le produit, la quantité et le prix d'achat

### 3. Sortie de stock
Utilisée pour les déclassements, consommations internes, pertes ou autres sorties de marchandises.

- Aucun entrepôt de destination n'est requis
- Seul l'**entrepôt source** doit être renseigné
- Le champ **Libellé / Description** doit être affiché et **obligatoire**
- Le champ **Prix d'achat** ne doit **pas** être affiché

---

## Évolution de l'interface

Ajouter un champ **Type de mouvement** placé **avant le champ Date** dans le formulaire.

**Valeurs possibles :**
- Transfert
- Entrée de stock
- Sortie de stock

### Comportement par défaut
À l'ouverture de la page, le type de mouvement sélectionné par défaut doit être **Transfert**. L'interface doit donc initialement se comporter exactement comme le formulaire de transfert actuel afin de ne pas perturber les utilisateurs habituels.

---

## Affichage dynamique des champs

| Type de mouvement | Entrepôt source | Entrepôt destination | Libellé | Prix d'achat |
|---|---|---|---|---|
| Transfert | Obligatoire | Obligatoire | Masqué | Masqué |
| Entrée de stock | Masqué | Obligatoire | Obligatoire | Obligatoire |
| Sortie de stock | Obligatoire | Masqué | Obligatoire | Masqué |

---

## Règles de gestion

- Le backend existant des transferts doit être **conservé et réutilisé** sans modification du comportement actuel
- Les mouvements d'entrée et de sortie doivent utiliser les **mécanismes standard de gestion de stock de Dolibarr** afin que les mouvements soient visibles dans l'historique des stocks
- Les **validations doivent être adaptées automatiquement** selon le type de mouvement sélectionné
- Les champs inutiles pour un type de mouvement donné doivent être **masqués** et non simplement désactivés
- Lorsqu'un utilisateur change le type de mouvement, les champs affichés et les validations doivent être **mis à jour immédiatement** sans rechargement de la page

---

## Réinitialisation après enregistrement

Après l'enregistrement réussi d'un mouvement de stock, seuls les champs suivants doivent être **réinitialisés** :

- Produit
- Quantité
- Prix d'achat

Tous les autres champs doivent **conserver leurs valeurs** précédentes jusqu'à ce que l'utilisateur les modifie lui-même. Cela concerne notamment :

- le type de mouvement
- la date
- l'entrepôt source
- l'entrepôt destination
- le libellé / description

L'objectif est de permettre à l'utilisateur d'**enchaîner rapidement plusieurs mouvements similaires** sans devoir ressaisir toutes les informations à chaque fois.

---

## Améliorations de l'interface (ergonomie)

Ces ajustements visent la lisibilité et la rapidité de saisie sans modifier les règles de gestion.

### Sélecteur de type bien visible
- Le type de mouvement est présenté sous forme de **boutons segmentés** (Transfert / Entrée de stock / Sortie de stock).
- Le bouton **sélectionné est nettement mis en évidence** : bordure pleine de couleur accent, **anneau lumineux** autour, fond clair et texte en gras.
- Les boutons inactifs conservent une bordure transparente de même épaisseur → **aucun décalage de mise en page** lors du changement de type.

### Bloc de résultat pliable / dépliable
- Après un enregistrement réussi, le récapitulatif est affiché dans un bloc **pliable** (`<details>` natif).
- **Plié par défaut** : seul le message « … enregistré avec succès » est visible (gain de place).
- Au clic, le bloc se déplie pour montrer le détail (Type, Produit, Quantité, Date, entrepôts, libellé, prix, code mouvement). Le chevron pivote.
- **Libellés des champs affichés en noir** (au lieu d'un vert clair peu lisible) ; les valeurs restent en vert foncé contrasté.

### Reprise de saisie immédiate
- Après un enregistrement réussi, le **focus est repositionné automatiquement sur le champ Produit** pour enchaîner la saisie suivante.

---

## Fonctionnalités avancées

Fonctionnalités ajoutées au-delà de la spécification initiale pour fiabiliser et accélérer la saisie.

### 1. Affichage du stock en temps réel
- Dès qu'un produit est sélectionné, un panneau affiche le **stock disponible** dans l'entrepôt pertinent :
  - entrepôt **source** pour un transfert ou une sortie ;
  - entrepôt **destination** pour une entrée.
- Le **stock total des entrepôts ouverts et visibles** dans le contexte d'entité courant est également indiqué.
- Une valeur **≤ 0 est signalée en rouge**. Les données sont récupérées via un appel **AJAX** sans rechargement de page.

### 2. Garde-fou contre le stock négatif (sortie)
- Lors d'une **sortie**, si le réglage Dolibarr `STOCK_ALLOW_NEGATIVE_TRANSFER` est désactivé, le mouvement est **bloqué côté serveur** lorsque la quantité demandée dépasse le stock disponible, avec un message explicite.

### 3. Pré-remplissage du prix d'achat (entrée)
- En mode **Entrée**, le champ **Prix d'achat** est pré-rempli automatiquement avec le **PMP** du produit (ou, à défaut, son **coût d'achat**), **uniquement si le champ est vide**. La valeur reste modifiable.

### 4. Inversion source ↔ destination (transfert)
- Un bouton sur la flèche centrale permet d'**inverser en un clic** l'entrepôt source et l'entrepôt destination (mode Transfert). Le panneau de stock est rafraîchi en conséquence.

### 5. Raccourcis clavier et accessibilité
- **Ctrl / ⌘ + Entrée** : enregistrer le mouvement.
- Navigation des types au clavier (**← / → / Début / Fin**) avec rôles **ARIA `tablist` / `tab`** et `tabindex` mobile pour l'accessibilité.

### 6. Mini-journal des derniers mouvements
- Les **5 derniers mouvements** enregistrés au cours de la session sont listés sous le formulaire : badge coloré selon le type, produit, trajet (source → destination), quantité et date.
- Un bouton **Effacer** protégé par jeton anti-CSRF permet de vider la liste.

### 7. Validation non bloquante
- Les contrôles de saisie n'utilisent plus de fenêtres d'alerte bloquantes : un **bandeau d'erreur en ligne** s'affiche et le **champ fautif est surligné en rouge** avec mise au focus automatique.
- **Bonus** — bouton **Max** (sortie / transfert) : remplit la quantité avec le stock disponible de l'entrepôt source.

---

## Détails techniques d'implémentation

- **Page du module** : `custom/stockquickmove/quickmovement.php` (UI, styles préfixés `.trs-`, logique JavaScript en IIFE, et endpoint AJAX intégré).
- **Descripteur et hook** : `custom/stockquickmove/core/modules/modStockQuickMove.class.php` et `custom/stockquickmove/class/actions_stockquickmove.class.php`.
- **Mécanismes standard Dolibarr** :
  - Transfert : backend existant **conservé sans modification** (`MouvementStock::_create`).
  - Entrée : `MouvementStock::reception()` (met à jour le PMP).
  - Sortie : `MouvementStock::livraison()`.
  - Les mouvements apparaissent donc dans l'**historique des stocks**.
- **Endpoint AJAX** `action=getproductinfo` : renvoie en JSON le stock par entrepôt, le stock total, le PMP et le coût d'achat du produit.
- **Périmètre produits** : seuls les produits physiques autorisés à la vente (`tosell=1`) sans gestion de lot/série sont proposés ; les services, produits internes non commercialisables et produits à lot sont exclus du sélecteur rapide.
- **Mini-journal** : stocké en session (`$_SESSION['trs_recent_movements']`, limité aux 5 dernières entrées) et effaçable par un POST `action=clearrecent` protégé par jeton.
- **Sécurité** : utilisateur interne, module actif, droits `stock->lire` et `stock->mouvement->creer`, jeton anti-CSRF strict sur le POST, validation des produits/entrepôts visibles et requêtes filtrées par entité.
- **Anti-rejeu** : après succès, une redirection POST/Redirect/GET empêche le rafraîchissement du navigateur de recréer le mouvement.

---

## Objectif final

Disposer d'un formulaire unique, simple et rapide d'utilisation permettant de réaliser :

- les transferts de stock
- les entrées de stock
- les sorties de stock

tout en conservant l'expérience actuelle des utilisateurs grâce à l'ouverture par défaut en mode **Transfert**.
