<?php
/* ==========================================================================
   TENANT DASHBOARD — Gharelu
   --------------------------------------------------------------------------
   Handles: browsing houses, saving/removing favorites, sending interest
   requests to landlords, and writing/deleting reviews.

   NOTE FOR PARIDHI: This assumes your login.php sets these session keys:
       $_SESSION['user_id']    -> the row id from the `users` table
       $_SESSION['user_type']  -> 'tenant' / 'landlord' / 'admin'
   If your login.php uses different session key names, just update the
   two lines under "AUTH CHECK" below to match.
   ========================================================================== */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/pagination.php';
$conn = db_connect();

/* ---------------------- AUTH CHECK ---------------------- */
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'tenant') {
    header("Location: ../login.php");
    exit;
}
$user_id = (int) $_SESSION['user_id'];

/* ---------------------- GET TENANT ID ---------------------- */
$stmt = mysqli_prepare($conn, "SELECT tenant_id, occupation FROM tenant WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$tenant_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$tenant_row) {
    // Fallback dynamic creation of tenant row
    $ins = mysqli_prepare($conn, "INSERT INTO tenant (user_id) VALUES (?)");
    mysqli_stmt_bind_param($ins, "i", $user_id);
    mysqli_stmt_execute($ins);
    
    // Re-fetch
    mysqli_stmt_execute($stmt);
    $tenant_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}
$tenant_id = (int) $tenant_row['tenant_id'];

/* ---------------------- GET USER NAME (for header) ---------------------- */
$stmt = mysqli_prepare($conn, "SELECT full_name, profile_image FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$full_name = $user_row['full_name'] ?? 'Tenant';
$profile_image = $user_row['profile_image'] ?? '';
$initials = strtoupper(substr($full_name, 0, 1));

/* ---------------------- HANDLE POST ACTIONS ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    switch ($action) {

        case 'add_favorite':
            $house_id = (int) $_POST['house_id'];
            $check = mysqli_prepare($conn, "SELECT favorite_id FROM favorite WHERE tenant_id=? AND house_id=?");
            mysqli_stmt_bind_param($check, "ii", $tenant_id, $house_id);
            mysqli_stmt_execute($check);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
                $ins = mysqli_prepare($conn, "INSERT INTO favorite (tenant_id, house_id) VALUES (?, ?)");
                mysqli_stmt_bind_param($ins, "ii", $tenant_id, $house_id);
                mysqli_stmt_execute($ins);
            }
            header("Location: dashboard.php?tab=" . urlencode($_POST['return_tab'] ?? 'browse') . "&msg=fav_added");
            exit;

        case 'remove_favorite':
            $house_id = (int) $_POST['house_id'];
            $del = mysqli_prepare($conn, "DELETE FROM favorite WHERE tenant_id=? AND house_id=?");
            mysqli_stmt_bind_param($del, "ii", $tenant_id, $house_id);
            mysqli_stmt_execute($del);
            header("Location: dashboard.php?tab=" . urlencode($_POST['return_tab'] ?? 'favorites') . "&msg=fav_removed");
            exit;

        case 'send_interest':
            $house_id = (int) $_POST['house_id'];
            $msg_text = trim($_POST['message'] ?? '');
            if ($msg_text === '') $msg_text = 'I am interested in this property. Please share more details.';
            
            // Check duplicate interest request
            $check = mysqli_prepare($conn, "SELECT request_id FROM interest_request WHERE tenant_id = ? AND house_id = ?");
            mysqli_stmt_bind_param($check, "ii", $tenant_id, $house_id);
            mysqli_stmt_execute($check);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
                header("Location: dashboard.php?tab=browse&msg=already_sent");
                exit;
            }

            $ins = mysqli_prepare($conn, "INSERT INTO interest_request (tenant_id, house_id, message, request_status) VALUES (?, ?, ?, 'pending')");
            mysqli_stmt_bind_param($ins, "iis", $tenant_id, $house_id, $msg_text);
            mysqli_stmt_execute($ins);
            header("Location: dashboard.php?tab=requests&msg=interest_sent");
            exit;

        case 'add_review':
            $house_id = (int) $_POST['house_id'];
            $rating = max(1, min(5, (int) $_POST['rating']));
            $comment = trim($_POST['comment'] ?? '');
            $ins = mysqli_prepare($conn, "INSERT INTO review (house_id, tenant_id, rating, comment) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($ins, "iiis", $house_id, $tenant_id, $rating, $comment);
            mysqli_stmt_execute($ins);
            header("Location: dashboard.php?tab=reviews&msg=review_added");
            exit;

        case 'delete_review':
            $review_id = (int) $_POST['review_id'];
            $del = mysqli_prepare($conn, "DELETE FROM review WHERE review_id=? AND tenant_id=?");
            mysqli_stmt_bind_param($del, "ii", $review_id, $tenant_id);
            mysqli_stmt_execute($del);
            header("Location: dashboard.php?tab=reviews&msg=review_deleted");
            exit;
    }
}

/* ---------------------- ACTIVE TAB ---------------------- */
$allowed_tabs = ['overview', 'browse', 'favorites', 'requests', 'reviews'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $allowed_tabs)) $tab = 'overview';

