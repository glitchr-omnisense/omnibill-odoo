<?php

namespace Omnibill\Odoo\Tests;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\Gateway;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Odoo\OdooGatewayFactory;
use Omnibill\Request\Accounting\FindContact;
use Omnisense\Contracts\Testing\MockHttp;
use PHPUnit\Framework\TestCase;

/**
 * Since omnibill 1.3: a partner whose creation's answer was lost, found
 * again by its ref. Answers written from Odoo 19's JSON-2 API
 * documentation and its base module's res.partner (ref, a Char), read on
 * 2026-11-11: no instance, no call left.
 */
final class ContactLookupTest extends TestCase
{
    private const URL = 'https://books.odoo.example';

    private const PARTNER = '{"id":%d,"name":"EDF","email":"factures@edf.example","vat":"FR03552081317","company_registry":"55208131766522","is_company":true,"ref":"supplier:edf","customer_rank":%d,"supplier_rank":%d,"street":"22-30 avenue de Wagram","zip":"75008","city":"Paris","country_id":[75,"France"]}';

    private static function gateway(MockHttp $http): Gateway
    {
        $gateway = (new OdooGatewayFactory($http->client))->create(['url' => self::URL, 'api_key' => 'odoo-api-key', 'database' => 'books']);
        self::assertInstanceOf(Gateway::class, $gateway);

        return $gateway;
    }

    public function testAPartnerWhoseCreationsAnswerWasLostIsFoundByItsRef(): void
    {
        $lost = MockHttp::routes(['~/json/2/res\.partner/create$~' => [502, '']]);
        try {
            self::gateway($lost)->saveContact(new Contact(ContactKind::SUPPLIER, 'EDF', 'supplier:edf'));
            self::fail();
        } catch (OutcomeUnknownException) {
            self::assertSame(['POST '.self::URL.'/json/2/res.partner/create'], $lost->lines(), 'not sent again');
        }

        $http = MockHttp::routes([
            '~^POST https://books\.odoo\.example/json/2/res\.partner/search_read$~' => [200, '['.\sprintf(self::PARTNER, 56, 1, 0).','.\sprintf(self::PARTNER, 57, 0, 1).']'],
            '~^POST https://books\.odoo\.example/json/2/res\.country/read$~' => [200, '[{"id":75,"code":"FR"}]'],
        ]);
        $gateway = self::gateway($http);
        self::assertTrue($gateway->supports(FindContact::class));
        $found = $gateway->findContact(new Contact(ContactKind::SUPPLIER, 'EDF', 'supplier:edf'));

        self::assertSame(['57', 'supplier:edf', ContactKind::SUPPLIER, 'FR'], [$found?->reference, $found?->key, $found?->kind, $found?->address?->country], 'the one of its kind, though another carries the same ref');
        self::assertSame(['domain' => [['ref', '=', 'supplier:edf']], 'fields' => ['id', 'name', 'email', 'vat', 'company_registry', 'is_company', 'ref', 'customer_rank', 'supplier_rank', 'street', 'zip', 'city', 'country_id'], 'limit' => 10, 'order' => 'id asc'], $http->json(0));
        self::assertSame(['ids' => [75], 'fields' => ['code']], $http->json(1));
        self::assertSame(['bearer odoo-api-key', 'books'], [$http->header('authorization', 0), $http->header('x-odoo-database', 0)]);
    }

    public function testNoneIsAnAnswerAndASearchThatFailsWritesNothing(): void
    {
        self::assertNull(self::gateway(MockHttp::routes(['~/res\.partner/search_read$~' => [200, '[]']]))->findContact(new Contact(ContactKind::CUSTOMER, 'Camille', 'lease-42')));

        try {
            self::gateway(MockHttp::routes(['~/res\.partner/search_read$~' => [503, '']]))->findContact(new Contact(ContactKind::CUSTOMER, 'Camille', 'lease-42'));
            self::fail();
        } catch (\Omnibill\Exception\UnreachableException $e) {
            self::assertNotInstanceOf(OutcomeUnknownException::class, $e, 'search_read is a read');
        }
    }

    public function testAContactWithoutAKeyCannotBeFound(): void
    {
        $http = MockHttp::routes([]);
        $this->expectException(InvalidConfigException::class);
        try {
            self::gateway($http)->findContact(new Contact(ContactKind::SUPPLIER, 'EDF'));
        } finally {
            self::assertSame([], $http->requests);
        }
    }
}
