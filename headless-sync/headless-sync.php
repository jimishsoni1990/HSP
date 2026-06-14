<?php
/**
 * Plugin Name: Headless Sync Platform (HSP)
 * Description: Asynchronous synchronization engine connecting WordPress editor actions to PostgreSQL read-optimized projections.
 * Version: 1.0.0
 * Author: HSP Team
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_filter('wp_is_application_passwords_supported', '__return_true');
add_filter('wp_is_application_passwords_available', '__return_true');
add_filter('wp_is_application_passwords_available_for_user', '__return_true');
add_filter('wp_is_application_passwords_in_use', '__return_true');

// Load Composer autoloader
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// Initialize bootstrapper
if (class_exists('HSP\Bootstrap\Bootstrapper')) {
    \HSP\Bootstrap\Bootstrapper::init(__FILE__);
}
