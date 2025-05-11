<?php

require_once '/home/xxxx/domains/xxx.xxxx/xxxxx/ap_bot_credentials.php';


function getDbConnection() {
    static $conn = null;

    if ($conn === null || !$conn->ping()) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            $conn->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $e) {
            if (function_exists('logMessage')) {
                logMessage("Database connection error: " . $e->getMessage(), "ERROR");
            } else {
                error_log("Database connection error: " . $e->getMessage());
            }
            return null;
        }
    }
    return $conn;
}


function ensureUserExists($userId, $username, $firstName) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $now = date('Y-m-d H:i:s');
    $username = $username ?: '';
    $firstName = $firstName ?: '';


    $stmt = $conn->prepare("UPDATE users SET username = ?, first_name = ?, last_active_timestamp = ? WHERE user_id = ?");
    $stmt->bind_param("sssi", $username, $firstName, $now, $userId);
    $stmt->execute();
    $affected_rows = $stmt->affected_rows;
    $stmt->close();

    if ($affected_rows == 0) {
        $stmt_insert = $conn->prepare("INSERT INTO users (user_id, username, first_name, points, last_active_timestamp, joined_date) VALUES (?, ?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE username=VALUES(username), first_name=VALUES(first_name), last_active_timestamp=VALUES(last_active_timestamp)");
        $stmt_insert->bind_param("issss", $userId, $username, $firstName, $now, $now);
        $stmt_insert->execute();
        $stmt_insert->close();
    }
    return getUserDetails($userId);
}


function transferPoints($senderId, $recipientId, $amount, $reasonPrefix = "Gift") {
    if ($senderId == $recipientId) {
        if (function_exists('logMessage')) {
            logMessage("User {$senderId} attempted to transfer points to themselves.", "WARNING");
        }
        return false;
    }
    if ($amount <= 0) {
         if (function_exists('logMessage')) {
            logMessage("User {$senderId} attempted to transfer non-positive amount: {$amount}.", "WARNING");
        }
        return false;
    }

    $conn = getDbConnection();
    if (!$conn) return false;

    $conn->begin_transaction();
    try {
        $stmt_check = $conn->prepare("SELECT points, username, first_name FROM users WHERE user_id = ? FOR UPDATE");
        $stmt_check->bind_param("i", $senderId);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $sender = $result_check->fetch_assoc();
        $stmt_check->close();

        if (!$sender) {
            throw new Exception("Sender {$senderId} not found.");
        }
        if ($sender['points'] < $amount) {
            throw new Exception("Sender {$senderId} has insufficient points (has {$sender['points']}, needs {$amount}).");
        }
        
        $stmt_rec_check = $conn->prepare("SELECT username, first_name FROM users WHERE user_id = ?");
        $stmt_rec_check->bind_param("i", $recipientId);
        $stmt_rec_check->execute();
        $result_rec_check = $stmt_rec_check->get_result();
        $recipient = $result_rec_check->fetch_assoc();
        $stmt_rec_check->close();

        if (!$recipient) {
             throw new Exception("Recipient {$recipientId} not found.");
        }
        $recipientName = $recipient['first_name'] ?? "User {$recipientId}";
        $senderName = $sender['first_name'] ?? "User {$senderId}";
        $stmt_remove = $conn->prepare("UPDATE users SET points = points - ? WHERE user_id = ?");
        $stmt_remove->bind_param("ii", $amount, $senderId);
        $stmt_remove->execute();
        $stmt_remove->close();
        $stmt_add = $conn->prepare("UPDATE users SET points = points + ? WHERE user_id = ?");
        $stmt_add->bind_param("ii", $amount, $recipientId);
        $stmt_add->execute();
        $stmt_add->close();
        $now = date('Y-m-d H:i:s');
        $reasonSender = "{$reasonPrefix} sent to {$recipientName} ({$recipientId})";
        $reasonRecipient = "{$reasonPrefix} received from {$senderName} ({$senderId})";
        $changeAmountSender = -$amount;
        $changeAmountRecipient = $amount;

        $stmt_log_sender = $conn->prepare("INSERT INTO transactions (user_id, change_amount, reason, transaction_date) VALUES (?, ?, ?, ?)");
        $stmt_log_sender->bind_param("iiss", $senderId, $changeAmountSender, $reasonSender, $now);
        $stmt_log_sender->execute();
        $stmt_log_sender->close();

        $stmt_log_recipient = $conn->prepare("INSERT INTO transactions (user_id, change_amount, reason, transaction_date) VALUES (?, ?, ?, ?)");
        $stmt_log_recipient->bind_param("iiss", $recipientId, $changeAmountRecipient, $reasonRecipient, $now);
        $stmt_log_recipient->execute();
        $stmt_log_recipient->close();
        $conn->commit();
        if (function_exists('logMessage')) {
            logMessage("Successfully transferred {$amount} AP from user {$senderId} to user {$recipientId}.", "INFO");
        }
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        if (function_exists('logMessage')) {
            logMessage("Failed to transfer points from {$senderId} to {$recipientId}: " . $e->getMessage(), "ERROR");
        }
        return false;
    }
}


