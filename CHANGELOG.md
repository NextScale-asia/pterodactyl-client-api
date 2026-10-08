# Changelog

All notable changes to `pterodactyl-client-api` will be documented in this file.

## [Unreleased]

## [2.3.0] - 2026-10-08

### Added
- `PUT /api/application/eggs/{egg}/variables/{env}` (`eggs: write`): create or update one egg
  variable by its environment variable name, through the panel's `VariableCreationService` /
  `VariableUpdateService`. `201` when created, `200` when updated. Reserved names and unknown
  validation rules return `400` (an unknown rule is a 500 in the panel itself). Tested on panel
  1.13.0, 1.14.0 and 1.15.1.

## [2.2.0] - 2026-10-06

### Added
- **Server transfer through the Application API**, mirroring the panel's admin transfer
  (`Admin\Servers\ServerTransferController`) with one difference: the URL the source Wings
  streams to is supplied as `relay_url` (the wings-ops-agent relay on the source node's
  loopback) instead of the target node's public address. Panel and Wings still make every
  decision (transfer row, JWT, success/failure callbacks, node and allocation swap).
  - `POST /api/application/servers/{server}/transfer` (`servers: write`): `node_id`,
    `allocation_id`, `allocation_additional[]`, `relay_url`. Validates that the target node
    differs and is viable, that every allocation belongs to the target node and is free, and
    `validateTransferState()`. Creates the transfer in a transaction exactly like the panel,
    then sends the source Wings the same body as `DaemonTransferRepository::notify()`
    (`server_id`, `url`, `token: "Bearer <jwt>"`, `server: {uuid, start_on_completion: false}`)
    with only `url` changed. Returns 202.
  - `GET /api/application/servers/{server}/transfer` (`servers: read`): the latest transfer,
    finished or not. `?verify=1` adds `meta.integrity`, which checks the server's node, primary
    allocation and every old/new allocation against the transfer's outcome.
  - `POST /api/application/servers/{server}/transfer/cancel` (`servers: write`), body
    `{"confirm_agents_idle": true}`: marks a **dead** pending transfer as failed and releases its
    reserved allocations, like the panel's `processFailedTransfer()`. No files are touched.
    204 on success; 409 `no_pending_transfer`, `too_early` (`meta.retry_after` + `Retry-After`),
    `transfer_active` or `cannot_verify`.
  - **There is no `DELETE …/servers/{server}/transfer`, and it must never be called**: the panel's
    `DELETE /api/application/servers/{server:id}/{force?}` matches that path and deletes the
    server. An earlier draft of this release used DELETE for cancelling; it was replaced before
    release and a test asserts the path never reaches this package's controller.
- Config block `transfer`: `require_relay` (default `true`), `relay_port` (default `781`),
  `notifier_timeout` (default 60 s), `probe_timeout` (default 10 s), `delete_min_age_seconds`
  (default 1020 s).
- Activity events `server:transfer.start` (`transfer_id` only) and `server:transfer.fail`
  (`transfer_id`, `reason`). Server activity is shown to the server's owner and subusers, so no
  node or allocation ids (and no JWT, relay ticket, `via_relay` or `uncertain` flag) are logged.

### Security
- With `require_relay` on (the default), `relay_url` must be exactly
  `http://127.0.0.1:{relay_port}/relay/{target_node_id}/{32 lowercase hex}/api/transfers`, so
  this endpoint can never send a transfer JWT anywhere but the configured loopback port.
- A leaked `ptla_` key is **not** made harmless by this: a key with `nodes: write` can still
  change a node's FQDN and use the panel's own Transfer button. Use a dedicated key with only
  `servers` access for transfers.
- Wings is called without following redirects, so the node token and transfer JWT are never
  resent to a `Location` header. A 3xx is `uncertain` on start and `cannot_verify` on cancel.
- Cancel is refused (`409 too_early`) until the transfer is older than the JWT lifetime plus
  clock skew (`delete_min_age_seconds`), so no stream can start after the allocations are released.
- `?verify=1` reports per allocation only `owned_by_this_server` and `ok`, never the id of another
  server that now holds it.
- Node requirements: keep `net.ipv4.ip_unprivileged_port_start = 1024`; run Wings in the host
  network namespace; hosting-api must always send `relay_url`.

### Design notes
- **Timeouts do not roll back.** Wings stops the server synchronously (up to 15 s) before it
  answers 202, which is as long as the panel's Guzzle timeout, so the notify call has its own
  timeout. A 4xx or 500 from Wings means nothing was started and the transaction is rolled
  back (502 `wings_rejected`). A timeout, a network error, a 3xx, or a 502/503/504/52x from a
  proxy in front of Wings commits the transfer and returns 202 with `meta.uncertain = true`, because
  Wings may already be streaming; the caller must reconcile.
- **Cancel cannot see the source side.** Wings' `GET /api/servers/{uuid}` has no
  "transferring" field. On the target node an incoming transfer registers the server when the
  stream starts and removes it on failure, so the addon refuses while the target still has the
  server (409 `transfer_active`) and accepts only Wings' own 404 as "gone". On the source node
  the server always exists and the only transfer call Wings offers (Wings' own
  `DELETE /api/servers/{uuid}/transfer`) cancels instead of reporting, so the addon only checks
  that the source Wings answers. `confirm_agents_idle=true` is therefore the caller's
  attestation that neither node's agent is still relaying the server (hosting-api does this
  from agent heartbeats). Any Wings that cannot be reached gives 409 `cannot_verify`; there is
  no `force`.
- **Cancel vs. the panel's success callback.** `success()` reads the transfer without a lock.
  Cancel locks the server row first, then the transfer row, and only proceeds while the transfer
  is still pending and `server.node_id = transfer.old_node`. If success commits first, cancel
  sees it and refuses; if cancel commits first, success's server update waits for the lock and
  its `->transfer` is then null, so it fails and rolls back. A server can no longer end up on the
  new node with its primary allocation released.
- POST locks the server row, re-runs `validateTransferState()` on the locked fresh row, and
  locks and re-checks the target allocations inside the transaction, so two concurrent starts
  cannot both succeed or grab the same allocation (the panel's admin controller silently skipped
  allocations that were no longer free).
- **Accepted trade-off:** POST holds those locks while waiting for the source Wings (up to
  `notifier_timeout`), because commit vs. rollback depends on its answer.

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
