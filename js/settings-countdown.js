jQuery(document).ready(function($) {
    var el = $('#cron_countdown');
    var initial_seconds = -1;

    if (el.length > 0 && typeof el.data('seconds-left') !== 'undefined') {
        initial_seconds = parseInt(el.data('seconds-left'));
    }

    function tick() {
        if (initial_seconds < 0) {
            el.text('--:--');
            return;
        }
        var m = Math.floor(initial_seconds / 60);
        var s = initial_seconds % 60;
        el.text((m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s);
        
        if (initial_seconds > 0) {
            initial_seconds--;
            setTimeout(tick, 1000);
        } else {
             el.text('در حال اجرا...');
        }
    }

    function refreshAjax() {
        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: { action: wc_scraper_settings_vars.next_cron_action },
            success: function(response) {
                if (response.success && typeof response.data.diff !== "undefined") {
                    initial_seconds = parseInt(response.data.diff);
                    if (!$("#cron_countdown:hover").length) { // Only restart if not hovering
                         tick();
                    }
                } else {
                     initial_seconds = -1;
                     el.text('--:--');
                }
            }
        });
    }

    // Start the countdown
    tick();
    // Refresh every 30 seconds
    setInterval(refreshAjax, 30000);

    // --- Manual Reschedule Button ---
    $('#force_reschedule_button').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var statusSpan = $('#reschedule_status');
        var spinner = button.siblings('.spinner');
        var interval_val = $('#wc_price_scraper_cron_interval').val();

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        
        // Optimistic UI: Assume success immediately
        statusSpan.text('درخواست اجرای پس‌زمینه ارسال شد...').css('color', 'green');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_force_reschedule',
                security: wc_scraper_settings_vars.reschedule_nonce,
                interval: interval_val
            }
        });
        
        // Reload the page to show the effect
        setTimeout(function() {
            location.reload();
        }, 2000);
    });

    // --- Emergency Stop Button ---
    $('#force_stop_button').on('click', function(e) {
        e.preventDefault();
        if (!confirm('آیا مطمئن هستید؟ این عمل تمام فرآیندهای زمان‌بندی شده این پلاگین را متوقف می‌کند.')) {
            return;
        }

        var button = $(this);
        var statusSpan = $('#stop_status');
        var mainSpinner = button.closest('td').find('.spinner');

        button.prop('disabled', true);
        mainSpinner.addClass('is-active').css('display', 'inline-block');

        // Optimistic UI: Assume success immediately
        statusSpan.text('دستور توقف با موفقیت ارسال شد...').css('color', 'green');
        
        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_force_stop',
                security: wc_scraper_settings_vars.stop_nonce
            }
        });
        
        // Reload the page to show the cleared schedule
        setTimeout(function() {
            location.reload();
        }, 2000);
    });

    // --- NEW: Global Scrape Toggle ---
    $('#pause_global_scrape, #start_global_scrape').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var statusSpan = $('#toggle_scrape_status');
        var spinner = $('#toggle_scrape_spinner');
        var statusDisplay = $('#global_status_display');

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        statusSpan.text('در حال بروزرسانی...').css('color', '');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_toggle_global_scrape',
                security: wc_scraper_settings_vars.toggle_scrape_nonce
            },
            success: function(response) {
                if(response.success) {
                    statusSpan.text(response.data.message).css('color', 'green');
                    
                    // Update status display
                    var newStatus = response.data.new_status;
                    var newStatusText = response.data.status_text;
                    var newColor = (newStatus === 'active') ? 'green' : 'red';
                    
                    statusDisplay.text(newStatusText).css('color', newColor);
                    
                    // Update button
                    if (newStatus === 'active') {
                        button.replaceWith('<button type="button" class="button button-danger" id="pause_global_scrape">⏸ توقف اسکرپ عمومی</button>');
                    } else {
                        button.replaceWith('<button type="button" class="button button-primary" id="start_global_scrape">▶ شروع اسکرپ عمومی</button>');
                    }
                    
                    // Re-bind event to new button
                    $('#pause_global_scrape, #start_global_scrape').on('click', arguments.callee);
                } else {
                    statusSpan.text('خطا: ' + response.data.message).css('color', 'red');
                    button.prop('disabled', false);
                }
            },
            error: function() {
                statusSpan.text('خطای ایجکس.').css('color', 'red');
                button.prop('disabled', false);
            },
            complete: function() {
                spinner.removeClass('is-active').hide();
            }
        });
    });

    // --- NEW: Priority Scrape Toggle ---
    $('#pause_priority_scrape, #start_priority_scrape').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var statusSpan = $('#priority_scrape_status');
        var spinner = $('#priority_scrape_spinner');
        var statusDisplay = $('#priority_status_display');

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        statusSpan.text('در حال بروزرسانی...').css('color', '');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_toggle_priority_scrape',
                security: wc_scraper_settings_vars.toggle_scrape_nonce
            },
            success: function(response) {
                if(response.success) {
                    statusSpan.text(response.data.message).css('color', 'green');
                    
                    // Update status display
                    var newStatus = response.data.new_status;
                    var newStatusText = response.data.status_text;
                    var newColor = (newStatus === 'active') ? 'green' : 'red';
                    
                    statusDisplay.text(newStatusText).css('color', newColor);
                    
                    // Update button
                    if (newStatus === 'active') {
                        button.replaceWith('<button type="button" class="button button-danger" id="pause_priority_scrape">⏸ توقف اسکرپ اولویتی</button>');
                    } else {
                        button.replaceWith('<button type="button" class="button button-primary" id="start_priority_scrape">▶ شروع اسکرپ اولویتی</button>');
                    }
                    
                    // Re-bind event to new button
                    $('#pause_priority_scrape, #start_priority_scrape').on('click', arguments.callee);
                } else {
                    statusSpan.text('خطا: ' + response.data.message).css('color', 'red');
                    button.prop('disabled', false);
                }
            },
            error: function() {
                statusSpan.text('خطای ایجکس.').css('color', 'red');
                button.prop('disabled', false);
            },
            complete: function() {
                spinner.removeClass('is-active').hide();
            }
        });
    });

    // --- Conditional Rules Repeater Logic ---
    function wcps_reindex_rules() {
        $('#wcps-rules-container .wcps-rule-row').each(function(index, row) {
            $(row).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    var new_name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', new_name);
                }
            });
        });
    }

    // Add Rule Button
    $('#wcps-add-rule').on('click', function() {
        var container = $('#wcps-rules-container');
        var new_row = container.find('.wcps-rule-row:first').clone();
        new_row.find('input').val(''); // Clear values in new row
        container.append(new_row);
        wcps_reindex_rules();
    });

    // Remove Rule Button (uses event delegation for dynamically added rows)
    $('#wcps-rules-container').on('click', '.wcps-remove-rule', function(e) {
        e.preventDefault();
        var row = $(this).closest('.wcps-rule-row');
        // Do not remove the last row, just clear it
        if ($('#wcps-rules-container .wcps-rule-row').length > 1) {
            row.remove();
        } else {
            row.find('input').val('');
        }
        wcps_reindex_rules();
    });

    // --- High-Frequency Force Scrape Button ---
    $('#force_scrape_high_frequency').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var statusSpan = $('#hf_status');
        var spinner = $('#hf_spinner');

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        statusSpan.text('در حال ارسال درخواست...').css('color', '');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_force_hf_scrape',
                security: wc_scraper_settings_vars.hf_scrape_nonce // We need to add this nonce
            },
            success: function(response) {
                if(response.success) {
                    statusSpan.text(response.data.message).css('color', 'green');
                } else {
                    statusSpan.text('خطا: ' + response.data.message).css('color', 'red');
                }
            },
            error: function() {
                statusSpan.text('خطای ایجکس.').css('color', 'red');
            },
            complete: function() {
                setTimeout(function(){
                    button.prop('disabled', false);
                    spinner.removeClass('is-active').hide();
                    statusSpan.text('');
                }, 2000);
            }
        });
    });

    $('#wcps-retry-n8n-button').on('click', function(e) {
        e.preventDefault();

        var button = $(this);
        var spinner = $('#wcps-retry-n8n-spinner');
        var statusSpan = $('#wcps-retry-n8n-status');

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        statusSpan.text('در حال ارسال درخواست...').css('color', '');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_retry_n8n',
                security: wc_scraper_settings_vars.retry_n8n_nonce // این را باید در مرحله بعد اضافه کنیم
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.text(response.data.message).css('color', 'green');
                } else {
                    statusSpan.text('خطا: ' + (response.data.message || 'خطای ناشناخته')).css('color', 'red');
                    button.prop('disabled', false);
                }
            },
            error: function() {
                statusSpan.text('خطای ایجکس.').css('color', 'red');
                button.prop('disabled', false);
            },
            complete: function() {
                spinner.removeClass('is-active').hide();
                setTimeout(function(){
                    location.reload(); // صفحه را رفرش می‌کنیم تا لیست به‌روز شود
                }, 2000);
            }
        });
    });
});

// --- Clear Failed Log Button ---
jQuery(document).ready(function($) {
    $('#wcps-clear-log-button').on('click', function(e) {
        e.preventDefault();
        if (!confirm('آیا از پاک کردن تمام گزارش‌های خطا مطمئن هستید؟')) {
            return;
        }

        var button = $(this);
        var spinner = $('#wcps-clear-log-spinner');
        var statusSpan = $('#wcps-clear-log-status');

        button.prop('disabled', true);
        spinner.addClass('is-active').css('display', 'inline-block');
        statusSpan.text('');

        $.ajax({
            url: wc_scraper_settings_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'wcps_clear_failed_log',
                security: wc_scraper_settings_vars.clear_log_nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.text('لیست با موفقیت پاک شد.').css('color', 'green');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    statusSpan.text('خطا: ' + response.data.message).css('color', 'red');
                    button.prop('disabled', false);
                }
            },
            error: function() {
                statusSpan.text('خطای ایجکس.').css('color', 'red');
                button.prop('disabled', false);
            },
            complete: function() {
                spinner.removeClass('is-active').hide();
            }
        });
    });
});