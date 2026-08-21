# Prompt d'implémentation corrigé --- Niveaux de prix dans `invoicePlus`

## 1. Rôle attendu

Tu interviens comme développeur senior Dolibarr/PHP/MySQL, spécialisé dans
les modules externes Dolibarr et les API REST.

Tu dois étendre le module `invoicePlus` sans modifier le coeur Dolibarr et
sans dupliquer les mécanismes natifs déjà disponibles.

Avant toute modification, vérifie que les constats techniques de ce document
correspondent encore au code présent dans le dépôt. Si le code a changé,
signale l'écart avant d'adapter le plan.

Respecte en priorité :

- l'architecture native de Dolibarr ;
- les classes métier et API natives ;
- les hooks, triggers et extrafields ;
- les permissions et protections CSRF ;
- la gestion multi-entité ;
- les conventions de code du dépôt ;
- la compatibilité ascendante de `invoicePlus`.

Ne modifie aucun fichier du coeur Dolibarr.

---

## 2. État réel vérifié du projet

La cible actuelle est :

- Dolibarr 20.0.4 ;
- `invoicePlus` 1.3.4 ;
- PHP 8.2 ;
- MariaDB/MySQL avec préfixe de table configurable.

Les mécanismes suivants existent déjà dans Dolibarr et ne doivent pas être
réimplémentés :

1. activation des niveaux par `PRODUIT_MULTIPRICES` ;
2. nombre dynamique de niveaux par `PRODUIT_MULTIPRICES_LIMIT` ;
3. grille et historique dans `product_price`, avec `price_level` et `entity` ;
4. chargement des niveaux dans les tableaux `Product::$multiprices*` ;
5. écriture et historisation par `Product::updatePrice()` ;
6. niveau client dans `societe.price_level` ;
7. modification du niveau client par `Societe::setPriceLevel()` et par
   l'API native des tiers ;
8. édition complète des niveaux produit dans l'onglet natif **Prix** ;
9. table native `entrepot_extrafields` et API native des extrafields.

Le formulaire natif de création d'un produit masque volontairement la grille
des multiprix. C'est ce manque précis que `invoicePlus` doit compléter.

Le module `invoicePlus` ne possède actuellement aucun endpoint de création de
facture. Son seul endpoint REST en écriture est :

```http
POST /api/index.php/invoiceplus/invoices/{invoice_id}/cash-settlement
```

Cet endpoint règle une facture existante. Il ne crée ni facture ni ligne et ne
doit pas être modifié pour cette fonctionnalité.

La création standard reste assurée par :

```http
POST /api/index.php/invoices
```

---

## 3. Objectif fonctionnel corrigé

Compléter les niveaux de prix natifs avec les fonctions réellement absentes :

1. saisir N1 à N pendant la création d'un produit ou service ;
2. conserver l'édition native des prix après création ;
3. affecter un niveau de prix commercial à un entrepôt ;
4. centraliser la résolution Client > Entrepôt > N1 ;
5. appliquer un fallback direct vers N1 quand le niveau retenu n'a aucun prix ;
6. exposer les produits et leur prix applicable par client ;
7. exposer les produits et leur prix applicable par entrepôt ;
8. préserver strictement le comportement des stocks, des factures et des API
   existantes ;
9. fournir les tests, traductions et documents nécessaires.

---

## 4. Invariants impératifs

### 4.1 Une seule grille de prix

Clients et entrepôts utilisent exclusivement la grille native
`product_price.price_level`.

Il est interdit de créer :

- une table de prix par entrepôt ;
- une copie des prix produit dans un extrafield ;
- une grille propre à `invoicePlus` ;
- une valeur de prix différente selon que le niveau vient d'un client ou d'un
  entrepôt.

Seul le numéro de niveau de l'entrepôt est une nouvelle donnée.

### 4.2 Nombre de niveaux dynamique

Ne jamais coder le nombre de niveaux en dur.

Toutes les boucles, listes, validations et requêtes doivent utiliser :

```php
getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT')
```

La fonctionnalité ne doit être active que si :

```php
getDolGlobalString('PRODUIT_MULTIPRICES')
```

est actif et que la limite est strictement positive.

