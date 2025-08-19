<?php

/**
 * Plugin Name: WP SQL Debugger
 * Description: Run SQL queries live inside WordPress Admin and debug results with query logs.
 * Version: 1.1.0
 * Author: Vinay
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Add Admin Menu
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

// Enqueue CodeMirror for syntax highlighting
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_wp-sql-debugger') {
        return;
    }
    wp_enqueue_code_editor(['type' => 'text/x-sql']);
    wp_enqueue_script('wp-theme-plugin-editor');
    wp_enqueue_style('wp-codemirror');
});

// Admin Page Content
function wpsqldebugger_admin_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'sql_debugger_history';

    $query  = '';
    $result = '';
    $error  = '';
    $runtime = 0;

    // Handle Clear History
    if (isset($_POST['wpsqldebugger_clear'])) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-success"><p>History cleared.</p></div>';
    }

    // Handle Query
    if (isset($_POST['wpsqldebugger_query'])) {
        $query = trim(stripslashes($_POST['wpsqldebugger_query']));

        if (! empty($query)) {
            $start = microtime(true);

            // Allow only safe queries
            if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE)/i', $query)) {
                $result = $wpdb->get_results($query, ARRAY_A);
                $error  = $wpdb->last_error;
            } else {
                $error = '⚠️ Only SELECT, INSERT, UPDATE, DELETE queries are allowed.';
            }

            $runtime = round((microtime(true) - $start) * 1000, 2); // ms
        }

        // Save to history
        $wpdb->insert(
            $table_name,
            array(
                'query'          => $query,
                'execution_time' => $runtime,
                'error'          => $error,
            )
        );
    }

?>
    <div class="wrap">
        <h1>WP SQL Debugger</h1>
        <form method="post">
            <textarea id="wpsqldebugger_query" name="wpsqldebugger_query" rows="6" style="width:100%; font-family:monospace;"><?php echo esc_textarea($query); ?></textarea>
            <p>
                <input type="submit" class="button button-primary" value="Run Query">
                <button type="submit" name="wpsqldebugger_clear" value="1" class="button button-secondary" onclick="return confirm('Clear all history?');">Clear History</button>
            </p>
        </form>

        <?php if (! empty($query)): ?>
            <h2>Query:</h2>
            <pre><?php echo esc_html($query); ?></pre>

            <h2>Execution Time:</h2>
            <p><?php echo esc_html($runtime); ?> ms</p>

            <?php if (! empty($error)): ?>
                <h2 style="color:red;">Error:</h2>
                <p><?php echo esc_html($error); ?></p>
            <?php elseif (is_array($result)): ?>
                <h2>Results (<?php echo count($result); ?> rows):</h2>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <?php if (! empty($result[0])): ?>
                                <?php foreach (array_keys($result[0]) as $col): ?>
                                    <th><?php echo esc_html($col); ?></th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?php echo esc_html($cell); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <h2>Query History</h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Query</th>
                    <th>Execution Time (ms)</th>
                    <th>Error</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $history = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 10", ARRAY_A);
                if ($history) :
                    foreach ($history as $row) :
                ?>
                        <tr>
                            <td><?php echo esc_html($row['id']); ?></td>
                            <td><code><?php echo esc_html($row['query']); ?></code></td>
                            <td><?php echo esc_html($row['execution_time']); ?></td>
                            <td style="color:red;"><?php echo esc_html($row['error']); ?></td>
                            <td><?php echo esc_html($row['created_at']); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="wpsqldebugger_query" value="<?php echo esc_attr($row['query']); ?>">
                                    <button type="submit" class="button">Run Again</button>
                                </form>
                            </td>
                        </tr>
                <?php
                    endforeach;
                else :
                    echo '<tr><td colspan="6">No history yet.</td></tr>';
                endif;
                ?>
            </tbody>
        </table>
    </div>

    <script>
        jQuery(function($) {
            if (wp.codeEditor) {
                wp.codeEditor.initialize($('#wpsqldebugger_query'), {
                    type: 'text/x-sql'
                });
            }
        });
    </script>
<?php
}

// Create table on activation
register_activation_hook(__FILE__, 'wpsqldebugger_create_table');
function wpsqldebugger_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'sql_debugger_history';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        query TEXT NOT NULL,
        execution_time FLOAT DEFAULT 0,
        error TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
