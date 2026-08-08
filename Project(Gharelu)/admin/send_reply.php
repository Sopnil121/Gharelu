<?php
// admin/send_reply.php - Process and save admin reply

require_once '../config.php';

// Authentication check
// session_start();
// if (!isset($_SESSION['admin_logged_in'])) {
//     header('Location: login.php');
//     exit();
// }

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    $reply = isset($_POST['reply_message']) ? sanitizeInput($_POST['reply_message']) : '';
    $user_email = isset($_POST['user_email']) ? sanitizeInput($_POST['user_email']) : '';
    $user_name = isset($_POST['user_name']) ? sanitizeInput($_POST['user_name']) : '';
    
    if ($message_id <= 0 || empty($reply)) {
        header('Location: view_message.php?id=' . $message_id . '&error=empty');
        exit();
    }
    
    $conn = getDBConnection();
    
    // Update message with reply
    $stmt = $conn->prepare("UPDATE contact_messages SET admin_reply = ?, status = 'replied', replied_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $reply, $message_id);
    
    if ($stmt->execute()) {
        // Here you would also send an email to the user
        // mail($user_email, "Reply to your message: " . $subject, $reply);
        
        // Redirect with success
        header('Location: view_message.php?id=' . $message_id . '&success=replied');
        exit();
    } else {
        header('Location: view_message.php?id=' . $message_id . '&error=db');
        exit();
    }
    
    $stmt->close();
    $conn->close();
} else {
    header('Location: contact_messages.php');
    exit();
}
?>