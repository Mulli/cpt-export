<?php
/**
 * CPT Export Core Class
 * 
 * Main plugin functionality and admin interface
 * 
 * @package CPT_Export
 * @version 1.0.97
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CPT_Export_Tool
{
    const NONCE_ACTION = 'cpt_export_nonce_action';
    const USER_META_KEY = 'cpt_export_last_values';
    
    private $admin_page_notices = [];
    private $xml_generator;
    private $file_handler;

    public function __construct()
    {
        $this->admin_page_notices = [];
        $this->xml_generator = new CPT_Export_XML_Generator();
        $this->file_handler = new CPT_Export_File_Handler();
        
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
        $plugin_rel_path = dirname(plugin_basename(CPT_EXPORT_PLUGIN_DIR . 'cpt-export.php')) . '/languages';
        load_plugin_textdomain('cpt-export', false, $plugin_rel_path);
    }

    public function translation_admin_notice()
    {
        if (current_user_can('manage_options')) {
            $locale = get_locale();
            $plugin_lang_file_wp_dir = WP_LANG_DIR . '/plugins/cpt-export-' . $locale . '.mo';
            $plugin_lang_file_plugin_dir = CPT_EXPORT_PLUGIN_DIR . 'languages/cpt-export-' . $locale . '.mo';

            if (!file_exists($plugin_lang_file_wp_dir) && !file_exists($plugin_lang_file_plugin_dir)) {
                $screen = get_current_screen();
                if ($screen && ($screen->id === 'tools_page_cpt-export-tool' || $screen->base === 'tools')) {
                    echo '<div class="notice notice-info is-dismissible"><p>';
                    printf(
                        esc_html__('CPT Export: To use translations for your language (%1$s), please ensure .mo files are placed in %2$s or %3$s. A .pot file is available in the plugin\'s %4$s directory to create new translations.', 'cpt-export'),
                        '<strong>' . esc_html($locale) . '</strong>',
                        '<code>wp-content/languages/plugins/</code>',
                        '<code>' . esc_html(plugin_basename(CPT_EXPORT_PLUGIN_DIR)) . '/languages/</code>',
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
        
        // Enqueue CSS
        wp_enqueue_style(
            'cpt-export-admin',
            CPT_EXPORT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CPT_EXPORT_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'cpt-export-admin',
            CPT_EXPORT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CPT_EXPORT_VERSION,
            true
        );
        
        // Localize script for AJAX and translations
        wp_localize_script('cpt-export-admin', 'cpt_export_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cpt_export_get_categories'),
            'strings' => array(
                'all_categories' => __('All categories', 'cpt-export'),
                'reset_confirm' => __('Are you sure you want to reset all form fields to their default values?', 'cpt-export'),
                'save' => __('Save', 'cpt-export'),
                'download' => __('Download', 'cpt-export'),
                'export_file' => __('Export File', 'cpt-export'),
                'and_permanently_delete_posts_media' => __('and Permanently Delete Posts + Media', 'cpt-export'),
                'and_permanently_delete_posts' => __('and Permanently Delete Posts', 'cpt-export'),
                'and_move_posts_trash_delete_media' => __('and Move Posts to Trash + Delete Media', 'cpt-export'),
                'and_move_posts_trash' => __('and Move Posts to Trash', 'cpt-export'),
                'confirm_permanent_deletion' => __('Confirm Permanent Deletion', 'cpt-export'),
                'confirm_media_deletion' => __('Confirm Media Deletion', 'cpt-export'),
                'confirm_move_trash' => __('Confirm Move to Trash', 'cpt-export'),
                'warning_permanent_delete_posts_media' => __('You are about to export and PERMANENTLY DELETE posts AND their media.\\n\\nThis action cannot be undone!\\n\\nAre you sure you want to proceed?', 'cpt-export'),
                'warning_permanent_delete_posts' => __('You are about to export and PERMANENTLY DELETE posts.\\n\\nMedia will be preserved, but posts will be permanently deleted.\\n\\nThis action cannot be undone!\\n\\nAre you sure you want to proceed?', 'cpt-export'),
                'warning_delete_media' => __('You are about to export posts, move them to trash, and PERMANENTLY DELETE their media.\\n\\nPosts can be restored from trash, but media deletion cannot be undone!\\n\\nAre you sure you want to proceed?', 'cpt-export'),
                'warning_move_trash' => __('You are about to export posts and MOVE them to trash.\\n\\nMedia will be preserved and posts can be restored from trash.\\n\\nDo you want to proceed?', 'cpt-export')
            ),
            'form_data' => array(
                'category' => $this->get_last_form_values()['cpt_category'] ?? ''
            )
        ));
    }

    /**
     * Get last used form values for the current user
     */
    private function get_last_form_values()
    {
        $user_id = get_current_user_id();
        $last_values = get_user_meta($user_id, self::USER_META_KEY, true);
        
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

    public function admin_page()
    {
        // Include the admin page template
        $form_data = $this->get_last_form_values();
        
        // Handle POST data override
        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['cpt_export_nonce_field'])) {
            $defaults = $this->get_default_form_values();
            foreach ($defaults as $key => $default_value) {
                if (in_array($key, ['cpt_export_and_delete', 'cpt_delete_permanently', 'cpt_delete_media', 'cpt_compress'])) {
                    $form_data[$key] = (isset($_POST[$key]) && $_POST[$key] === '1');
                } elseif (isset($_POST[$key])) {
                    $form_data[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
                }
            }
        }
        
        // Include admin page template
        include CPT_EXPORT_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function handle_export_admin_request()
    {
        // Debug: Log all POST data to see what's being sent
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('CPT Export DEBUG - POST data: ' . print_r($_POST, true));
            error_log('CPT Export DEBUG - REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        }

        // Skip AJAX requests - they have their own handlers
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Skip if this is an AJAX action
        if (isset($_POST['action']) && $_POST['action'] === 'cpt_export_get_categories') {
            return;
        }

        if (!isset($_POST['cpt_export_submit']) || !isset($_POST['cpt_export_nonce_field'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('CPT Export DEBUG - Missing cpt_export_submit or nonce field');
            }
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cpt_export_nonce_field'])), self::NONCE_ACTION)) {
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('Security check failed: Invalid nonce.', 'cpt-export')];
            return;
        }

        if (!current_user_can('export')) {
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('You do not have sufficient permissions to export content.', 'cpt-export')];
            return;
        }

        $post_type = isset($_POST['cpt_post_type']) ? sanitize_text_field($_POST['cpt_post_type']) : '';
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

        // Save form values for future use
        $form_data_to_save = array(
            'cpt_post_type' => $post_type,
            'cpt_category' => $category,
            'cpt_author' => $author,
            'cpt_status' => $status,
            'cpt_start_date' => $start_date,
            'cpt_end_date' => $end_date,
            'cpt_export_and_delete' => false,
            'cpt_delete_permanently' => false,
            'cpt_delete_media' => false,
            'cpt_save_folder' => $save_folder,
            'cpt_compress' => $compress
        );
        $this->save_form_values($form_data_to_save);

        if (empty($post_type)) {
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('Please select a post type to export.', 'cpt-export')];
            return;
        }

        if ($export_and_delete && !current_user_can('delete_posts')) {
            $this->admin_page_notices[] = ['type' => 'error', 'message' => __('You do not have sufficient permissions to delete content.', 'cpt-export')];
            return;
        }

        $export_result = $this->_prepare_export_data($post_type, $category, $author, $status, $start_date, $end_date, $compress);

        if ($export_result['status'] === 'error') {
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
            $file_path = $this->file_handler->save_to_uploads_folder($data['file_data'], $data['filename'], $save_folder);
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
            if (ob_get_level()) {
                ob_end_clean();
            }

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
                $date_query['after'] = array(
                    'year'  => date('Y', strtotime($start_date)),
                    'month' => date('m', strtotime($start_date)),
                    'day'   => date('d', strtotime($start_date)),
                    'inclusive' => true
                );
            }
            if (!empty($end_date)) {
                $date_query['before'] = array(
                    'year'  => date('Y', strtotime($end_date)),
                    'month' => date('m', strtotime($end_date)),
                    'day'   => date('d', strtotime($end_date)),
                    'inclusive' => true
                );
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

        $xml_content = $this->xml_generator->generate_xml($posts, $attachments, $post_type);
        $filename = $this->generate_filename($post_type, $start_date, $end_date, $compress);

        $file_data = $xml_content;
        $content_type = 'text/xml; charset=' . get_option('blog_charset');

        if ($compress) {
            if (!class_exists('ZipArchive')) {
                return ['status' => 'error', 'message' => __('Error: ZIP compression is not available on this server. ZipArchive extension is required.', 'cpt-export')];
            }
            $zip_result = $this->file_handler->create_zip_file($xml_content, $filename);
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

    /**
     * Shortcode handler for [cpt_export] shortcode
     */
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
            '', // category not supported in shortcode for simplicity
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
            $file_path = $this->file_handler->save_to_uploads_folder($data['file_data'], $data['filename'], sanitize_text_field($atts['save_folder']));
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
                ob_end_clean();

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

    /**
     * Get admin page notices for display in template
     */
    public function get_admin_page_notices()
    {
        return $this->admin_page_notices;
    }
}