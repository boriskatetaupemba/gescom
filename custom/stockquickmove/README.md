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

Le module porte l'identifiant `4940000` et dépend de `modProduct` et `modStock`.

## Accès

Le formulaire est disponible :

- dans le menu Produits > Entrepôts > Mouvement rapide ;
- depuis le bouton « Nouveau mouvement rapide » affiché sur la liste des mouvements d'un entrepôt.

Les deux accès sont réservés aux utilisateurs internes disposant des droits natifs de lire le stock (`stock.lire`) et de créer des mouvements (`stock.mouvement.creer`). Le bouton est injecté par le hook `addMoreActionsButtons` dans le contexte `stockmovementlist`.

## Fichiers principaux

- `quickmovement.php` : formulaire et traitement des mouvements ;
- `core/modules/modStockQuickMove.class.php` : descripteur, dépendances, hook et menu ;
- `class/actions_stockquickmove.class.php` : bouton ajouté à la liste native ;
- `langs/fr_FR/stockquickmove.lang` et `langs/en_US/stockquickmove.lang` : traductions.

Le lien **Annuler** du formulaire retourne explicitement vers `/product/stock/movement_list.php`.
