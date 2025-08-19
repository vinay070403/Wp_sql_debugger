<?php

/**
 * Plugin Name: WP SQL Debugger
 * Description: Run SQL queries live inside WordPress Admin and debug results with query logs.
 * Version: 1.0.0
 * Author: Vinay
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create history table on activation.
 */
register_activation_hook(__FILE__, 'wpsqldebugger_create_table');
function wpsqldebugger_create_table()
{
    global $wpdb;
    $table_name      = $wpdb->prefix . 'sql_debugger_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		query LONGTEXT NOT NULL,
		execution_time FLOAT DEFAULT 0,
		error TEXT DEFAULT NULL,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id)
	) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Admin menu.
 */
add_action('admin_menu', 'wpsqldebugger_register_menu');
function wpsqldebugger_register_menu()
{
    add_menu_page(
        'SQL Debugger',
        'SQL Debugger',
        'manage_options',
        'wp-sql-debugger',
        'wpsqldebugger_admin_page',
        'dashicons-database',
        80
    );
}

/**
 * Small helper to validate allowed queries.
 */
function wpsqldebugger_is_allowed_query($query)
{
    $q = ltrim($query);

    // Only allow single statement starting with these verbs:
    if (! preg_match('/^(SELECT|INSERT|UPDATE|DELETE)\b/i', $q)) {
        return 'Only SELECT, INSERT, UPDATE, DELETE queries are allowed.';
    }

    // Disallow dangerous statements anywhere in text.
    if (preg_match('/\b(DROP|ALTER|TRUNCATE|RENAME|CREATE|GRANT|REVOKE)\b/i', $q)) {
        return 'Dangerous statements (DROP/ALTER/TRUNCATE/RENAME/CREATE/GRANT/REVOKE) are not allowed.';
    }

    // Disallow multiple statements / inline comments for safety.
    if (strpos($q, ';') !== false) {
        return 'Multiple statements are not allowed (remove semicolons).';
    }
    if (preg_match('/(--|#|\/\*)/', $q)) {
        return 'Inline comments are not allowed.';
    }

    return true;
}

/**
 * Admin page content.
 */
function wpsqldebugger_admin_page()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'wp-sql-debugger'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'sql_debugger_history';

    $query        = '';
    $result_rows  = array(); // For SELECT results.
    $rows_affected = null;   // For INSERT/UPDATE/DELETE.
    $error        = '';
    $runtime_ms   = 0;

    // Handle POST (run query).
    if (isset($_POST['wpsqldebugger_query'])) {
        check_admin_referer('wpsqldebugger_run_query', 'wpsqldebugger_nonce');

        // Raw SQL typed by admin:
        $query = isset($_POST['wpsqldebugger_query']) ? trim(wp_unslash($_POST['wpsqldebugger_query'])) : '';

        if ($query !== '') {
            $allowed = wpsqldebugger_is_allowed_query($query);

            if ($allowed === true) {
                $start = microtime(true);

                // Determine query type:
                if (preg_match('/^\s*SELECT\b/i', $query)) {
                    // Safety cap: add LIMIT 200 if none present.
                    if (! preg_match('/\blimit\s+\d+/i', $query)) {
                        $query_to_run = $query . ' LIMIT 200';
                    } else {
                        $query_to_run = $query;
                    }

                    $result_rows = $wpdb->get_results($query_to_run, ARRAY_A);
                    $error       = $wpdb->last_error;
                } else {
                    // INSERT/UPDATE/DELETE → use $wpdb->query(), capture rows affected.
                    $wpdb->query($query);
                    $rows_affected = (int) $wpdb->rows_affected;
                    $error         = $wpdb->last_error;
                }

                $runtime_ms = round((microtime(true) - $start) * 1000, 2);
            } else {
                $error = $allowed; // Validation error string.
            }

            // Log into history (even if validation failed, we keep the message).
            $wpdb->insert(
                $table_name,
                array(
                    'query'          => $query,
                    'execution_time' => $runtime_ms,
                    'error'          => $error,
                ),
                array('%s', '%f', '%s')
            );
        }
    }

    // Fetch recent history (latest 10).
    $history = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 10", ARRAY_A);
?>
    <div class="wrap">
        <h1>WP SQL Debugger</h1>

        <form method="post">
            <?php wp_nonce_field('wpsqldebugger_run_query', 'wpsqldebugger_nonce'); ?>
            <textarea name="wpsqldebugger_query" rows="6" style="width:100%; font-family:monospace;"><?php echo esc_textarea($query); ?></textarea>
            <p>
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Run Query', 'wp-sql-debugger'); ?>">
                <span style="margin-left:10px;color:#666;">
                    Allowed: SELECT / INSERT / UPDATE / DELETE. Max 200 rows for SELECT.
                </span>
            </p>
        </form>

        <?php if ($query !== '') : ?>
            <h2><?php esc_html_e('Query', 'wp-sql-debugger'); ?></h2>
            <pre><?php echo esc_html($query); ?></pre>

            <h2><?php esc_html_e('Execution Time', 'wp-sql-debugger'); ?></h2>
            <p><?php echo esc_html($runtime_ms); ?> ms</p>

            <?php if (! empty($error)) : ?>
                <h2 style="color:#b32d2e;"><?php esc_html_e('Error', 'wp-sql-debugger'); ?></h2>
                <p style="color:#b32d2e;"><?php echo esc_html($error); ?></p>

            <?php else : ?>

                <?php if (is_array($result_rows) && ! empty($result_rows)) : ?>
                    <h2><?php printf(esc_html__('Results (%d rows)', 'wp-sql-debugger'), count($result_rows)); ?></h2>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <?php foreach (array_keys($result_rows[0]) as $col) : ?>
                                    <th><?php echo esc_html($col); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result_rows as $row) : ?>
                                <tr>
                                    <?php foreach ($row as $cell) : ?>
                                        <td><?php echo esc_html((string) $cell); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php elseif ($rows_affected !== null) : ?>
                    <h2><?php esc_html_e('Query OK', 'wp-sql-debugger'); ?></h2>
                    <p><?php printf(esc_html__('Rows affected: %d', 'wp-sql-debugger'), $rows_affected); ?></p>

                <?php else : ?>
                    <p><?php esc_html_e('No results.', 'wp-sql-debugger'); ?></p>
                <?php endif; ?>

            <?php endif; ?>
        <?php endif; ?>

        <h2 style="margin-top:30px;"><?php esc_html_e('Query History', 'wp-sql-debugger'); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'wp-sql-debugger'); ?></th>
                    <th><?php esc_html_e('Query', 'wp-sql-debugger'); ?></th>
                    <th><?php esc_html_e('Execution Time (ms)', 'wp-sql-debugger'); ?></th>
                    <th><?php esc_html_e('Error', 'wp-sql-debugger'); ?></th>
                    <th><?php esc_html_e('Created At', 'wp-sql-debugger'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($history) :
                    foreach ($history as $row) :
                ?>
                        <tr>
                            <td><?php echo esc_html($row['id']); ?></td>
                            <td><code style="white-space:pre-wrap;display:block;"><?php echo esc_html($row['query']); ?></code></td>
                            <td><?php echo esc_html($row['execution_time']); ?></td>
                            <td style="color:#b32d2e;"><?php echo esc_html((string) $row['error']); ?></td>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                        </tr>
                <?php
                    endforeach;
                else :
                    echo '<tr><td colspan="5">' . esc_html__('No history yet.', 'wp-sql-debugger') . '</td></tr>';
                endif;
                ?>
            </tbody>
        </table>
    </div>
<?php
}
