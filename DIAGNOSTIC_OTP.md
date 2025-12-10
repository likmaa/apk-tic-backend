# Diagnostic OTP - Guide de dépannage

## 🔍 Étapes de diagnostic

### 1. Vérifier les logs Laravel

Sur le serveur de production, vérifiez les logs :

```bash
# Dans Coolify, terminal de l'application backend
tail -f storage/logs/laravel.log | grep -i "kya\|otp"
```

Vous devriez voir :
- `KYA SMS OTP send payload` - Le payload envoyé
- `KYA SMS OTP send response` - La réponse de KYA SMS
- Les erreurs éventuelles

### 2. Tester directement l'API KYA SMS

Utilisez le script de test :

```bash
cd /path/to/backend
php test-kyasms.php +229XXXXXXXX
```

Ce script va :
- Tester la connexion à KYA SMS
- Afficher la réponse complète
- Identifier les erreurs

### 3. Vérifier la configuration

**Dans le fichier `KyaSmsService.php`** :
- ✅ API Key : `kyasms661efc85b7b3c8f0d90cd7f21097e731e05b029cedcf265319b853dd67`
- ✅ Base URL : `https://route.kyasms.com/api/v3`
- ✅ App ID : `9DILGC5Y`

**Vérifier dans les variables d'environnement** (si déployé) :
```env
KYASMS_API_KEY=kyasms661efc85b7b3c8f0d90cd7f21097e731e05b029cedcf265319b853dd67
KYASMS_BASE_URL=https://route.kyasms.com/api/v3
KYASMS_FROM=TICMITON
```

### 4. Vérifier le format du numéro

Le numéro doit être :
- Format E.164 : `+229XXXXXXXX`
- Envoyé à KYA SMS sans le `+` : `229XXXXXXXX`

### 5. Vérifier le cache

Si un code existe déjà en cache, le backend retourne `already_exists` au lieu d'envoyer un nouveau code.

**Pour vider le cache manuellement** :
```bash
php artisan tinker
>>> cache()->forget('kya_otp_key_+229XXXXXXXX');
```

**Pour forcer un nouveau code** :
- Utiliser le paramètre `force_new: true` dans la requête
- Ou utiliser le bouton "Renvoyer le code" dans l'app

## 🐛 Problèmes courants

### Problème 1 : Erreur HTTP 401 ou 403
**Cause** : API key invalide ou expirée
**Solution** : Vérifier l'API key dans le compte KYA SMS

### Problème 2 : Erreur HTTP 400
**Cause** : Format du numéro incorrect ou paramètres manquants
**Solution** : Vérifier le format du numéro et le payload

### Problème 3 : Pas de réponse (timeout)
**Cause** : Problème de connexion réseau ou service KYA SMS indisponible
**Solution** : Vérifier la connectivité réseau et le statut de KYA SMS

### Problème 4 : Réponse 200 mais pas de SMS reçu
**Causes possibles** :
- Le numéro est bloqué par l'opérateur
- Le service KYA SMS a un problème
- Le crédit KYA SMS est épuisé
- Le numéro est invalide

**Solution** :
1. Vérifier les logs KYA SMS dans leur dashboard
2. Tester avec un autre numéro
3. Contacter le support KYA SMS

### Problème 5 : Cache bloquant
**Cause** : Un code existe déjà en cache et n'a pas expiré
**Solution** : Utiliser `force_new: true` ou attendre 10 minutes

## 📊 Vérification des logs dans l'app

Dans l'application mobile, vérifiez les logs dans le terminal Metro :

```
[DriverPhoneLogin] Envoi de la demande OTP pour: +229XXXXXXXX
[DriverPhoneLogin] Réponse OTP - Status: 200 OK: true
[DriverPhoneLogin] Réponse JSON OTP: {...}
```

Si vous voyez une erreur, les logs indiqueront le problème.

## 🔧 Actions correctives

### Si l'API key est invalide
1. Se connecter au dashboard KYA SMS
2. Vérifier/générer une nouvelle API key
3. Mettre à jour dans `KyaSmsService.php` ou les variables d'environnement

### Si le service ne répond pas
1. Vérifier le statut de KYA SMS : https://status.kyasms.com (si disponible)
2. Tester avec curl directement :
```bash
curl -X POST https://route.kyasms.com/api/v3/otp/create \
  -H "APIKEY: kyasms661efc85b7b3c8f0d90cd7f21097e731e05b029cedcf265319b853dd67" \
  -H "Content-Type: application/json" \
  -d '{"appId":"9DILGC5Y","recipient":"229XXXXXXXX","lang":"fr"}'
```

### Si le cache bloque
1. Vider le cache manuellement (voir ci-dessus)
2. Ou utiliser `force_new: true` dans la requête

## 📞 Support KYA SMS

Si le problème persiste :
1. Vérifier le dashboard KYA SMS pour voir les statistiques d'envoi
2. Vérifier les crédits disponibles
3. Contacter le support KYA SMS avec :
   - Le numéro testé
   - L'heure de la tentative
   - Les logs de la réponse

