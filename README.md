# omnibill/odoo

**Odoo**'s accounting for [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill): journal
entries posted and read back, customers and suppliers - the family's `journal-entries` and
`contacts` contracts - through Odoo's external **JSON-2** API (Odoo 19 and later, Online or
self-hosted) with a user's API key. XML-RPC and JSON-RPC, deprecated and removed in Odoo 22, are
never called.

```php
use Omnibill\Odoo\OdooGatewayFactory;

$books = (new OdooGatewayFactory($httpClient))->create(['url' => 'https://mycompany.odoo.com', 'api_key' => getenv('ODOO_API_KEY')]);

$entry = $books->postEntry($journalEntry);   // account.move created, posted, read back: its number
$books->fetchEntry($entry);                  // its lines, on their accounts' codes
$books->saveContact($supplier);              // res.partner, supplier_rank 1
$books->findContact($supplier);              // after an OutcomeUnknownException: the partner whose ref is its key, or null
```

Written from Odoo 19's external API documentation and its server's code, read on 2026-11-10.
**Not verified in real: no instance.**

[Documentation](docs/index.md): the calls, the options, how entries and contacts map, what is not
carried, Odoo's terms.

## Usage policy

`policy.json`, read on 2026-11-10 in [Odoo's external API reference](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html):
no rate limit published (declared unknown); on Odoo Online the external API needs the Custom plan
(commercial use `contract`); a key lasts three months at most.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Odoo S.A. It reaches an Odoo instance on behalf of whoever runs it. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Odoo's terms (the external
API is open on Odoo Online's Custom plan), for the key it uses and the rights of the user behind it,
and for any use made of what it returns. The authors publish it free of charge, make no commercial
use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT.
