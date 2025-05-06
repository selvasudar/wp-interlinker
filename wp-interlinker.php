<?php
/*
Plugin Name: WP Interlinker
Plugin URI: 
Description: WP Interlinker is an advanced WordPress plugin designed to enhance your site's internal linking structure. It processes your sitemap to identify potential internal linking opportunities and suggests relevant connections between your content. Key features include:
* Automated sitemap processing and keyword extraction
* Smart internal link suggestions based on content relevance
* Bulk management of internal linking opportunities
* Real-time link placement through an intuitive interface
* Automated monitoring of linking progress
* Detailed reporting of successful and failed URL processing
* Integration with Dropbox for secure data handling
* Custom email validation and MySQL data storage
* Configurable 6-hour processing window with automated cleanup
* Easy-to-use admin interface for managing all aspects of interlinking

This plugin helps improve your site's SEO by creating meaningful internal links between related content, enhancing user navigation, and strengthening your site's overall link structure.
Version: 2.0
Requires at least: 5.0
Requires PHP: 7.2
Author: Piperocket
Author URI: https://piperocket.digital/
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wp-interlinker
Domain Path: /languages
*/

// Constants should be defined before they're used
define('TOKEN_URL', "https://api.dropboxapi.com/oauth2/token"); 
define('APP_KEY', 'ez3emz94jvpjycc');     
define('APP_SECRET', 'e8caieekoua1h2m'); 
define('REFRESH_TOKEN', "4LHFRxBleq0AAAAAAAAAAXpRLG7yd-FVc9WNQtKf7q-M9gMpfIYMd27HcEXUwfa4"); 
// define('CORE_ENDPOINT','https://us-central1-gen-lang-client-0958333832.cloudfunctions.net/process_sitemap/process-sitemap');
define('CORE_ENDPOINT','https://34.68.109.208/process-sitemap');



$accessToken = getAccessToken();

if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
} else {
    // Handle missing vendor directory gracefully
    function wp_interlinker_admin_notice_composer() {
        ?>
        <div class="notice notice-error">
            <p>WP Interlinker: Composer dependencies are missing. Please run composer install in the plugin directory.</p>
        </div>
        <?php
    }
    add_action('admin_notices', 'wp_interlinker_admin_notice_composer');
    return; // Prevent further execution
}

use Kunnu\Dropbox\Dropbox;
use Kunnu\Dropbox\DropboxApp;

// Activation hook to create database table
register_activation_hook(__FILE__, 'wp_interlinker_create_tables');
// register_activation_hook( __FILE__, 'wp_interlinker_create_table' );
add_action('admin_menu', 'wp_interlinker_menu');
add_action('admin_notices', 'wp_interlinker_admin_notices');
add_action('admin_enqueue_scripts', 'my_plugin_enqueue_admin_assets');


function wp_interlinker_schedule_cron() {
    if (class_exists('ActionScheduler')) {
        // Schedule the existing keywords.json task
        if (!as_next_scheduled_action('move_json_folder_action')) {
            as_schedule_recurring_action(
                time(),
                300, // Every 2 hours
                'move_json_folder_action',
                array()
            );
        }
        
        // Schedule the new interlink_data.json task - only if not already completed
        $interlink_complete = get_option('interlink_data_transfer_complete', false);        
        if (!as_next_scheduled_action('move_interlink_data_action')) {
            as_schedule_recurring_action(
                time(),
                300, // Every 2 hours
                'move_interlink_data_action',
                array()
            );
        }
    }
}
add_action('init', 'wp_interlinker_schedule_cron');


function my_plugin_enqueue_admin_assets($hook) {
    // Enqueue CSS file
    $css_file_url = plugin_dir_url(__FILE__) . 'assets/css/admin-style.css';
    wp_enqueue_style('my-plugin-admin-style', $css_file_url, [], '1.0.0', 'all');

    // Enqueue JS file
    wp_enqueue_script(
        'wp-interlinker-script',
        plugin_dir_url(__FILE__) . 'assets/js/wp-interlinker.js',
        ['jquery'],
        '1.0.0',
        true // Load in the footer
    );

    // Add the ajaxurl and nonce to your script
    wp_localize_script('wp-interlinker-script', 'wpInterlinkerData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp_interlinker_nonce'),
    ]);
}