/* ---------------------- FLASH MESSAGE ---------------------- */
$flash_messages = [
    'fav_added'      => ['Added to your favorites.', 'success'],
    'fav_removed'    => ['Removed from your favorites.', 'success'],
    'interest_sent'  => ['Your interest request has been sent to the landlord.', 'success'],
    'review_added'   => ['Your review has been posted. Thank you!', 'success'],
    'review_deleted' => ['Review deleted.', 'success'],
    'already_sent'   => ['You have already sent an interest request for this property.', 'danger'],
];
$flash = $flash_messages[$_GET['msg'] ?? ''] ?? null;

/* ---------------------- STATS FOR OVERVIEW ---------------------- */
$fav_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM favorite WHERE tenant_id=$tenant_id"))['c'];
$req_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM interest_request WHERE tenant_id=$tenant_id"))['c'];
$unread_req_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM interest_request WHERE tenant_id=$tenant_id AND tenant_is_read=0"))['c'];
$review_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM review WHERE tenant_id=$tenant_id"))['c'];

if ($tab === 'requests') {
    mysqli_query($conn, "UPDATE interest_request SET tenant_is_read = 1 WHERE tenant_id=$tenant_id AND tenant_is_read=0");
    $unread_req_count = 0; // It is now 0 since we just marked them read
}

/* ---------------------- BROWSE HOUSES ---------------------- */
$location = isset($_GET['location']) ? trim($_GET['location']) : '';
$rooms = isset($_GET['rooms']) ? (int)$_GET['rooms'] : 0;
$max_price = isset($_GET['max_price']) ? (int)$_GET['max_price'] : 0;

// No longer fetching locations here because we use dynamic JS dropdowns

$houses = [];
if ($tab === 'browse' || $tab === 'overview') {
    $sql = "SELECT h.*,
                   (SELECT image_url FROM house_image WHERE house_id = h.house_id LIMIT 1) AS image_url,
                   u.full_name AS landlord_fallback_name,
                   u.phone_number AS landlord_fallback_phone,
                   u.email AS landlord_fallback_email,
                   (SELECT COUNT(*) FROM favorite f WHERE f.house_id = h.house_id AND f.tenant_id = $tenant_id) AS is_fav,
                     (SELECT request_status FROM interest_request ir WHERE ir.house_id = h.house_id AND ir.tenant_id = $tenant_id ORDER BY requested_at DESC LIMIT 1) AS my_request_status
            FROM house h
            LEFT JOIN landlord l ON l.landlord_id = h.landlord_id
            LEFT JOIN users u ON u.id = l.user_id
            WHERE h.availability_status = 'available' AND h.admin_status = 'approved' AND h.price > 0";

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $count_sql = "SELECT COUNT(*) as total FROM house h WHERE h.availability_status = 'available' AND h.admin_status = 'approved' AND h.price > 0";
    $count_params = [];
    $count_types = '';

    $search_province = isset($_GET['province']) ? trim($_GET['province']) : '';
    $search_district = isset($_GET['district']) ? trim($_GET['district']) : '';
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

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $houses[] = $row;
        }
    }
}

