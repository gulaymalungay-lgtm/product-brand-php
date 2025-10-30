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

$BRANDS_FILE = __DIR__ . '/brands.json';

function loadBrands() {
    global $BRANDS_FILE;
    if (file_exists($BRANDS_FILE)) {
        $data = json_decode(file_get_contents($BRANDS_FILE), true);
        return $data['brands'] ?? getDefaultBrands();
    }
    return getDefaultBrands();
}

function saveBrands($brands) {
    global $BRANDS_FILE;
    file_put_contents($BRANDS_FILE, json_encode(['brands' => $brands], JSON_PRETTY_PRINT));
}

function getDefaultBrands() {
    return [
        '24 Bottles',
        '360 Degrees Water Bottles',
        'ALIFEDESIGN',
        'Alpaka',
        'Anello',
        'Bagsmart',
        'Bellroy',
        'Black Blaze',
        'Black Ember',
        'Bobby Backpack by XD Design',
        'Bric\'s',
        'Briggs & Riley',
        'C-Secure',
        'Cabeau',
        'CabinZero',
        'Case Logic',
        'Cilocala',
        'Conwood',
        'Crossing',
        'Crossing Wallet',
        'Doughnut',
        'Eagle Creek',
        'Eastpak',
        'Easynap',
        'Echolac',
        'ELECOM',
        'Ember',
        'FLEXTAIL',
        'Fulton Umbrellas',
        'GASTON LUGA',
        'Go Girl',
        'Go Travel',
        'Haan Hand Sanitisers',
        'Hellolulu',
        'Heroclip',
        'Human Gear',
        'Jansport',
        'July',
        'KeepCup',
        'King Jim',
        'Kinto',
        'KiU',
        'Klean Kanteen',
        'Klipsta',
        'Knirps Umbrellas',
        'Legato Largo',
        'LOQI',
        'Made By Fressko',
        'MAH',
        'Miamily',
        'Nalgene Water Bottles',
        'Nebo Flashlights',
        'Nifteen',
        'Notabag',
        'O2COOL',
        'Oasis Bottles',
        'OOFOS',
        'Orbitkey Key Organizers',
        'Osprey',
        'Pacsafe',
        'Paire',
        'Pitas',
        'Porsche Design',
        'RAWROW',
        'Retrokitchen',
        'SACHI',
        'Sandqvist',
        'Sea To Summit',
        'Secrid',
        'Shupatto',
        'SKROSS',
        'Status Anxiety',
        'Stratic',
        'STTOKE',
        'TEST',
        'The Planet Traveller',
        'THULE',
        'TPT TEST1',
        'Tropicfeel',
        'Ubiqua',
        'Varigrip Hand Exerciser',
        'Wacaco',
        'WPC',
    ];
}

$CONFIG = [
    'SHOPIFY_SHOP' => getenv('SHOPIFY_SHOP'),
    'SHOPIFY_ACCESS_TOKEN' => getenv('SHOPIFY_ACCESS_TOKEN'),
    'SHOPIFY_WEBHOOK_SECRET' => getenv('SHOPIFY_WEBHOOK_SECRET'),
    'EMAIL_FROM' => getenv('EMAIL_FROM'),
    'EMAIL_TO' => getenv('EMAIL_TO'),
    'SENDGRID_API_KEY' => getenv('SENDGRID_API_KEY'),
    'SETTINGS_PASSWORD' => getenv('SETTINGS_PASSWORD') ?: 'admin123',
    'BRANDS_TO_MONITOR' => loadBrands()
];

$requiredVars = ['SHOPIFY_SHOP', 'SHOPIFY_ACCESS_TOKEN', 'SHOPIFY_WEBHOOK_SECRET', 
                 'EMAIL_FROM', 'EMAIL_TO'];

foreach ($requiredVars as $var) {
    if (empty($CONFIG[$var])) {
        error_log("Missing required environment variable: $var");
        http_response_code(500);
        die(json_encode(['error' => "Missing configuration: $var"]));
    }
}

