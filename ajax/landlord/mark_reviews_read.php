<?php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id']) || $_SESSION['user_type'] !== 'landlord') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$conn = db_connect();

// Get the landlord ID for this user
$stmt = mysqli_prepare($conn, 'SELECT landlord_id FROM landlord WHERE user_id = ?');
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $landlordId = $row['landlord_id'];
    }
    mysqli_stmt_close($stmt);
}

if (empty($landlordId)) {
    echo json_encode(['success' => false, 'error' => 'Landlord not found']);
    exit;
}

// Mark all unread reviews as read for this landlord
// A review notification corresponds to an interest_request that has request_status = 'leased'
$updateSql = "UPDATE interest_request ir 
              JOIN house h ON ir.house_id = h.house_id 
              SET ir.landlord_review_is_read = 1 
              WHERE h.landlord_id = ? 
              AND ir.request_status = 'leased' 
              AND ir.landlord_review_is_read = 0";

$updateStmt = mysqli_prepare($conn, $updateSql);
if ($updateStmt) {
    mysqli_stmt_bind_param($updateStmt, 'i', $landlordId);
    mysqli_stmt_execute($updateStmt);
    $affected = mysqli_stmt_affected_rows($updateStmt);
    mysqli_stmt_close($updateStmt);
    echo json_encode(['success' => true, 'affected' => $affected]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to prepare statement']);
}

mysqli_close($conn);
