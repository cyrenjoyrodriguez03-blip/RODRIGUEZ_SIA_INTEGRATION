<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

// TODO: Implement the system integration here.

// TODO: Display the appropriate checkout result.

/* Send the order to the independent payment gateway. */


$gatewayUrl = 'http://localhost/payment-gateway/api/payments.php';

$idempotencyKey = 'cart-order-' . $orderId;

$payload = [
    'order_id' => $orderId,
    'amount' => round($total, 2),
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => $idempotencyKey,
    'outcome' => 'PENDING'
];

$ch = curl_init($gatewayUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);

$responseBody = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

/* Handle connection errors and timeouts safely. */
if ($responseBody === false) {
    finish(
        'Payment Pending',
        'The gateway did not return a response. Check the order before retrying. Order ID: ' . $orderId
    );
}

$response = json_decode($responseBody, true);

/* Validate the gateway response. */
if (
    !is_array($response) ||
    !isset(
        $response['success'],
        $response['transaction_id'],
        $response['order_id'],
        $response['amount'],
        $response['status']
    ) ||
    (int) $response['order_id'] !== $orderId ||
    !is_numeric($response['amount']) ||
    abs((float) $response['amount'] - $total) > 0.001
) {
    finish(
        'Payment Pending',
        'The gateway response is invalid or does not match this order. Order ID: ' . $orderId
    );
}

$status = strtoupper((string) $response['status']);
$transactionId = trim((string) $response['transaction_id']);


if (
    $httpCode < 200 ||
    $httpCode >= 300 ||
    $transactionId === '' ||
    !in_array($status, ['SUCCESS', 'FAILED', 'PENDING'], true)
) {
    finish(
        'Payment Pending',
        'The payment result could not be confirmed. Order ID: ' . $orderId
    );
}

/* Save the payment result in the shopping cart database. */
$orderStatus = match ($status) {
    'SUCCESS' => 'PAID',
    'FAILED' => 'PAYMENT_FAILED',
    default => 'PENDING_PAYMENT'
};

try {
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET status = ?, gateway_transaction_id = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $orderStatus,
        $transactionId,
        $orderId
    ]);

    if ($stmt->rowCount() !== 1) {
        finish(
            'Payment Pending',
            'Could not confirm the saved order status. Order ID: ' . $orderId
        );
    }
} catch (Throwable $e) {
    finish(
        'Payment Pending',
        'The payment response was received, but the order status could not be saved. Order ID: ' . $orderId
    );
}

/* Display the result. */
if ($status === 'SUCCESS') {
    finish(
        'Payment Successful',
        'Order ID: ' . $orderId . ' | Transaction ID: ' . $transactionId
    );
}

if ($status === 'FAILED') {
    finish(
        'Payment Failed',
        'Order ID: ' . $orderId . ' | Transaction ID: ' . $transactionId
    );
}

finish(
    'Payment Pending',
    'Order ID: ' . $orderId . ' | Transaction ID: ' . $transactionId
);

