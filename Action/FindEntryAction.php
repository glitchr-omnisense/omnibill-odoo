<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Request\Accounting\FindEntry;
use Omnibill\Request\Request;

/**
 * account.move/search_read on the entries (move_type entry) of the entry's
 * date, in its journal (journal_id.code), whose ref is the key or whose
 * narration carries it ("[omnibill:<key>]", written by postEntry() since
 * omnibill 1.2); the match checked on what comes back - ilike is a
 * substring. Null when none. An entry posted before 1.2 with a piece has
 * its key nowhere: it is not found.
 */
final class FindEntryAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FindEntry;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FindEntry);
        $entry = $request->entry;
        $key = $entry->key ?? throw new InvalidConfigException(\sprintf('The entry "%s" has no key to be found by.', $entry->label));
        $marker = self::marker($key);
        $moves = $this->api->searchRead('account.move', [
            ['move_type', '=', 'entry'],
            ['date', '=', $entry->date->format('Y-m-d')],
            ['journal_id.code', '=', $entry->journal],
            '|', ['ref', '=', $key], ['narration', 'ilike', $marker],
        ], ['name', 'state', 'date', 'ref', 'journal_id', 'narration'], 10, 0, 'id asc');
        foreach ($moves as $move) {
            if ($key === self::string($move, 'ref') || str_contains(self::string($move, 'narration') ?? '', $marker)) {
                $request->setResult(self::readMove($entry, $move, $entry->journal));

                return;
            }
        }
        $request->setResult(null);
    }
}
