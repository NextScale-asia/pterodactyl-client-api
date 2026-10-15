<?php

namespace Byzic\PterodactylClientApi\Support;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Arr;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Services\Eggs\EggParserService;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/**
 * Pure-PHP side of the egg import endpoints: parse an egg the way the panel does, reduce it to the
 * exact PTDL_v2 payload that will be written, validate it against the panel's model rules and
 * compare it with what is stored. Nothing here writes to the database.
 */
class EggDiff
{
    public const VARIABLE_KEYS = ['name', 'description', 'env_variable', 'default_value', 'user_viewable', 'user_editable', 'rules'];

    private const FIELDS = [
        'name' => 'name',
        'description' => 'description',
        'features' => 'features',
        'docker_images' => 'docker_images',
        'file_denylist' => 'file_denylist',
        'meta.update_url' => 'update_url',
        'startup' => 'startup',
        'config.files' => 'config_files',
        'config.startup' => 'config_startup',
        'config.logs' => 'config_logs',
        'config.stop' => 'config_stop',
        'scripts.installation.script' => 'script_install',
        'scripts.installation.container' => 'script_container',
        'scripts.installation.entrypoint' => 'script_entry',
    ];

    private const STRING_FIELDS = [
        'startup',
        'meta.update_url',
        'scripts.installation.script',
        'scripts.installation.container',
        'scripts.installation.entrypoint',
    ];

    /**
     * path => [parent link column, model accessor with the inherited value, own column,
     * panel always uses the parent's value while linked].
     */
    private const INHERITED = [
        'features' => ['config_from', 'inherit_features', 'features', false],
        'file_denylist' => ['config_from', 'inherit_file_denylist', 'file_denylist', true],
        'config.files' => ['config_from', 'inherit_config_files', 'config_files', false],
        'config.startup' => ['config_from', 'inherit_config_startup', 'config_startup', false],
        'config.logs' => ['config_from', 'inherit_config_logs', 'config_logs', false],
        'config.stop' => ['config_from', 'inherit_config_stop', 'config_stop', false],
        'scripts.installation.script' => ['copy_script_from', 'copy_script_install', 'script_install', false],
        'scripts.installation.container' => ['copy_script_from', 'copy_script_container', 'script_container', true],
        'scripts.installation.entrypoint' => ['copy_script_from', 'copy_script_entry', 'script_entry', true],
    ];

    public function __construct(private EggParserService $parser)
    {
    }

    /**
     * Parses the egg exactly like the admin import (PTDL_v1 is converted) and returns the
     * normalized PTDL_v2 payload: known keys only, variables reduced to their columns.
     *
     * @throws \Throwable
     */
    public function normalize(array $egg): array
    {
        try {
            $parsed = EggApiException::translate(
                fn () => JsonUploadedFile::with($egg, fn ($file) => $this->parser->handle($file))
            );
        } catch (\TypeError|\ErrorException|\ValueError) {
            throw EggApiException::invalid('The egg JSON has a field of the wrong type.');
        }

        foreach (self::STRING_FIELDS as $path) {
            $value = Arr::get($parsed, $path);
            if (!is_null($value) && !is_string($value)) {
                throw EggApiException::invalid("{$path} must be a string or null.");
            }
        }

        $variables = $parsed['variables'] ?? [];
        if (!is_array($variables) || !array_is_list($variables)) {
            throw EggApiException::invalid('"variables" must be a list.');
        }

        $seen = [];
        $normalized = [];
        foreach ($variables as $index => $variable) {
            $env = is_array($variable) ? ($variable['env_variable'] ?? null) : null;
            if (!is_string($env) || $env === '') {
                throw EggApiException::invalid("variables.{$index} has no env_variable.");
            }
            // the panel's collation is case-insensitive, so TZ and tz are the same row
            if (isset($seen[strtoupper($env)])) {
                throw EggApiException::invalid("variables.{$index}: env_variable {$env} is listed twice (names are case-insensitive).", ['env' => $env]);
            }
            $seen[strtoupper($env)] = true;
            $normalized[] = self::variable($variable);
        }

        $denylist = Arr::get($parsed, 'file_denylist');

        $result = [
            'meta' => ['version' => Egg::EXPORT_VERSION, 'update_url' => Arr::get($parsed, 'meta.update_url')],
            'name' => Arr::get($parsed, 'name'),
            'author' => Arr::get($parsed, 'author'),
            'description' => Arr::get($parsed, 'description'),
            'features' => Arr::get($parsed, 'features'),
            'docker_images' => Arr::get($parsed, 'docker_images'),
            'file_denylist' => array_values(array_filter(is_array($denylist) ? $denylist : (array) $denylist, fn ($value) => !empty($value))),
            'startup' => Arr::get($parsed, 'startup'),
            'config' => [
                'files' => Arr::get($parsed, 'config.files'),
                'startup' => Arr::get($parsed, 'config.startup'),
                'logs' => Arr::get($parsed, 'config.logs'),
                'stop' => Arr::get($parsed, 'config.stop'),
            ],
            'scripts' => [
                'installation' => [
                    'script' => Arr::get($parsed, 'scripts.installation.script'),
                    'container' => Arr::get($parsed, 'scripts.installation.container'),
                    'entrypoint' => Arr::get($parsed, 'scripts.installation.entrypoint'),
                ],
            ],
            'variables' => $normalized,
        ];

        FieldLimits::checkEgg($result);

        return $result;
    }

