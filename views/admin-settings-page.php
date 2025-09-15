<?php
if (!defined('ABSPATH')) exit;

// Add this PHP block at the top of the file to handle the form submission for clearing the log
if (isset($_POST['wcps_action']) && $_POST['wcps_action'] === 'clear_failed_log') {
    if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'wcps_clear_failed_log_nonce')) {
        delete_option('wcps_failed_scrapes');
        echo '<div class="updated"><p>' . esc_html__('لیست خطاهای اسکرپ با موفقیت پاک شد.', 'wc-price-scraper') . '</p></div>';
    }
}
?>
<div class="wrap wcps-settings-wrap">
    <h1><?php esc_html_e('تنظیمات اسکرپر قیمت ووکامرس', 'wc-price-scraper'); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields('wc_price_scraper_group'); ?>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('بخش ۱: پنهان‌سازی و ادغام ویژگی‌ها', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <p class="description">
                    <?php esc_html_e('ویژگی‌هایی که در اینجا وارد می‌کنید (مثل گارانتی، کد داخلی و...) همیشه از دید کاربر پنهان شده و در فرآیند ادغام متغیرهای مشابه استفاده می‌شوند.', 'wc-price-scraper'); ?>
                </p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="wcps_always_hide_keys"><?php esc_html_e('کلید ویژگی‌ها برای پنهان‌سازی', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <textarea id="wcps_always_hide_keys" name="wcps_always_hide_keys" rows="4" class="large-text code" placeholder="<?php esc_attr_e('هر کلید را در یک خط وارد کنید. مثال: pa_guarantee', 'wc-price-scraper'); ?>"><?php echo esc_textarea(get_option('wcps_always_hide_keys', '')); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('بخش ۲: قوانین حذف شرطی متغیرها', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <p class="description">
                    <?php esc_html_e('قوانینی برای حذف متغیرها بر اساس مقدار یک ویژگی خاص تعریف کنید.', 'wc-price-scraper'); ?><br>
                    <strong><?php esc_html_e('نکته مهم:', 'wc-price-scraper'); ?></strong> <?php esc_html_e('اگر قانونی باعث شود هیچ متغیری باقی نماند، اجرا نشده و صرفاً ویژگی مربوطه از صفحه محصول پنهان خواهد شد.', 'wc-price-scraper'); ?>
                </p>
                <table class="form-table wcps-repeater-table" id="wcps-conditional-rules-table">
                    <tbody id="wcps-rules-container">
                        <?php
                        $conditional_rules = get_option('wcps_conditional_rules', []);
                        if (empty($conditional_rules)) { $conditional_rules[] = ['key' => '', 'value' => '']; }
                        foreach ($conditional_rules as $i => $rule) :
                        ?>
                        <tr valign="top" class="wcps-rule-row">
                            <td>
                                <label><?php esc_html_e('اگر ویژگی', 'wc-price-scraper'); ?></label>
                                <input type="text" name="wcps_conditional_rules[<?php echo $i; ?>][key]" value="<?php echo esc_attr($rule['key']); ?>" placeholder="مثال: pa_location-inventory" class="regular-text" />
                            </td>
                            <td>
                                <label><?php esc_html_e('برابر بود با', 'wc-price-scraper'); ?></label>
                                <input type="text" name="wcps_conditional_rules[<?php echo $i; ?>][value]" value="<?php echo esc_attr($rule['value']); ?>" placeholder="مثال: فروشگاه مشهد" class="regular-text" />
                            </td>
                            <td class="wcps-repeater-action">
                                <button type="button" class="button button-link-delete wcps-remove-rule" title="<?php esc_attr_e('حذف این قانون', 'wc-price-scraper'); ?>"><span class="dashicons dashicons-trash"></span></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">
                                <button type="button" class="button" id="wcps-add-rule"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('افزودن قانون جدید', 'wc-price-scraper'); ?></button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('تنظیمات عمومی و کرون‌جاب', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="wcps_price_rounding_factor"><?php esc_html_e('ضریب رند کردن قیمت', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="number" id="wcps_price_rounding_factor" name="wcps_price_rounding_factor" value="<?php echo esc_attr(get_option('wcps_price_rounding_factor', 0)); ?>" min="0" class="small-text">
                            <p class="description"><?php esc_html_e('یک عدد برای گرد کردن قیمت نهایی وارد کنید. برای مثال اگر ۱۰۰۰ وارد کنید، قیمت ۱,۵۵۰,۸۰۰ به ۱,۵۵۱,۰۰۰ رند می‌شود. برای غیرفعال کردن، عدد ۰ را وارد کنید.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wc_price_scraper_cron_interval"><?php esc_html_e('فاصله به‌روزرسانی (ساعت)', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="number" id="wc_price_scraper_cron_interval" name="wc_price_scraper_cron_interval" value="<?php echo esc_attr(get_option('wc_price_scraper_cron_interval', 12)); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('هر چند ساعت یکبار قیمت تمام محصولات به صورت خودکار به‌روز شود.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('رفتار در زمان شکست', 'wc-price-scraper'); ?></th>
                        <td>
                            <label for="wcps_on_failure_set_outofstock">
                                <input name="wcps_on_failure_set_outofstock" type="checkbox" id="wcps_on_failure_set_outofstock" value="yes" <?php checked('yes', get_option('wcps_on_failure_set_outofstock', 'yes')); ?> />
                                <?php esc_html_e('اگر اسکرپ ناموفق بود، محصول ناموجود شود.', 'wc-price-scraper'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('اگر تیک نخورده باشد، در صورت بروز خطا هیچ تغییری در وضعیت انبار محصول ایجاد نمی‌شود و صرفاً خطا ثبت می‌گردد.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('وضعیت کرون جاب', 'wc-price-scraper'); ?></th>
                        <td>
                            <?php
                            $next_cron = wp_next_scheduled('wc_price_scraper_cron_event');
                            $now = current_time('timestamp');
                            $default_interval = intval(get_option('wc_price_scraper_cron_interval', 12)) * 3600;
                            $diff = $next_cron ? max(0, $next_cron - $now) : $default_interval;
                            ?>
                            <div class="wcps-cron-status">
                                <div>
                                    <span class="wcps-cron-label"><?php esc_html_e('زمان اجرای بعدی:', 'wc-price-scraper'); ?></span>
                                    <span id="cron_countdown" data-seconds-left="<?php echo esc_attr($diff); ?>">--:--</span>
                                </div>
                                <button type="button" class="button button-primary" id="force_reschedule_button">
                                    <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('شروع فوری کران جاب', 'wc-price-scraper'); ?>
                                </button>
                                <span class="spinner"></span>
                            </div>
                            <span id="reschedule_status" class="wcps-status-text"></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('به‌روزرسانی با فرکانس بالا (برای محصولات خاص)', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                 <p class="description">
                    <?php esc_html_e('شناسه محصولاتی که نیاز به به‌روزرسانی سریع‌تر دارند را در کادر زیر وارد کنید (هر شناسه در یک خط). سپس فاصله زمانی دلخواه خود را بر حسب دقیقه تنظیم کنید.', 'wc-price-scraper'); ?>
                </p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="wcps_high_frequency_pids"><?php esc_html_e('شناسه محصولات', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <textarea id="wcps_high_frequency_pids" name="wcps_high_frequency_pids" rows="8" class="large-text" placeholder="<?php esc_attr_e("مثال:\n123\n456\n789", 'wc-price-scraper'); ?>"><?php echo esc_textarea(get_option('wcps_high_frequency_pids', '')); ?></textarea>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wcps_high_frequency_interval"><?php esc_html_e('فاصله به‌روزرسانی (دقیقه)', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="number" id="wcps_high_frequency_interval" name="wcps_high_frequency_interval" value="<?php echo esc_attr(get_option('wcps_high_frequency_interval', 30)); ?>" min="1" class="small-text">
                             <p class="description"><?php esc_html_e('این کران‌جاب به صورت مستقل از کران‌جاب عمومی اجرا خواهد شد.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                     <tr valign="top">
                        <th scope="row"><?php esc_html_e('عملیات فوری', 'wc-price-scraper'); ?></th>
                        <td>
                            <button type="button" class="button button-secondary" id="force_scrape_high_frequency">
                                <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('اسکرپ فوری این محصولات', 'wc-price-scraper'); ?>
                            </button>
                            <span class="spinner" id="hf_spinner"></span>
                            <span id="hf_status" class="wcps-status-text"></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('تنظیمات یکپارچه‌سازی با N8N', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('فعال‌سازی N8N', 'wc-price-scraper'); ?></th>
                        <td>
                            <label for="wc_price_scraper_n8n_enable">
                                <input name="wc_price_scraper_n8n_enable" type="checkbox" id="wc_price_scraper_n8n_enable" value="yes" <?php checked('yes', get_option('wc_price_scraper_n8n_enable', 'no')); ?> />
                                <?php esc_html_e('ارسال داده به N8N پس از همگام‌سازی موفق.', 'wc-price-scraper'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wc_price_scraper_n8n_webhook_url"><?php esc_html_e('URL وب‌هوک N8N', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="url" id="wc_price_scraper_n8n_webhook_url" name="wc_price_scraper_n8n_webhook_url" value="<?php echo esc_attr(get_option('wc_price_scraper_n8n_webhook_url', '')); ?>" class="large-text" placeholder="https://n8n.example.com/webhook/your-hook-id" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wc_price_scraper_n8n_model_slug"><?php esc_html_e('نامک ویژگی برای "مدل"', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="text" id="wc_price_scraper_n8n_model_slug" name="wc_price_scraper_n8n_model_slug" value="<?php echo esc_attr(get_option('wc_price_scraper_n8n_model_slug', '')); ?>" class="regular-text" placeholder="<?php esc_attr_e('مثال: model یا size', 'wc-price-scraper'); ?>" />
                            <p class="description"><?php esc_html_e('نامک (slug) ویژگی که می‌خواهید به عنوان "مدل" در داده‌های ارسالی به N8N استفاده شود (بدون پیشوند pa_).', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wc_price_scraper_n8n_purchase_link_text"><?php esc_html_e('متن لینک خرید برای شیت', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="text" id="wc_price_scraper_n8n_purchase_link_text" name="wc_price_scraper_n8n_purchase_link_text" value="<?php echo esc_attr(get_option('wc_price_scraper_n8n_purchase_link_text', 'Buy Now')); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('عملیات اضطراری', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('توقف کامل', 'wc-price-scraper'); ?></th>
                        <td>
                            <button type="button" class="button button-danger" id="force_stop_button">توقف تمام عملیات و پاک‌سازی کامل صف</button>
                            <p class="description"><?php esc_html_e('اگر احساس می‌کنید فرآیندی گیر کرده و متوقف نمی‌شود، از این دکمه استفاده کنید. این دکمه تمام کران‌جاب‌های این پلاگین (چه در حال اجرا و چه زمان‌بندی شده) را فوراً حذف می‌کند.', 'wc-price-scraper'); ?></p>
                            <span id="stop_status" style="margin-left: 10px; font-weight: bold;"></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('گزارش آخرین فعالیت‌ها (۶۳ خط آخر)', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <div id="wcps-log-viewer">
                    <pre><?php
                        // We need an instance of the admin class to call the method.
                        // This is a simplified way for a view file.
                        $admin_class_instance = WC_Price_Scraper::instance()->admin;
                        $log_lines = $admin_class_instance->get_log_lines(63);
                        foreach (array_reverse($log_lines) as $line) {
                            echo esc_html($line) . "\n";
                        }
                    ?></pre>
                </div>
                <style>
                    #wcps-log-viewer pre {
                        background-color: #f7f7f7;
                        border: 1px solid #ccc;
                        padding: 15px;
                        max-height: 400px;
                        overflow-y: scroll;
                        white-space: pre-wrap;
                        word-wrap: break-word;
                        direction: ltr;
                        text-align: left;
                    }
                </style>
            </div>
        </div>
        
        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('گزارش محصولات ناموفق در اسکرپ', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <p class="description">
                    <?php esc_html_e('در این بخش، محصولاتی که در آخرین تلاش‌ها برای اسکرپ با خطا مواجه شده‌اند لیست می‌شوند. با کلیک روی هر مورد می‌توانید به صفحه ویرایش آن محصول بروید و مشکل را بررسی کنید (مثلاً اصلاح URL منبع).', 'wc-price-scraper'); ?>
                </p>
                
                <?php
                $failed_scrapes = get_option('wcps_failed_scrapes', []);
                if (!empty($failed_scrapes)) :
                ?>
                    <ul class="ul-disc" style="margin-right: 20px;">
                        <?php foreach (array_reverse($failed_scrapes, true) as $product_id => $data) : ?>
                            <li>
                                <strong><a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" target="_blank"><?php echo esc_html($data['product_title'] ?? "محصول با شناسه {$product_id}"); ?></a></strong>
                                <p style="margin-top: 0; margin-bottom: 10px;">
                                    <small>
                                        <?php echo esc_html(date_i18n('Y/m/d H:i:s', $data['timestamp'])); ?> - 
                                        <em><?php esc_html_e('خطا:', 'wc-price-scraper'); ?> <?php echo esc_html($data['error_message']); ?></em>
                                    </small>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" id="wcps-clear-log-button" class="button button-delete">
                        <?php esc_html_e('پاک کردن لیست خطاها', 'wc-price-scraper'); ?>
                    </button>
                    <span class="spinner" id="wcps-clear-log-spinner"></span>
                    <span id="wcps-clear-log-status" style="margin-right: 10px; font-weight: bold;"></span>
                <?php else : ?>
                    <p><?php esc_html_e('هیچ خطایی در اسکرپ محصولات ثبت نشده است. همه چیز به درستی کار می‌کند.', 'wc-price-scraper'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <?php submit_button(); ?>
    </form>
    <p class="wcps-footer"><b>پلاگین توسعه داده شده توسط <a href="https://sajj.ir/" target="_blank">sajj.ir</a></b></p>
</div>