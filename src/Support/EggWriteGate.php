<?php

namespace Byzic\PterodactylClientApi\Support;

use Illuminate\Http\Request;
use Pterodactyl\Models\ApiKey;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/**
 * Before 2.3.2 the WRITE bit of `r_eggs` / `r_nests` unlocked nothing, so plenty of keys carry it.
 * Writing eggs means writing install scripts that run as root on every node, so the routes stay
 * closed until the panel owner turns them on, optionally for a short list of key identifiers.
 *
 * Only application keys pass: the panel's ApplicationApiRequest lets a root admin's account key or
 * browser session skip the ACL entirely, which must not happen for these routes.
 */
class EggWriteGate
{
    public static function enabled(): bool
    {
        return (bool) config('pterodactyl-client-api.egg_write.enabled', false);
    }

    public static function applicationKey(Request $request): ?ApiKey
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof ApiKey && $token->key_type === ApiKey::TYPE_APPLICATION ? $token : null;
    }

    public static function allows(Request $request): bool
    {
        $key = self::applicationKey($request);
        if (!$key) {
            return false;
        }

        $allowed = self::allowedIdentifiers();

        return empty($allowed) || in_array($key->identifier, $allowed, true);
    }

    /**
     * @throws EggApiException
     */
    public static function authorize(Request $request): void
    {
        if (!self::enabled()) {
            throw EggApiException::writeDisabled();
        }

        if (!self::applicationKey($request)) {
            throw EggApiException::keyTypeNotAllowed();
        }

        if (!self::allows($request)) {
            throw EggApiException::keyNotAllowed();
        }
    }

    private static function allowedIdentifiers(): array
    {
        $value = config('pterodactyl-client-api.egg_write.key_identifiers', []);
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $list)));
    }
}
