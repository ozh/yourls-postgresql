<?php
/**
 * DB Engine:   PostgreSQL Support
 * DB URI:      https://github.com/ozh/yourls-postgresql
 * Description: Adds support for PostgreSQL databases in YOURLS. ⚠ PLACE THIS FILE IN THE USER/ DIRECTORY.
 * Version:     0.1
 * Author:      Ozh
 * Author URI:  https://ozh.org/
 */

// PostgreSQL hooks
yourls_add_filter('db_connect_custom_dsn', 'ozh_yourls_pgsql_dsn');
yourls_add_filter('html_footer_text', 'ozh_yourls_pgsql_footer');
yourls_add_filter('fetch_wrapper_statement', 'ozh_yourls_pgsql_rewrite_query');
yourls_add_filter('shunt_yourls_create_sql_tables', 'ozh_yourls_pgsql_create_tables');

// Now load the rest of the database code, which will use the above filters to modify behavior for PostgreSQL
require_once YOURLS_ABSPATH.'/includes/class-mysql.php';

/**
 * Modify the DSN to use PostgreSQL instead of MySQL
 *
 * @param $in
 * @return string
 */
function ozh_yourls_pgsql_dsn($in): string {
    $in = str_replace("mysql:","pgsql:",$in);
    $in = str_replace("charset=utf8mb4","",$in);
    // If running in a PHPUnit test context, add a note about using PostgreSQL
    if (class_exists('PHPUnit\Framework\TestCase')) {
        echo "Using PostgreSQL Engine 🐘\n";
    }
    return $in;
}


/**
 * Add a footer note to indicate we're using PostgreSQL
 *
 * @param $in
 * @return string
 */
function ozh_yourls_pgsql_footer($in): string {
    return $in . ' on PostgreSQL 🐘';
}

/**
 * Rewrite MySQL query to PostgreSQL compatible syntax
 *
 * @param string $query The original MySQL query
 * @return string The rewritten PostgreSQL query
 */
function ozh_yourls_pgsql_rewrite_query(string $query): string {
    // Store original for debugging
    $original_query = $query;

    // Replace backticks with double quotes
    $query = str_replace('`', '"', $query);

    // Convert DATE_ADD/DATE_SUB to PostgreSQL interval syntax
    $query = pgsql_convert_date_add($query);

    // Convert DATE_FORMAT to TO_CHAR
    $query = pgsql_convert_date_format($query);

    // Convert "LIMIT offset, count" to "LIMIT count OFFSET offset"
    $query = preg_replace(
        '/LIMIT\s+(\d+)\s*,\s*(\d+)/i',
        'LIMIT $2 OFFSET $1',
        $query
    );

    // Replace current_timestamp() with CURRENT_TIMESTAMP (no parentheses)
    $query = preg_replace(
        '/current_timestamp\(\)/i',
        'CURRENT_TIMESTAMP',
        $query
    );

    // Replace RAND() with RANDOM()
    $query = preg_replace('/\bRAND\s*\(/i', 'RANDOM(', $query);

    // Handle DATETIME to TIMESTAMP conversion
    $query = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $query);

    // Remove BINARY keyword (case-sensitive comparison)
    $query = preg_replace('/\bBINARY\b/i', '', $query);

    // Remove UNSIGNED keyword that doesn't exist in PostgreSQL
    $query = preg_replace('/\s+UNSIGNED/i', '', $query);

    // Handle AUTO_INCREMENT conversions in CREATE TABLE
    $query = preg_replace(
        '/\bBIGINT\s*(?:\(\d+\))?\s*(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT/i',
        'BIGSERIAL',
        $query
    );

    // Pattern for INT with AUTO_INCREMENT
    $query = preg_replace(
        '/\bINT\s*(?:\(\d+\))?\s*(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT/i',
        'SERIAL',
        $query
    );

    // Clean up any remaining AUTO_INCREMENT
    $query = preg_replace('/\bAUTO_INCREMENT\b/i', '', $query);

    // Remove MySQL type size hints like INT(10), VARCHAR preserves its size
    $query = preg_replace('/\bINT\(\d+\)/i', 'INTEGER', $query);
    $query = preg_replace('/\bBIGINT\(\d+\)/i', 'BIGINT', $query);

    // Replace CHARACTER SET and COLLATE clauses
    $query = preg_replace('/CHARACTER\s+SET\s+\w+/i', '', $query);
    $query = preg_replace('/COLLATE\s+\w+/i', '', $query);

    // Remove ENGINE, DEFAULT CHARSET, and similar MySQL-specific table options
    $query = preg_replace('/ENGINE\s*=\s*\w+/i', '', $query);
    $query = preg_replace('/DEFAULT\s+CHARSET\s*=\s*\w+/i', '', $query);
    $query = preg_replace('/AUTO_INCREMENT\s*=\s*\d+/i', '', $query);

    // Replace SHOW TABLES with PostgreSQL equivalent
    if (preg_match('/SHOW\s+TABLES(?:\s+LIKE\s+["\']([^"\']+)["\'])?/i', $query, $matches)) {
        if (isset($matches[1])) {
            $table_pattern = $matches[1];
            $query = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE '$table_pattern'";
        } else {
            $query = "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
        }
    }

    // Replace SHOW COLUMNS with PostgreSQL equivalent
    if (preg_match('/SHOW\s+COLUMNS\s+FROM\s+"?(\w+)"?/i', $query, $matches)) {
        $table = $matches[1];
        $query = "SELECT column_name, data_type, is_nullable
                  FROM information_schema.columns
                  WHERE table_name = '$table'";
    }

    // Handle KEY to INDEX conversion (both work, but INDEX is clearer)
    $query = preg_replace('/\bKEY\s+"/i', 'INDEX "', $query);

    // Fix PRIMARY KEY syntax issues (remove quotes around constraint name)
    $query = preg_replace('/PRIMARY\s+KEY\s+"([^"]+)"/i', 'PRIMARY KEY', $query);

    // Clean up extra whitespace and commas
    $query = preg_replace('/,\s*\)/', ')', $query);
    $query = preg_replace('/\s+/', ' ', $query);

    return trim($query);
}