function getAccessToken() {
    // Request payload for token refresh
    $payload = array(
        'grant_type' => 'refresh_token',
        'refresh_token' => REFRESH_TOKEN
    );

    // Initialize cURL session
    $ch = curl_init(TOKEN_URL);

    // Set cURL options
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Basic ' . base64_encode(APP_KEY . ':' . APP_SECRET)
    ));

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($ch);

    if ($httpCode === 200) {
        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'];
        $expiresIn = $tokenData['expires_in'];
        return $accessToken;
    } else {
        echo "Error: $httpCode - $response";
        return null;
    }
}

//1. While Activation, creates a table
function wp_interlinker_create_tables() {
    global $wpdb;
    // Define table names
    $table_name = $wpdb->prefix . 'interlinker_uploads';
    // Set the charset and collation for the tables
    $charset_collate = $wpdb->get_charset_collate();
    // SQL for uploads table
    $sql1 = "CREATE TABLE $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,                
        email VARCHAR(100) NOT NULL,        
        uploaded_time DATETIME DEFAULT CURRENT_TIMESTAMP,        
        post_status TINYINT(1) DEFAULT 0,
        keyword_status TINYINT(1) DEFAULT 0,
        sitemap_url VARCHAR(255) NOT NULL,        
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    // dbDelta([$sql1]);
    dbDelta($sql1);
}

//2. Registers Menu on the left pane
function wp_interlinker_menu() {
    // Main menu page
    add_menu_page(
        'WP Interlinker', // Page title
        'WP Interlinker', // Menu title
        'manage_options', // Capability
        'wp-interlinker', // Menu slug
        'wp_interlinker_render_settings_page', // Function to display the page
        'dashicons-admin-generic', // Icon (you can choose a different icon)
        6 // Position in the menu
    );  
    add_submenu_page(
        'wp-interlinker',  // The slug for the main plugin page
        'Sitemap Keywords', // The page title
        'Sitemap Keywords', // The menu title
        'manage_options',   // Capability required
        'sitemap_keywords', // The submenu slug
        'render_sitemap_keywords_page' // The function to display the page
    );   
    // Add new submenu for interlink suggestions
    add_submenu_page(
        'wp-interlinker',
        'Interlink Suggestions',
        'Interlink Suggestions',
        'manage_options',
        'interlink-suggestions',
        'render_interlink_suggestions_page'
    );
}


// Register the AJAX handler
add_action('wp_ajax_handle_sitemap_submission', 'handle_sitemap_submission');

function handle_sitemap_submission() {
    // Verify nonce
    check_ajax_referer('wp_interlinker_nonce', 'nonce');
    
    // Get and sanitize the sitemap URL
    $sitemap_url = isset($_POST['sitemap_url']) ? esc_url_raw($_POST['sitemap_url']) : '';
    
    if (empty($sitemap_url)) {
        wp_send_json_error([
            'message' => 'Please provide a valid sitemap URL.'
        ]);
    }
    
    // Send to API and handle the response
    $api_response = send_sitemap_to_api($sitemap_url, $core_endpoint);
    
    if ($api_response['success']) {
        // Insert into database
        global $wpdb;
        $table_name = $wpdb->prefix . 'interlinker_uploads';
        $current_user = wp_get_current_user();
        
        $insert_result = $wpdb->insert(
            $table_name,
            [
                'email' => sanitize_email($current_user->user_email),
                'sitemap_url' => $sitemap_url,
                'uploaded_time' => current_time('mysql'),
                'post_status' => 0,
                'keyword_status' => 0
            ]
        );
        
        if ($insert_result === false) {
            wp_send_json_error([
                'message' => 'Database error occurred while saving data.'
            ]);
        }
        
        // Set up the cron job
        
        
        // Send success response
        wp_send_json_success([
            'message' => 'Sitemap submitted successfully! Processing will take approximately 6 hours.'
        ]);
    } else {
        wp_send_json_error([
            'message' => $api_response['message']
        ]);
    }
}

// Modify your form rendering function to include the nonce
function wp_interlinker_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>WP Interlinker</h1>
        <form method="post" id="wp-interlinker-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wp_interlinker_nonce', 'wp_interlinker_nonce'); ?>
            <input type="url" id="sitemap_url" name="sitemap_url" placeholder="Enter sitemap url" required>
            <div id="sitemap-error" style="color: red; display: none;">Sitemap URL must belong to the current domain.</div>
            <input type="submit" name="wp_interlinker_submit" value="Submit">
        </form>
        <span>Powered by <a href="https://piperocket.digital/">Piperocket</a></span>
    </div>
    <?php
}

