#!/usr/bin/php
<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

echo "WARNING: Use this script at your own risk. The author assumes no liability for any damages caused by its usage.\n";
echo "Press [Enter] to continue or Ctrl+C to exit.\n";
fgets(STDIN);

const PGSQL_TO_MARIADB_TYPES = [
    'smallint' => 'INT',
    'integer' => 'INT',
    'bigint' => 'BIGINT',
    'boolean' => 'TINYINT(1)',
    'character varying' => 'VARCHAR(255)',
    'text' => 'TEXT',
    'timestamp without time zone' => 'DATETIME',
    'date' => 'DATE',
    'numeric' => 'DECIMAL(20,6)',
];

const BATCH_SIZE = 100;
const DEFAULT_ENGINE = 'InnoDB';
const CHARSET = 'utf8mb4';

/**
 * Load environment variables from a .env file.
 */
function loadEnv(string $filePath): void
{
    if (!file_exists($filePath)) {
        return;
    }

    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2) + [1 => ''];
        putenv("$key=$value");
    }
}

/**
 * Main execution function.
 */
function main(): void
{
    loadEnv(__DIR__ . '/.env');

    $pgsqlConfig = [
        'dsn' => sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('PGSQL_HOST') ?: 'localhost',
            getenv('PGSQL_PORT') ?: '5432',
            getenv('PGSQL_DBNAME') ?: 'your_dbname'
        ),
        'user' => getenv('PGSQL_USER') ?: 'your_user',
        'password' => getenv('PGSQL_PASSWORD') ?: 'your_password',
    ];

    $mariadbConfig = [
        'dsn' => sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            getenv('MARIADB_HOST') ?: 'localhost',
            getenv('MARIADB_PORT') ?: '3306',
            getenv('MARIADB_DBNAME') ?: 'your_dbname'
        ),
        'user' => getenv('MARIADB_USER') ?: 'your_user',
        'password' => getenv('MARIADB_PASSWORD') ?: 'your_password',
    ];

    try {
        $pgsql = createPDOConnection($pgsqlConfig['dsn'], $pgsqlConfig['user'], $pgsqlConfig['password']);
        $mariadb = createPDOConnection($mariadbConfig['dsn'], $mariadbConfig['user'], $mariadbConfig['password']);
    } catch (PDOException $e) {
        logError("Database connection failed: " . $e->getMessage());
        exit("ERROR: Unable to connect to one of the databases.\n");
    }

    echo "### Starting Database Migration ###\n";
    migrateDatabase($pgsql, $mariadb, getenv('TABLE_ENGINE') ?: DEFAULT_ENGINE);
    echo "\nMigration completed successfully!\n";
}

/**
 * Create a PDO connection.
 */
function createPDOConnection(string $dsn, string $user, string $password): PDO
{
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4', // For MariaDB
        PDO::ATTR_PERSISTENT => true,
    ]);
}

/**
 * Migrate tables and data from PostgreSQL to MariaDB.
 */
function migrateDatabase(PDO $pgsql, PDO $mariadb, string $tableEngine): void
{
    $tables = $pgsql->query(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$tables) {
        echo "No tables found in the PostgreSQL database.\n";
        return;
    }

    foreach ($tables as $index => $tableName) {
        echo sprintf("[%d/%d] Migrating table: %s\n", $index + 1, count($tables), $tableName);

        $pgsqlColumns = fetchTableColumns($pgsql, $tableName);
        if (!$pgsqlColumns) {
            echo "  No columns found for table `$tableName`. Skipping.\n";
            continue;
        }

        createMariaDBTable($mariadb, $tableName, $pgsqlColumns, $tableEngine);
        transferTableData($pgsql, $mariadb, $tableName, $pgsqlColumns);
    }
}

/**
 * Fetch columns from a PostgreSQL table.
 */
function fetchTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare(
        "SELECT column_name, data_type, is_nullable 
         FROM information_schema.columns 
         WHERE table_name = :table_name"
    );
    $stmt->execute(['table_name' => $tableName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a MariaDB table based on PostgreSQL schema.
 */
function createMariaDBTable(PDO $mariadb, string $tableName, array $columns, string $tableEngine): void
{
    $columnsSql = array_map(function ($col) {
        $type = PGSQL_TO_MARIADB_TYPES[$col['data_type']] ?? 'TEXT';
        $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        return sprintf("`%s` %s %s", $col['column_name'], $type, $nullable);
    }, $columns);

    $sql = sprintf(
        "CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=%s DEFAULT CHARSET=%s",
        $tableName,
        implode(', ', $columnsSql),
        $tableEngine,
        CHARSET
    );

    $mariadb->exec($sql);
}

/**
 * Cleans a row of data based on column data types.
 */
function sanitizeRow(array &$row, array $columns): void
{
    foreach ($columns as $col) {
        $colName = $col['column_name'];
        $dataType = $col['data_type'];
        $isNullable = $col['is_nullable'] === 'YES';

        // Handle empty strings and NULL values
        if (!isset($row[$colName]) || $row[$colName] === '') {
            if (!$isNullable) {
                // Default values for NOT NULL columns
                if (in_array($dataType, ['integer', 'bigint', 'smallint', 'numeric'], true)) {
                    $row[$colName] = 0; // Default for numeric types
                } elseif ($dataType === 'boolean') {
                    $row[$colName] = 0; // Default for boolean types
                } else {
                    $row[$colName] = ''; // Default for text types
                }
            } else {
                $row[$colName] = null; // Allow NULL for nullable columns
            }
        }

        // Boolean sanitization: map values to 0/1
        if ($dataType === 'boolean') {
            $row[$colName] = filter_var($row[$colName], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
    }
}


/**
 * Transfer data from PostgreSQL to MariaDB.
 */
function transferTableData(PDO $pgsql, PDO $mariadb, string $tableName, array $columns): void
{
    $columnNames = array_column($columns, 'column_name');
    $sourceColumns = implode(', ', $columnNames);
    $placeholders = '(' . implode(',', array_fill(0, count($columnNames), '?')) . ')';

    $stmt = $mariadb->prepare(
        sprintf("INSERT INTO `%s` (%s) VALUES %s", $tableName, implode(', ', $columnNames), $placeholders)
    );

    $offset = 0;

    while (true) {
        $data = $pgsql->query(
            "SELECT $sourceColumns FROM \"$tableName\" LIMIT " . BATCH_SIZE . " OFFSET $offset"
        )->fetchAll();

        if (!$data) break;

        foreach ($data as &$row) {
            try {
                sanitizeRow($row, $columns); // Sanitize data before insert
                $stmt->execute(array_values($row));
            } catch (PDOException $e) {
                echo "\nError migrating row in table `$tableName`: " . $e->getMessage() . "\n";
                echo "Row Data: " . json_encode($row) . "\n";
                continue; // Skip problematic row
            }
        }

        $offset += BATCH_SIZE;
        echo ".";
    }

    echo " Done!\n";
}

// Start the script
main();
