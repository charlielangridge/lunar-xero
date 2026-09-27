<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;

it('serializes genuinely overlapping invoice workers on the same MySQL order row', function (): void {
    if (getenv('MYSQL_CONCURRENCY') !== '1') {
        test()->markTestSkipped('Set MYSQL_CONCURRENCY=1 to run the MySQL process concurrency test.');
    }

    $host = getenv('MYSQL_CONCURRENCY_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('MYSQL_CONCURRENCY_PORT') ?: '3306');
    $username = getenv('MYSQL_CONCURRENCY_USER') ?: 'root';
    $password = getenv('MYSQL_CONCURRENCY_PASSWORD') ?: '';
    $database = 'lunar_xero_race_'.bin2hex(random_bytes(6));
    $directory = sys_get_temp_dir().'/lunar-xero-concurrency-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    $admin = new PDO(
        sprintf('mysql:host=%s;port=%d', $host, $port),
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $processes = [];

    try {
        $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        foreach ([
            'CREATE TABLE test_customers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255), first_name VARCHAR(255), last_name VARCHAR(255), xero_contact_id VARCHAR(255), xero_include_order_line_notes BOOLEAN DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE test_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, customer_id BIGINT UNSIGNED, reference VARCHAR(255), customer_reference VARCHAR(255), meta JSON NULL, placed_at TIMESTAMP NULL, xero_invoice_id VARCHAR(255), xero_invoice_number VARCHAR(255), xero_invoice_status VARCHAR(255), xero_online_invoice_url VARCHAR(2048), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE test_products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, xero_account_code VARCHAR(255), xero_item_code VARCHAR(255), attribute_data JSON NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE test_order_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED, product_id BIGINT UNSIGNED, product_variant_id BIGINT UNSIGNED NULL, description VARCHAR(255), notes TEXT NULL, quantity INT UNSIGNED DEFAULT 1, unit_price DECIMAL(12,2) DEFAULT 0, tax_total DECIMAL(12,2) DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE test_order_addresses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED, type VARCHAR(255), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE test_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED, type VARCHAR(255), success BOOLEAN, captured_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE xero_sync_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, operation VARCHAR(255), status VARCHAR(255), resource_type VARCHAR(255) NULL, resource_id BIGINT UNSIGNED NULL, external_reference VARCHAR(255) NULL, payload JSON NULL, response JSON NULL, context JSON NULL, error_message TEXT NULL, attempt INT UNSIGNED DEFAULT 0, started_at TIMESTAMP NULL, completed_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) ENGINE=InnoDB',
            'CREATE TABLE provider_calls (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(32)) ENGINE=InnoDB',
        ] as $statement) {
            $pdo->exec($statement);
        }

        $pdo->exec("INSERT INTO test_customers (id, email, first_name, xero_contact_id) VALUES (1, 'buyer@example.com', 'Buyer', 'contact-1')");
        $pdo->exec("INSERT INTO test_orders (id, customer_id, reference) VALUES (1, 1, 'RACE-ORDER')");
        $pdo->exec("INSERT INTO test_products (id, xero_account_code, xero_item_code, attribute_data) VALUES (1, '200', 'RACE-ITEM', '{\"name\":\"Item\"}')");
        $pdo->exec("INSERT INTO test_order_lines (order_id, product_id, description, unit_price) VALUES (1, 1, 'Item', 10.00)");

        $settings = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'order_id' => 1,
        ];
        $configPath = $directory.'/connection.json';
        file_put_contents($configPath, json_encode($settings, JSON_THROW_ON_ERROR));

        $start = function (string $role) use ($configPath): array {
            $command = [PHP_BINARY, dirname(__DIR__).'/Support/MySqlInvoiceSyncWorker.php', $configPath, $role];
            $pipes = [];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));

            if (! is_resource($process)) {
                throw new RuntimeException("Could not start worker {$role}.");
            }

            fclose($pipes[0]);

            return [$process, $pipes];
        };

        $waitFor = function (callable $condition, string $description): void {
            $deadline = microtime(true) + 12;

            while (! $condition()) {
                if (microtime(true) >= $deadline) {
                    throw new AssertionFailedError("Timed out waiting for {$description}.");
                }

                usleep(20_000);
            }
        };

        $processes['A'] = $start('A');
        $waitFor(fn (): bool => file_exists($directory.'/a-in-create'), 'worker A to enter fake createInvoice');

        $processes['B'] = $start('B');
        $waitFor(fn (): bool => file_exists($directory.'/b-starting'), 'worker B to start');

        $lockWait = $admin->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM performance_schema.data_lock_waits AS waits
            JOIN performance_schema.data_locks AS locks
              ON locks.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID
            WHERE locks.OBJECT_SCHEMA = ? AND locks.OBJECT_NAME = 'test_orders'
            SQL);
        $waitFor(function () use ($lockWait, $database): bool {
            $lockWait->execute([$database]);

            return (int) $lockWait->fetchColumn() > 0;
        }, 'a real MySQL row-lock wait by worker B');

        expect((int) $pdo->query("SELECT COUNT(*) FROM provider_calls WHERE kind = 'create'")->fetchColumn())->toBe(1)
            ->and($pdo->query('SELECT xero_invoice_id FROM test_orders WHERE id = 1')->fetchColumn())->toBeNull();

        file_put_contents($directory.'/release-a', '1');

        $results = [];
        foreach ($processes as $role => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            expect($exitCode)->toBe(0, "Worker {$role} failed: {$error}");
            $results[$role] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $processes = [];

        expect((int) $pdo->query("SELECT COUNT(*) FROM provider_calls WHERE kind = 'create'")->fetchColumn())->toBe(1)
            ->and((int) $pdo->query("SELECT COUNT(*) FROM provider_calls WHERE kind = 'update'")->fetchColumn())->toBe(1)
            ->and($pdo->query('SELECT xero_invoice_id FROM test_orders WHERE id = 1')->fetchColumn())->toBe('invoice-1')
            ->and($results['A']['result']['id'])->toBe('invoice-1')
            ->and($results['B']['result']['id'])->toBe('invoice-1');
    } finally {
        file_put_contents($directory.'/release-a', '1');

        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }

        $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});
