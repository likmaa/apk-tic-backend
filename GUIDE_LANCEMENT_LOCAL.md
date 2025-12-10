# Guide de Lancement Local - Backend Laravel

**Date :** 7 décembre 2025

---

## 🚀 Options de Serveur Local

Vous avez **3 options** pour lancer le backend en local :

### Option 1 : Serveur PHP Intégré (Recommandé pour développement rapide)
### Option 2 : Laravel Sail (Docker - Recommandé pour environnement complet)
### Option 3 : Docker Compose (Production-like)

---

## 📋 Prérequis

### Pour toutes les options :
- ✅ PHP 8.2 ou supérieur
- ✅ Composer installé
- ✅ MySQL 8.0 (ou via Docker)
- ✅ Node.js et npm (pour les assets)

### Pour Option 2 et 3 :
- ✅ Docker et Docker Compose installés

---

## 🎯 Option 1 : Serveur PHP Intégré (Le plus simple)

### Avantages
- ✅ Démarrage rapide
- ✅ Pas besoin de Docker
- ✅ Idéal pour développement rapide

### Inconvénients
- ⚠️ Nécessite MySQL installé localement
- ⚠️ Nécessite Soketi pour WebSockets

### Étapes

1. **Installer les dépendances** :
```bash
cd backend
composer install
```

2. **Configurer l'environnement** :
```bash
cp .env.example .env
php artisan key:generate
```

3. **Configurer `.env`** :
```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apk_tic
DB_USERNAME=root
DB_PASSWORD=your_password

# WebSockets (Soketi)
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http

# KYA SMS
KYA_SMS_API_KEY=your_api_key
KYA_SMS_BASE_URL=https://api.kyasms.com
KYA_SMS_FROM=your_sender_id
```

4. **Créer la base de données** :
```bash
# Dans MySQL
CREATE DATABASE apk_tic;
```

5. **Lancer les migrations** :
```bash
php artisan migrate
```

6. **Lancer le serveur** :
```bash
# Option A : Serveur simple
php artisan serve

# Option B : Avec queue et logs (recommandé)
composer dev
```

### URLs
- **API** : http://localhost:8000
- **API Routes** : http://localhost:8000/api

### Commandes utiles
```bash
# Voir les logs en temps réel
php artisan pail

# Lancer la queue
php artisan queue:work

# Vider le cache
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

---

## 🐳 Option 2 : Laravel Sail (Docker - Recommandé)

### Avantages
- ✅ Environnement isolé (Docker)
- ✅ MySQL, Redis, Soketi inclus
- ✅ Configuration automatique
- ✅ Identique à la production

### Inconvénients
- ⚠️ Nécessite Docker
- ⚠️ Plus lent au démarrage

### Étapes

1. **Installer les dépendances** :
```bash
cd backend
composer install
```

2. **Configurer l'environnement** :
```bash
cp .env.example .env
php artisan key:generate
```

3. **Configurer `.env` pour Sail** :
```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=apk_tic
DB_USERNAME=sail
DB_PASSWORD=password

# WebSockets (Soketi via Sail)
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

4. **Lancer Sail** :
```bash
# Si c'est la première fois
./vendor/bin/sail up -d

# Ou avec alias (ajouter à ~/.zshrc ou ~/.bashrc)
alias sail='./vendor/bin/sail'
sail up -d
```

5. **Lancer les migrations** :
```bash
sail artisan migrate
```

6. **Accéder au serveur** :
```bash
# Le serveur est accessible sur http://localhost
# Sail utilise le port 80 par défaut
```

### Commandes Sail
```bash
# Démarrer les conteneurs
sail up -d

# Arrêter les conteneurs
sail down

# Voir les logs
sail logs

# Exécuter des commandes artisan
sail artisan migrate
sail artisan queue:work

# Accéder au shell du conteneur
sail shell

# Installer des dépendances
sail composer install
sail npm install
```

### Services disponibles
- **Laravel** : http://localhost
- **MySQL** : localhost:3306
- **Soketi** : localhost:6001 (si configuré)
- **Redis** : localhost:6379 (si activé)

---

## 🐳 Option 3 : Docker Compose (Production-like)

### Avantages
- ✅ Environnement identique à la production
- ✅ Nginx inclus
- ✅ Configuration complète

### Inconvénients
- ⚠️ Plus complexe
- ⚠️ Nécessite configuration Nginx

### Étapes

1. **Configurer `.env`** :
```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=apk_tic
DB_USERNAME=root
DB_PASSWORD=your_password
```

2. **Lancer avec Docker Compose** :
```bash
# Utiliser le fichier docker-compose.prod.yml
docker-compose -f docker-compose.prod.yml up -d
```

3. **Lancer les migrations** :
```bash
docker-compose -f docker-compose.prod.yml exec backend php artisan migrate
```

---

## 🔧 Configuration WebSockets (Soketi)

Pour que les WebSockets fonctionnent en local, vous devez lancer Soketi :

### Option A : Via Docker
```bash
# Lancer Soketi seul
docker run -d \
  -p 6001:6001 \
  -p 9601:9601 \
  -e APP_ID=your_app_id \
  -e APP_KEY=your_app_key \
  -e APP_SECRET=your_app_secret \
  quay.io/soketi/soketi:1.0-16-debian
```

### Option B : Via npm (global)
```bash
npm install -g @soketi/soketi
soketi start
```

### Option C : Via docker-compose.soketi.yml
```bash
cd backend
docker-compose -f docker-compose.soketi.yml up -d
```

---

## ✅ Vérification

### Tester que le serveur fonctionne

1. **Vérifier la santé** :
```bash
curl http://localhost:8000/api/health
# Devrait retourner : {"status":"ok"}
```

2. **Tester un endpoint** :
```bash
curl http://localhost:8000/api/geocoding/search?query=paris
```

3. **Vérifier les logs** :
```bash
# Option 1
tail -f storage/logs/laravel.log

# Option 2 (avec Sail)
sail logs -f

# Option 3 (avec artisan pail)
php artisan pail
```

---

## 🐛 Dépannage

### Problème : Port 8000 déjà utilisé
```bash
# Utiliser un autre port
php artisan serve --port=8001
```

### Problème : Erreur de connexion MySQL
- Vérifier que MySQL est lancé
- Vérifier les credentials dans `.env`
- Vérifier que la base de données existe

### Problème : Erreur "Class not found"
```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

### Problème : WebSockets ne fonctionnent pas
- Vérifier que Soketi est lancé
- Vérifier les variables `PUSHER_*` dans `.env`
- Vérifier que le port 6001 est accessible

---

## 📝 Recommandation

**Pour le développement rapide** : Utilisez **Option 1** (serveur PHP intégré)
```bash
composer dev
```

**Pour un environnement complet** : Utilisez **Option 2** (Laravel Sail)
```bash
sail up -d
```

---

## 🔗 URLs Importantes

- **API Base** : http://localhost:8000/api
- **Health Check** : http://localhost:8000/api/health
- **Geocoding** : http://localhost:8000/api/geocoding/search
- **WebSockets** : ws://localhost:6001

---

**Bon développement ! 🚀**
