<?php
/* Reusable property card — expects $house (array) and $tab (string) in scope.
   Included from tenant_dashboard.php inside a foreach loop. */
$contact_email = !empty($house['contact_email']) ? $house['contact_email'] : ($house['landlord_fallback_email'] ?? 'No email provided');
$contact_phone = !empty($house['contact_phone']) ? $house['contact_phone'] : ($house['landlord_fallback_phone'] ?? 'No phone provided');
$contact_name = $house['landlord_fallback_name'] ?? 'Landlord';

$is_accepted = isset($house['my_request_status']) && in_array($house['my_request_status'], ['accepted', 'leased']);

if (!$is_accepted) {
    $contact_phone = 'Available after request is accepted';
    $contact_email = 'Available after request is accepted';
}

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
    'image_url' => $house['image_url'] ? '../' . addslashes($house['image_url']) : '',
    'landlord_name' => addslashes($contact_name),
    'landlord_phone' => addslashes($contact_phone),
    'landlord_email' => addslashes($contact_email)
];
$houseJson = json_encode($houseSafe);
?>
<div class="property-card" onclick="openDetailsModal(<?php echo htmlspecialchars($houseJson); ?>)">
    <div class="property-image">
        <?php if ($img = img_or_placeholder($house['image_url'])): ?>
            <img src="<?= $img ?>" alt="<?= h($house['title']) ?>">
        <?php else: ?>
            <div class="no-img"><i class="fas fa-image"></i></div>
        <?php endif; ?>
        <span class="property-status <?= h($house['availability_status']) ?>"><?= h($house['availability_status']) ?></span>

        <form method="POST" onclick="event.stopPropagation();">
            <input type="hidden" name="action" value="<?= $house['is_fav'] ? 'remove_favorite' : 'add_favorite' ?>">
            <input type="hidden" name="house_id" value="<?= (int)$house['house_id'] ?>">
            <input type="hidden" name="return_tab" value="<?= h($tab) ?>">
            <button type="submit" class="fav-toggle-btn <?= $house['is_fav'] ? 'is-fav' : '' ?>" title="<?= $house['is_fav'] ? 'Remove from favorites' : 'Save to favorites' ?>">
                <i class="fa<?= $house['is_fav'] ? 's' : 'r' ?> fa-heart"></i>
            </button>
        </form>
    </div>
    <div class="property-details">
        <h3><?= h($house['title']) ?></h3>
        <div class="property-location"><i class="fas fa-location-dot"></i> <?= h(($house['district'] ?? '') . ', ' . $house['province']) ?></div>
        <div class="property-features">
            <span><i class="fas fa-door-open"></i><?= (int)$house['rooms'] ?> Room</span>
            <span><i class="fas fa-bath"></i><?= (int)$house['bathrooms'] ?> Bath</span>
            <span><i class="fas fa-couch"></i><?= h($house['furnishing']) ?></span>
        </div>
        <div class="property-price">Rs <?= number_format($house['price']) ?> <small>/ month</small></div>
        <div class="property-actions" onclick="event.stopPropagation();" style="display: flex; gap: 0.5rem;">
            <button class="btn-primary" style="flex:1; padding: 0.4rem;" onclick="openInterestModal(<?= (int)$house['house_id'] ?>, '<?= h(addslashes($house['title'])) ?>')">
                <i class="fas fa-paper-plane"></i> Send Interest
            </button>
            <button class="btn-outline" style="flex:1; padding: 0.4rem;" onclick="handleContactClick('<?= h(addslashes($contact_name)) ?>', '<?= h(addslashes($contact_phone)) ?>', '<?= h(addslashes($contact_email)) ?>')">
                <i class="fas fa-phone-alt"></i> Contact
            </button>
        </div>
    </div>
</div>
