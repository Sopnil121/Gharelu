<?php
require_once '../../includes/config.php';
$conn = db_connect();

header('Content-Type: application/json');

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
if (!isset($input['request_id']) || !isset($input['house_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$request_id = intval($input['request_id']);
$house_id = intval($input['house_id']);

// Verify landlord owns the house
$landlordStmt = mysqli_prepare($conn, "SELECT landlord_id FROM landlord WHERE user_id = ?");
mysqli_stmt_bind_param($landlordStmt, 'i', $user_id);
mysqli_stmt_execute($landlordStmt);
$landlordRes = mysqli_stmt_get_result($landlordStmt);

if ($l = mysqli_fetch_assoc($landlordRes)) {
    $landlord_id = $l['landlord_id'];
    
    // Verify house ownership
    $houseStmt = mysqli_prepare($conn, "SELECT house_id FROM house WHERE house_id = ? AND landlord_id = ?");
    mysqli_stmt_bind_param($houseStmt, 'ii', $house_id, $landlord_id);
    mysqli_stmt_execute($houseStmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($houseStmt))) {
        echo json_encode(['success' => false, 'error' => 'House not found or permission denied']);
        exit;
    }
    
    mysqli_begin_transaction($conn);
    try {
        // 1. Mark this specific request as leased
        $stmt1 = mysqli_prepare($conn, "UPDATE interest_request SET request_status = 'leased', tenant_is_read = 0 WHERE request_id = ? AND house_id = ?");
        mysqli_stmt_bind_param($stmt1, 'ii', $request_id, $house_id);
        mysqli_stmt_execute($stmt1);
        
        // 2. Mark the house as rented
        $stmt2 = mysqli_prepare($conn, "UPDATE house SET availability_status = 'rented' WHERE house_id = ? AND landlord_id = ?");
        mysqli_stmt_bind_param($stmt2, 'ii', $house_id, $landlord_id);
        mysqli_stmt_execute($stmt2);
        
        // 3. Decline any other pending or accepted requests for this house
        $stmt3 = mysqli_prepare($conn, "UPDATE interest_request SET request_status = 'declined', tenant_is_read = 0 WHERE house_id = ? AND request_id != ? AND request_status IN ('pending', 'accepted')");
        mysqli_stmt_bind_param($stmt3, 'ii', $house_id, $request_id);
        mysqli_stmt_execute($stmt3);
        
        mysqli_commit($conn);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'error' => 'Transaction failed: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Landlord not found']);
}
