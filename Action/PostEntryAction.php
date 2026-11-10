<?php

namespace Omnibill\Odoo\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Odoo\Api;
use Omnibill\Request\Accounting\PostEntry;
use Omnibill\Request\Request;

/**
 * account.move/create (move_type entry, the journal and accounts found by
 * their codes, the partners by their ref), then action_post unless the
 * gateway is told not to, then read: its number and state. The VAT lines
 * go as the application gives them, on their accounts - Odoo's own tax
 * computation is not triggered (no tax_ids): the books' VAT stays the
 * application's, to the cent.
 */
final class PostEntryAction extends AbstractAction
{
    public function __construct(private readonly bool $post = true)
    {
        parent::__construct();
    }

    public function supports(Request $request): bool
    {
        return $request instanceof PostEntry;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof PostEntry);
        $entry = $request->entry;
        if (!$entry->isBalanced()) {
            throw new InvalidConfigException(\sprintf('The entry "%s" is not balanced: %d debit, %d credit.', $entry->label, $entry->totalDebit(), $entry->totalCredit()));
        }
        $journal = $this->ids('account.journal', [$entry->journal])[$entry->journal];
        $accounts = $this->ids('account.account', array_map(static fn ($l) => $l->account, $entry->lines));
        $partners = $this->partners(array_values(array_filter(array_map(static fn ($l) => $l->contact, $entry->lines), static fn ($c) => null !== $c)));

        $lines = [];
        foreach ($entry->lines as $line) {
            $lines[] = [0, 0, array_filter([
                'account_id' => $accounts[$line->account],
                'partner_id' => null !== $line->contact ? $partners[$line->contact] : null,
                'name' => $line->label ?? $entry->label,
                'debit' => self::float($line->debit),
                'credit' => self::float($line->credit),
            ], static fn ($v) => null !== $v)];
        }
        $id = $this->api->create('account.move', array_filter([
            'move_type' => 'entry',
            'journal_id' => $journal,
            'date' => $entry->date->format('Y-m-d'),
            'ref' => $entry->piece ?? $entry->key,
            'line_ids' => $lines,
        ], static fn ($v) => null !== $v));
        if ($this->post) {
            $this->api->call('account.move', 'action_post', ['ids' => [$id]]);
        }
        $move = $this->api->read('account.move', [$id], ['name', 'state', 'date', 'ref', 'journal_id'])[0] ?? ['id' => $id];
        $request->setResult(self::readMove($entry, $move + ['id' => $id]));
    }
}
