```php
<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php esc_html_e('محصولات دارای لینک ترب', 'wc-price-scraper'); ?></h1>
    <p><?php esc_html_e('لیست محصولاتی که لینک ترب (Torob URL) برای آن‌ها ثبت شده است.', 'wc-price-scraper'); ?></p>

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
    $torob_products = [];

    foreach ($products as $pid) {
        $torob_products[] = [
            'id' => $pid,
            'title' => get_the_title($pid),
        ];
    }

    if (!empty($torob_products)) :
    ?>
        <ul class="ul-disc" style="margin-right: 20px;">
            <?php foreach ($torob_products as $product) : ?>
                <li>
                    <a href="<?php echo esc_url(get_edit_post_link($product['id'])); ?>" target="_blank">
                        <?php echo esc_html($product['title']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p><?php esc_html_e('هیچ محصولی با لینک ترب یافت نشد.', 'wc-price-scraper'); ?></p>
    <?php endif; ?>
</div>
```