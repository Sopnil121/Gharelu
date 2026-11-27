<?php
require_once __DIR__ . '/../includes/config.php';

require_role('admin');

$conn = db_connect();

$notice = trim($_GET['notice'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['user_id'] ?? 0);

    if ($action === 'approve_property' || $action === 'reject_property') {
        $houseId = intval($_POST['house_id'] ?? 0);
        if ($houseId > 0) {
            if ($action === 'approve_property') {
                $stmt = mysqli_prepare($conn, "UPDATE house SET admin_status = 'approved', admin_feedback = NULL WHERE house_id = ?");
                $notice = 'Property listing approved successfully.';
            } else {
                $feedback = trim($_POST['admin_feedback'] ?? 'Rejected by Admin');
                $stmt = mysqli_prepare($conn, "UPDATE house SET admin_status = 'rejected', admin_feedback = ? WHERE house_id = ?");
            }
            if ($stmt) {
                if ($action === 'reject_property') {
                    mysqli_stmt_bind_param($stmt, 'si', $feedback, $houseId);
                } else {
                    mysqli_stmt_bind_param($stmt, 'i', $houseId);
                }
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $notice = $action === 'reject_property' ? 'Property listing rejected.' : $notice;
            }
        }
        header('Location: dashboard.php?notice=' . urlencode($notice));
        exit();
    }

    if ($id > 0 && ($action === 'approve' || $action === 'reject')) {
        $status = $action === 'approve' ? 'verified' : 'rejected';
        
        // Update landlord table
        $stmt1 = mysqli_prepare($conn, 'UPDATE landlord SET verification_status = ?, verified_at = NOW() WHERE user_id = ?');
        if ($stmt1) {
            mysqli_stmt_bind_param($stmt1, 'si', $status, $id);
            mysqli_stmt_execute($stmt1);
        }

        $notice = $action === 'approve' ? 'Landlord verified successfully.' : 'Landlord rejected successfully.';
        header('Location: dashboard.php?notice=' . urlencode($notice));
        exit();
    }
}

$totalUsers = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users"))[0];
$totalProperties = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM house"))[0];
$totalLandlords = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE user_type='landlord'"))[0];
$totalTenants = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM users WHERE user_type='tenant'"))[0];
$totalReviews = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM review"))[0];