function getUserDetails($userId) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT user_id, username, first_name, points, last_active_timestamp, joined_date FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user;
}


function addPoints($userId, $pointsToAdd, $reason, $adminId = null) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $conn->begin_transaction();
    try {
        $stmt_user = $conn->prepare("UPDATE users SET points = points + ? WHERE user_id = ?");
        $stmt_user->bind_param("ii", $pointsToAdd, $userId);
        $stmt_user->execute();
        if ($stmt_user->affected_rows == 0) {
            throw new Exception("User not found or no points changed for User ID: " . $userId . ". Was ensureUserExists called?");
        }
        $stmt_user->close();
        $now = date('Y-m-d H:i:s');
        $stmt_trans = $conn->prepare("INSERT INTO transactions (user_id, admin_id, change_amount, reason, transaction_date) VALUES (?, ?, ?, ?, ?)");

        if ($adminId === null) {
            $stmt_trans->bind_param("isiss", $userId, $adminId, $pointsToAdd, $reason, $now);
        } else {
            $stmt_trans->bind_param("iiiis", $userId, $adminId, $pointsToAdd, $reason, $now);
        }
        $stmt_trans->execute();
        $stmt_trans->close();
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        if (function_exists('logMessage')) {
            logMessage("Error adding points for User {$userId}: " . $e->getMessage(), "ERROR");
        }
        return false;
    }
}


function removePointsDb($userId, $pointsToRemove, $reason, $adminId = null) {
    $conn = getDbConnection();
    if (!$conn) return false;
    $conn->begin_transaction();
    try {
        $stmt_check = $conn->prepare("SELECT points FROM users WHERE user_id = ? FOR UPDATE");
        $stmt_check->bind_param("i", $userId);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $currentUser = $result_check->fetch_assoc();
        $stmt_check->close();

        if (!$currentUser) {
            throw new Exception("User {$userId} not found.");
        }
        if ($currentUser['points'] < $pointsToRemove) {
             if (function_exists('logMessage')) {
                logMessage("Insufficient points to deduct for User {$userId} (has {$currentUser['points']}, needs {$pointsToRemove}).", "WARNING");
            }
            $conn->rollback();
            return false;
        }

        $stmt_user = $conn->prepare("UPDATE users SET points = points - ? WHERE user_id = ?");
        $stmt_user->bind_param("ii", $pointsToRemove, $userId);
        $stmt_user->execute();
        $stmt_user->close();
        $changeAmount = -$pointsToRemove;
        $now = date('Y-m-d H:i:s');
        $stmt_trans = $conn->prepare("INSERT INTO transactions (user_id, admin_id, change_amount, reason, transaction_date) VALUES (?, ?, ?, ?, ?)");
        if ($adminId === null) {
            $stmt_trans->bind_param("isiss", $userId, $adminId, $changeAmount, $reason, $now);
        } else {
             $stmt_trans->bind_param("iiiis", $userId, $adminId, $changeAmount, $reason, $now);
        }
        $stmt_trans->execute();
        $stmt_trans->close();
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        if (function_exists('logMessage')) {
            logMessage("Error deducting points for User {$userId}: " . $e->getMessage(), "ERROR");
        }
        return false;
    }
}


