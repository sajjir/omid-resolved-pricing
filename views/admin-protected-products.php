```php
<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php esc_html_e('محصولات محافظت‌شده', 'wc-price-scraper'); ?></h1>
    <p><?php esc_html_e('لیست محصولاتی که حداقل یکی از واریشن‌های آن‌ها گزینه «محافظت از این واریشن» فعال شده است.', 'wc-price-scraper'); ?></p>

    <?php
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];
    $products = get_posts($args);
    $protected_products = [];

    foreach ($products as $pid) {
        $product = wc_get_product($pid);
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                if (get_post_meta($variation_id, '_wcps_is_protected', true) === 'yes') {
                    $protected_products[] = [
                        'id' => $pid,
                        'title' => get_the_title($pid),
                    ];
                    break; // No need to check other variations
                }
            }
        }
    }

    if (!empty($protected_products)) :
    ?>
        <ul class="ul-disc" style="margin-right: 20px;">
            <?php foreach ($protected_products as $product) : ?>
                <li>
                    <a href="<?php echo esc_url(get_edit_post_link($product['id'])); ?>" target="_blank">
                        <?php echo esc_html($product['title']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p><?php esc_html_e('هیچ محصول محافظت‌شده‌ای یافت نشد.', 'wc-price-scraper'); ?></p>
    <?php endif; ?>
</div>
```