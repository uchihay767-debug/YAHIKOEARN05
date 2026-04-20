<?php
// Telegram Bot Webhook Handler for Render.com
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error.log');

define('BOT_TOKEN', $_ENV['BOT_TOKEN'] ?? 'YOUR_BOT_TOKEN_HERE');
define('WEBHOOK_URL', $_ENV['WEBHOOK_URL'] ?? 'https://your-app.onrender.com/webhook');
define('API_URL', "https://api.telegram.org/bot" . BOT_TOKEN . '/');

$users_file = 'users.json';
$users = [];

// Load users
function loadUsers() {
    global $users, $users_file;
    if (file_exists($users_file)) {
        $users = json_decode(file_get_contents($users_file), true) ?: [];
    }
}

// Save users
function saveUsers($users_data) {
    global $users_file;
    file_put_contents($users_file, json_encode($users_data, JSON_PRETTY_PRINT));
    chmod($users_file, 0666);
}

// Log errors
function logError($message) {
    error_log(date('Y-m-d H:i:s') . " - $message\n", 3, 'error.log');
}

// Send Telegram message
function sendMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    $ch = curl_init(API_URL . 'sendMessage');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}

// Get main keyboard
function getMainKeyboard() {
    return [
        'keyboard' => [
            [['text' => '💰 Earn']],
            [['text' => '💳 Balance'], ['text' => '👥 Referrals']],
            [['text' => '🏆 Leaderboard'], ['text' => '🏧 Withdraw']],
            [['text' => '❓ Help']]
        ],
        'resize_keyboard' => true
    ];
}

// Set webhook on first run
function initializeBot() {
    $ch = curl_init(API_URL . 'setWebhook');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['url' => WEBHOOK_URL]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    
    logError("Webhook setup result: " . $result);
}

// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['webhook'])) {
    loadUsers();
    
    $update = json_decode(file_get_contents('php://input'), true);
    
    if (!$update) {
        http_response_code(200);
        exit;
    }
    
    $chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
    
    if (!$chat_id) {
        http_response_code(200);
        exit;
    }
    
    // Initialize user if new
    if (!isset($users[$chat_id])) {
        $users[$chat_id] = [
            'balance' => 0,
            'last_earn' => 0,
            'referrals' => 0,
            'ref_code' => substr(md5($chat_id . time()), 0, 8),
            'referred_by' => null
        ];
        
        // Check referral
        if (isset($update['message']['text']) && preg_match('/^\/start (.+)/', $update['message']['text'], $matches)) {
            $ref_code = $matches[1];
            foreach ($users as $user_id => &$user) {
                if (isset($user['ref_code']) && $user['ref_code'] === $ref_code) {
                    $user['referrals']++;
                    $users[$chat_id]['referred_by'] = $user_id;
                    sendMessage($chat_id, "🎉 Welcome! You've been referred by user with code $ref_code!\nStart earning now!");
                    break;
                }
            }
        }
    }
    
    // Handle commands and messages
    if (isset($update['message'])) {
        $text = $update['message']['text'] ?? '';
        
        switch (true) {
            case strpos($text, '/start') === 0:
                $msg = "🤖 Welcome to Point Bot!\n\n" .
                      "💰 Tap 'Earn' to get 10 points every minute\n" .
                      "👥 Share your referral code for 50 points each\n" .
                      "🏧 Withdraw min 100 points\n\n" .
                      "Your ref code: <b>{$users[$chat_id]['ref_code']}</b>";
                sendMessage($chat_id, $msg, getMainKeyboard());
                break;
                
            default:
                $msg = "Use the buttons below or /start to begin!";
                sendMessage($chat_id, $msg, getMainKeyboard());
                break;
        }
        
    } elseif (isset($update['callback_query'])) {
        $data = $update['callback_query']['data'];
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                arsort($users);
                $top = array_slice($users, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $user) {
                    $msg .= "$i. User $id: {$user['balance']} points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: t.me/" . BOT_TOKEN . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below!";
                break;
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
    http_response_code(200);
    exit;
}

// Health check / status page
if (isset($_GET['status'])) {
    echo "Bot is running!\n";
    echo "Webhook URL: " . WEBHOOK_URL . "\n";
    echo "Users count: " . count($users) . "\n";
    exit;
}

// Initialize webhook on first access
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET)) {
    initializeBot();
    header('Location: ?status');
    exit;
}
?>