<?php
/**
 * CPT Export Admin Page Template
 * 
 * @package CPT_Export
 * @version 1.0.97
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Display any notices set by handle_export_admin_request
$notices = $this->get_admin_page_notices();
if (!empty($notices)) {
    foreach ($notices as $notice) {
        echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }
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
    <p><?php _e('When you click the button below WordPress will create an XML file for you to save to your computer.', 'cpt-export'); ?></p>
    <p><?php _e('This format, which is called WordPress eXtended RSS or WXR, will contain your posts, custom fields, categories, and other content.', 'cpt-export'); ?></p>

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

    <form method="post" id="cpt-export-form" action="<?php echo esc_url(admin_url('tools.php?page=cpt-export-tool')); ?>">
        <?php wp_nonce_field(CPT_Export_Tool::NONCE_ACTION, 'cpt_export_nonce_field'); ?>

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
                        <p class="description"><?php _e('Select the post type you want to export.', 'cpt-export'); ?></p>
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
                            <option value="" <?php selected('', $form_data['cpt_status']); ?>><?php _e('All statuses', 'cpt-export'); ?></option>
                            <option value="publish" <?php selected('publish', $form_data['cpt_status']); ?>><?php _e('Published', 'cpt-export'); ?></option>
                            <option value="draft" <?php selected('draft', $form_data['cpt_status']); ?>><?php _e('Draft', 'cpt-export'); ?></option>
                            <option value="private" <?php selected('private', $form_data['cpt_status']); ?>><?php _e('Private', 'cpt-export'); ?></option>
                            <option value="pending" <?php selected('pending', $form_data['cpt_status']); ?>><?php _e('Pending Review', 'cpt-export'); ?></option>
                            <option value="future" <?php selected('future', $form_data['cpt_status']); ?>><?php _e('Scheduled', 'cpt-export'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Date Range', 'cpt-export'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php _e('Date Range', 'cpt-export'); ?></legend>
                            <label for="cpt_start_date"><?php _e('Start Date:', 'cpt-export'); ?>
                                <input type="date" name="cpt_start_date" id="cpt_start_date" value="<?php echo esc_attr($form_data['cpt_start_date']); ?>">
                            </label>
                            <br><br>
                            <label for="cpt_end_date"><?php _e('End Date:', 'cpt-export'); ?>
                                <input type="date" name="cpt_end_date" id="cpt_end_date" value="<?php echo esc_attr($form_data['cpt_end_date']); ?>">
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
                            <div id="delete-confirmation-message-placeholder" style="color: #d63638; font-weight: bold; margin-bottom: 10px;"></div>
                            <label for="cpt_export_and_delete">
                                <input type="checkbox" name="cpt_export_and_delete" id="cpt_export_and_delete" value="1" <?php checked($form_data['cpt_export_and_delete']); ?>>
                                <?php _e('Move to Trash', 'cpt-export'); ?>
                            </label>
                            <br><br>
                            <label for="cpt_delete_permanently">
                                <input type="checkbox" name="cpt_delete_permanently" id="cpt_delete_permanently" value="1" <?php checked($form_data['cpt_delete_permanently']); ?> <?php disabled(!$form_data['cpt_export_and_delete']); ?>>
                                <?php _e('Delete Permanently (bypass trash)', 'cpt-export'); ?>
                            </label>
                            <br><br>
                            <label for="cpt_delete_media">
                                <input type="checkbox" name="cpt_delete_media" id="cpt_delete_media" value="1" <?php checked($form_data['cpt_delete_media']); ?> <?php disabled(!$form_data['cpt_export_and_delete']); ?>>
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
                    <th scope="row"><label for="cpt_save_folder"><?php _e('Save in Folder', 'cpt-export'); ?></label></th>
                    <td>
                        <input type="text" name="cpt_save_folder" id="cpt_save_folder" class="regular-text" value="<?php echo esc_attr($form_data['cpt_save_folder']); ?>" placeholder="<?php esc_attr_e('e.g., plfiles/stas/2022 or leave empty to download', 'cpt-export'); ?>">
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
        
        <!-- Hidden field to ensure cpt_export_submit is always sent -->
        <input type="hidden" name="cpt_export_submit" value="1">
        
        <p class="submit">
            <input type="submit" name="cpt_export_submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Download Export File', 'cpt-export'); ?>">
        </p>
    </form>
</div>