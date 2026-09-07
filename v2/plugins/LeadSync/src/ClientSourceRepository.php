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
            'SELECT shop.phonenumber, shop.email AS shop_email, shop.full_name, '
            . 'shop.country AS shop_country, shop.city AS shop_city, '
            . 'shop.zip AS shop_zip, shop.street AS shop_street, '
            . 'shared.email, shared.language, shared.first_name, shared.last_name, '
            . 'shared.country, shared.city, shared.cp, shared.region, shared.street '
            . 'FROM `%s` AS shop '
            . 'LEFT JOIN `clients_data` AS shared ON shared.phonenumber = shop.phonenumber',
            $table,
        );

        foreach ($this->connection->select($sql) as $customer) {
            yield $customer;
        }
    }
}