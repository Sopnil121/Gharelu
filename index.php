<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/pagination.php';
$conn = db_connect();

// Database migration: add contact fields to house if they don't exist
$check_col = mysqli_query($conn, "SHOW COLUMNS FROM house LIKE 'contact_email'");
if ($check_col && mysqli_num_rows($check_col) == 0) {
    mysqli_query($conn, "ALTER TABLE house ADD COLUMN contact_email VARCHAR(255) NULL");
    mysqli_query($conn, "ALTER TABLE house ADD COLUMN contact_phone VARCHAR(50) NULL");
}

$user = current_user();
$is_logged_in = ($user !== null);
$user_type = $user['user_type'] ?? '';
$display_name = $user['full_name'] ?? ($user['username'] ?? '');
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch tenant_id if logged in as tenant
$tenant_id = 0;
if ($is_logged_in && $user_type === 'tenant') {
    $stmt = mysqli_prepare($conn, "SELECT tenant_id FROM tenant WHERE user_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $tenant_id = (int)$row['tenant_id'];
        }
        mysqli_stmt_close($stmt);
    }
}

// Handle post action for tenant interest (for logged in tenants)
$msg = $_GET['msg'] ?? '';
$error_msg = '';
$success_msg = '';

if ($msg === 'interest_sent') {
    $success_msg = 'Your interest request has been sent to the landlord successfully.';
} elseif ($msg === 'already_sent') {
    $error_msg = 'You have already sent an interest request for this property.';
} elseif ($msg === 'only_tenants') {
    $error_msg = 'Only tenant accounts can perform this action.';
} elseif ($msg === 'fav_added') {
    $success_msg = 'Added to your favorites.';
} elseif ($msg === 'fav_removed') {
    $success_msg = 'Removed from your favorites.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_interest') {
    if (empty($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    
    if (($_SESSION['user_type'] ?? '') !== 'tenant') {
        header("Location: index.php?msg=only_tenants");
        exit;
    }
    
    $user_id = (int)$_SESSION['user_id'];
    $house_id = (int)$_POST['house_id'];
    $msg_text = trim($_POST['message'] ?? '');
    if ($msg_text === '') {
        $msg_text = 'I am interested in this property. Please share more details.';
    }
    
    // Fallback create tenant row if it does not exist
    $stmt = mysqli_prepare($conn, "SELECT tenant_id FROM tenant WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $tenant_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$tenant_row) {
        $ins = mysqli_prepare($conn, "INSERT INTO tenant (user_id) VALUES (?)");
        mysqli_stmt_bind_param($ins, "i", $user_id);
        mysqli_stmt_execute($ins);
        $tenant_id = mysqli_insert_id($conn);
    } else {
        $tenant_id = (int)$tenant_row['tenant_id'];
    }
    
    // Check duplicates
    $check = mysqli_prepare($conn, "SELECT request_id FROM interest_request WHERE tenant_id = ? AND house_id = ?");
    mysqli_stmt_bind_param($check, "ii", $tenant_id, $house_id);
    mysqli_stmt_execute($check);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
        header("Location: index.php?msg=already_sent");
        exit;
    }
    
    // Insert interest request
    $ins = mysqli_prepare($conn, "INSERT INTO interest_request (tenant_id, house_id, message, request_status) VALUES (?, ?, ?, 'pending')");
    mysqli_stmt_bind_param($ins, "iis", $tenant_id, $house_id, $msg_text);
    mysqli_stmt_execute($ins);
    header("Location: index.php?msg=interest_sent");
    exit;
}

// Handle post action for contact message (inquiries)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'contact_submit') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if ($name === '' || $email === '' || $subject === '' || $message === '') {
        $error_msg = 'All fields are required for sending a message.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = 'Please enter a valid email address.';
    } else {
        // Ensure the table exists
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `contact_messages` (
            `id` int NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `email` varchar(255) NOT NULL,
            `subject` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `status` enum('unread','read','replied') DEFAULT 'unread',
            `admin_reply` text DEFAULT NULL,
            `replied_at` timestamp NULL DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        
        $ins = mysqli_prepare($conn, "INSERT INTO contact_messages (name, email, subject, message, status, created_at) VALUES (?, ?, ?, ?, 'unread', NOW())");
        if ($ins) {
            mysqli_stmt_bind_param($ins, "ssss", $name, $email, $subject, $message);
            if (mysqli_stmt_execute($ins)) {
                $success_msg = 'Your inquiry has been submitted successfully. The administration will contact you shortly.';
            } else {
                $error_msg = 'Error sending message. Please try again.';
            }
            mysqli_stmt_close($ins);
        } else {
            $error_msg = 'Error preparing message submission.';
        }
    }
}

