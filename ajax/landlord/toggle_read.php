<?php
require_once '../../includes/config.php';
$conn = db_connect();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_type = $_SESSION['user_type'] ?? '';
$user_id = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['request_id']) || !isset($input['is_read'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$request_id = intval($input['request_id']);
$is_read = intval($input['is_read']) === 1 ? 1 : 0;

if ($user_type === 'landlord') {
    // Verify landlord owns the house this request is for
    $landlordStmt = mysqli_prepare($conn, "SELECT landlord_id FROM landlord WHERE user_id = ?");
    mysqli_stmt_bind_param($landlordStmt, 'i', $user_id);
    mysqli_stmt_execute($landlordStmt);
    $landlordRes = mysqli_stmt_get_result($landlordStmt);
    
    if ($l = mysqli_fetch_assoc($landlordRes)) {
        $landlord_id = $l['landlord_id'];
        
        $updateStmt = mysqli_prepare($conn, "
            UPDATE interest_request ir
            JOIN house h ON ir.house_id = h.house_id
            SET ir.landlord_is_read = ?
            WHERE ir.request_id = ? AND h.landlord_id = ?
        ");
        mysqli_stmt_bind_param($updateStmt, 'iii', $is_read, $request_id, $landlord_id);
        mysqli_stmt_execute($updateStmt);
        
        if (mysqli_stmt_affected_rows($updateStmt) > 0 || mysqli_stmt_errno($updateStmt) == 0) {
            // Recalculate total unread
            $countStmt = mysqli_prepare($conn, "
                SELECT COUNT(*) as unread
                FROM interest_request ir
                JOIN house h ON ir.house_id = h.house_id
                WHERE h.landlord_id = ? AND ir.landlord_is_read = 0
            ");
            mysqli_stmt_bind_param($countStmt, 'i', $landlord_id);
            mysqli_stmt_execute($countStmt);
            $countRes = mysqli_stmt_get_result($countStmt);
            $count = mysqli_fetch_assoc($countRes)['unread'];
            
            echo json_encode(['success' => true, 'unread_count' => $count]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No changes made or permission denied']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Landlord not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Only landlords can toggle these requests via this endpoint currently']);
}
