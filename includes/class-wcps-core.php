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
            
            // --- استعلام از ترب ---
            $torob_url = get_post_meta($pid, '_torob_url', true);
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
                    $this->plugin->debug_log("Successfully scraped Torob data for product #{$pid}.", 'TOROB_SUCCESS');
                }
            } else {
                delete_post_meta($pid, '_last_torob_scrape_raw_result');
            }

            $raw_data = $this->plugin->make_api_call(WC_PRICE_SCRAPER_API_ENDPOINT . '?url=' . urlencode($url));

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
                $this->set_all_product_variations_outof_stock($pid);
                update_post_meta($pid, '_scraped_data', []);
                return new WP_Error('no_data', __('داده معتبری از API اسکرپینگ دریافت نشد.', 'wc-price-scraper'));
            }

            // --- فیلترینگ پیشرفته ---
            $conditional_rules = get_option('wcps_conditional_rules', []);
            $data_after_conditional_filter = $data;

            if (!empty($conditional_rules)) {
                $this->plugin->debug_log("Applying conditional rules for product #{$pid}", $conditional_rules);
                $temp_data = $data;
                foreach ($conditional_rules as $rule) {
                    $key_to_check = $rule['key']; $value_to_ignore = $rule['value'];
                    $filtered_tentatively = array_values(array_filter($temp_data, function ($item) use ($key_to_check, $value_to_ignore) {
                        return !isset($item[$key_to_check]) || $item[$key_to_check] != $value_to_ignore;
                    }));
                    if (!empty($filtered_tentatively)) {
                        $temp_data = $filtered_tentatively;
                        $this->plugin->debug_log("Rule ({$key_to_check} = {$value_to_ignore}) applied. Items left: " . count($temp_data));
                    } else {
                        $this->plugin->debug_log("Rule ({$key_to_check} = {$value_to_ignore}) ignored because it would remove all variations.");
                    }
                }
                $data_after_conditional_filter = $temp_data;
            }

            $always_hide_keys = array_filter(array_map('trim', explode("\n", get_option('wcps_always_hide_keys', ''))));
            $final_data = [];

            if (!empty($always_hide_keys)) {
                $this->plugin->debug_log("Applying always-hide/deduplicate logic for product #{$pid}", $always_hide_keys);
                $seen_fingerprints = [];
                foreach ($data_after_conditional_filter as $item) {
                    $core_attributes = $item;
                    foreach ($always_hide_keys as $key_to_hide) { unset($core_attributes[$key_to_hide]); }
                    $fingerprint = md5(json_encode($core_attributes));
                    if (!in_array($fingerprint, $seen_fingerprints)) {
                        $seen_fingerprints[] = $fingerprint;
                        $final_data[] = $item;
                    }
                }
            } else {
                $final_data = $data_after_conditional_filter;
            }

            $final_data = array_values(array_map('unserialize', array_unique(array_map('serialize', $final_data))));
            
            if (empty($final_data)) {
                $this->set_all_product_variations_outof_stock($pid);
                update_post_meta($pid, '_scraped_data', []);
                return new WP_Error('filtered_out', __('همه داده‌ها توسط قوانین فیلترینگ حذف شدند.', 'wc-price-scraper'));
            }

            update_post_meta($pid, '_scraped_data', $final_data);

            $auto_sync_enabled = get_post_meta($pid, '_auto_sync_variations', true) === 'yes';
            if ($is_ajax || $auto_sync_enabled) {
                $this->sync_product_variations($pid, $final_data);
            }

            return $final_data;
        }

	public function sync_product_variations($pid, $scraped_data) {
            $this->plugin->debug_log("Starting smart variation sync for product #{$pid}");
            $parent_product = wc_get_product($pid);
            if (!$parent_product || !$parent_product->is_type('variable')) {
                $this->plugin->debug_log("Parent product #{$pid} not found or not variable for sync.");
                return;
            }

            $this->prepare_parent_attributes_stable($pid, $scraped_data);

            $existing_variation_ids = $parent_product->get_children();
            $all_variations_map = [];
            $protected_variation_ids = [];

            foreach ($existing_variation_ids as $var_id) {
                if (get_post_meta($var_id, '_wcps_is_protected', true) === 'yes') {
                    $protected_variation_ids[] = $var_id;
                }
                $variation = wc_get_product($var_id);
                if (!$variation) continue;
                $attributes = $variation->get_attributes();
                ksort($attributes);
                $all_variations_map[md5(json_encode($attributes))] = $var_id;
            }

            // ===================================================================
            // +++ شروع منطق جدید قیمت‌گذاری هوشمند +++
            // ===================================================================
            $this->plugin->debug_log("--- Starting Smart Pricing Logic for #{$pid} ---", 'SMART_PRICE');
            
            // مرحله ۱: استخراج قیمت ترب (قبل از هر محاسبه‌ای)
            $this->plugin->debug_log("Step 1: Extracting Torob price.", 'SMART_PRICE');
            $torob_price = 0;
            $torob_raw_result = get_post_meta($pid, '_last_torob_scrape_raw_result', true);
            if ($torob_raw_result) {
                $torob_data = json_decode($torob_raw_result, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($torob_data)) {
                    $source_key = get_post_meta($pid, '_torob_price_source', true) ?: 'mashhad';
                    $message_key = $source_key === 'mashhad' ? 'OK (مشهد)' : 'OK (ایران)';
                    
                    foreach ($torob_data as $entry) {
                        if (isset($entry['message']) && $entry['message'] === $message_key && isset($entry['data']['lowest_price'])) {
                            $torob_price = (float) $entry['data']['lowest_price'];
                            break;
                        }
                    }
                }
            }
            $this->plugin->debug_log("Torob price extracted: {$torob_price}", 'SMART_PRICE');

            // مرحله ۲: محاسبه قیمت استاندارد و پیدا کردن وریشن هدف
            $this->plugin->debug_log("Step 2: Calculating standard prices and finding target variation.", 'SMART_PRICE');
            $adjustment_percent = (float) get_post_meta($pid, '_price_adjustment_percent', true);
            $calculated_prices = [];
            $target_variation_hash = null;
            $min_standard_price = PHP_INT_MAX;

            foreach ($scraped_data as $item) {
                $attr_data = [];
                foreach ($item as $k => $v) {
                    if (in_array(strtolower($k), ['price', 'stock', 'url', 'image', 'seller']) || $v === '' || $v === null) continue;
                    $clean_key = sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $k)));
                    $taxonomy = 'pa_' . $clean_key;
                    $term_name = is_array($v) ? ($v['label'] ?? $v['name']) : $v;
                    if (empty($term_name)) continue;
                    $term = get_term_by('name', $term_name, $taxonomy);
                    $attr_data[$taxonomy] = $term ? $term->slug : sanitize_title($term_name);
                }
                if (empty($attr_data)) continue;
                ksort($attr_data);
                $variation_hash = md5(json_encode($attr_data));

                $base_price = isset($item['price']) ? (float) preg_replace('/[^0-9.]/', '', $item['price']) : 0;
                
                // +++ شروع اصلاحیه اصلی +++
                $current_adjustment = $adjustment_percent;
                // اگر قیمت ترب معتبر است و قیمت پایه ما از آن بالاتر است، درصد سود مثبت را نادیده بگیر
                if ($torob_price > 0 && $base_price > $torob_price && $adjustment_percent > 0) {
                    $current_adjustment = 0;
                    $this->plugin->debug_log("Adjustment override for hash {$variation_hash}: Base price {$base_price} > Torob price {$torob_price}. Ignoring positive adjustment.", 'SMART_PRICE_ADJUST');
                }
                $standard_price = $base_price * (1 + ($current_adjustment / 100));
                // +++ پایان اصلاحیه اصلی +++

                $calculated_prices[$variation_hash] = ['base_price' => $base_price, 'standard_price' => $standard_price];

                if ($standard_price > 0 && $standard_price < $min_standard_price) {
                    $min_standard_price = $standard_price;
                    $target_variation_hash = $variation_hash;
                }
            }
            $this->plugin->debug_log("Step 2 Completed. Target variation hash: {$target_variation_hash}, Min Standard Price: {$min_standard_price}", 'SMART_PRICE');


            // مرحله ۳: اعمال منطق اصلی قیمت‌گذاری
            $this->plugin->debug_log("Step 3: Applying main pricing logic.", 'SMART_PRICE');
            $final_prices = [];
            
            if ($torob_price > 0 && $target_variation_hash !== null) {
                $target_standard_price = $calculated_prices[$target_variation_hash]['standard_price'];
                $new_target_price = $target_standard_price;

                // سناریو ۱: قیمت ما بالاتر از ترب است
                if ($target_standard_price > $torob_price) {
                    $this->plugin->debug_log("Scenario 1: Our price is higher. Our: {$target_standard_price}, Torob: {$torob_price}", 'SMART_PRICE');
                    $max_allowed_discount_price = $target_standard_price;
                    if ($adjustment_percent < 0) {
                       $max_allowed_discount_price = $calculated_prices[$target_variation_hash]['base_price'] * (1 + ($adjustment_percent / 100));
                    }
                    $new_target_price = max($max_allowed_discount_price, $torob_price - 1000);
                    $this->plugin->debug_log("New target price (Scenario 1): {$new_target_price} (Based on Max Discount: {$max_allowed_discount_price})", 'SMART_PRICE');

                // سناریو ۲: قیمت ما پایین‌تر از ترب است
                } else {
                    $this->plugin->debug_log("Scenario 2: Our price is lower. Our: {$target_standard_price}, Torob: {$torob_price}", 'SMART_PRICE');
                    $new_target_price = $torob_price - 1000;
                    $this->plugin->debug_log("New target price (Scenario 2): {$new_target_price}", 'SMART_PRICE');
                }

                $price_diff = $new_target_price - $target_standard_price;
                
                foreach ($calculated_prices as $hash => $prices) {
                    $final_prices[$hash] = $prices['standard_price'] + $price_diff;
                }
                $this->plugin->debug_log("Price difference calculated: {$price_diff}. All other variations adjusted accordingly.", 'SMART_PRICE');

            } else {
                // سناریو ۳ و ۴: قیمت ترب نامعتبر یا لینک ترب موجود نیست
                $this->plugin->debug_log("Scenario 3/4: No valid Torob price or URL. Using standard prices.", 'SMART_PRICE');
                foreach ($calculated_prices as $hash => $prices) {
                    $final_prices[$hash] = $prices['standard_price'];
                }
            }

            // ===================================================================
            // +++ پایان منطق جدید قیمت‌گذاری هوشمند +++
            // ===================================================================


            // --- حلقه اصلی برای ایجاد و به‌روزرسانی وریشن‌ها ---
            $created_or_updated = [];
            foreach ($scraped_data as $item) {
                $attr_data = [];
                foreach ($item as $k => $v) {
                    if (in_array(strtolower($k), ['price', 'stock', 'url', 'image', 'seller']) || $v === '' || $v === null) continue;
                    $clean_key = sanitize_title(urldecode(str_replace(['attribute_pa_', 'pa_'], '', $k)));
                    $taxonomy = 'pa_' . $clean_key;
                    $term_name = is_array($v) ? ($v['label'] ?? $v['name']) : $v;
                    if (empty($term_name)) continue;
                    $term = get_term_by('name', $term_name, $taxonomy);
                    $attr_data[$taxonomy] = $term ? $term->slug : sanitize_title($term_name);
                }
                if (empty($attr_data)) continue;
                ksort($attr_data);
                $variation_hash = md5(json_encode($attr_data));
                
                $var_id = $all_variations_map[$variation_hash] ?? null;

                if ($var_id && in_array($var_id, $protected_variation_ids)) {
                    $created_or_updated[] = $var_id;
                    $this->plugin->debug_log("Skipping update for protected variation #{$var_id}.");
                    continue;
                }

                $variation = ($var_id) ? wc_get_product($var_id) : new WC_Product_Variation();
                if (!$var_id) {
                    $variation->set_parent_id($pid);
                }
                
                $variation->set_attributes($attr_data);
                
                // مرحله ۴: ذخیره قیمت نهایی محاسبه شده
                if (isset($final_prices[$variation_hash])) {
                    $price = round($final_prices[$variation_hash], 2);
                    $variation->set_price($price);
                    $variation->set_regular_price($price);
                }

                if (isset($item['stock'])) {
                    $outofstock_keywords = ['ناموجود', 'نمی باشد', 'نمیباشد', 'اتمام'];
                    $is_outofstock = false;
                    foreach ($outofstock_keywords as $keyword) {
                        if (strpos($item['stock'], $keyword) !== false) {
                            $is_outofstock = true;
                            break;
                        }
                    }
                    $variation->set_stock_status($is_outofstock ? 'outofstock' : 'instock');
                }
                
                $variation_id = $variation->save();
                if (empty($variation->get_sku())) {
                    $variation->set_sku((string)$variation_id);
                    $variation->save();
                }
                $created_or_updated[] = $variation_id;
            }

            $unprotected_variation_ids = array_diff($existing_variation_ids, $protected_variation_ids);
            $variations_to_delete = array_diff($unprotected_variation_ids, $created_or_updated);

            foreach ($variations_to_delete as $var_id_to_delete) {
                wp_delete_post($var_id_to_delete, true);
                $this->plugin->debug_log("Deleted obsolete variation #{$var_id_to_delete}.");
            }
            
            $this->plugin->debug_log("Smart variation sync complete for product #{$pid}.");
            
            // --- تنظیم وریشن پیش‌فرض (بدون تغییر) ---
            if (!empty($scraped_data)) {
                $in_stock_items = []; $out_of_stock_items = [];
                $outofstock_keywords = ['ناموجود', 'نمی باشد', 'نمیباشد', 'اتمام'];
                foreach ($scraped_data as $item) {
                    $is_outofstock = false;
                    if (isset($item['stock'])) {
                        foreach ($outofstock_keywords as $keyword) {
                            if (strpos($item['stock'], $keyword) !== false) { $is_outofstock = true; break; }
                        }
                    }
                    if (!isset($item['price']) || empty($item['price'])) { $is_outofstock = true; }
                    if ($is_outofstock) { $out_of_stock_items[] = $item; } else { $in_stock_items[] = $item; }
                }
                $lowest_price_item = null;
                $target_items = !empty($in_stock_items) ? $in_stock_items : $out_of_stock_items;
                if (!empty($target_items)) {
                    $min_price = PHP_INT_MAX;
                    foreach ($target_items as $item) {
                        if (isset($item['price'])) {
                            $cleaned_price = (float) preg_replace('/[^0-9.]/', '', $item['price']);
                            if ($cleaned_price > 0 && $cleaned_price < $min_price) {
                                $min_price = $cleaned_price;
                                $lowest_price_item = $item;
                            }
                        }
                    }
                }
                if ($lowest_price_item !== null) {
                    $default_attributes = [];
                    $non_attribute_keys = ['price', 'stock', 'url', 'image', 'seller'];
                    foreach ($lowest_price_item as $key => $value) {
                        if (in_array(strtolower($key), $non_attribute_keys) || empty($value)) continue;
                        $taxonomy_key = strpos($key, 'pa_') !== 0 ? 'pa_' . sanitize_title($key) : $key;
                        $term_name = is_array($value) ? ($value['label'] ?? $value['name']) : $value;
                        $term = get_term_by('name', $term_name, $taxonomy_key);
                        if ($term && !is_wp_error($term)) {
                            $default_attributes[$taxonomy_key] = $term->slug;
                        }
                    }
                    if (!empty($default_attributes)) {
                        update_post_meta($pid, '_default_attributes', $default_attributes);
                        $this->plugin->debug_log("Set default attributes for product #{$pid} using REAL term slugs.", $default_attributes);
                    }
                }
            }
            wc_delete_product_transients($pid);
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
                foreach ($product->get_children() as $variation_id) {
                    if (get_post_meta($variation_id, '_wcps_is_protected', true) === 'yes') continue;
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $variation->set_stock_status('outofstock');
                        $variation->save();
                    }
                }
            }
        }
    }
}