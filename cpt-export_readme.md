# CPT Export

**Contributors:** mullibahr, ai-assistant  
**Tags:** export, custom post types, xml, backup, delete, cpt, migration  
**Requires at least:** 5.0  
**Tested up to:** 6.4  
**Requires PHP:** 7.4  
**Stable tag:** 1.0.95  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

Export Custom Post Types to XML format with advanced filtering, optional deletion, and form value persistence.

## Description

CPT Export is a powerful WordPress plugin that allows you to export Custom Post Types to WordPress eXtended RSS (WXR) format with comprehensive filtering options. The plugin supports date ranges, author filtering, post status selection, and optional post deletion after export.

### Key Features

* **Export any Custom Post Type** to standard WordPress XML format
* **Advanced filtering options:**
  * Filter by author
  * Filter by post status (publish, draft, private, pending, future)
  * Filter by date range (start and end dates)
* **Flexible export options:**
  * Download files directly
  * Save to uploads folder with custom folder names
  * Optional ZIP compression
* **Optional deletion features:**
  * Move posts to trash after export
  * Permanently delete posts (bypass trash)
  * Permanently delete attached media
* **User experience enhancements:**
  * Form remembers your last used settings
  * Safety confirmations for destructive operations
  * Reset form to defaults option
* **Includes all related content:**
  * Post meta fields
  * Taxonomies and terms
  * Comments and comment meta
  * Featured images and attached media
  * Author information

### Use Cases

* **Site migration** - Export specific post types for import to another site
* **Content backup** - Create XML backups of important custom post types
* **Content cleanup** - Export and delete old content in one operation
* **Development workflows** - Move content between staging and production
* **Client handoffs** - Provide clean exports without sensitive data

## Installation

### Manual Installation

1. Download the plugin ZIP file
2. Upload to your `/wp-content/plugins/` directory
3. Unzip the file
4. Activate the plugin through the 'Plugins' menu in WordPress

### Via WordPress Admin

1. Go to Plugins > Add New
2. Upload the plugin ZIP file
3. Click "Install Now"
4. Activate the plugin

## Usage

### Admin Interface

1. Navigate to **Tools > CPT Export** in your WordPress admin
2. Select the **Post Type** you want to export
3. Configure filtering options:
   * **Author**: Choose specific author or all authors
   * **Status**: Select post status or all statuses
   * **Date Range**: Set start and/or end dates (optional)
4. Choose export options:
   * **Save Folder**: Enter folder name to save in uploads directory (optional)
   * **Compress**: Check to create ZIP file instead of XML
5. Configure deletion options (optional):
   * **Move to Trash**: Move posts to trash after export
   * **Delete Permanently**: Permanently delete posts (bypass trash)
   * **Delete Media**: Permanently delete attached media files
6. Click **Download Export File** or **Save Export File**

### Form Memory Feature

The plugin remembers your last used settings (except deletion options for safety):
* Post type selection
* Author and status filters  
* Date ranges
* Save folder preferences
* Compression settings

Use the "Reset to defaults" button to clear remembered values.

### Shortcode Usage

```
[cpt_export post_type="product" author="1" status="publish" start_date="2024-01-01" end_date="2024-12-31"]
```

#### Shortcode Parameters

* **post_type** (required): Post type to export
* **author** (optional): User ID of author to filter by
* **status** (optional): Post status filter
* **start_date** (optional): Start date (YYYY-MM-DD format)
* **end_date** (optional): End date (YYYY-MM-DD format)
* **delete** (optional): "1" to delete after export, "0" to export only
* **permanent** (optional): "1" for permanent deletion, "0" for trash
* **delete_media** (optional): "1" to delete media, "0" to preserve
* **save_folder** (optional): Folder name in uploads directory
* **compress** (optional): "1" for ZIP, "0" for XML
* **download** (optional): "1" to download, "0" for message only
* **return_data** (optional): "1" to return XML content as text

