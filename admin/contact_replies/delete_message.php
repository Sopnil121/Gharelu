<?php
// contact_replies/delete_message.php - Delete a contact message
require_once __DIR__ . '/../../includes/config.php';

// Authentication check
require_role('admin');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: ../messages.php');
    exit();
}

$conn = db_connect();

// Delete the message
$stmt = mysqli_prepare($conn, "DELETE FROM contact_messages WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);

if (mysqli_stmt_execute($stmt)) {
    $notice = "Message deleted successfully!";
} else {
    $notice = "Error deleting message.";
}

mysqli_stmt_close($stmt);
mysqli_close($conn);

header('Location: ../messages.php?notice=' . urlencode($notice));
exit();
?>
