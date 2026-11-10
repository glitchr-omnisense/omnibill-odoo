<?php

namespace Omnibill\Odoo;

use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\ProviderException;
use Omnibill\Http\Answer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Odoo's external JSON-2 API (Odoo 19 and later): POST /json/2/<model>/<method>,
 * a user's API key as the bearer, the method's arguments by name in a JSON
 * object, its return value as the answer. XML-RPC and JSON-RPC (/xmlrpc,
 * /jsonrpc) are deprecated since 19.0 and removed in Odoo 22: this client
 * never calls them. Each call is a transaction of its own.
 */
final class Api
{
    public const PROVIDER = 'odoo';

    /**
     * @param string      $url       the instance's root: https://mycompany.odoo.com
     * @param string|null $database  X-Odoo-Database, when the server hosts several and its dbfilter does not follow the host
     * @param int|null    $companyId the company the calls act for (context allowed_company_ids): an account's code is per company
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        public readonly string $url,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly ?string $database = null,
        private readonly ?int $companyId = null,
        private readonly ?string $lang = null,
    ) {
    }

    /**
     * A model's method, its arguments by name: ids, vals_list, domain, fields...
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $model, string $method, array $arguments = []): mixed
    {
        $context = array_filter(['lang' => $this->lang, 'allowed_company_ids' => null !== $this->companyId ? [$this->companyId] : null], static fn ($v) => null !== $v);
        if ($context) {
            $arguments['context'] = $context + (\is_array($arguments['context'] ?? null) ? $arguments['context'] : []);
        }
        $headers = ['Authorization' => 'bearer '.$this->apiKey, 'Content-Type' => 'application/json; charset=utf-8', 'User-Agent' => 'omnibill-odoo'];
        if (null !== $this->database) {
            $headers['X-Odoo-Database'] = $this->database;
        }
        $path = '/json/2/'.$model.'/'.$method;
        $answer = Answer::send($this->http, self::PROVIDER, 'POST', $this->url.$path, ['headers' => $headers, 'body' => json_encode((object) $arguments, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]);
        $data = json_decode($answer->body, true);
        if (401 === $answer->status) {
            throw new InvalidKeyException(self::PROVIDER, \sprintf('The API key was refused (HTTP 401): %s', self::message($data, $answer)), '401');
        }
        if ($answer->status >= 400) {
            $name = \is_array($data) && \is_string($data['name'] ?? null) ? $data['name'] : (string) $answer->status;

            throw new ProviderException(self::PROVIDER, \sprintf('%s: HTTP %d, %s', $path, $answer->status, self::message($data, $answer)), $name);
        }
        if (null === $data && 'null' !== trim($answer->body)) {
            throw new ProviderException(self::PROVIDER, \sprintf('%s: HTTP %d, not JSON: %s', $path, $answer->status, mb_substr(trim($answer->body), 0, 200)));
        }

        return $data;
    }

    /**
     * search_read: the records a domain finds, as lists of fields.
     *
     * @param list<mixed>  $domain
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields, ?int $limit = null, int $offset = 0, ?string $order = null): array
    {
        return self::records($this->call($model, 'search_read', array_filter(['domain' => $domain, 'fields' => $fields, 'limit' => $limit, 'offset' => $offset ?: null, 'order' => $order], static fn ($v) => null !== $v)));
    }

    /**
     * read: records by id.
     *
     * @param list<int>    $ids
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    public function read(string $model, array $ids, array $fields): array
    {
        return self::records($this->call($model, 'read', ['ids' => $ids, 'fields' => $fields]));
    }

    /**
     * create: one record, its id back.
     *
     * @param array<string, mixed> $values
     */
    public function create(string $model, array $values): int
    {
        $ids = $this->call($model, 'create', ['vals_list' => [$values]]);

        return \is_array($ids) && \is_int($ids[0] ?? null) ? $ids[0] : throw new ProviderException(self::PROVIDER, \sprintf('%s/create gave no id.', $model));
    }

    /** @return list<array<string, mixed>> */
    private static function records(mixed $data): array
    {
        $records = [];
        foreach (\is_array($data) ? $data : [] as $record) {
            if (\is_array($record)) {
                $row = [];
                foreach ($record as $key => $value) {
                    $row[(string) $key] = $value;
                }
                $records[] = $row;
            }
        }

        return $records;
    }

    private static function message(mixed $data, Answer $answer): string
    {
        return \is_array($data) && \is_string($data['message'] ?? null) ? $data['message'] : (mb_substr(trim($answer->body), 0, 200) ?: 'no detail');
    }
}