function send_sitemap_to_api($sitemap_url) {    
    // Validate input parameters
    if (empty($sitemap_url) || empty(CORE_ENDPOINT)) {
        error_log('WP Interlinker: Missing required parameters for API request');
        return [
            'success' => false,
            'message' => 'Missing required parameters',
            'code' => 'INVALID_PARAMS'
        ];
    }

    // Prepare the request
    $args = [
        'body' => json_encode(['sitemap_url' => $sitemap_url]),
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ],
        'timeout' => 45,
        'sslverify' => false
    ];

    // Attempt the API request
    try {
        $response = wp_remote_post(CORE_ENDPOINT, $args);

        // Check for WordPress HTTP API errors
        if (is_wp_error($response)) {
            error_log('WP Interlinker API Error: ' . $response->get_error_message());
            return [
                'success' => false,
                'message' => 'Failed to connect to API: ' . $response->get_error_message(),
                'code' => 'CONNECTION_ERROR'
            ];
        }

        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the response for debugging
        error_log('WP Interlinker API Response: ' . $response_code . ' - ' . $response_body);

        // Decode JSON response
        $body = json_decode($response_body, true);
        
        // Check for JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('WP Interlinker: JSON decode error - ' . json_last_error_msg());
            return [
                'success' => false,
                'message' => 'Invalid API response format',
                'code' => 'INVALID_RESPONSE'
            ];
        }

        // Check for successful response
        if ($response_code === 200 && isset($body['StatusCode']) && $body['StatusCode'] === 200) {
            return [
                'success' => true,
                'message' => 'Sitemap successfully processed',
                'data' => $body
            ];
        } else {
            // Log specific error details
            $error_message = isset($body['message']) ? $body['message'] : 'Unknown error';
            error_log('WP Interlinker: API returned error - ' . $error_message);
            
            return [
                'success' => false,
                'message' => 'API Error: ' . $response_body,
                'code' => 'API_ERROR',
                'status' => $response_code,
                'response_body' => $response_body
            ];
        }

    } catch (Exception $e) {
        error_log('WP Interlinker: Exception - ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Internal error occurred',
            'code' => 'INTERNAL_ERROR'
        ];
    }
}

//5. Shows the notice bar
function wp_interlinker_admin_notices() {
    if (get_transient('wp_interlinker_processing')) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>WP Interlinker:</strong> Your sitemap is being processed. It will take 6 hours.</p>
        </div>
        <?php
    }
    
    $notice = get_transient('wp_interlinker_notice');
    if ($notice === 'error') {
        echo '<div class="notice notice-error is-dismissible"><p>There is a problem with Settings. Contact your administrator for help.</p></div>';
    }
    delete_transient('wp_interlinker_notice');
}

function move_json_folder() {
    
    // Define Dropbox credentials
    $app_key = 'APP_KEY'; // Replace with your app key
    $app_secret = 'APP_SECRET'; // Replace with your app secret
    $access_token = getAccessToken(); // Replace with your access token
    
    // Define file paths
    $dropbox_file_path = '/interlinker/keywords.json'; // Full path to file on Dropbox
    
    // Get the WordPress uploads directory
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/interlinker'; // Create a subfolder for organization
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        wp_mkdir_p($target_dir);
    }
    
    $target_path = $target_dir . '/keywords.json';
    
    // Backup existing file if it exists
    if (file_exists($target_path)) {
        $timestamp = date('Y-m-d_H-i-s');
        $backup_path = $target_dir . '/keywords_backup_' . $timestamp . '.json';
        // rename($target_path, $backup_path);
        error_log("Existing file backed up to: $backup_path");
    }
    
    // Fetch the file from Dropbox to the target location
    if (fetch_file_from_dropbox($dropbox_file_path, $target_path, $app_key, $app_secret, $access_token)) {
        error_log("File successfully moved to: $target_path");
        echo "File successfully moved to: $target_path";
        // Update the database status if needed
        global $wpdb;
        $table_name = $wpdb->prefix . 'interlinker_uploads';
        $result = $wpdb->update(
            $table_name,
            array('keyword_status' => 1),
            array('keyword_status' => 0),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            error_log("Database updated successfully. Rows affected: $result");
        } else {
            error_log("Database update failed or no rows needed updating.");
        }
        
        return true;
    } else {
        error_log("Failed to fetch keywords.json from Dropbox.");
        return false;
    }
}

add_action( 'move_json_folder_action', 'move_json_folder' );

