<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('محصولات دارای واریشن ناموجود در منبع', 'wc-price-scraper'); ?></h1>
    <p class="description">
        <?php esc_html_e('لیست محصولاتی که همگام‌سازی خودکار دارند و در آخرین اسکرپ، حداقل یکی از واریشن‌های آن‌ها در سایت منبع "ناموجود" بوده است.', 'wc-price-scraper'); ?>
    </p>
    <hr class="wp-header-end">

    <?php
    // 1. گرفتن تمام محصولاتی که لینک منبع و همگام‌سازی فعال دارند
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'     => '_source_url',
                'value'   => '',
                'compare' => '!=',
            ],
            [
                'key'     => '_auto_sync_variations',
                'value'   => 'yes',
                'compare' => '=',
            ],
        ],
    ];

    $candidates = get_posts($args);
    $target_products = [];

    // 2. بررسی دقیق دیتای اسکرپ شده برای پیدا کردن ناموجودها
    foreach ($candidates as $pid) {
        $scraped_data = get_post_meta($pid, '_scraped_data', true);
        
        // اگر دیتای اسکرپ وجود نداشت، رد شو
        if (!is_array($scraped_data) || empty($scraped_data)) {
            continue;
        }

        $found_outofstock = false;
        
        // پیمایش روی تک تک واریشن‌های اسکرپ شده
        foreach ($scraped_data as $item) {
            // شرط: اگر کلید stock وجود داشت و مقدارش "موجود در انبار" نبود
            if (isset($item['stock']) && $item['stock'] !== 'موجود در انبار') {
                $found_outofstock = true;
                break; // یکی هم پیدا کنیم کافیه
            }
        }

        if ($found_outofstock) {
            $target_products[] = $pid;
        }
    }

    if (!empty($target_products)) :
    ?>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-primary" style="width: 50px;">#</th>
                    <th scope="col" class="manage-column"><?php esc_html_e('نام محصول', 'wc-price-scraper'); ?></th>
                    <th scope="col" class="manage-column" style="width: 200px;"><?php esc_html_e('عملیات', 'wc-price-scraper'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($target_products as $index => $product_id) : ?>
                    <tr>
                        <td><?php echo esc_html($index + 1); ?></td>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" target="_blank">
                                    <?php echo esc_html(get_the_title($product_id)); ?>
                                </a>
                            </strong>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" class="button button-primary" target="_blank">
                                <?php esc_html_e('ویرایش محصول', 'wc-price-scraper'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <span class="displaying-num"><?php echo sprintf(__('%d مورد یافت شد', 'wc-price-scraper'), count($target_products)); ?></span>
            </div>
        </div>

    <?php else : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e('هیچ محصولی با واریشن ناموجود در منبع یافت نشد.', 'wc-price-scraper'); ?></p>
        </div>
    <?php endif; ?>
</div>