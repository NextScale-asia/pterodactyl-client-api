<?php

namespace Byzic\PterodactylClientApi\Exceptions;

/**
 * The source Wings explicitly refused to start a transfer, so nothing was started there.
 */
class WingsRejectedTransferException extends \RuntimeException
{
    public function __construct(public readonly int $wingsStatus)
    {
        parent::__construct("The source node refused the transfer (HTTP {$wingsStatus}).");
    }
}
