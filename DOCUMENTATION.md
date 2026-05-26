# Projet PHP - 2026
**HOUDART KERYAN**
**DELCROIX AXEL**

# Documentation Technique — Photo API

## 0. Installation (nouveau poste)

**Prérequis** : PHP 8.2+, Composer, PostgreSQL

```bash
# 1. Installer les dépendances
composer install --no-audit

# 2. Créer le fichier de config local
cp .env .env.local
# Éditer .env.local : renseigner DATABASE_URL avec tes identifiants PostgreSQL
```

```bash
# 3. Générer les clés JWT (Windows : définir OPENSSL_CONF avant)
# Windows PowerShell :
$env:OPENSSL_CONF = "C:\php-x.x.x\extras\ssl\openssl.cnf"

php bin/console lexik:jwt:generate-keypair
```

```bash
# 4. Créer les tables
php bin/console doctrine:schema:create

# 5. Charger les données de test
php bin/console doctrine:fixtures:load

# 6. Lancer le serveur
php -S localhost:8000 -t public
```

> **Note** : après `lexik:jwt:generate-keypair`, le fichier `.env` est mis à jour avec une nouvelle `JWT_PASSPHRASE`. Copie cette valeur dans `.env.local` pour qu'elle soit prise en compte.

---

## 1. Présentation du projet

**Objectif** : API REST de partage de photos permettant à des utilisateurs de publier des images, les commenter et les liker.

**Fonctionnalités** :
- Création de compte et authentification JWT
- Publication de posts (image via URL, titre, description)
- Commentaires imbriqués (réponses à un commentaire)
- Likes avec toggle (like / unlike)
- Espace admin pour gérer utilisateurs, posts et commentaires

---

## 2. Conception

### Entités

| Entité | Champs principaux |
|--------|-------------------|
| `User` | id (UUID), email, username, password, roles, createdAt |
| `Post` | id (UUID), title, description, imageUrl, createdAt, updatedAt, user |
| `Comment` | id (UUID), content, createdAt, user, post, parent (nullable) |
| `Like` | id (UUID), createdAt, user, post |

### Relations

```
User ──< Post       (OneToMany)
User ──< Comment    (OneToMany)
User ──< Like       (OneToMany)
Post ──< Comment    (OneToMany)
Post ──< Like       (OneToMany)
Comment ──< Comment (self-referencing, parent/enfant)
```

**Contrainte** : un utilisateur ne peut liker un post qu'une seule fois (`UNIQUE` sur user_id + post_id).

### Choix de modélisation
- **UUID** comme identifiant pour éviter l'énumération des ressources
- Table `Like` nommée `post_like` pour éviter le conflit avec le mot réservé SQL
- Commentaires imbriqués sur un seul niveau de récursion (parent → enfants)

---

## 3. Architecture

### Organisation du code

```
src/
├── Controller/
│   ├── AuthController.php       # POST /api/register
│   ├── PostController.php       # CRUD posts
│   ├── CommentController.php    # CRUD commentaires
│   ├── LikeController.php       # Toggle like
│   └── AdminController.php      # Routes admin
├── Entity/
│   ├── User.php
│   ├── Post.php
│   ├── Comment.php
│   └── Like.php
├── Repository/
│   ├── UserRepository.php
│   ├── PostRepository.php
│   ├── CommentRepository.php
│   └── LikeRepository.php
└── DataFixtures/
    └── AppFixtures.php          # Données de test
```

### Séparation des responsabilités
- **Controller** : validation des entrées, logique métier, retour JSON
- **Entity** : mapping Doctrine, contraintes de base
- **Repository** : requêtes personnalisées (pagination, eager loading)

### Choix techniques
| Choix | Justification |
|-------|---------------|
| Symfony 7.3 | Framework PHP structuré, adapté aux APIs REST |
| PostgreSQL | Base relationnelle robuste, support natif UUID |
| Doctrine ORM | Abstraction BDD, migrations, UUIDs automatiques |
| LexikJWT | Standard pour l'auth stateless en API |
| NelmioCORS | Gestion des headers CORS pour les clients web/mobile |
| Sérialisation manuelle | Évite les dépendances circulaires entre entités |

---

## 4. Sécurité

### Authentification

JWT (JSON Web Token) via `lexik/jwt-authentication-bundle` :
1. Le client envoie `POST /api/login` avec email + password
2. Symfony vérifie les credentials via le provider Doctrine
3. Un token signé (RS256, clé RSA 4096 bits) est retourné
4. Le client inclut le token dans chaque requête : `Authorization: Bearer <token>`
5. Token valide 3600 secondes (1 heure)

### Gestion des rôles

| Rôle | Accès |
|------|-------|
| `ROLE_USER` | Créer posts/commentaires, liker, modifier/supprimer ses propres ressources |
| `ROLE_ADMIN` | Toutes les routes `/api/admin/*`, suppression de n'importe quelle ressource |

### Protection des routes

| Routes | Accès |
|--------|-------|
| `POST /api/login`, `POST /api/register` | Public |
| `GET /api/posts`, `GET /api/posts/{id}/comments` | Public |
| `POST/PUT/DELETE /api/posts`, `POST/DELETE /api/comments`, `POST /api/posts/{id}/like` | `IS_AUTHENTICATED_FULLY` |
| `GET/DELETE /api/admin/*`, `PATCH /api/admin/users/{id}/role` | `ROLE_ADMIN` |

**Contrôles supplémentaires dans les controllers** :
- Vérification que l'utilisateur connecté est bien le propriétaire de la ressource avant modification/suppression
- Un admin ne peut pas supprimer son propre compte
- Un commentaire enfant doit appartenir au même post que son parent

---

## 5. Tests de l'API

### Obtenir un token

```http
POST http://localhost:8000/api/login
Content-Type: application/json

{"username": "admin@photo.com", "password": "Admin1234!"}
```

Réponse : `{"token": "eyJ..."}`

### Utiliser le token

Dans chaque requête authentifiée, ajouter le header :
```
Authorization: Bearer eyJ...
```

### Comptes de test disponibles

| Email | Password | Rôle |
|-------|----------|------|
| admin@photo.com | Admin1234! | ROLE_ADMIN |
| user1@photo.com | User1234! | ROLE_USER |
| user2@photo.com | User1234! | ROLE_USER |
| user3@photo.com | User1234! | ROLE_USER |

### Exemples de cas d'utilisation

**Créer un post (authentifié)**
```http
POST /api/posts
Authorization: Bearer <token>
Content-Type: application/json

{"title": "Mon post", "imageUrl": "https://picsum.photos/800/600", "description": "Test"}
```

**Commenter avec réponse imbriquée**
```http
POST /api/posts/{postId}/comments
Authorization: Bearer <token>
Content-Type: application/json

{"content": "Super photo!", "parentId": "<id-du-commentaire-parent>"}
```

**Liker / unliker**
```http
POST /api/posts/{postId}/like
Authorization: Bearer <token>
```
Retourne : `{"liked": true, "likesCount": 5}`

**Changer le rôle d'un utilisateur (admin)**
```http
PATCH /api/admin/users/{id}/role
Authorization: Bearer <token-admin>
Content-Type: application/json

{"role": "ROLE_ADMIN"}
```