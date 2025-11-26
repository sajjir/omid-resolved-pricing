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
            
            // اضافه کردن هوک برای نوار مدیریت
            add_action('wp_before_admin_bar_render', [$this, 'add_admin_bar_scrape_button']);
            add_action('wp_head', [$this, 'add_admin_bar_scripts']);
        
        }

        /**
         * Adds the plugin's settings page to the WordPress admin menu.
         */
        public function add_settings_page() {
            // Top-level menu: اسکرپر قیمت
            add_menu_page(
                __('اسکرپر قیمت', 'wc-price-scraper'),
                __('اسکرپر قیمت', 'wc-price-scraper'),
                'manage_options',
                'wcps-scraper',
                [$this, 'render_settings_page'],
                'dashicons-money',
                56
            );

            // +++ حذف منوی تکراری "تنظیمات کلی اسکرپر" +++
            // منوی اصلی (wcps-scraper) خودش تنظیمات کلی رو نشون میده

            // Submenu: محصولات محافظ شده
            add_submenu_page(
                'wcps-scraper',
                __('محصولات محافظت‌شده', 'wc-price-scraper'),
                __('محصولات محافظت‌شده', 'wc-price-scraper'),
                'manage_options',
                'wcps-protected-products',
                [$this, 'render_protected_products_page']
            );

            // Submenu: محصولات رقابتی در ترب
            add_submenu_page(
                'wcps-scraper',
                __('محصولات رقابتی در ترب', 'wc-price-scraper'),
                __('محصولات رقابتی در ترب', 'wc-price-scraper'),
                'manage_options',
                'wcps-torob-products',
                [$this, 'render_torob_products_page']
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

    // Combined Filtering Settings
    register_setting($option_group, 'wcps_combined_rules', [
        'type' => 'array',
        'sanitize_callback' => [$this, 'sanitize_conditional_rules'],
        'default' => []
    ]);

    // Priority scrape status
    register_setting($option_group, 'wcps_priority_scrape_status', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_scrape_status'], 'default' => 'active']);

    // Register price rounding factor setting
    register_setting($option_group, 'wcps_price_rounding_factor', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]);

    // Priority System Settings
    register_setting($option_group, 'wcps_priority_low_interval', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 6]);
    register_setting($option_group, 'wcps_priority_medium_interval', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 3]);
    register_setting($option_group, 'wcps_priority_high_interval', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1]);
    register_setting($option_group, 'wcps_global_scrape_status', ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_scrape_status'], 'default' => 'active']);

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
         * Render the protected products list page.
         */
        public function render_protected_products_page() {
            require_once WC_PRICE_SCRAPER_PATH . 'views/protected-products-page.php';
        }

        /**
         * Render the Torob products list page.
         */
        public function render_torob_products_page() {
            require_once WC_PRICE_SCRAPER_PATH . 'views/torob-products-page.php';
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
         * Sanitizes scrape status value.
         * @param string $input The input from the status field.
         * @return string Returns 'active' or 'paused'.
         */
        public function sanitize_scrape_status($input) {
            return in_array($input, ['active', 'paused']) ? $input : 'active';
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

        // Script for the plugin's Settings page (support both old and new screen IDs)
         if ($screen && in_array($screen->id, [
                'settings_page_wc-price-scraper',
                'toplevel_page_wcps-scraper',
                'wcps-scraper_page_wc-price-scraper'
            ], true)) {
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
                'retry_n8n_nonce'  => wp_create_nonce('wcps_retry_n8n_nonce'),
                'toggle_scrape_nonce' => wp_create_nonce('wcps_toggle_scrape_nonce')
            ]);
            }
        }

        /**
         * Adds custom fields to the 'General' tab of the WooCommerce product data meta box.
         */
        public function add_scraper_fields() {
            global $post;
            echo '<div class="options_group">';

            // فیلد نوع اسکرپ
            woocommerce_wp_select([
                'id' => '_scrape_type',
                'label' => __('نوع اسکرپ', 'wc-price-scraper'),
                'options' => [
                    'api' => __('API (پیش‌فرض - محصولات متغیر)', 'wc-price-scraper'),
                    'local' => __('HTTP Request محلی (محصولات ساده)', 'wc-price-scraper')
                ],
                'value' => get_post_meta($post->ID, '_scrape_type', true) ?: 'api',
                'desc_tip' => true,
                'description' => __('انتخاب روش اسکرپ قیمت', 'wc-price-scraper')
            ]);

            // فیلد اصلی
            woocommerce_wp_text_input([
                'id'          => '_source_url',
                'label'       => __('لینک منبع قیمت', 'wc-price-scraper'),
                'desc_tip'    => true,
                'description' => __('لینک کامل محصول در سایت مرجع را وارد کنید.', 'wc-price-scraper')
            ]);

            // فیلد نشانه قیمت (برای اسکرپ محلی)
            $current_scrape_type = get_post_meta($post->ID, '_scrape_type', true) ?: 'api';
            $display_style = ($current_scrape_type === 'local') ? '' : 'display: none;';
            
            echo '<p class="form-field _price_selector_field' . ($current_scrape_type === 'local' ? '' : ' hidden') . '" id="_price_selector_field">';
            echo '<label for="_price_selector">' . __('نشانه قیمت', 'wc-price-scraper') . '</label>';
            echo '<input type="text" class="short" style="" name="_price_selector" id="_price_selector" value="' . esc_attr(get_post_meta($post->ID, '_price_selector', true)) . '" placeholder=".price یا //span[@class=\'price\']">';
            echo '<span class="description">' . __('CSS Selector یا XPath عنصر قیمت در صفحه منبع', 'wc-price-scraper') . '</span>';
            echo '</p>';

            // فیلد ترب
            woocommerce_wp_text_input([
                'id'          => '_torob_url',
                'label'       => __('لینک منبع ترب', 'wc-price-scraper'),
                'desc_tip'    => true,
                'description' => __('لینک صفحه محصول در سایت ترب را برای استعلام قیمت دوم وارد کنید.', 'wc-price-scraper')
            ]);
            
           woocommerce_wp_checkbox([
    'id'          => '_auto_sync_variations',
    'label'       => __('همگام‌سازی خودکار', 'wc-price-scraper'),
    'description' => __('با فعال بودن این گزینه، محصول در به‌روزرسانی‌های خودکار (کرون‌جاب) بررسی می‌شود.', 'wc-price-scraper'),
    'value'       => get_post_meta($post->ID, '_auto_sync_variations', true) ?: 'yes' // اضافه کردن این خط
]);

            // دکمه رادیویی ترب
            woocommerce_wp_radio([
                'id'            => '_torob_price_source',
                'label'         => __('مبنای قیمت ترب', 'wc-price-scraper'),
                'options'       => [
                    'mashhad' => __('مشهد', 'wc-price-scraper'),
                    'iran'    => __('ایران', 'wc-price-scraper'),
                ],
                'value'         => get_post_meta($post->ID, '_torob_price_source', true) ?: 'mashhad',
                'description'   => __('انتخاب کنید که قیمت کدام منطقه از ترب به عنوان مبنای رقابت در نظر گرفته شود.', 'wc-price-scraper'),
                'desc_tip'      => true,
            ]);

            // فیلد اولویت اسکرپ
            woocommerce_wp_select([
                'id' => '_scrape_priority',
                'label' => __('اولویت اسکرپ', 'wc-price-scraper'),
                'options' => [
                    'period' => __('پریود (پیش‌فرض)', 'wc-price-scraper'),
                    'low' => __('کم', 'wc-price-scraper'),
                    'medium' => __('متوسط', 'wc-price-scraper'),
                    'high' => __('بالا', 'wc-price-scraper')
                ],
                'value' => get_post_meta($post->ID, '_scrape_priority', true) ?: 'period',
                'desc_tip' => true,
                'description' => __('تعیین اولویت به‌روزرسانی خودکار قیمت', 'wc-price-scraper')
            ]);

            // +++ شروع کد جدید: سیستم تنظیم قیمت پیشرفته با ساختار درست +++
            $adjustment_type = get_post_meta($post->ID, '_price_adjustment_type', true) ?: 'percent';
            $percent_display = ($adjustment_type === 'percent') ? '' : 'display: none;';
            $fixed_display = ($adjustment_type === 'fixed') ? '' : 'display: none;';
            
            // استفاده از woocommerce_wp_radio برای ساختار استاندارد
            woocommerce_wp_radio([
                'id'            => '_price_adjustment_type',
                'label'         => __('نوع تنظیم قیمت', 'wc-price-scraper'),
                'options'       => [
                    'percent' => __('درصدی', 'wc-price-scraper'),
                    'fixed'   => __('مبلغ ثابت (تومان)', 'wc-price-scraper'),
                ],
                'value'         => $adjustment_type,
                'description'   => __('انتخاب روش محاسبه قیمت نهایی', 'wc-price-scraper'),
                'desc_tip'      => true,
            ]);

            // فیلد درصدی
            echo '<p class="form-field _price_adjustment_percent_field' . ($adjustment_type === 'percent' ? '' : ' hidden') . '" id="_price_adjustment_percent_field">';
            echo '<label for="_price_adjustment_percent">' . __('مقدار تنظیم (درصد)', 'wc-price-scraper') . '</label>';
            echo '<input type="number" class="short" name="_price_adjustment_percent" id="_price_adjustment_percent" value="' . esc_attr(get_post_meta($post->ID, '_price_adjustment_percent', true)) . '" placeholder="0" step="any">';
            echo '<span class="description">' . __('مثال: 10 برای 10% افزایش یا -5 برای 5% کاهش', 'wc-price-scraper') . '</span>';
            echo '</p>';

            // فیلد مبلغ ثابت
            echo '<p class="form-field _price_adjustment_fixed_field' . ($adjustment_type === 'fixed' ? '' : ' hidden') . '" id="_price_adjustment_fixed_field">';
            echo '<label for="_price_adjustment_fixed">' . __('مبلغ تنظیم (تومان)', 'wc-price-scraper') . '</label>';
            echo '<input type="number" class="short" name="_price_adjustment_fixed" id="_price_adjustment_fixed" value="' . esc_attr(get_post_meta($post->ID, '_price_adjustment_fixed', true)) . '" placeholder="0" step="any">';
            echo '<span class="description">' . __('مثال: 10000 برای ۱۰,۰۰۰ تومان افزایش یا -5000 برای ۵,۰۰۰ تومان کاهش', 'wc-price-scraper') . '</span>';
            echo '</p>';
            // +++ پایان کد جدید +++
            
            echo '<p class="form-field scrape-controls">' .
                 '<button type="button" class="button button-primary" id="scrape_price">' . __('اسکرپ قیمت اکنون', 'wc-price-scraper') . '</button>' .
                 '<span class="spinner"></span>' .
                 '<span id="scrape_status" style="margin-right:10px;"></span>' .
                 '</p>';

            // نمایش اطلاعات آخرین اسکرپ
            $last_scraped_timestamp = get_post_meta($post->ID, '_last_scraped_time', true);
            if ($last_scraped_timestamp) {
                $display_time = date_i18n(get_option('date_format') . ' @ ' . get_option('time_format'), $last_scraped_timestamp);
                echo '<p class="form-field"><strong>' . __('آخرین اسکرپ موفق:', 'wc-price-scraper') . '</strong> ' . esc_html($display_time) . '</p>';

                // نمایش نتیجه خام
                $raw_result = get_post_meta($post->ID, '_last_scrape_raw_result', true);
                if ($raw_result) {
                    echo '<strong>' . __('آخرین نتیجه خام دریافتی:', 'wc-price-scraper') . '</strong>';
                    echo '<pre style="direction: ltr; text-align: left; background-color: #f5f5f5; border: 1px solid #ccc; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto;">';
                    $json_data = json_decode($raw_result);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        echo esc_html(json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    } else {
                        echo esc_html($raw_result);
                    }
                    echo '</pre>';
                }
            }
            
            // نمایش نتیجه خام ترب - بعد از باکس اصلی
            $torob_raw_result = get_post_meta($post->ID, '_last_torob_scrape_raw_result', true);
            if ($torob_raw_result) {
                echo '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">';
                echo '<strong>' . __('آخرین نتیجه خام دریافتی ترب:', 'wc-price-scraper') . '</strong>';
                echo '<pre style="direction: ltr; text-align: left; background-color: #f5f5f5; border: 1px solid #ccc; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto; margin-top: 10px;">';
                
                // بررسی کن آیا داده JSON هست یا خطا
                if (strpos($torob_raw_result, 'Torob Error') === 0 || strpos($torob_raw_result, 'INVALID_STRUCTURE') === 0) {
                    // خطای متنی
                    echo esc_html($torob_raw_result);
                } else {
                    // سعی کن JSON رو decode کن
                    $torob_json_data = json_decode($torob_raw_result);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        echo esc_html(json_encode($torob_json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    } else {
                        // اگر JSON نیست، خام نمایش بده
                        echo esc_html($torob_raw_result);
                    }
                }
                
                echo '</pre>';
                echo '</div>';
            }

            echo '</div>';
            
            // +++ شروع کد جدید: JS برای نمایش پویا فیلدها +++
            echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                function toggleScrapeFields() {
                    var scrapeType = $("#_scrape_type").val();
                    if (scrapeType === "local") {
                        $("#_price_selector_field").show();
                    } else {
                        $("#_price_selector_field").hide();
                    }
                }
                
                function toggleAdjustmentFields() {
                    var adjustmentType = $("input[name=\'_price_adjustment_type\']:checked").val();
                    if (adjustmentType === "percent") {
                        $("#_price_adjustment_percent_field").show();
                        $("#_price_adjustment_fixed_field").hide();
                    } else {
                        $("#_price_adjustment_percent_field").hide();
                        $("#_price_adjustment_fixed_field").show();
                    }
                }
                
                // اجرای اولیه
                toggleScrapeFields();
                toggleAdjustmentFields();
                
                // تغییر موقع انتخاب نوع اسکرپ
                $("#_scrape_type").change(function() {
                    toggleScrapeFields();
                });
                
                // تغییر موقع انتخاب نوع تنظیم قیمت
                $("input[name=\'_price_adjustment_type\']").change(function() {
                    toggleAdjustmentFields();
                });
            });
            </script>';
            // +++ پایان کد جدید +++
        }

        /**
         * Saves the custom scraper fields when a product is saved.
         */
        public function save_scraper_fields($post_id, $post) {
            // +++ شروع کد جدید: ذخیره نوع اسکرپ +++
            if (isset($_POST['_scrape_type']) && in_array($_POST['_scrape_type'], ['api', 'local'])) {
                update_post_meta($post_id, '_scrape_type', sanitize_text_field($_POST['_scrape_type']));
            }
            // +++ پایان کد جدید +++

            if (isset($_POST['_source_url'])) {
                update_post_meta($post_id, '_source_url', esc_url_raw($_POST['_source_url']));
            }

            // +++ شروع کد جدید: ذخیره نشانه قیمت +++
            if (isset($_POST['_price_selector'])) {
                update_post_meta($post_id, '_price_selector', sanitize_text_field($_POST['_price_selector']));
            }
            // +++ پایان کد جدید +++

            if (isset($_POST['_torob_url'])) {
                update_post_meta($post_id, '_torob_url', esc_url_raw($_POST['_torob_url']));
            }

            // +++ شروع کد جدید: ذخیره نوع تنظیم قیمت +++
            if (isset($_POST['_price_adjustment_type']) && in_array($_POST['_price_adjustment_type'], ['percent', 'fixed'])) {
                update_post_meta($post_id, '_price_adjustment_type', sanitize_text_field($_POST['_price_adjustment_type']));
            }
            // +++ پایان کد جدید +++

            // +++ شروع کد جدید: ذخیره مبلغ ثابت +++
            if (isset($_POST['_price_adjustment_fixed'])) {
                update_post_meta($post_id, '_price_adjustment_fixed', floatval($_POST['_price_adjustment_fixed']));
            }
            // +++ پایان کد جدید +++

            // +++ شروع کد جدید: ذخیره دکمه رادیویی ترب +++
            if (isset($_POST['_torob_price_source']) && in_array($_POST['_torob_price_source'], ['mashhad', 'iran'])) {
                update_post_meta($post_id, '_torob_price_source', sanitize_text_field($_POST['_torob_price_source']));
            }
            // +++ پایان کد جدید +++

            // +++ شروع کد جدید: ذخیره اولویت اسکرپ +++
            if (isset($_POST['_scrape_priority']) && in_array($_POST['_scrape_priority'], ['period', 'low', 'medium', 'high'])) {
                update_post_meta($post_id, '_scrape_priority', sanitize_text_field($_POST['_scrape_priority']));
            }
            // +++ پایان کد جدید +++

            // +++ شروع کد جدید: ذخیره درصد تنظیم +++
            if (isset($_POST['_price_adjustment_percent'])) {
                update_post_meta($post_id, '_price_adjustment_percent', floatval($_POST['_price_adjustment_percent']));
            }
            // +++ پایان کد جدید +++

// برای محصولات جدید، پیش‌فرض رو 'yes' قرار بده
$auto_sync_value = isset($_POST['_auto_sync_variations']) ? 'yes' : 'no';
if (empty($_POST['_auto_sync_variations']) && get_post_meta($post_id, '_auto_sync_variations', true) === '') {
    $auto_sync_value = 'yes'; // پیش‌فرض برای محصولات جدید
}
update_post_meta($post_id, '_auto_sync_variations', $auto_sync_value);        }


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

            // 2. نمایش وضعیت اسکرپ عمومی
            $global_status = get_option('wcps_global_scrape_status', 'active');
            $status_text = ($global_status === 'active') ? 'فعال' : 'متوقف شده';
            $status_color = ($global_status === 'active') ? 'green' : 'red';
            echo '<p><strong>' . esc_html__('وضعیت اسکرپ عمومی:', 'wc-price-scraper') . '</strong><br><span style="color:' . $status_color . ';">' . esc_html($status_text) . '</span></p>';

            // 3. نمایش زمان آخرین اسکرپ موفق (با استفاده از لاگ)
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
            
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wc-price-scraper')) . '" class="button">' . esc_html__('رفتن به تنظیمات', 'wc-price-scraper') . '</a></p>';
            echo '</div>';
        }

        /**
         * Reads the last N lines from the log file.
         *
         * @param int $lines_count The number of lines to retrieve.
         * @return array An array of log lines.
         */
     public function get_log_lines($lines_count = 63) {
    $log_path = $this->plugin->get_log_path();
    if (!file_exists($log_path)) {
        return [__('فایل لاگ یافت نشد.', 'wc-price-scraper')];
    }

    // استفاده از روش بهینه برای خواندن فایل‌های بزرگ
    $lines = [];
    $file = new SplFileObject($log_path, 'r');
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key() + 1;
    
    $start_line = max(0, $total_lines - $lines_count);
    $file->seek($start_line);
    
    while (!$file->eof() && count($lines) < $lines_count) {
        $line = $file->current();
        if (!empty(trim($line))) {
            $lines[] = trim($line);
        }
        $file->next();
    }
    
    return $lines;
}

