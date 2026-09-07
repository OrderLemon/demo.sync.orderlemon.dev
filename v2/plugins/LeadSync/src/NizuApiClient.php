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
    public function createLead(array $fields): ?string
    {
        $response = $this->request('POST', [
            'module' => 'clients',
            'data' => $fields,
        ]);

        $this->assertNotFailed($response, 'lead creation');

        return $this->createdClientId($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload): array
    {
        $baseUrl = (string) $this->config->secret('nizu.base_url');
        $token = (string) $this->config->secret('nizu.token', '');
        $timeout = max(1, (int) $this->config->secret('nizu.timeout', 10));
        $maxRetries = max(1, (int) $this->config->secret('nizu.max_retries', 3));

        if ($token === '') {
            throw new NizuApiException('NIZU token is missing from configuration.');
        }

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $redactedHeaders = array_map(
            static fn(string $header) => str_starts_with($header, 'Authorization:')
                ? 'Authorization: Bearer ***'
                : $header,
            $headers,
        );

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
                    CURLOPT_HTTPHEADER => $headers,
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

            usleep($attempt * 250_000);
        }

        throw new NizuApiException('NIZU request exhausted its retries.');
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseContainsLead(array $response): bool
    {
        $this->assertNotFailed($response, 'lead lookup');

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

    /**
     * @param array<string, mixed> $response
     */
    private function assertNotFailed(array $response, string $action): void
    {
        if (!isset($response['success']) || $response['success'] !== false) {
            return;
        }

        $reason = $this->responseErrorMessage($response);
        $diagnostic = $reason === null ? $this->responseDiagnostic($response) : null;

        throw new NizuApiException(
            $reason !== null
                ? "NIZU rejected the {$action}: {$reason}"
                : "NIZU rejected the {$action}" . $diagnostic,
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseErrorMessage(array $response): ?string
    {
        foreach (['message', 'error', 'detail'] as $key) {
            if (is_string($response[$key] ?? null) && $response[$key] !== '') {
                return $response[$key];
            }
        }

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        foreach (['message', 'error', 'detail'] as $key) {
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function createdClientId(array $response): ?string
    {
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            if (is_scalar($data['id'] ?? null)) {
                return (string) $data['id'];
            }

            $content = $data['content'] ?? null;
            if (is_array($content) && isset($content[0]) && is_array($content[0])) {
                return is_scalar($content[0]['id'] ?? null)
                    ? (string) $content[0]['id']
                    : null;
            }
        }

        return is_scalar($response['id'] ?? null) ? (string) $response['id'] : null;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function responseDiagnostic(array $response): string
    {
        $parts = [];

        foreach ($response as $key => $value) {
            if (in_array($key, ['token', 'password', 'secret'], true)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $parts[] = $key . '=' . (string) $value;
                continue;
            }

            if (is_array($value)) {
                $parts[] = $key . '_keys=' . implode(',', array_keys($value));
            }
        }

        return $parts === [] ? '.' : ' (' . implode('; ', $parts) . ').';
    }
}