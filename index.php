<?php
/**
 * Shopify Brand Inventory Monitor - Pure PHP Version with File Logging
 */

// === FILE LOGGING SETUP ===
$LOG_FILE = __DIR__ . '/webhook_debug.log';

function logToFile($message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($LOG_FILE, $logMessage, FILE_APPEND);
}

logToFile("=== SCRIPT STARTED ===");

// Load .env configuration
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
    logToFile(".env file loaded successfully");
}

$CONFIG = [
    'SHOPIFY_SHOP' => getenv('SHOPIFY_SHOP'),
    'SHOPIFY_ACCESS_TOKEN' => getenv('SHOPIFY_ACCESS_TOKEN'),
    'SHOPIFY_WEBHOOK_SECRET' => getenv('SHOPIFY_WEBHOOK_SECRET'),
    'EMAIL_FROM' => getenv('EMAIL_FROM'),
    'EMAIL_TO' => getenv('EMAIL_TO'),
    'MAILGUN_API_KEY' => getenv('MAILGUN_API_KEY'),
    'MAILGUN_DOMAIN' => getenv('MAILGUN_DOMAIN'),
    'SENDGRID_API_KEY' => getenv('SENDGRID_API_KEY'),
    'BRANDS_TO_MONITOR' => array_map('trim', explode(',', getenv('BRANDS_TO_MONITOR')))
];

// Remove https:// from SHOPIFY_SHOP if present (we'll add it when needed)
$CONFIG['SHOPIFY_SHOP'] = str_replace(['https://', 'http://'], '', $CONFIG['SHOPIFY_SHOP']);

logToFile("Configuration loaded - Shop: {$CONFIG['SHOPIFY_SHOP']}, Brands: " . count($CONFIG['BRANDS_TO_MONITOR']));

// Validate required configuration
$requiredVars = ['SHOPIFY_SHOP', 'SHOPIFY_ACCESS_TOKEN', 'SHOPIFY_WEBHOOK_SECRET', 
                 'EMAIL_FROM', 'EMAIL_TO', 'BRANDS_TO_MONITOR'];

foreach ($requiredVars as $var) {
    if (empty($CONFIG[$var])) {
        logToFile("ERROR: Missing required variable: $var");
        error_log("Missing required environment variable: $var");
        http_response_code(500);
        die(json_encode(['error' => "Missing configuration: $var"]));
    }
}

// Determine email method
$emailMethod = 'none';
if (!empty($CONFIG['MAILGUN_API_KEY']) && !empty($CONFIG['MAILGUN_DOMAIN'])) {
    $emailMethod = 'mailgun';
    logToFile("Email method: Mailgun");
} elseif (!empty($CONFIG['SENDGRID_API_KEY'])) {
    $emailMethod = 'sendgrid';
    logToFile("Email method: SendGrid");
} else {
    logToFile("WARNING: No email service configured");
}

$STATE_FILE = __DIR__ . '/notification_state.json';

function loadState() {
    global $STATE_FILE;
    if (file_exists($STATE_FILE)) {
        $state = json_decode(file_get_contents($STATE_FILE), true) ?: [];
        logToFile("State loaded: " . json_encode($state));
        return $state;
    }
    logToFile("No state file found, starting fresh");
    return [];
}

function saveState($state) {
    global $STATE_FILE;
    file_put_contents($STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
    logToFile("State saved: " . json_encode($state));
}

function verifyWebhook($data, $hmacHeader) {
    global $CONFIG;
    
    if (empty($hmacHeader)) {
        logToFile("HMAC verification FAILED: No HMAC header");
        return false;
    }
    
    $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $CONFIG['SHOPIFY_WEBHOOK_SECRET'], true));
    $match = hash_equals($calculatedHmac, $hmacHeader);
    
    logToFile("HMAC verification - Received: " . substr($hmacHeader, 0, 20) . "... Calculated: " . substr($calculatedHmac, 0, 20) . "... Match: " . ($match ? 'YES' : 'NO'));
    
    return $match;
}

function makeRequest($url, $method = 'GET', $headers = [], $data = null) {
    logToFile("Making $method request to: $url");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    if ($error) {
        logToFile("cURL ERROR: $error");
    }
    
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    logToFile("Response code: $httpCode");
    
    return [
        'code' => $httpCode,
        'headers' => $header,
        'body' => $body,
        'error' => $error
    ];
}

