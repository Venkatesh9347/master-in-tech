<?php

namespace App\Services\LiveKit\Exceptions;

use RuntimeException;

/**
 * Thrown when LiveKit token generation is requested but the required
 * credentials/endpoint are not configured on the server.
 *
 * This is intentionally a fail-loud, server-side guard: we must never mint a
 * JWT or hand back a misleading websocket URL using empty or default
 * credentials, because LiveKit would silently reject the token and leave the
 * user with a broken classroom.
 */
class LiveKitConfigurationException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
