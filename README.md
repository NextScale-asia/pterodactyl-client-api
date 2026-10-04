# Pterodactyl Client API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/nextscale-asia/pterodactyl-client-api.svg?style=flat-square)](https://packagist.org/packages/nextscale-asia/pterodactyl-client-api)
[![Total Downloads](https://img.shields.io/packagist/dt/nextscale-asia/pterodactyl-client-api.svg?style=flat-square)](https://packagist.org/packages/nextscale-asia/pterodactyl-client-api)

Adds Application API endpoints to Pterodactyl Panel that the panel does not ship:

- **Manage another user's account API keys** (`ptlc_…`): list, create, delete. Useful for a
  billing or provisioning system that needs a client key per user to drive the Client API
  (console, power, files) on that user's behalf.
- **List the free allocations of a node.**

The endpoints reuse the panel's own primitives (`User::createToken()`, the client API key
transformer, the activity log and the `application-api` middleware), so keys created here are
identical to keys a user creates from their account page.

> **Upgrading from 2.0.0:** 2.0.0 did not work on any panel version (every API key endpoint
> returned 500). Upgrade to 2.1.0. See [CHANGELOG](CHANGELOG.md).

## Requirements

| | Version |
|---|---|
| Pterodactyl Panel | 1.13, 1.14, 1.15 |
| PHP | 8.2+ |
| Laravel | 11 or 12 (as shipped by the panel) |

## Installation

From the panel directory (e.g. `/var/www/pterodactyl`), as for a panel upgrade:

```bash
cd /var/www/pterodactyl
cp composer.json composer.json.bak && cp composer.lock composer.lock.bak

COMPOSER_ALLOW_SUPERUSER=1 composer require nextscale-asia/pterodactyl-client-api:^2.1 \
  --update-no-dev --optimize-autoloader
php artisan optimize:clear
chown -R www-data:www-data /var/www/pterodactyl/*   # nginx/apache/caddy user on your system

php artisan route:list --path=api-keys | grep application   # the three routes of this package
```

- `--update-no-dev` matters on a production panel: a plain `composer require` also installs the
  panel's dev dependencies (PHPUnit and friends) into `vendor/`.
- The service provider is auto-discovered.
- Rollback: restore `composer.json.bak` / `composer.lock.bak`, then
  `composer install --no-dev --optimize-autoloader` and `php artisan optimize:clear`.

**Panel 1.13 / 1.14 (Laravel 11):** use a plain `composer require` as above, **without**
`-W` / `--with-all-dependencies`. Current Composer releases block every Laravel 11 version
through security advisories, so re-resolving `laravel/framework` fails; a plain require keeps
the panel's locked Laravel version.

**Panel upgrades:** the standard panel upgrade overwrites the panel's `composer.json`, which
silently removes this package. Re-run `composer require` after every panel upgrade (or bake it
into your panel image).

### Upgrading or removing an older install

Earlier releases were published under several package names, and the only tag before 2.1.0
(`v1.0.0`) contains the broken 2.0.0 code. Remove whatever is installed, then install 2.1.

**1. Find what is installed** (from the panel directory):

```bash
composer show | grep -i -E "pterodactyl-api-addon|pterodactyl-client-api|panel-client-api"
grep -n -i -E "api-addon|client-api" composer.json          # require + repositories entries
composer config repositories                                 # path / vcs repositories
grep -rn "PterodactylApiAddonServiceProvider" config/ bootstrap/ 2>/dev/null   # manual registration
ls config/pterodactyl-client-api.php 2>/dev/null             # published config
```

Names used so far: `rene-roscher/pterodactyl-api-addon` (1.x and the first 2.0.0),
`byzic/pterodactyl-client-api`, `NextScale-asia/Panel-Client-API`,
`nextscale-asia/pterodactyl-client-api`.

**2. Remove it:**

```bash
cp composer.json composer.json.bak && cp composer.lock composer.lock.bak

# the name printed by `composer show` above
COMPOSER_ALLOW_SUPERUSER=1 composer remove rene-roscher/pterodactyl-api-addon --update-no-dev

# only if `composer config repositories` listed a path/vcs entry for it
composer config --unset repositories.<name>

rm -f config/pterodactyl-client-api.php
php artisan optimize:clear
php artisan route:list --path=api-keys | grep application   # must print nothing now
```

If the step 1 `grep` found `PterodactylApiAddonServiceProvider` in `config/app.php` or
`bootstrap/providers.php` (a manual install, not through Composer), delete that line and the
copied source files instead of running `composer remove`.

Removing a published config file matters: Laravel merges package config only one level deep, so
an old `api_key` block would replace the new one entirely (with the 2.0.0 file, the key cap
silently becomes 10 instead of 5).

If you are already on `nextscale-asia/pterodactyl-client-api`, you can skip the removal and run
the install command directly; Composer upgrades it in place. Still delete an old published config.

**3. Install 2.1** with the command in [Installation](#installation).

**After removing 1.x:** 1.x had **no authorization** on key creation (any panel user could mint a
key for any user, including root admins). Review admin accounts for keys you did not create:

```sql
SELECT k.identifier, u.username, k.memo, k.created_at, k.last_used_at
FROM api_keys k JOIN users u ON u.id = k.user_id
WHERE k.key_type = 1 AND u.root_admin = 1 ORDER BY k.created_at DESC;
```

Keys created by 1.x are ordinary panel keys and keep working. 2.0.0 never managed to create a
key, so there is nothing to clean up from it.

### Panel running in Docker (official image)

In the official `ghcr.io/pterodactyl/panel` image the panel lives in `/app` and only `/app/var`,
logs, nginx config and certificates are volumes. `vendor/` and `config/` are part of the image, so:

- **Removing:** a package installed with `docker compose exec panel composer require …` disappears
  when the container is recreated: `docker compose up -d --force-recreate panel`.
- **Installing:** `composer require` inside a running container does not survive a recreate or
  an image update. Build your own image instead:

```dockerfile
FROM ghcr.io/pterodactyl/panel:v1.15.1
# Same steps as the official image's own composer install.
RUN cp .env.example .env \
 && composer require nextscale-asia/pterodactyl-client-api:^2.1 --update-no-dev --optimize-autoloader \
 && rm -rf .env bootstrap/cache/*.php \
 && chown -R nginx:nginx .
```

Then point `image:` (or `build:`) in your `docker-compose.yml` at it and run
`docker compose up -d panel`. Rebuild it whenever you change the panel version.

## Authentication and permissions

All endpoints sit under `/api/application` and use the same middleware as the panel's own
Application API:

- The caller must be a **root admin**. Account keys (`ptlc_…`) and browser sessions of other
  users are rejected with 403. As with the panel's own Application API, a root admin's account
  key or session also passes and is **not** limited by the ACL below; use an application key
  (`ptla_…`) for integrations.
- An application key's ACL must grant:

| Endpoint | ACL resource | Level |
|---|---|---|
| `GET …/users/{user}/api-keys` | Users | Read |
| `POST …/users/{user}/api-keys` | Users | Read & Write |
| `DELETE …/users/{user}/api-keys/{identifier}` | Users | Read & Write |
| `GET …/nodes/{node}/allocations/free` | Allocations | Read |

- Keys of **root admins** cannot be listed, created or deleted (403), because a key minted for
  an admin is a full panel takeover. See `allow_admin_targets` below to opt back in.
- Create and delete are written to the panel's activity log (`user:api-key.create`,
  `user:api-key.delete`) with the admin as actor; the target user sees them on their own
  Activity page.

## Endpoints

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/application/users/{user}/api-keys` | List a user's account API keys |
| POST | `/api/application/users/{user}/api-keys` | Create an account API key for a user |
| DELETE | `/api/application/users/{user}/api-keys/{identifier}` | Delete one of a user's account API keys |
| GET | `/api/application/nodes/{node}/allocations/free` | List a node's unassigned allocations (paginated) |

`{user}` and `{node}` are panel IDs. Unknown IDs return 404.

### Create a key

```bash
curl -X POST "https://panel.example.com/api/application/users/42/api-keys" \
  -H "Authorization: Bearer ptla_..." \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"description": "billing-provision", "allowed_ips": ["203.0.113.10", "10.0.0.0/24"]}'
