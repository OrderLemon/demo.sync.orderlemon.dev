<?php

declare(strict_types=1);

namespace Plugins\LeadSync;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Database\Connection;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Support\Logger;

final class LeadSyncPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            NizuApiClient::class,
            static fn(Container $container): NizuApiClient => new NizuApiClient(
                $container->get(Config::class),
                $container->get(Logger::class),
            ),
        );

        $registrar->singleton(
            ClientSourceRepository::class,
            static fn(Container $container): ClientSourceRepository => new ClientSourceRepository(
                $container->get(Connection::class),
                $container->get(Config::class),
            ),
        );

        $registrar->singleton(
            LeadSyncService::class,
            static fn(Container $container): LeadSyncService => new LeadSyncService(
                $container->get(Config::class),
                $container->get(ClientSourceRepository::class),
                $container->get(NizuApiClient::class),
                $container->get(Logger::class),
            ),
        );
    }
}