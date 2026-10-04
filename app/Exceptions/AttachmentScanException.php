<?php

namespace App\Exceptions;

use App\Enums\AttachmentScanFailure;
use RuntimeException;
use Throwable;

class AttachmentScanException extends RuntimeException
{
    public function __construct(
        public readonly AttachmentScanFailure $failure,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
