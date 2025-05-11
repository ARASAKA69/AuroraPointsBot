<?php

set_time_limit(5);

require_once '/home/xxxx/domains/xxxx.xxx/xxxx/ap_bot_credentials.php';
// Load Database Helper Functions
require_once __DIR__ . '/db_helpers.php';


if (!defined('LOG_FILE_PATH')) {
    define('LOG_FILE_PATH', __DIR__ . '/ap_bot_fallback.log');
    error_log("Warning: LOG_FILE_PATH was not defined in credentials file. Using fallback.");
}

logMessage("Webhook accessed.", "DEBUG");

function apiRequest($method, $params = []) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;
    $response_data = false;

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $log_prefix = "apiRequest ({$method}): ";

        if ($curl_error != "") {
            logMessage($log_prefix . "cURL Error: " . $curl_error, "ERROR");
        } elseif ($http_code != 200) {
            logMessage($log_prefix . "HTTP Error: Code {$http_code}. Response: " . $response, "ERROR");
        } else {
            $decoded_response = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                logMessage($log_prefix . "JSON Decode Error: " . json_last_error_msg() . ". Raw Response: " . $response, "ERROR");
            } else {
                $response_data = $decoded_response;
                if (!(isset($decoded_response['ok']) && $decoded_response['ok'] === true)) {
                    $error_description = $decoded_response['description'] ?? 'Unknown Telegram error';
                    $error_code = $decoded_response['error_code'] ?? 'N/A';
                    logMessage($log_prefix . "Telegram API Error [{$error_code}]: {$error_description}. Full Response: {$response}", "ERROR");
                }
            }
        }
    } catch (Exception $e) {
        logMessage("apiRequest Exception: " . $e->getMessage(), "CRITICAL");
    }

    return $response_data;
}


function sendMessage($chat_id, $text, $reply_markup = null, $message_thread_id = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    
    if (defined('TOPIC_ID') && TOPIC_ID !== null && TOPIC_ID !== 0 && $message_thread_id !== null) {
         $params['message_thread_id'] = $message_thread_id;
    } elseif ($message_thread_id !== null && $message_thread_id !== 0)
    { $params['message_thread_id'] = $message_thread_id;
    }

    return apiRequest('sendMessage', $params);
}


function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $params = ['callback_query_id' => $callback_query_id];
    if ($text) {
        $params['text'] = $text;
    }
    if ($show_alert) {
        $params['show_alert'] = true;
    }
    return apiRequest('answerCallbackQuery', $params);
}


function editMessageText($chat_id, $message_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup === null) {
         $params['reply_markup'] = json_encode(['inline_keyboard' => []]);
    } elseif (is_array($reply_markup)) {
        $params['reply_markup'] = json_encode($reply_markup);
    }

    return apiRequest('editMessageText', $params);
}


$update_json = file_get_contents('php://input');
$update = json_decode($update_json, true);

if (!$update) {
    logMessage("Received invalid JSON or empty update.", "WARNING");
    http_response_code(400);
    exit();
}


if (isset($update['message'])) {
    logMessage("Received 'message' update.", "DEBUG");
} elseif (isset($update['callback_query'])) {
    logMessage("Received 'callback_query' update.", "DEBUG");
} else {
    logMessage("Received unhandled update type: " . json_encode(array_keys($update)), "DEBUG");
    exit();
}


try {
    if (isset($update['message'])) {
        $message = $update['message'];
        handleIncomingMessage($message);
    } elseif (isset($update['callback_query'])) {
        $callback_query = $update['callback_query'];
        handleCallbackQuery($callback_query);
    }
} catch (Exception $e) {
    logMessage("Unhandled Exception during update processing: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), "CRITICAL");
    http_response_code(500);
}


function isUserGroupAdmin($chatId, $userId) {
    if (defined('ADMIN_USER_ID') && $userId == ADMIN_USER_ID) {
        logMessage("User {$userId} matches pre-defined ADMIN_USER_ID. Granting access.", "DEBUG");
        return true;
    }


    logMessage("Checking group admin status via getChatMember for user {$userId} in chat {$chatId}.", "DEBUG");
    $response = apiRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId
    ]);


    if ($response && isset($response['ok']) && $response['ok'] === true && isset($response['result']['status'])) {
        $status = $response['result']['status'];
        logMessage("Status for user {$userId} in chat {$chatId}: {$status}", "DEBUG");
        if ($status === 'administrator' || $status === 'creator') {
            return true;
        }
    } else {
        $error_detail = $response['description'] ?? json_encode($response);
        logMessage("Failed to get chat member info for user {$userId} in chat {$chatId}. Response: {$error_detail}", "WARNING");
    }
    return false;
}




function handleIncomingMessage($message) {
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $is_bot = $message['from']['is_bot'] ?? false;
    $text = $message['text'] ?? '';
    $username = $message['from']['username'] ?? '';
    $first_name = $message['from']['first_name'] ?? '';
    $message_id = $message['message_id'];
    $message_thread_id = $message['message_thread_id'] ?? null;
    $reply_to_message = $message['reply_to_message'] ?? null;
    $chat_type = $message['chat']['type'] ?? 'private';

    logMessage("Processing message from user {$user_id} ('{$first_name}') in chat {$chat_id} (Type: {$chat_type})" . ($message_thread_id ? " topic {$message_thread_id}" : ""), "INFO");

    if ($is_bot) {
        logMessage("Ignoring message from bot {$user_id}.", "DEBUG");
        return;
    }

    ensureUserExists($user_id, $username, $first_name);

    if ($chat_id != GROUP_ID) {
        if ($chat_type === 'private') {
            if (isset($text[0]) && $text[0] === '/') {
                $parts = explode(' ', trim($text), 2);
                $command = $parts[0];
                $allowed_private_commands = ['/apwelcome', '/apinfo', '/aphelp', '/apleaderboard', '/aphistory', '/ap_daily_reward'];
                if (!in_array($command, $allowed_private_commands)) {
                    sendMessage($chat_id, "This command can only be used in the designated group topic. You can use commands like /apwelcome, /apinfo, /aphelp, etc. here.");
                    logMessage("Command '{$command}' blocked in private chat for user {$user_id}.", "INFO");
                    return;
                }
            } else {
                sendMessage($chat_id, "You can use commands like /apwelcome, /apinfo, or /aphelp here. For other actions, please go to the group topic.");
                logMessage("Ignoring regular message in private chat from user {$user_id}.", "DEBUG");
                return;
            }
        } else {
            logMessage("Message from user {$user_id} ignored (wrong group: {$chat_id}).", "DEBUG");
            return;
        }
    }
    elseif (defined('TOPIC_ID') && TOPIC_ID !== null && TOPIC_ID !== 0) {
        if ($message_thread_id != TOPIC_ID) {
            logMessage("Message from user {$user_id} in group " . GROUP_ID . " ignored (wrong topic: {$message_thread_id}, expected: " . TOPIC_ID . ").", "DEBUG");
            return;
        }
    }

    if (isset($text[0]) && $text[0] === '/') {
        $parts = explode(' ', trim($text), 2);
        $command = $parts[0];
        $arguments_str = $parts[1] ?? '';

        logMessage("Command received: {$command} with args '{$arguments_str}' from user {$user_id} in chat {$chat_id}" . ($message_thread_id && $chat_id == GROUP_ID ? "/topic {$message_thread_id}" : ""), "INFO");


switch ($command) {
    case '/apwelcome':
        handleApwelcomeCommand($chat_id, $user_id, $first_name, $message_thread_id);
        break;
    case '/apinfo':
        handleApinfoCommand($chat_id, $user_id, $message);
        break;
    case '/aphelp':
        handleAphelpCommand($chat_id, $message_thread_id);
        break;
    case '/apleaderboard':
        handleApLeaderboardCommand($chat_id, $message_thread_id);
        break;
    case '/aphistory':
        handleApHistoryCommand($chat_id, $user_id, $message);
        break;
    case '/ap_daily_reward':
        handleApDailyRewardCommand($chat_id, $user_id, $first_name, $message_thread_id);
        break;
    case '/apuserinfo':
        handleApUserinfoCommand($chat_id, $user_id, $message);
        break;
    case '/apsend':
        handleSendPointsCommand($chat_id, $user_id, $message);
        break;
    case '/apremove':
        handleRemovePointsCommand($chat_id, $user_id, $message);
        break;
    case '/apwithdraw':
        handleWithdrawalCommand($chat_id, $user_id, $first_name, $message_thread_id);
        break;
    case '/apgift':
        handleGiftApCommand($chat_id, $user_id, $message);
        break;
    default:
        sendMessage($chat_id, "Sorry, I don't recognize that command. Try /aphelp", null, $message_thread_id);
        logMessage("Unknown command '{$command}' from user {$user_id}.", "INFO");
        break;
}
    }
    elseif ($chat_type !== 'private') {
        handleRegularMessage($message);
    }
}



