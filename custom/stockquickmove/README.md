# StockQuickMove

StockQuickMove extrait dans un module externe le formulaire personnalisé qui se trouvait auparavant dans `product/stock/movement_create.php`. Il permet d'enregistrer rapidement :

- un transfert entre deux entrepôts ;
- une entrée de stock avec prix d'achat ;
- une sortie de stock.

La logique métier du formulaire d'origine est conservée : contrôle des droits natifs, validation des quantités et des entrepôts visibles, respect de l'option de stock négatif, date du mouvement, mise à jour du PMP par le mécanisme standard de réception et mini-journal de session. Le traitement utilise un cycle POST/Redirect/GET afin qu'un rafraîchissement ne répète jamais un mouvement.

Le formulaire est réservé aux produits physiques autorisés à la vente (`tosell=1`) qui ne sont pas gérés par lot ou numéro de série. Les services, produits internes non commercialisables et produits à lot/série sont volontairement exclus du sélecteur rapide.

## Installation et activation

1. Copier le dossier `stockquickmove` dans le répertoire `custom` de Dolibarr.
2. Activer les modules **Produits** et **Stocks**.
3. Activer **Mouvements de stock rapides** depuis Configuration > Modules/Applications.

Lors d'une mise à jour depuis la version 1.0.0, désactiver puis réactiver le module
une fois afin que Dolibarr recrée son entrée dans le menu.

Le module porte l'identifiant `4940000` et dépend de `modProduct` et `modStock`.

## Accès

Le formulaire est disponible :

- dans le menu Produits > Entrepôts > Mouvement rapide ;
- depuis le bouton « Nouveau mouvement rapide » affiché sur la liste globale des mouvements ou sur celle d'un entrepôt.

Les deux accès sont réservés aux utilisateurs internes disposant des droits natifs de lire le stock (`stock.lire`) et de créer des mouvements (`stock.mouvement.creer`). Les boutons sont injectés par les hooks `printFieldPreListTitle` et `addMoreActionsButtons` dans le contexte `stockmovementlist`.

## Fichiers principaux

- `quickmovement.php` : formulaire et traitement des mouvements ;
- `core/modules/modStockQuickMove.class.php` : descripteur, dépendances, hooks et menu ;
- `class/actions_stockquickmove.class.php` : boutons ajoutés aux listes globale et par entrepôt ;
- `langs/fr_FR/stockquickmove.lang` et `langs/en_US/stockquickmove.lang` : traductions.

Le lien **Annuler** du formulaire retourne explicitement vers `/product/stock/movement_list.php`.