```

| Field | Rules |
|---|---|
| `description` | required, string, max 500 |
| `allowed_ips` | optional array, max 50 entries; each an IP address or CIDR range. Empty = no IP restriction |

Response `200`:

```json
{
  "object": "api_key",
  "attributes": {
    "identifier": "ptlc_AbCdEf12345",
    "description": "billing-provision",
    "allowed_ips": ["203.0.113.10", "10.0.0.0/24"],
    "last_used_at": null,
    "created_at": "2026-10-04T08:00:00+00:00"
  },
  "meta": {
    "secret_token": "<32 characters>"
  }
}
```

The secret is shown **once**. The full key to use as a Bearer token on the Client API is
`attributes.identifier` followed by `meta.secret_token` (48 characters), exactly as with the
panel's own `POST /api/client/account/api-keys`.

Errors: `400` the user already has `max_keys_per_user` keys · `403` missing ACL, or the user is a
root admin · `404` unknown user · `422` validation failed.

### List keys

```bash
curl "https://panel.example.com/api/application/users/42/api-keys" \
  -H "Authorization: Bearer ptla_..." -H "Accept: application/json"
```

Returns a list of `api_key` objects (same attributes as above, never the secret).

### Delete a key

```bash
curl -X DELETE "https://panel.example.com/api/application/users/42/api-keys/ptlc_AbCdEf12345" \
  -H "Authorization: Bearer ptla_..." -H "Accept: application/json"