function handleApDailyRewardCommand($chat_id, $user_id, $first_name, $message_thread_id) {
    ensureUserExists($user_id, '', $first_name); 

    if (canClaimDailyReward($user_id)) {
        $min_daily_reward = defined('MIN_DAILY_REWARD') ? MIN_DAILY_REWARD : 5;
        $max_daily_reward = defined('MAX_DAILY_REWARD') ? MAX_DAILY_REWARD : 15;
        $reward_amount = rand($min_daily_reward, $max_daily_reward);

        if (addPoints($user_id, $reward_amount, "Daily Reward")) {
            if (updateLastDailyClaim($user_id)) {
                $user_details = getUserDetails($user_id);
                $current_points = $user_details['points'] ?? 'N/A';
                sendMessage($chat_id, "🎉 Congratulations, {$first_name}! You've claimed your daily reward of {$reward_amount} AP (randomly chosen between {$min_daily_reward}-{$max_daily_reward}). Your new balance is {$current_points} AP.", null, $message_thread_id);
                logMessage("User {$user_id} claimed daily reward of {$reward_amount} AP (random {$min_daily_reward}-{$max_daily_reward}).", "INFO");
            } else {
                sendMessage($chat_id, "You received {$reward_amount} AP, but there was an issue updating your claim status. Please contact an admin.", null, $message_thread_id);
                logMessage("User {$user_id} received daily reward ({$reward_amount} AP), BUT FAILED to update last_daily_claim timestamp.", "CRITICAL");
            }
        } else {
            sendMessage($chat_id, "Sorry, there was an error adding your daily reward points. Please try again later.", null, $message_thread_id);
            logMessage("Failed to add daily reward points ({$reward_amount} AP) for user {$user_id}.", "ERROR");
        }
    } else {
        sendMessage($chat_id, "You've already claimed your daily reward today, {$first_name}. Please try again tomorrow!", null, $message_thread_id);
        logMessage("User {$user_id} tried to claim daily reward again today.", "INFO");
    }
}



function handleGiftApCommand($chat_id, $sender_id, $message) {
    $message_thread_id = $message['message_thread_id'] ?? null;
    $text = $message['text'] ?? '';
    $reply_to_message = $message['reply_to_message'] ?? null;

    $parts = explode(' ', trim($text), 3);
    $recipient_id = null;
    $recipient_name = "User";
    $amount = 0;

    if ($reply_to_message !== null) {
        if (count($parts) < 2 || !ctype_digit($parts[1])) {
            sendMessage($chat_id, "Usage (when replying): `/apgift <amount>`\nAmount must be a positive number.", null, $message_thread_id);
            return;
        }
        $amount = (int)$parts[1];
        $recipient_id = $reply_to_message['from']['id'];

        if ($recipient_id == $sender_id) {
             sendMessage($chat_id, "You cannot gift points to yourself.", null, $message_thread_id);
             return;
        }
         if ($reply_to_message['from']['is_bot'] ?? false) {
             sendMessage($chat_id, "You cannot gift points to a bot.", null, $message_thread_id);
             return;
         }
         $recipient_user = ensureUserExists($recipient_id, $reply_to_message['from']['username'] ?? '', $reply_to_message['from']['first_name'] ?? 'User');
         if(!$recipient_user) {
              sendMessage($chat_id, "Could not find or verify the recipient user in the database. They might need to interact with the bot first.", null, $message_thread_id);
               logMessage("Gift failed: Recipient {$recipient_id} (from reply) could not be ensured/found in DB.", "WARNING");
              return;
         }
         $recipient_name = $recipient_user['first_name'] ?? "User {$recipient_id}";

    } else {
        if (count($parts) < 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
             sendMessage($chat_id, "Usage (without reply): `/apgift <amount> <user_id>`\nAmount and User ID must be numbers.", null, $message_thread_id);
             return;
        }
        $amount = (int)$parts[1];
        $recipient_id = (int)$parts[2];

         if ($recipient_id == $sender_id) {
             sendMessage($chat_id, "You cannot gift points to yourself.", null, $message_thread_id);
             return;
         }
         $recipient_user = getUserDetails($recipient_id);
         if (!$recipient_user) {
             sendMessage($chat_id, "Recipient User ID {$recipient_id} not found in the bot's records. They might need to interact with the bot first (e.g., send /apinfo).", null, $message_thread_id);
             logMessage("Gift failed: Recipient ID {$recipient_id} not found in users table.", "WARNING");
             return;
         }
          ensureUserExists($recipient_id, $recipient_user['username'] ?? '', $recipient_user['first_name'] ?? 'User');
          $recipient_name = $recipient_user['first_name'] ?? "User {$recipient_id}";
    }

    if ($amount <= 0) {
        sendMessage($chat_id, "You must gift a positive amount of AP.", null, $message_thread_id);
        return;
    }

    $sender_details = getUserDetails($sender_id);
    if (!$sender_details) {
        sendMessage($chat_id, "Could not retrieve your user details to check balance. Please try again.", null, $message_thread_id);
        logMessage("Failed /apgift: Sender {$sender_id} details not found.", "ERROR");
        return;
    }
    $sender_name = $sender_details['first_name'] ?? "User {$sender_id}";

    if ($sender_details['points'] < $amount) {
         sendMessage($chat_id, "You don't have enough points to gift {$amount} AP. Your balance: {$sender_details['points']} AP.", null, $message_thread_id);
         logMessage("Gift failed: Sender {$sender_id} has insufficient points ({$sender_details['points']} / {$amount}).", "INFO");
         return;
    }

    if (transferPoints($sender_id, $recipient_id, $amount, "Gift")) {
        logMessage("User {$sender_id} successfully gifted {$amount} AP to user {$recipient_id}.", "INFO");
        sendMessage($chat_id, "✅ You successfully gifted {$amount} AP to {$recipient_name}!", null, $message_thread_id);

        $recipient_details_after = getUserDetails($recipient_id);
        $recipient_balance = $recipient_details_after['points'] ?? 'N/A';
        sendMessage($recipient_id, "🎁 You received a gift of {$amount} AP from {$sender_name}! Your new balance is {$recipient_balance} AP.");
    } else {
        sendMessage($chat_id, "⚠️ There was an error processing your gift transfer. This could be due to insufficient funds or another issue. Please try again later or check /apinfo.", null, $message_thread_id);
        logMessage("Failed /apgift: transferPoints function returned false for transfer from {$sender_id} to {$recipient_id} for amount {$amount}.", "ERROR");
    }
}



function handleAphelpCommand($chat_id, $message_thread_id) {
    $points_needed = defined('POINTS_FOR_SUBSCRIPTION') ? POINTS_FOR_SUBSCRIPTION : 200;
    
    
    $min_thank_points = defined('MIN_THANK_YOU_POINTS') ? MIN_THANK_YOU_POINTS : 1;
    $max_thank_points = defined('MAX_THANK_YOU_POINTS') ? MAX_THANK_YOU_POINTS : 10;
    $min_daily_reward = defined('MIN_DAILY_REWARD') ? MIN_DAILY_REWARD : 5;
    $max_daily_reward = defined('MAX_DAILY_REWARD') ? MAX_DAILY_REWARD : 15;

    $help_text = "✨ <b>Welcome to the Aurora Points Bot!</b> ✨\n\n";
    $help_text .= "I'm here to reward your activity in the Aurora Horizons group topic!\n";
    $help_text .= "By being active and helpful, you earn 💰 <b>Aurora Points (AP)</b>.\n";
    $help_text .= "You can redeem <code>{$points_needed} AP</code> for a free 1-Week Aurora Horizons subscription!\n\n";

    $help_text .= "💡 <b><u>How to Earn AP:</u></b>\n";
    $help_text .= "<blockquote>- General activity in the designated topic (<code>" . (defined('POINTS_FOR_ACTIVITY') ? POINTS_FOR_ACTIVITY : 1) . " AP</code> per message).\n";
    $help_text .= "- Thanking other members (earns them <code>{$min_thank_points}-{$max_thank_points} AP</code> randomly).\n";
    $help_text .= "- Claiming your daily reward with <code>/ap_daily_reward</code> (earns <code>{$min_daily_reward}-{$max_daily_reward} AP</code> randomly).</blockquote>\n\n";

    $help_text .= "👤 <b><u>User Commands:</u></b>\n\n";

    $help_text .= "👋 <code>/apwelcome</code>\n";
    $help_text .= "<blockquote>Shows the welcome message.</blockquote>\n\n";

    $help_text .= "ℹ️ <code>/apinfo</code>\n";
    $help_text .= "<blockquote>Shows your current AP balance and points needed for a subscription.</blockquote>\n\n";

    $help_text .= "💸 <code>/apwithdraw</code>\n";
    $help_text .= "<blockquote>Request to redeem {$points_needed} AP for a subscription.</blockquote>\n\n";

    $help_text .= "🎁 <code>/apgift &lt;amount&gt; &lt;user_id&gt;</code>\n";
    $help_text .= "<blockquote>Gift some of your AP to another user by their ID.</blockquote>\n";
    $help_text .= "🎁 <code>/apgift &lt;amount&gt;</code> (as reply)\n";
    $help_text .= "<blockquote>Gift AP to the user you replied to.</blockquote>\n\n";

    $help_text .= "🏆 <code>/apleaderboard</code>\n";
    $help_text .= "<blockquote>Shows the top AP earners in the group.</blockquote>\n\n";

    $help_text .= "📜 <code>/aphistory</code>\n";
    $help_text .= "<blockquote>Shows your last 10 point transactions (sent to you via DM).</blockquote>\n\n";

    $help_text .= "🗓️ <code>/ap_daily_reward</code>\n";
    $help_text .= "<blockquote>Claim your daily bonus of {$min_daily_reward}-{$max_daily_reward} AP! (Once per day)</blockquote>\n\n";

    $help_text .= "❓ <code>/aphelp</code>\n";
    $help_text .= "<blockquote>Shows this help message.</blockquote>\n\n";


    $help_text .= "🔑 <b><u>Admin Commands:</u></b> (Only for Group Admins)\n\n";
    $help_text .= "➕ <code>/apsend &lt;amount&gt; &lt;user_id&gt;</code>\n";
    $help_text .= "<blockquote>Give AP to a user by their ID.</blockquote>\n";
    $help_text .= "➕ <code>/apsend &lt;amount&gt;</code> (as reply)\n";
    $help_text .= "<blockquote>Give AP to the replied-to user.</blockquote>\n\n";
    $help_text .= "➖ <code>/apremove &lt;amount&gt; &lt;user_id&gt;</code>\n";
    $help_text .= "<blockquote>Remove AP from a user by their ID.</blockquote>\n";
    $help_text .= "➖ <code>/apremove &lt;amount&gt;</code> (as reply)\n";
    $help_text .= "<blockquote>Remove AP from the replied-to user.</blockquote>\n\n";
    $help_text .= "📊 <code>/apinfo &lt;user_id&gt;</code>\n";
    $help_text .= "<blockquote>Show AP info for a specific user.</blockquote>\n\n";
    $help_text .= "🧾 <code>/aphistory &lt;user_id&gt;</code>\n";
    $help_text .= "<blockquote>Show transaction history for a specific user (sent to you via DM).</blockquote>\n";
    $help_text .= "🧾 <code>/aphistory</code> (as reply to a user)\n";
    $help_text .= "<blockquote>Show transaction history for the replied-to user (sent to you via DM).</blockquote>\n\n";
    $help_text .= "🕵️ <code>/apuserinfo &lt;user_id&gt;</code>\n";
    $help_text .= "<blockquote>Get detailed info about a user (ID, points, history - sent to you via DM).</blockquote>\n";
    $help_text .= "🕵️ <code>/apuserinfo</code> (as reply to a user)\n";
    $help_text .= "<blockquote>Get detailed info for the replied-to user (sent to you via DM).</blockquote>\n\n";

    sendMessage($chat_id, $help_text, null, $message_thread_id);
    logMessage("/aphelp command processed for chat {$chat_id}.", "INFO");
}





