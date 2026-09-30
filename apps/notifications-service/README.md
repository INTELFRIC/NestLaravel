# Notifications service (backend)

Internal Laravel microservice for the Notifications domain. Lives under `apps/` like every other service.

Portals and browsers must **not** call this app. They talk only to the gateway (`apps/api`). Enable the proxy when this process is running:

```env
# apps/api/.env
GATEWAY_NOTIFICATIONS_ENABLED=true
NOTIFICATIONS_SERVICE_URL=http://127.0.0.1:8003
```

Until the proxy is enabled, the Notifications **module** still runs in-process on `apps/api`.

## Run this service only

```bash
cd apps/notifications-service
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8003
```

Or from the repo root:

```bash
npx nx serve notifications-service
```

Liveness: http://127.0.0.1:8003/up  
Module health: http://127.0.0.1:8003/api/v1/notifications/health
