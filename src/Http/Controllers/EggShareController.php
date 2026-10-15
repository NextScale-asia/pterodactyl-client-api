<?php

namespace Byzic\PterodactylClientApi\Http\Controllers;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\DB;
use Byzic\PterodactylClientApi\Support\EggDiff;
use Byzic\PterodactylClientApi\Support\AuditLog;
use Byzic\PterodactylClientApi\Support\DroppedValues;
use Byzic\PterodactylClientApi\Support\EggFingerprint;
use Byzic\PterodactylClientApi\Support\JsonUploadedFile;
use Pterodactyl\Services\Eggs\Sharing\EggExporterService;
use Pterodactyl\Services\Eggs\Sharing\EggImporterService;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;
use Pterodactyl\Transformers\Api\Application\EggTransformer;
use Byzic\PterodactylClientApi\Http\Requests\EggImportRequest;
use Byzic\PterodactylClientApi\Http\Requests\ExportEggRequest;
use Pterodactyl\Services\Eggs\Sharing\EggUpdateImporterService;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;

/**
 * The admin egg "Export", "Update Egg" (from a file) and "Import Egg" buttons as API endpoints.
 * The previews never write: they parse and compare in PHP inside one read-only snapshot and return
 * the exact payload the apply call must send back. The applies lock the egg, compare the
 * fingerprint and run the panel's own EggUpdateImporterService / EggImporterService.
 */
class EggShareController extends ApplicationApiController
{
    public function __construct(
        private EggDiff $diff,
        private EggExporterService $exporter,
        private EggImporterService $importer,
        private EggUpdateImporterService $updateImporter,
    ) {
        parent::__construct();
    }

    /**
     * @throws \Throwable
     */
    public function export(ExportEggRequest $request, Egg $egg): array
    {
        return EggFingerprint::snapshot($egg->id, fn (Egg $locked, string $fingerprint) => [
            'egg' => $this->exportOf($locked->id),
            'fingerprint' => $fingerprint,
            'extra' => [
                'config_from' => $locked->config_from,
                'copy_script_from' => $locked->copy_script_from,
                'script_is_privileged' => $locked->script_is_privileged,
                'force_outgoing_ip' => $locked->force_outgoing_ip,
                'nest_id' => $locked->nest_id,
                'author' => $locked->author,
                'uuid' => $locked->uuid,
            ],
        ]);
    }

    /**
     * Variables missing from the file are kept with their current definition unless they are
     * listed in `drop_variables`, so the safe outcome is the default. For a child egg, values that
     * equal what it inherits stay inherited.
     *
     * @throws \Throwable
     */
    public function previewUpdate(EggImportRequest $request, Egg $egg): array
    {
        $input = $request->validated();
        $normalized = $this->diff->normalize($input['egg']);

        return EggFingerprint::snapshot($egg->id, function (Egg $locked, string $fingerprint) use ($normalized, $input) {
            $this->diff->assertCaseMatches($locked, $normalized);
            [$normalized, $kept] = $this->diff->keepMissing($locked, $normalized, $input['drop_variables'] ?? []);
            [$normalized, $detaches, $warnings] = $this->diff->inherit($locked, $normalized);
            $this->diff->validate($normalized, $locked);

            return [
                'normalized_egg' => $normalized,
                'fingerprint' => $fingerprint,
                'expected_removed' => $this->diff->removed($locked, $normalized),
                'diff' => $this->diff->diff($locked, $normalized, $kept, $detaches, $warnings),
            ];
        });
    }

    /**
     * Writes `egg` as is: every stored variable it leaves out is deleted, together with its
     * server values, which are returned in `dropped_values`.
     *
     * @throws \Throwable
     */
    public function applyUpdate(EggImportRequest $request, Egg $egg): array
    {
        $input = $request->validated();
        $normalized = $this->diff->normalize($input['egg']);
        $expectedRemoved = self::sorted($input['expected_removed']);

        $result = EggFingerprint::transaction($egg->id, $input['expected_fingerprint'], function (Egg $locked) use ($normalized, $expectedRemoved) {
            $this->diff->assertCaseMatches($locked, $normalized);
            $removed = $this->diff->removed($locked, $normalized);
            if (self::sorted($removed) !== $expectedRemoved) {
                throw EggApiException::removedMismatch($expectedRemoved, $removed);
            }

            $before = $this->exportOf($locked->id);
            $dropped = DroppedValues::of($locked->id, $removed);
            $oldScript = $locked->script_install;

            $updated = JsonUploadedFile::with($normalized, fn ($file) => $this->updateImporter->handle($locked, $file));

            return [
                'egg' => $updated,
                'old_script' => $oldScript,
                'dropped' => $dropped,
                'response' => [
                    'before' => $before,
                    'after' => $this->exportOf($locked->id),
                    'fingerprint' => EggFingerprint::of($locked->id),
                    'removed' => $removed,
                ],
            ];
        });

        AuditLog::write(fn () => Activity::event('admin:egg.update-import')
            ->subject($result['egg'])
            ->property([
                'egg_id' => $result['egg']->id,
                'name' => $result['egg']->name,
                'script_sha256_old' => self::hash($result['old_script']),
                'script_sha256_new' => self::hash($result['egg']->script_install),
                'removed' => $result['response']['removed'],
                'dropped_values' => count($result['dropped']),
            ])
            ->log());

        return $result['response'] + DroppedValues::present($result['dropped'], $request);
    }

    /**
     * @throws \Throwable
     */
    public function previewImport(EggImportRequest $request, Nest $nest): array
    {
        $normalized = $this->diff->normalize($request->validated()['egg']);
        $this->diff->validate($normalized, null, $nest->id);

        return [
            'normalized_egg' => $normalized,
            'nest_id' => $nest->id,
            'same_name_eggs' => Egg::query()
                ->where('nest_id', $nest->id)
                ->where('name', $normalized['name'])
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'diff' => ['variables' => ['added' => $normalized['variables']]],
        ];
    }

    /**
     * Always creates a new egg (new id and uuid), like the admin import.
     *
     * @throws \Throwable
     */
    public function import(EggImportRequest $request, Nest $nest): JsonResponse
    {
        $normalized = $this->diff->normalize($request->validated()['egg']);

        /** @var Egg $egg */
        [$egg, $fingerprint] = EggApiException::translate(fn () => DB::transaction(function () use ($normalized, $nest) {
            $egg = JsonUploadedFile::with($normalized, fn ($file) => $this->importer->handle($file, $nest->id));

            return [$egg, EggFingerprint::of($egg->id)];
        }));

        AuditLog::write(fn () => Activity::event('admin:egg.import')
            ->subject($egg)
            ->property([
                'egg_id' => $egg->id,
                'name' => $egg->name,
                'nest_id' => $nest->id,
                'script_sha256' => self::hash($egg->script_install),
            ])
            ->log());

        $response = $this->fractal->item($egg->refresh())
            ->transformWith($this->getTransformer(EggTransformer::class))
            ->toArray();
        $response['meta']['fingerprint'] = $fingerprint;

        return new JsonResponse($response, JsonResponse::HTTP_CREATED);
    }

    private function exportOf(int $eggId): array
    {
        return json_decode($this->exporter->handle($eggId), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function sorted(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);

        return $values;
    }

    private static function hash(?string $script): ?string
    {
        return is_null($script) ? null : hash('sha256', $script);
    }
}
