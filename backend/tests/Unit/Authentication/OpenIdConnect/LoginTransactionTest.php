<?php

declare(strict_types=1);

namespace Tests\Unit\Authentication\OpenIdConnect;

use App\Authentication\OpenIdConnect\LoginTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The OpenID Connect login transaction: `state`, `nonce` and PKCE (RFC 7636).
 */
final class LoginTransactionTest extends TestCase
{
    private const NOW = 1_789_650_000;

    public function test_a_new_transaction_has_independent_random_values(): void
    {
        $transaction = LoginTransaction::start(self::NOW);

        foreach ([$transaction->state, $transaction->nonce, $transaction->codeVerifier] as $value) {
            // 32 random bytes in unpadded Base64URL.
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $value);
            $this->assertSame(32, strlen(base64_decode(strtr($value, '-_', '+/'), true)));
        }

        $this->assertCount(3, array_unique([$transaction->state, $transaction->nonce, $transaction->codeVerifier]));
        $this->assertSame(self::NOW, $transaction->createdAt);

        $other = LoginTransaction::start(self::NOW);
        $this->assertNotSame($transaction->state, $other->state);
        $this->assertNotSame($transaction->nonce, $other->nonce);
        $this->assertNotSame($transaction->codeVerifier, $other->codeVerifier);
    }

    /** RFC 7636, section 4.1: 43 to 128 unreserved characters. */
    public function test_the_code_verifier_satisfies_rfc_7636(): void
    {
        $verifier = LoginTransaction::start(self::NOW)->codeVerifier;

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier);
    }

    /** RFC 7636, appendix B. */
    public function test_the_code_challenge_is_the_s256_transform_of_the_verifier(): void
    {
        $transaction = LoginTransaction::fromArray([
            'state' => 'state',
            'nonce' => 'nonce',
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            'created_at' => self::NOW,
        ]);

        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $transaction->codeChallenge());
    }

    public function test_the_transaction_expires_after_ten_minutes(): void
    {
        $transaction = LoginTransaction::start(self::NOW);

        $this->assertSame(600, LoginTransaction::TTL_SECONDS);
        $this->assertFalse($transaction->isExpiredAt(self::NOW));
        $this->assertFalse($transaction->isExpiredAt(self::NOW + 600));
        $this->assertTrue($transaction->isExpiredAt(self::NOW + 601));
        // A transaction from the future is not trusted either.
        $this->assertTrue($transaction->isExpiredAt(self::NOW - 1));
    }

    public function test_the_state_must_match_exactly(): void
    {
        $transaction = LoginTransaction::start(self::NOW);

        $this->assertTrue($transaction->matchesState($transaction->state));

        foreach (['', strtoupper($transaction->state), $transaction->state.' ', substr($transaction->state, 1), $transaction->nonce] as $other) {
            $this->assertFalse($transaction->matchesState($other));
        }
    }

    public function test_the_transaction_round_trips_through_the_session_format(): void
    {
        $transaction = LoginTransaction::start(self::NOW);
        $stored = $transaction->toArray();

        $this->assertSame(['state', 'nonce', 'code_verifier', 'created_at'], array_keys($stored));

        // The session serializes to JSON.
        $restored = LoginTransaction::fromArray(json_decode(json_encode($stored), true));

        $this->assertNotNull($restored);
        $this->assertSame($stored, $restored->toArray());
    }

    #[DataProvider('malformedTransactions')]
    public function test_a_malformed_stored_transaction_is_rejected(mixed $stored): void
    {
        $this->assertNull(LoginTransaction::fromArray($stored));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedTransactions(): array
    {
        $valid = ['state' => 's', 'nonce' => 'n', 'code_verifier' => 'v', 'created_at' => self::NOW];

        return [
            'nothing' => [null],
            'a string' => ['transaction'],
            'empty array' => [[]],
            'missing state' => [array_diff_key($valid, ['state' => true])],
            'missing nonce' => [array_diff_key($valid, ['nonce' => true])],
            'missing verifier' => [array_diff_key($valid, ['code_verifier' => true])],
            'missing creation time' => [array_diff_key($valid, ['created_at' => true])],
            'empty state' => [['state' => ''] + $valid],
            'empty nonce' => [['nonce' => ''] + $valid],
            'empty verifier' => [['code_verifier' => ''] + $valid],
            'non-string state' => [['state' => 1] + $valid],
            'textual creation time' => [['created_at' => (string) self::NOW] + $valid],
        ];
    }
}
