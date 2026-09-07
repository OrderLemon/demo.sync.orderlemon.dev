<?php

declare(strict_types=1);

namespace Plugins\LeadSync;

use Generator;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Connection;

final class ClientSourceRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Config $config,
    ) {
    }

    /**
     * @return Generator<int, array<string, mixed>, void, void>
     */
    public function customersForShop(int $shopId): Generator
    {
        $shopIds = $this->config->secret('sync.shop_ids', []);
        if (!is_array($shopIds) || !in_array($shopId, $shopIds, true)) {
            throw new \InvalidArgumentException('Shop ID is not allowlisted.');
        }

        $table = 'clients_' . $shopId;
        $sql = sprintf(
            'SELECT shop.phonenumber, shop.email AS shop_email, shop.full_name, shop.business_vat AS vat_number, '
            . 'shared.email, shared.language, shared.first_name, shared.last_name, '
            . 'shared.country, shared.city, shared.cp, shared.region, shared.street, '
            . 'countries.long_name AS country_name, '
            . 'shops.company_id AS shop_company_id '
            . 'FROM `%s` AS shop '
            . 'LEFT JOIN `clients_data` AS shared ON shared.phonenumber = shop.phonenumber '
            . 'LEFT JOIN `countries` ON countries.iso2 = shared.country '
            . 'LEFT JOIN `shops` ON shops.id = ?',
            $table,
        );

        foreach ($this->connection->select($sql, [$shopId]) as $customer) {
            yield $customer;
        }
    }
}