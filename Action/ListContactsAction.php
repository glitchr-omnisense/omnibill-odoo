<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Model\ContactKind;
use Omnibill\Model\ContactList;
use Omnibill\Request\Accounting\ListContacts;
use Omnibill\Request\Request;

/** res.partner/search_read: customer_rank or supplier_rank above 0, the name starting with the search, by name; the cursor is the offset. */
final class ListContactsAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof ListContacts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof ListContacts);
        $domain = [[ContactKind::SUPPLIER === $request->kind ? 'supplier_rank' : 'customer_rank', '>', 0]];
        if (null !== $request->search && '' !== $request->search) {
            $domain[] = ['name', '=ilike', addcslashes($request->search, '%_').'%'];
        }
        $offset = null !== $request->cursor && ctype_digit($request->cursor) ? (int) $request->cursor : 0;
        $rows = $this->api->searchRead('res.partner', $domain, ['id', ...self::PARTNER_FIELDS], $request->limit, $offset, 'name');
        $request->setResult(new ContactList(
            array_map(static fn (array $row) => self::readPartner($row, $request->kind), $rows),
            \count($rows) === $request->limit ? (string) ($offset + $request->limit) : null,
        ));
    }
}
