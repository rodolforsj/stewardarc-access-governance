<?php

declare(strict_types=1);

namespace App\Authentication\OpenIdConnect;

use App\Authentication\ExternalIdentityKey;

/**
 * The validated `iss` and `sub` of an ID Token, and nothing else.
 */
final class OpenIdConnectIdentity
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
    ) {
    }

    public function externalIdentityKey(): ExternalIdentityKey
    {
        return ExternalIdentityKey::fromOidc($this->issuer, $this->subject);
    }
}
