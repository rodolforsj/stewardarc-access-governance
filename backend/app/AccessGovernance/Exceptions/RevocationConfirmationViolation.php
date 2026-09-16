<?php

declare(strict_types=1);

namespace App\AccessGovernance\Exceptions;

use DomainException;

final class RevocationConfirmationViolation extends DomainException
{
    public function __construct(
        public readonly string $constraintId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
