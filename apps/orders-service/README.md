# Orders service (backend)

Internal Laravel microservice for the Orders domain. Lives under `apps/` like every other service.

Portals and browsers must **not** call this app. They talk only to the gateway (`apps/api`). Enable the proxy when this process is running:

```env
# apps/api/.env
GATEWAY_ORDERS_ENABLED=true
ORDERS_SERVICE_URL=http://127.0.0.1:8001
```

## Run this service only

```bash
cd apps/orders-service
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=127.0.0.1 --port=8001
```

Or from the repo root:

```bash
npx nx serve orders-service
```

Liveness: http://127.0.0.1:8001/up  
Module health: http://127.0.0.1:8001/api/v1/orders/health
