<?php

namespace Omnibill\Odoo\Tests;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\NotSupportedException;
use Omnibill\Exception\ProviderException;
use Omnibill\Gateway;
use Omnibill\Model\Address;
use Omnibill\Model\Capabilities;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Model\EntryLine;
use Omnibill\Model\EntryStatus;
use Omnibill\Model\JournalEntry;
use Omnibill\Model\Vat;
use Omnibill\Model\VatKind;
use Omnibill\Odoo\OdooGatewayFactory;
use Omnibill\Request\Submit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Odoo 19's external JSON-2 API documentation and
 * its server's code (addons/rpc/controllers/json2.py, the ORM's methods,
 * the account and base models), read on 2026-11-10: no instance was used,
 * no call left.
 */
final class OdooGatewayTest extends TestCase
{
    private const URL = 'https://meyers.odoo.example';

    /** @var list<array{string, string, array<array-key, mixed>}> */
    private array $calls = [];

    /**
     * @param list<string>         $answers fixture[:status]
     * @param array<string, mixed> $options
     */
    private function gateway(array $answers, array $options = []): Gateway
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$method.' '.$url);
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status, 'response_headers' => ['content-type' => 'application/json; charset=utf-8']]);
        });
        $gateway = (new OdooGatewayFactory($http))->create($options + ['url' => self::URL.'/', 'api_key' => 'odoo-api-key', 'database' => 'meyers']);
        self::assertInstanceOf(Gateway::class, $gateway);

        return $gateway;
    }

    /** @return array{string, array<array-key, mixed>} the call's path and its JSON body */
    private function call(int $n): array
    {
        $body = $this->calls[$n][2]['body'] ?? '';
        $decoded = json_decode(\is_string($body) ? $body : '', true);

        return [substr($this->calls[$n][1], \strlen(self::URL)), \is_array($decoded) ? $decoded : []];
    }

    private function header(int $call, string $name): ?string
    {
        foreach ((array) ($this->calls[$call][2]['headers'] ?? []) as $line) {
            [$n, $value] = explode(': ', \is_string($line) ? $line : '', 2);
            if (0 === strcasecmp($n, $name)) {
                return $value;
            }
        }

        return null;
    }

    /** An electricity bill, as omnibase/ledger's EntryDraft writes it: ->taxed('615', 12000, 0, $a20)->line('401EDF', 0, 12000, $edf). */
    private static function bill(): JournalEntry
    {
        return new JournalEntry('AC', new \DateTimeImmutable('2026-11-10'), 'Électricité novembre', [
            new EntryLine('615', 10000, 0, vat: new Vat(2000, VatKind::DEDUCTIBLE, code: 'A20')),
            new EntryLine('44566', 2000, 0, 'TVA 20 %', vat: new Vat(2000, VatKind::DEDUCTIBLE, 10000, 'A20')),
            new EntryLine('401EDF', 0, 12000, contact: 'supplier:edf'),
        ], 'EDF-2026-11', 'bill:edf-2026-11');
    }

    public function testAnEntryIsCreatedWithItsJournalAccountsAndPartnerThenPostedAndReadBack(): void
    {
        $posted = $this->gateway(['journal', 'accounts', 'partners', 'created', 'posted', 'move'])->postEntry(self::bill());

        self::assertSame(['/json/2/account.journal/search_read', ['domain' => [['code', 'in', ['AC']]], 'fields' => ['id', 'code']]], $this->call(0));
        self::assertSame(['/json/2/account.account/search_read', ['domain' => [['code', 'in', ['615', '44566', '401EDF']]], 'fields' => ['id', 'code']]], $this->call(1));
        self::assertSame(['/json/2/res.partner/search_read', ['domain' => [['ref', 'in', ['supplier:edf']]], 'fields' => ['id', 'ref']]], $this->call(2));
        self::assertSame(['/json/2/account.move/create', ['vals_list' => [[
            'move_type' => 'entry', 'journal_id' => 2, 'date' => '2026-11-10', 'ref' => 'EDF-2026-11',
            'line_ids' => [
                [0, 0, ['account_id' => 31, 'name' => 'Électricité novembre', 'debit' => 100, 'credit' => 0]],
                [0, 0, ['account_id' => 44, 'name' => 'TVA 20 %', 'debit' => 20, 'credit' => 0]],
                [0, 0, ['account_id' => 12, 'partner_id' => 57, 'name' => 'Électricité novembre', 'debit' => 0, 'credit' => 120]],
            ],
        ]]]], $this->call(3));
        self::assertSame(['/json/2/account.move/action_post', ['ids' => [42]]], $this->call(4));
        self::assertSame(['/json/2/account.move/read', ['ids' => [42], 'fields' => ['name', 'state', 'date', 'ref', 'journal_id']]], $this->call(5));
        self::assertSame('POST', $this->calls[3][0]);
        self::assertSame(['bearer odoo-api-key', 'meyers', 'application/json; charset=utf-8'], [$this->header(3, 'Authorization'), $this->header(3, 'X-Odoo-Database'), $this->header(3, 'Content-Type')]);
        self::assertStringNotContainsString('/xmlrpc', implode(' ', array_column($this->calls, 1)));
        self::assertStringNotContainsString('/jsonrpc', implode(' ', array_column($this->calls, 1)));

        self::assertSame(['42', 'ACH/2026/11/0001', EntryStatus::POSTED, 'bill:edf-2026-11', 'AC'], [$posted->reference, $posted->number, $posted->status, $posted->key, $posted->journal]);
    }

    public function testADraftStaysADraftAndTheCompanyIsPinned(): void
    {
        $this->gateway(['journal', 'accounts', 'partners', 'created', 'move'], ['post' => false, 'company_id' => 3, 'lang' => 'fr_FR'])->postEntry(self::bill());

        self::assertSame(['/json/2/account.move/read'], [$this->call(4)[0]], 'no action_post');
        self::assertSame(['lang' => 'fr_FR', 'allowed_company_ids' => [3]], $this->call(1)[1]['context'] ?? null);
    }

    public function testAnUnbalancedEntryIsRefusedBeforeAnyCall(): void
    {
        try {
            $this->gateway([])->postEntry(new JournalEntry('OD', new \DateTimeImmutable(), 'x', [new EntryLine('512', 100, 0)]));
            self::fail();
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('not balanced', $e->getMessage());
        }
        self::assertSame([], $this->calls);
    }

    public function testAnAccountOdooHasNotIsSaid(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No account.account with the code 401EDF');
        $this->gateway(['journal', 'journal'])->postEntry(new JournalEntry('AC', new \DateTimeImmutable(), 'x', [new EntryLine('401EDF', 0, 100), new EntryLine('AC', 100, 0)]));
    }

    public function testAPostedEntryIsReadWithItsLines(): void
    {
        $entry = $this->gateway(['move-full', 'lines', 'accounts', 'journal'])->fetchEntry(new JournalEntry('?', new \DateTimeImmutable(), '?', reference: '42'));

        self::assertSame(['/json/2/account.move.line/read', ['ids' => [101, 102, 103], 'fields' => ['account_id', 'partner_id', 'name', 'debit', 'credit']]], $this->call(1));
        self::assertSame(['/json/2/account.account/read', ['ids' => [31, 44, 12], 'fields' => ['code']]], $this->call(2));
        self::assertSame(['AC', 'ACH/2026/11/0001', EntryStatus::POSTED, 'EDF-2026-11', '2026-11-10'], [$entry->journal, $entry->number, $entry->status, $entry->piece, $entry->date->format('Y-m-d')]);
        self::assertSame([['615', 10000, 0, null], ['44566', 2000, 0, null], ['401EDF', 0, 12000, '57']], array_map(static fn ($l) => [$l->account, $l->debit, $l->credit, $l->contact], $entry->lines));
        self::assertTrue($entry->isBalanced());
    }

    public function testEntriesArePagedByOffset(): void
    {
        $list = $this->gateway(['moves', 'journals'])->entries(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), limit: 2);

        self::assertSame(['/json/2/account.move/search_read', ['domain' => [['move_type', '=', 'entry'], ['date', '>=', '2026-11-01'], ['date', '<=', '2026-11-30']], 'fields' => ['name', 'state', 'date', 'ref', 'journal_id'], 'limit' => 2, 'order' => 'date desc, id desc']], $this->call(0));
        self::assertSame([['AC', 'ACH/2026/11/0001', EntryStatus::POSTED], ['VT', null, EntryStatus::DRAFT]], array_map(static fn ($e) => [$e->journal, $e->number, $e->status], $list->entries));
        self::assertSame('2', $list->cursor);
    }

    public function testASupplierIsCreatedThenUpdatedThenRead(): void
    {
        $gateway = $this->gateway(['country', 'partner-created', 'country', 'written', 'partner', 'country']);
        $edf = new Contact(ContactKind::SUPPLIER, 'EDF', 'supplier:edf', '401EDF', 'factures@edf.example', '552081317', '55208131766522', 'FR03552081317', 'FR7630004000031234567890143', new Address('22-30 avenue de Wagram', '75008', 'Paris'));
        $saved = $gateway->saveContact($edf);

        self::assertSame(['/json/2/res.country/search_read', ['domain' => [['code', 'in', ['FR']]], 'fields' => ['id', 'code']]], $this->call(0));
        self::assertSame(['/json/2/res.partner/create', ['vals_list' => [[
            'name' => 'EDF', 'email' => 'factures@edf.example', 'vat' => 'FR03552081317', 'company_registry' => '55208131766522', 'is_company' => true, 'ref' => 'supplier:edf', 'supplier_rank' => 1,
            'street' => '22-30 avenue de Wagram', 'zip' => '75008', 'city' => 'Paris', 'country_id' => 75,
            'bank_ids' => [[0, 0, ['acc_number' => 'FR7630004000031234567890143']]],
        ]]]], $this->call(1));
        self::assertSame('57', $saved->reference);

        $gateway->saveContact($saved);
        self::assertSame('/json/2/res.partner/write', $this->call(3)[0]);
        self::assertSame([57], $this->call(3)[1]['ids'] ?? null);
        self::assertArrayNotHasKey('bank_ids', (array) ($this->call(3)[1]['vals'] ?? []), 'the bank account is given once');

        $read = $gateway->fetchContact($saved);
        self::assertSame([ContactKind::SUPPLIER, 'EDF', 'supplier:edf', '552081317', '55208131766522', 'Paris', 'FR'], [$read->kind, $read->name, $read->key, $read->siren, $read->siret, $read->address?->city, $read->address?->country]);
    }

    public function testContactsAreListedByKindAndName(): void
    {
        $list = $this->gateway(['partner'])->contacts(ContactKind::SUPPLIER, 'ED', limit: 10);

        self::assertSame(['domain' => [['supplier_rank', '>', 0], ['name', '=ilike', 'ED%']], 'fields' => ['id', 'name', 'email', 'vat', 'company_registry', 'is_company', 'ref', 'customer_rank', 'supplier_rank', 'street', 'zip', 'city', 'country_id'], 'limit' => 10, 'order' => 'name'], $this->call(0)[1]);
        self::assertSame(['57'], array_map(static fn ($c) => $c->reference, $list->contacts));
        self::assertNull($list->cursor);
    }

    public function testErrors(): void
    {
        try {
            $this->gateway(['error-401:401'])->contacts(ContactKind::CUSTOMER);
            self::fail();
        } catch (InvalidKeyException $e) {
            self::assertStringContainsString('Invalid apikey', $e->getMessage());
            self::assertStringNotContainsString('Traceback', $e->getMessage());
        }
        try {
            $this->gateway(['journal', 'accounts', 'partners', 'error-422:422'])->postEntry(self::bill());
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame('odoo.exceptions.UserError', $e->providerCode);
            self::assertStringContainsString('The entry is not balanced.', $e->getMessage());
        }
    }

    public function testItKeepsBooksAndCarriesNoInvoice(): void
    {
        $gateway = $this->gateway([]);
        $capabilities = $gateway->capabilities();

        self::assertSame([Capabilities::JOURNAL_ENTRIES, Capabilities::CONTACTS], $capabilities->contracts);
        self::assertFalse($capabilities->keeps(Capabilities::E_INVOICES));
        self::assertStringContainsString('JSON-2', implode(' ', $capabilities->terms->rules ?? []));
        self::assertFalse($gateway->supports(Submit::class));
        $this->expectException(InvalidConfigException::class);
        (new OdooGatewayFactory(new MockHttpClient()))->create(['url' => self::URL]);
    }
}