public function get_last_log_line($type_filter) {
    $log_path = $this->plugin->get_log_path();
    if (!file_exists($log_path)) return null;

    // فقط 1000 خط آخر رو بررسی کن
    $lines = $this->get_log_lines(1000);
    
    foreach ($lines as $line) {
        if (strpos($line, "[$type_filter]") !== false) {
            preg_match('/\\[(.*?)\\]/', $line, $matches);
            return [
                'timestamp' => isset($matches[1]) ? strtotime($matches[1]) : time(),
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

        /**
         * NEW: Adds scrape button to admin bar in frontend
         */
          public function add_admin_bar_scrape_button() {
            global $wp_admin_bar;
            
            // فقط در صفحه سینگل محصول و برای کاربران مجاز
            if (!is_singular('product') || !current_user_can('edit_products') || !is_admin_bar_showing()) {
                return;
            }
            
            global $post;
            $product_id = $post->ID;
            
            $wp_admin_bar->add_node([
                'id'    => 'scrape-price-now',
                'title' => '<span class="ab-icon dashicons dashicons-update-alt"></span>' . __('اسکرپ قیمت', 'wc-price-scraper'),
                'href'  => '#',
                'meta'  => [
                    'title' => __('اسکرپ قیمت این محصول', 'wc-price-scraper'),
                    'class' => 'scrape-price-admin-bar-btn',
                    'onclick' => "scrapePriceFromAdminBar({$product_id}); return false;"
                ]
            ]);
        }

        /**
         * NEW: Adds scripts for admin bar button
         */
        public function add_admin_bar_scripts() {
            // فقط در صفحه سینگل محصول و برای کاربران مجاز
            if (!is_singular('product') || !current_user_can('edit_products') || !is_admin_bar_showing()) {
                return;
            }
            ?>
            <script type="text/javascript">
            function scrapePriceFromAdminBar(productId) {
                if (!confirm('آیا از اسکرپ قیمت این محصول اطمینان دارید؟')) {
                    return;
                }
                
                var button = jQuery('#wp-admin-bar-scrape-price-now');
                var originalText = button.find('.ab-item').html();
                
                // نمایش اسپینر
                button.find('.ab-item').html('<span class="ab-icon dashicons dashicons-update-alt spinning"></span>در حال اسکرپ...');
                button.addClass('disabled');
                
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'scrape_price',
                        product_id: productId,
                        security: '<?php echo wp_create_nonce('scrape_price_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('اسکرپ با موفقیت انجام شد!');
                            location.reload();
                        } else {
                            alert('خطا: ' + (response.data.message || 'خطای ناشناخته'));
                        }
                    },
                    error: function(xhr) {
                        alert('خطای ارتباطی: ' + xhr.statusText);
                    },
                    complete: function() {
                        // بازگشت به حالت عادی
                        button.find('.ab-item').html(originalText);
                        button.removeClass('disabled');
                        jQuery('.dashicons.spinning').removeClass('spinning');
                    }
                });
            }
            
            // استایل برای اسپینر
            jQuery(document).ready(function($) {
                $('<style>').text(`
                    .dashicons.spinning {
                        animation: spin 1s infinite linear;
                    }
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                    #wp-admin-bar-scrape-price-now.disabled {
                        opacity: 0.6;
                        pointer-events: none;
                    }
                    #wp-admin-bar-scrape-price-now .ab-icon:before {
                        content: "\\f463";
                        font-family: dashicons;
                    }
                `).appendTo('head');
            });
            </script>
            <?php
        }
    }
}