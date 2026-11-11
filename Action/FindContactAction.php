<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\ContactKind;
use Omnibill\Request\Accounting\FindContact;
use Omnibill\Request\Request;

/**
 * res.partner/search_read on ref = the key saveContact() wrote (res.partner's
 * ref, a Char of Odoo's base module), by id; of those found, the first that
 * is of the contact's kind (supplier_rank or customer_rank above 0), else
 * the first - ref is not unique in Odoo, the earliest is the one created
 * first. Then res.country/read for its country's code, as fetchContact().
 * Null when none; an archived partner is not searched (Odoo's active
 * filter). A contact without a key cannot be found.
 */
final class FindContactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FindContact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FindContact);
        $contact = $request->contact;
        $key = $contact->key ?? throw new InvalidConfigException(\sprintf('The contact "%s" has no key to be found by.', $contact->name));
        $rank = ContactKind::SUPPLIER === $contact->kind ? 'supplier_rank' : 'customer_rank';
        $partners = array_values(array_filter(
            $this->api->searchRead('res.partner', [['ref', '=', $key]], ['id', ...self::PARTNER_FIELDS], 10, 0, 'id asc'),
            static fn (array $row) => $key === self::string($row, 'ref'),
        ));
        if (!$partners) {
            $request->setResult(null);

            return;
        }
        $kind = array_values(array_filter($partners, static fn (array $row) => (self::int($row, $rank) ?? 0) > 0));
        $partner = $kind[0] ?? $partners[0];
        $country = null;
        if (null !== ($countryId = self::many2one($partner['country_id'] ?? null))) {
            $country = self::string($this->api->read('res.country', [$countryId], ['code'])[0] ?? [], 'code');
        }
        $request->setResult(self::readPartner($partner, $contact->kind, $country));
    }
}
