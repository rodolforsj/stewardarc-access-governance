<?php

declare(strict_types=1);

namespace App\Authentication\OpenIdConnect;

use RuntimeException;

/**
 * Any failure of the OpenID Connect login. The reason is a fixed, non-sensitive
 * label; no code, token, claim or transaction value is ever carried, and the
 * underlying library exception is deliberately not chained.
 */
final class OpenIdConnectFailure extends RuntimeException
{
    public const CONFIGURATION = 'configuration';

    public const PROVIDER_METADATA = 'provider_metadata';

    public const MISSING_TRANSACTION = 'missing_transaction';

    public const EXPIRED_TRANSACTION = 'expired_transaction';

    public const PROVIDER_ERROR = 'provider_error';

    public const MISSING_STATE = 'missing_state';

    public const STATE_MISMATCH = 'state_mismatch';

    public const MISSING_CODE = 'missing_code';

    public const TOKEN_EXCHANGE = 'token_exchange';

    public const ID_TOKEN = 'id_token';

    public const IDENTITY_CLAIMS = 'identity_claims';

    private function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('OpenID Connect authentication failed.');
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