// Search & Filter Parameters

// Handle POST action for favorites
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'add_favorite' || $action === 'remove_favorite') {
        if (!$is_logged_in) {
            header("Location: login.php");
            exit;
        }
        if ($user_type !== 'tenant' || $tenant_id === 0) {
            header("Location: index.php?msg=only_tenants");
            exit;
        }
        $house_id = (int)$_POST['house_id'];
        if ($action === 'add_favorite') {
            $check = mysqli_prepare($conn, "SELECT favorite_id FROM favorite WHERE tenant_id=? AND house_id=?");
            mysqli_stmt_bind_param($check, "ii", $tenant_id, $house_id);
            mysqli_stmt_execute($check);
            mysqli_stmt_store_result($check);
            if (mysqli_stmt_num_rows($check) === 0) {
                $ins = mysqli_prepare($conn, "INSERT INTO favorite (tenant_id, house_id) VALUES (?, ?)");
                mysqli_stmt_bind_param($ins, "ii", $tenant_id, $house_id);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
            mysqli_stmt_close($check);
            header("Location: index.php?msg=fav_added");
            exit;
        } else {
            $del = mysqli_prepare($conn, "DELETE FROM favorite WHERE tenant_id=? AND house_id=?");
            mysqli_stmt_bind_param($del, "ii", $tenant_id, $house_id);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
            header("Location: index.php?msg=fav_removed");
            exit;
        }
    }
}
$search_province = isset($_GET['province']) ? trim($_GET['province']) : '';
$search_district = isset($_GET['district']) ? trim($_GET['district']) : '';
$rooms = isset($_GET['rooms']) ? (int)$_GET['rooms'] : 0;
$max_price = isset($_GET['max_price']) ? (int)$_GET['max_price'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Total count query
$count_sql = "SELECT COUNT(*) as total FROM house h WHERE h.availability_status = 'available' AND h.admin_status = 'approved' AND h.price > 0";
$count_params = [];
$count_types = '';
if ($search_province !== '') {
    $count_sql .= " AND h.province = ?";
    $count_params[] = $search_province;
    $count_types .= 's';
}
if ($search_district !== '') {
    $count_sql .= " AND h.district = ?";
    $count_params[] = $search_district;
    $count_types .= 's';
}
if ($rooms > 0) {
    $count_sql .= " AND h.rooms >= ?";
    $count_params[] = $rooms;
    $count_types .= 'i';
}
if ($max_price > 0) {
    $count_sql .= " AND h.price <= ?";
    $count_params[] = $max_price;
    $count_types .= 'd';
}

$count_stmt = mysqli_prepare($conn, $count_sql);
if ($count_stmt && !empty($count_params)) {
    mysqli_stmt_bind_param($count_stmt, $count_types, ...$count_params);
}
if ($count_stmt) {
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
} else {
    $count_result = mysqli_query($conn, $count_sql);
}
$total_rows = 0;
if ($count_result && $row = mysqli_fetch_assoc($count_result)) {
    $total_rows = $row['total'];
}
$total_pages = ceil($total_rows / $limit);

$sql = "SELECT h.*, 
               (SELECT image_url FROM house_image WHERE house_id = h.house_id LIMIT 1) AS image_url,
               u.full_name AS landlord_fallback_name,
               u.phone_number AS landlord_fallback_phone,
               u.email AS landlord_fallback_email,
               (SELECT COUNT(*) FROM favorite WHERE house_id = h.house_id AND tenant_id = " . (int)$tenant_id . ") AS is_fav
        FROM house h
        LEFT JOIN landlord l ON l.landlord_id = h.landlord_id
        LEFT JOIN users u ON u.id = l.user_id
        WHERE h.availability_status = 'available' AND h.admin_status = 'approved' AND h.price > 0";

$params = $count_params;
$types = $count_types;

if ($search_province !== '') {
    $sql .= " AND h.province = ?";
}
if ($search_district !== '') {
    $sql .= " AND h.district = ?";
}

if ($rooms > 0) {
    $sql .= " AND h.rooms >= ?";
}

if ($max_price > 0) {
    $sql .= " AND h.price <= ?";
}

$sql .= " ORDER BY h.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = mysqli_prepare($conn, $sql);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}

$houses = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $houses[] = $row;
    }
}

