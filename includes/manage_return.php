<?php
if (!defined('ABSPATH')) {
    exit;
}

// Adds Refund and Exchange Buttons to the Order Page
add_action('woocommerce_order_details_after_order_table', 'add_refund_exchange_buttons');
function add_refund_exchange_buttons($order)
{
    $order_id = $order->get_id();
    $exchanged = get_post_meta($order_id, '_exchanged', true); // Track if exchanged
    $used_coupons = $order->get_coupon_codes(); 
    $used_store_credit = get_post_meta($order_id, '_used_store_credit', true); // Check store credits
    $is_refunded = $order->has_status('refunded'); // Check if already refunded
    $refund_requested = get_post_meta($order_id, '_refund_requested', true);
    $disable_buttons = !empty($used_coupons) || !empty($used_store_credit) || $is_refunded || $exchanged || $refund_requested;

    if ($exchanged || $disable_buttons) {
        echo '<button id="request-refund-btn" class="refund_btn" disabled>Refund not available</button>';
    } else {
        echo '<button id="request-refund-btn" class="refund_btn">Request Refund</button>';
    }
    echo '<div id="refund-form" style="display:none;">
            <h3>Request a Refund</h3>
            <textarea id="refund-reason" placeholder="Enter refund reason" required></textarea>
            <button id="submit-refund" class="button">Submit Request</button>
          </div>';
    if ($exchanged || $disable_buttons) {
        echo '<button id="request-exchange-btn" class="exchange_btn" disabled>Exchange not available</button>';
    } else {
        echo '<button id="request-exchange-btn" class="exchange_btn">Request Exchange</button>';
    }
    echo '<div id="refund-form" style="display:none;">
            <h3>Request a Refund</h3>
            <textarea id="refund-reason" placeholder="Enter refund reason" required></textarea>
            <button id="submit-refund" class="button">Submit Request</button>
          </div>';

    ?>
    <script>
        document.getElementById("request-refund-btn").addEventListener("click", function () {
            document.getElementById("refund-form").style.display = "block";
        });

        document.getElementById("submit-refund").addEventListener("click", function () {
            var refundReason = document.getElementById("refund-reason").value;
            if (!refundReason) {
                alert("Please enter a refund reason.");
                return;
            }

            var orderId = "<?php echo $order->get_id(); ?>";

            fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "action=request_refund&order_id=" + orderId + "&refund_reason=" + encodeURIComponent(refundReason)
            })
                .then(response => response.json())
                .then(data => {
                    alert(data.message);
                    location.reload();
                })
                .catch(error => console.error("Error:", error));
        });
        document.getElementById("request-exchange-btn").addEventListener("click", function () {
            if (this.disabled) return;

            this.textContent = "Processing...";
            this.disabled = true;

            var orderId = "<?php echo $order_id; ?>";

            fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: "action=request_exchange&order_id=" + orderId
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert("Exchange successful! Redirecting...");
                        this.textContent = "Exchanged";
                        this.disabled = true;
                        window.location.href = data.redirect_url;
                    } else {
                        alert("Error: " + data.message);
                        this.textContent = "Request Exchange";
                        this.disabled = false;
                    }
                })
                .catch(error => {
                    console.error("Error:", error);
                    alert("An error occurred. Please try again.");
                    this.textContent = "Request Exchange";
                    this.disabled = false;
                });
        });
    </script>
    <?php
}

// Handle Refund Requests via AJAX
add_action('wp_ajax_request_refund', 'process_refund_request');
add_action('wp_ajax_nopriv_request_refund', 'process_refund_request');

