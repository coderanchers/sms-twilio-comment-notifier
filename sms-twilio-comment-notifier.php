<?php

/*
Plugin Name: SMS Twilio Comment Notifier
Plugin URI: http://www.coderanchers.com
Description: Example of using Twilio to send SMS Notifications of replies to a user's comments
Version: 1.0
Author: CodeRanchers, LLC
Author URI: http://www.coderanchers.com
*/

//download the latest twilio libraries and place them in the plugin root.
require __DIR__ . '/twilio-php-master/Twilio/autoload.php';

use Twilio\Rest\Client;

// Create the table used to store the Twilio credentials
register_activation_hook(__FILE__, 'sms_twilio_comment_notifier_create_db');

function sms_twilio_comment_notifier_create_db() {
    global $wpdb;
    global $sms_twilio_db_version;

    $table_name = $wpdb->prefix . 'sms_twilio_credentials';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE ".$table_name." (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		sid varchar(100) NOT NULL,
		token varchar(100) NOT NULL,
		twilio_number varchar(25) NOT NULL,
		UNIQUE KEY id (id)
	)" .$charset_collate.";";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    $row_count = $wpdb->get_var("select count(*) from $table_name");
    if($row_count == 0) {
        $wpdb->insert($table_name, array('sid' => 'sid', 'token' => 'token', 'twilio_number' => 'twilio number'));
    }



    add_option( 'sms_twilio_db_version', $sms_twilio_db_version );
}

// Create the settings page where the admin can set the Twilio Credentials.
// This is going to take in one Twilio number for now; you can easily expand to add multiple.

add_action('admin_menu', 'sms_twilio_comment_notifier_add_admin_page');

function sms_twilio_comment_notifier_add_admin_page() {

    add_menu_page('SMS Twilio Comment Notifier', 'SMS Twilio Comment Notifier', 'edit_pages', 'sms_twilio_comment_notifier_options', 'sms_twilio_comment_notifier_admin_page');

}

add_action('admin_post_sms_twilio_comment_notifier_submit', 'process_sms_twilio_comment_notifier_submit');

function sms_twilio_comment_notifier_admin_page() {

    global $wpdb;
    $table_name = $wpdb->prefix . 'sms_twilio_credentials';
    $twilio_creds = $wpdb->get_results("select * from $table_name where `id` = '1'");

    ?>
    <div>
        <h2>Twilio Settings</h2>

        <form name="sms_twilio_comment_notifier_admin" action="admin-post.php" method="post">
            <input type="hidden" name="action" value="sms_twilio_comment_notifier_submit"
            <?php wp_nonce_field('sms_twilio_comment_notifier_verify'); ?>
            <table>
                <tr>
                    <td>SID:</td>
                    <td><input type="text" name="sms_twilio_comment_notifier_sid_value" value="<?php echo($twilio_creds[0]->sid) ?>" size="50"/></td>
                </tr>
                <tr>
                    <td>Token:</td>
                    <td><input type="text" name="sms_twilio_comment_notifier_token_value" value="<?php echo($twilio_creds[0]->token) ?>" size="50"/></td>
                </tr>
                <tr>
                    <td>Twilio Number:</td>
                    <td><input type="text" name="sms_twilio_comment_notifier_twilio_number_value" value="<?php echo($twilio_creds[0]->twilio_number) ?>" size="50"/></td>
                </tr>
                <tr>
                    <td></td>
                    <td><input name="sms_twilio_comment_notifier_submit" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" /></td>
                </tr>
            </table>
        </form></div>

    <?php
}

