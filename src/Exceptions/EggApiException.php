<?php

namespace Byzic\PterodactylClientApi\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Exceptions\Handler;
use Pterodactyl\Exceptions\DisplayException;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Exceptions\Model\DataValidationException;
use Pterodactyl\Exceptions\Service\HasActiveServersException;
use Pterodactyl\Exceptions\Service\Egg\HasChildrenException;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;
use Pterodactyl\Exceptions\Service\Egg\Variable\BadValidationRuleException;
use Pterodactyl\Exceptions\Service\Egg\NoParentConfigurationFoundException;

/**
 * JSON:API error with an explicit status and `code`, used by the egg / nest write endpoints.
 */
class EggApiException extends DisplayException
{
    public function __construct(
        private int $status,
        private string $errorCode,
        string $message,
        private array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function writeDisabled(): self
    {
        return new self(403, 'EggWriteDisabled', 'Egg and nest writes are disabled on this panel (pterodactyl-client-api.egg_write.enabled).');
    }

    public static function keyNotAllowed(): self
    {
        return new self(403, 'EggWriteKeyNotAllowed', 'This API key is not in pterodactyl-client-api.egg_write.key_identifiers.');
    }

    public static function keyTypeNotAllowed(): self
    {
        return new self(403, 'EggWriteNeedsApplicationKey', 'Egg and nest writes need an application API key (ptla_); account keys and sessions are refused.');
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }

    public static function fieldTooLong(string $field, int $max): self
    {
        return new self(422, 'EggFieldTooLong', "{$field} is longer than {$max} bytes.", ['field' => $field, 'max_bytes' => $max]);
    }

    public static function tempFileFailed(): self
    {
        return new self(500, 'EggTempFileFailed', 'Could not create a temporary file for the egg JSON on the panel.');
    }

    public static function changed(): self
    {
        return new self(409, 'EggChanged', 'The egg was changed since expected_fingerprint was read. Fetch it again and retry.');
    }

    public static function removedMismatch(array $expected, array $actual): self
    {
        return new self(409, 'RemovedVariablesMismatch', 'The variables this update would delete differ from expected_removed.', [
            'expected_removed' => $expected,
            'removed' => $actual,
        ]);
    }

    public static function hasChildren(array $eggIds): self
    {
        return new self(409, 'EggHasChildren', 'Other eggs copy their configuration or install script from this egg.', ['eggs' => $eggIds]);
    }

    public static function nestHasEggs(int $count): self
    {
        return new self(409, 'NestHasEggs', 'The nest still has eggs; delete or move them first.', ['eggs_count' => $count]);
    }

    public static function tooLarge(int $limitKb): self
    {
        return new self(422, 'EggTooLarge', "The egg JSON is larger than {$limitKb} KB.");
    }

    public static function invalid(string $message, array $meta = []): self
    {
        return new self(422, 'InvalidEgg', $message, $meta);
    }

    /**
     * The panel's collation is case-insensitive: `tz` would update (or be created next to) `TZ`.
     */
    public static function caseMismatch(string $env, string $existing): self
    {
        return new self(422, 'EggVariableCaseMismatch', "The egg already has the variable {$existing}; env names differing only in case are refused.", [
            'env' => $env,
            'existing' => $existing,
        ]);
    }

    /**
     * Runs $callback and turns the panel's service exceptions into 409 (state conflicts) or 422
     * (bad input) instead of the panel's 400 / 500.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    public static function translate(\Closure $callback)
    {
        try {
            return $callback();
        } catch (HasActiveServersException|HasChildrenException $exception) {
            throw new self(409, class_basename($exception), $exception->getMessage());
        } catch (InvalidFileUploadException|BadValidationRuleException|NoParentConfigurationFoundException $exception) {
            throw new self(422, class_basename($exception), $exception->getMessage());
        } catch (DataValidationException $exception) {
            throw ValidationException::withMessages($exception->getMessageBag()->toArray());
        } catch (\JsonException) {
            throw self::invalid('The egg is not valid JSON.');
        } catch (\BadMethodCallException) {
            throw new self(422, 'BadValidationRuleException', 'A variable uses an unknown validation rule.');
        }
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function render(Request $request): JsonResponse
    {
        $body = Handler::toArray($this);
        $body['errors'][0]['code'] = $this->errorCode;
        if ($this->meta) {
            $body['errors'][0]['meta'] = array_merge($body['errors'][0]['meta'] ?? [], $this->meta);
        }

        return new JsonResponse($body, $this->status);
    }

    public function report()
    {
        return null;
    }
}
