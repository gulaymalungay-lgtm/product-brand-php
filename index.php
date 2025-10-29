<?php

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}

$CONFIG = [
    'SHOPIFY_SHOP' => getenv('SHOPIFY_SHOP'),
    'SHOPIFY_ACCESS_TOKEN' => getenv('SHOPIFY_ACCESS_TOKEN'),
    'SHOPIFY_WEBHOOK_SECRET' => getenv('SHOPIFY_WEBHOOK_SECRET'),
    'EMAIL_FROM' => getenv('EMAIL_FROM'),
    'EMAIL_TO' => getenv('EMAIL_TO'),
    'SENDGRID_API_KEY' => getenv('SENDGRID_API_KEY'),
    'BRANDS_TO_MONITOR' => array_map('trim', explode(',', getenv('BRANDS_TO_MONITOR')))
];

$requiredVars = ['SHOPIFY_SHOP', 'SHOPIFY_ACCESS_TOKEN', 'SHOPIFY_WEBHOOK_SECRET', 
                 'EMAIL_FROM', 'EMAIL_TO', 'BRANDS_TO_MONITOR'];

foreach ($requiredVars as $var) {
    if (empty($CONFIG[$var])) {
        error_log("Missing required environment variable: $var");
        http_response_code(500);
        die(json_encode(['error' => "Missing configuration: $var"]));
    }
}

$STATE_FILE = __DIR__ . '/notification_state.json';

function loadState() {
    global $STATE_FILE;
    if (file_exists($STATE_FILE)) {
        return json_decode(file_get_contents($STATE_FILE), true) ?: [];
    }
    return [];
}

function saveState($state) {
    global $STATE_FILE;
    file_put_contents($STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function verifyWebhook($data, $hmacHeader) {
    global $CONFIG;
    $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $CONFIG['SHOPIFY_WEBHOOK_SECRET'], true));
    return hash_equals($calculatedHmac, $hmacHeader);
}

function makeRequest($url, $method = 'GET', $headers = [], $data = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'headers' => $header,
        'body' => $body
    ];
}

function getProductsForBrand($vendor) {
    global $CONFIG;
    
    $allProducts = [];
    $url = "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2024-10/products.json?vendor=" . urlencode($vendor) . "&limit=250";
    
    while ($url) {
        $response = makeRequest($url, 'GET', [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ]);
        
        if ($response['code'] !== 200) {
            error_log("Shopify API error: " . $response['code']);
            break;
        }
        
        $data = json_decode($response['body'], true);
        $allProducts = array_merge($allProducts, $data['products'] ?? []);
        
        $url = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $response['headers'], $matches)) {
            $url = $matches[1];
        }
    }
    
    return $allProducts;
}

function checkBrandStock($vendor) {
    $products = getProductsForBrand($vendor);
    
    if (empty($products)) {
        return [
            'allOOS' => false,
            'totalProducts' => 0,
            'oosProducts' => 0,
            'inStockProducts' => 0
        ];
    }
    
    $totalProducts = count($products);
    $oosProducts = 0;
    
    foreach ($products as $product) {
        $productTotalStock = 0;
        
        foreach ($product['variants'] as $variant) {
            $productTotalStock += $variant['inventory_quantity'] ?? 0;
        }
        
        if ($productTotalStock <= 0) {
            $oosProducts++;
        }
    }
    
    return [
        'allOOS' => $totalProducts === $oosProducts,
        'totalProducts' => $totalProducts,
        'oosProducts' => $oosProducts,
        'inStockProducts' => $totalProducts - $oosProducts
    ];
}

// Send email via SendGrid
function sendEmail($subject, $message) {
    global $CONFIG;
    
    if (empty($CONFIG['SENDGRID_API_KEY'])) {
        error_log("No SendGrid API key configured");
        return ['success' => false, 'error' => 'No email service configured'];
    }
    
    $htmlMessage = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h2 style="margin: 0; color: #212529;">' . htmlspecialchars(preg_replace('/[🚨✅]/u', '', $subject)) . '</h2>
    </div>
    <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #dee2e6;">
        <pre style="font-family: \'Courier New\', monospace; white-space: pre-wrap; margin: 0; font-size: 14px;">' . htmlspecialchars($message) . '</pre>
    </div>
    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d;">
        <p>This is an automated notification from your Shopify Brand Inventory Monitor.</p>
        <p>Please do not reply to this email.</p>
    </div>
</body>
</html>';
    
    $data = [
        'personalizations' => [[
            'to' => [['email' => $CONFIG['EMAIL_TO']]]
        ]],
        'from' => [
            'email' => $CONFIG['EMAIL_FROM'],
            'name' => 'Shopify Inventory Monitor'
        ],
        'subject' => $subject,
        'content' => [[
            'type' => 'text/html',
            'value' => $htmlMessage
        ]],
        'tracking_settings' => [
            'click_tracking' => ['enable' => false],
            'open_tracking' => ['enable' => false]
        ],
        'categories' => ['inventory-alert']
    ];
    
    $response = makeRequest('https://api.sendgrid.com/v3/mail/send', 'POST', [
        "Authorization: Bearer {$CONFIG['SENDGRID_API_KEY']}",
        "Content-Type: application/json"
    ], json_encode($data));
    
    if ($response['code'] === 202) {
        error_log("Email sent: $subject");
        return ['success' => true, 'method' => 'sendgrid'];
    } else {
        error_log("SendGrid error: " . $response['body']);
        return ['success' => false, 'error' => $response['body']];
    }
}

