<?php

declare(strict_types=1);

namespace Plugins\LeadSync;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;

final class NizuApiClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function leadExists(string $phone): bool
    {
        $method = strtoupper((string) $this->config->secret('nizu.lookup_method', 'POST'));
        $response = $this->request($method, [
            'module' => 'clients',
            'data' => [
                'filter' => [
                    [
                        'is_lead' => 1,
                        'phone' => $phone,
                    ],
                ],
            ],
        ]);

        return $this->responseContainsLead($response);
    }

    /**
     * @param list<array{field: string, value: int|string|null}> $fields
     */
    public function createLead(array $fields): void
    {
        $this->request('POST', [
            'module' => 'users',
            'data' => $fields,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload): array
    {
        $baseUrl = rtrim((string) $this->config->secret('nizu.base_url', 'https://api.nizu.io/v2'), '/');
        $token = (string) $this->config->secret('nizu.token', '');
        $timeout = max(1, (int) $this->config->secret('nizu.timeout', 10));
        $maxRetries = max(1, (int) $this->config->secret('nizu.max_retries', 3));

        if ($token === '') {
            throw new NizuApiException('NIZU token is missing from configuration.');
        }

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $handle = curl_init($baseUrl);
            if ($handle === false) {
                throw new NizuApiException('Unable to initialize the NIZU HTTP client.');
            }

            try {
                curl_setopt_array($handle, [
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_POSTFIELDS => $encodedPayload,
                ]);

                $body = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $error = curl_error($handle);
            } finally {
                curl_close($handle);
            }

            if ($body !== false && $status >= 200 && $status < 300) {
                try {
                    /** @var array<string, mixed> $decoded */
                    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    return $decoded;
                } catch (\JsonException $exception) {
                    throw new NizuApiException('NIZU returned invalid JSON.', 0, $exception);
                }
            }

            $retryable = $body === false || $status === 408 || $status === 429 || $status >= 500;
            $message = $body === false
                ? 'NIZU request failed: ' . $error
                : sprintf('NIZU returned HTTP %d.', $status);

            if (!$retryable || $attempt === $maxRetries) {
                throw new NizuApiException($message);
            }

            $this->logger->warning('Retrying NIZU request', [
                'method' => $method,
                'status' => $status,
                'attempt' => $attempt,
            ]);
            usleep($attempt * 250_000);
        }

        throw new NizuApiException('NIZU request exhausted its retries.');
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseContainsLead(array $response): bool
    {
        if (isset($response['success']) && $response['success'] === false) {
            throw new NizuApiException('NIZU rejected the lead lookup.');
        }

        if (
            is_array($response['data'] ?? null)
            && array_key_exists('token', $response['data'])
            && array_key_exists('account_id', $response['data'])
        ) {
            throw new NizuApiException(
                'NIZU returned an authentication response instead of lead data.',
            );
        }

        $data = $response['data'] ?? null;
        if (is_array($data) && array_key_exists('content', $data)) {
            if (!is_array($data['content'])) {
                throw new NizuApiException(
                    'NIZU lead lookup returned an invalid content collection.',
                );
            }

            return $data['content'] !== [];
        }

        throw new NizuApiException(
            'NIZU lead lookup returned no recognizable result collection.',
        );
    }
}