function createWithdrawalRequest($userId, $pointsRequested) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $now = date('Y-m-d H:i:s');
    $status = 'pending';

    $stmt = $conn->prepare("INSERT INTO withdrawals (user_id, points_requested, status, request_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $userId, $pointsRequested, $status, $now);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $stmt->close();
        return $newId;
    } else {
        if (function_exists('logMessage')) {
            logMessage("Error creating withdrawal request for User {$userId}: " . $stmt->error, "ERROR");
        }
        $stmt->close();
        return false;
    }
}


function getWithdrawalRequest($withdrawalId) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT w.*, u.username, u.first_name FROM withdrawals w JOIN users u ON w.user_id = u.user_id WHERE w.withdrawal_id = ?");
    $stmt->bind_param("i", $withdrawalId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();
    return $request;
}


function updateWithdrawalStatus($withdrawalId, $status, $adminId) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE withdrawals SET status = ?, processed_by_admin_id = ?, processed_date = ? WHERE withdrawal_id = ?");
    $stmt->bind_param("sisi", $status, $adminId, $now, $withdrawalId);
    $success = $stmt->execute();
    if (!$success) {
        if (function_exists('logMessage')) {
            logMessage("Error updating withdrawal status for ID {$withdrawalId}: " . $stmt->error, "ERROR");
        }
    }
    $stmt->close();
    return $success;
}


function getPendingWithdrawalByUserId($userId) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $stmt = $conn->prepare("SELECT * FROM withdrawals WHERE user_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();
    return $request;
}



function getTopUsers($limit = 10) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $users = [];
    $stmt = $conn->prepare("SELECT user_id, first_name, username, points FROM users WHERE points > 0 ORDER BY points DESC LIMIT ?");
    $stmt->bind_param("i", $limit);

    try {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if (function_exists('logMessage')) {
            logMessage("Database error in getTopUsers: " . $e->getMessage(), "ERROR");
        }
        return null;
    }

    return $users;
}



function getUserTransactions($userId, $limit = 10) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $transactions = [];
    $stmt = $conn->prepare("SELECT transaction_date, change_amount, reason FROM transactions WHERE user_id = ? ORDER BY transaction_date DESC LIMIT ?");
    $stmt->bind_param("ii", $userId, $limit);

    try {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if (function_exists('logMessage')) {
            logMessage("Database error in getUserTransactions for user {$userId}: " . $e->getMessage(), "ERROR");
        }
        return null;
    }

    return $transactions;
}


function canClaimDailyReward($userId) {
    $conn = getDbConnection();
    if (!$conn) return false; 
    $stmt = $conn->prepare("SELECT last_daily_claim FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        logMessage("canClaimDailyReward: User {$userId} not found in DB.", "WARNING");
        return false;
    }

    $today = date('Y-m-d');

    if ($user['last_daily_claim'] === null || $user['last_daily_claim'] < $today) {
        return true;
    } else {
        logMessage("User {$userId} already claimed daily reward today ({$user['last_daily_claim']}).", "DEBUG");
        return false;
    }
}


function updateLastDailyClaim($userId) {
    $conn = getDbConnection();
    if (!$conn) return false;

    $today = date('Y-m-d');
    $stmt = $conn->prepare("UPDATE users SET last_daily_claim = ? WHERE user_id = ?");
    $stmt->bind_param("si", $today, $userId);
    $success = $stmt->execute();
    if (!$success) {
        logMessage("Failed to update last_daily_claim for user {$userId}: " . $stmt->error, "ERROR");
    }
    $stmt->close();
    return $success;
}


function getUserWithdrawalHistory($userId, $limit = 3) {
    $conn = getDbConnection();
    if (!$conn) return null;

    $withdrawals = [];
    $stmt = $conn->prepare("SELECT withdrawal_id, points_requested, status, request_date, processed_date, processed_by_admin_id 
                           FROM withdrawals 
                           WHERE user_id = ? 
                           ORDER BY request_date DESC 
                           LIMIT ?");
    $stmt->bind_param("ii", $userId, $limit);

    try {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $withdrawals[] = $row;
        }
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if (function_exists('logMessage')) {
            logMessage("Database error in getUserWithdrawalHistory for user {$userId}: " . $e->getMessage(), "ERROR");
        }
        return null;
    }
    return $withdrawals;
}


?>
