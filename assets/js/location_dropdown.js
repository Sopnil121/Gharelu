const locationData = {
    "Koshi": ["Morang", "Sunsari", "Jhapa", "Dhankuta", "Ilam"],
    "Madhesh": ["Parsa", "Bara", "Rautahat", "Sarlahi", "Dhanusha", "Siraha"],
    "Bagmati": ["Kathmandu", "Lalitpur", "Bhaktapur", "Chitwan", "Kavrepalanchok", "Makwanpur"],
    "Gandaki": ["Kaski", "Tanahun", "Gorkha", "Syangja", "Nawalpur"],
    "Lumbini": ["Rupandehi", "Kapilvastu", "Dang", "Banke", "Bardiya"],
    "Karnali": ["Surkhet", "Dailekh", "Jumla", "Salyan", "Kalikot"],
    "Sudurpashchim": ["Kailali", "Kanchanpur", "Dadeldhura", "Baitadi", "Doti"]
};

function initLocationDropdowns(provinceSelectId, districtSelectId, selectedProvince = '', selectedDistrict = '') {
    const provinceSelect = document.getElementById(provinceSelectId);
    const districtSelect = document.getElementById(districtSelectId);

    if (!provinceSelect || !districtSelect) return;

    // Populate Provinces
    provinceSelect.innerHTML = '<option value=\"\">Select Province</option>';
    for (const province in locationData) {
        const option = document.createElement('option');
        option.value = province;
        option.textContent = province;
        if (province === selectedProvince) {
            option.selected = true;
        }
        provinceSelect.appendChild(option);
    }

    // Handle change event
    provinceSelect.addEventListener('change', function() {
        populateDistricts(this.value, districtSelect, '');
    });

    // Populate initial districts if province is selected
    if (selectedProvince) {
        populateDistricts(selectedProvince, districtSelect, selectedDistrict);
    } else {
        districtSelect.innerHTML = '<option value=\"\">Select District</option>';
    }
}

function populateDistricts(province, districtSelect, selectedDistrict) {
    districtSelect.innerHTML = '<option value=\"\">Select District</option>';
    
    if (province && locationData[province]) {
        locationData[province].forEach(district => {
            const option = document.createElement('option');
            option.value = district;
            option.textContent = district;
            if (district === selectedDistrict) {
                option.selected = true;
            }
            districtSelect.appendChild(option);
        });
    }
}
