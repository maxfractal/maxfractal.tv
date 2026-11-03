<?php

declare(strict_types=1);

namespace Maxfractal;

class ArchiveException extends \RuntimeException
{
}

class InvalidIdentifierException extends ArchiveException
{
}

class ArchiveHttpException extends ArchiveException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}

class ArchiveTimeoutException extends ArchiveHttpException
{
}

class ArchiveResponseException extends ArchiveException
{
}
