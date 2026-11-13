# Render + TiDB Cloud deployment preparation

This guide describes the container and the remaining verification gates for Festora on Render with TiDB Cloud. It does not authorize or perform a deployment, database connection, or migration.

## Render container

The root `Dockerfile` builds PHP 8.4 with Apache, `pdo_mysql`, `mbstring`, and `mod_rewrite`. Apache serves `/var/www/html/public` as its `DocumentRoot`, enables the required `.htaccess` overrides, and listens on `0.0.0.0:$PORT`; the container defaults `PORT` to `10000` when it is not supplied.

Build a Render Web Service from the repository using the Docker runtime. Render supplies the runtime port. Do not put credentials in the Dockerfile, image, or build arguments.

The image copies only runtime application files, public assets, CLI scripts, and versioned migrations. `tests/`, local config, environment files, secret material, backups, SQL dumps other than migrations, and the obsolete InfinityFree upload package are excluded. Application controllers and configuration code remain outside `public/`; the `public/.htaccess` route allowlist remains active. Do not change Apache's document root to the project root.

For local development, start PHP's built-in server from the project directory with its protected router:

```text
php -S localhost:8000 -t public router.php
```

The router refuses to run unless `public/` is the document root. Never serve the project root directly.

## Render environment settings

Set these required application variables in the Render service's environment settings:

- `FESTORA_DB_HOST`
- `FESTORA_DB_PORT` — optional; defaults to `4000` when unset. Set the provider-supplied port explicitly when it differs.
- `FESTORA_DB_NAME`
- `FESTORA_DB_USER`
- `FESTORA_DB_PASS`
- `FESTORA_DB_SSL_CA` — optional path to a readable CA bundle. When omitted, the application uses a readable system CA bundle if available; otherwise database connections fail closed.

Other supported variables:

- `FESTORA_ENV` — unset or any value other than the exact development mode is treated as production.
- `FESTORA_TZ` — optional timezone override.
- `FESTORA_STATE_DIR` — set explicitly to an absolute, writable path outside the project and document root. Use a persistent mount if rate-limit state must survive restarts; the container's default state path and permissions have not been verified.
- `FESTORA_TRUSTED_PROXY_IPS` — optional comma-separated exact proxy IPs. Configure only after Render's current proxy addresses and forwarding behavior are verified. Do not trust arbitrary forwarded headers or CIDRs.
- `FESTORA_DEBUG` — development-only; do not enable in production.

Database connections use the MySQL protocol with `utf8mb4`, the configured port, TLS, and mandatory server-certificate verification. Local MySQL servers must also support TLS and have a trusted CA bundle configured; there is no plaintext fallback. For TiDB Cloud Starter, use the public endpoint details from the TiDB connection dialog and ensure the Render service's outbound IP addresses are allowed by the cluster firewall. The PHP container installs `ca-certificates`; if the system bundle is not suitable for the provider's certificate chain, configure `FESTORA_DB_SSL_CA` with the provider-trusted CA bundle path. Do not disable certificate verification or put private keys/passwords in the image.

## TiDB Cloud compatibility gates

No TiDB connection or migration has been performed. Before deployment:

1. Verify the selected TiDB Cloud cluster's MySQL endpoint, port, TLS requirement, CA/certificate verification method, and account permissions using provider documentation.
2. Verify the configured host, port, CA bundle, and certificate verification against a disposable TiDB schema before production use. The application defaults an unset port to `4000`; local MySQL installations using another port must configure it explicitly.
3. Verify the five ordered files in `database/migrations/` and the migration runner against a newly created disposable TiDB schema. The runner uses MySQL advisory locks (`GET_LOCK` and `RELEASE_LOCK`) and inspects `information_schema`; support and behavior must be confirmed on the selected TiDB version.
4. Confirm the new database defaults and resulting table character sets are `utf8mb4`. The migrations are the schema source of truth; the historical reset dump is not an installation path and is not included in the container.
5. Use a fresh, empty deployment database only after the migration compatibility and backup/recovery plan are approved. Do not use the regression suite against TiDB or production; it creates and drops disposable schemas on its configured database server.

Keep migration privileges separate from the restricted application runtime account where the deployment process permits. Provide migration credentials only to the controlled migration job, not as Docker build-time values.

## Persistent application state

The application stores login rate-limit state in files outside the project and document root. On Render, set `FESTORA_STATE_DIR` to an absolute, writable path outside `/var/www/html`; the Apache worker must be able to create and protect the state files. A non-writable or invalid path causes login to fail closed. Use persistent storage if rate-limit state must survive restarts.

PHP sessions use PHP's file-based session handler by default. They are stored on the container filesystem and may be lost on restart or deployment. The application does not currently configure an external session store or a session path environment variable. Decide and test a persistence strategy before production; a mounted disk is not shared state for multiple instances, so file-backed sessions and rate limits require a single-instance design or a separately implemented shared store.

There is no upload handler or upload storage in the application. Do not place future uploads under `public/`; new upload functionality requires a separate private-storage and non-executable serving design.

## Public/private boundary

The intended URL root is `public/`, which contains the approved route wrappers and static assets. The private controllers, `config.php`, migration files, scripts, and tests are outside the document root. The root `.htaccess` fails closed if the project root is accidentally served; `public/.htaccess` denies private URL prefixes and unapproved PHP-like paths. The Docker Apache virtual host grants access only to `public/` and allows the checked-in overrides needed for that policy.

Render uses the included Apache container, not Nginx. If the application is later moved to Nginx, its equivalent policy must deny private paths and pass only approved entry points to PHP-FPM. At minimum, the route policy must include:

```nginx
location ~* /(?:uploads|private-storage)(?:/|$) { return 404; }
location ~ ^/(?:index|events|event|login|signup|logout|register|my-registrations)\.php$ {
    # Configure the verified PHP-FPM handler for this allowlisted route only.
}
```

This is a policy illustration, not a complete Nginx server configuration.

## Verification before release

- Inspect the Render build output and confirm the image contains no local config, environment files, credentials, backups, or SQL dumps outside `database/migrations/`.
- Verify the running container listens on Render's assigned port and serves the home page and approved student, organizer, and API routes.
- Test that private configuration, controllers, scripts, migrations, tests, and unapproved PHP paths cannot be requested over HTTP.
- Verify HTTPS, secure session cookies, logging, rate-limit state, and the selected session persistence behavior. The app trusts forwarded HTTPS only from exact configured proxy IPs; verify whether Render sets Apache's native HTTPS indicator or provides stable trusted proxy addresses before relying on secure-cookie detection.
- Run the PHP regression suite only against a dedicated local disposable MySQL test server and account. Do not point it at TiDB Cloud or production.
- Complete TiDB TLS/port/CA and migration-lock compatibility tests against disposable resources before the first production migration.

No Render service has been created, no TiDB database has been contacted, and no migration has been run as part of this preparation.
