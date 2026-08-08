<?php
// =====================================================================
// LANDLORD DASHBOARD
// This page shows a logged-in landlord their overview stats, listings,
// an add/edit property form, and tenant interest requests.
// =====================================================================

require_once __DIR__ . '/config.php';

// Use manual check so we can diagnose unexpected redirects.
// (require_login() ensures a session exists / user is authenticated)
require_login();

// Get the role of the currently logged-in user from the session
$currentRole = $_SESSION['user_type'] ?? '';

// ---------------------------------------------------------------------
// ROLE GUARD: only landlords may view this page.
// If the session role isn't 'landlord', block access and show a
// friendly "Access Denied" page with a link to the correct dashboard.
// ---------------------------------------------------------------------
if ($currentRole !== 'landlord') {
  http_response_code(403);
  // Decide where to send the user based on their actual role
  $target = $currentRole === 'admin' ? 'admin_dashboard.php' : ($currentRole === 'tenant' ? 'tenant_dashboard.php' : 'login.php');
  // Inline HTML explaining the mismatch, plus a debug dump of session data
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access Denied</title><link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet"><style>body{font-family:"Manrope",Arial,Helvetica,sans-serif;background:#f7fafc;color:#2d3748;display:flex;align-items:center;justify-content:center;height:100vh;margin:0} .card{background:#fff;padding:22px;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.08);max-width:720px;width:100%} a{color:#2c5f8a;text-decoration:none;margin-right:12px;font-weight:700}</style></head><body><div class="card"><h2>Access blocked — role mismatch</h2><p>Your current session role is <strong>' . htmlspecialchars($currentRole) . "'</strong>. The Landlord Dashboard requires the 'landlord' role.</p><p>If you expected to be a landlord, please <a href=\"logout.php\">log out</a> and sign in with the correct landlord account.</p><p>You can go to your dashboard: <a href=\"" . $target . "\">Continue</a></p><pre style=\"background:#f1f5f9;padding:10px;border-radius:8px;margin-top:12px;overflow:auto;\">Session data:\n" . htmlspecialchars(print_r($_SESSION, true)) . "</pre></div></body></html>";
  exit();
}

// ---------------------------------------------------------------------
// Load current user info for display in the sidebar (name, avatar, etc.)
// ---------------------------------------------------------------------
$user = current_user();
$fullName = $user['full_name'] ?? $_SESSION['username'];
// Use the first letter of the name as an avatar placeholder
$avatarLetter = strtoupper(substr($fullName, 0, 1));
$landlordId = intval($_SESSION['user_id']);

// Variables used to hold form validation errors / success messages
// and to track whether we're currently in "edit property" mode.
$propertyErrors = [];
$propertySuccess = '';
$editingHouse = null;
$isEditing = false;
function processSingleUpload(string $fieldName, string $uploadDir, array &$errors): ?string {
    if (empty($_FILES[$fieldName]['name']) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed for ' . htmlspecialchars($_FILES[$fieldName]['name']) . '.';
        return null;
    }

    $tmpName = $_FILES[$fieldName]['tmp_name'];
    if (!is_uploaded_file($tmpName) || getimagesize($tmpName) === false) {
        $errors[] = 'The selected file for ' . htmlspecialchars($_FILES[$fieldName]['name']) . ' is not a valid image.';
        return null;
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        $errors[] = 'Unable to create upload directory.';
        return null;
    }

    $extension = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION) ?: 'jpg');
    $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'jpg';
    $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($tmpName, $destination)) {
        $errors[] = 'Failed to save uploaded file.';
        return null;
    }

    return 'uploads/' . $safeName;
}

