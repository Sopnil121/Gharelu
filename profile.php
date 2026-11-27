<?php
require_once __DIR__ . '/includes/config.php';
require_login();

$user = current_user();
if (!$user) {
    redirect_role_home();
}

$userId = intval($user['id']);
$userType = $user['user_type'];
$errors = [];
$success = '';

$conn = db_connect();

$landlordRow = null;
$tenantRow = null;

// Fetch role-specific details
if ($userType === 'landlord') {
    $landlordStmt = mysqli_prepare(
        $conn,
        'SELECT landlord_id, citizenship_no, land_ownership_certificate_no, citizenship_image, verification_status, verified_at FROM landlord WHERE user_id = ? LIMIT 1'
    );
    if ($landlordStmt) {
        mysqli_stmt_bind_param($landlordStmt, 'i', $userId);
        mysqli_stmt_execute($landlordStmt);
        $landlordResult = mysqli_stmt_get_result($landlordStmt);
        $landlordRow = mysqli_fetch_assoc($landlordResult) ?: null;
        mysqli_stmt_close($landlordStmt);
    }
    
    if (!$landlordRow) {
        $insertLandlordStmt = mysqli_prepare($conn, 'INSERT INTO landlord (user_id, verification_status) VALUES (?, ?)');
        if ($insertLandlordStmt) {
            $defaultStatus = 'pending';
            mysqli_stmt_bind_param($insertLandlordStmt, 'is', $userId, $defaultStatus);
            mysqli_stmt_execute($insertLandlordStmt);
            mysqli_stmt_close($insertLandlordStmt);
            $landlordRow = [
                'landlord_id' => mysqli_insert_id($conn),
                'citizenship_no' => '',
                'land_ownership_certificate_no' => '',
                'citizenship_image' => '',
                'verification_status' => 'pending',
                'verified_at' => null,
            ];
        }
    }
} elseif ($userType === 'tenant') {
    $tenantStmt = mysqli_prepare(
        $conn,
        'SELECT tenant_id, occupation, preferred_location, budget FROM tenant WHERE user_id = ? LIMIT 1'
    );
    if ($tenantStmt) {
        mysqli_stmt_bind_param($tenantStmt, 'i', $userId);
        mysqli_stmt_execute($tenantStmt);
        $tenantResult = mysqli_stmt_get_result($tenantStmt);
        $tenantRow = mysqli_fetch_assoc($tenantResult) ?: null;
        mysqli_stmt_close($tenantStmt);
    }
    
    if (!$tenantRow) {
        $insertTenantStmt = mysqli_prepare($conn, 'INSERT INTO tenant (user_id) VALUES (?)');
        if ($insertTenantStmt) {
            mysqli_stmt_bind_param($insertTenantStmt, 'i', $userId);
            mysqli_stmt_execute($insertTenantStmt);
            mysqli_stmt_close($insertTenantStmt);
            $tenantRow = [
                'tenant_id' => mysqli_insert_id($conn),
                'occupation' => '',
                'preferred_location' => '',
                'budget' => 0.0,
            ];
        }
    }
}