#### Shortcode Examples

```
// Basic export
[cpt_export post_type="product"]

// Export with date range and compression
[cpt_export post_type="event" start_date="2024-01-01" end_date="2024-12-31" compress="1"]

// Export and save to folder
[cpt_export post_type="portfolio" save_folder="exports" compress="1"]

// Export published posts by specific author
[cpt_export post_type="post" author="1" status="publish"]
```

## Safety Features

### Deletion Confirmations

When deletion options are selected, the plugin provides clear confirmation dialogs:

* **Move to Trash**: Standard confirmation for reversible action
* **Permanent Deletion**: Strong warning for irreversible post deletion
* **Media Deletion**: Special warning for permanent media file removal
* **Combined Operations**: Detailed warnings for multiple destructive actions

### Form Value Persistence Safety

* Deletion-related checkboxes are never remembered between sessions
* Only safe, non-destructive settings are preserved
* Each user has their own set of remembered values

## Technical Requirements

* **WordPress**: 5.0 or higher
* **PHP**: 7.4 or higher
* **Permissions**: Users need 'export' capability (usually Administrators and Editors)
* **For deletion features**: Users need 'delete_posts' capability
* **For ZIP compression**: ZipArchive PHP extension (usually included)

## File Naming Convention

Exported files follow this pattern:
```
digma-[post_type]-[start_date|all-start]-[end_date|all-end].[xml|zip]
```

Examples:
* `digma-product-2024-01-01-2024-12-31.xml`
* `digma-event-all-start-all-end.zip`

## Frequently Asked Questions

### Can I export built-in post types like posts and pages?

Yes, the plugin works with all public post types including WordPress built-in posts and pages.

### What happens to custom fields and meta data?

All post meta data is included in the export and can be imported to another WordPress site.

### Can I undo a deletion operation?

* **Trash operations**: Yes, posts can be restored from WordPress trash
* **Permanent deletions**: No, these cannot be undone
* **Media deletions**: No, permanently deleted media files cannot be recovered

### Why don't my deletion settings get remembered?

For safety reasons, all deletion-related checkboxes reset to unchecked each time you visit the page. Only safe export settings are remembered.

### Can I run exports programmatically?

Yes, you can use the shortcode in templates or call the class methods directly in custom code.

### What if I have a large number of posts?

The plugin handles large exports well, but for very large datasets:
* Use date ranges to break exports into smaller chunks
* Consider using the save folder option instead of direct download
* Enable ZIP compression to reduce file sizes

## Changelog

### 1.0.95
* **PHP 8.2 Compatibility**: Fixed deprecated `utf8_encode()` function
* Implemented fallback chain using `mb_convert_encoding()` and `iconv()`
* Added error logging for encoding issues
* Enhanced UTF-8 handling with multiple encoding methods
* Future-proofed for PHP 9.0 compatibility

### 1.0.93
* Added form value persistence - remembers last used settings
* Implemented proper confirmation dialogs for delete operations
* Added "Reset to defaults" functionality
* Enhanced user experience with visual indicators
* Improved safety by not persisting deletion settings
* Fixed JavaScript confirmation flow

### 1.0.92
* Added ZIP compression option
* Implemented save to uploads folder feature
* Enhanced shortcode with additional parameters
* Added comprehensive error handling
* Improved file naming convention
* Added translation support framework

### 1.0.0
* Initial release
* Basic CPT export functionality
* Date range and author filtering
* Optional post deletion features

## Support

For support, bug reports, or feature requests, please contact the plugin authors or visit the plugin's homepage at https://site2goal.co.il

## Contributing

This plugin is open to contributions. Please ensure any modifications maintain the safety features and user experience standards.

## Credits

* **Primary Development**: Mulli Bahr (https://site2goal.co.il)
* **AI Assistant**: Enhanced functionality and safety features
* **WordPress Community**: Inspiration and best practices