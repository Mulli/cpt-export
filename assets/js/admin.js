/**
 * CPT Export Admin JavaScript
 * 
 * @package CPT_Export
 * @version 1.0.97
 */

jQuery(document).ready(function ($) {
    'use strict';

    // Debounce function for performance
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
    }

    // Cache DOM elements
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

    // Initialize
    init();

    function init() {
        // Load categories on page load if post type is already selected
        var initialPostType = $postTypeSelect.val();
        var initialCategory = cpt_export_ajax.form_data.category || '';
        if (initialPostType) {
            loadCategories(initialPostType, initialCategory);
        }

        // Update button text and checkbox states
        updateButtonTextAndInteractivity();

        // Bind events
        bindEvents();
    }

    function bindEvents() {
        // Reset form button
        $resetButton.on('click', handleFormReset);

        // Post type change
        $postTypeSelect.on('change', handlePostTypeChange);

        // Delete checkbox changes
        $exportAndDeleteCheckbox.on('change', function() {
            updateButtonTextAndInteractivity();
            clearFormConfirmation();
        });
        $deletePermanentlyCheckbox.on('change', function() {
            updateButtonTextAndInteractivity();
            clearFormConfirmation();
        });
        $deleteMediaCheckbox.on('change', function() {
            updateButtonTextAndInteractivity();
            clearFormConfirmation();
        });

        // Save folder input change
        $saveFolderInput.on('input', debounce(updateButtonTextAndInteractivity, 250));

        // Form submission
        $exportForm.on('submit', handleFormSubmit);

        // Clear confirmation flag when form changes
        $exportForm.on('change input', clearFormConfirmation);
    }

    // Clear form confirmation flag
    function clearFormConfirmation() {
        $exportForm.removeData('confirmed');
    }

    // Handle form reset
    function handleFormReset(e) {
        e.preventDefault();
        if (confirm(cpt_export_ajax.strings.reset_confirm)) {
            resetFormToDefaults();
            updateButtonTextAndInteractivity();
            clearFormConfirmation();
            $(this).closest('.notice').fadeOut();
        }
    }

    // Reset form to default values
    function resetFormToDefaults() {
        $postTypeSelect.val('');
        $categorySelect.html('<option value="">' + cpt_export_ajax.strings.all_categories + '</option>').val('');
        $('#cpt_author').val('');
        $('#cpt_status').val('');
        $('#cpt_start_date').val('');
        $('#cpt_end_date').val('');
        $exportAndDeleteCheckbox.prop('checked', false);
        $deletePermanentlyCheckbox.prop('checked', false);
        $deleteMediaCheckbox.prop('checked', false);
        $saveFolderInput.val('');
        $('#cpt_compress').prop('checked', false);
    }

    // Handle post type change
    function handlePostTypeChange() {
        var postType = $(this).val();
        loadCategories(postType, '');
        clearFormConfirmation();
    }

    // Load categories based on post type
    function loadCategories(postType, selectedCategory) {
        if (!postType) {
            $categorySelect.html('<option value="">' + cpt_export_ajax.strings.all_categories + '</option>');
            return;
        }

        // Show loading state
        $categoryLoading.show();
        $categorySelect.prop('disabled', true).addClass('loading');

        // AJAX request
        $.post(cpt_export_ajax.ajax_url, {
            action: 'cpt_export_get_categories',
            post_type: postType,
            selected: selectedCategory || '',
            nonce: cpt_export_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                $categorySelect.html(response.data);
            } else {
                $categorySelect.html('<option value="">' + cpt_export_ajax.strings.all_categories + '</option>');
                console.error('Failed to load categories:', response);
            }
        })
        .fail(function(xhr, status, error) {
            $categorySelect.html('<option value="">' + cpt_export_ajax.strings.all_categories + '</option>');
            console.error('AJAX request failed:', status, error);
        })
        .always(function() {
            // Hide loading state
            $categoryLoading.hide();
            $categorySelect.prop('disabled', false).removeClass('loading');
        });
    }

    // Update button text and checkbox interactivity
    function updateButtonTextAndInteractivity() {
        var isExportAndDeleteChecked = $exportAndDeleteCheckbox.is(':checked');
        var isPermanent = $deletePermanentlyCheckbox.is(':checked');
        var isDeleteMedia = $deleteMediaCheckbox.is(':checked');
        var saveFolder = $saveFolderInput.val().trim();
        var buttonText = '';
        var action = saveFolder ? cpt_export_ajax.strings.save : cpt_export_ajax.strings.download;

        // Update dependent checkbox disabled state
        $deletePermanentlyCheckbox.prop('disabled', !isExportAndDeleteChecked);
        $deleteMediaCheckbox.prop('disabled', !isExportAndDeleteChecked);

        // If parent checkbox is unchecked, uncheck children
        if (!isExportAndDeleteChecked) {
            $deletePermanentlyCheckbox.prop('checked', false);
            $deleteMediaCheckbox.prop('checked', false);
            // Update variables after unchecking
            isPermanent = false;
            isDeleteMedia = false;
        }

        // Determine button text based on actions
        if (isExportAndDeleteChecked) {
            if (isPermanent && isDeleteMedia) {
                buttonText = action + ' ' + cpt_export_ajax.strings.and_permanently_delete_posts_media;
            } else if (isPermanent) {
                buttonText = action + ' ' + cpt_export_ajax.strings.and_permanently_delete_posts;
            } else if (isDeleteMedia) {
                buttonText = action + ' ' + cpt_export_ajax.strings.and_move_posts_trash_delete_media;
            } else {
                buttonText = action + ' ' + cpt_export_ajax.strings.and_move_posts_trash;
            }
            $submitButton.removeClass('button-primary').addClass('button-secondary');
        } else {
            buttonText = action + ' ' + cpt_export_ajax.strings.export_file;
            $submitButton.removeClass('button-secondary').addClass('button-primary');
        }

        $submitButton.val(buttonText);
    }

    // Handle form submission
    function handleFormSubmit(e) {
        // Debug: Log form submission details
        console.log('CPT Export: Form submission started');
        console.log('CPT Export: Submit button name:', $submitButton.attr('name'));
        console.log('CPT Export: Submit button value:', $submitButton.val());
        console.log('CPT Export: Form action:', $exportForm.attr('action'));
        console.log('CPT Export: Form method:', $exportForm.attr('method'));

        // Reset previous errors
        $postTypeError.hide();
        $deleteConfirmationPlaceholder.empty();

        // Validate post type selection
        var postType = $postTypeSelect.val();
        if (!postType) {
            e.preventDefault();
            $postTypeError.show();
            $postTypeSelect.focus();
            console.log('CPT Export: Form submission prevented - no post type selected');
            return false;
        }

        // Handle delete confirmations - but only prevent if user cancels
        if ($exportAndDeleteCheckbox.is(':checked')) {
            // Check if we've already confirmed this submission
            if (!$exportForm.data('confirmed')) {
                var isPermanent = $deletePermanentlyCheckbox.is(':checked');
                var isDeleteMedia = $deleteMediaCheckbox.is(':checked');
                var confirmMessage = '';
                var modalTitle = '';

                // Determine confirmation message based on selected actions
                if (isPermanent && isDeleteMedia) {
                    modalTitle = cpt_export_ajax.strings.confirm_permanent_deletion;
                    confirmMessage = cpt_export_ajax.strings.warning_permanent_delete_posts_media;
                } else if (isPermanent) {
                    modalTitle = cpt_export_ajax.strings.confirm_permanent_deletion;
                    confirmMessage = cpt_export_ajax.strings.warning_permanent_delete_posts;
                } else if (isDeleteMedia) {
                    modalTitle = cpt_export_ajax.strings.confirm_media_deletion;
                    confirmMessage = cpt_export_ajax.strings.warning_delete_media;
                } else {
                    modalTitle = cpt_export_ajax.strings.confirm_move_trash;
                    confirmMessage = cpt_export_ajax.strings.warning_move_trash;
                }

                // Show confirmation dialog
                console.log('CPT Export: Showing confirmation dialog');
                if (confirm(modalTitle + '\n\n' + confirmMessage)) {
                    // User confirmed - mark form as confirmed and allow submission
                    $exportForm.data('confirmed', true);
                    console.log('CPT Export: User confirmed deletion, proceeding with submission');
                } else {
                    // User cancelled - prevent submission
                    e.preventDefault();
                    console.log('CPT Export: User cancelled deletion, preventing submission');
                    return false;
                }
            } else {
                console.log('CPT Export: Form already confirmed, proceeding');
            }
        }

        // Ensure submit button has the correct attributes
        if (!$submitButton.attr('name') || $submitButton.attr('name') !== 'cpt_export_submit') {
            console.log('CPT Export: Fixing submit button name attribute');
            $submitButton.attr('name', 'cpt_export_submit');
        }

        console.log('CPT Export: Form submission proceeding - button name:', $submitButton.attr('name'));
        
        // Use setTimeout to add loading state AFTER form submission starts
        setTimeout(function() {
            $submitButton.prop('disabled', true).addClass('loading');
            $exportForm.addClass('loading');
        }, 10);
        
        // Allow form to submit immediately
        return true;
    }

    // Utility function to show temporary messages
    function showTempMessage(message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;

        var $message = $('<div class="notice notice-' + type + ' is-dismissible temp-message"><p>' + message + '</p></div>');
        $('.wrap h1').after($message);

        setTimeout(function() {
            $message.fadeOut(function() {
                $(this).remove();
            });
        }, duration);
    }

    // Handle AJAX errors globally
    $(document).ajaxError(function(event, xhr, settings, thrownError) {
        if (settings.url === cpt_export_ajax.ajax_url) {
            console.error('CPT Export AJAX Error:', {
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText,
                thrownError: thrownError
            });
        }
    });

    // Handle browser back/forward navigation
    $(window).on('popstate', function() {
        // Reset form loading states if user navigates away and back
        $submitButton.prop('disabled', false).removeClass('loading');
        $exportForm.removeClass('loading');
        clearFormConfirmation();
    });

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl/Cmd + Enter to submit form
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 13) {
            if ($exportForm.length && $postTypeSelect.val()) {
                $exportForm.submit();
            }
        }
        
        // Escape key to clear form errors
        if (e.keyCode === 27) {
            $postTypeError.hide();
            $deleteConfirmationPlaceholder.empty();
        }
    });

    // Auto-save form state to localStorage for persistence across browser sessions
    // (Only if browser supports localStorage)
    if (typeof(Storage) !== "undefined") {
        // Save form state periodically
        setInterval(function() {
            saveFormState();
        }, 30000); // Every 30 seconds

        // Save on form change
        $exportForm.on('change input', debounce(saveFormState, 1000));

        function saveFormState() {
            try {
                var formState = {
                    post_type: $postTypeSelect.val(),
                    category: $categorySelect.val(),
                    author: $('#cpt_author').val(),
                    status: $('#cpt_status').val(),
                    start_date: $('#cpt_start_date').val(),
                    end_date: $('#cpt_end_date').val(),
                    save_folder: $saveFolderInput.val(),
                    compress: $('#cpt_compress').is(':checked'),
                    timestamp: Date.now()
                };
                localStorage.setItem('cpt_export_form_state', JSON.stringify(formState));
            } catch(e) {
                // Fail silently if localStorage is not available or full
                console.warn('Could not save form state to localStorage:', e);
            }
        }
    }

    // Accessibility improvements
    function enhanceAccessibility() {
        // Add ARIA labels for better screen reader support
        $postTypeSelect.attr('aria-describedby', 'post-type-description');
        $categorySelect.attr('aria-describedby', 'category-description');
        
        // Add live region for dynamic content
        if (!$('#cpt-export-live-region').length) {
            $('body').append('<div id="cpt-export-live-region" aria-live="polite" aria-atomic="true" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden;"></div>');
        }
        
        var $liveRegion = $('#cpt-export-live-region');
        
        // Announce category loading
        $categoryLoading.on('show', function() {
            $liveRegion.text('Loading categories for selected post type');
        });
        
        // Announce when categories are loaded
        $categorySelect.on('change', function() {
            var count = $(this).find('option').length - 1; // Subtract "All categories" option
            if (count > 0) {
                $liveRegion.text(count + ' categories available for filtering');
            }
        });
    }

    // Initialize accessibility enhancements
    enhanceAccessibility();

    // Development mode helpers (only in debug mode)
    if (window.cpt_export_debug) {
        window.cptExportDebug = {
            getFormData: function() {
                return {
                    post_type: $postTypeSelect.val(),
                    category: $categorySelect.val(),
                    author: $('#cpt_author').val(),
                    status: $('#cpt_status').val(),
                    start_date: $('#cpt_start_date').val(),
                    end_date: $('#cpt_end_date').val(),
                    export_and_delete: $exportAndDeleteCheckbox.is(':checked'),
                    delete_permanently: $deletePermanentlyCheckbox.is(':checked'),
                    delete_media: $deleteMediaCheckbox.is(':checked'),
                    save_folder: $saveFolderInput.val(),
                    compress: $('#cpt_compress').is(':checked')
                };
            },
            resetForm: resetFormToDefaults,
            loadCategories: loadCategories
        };
    }
});