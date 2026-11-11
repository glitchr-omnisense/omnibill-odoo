# omnibill/odoo

Odoo's accounting for [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill) 1.1 and
later: the `journal-entries` and `contacts` contracts (`JournalEntriesInterface`,
`ContactsInterface`), through Odoo's external JSON-2 API.

Every call is `POST <url>/json/2/<model>/<method>` with `Authorization: bearer <api key>`,
`Content-Type: application/json; charset=utf-8`, `User-Agent: omnibill-odoo` and, when set,
`X-Odoo-Database`; the method's arguments by name in a JSON object (`ids`, `vals_list`, `domain`,
`fields`, `context`...), its return value as the answer. JSON-2 is new in Odoo 19.0; `/xmlrpc`,
`/xmlrpc/2` and `/jsonrpc` are deprecated since 19.0 and scheduled for removal in Odoo 22 (fall
2028) and Online 21.1 - this package never calls them.

## Installation

```sh
composer require omnibill/odoo
```

PHP 8.2 or later, `omnisense/omnibill` ^1.1 and `symfony/http-client`. An Odoo 19 or later.

## The sources

Read on 2026-11-10: Odoo 19's "External JSON-2 API" and "External RPC API" pages (and their sources,
`odoo/documentation` 19.0 to master), the server's code (`addons/rpc/controllers/json2.py`,
`odoo/http.py`, the ORM's `create`, `read`, `write`, `search_read`, the `account` models
`account.move`, `account.move.line`, `account.journal`, `account.account`, `account.tax`, and
`res.partner`, `res.partner.bank`, `res.country`).

## Credentials

An **API key** of the user the calls act as: Preferences → Account Security → New API Key. A key
is a user's: the calls have that user's rights and record rules - Odoo advises a dedicated bot user
with the accounting rights it needs. A key lasts three months at most: rotate it. On Odoo Online the
external API is open on the Custom plan only. Keys go by reference (`%env(ODOO_API_KEY)%`).

## Options

| Option | Default | |
|---|---|---|
| `url` | required | the instance's root: `https://mycompany.odoo.com` |
| `api_key` | required | the user's API key |
| `database` | | `X-Odoo-Database`, when the server hosts several databases and its dbfilter does not follow the host |
| `company_id` | | the company the calls act for (`context.allowed_company_ids`): an account's code is the company's |
| `post` | `true` | `false`: entries stay drafts (`action_post` not called) |
| `lang` | | `context.lang` (`fr_FR`) |

## From the contracts to Odoo

| | |
|---|---|
| `postEntry()` | `account.journal/search_read` (the journal by `code`), `account.account/search_read` (the accounts by `code`), `res.partner/search_read` (the lines' contacts by `ref`; a contact no partner carries, made of digits, is taken as the partner's id), `account.move/create` (`move_type: entry`, `journal_id`, `date`, `ref`: the piece, or the key; `narration`: `[omnibill:<key>]` since omnibill 1.2; `line_ids` as `[0, 0, {account_id, partner_id, name, debit, credit}]`), `account.move/action_post` unless `post: false`, `account.move/read` (`name`: the number, `state`) |
| `fetchEntry()` | `account.move/read`, `account.move.line/read` (its `line_ids`), `account.account/read` and `account.journal/read` for the codes |
| `findEntry()` | since omnibill 1.2: `account.move/search_read` on `move_type = entry`, the entry's `date`, `journal_id.code`, and `ref = <key>` or `narration ilike [omnibill:<key>]`; the match checked on the answer (`ilike` is a substring). `account.move`'s `ref` is a Char, its `narration` an Html field (Odoo 19's `addons/account/models/account_move.py`, read 2026-11-11). An entry posted before 1.2 with a piece has its key nowhere: not found |
| `entries()` | `account.move/search_read` on `move_type = entry`, the dates, `journal_id.code`; `date desc, id desc`; the cursor is the offset |
| `saveContact()` | `res.country/search_read` (the country by `code`), `res.partner/create` (`name`, `email`, `vat`, `company_registry`: the SIRET or the SIREN, `is_company`, `ref`: the key, `customer_rank` or `supplier_rank` 1, `street`, `zip`, `city`, `country_id`, the IBAN as `bank_ids`) - `res.partner/write` when it has its reference (no new bank account) |
| `fetchContact()` | `res.partner/read`, `res.country/read` |
| `contacts()` | `res.partner/search_read` on `customer_rank > 0` or `supplier_rank > 0` and `name =ilike <search>%`, by name; the cursor is the offset |

| `state` | `EntryStatus` |
|---|---|
| `draft` | `DRAFT` (number `/` until posted: none) |
| `posted` | `POSTED` |
| `cancel` | `CANCELED` |

Amounts: Odoo reads and writes floats of the company's currency; the package sends
`Amount::decimal()` as a float and reads them back to cents. The entry's `currency` is not sent:
entries are booked in the company's currency.

**VAT.** The VAT lines go as the application gives them, on their accounts (44566, 44571...), with no
`tax_ids`: Odoo's own tax computation ("auto tax mode") is not triggered, so the books' VAT stays the
application's to the cent - but Odoo's tax report tags are not set on them. Sending base lines with
`tax_ids` and letting Odoo compute the VAT is a possible option, not carried.

**Not idempotent.** `account.move` has no field for the application's key: posting twice records
twice. Each call is its own transaction (created, then posted: two commits).

## Capabilities and terms

`contracts: [journal-entries, contacts]`, no e-invoice syntaxes (`supports(Submit::class)` is
false). `capabilities()->terms` (`OdooGatewayFactory::terms()`): Odoo's subscription, the external
API on Online's Custom plan only, free on a self-hosted Community edition; a user's key, its rights,
three months at most; JSON-2 only; a transaction per call.

## Errors

| Answer | Exception |
|---|---|
| 401 (`Invalid apikey`) | `InvalidKeyException` |
| 403 (`AccessError`), 404 (`MissingError`, an unknown model or method), 422 (`UserError`, `ValidationError`: an entry not balanced, a lock date), 409 | `ProviderException`, `providerCode` Odoo's exception name (`odoo.exceptions.UserError`); the traceback (`debug`) is never carried |
| a journal, an account, a partner, a country not found | `ProviderException`, code `not_found` |
| an entry that does not balance | `InvalidConfigException`, before any call |
| a 5xx or a timeout to a call that writes (`create`, `write`, `action_post`...) | `OutcomeUnknownException` (an `UnreachableException`): the move may be there - `findEntry()` before posting again |
| 429, 5xx, no answer to a read (`search_read`, `read`); a host that did not resolve | `UnreachableException` |

## Example in Symfony

```yaml
omnibill:
    gateways:
        accountant:
            factory: odoo
            options:
                url: '%env(ODOO_URL)%'
                api_key: '%env(ODOO_API_KEY)%'
```

```php
public function __construct(private readonly JournalEntriesInterface $accountant) {}
```

## Verified, and not

- Verified: every call above against a `MockHttpClient` that records the requests (path, JSON body,
  headers) and serves answers written from Odoo's documentation and code (`Tests/Fixtures`); that no
  `/xmlrpc` nor `/jsonrpc` is called; PHPStan at level max.
- Not verified: no Odoo instance was used. Whether `allowed_company_ids` is honoured under a bearer
  key, the exact display of `name` before posting, `base_vat`'s check of a VAT number.
- Next step: Odoo is an e-invoicing platform candidate in France; its e-invoices are not carried here.
