# API de connexion et contexte utilisateur

La personnalisation historique de `api/class/api_login.class.php` a été
extraite du cœur Dolibarr. L'authentification et le contexte métier utilisent
maintenant deux appels explicites :

1. `POST /api/index.php/login` — route native, retourne le token ;
2. `GET /api/index.php/bankauditapi/context` — route du module BankAudit,
   retourne l'entrepôt par défaut et ses comptes/caisses pour un utilisateur
   interne.

Cette séparation permet de mettre à jour Dolibarr sans perdre
l'enrichissement métier.

## 1. Obtenir le token

```http
POST https://VOTRE-DOLIBARR/api/index.php/login
Content-Type: application/json

{
  "login": "utilisateur",
  "password": "mot-de-passe",
  "entity": "1",
  "reset": 0
}
```

Réponse native :

```json
{
  "success": {
    "code": 200,
    "token": "9f6f47942bc8f7ebf6909b27a7555c3d42...",
    "entity": "1",
    "message": "Welcome utilisateur - This is your token ..."
  }
}
```

Le champ historique `success.default_warehouse` n'est plus ajouté à cette
réponse native.

## 2. Charger le contexte métier

Activez BankAudit, puis appelez la route avec le token :

```http
GET https://VOTRE-DOLIBARR/api/index.php/bankauditapi/context
DOLAPIKEY: 9f6f47942bc8f7ebf6909b27a7555c3d42...
DOLAPIENTITY: 1
```

Réponse :

```json
{
  "default_warehouse": {
    "id": 3,
    "ref": "ENT-PRINCIPAL",
    "nom": "Magasin central",
    "description": "Entrepôt principal",
    "adresse": "12 avenue de la Gare",
    "ville": "1000 Bruxelles",
    "pays": "Belgique",
    "telephone": "+32 2 000 00 00",
    "etat": "Opened",
    "comptes_caisses": [
      {
        "id": 5,
        "ref": "CA01",
        "libelle": "Caisse USD",
        "type": "Espèces",
        "numero": "",
        "devise": "USD",
        "taux_conversion": 2850.0,
        "solde": 1250.0,
        "etat": "Opened"
      }
    ]
  }
}
```

`default_warehouse` vaut `null` si l'utilisateur n'a pas d'entrepôt par
défaut ou si celui-ci n'est pas visible dans l'entité API courante.

## Champs retournés

| Champ | Type | Description |
|---|---|---|
| `id` | int | Identifiant de l'entrepôt |
| `ref` | string | Référence de l'entrepôt |
| `nom` | string | Nom/lieu, avec repli sur la référence |
| `description` | string | Description |
| `adresse` | string | Adresse |
| `ville` | string | Code postal et ville |
| `pays` | string | Pays |
| `telephone` | string | Téléphone |
| `etat` | string | `Opened` ou `Closed` |
| `comptes_caisses` | array | Comptes liés par l'extrafield `bank_account.warehouse` |

Chaque compte contient `id`, `ref`, `libelle`, `type`, `numero`, `devise`,
`taux_conversion`, `solde` et `etat`.

## Droits et erreurs

La route de contexte exige :

- un utilisateur Dolibarr interne (les utilisateurs externes ne sont pas
  admis sur l'API BankAudit) ;
- le droit de lire les stocks ;
- le droit de lire les comptes bancaires/caisses ;
- l'accès à l'entrepôt par défaut dans l'entité courante.

| HTTP | Signification |
|---|---|
| 403 | Authentification, droit ou accès à l'entrepôt refusé |
| 503 | Erreur de lecture de la base de données |

Après installation ou mise à jour du module, désactivez/réactivez BankAudit et
purgez le cache REST si `API_PRODUCTION_MODE` est actif.

Conservez le token dans un stockage sécurisé, ne le journalisez pas et ne le
transmettez jamais dans l'URL.