/**
 * Convert MySQL DATE_FORMAT to PostgreSQL TO_CHAR
 */
function pgsql_convert_date_format($query): string {
    // Pattern to capture DATE_FORMAT(column, 'format')
    $pattern = '/DATE_FORMAT\s*\(\s*([^,]+?)\s*,\s*([\'"])([^\2]*?)\2\s*\)/i';

    $query = preg_replace_callback($pattern, function($matches) {
        $column = $matches[1];
        $mysql_format = $matches[3];

        // Convert MySQL format to PostgreSQL
        $pgsql_format = strtr($mysql_format, [
            '%Y' => 'YYYY',  // Year, 4 digits
            '%y' => 'YY',    // Year, 2 digits
            '%m' => 'MM',    // Month, 2 digits
            '%d' => 'DD',    // Day of the month, 2 digits
            '%H' => 'HH24',  // Hour (24-hour), 2 digits
            '%h' => 'HH12',  // Hour (12-hour), 2 digits
            '%i' => 'MI',    // Minute, 2 digits
            '%s' => 'SS',    // Second, 2 digits
            '%W' => 'Day',   // Full weekday name
            '%M' => 'Month', // Full month name
            '%a' => 'Dy',    // Abbreviated weekday name
            '%b' => 'Mon',   // Abbreviated month name
            '%w' => 'D',     // Day of the week (0=Sunday, 1=Monday...)
        ]);

        return "TO_CHAR($column, '$pgsql_format')";
    }, $query);

    return $query;
}

/**
 * Convert MySQL DATE_ADD/DATE_SUB to PostgreSQL interval syntax
 * @param string $query The original MySQL query
 * @return string The rewritten PostgreSQL query
 */
function pgsql_convert_date_add($query): string {
    // Pattern to capture DATE_ADD(column, INTERVAL x UNIT)
    $pattern = '/DATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([^\)]+?)\s+(\w+)\s*\)/i';

    $query = preg_replace_callback($pattern, function($matches) {
        $column = trim($matches[1]);
        $value = trim($matches[2]);
        $unit = strtoupper(trim($matches[3]));

        // Convert MySQL units to PostgreSQL units
        $unit_map = [
            'YEAR' => 'year',
            'MONTH' => 'month',
            'DAY' => 'day',
            'HOUR' => 'hour',
            'MINUTE' => 'minute',
            'SECOND' => 'second',
        ];

        $pg_unit = $unit_map[$unit] ?? strtolower($unit);

        // PostgreSQL: column + INTERVAL 'x unit'
        return "($column + INTERVAL '$value $pg_unit')";
    }, $query);

    // Pattern for DATE_SUB (substraction)
    $pattern = '/DATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+([^\)]+?)\s+(\w+)\s*\)/i';

    $query = preg_replace_callback($pattern, function($matches) {
        $column = trim($matches[1]);
        $value = trim($matches[2]);
        $unit = strtoupper(trim($matches[3]));

        $unit_map = [
            'YEAR' => 'year',
            'MONTH' => 'month',
            'DAY' => 'day',
            'HOUR' => 'hour',
            'MINUTE' => 'minute',
            'SECOND' => 'second',
        ];

        $pg_unit = $unit_map[$unit] ?? strtolower($unit);

        // PostgreSQL: column - INTERVAL 'x unit'
        return "($column - INTERVAL '$value $pg_unit')";
    }, $query);

    // Convert interval substraction like "CURRENT_TIMESTAMP - INTERVAL 1 DAY"
    $query = preg_replace('/INTERVAL\s+(\d+)\s+(\w+)/i', "INTERVAL '$1 $2'", $query);

    return $query;
}

/**
 * Create PostgreSQL tables. Return array( 'success' => array of success strings, 'errors' => array of error strings )
 * This is a PostgreSQL-specific version of yourls_create_sql_tables()
 *
 * @return array  An array like array( 'success' => array of success strings, 'errors' => array of error strings )
 */