// Function to handle the interlink_data.json file
function move_interlink_data_file() {
    // Define Dropbox credentials
    $app_key = APP_KEY;
    $app_secret = APP_SECRET;
    $access_token = getAccessToken(); // Replace with your access token
    
    // Define file paths
    $dropbox_file_path = '/interlinker/interlink_data.json'; // Path on Dropbox
    
    // Get the WordPress uploads directory
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/interlinker';
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        wp_mkdir_p($target_dir);
    }
    
    $target_path = $target_dir . '/interlink_data.json';
    
    // Fetch the file from Dropbox to the target location
    if (fetch_file_from_dropbox($dropbox_file_path, $target_path, $app_key, $app_secret, $access_token)) {
        error_log("Interlink data file successfully moved to: $target_path");
        
        // Set the option to indicate this task is complete
        update_option('interlink_data_transfer_complete', true);
        
        // Cancel the scheduled action since it's completed
        as_unschedule_all_actions('move_interlink_data_action');
        
        return true;
    } else {
        error_log("Failed to fetch interlink_data.json from Dropbox.");
        return false;
    }
}

// Hook the function to the scheduled action
add_action('move_interlink_data_action', 'move_interlink_data_file');

/**
 * Fetch a file from Dropbox and save it to a local path
 * 
 * @param string $dropbox_file_path Full path to file on Dropbox
 * @param string $local_file_path Path where file should be saved locally
 * @param string $app_key Dropbox App Key
 * @param string $app_secret Dropbox App Secret
 * @param string $access_token Dropbox Access Token
 * @return boolean Success or failure
 */
function fetch_file_from_dropbox($dropbox_file_path, $local_file_path, $app_key, $app_secret, $access_token) {
    try {
        // Include required files if not already included
        if (!class_exists('Kunnu\Dropbox\Dropbox')) {
            require_once WP_PLUGIN_DIR . '/wp-interlinker/vendor/autoload.php';
        }
        
        // Initialize Dropbox App and Client
        $app = new \Kunnu\Dropbox\DropboxApp($app_key, $app_secret, $access_token);
        $dropbox = new \Kunnu\Dropbox\Dropbox($app);
        
        // Download the file from Dropbox
        $file = $dropbox->download($dropbox_file_path);
        
        // Write file contents to the local file path
        file_put_contents($local_file_path, $file->getContents());
        
        if (file_exists($local_file_path)) {
            error_log("File saved successfully to: $local_file_path");
            return true;
        } else {
            error_log("File save operation completed but file does not exist at: $local_file_path");
            return false;
        }
    } catch (Exception $e) {
        echo "problem in File not fetched";
        die();
        // Log the exception message
        error_log("Error fetching file from Dropbox: " . $e->getMessage());
        return false;
    }
}

//7.Converts JSON to HTML table to show Primary keyword and URL
function render_sitemap_keywords_page() {
    ?>
    <div class="wrap table-wrapper">
        <h1>Sitemap Keywords</h1>
        <h2 class="nav-tab-wrapper">
            <a href="#success_urls_tab" class="nav-tab nav-tab-active">Successful URLs</a>
            <a href="#failed_urls_tab" class="nav-tab">Failed URLs</a>
        </h2>

        <div id="success_urls_tab" class="tab-content" style="display: block;">
            <?php display_sitemap_urls_table('success'); ?>
        </div>

        <div id="failed_urls_tab" class="tab-content" style="display: none;">
            <?php display_sitemap_urls_table('failed'); ?>
        </div>
    </div>
    <?php
}

//7.1 . Displays the table of successful or failed URLs
function display_sitemap_urls_table($type) {
    $file_location = ABSPATH . "wp-content/uploads/interlinker/keywords.json";
    if (file_exists($file_location)) {
        $json_data = file_get_contents($file_location);
        $data = json_decode($json_data, true);

        if ($data === null) {
            echo 'Failed to parse JSON: ' . json_last_error_msg();
            return;
        }

        $urls = $type === 'success' ? $data['success_urls'] : $data['failed_urls'];

        echo '<table class="wp-list-table widefat fixed striped table-view-list pages">';
        echo '<thead>';
        echo '<tr><th>S.No</th><th>Primary Keyword</th><th>URL</th></tr>';
        echo '</thead><tbody>';

        $count = 1;
        foreach ($urls as $url) {
            $primary_keyword = isset($url['primary_keyword']) ? esc_html($url['primary_keyword']) : 'N/A';
            $actual_url = isset($url['url']) ? esc_url($url['url']) : '#';            
            echo '<tr>';
            echo '<td>' . $count . '</td>';
            echo '<td>' . $primary_keyword . '</td>';
            echo '<td><a href="' . $actual_url . '" target="_blank">' . $actual_url . '</a></td>';
            echo '</tr>';
            $count++;
        }

        echo '</tbody></table>';
    } else {
        echo 'File not found at: ' . esc_html($file_location);
    }
}

