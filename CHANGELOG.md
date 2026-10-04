# Changelog

All notable changes to `pterodactyl-client-api` will be documented in this file.

## [Unreleased]

## [2.1.0] - 2026-10-04

2.0.0 did not work on any supported panel version: every API key endpoint returned 500, and
the free allocations endpoint always returned an empty list. 2.1.0 rewrites the endpoints on
top of the panel's own API key primitives and closes the authorization gaps that would have
become exploitable once the endpoints worked. Upgrading from 2.0.0 is strongly recommended.

### Security
- Routes now run through the same middleware stack as the panel's `/api/application`
  routes (`api`, 2FA, `application-api`, `throttle:api.application`). Previously the
  `application-api` group was missing, so account keys (`ptlc_`) and browser sessions of
  non-admin users were accepted and the ACL check was skipped.
- Creating, listing or deleting keys of a **root admin** is refused with 403. A key minted
  for an admin is a silent full-panel takeover. Set `CLIENT_API_ALLOW_ADMIN_TARGETS=true`
  to opt back in.
- Removed `ValidateUserOwnership`. It referenced a class that does not exist and never
  applied to the admins who actually call these endpoints.

### Fixed
- Keys are created with `User::createToken()`, so they use the panel's format (`ptlc_`
  identifier, encrypted 32-character token) and authenticate against the Client API.
  2.0.0 stored a SHA-256 hash that the panel cannot verify, and its insert failed anyway
  because `user_id`/`key_type` are not mass-assignable.
- `meta.secret_token` now holds only the 32-character token, matching the panel's own
  `POST /api/client/account/api-keys`. The full key is `attributes.identifier` followed by
  `meta.secret_token`. (2.0.0 never returned a working response, so no client relied on
  the old shape.)
- Audit events are written with the panel's activity log (`user:api-key.create`,
  `user:api-key.delete`), with the admin as actor and the target user as subject, so the
  user sees them on their own Activity page. 2.0.0 called an `activity()` helper that the
  panel does not ship.
- Responses use the panel's `Client\ApiKeyTransformer` (the `Application\ApiKeyTransformer`
  used by 2.0.0 does not exist).
- `DELETE` used the non-existent `AdminAcl::DELETE` and validated route parameters as body
  fields; it now requires `users: write` and validates the identifier in the route.
- `GET /nodes/{node}/allocations/free` now resolves the node (it previously received an
  empty model and returned nothing) and validates `per_page` (1–100).
- The per-user key limit is read from config and enforced inside a transaction with a row
  lock, so parallel requests cannot exceed it.
- `allowed_ips` accepts CIDR ranges and up to 50 entries, validated exactly like the panel.
- `composer.json` now allows Laravel 12 (panel 1.15+) as well as Laravel 11 (panel 1.13–1.14),
  and requires PHP 8.2.

### Changed
- Exceeding the key limit returns **400** (`DisplayException`, as in the panel) instead of 403.
- The per-description uniqueness rule was dropped; the panel has no such rule and it broke
  re-provisioning with a fixed description.
- `description` follows the panel's rule (required, max 500 characters).
- Config keys: `api_key.max_keys_per_user` (default 5), `api_key.allow_admin_targets`
  (default false), `allocations.max_per_page` (default 100). The unused `version`,
  `default_permissions`, `show_free_only` and `include_node_info` keys were removed.
- Routes moved from `routes/web.php` to `routes/api.php`.

## [2.0.0] - 2025-10-11

Rewrite of the API key endpoints. **Broken on every supported panel version; use 2.1.0.**

## [1.0.0] - 2025-03-29

Initial release: user API key endpoints and the free allocation listing.
