<?php

declare(strict_types=1);

namespace App\AccessGovernance\Exceptions;

use DomainException;

final class AccessDecisionRuleViolation extends DomainException
{
    public function __construct(
        public readonly string $ruleId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