function getVendorFromInventoryItem($inventoryItemId) {
    global $CONFIG;
    
    $query = '
        query getInventoryItem($id: ID!) {
            inventoryItem(id: $id) {
                variant {
                    product {
                        vendor
                        title
                    }
                }
            }
        }
    ';
    
    $response = makeRequest(
        "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2024-10/graphql.json",
        'POST',
        [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ],
        json_encode([
            'query' => $query,
            'variables' => [
                'id' => "gid://shopify/InventoryItem/$inventoryItemId"
            ]
        ])
    );
    
    if ($response['code'] !== 200) {
        return null;
    }
    
    $data = json_decode($response['body'], true);
    
    if (isset($data['errors'])) {
        error_log("GraphQL errors: " . json_encode($data['errors']));
        return null;
    }
    
    return [
        'vendor' => $data['data']['inventoryItem']['variant']['product']['vendor'] ?? null,
        'title' => $data['data']['inventoryItem']['variant']['product']['title'] ?? null
    ];
}

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);

if ($path === '/health' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'monitoring' => $CONFIG['BRANDS_TO_MONITOR'],
        'timestamp' => date('c')
    ]);
    exit;
}

if ($path === '/test-email' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    $result = sendEmail(
        '🧪 Test Email from Shopify Monitor',
        "This is a test email to verify your email configuration is working.\n\n" .
        "Method: sendgrid\n" .
        "From: {$CONFIG['EMAIL_FROM']}\n" .
        "To: {$CONFIG['EMAIL_TO']}\n" .
        "Timestamp: " . date('c') . "\n\n" .
        "If you're seeing this, your email setup is working correctly! "
    );
    
    echo json_encode($result);
    exit;
}

if ($path === '/check-now' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    $results = [];
    $oosBrands = [];
    
    foreach ($CONFIG['BRANDS_TO_MONITOR'] as $brand) {
        $stockStatus = checkBrandStock($brand);
        $results[$brand] = $stockStatus;
        
        if ($stockStatus['allOOS'] && $stockStatus['totalProducts'] > 0) {
            $oosBrands[] = [
                'brand' => $brand,
                'totalProducts' => $stockStatus['totalProducts']
            ];
        }
    }
    
    if (!empty($oosBrands)) {
        $emailMessage = "Manual inventory check completed. Found " . count($oosBrands) . " brand(s) completely out of stock:\n\n";
        $emailMessage .= "⚠️ BRANDS OUT OF STOCK:\n";
        $emailMessage .= str_repeat('=', 50) . "\n\n";
        
        foreach ($oosBrands as $index => $item) {
            $emailMessage .= ($index + 1) . ". {$item['brand']}\n";
            $emailMessage .= "   Total Products: {$item['totalProducts']}\n";
            $emailMessage .= "   Status: ALL OUT OF STOCK\n\n";
        }
        
        $emailMessage .= str_repeat('=', 50) . "\n";
        $emailMessage .= "Total Brands Monitored: " . count($CONFIG['BRANDS_TO_MONITOR']) . "\n";
        $emailMessage .= "Brands Out of Stock: " . count($oosBrands) . "\n";
        $emailMessage .= "Brands In Stock: " . (count($CONFIG['BRANDS_TO_MONITOR']) - count($oosBrands)) . "\n\n";
        $emailMessage .= "⚠️ ACTION REQUIRED: Hide these brands from your brand page.\n\n";
        $emailMessage .= "Timestamp: " . date('c');
        
        $emailResult = sendEmail(
            "🚨 Manual Check: " . count($oosBrands) . " Brand(s) Out of Stock",
            $emailMessage
        );
        
        echo json_encode([
            'results' => $results,
            'summary' => [
                'totalBrands' => count($CONFIG['BRANDS_TO_MONITOR']),
                'brandsOutOfStock' => count($oosBrands),
                'brandsInStock' => count($CONFIG['BRANDS_TO_MONITOR']) - count($oosBrands),
                'emailSent' => $emailResult['success'],
                'oosBrands' => array_column($oosBrands, 'brand')
            ]
        ]);
    } else {
        echo json_encode([
            'results' => $results,
            'summary' => [
                'totalBrands' => count($CONFIG['BRANDS_TO_MONITOR']),
                'brandsOutOfStock' => 0,
                'brandsInStock' => count($CONFIG['BRANDS_TO_MONITOR']),
                'emailSent' => false,
                'message' => 'All brands have inventory in stock'
            ]
        ]);
    }
    exit;
}

