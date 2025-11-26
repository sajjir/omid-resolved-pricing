<?php
if (!defined('ABSPATH')) exit;

?>
<div class="wrap">
    <h1><?php esc_html_e('محصولات دارای لینک ترب', 'wc-price-scraper'); ?></h1>
    <p class="description"><?php esc_html_e('لیست محصولاتی که دارای لینک ترب هستند.', 'wc-price-scraper'); ?></p>

    <?php
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_torob_url',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
    ];

    $products = get_posts($args);

    if (!empty($products)) :
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
                <?php foreach ($products as $index => $product_id) : ?>
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
            <p><?php esc_html_e('هیچ محصولی با لینک ترب یافت نشد.', 'wc-price-scraper'); ?></p>
        </div>
    <?php endif; ?>
</div>