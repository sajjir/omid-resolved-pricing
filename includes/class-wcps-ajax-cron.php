<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WCPS_Ajax_Cron')) {
    class WCPS_Ajax_Cron {

        private $plugin;
        private $core;
        private $schedule_name = 'wcps_custom_interval';

        public function __construct(WC_Price_Scraper $plugin, WCPS_Core $core) {
            $this->plugin = $plugin;
            $this->core = $core;
        }

        /**
         * This function is called on plugin activation.
         * It now ONLY logs a message and does NOT schedule any events.
         */
        public function activate() {
            $this->plugin->debug_log('--- PLUGIN ACTIVATED: The new activate() function was called successfully. No schedule was set. ---');
        }

        /**
         * This function is called on plugin deactivation.
         * It clears ALL recurring and one-off hooks related to this plugin.
         */
        public function deactivate() {
            wp_clear_scheduled_hook('wc_price_scraper_cron_event');
            wp_clear_scheduled_hook('wcps_force_run_all_event');
            wp_clear_scheduled_hook('wcps_priority_low_cron_event');
            wp_clear_scheduled_hook('wcps_priority_medium_cron_event');
            wp_clear_scheduled_hook('wcps_priority_high_cron_event');
            $this->plugin->debug_log("--- PLUGIN DEACTIVATED: All cron schedules have been cleared. ---");
        }

        /**
         * Handles the settings save action from the settings page.
         * It schedules the RECURRING event to start IN THE FUTURE.
         */
        public function handle_settings_save() {
            // General cron - only if global scrape is active
            $global_status = get_option('wcps_global_scrape_status', 'active');
            
            wp_clear_scheduled_hook('wc_price_scraper_cron_event');
            if ($global_status === 'active') {
                $interval_hours = (int) get_option('wc_price_scraper_cron_interval', 12);
                if ($interval_hours > 0) {
                    wp_schedule_event(time() + ($interval_hours * 3600), $this->schedule_name, 'wc_price_scraper_cron_event');
                    $this->plugin->debug_log('Recurring general schedule was set.', 'CRON_SETUP');
                }
            }

            // Priority cron schedules - only if priority scrape is active
            $priority_status = get_option('wcps_priority_scrape_status', 'active');
            if ($priority_status === 'active') {
                $this->setup_priority_crons();
            } else {
                // Clear priority crons if paused
                wp_clear_scheduled_hook('wcps_priority_low_cron_event');
                wp_clear_scheduled_hook('wcps_priority_medium_cron_event');
                wp_clear_scheduled_hook('wcps_priority_high_cron_event');
                $this->plugin->debug_log('Priority crons cleared due to paused status.', 'CRON_SETUP');
            }

            // حذف بخش High-frequency cron (دیگر وجود ندارد)
        }

        /**
         * Sets up priority-based cron schedules
         */
        private function setup_priority_crons() {
            // Clear existing priority crons
            wp_clear_scheduled_hook('wcps_priority_low_cron_event');
            wp_clear_scheduled_hook('wcps_priority_medium_cron_event');
            wp_clear_scheduled_hook('wcps_priority_high_cron_event');

            // Low priority
            $low_interval = (int) get_option('wcps_priority_low_interval', 6);
            if ($low_interval > 0) {
                wp_schedule_event(time() + ($low_interval * 3600), 'wcps_priority_low', 'wcps_priority_low_cron_event');
                $this->plugin->debug_log("Low priority cron set for every {$low_interval} hours.", 'CRON_SETUP');
            }

            // Medium priority
            $medium_interval = (int) get_option('wcps_priority_medium_interval', 3);
            if ($medium_interval > 0) {
                wp_schedule_event(time() + ($medium_interval * 3600), 'wcps_priority_medium', 'wcps_priority_medium_cron_event');
                $this->plugin->debug_log("Medium priority cron set for every {$medium_interval} hours.", 'CRON_SETUP');
            }

            // High priority
            $high_interval = (int) get_option('wcps_priority_high_interval', 1);
            if ($high_interval > 0) {
                wp_schedule_event(time() + ($high_interval * 3600), 'wcps_priority_high', 'wcps_priority_high_cron_event');
                $this->plugin->debug_log("High priority cron set for every {$high_interval} hours.", 'CRON_SETUP');
            }
        }

        /**
         * Handles the "Start Cron Job" button click via AJAX.
         * Fires a non-blocking request using a more reliable token method.
         */
        public function ajax_force_reschedule_callback() {
            if (!current_user_can('manage_options') || !check_ajax_referer('wcps_reschedule_nonce', 'security')) {
                wp_send_json_error(['message' => 'درخواست نامعتبر.']);
            }

            // Save the interval value from the form
            if (isset($_POST['interval'])) {
                remove_action('update_option_wc_price_scraper_cron_interval', [$this, 'handle_settings_save'], 10);
                update_option('wc_price_scraper_cron_interval', intval($_POST['interval']));
                add_action('update_option_wc_price_scraper_cron_interval', [$this, 'handle_settings_save'], 10, 3);
            }

            // Generate a simple, random token for security
            $token = wp_generate_password(32, false);
            set_transient('wcps_scrape_token', $token, 60); // Token is valid for 60 seconds

            // Prepare the request to run the task
            $request_args = [
                'body' => [
                    'action' => 'wcps_run_scrape_task',
                    'token'  => $token // Use the new reliable token
                ],
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            ];

            // Fire the background request
            wp_remote_post(admin_url('admin-ajax.php'), $request_args);
            
            $this->plugin->debug_log('Fired background request to start scraping.');

            wp_send_json_success(['message' => 'درخواست اجرای پس‌زمینه با موفقیت ارسال شد.']);
        }

        /**
         * AJAX handler for toggling global scrape status
         */
        public function ajax_toggle_global_scrape() {
            if (!current_user_can('manage_options') || !check_ajax_referer('wcps_toggle_scrape_nonce', 'security')) {
                wp_send_json_error(['message' => 'درخواست نامعتبر.']);
            }

            $current_status = get_option('wcps_global_scrape_status', 'active');
            $new_status = ($current_status === 'active') ? 'paused' : 'active';
            
            update_option('wcps_global_scrape_status', $new_status);
            
            // Reschedule crons based on new status
            $this->handle_settings_save();

            $status_text = ($new_status === 'active') ? 'فعال' : 'متوقف شده';
            $this->plugin->debug_log("Global scrape status changed to: {$new_status}", 'STATUS_CHANGE');

            wp_send_json_success([
                'message' => "وضعیت اسکرپ عمومی به {$status_text} تغییر کرد.",
                'new_status' => $new_status,
                'status_text' => $status_text
            ]);
        }

        /**
         * AJAX handler for toggling priority scrape status
         */
        public function ajax_toggle_priority_scrape() {
            if (!current_user_can('manage_options') || !check_ajax_referer('wcps_toggle_scrape_nonce', 'security')) {
                wp_send_json_error(['message' => 'درخواست نامعتبر.']);
            }

            $current_status = get_option('wcps_priority_scrape_status', 'active');
            $new_status = ($current_status === 'active') ? 'paused' : 'active';
            
            update_option('wcps_priority_scrape_status', $new_status);
            
            // Reschedule priority crons based on new status
            $this->setup_priority_crons();

            $status_text = ($new_status === 'active') ? 'فعال' : 'متوقف شده';
            $this->plugin->debug_log("Priority scrape status changed to: {$new_status}", 'STATUS_CHANGE');

            wp_send_json_success([
                'message' => "وضعیت اسکرپ اولویت‌بندی شده به {$status_text} تغییر کرد.",
                'new_status' => $new_status,
                'status_text' => $status_text
            ]);
        }

        /**
         * NEW LOGIC: This function now acts as a dispatcher.
         * It finds all eligible products and schedules a separate, single cron event for each one.
         * This prevents a single failed product from stopping the entire process.
         */
        public function cron_update_all_prices() {
            // Check if global scrape is paused
            $global_status = get_option('wcps_global_scrape_status', 'active');
            if ($global_status === 'paused') {
                $this->plugin->debug_log('General cron skipped: Global scrape is paused.', 'CRON_SKIP');
                return;
            }

            $this->plugin->debug_log('General Cron Dispatcher Started: Finding and queueing products.', 'CRON_DISPATCH');

            // Get products with period priority or no priority set (default to period)
            $args = [
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_source_url', 'value' => '', 'compare' => '!='],
                    ['key' => '_auto_sync_variations', 'value' => 'yes'],
                    [
                        'relation' => 'OR',
                        ['key' => '_scrape_priority', 'value' => 'period'],
                        ['key' => '_scrape_priority', 'compare' => 'NOT EXISTS']
                    ]
                ]
            ];
            
            $product_ids = get_posts($args);

            if (empty($product_ids)) {
                $this->plugin->debug_log('No period-priority products found for general cron processing.', 'CRON_DISPATCH');
                return;
            }

            $stagger_time_seconds = 60; // 60 ثانیه فاصله بین هر وظیفه
            $count = 0;

            foreach ($product_ids as $pid) {
                // Schedule a single, non-recurring event for each product.
                wp_schedule_single_event(time() + ($count * $stagger_time_seconds), 'wcps_scrape_single_product_task', ['product_id' => $pid]);
                $count++;
            }

            $this->plugin->debug_log("Successfully queued {$count} period-priority products for individual scraping.", 'CRON_DISPATCH');
        }

        /**
         * Cron for low priority products
         */
        public function cron_update_priority_low() {
            $priority_status = get_option('wcps_priority_scrape_status', 'active');
            if ($priority_status === 'paused') {
                $this->plugin->debug_log('Low priority cron skipped: Priority scrape is paused.', 'PRIORITY_CRON_SKIP');
                return;
            }
            $this->process_priority_products('low');
        }

        /**
         * Cron for medium priority products
         */
        public function cron_update_priority_medium() {
            $priority_status = get_option('wcps_priority_scrape_status', 'active');
            if ($priority_status === 'paused') {
                $this->plugin->debug_log('Medium priority cron skipped: Priority scrape is paused.', 'PRIORITY_CRON_SKIP');
                return;
            }
            $this->process_priority_products('medium');
        }

        /**
         * Cron for high priority products
         */
        public function cron_update_priority_high() {
            $priority_status = get_option('wcps_priority_scrape_status', 'active');
            if ($priority_status === 'paused') {
                $this->plugin->debug_log('High priority cron skipped: Priority scrape is paused.', 'PRIORITY_CRON_SKIP');
                return;
            }
            $this->process_priority_products('high');
        }

        /**
         * Process products by priority level
         */
        private function process_priority_products($priority) {
            $this->plugin->debug_log("{$priority} priority cron started.", 'PRIORITY_CRON_START');

            $args = [
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_source_url', 'value' => '', 'compare' => '!='],
                    ['key' => '_auto_sync_variations', 'value' => 'yes'],
                    ['key' => '_scrape_priority', 'value' => $priority]
                ]
            ];
            
            $product_ids = get_posts($args);

            if (empty($product_ids)) {
                $this->plugin->debug_log("No {$priority} priority products found.", 'PRIORITY_CRON_SKIP');
                return;
            }

            $stagger_time_seconds = 45; // 45 ثانیه فاصله بین هر وظیفه
            $count = 0;

            foreach ($product_ids as $pid) {
                wp_schedule_single_event(time() + ($count * $stagger_time_seconds), 'wcps_scrape_single_product_task', ['product_id' => $pid]);
                $count++;
            }

            $this->plugin->debug_log("Successfully queued {$count} {$priority} priority products for individual scraping.", 'PRIORITY_CRON_END');
        }

        /**
         * NEW FUNCTION: This handler processes a single product scrape task.
         * It's triggered by the wp_schedule_single_event from the dispatcher.
         */
        public function scrape_single_product_handler($product_id) {
            if (empty($product_id)) {
                return;
            }
            
            $this->plugin->debug_log("Starting single-product task for PID: {$product_id}", 'SINGLE_TASK_START');
            $source_url = get_post_meta($product_id, '_source_url', true);

            if (empty($source_url)) {
                $this->plugin->debug_log("No source URL for PID: {$product_id}. Skipping.", 'SINGLE_TASK_SKIP');
                return;
            }

            // Call the core scraping function (now handles both API and local)
            $result = $this->core->process_single_product_scrape($product_id, $source_url, false);

            // Check for errors and log them for the new UI
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                $this->plugin->debug_log("Scrape FAILED for PID: {$product_id}. Error: {$error_message}", 'ERROR');
                
                // Log the failure for the admin settings page
                $this->log_failed_scrape($product_id, $error_message);
            } else {
                // Here you can add logic for successful N8N send if needed
                update_post_meta($product_id, '_last_scraped_time', current_time('timestamp'));
                if (isset($this->plugin->n8n_integration) && $this->plugin->n8n_integration->is_enabled()) {
                     $this->plugin->n8n_integration->trigger_send_for_product($product_id);
                }
            }
        }

        /**
         * NEW UTILITY FUNCTION: Logs failed product scrapes to a WP option.
         */
        private function log_failed_scrape($product_id, $error_message) {
            $failures = get_option('wcps_failed_scrapes', []);
            
            // Store product ID, error message, and timestamp.
            // Use product ID as key to prevent duplicates in the list.
            $failures[$product_id] = [
                'product_title' => get_the_title($product_id),
                'error_message' => $error_message,
                'timestamp'     => current_time('timestamp')
            ];
            
            // Keep only the last 20 failures to prevent the option from getting too large.
            if(count($failures) > 20) {
               $failures = array_slice($failures, -20, 20, true);
            }

            update_option('wcps_failed_scrapes', $failures);
        }

        // --- Other Functions ---
        public function add_cron_interval($schedules) {
            // General cron
            $interval_hours = get_option('wc_price_scraper_cron_interval', 12);
            if ($interval_hours > 0) {
                $schedules[$this->schedule_name] = [
                    'interval' => intval($interval_hours) * 3600,
                    'display'  => sprintf(__('هر %d ساعت (اسکرپر عمومی)', 'wc-price-scraper'), $interval_hours)
                ];
            }

            // Priority intervals
            $low_interval = get_option('wcps_priority_low_interval', 6);
            if ($low_interval > 0) {
                $schedules['wcps_priority_low'] = [
                    'interval' => intval($low_interval) * 3600,
                    'display'  => sprintf(__('هر %d ساعت (اولویت کم)', 'wc-price-scraper'), $low_interval)
                ];
            }

            $medium_interval = get_option('wcps_priority_medium_interval', 3);
            if ($medium_interval > 0) {
                $schedules['wcps_priority_medium'] = [
                    'interval' => intval($medium_interval) * 3600,
                    'display'  => sprintf(__('هر %d ساعت (اولویت متوسط)', 'wc-price-scraper'), $medium_interval)
                ];
            }

            $high_interval = get_option('wcps_priority_high_interval', 1);
            if ($high_interval > 0) {
                $schedules['wcps_priority_high'] = [
                    'interval' => intval($high_interval) * 3600,
                    'display'  => sprintf(__('هر %d ساعت (اولویت بالا)', 'wc-price-scraper'), $high_interval)
                ];
            }

            return $schedules;
        }

        public function ajax_next_cron() {
            $timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
            $now = current_time('timestamp');
            $diff = $timestamp ? max(0, $timestamp - $now) : -1;
            wp_send_json_success(['diff' => $diff]);
        }
        
        // Original functions from your file, unchanged
        public function scrape_price_callback() {
            @ini_set('display_errors', 0);
            while (ob_get_level()) ob_end_clean();
            if (!current_user_can('edit_products') || empty($_POST['product_id']) || empty($_POST['security']) || !wp_verify_nonce(sanitize_key($_POST['security']), 'scrape_price_nonce')) {
                wp_send_json_error(['message' => __('درخواست نامعتبر.', 'wc-price-scraper')]);
            }
            $pid = intval($_POST['product_id']);
            $product = wc_get_product($pid);
            if (!$product) {
                wp_send_json_error(['message' => __('محصول یافت نشد.', 'wc-price-scraper')]);
            }
            
            // Get scrape type to determine behavior
            $scrape_type = get_post_meta($pid, '_scrape_type', true) ?: 'api';
            
            // For local scraping, no need to convert to variable
            if ($scrape_type === 'api' && !$product->is_type('variable')) {
                $product_variable = new WC_Product_Variable($pid);
                $product_variable->save();
                $product = wc_get_product($pid);
            }
            
            if ($scrape_type === 'api' && (!$product || !$product->is_type('variable'))) {
                wp_send_json_error(['message' => __('محصول نتوانست به نوع متغیر تبدیل شود.', 'wc-price-scraper')]);
            }
            
            $source_url = $product->get_meta('_source_url');
            if (empty($source_url)) {
                wp_send_json_error(['message' => __('لینک منبع برای این محصول تنظیم نشده است.', 'wc-price-scraper')]);
            }
            
            $result = $this->core->process_single_product_scrape($pid, $source_url, true);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            } else {
                update_post_meta($pid, '_last_scraped_time', current_time('timestamp'));
                if (isset($this->plugin->n8n_integration) && $this->plugin->n8n_integration->is_enabled()) {
                    $this->plugin->n8n_integration->trigger_send_for_product($pid);
                }
                wp_send_json_success(['message' => __('اسکرپ با موفقیت انجام شد! واریشن‌ها به‌روز شدند.', 'wc-price-scraper'), 'scraped_data' => $result]);
            }
        }

        public function update_variation_price_callback() {
            if (!current_user_can('edit_products') || empty($_POST['variation_id']) || !isset($_POST['price'])) {
                wp_send_json_error(['message' => 'Invalid request']);
            }
            $var_id = intval($_POST['variation_id']);
            $price = floatval($_POST['price']);
            $variation = wc_get_product($var_id);
            if (!$variation) {
                wp_send_json_error(['message' => 'Variation not found']);
            }
            $variation->set_price($price);
            $variation->save();
            wp_send_json_success(['message' => 'Price updated']);
        }

        /**
         * AJAX handler for the Emergency Stop button.
         * Forcefully clears all known cron schedules for this plugin.
         */
        public function ajax_force_stop_all_crons() {
            if (!current_user_can('manage_options') || !check_ajax_referer('wcps_stop_nonce', 'security')) {
                wp_send_json_error(['message' => 'درخواست نامعتبر.']);
            }

            // Call the comprehensive deactivate function to clear everything.
            $this->deactivate();

            wp_send_json_success(['message' => 'تمام عملیات و زمان‌بندی‌ها با موفقیت پاک‌سازی شدند.']);
        }

        

        /**
         * This is the handler that runs the actual long task.
         * It now uses the more reliable token for verification.
         */
        public function run_scrape_task_handler() {
            // Security check with the new token method
            $saved_token = get_transient('wcps_scrape_token');
            if (empty($saved_token) || empty($_POST['token']) || !hash_equals($saved_token, $_POST['token'])) {
                $this->plugin->debug_log('Scrape task handler called with invalid or expired token.');
                wp_die('Invalid security token.');
            }

            // The token is valid, so we delete it to prevent reuse
            delete_transient('wcps_scrape_token');

            // Increase time limit if possible
            if (function_exists('set_time_limit')) {
                set_time_limit(3600); // 1 hour
            }

            // Run the main scraping function
            $this->cron_update_all_prices();

            // End the process
            wp_die('Scrape task finished.');
        }

        /**
         * AJAX: Clear failed log callback
         */
        public function ajax_clear_failed_log_callback() {
            if (!current_user_can('manage_options') || !check_ajax_referer('wcps_clear_failed_log_nonce', 'security')) {
                wp_send_json_error(['message' => 'درخواست نامعتبر است.']);
            }

            delete_option('wcps_failed_scrapes');
            wp_send_json_success();
        }

        /**
         * AJAX handler for retrying failed N8N sends.
         */
        public function ajax_retry_failed_n8n_sends() {
            if (!current_user_can('manage_options') || !check_ajax_referer('wcps_retry_n8n_nonce', 'security')) {
                wp_send_json_error(['message' => 'درخواست نامعتبر.']);
            }

            // یک رویداد یک‌باره در پس‌زمینه اجرا می‌کند تا UI قفل نشود
            wp_schedule_single_event(time() + 5, 'wcps_trigger_retry_n8n_event');

            wp_send_json_success(['message' => 'درخواست ارسال مجدد در پس‌زمینه اجرا شد.']);
        }

        /**
         * The actual function that processes the failed N8N sends.
         */
        public function process_retry_n8n_sends() {
            $this->plugin->debug_log('[N8N_RETRY] Starting N8N retry process.');

            $failed_sends = get_option('wcps_n8n_failed_sends', []);
            if (empty($failed_sends)) {
                $this->plugin->debug_log('[N8N_RETRY] No failed sends to process.');
                return;
            }

            // اطمینان از اینکه کلاس N8N لود شده
            if (isset($this->plugin->n8n_integration) && method_exists($this->plugin->n8n_integration, 'is_enabled')) {
                foreach ($failed_sends as $product_id => $data) {
                    // فقط آیتم‌هایی که کمتر از ۵ بار تلاش شده‌اند را دوباره ارسال می‌کنیم
                    if ($data['attempts'] < 5) {
                        $this->plugin->debug_log("[N8N_RETRY] Retrying send for product #{$product_id}.");
                        // تابع اصلی ارسال را با پی‌لود ذخیره شده فراخوانی می‌کنیم
                        $this->plugin->n8n_integration->send_payload_to_n8n($data['payload'], $product_id);
                        // یک تاخیر کوچک بین درخواست‌ها برای جلوگیری از فشار به سرور
                        sleep(2);
                    }
                }
            }

            $this->plugin->debug_log('[N8N_RETRY] N8N retry process finished.');
        }
    }
}