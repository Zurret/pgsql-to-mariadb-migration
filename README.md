# PostgreSQL to MariaDB Migration Script

This PHP script migrates data and schema from a PostgreSQL database to a MariaDB database. It supports batch migration for large tables and ensures that data types are converted appropriately for MariaDB.

## WARNING

Use this script at your own risk. The author assumes no liability for any damages caused by its usage.

## Features

- Converts PostgreSQL data types to corresponding MariaDB types.
- Batch migration for large tables (configurable batch size).
- Handles default values for NOT NULL columns.
- Sanitize and transfer data with appropriate type conversions (e.g., boolean values).
- Migration can be customized using environment variables.

## Prerequisites

- PHP 8.3 or higher
- PostgreSQL and MariaDB databases accessible
- PHP PDO extension enabled for both PostgreSQL and MariaDB

## Configuration

Before running the script, you must configure the environment variables. To do this:

1. Copy the `.env.example` file to `.env`:

   ```bash
   cp .env.example .env
   ```

2. Edit the `.env` file with your PostgreSQL and MariaDB connection details.

### Example `.env` file:
```
PGSQL_HOST=localhost
PGSQL_PORT=5432
PGSQL_DBNAME=your_pgsql_dbname
PGSQL_USER=your_pgsql_user
PGSQL_PASSWORD=your_pgsql_password

MARIADB_HOST=localhost
MARIADB_PORT=3306
MARIADB_DBNAME=your_mariadb_dbname
MARIADB_USER=your_mariadb_user
MARIADB_PASSWORD=your_mariadb_password

TABLE_ENGINE=InnoDB  # Optional, default is 'InnoDB'
```

## How to Use

1. Clone or download the script.
2. Copy `.env.example` to `.env`:

   ```bash
   cp .env.example .env
   ```

3. Open the `.env` file and edit the connection details for PostgreSQL and MariaDB.
4. Run the script using the PHP CLI:

   ```bash
   php migrate.php
   ```

5. The script will prompt you with a warning message before proceeding. Press `[Enter]` to continue.

## Data Types Mapping

The following PostgreSQL data types are mapped to MariaDB equivalents:

- `smallint` → `INT`
- `integer` → `INT`
- `bigint` → `BIGINT`
- `boolean` → `TINYINT(1)`
- `character varying` → `VARCHAR(255)`
- `text` → `TEXT`
- `timestamp without time zone` → `DATETIME`
- `date` → `DATE`
- `numeric` → `DECIMAL(20,6)`

## Batch Size

The default batch size for migrating large tables is set to `100`. You can change this by modifying the `BATCH_SIZE` constant in the script.

## Charset and Engine

- Charset: `utf8mb4`
- Default Table Engine: `InnoDB` (can be changed using the `TABLE_ENGINE` environment variable)

## License

This script is released under the [MIT License](LICENSE).