### 4.3 Aucun impact sur la valorisation du stock

Le niveau d'un entrepôt est une information commerciale uniquement.

Ne jamais modifier :

- le PMP/AWP ;
- la valorisation des stocks ;
- les montants des mouvements de stock ;
- les algorithmes comptables ;
- la validation ou l'annulation native des mouvements ;
- les tables ou classes de valorisation.

---

## 5. Fonctionnalité A --- Multiprix à la création du produit

### 5.1 Interface

Étendre le formulaire natif de création via le contexte de hook `productcard`.

Afficher, uniquement pendant la création et lorsque les multiprix sont actifs :

```text
Prix niveau 1 : [          ] [HT/TTC]
Prix niveau 2 : [          ] [HT/TTC]
...
Prix niveau N : [          ] [HT/TTC]
```

Utiliser les libellés `PRODUIT_MULTIPRICES_LABEL{N}` lorsqu'ils existent,
sinon le libellé natif « Niveau de prix N ».

Réutiliser les noms de champs attendus par le formulaire natif lorsque cela
est possible :

- niveau 1 : `price` et `price_base_type` ;
- niveaux 2 à N : `price_{N}` et `multiprices_base_type_{N}`.

Ajouter un marqueur caché propre à `invoicePlus` afin que le traitement
post-création ne se déclenche que pour ce formulaire, jamais pour une création
de produit provenant d'une autre API ou d'un autre module.

La TVA reste celle du formulaire natif. Ne pas implémenter une TVA différente
par niveau : `PRODUIT_MULTIPRICES_USE_VAT_PER_LEVEL` est conservé par Dolibarr
pour compatibilité, mais n'est pas une base saine pour une nouvelle extension.

### 5.2 Validation

Pour chaque niveau :

- champ vide : niveau non renseigné ;
- valeur `0` ou `0.00` : prix explicitement nul ;
- autre valeur : nombre monétaire valide selon les fonctions Dolibarr ;
- valeur mal formée ou non finie : refuser la création avec un message clair.

Ne pas utiliser `empty()` pour décider si un prix a été renseigné.

Si au moins un niveau supérieur est renseigné, N1 doit aussi être renseigné,
car il constitue le fallback. Si tous les prix sont vides, laisser la création
native du produit fonctionner.

En cas d'erreur, conserver dans le formulaire toutes les valeurs soumises.

Respecter les droits natifs de création produit/service et le token CSRF du
formulaire. Le hook ne doit pas contourner le traitement de sécurité natif.

### 5.3 Persistance

Le niveau 1 doit rester créé et historisé par le cycle natif de
`Product::create()`.

Après la création, enregistrer uniquement les niveaux 2 à N effectivement
renseignés avec `Product::updatePrice()`.

Utiliser un trigger `PRODUCT_CREATE` dédié et limité par le marqueur du
formulaire `invoicePlus`. Le trigger s'exécute dans la transaction de création :
si un niveau échoue, retourner une erreur afin que la création complète soit
annulée.

Règles strictes :

- un champ vide ne déclenche aucun `updatePrice()` ;
- un champ explicitement égal à zéro déclenche `updatePrice(0, ...)` ;
- aucun `INSERT` direct dans `product_price` ;
- conserver les triggers natifs `PRODUCT_PRICE_MODIFY` ;
- ne pas créer deux lignes d'historique pour le même niveau pendant la même
  création.

### 5.4 Édition après création

Ne pas recréer une seconde interface d'édition sur la fiche produit.

L'onglet natif **Prix** couvre déjà l'édition, la TVA, les prix HT/TTC, les
prix minimums, les règles automatiques et l'historique. Il faut uniquement
vérifier sa non-régression.

---

## 6. Fonctionnalité B --- Niveau de prix de l'entrepôt

### 6.1 Stockage imposé

Créer de manière idempotente un extrafield entier nullable sur l'élément
`entrepot`, par exemple :

```text
invoiceplus_price_level
```

Utiliser `ExtraFields::addExtraField()` pendant l'activation ou la mise à jour
du module.

Ne pas créer de table SQL `invoicePlus` pour cette donnée. La valeur doit être
stockée dans la structure native `entrepot_extrafields`.

