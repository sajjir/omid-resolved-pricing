<?php
if (!defined('ABSPATH')) exit;

?>
<div class="wrap">
    <h1><?php esc_html_e('محصولات محافظت‌شده', 'wc-price-scraper'); ?></h1>
    <p class="description"><?php esc_html_e('لیست محصولاتی که حداقل یکی از واریشن‌های آن‌ها گزینه «محافظت از این واریشن» فعال شده است.', 'wc-price-scraper'); ?></p>

    <?php
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ];

    $products = get_posts($args);
    $protected_products = [];

    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                if (get_post_meta($variation_id, '_wcps_is_protected', true) === 'yes') {
                    $protected_products[] = $product_id;
                    break;
                }
            }
        }
    }

    if (!empty($protected_products)) :
    ?>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-primary">#</th>
                    <th scope="col" class="manage-column"><?php esc_html_e('نام محصول', 'wc-price-scraper'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('عملیات', 'wc-price-scraper'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($protected_products as $index => $product_id) : ?>
                    <tr>
                        <td><?php echo esc_html($index + 1); ?></td>
                        <td><?php echo esc_html(get_the_title($product_id)); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" class="button button-primary" target="_blank">
                                <?php esc_html_e('ویرایش محصول', 'wc-price-scraper'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php esc_html_e('هیچ محصولی با واریشن محافظت‌شده یافت نشد.', 'wc-price-scraper'); ?></p>
        </div>
    <?php endif; ?>
</div>