function getProductsForBrand($vendor) {
    global $CONFIG;
    
    logToFile("Fetching products for brand: $vendor");
    
    $allProducts = [];
    $url = "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2025-10/products.json?vendor=" . urlencode($vendor) . "&limit=250";
    
    $pageCount = 0;
    while ($url && $pageCount < 10) { // Limit to 10 pages max
        $pageCount++;
        logToFile("Fetching page $pageCount for $vendor");
        
        $response = makeRequest($url, 'GET', [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ]);
        
        if ($response['code'] !== 200) {
            logToFile("ERROR: Shopify API returned {$response['code']} for $vendor");
            logToFile("Response body: " . substr($response['body'], 0, 500));
            break;
        }
        
        $data = json_decode($response['body'], true);
        
        if (!$data || !isset($data['products'])) {
            logToFile("ERROR: Invalid JSON response for $vendor");
            break;
        }
        
        $productCount = count($data['products']);
        logToFile("Page $pageCount: Found $productCount products");
        
        $allProducts = array_merge($allProducts, $data['products']);
        
        // Check for next page
        $url = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $response['headers'], $matches)) {
            $url = $matches[1];
            logToFile("Next page URL found");
        }
    }
    
    logToFile("Total products fetched for $vendor: " . count($allProducts));
    return $allProducts;
}

function checkBrandStock($vendor) {
    logToFile("=== Checking stock for: $vendor ===");
    
    $products = getProductsForBrand($vendor);
    
    if (empty($products)) {
        logToFile("No products found for $vendor");
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
        
        if (!isset($product['variants']) || !is_array($product['variants'])) {
            logToFile("WARNING: Product {$product['title']} has no variants");
            continue;
        }
        
        foreach ($product['variants'] as $variant) {
            $qty = $variant['inventory_quantity'] ?? 0;
            $productTotalStock += $qty;
        }
        
        if ($productTotalStock <= 0) {
            $oosProducts++;
        }
    }
    
    $inStock = $totalProducts - $oosProducts;
    $allOOS = ($totalProducts > 0 && $totalProducts === $oosProducts);
    
    $result = [
        'allOOS' => $allOOS,
        'totalProducts' => $totalProducts,
        'oosProducts' => $oosProducts,
        'inStockProducts' => $inStock
    ];
    
    logToFile("Stock result for $vendor: " . json_encode($result));
    return $result;
}

function sendEmail($subject, $message) {
    global $CONFIG, $emailMethod;
    
    logToFile("=== Attempting to send email ===");
    logToFile("Subject: $subject");
    logToFile("Method: $emailMethod");
    
    if ($emailMethod === 'mailgun') {
        return sendEmailViaMailgun($subject, $message);
    } elseif ($emailMethod === 'sendgrid') {
        return sendEmailViaSendGrid($subject, $message);
    } else {
        logToFile("ERROR: No email service configured");
        return ['success' => false, 'error' => 'No email service configured'];
    }
}

function sendEmailViaMailgun($subject, $message) {
    global $CONFIG;
    
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
    </div>
</body>
</html>';
    
    $url = "https://api.mailgun.net/v3/{$CONFIG['MAILGUN_DOMAIN']}/messages";
    
    $postData = [
        'from' => "Shopify Monitor <{$CONFIG['EMAIL_FROM']}>",
        'to' => $CONFIG['EMAIL_TO'],
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
        'o:tracking' => 'no'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_USERPWD, "api:{$CONFIG['MAILGUN_API_KEY']}");
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logToFile("Mailgun response code: $httpCode");
    logToFile("Mailgun response: " . substr($response, 0, 200));
    
    if ($httpCode === 200) {
        logToFile("✅ Email sent successfully via Mailgun");
        return ['success' => true, 'method' => 'mailgun'];
    } else {
        logToFile("❌ Mailgun error: $response");
        return ['success' => false, 'error' => $response];
    }
}