function processMultipleUploads(string $fieldName, string $uploadDir, array &$errors): array {
    $uploadedPaths = [];
    if (empty($_FILES[$fieldName]['name'])) {
        return $uploadedPaths;
    }

    $fileNames = is_array($_FILES[$fieldName]['name']) ? $_FILES[$fieldName]['name'] : [$_FILES[$fieldName]['name']];
    $tmpNames = is_array($_FILES[$fieldName]['tmp_name']) ? $_FILES[$fieldName]['tmp_name'] : [$_FILES[$fieldName]['tmp_name']];
    $errorCodes = is_array($_FILES[$fieldName]['error']) ? $_FILES[$fieldName]['error'] : [$_FILES[$fieldName]['error']];

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        $errors[] = 'Unable to create upload directory.';
        return $uploadedPaths;
    }

    foreach ($tmpNames as $index => $tmpName) {
        if (empty($fileNames[$index]) || !is_uploaded_file($tmpName)) {
            continue;
        }

        if (($errorCodes[$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed for ' . htmlspecialchars($fileNames[$index]) . '.';
            continue;
        }

        if (getimagesize($tmpName) === false) {
            $errors[] = 'The selected file ' . htmlspecialchars($fileNames[$index]) . ' is not a valid image.';
            continue;
        }

        $extension = strtolower(pathinfo($fileNames[$index], PATHINFO_EXTENSION) ?: 'jpg');
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'jpg';
        $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = $uploadDir . '/' . $safeName;

        if (move_uploaded_file($tmpName, $destination)) {
            $uploadedPaths[] = 'uploads/' . $safeName;
        } else {
            $errors[] = 'Failed to save uploaded file ' . htmlspecialchars($fileNames[$index]) . '.';
        }
    }

    return $uploadedPaths;
}

// Open a database connection for this request
$conn = db_connect();

// =====================================================================
// POST HANDLER: request_action
// Handles a landlord approving/rejecting/resetting a tenant's interest
// request. Uses a JOIN to make sure the request belongs to a house
// owned by this landlord (prevents tampering with other landlords' data).
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_action') {
    $requestId = intval($_POST['request_id'] ?? 0);
    $requestAction = trim($_POST['request_action'] ?? '');
    $allowed = ['approve' => 'accepted', 'reject' => 'rejected', 'reset' => 'pending'];

    if ($requestId > 0 && isset($allowed[$requestAction])) {
        $status = $allowed[$requestAction];
        $stmt = mysqli_prepare($conn, 'UPDATE interest_request ir JOIN house h ON ir.house_id = h.house_id SET ir.request_status = ? WHERE ir.request_id = ? AND h.landlord_id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sii', $status, $requestId, $landlordId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    header('Location: landlord_dashboard.php');
    exit();
}

// =====================================================================
// POST HANDLER: create_property
// Handles submission of the "Add New Property" form, including
// validation, primary image and secondary images uploads.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_property') {
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $houseType = trim($_POST['house_type'] ?? 'Other');
    $price = trim($_POST['price'] ?? '');
    $bedrooms = intval($_POST['bedrooms'] ?? 0);
    $bathrooms = intval($_POST['bathrooms'] ?? 0);
    $amenities = trim($_POST['amenities'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($title === '' || $address === '' || $city === '' || $price === '' || !is_numeric($price)) {
        $propertyErrors[] = 'Please provide a valid title, address, city, and rent amount.';
    }

    if (empty($propertyErrors)) {
        $uploadDir = __DIR__ . '/uploads';
        $primaryImagePath = processSingleUpload('primary_image', $uploadDir, $propertyErrors);
        $secondaryImagePaths = processMultipleUploads('secondary_images', $uploadDir, $propertyErrors);
        if (empty($secondaryImagePaths)) {
            $singleSec = processSingleUpload('secondary_image', $uploadDir, $propertyErrors);
            if ($singleSec !== null) {
                $secondaryImagePaths[] = $singleSec;
            }
        }
    }

    if (empty($propertyErrors)) {
        $stmt = mysqli_prepare(
          $conn,
          'INSERT INTO house (landlord_id, title, description, house_type, address, city, price, bedrooms, bathrooms, furnishing, availability_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)' 
        );

        if ($stmt) {
          $status = 'available';
          mysqli_stmt_bind_param($stmt, 'isssssdiiss', $landlordId, $title, $description, $houseType, $address, $city, $price, $bedrooms, $bathrooms, $amenities, $status);
          if (mysqli_stmt_execute($stmt)) {
            $houseId = mysqli_insert_id($conn);

            // Insert primary image (is_primary = 1)
            if ($primaryImagePath !== null) {
                $pStmt = mysqli_prepare($conn, 'INSERT INTO house_image (house_id, image_url, is_primary) VALUES (?, ?, 1)');
                if ($pStmt) {
                    mysqli_stmt_bind_param($pStmt, 'is', $houseId, $primaryImagePath);
                    mysqli_stmt_execute($pStmt);
                    mysqli_stmt_close($pStmt);
                }
            }

            // Insert secondary images (is_primary = 0)
            if (!empty($secondaryImagePaths)) {
                $sStmt = mysqli_prepare($conn, 'INSERT INTO house_image (house_id, image_url, is_primary) VALUES (?, ?, 0)');
                if ($sStmt) {
                    foreach ($secondaryImagePaths as $secPath) {
                        mysqli_stmt_bind_param($sStmt, 'is', $houseId, $secPath);
                        mysqli_stmt_execute($sStmt);
                    }
                    mysqli_stmt_close($sStmt);
                }
            }

            $propertySuccess = 'Property added successfully.';
            header('Location: landlord_dashboard.php');
            exit();
          } else {
            $propertyErrors[] = 'Unable to save property. Please try again.';
          }
          mysqli_stmt_close($stmt);
        } else {
          $propertyErrors[] = 'Property form could not be processed. Please check schema setup.';
        }
    }
}

// =====================================================================
// POST HANDLER: update_property
// Handles editing an existing listing: updates fields, handles deletes,
// and updates primary / secondary images.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_property') {
    $houseId = intval($_POST['house_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $houseType = trim($_POST['house_type'] ?? 'Other');
    $price = trim($_POST['price'] ?? '');
    $bedrooms = intval($_POST['bedrooms'] ?? 0);
    $bathrooms = intval($_POST['bathrooms'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $amenities = trim($_POST['amenities'] ?? '');
    $availabilityStatus = trim($_POST['availability_status'] ?? 'available');

    if ($houseId <= 0 || $title === '' || $address === '' || $city === '' || $price === '' || !is_numeric($price)) {
        $propertyErrors[] = 'Please provide a valid listing and make sure all required fields are filled.';
    }

    if (!in_array($availabilityStatus, ['available', 'pending', 'rented'], true)) {
        $availabilityStatus = 'available';
    }

    if (empty($propertyErrors)) {
        $uploadDir = __DIR__ . '/uploads';
        $primaryImagePath = processSingleUpload('primary_image', $uploadDir, $propertyErrors);
        $secondaryImagePaths = processMultipleUploads('secondary_images', $uploadDir, $propertyErrors);
        if (empty($secondaryImagePaths)) {
            $singleSec = processSingleUpload('secondary_image', $uploadDir, $propertyErrors);
            if ($singleSec !== null) {
                $secondaryImagePaths[] = $singleSec;
            }
        }
    }

    if (empty($propertyErrors)) {
        $updateStmt = mysqli_prepare(
            $conn,
            'UPDATE house SET title = ?, description = ?, house_type = ?, address = ?, city = ?, price = ?, bedrooms = ?, bathrooms = ?, furnishing = ?, availability_status = ? WHERE house_id = ? AND landlord_id = ?'
        );

        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, 'sssssdiissii', $title, $description, $houseType, $address, $city, $price, $bedrooms, $bathrooms, $amenities, $availabilityStatus, $houseId, $landlordId);
            if (mysqli_stmt_execute($updateStmt)) {
                // Delete any images the landlord checked for removal
                if (!empty($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                    $deleteIds = array_filter(array_map('intval', $_POST['delete_images']));
                    if (!empty($deleteIds)) {
                        $deleteList = implode(',', $deleteIds);
                        $deleteSql = "DELETE hi FROM house_image hi JOIN house h ON hi.house_id = h.house_id WHERE hi.image_id IN ($deleteList) AND hi.house_id = ? AND h.landlord_id = ?";
                        $deleteStmt = mysqli_prepare($conn, $deleteSql);
                        if ($deleteStmt) {
                            mysqli_stmt_bind_param($deleteStmt, 'ii', $houseId, $landlordId);
                            mysqli_stmt_execute($deleteStmt);
                            mysqli_stmt_close($deleteStmt);
                        }
                    }
                }

                // If new primary image is uploaded, unmark existing primary image and insert new primary image
                if ($primaryImagePath !== null) {
                    $unmarkStmt = mysqli_prepare($conn, 'UPDATE house_image SET is_primary = 0 WHERE house_id = ?');
                    if ($unmarkStmt) {
                        mysqli_stmt_bind_param($unmarkStmt, 'i', $houseId);
                        mysqli_stmt_execute($unmarkStmt);
                        mysqli_stmt_close($unmarkStmt);
                    }

                    $insertPStmt = mysqli_prepare($conn, 'INSERT INTO house_image (house_id, image_url, is_primary) VALUES (?, ?, 1)');
                    if ($insertPStmt) {
                        mysqli_stmt_bind_param($insertPStmt, 'is', $houseId, $primaryImagePath);
                        mysqli_stmt_execute($insertPStmt);
                        mysqli_stmt_close($insertPStmt);
                    }
                }

                // If new secondary images are uploaded, insert them with is_primary = 0
                if (!empty($secondaryImagePaths)) {
                    $insertSStmt = mysqli_prepare($conn, 'INSERT INTO house_image (house_id, image_url, is_primary) VALUES (?, ?, 0)');
                    if ($insertSStmt) {
                        foreach ($secondaryImagePaths as $secPath) {
                            mysqli_stmt_bind_param($insertSStmt, 'is', $houseId, $secPath);
                            mysqli_stmt_execute($insertSStmt);
                        }
                        mysqli_stmt_close($insertSStmt);
                    }
                }

                mysqli_stmt_close($updateStmt);
                header('Location: landlord_dashboard.php');
                exit();
            }
            mysqli_stmt_close($updateStmt);
        }
    }
}

// =====================================================================
// POST HANDLER: delete_property
// Delete listing and its related records securely.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_property') {
    $houseId = intval($_POST['house_id'] ?? 0);
    if ($houseId > 0) {
        $imgDelStmt = mysqli_prepare($conn, 'DELETE hi FROM house_image hi JOIN house h ON hi.house_id = h.house_id WHERE hi.house_id = ? AND h.landlord_id = ?');
        if ($imgDelStmt) {
            mysqli_stmt_bind_param($imgDelStmt, 'ii', $houseId, $landlordId);
            mysqli_stmt_execute($imgDelStmt);
            mysqli_stmt_close($imgDelStmt);
        }
        $reqDelStmt = mysqli_prepare($conn, 'DELETE ir FROM interest_request ir JOIN house h ON ir.house_id = h.house_id WHERE ir.house_id = ? AND h.landlord_id = ?');
        if ($reqDelStmt) {
            mysqli_stmt_bind_param($reqDelStmt, 'ii', $houseId, $landlordId);
            mysqli_stmt_execute($reqDelStmt);
            mysqli_stmt_close($reqDelStmt);
        }
        $houseDelStmt = mysqli_prepare($conn, 'DELETE FROM house WHERE house_id = ? AND landlord_id = ?');
        if ($houseDelStmt) {
            mysqli_stmt_bind_param($houseDelStmt, 'ii', $houseId, $landlordId);
            mysqli_stmt_execute($houseDelStmt);
            mysqli_stmt_close($houseDelStmt);
        }
    }
    header('Location: landlord_dashboard.php');
    exit();
}

// =====================================================================
// GET HANDLER: ?edit_house_id=...
// If the landlord clicked "Edit" on a listing (and we're not already
// mid-way through processing an update_property POST above), load that
// listing's data (and its images) so the edit form can be pre-filled.
// =====================================================================
if (!$isEditing && isset($_GET['edit_house_id'])) {
    $editHouseId = intval($_GET['edit_house_id']);
    if ($editHouseId > 0) {
        // Ownership check: house_id AND landlord_id must match
        $editStmt = mysqli_prepare(
            $conn,
            'SELECT house_id, title, description, house_type, address, city, price, bedrooms, bathrooms, furnishing AS amenities, availability_status FROM house WHERE house_id = ? AND landlord_id = ? LIMIT 1'
        );
        if ($editStmt) {
            mysqli_stmt_bind_param($editStmt, 'ii', $editHouseId, $landlordId);
            mysqli_stmt_execute($editStmt);
            $editResult = mysqli_stmt_get_result($editStmt);
            if ($editHouse = mysqli_fetch_assoc($editResult)) {
                $editingHouse = $editHouse;
                $editingHouse['images'] = [];
                // Load all images belonging to this house for the edit form
                $imageLoadStmt = mysqli_prepare($conn, 'SELECT image_id, image_url, is_primary FROM house_image WHERE house_id = ? ORDER BY is_primary DESC, image_id ASC');
                if ($imageLoadStmt) {
                    mysqli_stmt_bind_param($imageLoadStmt, 'i', $editHouseId);
                    mysqli_stmt_execute($imageLoadStmt);
                    $imageResult = mysqli_stmt_get_result($imageLoadStmt);
                    while ($imageRow = mysqli_fetch_assoc($imageResult)) {
                        $editingHouse['images'][] = $imageRow;
                    }
                    mysqli_stmt_close($imageLoadStmt);
                }
                $isEditing = true;
            } else {
                // No matching house for this landlord -> show an error instead
                $propertyErrors[] = 'Listing not found or you do not have permission to edit it.';
            }
            mysqli_stmt_close($editStmt);
        }
    }
}

// Safety net: if we're in edit mode but somehow don't have the images
// loaded yet (e.g. came from the update_property POST branch above,
// which doesn't fetch images), fetch them now so the edit form can
// show existing images with delete checkboxes.
if ($isEditing && !isset($editingHouse['images']) && !empty($editingHouse['house_id'])) {
    $editingHouse['images'] = [];
    $imageLoadStmt = mysqli_prepare($conn, 'SELECT image_id, image_url FROM house_image WHERE house_id = ? ORDER BY image_id ASC');
    if ($imageLoadStmt) {
        mysqli_stmt_bind_param($imageLoadStmt, 'i', $editingHouse['house_id']);
        mysqli_stmt_execute($imageLoadStmt);
        $imageResult = mysqli_stmt_get_result($imageLoadStmt);
        while ($imageRow = mysqli_fetch_assoc($imageResult)) {
            $editingHouse['images'][] = $imageRow;
        }
        mysqli_stmt_close($imageLoadStmt);
    }
}

// =====================================================================
// LOAD DATA FOR THE PAGE: all of this landlord's houses + summary stats
// =====================================================================
$houses = [];
$listingStats = [
    'total' => 0,
    'available' => 0,
    'pending' => 0,
    'rented' => 0,
    'pending_requests' => 0,
];

$houseStmt = mysqli_prepare(
    $conn,
    'SELECT house_id, title, house_type, address, city, price, bedrooms, bathrooms, availability_status, description, created_at FROM house WHERE landlord_id = ? ORDER BY created_at DESC'
);

if ($houseStmt) {
    mysqli_stmt_bind_param($houseStmt, 'i', $landlordId);
    mysqli_stmt_execute($houseStmt);
    $houseResult = mysqli_stmt_get_result($houseStmt);

    // Fetch each house and tally up the stats used in the Overview tab
    while ($row = mysqli_fetch_assoc($houseResult)) {
        $row['first_image'] = ''; // placeholder, filled in below
        $houses[] = $row;
        $listingStats['total']++;
        if ($row['availability_status'] === 'available') {
            $listingStats['available']++;
        } elseif ($row['availability_status'] === 'rented') {
            $listingStats['rented']++;
        } else {
            $listingStats['pending']++;
        }
    }

    // For each house, look up its first image (used as the card thumbnail).
    // Falls back to a generated "No Image Available" placeholder SVG.
    foreach ($houses as &$house) {
        $house['all_images'] = [];
        $imageStmt = mysqli_prepare($conn, 'SELECT image_id, image_url FROM house_image WHERE house_id = ? ORDER BY is_primary DESC, image_id ASC');
        if ($imageStmt) {
            mysqli_stmt_bind_param($imageStmt, 'i', $house['house_id']);
            mysqli_stmt_execute($imageStmt);
            $imageResult = mysqli_stmt_get_result($imageStmt);
            while ($imageRow = mysqli_fetch_assoc($imageResult)) {
                if (!empty($imageRow['image_url'])) {
                    $house['all_images'][] = $imageRow['image_url'];
                }
            }
            mysqli_stmt_close($imageStmt);
        }
        $house['first_image'] = $house['all_images'][0] ?? '';
        if (empty($house['first_image'])) {
            $house['first_image'] = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="240"><rect width="400" height="240" fill="#f3f4f6"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-family="Arial, Helvetica, sans-serif" font-size="20">No Image Available</text></svg>');
        }
    }
    unset($house); // break the reference from the foreach loop above
}

// =====================================================================
// LOAD DATA FOR THE PAGE: tenant interest requests for this landlord's
// houses, including tenant contact info.
// =====================================================================
$requests = [];
$requestStmt = mysqli_prepare(
    $conn,
    'SELECT ir.request_id, ir.message, ir.request_status, ir.requested_at, h.title AS house_title, u.full_name AS tenant_name, u.phone_number AS tenant_phone
     FROM interest_request ir
     JOIN house h ON ir.house_id = h.house_id
     JOIN users u ON ir.tenant_id = u.id
     WHERE h.landlord_id = ?
     ORDER BY ir.requested_at DESC'
);

if ($requestStmt) {
    mysqli_stmt_bind_param($requestStmt, 'i', $landlordId);
    mysqli_stmt_execute($requestStmt);
    $requestResult = mysqli_stmt_get_result($requestStmt);

    while ($row = mysqli_fetch_assoc($requestResult)) {
        $requests[] = $row;
        if ($row['request_status'] === 'pending') {
            $listingStats['pending_requests']++;
        }
    }
}

// Done with the database for this request
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Landlord Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="dashboard.css">
</head>
<body>

<!-- SIDEBAR: branding, logged-in user info, and tab navigation -->
<aside class="sidebar">

  <div class="logo"><span>🏠Gharelu</span></div>
  <!-- Avatar + name now doubles as the Profile link -->
  <a class="user-info" href="profile.php" title="View Profile">
    <div class="av"><?php echo htmlspecialchars($avatarLetter); ?></div>
    <div>
      <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
      <div class="role">Landlord</div>
    </div>
  </a>

  <nav>
    <!-- Each nav button calls switchTab() (in script.js) to show/hide tab panes -->
    <button class="nav-link<?php echo $isEditing ? '' : ' active'; ?>" onclick="switchTab('overview', this)">📊 Overview</button>
    <button class="nav-link" onclick="switchTab('listings', this)">🏘️ My Listings <span class="n-badge"><?php echo $listingStats['total']; ?></span></button>
    <!-- "Add Property" tab is auto-activated when we're in edit mode -->
    <button class="nav-link<?php echo $isEditing ? ' active' : ''; ?>" onclick="switchTab('add', this)">➕ Add Property</button>
    <button class="nav-link" onclick="switchTab('requests', this)">📩 Requests <span class="n-badge"><?php echo $listingStats['pending_requests']; ?></span></button>
  </nav>

  <div class="sidebar-bottom">
    <a class="nav-link" href="logout.php">🚪 Logout</a>
  </div>
</aside>

<!-- MAIN CONTENT AREA -->
<div class="main">
  <header class="topbar">
    <h2 id="page-title"><?php echo $isEditing ? 'Edit Property' : 'Overview'; ?></h2>
    <div class="topbar-right">
      <span class="notif">🔔 <?php echo $listingStats['pending_requests']; ?> pending requests</span>
    </div>
  </header>

  <div class="content">

    <!-- ================= OVERVIEW TAB ================= -->
    <div class="tab-pane<?php echo $isEditing ? '' : ' active'; ?>" id="tab-overview">
      <!-- Summary stat cards -->
      <div class="stats">
        <div class="stat">
          <div class="stat-icon ic-o">🏘️</div>
          <div><div class="stat-num"><?php echo $listingStats['total']; ?></div><div class="stat-label">Total Listings</div></div>
        </div>
        <div class="stat">
          <div class="stat-icon ic-g">✅</div>
          <div><div class="stat-num"><?php echo $listingStats['available']; ?></div><div class="stat-label">Available</div></div>
        </div>
        <div class="stat">
          <div class="stat-icon ic-b">🔑</div>
          <div><div class="stat-num"><?php echo $listingStats['rented']; ?></div><div class="stat-label">Rented</div></div>
        </div>
        <div class="stat">
          <div class="stat-icon ic-r">📩</div>
          <div><div class="stat-num"><?php echo $listingStats['pending_requests']; ?></div><div class="stat-label">Pending Requests</div></div>
        </div>
      </div>

      <div class="two-col">
        <!-- Preview of the 3 most recent listings -->
        <div class="panel">
          <h4>Recent Listings</h4>
          <?php foreach (array_slice($houses, 0, 3) as $house): ?>
            <?php $statusLabel = $house['availability_status'] === 'available' ? 'Available' : ($house['availability_status'] === 'rented' ? 'Rented' : 'Pending'); ?>
            <div class="mini-row">
              <div>
                <div class="mr-name"><?php echo htmlspecialchars($house['title']); ?></div>
                <div class="mr-sub">📍 <?php echo htmlspecialchars($house['city']); ?></div>
              </div>
              <span class="badge badge-<?php echo htmlspecialchars($house['availability_status']); ?>"><?php echo $statusLabel; ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <!-- Preview of the 3 most recent tenant requests -->
        <div class="panel">
          <h4>Recent Requests</h4>
          <?php foreach (array_slice($requests, 0, 3) as $request): ?>
            <div class="mini-row">
              <div>
                <div class="mr-name"><?php echo htmlspecialchars($request['tenant_name']); ?></div>
                <div class="mr-sub"><?php echo htmlspecialchars($request['house_title']); ?></div>
              </div>
              <span class="badge badge-<?php echo htmlspecialchars($request['request_status']); ?>"><?php echo ucfirst($request['request_status']); ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ================= LISTINGS TAB ================= -->
    <div class="tab-pane" id="tab-listings">
      <div class="sh">
        <h3>My Listings</h3>
        <button class="btn btn-primary" onclick="switchTab('add', document.querySelector('[onclick*=add]'))">➕ Add Property</button>
      </div>

      <!-- Client-side filter buttons (filtering handled in script.js) -->
      <div class="filters">
        <button class="fbtn active" onclick="filterListings('all', this)">All (<?php echo $listingStats['total']; ?>)</button>
        <button class="fbtn" onclick="filterListings('available', this)">Available (<?php echo $listingStats['available']; ?>)</button>
        <button class="fbtn" onclick="filterListings('pending', this)">Pending (<?php echo $listingStats['pending']; ?>)</button>
        <button class="fbtn" onclick="filterListings('rented', this)">Rented (<?php echo $listingStats['rented']; ?>)</button>
      </div>

      <!-- Grid of property cards, one per listing -->
      <div class="listings-grid">
        <?php foreach ($houses as $listing): ?>
          <?php 
            $badgeClass = 'badge-' . $listing['availability_status']; 
            $statusLabel = $listing['availability_status'] === 'available' ? 'Available' : ($listing['availability_status'] === 'rented' ? 'Rented' : 'Pending');
          ?>
          <div class="prop-card" data-status="<?php echo htmlspecialchars($listing['availability_status']); ?>">
            <div class="prop-img" style="cursor:default;" title="Property main image">
              <?php if (!empty($listing['first_image'])): ?>
                <img src="<?php echo htmlspecialchars($listing['first_image']); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" style="width:100%;height:100%;object-fit:cover;">
              <?php endif; ?>
              <span class="badge <?php echo $badgeClass; ?>"><?php echo $statusLabel; ?></span>
            </div>
            <div class="prop-body">
              <div class="prop-title"><?php echo htmlspecialchars($listing['title']); ?></div>
              <div class="prop-loc">📍 <?php echo htmlspecialchars($listing['city']); ?></div>
              <div class="prop-meta"><span>🛏 <?php echo htmlspecialchars($listing['bedrooms']); ?> Bed</span><span>🚿 <?php echo htmlspecialchars($listing['bathrooms']); ?> Bath</span></div>
              <div class="prop-price">Rs <?php echo number_format($listing['price']); ?> <small>/ month</small></div>
              <div class="prop-footer">
                <select>
                  <option<?php echo $listing['availability_status'] === 'available' ? ' selected' : ''; ?>>🟢 Available</option>
                  <option<?php echo $listing['availability_status'] === 'pending' ? ' selected' : ''; ?>>🟡 Pending</option>
                  <option<?php echo $listing['availability_status'] === 'rented' ? ' selected' : ''; ?>>🔵 Rented</option>
                </select>
                <div class="prop-actions">
                  <a class="btn btn-ghost" style="padding:5px 10px" href="landlord_dashboard.php?edit_house_id=<?php echo $listing['house_id']; ?>">✏️ Edit</a>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this property listing?');">
                    <input type="hidden" name="action" value="delete_property">
                    <input type="hidden" name="house_id" value="<?php echo intval($listing['house_id']); ?>">
                    <button type="submit" class="btn btn-danger" style="padding:5px 10px;">🗑 Delete</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ================= ADD / EDIT PROPERTY TAB ================= -->
    <div class="tab-pane<?php echo $isEditing ? ' active' : ''; ?>" id="tab-add">
      <div class="sh"><h3><?php echo $isEditing ? 'Edit Property' : 'Add New Property'; ?></h3></div>
      <div class="form-card">
        <!-- Show validation errors or a success message above the form -->
        <?php if (!empty($propertyErrors)): ?>
          <div class="error-message">
            <ul>
              <?php foreach ($propertyErrors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php elseif ($propertySuccess): ?>
          <div class="success-message"><?php echo htmlspecialchars($propertySuccess); ?></div>
        <?php endif; ?>
        <!-- Single form used for both "create" and "update", switched via hidden "action" field -->
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="action" value="<?php echo $isEditing ? 'update_property' : 'create_property'; ?>">
          <?php if ($isEditing): ?>
            <input type="hidden" name="house_id" value="<?php echo htmlspecialchars($editingHouse['house_id']); ?>">
          <?php endif; ?>
          <div class="form-grid">
            <div class="fg full"><label>Title *</label><input type="text" name="title" placeholder="e.g. 2BHK Apartment in Lalitpur" value="<?php echo htmlspecialchars($editingHouse['title'] ?? ''); ?>" required></div>
            <div class="fg"><label>Location *</label><input type="text" name="city" placeholder="e.g. Kathmandu" value="<?php echo htmlspecialchars($editingHouse['city'] ?? ''); ?>" required></div>
            <div class="fg"><label>Type</label>
              <select name="house_type">
                <?php $typeOptions = ['Apartment','House','Room','Flat','Studio']; ?>
                <?php foreach ($typeOptions as $typeOption): ?>
                  <option<?php echo (isset($editingHouse['house_type']) && $editingHouse['house_type'] === $typeOption) ? ' selected' : ''; ?>><?php echo htmlspecialchars($typeOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg"><label>Monthly Rent (Rs) *</label><input type="number" name="price" placeholder="25000" value="<?php echo htmlspecialchars($editingHouse['price'] ?? ''); ?>" required></div>
            <div class="fg"><label>Address *</label><input type="text" name="address" placeholder="e.g. Budhanilkantha" value="<?php echo htmlspecialchars($editingHouse['address'] ?? ''); ?>" required></div>
            <div class="fg"><label>Bedrooms</label><input type="number" name="bedrooms" placeholder="2" min="0" value="<?php echo htmlspecialchars($editingHouse['bedrooms'] ?? ''); ?>"></div>
            <div class="fg"><label>Bathrooms</label><input type="number" name="bathrooms" placeholder="1" min="0" value="<?php echo htmlspecialchars($editingHouse['bathrooms'] ?? ''); ?>"></div>
            <div class="fg full"><label>Amenities</label><input type="text" name="amenities" placeholder="WiFi, Parking, Water, Generator" value="<?php echo htmlspecialchars($editingHouse['amenities'] ?? ''); ?>"></div>
            <!-- Only shown when editing and the listing already has images:
                 each image can be checked for deletion on save -->
                    <?php if ($isEditing && !empty($editingHouse['images'])): ?>
              <div class="fg full"><label>Existing Images</label>
                <div style="display:flex; flex-wrap:wrap; gap:12px; margin-top:6px;">
                  <?php foreach ($editingHouse['images'] as $img): ?>
                    <div style="text-align:center; background:#f8fafc; padding:8px; border:1px solid #e2e8f0; border-radius:8px;">
                      <img src="<?php echo htmlspecialchars($img['image_url']); ?>" alt="Listing image" style="width:110px; height:75px; object-fit:cover; display:block; border-radius:6px; margin-bottom:6px;">
                      <span style="font-size:10px; font-weight:700; display:block; margin-bottom:4px; color:<?php echo !empty($img['is_primary']) ? '#2c5f8a' : '#64748b'; ?>;">
                        <?php echo !empty($img['is_primary']) ? '⭐ PRIMARY' : 'SECONDARY'; ?>
                      </span>
                      <label style="font-size:11px; color:#e53e3e; cursor:pointer;"><input type="checkbox" name="delete_images[]" value="<?php echo intval($img['image_id']); ?>"> Delete</label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <div class="fg full"><label>Upload Primary Image (Main Cover Photo)</label><input type="file" name="primary_image" accept="image/*"></div>
            <div class="fg full"><label>Upload Secondary Images (Additional Photos)</label><input type="file" name="secondary_images[]" multiple accept="image/*"></div>
            <div class="fg full"><label>Description</label><textarea name="description" placeholder="Describe the property..."><?php echo htmlspecialchars($editingHouse['description'] ?? ''); ?></textarea></div>
            <!-- Availability status is only editable on the edit form (new listings default to "available") -->
            <?php if ($isEditing): ?>
              <div class="fg full"><label>Availability Status</label>
                <select name="availability_status">
                  <option value="available"<?php echo ($editingHouse['availability_status'] ?? '') === 'available' ? ' selected' : ''; ?>>🟢 Available</option>
                  <option value="pending"<?php echo ($editingHouse['availability_status'] ?? '') === 'pending' ? ' selected' : ''; ?>>🟡 Pending</option>
                  <option value="rented"<?php echo ($editingHouse['availability_status'] ?? '') === 'rented' ? ' selected' : ''; ?>>🔵 Rented</option>
                </select>
              </div>
            <?php endif; ?>
          </div>
          <br>
          <button class="btn btn-primary"><?php echo $isEditing ? '💾 Update Listing' : '🏠 Publish Listing'; ?></button>
          <?php if ($isEditing): ?>
            <a class="btn btn-ghost" href="landlord_dashboard.php">Cancel</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- ================= REQUESTS TAB ================= -->
    <div class="tab-pane" id="tab-requests">
      <div class="sh"><h3>Tenant Requests</h3></div>
      <div class="req-list">
        <?php foreach ($requests as $request): ?>
          <?php $badgeClass = 'badge-' . $request['request_status']; ?>
          <div class="req-card">
            <div>
              <div class="req-prop"><?php echo htmlspecialchars($request['house_title']); ?></div>
              <div class="req-name"><?php echo htmlspecialchars($request['tenant_name']); ?></div>
              <div class="req-contact">
                <?php if (!empty($request['tenant_phone'])): ?>
                  <a href="tel:<?php echo htmlspecialchars($request['tenant_phone']); ?>">📞 <?php echo htmlspecialchars($request['tenant_phone']); ?></a>
                <?php endif; ?>
              </div>
              <div class="req-msg"><?php echo htmlspecialchars($request['message']); ?></div>
              <div class="req-date">🕐 <?php echo htmlspecialchars($request['requested_at']); ?></div>
            </div>
            <div class="req-actions">
              <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($request['request_status']); ?></span>
              <!-- Action buttons shown depend on the current request status -->
              <form method="POST" style="display:inline-block; margin-top:8px;">
                <input type="hidden" name="action" value="request_action">
                <input type="hidden" name="request_id" value="<?php echo intval($request['request_id']); ?>">
                <?php if ($request['request_status'] === 'pending'): ?>
                  <button class="btn btn-success" type="submit" name="request_action" value="approve">✅ Accept</button>
                  <button class="btn btn-danger" type="submit" name="request_action" value="reject">❌ Reject</button>
                <?php elseif ($request['request_status'] === 'accepted'): ?>
                  <button class="btn btn-danger" type="submit" name="request_action" value="reject">❌ Reject</button>
                  <button class="btn btn-ghost" type="submit" name="request_action" value="reset">↩ Reset</button>
                <?php else: ?>
                  <button class="btn btn-ghost" type="submit" name="request_action" value="reset">↩ Reset</button>
                <?php endif; ?>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<script src="script.js"></script>
</body>
</html>