```

Returns `204`. A key that does not belong to that user returns `404`.

### Free allocations

```bash
curl "https://panel.example.com/api/application/nodes/1/allocations/free?per_page=100" \
  -H "Authorization: Bearer ptla_..." -H "Accept: application/json"
```

Paginated list of `allocation` objects with `server_id` = null. `per_page`: 1–100, default 50.

The panel's own endpoint can do the same:
`GET /api/application/nodes/{node}/allocations?filter[server_id]=false`.

## Configuration

Publish the config file (optional):

```bash
php artisan vendor:publish --provider="Byzic\PterodactylClientApi\PterodactylApiAddonServiceProvider" --tag="config"
```

| Key | Env | Default | Meaning |
|---|---|---|---|
| `api_key.max_keys_per_user` | `CLIENT_API_MAX_KEYS_PER_USER` | `5` | Maximum account keys per user. Counts **all** of the user's account keys, including ones they created themselves |
| `api_key.allow_admin_targets` | `CLIENT_API_ALLOW_ADMIN_TARGETS` | `false` | Allow managing keys of root admins. Leave off unless you understand the risk |
| `allocations.max_per_page` | `CLIENT_API_ALLOCATIONS_MAX_PER_PAGE` | `100` | Upper bound for `per_page` on free allocations |

After changing env values, run `php artisan config:clear` (or `config:cache`).

More detail (Vietnamese): [docs/API.md](docs/API.md).

## Running tests

The tests are panel integration tests: they run inside a copy of the panel with this package
installed, against MariaDB in Docker. Only Docker is required (Git Bash works on Windows).

```bash
scripts/test-in-panel.sh <path-to-panel-source> [phpunit args...]

scripts/test-in-panel.sh ../Pterodactyl_Base/panel-1.15.1/panel-1.15.1
scripts/test-in-panel.sh ../panel --filter testKeyLimitIsApplied
```

The script mounts the panel source read-only and copies it inside the container (the source is
never modified), installs this package through a composer path repository, copies `tests/Integration/*.php` into the panel's
`tests/Integration/Api/Application/Users/`, and runs phpunit.

| Env | Default | |
|---|---|---|
| `PHP_VERSION` | `8.3` | PHP image version |
| `DB_IMAGE` | `mariadb:11` | Database image |
| `KEEP` | unset | `KEEP=1` keeps the database container and network for debugging |

## Security

Please report vulnerabilities privately to the maintainers through
[GitHub security advisories](https://github.com/NextScale-asia/pterodactyl-client-api/security/advisories/new)
rather than opening a public issue.

## License

The MIT License (MIT). See [License File](LICENSE.md).