    /**
     * A child egg stores null (config, features, script) or an ignored value (file_denylist,
     * script container / entrypoint) where it inherits, while its export shows the parent's
     * values. Puts the stored value back wherever the payload still equals what the child
     * inherits, so exporting and re-importing a child does not detach it. Returns
     * [payload, fields that would stop inheriting, warnings about values the panel ignores].
     */
    public function inherit(Egg $egg, array $normalized): array
    {
        $detaches = [];
        $warnings = [];

        foreach (self::INHERITED as $path => [$link, $accessor, $column, $always]) {
            if (is_null($egg->{$link})) {
                continue;
            }

            $effective = self::comparable($path, $egg->{$accessor});
            $own = $column === 'file_denylist' ? array_values($egg->file_denylist ?? []) : $egg->{$column};
            $new = self::comparable($path, Arr::get($normalized, $path));

            if ($new === $effective || $new === self::comparable($path, $own)) {
                Arr::set($normalized, $path, $own);
                continue;
            }

            if ($always) {
                $warnings[] = ['field' => $path, 'message' => "Ignored by the panel while {$link} is set; the stored value is kept."];
                Arr::set($normalized, $path, $own);
                continue;
            }

            if (is_null($egg->{$column})) {
                $detaches[] = $path;
            }
        }

        return [$normalized, $detaches, $warnings];
    }

    /**
     * Refuses a payload variable that matches a stored one only case-insensitively: the panel's
     * updateOrCreate would update that row and keep its old name. Run before anything else
     * compares env names, which are then compared exactly.
     *
     * @throws EggApiException
     */
    public function assertCaseMatches(Egg $egg, array $normalized): void
    {
        $stored = $this->currentVariables($egg)->pluck('env_variable')->all();

        foreach (array_column($normalized['variables'], 'env_variable') as $env) {
            if (in_array($env, $stored, true)) {
                continue;
            }

            foreach ($stored as $existing) {
                if (strtoupper($existing) === strtoupper($env)) {
                    throw EggApiException::caseMismatch($env, $existing);
                }
            }
        }
    }

    /**
     * Adds back the current definition of every variable the file leaves out, unless it is
     * listed in $drop. Returns [payload, re-added env names].
     */
    public function keepMissing(Egg $egg, array $normalized, array $drop): array
    {
        $present = array_column($normalized['variables'], 'env_variable');
        $kept = [];

        foreach ($this->currentVariables($egg) as $variable) {
            if (in_array($variable->env_variable, $present, true) || in_array($variable->env_variable, $drop, true)) {
                continue;
            }

            $normalized['variables'][] = self::variable(Arr::only($variable->getAttributes(), self::VARIABLE_KEYS));
            $kept[] = $variable->env_variable;
        }

        return [$normalized, $kept];
    }

    /**
     * Runs the panel's model validation on an unsaved copy, so a payload the services would
     * refuse is reported (422) before anything is written.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(array $normalized, ?Egg $egg, ?int $nestId = null): void
    {
        $model = $egg ? $egg->replicate() : (new Egg())->forceFill([
            'uuid' => Uuid::uuid4()->toString(),
            'nest_id' => $nestId,
            'author' => Arr::get($normalized, 'author'),
        ]);

        $this->parser->fillFromParsed($model, $normalized)->validate();

        foreach ($normalized['variables'] as $variable) {
            (new EggVariable())->forceFill($variable)->validate();
        }
    }

    /**
     * Env names of the stored variables that $normalized would delete.
     */
    public function removed(Egg $egg, array $normalized): array
    {
        $present = array_column($normalized['variables'], 'env_variable');

        return $this->currentVariables($egg)
            ->pluck('env_variable')
            ->reject(fn ($env) => in_array($env, $present, true))
            ->values()
            ->all();
    }

