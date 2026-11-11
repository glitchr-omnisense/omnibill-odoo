<?php

namespace Omnibill\Odoo;

use Omnibill\Config;
use Omnibill\GatewayFactory;
use Omnibill\Model\Capabilities;
use Omnibill\Model\Terms;
use Omnibill\Odoo\Action\FetchContactAction;
use Omnibill\Odoo\Action\FetchEntryAction;
use Omnibill\Odoo\Action\FindContactAction;
use Omnibill\Odoo\Action\FindEntryAction;
use Omnibill\Odoo\Action\ListContactsAction;
use Omnibill\Odoo\Action\ListEntriesAction;
use Omnibill\Odoo\Action\PostEntryAction;
use Omnibill\Odoo\Action\SaveContactAction;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Odoo's accounting (Odoo 19 and later, Online or self-hosted): journal
 * entries posted and read back, customers and suppliers, through the
 * external JSON-2 API with a user's API key.
 *
 *   options:
 *     url: 'https://mycompany.odoo.com'          # required: the instance's root
 *     api_key: '%env(ODOO_API_KEY)%'             # required: an API key of the user the calls act as (a dedicated bot user)
 *     database: ~                                # X-Odoo-Database, when the server hosts several databases
 *     company_id: ~                              # the company the calls act for, in a multi-company database
 *     post: true                                 # false: entries stay drafts in Odoo
 *     lang: ~                                    # the context's lang ("fr_FR")
 */
final class OdooGatewayFactory extends GatewayFactory
{
    private static ?UsagePolicy $policy = null;

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    public static function terms(): Terms
    {
        return new Terms(
            partner: false,
            commercial: true,
            price: 'Odoo\'s subscription; the external API is open on Odoo Online\'s Custom plan only; free on a self-hosted Community edition',
            mustNotKeep: ['the API key in the code or the repository: by reference, rotated'],
            rules: [
                'An API key is a user\'s: the calls have that user\'s rights; Odoo advises a dedicated bot user',
                'A key lasts three months at most: rotate it',
                'JSON-2 (/json/2) since Odoo 19; XML-RPC and JSON-RPC are deprecated and removed in Odoo 22 (fall 2028) and Online 21.1',
                'Each call is its own transaction: an entry created then posted is two commits',
            ],
            source: 'https://www.odoo.com/documentation/19.0/developer/reference/external_api.html',
        );
    }

    /** Its usage policy, as policy.json declares it (read once). */
    public static function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibill.factory_name' => 'odoo',
            'omnibill.factory_title' => 'Odoo',
            'omnibill.required_options' => ['url', 'api_key'],
            'database' => null,
            'company_id' => null,
            'post' => true,
            'lang' => null,
            'omnibill.capabilities' => new Capabilities([], approved: false, publicSector: false, contracts: [Capabilities::JOURNAL_ENTRIES, Capabilities::CONTACTS], terms: self::terms(), policy: self::usagePolicy()),
            'omnibill.api' => fn (Config $c) => new Api(
                $this->http ?? HttpClient::create(),
                rtrim(self::text($c['url']), '/'),
                self::text($c['api_key']),
                '' !== ($database = self::text($c->get('database'))) ? $database : null,
                is_numeric($company = $c->get('company_id')) ? (int) $company : null,
                '' !== ($lang = self::text($c->get('lang'))) ? $lang : null,
                $c->backoff(),
            ),
            'omnibill.action.post_entry' => static fn (Config $c) => new PostEntryAction($c->bool('post')),
            'omnibill.action.fetch_entry' => new FetchEntryAction(),
            'omnibill.action.list_entries' => new ListEntriesAction(),
            'omnibill.action.find_entry' => new FindEntryAction(),
            'omnibill.action.save_contact' => new SaveContactAction(),
            'omnibill.action.fetch_contact' => new FetchContactAction(),
            'omnibill.action.list_contacts' => new ListContactsAction(),
            'omnibill.action.find_contact' => new FindContactAction(),
        ]);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