La définition de l'extrafield doit :

- être propre à l'entité courante ;
- être créée sans erreur si elle existe déjà avec une définition compatible ;
- provoquer une erreur d'activation explicite si une définition incompatible
  portant le même nom existe ;
- préserver les valeurs lors d'une simple désactivation du module ;
- être exploitable lors d'une mise à jour sans réinstallation complète.

### 6.2 Interface entrepôt

Utiliser le contexte `warehousecard` et les API d'extrafields pour afficher,
créer et modifier :

```text
Niveau de prix : Aucun / Niveau 1 / ... / Niveau N
```

La liste doit être reconstruite à chaque requête depuis la configuration
native. Elle ne doit pas être figée dans les options de l'extrafield au moment
de l'installation.

Valeurs persistées :

- `NULL` ou chaîne vide normalisée en `NULL` : aucun niveau ;
- entier entre 1 et N : niveau valide ;
- toute autre valeur : erreur de validation.

Vérifier le droit natif de création/modification des entrepôts et laisser le
formulaire natif gérer son token CSRF.

Lorsque les multiprix sont désactivés, ne pas afficher le champ et ne pas
effacer une ancienne valeur.

---

## 7. Service central de résolution

Créer un service central dans `invoicePlus`. Les noms exacts doivent suivre les
conventions du module, mais il doit couvrir les responsabilités suivantes :

```php
resolvePriceLevel(?int $customerId, ?int $warehouseId): array
resolveProductPrice(int $productId, int $requestedLevel): array
getProductPriceForCustomer(int $productId, int $customerId): array
getProductPriceForWarehouse(int $productId, int $warehouseId): array
```

### 7.1 Résolution du niveau

Algorithme obligatoire :

```text
si le client possède un niveau explicite valide
    niveau demandé = niveau client
    source = customer
sinon si l'entrepôt possède un niveau explicite valide
    niveau demandé = niveau entrepôt
    source = warehouse
sinon
    niveau demandé = 1
    source = default_level
```

La colonne `societe.price_level` est nullable, mais `Societe::fetch()` remplace
une valeur vide par `1`. Pour distinguer « aucun niveau client » de N1, le
service doit :

1. charger le tiers avec la classe native pour vérifier son existence et les
   droits ;
2. lire la valeur persistée brute de `societe.price_level` avec une requête
   ciblée en lecture seule ;
3. considérer `NULL` ou `0` comme « non défini ».

Ne jamais écrire directement dans `societe`.

Une ancienne valeur persistée devenue supérieure à la nouvelle limite doit
être traitée comme non définie et journalisée avec `dol_syslog`. Elle ne doit
jamais provoquer une lecture d'un niveau hors configuration.

### 7.2 Résolution du prix

Après résolution du niveau :

```text
si le dernier prix du produit pour le niveau demandé existe
et si sa colonne price n'est pas NULL
    utiliser ce niveau, y compris si price = 0
sinon
    chercher directement le dernier prix de N1
```

Ne jamais essayer N-1, un niveau intermédiaire ou le niveau de l'entrepôt après
qu'un niveau client a été retenu.

Le dernier prix d'un niveau est déterminé avec l'ordre natif :

```sql
ORDER BY date_price DESC, rowid DESC
```

et avec le périmètre d'entité de `productprice`.

Un prix est défini lorsque la ligne courante existe et que `price IS NOT NULL`.
Une valeur numérique égale à zéro est définie. Ne pas tester la présence avec
`empty()` ni avec une comparaison `> 0`.

Si le niveau demandé et N1 sont tous les deux absents ou `NULL`, retourner une
erreur métier. Ne jamais inventer le prix `0`.

### 7.3 Limite native connue pour N1

Dans Dolibarr 20.0.4, `Product::create()` normalise un prix N1 vide à zéro et
crée une ligne d'historique N1. Cette ligne native doit être considérée comme
un prix défini égal à zéro.

Ne pas ajouter une table de présence uniquement pour distinguer un N1 vide
d'un N1 explicitement nul. Documenter cette limite native.

Pour N2 à N, le formulaire ajouté doit préserver la différence :

- vide : aucune ligne de prix créée ;
- zéro : ligne de prix créée avec zéro.