// We use JS dynamic dropdowns, so no need to fetch unique provinces here anymore

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHARELU - House Rental System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time(); ?>">
    <style>
        /* Specific detail modal styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }
        .modal-overlay.open {
            display: flex;
        }
        .modal-box {
            background: var(--white);
            border-radius: 16px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            animation: modalFadeIn 0.3s ease;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-box-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #edf2f7;
        }
        .modal-box-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
        }
        .modal-box-body {
            padding: 1.5rem;
        }
        .modal-img-container {
            width: 100%;
            height: 280px;
            border-radius: 12px;
            overflow: hidden;
            background-color: #edf2f7;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .modal-img-container i {
            font-size: 5rem;
            color: var(--text-light);
        }
        .modal-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1.25rem 0;
            padding: 1rem;
            background: var(--background-light);
            border-radius: 10px;
            text-align: center;
        }
        .modal-meta-item {
            font-size: 0.95rem;
            color: var(--text-dark);
            font-weight: 500;
        }
        .modal-meta-item i {
            color: var(--primary-color);
            margin-right: 0.5rem;
        }
        .modal-desc-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        .modal-desc-text {
            color: var(--text-light);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .modal-price-box {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }
        .modal-price-box small {
            font-size: 0.9rem;
            color: var(--text-light);
            font-weight: 400;
        }
        .modal-footer-actions {
            display: flex;
            gap: 1rem;
            border-top: 1px solid #edf2f7;
            padding-top: 1.25rem;
        }
        /* Notification alert banner */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-left: 5px solid var(--primary-color);
            border-radius: 8px;
            padding: 1rem;
            display: none;
            animation: slideIn 0.3s ease;
        }
        .toast-notification.error {
            border-left-color: var(--danger, #c53030);
        }
        .toast-notification.success {
            border-left-color: var(--success, #2f855a);
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-header {
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toast-body {
            font-size: 0.9rem;
            color: var(--text-light);
        }
        .toast-actions {
            margin-top: 0.75rem;
            display: flex;
            gap: 0.5rem;
        }
        .toast-actions a {
            font-size: 0.85rem;
            font-weight: 600;
        }
        /* Make entire card clickable except action elements */
        .property-card {
            cursor: pointer;
        }
        .contact-us input:focus, 
        .contact-us textarea:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(44, 95, 138, 0.15);
        }
    </style>
</head>
<body>

    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-brand">
                <a href="index.php">
                    Gharelu
                </a>
            </div>
            <div class="nav-content">
                <div class="nav-links">
                    <a href="index.php" class="<?php echo $current_page === 'index.php' ? 'active' : ''; ?>">Home</a>
                    <a href="index.php#properties-section">Listings</a>
                    <a href="index.php#platform-section">About</a>
                    <a href="index.php#contact-us-section">Contact</a>
                    <?php if ($is_logged_in): ?>
                        <?php if ($user_type === 'tenant'): ?>
                            <a href="tenant/dashboard.php">Dashboard</a>
                        <?php elseif ($user_type === 'landlord'): ?>
                            <a href="landlord/dashboard.php">Dashboard</a>
                        <?php elseif ($user_type === 'admin'): ?>
                            <a href="admin/dashboard.php">Dashboard</a>
                        <?php endif; ?>
                        <a href="profile.php" class="<?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">Profile</a>
                    <?php endif; ?>
                </div>
                <div class="auth-buttons">
                    <?php if ($is_logged_in): ?>
                        <span class="user-greeting" style="font-weight: 500; font-size: 0.9rem; color: var(--text-light); align-self: center; margin-right: 0.5rem;">
                            Hello, <?php echo htmlspecialchars($display_name); ?>
                        </span>
                        <a href="logout.php" class="btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn-primary">Login</a>
                        <a href="signup.php" class="btn-primary">Register</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mobile-menu" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>

    <!-- Toast Notification System -->
    <div id="toastNotification" class="toast-notification">
        <div id="toastHeader" class="toast-header"></div>
        <div id="toastBody" class="toast-body"></div>
        <div id="toastActions" class="toast-actions"></div>
    </div>

    <!-- Alert triggers from PHP redirect -->
    <?php if ($success_msg !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('Success', '<?php echo addslashes($success_msg); ?>', 'success');
            });
        </script>
    <?php elseif ($error_msg !== ''): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('Error', '<?php echo addslashes($error_msg); ?>', 'error');
            });
        </script>
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h1>Finding your next home <br>should feel effortless.</h1>
                <p>GHARELU is your premium rental platform. Discover beautiful spaces, connect with trusted landlords, and move in with confidence.</p>
                
                <!-- Search Form -->
                <form class="search-form" action="index.php" method="GET">
                    <div class="search-grid">
                        <div class="search-field">
                            <label for="search_province"><i class="fas fa-map-marker-alt"></i> Province</label>
                            <select name="province" id="search_province" class="search-input"></select>
                        </div>
                        <div class="search-field">
                            <label for="search_district"><i class="fas fa-map-pin"></i> District</label>
                            <select name="district" id="search_district" class="search-input"></select>
                            </select>
                        </div>
                        <div class="search-field">
                            <label for="rooms"><i class="fas fa-door-open"></i> Rooms</label>
                            <select name="rooms" id="rooms" class="search-input">
                                <option value="0">Any Rooms</option>
                                <option value="1" <?php echo ($rooms == 1) ? 'selected' : ''; ?>>1+ Room</option>
                                <option value="2" <?php echo ($rooms == 2) ? 'selected' : ''; ?>>2+ Rooms</option>
                                <option value="3" <?php echo ($rooms == 3) ? 'selected' : ''; ?>>3+ Rooms</option>
                                <option value="4" <?php echo ($rooms == 4) ? 'selected' : ''; ?>>4+ Rooms</option>
                            </select>
                        </div>
                        <div class="search-field">
                            <label for="max_price"><i class="fas fa-rupee-sign"></i> Max Price</label>
                            <input type="number" name="max_price" id="max_price" class="search-input" placeholder="Any Price" value="<?php echo $max_price > 0 ? $max_price : ''; ?>" min="1">
                        </div>
                        <div class="search-field">
                            <button type="submit" class="btn-search">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- Featured Residences -->
    <section class="featured" id="properties-section">
        <div class="container">
            <div class="section-header">
                <h2>Featured Residences</h2>
                <p>Hand-picked properties that meet our highest standards for quality, design, and location.</p>
            </div>
            
            <?php if(count($houses) > 0): ?>
                <div class="properties-grid" id="propertiesGrid">
                    <?php foreach($houses as $house): 
                        // Prepare JS object safe data for detail modal popup
                        $contact_email = !empty($house['contact_email']) ? $house['contact_email'] : ($house['landlord_fallback_email'] ?? 'No email provided');
                        $contact_phone = !empty($house['contact_phone']) ? $house['contact_phone'] : ($house['landlord_fallback_phone'] ?? 'No phone provided');
                        $contact_name = $house['landlord_fallback_name'] ?? 'Landlord';

                        $houseSafe = [
                            'house_id' => (int)$house['house_id'],
                            'title' => addslashes($house['title']),
                            'address' => addslashes($house['address'] ?? ''),
                            'province' => addslashes($house['province'] ?? ''),
                            'district' => addslashes($house['district'] ?? ''),
                            'price' => (float)$house['price'],
                            'rooms' => (int)$house['rooms'],
                            'bathrooms' => (int)$house['bathrooms'],
                            'furnishing' => addslashes($house['furnishing'] ?? 'Unfurnished'),
                            'description' => addslashes(preg_replace('/\s+/',' ',$house['description'] ?? '')),
                            'image_url' => $house['image_url'] ? addslashes($house['image_url']) : '',
                            'landlord_name' => addslashes($contact_name),
                            'landlord_phone' => addslashes($contact_phone),
                            'landlord_email' => addslashes($contact_email)
                        ];
                        $houseJson = json_encode($houseSafe);
                    ?>
                        <div class="property-card" onclick="openDetailsModal(<?php echo htmlspecialchars($houseJson); ?>)">
                            <div class="property-image">
                                <?php if ($house['image_url']): ?>
                                    <img src="<?php echo htmlspecialchars($house['image_url']); ?>" alt="<?php echo htmlspecialchars($house['title']); ?>">
                                <?php else: ?>
                                    <div style="height:200px; display:flex; align-items:center; justify-content:center; background:#edf2f7; color:var(--text-light);">
                                        <i class="fas fa-image fa-3x"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" onclick="event.stopPropagation();" id="fav-form-<?php echo $house['house_id']; ?>">
                                    <input type="hidden" name="action" value="<?php echo $house['is_fav'] ? 'remove_favorite' : 'add_favorite'; ?>">
                                    <input type="hidden" name="house_id" value="<?php echo (int)$house['house_id']; ?>">
                                    <button type="button" class="fav-toggle-btn <?php echo $house['is_fav'] ? 'is-fav' : ''; ?>" title="<?php echo $house['is_fav'] ? 'Remove from favorites' : 'Save to favorites'; ?>" onclick="handleFavoriteClick(<?php echo (int)$house['house_id']; ?>, <?php echo $house['is_fav'] ? 'true' : 'false'; ?>)">
                                        <i class="<?php echo $house['is_fav'] ? 'fas' : 'far'; ?> fa-heart"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="property-details">
                                <h3><?php echo htmlspecialchars($house['title']); ?></h3>
                                <div class="property-location">
                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(trim(($house['district'] ?? '') . ', ' . ($house['province'] ?? ''), ', ')); ?>
                                </div>
                                <p class="property-description"><?php echo htmlspecialchars(substr($house['description'] ?? '', 0, 100)) . (strlen($house['description'] ?? '') > 100 ? '...' : ''); ?></p>
                                <div class="property-features">
                                    <span><i class="fas fa-door-open"></i> <?php echo (int)$house['rooms']; ?> Rooms</span>
                                    <span><i class="fas fa-bath"></i> <?php echo (int)$house['bathrooms']; ?> Baths</span>
                                    <span class="price"><i class="fas fa-rupee-sign"></i> NPR <?php echo number_format($house['price']); ?>/mo</span>
                                </div>
                                <div style="margin-top: 1rem; display: flex; gap: 0.5rem;" onclick="event.stopPropagation();">
                                    <button class="btn-primary" style="flex:1; padding: 0.4rem;" onclick="handleInterestClick(<?php echo (int)$house['house_id']; ?>, '<?php echo htmlspecialchars(addslashes($house['title'])); ?>')">
                                        <i class="fas fa-paper-plane"></i> Send Interest
                                    </button>
                                    <button class="btn-outline" style="flex:1; padding: 0.4rem;" onclick="handleContactClick('<?php echo htmlspecialchars(addslashes($contact_name)); ?>', '<?php echo htmlspecialchars(addslashes($contact_phone)); ?>', '<?php echo htmlspecialchars(addslashes($contact_email)); ?>')">
                                        <i class="fas fa-phone-alt"></i> Contact
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php 
                echo renderPagination($total_pages, $page, [
                    'province' => $search_province,
                    'district' => $search_district,
                    'rooms' => $rooms,
                    'max_price' => $max_price
                ]); 
                ?>
            <?php else: ?>
                <div class="no-results" style="text-align: center; padding: 4rem 1rem;">
                    <i class="fas fa-home fa-3x" style="color: var(--text-light); margin-bottom: 1rem;"></i>
                    <h3>No properties found</h3>
                    <p>Try adjusting your search criteria to find more options.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-box-header">
                <h3 id="detailsModalTitle">Property Details</h3>
                <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
            </div>
            <div class="modal-box-body">
                <div class="modal-img-container" id="detailsModalImgContainer">
                    <img id="detailsModalImage" src="" alt="">
                </div>
                <div class="modal-meta-grid">
                    <div class="modal-meta-item" id="detailsModalRooms"></div>
                    <div class="modal-meta-item" id="detailsModalBaths"></div>
                    <div class="modal-meta-item" id="detailsModalFurnishing"></div>
                </div>
                <div class="modal-desc-title">Amenities</div>
                <div class="modal-desc-text" id="detailsModalAmenities" style="margin-bottom:1rem;font-weight:500;color:var(--primary-color);"></div>
                <div class="modal-desc-title">Description</div>
                <div class="modal-desc-text" id="detailsModalDescription"></div>
                <div class="modal-price-box" id="detailsModalPrice"></div>
                <div class="modal-location" style="margin-bottom: 1.5rem; font-weight: 500; color: var(--text-light);">
                    <i class="fas fa-map-marker-alt" style="color: var(--primary-color);"></i> <span id="detailsModalLocation"></span>
                </div>
                <div class="modal-footer-actions">
                    <button class="btn-primary" id="detailsModalInterestBtn" style="flex:1;">
                        <i class="fas fa-paper-plane"></i> Send Interest
                    </button>
                    <button class="btn-outline" id="detailsModalContactBtn" style="flex:1;">
                        <i class="fas fa-phone-alt"></i> Contact
                    </button>
                    <button class="btn-outline" onclick="closeModal('detailsModal')">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Auth Required Modal -->
    <div id="authRequiredModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 420px; text-align: center;">
            <div class="modal-box-header" style="justify-content: center; position: relative;">
                <h3>Sign In Required</h3>
                <button class="modal-close" style="position: absolute; right: 1.5rem;" onclick="closeModal('authRequiredModal')">&times;</button>
            </div>
            <div class="modal-box-body" style="padding: 2rem 1.5rem;">
                <div style="width: 64px; height: 64px; background: rgba(44, 95, 138, 0.1); color: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 1.8rem;">
                    <i class="fas fa-user-lock"></i>
                </div>
                <h4 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.75rem; color: var(--text-dark);">You need to login or register to continue.</h4>
                <p style="color: var(--text-light); font-size: 0.95rem; margin-bottom: 2rem; line-height: 1.5;">Please sign in to your tenant account or create a new one to save favorites, contact landlords, and send interest requests.</p>
                <div style="display: flex; gap: 1rem;">
                    <a href="login.php" class="btn-primary" style="flex: 1; text-align: center; display: inline-block; padding: 0.75rem 1rem; text-decoration: none;">Login</a>
                    <a href="signup.php" class="btn-outline" style="flex: 1; text-align: center; display: inline-block; padding: 0.75rem 1rem; text-decoration: none; border: 2px solid var(--primary-color);">Register</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Landlord Modal -->
    <div id="contactModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 440px;">
            <div class="modal-box-header">
                <h3>Contact Landlord</h3>
                <button class="modal-close" onclick="closeModal('contactModal')">&times;</button>
            </div>
            <div class="modal-box-body" style="padding: 1.75rem 1.5rem;">
                <div style="width: 56px; height: 56px; background: rgba(232, 177, 44, 0.1); color: var(--secondary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem; font-size: 1.6rem;">
                    <i class="fas fa-address-card"></i>
                </div>
                <div style="display: flex; flex-direction: column; gap: 1.25rem; margin-bottom: 2rem;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 36px; height: 36px; background: #f0f4f8; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0;">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Landlord Name</div>
                            <div id="contactModalName" style="font-size: 1.05rem; font-weight: 700; color: var(--text-dark);"></div>
                        </div>
                    </div>
                    
                    
                </div>
                <button class="btn-primary" style="width: 100%; padding: 0.75rem;" onclick="closeModal('contactModal')">Close Details</button>
            </div>
        </div>
    </div>

    <!-- Interest Form Modal -->
    <div id="interestModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 500px;">
            <div class="modal-box-header">
                <h3 id="interestModalTitle">Send Interest</h3>
                <button class="modal-close" onclick="closeModal('interestModal')">&times;</button>
            </div>
            <form method="POST" class="modal-box-body">
                <input type="hidden" name="action" value="send_interest">
                <input type="hidden" name="house_id" id="interestHouseId">
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label style="display:block; font-weight:600; margin-bottom:0.5rem;">Message to landlord</label>
                    <textarea name="message" rows="4" style="width:100%; border-radius:8px; border:1px solid var(--border); padding:0.75rem; outline:none;" placeholder="I'd like to schedule a visit this weekend..."></textarea>
                </div>
                <button type="submit" class="btn-primary" style="width:100%;">
                    <i class="fas fa-paper-plane"></i> Send Request
                </button>
            </form>
        </div>
    </div>

    <!-- How It Works -->
    <section class="how-it-works" id="how-it-works-section">
        <div class="container">
            <div class="section-header">
                <h2>How It Works</h2>
                <p>Your journey to a new home simplified into three easy steps.</p>
            </div>
            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <i class="fas fa-search step-icon"></i>
                    <h3>Browse Listings</h3>
                    <p>Explore our curated collection of premium properties. Filter by location, price, and amenities to find your perfect match.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">2</div>
                    <i class="fas fa-heart step-icon"></i>
                    <h3>Express Interest</h3>
                    <p>Save your favorites and directly contact verified landlords to schedule viewings or ask questions about the property.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">3</div>
                    <i class="fas fa-key step-icon"></i>
                    <h3>Move In</h3>
                    <p>Finalize the details, sign securely, and step into your beautifully prepared new home.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Platform Section -->
    <section class="platform" id="platform-section">
        <div class="container">
            <div class="platform-header">
                <h2>A Platform Built for Everyone</h2>
                <p>GHARELU creates a transparent ecosystem where every participant has the tools they need to succeed in the rental market.</p>
            </div>
            <div class="users-grid">
                <div class="user-type">
                    <i class="fas fa-users"></i>
                    <h3>General Users</h3>
                    <p>Browse our entire catalog freely without committing to an account.</p>
                </div>
                <div class="user-type">
                    <i class="fas fa-user-check"></i>
                    <h3>Tenants</h3>
                    <p>Save favorites, set up alerts, and send direct interest to landlords.</p>
                </div>
                <div class="user-type">
                    <i class="fas fa-building"></i>
                    <h3>Landlords</h3>
                    <p>Post verified listings, manage inquiries, and find trustworthy tenants easily.</p>
                </div>
                <div class="user-type">
                    <i class="fas fa-shield-alt"></i>
                    <h3>Administrators</h3>
                    <p>Ensure quality control, verify listings, and maintain platform security.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Us Section -->
    <section class="contact-us" id="contact-us-section" style="background: var(--bg-light); padding: 5rem 0; border-top: 1px solid var(--border);">
        <div class="container" style="max-width: 800px;">
            <div class="section-header" style="text-align: center; margin-bottom: 3rem;">
                <h2>Get In Touch</h2>
                <p>Have questions about renting, listing properties, or account verification? Drop us a line and we'll reply as soon as possible.</p>
            </div>
            
            <form action="index.php#contact-us-section" method="POST" style="background: var(--white); padding: 2.5rem; border-radius: 16px; box-shadow: var(--shadow-md); border: 1px solid var(--border);">
                <input type="hidden" name="action" value="contact_submit">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                    <div>
                        <label for="contact_name" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);"><i class="fas fa-user" style="color: var(--primary-color); margin-right: 0.25rem;"></i> Full Name</label>
                        <input type="text" name="name" id="contact_name" required placeholder="John Doe" style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border); outline: none; transition: var(--transition);">
                    </div>
                    <div>
                        <label for="contact_email" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);"><i class="fas fa-envelope" style="color: var(--primary-color); margin-right: 0.25rem;"></i> Email Address</label>
                        <input type="email" name="email" id="contact_email" required placeholder="john@example.com" style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border); outline: none; transition: var(--transition);">
                    </div>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <label for="contact_subject" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);"><i class="fas fa-tag" style="color: var(--primary-color); margin-right: 0.25rem;"></i> Subject</label>
                    <input type="text" name="subject" id="contact_subject" required placeholder="Inquiry about landlord verification" style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border); outline: none; transition: var(--transition);">
                </div>
                
                <div style="margin-bottom: 2rem;">
                    <label for="contact_message" style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);"><i class="fas fa-comment-alt" style="color: var(--primary-color); margin-right: 0.25rem;"></i> Message</label>
                    <textarea name="message" id="contact_message" rows="5" required placeholder="Write your message here..." style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border); outline: none; resize: vertical; transition: var(--transition); font-family: inherit;"></textarea>
                </div>
                
                <button type="submit" class="btn-primary" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.85rem; font-size: 1rem; font-weight: 600;">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
            </form>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer" id="footer-section">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="index.php" style="text-decoration:none;color:inherit;">
                        <h3>GHARELU</h3>
                    </a>
                    <p>Your premium destination for finding beautiful homes and trustworthy landlords across Nepal. Making renting effortless and reliable.</p>
                </div>
                <div class="footer-links">
                    <h4>Platform</h4>
                    <ul>
                        <li><a href="index.php">Browse Listings</a></li>
                    </ul>
                </div>
                <div class="footer-links">
                    <h4>Legal</h4>
                    <ul>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                    </ul>
                </div>
                <div class="footer-social">
                    <h4>Connect With Us</h4>
                    <div class="social-icons">
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-facebook"></i></a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 GHARELU. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
    // Toggle Mobile Navigation Menu
    function toggleMobileMenu() {
        const navContent = document.querySelector('.nav-content');
        if (navContent) {
            navContent.classList.toggle('open');
        }
    }

    // Auto-close mobile menu and manage active underline link states dynamically (Click & ScrollSpy)
    document.addEventListener('DOMContentLoaded', function() {
        const allLinks = document.querySelectorAll('.nav-links a');
        const navLinks = document.querySelectorAll('.nav-links a[href^="#"]');
        const navContent = document.querySelector('.nav-content');
        
        allLinks.forEach(link => {
            link.addEventListener('click', function() {
                allLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });

        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (navContent && navContent.classList.contains('open')) {
                    navContent.classList.remove('open');
                }
            });
        });

        // ScrollSpy listener to update navbar active underline as user scrolls the landing page
        window.addEventListener('scroll', () => {
            if (window.scrollY < 200) {
                const homeLink = document.querySelector('.nav-links a[href="index.php"]');
                if (homeLink) {
                    allLinks.forEach(l => l.classList.remove('active'));
                    homeLink.classList.add('active');
                }
                return;
            }
            
            const sections = ['properties-section', 'platform-section', 'contact-us-section'];
            let currentSec = '';
            
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    const rect = el.getBoundingClientRect();
                    // Detect if the section takes up the current view scope
                    if (rect.top <= 180 && rect.bottom >= 180) {
                        currentSec = id;
                    }
                }
            });
            
            if (currentSec) {
                const activeLink = document.querySelector(`.nav-links a[href="#${currentSec}"]`);
                if (activeLink) {
                    allLinks.forEach(l => l.classList.remove('active'));
                    activeLink.classList.add('active');
                }
            }
        });
    });

    // Modal Control Functions
    function openModal(id) {
        document.getElementById(id).classList.add('open');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    // Details Modal Populator
    function openDetailsModal(property) {
        const imgContainer = document.getElementById('detailsModalImgContainer');
        if (property.image_url) {
            imgContainer.innerHTML = `<img id="detailsModalImage" src="${property.image_url}" alt="${property.title}">`;
        } else {
            imgContainer.innerHTML = `<i class="fas fa-image fa-3x"></i>`;
        }
        
        document.getElementById('detailsModalTitle').textContent = property.title;
        document.getElementById('detailsModalLocation').textContent = (property.district ? property.district + ', ' : '') + (property.province ? property.province : '') + ' (' + property.address + ')';
        document.getElementById('detailsModalDescription').textContent = property.description;
        document.getElementById('detailsModalAmenities').textContent = property.amenities ? property.amenities : 'Not specified';
        document.getElementById('detailsModalRooms').innerHTML = '<i class="fas fa-door-open"></i> ' + property.rooms + ' Rooms';
        document.getElementById('detailsModalBaths').innerHTML = '<i class="fas fa-bath"></i> ' + property.bathrooms + ' Baths';
        document.getElementById('detailsModalFurnishing').innerHTML = '<i class="fas fa-couch"></i> ' + property.furnishing;
        document.getElementById('detailsModalPrice').innerHTML = '<i class="fas fa-rupee-sign"></i> NPR ' + Number(property.price).toLocaleString() + ' <small>/ month</small>';
        
        const interestBtn = document.getElementById('detailsModalInterestBtn');
        interestBtn.onclick = function() {
            closeModal('detailsModal');
            handleInterestClick(property.house_id, property.title);
        };
        
        const contactBtn = document.getElementById('detailsModalContactBtn');
        if (contactBtn) {
            contactBtn.onclick = function() {
                closeModal('detailsModal');
                handleContactClick(property.landlord_name, property.landlord_phone, property.landlord_email);
            };
        }
        
        openModal('detailsModal');
    }

    // Intercept Send Interest
    const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
    const userType = '<?php echo $user_type; ?>';

    function handleInterestClick(houseId, title) {
        if (!isLoggedIn) {
            openModal('authRequiredModal');
            return;
        }
        
        if (userType !== 'tenant') {
            showToast('Role Restrained', 'Only tenant accounts can send interest requests.', 'error');
            return;
        }
        
        document.getElementById('interestHouseId').value = houseId;
        document.getElementById('interestModalTitle').textContent = 'Send Interest - ' + title;
        openModal('interestModal');
    }

    function handleContactClick(name, phone, email) {
        if (!isLoggedIn) {
            openModal('authRequiredModal');
            return;
        }
        
        if (userType !== 'tenant') {
            showToast('Role Restrained', 'Only tenant accounts can contact landlords.', 'error');
            return;
        }
        
        document.getElementById('contactModalName').textContent = name;
        
        openModal('contactModal');
    }

    function handleFavoriteClick(houseId, isFav) {
        if (!isLoggedIn) {
            openModal('authRequiredModal');
            return;
        }
        
        if (userType !== 'tenant') {
            showToast('Role Restrained', 'Only tenant accounts can save favorites.', 'error');
            return;
        }
        
        const form = document.getElementById('fav-form-' + houseId);
        if (form) {
            form.submit();
        }
    }

    // Toast Alert popup
    function showToast(title, body, type = 'success', showAuthButtons = false) {
        const toast = document.getElementById('toastNotification');
        const tHeader = document.getElementById('toastHeader');
        const tBody = document.getElementById('toastBody');
        const tActions = document.getElementById('toastActions');
        
        toast.className = 'toast-notification ' + type;
        tHeader.innerHTML = (type === 'success' ? '<i class="fas fa-check-circle" style="color:var(--success)"></i> ' : '<i class="fas fa-exclamation-circle" style="color:red"></i> ') + title;
        tBody.textContent = body;
        
        if (showAuthButtons) {
            tActions.innerHTML = `
                <a href="login.php" class="btn-primary" style="padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.8rem; text-decoration:none;">Login</a>
                <a href="signup.php" class="btn-outline" style="padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.8rem; text-decoration:none;">Create Account</a>
            `;
        } else {
            tActions.innerHTML = '';
        }
        
        toast.style.display = 'block';
        
        if (!showAuthButtons) {
            setTimeout(function() {
                toast.style.display = 'none';
            }, 6000);
        }
    }

    // Close Modals when clicking outside content box
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.classList.remove('open');
            }
        });
    });
    </script>
<script src="assets/js/location_dropdown.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        initLocationDropdowns('search_province', 'search_district', urlParams.get('province') || '', urlParams.get('district') || '');
    });
</script>
</body>
</html>
<?php
mysqli_close($conn);
?>