// 2. Add metabox to post edit screen
add_action('add_meta_boxes', 'add_interlink_suggestions_metabox');
function add_interlink_suggestions_metabox() {
    add_meta_box(
        'interlink_suggestions',
        'Interlink Suggestions',
        'render_interlink_suggestions_metabox',
        'post', // Change this if you need it for other post types
        'normal',
        'high'
    );
}
function render_interlink_suggestions_metabox($post) {
    // Get current post URL
    $current_url = get_permalink($post->ID);
    
    // Get suggestions from JSON
    $suggestions = get_interlink_suggestions($current_url);
    
    // Debugging output
    echo '<p><strong>Debug Info:</strong></p>';
    echo '<p>Current Post URL: ' . esc_html($current_url) . '</p>';
    echo '<p>Suggestions Found: ' . count($suggestions) . '</p>';
    
    if (empty($suggestions)) {
        echo '<p>No interlink suggestions found for this post.</p>';
        return;
    }
    
    // Add nonce for security
    wp_nonce_field('interlink_action', 'interlink_nonce');
    
    echo '<div class="interlink-suggestions-wrapper">';
    foreach ($suggestions as $index => $suggestion) {
        ?>
        <div class="suggestion-item" data-index="<?php echo esc_attr($index); ?>">
            <h4>Keyword: <?php echo esc_html($suggestion['keyword']); ?></h4>
            <p>Sentence: <?php echo esc_html($suggestion['sentence']); ?></p>
            <p>Position: <?php echo esc_html($suggestion['position']); ?></p>
            <p>Source: <a href="<?php echo esc_url($suggestion['source_url']); ?>" target="_blank">
                <?php echo esc_url($suggestion['source_url']); ?>
            </a></p>
            <div class="suggestion-actions">
                <button type="button" class="button button-primary approve-link" 
                    data-sentence="<?php echo esc_attr($suggestion['sentence']); ?>"
                    data-position="<?php echo esc_attr($suggestion['position']); ?>"
                    data-source="<?php echo esc_attr($suggestion['source_url']); ?>"
                    data-post-id="<?php echo esc_attr($post->ID); ?>">
                    Approve
                </button>
                <button type="button" class="button ignore-link">Ignore</button>
            </div>
        </div>
        <hr>
        <?php
    }
    echo '</div>';
}

function get_interlink_suggestions($url) {
    // Update to correct file path
    $json_file = WP_CONTENT_DIR . '/uploads/interlinker/interlink_data.json';
    
    if (!file_exists($json_file)) {
        error_log("Interlink suggestions JSON file not found at: $json_file");
        return array();
    }
    
    $json_data = json_decode(file_get_contents($json_file), true);
    
    if ($json_data === null) {
        error_log("Failed to parse interlink_data.json: " . json_last_error_msg());
        return array();
    }
    
    // Filter suggestions where target_url matches the current post URL
    $matching_suggestions = array_filter($json_data, function($suggestion) use ($url) {
        return isset($suggestion['target_url']) && $suggestion['target_url'] === $url;
    });
    
    // Reindex the array to ensure numeric keys
    return array_values($matching_suggestions);
}
function render_interlink_suggestions_page() {
    // Get all suggestions from JSON
    $json_file = WP_CONTENT_DIR . '/uploads/interlinker/interlink_data.json';
    
    if (!file_exists($json_file)) {
        echo '<div class="notice notice-error"><p>No suggestion data found.</p></div>';
        return;
    }
    
    $json_data = json_decode(file_get_contents($json_file), true);    
    ?>
    <div class="wrap">
        <h1>Interlink Suggestions</h1>
        <div class="tablenav top">
            <!-- Add any filters or search functionality here if needed -->
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Target URL</th>
                    <th>Keyword</th>
                    <th>Sentence</th>
                    <th>Source URL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($json_data as $target_url => $suggestions): ?>
                    <?php foreach ($suggestions as $suggestion): ?>
                        <tr>
                            <td><a href="<?php echo esc_url($target_url); ?>" target="_blank"><?php echo esc_url($target_url); ?></a></td>
                            <td><?php echo esc_html($suggestion['keyword']); ?></td>
                            <td><?php echo esc_html($suggestion['sentence']); ?></td>
                            <td><a href="<?php echo esc_url($suggestion['source_url']); ?>" target="_blank"><?php echo esc_url($suggestion['source_url']); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action('admin_head', 'add_interlink_suggestions_styles');
function add_interlink_suggestions_styles() {
    ?>
    <style>
        .suggestion-item {
            padding: 10px;
            margin-bottom: 10px;
            background: #f9f9f9;
            border-left: 4px solid #0073aa;
        }
        .suggestion-actions {
            margin-top: 10px;
        }
        .suggestion-actions .button {
            margin-right: 10px;
        }
        .ignore-link {
            color: #a00;
        }
        .notice {
            margin: 5px 0;
            padding: 5px 10px;
        }
    </style>
    <?php
}

add_action('admin_footer', 'add_interlink_suggestions_scripts');
function add_interlink_suggestions_scripts() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.approve-link').on('click', function(e) {
            e.preventDefault();
            const button = $(this);
            const suggestionItem = button.closest('.suggestion-item');
            
            const data = {
                action: 'approve_interlink',
                post_id: button.data('post-id'),
                sentence: button.data('sentence'),
                position: button.data('position'),
                source_url: button.data('source'),
                nonce: $('#interlink_nonce').val()
            };
            
            button.prop('disabled', true).text('Processing...');
            
            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    suggestionItem.slideUp().after(
                        '<div class="notice notice-success"><p>Link added successfully!</p></div>'
                    );
                } else {
                    button.prop('disabled', false).text('Approve');
                    console.log(response.data)
                    suggestionItem.after(
                        '<div class="notice notice-error"><p> Selva' + response.data + '</p></div>'
                    );
                }
            });
        });
        
        $('.ignore-link').on('click', function(e) {
            e.preventDefault();
            $(this).closest('.suggestion-item').slideUp();
        });
    });
    </script>
    <?php
}

