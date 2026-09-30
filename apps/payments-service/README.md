# Payments service (backend)

Internal Laravel microservice for the Payments domain. Lives under `apps/` like every other service.

Portals and browsers must **not** call this app. They talk only to the gateway (`apps/api`). Enable the proxy when this process is running:

```env
# apps/api/.env
GATEWAY_PAYMENTS_ENABLED=true
PAYMENTS_SERVICE_URL=http://127.0.0.1:8002
```

## Run this service only

```bash
cd apps/payments-service
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8002
```

Or from the repo root:

```bash
npx nx serve payments-service
```

Liveness: http://127.0.0.1:8002/up  
Module health: http://127.0.0.1:8002/api/v1/payments/health
