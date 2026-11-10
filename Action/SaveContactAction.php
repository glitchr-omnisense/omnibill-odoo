<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Model\ContactKind;
use Omnibill\Request\Accounting\SaveContact;
use Omnibill\Request\Request;

/**
 * res.partner/create - or write when the contact has its reference: name,
 * e-mail, VAT number, company_registry (the SIRET, or the SIREN), a company
 * or a person, ref (the application's key), customer_rank or supplier_rank,
 * the address (the country found by its code), the IBAN as a bank account
 * on creation.
 */
final class SaveContactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof SaveContact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SaveContact);
        $contact = $request->contact;
        $values = array_filter([
            'name' => $contact->name,
            'email' => $contact->email,
            'vat' => $contact->vatNumber,
            'company_registry' => $contact->siret ?? $contact->siren,
            'is_company' => $contact->company,
            'ref' => $contact->key,
            ContactKind::SUPPLIER === $contact->kind ? 'supplier_rank' : 'customer_rank' => 1,
            'street' => $contact->address?->line,
            'zip' => $contact->address?->postalCode,
            'city' => $contact->address?->city,
            'country_id' => null !== $contact->address ? $this->ids('res.country', [$contact->address->country])[$contact->address->country] : null,
        ], static fn ($v) => null !== $v);
        if (null !== $contact->reference) {
            $this->api->call('res.partner', 'write', ['ids' => [(int) $contact->reference], 'vals' => $values]);
            $request->setResult($contact);

            return;
        }
        if (null !== $contact->iban) {
            $values['bank_ids'] = [[0, 0, ['acc_number' => $contact->iban]]];
        }
        $request->setResult($contact->with(reference: (string) $this->api->create('res.partner', $values)));
    }
}