/* ---------------------- FAVORITES ---------------------- */
$favorites = [];
if ($tab === 'favorites') {
    $sql = "SELECT h.*, f.favorite_id,
                   (SELECT image_url FROM house_image WHERE house_id = h.house_id LIMIT 1) AS image_url,
                   u.full_name AS landlord_fallback_name,
                   u.phone_number AS landlord_fallback_phone,
                   u.email AS landlord_fallback_email
            FROM favorite f
            JOIN house h ON h.house_id = f.house_id
            LEFT JOIN landlord l ON l.landlord_id = h.landlord_id
            LEFT JOIN users u ON u.id = l.user_id
            WHERE f.tenant_id = $tenant_id
            ORDER BY f.saved_at DESC";
    $res = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($res)) $favorites[] = $row;
}

/* ---------------------- MY REQUESTS ---------------------- */
$requests = [];
if ($tab === 'requests' || $tab === 'overview') {
    $sql = "SELECT r.*, h.title, h.province, h.district, h.address,
                   (SELECT image_url FROM house_image WHERE house_id = h.house_id LIMIT 1) AS image_url
            FROM interest_request r
            JOIN house h ON h.house_id = r.house_id
            WHERE r.tenant_id = $tenant_id
            ORDER BY r.requested_at DESC";
    $res = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($res)) $requests[] = $row;
}

/* ---------------------- MY REVIEWS + REVIEWABLE HOUSES ---------------------- */
$my_reviews = [];
$reviewable = [];
if ($tab === 'reviews') {
    $sql = "SELECT rv.*, h.title, h.province, h.district,
                   (SELECT image_url FROM house_image WHERE house_id = h.house_id LIMIT 1) AS image_url
            FROM review rv
            JOIN house h ON h.house_id = rv.house_id
            WHERE rv.tenant_id = $tenant_id
            ORDER BY rv.created_at DESC";
    $res = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($res)) $my_reviews[] = $row;

    // Houses tenant had an accepted or leased interest in, that they haven't reviewed yet
    $sql = "SELECT DISTINCT h.house_id, h.title, h.province, h.district,
                   (SELECT image_url FROM house_image WHERE house_id = h.house_id LIMIT 1) AS image_url
            FROM interest_request r
            JOIN house h ON h.house_id = r.house_id
            WHERE r.tenant_id = $tenant_id
              AND r.request_status IN ('accepted', 'leased')
              AND h.house_id NOT IN (
                  SELECT house_id FROM review WHERE tenant_id = $tenant_id
              )";
    $res = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($res)) $reviewable[] = $row;
}



