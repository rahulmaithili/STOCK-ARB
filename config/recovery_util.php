<?php
/**
 * Cryptographic Offline Password Recovery Helper
 * Calculates matching Request and Approval keys using stateless hash matching.
 */

define('RECOVERY_SECRET', 'StockArb_Secret_Offline_Reset_2026_Key!');

/**
 * Generate a 5-digit Request Code for a given user email
 */
function generate_request_code($email) {
    $clean_email = strtolower(trim($email));
    $hash = md5($clean_email . RECOVERY_SECRET . "RequestSalt");
    // Get numeric value from hash and bound to 5 digits (10000 to 99999)
    $number = (hexdec(substr($hash, 0, 8)) % 90000) + 10000;
    return $number;
}

/**
 * Generate a 5-digit Approval Code for a given Request Code
 */
function generate_approval_code($request_code) {
    $clean_code = trim($request_code);
    $hash = md5($clean_code . RECOVERY_SECRET . "ApprovalSalt");
    // Get numeric value from hash and bound to 5 digits (10000 to 99999)
    $number = (hexdec(substr($hash, 0, 8)) % 90000) + 10000;
    return $number;
}
?>
