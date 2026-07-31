<?php
/**
 * Uninstall file for Cloudflare Responsive Images plugin
 * 
 * This file is executed when the plugin is deleted.
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('cfri_options');
