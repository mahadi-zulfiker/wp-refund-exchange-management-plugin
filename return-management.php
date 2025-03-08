<?php
/** 
 * Plugin Name: Return Management
 * Description: Adds return, refund and exchange features
 * Version: 1.0
 * Author: Amitav Roy Chowdhury
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/manage_return.php';
require_once plugin_dir_path(__FILE__) . 'includes/refund_admin.php';

add_action("wp_enqueue_scripts", "return_styles");
function return_styles()
{
    wp_enqueue_style('return', plugin_dir_url(__FILE__) . 'assets/css/return.css', [], '1.0');
    wp_localize_script('custom_refund_script', 'custom_refund_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('custom_refund_nonce')
    ]);
}
register_activation_hook(__FILE__, 'create_refund_requests_table');
function create_refund_requests_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'refund_requests';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL,
        vendor_id BIGINT UNSIGNED NOT NULL,
        refund_reason TEXT NOT NULL,
        restocking_fee DECIMAL(10,2) NOT NULL,
        status VARCHAR(50) DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}


add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input([
        'id' => '_restocking_fee',
        'label' => __('Restocking Fee ($)', 'woocommerce'),
        'desc_tip' => true,
        'description' => __('Enter the restocking fee for this product.', 'woocommerce'),
        'type' => 'number',
        'custom_attributes' => [
            'step' => '0.01',
            'min' => '0'
        ]
    ]);
});
add_action('woocommerce_process_product_meta', function ($post_id) {
    if (isset($_POST['_restocking_fee'])) {
        update_post_meta($post_id, '_restocking_fee', sanitize_text_field($_POST['_restocking_fee']));
    }
});

function dokan_add_restocking_fee_field($post)
{
    $restocking_fee = (isset($post->ID)) ? get_post_meta($post->ID, '_restocking_fee', true) : '';
    ?>
    <div class="dokan-form-group">
        <label for="_restocking_fee" class="dokan-w3"><?php _e('Restocking Fee ($)', 'your-textdomain'); ?></label>
        <input type="number" name="_restocking_fee" id="_restocking_fee" value="<?php echo esc_attr($restocking_fee); ?>"
            class="dokan-form-control" step="0.01" min="0">
        <p class="description"><?php _e('Enter the restocking fee for this product.', 'your-textdomain'); ?></p>
    </div>
    <?php
}
add_action('dokan_product_edit_after_pricing', 'dokan_add_restocking_fee_field', 10, 1);
function dokan_save_restocking_fee_field($post_id, $post)
{
    if (isset($_POST['_restocking_fee'])) {
        update_post_meta($post_id, '_restocking_fee', sanitize_text_field($_POST['_restocking_fee']));
    }
}
add_action('dokan_new_product_added', 'dokan_save_restocking_fee_field', 10, 2);
add_action('dokan_product_updated', 'dokan_save_restocking_fee_field', 10, 2);
function display_coupon_banner()
{
    if (!session_id()) {
        session_start();
    }

    if (!empty($_SESSION['exchange_coupon'])) {
        $coupon_code = $_SESSION['exchange_coupon'];
        echo '<div class="coupon-banner">Use code <strong>'." " . esc_html($coupon_code) . " ".'</strong> for redeem your exchanged amount!</div>';

        // Unset session after displaying the banner
        unset($_SESSION['exchange_coupon']);
    }
}
add_action('woocommerce_before_shop_loop', 'display_coupon_banner');