// Modified AJAX handler to handle position-based interlinking
add_action('wp_ajax_approve_interlink', 'handle_interlink_approval');
function handle_interlink_approval() {
    if (!check_ajax_referer('interlink_action', 'nonce', false)) {
        wp_send_json_error('Invalid security token.');
    }
    
    $post_id = intval($_POST['post_id']);
    $sentence = sanitize_text_field($_POST['sentence']);
    $position = sanitize_text_field($_POST['position']);
    $source_url = esc_url_raw($_POST['source_url']);
    
    $post = get_post($post_id);
    $content = $post->post_content;
    
    $debug = [
        'post_id' => $post_id,
        'sentence' => $sentence,
        'position' => $position,
        'source_url' => $source_url,
        'content_excerpt' => substr($content, 0, 200)
    ];
    
    if (strpos($content, $sentence) === false) {
        $debug['error'] = 'Sentence not found in content';
        wp_send_json_error(array_merge(['message' => 'Sentence not found'], $debug));
    }
    
    $linked_text = sprintf('<a href="%s" class="interlinked-word">%s</a>', $source_url, $position);
    $new_sentence = str_replace($position, $linked_text, $sentence);
    $new_content = str_replace($sentence, $new_sentence, $content);
    
    $debug['new_sentence'] = $new_sentence;
    
    if ($new_content === $content) {
        $debug['error'] = 'Content unchanged after replacement';
        wp_send_json_error(array_merge(['message' => 'No changes made'], $debug));
    }
    
    $updated = wp_update_post([
        'ID' => $post_id,
        'post_content' => $new_content
    ]);
    
    if ($updated && !is_wp_error($updated)) {
        wp_send_json_success(array_merge(['message' => 'Link added successfully'], $debug));
    } else {
        $debug['error'] = is_wp_error($updated) ? $updated->get_error_message() : 'Unknown error';
        wp_send_json_error(array_merge(['message' => 'Failed to update post'], $debug));
    }
}

function update_json_after_approval($post_id, $sentence) {
    $json_file = WP_CONTENT_DIR . '/uploads/interlinker/interlink_data.json';
    
    if (file_exists($json_file)) {
        $json_data = json_decode(file_get_contents($json_file), true);
        $post_url = get_permalink($post_id);
        
        if (isset($json_data[$post_url])) {
            // Filter out the used suggestion
            $json_data[$post_url] = array_filter($json_data[$post_url], function($suggestion) use ($sentence) {
                return $suggestion['sentence'] !== $sentence;
            });
            
            // Save updated JSON
            file_put_contents($json_file, json_encode($json_data, JSON_PRETTY_PRINT));
        }
    }
}