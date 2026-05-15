#!/usr/bin/env php
<?php
/**
 * YOURLS MySQL to PostgreSQL Migration Helper
 *
 * This script helps migrate data from MySQL to PostgreSQL
 *
 * Usage:
 *   php migrate.php
 *
 * Requirements:
 *   - PHP PDO with both MySQL and PostgreSQL drivers
 *   - Access to both source MySQL and target PostgreSQL databases
 */

// Configuration
$mysql_config = [
    'host' => 'mysql',
    'port' => 3306,
    'dbname' => 'yourls_mysql',
    'user' => 'mysql_user',
    'pass' => 'mysql_password',
];

$pgsql_config = [
    'host' => 'postgres',
    'port' => 5432,
    'dbname' => 'yourls_db',
    'user' => 'yourls_user',
    'pass' => 'yourls_password',
];

// Table prefix (usually 'yourls_')
$table_prefix = 'yourls_';

// Don't edit below unless you know what you're doing

echo "YOURLS MySQL → PostgreSQL Migration\n";
echo "====================================\n\n";

// Connect to MySQL
try {
    $mysql_dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $mysql_config['host'],
        $mysql_config['port'],
        $mysql_config['dbname']
    );
    $mysql = new PDO($mysql_dsn, $mysql_config['user'], $mysql_config['pass']);
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to MySQL\n";
} catch (PDOException $e) {
    die("✗ MySQL connection failed: " . $e->getMessage() . "\n");
}

// Connect to PostgreSQL
try {
    $pgsql_dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $pgsql_config['host'],
        $pgsql_config['port'],
        $pgsql_config['dbname']
    );
    $pgsql = new PDO($pgsql_dsn, $pgsql_config['user'], $pgsql_config['pass']);
    $pgsql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to PostgreSQL\n\n";
} catch (PDOException $e) {
    die("✗ PostgreSQL connection failed: " . $e->getMessage() . "\n");
}

// Migrate yourls_url table
echo "Migrating {$table_prefix}url table...\n";
try {
    $stmt = $mysql->query("SELECT * FROM {$table_prefix}url");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "  No data to migrate\n";
    } else {
        $pgsql->beginTransaction();

        $insert = $pgsql->prepare(
            "INSERT INTO {$table_prefix}url (keyword, url, title, timestamp, ip, clicks)
             VALUES (:keyword, :url, :title, :timestamp, :ip, :clicks)
             ON CONFLICT (keyword) DO NOTHING"
        );

        $count = 0;
        foreach ($rows as $row) {
            $insert->execute([
                'keyword' => $row['keyword'],
                'url' => $row['url'],
                'title' => $row['title'],
                'timestamp' => $row['timestamp'],
                'ip' => $row['ip'],
                'clicks' => $row['clicks'],
            ]);
            $count++;
        }

        $pgsql->commit();
        echo "  ✓ Migrated {$count} URLs\n";
    }
} catch (Exception $e) {
    if ($pgsql->inTransaction()) {
        $pgsql->rollBack();
    }
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Migrate yourls_options table
echo "\nMigrating {$table_prefix}options table...\n";
try {
    $stmt = $mysql->query("SELECT * FROM {$table_prefix}options");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "  No data to migrate\n";
    } else {
        $pgsql->beginTransaction();

        $insert = $pgsql->prepare(
            "INSERT INTO {$table_prefix}options (option_name, option_value)
             VALUES (:name, :value)
             ON CONFLICT (option_name) DO UPDATE SET option_value = EXCLUDED.option_value"
        );

        $count = 0;
        foreach ($rows as $row) {
            $insert->execute([
                'name' => $row['option_name'],
                'value' => $row['option_value'],
            ]);
            $count++;
        }

        $pgsql->commit();
        echo "  ✓ Migrated {$count} options\n";
    }
} catch (Exception $e) {
    if ($pgsql->inTransaction()) {
        $pgsql->rollBack();
    }
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Migrate yourls_log table (if exists)
echo "\nMigrating {$table_prefix}log table...\n";
try {
    // Check if table exists in MySQL
    $stmt = $mysql->query("SHOW TABLES LIKE '{$table_prefix}log'");
    if ($stmt->rowCount() === 0) {
        echo "  Table doesn't exist in MySQL, skipping\n";
    } else {
        $stmt = $mysql->query("SELECT * FROM {$table_prefix}log");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "  No data to migrate\n";
        } else {
            $pgsql->beginTransaction();

            $insert = $pgsql->prepare(
                "INSERT INTO {$table_prefix}log
                 (click_time, shorturl, referrer, user_agent, ip_address, country_code)
                 VALUES (:click_time, :shorturl, :referrer, :user_agent, :ip_address, :country_code)"
            );

            $count = 0;
            foreach ($rows as $row) {
                $insert->execute([
                    'click_time' => $row['click_time'],
                    'shorturl' => $row['shorturl'],
                    'referrer' => $row['referrer'] ?? null,
                    'user_agent' => $row['user_agent'] ?? null,
                    'ip_address' => $row['ip_address'] ?? null,
                    'country_code' => $row['country_code'] ?? null,
                ]);
                $count++;

                // Show progress for large datasets
                if ($count % 1000 === 0) {
                    echo "  ... {$count} rows migrated\n";
                }
            }

            $pgsql->commit();
            echo "  ✓ Migrated {$count} log entries\n";
        }
    }
} catch (Exception $e) {
    if ($pgsql->inTransaction()) {
        $pgsql->rollBack();
    }
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

// Update sequences to avoid conflicts
echo "\nUpdating PostgreSQL sequences...\n";
try {
    // Update yourls_options sequence
    $result = $pgsql->query("SELECT MAX(option_id) as max_id FROM {$table_prefix}options");
    $max_id = $result->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
    if ($max_id > 0) {
        $pgsql->exec("SELECT setval('{$table_prefix}options_option_id_seq', {$max_id})");
        echo "  ✓ Updated options sequence to {$max_id}\n";
    }

    // Update yourls_log sequence if table exists
    $stmt = $pgsql->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = '{$table_prefix}log')");
    if ($stmt->fetchColumn()) {
        $result = $pgsql->query("SELECT MAX(click_id) as max_id FROM {$table_prefix}log");
        $max_id = $result->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
        if ($max_id > 0) {
            $pgsql->exec("SELECT setval('{$table_prefix}log_click_id_seq', {$max_id})");
            echo "  ✓ Updated log sequence to {$max_id}\n";
        }
    }
} catch (Exception $e) {
    echo "  ✗ Error updating sequences: " . $e->getMessage() . "\n";
}

echo "\n====================================\n";
echo "Migration complete!\n\n";