### 7.4 Métadonnées retournées

Le résultat du service doit au minimum contenir :

```json
{
  "requested_price_level": 3,
  "applied_price_level": 1,
  "price_level_source": "customer",
  "price_fallback": true,
  "price": 50.00,
  "price_ttc": 60.00,
  "price_base_type": "HT",
  "tva_tx": 20.0
}
```

Valeurs autorisées pour `price_level_source` :

```text
customer
warehouse
default_level
```

`applied_price_level` vaut `1` lorsqu'un fallback N1 a été utilisé.

---

## 8. API REST produits par client

Ajouter :

```http
GET /api/index.php/invoiceplus/products/customer/{customer_id}
```

Pour chaque produit retourné :

1. utiliser le niveau client explicite ;
2. utiliser N1 si le client n'a pas de niveau ;
3. utiliser le prix du niveau demandé s'il est défini ;
4. sinon utiliser directement N1 ;
5. joindre les métadonnées de résolution.

Cet endpoint ne prend pas d'entrepôt et ne doit pas en déduire un.

---

## 9. API REST produits par entrepôt

Ajouter :

```http
GET /api/index.php/invoiceplus/products/warehouse/{warehouse_id}
```

Pour chaque produit retourné :

1. utiliser le niveau explicite de l'entrepôt ;
2. utiliser N1 si l'entrepôt n'a pas de niveau ;
3. utiliser le prix du niveau demandé s'il est défini ;
4. sinon utiliser directement N1 ;
5. joindre les métadonnées de résolution.

Ne pas confondre cette route avec la route existante :

```http
GET /api/index.php/invoiceplus/warehouse/{warehouse_id}
```

qui retourne des factures et doit conserver exactement son comportement.

---

## 10. Contrat commun des deux GET

### 10.1 Paramètres

Réutiliser autant que possible les paramètres de la liste native des produits :

```text
sortfield
sortorder
limit
page
mode
category
sqlfilters
variant_filter
pagination_data
includestockdata
properties
```

Conserver le plafond `INVOICEPLUS_MAX_API_LIMIT` et les validations strictes
déjà utilisées dans le module.

Ne pas accepter un champ de tri ou un filtre SQL non autorisé.

### 10.2 Forme de réponse

Conserver une représentation aussi proche que possible de l'API native des
produits. Remplacer les champs commerciaux `price` et `price_ttc` par les
valeurs applicables, puis ajouter uniquement :

```text
requested_price_level
applied_price_level
price_level_source
price_fallback
```

Conserver aussi `price_base_type` et les données de TVA cohérentes avec la
ligne de prix effectivement appliquée.

Si `pagination_data=true`, conserver l'enveloppe native `data` et
`pagination`.

Le filtre `properties` doit s'appliquer après l'enrichissement afin que le
consommateur puisse demander ou exclure les nouvelles propriétés.

### 10.3 Performance

Il est interdit d'exécuter une requête de prix par produit.

Après sélection et pagination des produits :

1. récupérer en une requête groupée les lignes courantes du niveau demandé et
   de N1 pour tous les produits de la page ;
2. départager les historiques par `date_price DESC, rowid DESC` ;
3. construire un index PHP par produit et niveau ;
4. résoudre les prix en mémoire.

Lors du chargement des objets produits, positionner si possible le sixième
argument `$ignore_price_load` de `Product::fetch()` à `1` afin d'éviter le
chargement de tous les niveaux pour chaque produit.

### 10.4 Permissions et entités

Les endpoints doivent :

- utiliser l'authentification REST Dolibarr ;
- respecter `INVOICEPLUS_API_ENABLED` ;
- exiger le droit de lecture produit ;
- exiger le droit de lecture tiers pour la route client ;
- exiger le droit de lecture stock pour la route entrepôt ;
- appeler les contrôles natifs d'accès à la ressource ;
- respecter les restrictions des utilisateurs externes ;
- filtrer les produits, tiers, entrepôts et prix par les entités natives ;
- ne jamais accepter un identifiant nul, négatif ou mal formé.

### 10.5 Codes d'erreur

Utiliser au minimum :

