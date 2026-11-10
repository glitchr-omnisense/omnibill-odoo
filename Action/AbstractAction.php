<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Action\ActionInterface;
use Omnibill\Action\ApiAwareInterface;
use Omnibill\Action\ApiAwareTrait;
use Omnibill\Exception\ProviderException;
use Omnibill\Model\Address;
use Omnibill\Model\Amount;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Model\EntryStatus;
use Omnibill\Model\JournalEntry;
use Omnibill\Odoo\Api;

/** An action on Odoo's JSON-2 API: the lookups by code the entries and contacts need, the readings back. */
abstract class AbstractAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** account.move's state, as the family's. */
    public const STATUSES = ['draft' => EntryStatus::DRAFT, 'posted' => EntryStatus::POSTED, 'cancel' => EntryStatus::CANCELED];

    /** The partner fields a contact is read from. */
    protected const PARTNER_FIELDS = ['name', 'email', 'vat', 'company_registry', 'is_company', 'ref', 'customer_rank', 'supplier_rank', 'street', 'zip', 'city', 'country_id'];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * Ids by code: account.journal, account.account (an account's code is the
     * company's: Api's company_id pins it), res.country; every code must be found.
     *
     * @param list<string> $codes
     *
     * @return array<string, int>
     */
    protected function ids(string $model, array $codes): array
    {
        $codes = array_values(array_unique($codes));
        if (!$codes) {
            return [];
        }
        $ids = [];
        foreach ($this->api->searchRead($model, [['code', 'in', $codes]], ['id', 'code']) as $row) {
            if (null !== ($id = self::int($row, 'id')) && null !== ($code = self::string($row, 'code'))) {
                $ids[$code] = $id;
            }
        }
        $missing = array_diff($codes, array_keys($ids));
        if ($missing) {
            throw new ProviderException(Api::PROVIDER, \sprintf('No %s with the code %s.', $model, implode(', ', $missing)), 'not_found');
        }

        return $ids;
    }

    /**
     * The partners lines name: by their ref (the application's key); a key
     * no partner carries, made of digits, is taken as the partner's id.
     *
     * @param list<string> $contacts
     *
     * @return array<string, int>
     */
    protected function partners(array $contacts): array
    {
        $contacts = array_values(array_unique($contacts));
        if (!$contacts) {
            return [];
        }
        $ids = [];
        foreach ($this->api->searchRead('res.partner', [['ref', 'in', $contacts]], ['id', 'ref']) as $row) {
            if (null !== ($id = self::int($row, 'id')) && null !== ($ref = self::string($row, 'ref'))) {
                $ids[$ref] = $id;
            }
        }
        foreach ($contacts as $contact) {
            if (!isset($ids[$contact])) {
                $ids[$contact] = ctype_digit($contact) ? (int) $contact : throw new ProviderException(Api::PROVIDER, \sprintf('No partner whose reference is "%s".', $contact), 'not_found');
            }
        }

        return $ids;
    }

    /**
     * An account.move as read, onto the entry.
     *
     * @param array<string, mixed> $move
     */
    protected static function readMove(JournalEntry $entry, array $move, ?string $journal = null): JournalEntry
    {
        $name = self::string($move, 'name');

        return $entry->with(
            reference: self::string($move, 'id') ?? $entry->reference,
            number: null !== $name && '/' !== $name ? $name : null,
            status: self::STATUSES[self::string($move, 'state') ?? ''] ?? EntryStatus::UNKNOWN,
            piece: self::string($move, 'ref'),
            date: null !== ($date = self::string($move, 'date')) ? new \DateTimeImmutable($date) : null,
            journal: $journal,
            data: $move,
        );
    }

    /**
     * A res.partner as read.
     *
     * @param array<string, mixed> $partner
     */
    protected static function readPartner(array $partner, ?ContactKind $kind = null, ?string $country = null): Contact
    {
        $registry = self::string($partner, 'company_registry');
        $street = self::string($partner, 'street');
        $zip = self::string($partner, 'zip');
        $city = self::string($partner, 'city');

        return new Contact(
            $kind ?? ((self::int($partner, 'supplier_rank') ?? 0) > (self::int($partner, 'customer_rank') ?? 0) ? ContactKind::SUPPLIER : ContactKind::CUSTOMER),
            self::string($partner, 'name') ?? '',
            self::string($partner, 'ref'),
            email: self::string($partner, 'email'),
            siren: null !== $registry ? substr($registry, 0, 9) : null,
            siret: null !== $registry && 14 === \strlen($registry) ? $registry : null,
            vatNumber: self::string($partner, 'vat'),
            address: null !== $street && null !== $zip && null !== $city ? new Address($street, $zip, $city, $country ?? 'FR') : null,
            company: true === ($partner['is_company'] ?? null),
            reference: self::string($partner, 'id'),
            data: $partner,
        );
    }

    /** An amount as Odoo reads and writes it: a float of the company's currency. */
    protected static function float(int $minor): float
    {
        return (float) Amount::decimal($minor);
    }

    /** @param array<string, mixed> $row */
    protected static function minor(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return \is_int($value) || \is_float($value) || \is_string($value) ? Amount::minor($value) : 0;
    }

    /** A Many2one read: [id, "display name"], or false. */
    protected static function many2one(mixed $value): ?int
    {
        return \is_array($value) && \is_int($value[0] ?? null) ? $value[0] : (\is_int($value) ? $value : null);
    }

    /** @param array<string, mixed> $row */
    protected static function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : (\is_int($value) ? (string) $value : null);
    }

    /** @param array<string, mixed> $row */
    protected static function int(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return \is_int($value) ? $value : null;
    }
}
