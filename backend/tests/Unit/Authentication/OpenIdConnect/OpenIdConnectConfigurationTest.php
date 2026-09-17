<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication\OpenIdConnect;

use App\Authentication\OpenIdConnect\OpenIdConnectConfiguration;
use App\Authentication\OpenIdConnect\OpenIdConnectFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenIdConnectConfigurationTest extends TestCase
{
    private const VALID = [
        'issuer' => 'https://id.example.test/realms/stewardarc',
        'client_id' => 'stewardarc-web',
        'client_secret' => 'local-secret',
        'redirect_uri' => 'http://localhost:8000/auth/callback',
    ];

    public function test_a_complete_configuration_is_accepted_as_is(): void
    {
        $config = OpenIdConnectConfiguration::fromArray(self::VALID);

        $this->assertSame(self::VALID['issuer'], $config->issuer);
        $this->assertSame(self::VALID['client_id'], $config->clientId);
        $this->assertSame(self::VALID['client_secret'], $config->clientSecret);
        $this->assertSame(self::VALID['redirect_uri'], $config->redirectUri);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('unusableConfigurations')]
    public function test_an_unusable_configuration_fails_closed(array $config): void
    {
        try {
            OpenIdConnectConfiguration::fromArray($config);
            $this->fail('The configuration should have been refused.');
        } catch (OpenIdConnectFailure $failure) {
            $this->assertSame(OpenIdConnectFailure::CONFIGURATION, $failure->reason);
            $this->assertStringNotContainsString('local-secret', $failure->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableConfigurations(): array
    {
        $cases = ['nothing configured' => [[]]];

        foreach (array_keys(self::VALID) as $key) {
            $cases["missing {$key}"] = [array_diff_key(self::VALID, [$key => true])];
            $cases["empty {$key}"] = [[$key => ''] + self::VALID];
            $cases["null {$key}"] = [[$key => null] + self::VALID];
            $cases["non-string {$key}"] = [[$key => 42] + self::VALID];
        }

        foreach (['issuer', 'redirect_uri'] as $key) {
            $cases["{$key} without scheme"] = [[$key => 'id.example.test/realms/stewardarc'] + self::VALID];
            $cases["{$key} with another scheme"] = [[$key => 'ftp://id.example.test/'] + self::VALID];
            $cases["{$key} without host"] = [[$key => 'https:///path'] + self::VALID];
        }

        return $cases;
    }
}
