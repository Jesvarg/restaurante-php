# Directorio de Restaurantes (Laravel)

Proyecto full-stack enfocado en gestión de restaurantes con roles, moderación y reseñas.

## Resumen

- Arquitectura MVC en Laravel 10 con validaciones en backend.
- Sistema de roles (`user`, `owner`, `admin`) y control de acceso.
- Flujo de moderación real: aprobar, rechazar con motivos estructurados, suspender/reactivar.
- Relación muchos-a-muchos para favoritos y reseñas por usuario.
- Seeders listos para demo con imágenes temáticas de restaurantes desde **Pexels**.

## Stack

- PHP 8.1+
- Laravel 10
- Blade + Bootstrap 5
- Base de datos: SQLite (por defecto para desarrollo)
- Extensiones PHP recomendadas: `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`

## Ejecución local mínima

```bash
composer install
composer run setup:local
composer run serve:local
```

Abrir: `http://127.0.0.1:8000`

## Usuarios demo

- Admin: `admin@restaurantes.com` / `admin123`
- Owner: `owner@restaurantes.com` / `owner123`
- User: `user@restaurantes.com` / `user123`

## Notas técnicas

- Seeder principal: [`database/seeders/RestaurantSeeder.php`](database/seeders/RestaurantSeeder.php)
- Las URLs de imágenes de demo usan `https://images.pexels.com/...`
- Si quieres MySQL, cambia `DB_CONNECTION` en `.env` y ejecuta:

```bash
php artisan migrate --seed
```
