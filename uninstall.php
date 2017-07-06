<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

global $wpdb;

$table_name = $wpdb->prefix.'sms_twilio_credentials';

// drop the table from the database.
$wpdb->query("DROP TABLE IF EXISTS $table_name");

delete_option('sms_twilio_db_version');

?>