<?php

namespace Byzic\PterodactylClientApi\Support;

use Illuminate\Support\Arr;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/**
 * Column sizes the panel's model rules do not check. Panels run MySQL with strict mode off
 * (DB_STRICT_MODE=false), so an oversized value would be cut silently instead of refused.
 */
class FieldLimits
{
    public const TEXT = 65535;

    public const VARCHAR = 191;

    private const EGG = [
        'description' => self::TEXT,
        'startup' => self::TEXT,
        'meta.update_url' => self::TEXT,
        'config.files' => self::TEXT,
        'config.startup' => self::TEXT,
        'config.logs' => self::TEXT,
        'scripts.installation.script' => self::TEXT,
        'scripts.installation.container' => self::VARCHAR,
        'scripts.installation.entrypoint' => self::VARCHAR,
    ];

    private const VARIABLE = [
        'description' => self::TEXT,
        'default_value' => self::TEXT,
        'rules' => self::TEXT,
    ];

    /**
     * @throws EggApiException
     */
    public static function check(string $field, mixed $value, int $max = self::TEXT): void
    {
        if (is_string($value) && strlen($value) > $max) {
            throw EggApiException::fieldTooLong($field, $max);
        }
    }

    /**
     * @throws EggApiException
     */
    public static function checkEgg(array $egg): void
    {
        foreach (self::EGG as $path => $max) {
            self::check($path, Arr::get($egg, $path), $max);
        }

        foreach ($egg['variables'] ?? [] as $index => $variable) {
            self::checkVariable($variable, "variables.{$index}.");
        }
    }

    /**
     * @throws EggApiException
     */
    public static function checkVariable(array $variable, string $prefix = ''): void
    {
        foreach (self::VARIABLE as $key => $max) {
            self::check($prefix . $key, $variable[$key] ?? null, $max);
        }
    }
}
