<?php
/**
 * Plugin Name: CPT Export
 * Plugin URI: https://site2goal.co.il
 * Description: Export Custom Post Types to XML format with filter for CPT type, category, date range, author, and status filtering. Optionally delete after export. Result in XML can be imported using standard Wordpress tool, or kept compressed.
 * Version: 1.2.0
 * Author: Mulli Bahr & AI
 * Author URI: https://site2goal.co.il
 * Text Domain: cpt-export
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 *
 * Shortcode Usage:
 * [cpt_export post_type="product" author="1" status="publish" start_date="2024-01-01" end_date="2024-12-31" delete="1" permanent="1" download="1"]
 *
 * Shortcode Parameters:
 * - post_type (required): The post type to export (e.g., "post", "page", "product")
 * - author (optional): User ID of the author to filter by
 * - status (optional): Post status to filter by ("publish", "draft", "private", "pending", "future")
 * - start_date (optional): Start date in YYYY-MM-DD format
 * - end_date (optional): End date in YYYY-MM-DD format
 * - delete (optional): "1" to delete posts after export, "0" to export only (default: "0")
 * - permanent (optional): "1" to permanently delete (bypass trash), "0" to move to trash (default: "0")
 * - delete_media (optional): "1" to delete attached media permanently, "0" to preserve media (default: "0")
 * - save_folder (optional): Folder name in uploads directory to save file, empty to download (default: "")
 * - compress (optional): "1" to create ZIP file, "0" for XML only (default: "0")
 * - download (optional): "1" to trigger download, "0" to return message (default: "1")
 * - return_data (optional): "1" to return XML content as text, "0" for normal operation (default: "0")
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check PHP version compatibility
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' .
            sprintf(__('CPT Export requires PHP 7.4 or higher. You are running PHP %s.', 'cpt-export'), PHP_VERSION) .
            '</p></div>';
    });
    return;
}

// Define plugin constants
define('CPT_EXPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CPT_EXPORT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CPT_EXPORT_VERSION', '1.0.97');

// Include required files
require_once CPT_EXPORT_PLUGIN_DIR . 'includes/class-cpt-export-core.php';
require_once CPT_EXPORT_PLUGIN_DIR . 'includes/class-cpt-export-xml-generator.php';
require_once CPT_EXPORT_PLUGIN_DIR . 'includes/class-cpt-export-file-handler.php';

// Initialize the plugin
function cpt_export_init()
{
    new CPT_Export_Tool();
}

// Hook into WordPress
add_action('plugins_loaded', 'cpt_export_init');