$STATE_FILE = __DIR__ . '/notification_state.json';
$LOG_FILE = __DIR__ . '/inventory_monitor.log';

function logMessage($message, $level = 'INFO') {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    file_put_contents($LOG_FILE, $logEntry, FILE_APPEND);
    error_log($message);
}

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
    logMessage("State saved: " . json_encode($state));
}

function verifyWebhook($data, $hmacHeader) {
    global $CONFIG;
    $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $CONFIG['SHOPIFY_WEBHOOK_SECRET'], true));
    $isValid = hash_equals($calculatedHmac, $hmacHeader);
    logMessage("Webhook verification: " . ($isValid ? 'VALID' : 'INVALID'));
    return $isValid;
}

function makeRequest($url, $method = 'GET', $headers = [], $data = null) {
    logMessage("Making $method request to: $url");
    
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
    
    logMessage("Response code: $httpCode");
    
    return [
        'code' => $httpCode,
        'headers' => $header,
        'body' => $body
    ];
}

function getProductsForBrand($vendor) {
    global $CONFIG;
    
    logMessage("Fetching products for brand: $vendor");
    
    $allProducts = [];
    $url = "{$CONFIG['SHOPIFY_SHOP']}/admin/api/2025-10/products.json?vendor=" . urlencode($vendor) . "&limit=250";
    
    while ($url) {
        $response = makeRequest($url, 'GET', [
            "X-Shopify-Access-Token: {$CONFIG['SHOPIFY_ACCESS_TOKEN']}",
            "Content-Type: application/json"
        ]);
        
        if ($response['code'] !== 200) {
            logMessage("Shopify API error for brand $vendor: " . $response['code'], 'ERROR');
            break;
        }
        
        $data = json_decode($response['body'], true);
        $allProducts = array_merge($allProducts, $data['products'] ?? []);
        
        $url = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $response['headers'], $matches)) {
            $url = $matches[1];
        }
    }
    
    logMessage("Fetched " . count($allProducts) . " products for brand: $vendor");
    
    return $allProducts;
}

function checkBrandStock($vendor) {
    logMessage("Checking stock status for brand: $vendor");
    
    $products = getProductsForBrand($vendor);
    
    if (empty($products)) {
        logMessage("No products found for brand: $vendor", 'WARNING');
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
    
    $result = [
        'allOOS' => $totalProducts === $oosProducts,
        'totalProducts' => $totalProducts,
        'oosProducts' => $oosProducts,
        'inStockProducts' => $totalProducts - $oosProducts
    ];
    
    logMessage("Stock check result for $vendor: " . json_encode($result));
    
    return $result;
}

function sendEmail($subject, $message) {
    global $CONFIG;
    
    logMessage("Attempting to send email: $subject");
    
    if (empty($CONFIG['SENDGRID_API_KEY'])) {
        logMessage("No SendGrid API key configured", 'ERROR');
        return ['success' => false, 'error' => 'No email service configured'];
    }
    
    $emailTo = array_map('trim', explode(',', $CONFIG['EMAIL_TO']));
    $recipients = [];
    foreach ($emailTo as $email) {
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = ['email' => $email];
        }
    }
    
    if (empty($recipients)) {
        logMessage("No valid email recipients configured", 'ERROR');
        return ['success' => false, 'error' => 'No valid email recipients'];
    }
    
    logMessage("Sending to " . count($recipients) . " recipient(s): " . implode(', ', array_column($recipients, 'email')));
    
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
            'to' => $recipients
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
        logMessage("Email sent successfully to " . count($recipients) . " recipient(s): $subject", 'SUCCESS');
        return ['success' => true, 'method' => 'sendgrid', 'recipients' => count($recipients)];
    } else {
        logMessage("SendGrid error: " . $response['body'], 'ERROR');
        return ['success' => false, 'error' => $response['body']];
    }
}

