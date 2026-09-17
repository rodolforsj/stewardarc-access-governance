<?php

declare(strict_types=1);

namespace Tests\PostgreSQL\Authentication;

use App\Authentication\ExternalIdentityKey;
use App\Models\ActorReference;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\PostgreSQL\PostgresTestCase;

/**
 * The local OpenID Connect demonstration pre-provisions one Actor Reference,
 * only on request, only locally and only for the demo issuer (ADR-010,
 * ADR-011: nothing is provisioned automatically).
 */
final class LocalOidcDemoSeederTest extends PostgresTestCase
{
    private const DEMO_ISSUER = 'http://idp.stewardarc.test:8080/realms/stewardarc-demo';

    /** The fixed user IDs, and therefore subjects, of keycloak/stewardarc-demo-realm.json. */
    private const REQUESTER_SUBJECT = 'd007fc4c-d9a1-4f9f-8a7b-294f3c08c8b8';

    private const UNPROVISIONED_SUBJECT = '5738d161-928d-4d15-ad93-6d74863a54f6';

    public function test_it_provisions_the_demo_requester_once(): void
    {
        $this->inEnvironment('local', self::DEMO_ISSUER, function (): void {
            $this->seedDemo();

            $actor = ActorReference::query()->sole();
            $this->assertSame('Demo Requester', $actor->display_name);
            $this->assertSame(
                ExternalIdentityKey::fromOidc(self::DEMO_ISSUER, self::REQUESTER_SUBJECT)->value(),
                $actor->external_identity_key
            );

            // Running it again finds the same actor and creates nothing.
            $this->seedDemo();

            $this->assertSame([$actor->id], ActorReference::query()->pluck('id')->all());
            $this->assertSame($actor->getAttributes(), ActorReference::query()->sole()->getAttributes());
        });
    }

    public function test_the_unprovisioned_demo_user_gets_no_actor_reference(): void
    {
        $this->inEnvironment('local', self::DEMO_ISSUER, function (): void {
            $this->seedDemo();

            $unprovisioned = ExternalIdentityKey::fromOidc(self::DEMO_ISSUER, self::UNPROVISIONED_SUBJECT)->value();
            $this->assertFalse(ActorReference::query()->where('external_identity_key', $unprovisioned)->exists());
            $this->assertSame(1, ActorReference::query()->count());

            // Provisioning grants no authority.
            $this->assertSame(0, DB::table('governance_memberships')->count());
            $this->assertSame(0, DB::table('resources')->count());
        });
    }

    public function test_the_default_seeder_does_not_run_it(): void
    {
        $this->inEnvironment('local', self::DEMO_ISSUER, function (): void {
            $this->seed();

            $this->assertSame(0, ActorReference::query()->count());
        });
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherEnvironments(): array
    {
        return [
            'testing' => ['testing'],
            'staging' => ['staging'],
            'production' => ['production'],
        ];
    }

    #[DataProvider('otherEnvironments')]
    public function test_it_fails_closed_outside_the_local_environment(string $environment): void
    {
        $this->assertRefused($environment, self::DEMO_ISSUER, 'only runs in the local environment');
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function otherIssuers(): array
    {
        return [
            'not configured' => [null],
            'empty' => [''],
            'trailing slash' => [self::DEMO_ISSUER.'/'],
            'upper-case host' => ['http://IDP.stewardarc.test:8080/realms/stewardarc-demo'],
            'https' => ['https://idp.stewardarc.test:8080/realms/stewardarc-demo'],
            'localhost' => ['http://localhost:8080/realms/stewardarc-demo'],
            'service name' => ['http://keycloak:8080/realms/stewardarc-demo'],
            'another realm' => ['http://idp.stewardarc.test:8080/realms/stewardarc'],
            'another provider' => [FakeOpenIdProvider::ISSUER],
        ];
    }

    #[DataProvider('otherIssuers')]
    public function test_it_fails_closed_for_any_other_issuer(?string $issuer): void
    {
        $this->assertRefused('local', $issuer, 'requires the demo issuer');
    }

    private function assertRefused(string $environment, ?string $issuer, string $reason): void
    {
        $this->inEnvironment($environment, $issuer, function () use ($reason): void {
            try {
                $this->seedDemo();
                $this->fail('The demo seeder must refuse to run.');
            } catch (RuntimeException $refusal) {
                $this->assertStringContainsString($reason, $refusal->getMessage());
            }

            $this->assertSame(0, ActorReference::query()->count());
        });
    }

    /**
     * The documented command. --force gets past the production confirmation,
     * so only the seeder's own guards can stop it.
     */
    private function seedDemo(): void
    {
        $this->artisan('db:seed', ['--class' => 'LocalOidcDemoSeeder', '--force' => true])
            ->assertSuccessful()
            ->run();
    }

    private function inEnvironment(string $environment, ?string $issuer, callable $test): void
    {
        $previousEnvironment = $this->app['env'];
        $previousIssuer = config('oidc.issuer');

        $this->app['env'] = $environment;
        config(['oidc.issuer' => $issuer]);

        try {
            $test();
        } finally {
            $this->app['env'] = $previousEnvironment;
            config(['oidc.issuer' => $previousIssuer]);
        }
    }
}