| Situation | HTTP |
|---|---:|
| ID mal formé ou paramètre invalide | 400 |
| Multiprix désactivés | 409 |
| Permission insuffisante | 403 |
| Client ou entrepôt inexistant | 404 |
| Aucun prix applicable, N1 compris | 422 |
| Erreur de lecture de la base | 503 |

Ne pas retourner arbitrairement un tableau vide ou un prix zéro pour masquer
une erreur métier.

---

## 11. Création de facture par API --- périmètre corrigé

Il n'existe pas de `POST invoicePlus` de création de facture dans le module
actuel.

Par conséquent, dans cette tâche :

1. ne pas modifier le endpoint de règlement `cash-settlement` ;
2. ne pas modifier le coeur `Invoices::post()` ;
3. ne pas créer silencieusement un nouveau wrapper de facture ;
4. vérifier par un test ou une analyse ciblée que le `POST /invoices` natif
   conserve un `lines[].subprice` explicitement fourni, y compris `0` ;
5. documenter que l'absence ou la valeur `null` de `subprice` ne déclenche pas
   la résolution automatique `invoicePlus` dans cette version.

Si une résolution automatique lors de la création API est requise, elle devra
faire l'objet d'un livrable séparé définissant explicitement :

- la nouvelle route InvoicePlus ;
- le champ réel `lines[].subprice` ;
- un entrepôt de tarification fourni avant la création ;
- la différence entre prix absent, `null` et zéro ;
- les lignes libres sans produit ;
- la persistance éventuelle de l'entrepôt sur les lignes ;
- le contrat multidevise.

Ces décisions ne doivent pas être inventées dans la présente tâche.

---

## 12. Factures créées manuellement --- périmètre corrigé

Dolibarr connaît le client pendant la saisie d'une facture et sait déjà
appliquer son niveau de prix natif.

En revanche, l'entrepôt global est généralement choisi au moment de la
validation du stock, après la saisie des prix. Une facture peut aussi posséder
des entrepôts différents selon les lignes.

Cette tâche ne doit donc pas modifier automatiquement le prix des lignes de
facture manuelles à partir d'un entrepôt indéterminé.

Le service central doit néanmoins prendre en charge Client > Entrepôt > N1 et
être testé indépendamment, afin qu'un futur appelant disposant explicitement
des deux identifiants puisse l'utiliser.

Ne pas modifier les factures existantes, validées, les avoirs, les lignes
libres, les remises ni les mouvements de stock.

---

## 13. Multidevise et fiscalité

Les deux GET retournent les prix natifs de `product_price` dans leur contexte
Dolibarr. Ils ne doivent pas introduire une conversion personnalisée.

Préserver ensemble les champs de la ligne appliquée :

- `price` ;
- `price_ttc` ;
- `price_base_type` ;
- `tva_tx` ;
- code TVA lorsqu'il est disponible ;
- champs multicurrency natifs déjà exposés, sans les recalculer.

Ne jamais mélanger le prix HT d'un niveau avec le prix TTC ou la TVA d'un
autre niveau.

---

## 14. Configuration et installation

Ne pas ajouter de constante de configuration sans besoin démontré.

Comportement attendu :

- les interfaces suivent `PRODUIT_MULTIPRICES` ;
- le nombre de niveaux suit `PRODUIT_MULTIPRICES_LIMIT` ;
- les endpoints suivent `INVOICEPLUS_API_ENABLED` ;
- la pagination suit `INVOICEPLUS_MAX_API_LIMIT`.

L'extrafield entrepôt doit être installé ou vérifié par `modInvoicePlus::init()`
avec les API Dolibarr. Aucun script SQL supplémentaire n'est attendu.

Une mise à jour d'une installation existante doit fonctionner par
réactivation du module, sans perte des données existantes.

Mettre à jour la version du module, le changelog, le README et les traductions
françaises et anglaises.

---

## 15. Tests obligatoires

### 15.1 Résolution client

```text
Client N3, produit N3 = 45                         => 45, niveau appliqué 3
Client N3, produit N3 absent, N1 = 50              => 50, fallback N1
Client sans niveau, produit N1 = 50                => 50, source default_level
Client avec ancien niveau hors limite, N1 = 50     => 50, source default_level
```

