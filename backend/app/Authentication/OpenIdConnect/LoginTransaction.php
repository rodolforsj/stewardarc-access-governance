<?php

declare(strict_types=1);

namespace App\Authentication\OpenIdConnect;

/**
 * The pending OpenID Connect login kept in the session between the redirect to
 * the provider and its callback: `state`, `nonce` and the PKCE verifier, each
 * 32 random bytes in unpadded Base64URL (43 characters, a valid RFC 7636
 * verifier), plus the Unix time it was created. It is single-use and
 * short-lived.
 */
final class LoginTransaction
{
    /** A technical guard against stale logins, not a functional rule. */
    public const TTL_SECONDS = 600;

    private function __construct(
        public readonly string $state,
        public readonly string $nonce,
        public readonly string $codeVerifier,
        public readonly int $createdAt,
    ) {
    }

    public static function start(int $now): self
    {
        return new self(self::randomValue(), self::randomValue(), self::randomValue(), $now);
    }

    /**
     * PKCE S256: BASE64URL(SHA256(code_verifier)).
     */
    public function codeChallenge(): string
    {
        return self::base64Url(hash('sha256', $this->codeVerifier, true));
    }

    public function isExpiredAt(int $now): bool
    {
        $age = $now - $this->createdAt;

        return $age < 0 || $age > self::TTL_SECONDS;
    }

    public function matchesState(string $state): bool
    {
        return hash_equals($this->state, $state);
    }

    /**
     * @return array{state: string, nonce: string, code_verifier: string, created_at: int}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Rebuilds a transaction from the session, or null when the stored value
     * is not a complete transaction.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        foreach (['state', 'nonce', 'code_verifier'] as $key) {
            if (! is_string($stored[$key] ?? null) || $stored[$key] === '') {
                return null;
            }
        }

        if (! is_int($stored['created_at'] ?? null)) {
            return null;
        }

        return new self($stored['state'], $stored['nonce'], $stored['code_verifier'], $stored['created_at']);
    }

    private static function randomValue(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
