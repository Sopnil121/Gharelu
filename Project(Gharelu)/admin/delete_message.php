<?php
// admin/delete_message.php - Delete a contact message

require_once '../config.php';

// Authentication check
// session_start();
// if (!isset($_SESSION['admin_logged_in'])) {
//     header('Location: login.php');
//     exit();
// }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: contact_messages.php');
    exit();
}

$conn = getDBConnection();

// Delete the message$stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $_SESSION['admin_message'] = "Message deleted successfully!";
} else {
    $_SESSION['admin_error'] = "Error deleting message.";
}

$stmt->close();
$conn->close();

header('Location: contact_messages.php');
exit();
?>