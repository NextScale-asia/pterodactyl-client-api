<?php

namespace Byzic\PterodactylClientApi\Support;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Illuminate\Support\Facades\DB;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/**
 * Optimistic lock for an egg. Editing a variable does not touch `eggs.updated_at`, so the lock is
 * a sha256 over the stored egg row and every variable row (ids included, timestamps excluded).
 */
class EggFingerprint
{
    /**
     * @throws \JsonException
     */
    public static function of(int $eggId): string
    {
        $egg = (array) DB::table('eggs')->where('id', $eggId)->first();
        unset($egg['created_at'], $egg['updated_at']);
        ksort($egg);

        $variables = DB::table('egg_variables')->where('egg_id', $eggId)->get()
            ->map(function ($row) {
                $row = (array) $row;
                unset($row['created_at'], $row['updated_at']);
                ksort($row);

                return $row;
            })
            ->sort(fn (array $a, array $b) => strcmp((string) $a['env_variable'], (string) $b['env_variable']) ?: $a['id'] <=> $b['id'])
            ->values()
            ->all();

        return hash('sha256', json_encode(
            ['egg' => $egg, 'variables' => $variables],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * Locks the egg row and its variable rows for the rest of the transaction, checks the
     * fingerprint (when given) and runs $callback with the locked egg. Must be called inside
     * DB::transaction().
     *
     * @template T
     *
     * @param \Closure(Egg): T $callback
     *
     * @return T
     *
     * @throws EggApiException
     */
    public static function locked(int $eggId, ?string $expected, \Closure $callback)
    {
        /** @var Egg $egg */
        $egg = Egg::query()->lockForUpdate()->findOrFail($eggId);
        EggVariable::query()->where('egg_id', $eggId)->lockForUpdate()->get();

        if (!is_null($expected) && !hash_equals(self::of($eggId), $expected)) {
            throw EggApiException::changed();
        }

        return $callback($egg);
    }

    /**
     * DB::transaction() + locked(), translating the panel's service exceptions.
     *
     * @template T
     *
     * @param \Closure(Egg): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    public static function transaction(int $eggId, ?string $expected, \Closure $callback)
    {
        return EggApiException::translate(
            fn () => DB::transaction(fn () => self::locked($eggId, $expected, $callback))
        );
    }

    /**
     * Read-only view of one consistent state: the egg and its variables are share-locked, so the
     * fingerprint and everything $callback reads describe the same egg. Nothing is written.
     *
     * @template T
     *
     * @param \Closure(Egg, string): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    public static function snapshot(int $eggId, \Closure $callback)
    {
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION READ ONLY');
        }

        return EggApiException::translate(fn () => DB::transaction(function () use ($eggId, $callback) {
            /** @var Egg $egg */
            $egg = Egg::query()->sharedLock()->findOrFail($eggId);
            EggVariable::query()->where('egg_id', $eggId)->sharedLock()->get();

            return $callback($egg, self::of($eggId));
        }));
    }
}
