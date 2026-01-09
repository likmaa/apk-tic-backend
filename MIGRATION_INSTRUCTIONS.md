# Instructions pour Exécuter les Migrations

## Nouvelles Migrations Créées

Deux nouvelles migrations ont été créées pour les analytics et métriques :

1. **`2026_01_10_120000_create_analytics_reconnections_table.php`**
   - Table pour stocker les événements de reconnexion réseau
   - Utilisée pour tracker les déconnexions/reconnexions des apps mobiles

2. **`2026_01_10_120100_create_app_metrics_table.php`**
   - Table pour stocker les métriques de performance
   - Utilisée pour tracker les appels API, WebSocket, polling, etc.

## Exécution des Migrations

### En Développement Local

```bash
cd backend
php artisan migrate
```

### En Production

```bash
cd backend
php artisan migrate --force
```

## Vérification

Après l'exécution, vous pouvez vérifier que les tables existent :

```bash
php artisan tinker
```

Puis dans tinker :
```php
DB::table('analytics_reconnections')->count();
DB::table('app_metrics')->count();
```

## Rollback (si nécessaire)

Si vous devez annuler les migrations :

```bash
php artisan migrate:rollback --step=2
```

Ou pour annuler une migration spécifique :

```bash
php artisan migrate:rollback --path=database/migrations/2026_01_10_120000_create_analytics_reconnections_table.php
```

## Notes

- Les tables sont créées avec les index appropriés pour les performances
- Les clés étrangères sont configurées avec `onDelete('cascade')` pour `user_id` et `onDelete('set null')` pour `ride_id`
- Les données sont automatiquement nettoyées si un utilisateur ou une course est supprimé
