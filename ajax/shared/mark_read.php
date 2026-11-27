<?php
session_start();
require_once '../../includes/config.php';

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

if ($user_type === 'tenant') {
    // Mark all requests for this tenant as read
    // Find the tenant_id first
    $tenantStmt = mysqli_prepare($conn, "SELECT tenant_id FROM tenant WHERE user_id = ?");
    mysqli_stmt_bind_param($tenantStmt, 'i', $user_id);
    mysqli_stmt_execute($tenantStmt);
    $tenantRes = mysqli_stmt_get_result($tenantStmt);
    if ($t = mysqli_fetch_assoc($tenantRes)) {
        $tenant_id = $t['tenant_id'];
        $updateStmt = mysqli_prepare($conn, "UPDATE interest_request SET tenant_is_read = 1 WHERE tenant_id = ? AND tenant_is_read = 0");
        mysqli_stmt_bind_param($updateStmt, 'i', $tenant_id);
        mysqli_stmt_execute($updateStmt);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Tenant not found']);
    }
} elseif ($user_type === 'landlord') {
    // Mark all requests for this landlord as read
    $landlordStmt = mysqli_prepare($conn, "SELECT landlord_id FROM landlord WHERE user_id = ?");
    mysqli_stmt_bind_param($landlordStmt, 'i', $user_id);
    mysqli_stmt_execute($landlordStmt);
    $landlordRes = mysqli_stmt_get_result($landlordStmt);
    if ($l = mysqli_fetch_assoc($landlordRes)) {
        $landlord_id = $l['landlord_id'];
        // Update where house belongs to landlord
        $updateStmt = mysqli_prepare($conn, "
            UPDATE interest_request ir
            JOIN house h ON ir.house_id = h.house_id
            SET ir.landlord_is_read = 1
            WHERE h.landlord_id = ? AND ir.landlord_is_read = 0
        ");
        mysqli_stmt_bind_param($updateStmt, 'i', $landlord_id);
        mysqli_stmt_execute($updateStmt);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Landlord not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid user type']);
}
