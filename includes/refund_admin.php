<?php
if (!defined('ABSPATH')) {
    exit;
}
add_action('admin_menu', 'add_refund_requests_page');
function add_refund_requests_page()
{
    add_menu_page(
        'Refund Requests',
        'Refund Requests',
        'manage_options',
        'refund_requests',
        'display_pending_refunds',
        'dashicons-undo',
        25
    );
}
function display_pending_refunds()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'refund_requests';
    $refund_requests = $wpdb->get_results("SELECT * FROM $table_name WHERE status = 'Pending'");

    echo '<div class="wrap"><h1>Pending Refund Requests</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Order ID</th><th>Customer</th><th>Total Price</th><th>Restocking Fee</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    foreach ($refund_requests as $request) {
        $order = wc_get_order($request->order_id);
        if (!$order) {
            continue;
        }

        $customer_name = get_userdata($request->customer_id)->display_name ?? 'Guest';
        $total_price = $order->get_total();
        $restocking_fee = $request->restocking_fee;

        echo '<tr>';
        echo "<td>{$request->order_id}</td>";
        echo "<td>{$customer_name}</td>";
        echo "<td>\${$total_price}</td>";
        echo "<td>\${$restocking_fee}</td>";
        echo '<td><button class="refund-btn button button-primary" data-id="' . $request->id . '" data-order="' . $request->order_id . '" data-customer="' . $request->customer_id . '" data-vendor="' . $request->vendor_id . '" data-total="' . $total_price . '" data-restocking="' . $restocking_fee . '">Process Refund</button></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    ?>
    <script>
        document.querySelectorAll(".refund-btn").forEach(button => {
            button.addEventListener("click", function () {
                let refundId = this.getAttribute("data-id");
                let orderId = this.getAttribute("data-order");
                let customerId = this.getAttribute("data-customer");
                let vendorId = this.getAttribute("data-vendor");
                let totalPrice = parseFloat(this.getAttribute("data-total"));
                let restockingFee = parseFloat(this.getAttribute("data-restocking"));

                if (!confirm("Are you sure you want to refund this order?")) return;

                fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: `action=process_refund&refund_id=${refundId}&order_id=${orderId}&customer_id=${customerId}&vendor_id=${vendorId}&total_price=${totalPrice}&restocking_fee=${restockingFee}`
                })
                    .then(response => response.json())
                    .then(data => alert(data.message))
                    .catch(error => console.error("Error:", error));
            });
        });
    </script>
    <?php
}
add_action('wp_ajax_process_refund', 'handle_process_refund');

function handle_process_refund()
{
    global $wpdb;

    if (!isset($_POST['refund_id'], $_POST['order_id'], $_POST['customer_id'], $_POST['vendor_id'], $_POST['total_price'], $_POST['restocking_fee'])) {
        wp_send_json(['message' => 'Invalid request'], 400);
    }

    $refund_id = intval($_POST['refund_id']);
    $order_id = intval($_POST['order_id']);
    $customer_id = intval($_POST['customer_id']);
    $vendor_id = intval($_POST['vendor_id']);
    $total_price = floatval($_POST['total_price']);
    $restocking_fee = floatval($_POST['restocking_fee']);

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json(['message' => 'Order not found'], 404);
    }
    $refund_amount = $total_price - $restocking_fee; 
    if (class_exists('Woo_Wallet_Wallet')) {
        $wallet = new Woo_Wallet_Wallet();
        $wallet->credit($customer_id, $refund_amount, __('Refund added as Store Credit', 'your-text-domain'));
        error_log("✅ Store Credit Added to Customer ID {$customer_id}: {$refund_amount}");
    } else {
        error_log("❌ Tera Wallet Plugin is Not Active!");
    }
    $vendor_share = round($restocking_fee / 2, 2); 
    $amount_to_deduct = $refund_amount + $vendor_share; 
    global $wpdb;
    if ($vendor_id && $amount_to_deduct > 0 && $order_id) {
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dokan_vendor_balance WHERE trn_id = %d AND vendor_id = %d",
            $order_id,
            $vendor_id
        ));

        if ($transaction) {
            $wpdb->update(
                "{$wpdb->prefix}dokan_vendor_balance",
                ['credit' => abs($amount_to_deduct)], 
                ['trn_id' => $order_id, 'vendor_id' => $vendor_id], 
                ['%f'], 
                ['%d', '%d']
            );
            error_log("✅ Vendor ID {$vendor_id} balance updated. Credit set to: {$amount_to_deduct} for Order ID: {$order_id}");
        } else {
            error_log("❌ No transaction found for Vendor ID: {$vendor_id} and Order ID: {$order_id}");
        }
    } else {
        error_log("❌ Vendor balance deduction failed. Vendor ID: {$vendor_id}, Order ID: {$order_id}");
    }
    $order->update_status('refunded', "Refund processed via Refund Requests Plugin");
    $order->save(); 
    $table_name = $wpdb->prefix . 'refund_requests';
    $wpdb->update(
        $table_name,
        ['status' => 'Refunded'],
        ['id' => $refund_id],
        ['%s'],
        ['%d']
    );
    error_log("✅ Updated Refund Request Status for Refund ID {$refund_id}");

    wp_send_json_success(['message' => 'Refund processed successfully']);
}


