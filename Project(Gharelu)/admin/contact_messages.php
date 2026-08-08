<?php
// admin/contact_messages.php - Admin panel to view messages

require_once '../config.php';

// Authentication check (you'll need to implement this)
// session_start();
// if (!isset($_SESSION['admin_logged_in'])) {
//     header('Location: login.php');
//     exit();
// }

$conn = getDBConnection();

// Get all messages
$sql = "SELECT * FROM contact_messages ORDER BY created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Contact Messages</title>
    <link rel="stylesheet" href="../style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background: #f7fafc;
            padding: 2rem;
        }
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
        }
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .admin-header h1 {
            font-size: 2rem;
            color: var(--text-dark);
        }
        .admin-header .stats {
            display: flex;
            gap: 2rem;
        }
        .admin-header .stat-item {
            text-align: center;
        }
        .admin-header .stat-item .number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .admin-header .stat-item .label {
            font-size: 0.8rem;
            color: var(--text-light);
        }
        .table-responsive {
            overflow-x: auto;
        }
        .message-preview {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-view {
            background: var(--primary-color);
            color: white;
        }
        .btn-view:hover {
            background: var(--primary-dark);
        }
        .btn-reply {
            background: #38a169;
            color: white;
        }
        .btn-reply:hover {
            background: #2f855a;
        }
        .btn-delete {
            background: #e53e3e;
            color: white;
        }
        .btn-delete:hover {
            background: #c53030;
        }
        .btn-small {
            padding: 0.3rem 0.75rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.75rem;
            display: inline-block;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-unread {
            background: #fefcbf;
            color: #744210;
        }
        .status-read {
            background: #bee3f8;
            color: #2a4365;
        }
        .status-replied {
            background: #c6f6d5;
            color: #22543d;
        }
        .no-messages {
            text-align: center;
            padding: 4rem;
            color: var(--text-light);
        }
        .no-messages i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #e2e8f0;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 1rem;
            color: var(--primary-color);
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <a href="../index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Site
        </a>
        
        <div class="admin-header">
            <h1><i class="fas fa-envelope"></i> Contact Messages</h1>
            <?php 
            // Get stats
            $total = $conn->query("SELECT COUNT(*) as count FROM contact_messages")->fetch_assoc()['count'];
            $unread = $conn->query("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'unread'")->fetch_assoc()['count'];
            ?>
            <div class="stats">
                <div class="stat-item">
                    <div class="number"><?php echo $total; ?></div>
                    <div class="label">Total</div>
                </div>
                <div class="stat-item">
                    <div class="number"><?php echo $unread; ?></div>
                    <div class="label">Unread</div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Subject</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>#<?php echo $row['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['subject']); ?></td>
                                <td>
                                    <div class="message-preview">
                                        <?php echo htmlspecialchars(substr($row['message'], 0, 60)); ?>
                                        <?php if (strlen($row['message']) > 60): ?>...<?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $row['status']; ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view_message.php?id=<?php echo $row['id']; ?>" class="btn-small btn-view">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="reply_message.php?id=<?php echo $row['id']; ?>" class="btn-small btn-reply">
                                            <i class="fas fa-reply"></i>
                                        </a>
                                        <a href="delete_message.php?id=<?php echo $row['id']; ?>" class="btn-small btn-delete" onclick="return confirm('Are you sure you want to delete this message?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="no-messages">
                                    <i class="fas fa-inbox"></i>
                                    <h3>No messages yet</h3>
                                    <p>Contact form messages will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

<?php 
if ($result) {
    $result->free();
}
$conn->close(); 
?>