<?php

namespace App\Services\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when the payment gateway rejects or fails to service a request.
 */
class PaymentProviderException extends RuntimeException
{
}
