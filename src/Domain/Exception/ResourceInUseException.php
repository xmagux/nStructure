<?php

declare(strict_types=1);

namespace NStructure\Domain\Exception;

use RuntimeException;

final class ResourceInUseException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The resource is still in use and cannot be removed');
    }
}
