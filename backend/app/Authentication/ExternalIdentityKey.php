<?php

declare(strict_types=1);

namespace App\Authentication;

use InvalidArgumentException;

/**
 * The persisted form of an authenticated external identity, stored in
 * `actor_references.external_identity_key` (ADR-011). For OpenID Connect it is
 * `oidc:v1:<base64url(iss)>:<base64url(sub)>`: each claim is encoded on its own,
 * byte for byte, with unpadded Base64URL (RFC 4648 §5). `:` is not part of that
 * alphabet, so the pair cannot be read ambiguously. Nothing is normalized.
 */
final class ExternalIdentityKey
{
    private const OIDC_PREFIX = 'oidc:v1:';

    private function __construct(
        private readonly string $value,
    ) {
    }

    /**
     * The issuer and subject must be the claims of an already validated ID
     * Token, exactly as received.
     */
    public static function fromOidc(string $issuer, string $subject): self
    {
        if ($issuer === '') {
            throw new InvalidArgumentException('An OpenID Connect identity requires an issuer.');
        }

        if ($subject === '') {
            throw new InvalidArgumentException('An OpenID Connect identity requires a subject.');
        }

        return new self(self::OIDC_PREFIX.self::base64Url($issuer).':'.self::base64Url($subject));
    }

    public function value(): string
    {
        return $this->value;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
