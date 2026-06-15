<?php
/**
 * Editorial QA — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes the
 * plugin's options and all post meta it created. (Single-site scope.)
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Settings + cached model lists.
delete_option('ogm_eqa_settings');
delete_option('ogm_eqa_models');

// All post meta written by the scanner.
$ogm_eqa_meta_keys = [
    '_ogm_eqa_report',
    '_ogm_eqa_issue_count',
    '_ogm_eqa_last_scanned_gmt',
    '_ogm_eqa_scanned_hash',
    '_ogm_eqa_model',
    '_ogm_eqa_error',
    '_ogm_eqa_last_trigger_user_id',
];
foreach ($ogm_eqa_meta_keys as $ogm_eqa_key) {
    delete_post_meta_by_key($ogm_eqa_key);
}