$pending = mysqli_query($conn, "
    SELECT u.id, u.username, u.full_name, u.phone_number, u.citizenship_id, u.created_at 
    FROM users u 
    INNER JOIN landlord l ON u.id = l.user_id 
    WHERE u.user_type='landlord' AND l.verification_status='pending' 
    ORDER BY u.id DESC
");
$pendingCount = mysqli_num_rows($pending);

$pendingProperties = mysqli_query($conn, "
    SELECT h.house_id, h.title, h.house_type, h.price, u.full_name as landlord_name, h.created_at, h.house_document 
    FROM house h 
    JOIN landlord l ON h.landlord_id = l.landlord_id 
    JOIN users u ON l.user_id = u.id 
    WHERE h.admin_status = 'pending' 
    ORDER BY h.created_at DESC
");
$pendingPropCount = mysqli_num_rows($pendingProperties);

mysqli_close($conn);

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gharelu Admin Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
 
</head>

<body>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">
            GHARELU
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php">
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
                    <a class="nav-link" href="messages.php">
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

<!-- Welcome Section -->
<div class="container mt-4">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">Welcome, Admin!</h2>
        </div>
    </div>
</div>

<!-- Notification Area -->
<?php if ($notice !== ''): ?>
    <div class="container mt-3">
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($notice); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<!-- Statistics Cards Section -->
<div class="container mt-4">
    <div class="row">
        <!-- Total Users Card - Blue Gradient -->
        <div class="col-md-3 mb-4">
            <div class="card text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title">Total Users</h5>
                            <h2 class="display-4"><?php echo $totalUsers; ?></h2>
                        </div>
                        <div class="fs-1">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Properties Card - Green Gradient -->
        <div class="col-md-3 mb-4">
            <div class="card text-white" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title">Total Properties</h5>
                            <h2 class="display-4"><?php echo $totalProperties; ?></h2>
                        </div>
                        <div class="fs-1">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Reviews Card - Orange Gradient -->
        <div class="col-md-3 mb-4">
            <div class="card text-white" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title">Total Reviews</h5>
                            <h2 class="display-4"><?php echo $totalReviews; ?></h2>
                        </div>
                        <div class="fs-1">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Landlords Card - Yellow/Orange Gradient -->
        <div class="col-md-3 mb-4">
            <div class="card text-white" style="background: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title">Pending Landlords</h5>
                            <h2 class="display-4"><?php echo $pendingCount; ?></h2>
                        </div>
                        <div class="fs-1">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Pending Property Verification Panel -->
<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h4 class="mb-0"><i class="fas fa-home me-2"></i>Pending Property Verification</h4>
        </div>
        <div class="card-body">
            <?php if ($pendingPropCount == 0): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No pending property verifications.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Landlord</th>
                                <th>Property Title</th>
                                <th>Type & Price</th>
                                <th>Document</th>
                                <th>Added On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($prop = mysqli_fetch_assoc($pendingProperties)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($prop['landlord_name']); ?></td>
                                    <td><?php echo htmlspecialchars($prop['title']); ?></td>
                                    <td><?php echo htmlspecialchars($prop['house_type']); ?><br>Rs <?php echo number_format($prop['price']); ?></td>
                                    <td>
                                        <?php if (!empty($prop['house_document'])): ?>
                                            <a href="../<?php echo htmlspecialchars($prop['house_document']); ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-alt"></i> View</a>
                                        <?php else: ?>
                                            <span class="text-muted">No Doc</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($prop['created_at'])); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Approve this property listing?');">
                                            <input type="hidden" name="action" value="approve_property">
                                            <input type="hidden" name="house_id" value="<?php echo $prop['house_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success" title="Approve"><i class="fas fa-check"></i></button>
                                        </form>
                                        
                                        <button class="btn btn-sm btn-danger" onclick="document.getElementById('rejectPropModal<?php echo $prop['house_id']; ?>').style.display='flex'" title="Reject"><i class="fas fa-times"></i></button>
                                        
                                        <!-- Reject Property Modal -->
                                        <div id="rejectPropModal<?php echo $prop['house_id']; ?>" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1050; align-items:center; justify-content:center;">
                                            <div style="background:white; padding:20px; border-radius:8px; width:400px; max-width:90%; text-align:left;">
                                                <h5 class="mb-3">Reject Listing</h5>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="reject_property">
                                                    <input type="hidden" name="house_id" value="<?php echo $prop['house_id']; ?>">
                                                    <div class="mb-3">
                                                        <label class="form-label">Reason for rejection:</label>
                                                        <textarea name="admin_feedback" rows="3" class="form-control" required></textarea>
                                                    </div>
                                                    <button type="submit" class="btn btn-danger">Reject</button>
                                                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('rejectPropModal<?php echo $prop['house_id']; ?>').style.display='none'">Cancel</button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Pending Landlord Verification Panel -->
<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-dark text-white">
            <h4 class="mb-0"><i class="fas fa-user-check me-2"></i>Pending Landlord Verification</h4>
        </div>
        <div class="card-body">
            <?php if ($pendingCount == 0): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>No pending landlord verifications.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Phone Number</th>
                                <th>Registration Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = mysqli_fetch_assoc($pending)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo htmlspecialchars($row['phone_number']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="user_id" value="<?php echo intval($row['id']); ?>">
                                            <button type="submit" name="action" value="approve" class="btn btn-success btn-sm me-1">
                                                <i class="fas fa-check me-1"></i>Verify Landlord
                                            </button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to reject this landlord?');">
                                                <i class="fas fa-times me-1"></i>Reject Landlord
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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
