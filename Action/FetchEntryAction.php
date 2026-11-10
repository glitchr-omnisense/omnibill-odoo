<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Exception\ProviderException;
use Omnibill\Model\EntryLine;
use Omnibill\Odoo\Api;
use Omnibill\Request\Accounting\FetchEntry;
use Omnibill\Request\Request;

/**
 * account.move/read, its lines (account.move.line/read), their accounts'
 * and the journal's codes: the entry as Odoo has it. A line's contact is
 * the partner's id.
 */
final class FetchEntryAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FetchEntry;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchEntry);
        $id = (int) $request->entry->reference();
        $move = $this->api->read('account.move', [$id], ['name', 'state', 'date', 'ref', 'journal_id', 'line_ids'])[0] ?? throw new ProviderException(Api::PROVIDER, \sprintf('No account.move %d.', $id), 'not_found');
        $lineIds = array_values(array_filter(\is_array($move['line_ids'] ?? null) ? $move['line_ids'] : [], 'is_int'));
        $rows = $lineIds ? $this->api->read('account.move.line', $lineIds, ['account_id', 'partner_id', 'name', 'debit', 'credit']) : [];

        $accountIds = array_values(array_unique(array_filter(array_map(static fn ($r) => self::many2one($r['account_id'] ?? null), $rows))));
        $codes = [];
        foreach ($accountIds ? $this->api->read('account.account', $accountIds, ['code']) : [] as $account) {
            $codes[self::int($account, 'id') ?? 0] = self::string($account, 'code') ?? '';
        }
        $journal = null;
        if (null !== ($journalId = self::many2one($move['journal_id'] ?? null))) {
            $journal = self::string($this->api->read('account.journal', [$journalId], ['code'])[0] ?? [], 'code');
        }

        $lines = [];
        foreach ($rows as $row) {
            $partner = self::many2one($row['partner_id'] ?? null);
            $lines[] = new EntryLine($codes[self::many2one($row['account_id'] ?? null) ?? 0] ?? '', self::minor($row, 'debit'), self::minor($row, 'credit'), self::string($row, 'name'), null !== $partner ? (string) $partner : null);
        }
        $request->setResult(self::readMove($request->entry, $move + ['id' => $id], $journal)->with(lines: $lines));
    }
}