function handleApUserinfoCommand($chat_id_origin, $requesting_admin_id, $message) {
    $message_thread_id = $message['message_thread_id'] ?? null;
    $text = $message['text'] ?? '';
    $reply_to_message = $message['reply_to_message'] ?? null;

    if ($chat_id_origin != GROUP_ID || !isUserGroupAdmin(GROUP_ID, $requesting_admin_id)) {
        if ($chat_id_origin == GROUP_ID) {
             sendMessage($chat_id_origin, "Sorry, this command is for group administrators only.", null, $message_thread_id);
        }
        logMessage("Unauthorized /apuserinfo attempt by user {$requesting_admin_id} in chat {$chat_id_origin}.", "WARNING");
        return;
    }


    $target_user_id = null;
    $parts = explode(' ', trim($text), 2);

    if ($reply_to_message !== null && count($parts) == 1) {
        $target_user_id = $reply_to_message['from']['id'];
    } elseif (count($parts) == 2 && ctype_digit($parts[1])) {
        $target_user_id = (int)$parts[1];
    } else {
        sendMessage($chat_id_origin, "Usage: `/apuserinfo <user_id>` or reply to a user's message with `/apuserinfo`.", null, $message_thread_id);
        return;
    }

    if ($target_user_id === null) {
        sendMessage($chat_id_origin, "Could not determine the target user.", null, $message_thread_id);
        return;
    }

    // --- Fetch Data ---
    $target_user_details = getUserDetails($target_user_id);
    if (!$target_user_details) {
        sendMessage($chat_id_origin, "User ID {$target_user_id} not found in bot records.", null, $message_thread_id);
        logMessage("Admin {$requesting_admin_id} requested info for non-existent user {$target_user_id}.", "INFO");
        return;
    }

    $recent_transactions_limit = 5;
    $user_transactions = getUserTransactions($target_user_id, $recent_transactions_limit);
    $recent_withdrawals_limit = 3;
    $user_withdrawals = getUserWithdrawalHistory($target_user_id, $recent_withdrawals_limit);

    // --- Format Output ---
    $user_display_name = htmlspecialchars($target_user_details['first_name'] ?? "User");
    $user_username_display = !empty($target_user_details['username']) ? " (@" . htmlspecialchars($target_user_details['username']) . ")" : "";

    $info_text = "👤 **User Info for {$user_display_name}{$user_username_display} (ID: {$target_user_id})**\n\n";
    $info_text .= "<b>Current AP:</b> <code>" . ($target_user_details['points'] ?? 0) . "</code>\n";
    $info_text .= "<b>Joined Bot:</b> " . (isset($target_user_details['joined_date']) ? date('M d, Y H:i', strtotime($target_user_details['joined_date'])) : 'N/A') . "\n";
    $info_text .= "<b>Last Active:</b> " . (isset($target_user_details['last_active_timestamp']) ? date('M d, Y H:i', strtotime($target_user_details['last_active_timestamp'])) : 'N/A') . "\n";

    $info_text .= "\n--- 📜 Recent Transactions (Last {$recent_transactions_limit}) ---\n";
    if ($user_transactions === null) {
        $info_text .= "Error retrieving transactions.\n";
    } elseif (empty($user_transactions)) {
        $info_text .= "No transactions found.\n";
    } else {
        foreach ($user_transactions as $tx) {
            $date = date('M d, H:i', strtotime($tx['transaction_date']));
            $amount = (int)$tx['change_amount'];
            $reason = htmlspecialchars($tx['reason'] ?? 'N/A');
            $prefix = $amount >= 0 ? '+' : '';
            $info_text .= "<code>{$date}</code>: <b>{$prefix}{$amount} AP</b> ({$reason})\n";
        }
    }

    $info_text .= "\n--- 💸 Recent Withdrawals (Last {$recent_withdrawals_limit}) ---\n";
    if ($user_withdrawals === null) {
        $info_text .= "Error retrieving withdrawal history.\n";
    } elseif (empty($user_withdrawals)) {
        $info_text .= "No withdrawal requests found.\n";
    } else {
        foreach ($user_withdrawals as $wd) {
            $req_date = date('M d, Y', strtotime($wd['request_date']));
            $proc_date = isset($wd['processed_date']) ? " (Proc: " . date('M d, Y', strtotime($wd['processed_date'])) . ")" : "";
            $points_req = $wd['points_requested'] ?? 'N/A';
            $status = htmlspecialchars($wd['status'] ?? 'N/A');
            $proc_admin = $wd['processed_by_admin_id'] ? " (Admin: {$wd['processed_by_admin_id']})" : "";
            $info_text .= "<code>{$req_date}</code>: {$points_req} AP - <b>Status: {$status}</b>{$proc_date}{$proc_admin}\n";
        }
    }

    // --- Send Info to Admin ---
    $dm_sent_successfully = sendMessage($requesting_admin_id, $info_text);

    if ($chat_id_origin == GROUP_ID) {
        if ($dm_sent_successfully && isset($dm_sent_successfully['ok']) && $dm_sent_successfully['ok'] === true) {
            sendMessage($chat_id_origin, "Detailed info for {$user_display_name} has been sent to you via private message.", null, $message_thread_id);
        } else {
            sendMessage($chat_id_origin, "I tried to send you the user info via private message, but it failed. Please make sure you have started a chat with me directly and haven't blocked me.", null, $message_thread_id);
        }
    }
    logMessage("Admin {$requesting_admin_id} successfully retrieved info for user {$target_user_id}.", "INFO");
}




function handleApLeaderboardCommand($chat_id, $message_thread_id) {
    $limit = 10;
    $top_users = getTopUsers($limit);

    if ($top_users === null) {
        sendMessage($chat_id, "Sorry, there was an error retrieving the leaderboard.", null, $message_thread_id);
        logMessage("Error retrieving leaderboard data.", "ERROR");
        return;
    }

    if (empty($top_users)) {
        sendMessage($chat_id, "The leaderboard is currently empty! Start earning points!", null, $message_thread_id);
        logMessage("/apleaderboard processed, but no users found.", "INFO");
        return;
    }

    $leaderboard_text = "🏆 <b>Aurora Points Leaderboard (Top {$limit})</b> 🏆\n\n";
    $rank = 1;
    foreach ($top_users as $user) {
        $user_id = $user['user_id'];
        $first_name = $user['first_name'] ?? '';
        $username = $user['username'] ?? null;
        $points = $user['points'] ?? 0;
        $display_name = '';
        if (!empty($username)) {
            $display_name = "@" . htmlspecialchars($username);
        } elseif (!empty($first_name)) {
            $display_name = htmlspecialchars($first_name);
        } else {
            $display_name = "User ID: " . $user_id;
        }

        // Add medal emojis for top 3
        $medal = '';
        if ($rank === 1) $medal = '🥇 ';
        elseif ($rank === 2) $medal = '🥈 ';
        elseif ($rank === 3) $medal = '🥉 ';

        $leaderboard_text .= "{$medal}<b>{$rank}.</b> {$display_name} - <code>{$points} AP</code>\n";
        $rank++;
    }

    sendMessage($chat_id, $leaderboard_text, null, $message_thread_id);
    logMessage("/apleaderboard processed for chat {$chat_id}.", "INFO");
}




