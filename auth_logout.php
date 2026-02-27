<?php
/**
 * =============================================================================
 * VerdictTrace - Logout
 * =============================================================================
 * Destroys the session and redirects to the login/index page.
 * =============================================================================
 */

session_start();
session_unset();
session_destroy();

header('Location: index.php');
exit;
