<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Exceptions\UserInfoException;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\TokenResponse;
use PHPUnit\Framework\TestCase;

final class UserInfoTest extends TestCase
{
    public function test_optional_enrichment_is_matching_sub_and_bearer_only(): void
    {
        $h = new Harness(['userinfo_enabled' => true]);
        try {
            $h->begin();
            $h->http->responses[Harness::USERINFO] = ['sub' => 'public-subject', 'name' => 'Updated', 'email_verified' => false, 'roles' => ['admin']];
            $identity = $h->client->handleCallback($h->callback());
            self::assertSame('public-subject', $identity->subject());
            self::assertSame('Updated', $identity->name());
            self::assertFalse($identity->emailVerified());
            self::assertArrayNotHasKey('roles', $identity->jsonSerialize());
            $request = array_values(array_filter($h->http->requests, fn ($r) => $r[1] === Harness::USERINFO))[0];
            self::assertSame('Bearer ACCESS-SECRET', $request[2]['headers']['Authorization']);
            self::assertStringNotContainsString('ACCESS-SECRET', $request[1]);
            self::assertArrayNotHasKey('query', $request[2]);
        } finally {
            $h->cleanup();
        }
    }

    public function test_subject_mismatch_is_discarded(): void
    {
        $h = new Harness(['userinfo_enabled' => true]);
        try {
            $h->begin();
            $h->http->responses[Harness::USERINFO] = ['sub' => 'attacker', 'name' => 'Attacker'];
            $this->expectException(UserInfoException::class);
            $h->client->handleCallback($h->callback());
        } finally {
            $h->cleanup();
        }
    }

    public function test_unauthorized_userinfo_is_safe(): void
    {
        $h = new Harness(['userinfo_enabled' => true]);
        try {
            $h->begin();
            $h->http->responses[Harness::USERINFO] = new \RuntimeException('Bearer ACCESS-SECRET');
            $this->expectException(UserInfoException::class);
            $h->client->handleCallback($h->callback());
        } finally {
            $h->cleanup();
        }
    }

    public function test_openid_provenance_required(): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $transaction = $h->transaction();
            $identity = $h->client->handleCallback($h->callback());
            $this->expectException(UserInfoException::class);
            (new UserInfo($h->http))->fetch($identity, new TokenResponse('ID', 'ACCESS', []), $transaction);
        } finally {
            $h->cleanup();
        }
    }
}
