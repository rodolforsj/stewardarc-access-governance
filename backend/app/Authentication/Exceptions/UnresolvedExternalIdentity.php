<?php

declare(strict_types=1);

namespace App\Authentication\Exceptions;

use RuntimeException;

/**
 * An authenticated external identity with no corresponding Actor Reference
 * (ADR-010). The identity is deliberately left out of the message.
 */
final class UnresolvedExternalIdentity extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The external identity does not correspond to any Actor Reference.');
    }
}
