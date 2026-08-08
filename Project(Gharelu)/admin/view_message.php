<?php
// admin/view_message.php - View single message details

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

// Get message details
$stmt = $conn->prepare("SELECT * FROM contact_messages WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$message = $result->fetch_assoc();

if (!$message) {
    header('Location: contact_messages.php');
    exit();
}

// Mark as read if unread
if ($message['status'] === 'unread') {
    $updateStmt = $conn->prepare("UPDATE contact_messages SET status = 'read' WHERE id = ?");
    $updateStmt->bind_param("i", $id);
    $updateStmt->execute();
    $updateStmt->close();
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Message - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f7fafc;
            padding: 2rem;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .back-link {
            display: inline-block;
            margin-bottom: 2rem;
            color: var(--primary-color, #2c5f8a);
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .message-header h1 {
            font-size: 1.5rem;
            color: #2d3748;
        }
        .status-badge {
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
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
        .message-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            background: #f7fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .meta-item label {
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #4a5568;
            display: block;
            margin-bottom: 0.25rem;
        }
        .meta-item .value {
            font-size: 1rem;
            color: #2d3748;
        }
        .message-content {
            margin-bottom: 2rem;
        }
        .message-content h3 {
            font-size: 1rem;
            color: #4a5568;
            margin-bottom: 0.75rem;
        }
        .message-content .content-box {
            background: #f7fafc;
            padding: 1.5rem;
            border-radius: 8px;
            line-height: 1.8;
            color: #2d3748;
            white-space: pre-wrap;
        }
        .reply-section {
            border-top: 2px solid #e2e8f0;
            padding-top: 2rem;
            margin-top: 2rem;
        }
        .reply-section h3 {
            margin-bottom: 1rem;
            color: #2d3748;
        }
        .reply-form textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: inherit;
            min-height: 120px;
            transition: border-color 0.3s;
        }
        .reply-form textarea:focus {
            outline: none;
            border-color: #2c5f8a;
        }
        .reply-form .btn-submit {
            margin-top: 1rem;
            padding: 0.75rem 2rem;
            background: #2c5f8a;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .reply-form .btn-submit:hover {
            background: #1e3d59;
        }
        .existing-reply {
            background: #f0fff4;
            border-left: 4px solid #38a169;
            padding: 1rem 1.5rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        .existing-reply .reply-label {
            font-weight: 600;
            color: #22543d;
            margin-bottom: 0.5rem;
        }
        .existing-reply .reply-text {
            color: #2d3748;
            white-space: pre-wrap;
        }
        .btn-danger {
            padding: 0.5rem 1.5rem;
            background: #e53e3e;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s;
        }
        .btn-danger:hover {
            background: #c53030;
        }
        .actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="contact_messages.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Messages
        </a>

        <div class="message-header">
            <h1><?php echo htmlspecialchars($message['subject']); ?></h1>
            <span class="status-badge status-<?php echo $message['status']; ?>">
                <?php echo ucfirst($message['status']); ?>
            </span>
        </div>

        <div class="message-meta">
            <div class="meta-item">
                <label>From</label>
                <div class="value">
                    <strong><?php echo htmlspecialchars($message['name']); ?></strong>
                    <br>
                    <a href="mailto:<?php echo htmlspecialchars($message['email']); ?>">
                        <?php echo htmlspecialchars($message['email']); ?>
                    </a>
                </div>
            </div>
            <div class="meta-item">
                <label>Received</label>
                <div class="value">
                    <?php echo date('F d, Y \a\t h:i A', strtotime($message['created_at'])); ?>
                </div>
            </div>
        </div>

        <div class="message-content">
            <h3><i class="fas fa-comment"></i> Message</h3>
            <div class="content-box">
                <?php echo nl2br(htmlspecialchars($message['message'])); ?>
            </div>
        </div>

        <?php if (!empty($message['admin_reply'])): ?>
            <div class="existing-reply">
                <div class="reply-label">
                    <i class="fas fa-reply"></i> Reply Sent
                </div>
                <div class="reply-text">
                    <?php echo nl2br(htmlspecialchars($message['admin_reply'])); ?>
                </div>
                <?php if ($message['replied_at']): ?>
                    <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #4a5568;">
                        Sent on <?php echo date('F d, Y \a\t h:i A', strtotime($message['replied_at'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="reply-section">
            <h3><i class="fas fa-reply"></i> Reply to <?php echo htmlspecialchars($message['name']); ?></h3>
            <form class="reply-form" action="send_reply.php" method="POST">
                <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($message['email']); ?>">
                <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($message['name']); ?>">
                <textarea name="reply_message" placeholder="Type your reply here..." required></textarea>
                <div class="actions">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                    <a href="delete_message.php?id=<?php echo $message['id']; ?>" 
                       class="btn-danger" 
                       onclick="return confirm('Are you sure you want to delete this message?')">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>