document.addEventListener('DOMContentLoaded', function () {
    // Select all tab links and tab content containers
    const tabs = document.querySelectorAll('.nav-tab');
    const tabContents = document.querySelectorAll('.tab-content');

    // Add click event listeners to each tab
    tabs.forEach(tab => {
        
        tab.addEventListener('click', function (event) {
            event.preventDefault();

            // Remove active class from all tabs and hide all content
            tabs.forEach(t => t.classList.remove('nav-tab-active'));
            tabContents.forEach(content => content.style.display = 'none');

            // Add active class to the clicked tab
            this.classList.add('nav-tab-active');

            // Show the corresponding content
            const target = this.getAttribute('href'); // Get the target ID from the href
            const targetContent = document.querySelector(target);
            if (targetContent) {
                targetContent.style.display = 'block';
            }
        });
    });
});

// Add this to your existing wp-interlinker.js file
jQuery(document).ready(function($) {
    $('#wp-interlinker-form').on('submit', function(e) {
        e.preventDefault(); // Prevent default form submission
        
        // Show loading state
        const submitButton = $(this).find('input[type="submit"]');
        const originalButtonText = submitButton.val();
        submitButton.val('Processing...').prop('disabled', true);
        
        // Get the form data
        const formData = {
            'action': 'handle_sitemap_submission',
            'sitemap_url': $('#sitemap_url').val(),
            'nonce': $('#wp_interlinker_nonce').val() // Make sure to add this hidden field
        };
        
        // Make the AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log(response)
                if (response.success) {
                    // Show success notification
                    const notification = $('<div class="notice notice-success is-dismissible">' +
                        '<p><strong>Success!</strong> ' + response.data.message + '</p>' +
                        '</div>');
                    
                    // Insert notification at the top of the form
                    $('#wpbody-content .wrap').before(notification);
                    
                    // Clear the form
                    // $('#sitemap_url').val('');
                } else {
                    // Show error notification
                    const notification = $('<div class="notice notice-error is-dismissible">' +
                        '<p><strong>Error!</strong> ' + response.data.message + '</p>' +
                        '</div>');
                    
                        $('#wpbody-content .wrap').before(notification);
                }
            },
            error: function() {
                // Show error notification for network/server errors
                const notification = $('<div class="notice notice-error is-dismissible">' +
                    '<p><strong>Error!</strong> There was a problem submitting the sitemap. Please try again.</p>' +
                    '</div>');
                
                    $('#wpbody-content .wrap').before(notification);
            },
            complete: function() {
                // Reset button state
                submitButton.val(originalButtonText).prop('disabled', false);
                
                // Make notices dismissible
                if (typeof wp !== 'undefined' && wp.notices) {
                    $('.notice.is-dismissible').each(function() {
                        wp.notices.makeDismissible($(this));
                    });
                }
            }
        });
    });
});