# Corrections du système OTP

## 🔴 Problèmes identifiés

### 1. **Bug de cache OTP - Cache jamais nettoyé**
- **Problème** : Le cache était stocké avec la clé `kya_otp_key_` + phone mais nettoyé avec `otp_` + phone
- **Impact** : Le cache OTP n'était jamais supprimé, bloquant l'envoi de nouveaux codes
- **Fichier** : `backend/app/Http/Controllers/Auth/OtpController.php` ligne 147

### 2. **Cache bloquant l'envoi de nouveaux codes**
- **Problème** : Si un code existait déjà en cache, le backend renvoyait `already_exists` sans permettre d'envoyer un nouveau code
- **Impact** : Si l'utilisateur n'avait pas reçu le SMS ou si le code avait expiré, il ne pouvait pas en demander un nouveau
- **Fichier** : `backend/app/Services/KyaSmsService.php` lignes 64-75

### 3. **Pas d'option pour forcer un nouveau code**
- **Problème** : Aucun moyen de forcer l'envoi d'un nouveau code même si un cache existait
- **Impact** : L'utilisateur était bloqué s'il n'avait pas reçu le SMS

## ✅ Corrections apportées

### 1. Correction du nettoyage du cache
**Fichier** : `backend/app/Http/Controllers/Auth/OtpController.php`

```php
// AVANT (BUG)
cache()->forget('otp_' . $phone);

// APRÈS (CORRIGÉ)
cache()->forget('kya_otp_key_' . $phone);
```

### 2. Ajout du paramètre `force_new`
**Fichier** : `backend/app/Services/KyaSmsService.php`

- Ajout du paramètre `$forceNew = false` à la méthode `sendOtp()`
- Si `$forceNew = true`, l'ancien cache est supprimé et un nouveau code est envoyé
- Si `$forceNew = false` et qu'un cache existe, retourne `already_exists` avec la clé existante

**Fichier** : `backend/app/Http/Controllers/Auth/OtpController.php`

- Ajout de la validation `'force_new' => ['sometimes', 'boolean']`
- Passage du paramètre `$forceNew` à `sendOtp()`

### 3. Amélioration du frontend
**Fichier** : `Apk Tic/driver-app/app/driver-phone-login.tsx`

- Gestion du cas `already_exists` : si un code existe déjà, on utilise la clé existante
- Bouton "Renvoyer le code" : envoie maintenant `force_new: true` pour forcer un nouveau code
- Logs de débogage améliorés pour diagnostiquer les problèmes

## 🔧 Comment utiliser

### Forcer l'envoi d'un nouveau code (backend)
```php
// Forcer un nouveau code même si un cache existe
$providerResponse = $this->kyaSms->sendOtp($phone, true);
```

### Forcer l'envoi d'un nouveau code (frontend)
```typescript
// Dans la requête
body: JSON.stringify({ 
  phone: e164, 
  force_new: true  // Force l'envoi d'un nouveau code
})
```

### Vérifier le cache (pour debug)
```bash
# Dans Laravel Tinker
php artisan tinker
>>> cache()->get('kya_otp_key_+229XXXXXXXX')
```

### Nettoyer manuellement le cache
```bash
# Dans Laravel Tinker
php artisan tinker
>>> cache()->forget('kya_otp_key_+229XXXXXXXX')
```

## 📊 Flux corrigé

1. **Premier envoi** : 
   - Pas de cache → Envoie un nouveau code via KYA SMS
   - Stocke la clé dans le cache (`kya_otp_key_` + phone) pour 10 minutes

2. **Deuxième envoi (sans force_new)** :
   - Cache existe → Retourne `already_exists` avec la clé existante
   - Frontend peut utiliser la clé existante pour la vérification

3. **Deuxième envoi (avec force_new=true)** :
   - Cache existe → Supprime l'ancien cache
   - Envoie un nouveau code via KYA SMS
   - Stocke la nouvelle clé dans le cache

4. **Vérification réussie** :
   - Cache nettoyé automatiquement avec la bonne clé (`kya_otp_key_` + phone)

## ⚠️ Points d'attention

1. **Expiration du cache** : Le cache expire automatiquement après 10 minutes
2. **API KYA SMS** : Vérifier que l'API key est valide et que le service répond
3. **Logs** : Les logs sont disponibles dans `storage/logs/laravel.log` pour diagnostiquer les problèmes

## 🧪 Tests à effectuer

1. ✅ Envoi d'un premier code OTP
2. ✅ Tentative d'envoi d'un deuxième code sans `force_new` (doit retourner `already_exists`)
3. ✅ Envoi d'un nouveau code avec `force_new=true` (doit envoyer un nouveau SMS)
4. ✅ Vérification du code OTP (doit nettoyer le cache)
5. ✅ Vérifier que le cache est bien nettoyé après vérification réussie
