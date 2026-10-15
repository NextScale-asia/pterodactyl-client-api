<?php

namespace Byzic\PterodactylClientApi\Http\Requests;

use Pterodactyl\Services\Acl\Api\AdminAcl;
use Byzic\PterodactylClientApi\Support\EggWriteGate;
use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Write access to eggs: the key's ACL first (403 from the panel), then the addon's
 * `egg_write` switch, application keys only and the key allow-list
 * (403 EggWriteDisabled / EggWriteNeedsApplicationKey / EggWriteKeyNotAllowed).
 */
abstract class EggWriteRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_EGGS;

    protected int $permission = AdminAcl::WRITE;

    private array $rawBody;

    public function authorize(): bool
    {
        if (!parent::authorize()) {
            return false;
        }

        EggWriteGate::authorize($this);

        return true;
    }

    /**
     * The panel's global TrimStrings / ConvertEmptyStringsToNull middleware rewrite the parsed body,
     * which would strip whitespace from install scripts and turn "" defaults into null. Validate and
     * use the JSON exactly as it was sent.
     */
    public function validationData(): array
    {
        if (!isset($this->rawBody)) {
            $raw = $this->isJson() ? json_decode($this->getContent(), true) : null;
            $this->rawBody = is_array($raw) ? $raw : parent::validationData();
        }

        return $this->rawBody;
    }
}