function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES); }
function img_or_placeholder($url) {
    if (!$url) return null;
    if (strpos($url, 'http') === 0 || strpos($url, '/') === 0) {
        return h($url);
    }
    return '../' . h($url);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tenant Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/tenant.css?v=<?= time(); ?>">

</head>

<body>

<div class="dash-shell">

    <!-- NAVBAR -->
    <header class="dash-navbar">
        <div class="dash-brand">
            <a href="dashboard.php?tab=overview" style="display: flex; align-items: center; gap: 0.6rem; text-decoration: none; color: inherit;">
                <span>Gharelu</span>
            </a>
        </div>

        <nav class="dash-nav" id="dashNav">
            <a href="?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>">
                <i class="fas fa-gauge"></i><span>Overview</span>
            </a>
            <a href="?tab=browse" class="<?= $tab === 'browse' ? 'active' : '' ?>">
                <i class="fas fa-magnifying-glass"></i><span>Browse</span>
            </a>
            <a href="?tab=favorites" class="<?= $tab === 'favorites' ? 'active' : '' ?>">
                <i class="fas fa-heart"></i><span>Favorites</span>
            </a>
            <a href="?tab=requests" class="<?= $tab === 'requests' ? 'active' : '' ?>">
                <i class="fas fa-paper-plane"></i><span>Requests</span>
                <?php if ($unread_req_count > 0): ?><span class="badge-count" id="tenantReqBadge"><?= $unread_req_count ?></span><?php endif; ?>
            </a>
            <a href="?tab=reviews" class="<?= $tab === 'reviews' ? 'active' : '' ?>">
                <i class="fas fa-star"></i><span>Reviews</span>
            </a>
        </nav>

        <div class="dash-nav-actions">
            <a href="../profile.php" class="dash-user" style="text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
                <?php if (!empty($profile_image)): ?>
                    <img src="../<?= h($profile_image) ?>" alt="Avatar" class="dash-user-avatar" style="width:38px; height:38px; border-radius:50%; object-fit:cover; flex-shrink:0;">
                <?php else: ?>
                    <div class="dash-user-avatar" style="width:38px; height:38px; font-size: 0.9rem;"><?= h($initials) ?></div>
                <?php endif; ?>
                <div class="dash-user-info desktop-only">
                    <h4 style="margin:0; font-size: 0.9rem; color: var(--text-dark);"><?= h(explode(' ', $full_name)[0]) ?></h4>
                </div>
            </a>

            <a href="../logout.php" class="dash-logout desktop-only" title="Log Out" style="color: var(--danger); font-size: 1.1rem; padding: 0.5rem; margin-left: 0.5rem;">
                <i class="fas fa-arrow-right-from-bracket"></i>
            </a>
            
            <button class="mobile-menu-toggle" onclick="document.getElementById('dashNav').classList.toggle('show')">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>

    <!-- MAIN -->
    <main class="dash-main">

        <?php if ($flash): ?>
            <div class="dash-alert <?= h($flash[1]) ?>">
                <i class="fas fa-circle-check"></i> <?= h($flash[0]) ?>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'overview'): ?>
            <!-- ============ OVERVIEW ============ -->
            <div class="dash-topbar">
                <div>
                    <h1>Welcome back, <?= h(explode(' ', $full_name)[0]) ?></h1>
                    <p>Here's what's happening with your house hunt.</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-heart"></i></div>
                    <div>
                        <div class="stat-number"><?= $fav_count ?></div>
                        <div class="stat-label">Saved Favorites</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon gold"><i class="fas fa-paper-plane"></i></div>
                    <div>
                        <div class="stat-number"><?= $req_count ?></div>
                        <div class="stat-label">Interest Requests</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="stat-number"><?= $review_count ?></div>
                        <div class="stat-label">Reviews Written</div>
                    </div>
                </div>
            </div>

            <div class="dash-section-title">
                <h2>Recently Listed Houses <span>— tap the heart to save one</span></h2>
            </div>
            <div class="properties-grid">
                <?php foreach (array_slice($houses, 0, 3) as $house): ?>
                    <?php include __DIR__ . '/tenant_house_card.php'; ?>
                <?php endforeach; ?>
                <?php if (empty($houses)): ?>
                    <div class="empty-state" style="grid-column:1/-1;">
                        <i class="fas fa-house-circle-xmark"></i>
                        <h3>No houses available right now</h3>
                        <p>Check back soon — new listings are added regularly.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'browse'): ?>
            <!-- ============ BROWSE ============ -->
            <div class="dash-topbar">
                <div>
                    <h1>Browse Houses</h1>
                    <p>Find your next home from <?= count($houses) ?> available listings.</p>
                </div>
            </div>

            <!-- Search Form -->
            <div class="search-container" style="margin-bottom: 2rem;">
                <form class="search-form" action="dashboard.php" method="GET" style="margin-top: 0; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid var(--border);">
                    <input type="hidden" name="tab" value="browse">
                    <div class="search-grid">
                          <div class="search-field">
                              <label for="search_province"><i class="fas fa-map-marker-alt"></i> Province</label>
                              <select name="province" id="search_province" class="search-input"></select>
                          </div>
                          <div class="search-field">
                              <label for="search_district"><i class="fas fa-map-pin"></i> District</label>
                              <select name="district" id="search_district" class="search-input"></select>
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

            <div class="properties-grid">
                <?php foreach ($houses as $house): ?>
                    <?php include __DIR__ . '/tenant_house_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($houses)): ?>
                <?php 
                echo renderPagination($total_pages, $page, [
                    'tab' => $tab,
                    'location' => $location,
                    'rooms' => $rooms,
                    'max_price' => $max_price
                ]); 
                ?>
            <?php endif; ?>
            <?php if (empty($houses)): ?>
                <div class="empty-state">
                    <i class="fas fa-house-circle-xmark"></i>
                    <h3>No houses available right now</h3>
                    <p>Check back soon — new listings are added regularly.</p>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'favorites'): ?>
            <!-- ============ FAVORITES ============ -->
            <div class="dash-topbar">
                <div>
                    <h1>My Favorites</h1>
                    <p>Houses you've saved for later.</p>
                </div>
            </div>

            <div class="properties-grid">
                <?php foreach ($favorites as $house): ?>
                    <div class="property-card">
                        <div class="property-image">
                            <?php if ($img = img_or_placeholder($house['image_url'])): ?>
                                <img src="<?= $img ?>" alt="<?= h($house['title']) ?>">
                            <?php else: ?>
                                <div class="no-img"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                            <span class="property-status <?= h($house['availability_status']) ?>"><?= ucfirst(h($house['availability_status'])) ?></span>
                        </div>
                        <div class="property-details">
                            <h3><?= h($house['title']) ?></h3>
                            <div class="property-location"><i class="fas fa-location-dot"></i> <?= h(trim(($house['district'] ?? '') . ', ' . ($house['province'] ?? ''), ', ')) ?></div>
                            <div class="property-features">
                                <span><i class="fas fa-door-open"></i><?= (int)$house['rooms'] ?> Room</span>
                                <span><i class="fas fa-bath"></i><?= (int)$house['bathrooms'] ?> Bath</span>
                            </div>
                            <div class="property-price">Rs <?= number_format($house['price']) ?> <small>/ month</small></div>
                            <div class="property-actions">
                                <form method="POST" style="flex:1;">
                                    <input type="hidden" name="action" value="remove_favorite">
                                    <input type="hidden" name="house_id" value="<?= (int)$house['house_id'] ?>">
                                    <input type="hidden" name="return_tab" value="favorites">
                                    <button type="submit" class="btn-danger-outline" style="width:100%;">
                                        <i class="fas fa-heart-crack"></i> Remove
                                    </button>
                                </form>
                                <button class="btn-primary" onclick="openInterestModal(<?= (int)$house['house_id'] ?>, '<?= h(addslashes($house['title'])) ?>')">
                                    <i class="fas fa-paper-plane"></i> Send Interest
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($favorites)): ?>
                <div class="empty-state">
                    <i class="fas fa-heart-crack"></i>
                    <h3>No favorites yet</h3>
                    <p>Browse houses and tap the heart icon to save the ones you like.</p>
                    <a href="?tab=browse" class="btn-primary"><i class="fas fa-magnifying-glass"></i> Browse Houses</a>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'requests'): ?>
            <!-- ============ MY REQUESTS ============ -->
            <div class="dash-topbar">
                <div>
                    <h1>My Requests</h1>
                    <p>Track the status of the interest you've sent to landlords.</p>
                </div>
            </div>

            <div class="request-list">
                <?php foreach ($requests as $req): ?>
                    <div class="request-card">
                        <div class="request-thumb">
                            <?php if ($img = img_or_placeholder($req['image_url'])): ?>
                                <img src="<?= $img ?>" alt="">
                            <?php else: ?>
                                <div class="no-img" style="height:100%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="request-body">
                            <h4><?= h($req['title']) ?></h4>
                            <div class="request-loc"><i class="fas fa-location-dot"></i> <?= h($req['district'] . ', ' . $req['province']) ?></div>
                            <div class="request-msg">"<?= h($req['message']) ?>"</div>
                        </div>
                        <div class="request-meta">
                            <span class="status-badge <?= h($req['request_status']) ?>"><?= h($req['request_status']) ?></span>
                            <div class="request-date"><?= date('M j, Y', strtotime($req['requested_at'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($requests)): ?>
                <div class="empty-state">
                    <i class="fas fa-paper-plane"></i>
                    <h3>No requests sent yet</h3>
                    <p>When you find a house you like, send the landlord an interest request.</p>
                    <a href="?tab=browse" class="btn-primary"><i class="fas fa-magnifying-glass"></i> Browse Houses</a>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'reviews'): ?>
            <!-- ============ MY REVIEWS ============ -->
            <div class="dash-topbar">
                <div>
                    <h1>My Reviews</h1>
                    <p>Share your experience and manage reviews you've written.</p>
                </div>
            </div>

            <?php if (!empty($reviewable)): ?>
                <div class="dash-section-title"><h2>Houses You Can Review <span>— based on accepted requests</span></h2></div>
                <?php foreach ($reviewable as $rh): ?>
                    <div class="reviewable-card">
                        <div class="request-thumb">
                            <?php if ($img = img_or_placeholder($rh['image_url'])): ?>
                                <img src="<?= $img ?>" alt="">
                            <?php else: ?>
                                <div class="no-img" style="height:100%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="request-body">
                            <h4><?= h($rh['title']) ?></h4>
                            <div class="request-loc"><i class="fas fa-location-dot"></i> <?= h($rh['district'] . ', ' . $rh['province']) ?></div>
                        </div>
                        <button class="btn-gold" onclick="openReviewModal(<?= (int)$rh['house_id'] ?>, '<?= h(addslashes($rh['title'])) ?>')">
                            <i class="fas fa-star"></i> Write Review
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="dash-section-title" style="margin-top:2rem;"><h2>Reviews You've Written</h2></div>
            <?php foreach ($my_reviews as $rv): ?>
                <div class="review-card">
                    <div class="review-card-top">
                        <div>
                            <h4><?= h($rv['title']) ?></h4>
                            <div class="stars">
                                <?php for ($i=1;$i<=5;$i++): ?>
                                    <i class="fa<?= $i <= $rv['rating'] ? 's' : 'r' ?> fa-star"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="review-date"><?= date('M j, Y', strtotime($rv['created_at'])) ?></div>
                    </div>
                    <p class="comment"><?= h($rv['comment']) ?></p>
                    <div class="review-card-actions">
                        <form method="POST" onsubmit="return confirm('Delete this review?');">
                            <input type="hidden" name="action" value="delete_review">
                            <input type="hidden" name="review_id" value="<?= (int)$rv['review_id'] ?>">
                            <button type="submit" class="btn-danger-outline"><i class="fas fa-trash"></i> Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($my_reviews) && empty($reviewable)): ?>
                <div class="empty-state">
                    <i class="fas fa-star"></i>
                    <h3>No reviews yet</h3>
                    <p>Once a landlord accepts your interest request, you'll be able to leave a review here.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </main>
