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

From the panel directory (e.g. `/var/www/pterodactyl`):

```bash
composer require nextscale-asia/pterodactyl-client-api:^2.1
php artisan optimize:clear
php artisan route:list --path=api-keys   # should list the three api-keys routes
```

The service provider is auto-discovered.

**Panel 1.13 / 1.14 (Laravel 11):** use a plain `composer require` as above, **without**
`-W` / `--with-all-dependencies`. Current Composer releases block every Laravel 11 version
through security advisories, so re-resolving `laravel/framework` fails; a plain require keeps
the panel's locked Laravel version.

**Panel upgrades:** the standard panel upgrade overwrites the panel's `composer.json`, which
silently removes this package. Re-run `composer require` after every panel upgrade (or bake it
into your panel image).

## Authentication and permissions

All endpoints sit under `/api/application` and use the same middleware as the panel's own
Application API:

- An **application API key** (`ptla_…`) owned by a **root admin** is required. Account keys
  (`ptlc_…`) and browser sessions are rejected with 403.
- The key's ACL must grant:

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
installed, against MySQL in Docker. Only Docker is required (Git Bash works on Windows).

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
