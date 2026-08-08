<?php
// contact_replies/send_reply.php - Process and save admin reply
require_once '../config.php';

// Authentication check
require_role('admin');

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
    
    $conn = db_connect();
    
    $reply = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';
    
    if ($message_id <= 0 || empty($reply)) {
        mysqli_close($conn);
        header('Location: view_message.php?id=' . $message_id . '&error=empty');
        exit();
    }
    
    // Update message with reply
    $stmt = mysqli_prepare($conn, "UPDATE contact_messages SET admin_reply = ?, status = 'replied', replied_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $reply, $message_id);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        // Redirect with success
        header('Location: view_message.php?id=' . $message_id . '&success=replied');
        exit();
    } else {
        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        header('Location: view_message.php?id=' . $message_id . '&error=db');
        exit();
    }
} else {
    header('Location: contact_messages.php');
    exit();
}
?>