function process_refund_request()
{
    if (!isset($_POST['order_id']) || !isset($_POST['refund_reason'])) {
        wp_send_json(['message' => 'Invalid request'], 400);
    }
    global $wpdb;
    $order_id = intval($_POST['order_id']);
    $refund_reason = sanitize_text_field($_POST['refund_reason']);
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json(['message' => 'Order not found'], 404);
    }
    update_post_meta($order_id, '_refund_requested', true);
    $customer_id = $order->get_customer_id();
    $items = $order->get_items();
    $total_restocking_fee = 0;
    $vendor_id = null;

    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        $restocking_fee = get_post_meta($product_id, '_restocking_fee', true);
        $total_restocking_fee += floatval($restocking_fee);
        $vendor_id = get_post_field('post_author', $product_id);
        if (!$vendor_id) {
            wp_send_json(['message' => 'Vendor ID not found for product ID: ' . $product_id], 400);
        }
    }

    // Insert refund request into the database
    $wpdb->insert(
        $wpdb->prefix . 'refund_requests',
        [
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'vendor_id' => $vendor_id,
            'refund_reason' => $refund_reason,
            'restocking_fee' => $total_restocking_fee,
            'status' => 'Pending',
        ],
        ['%d', '%d', '%d', '%s', '%f', '%s']
    );

    wp_send_json(['message' => 'Refund request submitted successfully']);
}
// Handle Exchange Requests via AJAX
function process_exchange_request() {
    if (!isset($_REQUEST['order_id'])) {
        wp_send_json(['success' => false, 'message' => 'Order ID is missing.'], 400);
    }

    $order_id = intval($_REQUEST['order_id']);

    // Load WooCommerce Order Object
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json(['success' => false, 'message' => 'Invalid order.'], 400);
    }

    // Fetch required details from the order
    $customer_id = $order->get_customer_id();
    $vendor_id = get_post_field('post_author', $order_id); // Assuming vendor is post author

    error_log("✅ Order ID: $order_id | Customer ID: $customer_id | Vendor ID: $vendor_id");

    // Your existing logic for processing exchange...

    wp_send_json(['success' => true, 'message' => 'Exchange request approved.']);
    // Prevent duplicate exchange requests
    if (get_post_meta($order_id, '_exchanged', true)) {
        wp_send_json(['success' => false, 'message' => 'Exchange has already been requested for this order.']);
    }
    
    $exchange_count = get_user_meta($customer_id, '_exchange_count', true);
    $exchange_count = $exchange_count ? intval($exchange_count) + 1 : 1;
    update_user_meta($customer_id, '_exchange_count', $exchange_count);

    // Mark order as exchanged
    update_post_meta($order_id, '_exchanged', true);

    // Get the order total
    $order_total = floatval($order->get_total());

    // TerraWallet: Add Order Total to Customer Wallet
    if (class_exists('Woo_Wallet_Wallet')) {
        $wallet = new Woo_Wallet_Wallet();
        $wallet->credit($customer_id, $order_total, __('Exchange refund added as Store Credit', 'your-text-domain'));
        error_log("✅ Store Credit Added to Customer ID {$customer_id}: {$order_total}");
    } else {
        error_log("❌ TerraWallet Plugin is Not Active!");
    }
    global $wpdb;
    $amount_to_deduct = $order_total;

    if ($vendor_id && $amount_to_deduct > 0) {
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dokan_vendor_balance WHERE trn_id = %d AND vendor_id = %d",
            $order_id,
            $vendor_id
        ));

        if ($transaction) {
            $wpdb->update(
                "{$wpdb->prefix}dokan_vendor_balance",
                ['credit' => abs($amount_to_deduct)], // Deduct balance from vendor
                ['trn_id' => $order_id, 'vendor_id' => $vendor_id],
                ['%f'],
                ['%d', '%d']
            );
            error_log("✅ Vendor ID {$vendor_id} balance updated. Deducted: {$amount_to_deduct} for Order ID: {$order_id}");
        } else {
            error_log("❌ No transaction found for Vendor ID: {$vendor_id} and Order ID: {$order_id}");
        }
    } else {
        error_log("❌ Vendor balance deduction failed. Vendor ID: {$vendor_id}, Order ID: {$order_id}");
    }

    // Mark Order as Refunded in WooCommerce
    $order->update_status('refunded', "Order exchanged and credited to customer wallet.");
    $order->save();

    // Generate redirect URL
    $redirect_url = wc_get_page_permalink('shop');

    wp_send_json([
        'success' => true,
        'message' => 'Exchange request approved. Amount added to your wallet.',
        'redirect_url' => $redirect_url
    ]);
}

add_action('wp_ajax_request_exchange', 'process_exchange_request');
add_action('wp_ajax_nopriv_request_exchange', 'process_exchange_request');



