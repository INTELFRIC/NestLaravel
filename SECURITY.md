# Security

## Reporting a vulnerability

Please report privately (GitHub Security Advisories → "Report a vulnerability", or security@nestlaravel.dev). Do not open
public issues for vulnerabilities. We acknowledge within 72 hours and coordinate disclosure.

## Threat model in one page

```text
PUBLIC     apps/api                 the only internet-facing process
INTERNAL   apps/<name>-service      never published; reachable only from the gateway network
SERVICE-TO-SERVICE                  gateway → service: HMAC-signed HTTP · service ↔ service: Kafka (TLS+SASL+ACL)
ADMIN      artisan, Kafka UI, DB    private network / VPN only; tenancy bypass is explicit code (`withoutTenancy`)
```

An attacker who can reach a service port directly must still fail: every request needs a signature made with that
service's private secret, bound to method + path + query + body hash + user + tenant, valid for 60 s, single-use.
A service without a configured secret answers 503 (fail closed). **Never publish service ports** and keep them on a
private network as defence in depth.

## Controls (built in)

| Area | Control |
|------|---------|
| Authn | Sanctum bearer tokens, **24 h expiry** (`SANCTUM_TOKEN_EXPIRATION`), bcrypt (cost 12), `Password::defaults()` |
| Authz | Roles/permissions (`permission:` middleware, gates). **Self-registration may only grant `auth.self_registration_roles`** (default `customer`); privileged roles are admin-assigned |
| Brute force | `throttle:auth`: 10/min per IP, 5/min per account+IP, 30/h per account; `throttle:api` 60/min per user; `mcp` 30/min |
| Input | FormRequest validation; DTOs; Eloquent bindings (no raw SQL); gateway path sanitiser (`..`, control chars, `%2e`, `%2f`) |
| SSRF | Gateway only calls configured base URLs, never follows redirects, ignores client-supplied hosts |
| Mass assignment | explicit `$fillable`; tenant column is never mass-assignable |
| CORS / CSRF | Explicit origin allow-list (`CORS_ALLOWED_ORIGINS`); stateless token API, no cookie auth on `/api` |
| Headers | `nosniff`, `X-Frame-Options: DENY`, CSP, `Referrer-Policy`, COOP/CORP, `Permissions-Policy` |
| Secrets | none in source; `.env` git-ignored, excluded from Docker context and the npm package; `create` generates unique `APP_KEY`, DB, Redis and per-service signing secrets |
| Errors | `APP_DEBUG=false` by default; 5xx bodies are generic; health output omits broker addresses |
| Kafka | TLS/SASL settings, `acks=all` + idempotence, manual commits, DLQ, schema-version guard (see KAFKA.md) |
| Containers | pinned images, infra ports on `127.0.0.1`, Redis `requirepass`, Postgres role+database per service, `server_tokens off` |
| Supply chain | `composer audit` / `npm audit` in CI; npm publish with provenance; template secret-scan blocks release |

## Production checklist

* [ ] TLS terminates at a reverse proxy/load balancer in front of the gateway; HSTS enabled there.
* [ ] `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY` per app, secrets from a vault/Docker/K8s secrets.
* [ ] `SESSION_ENCRYPT=true` if sessions are used; `CORS_ALLOWED_ORIGINS` lists only your frontends.
* [ ] Service containers have **no published ports**; network policy allows gateway → service only.
* [ ] Kafka: `sasl_ssl`, per-service principals, least-privilege ACLs (WRITE own topics, READ consumed topics + group),
      `auto.create.topics.enable=false`, replication ≥ 3.
* [ ] Postgres: per-service role/DB (generated), TLS, no superuser in app config; Redis: password + TLS or private net.
* [ ] Rotate `<NAME>_SERVICE_SECRET` by setting the new value on the service first, then the gateway (brief 401s), or
      run both during a maintenance window; rotate on personnel change.
* [ ] `composer audit` and `npm audit` clean in CI; dependabot/renovate on.
* [ ] Portals: prefer an httpOnly-cookie BFF over `localStorage` bearer tokens (XSS can read `localStorage`).

## Audit summary for 1.0.0

Full write-up of findings and fixes is in the release notes ([CHANGELOG.md](CHANGELOG.md#100)). Highlights: self-service
privilege escalation to `platform_admin` (Critical, fixed), unauthenticated gateway → unauthenticated internal
services (Critical, fixed with signed service auth), outbox marked messages published before broker confirmation
(High, fixed), missing production Kafka consumer/TLS/SASL (High, added), vulnerable transitive packages (High,
updated), default credentials in Compose files (Medium, removed).

Known limitations: no rate limit on service-to-service traffic beyond the gateway's; portals still use
`localStorage`; single outbox publisher per service; metrics endpoint not included (use your APM/OTel agent).