    /**
     * Field values are compared as the panel uses them (inherited values flattened, like the
     * export), so an unchanged child egg shows no field changes.
     */
    public function diff(Egg $egg, array $normalized, array $kept = [], array $detaches = [], array $warnings = []): array
    {
        $fields = [];
        foreach (self::FIELDS as $path => $column) {
            [$old, $new] = $this->effective($egg, $path, $column, Arr::get($normalized, $path));
            if ($old !== $new) {
                $fields[$path] = [$old, $new];
            }
        }

        $current = $this->currentVariables($egg)->keyBy('env_variable');
        $added = [];
        $changed = [];
        foreach ($normalized['variables'] as $variable) {
            $existing = $current->get($variable['env_variable']);
            if (!$existing) {
                $added[] = $variable;
                continue;
            }

            $old = self::variable(Arr::only($existing->getAttributes(), self::VARIABLE_KEYS));
            $delta = [];
            foreach ($variable as $key => $value) {
                if ($key !== 'env_variable' && ($old[$key] ?? null) !== $value) {
                    $delta[$key] = [$old[$key] ?? null, $value];
                }
            }
            if ($delta) {
                $changed[] = ['env' => $variable['env_variable'], 'fields' => $delta];
            }
        }

        $removedEnvs = $this->removed($egg, $normalized);
        $removedIds = $current->toBase()->only($removedEnvs)->pluck('id', 'env_variable');
        $counts = ServerVariable::query()
            ->whereIn('variable_id', $removedIds->values())
            ->selectRaw('variable_id, COUNT(*) as aggregate')
            ->groupBy('variable_id')
            ->pluck('aggregate', 'variable_id');

        $oldImages = array_values($egg->docker_images ?? []);
        $newImages = array_values(Arr::get($normalized, 'docker_images') ?? []);

        return [
            'fields' => $fields,
            'variables' => [
                'added' => $added,
                'changed' => $changed,
                'removed' => array_map(fn ($env) => [
                    'env' => $env,
                    'server_values' => (int) ($counts[$removedIds[$env]] ?? 0),
                ], $removedEnvs),
                'kept' => $kept,
            ],
            'servers_using_egg' => Server::query()->where('egg_id', $egg->id)->count(),
            'servers_on_removed_images' => array_map(fn ($image) => [
                'image' => $image,
                'count' => Server::query()->where('egg_id', $egg->id)->where('image', $image)->count(),
            ], array_values(array_diff($oldImages, $newImages))),
            'detaches_from_parent' => $detaches,
            'warnings' => $warnings,
            'has_parent_config' => !is_null($egg->config_from),
            'has_script_parent' => !is_null($egg->copy_script_from),
            'children_copying_script' => Egg::query()->where('copy_script_from', $egg->id)->count(),
            'children_copying_config' => Egg::query()->where('config_from', $egg->id)->count(),
        ];
    }

    /**
     * [value in use now, value in use after writing $new].
     */
    private function effective(Egg $egg, string $path, string $column, mixed $new): array
    {
        $inherited = self::INHERITED[$path] ?? null;
        if (!$inherited || is_null($egg->{$inherited[0]})) {
            return [self::comparable($path, $egg->{$column}), self::comparable($path, $new)];
        }

        [$link, $accessor, , $always] = $inherited;
        $old = self::comparable($path, $egg->{$accessor});
        if ($always) {
            return [$old, $old];
        }

        $parent = $link === 'config_from' ? $egg->configFrom : $egg->scriptFrom;

        return [$old, self::comparable($path, is_null($new) ? $parent?->{$column} : $new)];
    }

    private static function comparable(string $path, mixed $value): mixed
    {
        return $path === 'file_denylist' ? array_values(is_array($value) ? $value : []) : $value;
    }

    /**
     * @return \Illuminate\Support\Collection<int, EggVariable>
     */
    private function currentVariables(Egg $egg)
    {
        return EggVariable::query()->where('egg_id', $egg->id)->orderBy('id')->get();
    }

    private static function variable(array $variable): array
    {
        $out = Arr::only($variable, self::VARIABLE_KEYS);
        foreach (['user_viewable', 'user_editable'] as $flag) {
            if (array_key_exists($flag, $out) && (is_bool($out[$flag]) || in_array($out[$flag], [0, 1, '0', '1'], true))) {
                $out[$flag] = (bool) $out[$flag];
            }
        }

        return $out;
    }
}
