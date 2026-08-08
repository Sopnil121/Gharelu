-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 08, 2026 at 09:38 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gharelu_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `admin_level` varchar(50) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `user_id`, `admin_level`) VALUES
(1, 5, 'super_admin');

-- --------------------------------------------------------

--
-- Table structure for table `amenity`
--

CREATE TABLE `amenity` (
  `amenity_id` int(11) NOT NULL,
  `amenity_name` varchar(255) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `amenity`
--

INSERT INTO `amenity` (`amenity_id`, `amenity_name`) VALUES
(1, 'Wi-Fi'),
(2, 'Parking'),
(3, 'Garden'),
(4, 'Security'),
(5, 'Laundry');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `admin_reply` text DEFAULT NULL,
  `status` enum('unread','read','replied') DEFAULT 'unread',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `replied_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contact_messages`
--

INSERT INTO `contact_messages` (`id`, `name`, `email`, `subject`, `message`, `admin_reply`, `status`, `created_at`, `updated_at`, `replied_at`) VALUES
(1, 'Ayushma', 'ameeshadhamala@gmail.com', 'house finding problems', 'T am having trouble in browing houses', NULL, 'unread', '2026-08-08 07:24:13', '2026-08-08 07:24:13', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `favorite`
--

CREATE TABLE `favorite` (
  `favorite_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `house_id` int(11) DEFAULT NULL,
  `saved_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `favorite`
--

INSERT INTO `favorite` (`favorite_id`, `tenant_id`, `house_id`, `saved_at`) VALUES
(1, 1, 2, '2026-06-27 16:25:08'),
(2, 1, 3, '2026-06-27 16:25:08'),
(3, 2, 1, '2026-06-27 16:25:08');

-- --------------------------------------------------------

--
-- Table structure for table `house`
--

CREATE TABLE `house` (
  `house_id` int(11) NOT NULL,
  `landlord_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `house_type` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `bedrooms` int(11) DEFAULT NULL,
  `bathrooms` int(11) DEFAULT NULL,
  `furnishing` varchar(100) DEFAULT NULL,
  `availability_status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `house`
--

INSERT INTO `house` (`house_id`, `landlord_id`, `title`, `description`, `house_type`, `address`, `city`, `price`, `bedrooms`, `bathrooms`, `furnishing`, `availability_status`, `created_at`) VALUES
(1, 1, 'Sunny Apartment', 'Bright apartment with mountain view and modern kitchen.', 'Apartment', 'Kalimati, Kathmandu', 'Kathmandu', 25000.00, 2, 2, 'Semi-Furnished', 'available', '2026-06-27 16:24:59'),
(2, 1, 'Cozy Family House', 'Spacious house near school and hospital.', 'House', 'Boudha, Kathmandu', 'Kathmandu', 35000.00, 3, 2, 'Furnished', 'available', '2026-06-27 16:24:59'),
(3, 2, 'Lake View Villa', 'Beautiful villa with peaceful environment and garden.', 'Villa', 'Lakeside, Pokhara', 'Pokhara', 42000.00, 4, 3, 'Furnished', 'available', '2026-06-27 16:24:59'),
(4, 2, 'Modern Studio', 'Compact and stylish studio for working professionals.', 'Studio', 'Dharahara, Pokhara', 'Pokhara', 18000.00, 1, 1, 'Semi-Furnished', 'booked', '2026-06-27 16:24:59');

-- --------------------------------------------------------

--
-- Table structure for table `house_amenity`
--

CREATE TABLE `house_amenity` (
  `house_amenity_id` int(11) NOT NULL,
  `house_id` int(11) DEFAULT NULL,
  `amenity_id` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `house_amenity`
--

INSERT INTO `house_amenity` (`house_amenity_id`, `house_id`, `amenity_id`) VALUES
(1, 1, 1),
(2, 1, 2),
(3, 1, 4),
(4, 2, 1),
(5, 2, 3),
(6, 3, 1),
(7, 3, 2),
(8, 3, 4),
(9, 4, 1),
(10, 4, 5);

-- --------------------------------------------------------

--
-- Table structure for table `house_image`
--

CREATE TABLE `house_image` (
  `image_id` int(11) NOT NULL,
  `house_id` int(11) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `house_image`
--

INSERT INTO `house_image` (`image_id`, `house_id`, `image_url`) VALUES
(1, 1, 'images/house1.jpg'),
(2, 2, 'images/house2.jpg'),
(3, 3, 'images/house3.jpg'),
(4, 4, 'images/house4.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `interest_request`
--

CREATE TABLE `interest_request` (
  `request_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `house_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `request_status` varchar(50) DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `interest_request`
--

INSERT INTO `interest_request` (`request_id`, `tenant_id`, `house_id`, `message`, `request_status`, `requested_at`) VALUES
(1, 1, 2, 'I would like to schedule a visit this weekend.', 'pending', '2026-06-27 16:25:11'),
(2, 1, 3, 'Interested in booking the villa for next month.', 'accepted', '2026-06-27 16:25:11'),
(3, 2, 1, 'Please share more details about the apartment.', 'pending', '2026-06-27 16:25:11');

-- --------------------------------------------------------

--
-- Table structure for table `landlord`
--

CREATE TABLE `landlord` (
  `landlord_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `citizenship_no` varchar(255) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `citizenship_image` varchar(255) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_status` varchar(50) DEFAULT NULL,
  `land_ownership_certificate_no` varchar(30) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `landlord`
--

INSERT INTO `landlord` (`landlord_id`, `user_id`, `citizenship_no`, `verified_at`, `verification_status`, `land_ownership_certificate_no`) VALUES
(1, 2, 'CIT-001', '2025-01-15 04:15:00', 'verified', 'LAND-001'),
(2, 6, 'CIT-002', '2025-02-10 03:45:00', 'rejected', 'LAND-002'),
(3, 9, NULL, NULL, 'pending', NULL),
(4, 10, NULL, '2026-08-08 06:19:53', 'verified', NULL),
(5, 11, NULL, '2026-08-07 18:26:07', 'verified', NULL),
(6, 14, NULL, '2026-08-08 06:22:56', 'verified', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `review`
--

CREATE TABLE `review` (
  `review_id` int(11) NOT NULL,
  `house_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `review`
--

INSERT INTO `review` (`review_id`, `house_id`, `tenant_id`, `rating`, `comment`, `created_at`) VALUES
(1, 1, 3, 5, 'Beautiful apartment, very sunny and clean!', '2026-07-10 20:04:07'),
(2, 2, 4, 4, 'Great house, spacious and close to the market.', '2026-07-12 20:04:07'),
(3, 3, 5, 5, 'Stunning view of the lake! Highly recommended.', '2026-07-14 20:04:07');

-- --------------------------------------------------------

--
-- Table structure for table `tenant`
--

CREATE TABLE `tenant` (
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `occupation` varchar(255) DEFAULT NULL,
  `preferred_location` varchar(255) DEFAULT NULL,
  `budget` decimal(10,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenant`
--

INSERT INTO `tenant` (`tenant_id`, `user_id`, `occupation`, `preferred_location`, `budget`) VALUES
(1, 1, 'Software Engineer', 'Kathmandu', 45000.00),
(2, 2, 'Teacher', 'Pokhara', 30000.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `citizenship_id` varchar(50) NOT NULL,
  `user_type` enum('tenant','landlord','admin') NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `phone_number`, `citizenship_id`, `user_type`, `password`, `created_at`, `updated_at`) VALUES
(1, 'System Admin', 'admin', '9800000000', 'ADMIN001', 'admin', '$2y$10$zfWKK.tbv3Z.oiXosNlvj.uLuznCxCG3hrXw8eQVxkY3OaUxtgyIi', '2026-05-28 07:37:39', '2026-06-27 13:46:41'),
(13, 'kanak bhatta', 'kanak', '9876543216', '123456765438', 'tenant', '$2y$10$FvdmWLFdJ3kYhgof8TrRbuPAM4dx5WmyGCFWJnzrVsHyxSuxpzvN.', '2026-08-08 06:20:56', '2026-08-08 06:20:56'),
(14, 'sopanil joshi', 'sopanil', '1234567845', '3456787654588', 'landlord', '$2y$10$UcFcF0XLrjzS8kzShbnXSupfTInBP9jAnyPBUH54MZaK6H8tGyCcO', '2026-08-08 06:22:45', '2026-08-08 06:22:45');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `amenity`
--
ALTER TABLE `amenity`
  ADD PRIMARY KEY (`amenity_id`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `favorite`
--
ALTER TABLE `favorite`
  ADD PRIMARY KEY (`favorite_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `house_id` (`house_id`);

--
-- Indexes for table `house`
--
ALTER TABLE `house`
  ADD PRIMARY KEY (`house_id`),
  ADD KEY `landlord_id` (`landlord_id`);

--
-- Indexes for table `house_amenity`
--
ALTER TABLE `house_amenity`
  ADD PRIMARY KEY (`house_amenity_id`),
  ADD KEY `house_id` (`house_id`),
  ADD KEY `amenity_id` (`amenity_id`);

--
-- Indexes for table `house_image`
--
ALTER TABLE `house_image`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `house_id` (`house_id`);

--
-- Indexes for table `interest_request`
--
ALTER TABLE `interest_request`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `tenant_id` (`tenant_id`),
  ADD KEY `house_id` (`house_id`);

--
-- Indexes for table `landlord`
--
ALTER TABLE `landlord`
  ADD PRIMARY KEY (`landlord_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `review`
--
ALTER TABLE `review`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `house_id` (`house_id`),
  ADD KEY `tenant_id` (`tenant_id`);

--
-- Indexes for table `tenant`
--
ALTER TABLE `tenant`
  ADD PRIMARY KEY (`tenant_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `amenity`
--
ALTER TABLE `amenity`
  MODIFY `amenity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `favorite`
--
ALTER TABLE `favorite`
  MODIFY `favorite_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `house`
--
ALTER TABLE `house`
  MODIFY `house_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `house_amenity`
--
ALTER TABLE `house_amenity`
  MODIFY `house_amenity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `house_image`
--
ALTER TABLE `house_image`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `interest_request`
--
ALTER TABLE `interest_request`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `landlord`
--
ALTER TABLE `landlord`
  MODIFY `landlord_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `review`
--
ALTER TABLE `review`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tenant`
--
ALTER TABLE `tenant`
  MODIFY `tenant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
