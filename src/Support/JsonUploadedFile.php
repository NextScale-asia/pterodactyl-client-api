<?php

namespace Byzic\PterodactylClientApi\Support;

use Illuminate\Http\UploadedFile;
use Byzic\PterodactylClientApi\Exceptions\EggApiException;

/**
 * The panel's egg services only accept an UploadedFile. This writes a JSON body to a temporary
 * file, hands it to $callback as a "test" upload (no is_uploaded_file() check) and removes it.
 */
class JsonUploadedFile
{
    public const MAX_KB = 1000;

    /**
     * Same limit as the admin import form (`max:1000`, kilobytes).
     *
     * @throws EggApiException
     */
    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw EggApiException::invalid('The egg could not be encoded as JSON.');
        }

        if (strlen($json) > self::MAX_KB * 1024) {
            throw EggApiException::tooLarge(self::MAX_KB);
        }

        return $json;
    }

    /**
     * @template T
     *
     * @param \Closure(UploadedFile): T $callback
     *
     * @return T
     */
    public static function with(array $data, \Closure $callback)
    {
        $json = self::encode($data);
        $path = tempnam(sys_get_temp_dir(), 'pca-egg-');
        if ($path === false) {
            throw EggApiException::tempFileFailed();
        }

        try {
            if (file_put_contents($path, $json) !== strlen($json)) {
                throw EggApiException::tempFileFailed();
            }

            return $callback(new UploadedFile($path, 'egg.json', 'application/json', null, true));
        } finally {
            @unlink($path);
        }
    }
}
