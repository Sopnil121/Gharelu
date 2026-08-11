<?php
// =====================================================================
// LANDLORD DASHBOARD
// This page shows a logged-in landlord their overview stats, listings,
// an add/edit property form, and tenant interest requests.
// =====================================================================

require_once __DIR__ . '/../includes/config.php';

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
  $target = $currentRole === 'admin' ? '../admin/dashboard.php' : ($currentRole === 'tenant' ? '../tenant/dashboard.php' : '../login.php');
  // Inline HTML explaining the mismatch, plus a debug dump of session data
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access Denied</title><link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet"><style>body{font-family:"Manrope",Arial,Helvetica,sans-serif;background:#f7fafc;color:#2d3748;display:flex;align-items:center;justify-content:center;height:100vh;margin:0} .card{background:#fff;padding:22px;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.08);max-width:720px;width:100%} a{color:#2c5f8a;text-decoration:none;margin-right:12px;font-weight:700}</style></head><body><div class="card"><h2>Access blocked — role mismatch</h2><p>Your current session role is <strong>' . htmlspecialchars($currentRole) . "'</strong>. The Landlord Dashboard requires the 'landlord' role.</p><p>If you expected to be a landlord, please <a href=\"../logout.php\">log out</a> and sign in with the correct landlord account.</p><p>You can go to your dashboard: <a href=\"" . $target . "\">Continue</a></p><pre style=\"background:#f1f5f9;padding:10px;border-radius:8px;margin-top:12px;overflow:auto;\">Session data:\n" . htmlspecialchars(print_r($_SESSION, true)) . "</pre></div></body></html>";
  exit();
}

// ---------------------------------------------------------------------
// Load current user info for display in the sidebar (name, avatar, etc.)
// ---------------------------------------------------------------------
$user = current_user();
$fullName = $user['full_name'] ?? $_SESSION['username'];
// Use the first letter of the name as an avatar placeholder
$avatarLetter = strtoupper(substr($fullName, 0, 1));

// Variables used to hold form validation errors / success messages
// and to track whether we're currently in "edit property" mode.
$propertyErrors = [];
$propertySuccess = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'added') {
    $propertySuccess = 'Property added successfully and is now pending admin approval.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $propertySuccess = 'Property updated successfully and is now pending admin approval.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $propertySuccess = 'Property deleted successfully.';
}
$editingHouse = null;
$isEditing = false;

// Open a database connection for this request
$conn = db_connect();

// Get the actual landlord_id from the landlord table based on logged-in user_id
$userId = intval($_SESSION['user_id']);
$landlordId = 0;
$landlordQuery = mysqli_prepare($conn, "SELECT landlord_id FROM landlord WHERE user_id = ?");
if ($landlordQuery) {
    mysqli_stmt_bind_param($landlordQuery, "i", $userId);
    mysqli_stmt_execute($landlordQuery);
    $landlordRes = mysqli_stmt_get_result($landlordQuery);
    if ($row = mysqli_fetch_assoc($landlordRes)) {
        $landlordId = intval($row['landlord_id']);
    }
    mysqli_stmt_close($landlordQuery);
}

// Fallback dynamic creation if landlord row does not exist
if ($landlordId === 0) {
    $insLandlord = mysqli_prepare($conn, "INSERT INTO landlord (user_id, verification_status) VALUES (?, 'pending')");
    if ($insLandlord) {
        mysqli_stmt_bind_param($insLandlord, "i", $userId);
        mysqli_stmt_execute($insLandlord);
        $landlordId = mysqli_insert_id($conn);
        mysqli_stmt_close($insLandlord);
    }
}

