<?php

namespace App\Services\Ai\Exceptions;

use Exception;

class AiProviderException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $code = 'AI_PROVIDER_ERROR',
        public readonly int $status = 503,
    ) {
        parent::__construct($message);
    }
}