function sendEmailViaSendGrid($subject, $message) {
    global $CONFIG;
    
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
        ]
    ];
    
    $response = makeRequest('https://api.sendgrid.com/v3/mail/send', 'POST', [
        "Authorization: Bearer {$CONFIG['SENDGRID_API_KEY']}",
        "Content-Type: application/json"
    ], json_encode($data));
    
    if ($response['code'] === 202) {
        logToFile("✅ Email sent successfully via SendGrid");
        return ['success' => true, 'method' => 'sendgrid'];
    } else {
        logToFile("❌ SendGrid error: {$response['body']}");
        return ['success' => false, 'error' => $response['body']];
    }
}

function getVendorFromInventoryItem($inventoryItemId) {
    global $CONFIG;
    
    logToFile("Looking up vendor for inventory item: $inventoryItemId");
    
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
        "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2025-10/graphql.json",
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
        logToFile("ERROR: GraphQL request failed with code {$response['code']}");
        return null;
    }
    
    $data = json_decode($response['body'], true);
    
    if (isset($data['errors'])) {
        logToFile("ERROR: GraphQL errors: " . json_encode($data['errors']));
        return null;
    }
    
    $vendor = $data['data']['inventoryItem']['variant']['product']['vendor'] ?? null;
    $title = $data['data']['inventoryItem']['variant']['product']['title'] ?? null;
    
    logToFile("Found vendor: $vendor, product: $title");
    
    return [
        'vendor' => $vendor,
        'title' => $title
    ];
}

// === ROUTING ===

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);

logToFile("Request: $requestMethod $path");

// Health check
if ($path === '/health' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'monitoring' => count($CONFIG['BRANDS_TO_MONITOR']) . ' brands',
        'emailMethod' => $emailMethod,
        'timestamp' => date('c')
    ]);
    exit;
}

// Test email
if ($path === '/test-email' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    $result = sendEmail(
        '🧪 Test Email from Shopify Monitor',
        "This is a test email.\n\n" .
        "Method: $emailMethod\n" .
        "From: {$CONFIG['EMAIL_FROM']}\n" .
        "To: {$CONFIG['EMAIL_TO']}\n" .
        "Timestamp: " . date('c')
    );
    
    echo json_encode($result);
    exit;
}

// Manual check
if ($path === '/check-now' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    logToFile("=== MANUAL CHECK TRIGGERED ===");
    
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
        logToFile("Found " . count($oosBrands) . " OOS brands");
        
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
        $emailMessage .= "Brands Out of Stock: " . count($oosBrands) . "\n\n";
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
                'emailSent' => $emailResult['success'],
                'oosBrands' => array_column($oosBrands, 'brand')
            ]
        ]);
    } else {
        logToFile("All brands in stock");
        echo json_encode([
            'results' => $results,
            'summary' => [
                'totalBrands' => count($CONFIG['BRANDS_TO_MONITOR']),
                'brandsOutOfStock' => 0,
                'emailSent' => false,
                'message' => 'All brands have inventory in stock'
            ]
        ]);
    }
    exit;
}

