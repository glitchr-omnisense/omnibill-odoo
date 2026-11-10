<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Exception\ProviderException;
use Omnibill\Odoo\Api;
use Omnibill\Request\Accounting\FetchContact;
use Omnibill\Request\Request;

/** res.partner/read, and its country's code: the contact as Odoo has it. */
final class FetchContactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FetchContact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchContact);
        $id = (int) $request->contact->reference();
        $partner = $this->api->read('res.partner', [$id], self::PARTNER_FIELDS)[0] ?? throw new ProviderException(Api::PROVIDER, \sprintf('No res.partner %d.', $id), 'not_found');
        $country = null;
        if (null !== ($countryId = self::many2one($partner['country_id'] ?? null))) {
            $country = self::string($this->api->read('res.country', [$countryId], ['code'])[0] ?? [], 'code');
        }
        $request->setResult(self::readPartner($partner + ['id' => $id], $request->contact->kind, $country));
    }
}