function ozh_yourls_pgsql_create_tables(): array {
    $ydb = yourls_get_db('write-create_sql_tables');

    $error_msg = array();
    $success_msg = array();

    // Create Table Query - PostgreSQL syntax
    $create_tables = array();

    $create_tables[YOURLS_DB_TABLE_URL] =
        'CREATE TABLE IF NOT EXISTS "'.YOURLS_DB_TABLE_URL.'" ('.
        '"keyword" VARCHAR(100) NOT NULL DEFAULT \'\','.
        '"url" TEXT NOT NULL,'.
        '"title" TEXT DEFAULT NULL,'.
        '"timestamp" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'.
        '"ip" VARCHAR(41) NOT NULL,'.
        '"clicks" INTEGER NOT NULL DEFAULT 0,'.
        'PRIMARY KEY ("keyword")'.
        ');';

    $create_tables[YOURLS_DB_TABLE_OPTIONS] =
        'CREATE TABLE IF NOT EXISTS "'.YOURLS_DB_TABLE_OPTIONS.'" ('.
        '"option_id" BIGSERIAL,'.
        '"option_name" VARCHAR(64) NOT NULL DEFAULT \'\','.
        '"option_value" TEXT NOT NULL,'.
        'PRIMARY KEY ("option_id", "option_name"),'.
        'UNIQUE ("option_name")'.
        ');';

    $create_tables[YOURLS_DB_TABLE_LOG] =
        'CREATE TABLE IF NOT EXISTS "'.YOURLS_DB_TABLE_LOG.'" ('.
        '"click_id" SERIAL,'.
        '"click_time" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'.
        '"shorturl" VARCHAR(100) NOT NULL,'.
        '"referrer" VARCHAR(200) NOT NULL DEFAULT \'\','.
        '"user_agent" VARCHAR(255) NOT NULL DEFAULT \'\','.
        '"ip_address" VARCHAR(41) NOT NULL DEFAULT \'\','.
        '"country_code" CHAR(2) NOT NULL DEFAULT \'\','.
        'PRIMARY KEY ("click_id")'.
        ');';

    // Indexes to create after tables
    $create_indexes = array();

    $create_indexes[YOURLS_DB_TABLE_URL] = array(
        'CREATE INDEX IF NOT EXISTS "ip" ON "'.YOURLS_DB_TABLE_URL.'" ("ip");',
        'CREATE INDEX IF NOT EXISTS "timestamp" ON "'.YOURLS_DB_TABLE_URL.'" ("timestamp");',
        'CREATE INDEX IF NOT EXISTS "url_idx" ON "'.YOURLS_DB_TABLE_URL.'" ("url");'
    );

    $create_indexes[YOURLS_DB_TABLE_OPTIONS] = array(
        'CREATE INDEX IF NOT EXISTS "option_name" ON "'.YOURLS_DB_TABLE_OPTIONS.'" ("option_name");'
    );

    $create_indexes[YOURLS_DB_TABLE_LOG] = array(
        'CREATE INDEX IF NOT EXISTS "shorturl" ON "'.YOURLS_DB_TABLE_LOG.'" ("shorturl");'
    );

    $create_table_count = 0;

    yourls_debug_mode(true);

    // Create tables
    foreach ( $create_tables as $table_name => $table_query ) {
        try {
            $ydb->perform( $table_query );

            // PostgreSQL equivalent of SHOW TABLES LIKE
            $check_query = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = '$table_name'";
            $create_success = $ydb->fetchValue( $check_query );

            if( $create_success ) {
                $create_table_count++;
                $success_msg[] = yourls_s( "Table '%s' created.", $table_name );

                // Create indexes for this table
                if ( isset($create_indexes[$table_name]) ) {
                    foreach ( $create_indexes[$table_name] as $index_query ) {
                        try {
                            $ydb->perform( $index_query );
                        } catch (Exception $e) {
                            $error_msg[] = yourls_s( "Error creating index on table '%s': %s", $table_name, $e->getMessage() );
                        }
                    }
                }
            } else {
                $error_msg[] = yourls_s( "Error creating table '%s'.", $table_name );
            }
        } catch (Exception $e) {
            $error_msg[] = yourls_s( "Error creating table '%s': %s", $table_name, $e->getMessage() );
        }
    }

    // Initializes the option table
    if( !yourls_initialize_options() )
        $error_msg[] = yourls__( 'Could not initialize options' );

    // Insert sample links
    if( !yourls_insert_sample_links() )
        $error_msg[] = yourls__( 'Could not insert sample short URLs' );

    // Check results of operations
    if ( sizeof( $create_tables ) == $create_table_count ) {
        $success_msg[] = yourls__( 'YOURLS tables successfully created.' );
    } else {
        $error_msg[] = yourls__( 'Error creating YOURLS tables.' );
    }

    return array( 'success' => $success_msg, 'error' => $error_msg );
}
