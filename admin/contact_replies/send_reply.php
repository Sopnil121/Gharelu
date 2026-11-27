<?php
// contact_replies/send_reply.php - Process and save admin reply
require_once __DIR__ . '/../../includes/config.php';

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
    
    // Fetch the user's email and details
    $fetch_stmt = mysqli_prepare($conn, "SELECT name, email, subject, message FROM contact_messages WHERE id = ?");
    mysqli_stmt_bind_param($fetch_stmt, "i", $message_id);
    mysqli_stmt_execute($fetch_stmt);
    $result = mysqli_stmt_get_result($fetch_stmt);
    $msg_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($fetch_stmt);

    if ($msg_data) {
        // Send Email to the user
        $to = $msg_data['email'];
        $subject = "RE: " . $msg_data['subject'] . " (Gharelu Support)";
        
        $email_message = "Dear " . $msg_data['name'] . ",\n\n";
        $email_message .= "Thank you for contacting Gharelu Support. Below is the response to your message:\n\n";
        $email_message .= "----------------------------------------\n";
        $email_message .= $reply . "\n";
        $email_message .= "----------------------------------------\n\n";
        $email_message .= "Your Original Message:\n";
        $email_message .= "> " . str_replace("\n", "\n> ", $msg_data['message']) . "\n\n";
        $email_message .= "Best regards,\nThe Gharelu Team\nadmin@gharelu.com";
        
        $headers = "From: admin@gharelu.com\r\n";
        $headers .= "Reply-To: admin@gharelu.com\r\n";
        
        // Use PHP's built-in mail function (requires server config to actually deliver)
        @mail($to, $subject, $email_message, $headers);
    }
    
    // Update message with reply in database
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
    header('Location: ../messages.php');
    exit();
}
?>
