<?php
/**
 * WC Price Scraper Terab Integration
 *
 * Handles Terab.com specific scraping and price adjustment logic.
 * MODIFIED: This version uses an external API to get Terab prices.
 *
 * @package WC_Price_Scraper
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('WCPS_Terab')) {
    class WCPS_Terab {

        protected $main_plugin;
        protected $core_plugin;

        public function __construct(WC_Price_Scraper $main_plugin, WCPS_Core $core_plugin) {
            $this->main_plugin = $main_plugin;
            $this->core_plugin = $core_plugin;
        }

        /**
         * Scrapes a price from a given Terab URL by calling an external API.
         *
         * @param string $url The URL to get the price for.
         * @return float|WP_Error The scraped price or WP_Error on failure.
         */
        public function scrape_price_from_terab($url) {
            if (empty($url)) {
                return new WP_Error('invalid_args', __('URL ترب برای استعلام از API وجود ندارد.', 'wc-price-scraper'));
            }

            // ++ START: NEW API CALL LOGIC ++

            // 1. ساخت URL نهایی برای فراخوانی API
            $api_endpoint = 'http://davamcenter.com/sajjapi?url=';
            $api_url = $api_endpoint . urlencode($url);

            $this->main_plugin->debug_log("Attempting to get Terab price from API: {$api_url}", 'TERAB_API_CALL');

            // 2. فراخوانی API با استفاده از متد موجود در پلاگین
            $response_body = $this->main_plugin->make_api_call($api_url);

            // 3. بررسی خطا در فراخوانی API
            if (is_wp_error($response_body)) {
                $this->main_plugin->debug_log("API call failed (WP_Error): " . $response_body->get_error_message(), 'TERAB_API_ERROR');
                return $response_body;
            }

            // 4. رمزگشایی پاسخ JSON
            $data = json_decode($response_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->main_plugin->debug_log("Failed to decode JSON from API.", 'TERAB_API_ERROR', $response_body);
                return new WP_Error('json_decode_error', __('پاسخ دریافتی از API معتبر نبود.', 'wc-price-scraper'));
            }

            // 5. استخراج کمترین قیمت از پاسخ
            // با فرض اینکه کمترین قیمت همیشه در کلید 'price' قرار دارد.
            if (isset($data['price']) && is_numeric($data['price'])) {
                $price = (float) $data['price'];

                if ($price > 0) {
                    $this->main_plugin->debug_log("Successfully got price {$price} from Terab API.", 'TERAB_API_SUCCESS');
                    return $price;
                }
            }
            
            // -- END: NEW API CALL LOGIC --

            $this->main_plugin->debug_log("Could not find a valid price in the API response.", 'TERAB_API_ERROR', $data);
            return new WP_Error('price_not_found_in_api', __('قیمت معتبری در پاسخ API یافت نشد.', 'wc-price-scraper'));
        }
    }
}
