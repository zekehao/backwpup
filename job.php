<?php
if (empty($_GET['starttype']) and !defined('STDIN')) {
	header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
	header("Status: 404 Not Found");
	die();
}
ignore_user_abort(true);
define('DOING_BACKWPUP_JOB', true);
define('DONOTCACHEPAGE', true);
define('DONOTCACHEDB', true);
define('DONOTMINIFY', true);
define('DONOTCDN', true);
define('DONOTCACHCEOBJECT', true);
define('W3TC_IN_MINIFY', false); //W3TC will not loaded
define('DOING_CRON',true);
define('BACKWPUP_LINE_SEPARATOR', (false !== strpos(PHP_OS, "WIN") or false !== strpos(PHP_OS, "OS/2")) ? "\r\n" : "\n");
//define E_DEPRECATED if PHP lower than 5.3
if ( !defined('E_DEPRECATED') )
	define('E_DEPRECATED', 8192);
if ( !defined('E_USER_DEPRECATED') )
	define('E_USER_DEPRECATED', 16384);
//phrase commandline args
if ( defined('STDIN') ) {
	$_GET['starttype'] = 'runcmd';
	foreach ( $_SERVER['argv'] as $arg ) {
		if ( strtolower(substr($arg, 0, 7)) == '-jobid=' )
			$_GET['jobid'] = (int)substr($arg, 7);
		if ( strtolower(substr($arg, 0, 9)) == '-abspath=' )
			$_GET['ABSPATH'] = substr($arg, 9);
	}
	@chdir(dirname(__FILE__));
	if ( is_file('../../../wp-load.php') ) {
		require_once('../../../wp-load.php');
	} else {
		$_GET['ABSPATH']=rtrim($_GET['ABSPATH'],'/');
		if ( is_dir($_GET['ABSPATH']) and file_exists($_GET['ABSPATH'] . '/wp-load.php') )
			require_once($_GET['ABSPATH'] . '/wp-load.php');
		else
			die('ABSPATH check');
	}
	if ( (empty($_GET['jobid']) or !is_numeric($_GET['jobid'])) )
		die(__('JOBID check','backwpup-job'));
	@set_time_limit(0);
} else { //normal start from webservice
	//check get vars
	@chdir(dirname(__FILE__));
	if ( is_file('../../../wp-load.php') ) {
		require_once('../../../wp-load.php');
	} else {
		$_GET['ABSPATH']=rtrim(preg_replace('/[^a-zA-Z0-9. :_\/-]/', '',trim(urldecode($_GET['ABSPATH']))),'/');
		if (substr($_GET['ABSPATH'],1,1)==':')
			$_GET['ABSPATH'] =realpath(str_replace(array('..'), '', $_GET['ABSPATH']));
		else
			$_GET['ABSPATH'] = realpath('/'.ltrim(str_replace(array(':','..'), '', $_GET['ABSPATH']),'/'));
		if ( !empty($_GET['ABSPATH']) and is_dir($_GET['ABSPATH'].'/') and file_exists(realpath($_GET['ABSPATH'] . '/wp-load.php')) ) {
			require_once(realpath($_GET['ABSPATH'] . '/wp-load.php'));
		} else {
			header($_SERVER["SERVER_PROTOCOL"]." 400 Bad Request",400);
			die('ABSPATH check');
		}
	}
	if (isset($_GET['_nonce']))
		$_GET['_nonce'] = preg_replace('/[^a-zA-Z0-9]/', '', trim($_GET['_nonce']));
	if ( empty($_GET['_nonce']) or !is_string($_GET['_nonce']) )
		wp_die(__('Nonce pre check','backwpup-job'),__('Nonce pre check','backwpup-job'),array( 'response' => 403 ));
	if ( empty($_GET['starttype']) or !in_array($_GET['starttype'], array( 'restart', 'runnow', 'runnowalt', 'runext','apirun' )) )
		wp_die(__('Starttype check','backwpup-job'),__('Starttype check','backwpup-job'),array( 'response' => 400 ));
	if ( (empty($_GET['jobid']) or !is_numeric($_GET['jobid'])) and in_array($_GET['starttype'], array( 'runnow', 'runnowalt', 'runext', 'apirun' )) )
		wp_die(__('JOBID check','backwpup-job'),__('JOBID check','backwpup-job'),array( 'response' => 400 ));

	if ( in_array($_GET['starttype'], array( 'runnow', 'runnowalt' )) and backwpup_get_option('temp',$_GET['starttype'].'_nonce_'.$_GET['jobid'])!=$_GET['_nonce'])
		wp_die(__('Nonce check','backwpup-job'),__('Nonce check','backwpup-job'),array( 'response' => 403));
	elseif ( $_GET['starttype']=='restart' and backwpup_get_option('temp',$_GET['starttype'].'_nonce')!=$_GET['_nonce'])
		wp_die(__('Nonce check','backwpup-job'),__('Nonce check','backwpup-job'),array( 'response' => 403 ));
	elseif ( $_GET['starttype']=='apirun' and (!backwpup_get_option('cfg','apicronservicekey') or $_GET['_nonce']!=backwpup_get_option('cfg','apicronservicekey')))
		wp_die(__('Nonce check','backwpup-job'),__('Nonce check','backwpup-job'),array( 'response' => 403 ));
	elseif ( $_GET['starttype']=='runext' and (backwpup_get_option('cfg','jobrunauthkey') or $_GET['_nonce']!=backwpup_get_option('cfg','jobrunauthkey')))
		wp_die(__('Nonce check','backwpup-job'),__('Nonce check','backwpup-job'),array( 'response' => 403 ));
	//delete nonce
	backwpup_delete_option('temp',$_GET['starttype'].'_nonce_'.$_GET['jobid']);
	backwpup_delete_option('temp',$_GET['starttype'].'_nonce');
	//set max execution time
	@set_time_limit(backwpup_get_option('cfg','jobrunmaxexectime'));
}
//check job id exists
if (in_array($_GET['starttype'], array( 'runnow', 'runnowalt', 'runext', 'apirun', 'runcmd' )))  {
	if ( $_GET['jobid'] != backwpup_get_option('job_' . $_GET['jobid'], 'jobid'))
		wp_die(__('Wrong JOBID check','backwpup-job'),__('Wrong JOBID check','backwpup-job'),array( 'response' => 400 ));
}
//check api run is in time windows
if ($_GET['starttype']=='apirun')  {
	$nextruntime=backwpup_get_option('job_' . $_GET['jobid'], 'cronnextrun');
	$timenow=current_time('timestamp');
	if ( ($nextruntime+1800)<$timenow or ($nextruntime-1800)>$timenow)
		wp_die(__('API run on false time','backwpup-job'),__('API run on false time','backwpup-job'),array( 'response' => 400 ));
}
//check folders
if (!backwpup_get_option('cfg','logfolder') or !is_dir(backwpup_get_option('cfg','logfolder')) or !is_writable(backwpup_get_option('cfg','logfolder')))
	wp_die(__('Log folder not exists or is not writable','backwpup-job'),__('Log folder not exists or is not writable','backwpup-job'),array( 'response' => 500 ));
