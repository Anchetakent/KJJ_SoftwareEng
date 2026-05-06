-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 06, 2026 at 08:52 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `class_record_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `max_score` decimal(10,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_email` varchar(191) NOT NULL,
  `action` varchar(255) NOT NULL,
  `log_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_email`, `action`, `log_time`) VALUES
(1, 'sysadmin@edupulse.local', 'Successful login via web portal.', '2026-04-30 03:12:10'),
(2, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 03:25:21'),
(3, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 03:36:14'),
(4, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 07:04:21'),
(5, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 07:24:58'),
(6, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 07:56:45'),
(7, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 07:59:01'),
(8, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-04-30 11:46:01'),
(9, 'faculty@edupulse.local', 'Successful login via web portal.', '2026-05-04 07:53:32'),
(10, 'kentlouisea@gmail.com', 'New account registered and verified via OTP.', '2026-05-04 08:21:08'),
(11, 'kentlouisea@gmail.com', 'Successful login via web portal.', '2026-05-04 08:21:32'),
(12, 'kurtalbertancheta000@gmail.com', 'New account registered and verified via email OTP.', '2026-05-04 09:18:35'),
(13, 'kurtalbertancheta000@gmail.com', 'Account password recovered and reset via email OTP.', '2026-05-04 09:19:03'),
(14, 'kurtalbertancheta000@gmail.com', 'Successful login via web portal.', '2026-05-04 09:19:10'),
(15, 'kurtalbertancheta000@gmail.com', 'Successful SSO login via Google', '2026-05-04 11:35:38'),
(16, 'AKT0480@dlsud.edu.ph', 'Successful SSO login via Microsoft', '2026-05-04 11:35:54'),
(17, 'sysadmin@edupulse', 'Successful login via web portal.', '2026-05-04 11:50:10'),
(18, 'kurtalbertancheta000@gmail.com', 'Successful SSO login via Google', '2026-05-04 11:51:18'),
(19, 'sysadmin@edupulse', 'Successful login via web portal.', '2026-05-04 12:47:38'),
(20, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:50:31'),
(21, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:53:23'),
(22, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:53:24'),
(23, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:53:25'),
(24, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:53:25'),
(25, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:53:28'),
(26, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:53:29'),
(27, 'kentlouisea@gmail.com', 'Password reset by sysadmin@edupulse', '2026-05-04 12:54:03'),
(28, 'sysadmin@edupulse', 'Successful login via web portal.', '2026-05-04 12:54:54'),
(29, 'faculty@edupulse.local', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:55:17'),
(30, 'faculty@edupulse.local', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:55:18'),
(31, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:55:18'),
(32, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:55:49'),
(33, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:56:56'),
(34, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:56:57'),
(35, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:56:58'),
(36, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:57:03'),
(37, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:57:18'),
(38, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 12:57:19'),
(39, 'kentlouisea@gmail.com', 'Account reactivated by sysadmin@edupulse', '2026-05-04 12:57:20'),
(40, 'sysadmin@edupulse', 'Successful login via web portal.', '2026-05-04 13:53:37'),
(41, 'faculty@edupulse.local', 'Account suspended by sysadmin@edupulse', '2026-05-04 13:53:44'),
(42, 'kentlouisea@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 13:53:50'),
(43, 'kurtalbertancheta000@gmail.com', 'Account suspended by sysadmin@edupulse', '2026-05-04 13:53:53'),
(44, 'AKT0480@dlsud.edu.ph', 'Account suspended by sysadmin@edupulse', '2026-05-04 13:53:54'),
(45, 'faculty@edupulse.local', 'Account permanently deleted by sysadmin@edupulse', '2026-05-04 13:57:10'),
(46, 'kentlouisea@gmail.com', 'Account permanently deleted by sysadmin@edupulse', '2026-05-04 13:57:12'),
(47, 'kurtalbertancheta000@gmail.com', 'Account permanently deleted by sysadmin@edupulse', '2026-05-04 13:57:14'),
(48, 'AKT0480@dlsud.edu.ph', 'Account permanently deleted by sysadmin@edupulse', '2026-05-04 13:57:15'),
(49, 'kentlouiseancheta7@gmail.com', 'New account registered and verified via email OTP.', '2026-05-05 00:43:11');

-- --------------------------------------------------------

--
-- Table structure for table `email_otp_requests`
--

CREATE TABLE `email_otp_requests` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `otp_code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified` tinyint(1) DEFAULT 0,
  `attempts` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_otp_requests`
--

INSERT INTO `email_otp_requests` (`id`, `email`, `otp_code`, `expires_at`, `verified`, `attempts`, `created_at`) VALUES
(1, 'kurtalbertancheta000@gmail.com', '430046', '2026-05-04 11:27:33', 1, 0, '2026-05-04 09:17:33'),
(2, 'kurtalbertancheta000@gmail.com', '898204', '2026-05-04 11:28:46', 1, 0, '2026-05-04 09:18:46'),
(3, 'kentlouiseancheta7@gmail.com', '654142', '2026-05-05 02:52:47', 1, 0, '2026-05-05 00:42:47');

-- --------------------------------------------------------

--
-- Table structure for table `grading_categories`
--

CREATE TABLE `grading_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `section_id` int(10) UNSIGNED NOT NULL,
  `term` varchar(20) NOT NULL,
  `name` varchar(191) NOT NULL,
  `weight` decimal(5,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(191) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `email`, `ip`, `success`, `attempted_at`) VALUES
(1, 'sysadmin@edupulse', '::1', 1, '2026-05-04 13:53:37');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(10) UNSIGNED NOT NULL,
  `section_name` varchar(191) NOT NULL,
  `owner_email` varchar(191) NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `section_name`, `owner_email`, `notes`) VALUES
(6, 'test', 'kentlouiseancheta7@gmail.com', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(10) UNSIGNED NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `name` varchar(191) NOT NULL,
  `section_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_scores`
--

CREATE TABLE `student_scores` (
  `student_id` int(10) UNSIGNED NOT NULL,
  `assignment_id` int(10) UNSIGNED NOT NULL,
  `score` decimal(10,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(191) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` varchar(50) NOT NULL,
  `auth_provider` varchar(50) DEFAULT 'local',
  `provider_id` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `role`, `auth_provider`, `provider_id`, `status`) VALUES
(1, 'sysadmin@edupulse', '$2y$10$DZVF97LlAbrBDWtuQ/V96ekIuzMYhqItO3G8M7IknOwiA8UGp.Gem', 'System Admin', 'local', NULL, 'Active'),
(6, 'kentlouiseancheta7@gmail.com', '$2y$10$Ga3PW97BVu36xYcCCFCHCe97EwOWS5iXGm3CxItLJnf1ZKbvx5oLu', 'Faculty', 'local', NULL, 'Active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assignments_category` (`category_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user_email` (`user_email`),
  ADD KEY `idx_audit_log_time` (`log_time`);

--
-- Indexes for table `email_otp_requests`
--
ALTER TABLE `email_otp_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`);

--
-- Indexes for table `grading_categories`
--
ALTER TABLE `grading_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_categories_section` (`section_id`),
  ADD KEY `idx_categories_term` (`term`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_attempts_email_ip` (`email`,`ip`),
  ADD KEY `idx_login_attempts_time` (`attempted_at`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sections_owner_name` (`owner_email`,`section_name`),
  ADD KEY `idx_sections_owner_email` (`owner_email`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_students_section_studentid` (`section_id`,`student_id`),
  ADD KEY `idx_students_section` (`section_id`);

--
-- Indexes for table `student_scores`
--
ALTER TABLE `student_scores`
  ADD PRIMARY KEY (`student_id`,`assignment_id`),
  ADD KEY `idx_scores_assignment` (`assignment_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `email_otp_requests`
--
ALTER TABLE `email_otp_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `grading_categories`
--
ALTER TABLE `grading_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `fk_assignments_category` FOREIGN KEY (`category_id`) REFERENCES `grading_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `grading_categories`
--
ALTER TABLE `grading_categories`
  ADD CONSTRAINT `fk_categories_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_sections_owner_email` FOREIGN KEY (`owner_email`) REFERENCES `users` (`email`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_scores`
--
ALTER TABLE `student_scores`
  ADD CONSTRAINT `fk_scores_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_scores_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
