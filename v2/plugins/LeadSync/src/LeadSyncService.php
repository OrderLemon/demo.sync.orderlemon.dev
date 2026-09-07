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

                    $clientId = $this->nizu->createLead($this->buildFields($customer, $phone, $shopId));
                    $summary['inserted']++;
                    $this->logger->info('Lead inserted', [
                        'shop_id' => $shopId,
                        'phone' => $phone,
                        'client_id' => $clientId,
                    ]);
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
        $companyId = $customer['shop_company_id'] ?? null;

        return [
            ['field' => 'company_name', 'value' => $this->stringValue($customer['full_name'] ?? null)],
            ['field' => 'type', 'value' => 'person'],
            ['field' => 'address', 'value' => $this->stringValue($customer['street'] ?? null)],
            ['field' => 'city', 'value' => $this->stringValue($customer['city'] ?? null)],
            ['field' => 'state', 'value' => $this->stringValue($customer['region'] ?? null)],
            ['field' => 'zip', 'value' => $this->stringValue($customer['cp'] ?? null)],
            ['field' => 'country', 'value' => $this->stringValue($customer['country_name'] ?? null)],
            ['field' => 'country_code', 'value' => $this->stringValue($customer['country'] ?? null)],
            ['field' => 'created_date', 'value' => 'NOW()'],
            ['field' => 'website', 'value' => ""],
            ['field' => 'phone', 'value' => $phone],
            ['field' => 'starred_by', 'value' => ""],
            ['field' => 'group_ids', 'value' => $weazyo ? 2 : ""],
            ['field' => 'deleted', 'value' => 0],
            ['field' => 'is_lead', 'value' => 1],
            ['field' => 'lead_status_id', 'value' => 1],
            ['field' => 'owner_id', 'value' => $weazyo ? 42 : 2],
            ['field' => 'created_by', 'value' => 1],
            ['field' => 'sort', 'value' => 0],
            ['field' => 'lead_source_id', 'value' => $weazyo ? 182 : 181],
            ['field' => 'last_lead_status', 'value' => ""],
            ['field' => 'client_migration_date', 'value' => 'NOW()'],
            ['field' => 'vat_number', 'value' => $this->stringValue($customer['vat_number'] ?? "")],
            ['field' => 'gst_number', 'value' => ""],
            ['field' => 'stripe_customer_id', 'value' => ""],
            ['field' => 'stripe_card_ending_digit', 'value' => 0],
            ['field' => 'currency', 'value' => 'EUR'],
            ['field' => 'currency_symbol', 'value' => '€'],
            ['field' => 'disable_online_payment', 'value' => 0],
            ['field' => 'labels', 'value' => $companyId !== "" ? 'ol_company_id: ' . $companyId : ""],
            ['field' => 'client_type', 'value' => ""],
            ['field' => 'client_electronic_address', 'value' => ""],
            ['field' => 'managers', 'value' => ""],
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return "";
        }

        $value = trim($value);
        return $value === '' ? "" : $value;
    }
}