### 15.2 Résolution entrepôt

```text
Entrepôt N2, produit N2 = 48                       => 48, niveau appliqué 2
Entrepôt N4, produit N4 absent, N1 = 50            => 50, fallback N1
Entrepôt sans niveau, N1 = 50                      => 50, source default_level
Entrepôt avec ancien niveau hors limite, N1 = 50   => 50, source default_level
```

### 15.3 Priorité combinée

```text
Client N3, entrepôt N2                             => niveau demandé 3
Client sans niveau, entrepôt N2                    => niveau demandé 2
Client N3, entrepôt sans niveau                    => niveau demandé 3
Client sans niveau, entrepôt sans niveau           => niveau demandé 1
```

### 15.4 Fallback après priorité

```text
Client N3
Entrepôt N2
Produit : N1 = 50, N2 = 45, N3 absent

Résultat => 50
```

Ne surtout pas retourner `45`.

### 15.5 Zéro et absence

```text
Niveau demandé avec ligne price = 0                => 0, aucun fallback
Niveau demandé sans ligne, N1 = 50                 => 50, fallback
Niveau demandé avec price = NULL, N1 = 50          => 50, fallback
Niveau demandé absent et N1 absent                 => erreur métier
```

### 15.6 Création produit

Tester :

- tous les champs vides : création native réussie ;
- N1 et N2 renseignés : deux niveaux historisés ;
- N2 égal à zéro : ligne N2 créée avec zéro ;
- N2 vide : aucune ligne N2 créée ;
- N2 renseigné avec N1 vide : erreur avant création ;
- nombre de champs égal à la limite configurée ;
- erreur sur un niveau : rollback complet ;
- création native par API sans marqueur : aucun traitement en double.

### 15.7 Entrepôt

Tester :

- création et mise à jour de l'extrafield ;
- valeur `NULL` ;
- valeurs 1 et N ;
- rejet de `0`, des valeurs négatives et de N+1 depuis le formulaire ;
- isolation par entité ;
- champ masqué lorsque les multiprix sont désactivés ;
- conservation de la donnée après désactivation/réactivation.

### 15.8 API et sécurité

Tester :

- pagination simple et enveloppée ;
- filtres et tris autorisés ;
- rejet des tris/filtres non autorisés ;
- client/entrepôt inexistant ;
- ID invalide ;
- droits insuffisants ;
- utilisateur externe ;
- objet d'une autre entité ;
- multiprix désactivés ;
- aucun prix N1 ;
- absence de requête de prix par produit.

### 15.9 Non-régression

Vérifier au minimum :

- création native d'un produit sans multiprix ;
- onglet Prix natif ;
- historique des prix ;
- endpoint natif des produits ;
- tous les endpoints existants de `invoicePlus` ;
- `cash-settlement` ;
- validation d'une facture ;
- mouvements et valorisation du stock ;
- multicurrency ;
- installation où `PRODUIT_MULTIPRICES` est désactivé.

---

## 16. Fichiers attendus

Adapter les noms si les conventions du module l'exigent, mais le périmètre
probable est :

### Fichiers à modifier

- `core/modules/modInvoicePlus.class.php` ;
- `class/actions_invoiceplus.class.php` ;
- `class/api_invoiceplus.class.php` ;
- traductions `fr_FR` et `en_US` ;
- `README.md` ;
- `CHANGELOG.md` ;
- documentation API et tests existants.

### Fichiers à créer

- service central de résolution des prix ;
- service de liste produit enrichie si la séparation est utile ;
- trigger `PRODUCT_CREATE` dédié ;
- tests unitaires du résolveur ;
- tests unitaires des hooks/validations ;
- tests API des deux nouvelles routes.

### Fichiers à ne pas créer

- table SQL de prix entrepôt ;
- copie de la grille produit ;
- surcharge d'une classe du coeur ;
- nouveau POST de facture non spécifié.

---

## 17. Méthode de travail obligatoire

### Étape 1 --- Audit ciblé

Avant la première modification, confirmer :

