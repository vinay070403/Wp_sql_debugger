<?php

/**
 * Plugin Name: WP SQL Debugger
 * Description: Run SQL queries live inside WordPress Admin and debug results with query logs.
 * Version: 1.0.0
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

// Admin Page Content
function wpsqldebugger_admin_page()
{
    global $wpdb;

    $query  = '';
    $result = '';
    $error  = '';
    $runtime = 0;

    if (isset($_POST['wpsqldebugger_query'])) {
        $query = trim(stripslashes($_POST['wpsqldebugger_query']));

        if (! empty($query)) {
            $start = microtime(true);

            // Only allow SELECT / INSERT / UPDATE / DELETE (no DROP/ALTER)
            if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE)/i', $query)) {
                $result = $wpdb->get_results($query, ARRAY_A);
                $error  = $wpdb->last_error;
            } else {
                $error = '⚠️ Only SELECT, INSERT, UPDATE, DELETE queries are allowed.';
            }

            $runtime = round((microtime(true) - $start) * 1000, 2); // ms
        }
    }
?>
    <div class="wrap">
        <h1>WP SQL Debugger</h1>
        <form method="post">
            <textarea name="wpsqldebugger_query" rows="6" style="width:100%; font-family:monospace;"><?php echo esc_textarea($query); ?></textarea>
            <p><input type="submit" class="button button-primary" value="Run Query"></p>
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
    </div>
<?php
}
