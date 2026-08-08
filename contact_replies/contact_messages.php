<?php
// contact_replies/contact_messages.php - Admin panel to view messages
require_once '../config.php';

// Authentication check
require_role('admin');

$conn = db_connect();

// Handle status filtering
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

if ($status_filter && in_array($status_filter, ['unread', 'read', 'replied'])) {
    $sql = "SELECT * FROM contact_messages WHERE status = '$status_filter' ORDER BY created_at DESC";
} else {
    $sql = "SELECT * FROM contact_messages ORDER BY created_at DESC";
}

$result = mysqli_query($conn, $sql);

$notice = trim($_GET['notice'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="../admin_dashboard.php">Gharelu</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="../admin_dashboard.php">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../manage_users.php">
                        <i class="fas fa-users me-1"></i>Manage Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../manage_listings.php">
                        <i class="fas fa-home me-1"></i>Manage Listings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../manage_reviews.php">
                        <i class="fas fa-star me-1"></i>Manage Reviews
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="contact_messages.php">
                        <i class="fas fa-envelope me-1"></i>Messages
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
        <div class="col-12">
            <h2 class="mb-4"><i class="fas fa-envelope-open-text me-2"></i>Contact Messages</h2>
        </div>
    </div>
</div>

<!-- Notice Message -->
<?php if ($notice !== ''): ?>
    <div class="container mt-3">
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($notice); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<!-- Main Content Area -->
<div class="container mt-4">
    <div class="card mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Inquiries</h5>
            <div>
                <!-- Filter Buttons -->
                <a href="contact_messages.php" class="btn btn-sm btn-outline-light <?php echo $status_filter === '' ? 'active' : ''; ?>">All</a>
                <a href="contact_messages.php?status=unread" class="btn btn-sm btn-outline-warning <?php echo $status_filter === 'unread' ? 'active' : ''; ?>">Unread</a>
                <a href="contact_messages.php?status=read" class="btn btn-sm btn-outline-info <?php echo $status_filter === 'read' ? 'active' : ''; ?>">Read</a>
                <a href="contact_messages.php?status=replied" class="btn btn-sm btn-outline-success <?php echo $status_filter === 'replied' ? 'active' : ''; ?>">Replied</a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Received At</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td><a href="mailto:<?php echo htmlspecialchars($row['email']); ?>"><?php echo htmlspecialchars($row['email']); ?></a></td>
                                    <td><?php echo htmlspecialchars($row['subject']); ?></td>
                                    <td class="text-muted text-truncate" style="max-width: 250px;">
                                        <?php echo htmlspecialchars($row['message']); ?>
                                    </td>
                                    <td>
                                        <?php if ($row['status'] === 'unread'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-envelope me-1"></i>Unread</span>
                                        <?php elseif ($row['status'] === 'read'): ?>
                                            <span class="badge bg-info text-dark"><i class="fas fa-envelope-open me-1"></i>Read</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Replied</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($row['created_at'])); ?></td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <a href="view_message.php?id=<?php echo $row['id']; ?>" class="btn btn-primary btn-sm" title="View & Reply">
                                                <i class="fas fa-reply me-1"></i>View/Reply
                                            </a>
                                            <a href="delete_message.php?id=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this message?');" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 text-secondary"></i>
                                    <h5>No messages found</h5>
                                    <p>Messages submitted through the Contact Us form will appear here.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="container mt-5 mb-4">
    <div class="text-center text-muted">
        <small>&copy; 2026 Gharelu House Rental System. All rights reserved.</small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
if ($result) {
    mysqli_free_result($result);
}
mysqli_close($conn);
?>