if (!backwpup_get_option('cfg','tempfolder') or !is_dir(backwpup_get_option('cfg','tempfolder')) or !is_writable(backwpup_get_option('cfg','tempfolder')))
	wp_die(__('Temp folder not exists or is not writable','backwpup-job'),__('Temp folder not exists or is not writable','backwpup-job'),array( 'response' => 500 ));
//check running job
if ( in_array($_GET['starttype'], array( 'runnow', 'runnowalt', 'runext', 'runcmd','apirun' )) and backwpup_get_workingdata(false) )
	wp_die(__('A job already running','backwpup-job'),__('A job already running','backwpup-job'),array( 'response' => 503 ));
if ( in_array($_GET['starttype'], array( 'restart' )) and !backwpup_get_workingdata(false) )
	wp_die(__('No job running','backwpup-job'),__('No job running','backwpup-job'),array( 'response' => 400 ));
//disconnect or redirect
if ( in_array($_GET['starttype'], array( 'restart', 'runnowalt', 'runext','apirun' )) ) {
	nocache_headers();
	@ob_end_clean();
	header("Connection: close");
	@ob_start();
	header("Content-Length: 0");
	@ob_end_flush();
	@flush();
}
elseif ( $_GET['starttype'] == 'runnow' ) {
	nocache_headers();
	@ob_start();
	wp_redirect(add_query_arg(array('page'=>'backwpupworking','jobid'=>$_GET['jobid']),backwpup_admin_url('admin.php')));
	echo ' ';
	while ( @ob_end_flush() );
	@flush();
}
//start class
new BackWPup_job($_GET['starttype'],(int)$_GET['jobid']);