- version de Dolibarr et du module ;
- signatures exactes de `Product::create()` et `Product::updatePrice()` ;
- ordre et transaction du trigger `PRODUCT_CREATE` ;
- hooks `productcard` et `warehousecard` ;
- définition réelle de `entrepot_extrafields` ;
- contrat de la liste native des produits ;
- contrôles d'accès natifs pour tiers et entrepôts ;
- absence de POST de création dans `invoicePlus`.

### Étape 2 --- Plan court

Présenter :

- fichiers modifiés et créés ;
- méthode d'installation de l'extrafield ;
- stratégie transactionnelle de création des prix ;
- stratégie de requête groupée ;
- tests ciblés ;
- risques résiduels.

### Étape 3 --- Implémentation progressive

Implémenter et valider dans cet ordre :

1. fonctions pures du résolveur et tests ;
2. lecture native/groupée des prix et tests ;
3. extrafield et interface entrepôt ;
4. formulaire et trigger de création produit ;
5. endpoints REST ;
6. traductions et documentation ;
7. non-régression.

### Étape 4 --- Validation

Exécuter au minimum :

- `php -l` sur chaque fichier PHP modifié ou créé ;
- tests PHPUnit ciblés ;
- tests existants de `invoicePlus` ;
- tests API lorsque l'environnement authentifié est disponible ;
- contrôle qu'aucun fichier du coeur n'a été modifié.

Si la base ou le serveur authentifié n'est pas disponible, ne pas déclarer les
tests d'intégration réussis : indiquer précisément ceux qui restent à exécuter.

---

## 18. Interdictions

Ne pas :

- modifier le coeur Dolibarr ;
- coder un nombre de niveaux en dur ;
- créer une grille propre aux entrepôts ;
- écrire directement dans `product_price` ou `societe` ;
- modifier le PMP/AWP ou les mouvements de stock ;
- utiliser `empty()` pour distinguer zéro et absence ;
- rechercher un niveau intermédiaire avant N1 ;
- retomber sur le niveau entrepôt après l'échec du niveau client ;
- effectuer une requête de prix par produit ;
- dupliquer l'onglet Prix natif ;
- modifier `cash-settlement` ;
- prétendre qu'un POST InvoicePlus de création existe ;
- créer un nouveau POST de facture sans contrat séparé ;
- inventer un entrepôt pour une facture manuelle ;
- ajouter une conversion multidevise personnalisée ;
- supprimer des données à la désactivation du module ;
- casser les routes ou contrats existants.

---

## 19. Critères d'acceptation

La tâche est terminée lorsque :

1. la création produit affiche exactement N niveaux dynamiques lorsque les
   multiprix sont actifs ;
2. les niveaux renseignés sont historisés par les méthodes natives ;
3. un zéro explicite est conservé et un champ vide est ignoré pour N2 à N ;
4. l'édition continue d'utiliser l'onglet Prix natif ;
5. un entrepôt peut recevoir un niveau nullable entre 1 et N ;
6. aucune table de prix supplémentaire n'existe ;
7. le résolveur applique Client > Entrepôt > N1 ;
8. un niveau sans prix retombe directement sur N1 ;
9. le fallback ne transforme jamais un zéro défini en absence ;
10. les deux nouvelles routes retournent les bons prix et métadonnées ;
11. la résolution des prix est groupée et ne produit pas de N+1 de prix ;
12. permissions, ressources et entités sont contrôlées ;
13. le POST `cash-settlement` et les routes existantes sont inchangés ;
14. aucun fichier du coeur ni aucune logique de valorisation n'est modifié ;
15. les tests exécutables passent et les tests non exécutables sont signalés ;
16. la documentation décrit la limite native de N1 et l'absence de POST de
    création InvoicePlus.

---

## 20. Livrable final attendu

Fournir un résumé contenant :

- fichiers créés et modifiés ;
- version du module ;
- hooks et triggers ajoutés ;
- extrafield créé et politique de conservation ;
- routes ajoutées ;
- algorithme exact de résolution ;
- stratégie de requête groupée ;
- tests exécutés et résultats ;
- tests non exécutés et raison ;
- limites restantes ;
- procédure de mise à jour d'une installation existante.

Le résultat doit être directement exploitable dans le module `invoicePlus`,
maintenable lors d'une mise à jour Dolibarr et strictement sans modification du
coeur.