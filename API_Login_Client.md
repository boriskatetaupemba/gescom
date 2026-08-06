# API Login – Guide d'intégration client

Voici comment s'authentifier sur l'API Dolibarr et exploiter la
réponse personnalisée qui renvoie, en plus du token, **l'entrepôt par défaut de
l'utilisateur** et les **comptes / caisses** qui y sont rattachés.

---

## 1. Endpoint

| Méthode | URL                                            | Usage |
|---------|------------------------------------------------|-------|
| `POST`  | `https://dev-admin.quinleysarlu.com/api/index.php/login`    | Recommandé (identifiants dans le corps) |
| `GET`   | `https://dev-admin.quinleysarlu.com/api/index.php/login`    | Identifiants dans l'URL (à éviter) |

> Si Dolibarr est dans un
> sous-dossier, l'URL ressemble à `https://exemple.com/dolibarr/api/index.php/login`.

### Paramètres

| Nom        | Type   | Obligatoire | Description |
|------------|--------|-------------|-------------|
| `login`    | string | oui         | Identifiant de l'utilisateur |
| `password` | string | oui         | Mot de passe |
| `entity`   | string | non         | Entité (multicompany). Vide = `1` |
| `reset`    | int    | non         | `0` = token existant, `1` = régénère un nouveau token (invalide l'ancien) |

---

## 2. Réponse

### Succès (HTTP 200)

```json
{
  "success": {
    "code": 200,
    "token": "9f6f47942bc8f7ebf6909b27a7555c3d42......",
    "entity": "0",
    "message": "Welcome admin - This is your token (recorded for your user)...",
    "default_warehouse": {
      "id": 3,
      "ref": "ENT-PRINCIPAL",
      "nom": "Magasin Central",
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
}
```

### Champs `default_warehouse`

| Champ             | Type        | Description |
|-------------------|-------------|-------------|
| `id`              | int         | Identifiant de l'entrepôt |
| `ref`             | string      | Référence de l'entrepôt |
| `nom`             | string      | Nom / lieu (repli sur `ref` si vide) |
| `description`     | string      | Description |
| `adresse`         | string      | Adresse |
| `ville`           | string      | Code postal + ville |
| `pays`            | string      | Pays |
| `telephone`       | string      | Téléphone |
| `etat`            | string      | `Opened` ou `Closed` (toujours en anglais) |
| `comptes_caisses` | array       | Comptes / caisses rattachés (voir ci-dessous) |

`default_warehouse` vaut `null` si l'utilisateur n'a pas d'entrepôt par défaut.

### Champs d'un élément de `comptes_caisses`

| Champ             | Type   | Description |
|-------------------|--------|-------------|
| `id`              | int    | Identifiant du compte / caisse |
| `ref`             | string | Référence |
| `libelle`         | string | Libellé |
| `type`            | string | Type de compte (Épargne / Courant / Espèces) |
| `numero`          | string | Numéro de compte |
| `devise`          | string | Code devise (ISO, ex. `USD`, `CDF`, `EUR`) |
| `taux_conversion` | float  | Taux configuré dans Dolibarr (vs devise principale), `1` si non défini |
| `solde`           | float  | Solde courant du compte |
| `etat`            | string | `Opened` ou `Closed` (toujours en anglais) |

### Erreurs

| HTTP | Signification |
|------|---------------|
| 403  | Accès refusé (identifiants invalides ou API login désactivée) |
| 500  | Erreur système (utilisateur introuvable, token invalide…) |

Format d'erreur Dolibarr :

```json
{ "error": { "code": 403, "message": "Access denied" } }
```

---

## 3. Utiliser le token

Pour tout appel ultérieur, transmettez le token dans l'en-tête HTTP `DOLAPIKEY` :

```
DOLAPIKEY: 9f6f47942bc8f7ebf6909b27a7555c3d.........
```

> Conservez le token de manière sécurisée (stockage chiffré / secure storage).
> Ne le journalisez pas et ne l'exposez pas dans des URLs.

---
