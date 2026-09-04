<?php

namespace App\Services\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a real payment provider is selected but its credentials have not
 * been provisioned. Prevents silently charging with blank/missing keys.
 */
class PaymentNotConfiguredException extends RuntimeException
{
}