function getVendorFromInventoryItem($inventoryItemId) {
    global $CONFIG;
    
    logMessage("Fetching vendor for inventory item: $inventoryItemId");
    
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
        logMessage("GraphQL request failed with code: " . $response['code'], 'ERROR');
        return null;
    }
    
    $data = json_decode($response['body'], true);
    
    if (isset($data['errors'])) {
        logMessage("GraphQL errors: " . json_encode($data['errors']), 'ERROR');
        return null;
    }
    
    $result = [
        'vendor' => $data['data']['inventoryItem']['variant']['product']['vendor'] ?? null,
        'title' => $data['data']['inventoryItem']['variant']['product']['title'] ?? null
    ];
    
    logMessage("Vendor info retrieved: " . json_encode($result));
    
    return $result;
}

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);

logMessage("Incoming request: $requestMethod $path");

if ($path === '/settings' && $requestMethod === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brand Monitor Settings</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            line-height: 1.6;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .card {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .password-form { max-width: 400px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        input, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        textarea { min-height: 400px; font-family: 'Courier New', monospace; }
        .btn {
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        .btn:hover { background: #0056b3; }
        .btn-secondary {
            background: #6c757d;
            margin-left: 10px;
        }
        .btn-secondary:hover { background: #545b62; }
        .alert {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info-text { color: #666; font-size: 14px; margin-top: 8px; }
        .brand-count { 
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 500;
        }
        #brands-container { display: none; }
        .help-text {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>Brand Monitor Settings</h1>
            <p class="subtitle">Manage the brands being monitored for inventory changes</p>
            
            <div id="password-container">
                <form class="password-form" onsubmit="checkPassword(event)">
                    <div class="form-group">
                        <label for="password">Enter Password:</label>
                        <input type="password" id="password" required autofocus>
                        <p class="info-text">This password is set in your .env file as SETTINGS_PASSWORD</p>
                    </div>
                    <button type="submit" class="btn">Access Settings</button>
                </form>
            </div>

            <div id="brands-container">
                <div class="help-text">
                    <strong>Instructions:</strong><br>
                    • Enter one brand name per line<br>
                    • Brand names are case-sensitive and must match exactly as they appear in Shopify<br>
                    • Remove empty lines before saving<br>
                    • Current count: <span class="brand-count" id="brand-count">0</span>
                </div>

                <div id="message-container"></div>
                
                <form onsubmit="saveBrands(event)">
                    <div class="form-group">
                        <label for="brands">Monitored Brands (one per line):</label>
                        <textarea id="brands" required></textarea>
                    </div>
                    <button type="submit" class="btn">Save Brands</button>
                    <button type="button" class="btn btn-secondary" onclick="loadBrands()">Reset</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let currentPassword = '';

        function checkPassword(e) {
            e.preventDefault();
            const password = document.getElementById('password').value;
            currentPassword = password;
            
            fetch('/api/verify-password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password })
            })
            .then(r => r.json())
            .then(data => {
                if (data.valid) {
                    document.getElementById('password-container').style.display = 'none';
                    document.getElementById('brands-container').style.display = 'block';
                    loadBrands();
                } else {
                    alert('Invalid password. Please try again.');
                }
            })
            .catch(err => alert('Error: ' + err.message));
        }

        function loadBrands() {
            fetch('/api/get-brands', {
                headers: { 'X-Password': currentPassword }
            })
            .then(r => r.json())
            .then(data => {
                if (data.brands) {
                    document.getElementById('brands').value = data.brands.join('\n');
                    updateBrandCount();
                }
            })
            .catch(err => alert('Error loading brands: ' + err.message));
        }

        function saveBrands(e) {
            e.preventDefault();
            const brandsText = document.getElementById('brands').value;
            const brands = brandsText.split('\n')
                .map(b => b.trim())
                .filter(b => b.length > 0);
            
            if (brands.length === 0) {
                alert('Please enter at least one brand name.');
                return;
            }

            fetch('/api/save-brands', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-Password': currentPassword 
                },
                body: JSON.stringify({ brands: brands })
            })
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('message-container');
                if (data.success) {
                    container.innerHTML = '<div class="alert alert-success">✅ Brands saved successfully! Monitoring ' + data.count + ' brands.</div>';
                    updateBrandCount();
                } else {
                    container.innerHTML = '<div class="alert alert-error">❌ Error: ' + (data.error || 'Unknown error') + '</div>';
                }
                setTimeout(() => container.innerHTML = '', 5000);
            })
            .catch(err => {
                document.getElementById('message-container').innerHTML = 
                    '<div class="alert alert-error">❌ Error: ' + err.message + '</div>';
            });
        }

        function updateBrandCount() {
            const text = document.getElementById('brands').value;
            const count = text.split('\n').filter(b => b.trim().length > 0).length;
            document.getElementById('brand-count').textContent = count;
        }

        document.getElementById('brands')?.addEventListener('input', updateBrandCount);
    </script>
</body>
</html>
    <?php
    exit;
}

if ($path === '/api/verify-password' && $requestMethod === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';
    echo json_encode(['valid' => $password === $CONFIG['SETTINGS_PASSWORD']]);
    exit;
}

if ($path === '/api/get-brands' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    $password = $_SERVER['HTTP_X_PASSWORD'] ?? '';
    if ($password !== $CONFIG['SETTINGS_PASSWORD']) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    echo json_encode(['brands' => $CONFIG['BRANDS_TO_MONITOR']]);
    exit;
}

if ($path === '/api/save-brands' && $requestMethod === 'POST') {
    header('Content-Type: application/json');
    $password = $_SERVER['HTTP_X_PASSWORD'] ?? '';
    if ($password !== $CONFIG['SETTINGS_PASSWORD']) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $brands = $input['brands'] ?? [];
    
    if (empty($brands)) {
        echo json_encode(['success' => false, 'error' => 'No brands provided']);
        exit;
    }
    
    saveBrands($brands);
    logMessage("Brands updated via settings page. New count: " . count($brands), 'INFO');
    
    $CONFIG['BRANDS_TO_MONITOR'] = loadBrands();
    
    echo json_encode(['success' => true, 'count' => count($brands)]);
    exit;
}

if ($path === '/logs' && $requestMethod === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    
    if (file_exists($LOG_FILE)) {
        $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
        
        $file = file($LOG_FILE);
        $totalLines = count($file);
        $startLine = max(0, $totalLines - $lines);
        $logContent = implode('', array_slice($file, $startLine));
        
        echo "=== INVENTORY MONITOR LOGS (Last $lines lines) ===\n";
        echo "Total log entries: $totalLines\n";
        echo "Log file: $LOG_FILE\n";
        echo str_repeat('=', 60) . "\n\n";
        echo $logContent;
    } else {
        echo "No log file found. Logs will be created when the system starts processing.\n";
        echo "Log file location: $LOG_FILE\n";
    }
    exit;
}

if ($path === '/debug-config' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    $configInfo = [
        'shopify' => [
            'shop' => $CONFIG['SHOPIFY_SHOP'],
            'access_token' => $CONFIG['SHOPIFY_ACCESS_TOKEN'] ? substr($CONFIG['SHOPIFY_ACCESS_TOKEN'], 0, 10) . '...' : 'NOT SET',
            'webhook_secret' => $CONFIG['SHOPIFY_WEBHOOK_SECRET'] ? 'SET (hidden)' : 'NOT SET'
        ],
        'email' => [
            'from' => $CONFIG['EMAIL_FROM'],
            'to' => $CONFIG['EMAIL_TO'],
            'sendgrid_api_key' => $CONFIG['SENDGRID_API_KEY'] ? substr($CONFIG['SENDGRID_API_KEY'], 0, 10) . '...' : 'NOT SET'
        ],
        'monitoring' => [
            'brands' => $CONFIG['BRANDS_TO_MONITOR'],
            'total_brands' => count($CONFIG['BRANDS_TO_MONITOR'])
        ],
        'files' => [
            'state_file' => $STATE_FILE,
            'state_file_exists' => file_exists($STATE_FILE),
            'brands_file' => $BRANDS_FILE,
            'brands_file_exists' => file_exists($BRANDS_FILE),
            'log_file' => $LOG_FILE,
            'log_file_exists' => file_exists($LOG_FILE),
            'log_file_size' => file_exists($LOG_FILE) ? filesize($LOG_FILE) . ' bytes' : 'N/A'
        ],
        'current_state' => loadState(),
        'timestamp' => date('c'),
        'php_version' => PHP_VERSION
    ];
    
    logMessage("Debug config accessed");
    
    echo json_encode($configInfo, JSON_PRETTY_PRINT);
    exit;
}

if ($path === '/health' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'monitoring' => count($CONFIG['BRANDS_TO_MONITOR']) . ' brands',
        'timestamp' => date('c')
    ]);
    exit;
}

if ($path === '/test-email' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    logMessage("Test email requested");
    
    $result = sendEmail(
        '🧪 Test Email from Shopify Monitor',
        "This is a test email to verify your email configuration is working.\n\n" .
        "Method: SendGrid\n" .
        "From: {$CONFIG['EMAIL_FROM']}\n" .
        "To: {$CONFIG['EMAIL_TO']}\n" .
        "Timestamp: " . date('c') . "\n\n" .
        "If you're seeing this, your email setup is working correctly!"
    );
    
    echo json_encode($result);
    exit;
}

if ($path === '/check-now' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    logMessage("Manual inventory check initiated");
    
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
    
    logMessage("Manual check completed. OOS brands: " . count($oosBrands));
    
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
    
    logMessage("Webhook received from Shopify");
    
    if (!verifyWebhook($rawBody, $hmac)) {
        http_response_code(401);
        echo "Unauthorized";
        logMessage("Invalid webhook signature - request rejected", 'ERROR');
        exit;
    }
    
    $data = json_decode($rawBody, true);
    
    http_response_code(200);
    echo "OK";
    
    if (empty($data)) {
        logMessage("Empty webhook body (test webhook)", 'WARNING');
        exit;
    }
    
    logMessage("Webhook data: " . json_encode($data));
    
    sleep(3);
    
    $inventoryItemId = $data['inventory_item_id'] ?? null;
    
    if (!$inventoryItemId) {
        logMessage("No inventory_item_id in webhook", 'ERROR');
        exit;
    }
    
    $productInfo = getVendorFromInventoryItem($inventoryItemId);
    
    if (!$productInfo || !$productInfo['vendor']) {
        logMessage("Could not find vendor for inventory item: $inventoryItemId", 'ERROR');
        exit;
    }
    
    $vendor = $productInfo['vendor'];
    
    logMessage("Product: {$productInfo['title']}");
    logMessage("Brand: $vendor");
    
    logMessage("DEBUG - Checking if '$vendor' is in monitored list");
    logMessage("DEBUG - Vendor length: " . strlen($vendor) . " bytes");
    logMessage("DEBUG - Total brands to monitor: " . count($CONFIG['BRANDS_TO_MONITOR']));
    
    $found = in_array($vendor, $CONFIG['BRANDS_TO_MONITOR'], true);
    logMessage("DEBUG - Strict match result: " . ($found ? 'FOUND' : 'NOT FOUND'));
    
    if (!$found) {
        foreach ($CONFIG['BRANDS_TO_MONITOR'] as $idx => $brand) {
            if (stripos($brand, 'Jansport') !== false || stripos($vendor, $brand) !== false) {
                logMessage("DEBUG - Potential match at index $idx: '$brand' (length: " . strlen($brand) . ")");
            }
        }
        logMessage("Brand $vendor is not monitored - ignoring", 'INFO');
        exit;
    }
    
    logMessage("Brand $vendor IS MONITORED - proceeding with check");
    
    $stockStatus = checkBrandStock($vendor);
    $state = loadState();
    $lastState = $state[$vendor] ?? null;
    
    logMessage("$vendor: {$stockStatus['inStockProducts']}/{$stockStatus['totalProducts']} in stock");
    logMessage("Previous state for $vendor: " . ($lastState ?? 'NONE (First Check)'));

    if ($stockStatus['allOOS']) {
        if ($lastState !== 'OOS') {
            $isFirstCheck = ($lastState === null);
            logMessage("$vendor - ALL OUT OF STOCK" . ($isFirstCheck ? " (First Check)" : " (State Changed)") . " - Sending notification", 'ALERT');
            
            sendEmail(
                "🚨 ALL $vendor Products OUT OF STOCK",
                "All {$stockStatus['totalProducts']} products for \"$vendor\" are now out of stock.\n\n" .
                "⚠️ ACTION REQUIRED: Hide this brand from your brand page.\n\n" .
                "Brand: $vendor\n" .
                "Total Products: {$stockStatus['totalProducts']}\n" .
                "Out of Stock: {$stockStatus['oosProducts']}\n" .
                ($isFirstCheck ? "Note: This is the first check for this brand\n" : "") .
                "\nTimestamp: " . date('c')
            );
        } else {
            logMessage("$vendor - Still OOS - No notification needed");
        }
        
        $state[$vendor] = 'OOS';
        saveState($state);
    }
    elseif ($stockStatus['inStockProducts'] > 0) {
        if ($lastState === 'OOS') {
            logMessage("$vendor - BACK IN STOCK - Sending notification", 'ALERT');
            
            sendEmail(
                "✅ $vendor Products BACK IN STOCK",
                "Good news! {$stockStatus['inStockProducts']} product(s) for \"$vendor\" are back in stock.\n\n" .
                "✅ ACTION REQUIRED: Show this brand on your brand page.\n\n" .
                "Brand: $vendor\n" .
                "Total Products: {$stockStatus['totalProducts']}\n" .
                "In Stock: {$stockStatus['inStockProducts']}\n" .
                "Out of Stock: {$stockStatus['oosProducts']}\n\n" .
                "Timestamp: " . date('c')
            );
        } elseif ($lastState === null) {
            logMessage("$vendor - Has stock on first check - No notification needed (brand is healthy)");
        } else {
            logMessage("$vendor - Still has stock - No notification needed");
        }
        
        $state[$vendor] = 'IN_STOCK';
        saveState($state);
    }
    
    exit;
}

if ($path === '/admin/register-webhook' && $requestMethod === 'POST') {
    header('Content-Type: application/json');
    
    logMessage("Webhook registration requested");
    
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
        logMessage("Webhook registered successfully", 'SUCCESS');
        echo json_encode(['success' => true, 'webhook' => json_decode($response['body'], true)]);
    } else {
        logMessage("Webhook registration failed: " . $response['body'], 'ERROR');
        echo json_encode(['success' => false, 'error' => $response['body']]);
    }
    exit;
}

if ($path === '/admin/webhooks' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    
    logMessage("Webhook list requested");
    
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

if ($path === '/' && $requestMethod === 'GET') {
    header('Content-Type: application/json');
    echo json_encode([
        'service' => 'Shopify Brand Inventory Monitor',
        'status' => 'running',
        'monitoring' => count($CONFIG['BRANDS_TO_MONITOR']) . ' brands',
        'endpoints' => [
            'health' => '/health',
            'settings' => '/settings (Password Protected)',
            'webhook' => '/webhook/inventory (POST)',
            'manualCheck' => '/check-now',
            'testEmail' => '/test-email',
            'logs' => '/logs?lines=100 (GET)',
            'debugConfig' => '/debug-config (GET)',
            'listWebhooks' => '/admin/webhooks',
            'registerWebhook' => '/admin/register-webhook (POST)'
        ]
    ]);
    exit;
}

logMessage("404 Not Found: $path", 'WARNING');

http_response_code(404);
echo json_encode(['error' => 'Not Found']);
