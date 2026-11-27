<?php
// contact_replies/view_message.php - View single message details
require_once __DIR__ . '/../../includes/config.php';

// Authentication check
require_role('admin');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: ../messages.php');
    exit();
}

$conn = db_connect();

// Get message details
$stmt = mysqli_prepare($conn, "SELECT * FROM contact_messages WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$message = mysqli_fetch_assoc($result);

if (!$message) {
    mysqli_close($conn);
    header('Location: ../messages.php');
    exit();
}

// Mark as read if unread
if ($message['status'] === 'unread') {
    $updateStmt = mysqli_prepare($conn, "UPDATE contact_messages SET status = 'read' WHERE id = ?");
    mysqli_stmt_bind_param($updateStmt, "i", $id);
    mysqli_stmt_execute($updateStmt);
    mysqli_stmt_close($updateStmt);
    $message['status'] = 'read'; // Update status locally for display
}

mysqli_stmt_close($stmt);

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Message - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
</head>
<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="../dashboard.php">GHARELU</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="../dashboard.php">
                        <i class="fas fa-chart-line me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../users.php">
                        <i class="fas fa-users me-1"></i>Manage Users
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../listings.php">
                        <i class="fas fa-home me-1"></i>Manage Listings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../reviews.php">
                        <i class="fas fa-star me-1"></i>Manage Reviews
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="../messages.php">
                        <i class="fas fa-envelope me-1"></i>Messages
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../../profile.php">
                        <i class="fas fa-user me-1"></i>Profile
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../../logout.php">
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
            <a href="../messages.php" class="btn btn-outline-secondary mb-3">
                <i class="fas fa-arrow-left me-1"></i> Back to Messages
            </a>
        </div>
    </div>
</div>

<!-- Notice Messages -->
<?php if ($success === 'replied'): ?>
    <div class="container">
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i> Reply saved successfully and status updated to Replied.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>
<?php if ($error === 'empty'): ?>
    <div class="container">
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> Reply message cannot be empty.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>
<?php if ($error === 'db'): ?>
    <div class="container">
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i> Error updating database. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<!-- Message Detail Section -->
<div class="container">
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-envelope-open me-2"></i>Inquiry Details</h5>
                    <span>
                        <?php if ($message['status'] === 'unread'): ?>
                            <span class="badge bg-warning text-dark">Unread</span>
                        <?php elseif ($message['status'] === 'read'): ?>
                            <span class="badge bg-info text-dark">Read</span>
                        <?php else: ?>
                            <span class="badge bg-success">Replied</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-sm-3 text-muted fw-bold">From:</div>
                        <div class="col-sm-9">
                            <?php echo htmlspecialchars($message['name']); ?> 
                            (<a href="mailto:<?php echo htmlspecialchars($message['email']); ?>"><?php echo htmlspecialchars($message['email']); ?></a>)
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 text-muted fw-bold">Subject:</div>
                        <div class="col-sm-9 fw-bold">
                            <?php echo htmlspecialchars($message['subject']); ?>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 text-muted fw-bold">Received:</div>
                        <div class="col-sm-9">
                            <?php echo date('F d, Y h:i A', strtotime($message['created_at'])); ?>
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold">Message:</label>
                        <div class="p-3 bg-light rounded whitespace-pre-wrap" style="white-space: pre-wrap; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Reply Section -->
            <?php if (!empty($message['admin_reply'])): ?>
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-reply me-2"></i>Admin Response</h5>
                    </div>
                    <div class="card-body">
                        <div class="p-3 bg-light rounded mb-2" style="white-space: pre-wrap; line-height: 1.6;">
                            <?php echo nl2br(htmlspecialchars($message['admin_reply'])); ?>
                        </div>
                        <?php if ($message['replied_at']): ?>
                            <div class="text-end text-muted style-italic">
                                <small>Replied on: <?php echo date('F d, Y h:i A', strtotime($message['replied_at'])); ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Reply Form Section -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="fas fa-comment-dots me-2"></i>Send / Update Reply</h5>
                </div>
                <div class="card-body">
                    <form action="send_reply.php" method="POST">
                        <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                        <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($message['email']); ?>">
                        <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($message['name']); ?>">
                        
                        <div class="mb-3">
                            <label for="reply_message" class="form-label">Response Text:</label>
                            <textarea class="form-control" id="reply_message" name="reply_message" rows="6" placeholder="Type the answer/reply that will be displayed on the landing page..." required><?php echo htmlspecialchars($message['admin_reply'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-paper-plane me-1"></i> Submit Reply
                            </button>
                            <a href="delete_message.php?id=<?php echo $message['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this message?');">
                                <i class="fas fa-trash me-1"></i> Delete Message
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Sidebar Metadata -->
        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body">
                    <h5 class="card-title text-dark mb-3"><i class="fas fa-info-circle me-1"></i>Inquiry Meta</h5>
                    <ul class="list-group list-group-flush bg-transparent">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            <span>Status:</span>
                            <span>
                                <?php if ($message['status'] === 'unread'): ?>
                                    <span class="badge bg-warning text-dark">Unread</span>
                                <?php elseif ($message['status'] === 'read'): ?>
                                    <span class="badge bg-info text-dark">Read</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Replied</span>
                                <?php endif; ?>
                            </span>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            <span>Date Created:</span>
                            <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($message['created_at'])); ?></small>
                        </li>
                        <?php if ($message['replied_at']): ?>
                            <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                                <span>Date Replied:</span>
                                <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($message['replied_at'])); ?></small>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
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
mysqli_close($conn);
?>
