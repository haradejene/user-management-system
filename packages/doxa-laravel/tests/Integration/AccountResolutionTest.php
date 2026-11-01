<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Contracts\AccountResolver;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\Exceptions\ApplicationAccessException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\CodeExchange;
use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountResolutionTest extends TestCase
{
    public static function decisions(): array
    {
        return [['allowed', true], ['false', false], ['missing', false], ['extra', false], ['outage', false]];
    }

    #[DataProvider('decisions')]
    public function test_access_is_required_before_host_resolution(string $decision, bool $allowed): void
    {
        $h = new Harness;
        try {
            $endpoint = Harness::ISSUER.'/oauth/application-access';
            $h->http->responses[$endpoint] = match ($decision) {
                'allowed' => ['effective' => true], 'false' => ['effective' => false],
                'extra' => ['effective' => true, 'extra' => 1],
                'outage' => new \RuntimeException('PRIVATE transport details'), default => [],
            };
            $resolver = new class implements AccountResolver
            {
                public int $calls = 0;

                public function resolve(DoxaIdentity $identity): ?Authenticatable
                {
                    $this->calls++;

                    return null;
                }
            };
            $client = new DoxaClient($h->config, $h->discovery, new Authorization($h->discovery), new BrowserContext,
                $h->store, new CodeExchange($h->config, $h->http, $h->store), $h->validator,
                new UserInfo($h->http), $h->request(), null, $h->http);
            $h->begin();
            try {
                self::assertNull($client->resolveAccount($h->callback(), $resolver));
                self::assertTrue($allowed);
            } catch (ApplicationAccessException $e) {
                self::assertFalse($allowed);
                self::assertContains($e->category, ['access_denied_or_insufficient_scope', 'transient_service_failure']);
                self::assertNull($e->getPrevious());
            }
            self::assertSame($allowed ? 1 : 0, $resolver->calls);
            self::assertSame(1, $h->http->count($endpoint));
            self::assertSame(1, $h->http->count(Harness::TOKEN));
        } finally {
            $h->cleanup();
        }
    }
}
