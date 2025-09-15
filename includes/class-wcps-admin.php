<?php
/**
 * Handles all admin-facing functionality for the WC Price Scraper plugin.
 *
 * @package WC_Price_Scraper/Admin
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('WCPS_Admin')) {
    class WCPS_Admin {

        /**
         * The main plugin class instance.
         * @var WC_Price_Scraper
         */
        private $plugin;

        /**
         * Constructor.
         * @param WC_Price_Scraper $plugin The main plugin class.
         */
        public function __construct(WC_Price_Scraper $plugin) {
            $this->plugin = $plugin;
        }

        /**
         * Adds the plugin's settings page to the WordPress admin menu.
         */
        public function add_settings_page() {
            add_options_page(
                __('تنظیمات اسکرپر قیمت', 'wc-price-scraper'),
                __('اسکرپر قیمت', 'wc-price-scraper'),
                'manage_options',
                'wc-price-scraper',
                [$this, 'render_settings_page']
            );
        }

        /**
         * Registers all settings for the plugin.
         * This includes general settings and N8N integration settings.
         */
        public function register_settings() {
            $option_group = 'wc_price_scraper_group';

            // General & Cron Settings
            register_setting($option_group, 'wc_price_scraper_cron_interval', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 12]);
            register_setting($option_group, 'wc_price_scraper_priority_cats', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_category_ids'], 'default' => []]);

            // NEW: Smart Filtering Settings
            register_setting($option_group, 'wcps_always_hide_keys', ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '']);
            register_setting($option_group, 'wcps_conditional_rules', ['type' => 'array', 'sanitize_callback' => [$this, 'sanitize_conditional_rules'], 'default' => []]);

            // High-Frequency Settings
            register_setting($option_group, 'wcps_high_frequency_pids', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_pid_list']]);
            register_setting($option_group, 'wcps_high_frequency_interval', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30]);

            // Register price rounding factor setting
            register_setting($option_group, 'wcps_price_rounding_factor', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]);

            // N8N Integration Settings (if they exist)
            register_setting($option_group, 'wc_price_scraper_n8n_enable', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_checkbox_yes_no'], 'default' => 'no']);
            register_setting($option_group, 'wc_price_scraper_n8n_webhook_url', ['type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '']);
            register_setting($option_group, 'wc_price_scraper_n8n_model_slug', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
            register_setting($option_group, 'wc_price_scraper_n8n_purchase_link_text', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Buy Now']);

            // NEW: On Failure Set Out of Stock
            register_setting($option_group, 'wcps_on_failure_set_outofstock', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_checkbox_yes_no'], 'default' => 'yes']);
        }

        /**
         * Renders the settings page by including a separate view file.
         */
        public function render_settings_page() {
            require_once WC_PRICE_SCRAPER_PATH . 'views/admin-settings-page.php';
        }

        /**
         * Sanitizes an array of category IDs.
         * @param array $input The input array from the settings page.
         * @return array The sanitized array of integers.
         */
        public function sanitize_category_ids($input) {
            return is_array($input) ? array_map('absint', $input) : [];
        }

        /**
         * Sanitizes a checkbox value to either 'yes' or 'no'.
         * @param string $input The input from the checkbox.
         * @return string Returns 'yes' or 'no'.
         */
        public function sanitize_checkbox_yes_no($input) {
            return ($input === 'yes' || $input === 'on') ? 'yes' : 'no';
        }

        /**
         * Enqueues admin scripts and styles on the appropriate pages.
         * @param string $hook The current admin page hook.
         */
        public function enqueue_admin_scripts($hook) {
        global $post;
        $screen = get_current_screen();

        // Script for the Product Edit page
        if ($screen && 'product' === $screen->id && ('post.php' === $hook || 'post-new.php' === $hook)) {
            wp_enqueue_script(
                'wc-price-scraper-js',
                WC_PRICE_SCRAPER_URL . 'js/price-scraper.js',
                ['jquery'],
                WC_PRICE_SCRAPER_VERSION,
                true
            );

            $product = $post ? wc_get_product($post->ID) : null;
            $default_attributes = $product ? $product->get_default_attributes() : [];

            wp_localize_script('wc-price-scraper-js', 'price_scraper_vars', [
                'ajax_url'           => admin_url('admin-ajax.php'),
                'security'           => wp_create_nonce('scrape_price_nonce'),
                'product_id'         => $post ? $post->ID : 0,
                'default_attributes' => $default_attributes,
                'loading_text'       => __('در حال اسکرپ...', 'wc-price-scraper'),
                'success_text'       => __('اسکرپ با موفقیت انجام شد! در حال بارگذاری مجدد...', 'wc-price-scraper'),
                'error_text'         => __('اسکرپ ناموفق: ', 'wc-price-scraper'),
                'unknown_error'      => __('خطای ناشناخته.', 'wc-price-scraper'),
                'ajax_error'         => __('خطای AJAX: ', 'wc-price-scraper'),
                ]);
            }

        // Script for the plugin's Settings page
         if ($screen && 'settings_page_wc-price-scraper' === $screen->id) {
            wp_enqueue_script(
                'wc-price-scraper-settings-js',
                WC_PRICE_SCRAPER_URL . 'js/settings-countdown.js',
                ['jquery'],
                WC_PRICE_SCRAPER_VERSION,
                true
            );
            
            // ++ این بخش بسیار مهم است ++
            wp_localize_script('wc-price-scraper-settings-js', 'wc_scraper_settings_vars', [
                'ajax_url'         => admin_url('admin-ajax.php'),
                'next_cron_action' => 'wc_price_scraper_next_cron',
                'reschedule_nonce' => wp_create_nonce('wcps_reschedule_nonce'),
                'stop_nonce'       => wp_create_nonce('wcps_stop_nonce'),
                'clear_log_nonce'  => wp_create_nonce('wcps_clear_failed_log_nonce'),
                'retry_n8n_nonce'  => wp_create_nonce('wcps_retry_n8n_nonce')
            ]);
            }
        }

        /**
         * Adds custom fields to the 'General' tab of the WooCommerce product data meta box.
         */
        public function add_scraper_fields() {
            global $post;
            echo '<div class="options_group">';

            woocommerce_wp_text_input([
                'id'          => '_source_url',
                'label'       => __('لینک منبع قیمت', 'wc-price-scraper'),
                'desc_tip'    => true,
                'description' => __('لینک کامل محصول در سایت مرجع را وارد کنید.', 'wc-price-scraper')
            ]);
            
            woocommerce_wp_checkbox([
                'id'          => '_auto_sync_variations',
                'label'       => __('همگام‌سازی خودکار', 'wc-price-scraper'),
                'description' => __('با فعال بودن این گزینه، محصول در به‌روزرسانی‌های خودکار (کرون‌جاب) بررسی می‌شود.', 'wc-price-scraper')
            ]);

            woocommerce_wp_text_input([
                'id'          => '_price_adjustment_percent',
                'label'       => __('تنظیم قیمت (درصد)', 'wc-price-scraper'),
                'type'        => 'number',
                'desc_tip'    => true,
                'description' => __('یک عدد برای تغییر قیمت وارد کنید. مثال: 10 برای 10% افزایش یا -5 برای 5% کاهش.', 'wc-price-scraper'),
                'custom_attributes' => ['step' => 'any']
            ]);
            
            echo '<p class="form-field scrape-controls">' .
                 '<button type="button" class="button button-primary" id="scrape_price">' . __('اسکرپ قیمت اکنون', 'wc-price-scraper') . '</button>' .
                 '<span class="spinner"></span>' .
                 '<span id="scrape_status" style="margin-right:10px;"></span>' .
                 '</p>';

            $last_scraped_timestamp = get_post_meta($post->ID, '_last_scraped_time', true);
            if ($last_scraped_timestamp) {
                $display_time = date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $last_scraped_timestamp);
                echo '<p class="form-field"><strong>' . __('آخرین اسکرپ موفق:', 'wc-price-scraper') . '</strong> ' . esc_html($display_time) . '</p>';

                // Display the raw scrape result
                $raw_result = get_post_meta($post->ID, '_last_scrape_raw_result', true);
                if ($raw_result) {
                    echo '<strong>' . __('آخرین نتیجه خام دریافتی:', 'wc-price-scraper') . '</strong>';
                    echo '<pre style="direction: ltr; text-align: left; background-color: #f5f5f5; border: 1px solid #ccc; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word;">';
                    // Check if it's a JSON string and pretty-print it
                    $json_data = json_decode($raw_result);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        echo esc_html(json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    } else {
                        // If it's not JSON (e.g., an error message), just display as is
                        echo esc_html($raw_result);
                    }
                    echo '</pre>';
                }
            }
            
            echo '</div>';
        }

        /**
         * Saves the custom scraper fields when a product is saved.
         * @param int     $post_id The ID of the post being saved.
         * @param WP_Post $post The post object.
         */
        public function save_scraper_fields($post_id, $post) {
            if (isset($_POST['_source_url'])) {
                update_post_meta($post_id, '_source_url', esc_url_raw($_POST['_source_url']));
            }
            if (isset($_POST['_price_adjustment_percent'])) {
                update_post_meta($post_id, '_price_adjustment_percent', sanitize_text_field($_POST['_price_adjustment_percent']));
            }
            update_post_meta($post_id, '_auto_sync_variations', isset($_POST['_auto_sync_variations']) ? 'yes' : 'no');
        }

        /**
         * Adds a "protected" checkbox to the variation pricing options.
         * @param int     $loop           Position in the loop.
         * @param array   $variation_data Variation data.
         * @param WP_Post $variation      The variation post object.
         */
        public function add_protected_variation_checkbox($loop, $variation_data, $variation) {
            woocommerce_wp_checkbox([
                'id'            => '_wcps_is_protected[' . $variation->ID . ']',
                'name'          => '_wcps_is_protected[' . $variation->ID . ']',
                'label'         => __('محافظت از این واریشن', 'wc-price-scraper'),
                'description'   => __('اگر تیک بخورد، این واریشن در همگام‌سازی خودکار حذف یا آپدیت نخواهد شد.', 'wc-price-scraper'),
                'value'         => get_post_meta($variation->ID, '_wcps_is_protected', true),
                'desc_tip'      => true,
            ]);
        }

        /**
         * Saves the "protected" status of a variation.
         * @param int $variation_id The ID of the variation being saved.
         * @param int $i            The loop index.
         */
        public function save_protected_variation_checkbox($variation_id, $i) {
            $is_protected = isset($_POST['_wcps_is_protected'][$variation_id]) ? 'yes' : 'no';
            update_post_meta($variation_id, '_wcps_is_protected', $is_protected);
        }

        /**
         * Sets a unique SKU for a new variation if it's empty upon saving.
         * @param int $variation_id The ID of the variation being saved.
         * @param int $i            The loop index.
         */
        public function set_variation_sku_on_manual_save($variation_id, $i) {
            $variation = wc_get_product($variation_id);
            if ($variation && empty($variation->get_sku())) {
                $variation->set_sku((string) $variation_id);
                $variation->save();
            }
        }

        /**
         * Sanitizes the conditional rules repeater field.
         * @param array $input The input array from the settings page.
         * @return array The sanitized array of rules.
         */
        public function sanitize_conditional_rules($input) {
            $sanitized_rules = [];
            if (is_array($input)) {
                foreach ($input as $rule) {
                    if (is_array($rule) && !empty($rule['key']) && !empty($rule['value'])) {
                        $sanitized_rules[] = [
                            'key'   => sanitize_text_field($rule['key']),
                            'value' => sanitize_text_field($rule['value']),
                        ];
                    }
                }
            }
            return $sanitized_rules;
        }

        /**
         * Registers the dashboard widget.
         * Hooks into 'wp_dashboard_setup'.
         */
        public function setup_dashboard_widget() {
            if (current_user_can('manage_options')) {
                wp_add_dashboard_widget(
                    'wcps_dashboard_widget',
                    __('وضعیت اسکرپر قیمت', 'wc-price-scraper'),
                    [$this, 'render_dashboard_widget']
                );
            }
        }

        /**
         * Renders the content of the dashboard widget.
         */
        public function render_dashboard_widget() {
            echo '<div class="wcps-widget-content">';

            // 1. نمایش زمان اجرای بعدی کران جاب اصلی
            $next_run_timestamp = wp_next_scheduled('wc_price_scraper_cron_event');
            if ($next_run_timestamp) {
                // محاسبه زمان باقی‌مانده به صورت خوانا
                $remaining_time = human_time_diff($next_run_timestamp, current_time('timestamp')) . ' ' . __('دیگر', 'wc-price-scraper');
                $display_time = sprintf('%s (%s)', date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $next_run_timestamp), $remaining_time);
                echo '<p><strong>' . esc_html__('کران جاب بعدی (عمومی):', 'wc-price-scraper') . '</strong><br>' . esc_html($display_time) . '</p>';
            } else {
                echo '<p><strong>' . esc_html__('کران جاب بعدی (عمومی):', 'wc-price-scraper') . '</strong><br>' . esc_html__('زمان‌بندی فعال نیست.', 'wc-price-scraper') . '</p>';
            }

            // 2. نمایش زمان آخرین اسکرپ موفق (با استفاده از لاگ)
            $last_log_entry = $this->get_last_log_line('CRON_SUCCESS'); // فرض می‌کنیم لاگ‌ها چنین فرمتی دارند
            if ($last_log_entry && isset($last_log_entry['timestamp'])) {
                 echo '<p><strong>' . esc_html__('آخرین اجرای موفق:', 'wc-price-scraper') . '</strong><br>' . esc_html(date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $last_log_entry['timestamp'])) . '</p>';
            } else {
                // Fallback to old method if log is not available
                $last_scraped_product = get_posts(['post_type' => 'product', 'posts_per_page' => 1, 'orderby' => 'meta_value_num', 'meta_key' => '_last_scraped_time', 'order' => 'DESC']);
                if ($last_scraped_product) {
                    $last_scraped_time = get_post_meta($last_scraped_product[0]->ID, '_last_scraped_time', true);
                     echo '<p><strong>' . esc_html__('آخرین اسکرپ موفق:', 'wc-price-scraper') . '</strong><br>' . esc_html(date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $last_scraped_time)) . '</p>';
                }
            }
            
            echo '<p><a href="' . esc_url(admin_url('options-general.php?page=wc-price-scraper')) . '" class="button">' . esc_html__('رفتن به تنظیمات', 'wc-price-scraper') . '</a></p>';
            echo '</div>';
        }

        /**
         * Reads the last N lines from the log file.
         *
         * @param int $lines_count The number of lines to retrieve.
         * @return array An array of log lines.
         */
        private function get_log_lines($lines_count = 63) {
            $log_path = $this->plugin->get_log_path();
            if (!file_exists($log_path)) {
                return [__('فایل لاگ یافت نشد.', 'wc-price-scraper')];
            }

            $file_lines = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($file_lines)) {
                return [__('فایل لاگ خالی است.', 'wc-price-scraper')];
            }

            return array_slice($file_lines, -$lines_count);
        }
        
        // این تابع برای ویجت داشبورد کاربرد دارد
        public function get_last_log_line($type_filter) {
            $log_path = $this->plugin->get_log_path();
            if (!file_exists($log_path)) return null;

            $lines = file($log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);

            foreach ($lines as $line) {
                if (strpos($line, "[$type_filter]") !== false) {
                     preg_match('/\\[(.*?)\\]/', $line, $matches);
                     return [
                         'timestamp' => strtotime($matches[1]),
                         'line' => $line
                     ];
                }
            }
            return null;
        }

        /**
         * Sanitizes a list of product IDs from a textarea.
         * @param string $input The raw input from the textarea.
         * @return string The sanitized string of newline-separated IDs.
         */
        public function sanitize_pid_list($input) {
            $lines = explode("\n", trim($input));
            $sanitized_ids = [];
            foreach ($lines as $line) {
                $id = absint(trim($line));
                if ($id > 0) {
                    $sanitized_ids[] = $id;
                }
            }
            return implode("\n", array_unique($sanitized_ids));
        }
    }
}