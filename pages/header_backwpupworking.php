<?PHP
if (!defined('ABSPATH'))
	die();

nocache_headers(); //no chache
if (!empty($_GET['logfile']))
	check_admin_referer('view-log_'.basename(trim($_GET['logfile'])));

if (!empty($_GET['runlogjobid']))
	$_GET['logfile']=backwpup_get_option('job_' . $_GET['runlogjobid'], 'logfile');

//add Help
if (method_exists(get_current_screen(),'add_help_tab')) {
	get_current_screen()->add_help_tab( array(
		'id'      => 'overview',
		'title'   => __('Overview'),
		'content'	=>
		'<p>' .__('Here you see a working jobs or a logfile','backwpup') . '</p>'
	) );
}
?>