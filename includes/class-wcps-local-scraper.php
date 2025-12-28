<?php
if (!defined('ABSPATH')) exit;

class WCPS_Local_Scraper {

    /**
     * اجرای عملیات اسکرپ محلی با منطق جدید
     * * @param string $url لینک محصول
     * @param array $config آرایه تنظیمات شامل سلکتورها
     * @return array|WP_Error نتیجه شامل قیمت‌ها و وضعیت موجودی
     */
    public function scrape_product($url, $config) {
        // دریافت HTML
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('empty_html', 'محتوای HTML دریافت نشد.');
        }

        // پردازش DOM
        $dom = new DOMDocument();
        @ $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); // هندل کردن UTF-8
        $xpath = new DOMXPath($dom);

        // --- مرحله ۱: بررسی وضعیت موجودی (Stock Logic) ---
        $stock_status = 'instock'; // پیش‌فرض
        
        if (!empty($config['stock_status_selector'])) {
            $stock_nodes = $this->query_xpath($xpath, $config['stock_status_selector']);
            
            if ($stock_nodes && $stock_nodes->length > 0) {
                $stock_text = trim($stock_nodes->item(0)->textContent);
                
                // بررسی کلمات کلیدی ناموجود
                if (!empty($config['outofstock_keywords'])) {
                    $keywords = array_map('trim', explode(',', $config['outofstock_keywords']));
                    foreach ($keywords as $keyword) {
                        if (!empty($keyword) && mb_strpos($stock_text, $keyword) !== false) {
                            $stock_status = 'outofstock';
                            break;
                        }
                    }
                }
            }
        }

        // اگر ناموجود شد، دیگر نیازی به گشتن دنبال قیمت نیست (مگر اینکه بخواهید قیمت ناموجود آپدیت شود)
        // اما طبق توافق، اگر ناموجود باشد، قیمت مهم نیست. ولی ما قیمت را هم می‌گیریم شاید بعداً موجود شد.

        // --- مرحله ۲: استخراج قیمت‌ها ---
        
        // الف) قیمت فروش (Sale Price / Final Price) - اجباری
        $sale_price_raw = '';
        if (!empty($config['price_selector'])) {
            $nodes = $this->query_xpath($xpath, $config['price_selector']);
            if ($nodes && $nodes->length > 0) {
                $sale_price_raw = $nodes->item(0)->textContent;
            }
        }

        // ب) قیمت اصلی (Regular Price / Strikethrough) - اختیاری
        $regular_price_raw = '';
        if (!empty($config['regular_price_selector'])) {
            $nodes = $this->query_xpath($xpath, $config['regular_price_selector']);
            if ($nodes && $nodes->length > 0) {
                $regular_price_raw = $nodes->item(0)->textContent;
            }
        }

        // --- مرحله ۳: تمیزکاری و منطق قیمت ---
        $sale_price_cleaned = $this->clean_price($sale_price_raw);
        $regular_price_cleaned = $this->clean_price($regular_price_raw);

        // اگر قیمت فروش پیدا نشد، ارور برگردان
        if ($sale_price_cleaned <= 0) {
             // اگر محصول ناموجود بود، نداشتن قیمت مهم نیست
             if ($stock_status === 'outofstock') {
                 return [
                     'regular_price' => 0,
                     'sale_price'    => 0,
                     'stock_status'  => 'outofstock'
                 ];
             }
             return new WP_Error('price_not_found', 'قیمت محصول یافت نشد.');
        }

        // محاسبه نهایی
        $final_regular_price = $sale_price_cleaned;
        $final_sale_price = ''; // یعنی تخفیف ندارد

        // اگر قیمت خط‌خورده پیدا شد و معتبر بود
        if ($regular_price_cleaned > 0 && $regular_price_cleaned > $sale_price_cleaned) {
            $final_regular_price = $regular_price_cleaned;
            $final_sale_price = $sale_price_cleaned;
        }

        return [
            'regular_price' => $final_regular_price,
            'sale_price'    => $final_sale_price,
            'stock_status'  => $stock_status
        ];
    }

    /**
     * اجرای کوئری روی XPath با پشتیبانی از CSS Selector و XPath
     */
    private function query_xpath($xpath, $selector) {
        // تشخیص ساده: اگر با // شروع شود XPath است، وگرنه CSS Selector فرض می‌کنیم
        if (strpos(trim($selector), '//') === 0) {
            return @ $xpath->query($selector);
        } else {
            // تبدیل CSS به XPath (ساده‌سازی شده)
            // برای پروژه‌های بزرگ از کتابخانه CssSelector استفاده می‌شود ولی اینجا یک تبدیل ساده کافیست
            // اگر از ID یا Class استفاده شده باشد:
            if (strpos($selector, '#') === 0) {
                $id = substr($selector, 1);
                return @ $xpath->query("//*[@id='$id']");
            } elseif (strpos($selector, '.') === 0) {
                $class = substr($selector, 1);
                return @ $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]");
            } else {
                // تلاش برای استفاده مستقیم (گاهی جواب می‌دهد) یا بازگشت null
                // پیشنهاد: کاربر همیشه از کلاس یا آیدی استفاده کند
                return null; 
            }
        }
    }

    /**
     * تمیزکردن قیمت (تبدیل فارسی، حذف کاما، هندل کردن بازه)
     */
    private function clean_price($text) {
        if (empty($text)) return 0;

        // 1. تبدیل اعداد فارسی/عربی به انگلیسی
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        
        $text = str_replace($persian, $english, $text);
        $text = str_replace($arabic, $english, $text);

        // 2. هندل کردن بازه قیمتی (مثلاً: 1000 - 2000) -> برداشتن عدد اول
        if (strpos($text, '-') !== false) {
            $parts = explode('-', $text);
            $text = $parts[0]; // همیشه کف قیمت را بردار
        }

        // 3. حذف همه کاراکترها بجز اعداد و نقطه
        $text = preg_replace('/[^0-9\.]/', '', $text);

        return floatval($text);
    }
}
