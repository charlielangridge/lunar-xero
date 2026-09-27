<?php

declare(strict_types=1);

// A separate PHP process and database connection for MySqlInvoiceConcurrencyTest.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use CharlieLangridge\LunarXero\Contracts\XeroClientInterface;
use CharlieLangridge\LunarXero\Models\XeroSettings;
use CharlieLangridge\LunarXero\Repositories\XeroSettingsRepository;
use CharlieLangridge\LunarXero\Services\XeroSyncService;
use CharlieLangridge\LunarXero\Support\LunarModelResolver;
use CharlieLangridge\LunarXero\Tests\Fixtures\Models\Order;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Mockery as M;

try {
    $settings = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $role = $argv[2];
    $directory = dirname($argv[1]);

    $app = new Container;
    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    $capsule = new Capsule($app);
    $capsule->addConnection([
        'driver' => 'mysql',
        'host' => $settings['host'],
        'port' => $settings['port'],
        'database' => $settings['database'],
        'username' => $settings['username'],
        'password' => $settings['password'],
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);
    $app->instance('db', $capsule->getDatabaseManager());
    $capsule->bootEloquent();
    $app['config']->set('lunarpanel-xero.tables.sync_logs', 'xero_sync_logs');
    $app['config']->set('lunarpanel-xero.orders.require_placed_for_sync', false);
    $app['config']->set('lunarpanel-xero.charity.enabled', false);

    $providerConnection = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', $settings['host'], $settings['port'], $settings['database']),
        $settings['username'],
        $settings['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $client = M::mock(XeroClientInterface::class);
    $client->shouldReceive('createInvoice')->andReturnUsing(function () use ($providerConnection, $role, $directory): array {
        $providerConnection->exec("INSERT INTO provider_calls (kind) VALUES ('create')");
        $callId = (int) $providerConnection->lastInsertId();

        if ($role === 'A') {
            file_put_contents($directory.'/a-in-create', '1');
            $deadline = microtime(true) + 20;

            while (! file_exists($directory.'/release-a')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting to release fake Xero createInvoice.');
                }

                usleep(10_000);
            }
        }

        return ['id' => 'invoice-'.$callId, 'status' => 'DRAFT'];
    });
    $client->shouldReceive('updateInvoice')->andReturnUsing(function (string $invoiceId) use ($providerConnection): array {
        $providerConnection->exec("INSERT INTO provider_calls (kind) VALUES ('update')");

        return ['id' => $invoiceId, 'status' => 'DRAFT'];
    });

    $repository = M::mock(XeroSettingsRepository::class);
    $repository->shouldReceive('getInvoiceStatus')->andReturn('DRAFT');
    $repository->shouldReceive('updateConnectionMeta')->andReturn(new XeroSettings);

    file_put_contents($directory.'/'.strtolower($role).'-starting', '1');

    $result = (new XeroSyncService($client, $repository, new LunarModelResolver))
        ->syncOrderInvoice(Order::query()->findOrFail($settings['order_id']));

    echo json_encode(['role' => $role, 'result' => $result], JSON_THROW_ON_ERROR);
    M::close();
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage().PHP_EOL.$throwable->getTraceAsString());
    exit(1);
}
