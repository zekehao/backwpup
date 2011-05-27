<?PHP
if (!defined('ABSPATH')) {
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	header("Status: 404 Not Found");
	die();
}
	
echo "<div class=\"wrap\">";
screen_icon();
echo "<h2>".esc_html( __('BackWPup Jobs', 'backwpup'))."&nbsp;<a href=\"".wp_nonce_url('admin.php?page=backwpupEditJob', 'edit-job')."\" class=\"button add-new-h2\">".esc_html__('Add New')."</a></h2>";
if (isset($backwpup_message) and !empty($backwpup_message)) 
	echo "<div id=\"message\" class=\"updated\"><p>".$backwpup_message."</p></div>";
echo "<form id=\"posts-filter\" action=\"".get_admin_url()."admin.php?page=backwpup\" method=\"post\">";
wp_nonce_field('backwpup_ajax_nonce', 'backwpupajaxnonce', false ); 
$backwpup_listtable->display();
echo "<div id=\"ajax-response\"></div>";
echo "</form>"; 
echo "</div>";	
?>