//process the admin form
function process_sms_twilio_comment_notifier_submit() {

    global $wpdb;
    //verify the nonce
    wp_verify_nonce('sms_twilio_comment_notifier_verify');

    if(isset($_POST['sms_twilio_comment_notifier_submit'])){

        $sidInput = sanitize_text_field($_POST['sms_twilio_comment_notifier_sid_value']);
        $tokenInput = sanitize_text_field($_POST['sms_twilio_comment_notifier_token_value']);
        $twilioNumberInput = sanitize_text_field($_POST['sms_twilio_comment_notifier_twilio_number_value']);

        $table_name = $wpdb->prefix . 'sms_twilio_credentials';

        $wpdb->update($table_name, array('sid' => $sidInput, 'token' => $tokenInput, 'twilio_number' => $twilioNumberInput), array('id' => '1'));

        wp_redirect(admin_url('/admin.php?page=sms_twilio_comment_notifier_options'));
    }
}


// add the user's mobile number to their profile screen.

add_action('show_user_profile', 'sms_twilio_user_comment_notifier_profile_fields');
add_action('edit_user_profile', 'sms_twilio_user_comment_notifier_profile_fields');

function sms_twilio_user_comment_notifier_profile_fields( $user ) { ?>
    <h3><?php _e("SMS Twilio profile information", "blank"); ?></h3>

    <table class="form-table">
        <tr>
            <th><label for="mobile"><?php _e("Mobile"); ?></label></th>
            <td>
                <input type="text" name="mobile" id="mobile" value="<?php echo esc_attr( get_the_author_meta( 'sms_twilio_mobile', $user->ID ) ); ?>" class="regular-text" /><br />
                <span class="description"><?php _e("Please enter your mobile number for SMS notifications. By entering your mobile number you are consenting to be notified by SMS of notifications and standard messaging rates apply."); ?></span>
            </td>
        </tr>
    </table>
<?php }

add_action( 'personal_options_update', 'save_sms_twilio_user_profile_fields' );
add_action( 'edit_user_profile_update', 'save_sms_twilio_user_profile_fields' );

function save_sms_twilio_user_profile_fields( $user_id ) {

    if ( !current_user_can( 'edit_user', $user_id ) ) { return false; }

    update_user_meta( $user_id, 'sms_twilio_mobile', $_POST['mobile'] );
}

// add action to capture comment status changes including publication

add_action('wp_insert_comment', 'sms_twilio_comment_notification', 99, 2);
add_action('wp_set_comment_status', 'sms_twilio_comment_status_changed', 99, 2);

function sms_twilio_comment_notification($comment_id, $comment_object) {

    // Check to see if the comment is approved or if it's not a child comment, since
    // we only notify of comment replies at this point
    if ($comment_object->comment_approved != 1 || $comment_object->comment_parent < 1) {
        return;
    }
    $comment_parent = get_comment($comment_object->comment_parent);

    // Don't notify the user if they reply to their own comment
    if ($comment_parent->user_id == $comment_object->user_id) {
        return;
    }

    // Send the SMS notification
    send_SMS($comment_object, $comment_id, $comment_parent);
}

function sms_twilio_comment_status_changed($comment_id, $comment_status) {
    $comment_object = get_comment($comment_id);
    if ($comment_status == 'approve') {
        sms_twilio_comment_notification($comment_object->comment_ID, $comment_object);
    }
}
function send_SMS($comment_object, $comment_id, $comment_parent){

    //You could hard code this, but I prefer to keep it in a database table.

    global $wpdb;
    $table_name = $wpdb->prefix . 'sms_twilio_credentials';
    $credential_sql = 'select * from $table_name';

    $sid;
    $token;
    $twilio_number;

   $creds = $wpdb->get_results($credential_sql);
        $sid= $creds[0]->sid;
        $token = $creds[0]->token;
        $twilio_number = $creds[0]->twilio_number;
    
    $client = new Client($sid, $token);

    $mobile = get_the_author_meta('sms_twilio_mobile', $comment_parent->user_id);

    if($mobile){

        $client->messages->create(
            get_the_author_meta('sms_twilio_mobile', $comment_parent->user_id),
            array(
                'from' => $twilio_number,
                'body' => "A new post was published at ".get_site_url()." See it here: ".get_comment_link($comment_object)." Reply with the WordPress app here: wordpress://ranchermedia.com"
            )
        );
    }
}
?>