// Image upload handler with strict type, size, and mime validations
function saveUploadedImage(array $file, string $dir, array &$errors): ?string
{
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed for ' . htmlspecialchars($file['name']) . '.';
        return null;
    }

    $tmpName = $file['tmp_name'];
    
    // File size check: Max 2MB (2097152 bytes)
    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        $errors[] = 'The file ' . htmlspecialchars($file['name']) . ' exceeds the maximum size limit of 2MB.';
        return null;
    }

    // Extension check: Whitelist JPG, JPEG, PNG, GIF
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $extension = strtolower($extension ?: '');
    if (!in_array($extension, $allowedTypes, true)) {
        $errors[] = 'The file ' . htmlspecialchars($file['name']) . ' has an invalid extension. Only JPG, JPEG, PNG, and GIF are allowed.';
        return null;
    }

    // Real image verification via getimagesize
    if (!is_uploaded_file($tmpName) || getimagesize($tmpName) === false) {
        $errors[] = 'The file ' . htmlspecialchars($file['name']) . ' is not a valid image.';
        return null;
    }

    // MIME type verification
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpName);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = 'The file ' . htmlspecialchars($file['name']) . ' is not a valid image type.';
        return null;
    }

    // Ensure uploads directory exists
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $errors[] = 'Unable to create upload directory.';
        return null;
    }

    // Secure unique file name generation
    $safeName = time() . '_' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]+/', '', $extension);
    $destination = $dir . '/' . $safeName;

    if (!move_uploaded_file($tmpName, $destination)) {
        $errors[] = 'Failed to save uploaded file.';
        return null;
    }

    return str_replace('\\', '/', 'uploads/' . basename($dir) . '/' . $safeName);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $citizenshipId = trim($_POST['citizenship_id'] ?? '');

    if ($fullName === '' || $email === '' || $citizenshipId === '') {
        $errors[] = 'Full name, email, and citizenship ID are required.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($phoneNumber !== '' && !preg_match('/^[0-9]{10,15}$/', $phoneNumber)) {
        $errors[] = 'Phone number must contain 10 to 15 digits.';
    }

    // Role-specific collect
    $citizenshipNo = trim($_POST['citizenship_no'] ?? '');
    $ownershipNo = trim($_POST['land_ownership_certificate_no'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $preferredLocation = trim($_POST['preferred_location'] ?? '');
    $budget = floatval($_POST['budget'] ?? 0.0);

    $profileImagePath = null;
    $citizenshipImagePath = null;

    if (empty($errors)) {
        $profileImagePath = saveUploadedImage($_FILES['profile_image'] ?? [], __DIR__ . '/uploads/profiles', $errors);
        if ($userType === 'landlord') {
            $citizenshipImagePath = saveUploadedImage($_FILES['citizenship_image'] ?? [], __DIR__ . '/uploads/documents', $errors);
        }
    }

    // Duplicate Check: email or phone number in users table
    if (empty($errors)) {
        $emailCheckStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE (email = ? OR phone_number = ?) AND id != ? LIMIT 1');
        if ($emailCheckStmt) {
            mysqli_stmt_bind_param($emailCheckStmt, 'ssi', $email, $phoneNumber, $userId);
            mysqli_stmt_execute($emailCheckStmt);
            $result = mysqli_stmt_get_result($emailCheckStmt);
            if ($existing = mysqli_fetch_assoc($result)) {
                $errors[] = 'Email or phone number is already in use by another account.';
            }
            mysqli_stmt_close($emailCheckStmt);
        }
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);

        // Update unified profile details in users table
        $userUpdateSql = 'UPDATE users SET full_name = ?, email = ?, phone_number = ?, citizenship_id = ?';
        if ($profileImagePath !== null) {
            $userUpdateSql .= ', profile_image = ?';
        }
        $userUpdateSql .= ' WHERE id = ?';

        $userUpdateStmt = mysqli_prepare($conn, $userUpdateSql);
        if ($userUpdateStmt) {
            if ($profileImagePath !== null) {
                mysqli_stmt_bind_param($userUpdateStmt, 'sssssi', $fullName, $email, $phoneNumber, $citizenshipId, $profileImagePath, $userId);
            } else {
                mysqli_stmt_bind_param($userUpdateStmt, 'ssssi', $fullName, $email, $phoneNumber, $citizenshipId, $userId);
            }
            if (!mysqli_stmt_execute($userUpdateStmt)) {
                $errors[] = 'Unable to update user information.';
            }
            mysqli_stmt_close($userUpdateStmt);
        } else {
            $errors[] = 'Failed to prepare user update query.';
        }

        // Update role-specific fields
        if (empty($errors) && $userType === 'landlord') {
            $landlordUpdateSql = 'UPDATE landlord SET citizenship_no = ?, land_ownership_certificate_no = ?';
            if ($citizenshipImagePath !== null) {
                $landlordUpdateSql .= ', citizenship_image = ?';
            }
            $landlordUpdateSql .= ' WHERE user_id = ?';

            $landlordUpdateStmt = mysqli_prepare($conn, $landlordUpdateSql);
            if ($landlordUpdateStmt) {
                if ($citizenshipImagePath !== null) {
                    mysqli_stmt_bind_param($landlordUpdateStmt, 'sssi', $citizenshipNo, $ownershipNo, $citizenshipImagePath, $userId);
                } else {
                    mysqli_stmt_bind_param($landlordUpdateStmt, 'ssi', $citizenshipNo, $ownershipNo, $userId);
                }
                if (!mysqli_stmt_execute($landlordUpdateStmt)) {
                    $errors[] = 'Unable to update landlord details.';
                }
                mysqli_stmt_close($landlordUpdateStmt);
            } else {
                $errors[] = 'Failed to prepare landlord update query.';
            }
        } elseif (empty($errors) && $userType === 'tenant') {
            $tenantUpdateStmt = mysqli_prepare(
                $conn,
                'UPDATE tenant SET occupation = ?, preferred_location = ?, budget = ? WHERE user_id = ?'
            );
            if ($tenantUpdateStmt) {
                mysqli_stmt_bind_param($tenantUpdateStmt, 'ssdi', $occupation, $preferredLocation, $budget, $userId);
                if (!mysqli_stmt_execute($tenantUpdateStmt)) {
                    $errors[] = 'Unable to update tenant details.';
                }
                mysqli_stmt_close($tenantUpdateStmt);
            } else {
                $errors[] = 'Failed to prepare tenant update query.';
            }
        }

        if (empty($errors)) {
            mysqli_commit($conn);
            $success = 'Profile updated successfully.';
            
            // Reload user data
            $user['full_name'] = $fullName;
            $user['email'] = $email;
            $user['phone_number'] = $phoneNumber;
            $user['citizenship_id'] = $citizenshipId;
            if ($profileImagePath !== null) {
                $user['profile_image'] = $profileImagePath;
            }
            
            if ($userType === 'landlord') {
                $landlordRow['citizenship_no'] = $citizenshipNo;
                $landlordRow['land_ownership_certificate_no'] = $ownershipNo;
                if ($citizenshipImagePath !== null) {
                    $landlordRow['citizenship_image'] = $citizenshipImagePath;
                }
            } elseif ($userType === 'tenant') {
                $tenantRow['occupation'] = $occupation;
                $tenantRow['preferred_location'] = $preferredLocation;
                $tenantRow['budget'] = $budget;
            }
        } else {
            mysqli_rollback($conn);
        }
    }
}

