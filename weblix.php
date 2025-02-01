<?php
/**
 * Plugin Name: Weblix - Online Users
 * Description: Display online users and page views in the last 30 minutes.
 * Version: 1.3
 * Author: Vahid Behnam
 * Text Domain: weblix
 * Domain Path: /languages
 * Requires at least: 5.3
 * Requires PHP: 7.2
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Create database table on plugin activation
function weblix_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'weblix';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_ip VARCHAR(45) NOT NULL,
        page_url TEXT NOT NULL,
        visit_time DATETIME NOT NULL,
        device_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
        is_bot TINYINT(1) NOT NULL DEFAULT 0,
        page_title TEXT DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'weblix_create_table');


// Remove database table on plugin deactivation
function weblix_remove_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'weblix';
	$wpdb->query("DROP TABLE IF EXISTS " . esc_sql($table_name) . " "); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
register_uninstall_hook(__FILE__, 'weblix_remove_table');

function weblix_get_device_type() {
    // Check if the 'HTTP_USER_AGENT' key exists in $_SERVER
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return 'Unknown';
    }

    // Retrieve the user agent, remove slashes, and sanitize
    $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
    $user_agent = strtolower($user_agent);

    // Regular expressions for detecting device types
    $mobile_regex = '/(android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile)/i';
    $tablet_regex = '/(tablet|ipad|playbook|silk)/i';
    $desktop_regex = '/(windows|macintosh|linux|cros|x11)/i';

    if (preg_match($mobile_regex, $user_agent)) {
        return 'Mobile';
    } elseif (preg_match($tablet_regex, $user_agent)) {
        return 'Tablet';
    } elseif (preg_match($desktop_regex, $user_agent)) {
        return 'Desktop';
    } else {
        return 'Unknown';
    }
}


function weblix_track_page_view() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'weblix';
    $user_ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $user_ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])))[0]);
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $user_ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $user_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
    $user_ip = sanitize_text_field($user_ip);
    $page_url = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
    $page_title = isset($_POST['page_title']) ? sanitize_text_field(wp_unslash($_POST['page_title'])) : 'Untitled Page';
    $visit_time = current_time('mysql', 1);
    $device_type = weblix_get_device_type();
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $bot_keywords = 'bot|crawl|slurp|spider|mediapartners|google|bing|yahoo|duckduck|baidu|yandex|facebot|ia_archiver|mj12bot|semrush|ahrefs|dotbot|moobot';
    $is_bot = preg_match("/$bot_keywords/i", $user_agent) ? 1 : 0;
    
    if (!isset($_POST['nonce'])) {
        wp_send_json_error('Nonce is missing.');
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));

    if (!wp_verify_nonce($nonce, 'weblix_track_page_view_nonce')) {
        wp_send_json_error('Invalid nonce.');
    }

    // Check for previous visits within a 30-minute window
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing_visit = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}weblix WHERE user_ip = %s AND page_url = %s AND visit_time >= %s",
            $user_ip, $page_url, gmdate('Y-m-d H:i:s', strtotime('-30 minutes', strtotime($visit_time)))
        )
    );

    if ($existing_visit == 0) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table_name,
            array(
                'user_ip'    => sanitize_text_field($user_ip),
                'page_url'   => esc_url_raw($page_url),
                'visit_time' => sanitize_text_field($visit_time),
                'device_type'=> sanitize_text_field($device_type),
                'is_bot'     => intval($is_bot),
                'page_title' => sanitize_text_field($page_title),
            ),
            array(
                '%s', // user_ip
                '%s', // page_url
                '%s', // visit_time
                '%s', // device_type
                '%d', // is_bot
                '%s', // page_title
            )
        );
    }

    wp_send_json_success('Page view tracked.');
}

add_action('wp_ajax_track_page_view', 'weblix_track_page_view');
add_action('wp_ajax_nopriv_track_page_view', 'weblix_track_page_view');

function weblix_enqueue_scripts() {
    // Enqueue the script with jQuery as a dependency
    wp_enqueue_script(
        'weblix-ajax',
        plugin_dir_url(__FILE__) . 'assets/js/weblix-tracker.js',
        ['jquery'],
        '1.0',
        true
    );

    // Localize the script with additional data
    wp_localize_script('weblix-ajax', 'weblix_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('weblix_track_page_view_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'weblix_enqueue_scripts');

// Handle AJAX requests for logged-in and non-logged-in users
add_action('wp_ajax_weblix_handle_ajax', 'weblix_handle_ajax');
add_action('wp_ajax_nopriv_weblix_handle_ajax', 'weblix_handle_ajax');

function weblix_handle_ajax() {
    // Prevent caching of AJAX responses
    header("Cache-Control: no-cache, must-revalidate, max-age=0");
    header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");
    header("Pragma: no-cache");

    // Process the AJAX request
    $response = [
        'status'  => 'success',
        'message' => 'AJAX response successfully handled',
    ];

    // Send JSON response
    wp_send_json_success($response);
}

function weblix_add_dashboard_page() {
    add_menu_page(
        __('Online Users and Page Views', 'weblix'),
        __('Online Users', 'weblix'),
        'manage_options',
        'weblix',
        'weblix_display_dashboard',
        'dashicons-chart-bar',
        2
    );
}
add_action('admin_menu', 'weblix_add_dashboard_page');

function weblix_display_dashboard() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'weblix';
    $time_limit = current_time('mysql', 1);
    $time_limit = gmdate('Y-m-d H:i:s', strtotime('-30 minutes', strtotime($time_limit)));

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $weblix = $wpdb->get_results(
        $wpdb->prepare("SELECT DISTINCT user_ip FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 0", $time_limit)
    );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $bot_users = $wpdb->get_results(
        $wpdb->prepare("SELECT DISTINCT user_ip FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 1", $time_limit)
    );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $device_counts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT device_type, COUNT(DISTINCT user_ip) AS total_users 
             FROM " . esc_sql($table_name) . " 
             WHERE visit_time >= %s AND is_bot = 0
             GROUP BY device_type", 
            $time_limit
        )
    );

    // Visited Pages with Titles
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $page_views = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT page_url, page_title, COUNT(*) AS total_views 
             FROM " . esc_sql($table_name) . " 
             WHERE visit_time >= %s 
             GROUP BY page_url, page_title 
             ORDER BY total_views DESC 
             LIMIT 100", 
            $time_limit
        )
    );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $total_views = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($table_name) . " WHERE visit_time >= %s", $time_limit)
    );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $total_bot_views = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 1", $time_limit)
    );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $unique_user_count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(DISTINCT user_ip) FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 0", $time_limit)
    );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $unique_bot_count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(DISTINCT user_ip) FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 1", $time_limit)
    );

    ?>
    <div class="wrap">
<h1><?php esc_html_e('Online Users and Page Views', 'weblix'); ?></h1>
<h2><?php esc_html_e('Unique Visitors (Last 30 Minutes)', 'weblix'); ?></h2>
<table class="wp-list-table widefat fixed striped table-view-list">
    <thead>
        <tr>
            <th><?php esc_html_e('Unique Visitors', 'weblix'); ?></th>
            <th><?php esc_html_e('Unique Bot Visitors', 'weblix'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?php echo intval($unique_user_count); ?></td>
            <td><?php echo intval($unique_bot_count); ?></td>
        </tr>
    </tbody>
</table>

<h2><?php esc_html_e('Total Page Views (Last 30 Minutes)', 'weblix'); ?></h2>
<table class="wp-list-table widefat fixed striped table-view-list">
    <thead>
        <tr>
            <th><?php esc_html_e('Total Views', 'weblix'); ?></th>
            <th><?php esc_html_e('Total Bot Views', 'weblix'); ?></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><?php echo intval($total_views); ?></td>
            <td><?php echo intval($total_bot_views); ?></td>
        </tr>
    </tbody>
</table>

<h2><?php esc_html_e('Number of Users by Device Type (Last 30 Minutes)', 'weblix'); ?></h2>
<table class="wp-list-table widefat fixed striped table-view-list">
    <thead>
        <tr>
            <th><?php esc_html_e('Device Type', 'weblix'); ?></th>
            <th><?php esc_html_e('Number of Users', 'weblix'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($device_counts)): ?>
            <?php foreach ($device_counts as $device): ?>
                <tr>
                    <td><?php echo esc_html($device->device_type); ?></td>
                    <td><?php echo intval($device->total_users); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="2"><?php esc_html_e('No users recorded.', 'weblix'); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<h2><?php esc_html_e('Page Views (Last 30 Minutes)', 'weblix'); ?></h2>
<table class="wp-list-table widefat fixed striped table-view-list">
    <thead>
        <tr>
            <th width="50%"><?php esc_html_e('Page URL', 'weblix'); ?></th>
            <th width="25%"><?php esc_html_e('Total Views', 'weblix'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($page_views)): ?>
            <?php foreach ($page_views as $page): ?>
                <tr>
                    <td>
                        <a href='<?php echo esc_html($page->page_url); ?>' target='_blank'>
                            <?php echo esc_html($page->page_title); ?>
                        </a>
                    </td>
                    <td><?php echo intval($page->total_views); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="2"><?php esc_html_e('No views recorded.', 'weblix'); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

    <?php
}
// Add Widget to WordPress Dashboard
function weblix_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'weblix_dashboard_widget',
        __('Weblix - Online Users (Last 30 Minutes)', 'weblix'),
        'weblix_dashboard_widget_display'
    );
}
add_action('wp_dashboard_setup', 'weblix_add_dashboard_widget');

// Display Widget Content
function weblix_dashboard_widget_display() {
echo '<div id="weblix-dashboard">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <p>' . esc_html__('Total Views:', 'weblix') . ' <span id="weblix-views-count">0</span></p>
            <p>' . esc_html__('DESKTOP:', 'weblix') . ' <span id="weblix-desktop-count">0</span></p>
            <p>' . esc_html__('MOBILE:', 'weblix') . ' <span id="weblix-mobile-count">0</span></p>
            <p>' . esc_html__('TABLET:', 'weblix') . ' <span id="weblix-tablet-count">0</span></p>
        </div>
        <div style="font-size: 100px; font-weight: bold; color: #f77149; padding: 40px 0 40px 0;" id="weblix-user-count">0</div>
    </div>
    <div id="current-pages" class="avt-section">
        <h4 style="font-family: Tahoma,Arial,sans-serif;font-size: bold;font-weight: bold;">' . esc_html__('Most Visited Pages', 'weblix') . '</h4>
        <table id="weblix-pages-table" class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th width="12%">' . esc_html__('Views', 'weblix') . '</th>
                    <th width="86%">' . esc_html__('Page URL', 'weblix') . '</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="2">' . esc_html__('No data available', 'weblix') . '</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>';
}

// Create REST API for Dashboard Data
function weblix_register_rest_route() {
    register_rest_route('weblix/v1', '/dashboard-data', array(
        'methods' => 'GET',
        'callback' => 'weblix_get_dashboard_data',
        'permission_callback' => function() {
            return current_user_can('manage_options'); // Access restricted to administrators
        },
    ));
}
add_action('rest_api_init', 'weblix_register_rest_route');

// Function to Retrieve Dashboard Data
function weblix_get_dashboard_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'weblix';
    $time_limit = current_time('mysql', 1);
    $time_limit = gmdate('Y-m-d H:i:s', strtotime('-30 minutes', strtotime($time_limit)));

    // Unique Users
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $unique_user_count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(DISTINCT user_ip) FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 0", $time_limit)
    );

    // Total Views
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $total_views = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($table_name) . " WHERE visit_time >= %s", $time_limit)
    );

    // Users by Device Type
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $device_counts = $wpdb->get_results(
        $wpdb->prepare("SELECT device_type, COUNT(DISTINCT user_ip) as count FROM " . esc_sql($table_name) . " WHERE visit_time >= %s AND is_bot = 0 GROUP BY device_type", $time_limit)
    );
    $device_stats = [
        'Desktop' => 0,
        'Mobile' => 0,
        'Tablet' => 0,
    ];
    foreach ($device_counts as $device) {
        $device_stats[$device->device_type] = $device->count;
    }

    // Visited Pages with Titles
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $page_views = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT page_url, page_title, COUNT(*) AS total_views 
             FROM " . esc_sql($table_name) . " 
             WHERE visit_time >= %s 
             GROUP BY page_url, page_title 
             ORDER BY total_views DESC 
             LIMIT 100", 
            $time_limit
        )
    );

    // Set Cache Headers
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('Pragma: no-cache');

    return [
        'unique_users' => intval($unique_user_count),
        'total_views' => intval($total_views),
        'device_stats' => $device_stats,
        'pages' => $page_views,
    ];
}

// Enqueue JavaScript File
function weblix_enqueue_dashboard_scripts() {
    wp_enqueue_script(
        'weblix-dashboard-refresh', 
        plugin_dir_url(__FILE__) . 'assets/js/dashboard-refresh.js', 
        array('jquery'), 
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/dashboard-refresh.js'), // Set version based on file modification time
        true
    );

    wp_localize_script('weblix-dashboard-refresh', 'weblix_ajax_object', array(
        'api_url' => rest_url('weblix/v1/dashboard-data'),
        'nonce' => wp_create_nonce('wp_rest')
    ));
}
add_action('admin_enqueue_scripts', 'weblix_enqueue_dashboard_scripts');

// Function to delete old records (older than 7 days)
function weblix_delete_old_visitors() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'weblix';
    $current_time = current_time('mysql');

    // Delete records where the visit time is older than 7 days
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query($wpdb->prepare(
        "DELETE FROM " . esc_sql($table_name) . " WHERE visit_time < DATE_SUB(%s, INTERVAL 7 DAY)",
        $current_time
    ));
}

// Schedule a daily cron job for cleanup
function weblix_schedule_daily_cleanup() {
    // Check if the daily cleanup job is already scheduled
    if (!wp_next_scheduled('weblix_cleanup_old_visitors')) {
        wp_schedule_event(time(), 'daily', 'weblix_cleanup_old_visitors');
    }
}
add_action('wp', 'weblix_schedule_daily_cleanup');

// Hook the cleanup function to the scheduled event
add_action('weblix_cleanup_old_visitors', 'weblix_delete_old_visitors');