<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Authentication\ExternalIdentityKey;
use App\Models\ActorReference;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Pre-provisions the Actor Reference of the optional local OpenID Connect
 * demonstration (README, "Local OpenID Connect demonstration"): the
 * `demo-requester` user of the Keycloak demo realm, whose fixed user ID is its
 * `sub`. It runs only when asked for, only in the local environment and only
 * with the demo issuer configured. The realm's `demo-unprovisioned` user is
 * deliberately left out: logging in as it must fail closed.
 */
final class LocalOidcDemoSeeder extends Seeder
{
    public const ISSUER = 'http://idp.stewardarc.test:8080/realms/stewardarc-demo';

    public const REQUESTER_SUBJECT = 'd007fc4c-d9a1-4f9f-8a7b-294f3c08c8b8';

    public const REQUESTER_DISPLAY_NAME = 'Demo Requester';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('The local OpenID Connect demo seeder only runs in the local environment.');
        }

        if (config('oidc.issuer') !== self::ISSUER) {
            throw new RuntimeException('The local OpenID Connect demo seeder requires the demo issuer in OIDC_ISSUER.');
        }

        $key = ExternalIdentityKey::fromOidc(self::ISSUER, self::REQUESTER_SUBJECT)->value();

        if (ActorReference::query()->where('external_identity_key', $key)->exists()) {
            return;
        }

        // Actor References allow no mass assignment.
        $actor = new ActorReference();
        $actor->external_identity_key = $key;
        $actor->display_name = self::REQUESTER_DISPLAY_NAME;
        $actor->save();
    }
}
