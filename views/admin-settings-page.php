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
            <h2 class="hndle"><span><?php esc_html_e('قوانین حذف و پنهان‌سازی ویژگی‌ها', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <p class="description">
                    <?php esc_html_e('قوانینی برای حذف مطلق متغیرها بر اساس ویژگی و مقدار خاص تعریف کنید. اگر تمام متغیرها حذف شوند، محصول ناموجود می‌شود.', 'wc-price-scraper'); ?>
                </p>
                <table class="form-table wcps-repeater-table" id="wcps-rules-container-table">
                    <tbody id="wcps-rules-container">
                        <?php
                        $combined_rules = get_option('wcps_combined_rules', []);
                        if (empty($combined_rules)) { $combined_rules[] = ['key' => '', 'value' => '']; }
                        foreach ($combined_rules as $i => $rule) :
                        ?>
                        <tr valign="top" class="wcps-rule-row">
                            <td>
                                <label><?php esc_html_e('اگر ویژگی', 'wc-price-scraper'); ?></label>
                                <input type="text" name="wcps_combined_rules[<?php echo $i; ?>][key]" value="<?php echo esc_attr($rule['key']); ?>" placeholder="مثال: pa_location-inventory" class="regular-text" />
                            </td>
                            <td>
                                <label><?php esc_html_e('برابر بود با', 'wc-price-scraper'); ?></label>
                                <input type="text" name="wcps_combined_rules[<?php echo $i; ?>][value]" value="<?php echo esc_attr($rule['value']); ?>" placeholder="مثال: مرکزی تهران" class="regular-text" />
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
            <h2 class="hndle"><span><?php esc_html_e('کنترل وضعیت اسکرپ ها', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <!-- اسکرپ عمومی -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('اسکرپ عمومی (پریود)', 'wc-price-scraper'); ?></th>
                        <td>
                            <?php
                            $global_status = get_option('wcps_global_scrape_status', 'active');
                            $global_status_text = ($global_status === 'active') ? 'فعال' : 'متوقف شده';
                            $global_status_color = ($global_status === 'active') ? 'green' : 'red';
                            ?>
                            <span id="global_status_display" style="font-weight: bold; color: <?php echo $global_status_color; ?>;">
                                <?php echo esc_html($global_status_text); ?>
                            </span>
                            <br><br>
                            <?php if ($global_status === 'active'): ?>
                                <button type="button" class="button button-danger" id="pause_global_scrape">
                                    <?php esc_html_e('⏸ توقف اسکرپ عمومی', 'wc-price-scraper'); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="button button-primary" id="start_global_scrape">
                                    <?php esc_html_e('▶ شروع اسکرپ عمومی', 'wc-price-scraper'); ?>
                                </button>
                            <?php endif; ?>
                            <span class="spinner" id="global_scrape_spinner"></span>
                            <span id="global_scrape_status" style="margin-right: 10px; font-weight: bold;"></span>
                        </td>
                    </tr>
                    
                    <!-- اسکرپ اولویت‌بندی شده -->
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('اسکرپ اولویت‌بندی شده', 'wc-price-scraper'); ?></th>
                        <td>
                            <?php
                            $priority_status = get_option('wcps_priority_scrape_status', 'active');
                            $priority_status_text = ($priority_status === 'active') ? 'فعال' : 'متوقف شده';
                            $priority_status_color = ($priority_status === 'active') ? 'green' : 'red';
                            ?>
                            <span id="priority_status_display" style="font-weight: bold; color: <?php echo $priority_status_color; ?>;">
                                <?php echo esc_html($priority_status_text); ?>
                            </span>
                            <br><br>
                            <?php if ($priority_status === 'active'): ?>
                                <button type="button" class="button button-danger" id="pause_priority_scrape">
                                    <?php esc_html_e('⏸ توقف اسکرپ اولویتی', 'wc-price-scraper'); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="button button-primary" id="start_priority_scrape">
                                    <?php esc_html_e('▶ شروع اسکرپ اولویتی', 'wc-price-scraper'); ?>
                                </button>
                            <?php endif; ?>
                            <span class="spinner" id="priority_scrape_spinner"></span>
                            <span id="priority_scrape_status" style="margin-right: 10px; font-weight: bold;"></span>
                        </td>
                    </tr>
                </table>
                <p class="description"><?php esc_html_e('اسکرپ عمومی: محصولات با اولویت "پریود" | اسکرپ اولویتی: محصولات با اولویت "کم"، "متوسط" و "بالا"', 'wc-price-scraper'); ?></p>
            </div>
        </div>

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('اولویت‌بندی اسکرپ', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="wcps_priority_low_interval"><?php esc_html_e('فاصله اولویت کم (ساعت)', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="number" id="wcps_priority_low_interval" name="wcps_priority_low_interval" value="<?php echo esc_attr(get_option('wcps_priority_low_interval', 6)); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('هر چند ساعت یکبار محصولات با اولویت "کم" به‌روز شوند.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wcps_priority_medium_interval"><?php esc_html_e('فاصله اولویت متوسط (ساعت)', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="number" id="wcps_priority_medium_interval" name="wcps_priority_medium_interval" value="<?php echo esc_attr(get_option('wcps_priority_medium_interval', 3)); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('هر چند ساعت یکبار محصولات با اولویت "متوسط" به‌روز شوند.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="wcps_priority_high_interval"><?php esc_html_e('فاصله اولویت بالا (ساعت)', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="number" id="wcps_priority_high_interval" name="wcps_priority_high_interval" value="<?php echo esc_attr(get_option('wcps_priority_high_interval', 1)); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('هر چند ساعت یکبار محصولات با اولویت "بالا" به‌روز شوند.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="description">
                    <?php esc_html_e('توجه: محصولات با اولویت "پریود" از تنظیمات عمومی اسکرپ پیروی می‌کنند.', 'wc-price-scraper'); ?>
                </p>
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
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        

        <div class="postbox">
            <h2 class="hndle"><span><?php esc_html_e('تنظیمات ادغام N8N', 'wc-price-scraper'); ?></span></h2>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('فعال‌سازی ادغام N8N', 'wc-price-scraper'); ?></th>
                        <td>
                            <label for="wc_price_scraper_n8n_enable">
                                <input name="wc_price_scraper_n8n_enable" type="checkbox" id="wc_price_scraper_n8n_enable" value="yes" <?php checked('yes', get_option('wc_price_scraper_n8n_enable', 'no')); ?> />
                                <?php esc_html_e('ارسال داده‌های قیمت به N8N', 'wc-price-scraper'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wc_price_scraper_n8n_webhook_url"><?php esc_html_e('آدرس وب‌هوک N8N', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="url" id="wc_price_scraper_n8n_webhook_url" name="wc_price_scraper_n8n_webhook_url" value="<?php echo esc_attr(get_option('wc_price_scraper_n8n_webhook_url', '')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('آدرس وب‌هوکی که داده‌های محصول به آن ارسال می‌شود.', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wc_price_scraper_n8n_model_slug"><?php esc_html_e('اسلاگ‌های مدل (جدا شده با کاما)', 'wc-price-scraper'); ?></label></th>
                        <td>
                            <input type="text" id="wc_price_scraper_n8n_model_slug" name="wc_price_scraper_n8n_model_slug" value="<?php echo esc_attr(get_option('wc_price_scraper_n8n_model_slug', '')); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('اسلاگ‌های ویژگی مدل را وارد کنید (مثال: model,pa_model).', 'wc-price-scraper'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wc_price_scraper_n8n_purchase_link_text"><?php esc_html_e('متن لینک خرید', 'wc-price-scraper'); ?></label></th>
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
                            <button type="button" class="button button-danger" id="force_stop_button"><?php esc_html_e('توقف تمام عملیات و پاک‌سازی کامل صف', 'wc-price-scraper'); ?></button>
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
    <p class="wcps-footer"><b><?php esc_html_e('پلاگین توسعه داده شده توسط', 'wc-price-scraper'); ?> <a href="https://sajj.ir/" target="_blank">sajj.ir</a></b></p>
</div>