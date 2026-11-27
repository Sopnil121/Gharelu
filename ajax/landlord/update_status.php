<?php
require_once '../../includes/config.php';

header('Content-Type: application/json');

$conn = db_connect();

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'landlord') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['house_id']) || !isset($input['status'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$house_id = intval($input['house_id']);
$status = strtolower(trim($input['status']));

if (!in_array($status, ['available', 'pending', 'rented'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

// Get landlord_id
$landlordStmt = mysqli_prepare($conn, "SELECT landlord_id FROM landlord WHERE user_id = ?");
mysqli_stmt_bind_param($landlordStmt, 'i', $user_id);
mysqli_stmt_execute($landlordStmt);
$landlordRes = mysqli_stmt_get_result($landlordStmt);

if ($l = mysqli_fetch_assoc($landlordRes)) {
    $landlord_id = $l['landlord_id'];
    
    // Update status
    $updateStmt = mysqli_prepare($conn, "UPDATE house SET availability_status = ? WHERE house_id = ? AND landlord_id = ?");
    mysqli_stmt_bind_param($updateStmt, 'sii', $status, $house_id, $landlord_id);
    mysqli_stmt_execute($updateStmt);
    
    if (mysqli_stmt_affected_rows($updateStmt) > 0 || mysqli_stmt_errno($updateStmt) == 0) {
        // Also update any pending requests for this house to 'declined' if it was rented?
        // Let's just update the status for now as requested.
        echo json_encode(['success' => true, 'message' => 'Listing status updated successfully', 'status' => ucfirst($status)]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unable to update listing status']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Landlord not found']);
}
