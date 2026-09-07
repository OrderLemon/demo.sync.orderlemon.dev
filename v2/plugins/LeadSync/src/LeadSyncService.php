<?php

declare(strict_types=1);

namespace Plugins\LeadSync;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;

final class LeadSyncService
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientSourceRepository $source,
        private readonly NizuApiClient $nizu,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{shops: int, scanned: int, skipped: int, existing: int, inserted: int, failed: int}
     */
    public function run(): array
    {
        $shopIds = $this->config->secret('sync.shop_ids', []);
        if (!is_array($shopIds)) {
            throw new \InvalidArgumentException('sync.shop_ids must be an array.');
        }

        $summary = [
            'shops' => count($shopIds),
            'scanned' => 0,
            'skipped' => 0,
            'existing' => 0,
            'inserted' => 0,
            'failed' => 0,
        ];

        foreach ($shopIds as $shopId) {
            if (!is_int($shopId)) {
                throw new \InvalidArgumentException('Every configured shop ID must be an integer.');
            }

            foreach ($this->source->customersForShop($shopId) as $customer) {
                $summary['scanned']++;
                $phone = $this->normalizePhone($customer['phonenumber'] ?? null);

                if ($phone === null) {
                    $summary['skipped']++;
                    continue;
                }

                try {
                    if ($this->nizu->leadExists($phone)) {
                        $summary['existing']++;
                        continue;
                    }

                    $this->nizu->createLead($this->buildFields($customer, $phone, $shopId));
                    $summary['inserted']++;
                } catch (NizuApiException $exception) {
                    $summary['failed']++;
                    $this->logger->error('Lead sync record failed', [
                        'shop_id' => $shopId,
                        'phone_suffix' => substr($phone, -4),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }

    /**
     * @param mixed $value
     */
    private function normalizePhone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $phone = preg_replace('/\D+/', '', $value);
        if (!is_string($phone) || strlen($phone) < 8 || strlen($phone) > 15) {
            return null;
        }

        return $phone;
    }

    /**
     * @param array<string, mixed> $customer
     * @return list<array{field: string, value: int|string|null}>
     */
    private function buildFields(array $customer, string $phone, int $shopId): array
    {
        $weazyo = in_array($shopId, (array) $this->config->secret('sync.weazyo_shop_ids', [101]), true);
        $firstName = $this->stringValue($customer['first_name'] ?? null);
        $lastName = $this->stringValue($customer['last_name'] ?? null);
        $email = $this->stringValue($customer['email'] ?? null)
            ?? $this->stringValue($customer['shop_email'] ?? null);
        $address = $this->buildAddress($customer);

        $fields = [
            ['field' => 'client_id', 'value' => (int) $this->config->secret('nizu.client_id', 8)],
            ['field' => 'user_type', 'value' => 'client'],
            ['field' => 'is_admin', 'value' => 1],
            ['field' => 'role_id', 'value' => 0],
            ['field' => 'first_name', 'value' => $firstName],
            ['field' => 'last_name', 'value' => $lastName],
            ['field' => 'status', 'value' => 'active'],
            ['field' => 'email', 'value' => $email],
            ['field' => 'phone', 'value' => $phone],
            ['field' => 'is_primary_contact', 'value' => 1],
            ['field' => 'disable_login', 'value' => 0],
            ['field' => 'address', 'value' => $address],
            ['field' => 'language', 'value' => $this->stringValue($customer['language'] ?? null) ?? 'english'],
            ['field' => 'enable_web_notification', 'value' => 1],
            ['field' => 'enable_email_notification', 'value' => 1],
            ['field' => 'owner_id', 'value' => $weazyo ? 42 : 2],
            ['field' => 'lead_source_id', 'value' => $weazyo ? 182 : 181],
        ];

        if ($weazyo) {
            $fields[] = ['field' => 'group_ids', 'value' => 2];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function buildAddress(array $customer): ?string
    {
        $parts = array_filter([
            $this->stringValue($customer['street'] ?? null)
                ?? $this->stringValue($customer['shop_street'] ?? null),
            $this->stringValue($customer['cp'] ?? null)
                ?? $this->stringValue($customer['shop_zip'] ?? null),
            $this->stringValue($customer['city'] ?? null)
                ?? $this->stringValue($customer['shop_city'] ?? null),
            $this->stringValue($customer['country'] ?? null)
                ?? $this->stringValue($customer['shop_country'] ?? null),
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}