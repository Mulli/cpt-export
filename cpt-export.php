<?php
/**
 * Plugin Name: CPT Export
 * Plugin URI: https://site2goal.co.il
 * Description: Export Custom Post Types to XML format with date range, author, and status filtering. Optionally delete after export.
 * Version: 1.1.0
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

class CPT_Export_Tool
{
    const NONCE_ACTION = 'cpt_export_nonce_action'; // Defined constant for nonce action
    const USER_META_KEY = 'cpt_export_last_values'; // Key for storing last used values
    private $admin_page_notices = []; // For storing admin notices for the current request

    public function __construct()
    {
        $this->admin_page_notices = []; // Initialize notices array
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_export_admin_request'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_shortcode('cpt_export', array($this, 'shortcode_handler'));
        add_action('admin_notices', array($this, 'translation_admin_notice'));
        
        // Add AJAX handlers for category loading
        add_action('wp_ajax_cpt_export_get_categories', array($this, 'ajax_get_categories'));
    }

    public function load_textdomain()
    {
        $plugin_rel_path = dirname(plugin_basename(__FILE__)) . '/languages';
        load_plugin_textdomain('cpt-export', false, $plugin_rel_path);
    }

    public function translation_admin_notice()
    {
        // Ensure this notice is only shown on the relevant plugin page or general admin pages if necessary.
        // For now, it's general. Could be restricted to $hook checks.
        if (current_user_can('manage_options')) {
            $locale = get_locale();
            $plugin_lang_file_wp_dir = WP_LANG_DIR . '/plugins/cpt-export-' . $locale . '.mo';
            $plugin_lang_file_plugin_dir = plugin_dir_path(__FILE__) . 'languages/cpt-export-' . $locale . '.mo';

            if (!file_exists($plugin_lang_file_wp_dir) && !file_exists($plugin_lang_file_plugin_dir)) {
                $screen = get_current_screen();
                // Display only on the plugin's page or general tools page.
                if ($screen && ($screen->id === 'tools_page_cpt-export-tool' || $screen->base === 'tools')) {
                    echo '<div class="notice notice-info is-dismissible"><p>';
                    printf(
                        esc_html__('CPT Export: To use translations for your language (%1$s), please ensure .mo files are placed in %2$s or %3$s. A .pot file is available in the plugin\'s %4$s directory to create new translations.', 'cpt-export'),
                        '<strong>' . esc_html($locale) . '</strong>',
                        '<code>wp-content/languages/plugins/</code>',
                        '<code>' . esc_html(plugin_basename(dirname(__FILE__))) . '/languages/</code>',
                        '<code>languages</code>'
                    );
                    echo '</p></div>';
                }
            }
        }
    }

    public function add_admin_menu()
    {
        add_management_page(
            __('CPT Export Tool', 'cpt-export'),
            __('CPT Export', 'cpt-export'),
            'export',
            'cpt-export-tool',
            array($this, 'admin_page')
        );
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'tools_page_cpt-export-tool') {
            return;
        }
        wp_enqueue_script('jquery');
        
        // Add AJAX handler for loading categories based on post type
        wp_localize_script('jquery', 'cpt_export_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cpt_export_get_categories')
        ));
    }

    /**
     * Get last used form values for the current user
     */
    private function get_last_form_values()
    {
        $user_id = get_current_user_id();
        $last_values = get_user_meta($user_id, self::USER_META_KEY, true);
        
        // Default values
        $defaults = array(
            'cpt_post_type' => '',
            'cpt_category' => '',
            'cpt_author' => '',
            'cpt_status' => '',
            'cpt_start_date' => '',
            'cpt_end_date' => '',
            'cpt_export_and_delete' => false,
            'cpt_delete_permanently' => false,
            'cpt_delete_media' => false,
            'cpt_save_folder' => '',
            'cpt_compress' => false
        );

        // Merge with saved values if they exist
        if (is_array($last_values)) {
            return array_merge($defaults, $last_values);
        }

        return $defaults;
    }

    /**
     * Save form values for the current user
     */
    private function save_form_values($form_data)
    {
        $user_id = get_current_user_id();
        
        // Only save non-sensitive values (exclude delete options for safety)
        $values_to_save = array(
            'cpt_post_type' => $form_data['cpt_post_type'],
            'cpt_category' => $form_data['cpt_category'],
            'cpt_author' => $form_data['cpt_author'],
            'cpt_status' => $form_data['cpt_status'],
            'cpt_start_date' => $form_data['cpt_start_date'],
            'cpt_end_date' => $form_data['cpt_end_date'],
            'cpt_save_folder' => $form_data['cpt_save_folder'],
            'cpt_compress' => $form_data['cpt_compress'],
            // Note: We don't save delete options for safety reasons
            'cpt_export_and_delete' => false,
            'cpt_delete_permanently' => false,
            'cpt_delete_media' => false
        );

        update_user_meta($user_id, self::USER_META_KEY, $values_to_save);
    }

    public function admin_page()
    {
        // Get last used values as starting point
        $form_data = $this->get_last_form_values();

        // If this is a POST request, use submitted values instead (for validation errors, etc.)
        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['cpt_export_nonce_field'])) {
            $defaults = array(
                'cpt_post_type' => '',
                'cpt_category' => '',
                'cpt_author' => '',
                'cpt_status' => '',
                'cpt_start_date' => '',
                'cpt_end_date' => '',
                'cpt_export_and_delete' => false,
                'cpt_delete_permanently' => false,
                'cpt_delete_media' => false,
                'cpt_save_folder' => '',
                'cpt_compress' => false
            );

            // Override with POST data
            foreach ($defaults as $key => $default_value) {
                if (in_array($key, ['cpt_export_and_delete', 'cpt_delete_permanently', 'cpt_delete_media', 'cpt_compress'])) {
                    $form_data[$key] = (isset($_POST[$key]) && $_POST[$key] === '1');
                } elseif (isset($_POST[$key])) {
                    $form_data[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
                }
            }
        }

        // Display any notices set by handle_export_admin_request (e.g., validation errors)
        if (!empty($this->admin_page_notices)) {
            foreach ($this->admin_page_notices as $notice) {
                echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
            }
            $this->admin_page_notices = []; // Clear after displaying
        }

        // Display success message if file was saved (from redirect)
        if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cpt_export_saved_notice')) {
            $file_path_get = urldecode(sanitize_text_field($_GET['file_path']));
            $count_get = intval($_GET['count']);
            $compressed_get = isset($_GET['compressed']) && $_GET['compressed'] === '1';
            $uploads_dir_get = wp_upload_dir();
            $full_url_get = $uploads_dir_get['baseurl'] . '/' . $file_path_get;

            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . __('Export Successful!', 'cpt-export') . '</strong></p>';
            echo '<p>' . sprintf(esc_html__('Successfully exported %d posts.', 'cpt-export'), $count_get) . '</p>';
            if ($compressed_get) {
                echo '<p>' . sprintf(esc_html__('Compressed file saved to: %s', 'cpt-export'), '<code>' . esc_html($file_path_get) . '</code>') . '</p>';
            } else {
                echo '<p>' . sprintf(esc_html__('File saved to: %s', 'cpt-export'), '<code>' . esc_html($file_path_get) . '</code>') . '</p>';
            }
            echo '<p><a href="' . esc_url($full_url_get) . '" target="_blank" rel="noopener noreferrer" class="button button-secondary">' . __('Download File', 'cpt-export') . '</a></p>';
            echo '</div>';
        }
        // Display error message from redirect
        if (isset($_GET['export_error']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cpt_export_error_notice')) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__('Export Error:', 'cpt-export') . '</strong> ' . esc_html(urldecode(sanitize_text_field($_GET['export_error']))) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Export Custom Post Types', 'cpt-export'); ?></h1>
            <p><?php _e('When you click the button below WordPress will create an XML file for you to save to your computer.', 'cpt-export'); ?>
            </p>
            <p><?php _e('This format, which is called WordPress eXtended RSS or WXR, will contain your posts, custom fields, categories, and other content.', 'cpt-export'); ?>
            </p>

            <?php if ($this->get_last_form_values() !== $this->get_default_form_values()): ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('Form remembers your last settings', 'cpt-export'); ?></strong> - 
                    <?php _e('Fields below are pre-filled with your previous export settings.', 'cpt-export'); ?>
                    <button type="button" class="button-link" id="reset-form-values" style="margin-left: 10px;">
                        <?php _e('Reset to defaults', 'cpt-export'); ?>
                    </button>
                </p>
            </div>
            <?php endif; ?>

            <form method="post" id="cpt-export-form"
                action="<?php echo esc_url(admin_url('tools.php?page=cpt-export-tool')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, 'cpt_export_nonce_field'); ?>

                <h3><?php _e('Choose what to export', 'cpt-export'); ?></h3>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="cpt_post_type"><?php _e('Post Type', 'cpt-export'); ?></label></th>
                            <td>
                                <select name="cpt_post_type" id="cpt_post_type" required>
                                    <option value=""><?php _e('Select a post type...', 'cpt-export'); ?></option>
                                    <?php
                                    $post_types = get_post_types(array('public' => true), 'objects');
                                    foreach ($post_types as $post_type_obj) {
                                        if ($post_type_obj->name !== 'attachment') {
                                            echo '<option value="' . esc_attr($post_type_obj->name) . '" ' . selected($post_type_obj->name, $form_data['cpt_post_type'], false) . '>' . esc_html($post_type_obj->label) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                                <p class="description"><?php _e('Select the post type you want to export.', 'cpt-export'); ?>
                                </p>
                                <div id="post-type-error-message" style="color: red; display: none;">
                                    <?php esc_html_e('Please select a post type to export.', 'cpt-export'); ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cpt_category"><?php _e('Category', 'cpt-export'); ?></label></th>
                            <td>
                                <select name="cpt_category" id="cpt_category">
                                    <option value=""><?php _e('All categories', 'cpt-export'); ?></option>
                                </select>
                                <p class="description"><?php _e('Select a specific category to filter by. Options will update based on the selected post type.', 'cpt-export'); ?></p>
                                <div id="category-loading" style="display: none; color: #666; font-style: italic;">
                                    <?php esc_html_e('Loading categories...', 'cpt-export'); ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cpt_author"><?php _e('Author', 'cpt-export'); ?></label></th>
                            <td>
                                <?php wp_dropdown_users(array(
                                    'name' => 'cpt_author',
                                    'id' => 'cpt_author',
                                    'show_option_all' => __('All authors', 'cpt-export'),
                                    'capability' => 'edit_posts',
                                    'hide_if_only_one_author' => true,
                                    'selected' => $form_data['cpt_author'],
                                )); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cpt_status"><?php _e('Status', 'cpt-export'); ?></label></th>
                            <td>
                                <select name="cpt_status" id="cpt_status">
                                    <option value="" <?php selected('', $form_data['cpt_status']); ?>>
                                        <?php _e('All statuses', 'cpt-export'); ?></option>
                                    <option value="publish" <?php selected('publish', $form_data['cpt_status']); ?>>
                                        <?php _e('Published', 'cpt-export'); ?></option>
                                    <option value="draft" <?php selected('draft', $form_data['cpt_status']); ?>>
                                        <?php _e('Draft', 'cpt-export'); ?></option>
                                    <option value="private" <?php selected('private', $form_data['cpt_status']); ?>>
                                        <?php _e('Private', 'cpt-export'); ?></option>
                                    <option value="pending" <?php selected('pending', $form_data['cpt_status']); ?>>
                                        <?php _e('Pending Review', 'cpt-export'); ?></option>
                                    <option value="future" <?php selected('future', $form_data['cpt_status']); ?>>
                                        <?php _e('Scheduled', 'cpt-export'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Date Range', 'cpt-export'); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php _e('Date Range', 'cpt-export'); ?></legend>
                                    <label for="cpt_start_date"><?php _e('Start Date:', 'cpt-export'); ?>
                                        <input type="date" name="cpt_start_date" id="cpt_start_date"
                                            value="<?php echo esc_attr($form_data['cpt_start_date']); ?>">
                                    </label>
                                    <br><br>
                                    <label for="cpt_end_date"><?php _e('End Date:', 'cpt-export'); ?>
                                        <input type="date" name="cpt_end_date" id="cpt_end_date"
                                            value="<?php echo esc_attr($form_data['cpt_end_date']); ?>">
                                    </label>
                                    <p class="description"><?php _e('Leave blank to export all dates.', 'cpt-export'); ?></p>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Action', 'cpt-export'); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><?php _e('Export Action', 'cpt-export'); ?></legend>
                                    <div id="delete-confirmation-message-placeholder"
                                        style="color: #d63638; font-weight: bold; margin-bottom: 10px;"></div>
                                    <label for="cpt_export_and_delete">
                                        <input type="checkbox" name="cpt_export_and_delete" id="cpt_export_and_delete" value="1"
                                            <?php checked($form_data['cpt_export_and_delete']); ?>>
                                        <?php _e('Move to Trash', 'cpt-export'); ?>
                                    </label>
                                    <br><br>
                                    <label for="cpt_delete_permanently">
                                        <input type="checkbox" name="cpt_delete_permanently" id="cpt_delete_permanently"
                                            value="1" <?php checked($form_data['cpt_delete_permanently']); ?>         <?php disabled(!$form_data['cpt_export_and_delete']); ?>>
                                        <?php _e('Delete Permanently (bypass trash)', 'cpt-export'); ?>
                                    </label>
                                    <br><br>
                                    <label for="cpt_delete_media">
                                        <input type="checkbox" name="cpt_delete_media" id="cpt_delete_media" value="1" <?php checked($form_data['cpt_delete_media']); ?>         <?php disabled(!$form_data['cpt_export_and_delete']); ?>>
                                        <?php _e('Delete Media Permanently', 'cpt-export'); ?>
                                    </label>
                                    <p class="description" style="color: #d63638;">
                                        <strong><?php _e('Warning:', 'cpt-export'); ?></strong>
                                        <?php _e('Checking "Move to Trash" will move all exported posts to trash after export. If "Delete Permanently" is also checked, posts will bypass trash. If "Delete Media Permanently" is checked, attached media files will be permanently deleted (be careful if media is shared between posts). These actions cannot be undone!', 'cpt-export'); ?>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cpt_save_folder"><?php _e('Save in Folder', 'cpt-export'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="cpt_save_folder" id="cpt_save_folder" class="regular-text"
                                    value="<?php echo esc_attr($form_data['cpt_save_folder']); ?>"
                                    placeholder="<?php esc_attr_e('e.g., plfiles/stas/2022 or leave empty to download', 'cpt-export'); ?>">
                                <p class="description">
                                    <?php
                                    $uploads_dir = wp_upload_dir();
                                    printf(
                                        esc_html__('Optional: Enter folder path to save file in %s/[folder_path]/ instead of downloading. Supports subfolders (e.g., "plfiles/stas/2022"). Leave empty to download the file.', 'cpt-export'),
                                        '<code>' . esc_html($uploads_dir['basedir']) . '</code>'
                                    );
                                    ?>
                                    <br>
                                    <?php _e('All subdirectories will be created automatically if they don\'t exist.', 'cpt-export'); ?>
                                    <br>
                                    <?php _e('File will be named: digma-[post_type]-[start_date]-[end_date].xml (or .zip)', 'cpt-export'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cpt_compress"><?php _e('Compress File', 'cpt-export'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cpt_compress" id="cpt_compress" value="1" <?php checked($form_data['cpt_compress']); ?>>
                                    <?php _e('Compress export file (ZIP)', 'cpt-export'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Creates a ZIP file containing the XML export. Useful for large exports or email attachments.', 'cpt-export'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="cpt_export_submit" id="submit" class="button button-primary"
                        value="<?php esc_attr_e('Download Export File', 'cpt-export'); ?>">
                </p>
            </form>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                function debounce(func, wait, immediate) {
                    var timeout;
                    return function () {
                        var context = this, args = arguments;
                        var later = function () {
                            timeout = null;
                            if (!immediate) func.apply(context, args);
                        };
                        var callNow = immediate && !timeout;
                        clearTimeout(timeout);
                        timeout = setTimeout(later, wait);
                        if (callNow) func.apply(context, args);
                    };
                };

                var $postTypeSelect = $('#cpt_post_type');
                var $categorySelect = $('#cpt_category');
                var $categoryLoading = $('#category-loading');
                var $postTypeError = $('#post-type-error-message');
                var $exportForm = $('#cpt-export-form');
                var $exportAndDeleteCheckbox = $('#cpt_export_and_delete');
                var $deletePermanentlyCheckbox = $('#cpt_delete_permanently');
                var $deleteMediaCheckbox = $('#cpt_delete_media');
                var $submitButton = $('#submit');
                var $saveFolderInput = $('#cpt_save_folder');
                var $deleteConfirmationPlaceholder = $('#delete-confirmation-message-placeholder');
                var $resetButton = $('#reset-form-values');

                // Reset form to defaults
                $resetButton.on('click', function(e) {
                    e.preventDefault();
                    if (confirm('<?php echo esc_js(__('Are you sure you want to reset all form fields to their default values?', 'cpt-export')); ?>')) {
                        // Reset to default values
                        $postTypeSelect.val('');
                        $categorySelect.html('<option value=""><?php echo esc_js(__('All categories', 'cpt-export')); ?></option>').val('');
                        $('#cpt_author').val('');
                        $('#cpt_status').val('');
                        $('#cpt_start_date').val('');
                        $('#cpt_end_date').val('');
                        $exportAndDeleteCheckbox.prop('checked', false);
                        $deletePermanentlyCheckbox.prop('checked', false);
                        $deleteMediaCheckbox.prop('checked', false);
                        $saveFolderInput.val('');
                        $('#cpt_compress').prop('checked', false);
                        
                        // Update button text and states
                        updateButtonTextAndInteractivity();
                        
                        // Show a notice that values were reset
                        $(this).closest('.notice').fadeOut();
                    }
                });

                function updateButtonTextAndInteractivity() {

                // Load categories when post type changes
                function loadCategories(postType, selectedCategory) {
                    if (!postType) {
                        $categorySelect.html('<option value=""><?php echo esc_js(__('All categories', 'cpt-export')); ?></option>');
                        return;
                    }

                    $categoryLoading.show();
                    $categorySelect.prop('disabled', true);

                    $.post(cpt_export_ajax.ajax_url, {
                        action: 'cpt_export_get_categories',
                        post_type: postType,
                        selected: selectedCategory || '',
                        nonce: cpt_export_ajax.nonce
                    }, function(response) {
                        $categoryLoading.hide();
                        $categorySelect.prop('disabled', false);
                        
                        if (response.success) {
                            $categorySelect.html(response.data);
                        } else {
                            $categorySelect.html('<option value=""><?php echo esc_js(__('All categories', 'cpt-export')); ?></option>');
                            console.error('Failed to load categories:', response);
                        }
                    }).fail(function() {
                        $categoryLoading.hide();
                        $categorySelect.prop('disabled', false);
                        $categorySelect.html('<option value=""><?php echo esc_js(__('All categories', 'cpt-export')); ?></option>');
                    });
                }

                // Load categories on post type change
                $postTypeSelect.on('change', function() {
                    var postType = $(this).val();
                    loadCategories(postType, '');
                });

                // Load categories on page load if post type is already selected
                $(document).ready(function() {
                    var initialPostType = $postTypeSelect.val();
                    var initialCategory = '<?php echo esc_js($form_data['cpt_category']); ?>';
                    if (initialPostType) {
                        loadCategories(initialPostType, initialCategory);
                    }
                });
                    var isExportAndDeleteChecked = $exportAndDeleteCheckbox.is(':checked');
                    var isPermanent = $deletePermanentlyCheckbox.is(':checked');
                    var isDeleteMedia = $deleteMediaCheckbox.is(':checked');
                    var saveFolder = $saveFolderInput.val().trim();
                    var buttonText = '';
                    var action = saveFolder ? '<?php echo esc_js(__('Save', 'cpt-export')); ?>' : '<?php echo esc_js(__('Download', 'cpt-export')); ?>';

                    // Update dependent checkbox disabled state
                    $deletePermanentlyCheckbox.prop('disabled', !isExportAndDeleteChecked);
                    $deleteMediaCheckbox.prop('disabled', !isExportAndDeleteChecked);
                    if (!isExportAndDeleteChecked) {
                        // If parent is unchecked, children should also be unchecked (though PHP handles submitted values)
                        // $deletePermanentlyCheckbox.prop('checked', false); 
                        // $deleteMediaCheckbox.prop('checked', false);
                        // PHP will handle the actual value on submission. JS just handles interactivity.
                    }


                    if (isExportAndDeleteChecked) {
                        if (isPermanent && isDeleteMedia) {
                            buttonText = action + ' <?php echo esc_js(__('and Permanently Delete Posts + Media', 'cpt-export')); ?>';
                        } else if (isPermanent) {
                            buttonText = action + ' <?php echo esc_js(__('and Permanently Delete Posts', 'cpt-export')); ?>';
                        } else if (isDeleteMedia) {
                            buttonText = action + ' <?php echo esc_js(__('and Move Posts to Trash + Delete Media', 'cpt-export')); ?>';
                        } else {
                            buttonText = action + ' <?php echo esc_js(__('and Move Posts to Trash', 'cpt-export')); ?>';
                        }
                        $submitButton.removeClass('button-primary').addClass('button-secondary');
                    } else {
                        buttonText = action + ' <?php echo esc_js(__('Export File', 'cpt-export')); ?>';
                        $submitButton.removeClass('button-secondary').addClass('button-primary');
                    }
                    $submitButton.val(buttonText);
                }

                $exportForm.on('submit', function (e) {
                    $postTypeError.hide();
                    $deleteConfirmationPlaceholder.empty();

                    var postType = $postTypeSelect.val();
                    if (!postType) {
                        e.preventDefault();
                        $postTypeError.show();
                        $postTypeSelect.focus();
                        return false;
                    }

                    if ($exportAndDeleteCheckbox.is(':checked')) {
                        var isPermanent = $deletePermanentlyCheckbox.is(':checked');
                        var isDeleteMedia = $deleteMediaCheckbox.is(':checked');
                        var confirmMessage = '';
                        var modalTitle = '';

                        if (isPermanent && isDeleteMedia) {
                            modalTitle = '<?php echo esc_js(__('Confirm Permanent Deletion', 'cpt-export')); ?>';
                            confirmMessage = '<?php echo esc_js(__('You are about to export and PERMANENTLY DELETE posts AND their media.\n\nThis action cannot be undone!\n\nAre you sure you want to proceed?', 'cpt-export')); ?>';
                        } else if (isPermanent) {
                            modalTitle = '<?php echo esc_js(__('Confirm Permanent Deletion', 'cpt-export')); ?>';
                            confirmMessage = '<?php echo esc_js(__('You are about to export and PERMANENTLY DELETE posts.\n\nMedia will be preserved, but posts will be permanently deleted.\n\nThis action cannot be undone!\n\nAre you sure you want to proceed?', 'cpt-export')); ?>';
                        } else if (isDeleteMedia) {
                            modalTitle = '<?php echo esc_js(__('Confirm Media Deletion', 'cpt-export')); ?>';
                            confirmMessage = '<?php echo esc_js(__('You are about to export posts, move them to trash, and PERMANENTLY DELETE their media.\n\nPosts can be restored from trash, but media deletion cannot be undone!\n\nAre you sure you want to proceed?', 'cpt-export')); ?>';
                        } else {
                            modalTitle = '<?php echo esc_js(__('Confirm Move to Trash', 'cpt-export')); ?>';
                            confirmMessage = '<?php echo esc_js(__('You are about to export posts and MOVE them to trash.\n\nMedia will be preserved and posts can be restored from trash.\n\nDo you want to proceed?', 'cpt-export')); ?>';
                        }

                        // Show confirmation dialog
                        if (confirm(modalTitle + '\n\n' + confirmMessage)) {
                            // User confirmed, allow form submission
                            return true;
                        } else {
                            // User cancelled
                            e.preventDefault();
                            return false;
                        }
                    }
                });

                $exportAndDeleteCheckbox.on('change', updateButtonTextAndInteractivity);
                $deletePermanentlyCheckbox.on('change', updateButtonTextAndInteractivity);
                $deleteMediaCheckbox.on('change', updateButtonTextAndInteractivity);
                $saveFolderInput.on('input', debounce(updateButtonTextAndInteractivity, 250));

                // Initialize button text and checkbox states on page load
                updateButtonTextAndInteractivity();
            });
        </script>
        <style>
            #cpt_export_and_delete:checked+label,
            #cpt_delete_permanently:checked+label,
            #cpt_delete_media:checked+label {
                font-weight: bold;
                color: #d63638;
                /* WordPress warning red */
            }

            #cpt_delete_permanently:disabled+label,
            #cpt_delete_media:disabled+label {
                opacity: 0.5;
                color: #666;
            }
        </style>
        <?php
    }

    /**
     * AJAX handler to get categories/terms for a specific post type
     */
    public function ajax_get_categories()
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cpt_export_get_categories')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('export')) {
            wp_die('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);
        $selected_category = sanitize_text_field($_POST['selected'] ?? '');

        if (empty($post_type) || !post_type_exists($post_type)) {
            wp_send_json_error('Invalid post type');
        }

        // Get all taxonomies for this post type
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $options = ['<option value="">' . __('All categories', 'cpt-export') . '</option>'];

        foreach ($taxonomies as $taxonomy) {
            // Skip non-public taxonomies unless user has rights
            if (!$taxonomy->public && !$taxonomy->publicly_queryable && !current_user_can($taxonomy->cap->assign_terms)) {
                continue;
            }

            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'number' => 200, // Limit for performance
                'orderby' => 'name',
                'order' => 'ASC'
            ));

            if (!empty($terms) && !is_wp_error($terms)) {
                // Add taxonomy label as optgroup if multiple taxonomies
                if (count($taxonomies) > 1) {
                    $options[] = '<optgroup label="' . esc_attr($taxonomy->label) . '">';
                }

                foreach ($terms as $term) {
                    $term_label = $term->name;
                    if ($term->count > 0) {
                        $term_label .= ' (' . $term->count . ')';
                    }
                    $selected = selected($term->term_id, $selected_category, false);
                    $options[] = '<option value="' . esc_attr($term->term_id) . '"' . $selected . '>' . esc_html($term_label) . '</option>';
                }

                if (count($taxonomies) > 1) {
                    $options[] = '</optgroup>';
                }
            }
        }

        wp_send_json_success(implode('', $options));
    }
    /**
     * Get default form values (for comparison)
     */
    private function get_default_form_values()
    {
        return array(
            'cpt_post_type' => '',
            'cpt_category' => '',
            'cpt_author' => '',
            'cpt_status' => '',
            'cpt_start_date' => '',
            'cpt_end_date' => '',
            'cpt_export_and_delete' => false,
            'cpt_delete_permanently' => false,
            'cpt_delete_media' => false,
            'cpt_save_folder' => '',
            'cpt_compress' => false
        );
    }

    public function handle_export_admin_request()
    {
        if (!isset($_POST['cpt_export_submit']) || !isset($_POST['cpt_export_nonce_field'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpt_export_nonce_field'])), self::NONCE_ACTION)) {
            // Nonce failed, add notice and allow form to re-render with submitted values
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('Security check failed: Invalid nonce.', 'cpt-export')];
            return; // Stop further processing, admin_page will render with POST data
        }

        if (!current_user_can('export')) {
            // Permission failed, add notice and allow form to re-render
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('You do not have sufficient permissions to export content.', 'cpt-export')];
            return;
        }

        $post_type = isset($_POST['cpt_post_type']) ? sanitize_text_field($_POST['cpt_post_type']) : '';
        // ... (rest of the variable assignments from POST)
        $category = isset($_POST['cpt_category']) ? sanitize_text_field($_POST['cpt_category']) : '';
        $author = isset($_POST['cpt_author']) ? sanitize_text_field($_POST['cpt_author']) : '';
        $status = isset($_POST['cpt_status']) ? sanitize_text_field($_POST['cpt_status']) : '';
        $start_date = isset($_POST['cpt_start_date']) ? sanitize_text_field($_POST['cpt_start_date']) : '';
        $end_date = isset($_POST['cpt_end_date']) ? sanitize_text_field($_POST['cpt_end_date']) : '';
        $export_and_delete = isset($_POST['cpt_export_and_delete']) && $_POST['cpt_export_and_delete'] === '1';
        $delete_permanently = isset($_POST['cpt_delete_permanently']) && $_POST['cpt_delete_permanently'] === '1' && $export_and_delete;
        $delete_media = isset($_POST['cpt_delete_media']) && $_POST['cpt_delete_media'] === '1' && $export_and_delete;
        $save_folder = isset($_POST['cpt_save_folder']) ? sanitize_text_field($_POST['cpt_save_folder']) : '';
        $compress = isset($_POST['cpt_compress']) && $_POST['cpt_compress'] === '1';

        // Save form values for future use (before validation, so even failed attempts remember settings)
        $form_data_to_save = array(
            'cpt_post_type' => $post_type,
            'cpt_category' => $category,
            'cpt_author' => $author,
            'cpt_status' => $status,
            'cpt_start_date' => $start_date,
            'cpt_end_date' => $end_date,
            'cpt_export_and_delete' => false, // Don't save delete options for safety
            'cpt_delete_permanently' => false,
            'cpt_delete_media' => false,
            'cpt_save_folder' => $save_folder,
            'cpt_compress' => $compress
        );
        $this->save_form_values($form_data_to_save);

        if (empty($post_type)) {
            // Post type empty, add notice and allow form to re-render with POST data
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('Please select a post type to export.', 'cpt-export')];
            return; // Stop further processing
        }

        if ($export_and_delete && !current_user_can('delete_posts')) {
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('You do not have sufficient permissions to delete content.', 'cpt-export')];
            return;
        }

        // IMPORTANT: Server-side confirmation for delete operations should be implemented here
        // if client-side confirmation is bypassed or not sufficient.
        // For now, proceeding based on form submission if JS didn't halt it.

        $export_result = $this->_prepare_export_data($post_type, $category, $author, $status, $start_date, $end_date, $compress);

        if ($export_result['status'] === 'error') {
            // Error from _prepare_export_data, redirect with error
            $error_redirect_url = add_query_arg(array(
                'page' => 'cpt-export-tool',
                'export_error' => urlencode($export_result['message']),
                '_wpnonce' => wp_create_nonce('cpt_export_error_notice')
            ), admin_url('tools.php'));
            wp_redirect($error_redirect_url);
            exit;
        }

        $data = $export_result['data'];

        if (!empty($save_folder)) {
            $file_path = $this->save_to_uploads_folder($data['file_data'], $data['filename'], $save_folder);
            if ($file_path) {
                if ($export_and_delete) {
                    $this->delete_posts_and_media($data['posts_objects'], $data['attachments_objects'], $delete_permanently, $delete_media);
                }
                $redirect_url = add_query_arg(array(
                    'page' => 'cpt-export-tool',
                    'saved' => '1',
                    'file_path' => urlencode($file_path),
                    'count' => $data['posts_count'],
                    'compressed' => $compress ? '1' : '0',
                    '_wpnonce' => wp_create_nonce('cpt_export_saved_notice')
                ), admin_url('tools.php'));
                wp_redirect($redirect_url);
                exit;
            } else {
                $error_redirect_url = add_query_arg(array(
                    'page' => 'cpt-export-tool',
                    'export_error' => urlencode(__('Error: Could not save file to the specified folder.', 'cpt-export')),
                    '_wpnonce' => wp_create_nonce('cpt_export_error_notice')
                ), admin_url('tools.php'));
                wp_redirect($error_redirect_url);
                exit;
            }
        } else {
            // Download file
            // Ensure no output before headers
            if (ob_get_level()) { // Check if output buffering is active
                ob_end_clean(); // Clean existing buffer
            }

            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename=' . $data['filename']);
            header('Content-Type: ' . $data['content_type']);
            header('Content-Length: ' . strlen($data['file_data'])); // Ensure this is accurate
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');

            echo $data['file_data'];

            if ($export_and_delete) {
                // Deletion after download initiation.
                // register_shutdown_function might be more robust if script execution time is an issue.
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request(); // Finish request to browser, then delete
                } else {
                    flush(); // Try to flush output
                }
                $this->delete_posts_and_media($data['posts_objects'], $data['attachments_objects'], $delete_permanently, $delete_media);
            }
            exit;
        }
    }

    private function _prepare_export_data($post_type, $category = '', $author = '', $status = '', $start_date = '', $end_date = '', $compress = false)
    {
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => $status ? $status : array('publish', 'draft', 'private', 'pending', 'future'),
            'orderby' => 'date',
            'order' => 'DESC'
        );

        if (!empty($author)) {
            $args['author'] = intval($author);
        }

        // Add category/term filtering
        if (!empty($category)) {
            $term = get_term(intval($category));
            if ($term && !is_wp_error($term)) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => $term->taxonomy,
                        'field'    => 'term_id',
                        'terms'    => intval($category),
                    ),
                );
            }
        }

        if (!empty($start_date) || !empty($end_date)) {
            $date_query = array('inclusive' => true);
            if (!empty($start_date)) {
                $date_query['after'] = $start_date . ' 00:00:00'; // Ensure start of day
            }
            if (!empty($end_date)) {
                $date_query['before'] = $end_date . ' 23:59:59'; // Ensure end of day
            }
            $args['date_query'] = array($date_query);
        }

        $posts = get_posts($args);

        if (empty($posts)) {
            return ['status' => 'error', 'message' => __('No posts found matching your criteria.', 'cpt-export')];
        }

        $attachment_ids = array();
        foreach ($posts as $p) {
            $featured_image_id = get_post_thumbnail_id($p->ID);
            if ($featured_image_id)
                $attachment_ids[] = $featured_image_id;
            $content_attachments = $this->get_content_attachments($p->post_content);
            $attachment_ids = array_merge($attachment_ids, $content_attachments);
            $post_attachments = get_attached_media('', $p->ID);
            foreach ($post_attachments as $attachment) {
                $attachment_ids[] = $attachment->ID;
            }
        }
        $attachment_ids = array_unique(array_map('intval', $attachment_ids));

        $attachments = array();
        if (!empty($attachment_ids)) {
            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'post__in' => $attachment_ids,
                'posts_per_page' => -1,
                'post_status' => 'inherit',
                'orderby' => 'ID',
                'order' => 'ASC'
            ));
        }

        $xml_content = $this->generate_xml($posts, $attachments, $post_type);
        $filename = $this->generate_filename($post_type, $start_date, $end_date, $compress);

        $file_data = $xml_content;
        $content_type = 'text/xml; charset=' . get_option('blog_charset');

        if ($compress) {
            if (!class_exists('ZipArchive')) {
                return ['status' => 'error', 'message' => __('Error: ZIP compression is not available on this server. ZipArchive extension is required.', 'cpt-export')];
            }
            $zip_result = $this->create_zip_file($xml_content, $filename);
            if (is_wp_error($zip_result)) {
                return ['status' => 'error', 'message' => $zip_result->get_error_message()];
            }
            $file_data = $zip_result;
            $content_type = 'application/zip';
        }

        return [
            'status' => 'success',
            'data' => [
                'file_data' => $file_data,
                'filename' => $filename,
                'content_type' => $content_type,
                'posts_count' => count($posts),
                'attachments_count' => count($attachments),
                'posts_objects' => $posts,
                'attachments_objects' => $attachments
            ]
        ];
    }


    private function get_content_attachments($content)
    {
        $attachment_ids = array();
        if (preg_match_all('/wp-image-(\d+)/', $content, $matches)) {
            $attachment_ids = array_merge($attachment_ids, $matches[1]);
        }
        if (preg_match_all('/\[gallery[^\]]*ids=["\']([^"\']+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $ids_string) {
                $ids = explode(',', $ids_string);
                $attachment_ids = array_merge($attachment_ids, array_map('trim', $ids));
            }
        }
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $img_matches)) {
            foreach ($img_matches[1] as $img_url) {
                $attachment_id = attachment_url_to_postid($img_url);
                if ($attachment_id) {
                    $attachment_ids[] = $attachment_id;
                }
            }
        }
        return array_map('intval', array_filter(array_unique($attachment_ids)));
    }

    private function generate_filename($post_type, $start_date = '', $end_date = '', $compress = false)
    {
        $filename_parts = ['digma', sanitize_file_name($post_type)];
        $filename_parts[] = $start_date ? sanitize_file_name($start_date) : 'all-start';
        $filename_parts[] = $end_date ? sanitize_file_name($end_date) : 'all-end';
        return implode('-', $filename_parts) . ($compress ? '.zip' : '.xml');
    }

    private function create_zip_file($xml_content, $zip_filename)
    {
        $temp_zip = wp_tempnam($zip_filename);

        $zip = new ZipArchive();
        $result = $zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== TRUE) {
            if (file_exists($temp_zip))
                unlink($temp_zip);
            return new WP_Error('zip_creation_failed', sprintf(__('Error: Could not create ZIP file. ZipArchive open error code: %d', 'cpt-export'), $result));
        }

        $xml_filename_in_zip = preg_replace('/\.zip$/i', '.xml', $zip_filename);
        if (!$zip->addFromString($xml_filename_in_zip, $xml_content)) {
            $zip->close();
            if (file_exists($temp_zip))
                unlink($temp_zip);
            return new WP_Error('zip_add_string_failed', __('Error: Could not add XML data to ZIP file.', 'cpt-export'));
        }

        if (!$zip->close()) {
            if (file_exists($temp_zip))
                unlink($temp_zip);
            return new WP_Error('zip_close_failed', __('Error: Could not finalize ZIP file.', 'cpt-export'));
        }

        $zip_content = file_get_contents($temp_zip);
        if (file_exists($temp_zip))
            unlink($temp_zip);

        if ($zip_content === false) {
            return new WP_Error('zip_read_failed', __('Error: Could not read ZIP file content.', 'cpt-export'));
        }
        return $zip_content;
    }

    private function save_to_uploads_folder($file_content, $filename, $folder_name)
    {
        $uploads = wp_upload_dir();
        if ($uploads['error']) {
            return false;
        }

        // Clean up and validate the folder path
        $folder_name = trim($folder_name, '/\\');
        if (empty($folder_name)) {
            return false;
        }

        // Sanitize each part of the path separately to preserve subdirectories
        $path_parts = explode('/', str_replace('\\', '/', $folder_name));
        $sanitized_parts = array();
        
        foreach ($path_parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $sanitized_part = sanitize_file_name($part);
                if (!empty($sanitized_part)) {
                    $sanitized_parts[] = $sanitized_part;
                }
            }
        }

        if (empty($sanitized_parts)) {
            return false;
        }

        // Build the full target directory path
        $sanitized_folder_path = implode('/', $sanitized_parts);
        $target_dir = trailingslashit($uploads['basedir']) . $sanitized_folder_path;

        // Create all subdirectories recursively if they don't exist
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                error_log('CPT Export: Failed to create directory: ' . $target_dir);
                return false;
            }
        } else if (!is_dir($target_dir)) {
            error_log('CPT Export: Path exists but is not a directory: ' . $target_dir);
            return false;
        } else if (!is_writable($target_dir)) {
            error_log('CPT Export: Directory is not writable: ' . $target_dir);
            return false;
        }

        // Save the file
        $file_path = trailingslashit($target_dir) . sanitize_file_name($filename);

        if (file_put_contents($file_path, $file_content) === false) {
            error_log('CPT Export: Failed to write file: ' . $file_path);
            return false;
        }

        // Return the relative path from uploads directory
        return $sanitized_folder_path . '/' . sanitize_file_name($filename);
    }

    private function delete_posts_and_media($posts, $attachments, $delete_permanently = false, $delete_media = false)
    {
        if ($delete_media && !empty($attachments)) {
            foreach ($attachments as $attachment) {
                if ($attachment instanceof WP_Post && $attachment->post_type === 'attachment') {
                    wp_delete_attachment($attachment->ID, true);
                }
            }
        }

        if (!empty($posts)) {
            foreach ($posts as $post) {
                if ($post instanceof WP_Post) {
                    if ($delete_permanently) {
                        wp_delete_post($post->ID, true);
                    } else {
                        wp_trash_post($post->ID);
                    }
                }
            }
        }
    }

    public function generate_xml($posts, $attachments, $post_type_slug)
    {
        global $wpdb;
        ob_start();

        echo '<?xml version="1.0" encoding="' . esc_attr(get_bloginfo('charset')) . "\" ?>\n";
        ?>
        <!-- This is a WordPress eXtended RSS file generated by CPT Export as an export of your site. -->
        <!-- It contains information about your site's posts, pages, comments, categories, and other content. -->
        <!-- You may use this file to transfer that content from one site to another. -->
        <!-- This file is not intended to serve as a complete backup of your site. -->

        <rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
            xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/"
            xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
            <channel>
                <title><?php echo esc_html(get_bloginfo('name')); ?></title>
                <link><?php echo esc_url(get_bloginfo('url')); ?></link>
                <description><?php echo esc_html(get_bloginfo('description')); ?></description>
                <pubDate><?php echo esc_html(gmdate('D, d M Y H:i:s +0000')); ?></pubDate>
                <language><?php echo esc_html(get_option('rss_language', get_bloginfo('language'))); ?></language>
                <wp:wxr_version>1.2</wp:wxr_version>
                <wp:base_site_url><?php echo esc_url(get_option('siteurl')); ?></wp:base_site_url>
                <wp:base_blog_url><?php echo esc_url(get_option('home')); ?></wp:base_blog_url>
                <?php
                $author_ids = array();
                if (!empty($posts))
                    foreach ($posts as $p)
                        $author_ids[] = (int) $p->post_author;
                if (!empty($attachments))
                    foreach ($attachments as $a)
                        $author_ids[] = (int) $a->post_author;
                $author_ids = array_unique(array_filter($author_ids));
                foreach ($author_ids as $author_id) {
                    $author = get_userdata($author_id);
                    if ($author) {
                        ?>
                        <wp:author>
                            <wp:author_id><?php echo intval($author->ID); ?></wp:author_id>
                            <wp:author_login><?php echo $this->wxr_cdata($author->user_login); ?></wp:author_login>
                            <wp:author_email><?php echo $this->wxr_cdata($author->user_email); ?></wp:author_email>
                            <wp:author_display_name><?php echo $this->wxr_cdata($author->display_name); ?></wp:author_display_name>
                            <wp:author_first_name><?php echo $this->wxr_cdata($author->first_name); ?></wp:author_first_name>
                            <wp:author_last_name><?php echo $this->wxr_cdata($author->last_name); ?></wp:author_last_name>
                        </wp:author>
                        <?php
                    }
                }

                $term_ids = array();
                $all_posts_for_terms = array_merge($posts, $attachments);
                foreach ($all_posts_for_terms as $p) {
                    if (!$p instanceof WP_Post)
                        continue;
                    $post_taxonomies = get_object_taxonomies($p->post_type, 'objects');
                    foreach ($post_taxonomies as $taxonomy) {
                        if (!$taxonomy->public && !$taxonomy->publicly_queryable && !current_user_can($taxonomy->cap->assign_terms)) {
                            continue; // Skip non-public taxonomies unless user has rights
                        }
                        $post_terms = wp_get_object_terms($p->ID, $taxonomy->name, array('fields' => 'ids'));
                        if (!is_wp_error($post_terms) && !empty($post_terms)) {
                            $term_ids = array_merge($term_ids, $post_terms);
                        }
                    }
                }
                $term_ids = array_unique(array_map('intval', $term_ids));
                foreach ($term_ids as $term_id) {
                    $term = get_term($term_id);
                    if ($term && !is_wp_error($term)) {
                        ?>
                        <wp:term>
                            <wp:term_id><?php echo intval($term->term_id); ?></wp:term_id>
                            <wp:term_taxonomy><?php echo $this->wxr_cdata($term->taxonomy); ?></wp:term_taxonomy>
                            <wp:term_slug><?php echo $this->wxr_cdata($term->slug); ?></wp:term_slug>
                            <?php $parent_term = $term->parent ? get_term($term->parent, $term->taxonomy) : false; ?>
                            <wp:term_parent>
                                <?php echo $this->wxr_cdata($parent_term && !is_wp_error($parent_term) ? $parent_term->slug : ''); ?>
                            </wp:term_parent>
                            <wp:term_name><?php echo $this->wxr_cdata($term->name); ?></wp:term_name>
                            <?php if (!empty($term->description)): ?>
                                <wp:term_description><?php echo $this->wxr_cdata($term->description); ?></wp:term_description>
                            <?php endif; ?>
                        </wp:term>
                        <?php
                    }
                }

                do_action('rss2_head');

                if (!empty($attachments)) {
                    foreach ($attachments as $attachment_post) {
                        if ($attachment_post instanceof WP_Post)
                            $this->generate_post_xml($attachment_post);
                    }
                }
                if (!empty($posts)) {
                    foreach ($posts as $main_post) {
                        if ($main_post instanceof WP_Post)
                            $this->generate_post_xml($main_post);
                    }
                }
                ?>
            </channel>
        </rss>
        <?php
        $xml = ob_get_contents();
        ob_end_clean();
        return $xml;
    }

    private function generate_post_xml($post_obj)
    {
        global $wpdb;
        // setup_postdata($post_obj); // Not strictly needed if using $post_obj->property directly
        ?>
        <item>
            <title><?php echo $this->wxr_cdata($post_obj->post_title); ?></title>
            <link><?php echo esc_url(get_permalink($post_obj->ID)); ?></link>
            <pubDate><?php echo esc_html(mysql2date('D, d M Y H:i:s +0000', $post_obj->post_date_gmt, false)); ?></pubDate>
            <dc:creator><?php echo $this->wxr_cdata(get_the_author_meta('user_login', $post_obj->post_author)); ?></dc:creator>
            <guid isPermaLink="false"><?php echo esc_url(get_the_guid($post_obj->ID)); ?></guid>
            <description><?php echo $this->wxr_cdata($post_obj->post_excerpt); // Using post_excerpt for description ?>
            </description>
            <content:encoded><?php echo $this->wxr_cdata($post_obj->post_content); ?></content:encoded>
            <excerpt:encoded><?php echo $this->wxr_cdata($post_obj->post_excerpt); ?></excerpt:encoded>
            <wp:post_id><?php echo intval($post_obj->ID); ?></wp:post_id>
            <wp:post_date><?php echo $this->wxr_cdata($post_obj->post_date); ?></wp:post_date>
            <wp:post_date_gmt><?php echo $this->wxr_cdata($post_obj->post_date_gmt); ?></wp:post_date_gmt>
            <wp:comment_status><?php echo $this->wxr_cdata($post_obj->comment_status); ?></wp:comment_status>
            <wp:ping_status><?php echo $this->wxr_cdata($post_obj->ping_status); ?></wp:ping_status>
            <wp:post_name><?php echo $this->wxr_cdata($post_obj->post_name); ?></wp:post_name>
            <wp:status><?php echo $this->wxr_cdata($post_obj->post_status); ?></wp:status>
            <wp:post_parent><?php echo intval($post_obj->post_parent); ?></wp:post_parent>
            <wp:menu_order><?php echo intval($post_obj->menu_order); ?></wp:menu_order>
            <wp:post_type><?php echo $this->wxr_cdata($post_obj->post_type); ?></wp:post_type>
            <wp:post_password><?php echo $this->wxr_cdata($post_obj->post_password); ?></wp:post_password>
            <wp:is_sticky><?php echo intval($post_obj->post_type === 'post' && is_sticky($post_obj->ID)); ?></wp:is_sticky>
            <?php if ($post_obj->post_type === 'attachment'): ?>
                <wp:attachment_url><?php echo $this->wxr_cdata(wp_get_attachment_url($post_obj->ID)); ?></wp:attachment_url>
            <?php endif; ?>
            <?php
            $postmeta = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d", $post_obj->ID));
            if ($postmeta) {
                foreach ($postmeta as $meta):
                    if (apply_filters('wxr_export_skip_postmeta', false, $meta->meta_key, $meta, $post_obj))
                        continue;
                    ?>
                    <wp:postmeta>
                        <wp:meta_key><?php echo $this->wxr_cdata($meta->meta_key); ?></wp:meta_key>
                        <wp:meta_value><?php echo $this->wxr_cdata($meta->meta_value); ?></wp:meta_value>
                    </wp:postmeta>
                <?php endforeach;
            } ?>
            <?php
            $taxonomies = get_object_taxonomies($post_obj->post_type, 'objects');
            if (!empty($taxonomies)) {
                foreach ($taxonomies as $taxonomy_slug => $taxonomy_obj) {
                    if (!$taxonomy_obj->public && !$taxonomy_obj->publicly_queryable && !current_user_can($taxonomy_obj->cap->assign_terms)) {
                        continue;
                    }
                    $terms = get_the_terms($post_obj->ID, $taxonomy_slug);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        foreach ($terms as $term) {
                            ?>
                            <category domain="<?php echo esc_attr($taxonomy_slug); ?>" nicename="<?php echo esc_attr($term->slug); ?>">
                                <?php echo $this->wxr_cdata($term->name); ?></category>
                            <?php
                        }
                    }
                }
            }
            ?>
            <?php
            $comments = get_comments(array('post_id' => $post_obj->ID, 'status' => 'approve', 'order' => 'ASC', 'type__not_in' => 'trackback, pingback')); // More specific comment query
            if ($comments) {
                foreach ($comments as $comment):
                    ?>
                    <wp:comment>
                        <wp:comment_id><?php echo intval($comment->comment_ID); ?></wp:comment_id>
                        <wp:comment_author><?php echo $this->wxr_cdata($comment->comment_author); ?></wp:comment_author>
                        <wp:comment_author_email><?php echo $this->wxr_cdata($comment->comment_author_email); ?>
                        </wp:comment_author_email>
                        <wp:comment_author_url><?php echo esc_url_raw($comment->comment_author_url); ?></wp:comment_author_url>
                        <wp:comment_author_IP><?php echo $this->wxr_cdata($comment->comment_author_IP); ?></wp:comment_author_IP>
                        <wp:comment_date><?php echo $this->wxr_cdata($comment->comment_date); ?></wp:comment_date>
                        <wp:comment_date_gmt><?php echo $this->wxr_cdata($comment->comment_date_gmt); ?></wp:comment_date_gmt>
                        <wp:comment_content><?php echo $this->wxr_cdata($comment->comment_content); ?></wp:comment_content>
                        <wp:comment_approved><?php echo $this->wxr_cdata($comment->comment_approved); ?></wp:comment_approved>
                        <wp:comment_type><?php echo $this->wxr_cdata($comment->comment_type); ?></wp:comment_type>
                        <wp:comment_parent><?php echo intval($comment->comment_parent); ?></wp:comment_parent>
                        <wp:comment_user_id><?php echo intval($comment->user_id); ?></wp:comment_user_id>
                        <?php
                        $commentmeta = get_comment_meta($comment->comment_ID);
                        if ($commentmeta) {
                            foreach ($commentmeta as $meta_key => $meta_values) {
                                foreach ($meta_values as $meta_value):
                                    ?>
                                    <wp:commentmeta>
                                        <wp:meta_key><?php echo $this->wxr_cdata($meta_key); ?></wp:meta_key>
                                        <wp:meta_value><?php echo $this->wxr_cdata($meta_value); ?></wp:meta_value>
                                    </wp:commentmeta>
                                <?php endforeach;
                            }
                        } ?>
                    </wp:comment>
                <?php endforeach;
            } ?>
        </item>
        <?php
        // wp_reset_postdata(); // Not needed if not using setup_postdata or the_post
    }

    private function wxr_cdata($str)
    {
        if (null === $str)
            $str = ''; // Ensure string type
        if (!seems_utf8($str)) {
            // PHP 8.2 compatible UTF-8 encoding with proper encoding detection
            if (function_exists('mb_convert_encoding')) {
                // Detect the current encoding first
                $detected_encoding = mb_detect_encoding($str, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
                if ($detected_encoding && $detected_encoding !== 'UTF-8') {
                    $str = mb_convert_encoding($str, 'UTF-8', $detected_encoding);
                } else {
                    // Fallback to ISO-8859-1 if detection fails
                    $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
                }
            } elseif (function_exists('iconv')) {
                // Try to detect encoding for iconv
                if (function_exists('mb_detect_encoding')) {
                    $detected_encoding = mb_detect_encoding($str, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
                    $from_encoding = $detected_encoding ?: 'ISO-8859-1';
                } else {
                    $from_encoding = 'ISO-8859-1';
                }
                $str = iconv($from_encoding, 'UTF-8//IGNORE', $str);
            } else {
                if (function_exists('mb_convert_encoding')) {
                    $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
                } else {
                    // PHP 8.2+ compatible fallback using manual encoding or a polyfill
                    $str = $this->manual_utf8_encode($str);
                }
                /*
                // Fallback for older PHP versions or if extensions are missing
                if (function_exists('utf8_encode') && version_compare(PHP_VERSION, '8.2', '<')) {
                    $str = utf8_encode($str);
                } else {
                    // PHP 8.2+ compatible fallback using manual encoding
                    $str = $this->manual_utf8_encode($str);
                }*/
            }
        }
        $str = '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $str) . ']]>';
        return $str;
    }

    /**
     * Manual UTF-8 encoding fallback for PHP 8.2+ compatibility
     * Converts ISO-8859-1 (Latin-1) to UTF-8 manually
     * 
     * @param string $str Input string in ISO-8859-1 encoding
     * @return string UTF-8 encoded string
     */
    private function manual_utf8_encode($str)
    {
        if (empty($str)) {
            return $str;
        }

        $utf8_string = '';
        $len = strlen($str);
        
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($str[$i]);
            
            if ($byte < 0x80) {
                // ASCII characters (0-127) remain unchanged
                $utf8_string .= chr($byte);
            } else {
                // Convert extended ASCII (128-255) to UTF-8
                // For ISO-8859-1, this is a simple two-byte UTF-8 encoding
                $utf8_string .= chr(0xC0 | ($byte >> 6));
                $utf8_string .= chr(0x80 | ($byte & 0x3F));
            }
        }
        
        return $utf8_string;
    }

    public function shortcode_handler($atts)
    {
        if (!current_user_can('export')) {
            return '<p class="cpt-export-message cpt-export-error">' . __('You do not have permission to use this shortcode.', 'cpt-export') . '</p>';
        }

        $atts = shortcode_atts(array(
            'post_type' => '',
            'author' => '',
            'status' => '',
            'start_date' => '',
            'end_date' => '',
            'delete' => '0',
            'permanent' => '0',
            'delete_media' => '0',
            'save_folder' => '',
            'compress' => '0',
            'download' => '1',
            'return_data' => '0'
        ), $atts, 'cpt_export');

        $post_type = sanitize_text_field($atts['post_type']);
        if (empty($post_type)) {
            return '<p class="cpt-export-message cpt-export-error">' . __('Error: post_type parameter is required for the shortcode.', 'cpt-export') . '</p>';
        }
        if (!post_type_exists($post_type)) {
            return '<p class="cpt-export-message cpt-export-error">' . sprintf(__('Error: Post type "%s" does not exist.', 'cpt-export'), esc_html($post_type)) . '</p>';
        }

        $export_and_delete = ($atts['delete'] === '1');
        $delete_permanently = ($atts['permanent'] === '1' && $export_and_delete);
        $delete_media = ($atts['delete_media'] === '1' && $export_and_delete);

        if ($export_and_delete && !current_user_can('delete_posts')) {
            return '<p class="cpt-export-message cpt-export-error">' . __('You do not have permission to delete posts via this shortcode.', 'cpt-export') . '</p>';
        }

        $export_result = $this->_prepare_export_data(
            $post_type,
            sanitize_text_field($atts['author']),
            sanitize_text_field($atts['status']),
            sanitize_text_field($atts['start_date']),
            sanitize_text_field($atts['end_date']),
            ($atts['compress'] === '1')
        );

        if ($export_result['status'] === 'error') {
            return '<p class="cpt-export-message cpt-export-error">' . esc_html($export_result['message']) . '</p>';
        }

        $data = $export_result['data'];
        $message = '';

        if (!empty($atts['save_folder'])) {
            $file_path = $this->save_to_uploads_folder($data['file_data'], $data['filename'], sanitize_text_field($atts['save_folder']));
            if ($file_path) {
                if ($export_and_delete) {
                    $this->delete_posts_and_media($data['posts_objects'], $data['attachments_objects'], $delete_permanently, $delete_media);
                }
                $uploads_dir = wp_upload_dir();
                $full_url = $uploads_dir['baseurl'] . '/' . $file_path;
                $file_type = ($atts['compress'] === '1') ? 'ZIP' : 'XML';
                $message = sprintf(
                    __('Successfully saved %1$s export (%2$d posts, %3$d attachments) to: %4$s', 'cpt-export'),
                    $file_type,
                    $data['posts_count'],
                    $data['attachments_count'],
                    '<br><a href="' . esc_url($full_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($file_path) . '</a>'
                );
                return '<p class="cpt-export-message cpt-export-success">' . $message . '</p>';
            } else {
                return '<p class="cpt-export-message cpt-export-error">' . __('Error: Could not save file to the specified folder via shortcode.', 'cpt-export') . '</p>';
            }
        }

        if ($atts['return_data'] === '1') {
            if ($export_and_delete) {
                $this->delete_posts_and_media($data['posts_objects'], $data['attachments_objects'], $delete_permanently, $delete_media);
            }
            return '<pre class="cpt-export-data">' . esc_html($atts['compress'] === '1' ? __('ZIP data cannot be displayed directly. Use download or save_folder.', 'cpt-export') : $data['file_data']) . '</pre>';
        }

        if ($atts['download'] === '1') {
            if (headers_sent($file, $line)) {
                error_log('CPT Export Shortcode: Headers already sent from ' . $file . ':' . $line . '. Cannot initiate download for ' . $data['filename']);
                $message = sprintf(
                    __('Export ready (%1$d posts, %2$d attachments). Headers already sent, cannot download directly. File: %3$s', 'cpt-export'),
                    $data['posts_count'],
                    $data['attachments_count'],
                    esc_html($data['filename'])
                );
                return '<p class="cpt-export-message cpt-export-warning">' . $message . '<br/>' . __('Please try exporting from the Tools > CPT Export page for direct download.', 'cpt-export') . '</p>';
            }
            if (ob_get_level())
                ob_end_clean(); // Clean buffer before download

            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename=' . $data['filename']);
            header('Content-Type: ' . $data['content_type']);
            header('Content-Length: ' . strlen($data['file_data']));
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');

            echo $data['file_data'];

            if ($export_and_delete) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    flush();
                }
                $this->delete_posts_and_media($data['posts_objects'], $data['attachments_objects'], $delete_permanently, $delete_media);
            }
            exit;
        }

        $delete_action_msg = '';
        if ($export_and_delete) {
            $this->delete_posts_and_media($data['posts_objects'], $data['attachments_objects'], $delete_permanently, $delete_media);
            $delete_action_msg = $delete_permanently ? __('and posts/media permanently deleted', 'cpt-export') : __('and posts moved to trash', 'cpt-export');
            if ($delete_media && $delete_permanently)
                $delete_action_msg .= __('/media also permanently deleted', 'cpt-export');
            else if ($delete_media)
                $delete_action_msg .= __('/media also permanently deleted (even if posts only trashed)', 'cpt-export');
        }

        $message = sprintf(
            __('Export processed for %1$d posts and %2$d attachments. %3$s No download or save action specified.', 'cpt-export'),
            $data['posts_count'],
            $data['attachments_count'],
            $delete_action_msg
        );
        return '<p class="cpt-export-message cpt-export-info">' . $message . '</p>';
    }
}

// Initialize the plugin
new CPT_Export_Tool();