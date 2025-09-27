jQuery(document).ready(function($) {
    // تابع تنظیم مقادیر پیش‌فرض شما (بدون تغییر)
    function setDefaultAttributeDropdowns() {
        if (price_scraper_vars.default_attributes) {
            $.each(price_scraper_vars.default_attributes, function(attribute_slug, term_slug) {
                var $dropdown = $('select[name="default_attribute_' + attribute_slug + '"]');
                if ($dropdown.length > 0) {
                    $dropdown.val(term_slug).trigger('change');
                }
            });
        }
    }
    setDefaultAttributeDropdowns();

    // رویداد کلیک دکمه اصلی (با یک بهینه‌سازی کوچک)
    $('#scrape_price').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var controls = button.closest('.scrape-controls');
        var status_span = controls.find('#scrape_status');
        var spinner = controls.find('.spinner');
        
        // بهینه‌سازی: استفاده از product_id که مستقیماً از PHP آمده
        var product_id = price_scraper_vars.product_id;

        if (!product_id) {
            status_span.text(price_scraper_vars.error_text + 'Product ID not found.').css('color', 'red');
            return;
        }

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        status_span.text(price_scraper_vars.loading_text).css('color', '');

        $.ajax({
            url: price_scraper_vars.ajax_url,
            type: 'POST',
            timeout: 120000, // 120 seconds timeout
            data: {
                action: 'scrape_price',
                product_id: product_id,
                security: price_scraper_vars.security
            },
            success: function(response) {
                console.log('Success Response:', response);
                if (response.success) {
                    status_span.text(price_scraper_vars.success_text).css('color', 'green');
                    console.log('Price scraping successful for product ID:', product_id);
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    console.error('API Error:', {
                        product_id: product_id,
                        error: response.data.message || price_scraper_vars.unknown_error,
                        full_response: response
                    });
                    status_span.text(price_scraper_vars.error_text + (response.data.message || price_scraper_vars.unknown_error)).css('color', 'red');
                }
            },
            error: function(xhr, textStatus, errorThrown) {
                console.error('AJAX Error:', {
                    product_id: product_id,
                    status: textStatus,
                    error: errorThrown,
                    xhr: xhr.responseText,
                    state: xhr.state(),
                    statusCode: xhr.status
                });
                var errorMessage = price_scraper_vars.ajax_error + 
                    ' Status: ' + textStatus + 
                    ' Error: ' + errorThrown + 
                    ' Code: ' + xhr.status;
                status_span.text(errorMessage).css('color', 'red');
            },
            complete: function() {
                console.log('Request completed for product ID:', product_id);
                button.prop('disabled', false);
                spinner.removeClass('is-active').hide();
            }
        });
    });
});