function handleCallbackQuery($callback_query) {
    $callback_query_id = $callback_query['id'];
    $callback_data = $callback_query['data'] ?? '';
    $user_id = $callback_query['from']['id'];
    $message = $callback_query['message'] ?? null;
    $chat_id = $message['chat']['id'] ?? null;
    $message_id = $message['message_id'] ?? null;

    logMessage("Processing callback_query from user {$user_id}. Data: {$callback_data}", "INFO");

    if (strpos($callback_data, 'withdraw_') === 0) {
        handleWithdrawalCallback($callback_query);
    } else {
        logMessage("Unhandled callback_query data: {$callback_data}", "WARNING");
        answerCallbackQuery($callback_query_id, "Action not recognized.");
    }
}


function handleApwelcomeCommand($chat_id, $user_id, $first_name, $message_thread_id) {

    ensureUserExists($user_id, '', $first_name);

    $welcome_message = "Welcome, {$first_name}! I'm the Aurora Points Bot. ✨\n"
        . "Participate in the group to earn AP and redeem them for Aurora Horizons subscriptions!\n\n"
        . "Use /apinfo to check your points or /aphelp for more commands.";
    sendMessage($chat_id, $welcome_message, null, $message_thread_id);
    logMessage("/apwelcome command processed for user {$user_id}.", "INFO");
}



function handleApHistoryCommand($chat_id_origin, $requesting_user_id, $message) {
    logMessage("--- handleApHistoryCommand START --- Received message object for /aphistory: " . json_encode($message), "DEBUG");

    $message_thread_id = $message['message_thread_id'] ?? null;
    $text = $message['text'] ?? '';
    $target_user_id = $requesting_user_id;
    $is_checking_other = false;
    $parts = explode(' ', trim($text), 2);
    
    if (count($parts) == 2 && ctype_digit($parts[1])) {
        $potential_target_id = (int)$parts[1];
        if ($potential_target_id != $requesting_user_id) {
        
            if ($chat_id_origin != GROUP_ID || !isUserGroupAdmin(GROUP_ID, $requesting_user_id)) {
                sendMessage($chat_id_origin, "You do not have permission to view another user's transaction history using a User ID. Use plain `/aphistory` for your own.", null, $message_thread_id);
                logMessage("User {$requesting_user_id} attempted to view history of {$potential_target_id} (via arg) without admin rights or not in group context.", "WARNING");
                return;
            }
            $target_user_id = $potential_target_id;
            $is_checking_other = true;
            logMessage("Admin {$requesting_user_id} is requesting history for user {$target_user_id} via argument.", "INFO");
        }
    }
    elseif (count($parts) > 1 && !ctype_digit($parts[1])) {
        sendMessage($chat_id_origin, "Invalid format. Use `/aphistory` for your own, or `/aphistory <user_id>` if you are an admin.", null, $message_thread_id);
        return;
    }


    // Fetch user details for the target user
    $target_user_details = getUserDetails($target_user_id);
    if (!$target_user_details) {
        $error_message = $is_checking_other ?
            "Target User ID {$target_user_id} not found in bot records." :
            "Could not find your user details. Please try sending a message in the group first (e.g., /apinfo) to register.";
        
        $error_chat_id_target = ($is_checking_other && $chat_id_origin == GROUP_ID && $chat_id_origin != $requesting_user_id) ? $chat_id_origin : $requesting_user_id;
        $error_thread_id_target = ($is_checking_other && $chat_id_origin == GROUP_ID && $chat_id_origin != $requesting_user_id) ? $message_thread_id : null;
        sendMessage($error_chat_id_target, $error_message, null, $error_thread_id_target);
        logMessage("Failed /aphistory: Target user {$target_user_id} not found.", "WARNING");
        return;
    }

    $target_display_name = htmlspecialchars($target_user_details['first_name'] ?? "User {$target_user_id}");
    if (!empty($target_user_details['username'])) {
        $target_display_name .= " (@" . htmlspecialchars($target_user_details['username']) . ")";
    }

    $limit = 10;
    $transactions = getUserTransactions($target_user_id, $limit);

    $history_message_header = "📜 **AP Transaction History for {$target_display_name}** (Last {$limit}):\n\n";
    $history_message_body = "";

    if ($transactions === null) {
        $history_message_body = "Sorry, there was an error retrieving the transaction history for {$target_display_name}.";
        logMessage("Error retrieving transaction data for user {$target_user_id}.", "ERROR");
    } elseif (empty($transactions)) {
        $history_message_body = "No transactions found for {$target_display_name} yet!";
        logMessage("/aphistory processed for user {$target_user_id}, but no transactions found.", "INFO");
    } else {
        foreach ($transactions as $tx) {
            $date = date('M d, H:i', strtotime($tx['transaction_date']));
            $amount = (int)$tx['change_amount'];
            $reason = htmlspecialchars($tx['reason'] ?? 'N/A');
            $prefix = $amount >= 0 ? '+' : '';

            $history_message_body .= "<code>{$date}</code>: <b>{$prefix}{$amount} AP</b> ({$reason})\n";
        }
    }


    $dm_sent_successfully = sendMessage($requesting_user_id, $history_message_header . $history_message_body);


    if ($chat_id_origin == GROUP_ID) {
        if ($dm_sent_successfully && isset($dm_sent_successfully['ok']) && $dm_sent_successfully['ok'] === true) {
            if ($is_checking_other) {
                sendMessage($chat_id_origin, "Transaction history for {$target_display_name} has been sent to you via private message.", null, $message_thread_id);
            } else {
                sendMessage($chat_id_origin, "Your transaction history has been sent to you via private message.", null, $message_thread_id);
            }
        } else {
            sendMessage($chat_id_origin, "I tried to send you the transaction history via private message, but it failed. Please make sure you have started a chat with me directly and haven't blocked me.", null, $message_thread_id);
            logMessage("Failed to send DM history to user {$requesting_user_id}.", "WARNING");
        }
    }
    logMessage("/aphistory successfully processed for target user {$target_user_id} by requester {$requesting_user_id}.", "INFO");
}


function handleApinfoCommand($chat_id_origin, $requesting_user_id, $message) {
    $message_thread_id = $message['message_thread_id'] ?? null;
    $text = $message['text'] ?? '';

    $target_user_id = $requesting_user_id;
    $is_checking_other = false;

    $parts = explode(' ', trim($text), 2);
    if (count($parts) == 2 && ctype_digit($parts[1])) {
        $potential_target_id = (int)$parts[1];
        if ($potential_target_id != $requesting_user_id) {
            if ($chat_id_origin != GROUP_ID || !isUserGroupAdmin(GROUP_ID, $requesting_user_id)) {
                sendMessage($chat_id_origin, "You do not have permission to view another user's AP info. Use just `/apinfo` for your own.", null, $message_thread_id);
                logMessage("User {$requesting_user_id} tried to view AP info of {$potential_target_id} without admin rights or outside group.", "WARNING");
                return;
            }
            $target_user_id = $potential_target_id;
            $is_checking_other = true;
            logMessage("Admin {$requesting_user_id} is requesting AP info for user {$target_user_id}.", "INFO");
        }
    }

    $user_data = getUserDetails($target_user_id);
    $display_first_name = ($target_user_id == $requesting_user_id) ? ($message['from']['first_name'] ?? ($user_data['first_name'] ?? 'User')) : ($user_data['first_name'] ?? "User {$target_user_id}");
    $display_first_name = htmlspecialchars($display_first_name);


    if ($user_data) {
        $points = $user_data['points'] ?? 0;
        $points_needed = defined('POINTS_FOR_SUBSCRIPTION') ? POINTS_FOR_SUBSCRIPTION : 200;
        $points_left = $points_needed - $points;

        $message_text = "";
        if ($is_checking_other) {
            $message_text .= "AP Info for {$display_first_name} (ID: {$target_user_id}):\n";
        } else {
            $message_text .= "Hi {$display_first_name}, ";
        }
        $message_text .= "Current AP: <b>{$points}</b>. ";

        if ($points_left <= 0) {
            $message_text .= "They have enough points to redeem a 1-Week Aurora Horizons Subscription! (Or: You have enough points! Use /apwithdraw)";
        } else {
            $message_text .= "They need <b>{$points_left} more AP</b> to redeem a 1-Week Aurora Horizons Subscription (total {$points_needed} AP). (Or: You need...)";
        }
        sendMessage($chat_id_origin, $message_text, null, $message_thread_id);
        logMessage("/apinfo processed for target user {$target_user_id} by requester {$requesting_user_id}. Points: {$points}", "INFO");
    } else {
        $error_message = $is_checking_other ?
            "Could not retrieve AP info for User ID {$target_user_id}." :
            "Sorry, I couldn't retrieve your points information. Please try sending a message in the group first or contact an admin.";
        sendMessage($chat_id_origin, $error_message, null, $message_thread_id);
        logMessage("/apinfo: Could not get details for target user {$target_user_id}, requested by {$requesting_user_id}.", "WARNING");
    }
}



