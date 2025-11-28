<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WCPS_Core')) {
    class WCPS_Core {

        private $plugin;

        public function __construct(WC_Price_Scraper $plugin) {
            $this->plugin = $plugin;
        }

        public function process_single_product_scrape($pid, $url, $is_ajax = false) {
            $this->plugin->debug_log("Starting scrape for product #{$pid} from URL: {$url}");

            // Get scrape type
            $scrape_type = get_post_meta($pid, '_scrape_type', true);
            if (empty($scrape_type)) {
                $scrape_type = 'api'; // پیش‌فرض
            }
            
            $this->plugin->debug_log("Scrape type for product #{$pid}: {$scrape_type}", 'SCRAPE_TYPE');
            
            // Route based on scrape type
            if ($scrape_type === 'local') {
                return $this->process_local_scrape($pid, $url);
            } else {
                return $this->process_api_scrape($pid, $url, $is_ajax);
            }
        }

        /**
         * Process API-based scraping (original functionality)
         */
        private function process_api_scrape($pid, $url, $is_ajax = false) {
            $this->plugin->debug_log("Processing API scrape for product #{$pid}", 'API_SCRAPE_START');

            // --- استعلام از ترب ---
            $torob_url = get_post_meta($pid, '_torob_url', true);
            $torob_price = null;
            if (!empty($torob_url)) {
                $this->plugin->debug_log("Torob URL found for product #{$pid}. Starting Torob scrape.", 'TOROB_SCRAPE');
                $torob_price_source_key = get_post_meta($pid, '_torob_price_source', true) ?: 'mashhad';
                $api_suffix = $torob_price_source_key === 'mashhad' ? '/!mashhad!/' : '/';
                $torob_api_url = 'http://sajjcrapapi.qolamai.ir/sajjcrape?key=UXckq6pvj7&url=' . urlencode($torob_url . $api_suffix);

                $raw_torob_data = $this->plugin->make_torob_api_call($torob_api_url);

                if (is_wp_error($raw_torob_data)) {
                    $error_message = 'Torob Error: ' . $raw_torob_data->get_error_message();
                    update_post_meta($pid, '_last_torob_scrape_raw_result', $error_message);
                    $this->plugin->debug_log($error_message, 'TOROB_ERROR');
                } else {
                    update_post_meta($pid, '_last_torob_scrape_raw_result', $raw_torob_data);
                    $torob_data = json_decode($raw_torob_data, true);
                    if (is_array($torob_data) && !empty($torob_data) && isset($torob_data[0]['success']) && $torob_data[0]['success'] && isset($torob_data[0]['data']['lowest_price'])) {
                        $torob_price = (float) $torob_data[0]['data']['lowest_price'];
                        $this->plugin->debug_log("Torob lowest price: {$torob_price} (source: {$torob_price_source_key})", 'TOROB_SUCCESS');
                    } else {
                        $this->plugin->debug_log("Invalid Torob data structure: " . json_encode($torob_data), 'TOROB_ERROR');
                    }
                }
            } else {
                delete_post_meta($pid, '_last_torob_scrape_raw_result');
            }

            // --- استعلام از مرجع ---
            $raw_data = $this->plugin->make_api_call(WC_PRICE_SCRAPER_API_ENDPOINT . '?url=' . urlencode(urldecode($url)));

            if (is_wp_error($raw_data)) {
                if (get_option('wcps_on_failure_set_outofstock', 'yes') === 'yes') {
                    $this->plugin->debug_log("Setting product #{$pid} to out of stock due to scrape failure as per settings.");
                    $this->set_all_product_variations_outof_stock($pid);
                } else {
                    $this->plugin->debug_log("Skipping stock change for product #{$pid} on failure as per settings.");
                }
                update_post_meta($pid, '_scraped_data', []);
                update_post_meta($pid, '_last_scrape_raw_result', 'Error: ' . $raw_data->get_error_message());
                return $raw_data;
            }

            update_post_meta($pid, '_last_scrape_raw_result', $raw_data);

            $data = json_decode($raw_data, true);
            if (!is_array($data) || empty($data)) {
                return new WP_Error('invalid_data', __('داده‌های نامعتبر از API.', 'wc-price-scraper'));
            }

            // ++ START: FIX for non-pa_ attributes (Attribute Normalization) ++
            $processed_data = array();
            foreach ($data as $row) {
                $new_row = array();
                foreach ($row as $key => $value) {
                    // چک می‌کنیم که کلید، کلیدهای رزرو شده نباشد و با pa_ شروع نشود
                    if (!in_array($key, array('price', 'stock', 'url', 'image', 'seller', 'sku')) && strpos($key, 'pa_') !== 0) {
                        $new_key = 'pa_' . sanitize_title($key);
                        $this->plugin->debug_log("Renaming attribute key '{$key}' to '{$new_key}'", 'ATTR_RENAME');
                        $new_row[$new_key] = $value;
                    } else {
                        $new_row[$key] = $value;
                    }
                }
                $processed_data[] = $new_row;
            }
            $data = $processed_data;
            // -- END: FIX for non-pa_ attributes --

            // --- فیلترینگ پیشرفته ---
            $combined_rules = get_option('wcps_combined_rules', []);
            $filtered_data = $data;

            if (!empty($combined_rules)) {
                $this->plugin->debug_log("Applying combined rules for product #{$pid}", $combined_rules);
                foreach ($combined_rules as $rule) {
                    $key_to_check = $rule['key'];
                    $value_to_ignore = $rule['value'];
                    $filtered_data = array_values(array_filter($filtered_data, function ($item) use ($key_to_check, $value_to_ignore) {
                        return !isset($item[$key_to_check]) || $item[$key_to_check] != $value_to_ignore;
                    }));
                    $this->plugin->debug_log("Rule ({$key_to_check} = {$value_to_ignore}) applied. Items left: " . count($filtered_data));
                }
            }

            // اگر بعد از فیلتر هیچ داده‌ای باقی نماند، محصول را ناموجود کن
            if (empty($filtered_data)) {
                $this->set_all_product_variations_outof_stock($pid);
                update_post_meta($pid, '_scraped_data', []);
                $this->plugin->debug_log("No variations left after filtering, setting product #{$pid} to out of stock.");
                return new WP_Error('no_variations', __('هیچ متغیری پس از فیلتر باقی نماند.', 'wc-price-scraper'));
            }

            // --- گرفتن نوع تنظیم قیمت ---
            $adjustment_type = get_post_meta($pid, '_price_adjustment_type', true) ?: 'percent';
            $adjustment_value = 0;

            if ($adjustment_type === 'percent') {
                $adjustment_value = (float) get_post_meta($pid, '_price_adjustment_percent', true) ?: 0;
                $this->plugin->debug_log("Using PERCENT adjustment: {$adjustment_value}%", 'PRICE_ADJUSTMENT');
            } else {
                $adjustment_value = (float) get_post_meta($pid, '_price_adjustment_fixed', true) ?: 0;
                $this->plugin->debug_log("Using FIXED adjustment: {$adjustment_value} Tomans", 'PRICE_ADJUSTMENT');
            }

            $rounding_factor = (int) get_option('wcps_price_rounding_factor', 0);
            // انتخاب متغیر معتبر با کمترین قیمت
            $valid_variations = array_filter($filtered_data, function ($item) {
                return isset($item['stock']) && $item['stock'] === 'موجود در انبار' && isset($item['price']) && is_numeric($item['price']);
            });

            // ++ START MODIFICATION: Allow processing even if no in-stock variations found ++
            $min_price = PHP_FLOAT_MAX;
            $min_price_index = -1; // برای پیدا کردن index متغیری که min قیمت داره

            if (empty($valid_variations)) {
                $this->set_all_product_variations_outof_stock($pid); // ناموجود کردن والد
                $this->plugin->debug_log("No IN-STOCK variations found. All variations will be processed/created as out-of-stock.");

                // تنظیم قیمت مؤثر برای والد به صفر (محصول ناموجود)
                $effective_price = 0;
                
            } else {
                // lowest_price از منبع (برای والد)
                $prices = array_column($valid_variations, 'price');
                $lowest_source_price = min(array_map('floatval', $prices));

                // محاسبه قیمت مؤثر برای والد با منطق جدید
                $effective_price = $this->calculate_effective_price($lowest_source_price, $torob_price, $adjustment_type, $adjustment_value);
                
                // رند کردن اگر فعال باشه
                if ($rounding_factor > 0) {
                    $effective_price = round($effective_price / $rounding_factor) * $rounding_factor;
                }
            }

            // حالا برای هر متغیر: منطق مشابه اعمال کن (روی قیمت خودشون)
            $adjusted_variations_prices = [];
            $adjusted_variations_data = $filtered_data; // **تغییر اساسی: پردازش تمام متغیرهای فیلترشده**
            
            // FIX: لوپ روی تمام متغیرهای فیلترشده (شامل موجود و ناموجود)
            foreach ($filtered_data as $index => &$variation) { 
                $var_price = (float) $variation['price'];
                
                $is_instock = isset($variation['stock']) && $variation['stock'] === 'موجود در انبار';

                $adjusted_var_price = $var_price;
                
                // محاسبه قیمت مؤثر فقط برای متغیرهای موجود
                if ($is_instock) {
                    $adjusted_var_price = $this->calculate_effective_price($var_price, $torob_price, $adjustment_type, $adjustment_value);
                }
                
                if ($rounding_factor > 0) {
                    $adjusted_var_price = round($adjusted_var_price / $rounding_factor) * $rounding_factor;
                }
                
                $variation['price'] = (string) $adjusted_var_price; // بروزرسانی قیمت متغیر
                
                // فقط قیمت‌های موجود و مثبت برای تعیین min قیمت والد استفاده می‌شود
                if ($is_instock && $adjusted_var_price > 0) {
                    $adjusted_variations_prices[$index] = $adjusted_var_price;
                    
                    // پیدا کردن index متغیری که min قیمت داره
                    if ($adjusted_var_price < $min_price) {
                        $min_price = $adjusted_var_price;
                        $min_price_index = $index;
                    }
                } else {
                    // برای متغیرهای ناموجود، قیمت تنظیم شده نهایی در آرایه موقت صفر می‌شود
                    $adjusted_variations_prices[$index] = 0;
                }
                
                $adjusted_variations_data[$index] = $variation; // ذخیره برای استفاده بعدی
            }

            // تنظیم قیمت والد به min همه متغیرهای موجود (اصل نهایی)
            if ($min_price_index !== -1) {
                // اگر حداقل یک قیمت معتبر برای تعیین min پیدا شد، effective_price را به min تنظیم کن
                $effective_price = $min_price; 
            }
            // ++ END MODIFICATION ++

            $this->plugin->debug_log("Scrape completed for product #{$pid}. Final default price: {$effective_price}");

            // ذخیره داده‌های فیلترشده و تنظیم‌شده
            update_post_meta($pid, '_scraped_data', $filtered_data);
            update_post_meta($pid, '_last_scraped_time', time());

            // --- تهیه ویژگی‌های والد ---
            $this->prepare_parent_attributes_stable($pid, $filtered_data);

            // --- بروزرسانی قیمت محصول والد ---
            $product = wc_get_product($pid);
            if (!$product) return new WP_Error('product_not_found', __('محصول یافت نشد.', 'wc-price-scraper'));

            $product->set_regular_price($effective_price);
            $product->set_sale_price($effective_price);
            $product->set_price($effective_price);

            // محصول والد رو فقط اگه واریشن غیرمحافظت شده موجود داره، موجود کن
            $has_non_protected_instock = false;
            foreach ($product->get_children() as $variation_id) {
                if (get_post_meta($variation_id, '_wcps_is_protected', true) !== 'yes') {
                    $variation = wc_get_product($variation_id);
                    if ($variation && $variation->get_stock_status() === 'instock') {
                        $has_non_protected_instock = true;
                        break;
                    }
                }
            }

            if ($has_non_protected_instock) {
                $product->set_stock_status('instock');
            } else {
                $product->set_stock_status('outofstock');
            }
            $product->save();

            $this->plugin->debug_log("Updated parent product #{$pid} price to {$effective_price}.", 'PRICE_UPDATE');

            // --- ایجاد/بروزرسانی متغیرها ---
            $attributes = $product->get_attributes();
            $existing_variations = $product->get_children();
            $existing_variations_map = [];
            $scraped_keys = [];

            foreach ($existing_variations as $variation_id) {
                $variation_obj = wc_get_product($variation_id);
                if ($variation_obj) {
                    $attrs = $variation_obj->get_attributes();
                    $existing_variations_map[$this->generate_variation_key($attrs)] = $variation_id;
                }
            }

            foreach ($adjusted_variations_data as $row) {
                $variation_attributes = [];
                foreach ($row as $key => $value) {
                    if (strpos($key, 'pa_') === 0 && !empty($value)) {
                        $term = get_term_by('name', $value, $key);
                        if ($term) {
                            $variation_attributes[$key] = $term->slug;
                        }
                    }
                }

                if (empty($variation_attributes)) continue;

                $variation_key = $this->generate_variation_key($variation_attributes);
                $scraped_keys[] = $variation_key;

                $variation_price = (float) $row['price'];
                $stock_status = isset($row['stock']) && $row['stock'] === 'موجود در انبار' && $variation_price > 0 ? 'instock' : 'outofstock';

                if (isset($existing_variations_map[$variation_key])) {
                    $variation_id = $existing_variations_map[$variation_key];
                    $variation = wc_get_product($variation_id);
                    $variation->set_regular_price($variation_price);
                    $variation->set_sale_price($variation_price);
                    $variation->set_price($variation_price);
                    $variation->set_stock_status($stock_status);
                    $variation->save();
                    $this->plugin->debug_log("Updated variation #{$variation_id} for product #{$pid} with price {$variation_price}.");
                } else {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($pid);
                    $variation->set_attributes($variation_attributes);
                    $variation->set_regular_price($variation_price);
                    $variation->set_sale_price($variation_price);
                    $variation->set_price($variation_price);
                    $variation->set_stock_status($stock_status);
                    $variation_id = $variation->save();
                    $this->plugin->debug_log("Created new variation #{$variation_id} for product #{$pid}.");
                }
            }

            // حذف متغیرهای قدیمی
            foreach ($existing_variations_map as $key => $variation_id) {
                if (!in_array($key, $scraped_keys) && get_post_meta($variation_id, '_wcps_is_protected', true) !== 'yes') {
                    wp_delete_post($variation_id);
                    $this->plugin->debug_log("Deleted outdated variation #{$variation_id} for product #{$pid}.");
                }
            }

            // تنظیم متغیر پیش‌فرض به min قیمت
            if (!empty($adjusted_variations_data[$min_price_index])) {
                $default_attrs = [];
                $min_variation = $adjusted_variations_data[$min_price_index];
                foreach ($min_variation as $key => $value) {
                    if (strpos($key, 'pa_') === 0 && !empty($value)) {
                        $term = get_term_by('name', $value, $key);
                        if ($term) {
                            $default_attrs[$key] = $term->slug;
                        }
                    }
                }
                $product->set_default_attributes($default_attrs);
                $product->save();
                $this->plugin->debug_log("Set default attributes for product #{$pid}: " . json_encode($default_attrs));
                $this->plugin->debug_log("Set default variation with final price: {$min_price}");
            }

            wc_delete_product_transients($pid);

            return true; // موفقیت
        }

        /**
         * NEW: Calculate effective price based on adjustment type and torob price
         */
        private function calculate_effective_price($source_price, $torob_price, $adjustment_type, $adjustment_value) {
            $effective_price = $source_price; // پیش‌فرض بدون ترب
            
            if ($torob_price !== null) {
                $target = $torob_price - 1000; // ایده‌آل 1000 زیر ترب
                
                if ($adjustment_type === 'percent') {
                    // منطق درصدی
                    if ($adjustment_value >= 0) {
                        // دامنه: 0% تا +percent
                        $min_price = $source_price * 1.0;
                        $max_price = $source_price * (1 + $adjustment_value / 100);
                        $effective_price = max(min($max_price, $target), $min_price);
                    } else {
                        // دامنه: -percent تا 0%
                        $min_price = $source_price * (1 + $adjustment_value / 100);
                        $max_price = $source_price * 1.0;
                        $effective_price = max(min($max_price, $target), $min_price);
                    }
                } else {
                    // منطق مبلغ ثابت
                    $min_price = $source_price;
                    $max_price = $source_price + $adjustment_value;
                    
                    if ($adjustment_value >= 0) {
                        // افزایشی: دامنه source_price تا source_price + fixed
                        $effective_price = max(min($max_price, $target), $min_price);
                    } else {
                        // کاهشی: دامنه source_price + fixed تا source_price
                        $min_price = $source_price + $adjustment_value;
                        $max_price = $source_price;
                        $effective_price = max(min($max_price, $target), $min_price);
                    }
                }
                
                $adjustment_type_text = ($adjustment_type === 'percent') ? "percent: {$adjustment_value}%" : "fixed: {$adjustment_value} Tomans";
                $this->plugin->debug_log("Torob price ({$torob_price}) merged with source ({$source_price}). Adjusted effective price: {$effective_price} ({$adjustment_type_text})", 'PRICE_MERGE');
            } else {
                // بدون ترب: اعمال کامل تنظیم
                if ($adjustment_type === 'percent') {
                    $effective_price = $source_price * (1 + $adjustment_value / 100);
                } else {
                    $effective_price = $source_price + $adjustment_value;
                }
                
                $adjustment_type_text = ($adjustment_type === 'percent') ? "percent: {$adjustment_value}%" : "fixed: {$adjustment_value} Tomans";
                $this->plugin->debug_log("No Torob price. Applied {$adjustment_type_text} to source price {$source_price}. Result: {$effective_price}", 'PRICE_ADJUSTMENT');
            }

            return $effective_price;
        }

        /**
         * Process local scraping (new functionality) with Torob integration
         */
        private function process_local_scrape($pid, $url) {
            $this->plugin->debug_log("Processing LOCAL scrape for product #{$pid}", 'LOCAL_SCRAPE_START');
            
            // Get price selector
            $price_selector = get_post_meta($pid, '_price_selector', true);
            if (empty($price_selector)) {
                $error_msg = __('نشانه قیمت برای اسکرپ محلی تنظیم نشده است.', 'wc-price-scraper');
                $this->plugin->debug_log($error_msg, 'LOCAL_SCRAPE_ERROR');
                return new WP_Error('missing_selector', $error_msg);
            }
            
            $this->plugin->debug_log("Using price selector: {$price_selector}", 'LOCAL_SCRAPE_SELECTOR');
            
            // --- استعلام از ترب (کد جدید) ---
            $torob_url = get_post_meta($pid, '_torob_url', true);
            $torob_price = null;
            if (!empty($torob_url)) {
                $this->plugin->debug_log("Torob URL found for product #{$pid}. Starting Torob scrape.", 'TOROB_SCRAPE');
                $torob_price_source_key = get_post_meta($pid, '_torob_price_source', true) ?: 'mashhad';
                $api_suffix = $torob_price_source_key === 'mashhad' ? '/!mashhad!/' : '/';
                $torob_api_url = 'http://sajjcrapapi.qolamai.ir/sajjcrape?key=UXckq6pvj7&url=' . urlencode($torob_url . $api_suffix);

                $raw_torob_data = $this->plugin->make_torob_api_call($torob_api_url);

                if (is_wp_error($raw_torob_data)) {
                    $error_message = 'Torob Error: ' . $raw_torob_data->get_error_message();
                    update_post_meta($pid, '_last_torob_scrape_raw_result', $error_message);
                    $this->plugin->debug_log($error_message, 'TOROB_ERROR');
                } else {
                    update_post_meta($pid, '_last_torob_scrape_raw_result', $raw_torob_data);
                    $torob_data = json_decode($raw_torob_data, true);
                    
                    // +++ اصلاح این بخش +++
                    if (is_array($torob_data) && !empty($torob_data)) {
                        // پیدا کردن آیتم بر اساس منطقه انتخاب شده
                        foreach ($torob_data as $torob_item) {
                            if (isset($torob_item['success']) && $torob_item['success'] && 
                                isset($torob_item['message']) && 
                                isset($torob_item['data']['lowest_price']) && 
                                $torob_item['data']['lowest_price'] > 0) {
                                
                                // چک کردن منطقه بر اساس تنظیمات محصول
                                $is_correct_region = false;
                                if ($torob_price_source_key === 'mashhad' && strpos($torob_item['message'], 'مشهد') !== false) {
                                    $is_correct_region = true;
                                } elseif ($torob_price_source_key === 'iran' && strpos($torob_item['message'], 'ایران') !== false) {
                                    $is_correct_region = true;
                                }
                                
                                if ($is_correct_region) {
                                    $torob_price = (float) $torob_item['data']['lowest_price'];
                                    $this->plugin->debug_log("Torob {$torob_price_source_key} price found: {$torob_price}", 'TOROB_SUCCESS');
                                    break;
                                }
                            }
                        }
                        
                        if ($torob_price === null) {
                            $this->plugin->debug_log("No valid Torob price found for region: {$torob_price_source_key}", 'TOROB_ERROR');
                        }
                    } else {
                        $this->plugin->debug_log("Invalid Torob data structure", 'TOROB_ERROR');
                    }
                }
            } else {
                delete_post_meta($pid, '_last_torob_scrape_raw_result');
            }
            
            // Use local scraper
            if (isset($this->plugin->local_scraper)) {
                $scraped_price = $this->plugin->local_scraper->scrape_price($url, $price_selector);
                
                if (is_wp_error($scraped_price)) {
                    return $scraped_price;
                }
                
                // حالا با قیمت ترب ادغام کن (کد جدید)
                return $this->apply_price_adjustment_with_torob($pid, $scraped_price, $torob_price, true);
            } else {
                $error_msg = __('کلاس اسکرپ محلی در دسترس نیست.', 'wc-price-scraper');
                $this->plugin->debug_log($error_msg, 'LOCAL_SCRAPE_ERROR');
                return new WP_Error('local_scraper_not_found', $error_msg);
            }
        }

        /**
         * NEW: Apply price adjustment based on type (percent or fixed)
         */
        private function apply_price_adjustment($product_id, $scraped_price, $is_local = false) {
            $this->plugin->debug_log("Applying price adjustment for product #{$product_id}. Scraped price: {$scraped_price}", 'PRICE_ADJUSTMENT');
            
            $adjustment_type = get_post_meta($product_id, '_price_adjustment_type', true) ?: 'percent';
            $final_price = $scraped_price;
            
            // Apply adjustment based on type
            if ($adjustment_type === 'percent') {
                $adjustment_value = (float) get_post_meta($product_id, '_price_adjustment_percent', true) ?: 0;
                $final_price = $scraped_price * (1 + ($adjustment_value / 100));
                $this->plugin->debug_log("Applied percent adjustment: {$adjustment_value}%. Result: {$final_price}", 'PRICE_ADJUSTMENT');
            } else {
                $adjustment_value = (float) get_post_meta($product_id, '_price_adjustment_fixed', true) ?: 0;
                $final_price = $scraped_price + $adjustment_value;
                $this->plugin->debug_log("Applied fixed adjustment: {$adjustment_value} Tomans. Result: {$final_price}", 'PRICE_ADJUSTMENT');
            }
            
            // Apply rounding
            $rounding_factor = (int) get_option('wcps_price_rounding_factor', 0);
            if ($rounding_factor > 0) {
                $final_price = round($final_price / $rounding_factor) * $rounding_factor;
                $this->plugin->debug_log("Applied rounding: {$rounding_factor}. Final price: {$final_price}", 'PRICE_ADJUSTMENT');
            }
            
            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                return new WP_Error('product_not_found', __('محصول یافت نشد.', 'wc-price-scraper'));
            }
            
            // For local scraping, convert to simple product if it's variable
            if ($is_local && $product->is_type('variable')) {
                $this->convert_to_simple_product($product_id);
                $product = wc_get_product($product_id);
            }
            
            // Update product price
            $product->set_regular_price($final_price);
            $product->set_sale_price($final_price);
            $product->set_price($final_price);
            $product->set_stock_status('instock');
            $product->save();
            
            // Update metadata
            update_post_meta($product_id, '_last_scraped_time', time());
            
            $adjustment_info = ($adjustment_type === 'percent') ? 
                "Percent: " . get_post_meta($product_id, '_price_adjustment_percent', true) . "%" : 
                "Fixed: " . get_post_meta($product_id, '_price_adjustment_fixed', true) . " Tomans";
                
            update_post_meta($product_id, '_last_scrape_raw_result', 
                "Local Scrape - Original: {$scraped_price}, {$adjustment_info}, Final: {$final_price}");
            
            $this->plugin->debug_log("Price adjustment completed for product #{$product_id}. Final price: {$final_price}", 'PRICE_ADJUSTMENT_SUCCESS');
            
            return true;
        }

        /**
         * NEW: Apply price adjustment with Torob integration for local scraping
         */
        private function apply_price_adjustment_with_torob($product_id, $scraped_price, $torob_price, $is_local = false) {
            $this->plugin->debug_log("Applying price adjustment with Torob for product #{$product_id}. Scraped price: {$scraped_price}, Torob price: " . ($torob_price ?? 'N/A'), 'PRICE_ADJUSTMENT');
            
            $adjustment_type = get_post_meta($product_id, '_price_adjustment_type', true) ?: 'percent';
            
            // +++ درست کردن این بخش +++
            $adjustment_value = 0;
            if ($adjustment_type === 'percent') {
                $adjustment_value = (float) get_post_meta($product_id, '_price_adjustment_percent', true) ?: 0;
            } else {
                $adjustment_value = (float) get_post_meta($product_id, '_price_adjustment_fixed', true) ?: 0;
            }
            
            // استفاده از منطق مشابه API برای ادغام با ترب
            $final_price = $this->calculate_effective_price($scraped_price, $torob_price, $adjustment_type, $adjustment_value);
            
            // Apply rounding
            $rounding_factor = (int) get_option('wcps_price_rounding_factor', 0);
            if ($rounding_factor > 0) {
                $final_price = round($final_price / $rounding_factor) * $rounding_factor;
                $this->plugin->debug_log("Applied rounding: {$rounding_factor}. Final price: {$final_price}", 'PRICE_ADJUSTMENT');
            }
            
            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                return new WP_Error('product_not_found', __('محصول یافت نشد.', 'wc-price-scraper'));
            }
            
            // For local scraping, convert to simple product if it's variable
            if ($is_local && $product->is_type('variable')) {
                $this->convert_to_simple_product($product_id);
                $product = wc_get_product($product_id);
            }
            
            // Update product price
            $product->set_regular_price($final_price);
            $product->set_sale_price($final_price);
            $product->set_price($final_price);
            $product->set_stock_status('instock');
            $product->save();
            
            // Update metadata
            update_post_meta($product_id, '_last_scraped_time', time());
            
            $torob_info = $torob_price ? "Torob: {$torob_price}, " : "No Torob, ";
            $adjustment_info = ($adjustment_type === 'percent') ? 
                "Percent: " . get_post_meta($product_id, '_price_adjustment_percent', true) . "%" : 
                "Fixed: " . get_post_meta($product_id, '_price_adjustment_fixed', true) . " Tomans";
                
            update_post_meta($product_id, '_last_scrape_raw_result', 
                "Local Scrape - Original: {$scraped_price}, {$torob_info}{$adjustment_info}, Final: {$final_price}");
            
            $this->plugin->debug_log("Price adjustment with Torob completed for product #{$product_id}. Final price: {$final_price}", 'PRICE_ADJUSTMENT_SUCCESS');
            
            return true;
        }

        /**
         * Converts variable product to simple product (for local scraping)
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

        private function generate_variation_key($attributes) {
            ksort($attributes);
            return md5(json_encode($attributes));
        }

        public function prepare_parent_attributes_stable($pid, $scraped_data) {
            $this->plugin->debug_log("Preparing parent attributes using STABLE method for product #{$pid}.");
            $product = wc_get_product($pid);
            if (!$product) {
                $this->plugin->debug_log("Could not get product object for #{$pid} in prepare_parent_attributes_stable.");
                return;
            }
            $always_hide_keys = array_filter(array_map('trim', explode("\n", get_option('wcps_always_hide_keys', ''))));
            $conditional_rules = get_option('wcps_conditional_rules', []);
            $conditional_keys = !empty($conditional_rules) ? array_column($conditional_rules, 'key') : [];
            $managed_keys = array_unique(array_merge($always_hide_keys, $conditional_keys));
            $this->plugin->debug_log("Keys managed by visibility rules:", $managed_keys);
            $attribute_keys_cleaned = [];
            foreach ($scraped_data as $row) {
                foreach ($row as $k => $v) {
                    if (in_array(strtolower($k), ['price', 'stock', 'url', 'image', 'seller']) || $v === '' || $v === null) continue;
                    $clean_key = sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $k)));
                    if (!in_array($clean_key, $attribute_keys_cleaned)) $attribute_keys_cleaned[] = $clean_key;
                }
            }
            sort($attribute_keys_cleaned);

            $attributes_array_for_product = [];
            foreach ($attribute_keys_cleaned as $index => $attr_key_clean) {
                $taxonomy_name = 'pa_' . $attr_key_clean;

                if (!taxonomy_exists($taxonomy_name)) {
                    wc_create_attribute(['name' => ucfirst(str_replace('-', ' ', $attr_key_clean)), 'slug' => $attr_key_clean]);
                    $this->plugin->debug_log("Created global attribute: {$taxonomy_name}");
                }
                $attribute = new WC_Product_Attribute();
                $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy_name));
                $attribute->set_name($taxonomy_name);
                $all_terms_for_this_attr = [];
                foreach ($scraped_data as $item) {
                    foreach ($item as $key => $value) {
                        if (sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $key))) === $attr_key_clean && !empty($value)) {
                            $all_terms_for_this_attr[] = is_array($value) ? $value['label'] : $value;
                        }
                    }
                }
                $all_terms_for_this_attr = array_unique($all_terms_for_this_attr);
                $term_ids = [];
                foreach ($all_terms_for_this_attr as $term_name) {
                    $term = get_term_by('name', $term_name, $taxonomy_name);
                    if (!$term) {
                        $term_result = wp_insert_term($term_name, $taxonomy_name);
                        if (!is_wp_error($term_result)) $term_ids[] = $term_result['term_id'];
                    } else {
                        $term_ids[] = $term->term_id;
                    }
                }
                $attribute->set_options($term_ids);

                $is_visible_for_user = !in_array($taxonomy_name, $managed_keys);
                $attribute->set_visible($is_visible_for_user);
                $attribute->set_variation(true);

                $visibility_status = $is_visible_for_user ? 'Visible' : 'Hidden';
                $this->plugin->debug_log("Attribute Check: '{$taxonomy_name}'. Should be {$visibility_status}.");
                $attributes_array_for_product[] = $attribute;
            }
            $product->set_attributes($attributes_array_for_product);
            $product->save();

            $this->plugin->debug_log("Product attributes set via product object and saved for #{$pid}. This should clear caches.");
            wc_delete_product_transients($pid);
        }

        public function set_all_product_variations_outof_stock($pid) {
            $product = wc_get_product($pid);
            if ($product && $product->is_type('variable')) {
                $all_out_of_stock = true;
                
                foreach ($product->get_children() as $variation_id) {
                    if (get_post_meta($variation_id, '_wcps_is_protected', true) === 'yes') continue;
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $variation->set_stock_status('outofstock');
                        $variation->save();
                    }
                }
                
                // چک کن آیا همه واریشن‌های غیرمحافظت شده ناموجود شدن
                foreach ($product->get_children() as $variation_id) {
                    if (get_post_meta($variation_id, '_wcps_is_protected', true) === 'yes') {
                        continue;
                    }
                    
                    $variation = wc_get_product($variation_id);
                    if ($variation && $variation->get_stock_status() === 'instock') {
                        $all_out_of_stock = false;
                        break;
                    }
                }
                
                // اگر همه واریشن‌های غیرمحافظت شده ناموجود بودن، والد رو هم ناموجود کن
                if ($all_out_of_stock) {
                    $product->set_stock_status('outofstock');
                    $product->save();
                }
            }
        }
    }
}