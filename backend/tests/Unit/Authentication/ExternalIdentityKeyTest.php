<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication;

use App\Authentication\ExternalIdentityKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-011: the persisted representation of an OpenID Connect identity (`iss`, `sub`).
 */
final class ExternalIdentityKeyTest extends TestCase
{
    #[DataProvider('knownKeys')]
    public function test_it_encodes_known_identities_exactly(string $issuer, string $subject, string $expected): void
    {
        $this->assertSame($expected, ExternalIdentityKey::fromOidc($issuer, $subject)->value());
    }

    /** @return array<string, array{string, string, string}> */
    public static function knownKeys(): array
    {
        return [
            'plain issuer and numeric subject' => [
                'https://id.example.test',
                '248289761001',
                'oidc:v1:aHR0cHM6Ly9pZC5leGFtcGxlLnRlc3Q:MjQ4Mjg5NzYxMDAx',
            ],
            'separator-like characters in both claims' => [
                'https://id.example.test/tenant|a',
                'user:42|x',
                'oidc:v1:aHR0cHM6Ly9pZC5leGFtcGxlLnRlc3QvdGVuYW50fGE:dXNlcjo0Mnx4',
            ],
        ];
    }

    public function test_the_same_identity_always_produces_the_same_key(): void
    {
        $first = ExternalIdentityKey::fromOidc('https://id.example.test/realms/a', 'f47ac10b-58cc-4372-a567-0e02b2c3d479');
        $second = ExternalIdentityKey::fromOidc('https://id.example.test/realms/a', 'f47ac10b-58cc-4372-a567-0e02b2c3d479');

        $this->assertSame($first->value(), $second->value());
    }

    /** Both claims are recoverable byte for byte, whatever characters they contain. */
    #[DataProvider('unusualClaims')]
    public function test_each_claim_is_encoded_on_its_own_with_unpadded_base64url(string $issuer, string $subject): void
    {
        $key = ExternalIdentityKey::fromOidc($issuer, $subject)->value();

        $this->assertStringStartsWith('oidc:v1:', $key);
        $this->assertStringNotContainsString('=', $key);

        $parts = explode(':', substr($key, strlen('oidc:v1:')));
        $this->assertCount(2, $parts);

        foreach ($parts as $part) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $part);
        }

        $this->assertSame($issuer, $this->decode($parts[0]));
        $this->assertSame($subject, $this->decode($parts[1]));
    }

    /** @return array<string, array{string, string}> */
    public static function unusualClaims(): array
    {
        return [
            'issuer with port, path, query-like and unicode characters' => ['https://id.example.test:8443/t/ä?x=1&y=:|', 'subject'],
            'subject with colons and pipes' => ['https://id.example.test', 'a:b|c::d||'],
            'lengths that would need padding' => ['https://i', 'ab'],
            'bytes that map to + and / in standard base64' => ["https://id.example.test/\xfb\xff", "\xfb\xff\xfe"],
            'single-character claims' => ['x', 'y'],
        ];
    }

    /** A delimiter inside a claim can never shift the boundary between the two claims. */
    public function test_different_pairs_never_produce_the_same_key(): void
    {
        $pairs = [
            ['https://a|b', 'c'],
            ['https://a', 'b|c'],
            ['https://a:b', 'c'],
            ['https://a', 'b:c'],
            ['https://a:', 'b'],
            ['https://a', ':b'],
        ];

        $keys = array_map(
            static fn (array $pair): string => ExternalIdentityKey::fromOidc($pair[0], $pair[1])->value(),
            $pairs
        );

        $this->assertCount(count($pairs), array_unique($keys));
    }

    public function test_an_empty_issuer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExternalIdentityKey::fromOidc('', '248289761001');
    }

    public function test_an_empty_subject_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExternalIdentityKey::fromOidc('https://id.example.test', '');
    }

    /** Case, whitespace and trailing slashes are significant: nothing is normalized. */
    public function test_claims_are_not_normalized(): void
    {
        $reference = ExternalIdentityKey::fromOidc('https://id.example.test', 'Subject')->value();

        $variants = [
            ['https://id.example.test/', 'Subject'],
            ['HTTPS://ID.EXAMPLE.TEST', 'Subject'],
            [' https://id.example.test', 'Subject'],
            ['https://id.example.test', 'subject'],
            ['https://id.example.test', ' Subject'],
            ['https://id.example.test', 'Subject '],
        ];

        foreach ($variants as [$issuer, $subject]) {
            $variant = ExternalIdentityKey::fromOidc($issuer, $subject);

            $this->assertNotSame($reference, $variant->value());
            $this->assertSame($subject, $this->decode(explode(':', $variant->value())[3]));
        }

        // Whitespace-only claims are not empty, so they are kept as they are.
        $this->assertSame(
            'oidc:v1:IA:IA',
            ExternalIdentityKey::fromOidc(' ', ' ')->value()
        );
    }

    private function decode(string $part): string
    {
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);
        $this->assertIsString($decoded);

        return $decoded;
    }
}