</div>



<!-- ============ INTEREST MODAL ============ -->
<div id="interestModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 500px;">
        <div class="modal-box-header">
            <h3 id="interestModalTitle">Send Interest</h3>
            <button class="modal-close" onclick="closeModal('interestModal')">&times;</button>
        </div>
        <form method="POST" class="modal-box-body">
            <input type="hidden" name="action" value="send_interest">
            <input type="hidden" name="house_id" id="interestHouseId">
            
            <p style="margin-bottom: 1rem; color: var(--text-light); font-size: 0.95rem;">Send a direct message to the landlord to express your interest in renting this property.</p>
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);">Message (Optional)</label>
                <textarea name="message" rows="4" placeholder="Hello! I am very interested in your property..." style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; resize: vertical; font-family: inherit;"></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; padding: 0.75rem; font-size: 1.05rem;">
                <i class="fas fa-paper-plane"></i> Send Request
            </button>
        </form>
    </div>
</div>



<!-- ============ CONTACT MODAL ============ -->
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
                
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 36px; height: 36px; background: #f0f4f8; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0;">
                        <i class="fas fa-phone-alt"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Phone Number</div>
                        <div id="contactModalPhone" style="font-size: 1.05rem; font-weight: 700; color: var(--text-dark);"></div>
                    </div>
                    
                </div>
                
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="width: 36px; height: 36px; background: #f0f4f8; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--primary-color); flex-shrink: 0;">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600;">Email Address</div>
                        <div id="contactModalEmail" style="font-size: 1.05rem; font-weight: 700; color: var(--text-dark);"></div>
                    </div>
                    
                </div>
            </div>
            <button class="btn-primary" style="width: 100%; padding: 0.75rem;" onclick="closeModal('contactModal')">Close Details</button>
        </div>
    </div>
