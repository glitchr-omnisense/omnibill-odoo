<?php

namespace Omnibill\Odoo\Tests;

use Omnibill\Exception\UsageLimitReachedException;
use Omnibill\Odoo\OdooGatewayFactory;
use Omnibill\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Odoo's usage policy: declared in policy.json, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsDeclaredAndRead(): void
    {
        $declared = UsagePolicy::fromFile(__DIR__.'/../policy.json');
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertSame(['policy' => 'policy.json'], $extra['omnisense'] ?? null);
        self::assertSame('odoo', $declared->provider);
        self::assertSame('2026-11-10', $declared->provenance->verifiedAt);
        self::assertSame($declared->toArray(), OdooGatewayFactory::usagePolicy()->toArray());
        self::assertNull($declared->rateLimits, 'Odoo publishes none');
        self::assertSame(Permission::Contract, $declared->rights->commercial, 'the Custom plan on Odoo Online');
        self::assertNotNull(OdooGatewayFactory::terms()->source, 'the terms in words stay');

        $registry = new Registry([new OdooGatewayFactory(new MockHttpClient())], ['odoo' => ['factory' => 'odoo', 'options' => ['url' => 'https://books.example.odoo.com', 'api_key' => 'k']]]);
        self::assertSame($declared->toArray(), $registry->policy('odoo')->toArray());
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30', 'content-type' => 'application/json']]);
        });
        $registry = new Registry([new OdooGatewayFactory($http)], ['odoo' => ['factory' => 'odoo', 'options' => ['url' => 'https://books.example.odoo.com', 'api_key' => 'k']]]);

        try {
            $registry->journalEntries('odoo')->entries();
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        try {
            $registry->journalEntries('odoo')->entries();
            self::fail('A call went out while Odoo asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertSame(1, $requests, 'the wait held the next request back');
    }
}