function handleSendPointsCommand($chat_id, $user_id_who_sent_command, $message) {
    $message_thread_id = $message['message_thread_id'] ?? null;

    if ($chat_id != GROUP_ID || !isUserGroupAdmin(GROUP_ID, $user_id_who_sent_command)) {

        if ($chat_id == GROUP_ID){
             sendMessage($chat_id, "Sorry, only group administrators can use this command.", null, $message_thread_id);
        }
        logMessage("Unauthorized /apsend attempt by user {$user_id_who_sent_command} in chat {$chat_id}.", "WARNING");
        return;
    }

    $admin_id = $user_id_who_sent_command;
    $text = $message['text'] ?? '';
    $reply_to_message = $message['reply_to_message'] ?? null;
    $parts = explode(' ', trim($text), 3);
    $target_user_id = null;
    $target_user_name = "User";


    if ($reply_to_message !== null) {
         if (count($parts) < 2 || !ctype_digit($parts[1])) {
            sendMessage($chat_id, "Usage (when replying): `/apsend <amount>`", null, $message_thread_id);
            return;
        }
        $amount = (int)$parts[1];
        $target_user_id = $reply_to_message['from']['id'];
        $target_user_name = $reply_to_message['from']['first_name'] ?? "User";
         if ($reply_to_message['from']['is_bot'] ?? false) {
             sendMessage($chat_id, "Cannot send points to a bot.", null, $message_thread_id);
             return;
         }
         ensureUserExists($target_user_id, $reply_to_message['from']['username'] ?? '', $target_user_name);
    } else {
        if (count($parts) < 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
             sendMessage($chat_id, "Usage (without reply): `/apsend <amount> <user_id>`", null, $message_thread_id);
             return;
        }
        $amount = (int)$parts[1];
        $target_user_id = (int)$parts[2];
        $target_user_details = getUserDetails($target_user_id);
        if ($target_user_details) {
            $target_user_name = $target_user_details['first_name'] ?? "User";
            ensureUserExists($target_user_id, $target_user_details['username'] ?? '', $target_user_name);
        } else {
             logMessage("Admin {$admin_id} sending points to user ID {$target_user_id} not yet in DB. Creating.", "INFO");
             ensureUserExists($target_user_id, "User_{$target_user_id}", "User");
             $target_user_name = "User";
        }
    }

    if ($amount <= 0) {
        sendMessage($chat_id, "Amount must be a positive number.", null, $message_thread_id);
        return;
    }


    if ($target_user_id !== null) {
        if (addPoints($target_user_id, $amount, "Admin Grant", $admin_id)) {
            sendMessage($chat_id, "Successfully sent {$amount} AP to {$target_user_name} (ID: {$target_user_id}).", null, $message_thread_id);
            logMessage("Admin {$admin_id} sent {$amount} AP to user {$target_user_id}.", "INFO");
            $updated_user_data = getUserDetails($target_user_id);
            $current_points = $updated_user_data['points'] ?? 'N/A';
            sendMessage($target_user_id, "An admin has granted you {$amount} AP! You now have {$current_points} AP.");
        } else {
            sendMessage($chat_id, "Failed to send points to User ID {$target_user_id}. Check logs.", null, $message_thread_id);
            logMessage("Admin {$admin_id} failed to send {$amount} AP to user {$target_user_id}.", "ERROR");
        }
    }
}



function handleRemovePointsCommand($chat_id, $user_id_who_sent_command, $message) {
    $message_thread_id = $message['message_thread_id'] ?? null;


    if ($chat_id != GROUP_ID || !isUserGroupAdmin(GROUP_ID, $user_id_who_sent_command)) {
         if ($chat_id == GROUP_ID){
             sendMessage($chat_id, "Sorry, only group administrators can use this command.", null, $message_thread_id);
         }
        logMessage("Unauthorized /apremove attempt by user {$user_id_who_sent_command} in chat {$chat_id}.", "WARNING");
        return;
    }


    $admin_id = $user_id_who_sent_command;
    $text = $message['text'] ?? '';
    $reply_to_message = $message['reply_to_message'] ?? null;
    $parts = explode(' ', trim($text), 3);
    $target_user_id = null;
    $target_user_name = "User";

     if ($reply_to_message !== null) {
        if (count($parts) < 2 || !ctype_digit($parts[1])) {
            sendMessage($chat_id, "Usage (when replying): `/apremove <amount>`", null, $message_thread_id);
            return;
        }
        $amount = (int)$parts[1];
        $target_user_id = $reply_to_message['from']['id'];
        $target_user_name = $reply_to_message['from']['first_name'] ?? "User";
         if ($reply_to_message['from']['is_bot'] ?? false) {
             sendMessage($chat_id, "Cannot remove points from a bot.", null, $message_thread_id);
             return;
         }
         ensureUserExists($target_user_id, $reply_to_message['from']['username'] ?? '', $target_user_name);
    } else {
        if (count($parts) < 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
             sendMessage($chat_id, "Usage (without reply): `/apremove <amount> <user_id>`", null, $message_thread_id);
             return;
        }
        $amount = (int)$parts[1];
        $target_user_id = (int)$parts[2];
        $target_user_details = getUserDetails($target_user_id);
        if (!$target_user_details) {
             sendMessage($chat_id, "User ID {$target_user_id} not found. Cannot remove points.", null, $message_thread_id);
             return;
        }
        $target_user_name = $target_user_details['first_name'] ?? "User";
        ensureUserExists($target_user_id, $target_user_details['username'] ?? '', $target_user_name);
    }


    if ($amount <= 0) {
        sendMessage($chat_id, "Amount must be a positive number.", null, $message_thread_id);
        return;
    }

    if ($target_user_id !== null) {
        if (removePointsDb($target_user_id, $amount, "Admin Deduction", $admin_id)) {
            sendMessage($chat_id, "Successfully removed {$amount} AP from {$target_user_name} (ID: {$target_user_id}).", null, $message_thread_id);
            logMessage("Admin {$admin_id} removed {$amount} AP from user {$target_user_id}.", "INFO");
            $updated_user_data = getUserDetails($target_user_id);
            $current_points = $updated_user_data['points'] ?? 'N/A';
            sendMessage($target_user_id, "An admin has deducted {$amount} AP from your account. You now have {$current_points} AP.");
        } else {
            sendMessage($chat_id, "Failed to remove points from User ID {$target_user_id}. Insufficient points or database error. Check logs.", null, $message_thread_id);
        }
    }
}



function handleWithdrawalCommand($chat_id, $user_id, $first_name, $message_thread_id) {
    $user_data = getUserDetails($user_id);
    $points_needed = defined('POINTS_FOR_SUBSCRIPTION') ? POINTS_FOR_SUBSCRIPTION : 200;

    if (!$user_data) {
        sendMessage($chat_id, "Sorry, I couldn't retrieve your user data. Try sending a message in the group first.", null, $message_thread_id);
        logMessage("/apwithdraw: User {$user_id} data not found.", "WARNING");
        return;
    }

    $current_points = $user_data['points'] ?? 0;

    if ($current_points >= $points_needed) {
        $pending_request = getPendingWithdrawalByUserId($user_id);
        if ($pending_request) {
            sendMessage($chat_id, "You already have a pending withdrawal request (ID: {$pending_request['withdrawal_id']}). Please wait for it to be processed.", null, $message_thread_id);
            logMessage("User {$user_id} tried /apwithdraw but has pending request {$pending_request['withdrawal_id']}.", "INFO");
            return;
        }

        $request_id = createWithdrawalRequest($user_id, $points_needed);
        if ($request_id) {
            logMessage("User {$user_id} created withdrawal request {$request_id} for {$points_needed} AP.", "INFO");
            $user_username = $user_data['username'] ?? 'N/A';
            $admin_message_text = "📢 Withdrawal Request (ID: {$request_id}):\n"
                . "User: {$first_name} (@{$user_username}, ID: {$user_id})\n"
                . "Requests: {$points_needed} AP for 1-Week sub.\n"
                . "Current Balance: {$current_points} AP.";
            $admin_keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Approve ✅', 'callback_data' => "withdraw_approve_{$request_id}_{$user_id}"],
                        ['text' => 'Reject ❌', 'callback_data' => "withdraw_reject_{$request_id}_{$user_id}"]
                    ]
                ]
            ];
            sendMessage(ADMIN_USER_ID, $admin_message_text, $admin_keyboard);
            sendMessage($chat_id, "Your request to withdraw {$points_needed} AP (Request ID: {$request_id}) has been submitted. An admin will review it shortly.", null, $message_thread_id);

        } else {
            sendMessage($chat_id, "There was a database error submitting your request. Please try again later.", null, $message_thread_id);
            logMessage("Failed to create withdrawal request in DB for user {$user_id}.", "ERROR");
        }
    } else {
        $points_left = $points_needed - $current_points;
        sendMessage($chat_id, "You need {$points_left} more AP to request a withdrawal (Current: {$current_points} AP, Needed: {$points_needed} AP).", null, $message_thread_id);
        logMessage("User {$user_id} tried /apwithdraw with insufficient points ({$current_points}).", "INFO");
    }
}