</div>


<!-- ============ REVIEW MODAL ============ -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal-box" style="max-width: 500px;">
        <div class="modal-box-header">
            <h3 id="reviewModalTitle">Review Property</h3>
            <button type="button" class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <form method="POST" class="modal-box-body">
            <input type="hidden" name="action" value="add_review">
            <input type="hidden" name="house_id" id="reviewHouseId">
            <input type="hidden" name="rating" id="reviewRatingInput" value="5">
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);">Property Rating</label>
                <div class="rating-input" id="ratingStars" style="font-size: 2rem; color: #cbd5e1; cursor: pointer; display: flex; gap: 0.5rem;">
                    <i class="fas fa-star active" data-val="1" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="2" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="3" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="4" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="5" style="color: #f59e0b;"></i>
                </div>
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);">Your Review</label>
                <textarea name="comment" rows="4" placeholder="Share your experience with this property..." required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; resize: vertical; font-family: inherit;"></textarea>
            </div>
            
            <button type="submit" class="btn-primary" style="width: 100%; padding: 0.75rem; font-size: 1.05rem;">
                <i class="fas fa-star"></i> Submit Review
            </button>
        </form>
    </div>
</div>

<!-- ============ DETAILS MODAL ============ -->
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
                <button class="btn-outline" onclick="closeModal('detailsModal')">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openReviewModal(houseId, title) {
    document.getElementById('reviewHouseId').value = houseId;
    document.getElementById('reviewModalTitle').textContent = 'Review - ' + title;
    document.getElementById('reviewModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function openDetailsModal(property) {
    const imgContainer = document.getElementById('detailsModalImgContainer');
    if (property.image_url) {
        imgContainer.innerHTML = `<img id="detailsModalImage" src="${property.image_url}" alt="${property.title}" style="width: 100%; height: 100%; object-fit: cover;">`;
    } else {
        imgContainer.innerHTML = `<i class="fas fa-image fa-3x" style="color: var(--text-light);"></i>`;
    }
    
    document.getElementById('detailsModalTitle').textContent = property.title;
    document.getElementById('detailsModalLocation').textContent = (property.district ? property.district + ', ' : '') + (property.province ? property.province : '') + ' (' + property.address + ')';
    document.getElementById('detailsModalDescription').textContent = property.description;
    document.getElementById('detailsModalAmenities').textContent = property.amenities ? property.amenities : 'Not specified';
    document.getElementById('detailsModalRooms').innerHTML = '<i class="fas fa-door-open"></i> ' + property.rooms + ' Rooms';
    document.getElementById('detailsModalBaths').innerHTML = '<i class="fas fa-bath"></i> ' + property.bathrooms + ' Baths';
    document.getElementById('detailsModalFurnishing').innerHTML = '<i class="fas fa-couch"></i> ' + property.furnishing;
    document.getElementById('detailsModalPrice').innerHTML = '<i class="fas fa-rupee-sign"></i> Rs ' + Number(property.price).toLocaleString() + ' <small style="font-size: 0.8rem; color: var(--text-light); font-weight: 400;">/ month</small>';
    
    const interestBtn = document.getElementById('detailsModalInterestBtn');
    interestBtn.onclick = function() {
        closeModal('detailsModal');
        openInterestModal(property.house_id, property.title);
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

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});

// Star rating picker
const stars = document.querySelectorAll('#ratingStars i');
const ratingInput = document.getElementById('reviewRatingInput');
stars.forEach(function(star) {
    star.addEventListener('click', function() {
        const val = parseInt(star.dataset.val);
        ratingInput.value = val;
        stars.forEach(function(s) {
            s.classList.toggle('active', parseInt(s.dataset.val) <= val);
        });
    });
});

function openInterestModal(houseId, title) {
    document.getElementById('interestHouseId').value = houseId;
    document.getElementById('interestModalTitle').textContent = 'Send Interest - ' + title;
    openModal('interestModal');
}

function handleContactClick(name, phone, email) {
    document.getElementById('contactModalName').textContent = name || 'Not provided';
    document.getElementById('contactModalPhone').textContent = phone || 'Not provided';
    document.getElementById('contactModalEmail').textContent = email || 'Not provided';
    
    
    
    
    openModal('contactModal');
}
</script>

<script src="../assets/js/location_dropdown.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initLocationDropdowns === 'function') {
            initLocationDropdowns('search_province', 'search_district', '<?= addslashes($search_province ?? '') ?>', '<?= addslashes($search_district ?? '') ?>');
        }
    });
</script>
</body>
</html>