if ($path === '/webhook/inventory' && $requestMethod === 'POST') {
    $rawBody = file_get_contents('php://input');
    $hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    
    if (!verifyWebhook($rawBody, $hmac)) {
        http_response_code(401);
        echo "Unauthorized";
        error_log("Invalid webhook signature");
        exit;
    }
    
    $data = json_decode($rawBody, true);
    
    http_response_code(200);
    echo "OK";
    
    if (empty($data)) {
        error_log("Empty webhook body (test webhook)");
        exit;
    }
    
    error_log("Webhook body: " . json_encode($data));
    
    sleep(3);
    
    $inventoryItemId = $data['inventory_item_id'] ?? null;
    
    if (!$inventoryItemId) {
        error_log("No inventory_item_id in webhook");
        exit;
    }
    
    $productInfo = getVendorFromInventoryItem($inventoryItemId);
    
    if (!$productInfo || !$productInfo['vendor']) {
        error_log("Could not find vendor for inventory item: $inventoryItemId");
        exit;
    }
    
    $vendor = $productInfo['vendor'];
    
    error_log("Product: {$productInfo['title']}");
    error_log("Brand: $vendor");
    
    if (!in_array($vendor, $CONFIG['BRANDS_TO_MONITOR'])) {
        error_log("Brand $vendor is not monitored");
        exit;
    }
    
    $stockStatus = checkBrandStock($vendor);
    $state = loadState();
    $lastState = $state[$vendor] ?? null;
    
    error_log("$vendor: {$stockStatus['inStockProducts']}/{$stockStatus['totalProducts']} in stock");
    
    if ($stockStatus['allOOS'] && $lastState !== 'OOS') {
        error_log("$vendor - ALL OUT OF STOCK");
        
        sendEmail(
            "ALL $vendor Products OUT OF STOCK",
            "All {$stockStatus['totalProducts']} products for \"$vendor\" are now out of stock.\n\n" .
            "⚠️ ACTION REQUIRED: Hide this brand from your brand page.\n\n" .
            "Brand: $vendor\n" .
            "Total Products: {$stockStatus['totalProducts']}\n" .
            "Out of Stock: {$stockStatus['oosProducts']}\n\n" .
            "Timestamp: " . date('c')
        );
        
        $state[$vendor] = 'OOS';
        saveState($state);
    }
    // Back in stock
    elseif (!$stockStatus['allOOS'] && $stockStatus['inStockProducts'] > 0 && $lastState === 'OOS') {
        error_log("$vendor - BACK IN STOCK");
        
        sendEmail(
            "$vendor Products BACK IN STOCK",
            "Good news! {$stockStatus['inStockProducts']} product(s) for \"$vendor\" are back in stock.\n\n" .
            "ACTION REQUIRED: Show this brand on your brand page.\n\n" .
            "Brand: $vendor\n" .
            "Total Products: {$stockStatus['totalProducts']}\n" .
            "In Stock: {$stockStatus['inStockProducts']}\n" .
            "Out of Stock: {$stockStatus['oosProducts']}\n\n" .
            "Timestamp: " . date('c')
        );
        
        $state[$vendor] = 'IN_STOCK';
        saveState($state);
    }
    
    exit;
}

if ($path === '/admin/register-webhook' && $requestMethod === 'POST') {
    header('Content-Type: application/json');
    
    $webhookUrl = "https://{$_SERVER['HTTP_HOST']}/webhook/inventory";
    
    $response = makeRequest(
        "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2025-10/webhooks.json",
        'POST',
        [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ],
        json_encode([
            'webhook' => [
                'topic' => 'inventory_levels/update',
                'address' => $webhookUrl,
                'format' => 'json'
            ]
        ])
    );
    
    if ($response['code'] === 201) {
        echo json_encode(['success' => true, 'webhook' => json_decode($response['body'], true)]);
    } else {
        echo json_encode(['success' => false, 'error' => $response['body']]);
    }
    exit;
}

if ($path === '/admin/webhooks' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    $response = makeRequest(
        "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2024-10/webhooks.json",
        'GET',
        [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ]
    );
    
    echo $response['body'];
    exit;
}

if ($path === '/' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'service' => 'Shopify Brand Inventory Monitor',
        'status' => 'running',
        'monitoring' => count($CONFIG['BRANDS_TO_MONITOR']) . ' brands',
        'endpoints' => [
            'health' => '/health',
            'webhook' => '/webhook/inventory (POST)',
            'manualCheck' => '/check-now',
            'testEmail' => '/test-email',
            'listWebhooks' => '/admin/webhooks',
            'registerWebhook' => '/admin/register-webhook (POST)'
        ]
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found']);
