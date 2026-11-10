<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Model\EntryList;
use Omnibill\Model\EntryStatus;
use Omnibill\Model\JournalEntry;
use Omnibill\Request\Accounting\ListEntries;
use Omnibill\Request\Request;

/**
 * account.move/search_read on the entries (move_type entry) between the
 * dates, in the journal (journal_id.code), the latest first; the cursor is
 * the offset. Lines left out: fetchEntry() reads them.
 */
final class ListEntriesAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof ListEntries;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof ListEntries);
        $domain = [['move_type', '=', 'entry']];
        if (null !== $request->from) {
            $domain[] = ['date', '>=', $request->from->format('Y-m-d')];
        }
        if (null !== $request->to) {
            $domain[] = ['date', '<=', $request->to->format('Y-m-d')];
        }
        if (null !== $request->journal) {
            $domain[] = ['journal_id.code', '=', $request->journal];
        }
        $offset = null !== $request->cursor && ctype_digit($request->cursor) ? (int) $request->cursor : 0;
        $moves = $this->api->searchRead('account.move', $domain, ['name', 'state', 'date', 'ref', 'journal_id'], $request->limit, $offset, 'date desc, id desc');

        $journalIds = array_values(array_unique(array_filter(array_map(static fn ($m) => self::many2one($m['journal_id'] ?? null), $moves))));
        $codes = [];
        foreach ($journalIds && null === $request->journal ? $this->api->read('account.journal', $journalIds, ['code']) : [] as $journal) {
            $codes[self::int($journal, 'id') ?? 0] = self::string($journal, 'code') ?? '';
        }
        $entries = [];
        foreach ($moves as $move) {
            $journal = $request->journal ?? $codes[self::many2one($move['journal_id'] ?? null) ?? 0] ?? '';
            $entries[] = self::readMove(new JournalEntry($journal, new \DateTimeImmutable(self::string($move, 'date') ?? 'today'), self::string($move, 'ref') ?? self::string($move, 'name') ?? '', status: EntryStatus::UNKNOWN), $move);
        }
        $request->setResult(new EntryList($entries, \count($moves) === $request->limit ? (string) ($offset + $request->limit) : null));
    }
}