mysqli_close($conn);

// Set back link depending on role
$backLink = 'index.php';
if ($userType === 'tenant') {
    $backLink = 'tenant/dashboard.php';
} elseif ($userType === 'landlord') {
    $backLink = 'landlord/dashboard.php';
} elseif ($userType === 'admin') {
    $backLink = 'admin/dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GHARELU - Profile Management</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/landlord.css">
<style>
    /* Profile specific style fixes to support responsive elements */
    .btn-icon {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .profile-avatar-container {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .profile-avatar-img {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        border: 2px solid var(--accent);
    }
    .profile-avatar-placeholder {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        background: linear-gradient(135deg, #2c5f8a, #1e3d59);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 700;
    }
</style>
</head>
<body>
<div class="main" style="margin-left:0; padding: 2rem 1rem; max-width: 800px; margin: 0 auto;">
  <header class="topbar" style="background:transparent; border:none; padding:0; margin-bottom: 2rem;">
    <h2>Gharelu Profile</h2>
    <div class="topbar-right">
      <a class="btn btn-ghost btn-icon" href="<?php echo htmlspecialchars($backLink); ?>">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>
  </header>

  <div class="content" style="padding:0;">
    <div class="form-card" style="max-width:100%;">
      <?php if (!empty($errors)): ?>
        <div class="error-message" style="border:1px solid #f1a0a0; background:#fff5f5; padding:14px; border-radius:12px; margin-bottom:16px;">
          <ul style="margin:0; padding-left:20px; color:var(--red);">
            <?php foreach ($errors as $error): ?>
              <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php elseif (!empty($success)): ?>
        <div class="success-message" style="border:1px solid #b7f5c1; background:#ecffed; padding:14px; border-radius:12px; margin-bottom:16px; color:var(--green);">
          <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data">
        <div class="profile-avatar-container">
            <?php if (!empty($user['profile_image'])): ?>
                <img src="<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile" class="profile-avatar-img">
            <?php else: ?>
                <div class="profile-avatar-placeholder">
                    <?php echo strtoupper(substr($user['full_name'] ?? 'U', 0, 1)); ?>
                </div>
            <?php endif; ?>
            <div>
                <label style="display:block; font-weight:600; font-size: 0.95rem; margin-bottom: 0.5rem;"><i class="fas fa-image"></i> Change Profile Picture</label>
                <input type="file" name="profile_image" accept="image/*">
                <small style="color:var(--muted); display:block; margin-top: 0.25rem;">JPG, JPEG, PNG, or GIF. Max 2MB.</small>
            </div>
        </div>

        <div class="form-grid">
          <div class="fg full">
            <label><i class="fas fa-id-card"></i> Full Name</label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
          </div>
          <div class="fg">
            <label><i class="fas fa-user"></i> Username</label>
            <input type="text" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" readonly style="background:#eef2f7; cursor:not-allowed;">
          </div>
          <div class="fg">
            <label><i class="fas fa-envelope"></i> Email</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
          </div>
          <div class="fg">
            <label><i class="fas fa-phone"></i> Phone Number</label>
            <input type="text" name="phone_number" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" pattern="[0-9]{10,15}">
          </div>
          <div class="fg">
            <label><i class="fas fa-passport"></i> Citizenship ID</label>
            <input type="text" name="citizenship_id" value="<?php echo htmlspecialchars($user['citizenship_id'] ?? ''); ?>" required>
          </div>

          <!-- LANDLORD SPECIFIC FIELDS -->
          <?php if ($userType === 'landlord'): ?>
              <div class="fg">
                <label><i class="fas fa-file-signature"></i> Citizenship Number</label>
                <input type="text" name="citizenship_no" value="<?php echo htmlspecialchars($landlordRow['citizenship_no'] ?? ''); ?>">
              </div>

              <div class="fg">
                <label><i class="fas fa-shield"></i> Verification Status</label>
                <input type="text" value="<?php echo htmlspecialchars(ucfirst($landlordRow['verification_status'] ?? 'pending')); ?>" readonly style="background:#eef2f7;">
              </div>
              <div class="fg">
                <label><i class="fas fa-calendar-check"></i> Verified At</label>
                <input type="text" value="<?php echo htmlspecialchars($landlordRow['verified_at'] ?? 'Not verified'); ?>" readonly style="background:#eef2f7;">
              </div>
              <div class="fg full">
                <label><i class="fas fa-image"></i> Citizenship Image Document</label>
                <?php if (!empty($landlordRow['citizenship_image'])): ?>
                  <img src="<?php echo htmlspecialchars($landlordRow['citizenship_image']); ?>" alt="Citizenship" style="max-width:160px; border-radius:12px; display:block; margin-bottom:10px; border: 1px solid var(--border);">
                <?php endif; ?>
                <input type="file" name="citizenship_image" accept="image/*">
                <small style="color:var(--muted); display:block; margin-top: 0.25rem;">Upload a clear scan of your citizenship document.</small>
              </div>
          <?php endif; ?>

          <!-- TENANT SPECIFIC FIELDS -->
          <?php if ($userType === 'tenant'): ?>
              <div class="fg">
                <label><i class="fas fa-briefcase"></i> Occupation</label>
                <input type="text" name="occupation" value="<?php echo htmlspecialchars($tenantRow['occupation'] ?? ''); ?>" placeholder="e.g. Software Developer">
              </div>
              <div class="fg">
                <label><i class="fas fa-map-location-dot"></i> Preferred Location</label>
                <input type="text" name="preferred_location" value="<?php echo htmlspecialchars($tenantRow['preferred_location'] ?? ''); ?>" placeholder="e.g. Kathmandu">
              </div>
              <div class="fg full">
                <label><i class="fas fa-wallet"></i> Monthly Budget (NPR)</label>
                <input type="number" name="budget" value="<?php echo floatval($tenantRow['budget'] ?? 0.0); ?>" placeholder="e.g. 25000" min="0" step="any">
              </div>
          <?php endif; ?>
        </div>
        <br>
        <button class="btn btn-primary btn-icon" style="width: 100%; justify-content: center;" type="submit">
            <i class="fas fa-save"></i> Save Profile Details
        </button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
