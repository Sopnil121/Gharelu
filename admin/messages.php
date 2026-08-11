<?php
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

$conn = db_connect();

$notice = trim($_GET['notice'] ?? '');

// Handle status updates and deletes for inquiries
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        $id = intval($_POST['inquiry_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE contact_messages SET status = 'read' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        header("Location: messages.php?notice=Inquiry marked as read.");
        exit;
    } elseif ($action === 'delete_inquiry') {
        $id = intval($_POST['inquiry_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM contact_messages WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        header("Location: messages.php?notice=Inquiry deleted successfully.");
        exit;
    } elseif ($action === 'delete_interest') {
        $id = intval($_POST['request_id'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM interest_request WHERE request_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        header("Location: messages.php?notice=Interest request deleted.");
        exit;
    }
}

// Fetch inquiries (contact us messages)
$inquiries_res = mysqli_query($conn, "SELECT * FROM contact_messages ORDER BY created_at DESC");
$inquiries = [];
if ($inquiries_res) {
    while ($row = mysqli_fetch_assoc($inquiries_res)) {
        $inquiries[] = $row;
    }
}

// Fetch tenant interest requests with sender and recipient details
$interests_res = mysqli_query($conn, "
    SELECT ir.request_id, ir.message, ir.request_status, ir.requested_at, 
           h.title AS house_title, h.province AS house_city,
           ut.full_name AS tenant_name, ut.phone_number AS tenant_phone,
           ul.full_name AS landlord_name, ul.phone_number AS landlord_phone
    FROM interest_request ir
    JOIN house h ON ir.house_id = h.house_id
    JOIN tenant t ON ir.tenant_id = t.tenant_id
    JOIN users ut ON t.user_id = ut.id
    JOIN landlord l ON h.landlord_id = l.landlord_id
    JOIN users ul ON l.user_id = ul.id
    ORDER BY ir.requested_at DESC
");
$interests = [];
if ($interests_res) {
    while ($row = mysqli_fetch_assoc($interests_res)) {
        $interests[] = $row;
    }
}

mysqli_close($conn);

$active_tab = $_GET['tab'] ?? 'inquiries';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHARELU - Messages Console</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">GHARELU</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-chart-line me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users me-1"></i>Manage Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="listings.php">
                        <i class="fas fa-house me-1"></i>Manage Listings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="reviews.php">
                        <i class="fas fa-star me-1"></i>Manage Reviews
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="messages.php">
                        <i class="fas fa-envelope me-1"></i>Messages
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../profile.php">
                        <i class="fas fa-user me-1"></i>Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../logout.php">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Page Header -->
<div class="container mt-4">
    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h2><i class="fas fa-envelope-open-text me-2" style="color:#e8a045;"></i>System Messages & Inquiries</h2>
            <a class="btn btn-outline-dark" href="dashboard.php"><i class="fas fa-arrow-left me-1"></i>Back</a>
        </div>
    </div>
</div>

<!-- Notices -->
<?php if ($notice !== ''): ?>
    <div class="container mt-3">
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> <?php echo htmlspecialchars($notice); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<div class="container mt-4">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'inquiries' ? 'active bg-dark text-white' : 'text-dark'; ?>" href="messages.php?tab=inquiries">
                <i class="fas fa-envelope me-1"></i>User Inquiries (<?php echo count($inquiries); ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'interests' ? 'active bg-dark text-white' : 'text-dark'; ?>" href="messages.php?tab=interests">
                <i class="fas fa-handshake me-1"></i>Property Interest Requests (<?php echo count($interests); ?>)
            </a>
        </li>
    </ul>

    <!-- Tabs Content -->
    <div class="card border-top-0 rounded-bottom">
        <div class="card-body">
            <?php if ($active_tab === 'inquiries'): ?>
                <!-- INQUIRIES TAB -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Sender</th>
                                <th>Email</th>
                                <th>Subject</th>
                                <th>Message</th>
                                <th>Received At</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inquiries)): ?>
                                <?php foreach ($inquiries as $inq): ?>
                                    <tr>
                                        <td>#<?php echo $inq['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($inq['name']); ?></strong></td>
                                        <td><a href="mailto:<?php echo htmlspecialchars($inq['email']); ?>"><?php echo htmlspecialchars($inq['email']); ?></a></td>
                                        <td><?php echo htmlspecialchars($inq['subject']); ?></td>
                                        <td class="text-muted" style="max-width: 250px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?php echo htmlspecialchars($inq['message']); ?>">
                                            <?php echo htmlspecialchars($inq['message']); ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($inq['created_at'])); ?></td>
                                        <td>
                                            <?php if ($inq['status'] === 'unread'): ?>
                                                <span class="badge bg-warning text-dark"><i class="fas fa-envelope me-1"></i>Unread</span>
                                            <?php elseif ($inq['status'] === 'read'): ?>
                                                <span class="badge bg-info text-dark"><i class="fas fa-envelope-open me-1"></i>Read</span>
                                            <?php else: ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Replied</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <a href="contact_replies/view_message.php?id=<?php echo $inq['id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-reply me-1"></i>View/Reply
                                                </a>
                                                <?php if ($inq['status'] === 'unread'): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="mark_read">
                                                        <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                                                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-eye me-1"></i>Read</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this inquiry?');">
                                                    <input type="hidden" name="action" value="delete_inquiry">
                                                    <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No user inquiries found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- INTEREST REQUESTS TAB -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Sender (Tenant)</th>
                                <th>Recipient (Landlord)</th>
                                <th>Property/Listing</th>
                                <th>Message</th>
                                <th>Date/Time</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($interests)): ?>
                                <?php foreach ($interests as $req): ?>
                                    <tr>
                                        <td>#<?php echo $req['request_id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($req['tenant_name']); ?></strong><br>
                                            <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($req['tenant_phone']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($req['landlord_name']); ?></strong><br>
                                            <small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($req['landlord_phone']); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($req['house_title']); ?></strong><br>
                                            <small class="text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($req['house_city']); ?></small>
                                        </td>
                                        <td class="text-muted" style="max-width: 200px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?php echo htmlspecialchars($req['message']); ?>">
                                            "<?php echo htmlspecialchars($req['message']); ?>"
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($req['requested_at'])); ?></td>
                                        <td>
                                            <?php if ($req['request_status'] === 'pending'): ?>
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            <?php elseif ($req['request_status'] === 'accepted'): ?>
                                                <span class="badge bg-success">Accepted</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" onsubmit="return confirm('Delete this interest request record?');">
                                                <input type="hidden" name="action" value="delete_interest">
                                                <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No property interest requests found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container mt-5 mb-4">
    <div class="text-center text-muted">
        <small>&copy; 2026 GHARELU House Rental System. All rights reserved.</small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

