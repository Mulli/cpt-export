<?php
/**
 * CPT Export File Handler Class
 * 
 * Handles file operations, ZIP creation, and folder management
 * 
 * @package CPT_Export
 * @version 1.0.97
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CPT_Export_File_Handler
{
    public function create_zip_file($xml_content, $zip_filename)
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

    public function save_to_uploads_folder($file_content, $filename, $folder_name)
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
}