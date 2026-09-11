<?php

namespace App\Services;

/**
 * Thrown when a student cannot be assigned to a cohort batch for a
 * business-rule reason (batch full, batch closed). Callers map this to a
 * 422 response — never a 500.
 */
class BatchAssignmentException extends \RuntimeException
{
}