// Webhook handler
if ($path === '/webhook/inventory' && $requestMethod === 'POST') {
    logToFile("=== WEBHOOK RECEIVED ===");
    
    $rawBody = file_get_contents('php://input');
    $hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    
    logToFile("Raw body length: " . strlen($rawBody));
    logToFile("HMAC header: " . substr($hmac, 0, 20) . "...");
    
    if (!verifyWebhook($rawBody, $hmac)) {
        logToFile("❌ WEBHOOK VERIFICATION FAILED");
        http_response_code(401);
        echo "Unauthorized";
        exit;
    }
    
    logToFile("✅ Webhook verified");
    
    $data = json_decode($rawBody, true);
    
    // Respond to Shopify immediately
    http_response_code(200);
    echo "OK";
    
    if (empty($data)) {
        logToFile("Empty webhook body - test webhook");
        exit;
    }
    
    logToFile("Webhook data: " . json_encode($data));
    
    // Wait for Shopify to propagate
    logToFile("Waiting 3 seconds for Shopify to propagate...");
    sleep(3);
    
    $inventoryItemId = $data['inventory_item_id'] ?? null;
    
    if (!$inventoryItemId) {
        logToFile("ERROR: No inventory_item_id in webhook");
        exit;
    }
    
    $productInfo = getVendorFromInventoryItem($inventoryItemId);
    
    if (!$productInfo || !$productInfo['vendor']) {
        logToFile("ERROR: Could not find vendor");
        exit;
    }
    
    $vendor = $productInfo['vendor'];
    logToFile("Brand: $vendor, Product: {$productInfo['title']}");
    
    if (!in_array($vendor, $CONFIG['BRANDS_TO_MONITOR'])) {
        logToFile("Brand $vendor not monitored - skipping");
        exit;
    }
    
    $stockStatus = checkBrandStock($vendor);
    $state = loadState();
    $lastState = $state[$vendor] ?? null;
    
    logToFile("Last state: " . ($lastState ?? 'NULL') . ", Current: " . ($stockStatus['allOOS'] ? 'OOS' : 'IN_STOCK'));
    
    // All OOS
    if ($stockStatus['allOOS'] && $lastState !== 'OOS') {
        logToFile("🚨 $vendor - ALL OUT OF STOCK - SENDING EMAIL");
        
        $emailResult = sendEmail(
            "🚨 ALL $vendor Products OUT OF STOCK",
            "All {$stockStatus['totalProducts']} products for \"$vendor\" are now out of stock.\n\n" .
            "⚠️ ACTION REQUIRED: Hide this brand from your brand page.\n\n" .
            "Brand: $vendor\n" .
            "Total Products: {$stockStatus['totalProducts']}\n" .
            "Timestamp: " . date('c')
        );
        
        logToFile("Email result: " . json_encode($emailResult));
        
        $state[$vendor] = 'OOS';
        saveState($state);
    }
    // Back in stock
    elseif (!$stockStatus['allOOS'] && $stockStatus['inStockProducts'] > 0 && $lastState === 'OOS') {
        logToFile("✅ $vendor - BACK IN STOCK - SENDING EMAIL");
        
        $emailResult = sendEmail(
            "✅ $vendor Products BACK IN STOCK",
            "Good news! {$stockStatus['inStockProducts']} product(s) for \"$vendor\" are back in stock.\n\n" .
            "✅ ACTION REQUIRED: Show this brand on your brand page.\n\n" .
            "Brand: $vendor\n" .
            "In Stock: {$stockStatus['inStockProducts']}\n" .
            "Timestamp: " . date('c')
        );
        
        logToFile("Email result: " . json_encode($emailResult));
        
        $state[$vendor] = 'IN_STOCK';
        saveState($state);
    } else {
        logToFile("No state change - skipping email");
    }
    
    logToFile("=== WEBHOOK COMPLETE ===\n");
    exit;
}

// Register webhook
if ($path === '/admin/register-webhook' && $requestMethod === 'POST') {
    header('Content-Type: application/json');
    
    $webhookUrl = "https://{$_SERVER['HTTP_HOST']}/webhook/inventory";
    logToFile("Registering webhook: $webhookUrl");
    
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
        logToFile("Webhook registered successfully");
        echo json_encode(['success' => true, 'webhook' => json_decode($response['body'], true)]);
    } else {
        logToFile("Webhook registration failed: {$response['body']}");
        echo json_encode(['success' => false, 'error' => $response['body']]);
    }
    exit;
}

// List webhooks
if ($path === '/admin/webhooks' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    $response = makeRequest(
        "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2025-10/webhooks.json",
        'GET',
        [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ]
    );
    
    echo $response['body'];
    exit;
}

// View logs endpoint
if ($path === '/view-logs' && $requestMethod === 'GET') {
    header('Content-Type: text/plain');
    
    if (file_exists($LOG_FILE)) {
        // Show last 200 lines
        $lines = file($LOG_FILE);
        $lastLines = array_slice($lines, -200);
        echo implode('', $lastLines);
    } else {
        echo "No log file found.";
    }
    exit;
}

// Root
if ($path === '/' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'service' => 'Shopify Brand Inventory Monitor',
        'status' => 'running',
        'monitoring' => count($CONFIG['BRANDS_TO_MONITOR']) . ' brands',
        'emailMethod' => $emailMethod,
        'endpoints' => [
            'health' => '/health',
            'webhook' => '/webhook/inventory (POST)',
            'manualCheck' => '/check-now',
            'testEmail' => '/test-email',
            'listWebhooks' => '/admin/webhooks',
            'registerWebhook' => '/admin/register-webhook (POST)',
            'viewLogs' => '/view-logs'
        ]
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not Found']);
