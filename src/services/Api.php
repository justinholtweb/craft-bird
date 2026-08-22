<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use GuzzleHttp\Client;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\Plugin;
use Psr\Http\Message\ResponseInterface;

/**
 * The Moneybird REST API.
 *
 * One `request()` for every call, so the token, the logging, the error flattening and the
 * rate-limit handling exist once. Moneybird allows 150 requests per 5 minutes per token (50 for
 * `/reports/`), answers 429 with a `Retry-After`, and expects `.json` on the end of every path.
 *
 * @see https://developer.moneybird.com/introduction
 */
class Api extends Component
{
    public const BASE = 'https://moneybird.com/api/v2/';

    /**
     * How many times a retryable failure (429, 5xx, connection error) is tried again inside one
     * call. Anything beyond that is the queue's job, not this method's.
     */
    public const MAX_RETRIES = 2;

    /**
     * Pages `getAll()` will walk before giving up. 100 per page is Moneybird's maximum, so this
     * is 20,000 records — far past anything a settings screen should be fetching.
     */
    public const MAX_PAGES = 200;

    /**
     * Administrations the token can see. The only endpoint that is not scoped to one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdministrations(): array
    {
        return $this->request('GET', 'administrations.json', [], false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTaxRates(): array
    {
        return $this->getAll('tax_rates.json', ['filter' => 'tax_rate_type:sales_invoice']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLedgerAccounts(): array
    {
        // Moneybird explicitly does not paginate this one.
        return $this->request('GET', 'ledger_accounts.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFinancialAccounts(): array
    {
        return $this->getAll('financial_accounts.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWorkflows(): array
    {
        return $this->getAll('workflows.json');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDocumentStyles(): array
    {
        return $this->getAll('document_styles.json');
    }

    // Contacts
    // =========================================================================

    /**
     * @return array<string, mixed>|null
     */
    public function findContactByCustomerId(string $customerId): ?array
    {
        try {
            return $this->request('GET', 'contacts/customer_id/' . rawurlencode($customerId) . '.json');
        } catch (ApiException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Free-text contact search. Moneybird looks across name, email, phone, customer id, tax
     * number and address, so a bare email address is a perfectly good query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchContacts(string $query): array
    {
        return $this->request('GET', 'contacts.json', ['query' => ['query' => $query, 'per_page' => 25]]);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createContact(array $attributes): array
    {
        return $this->request('POST', 'contacts.json', ['json' => ['contact' => $attributes]]);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function updateContact(string $contactId, array $attributes): array
    {
        return $this->request('PATCH', "contacts/$contactId.json", ['json' => ['contact' => $attributes]]);
    }

    // Sales invoices
    // =========================================================================

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createSalesInvoice(array $attributes): array
    {
        return $this->request('POST', 'sales_invoices.json', ['json' => ['sales_invoice' => $attributes]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSalesInvoice(string $id): array
    {
        return $this->request('GET', "sales_invoices/$id.json");
    }

    /**
     * The recovery path after a crash between "Moneybird created the invoice" and "Bird wrote the
     * row": the reference is the order number, so the invoice can always be found again.
     *
     * @return array<string, mixed>|null
     */
    public function findSalesInvoiceByReference(string $reference): ?array
    {
        try {
            return $this->request('GET', 'sales_invoices/find_by_reference/' . rawurlencode($reference) . '.json');
        } catch (ApiException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $sending
     * @return array<string, mixed>
     */
    public function sendSalesInvoice(string $id, array $sending): array
    {
        return $this->request('PATCH', "sales_invoices/$id/send_invoice.json", [
            'json' => ['sales_invoice_sending' => $sending],
        ]);
    }

    /**
     * `POST …/payments` rather than the older `PATCH …/register_payment`, which Moneybird has
     * deprecated.
     *
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public function createSalesInvoicePayment(string $id, array $payment): array
    {
        return $this->request('POST', "sales_invoices/$id/payments.json", ['json' => ['payment' => $payment]]);
    }

    // External sales invoices
    // =========================================================================

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createExternalSalesInvoice(array $attributes): array
    {
        return $this->request('POST', 'external_sales_invoices.json', [
            'json' => ['external_sales_invoice' => $attributes],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExternalSalesInvoice(string $id): array
    {
        return $this->request('GET', "external_sales_invoices/$id.json");
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public function createExternalSalesInvoicePayment(string $id, array $payment): array
    {
        return $this->request('POST', "external_sales_invoices/$id/payments.json", [
            'json' => ['payment' => $payment],
        ]);
    }

    // Webhooks
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWebhooks(): array
    {
        return $this->request('GET', 'webhooks.json');
    }

    /**
     * Moneybird returns the signing secret exactly once, in the create response. Losing it means
     * deleting the webhook and making a new one.
     *
     * @param string[] $events
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $events): array
    {
        return $this->request('POST', 'webhooks.json', [
            'json' => ['url' => $url, 'enabled_events' => $events],
        ]);
    }

    public function deleteWebhook(string $id): void
    {
        $this->request('DELETE', "webhooks/$id.json");
    }

    // Connection
    // =========================================================================

    /**
     * Whether the token and administration work, for the settings screen.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->getParsedApiToken() === '') {
            return ['success' => false, 'message' => Craft::t('bird', 'No Moneybird API token is configured.')];
        }

        try {
            $administrations = $this->getAdministrations();
        } catch (ApiException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $administrationId = $settings->getParsedAdministrationId();

        if ($administrationId === '') {
            return [
                'success' => false,
                'message' => Craft::t('bird', 'The token works, but no administration is selected. {count} available.', [
                    'count' => count($administrations),
                ]),
            ];
        }

        foreach ($administrations as $administration) {
            if ((string)($administration['id'] ?? '') !== $administrationId) {
                continue;
            }

            return [
                'success' => true,
                'message' => Craft::t('bird', 'Connected to {name} ({currency}, {country}).', [
                    'name' => $administration['name'] ?? $administrationId,
                    'currency' => $administration['currency'] ?? '—',
                    'country' => $administration['country'] ?? '—',
                ]),
            ];
        }

        return [
            'success' => false,
            'message' => Craft::t('bird', 'The token works, but it has no access to administration {id}.', [
                'id' => $administrationId,
            ]),
        ];
    }

    // The one request
    // =========================================================================

    /**
     * Every call goes through here.
     *
     * @param array<string, mixed> $options Guzzle options (`json`, `query`).
     * @return array<mixed> The decoded body, or an empty array for a 204.
     * @throws ApiException
     */
    public function request(string $method, string $path, array $options = [], bool $administrationScoped = true): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $token = $settings->getParsedApiToken();

        if ($token === '') {
            throw new ApiException(Craft::t('bird', 'No Moneybird API token is configured.'), null, null, $path);
        }

        $uri = self::BASE;

        if ($administrationScoped) {
            $administrationId = $settings->getParsedAdministrationId();

            if ($administrationId === '') {
                throw new ApiException(Craft::t('bird', 'No Moneybird administration is configured.'), null, null, $path);
            }

            $uri .= $administrationId . '/';
        }

        $client = Craft::createGuzzleClient([
            'base_uri' => $uri,
            'timeout' => 30,
            // Statuses are read, not thrown: Moneybird's 422 body is the useful part, and Guzzle's
            // exception message throws it away in favour of "resulted in a `422` response".
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'Bird for Craft Commerce',
            ],
        ]);

        $attempt = 0;

        while (true) {
            $started = microtime(true);

            try {
                $response = $client->request($method, $path, $options);
            } catch (\Throwable $e) {
                // A connection error: no status, no body, nothing to flatten.
                if ($attempt < self::MAX_RETRIES) {
                    $attempt++;
                    $this->pause($this->backoffSeconds($attempt));
                    continue;
                }

                $this->log($method, $path, null, $started, LogEntry::LEVEL_ERROR, $e->getMessage(), $options, null);

                throw new ApiException($e->getMessage(), null, null, $path);
            }

            $status = $response->getStatusCode();
            $body = (string)$response->getBody();

            if ($status >= 200 && $status < 300) {
                $this->log($method, $path, $status, $started, LogEntry::LEVEL_INFO, null, $options, $body);

                $decoded = $body !== '' ? Json::decodeIfJson($body) : [];

                return is_array($decoded) ? $decoded : [];
            }

            $retryable = $status === 429 || $status >= 500;

            if ($retryable && $attempt < self::MAX_RETRIES) {
                $attempt++;
                $this->pause($this->retryAfterSeconds($response) ?? $this->backoffSeconds($attempt));
                continue;
            }

            $message = $this->describe($status, $body);
            $this->log($method, $path, $status, $started, LogEntry::LEVEL_ERROR, $message, $options, $body);

            throw new ApiException($message, $status, $body, $path);
        }
    }

    /**
     * A `Link: …; rel="next"` walk. Moneybird pages at 50 by default and 100 at most.
     *
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function getAll(string $path, array $query = []): array
    {
        $query['per_page'] = 100;
        $page = 1;
        $out = [];

        while ($page <= self::MAX_PAGES) {
            $query['page'] = $page;
            $rows = $this->request('GET', $path, ['query' => $query]);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $out[] = $row;
            }

            // A short page is the last page. Reading the Link header would be more literal, but
            // Moneybird omits it on single-page responses and this cannot be fooled by that.
            if (count($rows) < 100) {
                break;
            }

            $page++;
        }

        return $out;
    }

    // Private
    // =========================================================================

    /**
     * A message a bookkeeper can act on.
     *
     * Moneybird answers a 422 with `{"error": {"contact_id": ["can't be blank"]}}` — a field map,
     * not a sentence — so it gets flattened into one.
     */
    private function describe(int $status, string $body): string
    {
        $decoded = $body !== '' ? Json::decodeIfJson($body) : null;

        if (is_array($decoded)) {
            $error = $decoded['error'] ?? $decoded['errors'] ?? null;

            if (is_string($error) && $error !== '') {
                return "$status: $error";
            }

            if (is_array($error)) {
                $parts = [];

                foreach ($error as $field => $messages) {
                    $messages = is_array($messages) ? $messages : [$messages];
                    $messages = array_map(static fn($m) => is_scalar($m) ? (string)$m : Json::encode($m), $messages);

                    $parts[] = is_string($field)
                        ? $field . ' ' . implode(', ', $messages)
                        : implode(', ', $messages);
                }

                if ($parts !== []) {
                    return "$status: " . implode('; ', $parts);
                }
            }
        }

        return match ($status) {
            401 => Craft::t('bird', '401: Moneybird rejected the API token.'),
            403 => Craft::t('bird', '403: the token is missing the scope this call needs.'),
            404 => Craft::t('bird', '404: Moneybird has no such record.'),
            429 => Craft::t('bird', '429: Moneybird’s rate limit was hit (150 requests per 5 minutes).'),
            default => $status . ($body !== '' ? ': ' . mb_substr($body, 0, 200) : ''),
        };
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        if ($header === '' || !is_numeric($header)) {
            return null;
        }

        return max(1, (int)$header);
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(8, 2 ** $attempt);
    }

    /**
     * Waiting out a rate limit is fine in a queue worker and unacceptable in a web request: an
     * order-complete handler that sleeps for a minute is an order the customer never sees confirm.
     */
    private function pause(int $seconds): void
    {
        $isConsole = Craft::$app->getRequest() instanceof \craft\console\Request;
        $cap = $isConsole ? 60 : 3;

        sleep(max(0, min($seconds, $cap)));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function log(string $method, string $path, ?int $status, float $started, string $level, ?string $message, array $options, ?string $body): void
    {
        $request = null;

        if (isset($options['json'])) {
            $request = Json::encode($options['json']);
        } elseif (isset($options['query'])) {
            $request = Json::encode($options['query']);
        }

        Plugin::getInstance()->getLog()->write('api', [
            'level' => $level,
            'statusCode' => $status,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'summary' => strtoupper($method) . ' ' . $path,
            'message' => $message,
            'request' => $request,
            'response' => $body,
        ]);
    }
}