function handleRegularMessage($message) {
    $user_id = $message['from']['id'];
    $first_name = $message['from']['first_name'] ?? 'User';
    $chat_id = $message['chat']['id'];
    $message_id = $message['message_id'];
    $message_thread_id = $message['message_thread_id'] ?? null;
    $reply_to_message = $message['reply_to_message'] ?? null;
    $text = $message['text'] ?? '';
    $points_activity = defined('POINTS_FOR_ACTIVITY') ? POINTS_FOR_ACTIVITY : 1;
    if ($points_activity > 0) {
        if (addPoints($user_id, $points_activity, "General Activity")) {
            logMessage("Awarded {$points_activity} AP to {$first_name} (ID: {$user_id}) for activity (Msg ID: {$message_id}).", "INFO");
        } else {
            logMessage("Failed to award activity points to user {$user_id} for Msg ID: {$message_id}.", "WARNING");
        }
    }


    if ($reply_to_message !== null) {
        $thanked_user_obj = $reply_to_message['from'] ?? null;
        if ($thanked_user_obj && !($thanked_user_obj['is_bot'] ?? false) && $thanked_user_obj['id'] != $user_id) {
            $thanked_user_id = $thanked_user_obj['id'];
            $thanked_user_first_name = $thanked_user_obj['first_name'] ?? 'User';
            $thanked_user_username = $thanked_user_obj['username'] ?? '';

            $text_lower = strtolower($text);
$thank_keywords = [
    // This list is Generated by Gemini, if there is any false shit then well, i dont care.
    // English
    "thanks",
    "thank you",
    "thx",
    "ty",
    "thankyou",
    "much appreciated",
    "appreciate it",
    "thanks a lot",
    "thank you very much",
    "thanks so much",
    "cheers",

    // German
    "danke",
    "danke schön",
    "danke sehr",
    "vielen dank",
    "besten dank",
    "ich danke Ihnen",
    "ich danke dir",

    // French
    "merci",
    "merci beaucoup",
    "merci bien",
    "je vous remercie", // formal
    "je te remercie",   // informal
    "grand merci",

    // Spanish
    "gracias",
    "muchas gracias",
    "mil gracias", // "a thousand thanks"
    "te lo agradezco", // informal, "I appreciate it from you"
    "se lo agradezco", // formal, "I appreciate it from you"
    "muy amable", // "very kind" - often used in thanks context

    // Italian
    "grazie",
    "grazie mille", // "a thousand thanks"
    "molte grazie",
    "grazie tante",
    "ti ringrazio", // informal
    "la ringrazio", // formal

    // Japanese
    "arigato", // ありがとう (casual)
    "arigato gozaimasu", // ありがとうございます (polite)
    "domo arigato", // どうもありがとう (more polite)
    "domo arigato gozaimasu", // どうもありがとうございます (very polite)
    "kansha shimasu", // 感謝します (I am grateful)
    "osoreirimasu", // 恐れ入ります (often used for thanks in a humble way, can also mean "excuse me" or "sorry")

    // Arabic (Romanized and Arabic script where common)
    "shukran", // شكراً
    "shukran jaziilan", // شكراً جزيلاً (thank you very much)
    "mashkoor", // مشكور (masculine, to a male)
    "mashkoora", // مشكورة (feminine, to a female)
    "barakallahu feek", // بارك الله فيك (May God bless you - common response/thanks)
    "teslam", // تسلم (May you be safe/preserved - often used as thanks)

    // Russian
    "spasibo", // спасибо
    "bolshoye spasibo", // большое спасибо (big thank you)
    "spasibki", // спасибки (cutesy/informal)
    "blagodaryu vas", // благодарю вас (formal, I thank you)
    "blagodaryu tebya", // благодарю тебя (informal, I thank you)

    // Korean
    "gamsahamnida", // 감사합니다 (formal)
    "gomapseumnida", // 고맙습니다 (formal, slightly softer than gamsahamnida)
    "gomawo", // 고마워 (informal)
    "kamsahae", // 감사해 (informal, derived from gamsahamnida)

    // Portuguese
    "obrigado", // (if speaker is male)
    "obrigada", // (if speaker is female)
    "muito obrigado", // (male speaker, "thank you very much")
    "muita obrigada", // (female speaker, "thank you very much")
    "agradecido", // (male speaker, "grateful")
    "agradecida", // (female speaker, "grateful")
    "valeu", // (Brazilian Portuguese, informal "cheers" or "thanks")

    // Dutch
    "dank je", // (informal)
    "dank u", // (formal or plural)
    "dankjewel", // (informal, slightly more emphasized)
    "dankuwel", // (formal, slightly more emphasized)
    "bedankt",
    "hartelijk dank", // "heartfelt thanks"
    "enorm bedankt", // "huge thanks"

    // Swedish
    "tack",
    "tack så mycket", // "thanks so much"
    "stort tack", // "big thanks"
    "tackar", // (more informal)

    // Norwegian
    "takk",
    "tusen takk", // "a thousand thanks"
    "mange takk", // "many thanks"
    "takk skal du ha", // (informal "thank you you shall have")
    "takk skal De ha", // (formal "thank you you shall have")

    // Danish
    "tak",
    "mange tak", // "many thanks"
    "tusind tak", // "a thousand thanks"
    "tak skal du have", // (informal)

    // Finnish
    "kiitos",
    "paljon kiitoksia", // "many thanks"
    "kiitoksia",

    // Polish
    "dziękuję",
    "dzięki", // (informal)
    "bardzo dziękuję", // "thank you very much"
    "dziękuję bardzo",

    // Czech
    "děkuji",
    "díky", // (informal)
    "mockrát děkuji", // "thank you very much"
    "děkuju", // (common spoken form of děkuji)

    // Slovak
    "ďakujem",
    "vďaka", // (informal "thanks to")
    "veľmi pekne ďakujem", // "thank you very nicely" (very much)
    "ďakujem pekne",

    // Hungarian
    "köszönöm",
    "köszi", // (informal)
    "nagyon köszönöm", // "thank you very much"
    "köszönöm szépen", // "thank you nicely/beautifully"

    // Turkish
    "teşekkür ederim",
    "teşekkürler", // (plural "thanks")
    "sağ ol", // (informal, literally "be healthy/alive")
    "çok teşekkür ederim", // "thank you very much"
    "mersi", // (borrowed from French, less common but understood)

    // Greek
    "efcharisto", // ευχαριστώ
    "efcharisto poly", // ευχαριστώ πολύ (thank you very much)
    "sas efcharisto", // σας ευχαριστώ (formal/plural "I thank you")
    "se efcharisto", // σε ευχαριστώ (informal "I thank you")

    // Hebrew (Romanized)
    "toda", // תודה
    "toda raba", // תודה רבה (thank you very much)
    "rav todot", // רב תודות (many thanks)

    // Hindi (Romanized)
    "dhanyawad", // धन्यवाद
    "shukriya", // शुक्रिया (borrowed from Persian/Urdu, very common)
    "bahut dhanyawad", // बहुत धन्यवाद (thank you very much)
    "bahut shukriya", // बहुत शुक्रिया (thank you very much)

    // Swahili
    "asante",
    "asante sana", // "thank you very much"
    "nashukuru", // "I am thankful"

    // Indonesian
    "terima kasih",
    "makasih", // (informal short form)
    "terima kasih banyak", // "thank you very much"

    // Malay
    "terima kasih",
    "terima kasih banyak", // "thank you very much"

    // Vietnamese (Romanized with tones for clarity, but often omitted in keywords)
    "cảm ơn", // (cam on)
    "cảm ơn nhiều", // (cam on nhieu - thank you much)
    "đa tạ", // (da ta - more formal, literary)

    // Thai (Romanized)
    "khop khun", // (ครับ/ค่ะ is added based on speaker's gender: khop khun krap for male, khop khun ka for female)
    "khop khun krap", // ขอบคุณครับ (male speaker)
    "khop khun ka", // ขอบคุณค่ะ (female speaker)
    "khop khun maak", // ขอบคุณมาก (thank you very much - krap/ka still applies)

    // Tagalog (Filipino)
    "salamat",
    "maraming salamat", // "many thanks"
    "salamat po", // (polite, with "po")
    "maraming salamat po", // (polite, "many thanks")

    // Irish (Gaeilge)
    "go raibh maith agat", // (to one person)
    "go raibh maith agaibh", // (to more than one person)
    "grma", // (common abbreviation for go raibh maith agat)

    // Welsh (Cymraeg)
    "diolch",
    "diolch yn fawr", // "thanks very much"

    // Scottish Gaelic (Gàidhlig)
    "tapadh leat", // (to one person)
    "tapadh leibh", // (to more than one person or formal)

    // Esperanto
    "dankon",
    "multan dankon", // "many thanks"

    // Latin
    "gratias tibi ago", // (to one person, "I give thanks to you")
    "gratias vobis ago", // (to more than one person, "I give thanks to you all")
    "gratias", // (simple "thanks")

    // Mandarin Chinese (Pinyin)
    "xièxie", // 谢谢
    "duō xiè", // 多谢 (many thanks, more common in some southern regions/Cantonese context)
    "fēicháng gǎnxiè", // 非常感谢 (thank you very much/extremely grateful)
    "gǎnxiè", // 感谢 (grateful, thanks - often more formal)
    "xièxie nǐ", // 谢谢你 (thank you - to you, informal)
    "xièxie nín", // 谢谢您 (thank you - to you, polite)

    // Cantonese (Yale Romanization)
    "m̀h'gōi", // 唔該 (used for services, light thanks, "excuse me")
    "dōjeh", // 多謝 (used for gifts, compliments, heavier thanks)
    "m̀h'gōi saai", // 唔該晒 (thanks for everything - service context)
    "dōjeh saai", // 多謝晒 (thanks for everything - gift context)

    // Icelandic
    "takk",
    "takk fyrir", // "thanks for"
    "kærar þakkir", // "dear thanks" (many thanks)
    "þakka þér fyrir", // (I thank you for)

    // Lithuanian
    "ačiū",
    "labai ačiū", // "thank you very much"
    "dėkoju", // "I thank (you)"

    // Latvian
    "paldies",
    "liels paldies", // "big thanks"

    // Estonian
    "aitäh",
    "suur aitäh", // "big thanks"
    "tänan", // "I thank"

    // Albanian
    "faleminderit",
    "shumë faleminderit", // "thank you very much"

    // Bosnian
    "hvala",
    "hvala lijepa", // "nice thanks" (thank you very much)
    "puno hvala", // "many thanks"

    // Croatian
    "hvala",
    "hvala lijepa", // "nice thanks" (thank you very much)
    "puno hvala", // "many thanks"

    // Serbian (Cyrillic and Latin)
    "hvala", // хвала
    "hvala lepo", // хвала лепо (thank you very much)
    "puno hvala", // пуно хвала (many thanks)

    // Macedonian
    "blagodaram", // благодарам
    "mnogu blagodaram", // многу благодарам (thank you very much)
    "fala", // фала (more informal, similar to hvala)

    // Bulgarian
    "blagodarya", // благодаря
    "mnogo blagodarya", // много благодаря (thank you very much)
    "mersi", // мерси (borrowed from French, informal)

    // Romanian
    "mulțumesc",
    "mersi", // (borrowed from French, informal)
    "mulțumesc frumos", // "thank you beautifully" (thank you very much)
    "mulțumesc mult", // "thank you much"

    // Slovenian
    "hvala",
    "najlepša hvala", // "most beautiful thanks" (thank you very much)
    "hvala lepa", // "nice thanks"

    // Georgian (Romanized)
    "madloba", // მადლობა
    "didi madloba", // დიდი მადლობა (big thank you)

    // Armenian (Western Romanized, Eastern Romanized)
    "shnorhakalutyun", // շնորհակալություն (shnorhakalut’yun - common)
    "mersi", // (informal, borrowed from French)

    // Bengali (Romanized)
    "dhonnobad", // ধন্যবাদ
    "shukriya", // (from Persian/Urdu, also used)
    "one_k_dhonnobad", // অনেক ধন্যবাদ (many thanks)

    // Gujarati (Romanized)
    "aabhar", // આભાર
    "dhanyawad", // ધન્યવાદ
    "tamaro aabhar", // તમારો આભાર (your thanks)

    // Kannada (Romanized)
    "dhanyavadagalu", // ಧನ್ಯವಾದಗಳು
    "thumba dhanyavadagalu", // ತುಂಬಾ ಧನ್ಯವಾದಗಳು (many thanks)

    // Malayalam (Romanized)
    "nandi", // നന്ദി
    "valare nandi", // വളരെ നന്ദി (very much thanks)

    // Marathi (Romanized)
    "dhanyawad", // धन्यवाद
    "aabhari aahe", // आभारी आहे (I am grateful)
    "khup dhanyawad", // खूप धन्यवाद (many thanks)

    // Nepali (Romanized)
    "dhanyabad", // धन्यवाद
    "dherai dhanyabad", // धेरै धन्यवाद (many thanks)

    // Punjabi (Romanized)
    "meharbani", // ਮਿਹਰਬਾਨੀ
    "dhannvaad", // ਧੰਨਵਾਦ
    "shukriya", // ਸ਼ੁਕਰੀਆ (from Persian/Urdu)
    "bahut meharbani", // ਬਹੁਤ ਮਿਹਰਬਾਨੀ (very kind/thank you)
    "bahut dhannvaad", // ਬਹੁਤ ਧੰਨਵਾਦ (many thanks)

    // Sinhala (Romanized)
    "istuti", // ස්තුතියි
    "bohoma istuti", // බොහොම ස්තුතියි (thank you very much)

    // Tamil (Romanized)
    "nanri", // நன்றி
    "romba nanri", // ரொம்ப நன்றி (thank you very much)
    "mikka nanri", // மிக்க நன்றி (many thanks)

    // Telugu (Romanized)
    "dhanyavadalu", // ధన్యవాదాలు
    "chala dhanyavadalu", // చాలా ధన్యవాదాలు (many thanks)
    "kruthagnathalu", // కృతజ్ఞతలు (gratitude)

    // Urdu (Romanized - often overlaps with Hindi)
    "shukriya", // شکریہ
    "bahut shukriya", // بہت شکریہ (thank you very much)
    "mehrbani", // مہربانی (kindness, often used as thanks)
    "nawazish", // نوازش (kindness, favour - used to express thanks)

    // Pashto (Romanized)
    "manana", // مننه
    "ډیره مننه", // dera manana (thank you very much)

    // Farsi (Persian - Romanized)
    "merci", // (common, borrowed from French)
    "mamnoon", // ممنون
    "sepasgozaram", // سپاسگزارم (I am grateful)
    "kheyli mamnoon", // خیلی ممنون (thank you very much)
    "tashakkor mikonam", // تشکر می‌کنم (I thank you)

    // Somali
    "mahadsanid",
    "aad baad u mahadsantahay", // (thank you very much)

    // Yoruba
    "e dupe", // (to one elder or plural)
    "o dupe", // (to one peer or younger)
    "e se", // (more common, "thank you")
    "e se gan", // ("thank you very much")

    // Zulu
    "ngiyabonga", // (I thank you)
    "siyabonga", // (we thank you)
    "ngiyabonga kakhulu", // (I thank you very much)

    // Xhosa
    "enkosi",
    "enkosi kakhulu", // (thank you very much)

    // Afrikaans
    "dankie",
    "baie dankie", // "very much thanks"

    // Mongolian (Romanized)
    "bayarlalaa", // Баярлалаа
    "mash ikh bayarlalaa", // Маш их баярлалаа (thank you very much)

    // Tibetan (Romanized)
    "thuk je che", // ཐུགས་རྗེ་ཆེ།
    "thuk je nang", // (polite form)

    // Hawaiian
    "mahalo",
    "mahalo nui loa", // "thank you very much"

    // Maori (New Zealand)
    "kia ora", // (also means hello, be well - used for thanks)

    // Haitian Creole
    "mèsi",
    "mèsi anpil", // "thanks a lot"

    // Maltese
    "grazzi",
    "grazzi ħafna", // "thank you very much"

    // Uzbek (Romanized)
    "rahmat",
    "katta rahmat", // "big thanks"

    // Azerbaijani (Romanized)
    "təşəkkür edirəm",
    "çox sağ ol", // "be very healthy" (thank you very much)
    "minnətdaram", // "I am grateful"

    // Kazakh (Romanized)
    "rahmet", // рақмет
    "kop rahmet", // көп рақмет (many thanks)

    // Kyrgyz (Romanized)
    "rahmat", // рахмат
    "chong rahmat", // чоң рахмат (big thanks)

    // Turkmen (Romanized)
    "sag boluň", // (formal/plural)
    "sag bol", // (informal)
    "köp sag boluň", // (thank you very much - formal)

    // Lao (Romanized)
    "khop jai", // ຂອບໃຈ
    "khop jai lai lai", // ຂອບໃຈຫຼາຍໆ (thank you very much)

    // Burmese (Myanmar - Romanized)
    "kyay zu tin ba deh", // ကျေးဇူးတင်ပါတယ်
    "kyay zu tin de", // (more casual)
    "arr nae par dae", // အားနာပါတယ် (often used to express gratitude with a sense of imposing)

    // Khmer (Cambodian - Romanized)
    "arkun", // អរគុណ
    "arkun chraen", // អរគុណច្រើន (thank you very much)

    // Javanese (Indonesia)
    "matur nuwun",
    "matur suwun", // (slight variation)
    "nuwun sanget", // (thank you very much)

    // Sundanese (Indonesia)
    "hatur nuhun",
    "nuhun pisan", // (thank you very much)

    // Basque (Euskara)
    "eskerrik asko",
    "mila esker", // "a thousand thanks"

    // Catalan
    "gràcies",
    "moltes gràcies", // "many thanks"
    "merci", // (borrowed from French, also used)

    // Galician
    "grazas",
    "moitas grazas", // "many thanks"

    // Luxembourgish
    "merci",
    "villmools merci", // "thank you very much"

    // Chechen (Romanized)
    "barkalla", // Баркалла

    // Tatar (Romanized)
    "rähmät", // рәхмәт
    "zur rähmät", // зур рәхмәт (big thanks)

    // Uighur (Romanized)
    "rehmet", // رەھمەت
    "kop rehmet", // كۆپ رەھمەت (many thanks)

    // Amharic (Romanized)
    "ameseginalehu", // አመሰግናለሁ
    "betam ameseginalehu", // በጣም አመሰግናለሁ (thank you very much)

    // Tigrinya (Romanized)
    "yekeniyeley", // የቐንየለይ
    "yekeniyele", // (common alternative)

    // Igbo
    "daalụ",
    "imela", // (also "well done", can convey thanks)
    "daalụ rinne", // (thank you very much)

    // Hausa
    "na gode",
    "na gode kwarai", // (thank you very much)

    // Lingala
    "melesi", // (from French 'merci')
    "botondi", // (less common, more indigenous)

    // Sesotho
    "kea leboha",
    "kea leboha haholo", // (thank you very much)

    // Setswana
    "ke a leboga",
    "ke a leboga thata", // (thank you very much)

    // Kinyarwanda (Rwanda)
    "murakoze",
    "murakoze cyane", // (thank you very much)

    // Kirundi (Burundi)
    "murakoze",
    "murakoze cane", // (thank you very much)

    // Chichewa (Nyanja - Malawi, Zambia)
    "zikomo",
    "zikomo kwambiri", // (thank you very much)

    // Shona (Zimbabwe)
    "ndatenda", // (I thank you)
    "mazvita", // (more formal, or to elders)
    "tinotenda", // (we thank you)

    // Tsonga (South Africa, Mozambique)
    "ndza khensa",
    "ndza khensa ngopfu", // (thank you very much)

    // Venda (South Africa)
    "ndo livhuwa",
    "ndo livhuwa nga maanda", // (thank you very much)

    // Fijian
    "vinaka",
    "vinaka vakalevu", // "thank you very much"

    // Samoan
    "fa'afetai",
    "fa'afetai tele", // "thank you very much"

    // Tongan
    "mālō",
    "mālō 'aupito", // "thank you very much"

    // Tahitian
    "māuruuru",
    "māuruuru roa", // "thank you very much"

    // Greenlandic (Kalaallisut)
    "qujanaq",
    "qujanarsuaq", // "thank you very much"

    // Faroese
    "takk",
    "takk fyri",
    "manga tøkk", // "many thanks"

    // Wolof (Senegal, Gambia)
    "jërëjëf",
    "jërëjëf bu baax", // (thank you very much)

    // Bambara (Mali)
    "i ni ce",
    "aw ni ce", // (plural/formal)
    "i ni ce kosɛbɛ", // (thank you very much)

    // Dzongkha (Bhutan - Romanized)
    "kadrinche",
    "kadrinche la", // (polite honorific)

    // Tok Pisin (Papua New Guinea)
    "tenkyu",
    "tenkyu tru", // "thank you very much"

    // Bislama (Vanuatu)
    "tank yu",
    "tank yu tumas", // "thank you very much"

    // Marshallese
    "kommol",
    "kommol tata", // "thank you very much"

    // Palauan
    "sulang",
    "sulang el lmes", // "thank you very much"

    // Chamorro (Guam, Northern Mariana Islands)
    "si Yu'os ma'åse'", // "God have mercy/bless you" (used as thank you)
    "si Yu'os ma'ase'", // (Alternative spelling)

    // Interlingua
    "gratias",
    "multe gratias",

    // Lojban (Constructed language)
    "ki'e", // (standard thanks)
];

            $found_thank_you = false;

            foreach ($thank_keywords as $keyword) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $text_lower)) {
                    $found_thank_you = true;
                    break;
                }
            }

            if ($found_thank_you) {
                ensureUserExists($thanked_user_id, $thanked_user_username, $thanked_user_first_name);
                $min_thank_points = defined('MIN_THANK_YOU_POINTS') ? MIN_THANK_YOU_POINTS : 1;
                $max_thank_points = defined('MAX_THANK_YOU_POINTS') ? MAX_THANK_YOU_POINTS : 10;
                $points_thank_awarded = rand($min_thank_points, $max_thank_points);

                if ($points_thank_awarded > 0) {
                    if (addPoints($thanked_user_id, $points_thank_awarded, "Thanked by {$first_name} ({$user_id})")) {
                         logMessage("Awarded {$points_thank_awarded} AP (random {$min_thank_points}-{$max_thank_points}) to {$thanked_user_first_name} (ID: {$thanked_user_id}) for being thanked by {$first_name} (ID: {$user_id}) (Original Msg ID: {$message_id}).", "INFO");

                         $thanked_user_details = getUserDetails($thanked_user_id);
                         $current_points = $thanked_user_details['points'] ?? 'N/A';
                         $reply_text_notification = "{$thanked_user_first_name} received {$points_thank_awarded} AP because {$first_name} thanked them! {$thanked_user_first_name} now has {$current_points} AP.";
                         sendMessage($chat_id, $reply_text_notification, null, $message_thread_id);
                    } else {
                         logMessage("Failed to award random 'thank you' points ({$points_thank_awarded} AP) to user {$thanked_user_id} for Msg ID: {$message_id}.", "WARNING");
                    }
                }
            }
        }
    }
}



