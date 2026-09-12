<?php

namespace App\Services\Ai\Exceptions;

use Exception;

class AiProviderException extends Exception
{
    /**
     * Machine-readable error slug. Named $errorCode (not $code) because
     * PHP's base Exception already types $code as int — redeclaring it
     * fatals the process at class load.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'AI_PROVIDER_ERROR',
        public readonly int $status = 503,
    ) {
        parent::__construct($message);
    }
}
