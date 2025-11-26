<?php
/**
 * WC Price Scraper Local Scraper
 *
 * Handles local HTTP request scraping with CSS/XPath selectors
 *
 * @package WC_Price_Scraper
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCPS_Local_Scraper')) {
    class WCPS_Local_Scraper {
        
        private $plugin;
        
        public function __construct(WC_Price_Scraper $plugin) {
            $this->plugin = $plugin;
        }
        
        /**
         * Scrapes price from a URL using CSS selector or XPath
         *
         * @param string $url The URL to scrape
         * @param string $selector CSS selector or XPath
         * @return float|WP_Error Scraped price or error
         */
        public function scrape_price($url, $selector) {
            $this->plugin->debug_log("Starting local scrape for URL: {$url} with selector: {$selector}", 'LOCAL_SCRAPE_START');
            
            // Get HTML content
            $html_content = $this->get_html_content($url);
            if (is_wp_error($html_content)) {
                return $html_content;
            }
            
            // Load HTML into DOMDocument
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            
            // Handle encoding issues
            $html_content = mb_convert_encoding($html_content, 'HTML-ENTITIES', 'UTF-8');
            $dom->loadHTML($html_content);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Determine if selector is XPath or CSS
            if ($this->is_xpath($selector)) {
                $price_element = $this->get_element_by_xpath($xpath, $selector);
            } else {
                $price_element = $this->get_element_by_css($dom, $xpath, $selector);
            }
            
            if (!$price_element) {
                return new WP_Error('element_not_found', sprintf(__('عنصر قیمت با نشانه "%s" یافت نشد.', 'wc-price-scraper'), $selector));
            }
            
            // Extract price text
            $price_text = trim($price_element->textContent);
            $this->plugin->debug_log("Raw price text found: {$price_text}", 'LOCAL_SCRAPE_RAW');
            
            // Extract numeric price
            $price = $this->extract_numeric_price($price_text);
            if ($price === false) {
                return new WP_Error('price_not_found', sprintf(__('قیمت عددی در متن "%s" یافت نشد.', 'wc-price-scraper'), $price_text));
            }
            
            $this->plugin->debug_log("Successfully extracted price: {$price}", 'LOCAL_SCRAPE_SUCCESS');
            return $price;
        }
        
        /**
         * Gets HTML content from URL
         */
        private function get_html_content($url) {
            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                ]
            ]);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                return new WP_Error('http_error', sprintf(__('خطای HTTP: %d', 'wc-price-scraper'), $http_code));
            }
            
            return wp_remote_retrieve_body($response);
        }
        
        /**
         * Checks if selector is XPath
         */
        private function is_xpath($selector) {
            return strpos($selector, '/') === 0 || strpos($selector, './') === 0;
        }
        
        /**
         * Gets element by XPath
         */
        private function get_element_by_xpath($xpath, $selector) {
            $elements = $xpath->query($selector);
            return ($elements && $elements->length > 0) ? $elements->item(0) : null;
        }
        
        /**
         * Gets element by CSS selector (converts to XPath)
         */
        private function get_element_by_css($dom, $xpath, $selector) {
            // Simple CSS to XPath conversion for common selectors
            $converted_xpath = $this->css_to_xpath($selector);
            return $this->get_element_by_xpath($xpath, $converted_xpath);
        }
        
        /**
         * Simple CSS to XPath converter
         */
        private function css_to_xpath($selector) {
            // Remove whitespace
            $selector = trim($selector);
            
            // Convert common CSS selectors to XPath
            $xpath = '//' . str_replace(' ', '//', $selector);
            $xpath = preg_replace('/\.([a-zA-Z0-9_-]+)/', '[contains(@class, "$1")]', $xpath);
            $xpath = preg_replace('/#([a-zA-Z0-9_-]+)/', '[@id="$1"]', $xpath);
            $xpath = preg_replace('/\[([a-zA-Z0-9_-]+)=["\']([^"\']*)["\']\]/', '[@$1="$2"]', $xpath);
            
            return $xpath;
        }
        
        /**
         * Extracts numeric price from text
         */
        private function extract_numeric_price($text) {
            // Remove common currency symbols and text
            $clean_text = preg_replace('/[^\d,.]/', '', $text);
            
            // Handle Persian/Arabic numbers
            $clean_text = $this->normalize_persian_numbers($clean_text);
            
            // Remove commas (thousands separators)
            $clean_text = str_replace(',', '', $clean_text);
            
            // Extract float value
            if (preg_match('/(\d+\.?\d*)/', $clean_text, $matches)) {
                return floatval($matches[1]);
            }
            
            return false;
        }
        
        /**
         * Converts Persian/Arabic numbers to English
         */
        private function normalize_persian_numbers($text) {
            $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
            $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
            
            $text = str_replace($persian, $english, $text);
            $text = str_replace($arabic, $english, $text);
            
            return $text;
        }
        
        /**
         * Processes a product with local scraping
         */
        public function process_local_scrape($product_id, $source_url, $price_selector) {
            $this->plugin->debug_log("Processing local scrape for product #{$product_id}", 'LOCAL_PROCESS_START');
            
            // Scrape price
            $scraped_price = $this->scrape_price($source_url, $price_selector);
            if (is_wp_error($scraped_price)) {
                return $scraped_price;
            }
            
            // از اینجا به بعد رو پاک کن و بذار core مدیریت کنه
            // این تابع فقط قیمت رو برمی‌گردونه و تنظیم قیمت در core انجام میشه
            return $scraped_price;
        }
        
        /**
         * Converts variable product to simple product
         */
        private function convert_to_simple_product($product_id) {
            $this->plugin->debug_log("Converting product #{$product_id} from variable to simple", 'PRODUCT_CONVERSION');
            
            // Get all variations and delete them
            $product = wc_get_product($product_id);
            if ($product && $product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    // Skip protected variations
                    if (get_post_meta($variation_id, '_wcps_is_protected', true) !== 'yes') {
                        wp_delete_post($variation_id, true);
                    }
                }
            }
            
            // Change product type
            wp_set_object_terms($product_id, 'simple', 'product_type');
            
            // Clear product attributes
            update_post_meta($product_id, '_product_attributes', []);
            
            $this->plugin->debug_log("Product #{$product_id} converted to simple product", 'PRODUCT_CONVERSION_SUCCESS');
        }
    }
}