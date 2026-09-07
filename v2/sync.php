<?php

declare(strict_types=1);

use Plugins\LeadSync\LeadSyncService;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Container $container */
$container = require __DIR__ . '/bootstrap.php';
$config = $container->get(Config::class);
$logger = $container->get(Logger::class);
$lockPath = (string) $config->secret('sync.lock_path', sys_get_temp_dir() . '/orderlemon-nizu-sync.lock');

$lock = fopen($lockPath, 'c');
if ($lock === false) {
    $logger->error('Unable to open sync lock file', ['path' => $lockPath]);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    $logger->info('Skipped sync because another run is active.');
    exit(0);
}

try {
    $summary = $container->get(LeadSyncService::class)->run();
    $logger->info('Lead sync completed', $summary);
    fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    $logger->critical('Lead sync failed', ['error' => $exception->getMessage()]);
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}