function handleWithdrawalCallback($callback_query) {
    $callback_query_id = $callback_query['id'];
    $callback_data = $callback_query['data'];
    $admin_id = $callback_query['from']['id'];
    $message = $callback_query['message'] ?? null;
    $chat_id = $message['chat']['id'] ?? null;
    $message_id = $message['message_id'] ?? null;

    if ($admin_id != ADMIN_USER_ID) {
        answerCallbackQuery($callback_query_id, "This action is only for the bot admin.", true);
        logMessage("Non-admin user {$admin_id} tried to action withdrawal callback: {$callback_data}", "WARNING");
        return;
    }


    $parts = explode('_', $callback_data);
    if (count($parts) !== 4 || $parts[0] !== 'withdraw') {
        answerCallbackQuery($callback_query_id, "Error: Invalid callback data format.");
        logMessage("Invalid withdrawal callback data format: {$callback_data}", "ERROR");
        return;
    }

    $action = $parts[1];
    $request_id = (int)$parts[2];
    $user_id = (int)$parts[3];
    $request_data = getWithdrawalRequest($request_id);

    if (!$request_data) {
        answerCallbackQuery($callback_query_id, "Error: Withdrawal request not found.", true);
        editMessageText($chat_id, $message_id, "Error: Request ID {$request_id} not found in DB.");
        logMessage("Withdrawal request {$request_id} not found for callback.", "ERROR");
        return;
    }


    if ($request_data['user_id'] != $user_id) {
        answerCallbackQuery($callback_query_id, "Error: User ID mismatch.", true);
        editMessageText($chat_id, $message_id, "Error processing request {$request_id}: User ID mismatch. Check logs.");
        logMessage("CRITICAL: User ID mismatch for request {$request_id}. DB: {$request_data['user_id']}, Callback: {$user_id}", "CRITICAL");
        return;
    }


    if ($request_data['status'] !== 'pending') {
        answerCallbackQuery($callback_query_id, "This request was already processed ({$request_data['status']}).");
        editMessageText($chat_id, $message_id, "Request ID {$request_id} (User: {$user_id}) already processed as '{$request_data['status']}'.");
        logMessage("Withdrawal request {$request_id} already processed as {$request_data['status']}. Clicked again by admin.", "INFO");
        return;
    }

    $points_to_deduct = $request_data['points_requested'];
    $user_display_name = $request_data['first_name'] ?? "User {$user_id}";
    $new_status = "";
    $admin_feedback_text = "";
    $user_notification_text = "";
    $points_deducted_successfully = false;

    if ($action === 'approve') {
        if (removePointsDb($user_id, $points_to_deduct, "Withdrawal Approved (Req ID: {$request_id})", $admin_id)) {
            $new_status = "approved";
            $points_deducted_successfully = true;
            $admin_feedback_text = "✅ Request ID {$request_id} for {$user_display_name} APPROVED. {$points_to_deduct} AP deducted.";
            $user_notification_text = "🎉 Congratulations! Your Aurora Points withdrawal (Request ID: {$request_id}) for {$points_to_deduct} AP has been APPROVED! "
                                    . "An admin will contact you shortly regarding your Aurora Horizons subscription.";
            logMessage("Admin {$admin_id} approved withdrawal request {$request_id} for user {$user_id}. Points deducted.", "INFO");
        } else {
            $new_status = "failed_approval_insufficient_funds";
            $admin_feedback_text = "⚠️ Request ID {$request_id} for {$user_display_name} APPROVAL FAILED. User had insufficient points. Points NOT deducted.";
            $user_notification_text = "⚠️ Your withdrawal request (ID: {$request_id}) could not be approved due to insufficient AP balance at the time of processing. "
                                    . "Please check your balance and contact an admin if you have questions.";
            logMessage("Admin {$admin_id} failed to approve request {$request_id} for user {$user_id} due to insufficient points.", "WARNING");
        }
        answerCallbackQuery($callback_query_id, $points_deducted_successfully ? "Approved!" : "Approval Failed (Funds?)");

    } elseif ($action === 'reject') {
        $new_status = "rejected";
        $admin_feedback_text = "❌ Request ID {$request_id} for {$user_display_name} REJECTED.";
        $user_notification_text = "Your Aurora Points withdrawal (Request ID: {$request_id}) for {$points_to_deduct} AP has been rejected. If you have questions, please contact an admin.";
        answerCallbackQuery($callback_query_id, "Rejected.");
        logMessage("Admin {$admin_id} rejected withdrawal request {$request_id} for user {$user_id}.", "INFO");
        
        } else {
        answerCallbackQuery($callback_query_id, "Error: Unknown action.", true);
        editMessageText($chat_id, $message_id, "Error processing request {$request_id}: Unknown action '{$action}'.");
        logMessage("Unknown action '{$action}' in withdrawal callback for request {$request_id}.", "ERROR");
        return;
    }

    if ($new_status) {
        if (updateWithdrawalStatus($request_id, $new_status, $admin_id)) {
            logMessage("Withdrawal request {$request_id} status updated to {$new_status}.", "INFO");
        } else {
            logMessage("Failed to update withdrawal status for request {$request_id} in DB.", "ERROR");
            $admin_feedback_text .= " (DB Status Update FAILED!)";
        }
    }

    if ($chat_id && $message_id) {
        editMessageText($chat_id, $message_id, $admin_feedback_text, null);
    }

    if ($user_notification_text) {
        sendMessage($user_id, $user_notification_text);
    }
}

?>