// =====================================================================
// POST HANDLER: request_action
// Handles a landlord approving/rejecting/resetting a tenant's interest
// request. Uses a JOIN to make sure the request belongs to a house
// owned by this landlord (prevents tampering with other landlords' data).
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_action') {
    $requestId = intval($_POST['request_id'] ?? 0);
    $requestAction = trim($_POST['request_action'] ?? '');
    // Map the button action name to the actual status value stored in DB
    $allowed = ['approve' => 'accepted', 'reject' => 'rejected', 'reset' => 'pending'];

    if ($requestId > 0 && isset($allowed[$requestAction])) {
        $status = $allowed[$requestAction];
        // Update only if the request's house belongs to this landlord
        $stmt = mysqli_prepare($conn, 'UPDATE interest_request ir JOIN house h ON ir.house_id = h.house_id SET ir.request_status = ?, ir.landlord_is_read = 1, ir.tenant_is_read = 0 WHERE ir.request_id = ? AND h.landlord_id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sii', $status, $requestId, $landlordId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    // Redirect back to the dashboard (Post/Redirect/Get pattern to avoid
    // duplicate form resubmission on refresh)
    header('Location: dashboard.php');
    exit();
}

// =====================================================================

// =====================================================================
// POST HANDLER: delete_tenant_review
// Allows a landlord to delete a review they previously submitted
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tenant_review') {
    $reviewId = intval($_POST['review_id'] ?? 0);
    if ($reviewId > 0) {
        $stmt = mysqli_prepare($conn, 'DELETE FROM tenant_review WHERE review_id = ? AND landlord_id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $reviewId, $landlordId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    header('Location: dashboard.php#reviews');
    exit();
}

// =====================================================================
// POST HANDLER: add_tenant_review
// Allows a landlord to review a tenant if they have an accepted request
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_tenant_review') {
    $tenantId = intval($_POST['tenant_id'] ?? 0);
    $houseId = intval($_POST['house_id'] ?? 0);
    $rating = intval($_POST['rating'] ?? 5);
    $comment = trim($_POST['comment'] ?? '');

    if ($tenantId > 0 && $houseId > 0 && $rating >= 1 && $rating <= 5) {
        // Verify the landlord actually accepted a request from this tenant for this property
        $verifyStmt = mysqli_prepare($conn, 'SELECT ir.request_id FROM interest_request ir JOIN house h ON ir.house_id = h.house_id WHERE ir.tenant_id = ? AND h.landlord_id = ? AND ir.house_id = ? AND ir.request_status = "leased" LIMIT 1');
        if ($verifyStmt) {
            mysqli_stmt_bind_param($verifyStmt, 'iii', $tenantId, $landlordId, $houseId);
            mysqli_stmt_execute($verifyStmt);
            $verifyResult = mysqli_stmt_get_result($verifyStmt);
            
            if (mysqli_num_rows($verifyResult) > 0) {
                // Check if review already exists for this tenant in this property
                $checkStmt = mysqli_prepare($conn, 'SELECT review_id FROM tenant_review WHERE landlord_id = ? AND tenant_id = ? AND house_id = ?');
                mysqli_stmt_bind_param($checkStmt, 'iii', $landlordId, $tenantId, $houseId);
                mysqli_stmt_execute($checkStmt);
                $checkRes = mysqli_stmt_get_result($checkStmt);
                $reviewExists = (mysqli_num_rows($checkRes) > 0);
                mysqli_stmt_close($checkStmt);

                if (!$reviewExists) {
                    $reviewStmt = mysqli_prepare($conn, 'INSERT INTO tenant_review (landlord_id, tenant_id, house_id, rating, comment) VALUES (?, ?, ?, ?, ?)');
                    if ($reviewStmt) {
                        mysqli_stmt_bind_param($reviewStmt, 'iiiis', $landlordId, $tenantId, $houseId, $rating, $comment);
                        mysqli_stmt_execute($reviewStmt);
                        mysqli_stmt_close($reviewStmt);
                    }
                }
            }
            mysqli_stmt_close($verifyStmt);
        }
    }
    
    header('Location: dashboard.php#reviews');
    exit();
}

// =====================================================================
// POST HANDLER: create_property
// Handles submission of the "Add New Property" form, including
// validation, optional image uploads, and inserting into the DB.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_property') {
    // Collect and trim form fields
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $houseType = trim($_POST['house_type'] ?? 'Other');
    $price = trim($_POST['price'] ?? '');
    $rooms = intval($_POST['rooms'] ?? 0);
    $bathrooms = intval($_POST['bathrooms'] ?? 0);
    $amenities = trim($_POST['amenities'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $contactPhone = NULL;
    $contactEmail = NULL;

    // Basic required-field / type validation
    if ($title === '' || $address === '' || $province === '' || $district === '' || $price === '' || !is_numeric($price) || floatval($price) <= 0) {
        $propertyErrors[] = 'Please provide a valid title, address, province, district, and rent amount (greater than 0).';
    }

    if (empty($propertyErrors)) {
        // Ensure the uploads directory exists
        $uploadDir = dirname(__DIR__) . '/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 1. Process House Document
        $docUrl = null;
        if (!empty($_FILES['house_document']['name'])) {
            $tmpName = $_FILES['house_document']['tmp_name'];
            $originalName = basename($_FILES['house_document']['name']);
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $safeName = 'doc_' . time() . '_' . uniqid('', true) . ($extension !== '' ? '.' . strtolower($extension) : '');
            $docUploadDir = dirname(__DIR__) . '/uploads/documents';
            if (!is_dir($docUploadDir)) mkdir($docUploadDir, 0755, true);
            $destination = $docUploadDir . '/' . $safeName;
            if (move_uploaded_file($tmpName, $destination)) {
                $docUrl = 'uploads/documents/' . $safeName;
            }
        }

        // 2. Process Property Images
        $uploadedImages = [];
        if (!empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
            foreach ($_FILES['images']['tmp_name'] as $index => $tmpName) {
                if (empty($_FILES['images']['name'][$index]) || !is_uploaded_file($tmpName)) continue;
                $originalName = basename($_FILES['images']['name'][$index]);
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $safeName = time() . '_' . uniqid('', true) . ($extension !== '' ? '.' . strtolower($extension) : '');
                $destination = $uploadDir . '/' . $safeName;
                if (getimagesize($tmpName) !== false && move_uploaded_file($tmpName, $destination)) {
                    $uploadedImages[] = 'uploads/' . $safeName;
                }
            }
        }

        // 3. Insert Property
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO house (landlord_id, title, description, house_type, address, province, district, price, rooms, bathrooms, amenities, availability_status, contact_phone, contact_email, house_document, admin_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")' 
        );

        if ($stmt) {
            $status = 'available';
            mysqli_stmt_bind_param($stmt, 'issssssdiisssss', $landlordId, $title, $description, $houseType, $address, $province, $district, $price, $rooms, $bathrooms, $amenities, $status, $contactPhone, $contactEmail, $docUrl);
            
            if (mysqli_stmt_execute($stmt)) {
                $houseId = mysqli_insert_id($conn);
                $imageInsertError = false;

                // 4. Insert Images
                if (!empty($uploadedImages)) {
                    $imageStmt = mysqli_prepare($conn, 'INSERT INTO house_image (house_id, image_url) VALUES (?, ?)');
                    if ($imageStmt) {
                        foreach ($uploadedImages as $imagePath) {
                            mysqli_stmt_bind_param($imageStmt, 'is', $houseId, $imagePath);
                            if (!mysqli_stmt_execute($imageStmt)) {
                                $imageInsertError = true;
                                break;
                            }
                        }
                        mysqli_stmt_close($imageStmt);
                    } else {
                        $imageInsertError = true;
                    }
                }

                if ($imageInsertError) {
                    mysqli_rollback($conn);
                    $propertyErrors[] = 'Unable to save property images. Please try again.';
                } else {
                    mysqli_commit($conn);
                    header('Location: dashboard.php?msg=added');
                    exit();
                }
            } else {
                mysqli_rollback($conn);
                $propertyErrors[] = 'Unable to save property. Please try again.';
            }
            mysqli_stmt_close($stmt);
        } else {
            mysqli_rollback($conn);
            $propertyErrors[] = 'Property form could not be processed. Please check schema setup.';
        }
    }
}

// =====================================================================
// POST HANDLER: delete_property
// Deletes a house completely from the database.
// Foreign keys (ON DELETE CASCADE) will automatically clean up images,
// favorites, and requests related to this house.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_property') {
    $houseId = intval($_POST['house_id'] ?? 0);
    if ($houseId > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM house WHERE house_id = ? AND landlord_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $houseId, $landlordId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    header('Location: dashboard.php?msg=deleted');
    exit();
}

// =====================================================================
// POST HANDLER: update_property
// Handles editing an existing listing: updates its fields, optionally
// deletes selected existing images, and optionally adds new images.
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_property') {
    // Collect and trim form fields
    $houseId = intval($_POST['house_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $houseType = trim($_POST['house_type'] ?? 'Other');
    $price = trim($_POST['price'] ?? '');
    $rooms = intval($_POST['rooms'] ?? 0);
    $bathrooms = intval($_POST['bathrooms'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $amenities = trim($_POST['amenities'] ?? '');
    $availabilityStatus = trim($_POST['availability_status'] ?? 'available');
    $contactPhone = NULL;
    $contactEmail = NULL;

    // Basic required-field / type validation
    if ($houseId <= 0 || $title === '' || $address === '' || $province === '' || $district === '' || $price === '' || !is_numeric($price) || floatval($price) <= 0) {
        $propertyErrors[] = 'Please provide a valid listing, make sure all required fields are filled, and rent amount is greater than 0.';
    }

    // Whitelist the availability status to only allowed values
    if (!in_array($availabilityStatus, ['available', 'pending', 'rented'], true)) {
        $availabilityStatus = 'available';
    }

    if (empty($propertyErrors)) {
        // Wrap the update + image changes in a transaction for consistency
        mysqli_begin_transaction($conn);
        $updateStmt = mysqli_prepare(
            $conn,
            'UPDATE house SET title = ?, description = ?, house_type = ?, address = ?, province = ?, district = ?, price = ?, rooms = ?, bathrooms = ?, amenities = ?, availability_status = ?, contact_phone = ?, contact_email = ? WHERE house_id = ? AND landlord_id = ?'
        );

        if ($updateStmt) {
            // Note: landlord_id is included in WHERE to ensure landlords
            // can only update their own listings
            mysqli_stmt_bind_param($updateStmt, 'ssssssdiissssii', $title, $description, $houseType, $address, $province, $district, $price, $rooms, $bathrooms, $amenities, $availabilityStatus, $contactPhone, $contactEmail, $houseId, $landlordId);
            if (mysqli_stmt_execute($updateStmt)) {
                $imageManageError = false;

                // -----------------------------------------------------
                // Delete any images the landlord checked for removal.
                // The DELETE query joins back to `house` to double-check
                // ownership (landlord_id) before deleting.
                // -----------------------------------------------------
                if (!empty($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                    $deleteIds = array_filter(array_map('intval', $_POST['delete_images']));
                    if (!empty($deleteIds)) {
                        $deleteList = implode(',', $deleteIds);
                        $deleteSql = "DELETE hi FROM house_image hi JOIN house h ON hi.house_id = h.house_id WHERE hi.image_id IN ($deleteList) AND hi.house_id = ? AND h.landlord_id = ?";
                        $deleteStmt = mysqli_prepare($conn, $deleteSql);
                        if ($deleteStmt) {
                            mysqli_stmt_bind_param($deleteStmt, 'ii', $houseId, $landlordId);
                            if (!mysqli_stmt_execute($deleteStmt)) {
                                $imageManageError = true;
                            }
                            mysqli_stmt_close($deleteStmt);
                        } else {
                            $imageManageError = true;
                        }
                    }
                }

                // -----------------------------------------------------
                // Upload and insert any newly added images (same logic
                // as in create_property above).
                // -----------------------------------------------------
                if (!$imageManageError && !empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
                    $uploadDir = dirname(__DIR__) . '/uploads';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $newImageStmt = mysqli_prepare($conn, 'INSERT INTO house_image (house_id, image_url) VALUES (?, ?)');
                    if ($newImageStmt) {
                        foreach ($_FILES['images']['tmp_name'] as $index => $tmpName) {
                            if (empty($_FILES['images']['name'][$index]) || !is_uploaded_file($tmpName)) {
                                continue;
                            }
                            $originalName = basename($_FILES['images']['name'][$index]);
                            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                            $safeName = time() . '_' . uniqid('', true) . ($extension !== '' ? '.' . strtolower($extension) : '');
                            $destination = $uploadDir . '/' . $safeName;
                            if (getimagesize($tmpName) !== false && move_uploaded_file($tmpName, $destination)) {
                                $imageUrl = 'uploads/' . $safeName;
                                mysqli_stmt_bind_param($newImageStmt, 'is', $houseId, $imageUrl);
                                if (!mysqli_stmt_execute($newImageStmt)) {
                                    $imageManageError = true;
                                    break;
                                }
                            }
                        }
                        mysqli_stmt_close($newImageStmt);
                    } else {
                        $imageManageError = true;
                    }
                }

                if (!$imageManageError) {
                    // All good -> commit and redirect (Post/Redirect/Get)
                    mysqli_commit($conn);
                    mysqli_stmt_close($updateStmt);
                    header('Location: dashboard.php');
                    exit();
                }

                // Something failed while managing images -> roll back everything
                mysqli_rollback($conn);
                $propertyErrors[] = 'Unable to update property images. Please try again.';
            } else {
                mysqli_rollback($conn);
                $propertyErrors[] = 'Unable to update property. Please try again.';
            }
            mysqli_stmt_close($updateStmt);
        } else {
            mysqli_rollback($conn);
            $propertyErrors[] = 'Property update form could not be processed. Please check schema setup.';
        }
    }

    // Re-populate the edit form with the submitted values (so the user
    // doesn't lose their input if there was a validation error), and
    // flag that we're in "editing" mode so the Add/Edit tab is shown.
    $editingHouse = [
        'house_id' => $houseId,
        'title' => $title,
        'address' => $address,
        'province' => $province,
        'house_type' => $houseType,
        'price' => $price,
        'rooms' => $rooms,
        'bathrooms' => $bathrooms,
        'description' => $description,
        'amenities' => $amenities,
        'availability_status' => $availabilityStatus,
    ];
    $isEditing = true;
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
            'SELECT house_id, title, description, house_type, address, province, district, price, rooms, bathrooms, amenities, availability_status, contact_phone, contact_email, house_document FROM house WHERE house_id = ? AND landlord_id = ? LIMIT 1'
        );
        if ($editStmt) {
            mysqli_stmt_bind_param($editStmt, 'ii', $editHouseId, $landlordId);
            mysqli_stmt_execute($editStmt);
            $editResult = mysqli_stmt_get_result($editStmt);
            if ($editHouse = mysqli_fetch_assoc($editResult)) {
                $editingHouse = $editHouse;
                $editingHouse['images'] = [];
                // Load all images belonging to this house for the edit form
                $imageLoadStmt = mysqli_prepare($conn, 'SELECT image_id, image_url FROM house_image WHERE house_id = ? ORDER BY image_id ASC');
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
    'unread_requests' => 0,
    'unread_reviews' => 0,
];

$houseStmt = mysqli_prepare(
    $conn,
    'SELECT house_id, title, house_type, address, province, district, price, rooms, bathrooms, availability_status, description, created_at, admin_status, admin_feedback FROM house WHERE landlord_id = ? ORDER BY created_at DESC'
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
        $imageStmt = mysqli_prepare($conn, 'SELECT image_id, image_url FROM house_image WHERE house_id = ? ORDER BY image_id ASC LIMIT 1');
        if ($imageStmt) {
            mysqli_stmt_bind_param($imageStmt, 'i', $house['house_id']);
            mysqli_stmt_execute($imageStmt);
            $imageResult = mysqli_stmt_get_result($imageStmt);
            if ($imageRow = mysqli_fetch_assoc($imageResult)) {
                $house['first_image'] = $imageRow['image_url'] ?? '';
            }
            mysqli_stmt_close($imageStmt);
        }
        if (empty($house['first_image'])) {
            // Inline data-URI SVG placeholder when no image exists
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
    'SELECT ir.request_id, ir.house_id, ir.message, ir.request_status, ir.requested_at, ir.landlord_is_read, ir.landlord_review_is_read, h.title AS house_title, u.full_name AS tenant_name, u.phone_number AS tenant_phone, u.email AS tenant_email, t.occupation, t.budget, t.preferred_location, t.tenant_id,
     (SELECT AVG(rating) FROM tenant_review WHERE tenant_id = t.tenant_id) AS avg_rating,
     (SELECT COUNT(*) FROM tenant_review WHERE tenant_id = t.tenant_id) AS review_count,
     (SELECT review_id FROM tenant_review WHERE tenant_id = t.tenant_id AND house_id = ir.house_id AND landlord_id = h.landlord_id LIMIT 1) AS existing_review_id
     FROM interest_request ir
     JOIN house h ON ir.house_id = h.house_id
     JOIN tenant t ON ir.tenant_id = t.tenant_id
     JOIN users u ON t.user_id = u.id
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
        if ($row['landlord_is_read'] == 0) {
            $listingStats['unread_requests']++;
        }
        if ($row['request_status'] === 'leased' && $row['landlord_review_is_read'] == 0) {
            $listingStats['unread_reviews']++;
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
<title>GHARELU - Landlord Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/landlord.css">
</head>
<body>

<!-- NAVBAR -->
<header class="dash-navbar">
    <div class="dash-brand">
        <a href="dashboard.php" style="display: flex; align-items: center; gap: 0.6rem; text-decoration: none; color: inherit;">
            <span>Gharelu</span>
        </a>
    </div>

    <nav class="dash-nav" id="dashNav">
        <!-- Each nav button calls switchTab() (in script.js) to show/hide tab panes -->
        <button class="nav-link<?php echo $isEditing ? '' : ' active'; ?>" onclick="switchTab('overview', this)"><i class="fas fa-chart-line"></i> <span>Overview</span></button>
        <button class="nav-link" onclick="switchTab('listings', this)"><i class="fas fa-house"></i> <span>My Listings</span></button>
        <button class="nav-link<?php echo $isEditing ? ' active' : ''; ?>" onclick="switchTab('add', this)"><i class="fas fa-plus"></i> <span>Add Property</span></button>
        <button class="nav-link" onclick="switchTab('requests', this)"><i class="fas fa-envelope"></i> <span>Requests</span> <?php if ($listingStats['unread_requests'] > 0): ?><span class="badge-count" id="landlordReqBadge"><?php echo $listingStats['unread_requests']; ?></span><?php endif; ?></button>
        <button class="nav-link" onclick="switchTab('reviews', this)"><i class="fas fa-star"></i> <span>Review Tenants</span> <?php if ($listingStats['unread_reviews'] > 0): ?><span class="badge-count" id="landlordReviewBadge"><?php echo $listingStats['unread_reviews']; ?></span><?php endif; ?></button>
    </nav>

    <div class="dash-nav-actions">
        <a class="dash-user" href="../profile.php" title="View Profile" style="text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
            <?php if (!empty($user['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars('../' . $user['profile_image']); ?>" alt="Profile" class="dash-user-avatar" style="width:38px; height:38px; border-radius:50%; object-fit:cover; flex-shrink:0;">
            <?php else: ?>
                <div class="dash-user-avatar" style="width:38px; height:38px; font-size: 0.9rem;"><?php echo htmlspecialchars($avatarLetter); ?></div>
            <?php endif; ?>
            <div class="dash-user-info desktop-only">
                <h4 style="margin:0; font-size: 0.9rem; color: var(--text-dark);"><?php echo htmlspecialchars(explode(' ', $fullName)[0]); ?></h4>
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

<!-- MAIN CONTENT AREA -->
<div class="dash-main">
  <div style="padding: 20px 28px 0 28px;">
    <h2 id="page-title" style="font-family: 'Inter', sans-serif; font-size: 1.5rem; color: var(--primary-color);"><?php echo $isEditing ? 'Edit Property' : 'Overview'; ?></h2>
  </div>
  <div class="content">

    <!-- ================= OVERVIEW TAB ================= -->
    <div class="tab-pane<?php echo $isEditing ? '' : ' active'; ?>" id="tab-overview">
      <!-- Summary stat cards -->
      <div class="stats">
        <div class="stat">
          <div class="stat-icon ic-o"><i class="fas fa-house" style="color: var(--accent);"></i></div>
          <div><div class="stat-num"><?php echo $listingStats['total']; ?></div><div class="stat-label">Total Listings</div></div>
        </div>
        <div class="stat">
          <div class="stat-icon ic-g"><i class="fas fa-check-circle" style="color: var(--green);"></i></div>
          <div><div class="stat-num"><?php echo $listingStats['available']; ?></div><div class="stat-label">Available</div></div>
        </div>
        <div class="stat">
          <div class="stat-icon ic-b"><i class="fas fa-key" style="color: var(--blue);"></i></div>
          <div><div class="stat-num"><?php echo $listingStats['rented']; ?></div><div class="stat-label">Rented</div></div>
        </div>
        <div class="stat">
          <div class="stat-icon ic-r"><i class="fas fa-envelope" style="color: var(--red);"></i></div>
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
                <div class="mr-sub"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(trim(($house['district'] ?? '') . ', ' . ($house['province'] ?? ''), ', ')); ?></div>
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
        <button class="btn btn-primary" onclick="switchTab('add', document.querySelector('[onclick*=add]'))"><i class="fas fa-plus"></i> Add Property</button>
      </div>

      <!-- Client-side filter buttons (filtering handled in script.js) -->
      <div class="filters">
        <button id="fbtn-all" class="fbtn active" onclick="filterListings('all', this)">All (<?php echo $listingStats['total']; ?>)</button>
        <button id="fbtn-available" class="fbtn" onclick="filterListings('available', this)">Available (<?php echo $listingStats['available']; ?>)</button>
        <button id="fbtn-pending" class="fbtn" onclick="filterListings('pending', this)">Pending (<?php echo $listingStats['pending']; ?>)</button>
        <button id="fbtn-rented" class="fbtn" onclick="filterListings('rented', this)">Rented (<?php echo $listingStats['rented']; ?>)</button>
      </div>

      <!-- Grid of property cards, one per listing -->
      <div class="listings-grid">
        <?php foreach ($houses as $listing): ?>
          <?php $badgeClass = 'badge-' . $listing['availability_status']; ?>
          <?php $statusLabel = $listing['availability_status'] === 'available' ? 'Available' : ($listing['availability_status'] === 'rented' ? 'Rented' : 'Pending'); ?>
          <div class="prop-card" data-status="<?php echo htmlspecialchars($listing['availability_status']); ?>">
              <div class="prop-img">
                  <?php if ($listing['admin_status'] === 'pending'): ?>
                      <span class="badge" style="position:absolute; top:10px; left:10px; background:var(--gold); color:var(--white);">Pending Approval</span>
                  <?php elseif ($listing['admin_status'] === 'rejected'): ?>
                      <span class="badge" style="position:absolute; top:10px; left:10px; background:var(--red); color:var(--white);">Rejected</span>
                  <?php endif; ?>
                <?php if (!empty($listing['first_image'])): ?>
                  <?php
                  $imgSrc = $listing['first_image'];
                  if (strpos($imgSrc, 'data:') !== 0 && strpos($imgSrc, 'http') !== 0 && strpos($imgSrc, '/') !== 0) {
                      $imgSrc = '../' . $imgSrc;
                  }
                  ?>
                  <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($listing['title']); ?>" style="width:100%;height:100%;object-fit:cover;">
                <?php endif; ?>
                <span class="badge <?php echo $badgeClass; ?>" id="badge-<?php echo $listing['house_id']; ?>"><?php echo $statusLabel; ?></span>
              </div>
              <div class="prop-body">
                <div class="prop-title"><?php echo htmlspecialchars($listing['title']); ?></div>
                <div class="prop-loc"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(trim(($listing['district'] ?? '') . ', ' . ($listing['province'] ?? ''), ', ')); ?></div>
                <div class="prop-meta"><span><i class="fas fa-door-open"></i> <?php echo htmlspecialchars($listing['rooms']); ?> Room</span><span><i class="fas fa-bath"></i> <?php echo htmlspecialchars($listing['bathrooms']); ?> Bath</span></div>
                <div class="prop-price"><i class="fas fa-rupee-sign"></i> NPR <?php echo number_format($listing['price']); ?> <small>/ month</small></div>
                
                <?php if ($listing['admin_status'] === 'rejected' && !empty($listing['admin_feedback'])): ?>
                  <div style="color:var(--red); font-size:0.8rem; margin-top:5px; padding:5px; background:rgba(245,101,101,0.1); border-radius:4px;">
                      <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($listing['admin_feedback']); ?>
                  </div>
                <?php endif; ?>

                <div class="prop-footer">
                  <?php if ($listing['admin_status'] === 'approved'): ?>
                      <select id="status-select-<?php echo $listing['house_id']; ?>" onchange="updatePropertyStatus(<?php echo $listing['house_id']; ?>, this.value, this)">
                        <option value="available"<?php echo $listing['availability_status'] === 'available' ? ' selected' : ''; ?>>Available</option>
                        <option value="pending"<?php echo $listing['availability_status'] === 'pending' ? ' selected' : ''; ?>>Pending</option>
                        <option value="rented"<?php echo $listing['availability_status'] === 'rented' ? ' selected' : ''; ?>>Rented</option>
                      </select>
                  <?php else: ?>
                      <span class="text-muted" style="font-size: 0.9em; padding: 5px; font-weight: 500; color: #6c757d;">
                          <i class="fas fa-lock"></i> <?php echo $listing['admin_status'] === 'pending' ? 'Pending Verification' : 'Rejected by Admin'; ?>
                      </span>
                  <?php endif; ?>
                  
                  <div class="prop-actions">
                    <a class="btn btn-ghost" style="padding:5px 10px" href="dashboard.php?edit_house_id=<?php echo $listing['house_id']; ?>"><i class="fas fa-edit"></i> Edit</a>
                    <form method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm('Are you sure you want to completely delete this listing?');">
                      <input type="hidden" name="action" value="delete_property">
                      <input type="hidden" name="house_id" value="<?php echo $listing['house_id']; ?>">
                      <button type="submit" class="btn btn-danger" style="padding:5px 10px"><i class="fas fa-trash"></i></button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
  
      <!-- ================= ADD / EDIT PROPERTY TAB ================= -->    <div class="tab-pane<?php echo $isEditing ? ' active' : ''; ?>" id="tab-add">
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
            <div class="fg"><label>Province *</label><select name="province" id="property_province" required></select></div>
            <div class="fg"><label>District *</label><select name="district" id="property_district" required></select></div>
            <div class="fg"><label>Type</label>
              <select name="house_type">
                <?php $typeOptions = ['Apartment','House','Room','Flat','Studio']; ?>
                <?php foreach ($typeOptions as $typeOption): ?>
                  <option<?php echo (isset($editingHouse['house_type']) && $editingHouse['house_type'] === $typeOption) ? ' selected' : ''; ?>><?php echo htmlspecialchars($typeOption); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg"><label>Monthly Rent (Rs) *</label><input type="number" name="price" placeholder="25000" min="0.01" step="any" value="<?php echo htmlspecialchars($editingHouse['price'] ?? ''); ?>" required></div>
            <div class="fg"><label>Address *</label><input type="text" name="address" placeholder="e.g. Budhanilkantha" value="<?php echo htmlspecialchars($editingHouse['address'] ?? ''); ?>" required></div>
            <div class="fg"><label>rooms</label><input type="number" name="rooms" placeholder="2" min="0" value="<?php echo htmlspecialchars($editingHouse['rooms'] ?? ''); ?>"></div>
            <div class="fg"><label>Bathrooms</label><input type="number" name="bathrooms" placeholder="1" min="0" value="<?php echo htmlspecialchars($editingHouse['bathrooms'] ?? ''); ?>"></div>
              <?php if ($isEditing): ?>
              <div class="fg"><label>Status</label>
                <select name="availability_status">
                  <option value="available" <?php echo (isset($editingHouse['availability_status']) && $editingHouse['availability_status'] === 'available') ? 'selected' : ''; ?>>Available</option>
                  <option value="rented" <?php echo (isset($editingHouse['availability_status']) && $editingHouse['availability_status'] === 'rented') ? 'selected' : ''; ?>>Rented</option>
                </select>
              </div>
              <?php endif; ?>
            <div class="fg full"><label>Amenities</label><input type="text" name="amenities" placeholder="WiFi, Parking, Water, Generator" value="<?php echo htmlspecialchars($editingHouse['amenities'] ?? ''); ?>"></div>
            <!-- Only shown when editing and the listing already has images:
                 each image can be checked for deletion on save -->
            <?php if ($isEditing && !empty($editingHouse['images'])): ?>
              <div class="fg full"><label>Existing Images</label>
                <div>
                  <?php foreach ($editingHouse['images'] as $img): ?>
                    <div style="display:inline-block; margin-right:10px; margin-bottom:10px; text-align:center;">
                      <img src="<?php echo htmlspecialchars('../' . $img['image_url']); ?>" alt="Image" style="width:100px; height:70px; object-fit:cover; display:block; border:1px solid #ddd; margin-bottom:4px;">
                      <label style="color:#555;"><input type="checkbox" name="delete_images[]" value="<?php echo intval($img['image_id']); ?>"> Delete</label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <div class="fg full">
                <label>House Document (Verification required)</label>
                <input type="file" name="house_document" accept="image/*,application/pdf" <?php echo $isEditing ? '' : 'required'; ?>>
                <?php if ($isEditing && !empty($editingHouse['house_document'])): ?>
                    <div style="margin-top: 5px; font-size: 0.9em;">
                        <a href="../<?php echo htmlspecialchars($editingHouse['house_document']); ?>" target="_blank" style="color: var(--primary-color);">View Current Document</a>
                        <br><small class="text-muted">Uploading a new document will replace the existing one and return the listing to Pending status.</small>
                    </div>
                <?php endif; ?>
            </div>
            <div class="fg full"><label>Property Images</label><input type="file" name="images[]" multiple accept="image/*"></div>
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
          <button class="btn btn-primary"><?php echo $isEditing ? '<i class="fas fa-save"></i> Update Listing' : 'Publish Listing'; ?></button>
          <?php if ($isEditing): ?>
            <a class="btn btn-ghost" href="dashboard.php">Cancel</a>
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
          <div class="req-card" id="reqCard_<?php echo $request['request_id']; ?>" style="<?php echo $request['landlord_is_read'] == 0 ? 'border-left: 4px solid var(--primary-color); background-color: #f8fafc;' : ''; ?>">
            <div>
              <div class="req-prop"><?php echo htmlspecialchars($request['house_title']); ?></div>
              <div class="req-name" style="display: flex; align-items: center; gap: 0.75rem;">
                <?php echo htmlspecialchars($request['tenant_name']); ?>
                <?php if ($request['review_count'] > 0): ?>
                  <span style="font-size: 0.9rem; font-weight: 500; color: #d97706; background: #fef3c7; padding: 0.2rem 0.6rem; border-radius: 20px;">
                    <i class="fas fa-star"></i> <?php echo number_format($request['avg_rating'], 1); ?> <span style="color: #92400e; font-size: 0.8rem;">(<?php echo $request['review_count']; ?>)</span>
                  </span>
                <?php endif; ?>
              </div>
              <div class="req-contact">
                <?php if (!empty($request['tenant_phone'])): ?>
                  <a href="tel:<?php echo htmlspecialchars($request['tenant_phone']); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($request['tenant_phone']); ?></a>
                <?php endif; ?>
                <?php if (!empty($request['tenant_email'])): ?>
                  <a href="mailto:<?php echo htmlspecialchars($request['tenant_email']); ?>"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($request['tenant_email']); ?></a>
                <?php endif; ?>
              </div>
              
              <?php if (!empty($request['occupation']) || !empty($request['budget']) || !empty($request['preferred_location'])): ?>
              <div style="background:#f8fafc; padding:0.75rem; border-radius:6px; margin-top:0.5rem; font-size:0.85rem; color:#475569; display:flex; gap:1rem; flex-wrap:wrap;">
                  <?php if (!empty($request['occupation'])): ?>
                    <div><i class="fas fa-briefcase" style="color:var(--primary-color);"></i> <?php echo htmlspecialchars($request['occupation']); ?></div>
                  <?php endif; ?>
                  <?php if (!empty($request['preferred_location'])): ?>
                    <div><i class="fas fa-map-marker-alt" style="color:var(--primary-color);"></i> <?php echo htmlspecialchars($request['preferred_location']); ?></div>
                  <?php endif; ?>
                  <?php if (!empty($request['budget']) && $request['budget'] > 0): ?>
                    <div><i class="fas fa-wallet" style="color:var(--primary-color);"></i> NPR <?php echo number_format($request['budget']); ?></div>
                  <?php endif; ?>
              </div>
              <?php endif; ?>
              <div class="req-msg"><?php echo htmlspecialchars($request['message']); ?></div>
              <div class="req-date"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($request['requested_at']); ?></div>
            </div>
            <div class="req-actions">
              <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($request['request_status']); ?></span>
              <!-- Action buttons shown depend on the current request status -->
              <div style="margin-top: 8px;">
                <?php if ($request['landlord_is_read'] == 0): ?>
                  <button type="button" class="btn btn-ghost" onclick="toggleReadStatus(<?php echo $request['request_id']; ?>, 1, this)"><i class="fas fa-envelope-open"></i> Mark Read</button>
                <?php else: ?>
                  <button type="button" class="btn btn-ghost" onclick="toggleReadStatus(<?php echo $request['request_id']; ?>, 0, this)"><i class="fas fa-envelope"></i> Mark Unread</button>
                <?php endif; ?>
              </div>
              <form method="POST" style="display:inline-block; margin-top:8px;">
                <input type="hidden" name="action" value="request_action">
                <input type="hidden" name="request_id" value="<?php echo intval($request['request_id']); ?>">
                <?php if ($request['request_status'] === 'pending'): ?>
                  <button class="btn btn-success" type="submit" name="request_action" value="approve"><i class="fas fa-check"></i> Accept</button>
                  <button class="btn btn-danger" type="submit" name="request_action" value="reject"><i class="fas fa-times"></i> Reject</button>
                <?php elseif ($request['request_status'] === 'accepted'): ?>
                  <button class="btn btn-danger" type="submit" name="request_action" value="reject"><i class="fas fa-times"></i> Reject</button>
                  <button class="btn btn-primary" type="button" onclick="finalizeLease(<?php echo intval($request['request_id']); ?>, <?php echo intval($request['house_id']); ?>)"><i class="fas fa-file-signature"></i> Finalize Lease</button>
                <?php else: ?>
                  <button class="btn btn-ghost" type="submit" name="request_action" value="reset"><i class="fas fa-undo"></i> Reset</button>
                <?php endif; ?>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    
    <!-- ================= REVIEW TENANTS TAB ================= -->
    <div class="tab-pane" id="tab-reviews">
      <div class="sh"><h3>Review Tenants</h3></div>
      <p style="color: var(--text-light); margin-bottom: 1.5rem;">Below are all the tenants whose leases are finalized. Leave reviews to help build their reputation!</p>
      <div class="req-list">
        <?php 
        $approvedTenants = [];
        foreach ($requests as $req) {
            if ($req['request_status'] === 'leased' && !isset($approvedTenants[$req['tenant_id']])) {
                $approvedTenants[$req['tenant_id']] = $req;
            }
        }
        ?>
        <?php if (empty($approvedTenants)): ?>
            <div style="background: white; padding: 2rem; border-radius: 8px; text-align: center; color: var(--text-light);">
                You haven't finalized any leases yet.
            </div>
        <?php else: ?>
            <?php foreach ($approvedTenants as $tenant): ?>
              <div class="req-card" id="reqCard_<?php echo $tenant['request_id']; ?>" style="<?php echo $tenant['landlord_review_is_read'] == 0 ? 'border-left: 4px solid var(--primary-color); background-color: #f8fafc;' : ''; ?>">
                <div>
                  <div class="req-name" style="display: flex; align-items: center; gap: 0.75rem;">
                    <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                    <?php if ($tenant['review_count'] > 0): ?>
                      <span style="font-size: 0.9rem; font-weight: 500; color: #d97706; background: #fef3c7; padding: 0.2rem 0.6rem; border-radius: 20px;">
                        <i class="fas fa-star"></i> <?php echo number_format($tenant['avg_rating'], 1); ?> <span style="color: #92400e; font-size: 0.8rem;">(<?php echo $tenant['review_count']; ?>)</span>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="req-contact">
                    <?php if (!empty($tenant['tenant_phone'])): ?>
                      <a href="tel:<?php echo htmlspecialchars($tenant['tenant_phone']); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($tenant['tenant_phone']); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($tenant['tenant_email'])): ?>
                      <a href="mailto:<?php echo htmlspecialchars($tenant['tenant_email']); ?>"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($tenant['tenant_email']); ?></a>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="req-actions">
                  <?php if (!empty($tenant['existing_review_id'])): ?>
                      <span style="color:var(--green); font-weight:600;"><i class="fas fa-check-circle"></i> Review Submitted</span>
                      <form method="POST" style="display:inline-block; margin-left: 10px;" onsubmit="return confirm('Delete this review?');">
                          <input type="hidden" name="action" value="delete_tenant_review">
                          <input type="hidden" name="review_id" value="<?php echo $tenant['existing_review_id']; ?>">
                          <button type="submit" class="btn btn-danger" style="padding: 5px 10px; font-size: 0.9em;"><i class="fas fa-trash"></i> Delete</button>
                      </form>
                  <?php else: ?>
                      <button class="btn btn-primary" type="button" onclick="openTenantReviewModal(<?php echo intval($tenant['tenant_id']); ?>, <?php echo intval($tenant['house_id']); ?>, '<?php echo htmlspecialchars(addslashes($tenant['tenant_name'])); ?>')"><i class="fas fa-star"></i> Write a Review</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- ============ TENANT REVIEW MODAL ============ -->
<div class="modal-overlay" id="tenantReviewModal">
    <div class="modal-box" style="max-width: 500px;">
        <div class="modal-box-header">
            <h3 id="tenantReviewModalTitle">Review Tenant</h3>
            <button type="button" class="modal-close" onclick="closeTenantReviewModal()">&times;</button>
        </div>
        <form method="POST" class="modal-box-body">
            <input type="hidden" name="action" value="add_tenant_review">
            <input type="hidden" name="tenant_id" id="tenantReviewId">
              <input type="hidden" name="house_id" id="tenantReviewHouseId">
            <input type="hidden" name="rating" id="tenantReviewRatingInput" value="5">
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);">Tenant Rating</label>
                <div class="rating-input" id="tenantRatingStars" style="font-size: 2rem; color: #cbd5e1; cursor: pointer; display: flex; gap: 0.5rem;">
                    <i class="fas fa-star active" data-val="1" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="2" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="3" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="4" style="color: #f59e0b;"></i>
                    <i class="fas fa-star active" data-val="5" style="color: #f59e0b;"></i>
                </div>
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark);">Your Review</label>
                <textarea name="comment" rows="4" placeholder="Share your experience renting to this tenant..." required style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 6px; resize: vertical; font-family: inherit;"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-size: 1.05rem;">
                <i class="fas fa-star"></i> Submit Review
            </button>
        </form>
    </div>
</div>

<script src="../assets/js/location_dropdown.js"></script>
<script>
  // Initialize Location Dropdowns
  (function() {
      const selectedProvince = <?php echo json_encode($editingHouse['province'] ?? ($_POST['province'] ?? '')); ?>;
      const selectedDistrict = <?php echo json_encode($editingHouse['district'] ?? ($_POST['district'] ?? '')); ?>;
      initLocationDropdowns('property_province', 'property_district', selectedProvince, selectedDistrict);
  })();

  function toggleReadStatus(requestId, isRead, btnElement) {
      fetch('../ajax/landlord/toggle_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ request_id: requestId, is_read: isRead })
      })
      .then(res => res.json())
      .then(data => {
          if (data.success) {
              const card = document.getElementById('reqCard_' + requestId);
              if (isRead) {
                  if(card) {
                      card.style.borderLeft = 'none';
                      card.style.backgroundColor = '#fff';
                  }
                  btnElement.innerHTML = '<i class="fas fa-envelope"></i> Mark Unread';
                  btnElement.setAttribute('onclick', `toggleReadStatus(${requestId}, 0, this)`);
              } else {
                  if(card) {
                      card.style.borderLeft = '4px solid var(--primary-color)';
                      card.style.backgroundColor = '#f8fafc';
                  }
                  btnElement.innerHTML = '<i class="fas fa-envelope-open"></i> Mark Read';
                  btnElement.setAttribute('onclick', `toggleReadStatus(${requestId}, 1, this)`);
              }
              
              // Update badge
              let badge = document.getElementById('landlordReqBadge');
              if (data.unread_count > 0) {
                  if (!badge) {
                      badge = document.createElement('span');
                      badge.className = 'badge-count';
                      badge.id = 'landlordReqBadge';
                      const reqTab = document.querySelector('button[onclick*="switchTab(\'requests\'"]');
                      if(reqTab) reqTab.appendChild(badge);
                  }
                  badge.textContent = data.unread_count;
              } else if (badge) {
                  badge.remove();
              }
          } else {
              alert('Failed to update status: ' + data.error);
          }
      });
  }

  function finalizeLease(requestId, houseId) {
      if (!confirm('Are you sure you want to finalize this lease? This will mark the property as Rented and decline all other pending/accepted requests for this property.')) return;
      
      fetch('../ajax/landlord/finalize_lease.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ request_id: requestId, house_id: houseId })
      })
      .then(async res => {
          const text = await res.text();
          try { return JSON.parse(text); } 
          catch(e) { throw new Error(text); }
      })
      .then(data => {
          if (data.success) {
              window.location.hash = '#reviews';
              window.location.reload();
          } else {
              alert('Failed to finalize lease: ' + data.error);
          }
      })
      .catch(err => {
          console.error(err);
          alert('A network error occurred: ' + err.message);
      });
  }

  function openTenantReviewModal(tenantId, houseId, tenantName) {
      document.getElementById('tenantReviewId').value = tenantId;
      document.getElementById('tenantReviewHouseId').value = houseId;
      document.getElementById('tenantReviewModalTitle').textContent = 'Review Tenant - ' + tenantName;
      document.getElementById('tenantReviewModal').classList.add('open');
  }

function closeTenantReviewModal() {
    document.getElementById('tenantReviewModal').classList.remove('open');
}

// Star rating logic for Tenant Review
document.querySelectorAll('#tenantRatingStars i').forEach(star => {
    star.addEventListener('click', function() {
        const val = parseInt(this.getAttribute('data-val'));
        document.getElementById('tenantReviewRatingInput').value = val;
        
        document.querySelectorAll('#tenantRatingStars i').forEach(s => {
            if (parseInt(s.getAttribute('data-val')) <= val) {
                s.style.color = '#f59e0b';
                s.classList.add('active');
            } else {
                s.style.color = '#cbd5e1';
                s.classList.remove('active');
            }
        });
    });
});

function updatePropertyStatus(houseId, newStatus, selectElement) {
    const originalStatus = selectElement.getAttribute('data-original') || selectElement.querySelector('option[selected]')?.value;
    
    fetch('../ajax/landlord/update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ house_id: houseId, status: newStatus })
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch(e) {
            throw new Error('Server returned invalid JSON: ' + text);
        }
    })
    .then(data => {
        if (data.success) {
            selectElement.setAttribute('data-original', newStatus);
            
            const badge = document.getElementById('badge-' + houseId);
            if (badge) {
                badge.classList.remove('badge-available', 'badge-pending', 'badge-rented');
                badge.classList.add('badge-' + newStatus);
                badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            }
            
            const card = selectElement.closest('.prop-card');
            if (card) {
                card.setAttribute('data-status', newStatus);
            }
            
            recalculateFilterCounts();
            alert('Status successfully changed to ' + newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
        } else {
            alert('Failed to update status: ' + (data.error || 'Unknown error'));
            if (originalStatus) selectElement.value = originalStatus;
        }
    })
    .catch(err => {
        console.error(err);
        alert('A network error occurred: ' + err.message);
        if (originalStatus) selectElement.value = originalStatus;
    });
}

function recalculateFilterCounts() {
    const cards = document.querySelectorAll('.prop-card');
    let counts = { all: cards.length, available: 0, pending: 0, rented: 0 };
    
    cards.forEach(card => {
        const status = card.getAttribute('data-status');
        if (counts[status] !== undefined) counts[status]++;
    });
    
    const btnAll = document.getElementById('fbtn-all');
    const btnAvail = document.getElementById('fbtn-available');
    const btnPend = document.getElementById('fbtn-pending');
    const btnRent = document.getElementById('fbtn-rented');
    
    if(btnAll) btnAll.innerHTML = `All (${counts.all})`;
    if(btnAvail) btnAvail.innerHTML = `Available (${counts.available})`;
    if(btnPend) btnPend.innerHTML = `Pending (${counts.pending})`;
    if(btnRent) btnRent.innerHTML = `Rented (${counts.rented})`;
}
</script>

<script src="../assets/js/script.js"></script>
</body>
</html>

