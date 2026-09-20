<?php
/* ======================================================================
 *  EDIT THESE ONLY IF YOUR SETUP IS DIFFERENT FROM A DEFAULT XAMPP
 * ====================================================================== */

// MySQL / MariaDB connection (XAMPP default: user "root", empty password)
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'scholarhub_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Code a person must enter to register a new ADMIN account.
// Change this before giving the project to anyone else.
define('ADMIN_REGISTRATION_CODE', 'SH-ADMIN-2026');

// Show the demo accounts box on the login page (turn off for real use).
define('SHOW_DEMO_ACCOUNTS', true);

// How long "Remember me" keeps a user logged in (days).
define('REMEMBER_DAYS', 30);

define('APP_NAME', 'ScholarHub');
define('APP_TAGLINE', 'Scholarship Management System');
date_default_timezone_set('Asia/Dhaka');

// Payment gateway. 'test' = full checkout flow with test credentials, no real money.
// Going live needs a merchant account with bKash / Nagad / Rocket or a card gateway
// (see includes/payment.php).
define('PAYMENT_MODE', 'test');
define('MERCHANT_NAME', 'ScholarHub Scholarship Office');
define('MERCHANT_ADDRESS', 'House 21, Road 7, Dhanmondi, Dhaka 1205');
define('MERCHANT_EMAIL', 'accounts@scholarhub.example');
