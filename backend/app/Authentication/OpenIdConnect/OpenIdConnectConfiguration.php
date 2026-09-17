<?php

declare(strict_types=1);

namespace App\Authentication\OpenIdConnect;

/**
 * The Relying Party settings, read only when the login is used.
 */
final class OpenIdConnectConfiguration
{
    private function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config  the `oidc` configuration
     *
     * @throws OpenIdConnectFailure when a value is missing or unusable
     */
    public static function fromArray(array $config): self
    {
        $values = [];

        foreach (['issuer', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || $value === '') {
                throw OpenIdConnectFailure::because(OpenIdConnectFailure::CONFIGURATION);
            }

            $values[$key] = $value;
        }

        foreach (['issuer', 'redirect_uri'] as $key) {
            $scheme = parse_url($values[$key], PHP_URL_SCHEME);

            if (! in_array($scheme, ['http', 'https'], true) || ! is_string(parse_url($values[$key], PHP_URL_HOST))) {
                throw OpenIdConnectFailure::because(OpenIdConnectFailure::CONFIGURATION);
            }
        }

        return new self($values['issuer'], $values['client_id'], $values['client_secret'], $values['redirect_uri']);
    }
}
