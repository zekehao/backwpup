<?php
// don't load directly 
if ( !defined('ABSPATH') ) 
	die('-1');

	
//function for PHP error handling	
function backwpup_joberrorhandler($errno, $errstr, $errfile, $errline) {
	global $backwpup_logfile;
	
	//genrate timestamp
	if (!function_exists('memory_get_usage')) { // test if memory functions compiled in
		$timestamp="<span style=\"background-color:c3c3c3;\" title=\"[Line: ".$errline."|File: ".basename($errfile)."\">".date_i18n('Y-m-d H:i.s').":</span> ";
	} else  {
		if (version_compare(phpversion(), '5.2.0', '<'))
			$timestamp="<span style=\"background-color:c3c3c3;\" title=\"[Line: ".$errline."|File: ".basename($errfile)."|Mem: ".backwpup_formatBytes(@memory_get_usage())."|Mem Max: ".backwpup_formatBytes(@memory_get_peak_usage())."|Mem Limit: ".ini_get('memory_limit')."]\">".date_i18n('Y-m-d H:i.s').":</span> ";
		else 
			$timestamp="<span style=\"background-color:c3c3c3;\" title=\"[Line: ".$errline."|File: ".basename($errfile)."|Mem: ".backwpup_formatBytes(@memory_get_usage(true))."|Mem Max: ".backwpup_formatBytes(@memory_get_peak_usage(true))."|Mem Limit: ".ini_get('memory_limit')."]\">".date_i18n('Y-m-d H:i.s').":</span> ";
	}
	
	switch ($errno) {
    case E_NOTICE:
	case E_USER_NOTICE:
		$massage=$timestamp."<span>".$errstr."</span>";
        break;
    case E_WARNING:
    case E_USER_WARNING:
		$logheader=backwpup_read_logheader($backwpup_logfile); //read waring count from log header
		$warnings=$logheader['warnings']+1;
		$massage=$timestamp."<span style=\"background-color:yellow;\">".__('[WARNING]','backwpup')." ".$errstr."</span>";
        break;
    case E_USER_ERROR:
		$logheader=backwpup_read_logheader($backwpup_logfile); //read error count from log header
		$errors=$logheader['errors']+1;
		$massage=$timestamp."<span style=\"background-color:red;\">".__('[ERROR]','backwpup')." ".$errstr."</span>";
        break;
	case E_DEPRECATED:
	case E_USER_DEPRECATED:
		$massage=$timestamp."<span>".__('[DEPRECATED]','backwpup')." ".$errstr."</span>";		
		break;
	case E_STRICT:
		$massage=$timestamp."<span>".__('[STRICT NOTICE]','backwpup')." ".$errstr."</span>";
		break;
	case E_RECOVERABLE_ERROR:
		$massage=$timestamp."<span>".__('[RECOVERABLE ERROR]','backwpup')." ".$errstr."</span>";
		break;
	default:
		$massage=$timestamp."<span>[".$errno."] ".$errstr."</span>";
        break;
    }
	
	if (!empty($massage)) {
		//wirte log file
		$fd=@fopen($backwpup_logfile,"a+");
		@fputs($fd,$massage."<br />\n");
		@fclose($fd);
		
		//output on run now	
		if (!defined('DOING_CRON')) {
			echo $massage."<script type=\"text/javascript\">window.scrollBy(0, 15);</script><br />\n";
			@flush();
			@ob_flush();
		}
		
		//write new log header
		if (isset($errors) or isset($warnings)) {
			$fd=@fopen($backwpup_logfile,"r+");
			while (!feof($fd)) {
				$line=@fgets($fd);
				if (stripos($line,"<meta name=\"backwpup_errors\"") !== false and isset($errors)) {
					@fseek($fd,$filepos);
					@fputs($fd,str_pad("<meta name=\"backwpup_errors\" content=\"".$errors."\" />",100)."\n");
					break;
				}
				if (stripos($line,"<meta name=\"backwpup_warnings\"") !== false and isset($warnings)) {
					@fseek($fd,$filepos);
					@fputs($fd,str_pad("<meta name=\"backwpup_warnings\" content=\"".$warnings."\" />",100)."\n");
					break;
				}
				$filepos=ftell($fd);
			}
			@fclose($fd);
		}
			
		if ($errno==E_ERROR or $errno==E_CORE_ERROR or $errno==E_COMPILE_ERROR) //Die on fatal php errors.
			die();
		

		@set_time_limit(300); //300 is most webserver time limit. 0= max time! Give script 5 min. more to work.
		//true for no more php error hadling.
		return true;
	} else {
		return false;
	}
}

//Job shutdown function for abort
function backwpup_jobshutdown() {
	global $backwpup_logfile;
		$logheader=backwpup_read_logheader($backwpup_logfile); //read waring count from log header
		$cfg=get_option('backwpup'); //load config
		$jobs=get_option('backwpup_jobs'); //load job options
		
		if (!empty($jobs[$logheader['jobid']]['stoptime'])) //abort if job exits normaly
			return;
			
		backwpup_joberrorhandler(E_USER_WARNING, __('Job shutdown function is working! Please delete temp Backupfiles by hand.','backwpup'), __FILE__, __LINE__);
		
		//try to get last error
		$lasterror=error_get_last();
		backwpup_joberrorhandler($lasterror['type'], __('Last ERROR:','backwpup').' '.$lasterror['message'], $lasterror['file'], $lasterror['line']);
		
		//set Temp Dir
		$tempdir=trailingslashit($cfg['dirtemp']);
		if ($tempdir=='/') 
			$tempdir=str_replace('\\','/',trailingslashit(WP_CONTENT_DIR)).'uploads/';
		
		if (is_file($tempdir.DB_NAME.'.sql') ) { //delete sql temp file
			unlink($tempdir.DB_NAME.'.sql');
		}
		
		if (is_file($tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml') ) { //delete WP XML Export temp file
			unlink($tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml');
		}
			
		$jobs[$logheader['jobid']]['stoptime']=current_time('timestamp');
		$jobs[$logheader['jobid']]['lastrun']=$jobs[$logheader['jobid']]['starttime'];
		$jobs[$logheader['jobid']]['lastruntime']=$jobs[$logheader['jobid']]['stoptime']-$jobs[$logheader['jobid']]['starttime'];
		update_option('backwpup_jobs',$jobs); //Save Settings

		//write runtime header
		$fd=@fopen($backwpup_logfile,"r+");
		while (!feof($fd)) {
			$line=@fgets($fd);
			if (stripos($line,"<meta name=\"backwpup_jobruntime\"") !== false) {
				@fseek($fd,$filepos);
				@fputs($fd,str_pad("<meta name=\"backwpup_jobruntime\" content=\"".$jobs[$logheader['jobid']]['lastruntime']."\" />",100)."\n");
				break;
			}
			$filepos=ftell($fd);
		}
		@fclose($fd);
		//logfile end
		$fd=fopen($backwpup_logfile,"a+");
		fputs($fd,"</body>\n</html>\n");
		fclose($fd);
		restore_error_handler();
		$logdata=backwpup_read_logheader($backwpup_logfile);
		//Send mail with log
		$sendmail=false;
		if ($logdata['errors']>0 and $jobs[$logheader['jobid']]['mailerroronly'] and !empty($jobs[$logheader['jobid']]['mailaddresslog']))
			$sendmail=true;
		if (!$jobs[$logheader['jobid']]['mailerroronly'] and !empty($jobs[$logheader['jobid']]['mailaddresslog']))
			$sendmail=true;
		if ($sendmail) {
			$mailbody=__("Jobname:","backwpup")." ".$logdata['name']."\n";
			$mailbody.=__("Jobtype:","backwpup")." ".$logdata['type']."\n";
			if (!empty($logdata['errors'])) 
				$mailbody.=__("Errors:","backwpup")." ".$logdata['errors']."\n";
			if (!empty($logdata['warnings']))
				$mailbody.=__("Warnings:","backwpup")." ".$logdata['warnings']."\n";
			wp_mail($jobs[$logheader['jobid']]['mailaddresslog'],__('BackWPup Log File from','backwpup').' '.date_i18n('Y-m-d H:i',$jobs[$logheader['jobid']]['starttime']).': '.$jobs[$logheader['jobid']]['name'] ,$mailbody,'',array($backwpup_logfile));
		}	
}

/**
* BackWPup PHP class for WordPress
*
*/
class backwpup_dojob {
   
	private $jobid=0;
	private $filelist=array();
	private $todo=array();
	private $allfilesize=0;
	private $backupfile='';
	private $backupfileformat='.zip';
	private $backupdir='';
	private $logdir='';
	private $logfile='';
	private $tempdir='';
	private $cfg=array();
	private $job=array();
	
	public function __construct($jobid) {
		global $backwpup_logfile,$backwpup_dojob;
		@ini_get('safe_mode','Off'); //disable safe mode
		//Set no user abort
		@ini_set('ignore_user_abort','Off'); //Set PHP ini setting
		ignore_user_abort(true);			//user can't abort script (close windows or so.)
		$this->jobid=$jobid;			   //set job id
		$this->cfg=get_option('backwpup'); //load config
		$jobs=get_option('backwpup_jobs'); //load jobdata
		$jobs[$this->jobid]['starttime']=current_time('timestamp'); //set start time for job
		$jobs[$this->jobid]['stoptime']='';	   //Set stop time for job
		if ($schedteime=wp_next_scheduled('backwpup_cron',array('jobid'=>$this->jobid))) //set Schedule time to next scheduled
			$jobs[$this->jobid]['scheduletime']=$schedteime;
		update_option('backwpup_jobs',$jobs); //Save job Settings
		$this->job=backwpup_check_job_vars($jobs[$this->jobid]);//Set and check job settings
		//set waht to do
		$this->todo=explode('+',$this->job['type']);
		//set Backup File format Dir
		$this->backupfileformat=$this->job['fileformart'];
		//set Temp Dir
		$this->tempdir=trailingslashit($this->cfg['dirtemp']);
		if (empty($this->tempdir) or $this->tempdir=='/') 
			$this->tempdir=str_replace('\\','/',trailingslashit(WP_CONTENT_DIR)).'uploads/';
		//set Backup Dir
		$this->backupdir=$this->job['backupdir'];
		if (empty($this->backupdir))
			$this->backupdir=$this->tempdir;
		//set Logs Dir
		$this->logdir=trailingslashit($this->cfg['dirlogs']);
		if (empty($this->logdir) or $this->logdir=='/') {
			$rand = substr( md5( md5( SECURE_AUTH_KEY ) ), -5 );
			$this->logdir=str_replace('\\','/',trailingslashit(WP_CONTENT_DIR)).'backwpup-'.$rand.'-logs/';
		}
		//set Backup file name only for jos that makes backups
		if (in_array('FILE',$this->todo) or in_array('DB',$this->todo) or in_array('WPEXP',$this->todo)) 
			$this->backupfile='backwpup_'.$this->jobid.'_'.date_i18n('Y-m-d_H-i-s').$this->backupfileformat;
		//set Log file name
		$this->logfile='backwpup_log_'.date_i18n('Y-m-d_H-i-s').'.html'; 
		$backwpup_logfile=$this->logdir.$this->logfile;
		//Create log file
		if (!$this->_check_folders($this->logdir))
			return false;
		$fd=@fopen($backwpup_logfile,"a+");
		@fputs($fd,"<html>\n<head>\n");
		@fputs($fd,"<meta name=\"backwpup_version\" content=\"".BACKWPUP_VERSION."\" />\n");
		@fputs($fd,"<meta name=\"backwpup_logtime\" content=\"".current_time('timestamp')."\" />\n");
		@fputs($fd,str_pad("<meta name=\"backwpup_errors\" content=\"0\" />",100)."\n");
		@fputs($fd,str_pad("<meta name=\"backwpup_warnings\" content=\"0\" />",100)."\n");
		@fputs($fd,"<meta name=\"backwpup_jobid\" content=\"".$this->jobid."\" />\n");
		@fputs($fd,"<meta name=\"backwpup_jobname\" content=\"".$this->job['name']."\" />\n");
		@fputs($fd,"<meta name=\"backwpup_jobtype\" content=\"".$this->job['type']."\" />\n");
		if (!empty($this->backupfile) and $this->backupdir!=$this->tempdir)
			@fputs($fd,"<meta name=\"backwpup_backupfile\" content=\"".$this->backupdir.$this->backupfile."\" />\n");
		@fputs($fd,str_pad("<meta name=\"backwpup_jobruntime\" content=\"0\" />",100)."\n");
		@fputs($fd,"<title>".sprintf(__('BackWPup Log for %1$s from %2$s at %3$s','backwpup'),$this->job['name'],date_i18n(get_option('date_format')),date_i18n(get_option('time_format')))."</title>\n</head>\n<body style=\"font-family:monospace;font-size:12px;white-space:nowrap;\">\n");
		@fclose($fd);
		//set function for PHP user defineid error handling
		if (defined(WP_DEBUG) and WP_DEBUG)
			set_error_handler('backwpup_joberrorhandler',E_ALL | E_STRICT);
		else 
			set_error_handler('backwpup_joberrorhandler',E_ALL & ~E_NOTICE);
		//set a schutdown function.
		register_shutdown_function('backwpup_jobshutdown');
		//check dirs
		if ($this->backupdir!=str_replace('\\','/',trailingslashit(WP_CONTENT_DIR)).'uploads/') {
			if (!$this->_check_folders($this->backupdir))
				return false;
		}
		//check max script execution tme
		if (ini_get('safe_mode') or strtolower(ini_get('safe_mode'))=='on' or ini_get('safe_mode')=='1') 
			trigger_error(sprintf(__('PHP Safe Mode is on!!! Max exec time is %1$d sec.','backwpup'),ini_get('max_execution_time')),E_USER_WARNING);
		// check function for memorylimit
		if (!function_exists('memory_get_usage')) {
			ini_set('memory_limit', apply_filters( 'admin_memory_limit', '256M' )); //Wordpress default
			trigger_error(sprintf(__('Memory limit set to %1$s ,because can not use PHP: memory_get_usage() function to dynamicly increase the Memeory!','backwpup'),ini_get('memory_limit')),E_USER_WARNING);
		}
		//run job parts
		foreach($this->todo as $key => $value) {
			switch ($value) {
			case 'DB':
				$this->dump_db();
				break;
			case 'WPEXP':
				$this->export_wp();
				break;
			case 'FILE':
				$this->file_list();
				break;
			}
		}

		if (isset($this->filelist[0][79001])) { // Make backup file
			if ($this->backupfileformat==".zip")
				$this->zip_files();
			elseif ($this->backupfileformat==".tar.gz" or $this->backupfileformat==".tar.bz2" or $this->backupfileformat==".tar")
				$this->tar_pack_files();
		}
		if (file_exists($this->backupdir.$this->backupfile)) {  // Put backup file to destination
			$this->destination_mail();
			$this->destination_ftp();
			$this->destination_s3();
			$this->destination_dir();
		}
		
		foreach($this->todo as $key => $value) {
			switch ($value) {
			case 'CHECK':
				$this->check_db();
				break;
			case 'OPTIMIZE':
				$this->optimize_db();
				break;
			}
		}	
	}	
	
	private function _check_folders($folder) {
		if (!is_dir($folder)) { //create dir if not exists
			if (!mkdir($folder,0777,true)) {
				trigger_error(sprintf(__('Can not create Folder: %1$s','backwpup'),$folder),E_USER_ERROR);
				return false;
			}
		}
		if (!is_writeable($folder)) { //test if folder wirteable
			trigger_error(sprintf(__('Can not write to Folder: %1$s','backwpup'),$folder),E_USER_ERROR);	
			return false;	
		}
		//create .htaccess for apache and index.html for other
		if (strtolower(substr($_SERVER["SERVER_SOFTWARE"],0,6))=="apache") {  //check if it a apache webserver
			if (!is_file($folder.'.htaccess')) {
				if($file = fopen($folder.'.htaccess', 'w')) {
					fwrite($file, "Order allow,deny\ndeny from all");
					fclose($file);
				}
			}
		} else {
			if (!is_file($folder.'index.html')) {
				if($file = fopen($folder.'index.html', 'w')) {
					fwrite($file,"\n");
					fclose($file);
				} 
			}
			if (!is_file($folder.'index.php')) {
				if($file = fopen($folder.'index.php', 'w')) {
					fwrite($file,"\n");
					fclose($file);
				} 
			}
		}
		return true;
	}
	
	private function need_free_memory($memneed) {
		//fail back if fuction not exist
		if (!function_exists('memory_get_usage'))
			return true;

		if (ini_get('safe_mode') or strtolower(ini_get('safe_mode'))=='on' or ini_get('safe_mode')=='1') {
			trigger_error(sprintf(__('PHP Safe Mode is on!!! Can not increase Memory Limit is %1$s','backwpup'),ini_get('memory_limit')),E_USER_WARNING);
			return false;
		}
			
		//calc mem to bytes
		if (strtoupper(substr(trim(ini_get('memory_limit')),-1))=='K')
			$memory=trim(substr(ini_get('memory_limit'),0,-1))*1024;
		elseif (strtoupper(substr(trim(ini_get('memory_limit')),-1))=='M')
			$memory=trim(substr(ini_get('memory_limit'),0,-1))*1024*1024;
		elseif (strtoupper(substr(trim(ini_get('memory_limit')),-1))=='G')
			$memory=trim(substr(ini_get('memory_limit'),0,-1))*1024*1024*1024;
		else
			$memory=trim(ini_get('memory_limit'));
		
		//use real memory at php version 5.2.0
		if (version_compare(phpversion(), '5.2.0', '<'))
			$memnow=memory_get_usage();
		else 
			$memnow=memory_get_usage(true);
		
		//need memory
		$needmemory=$memnow+$memneed;
		
		// increase Memory	
		if ($needmemory>$memory) { 
			$newmemory=round($needmemory/1024/1024)+1;
			if ($oldmem=ini_set('memory_limit', $newmemory.'M')) 
				trigger_error(sprintf(__('Memory increased from %1$s to %2$s','backwpup'),$oldmem,ini_get('memory_limit')),E_USER_NOTICE);
			else 
				trigger_error(sprintf(__('Can not increase Memory Limit is %1$s','backwpup'),ini_get('memory_limit')),E_USER_WARNING);
		}
		return true;	
	}
	
	private function maintenance_mode($enable = false) {
		if (!$this->job['maintenance'])
			return;
			
		if ( $enable ) {
			trigger_error(__('Set Blog to Maintenance Mode','backwpup'),E_USER_NOTICE);
			if ( class_exists('WPMaintenanceMode') ) { //Support for WP Maintenance Mode Plugin
				update_option('wp-maintenance-mode-msqld','1');			
			} elseif ( class_exists('MaintenanceMode') ) { //Support for Maintenance Mode Plugin
				$mamo=get_option('plugin_maintenance-mode');
				$mamo['mamo_activate']='on_'.current_time('timestamp');
				$mamo['mamo_backtime_days']='0';
				$mamo['mamo_backtime_hours']='0';
				$mamo['mamo_backtime_mins']='5';
				update_option('plugin_maintenance-mode',$mamo);				
			} else { //WP Support
				$fdmain=fopen(trailingslashit(ABSPATH).'.maintenance','w');
				fputs($fdmain,'<?php $upgrading = ' . time() . '; ?>');
				fclose($fdmain);			
			}
		} else {
			trigger_error(__('Set Blog to normal Mode','backwpup'),E_USER_NOTICE);
			if ( class_exists('WPMaintenanceMode') ) { //Support for WP Maintenance Mode Plugin
				update_option('wp-maintenance-mode-msqld','0');	
			} elseif ( class_exists('MaintenanceMode') ) { //Support for Maintenance Mode Plugin
				$mamo=get_option('plugin_maintenance-mode');
				$mamo['mamo_activate']='off';
				update_option('plugin_maintenance-mode',$mamo);					
			} else { //WP Support
				@unlink(trailingslashit(ABSPATH).'.maintenance');
			}
		}
	}
	
	private function check_db() {
		global $wpdb;
		
		trigger_error(__('Run Database check...','backwpup'),E_USER_NOTICE);
		
		$tables=$wpdb->get_col('SHOW TABLES FROM `'.DB_NAME.'`');
		//exclude tables from check
		foreach($tables as $tablekey => $tablevalue) {
			if (in_array($tablevalue,$this->job['dbexclude']))
				unset($tables[$tablekey]);
		}
	
		//check tables
		if (sizeof($tables)>0) {
			$this->maintenance_mode(true);
			foreach ($tables as $table) {
				$check=$wpdb->get_row('CHECK TABLE `'.$table.'` MEDIUM', ARRAY_A);
				if ($check['Msg_type']=='error')
					trigger_error(sprintf(__('Result of table check for %1$s is: %2$s','backwpup'), $table, $check['Msg_text']),E_USER_ERROR);
				elseif ($check['Msg_type']=='warning')
					trigger_error(sprintf(__('Result of table check for %1$s is: %2$s','backwpup'), $table, $check['Msg_text']),E_USER_WARNING);
				else
					trigger_error(sprintf(__('Result of table check for %1$s is: %2$s','backwpup'), $table, $check['Msg_text']),E_USER_NOTICE);						
					
				if ($sqlerr=mysql_error($wpdb->dbh)) //aditional SQL error
					trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), $sqlerr, $sqlerr->last_query),E_USER_ERROR);
				//Try to Repair tabele
				if ($check['Msg_type']=='error' or $check['Msg_type']=='warning') {
					$repair=$wpdb->get_row('REPAIR TABLE `'.$table.'`', ARRAY_A);
					if ($repair['Msg_type']=='error')
						trigger_error(sprintf(__('Result of table repair for %1$s is: %2$s','backwpup'), $table, $repair['Msg_text']),E_USER_ERROR);
					elseif ($repair['Msg_type']=='warning')
						trigger_error(sprintf(__('Result of table repair for %1$s is: %2$s','backwpup'), $table, $repair['Msg_text']),E_USER_WARNING);
					else
						trigger_error(sprintf(__('Result of table repair for %1$s is: %2$s','backwpup'), $table, $repair['Msg_text']),E_USER_NOTICE);						
			
					if ($sqlerr=mysql_error($wpdb->dbh)) //aditional SQL error
						trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), $sqlerr, $sqlerr->last_query),E_USER_ERROR);
				}
			}
			$wpdb->flush();
			$this->maintenance_mode(false);
			trigger_error(__('Database check done!','backwpup'),E_USER_NOTICE);
		} else {
			trigger_error(__('No Tables to check','backwpup'),E_USER_WARNING);
		}	
	}

	private function dump_db_table($table,$status,$file) {

		// create dump
		fwrite($file, "\n");
		fwrite($file, "--\n");
		fwrite($file, "-- Table structure for table $table\n");
		fwrite($file, "--\n\n");
		fwrite($file, "DROP TABLE IF EXISTS `" . $table .  "`;\n");            
		fwrite($file, "/*!40101 SET @saved_cs_client     = @@character_set_client */;\n");
		fwrite($file, "/*!40101 SET character_set_client = '".mysql_client_encoding()."' */;\n");
		//Dump the table structure
		$result=mysql_query("SHOW CREATE TABLE `".$table."`");
		if (!$result) {
			trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), mysql_error(), "SHOW CREATE TABLE `".$table."`"),E_USER_ERROR);
			return false;
		}
		$tablestruc=mysql_fetch_assoc($result);
		fwrite($file, $tablestruc['Create Table'].";\n");
		fwrite($file, "/*!40101 SET character_set_client = @saved_cs_client */;\n");
	
		//take data of table
		$result=mysql_query("SELECT * FROM `".$table."`");
		if (!$result) {
			trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), mysql_error(), "SELECT * FROM `".$table."`"),E_USER_ERROR);
			return false;
		}
	
		fwrite($file, "--\n");
		fwrite($file, "-- Dumping data for table $table\n");
		fwrite($file, "--\n\n");
		fwrite($file, "LOCK TABLES `".$table."` WRITE;\n\n");
		if ($status['Engine']=='MyISAM')
			fwrite($file, "/*!40000 ALTER TABLE `".$table."` DISABLE KEYS */;\n");

		
		while ($data = mysql_fetch_assoc($result)) {
			$keys = array();
			$values = array();
			foreach($data as $key => $value) {
				if (!$this->job['dbshortinsert'])
					$keys[] = "`".str_replace("�", "��", $key)."`"; // Add key to key list
				if($value === NULL) // Make Value NULL to string NULL
					$value = "NULL";
				elseif($value === "" or $value === false) // if empty or false Value make  "" as Value
					$value = "''";
				elseif(!is_numeric($value)) //is value not numeric esc
					$value = "'".mysql_real_escape_string($value)."'";
				$values[] = $value;
			}
			// make data dump
			if ($this->job['dbshortinsert'])
				fwrite($file, "INSERT INTO `".$table."` VALUES ( ".implode(", ",$values)." );\n");
			else
				fwrite($file, "INSERT INTO `".$table."` ( ".implode(", ",$keys)." )\n\tVALUES ( ".implode(", ",$values)." );\n");

		}
		if ($status['Engine']=='MyISAM')
			fwrite($file, "/*!40000 ALTER TABLE ".$table." ENABLE KEYS */;\n");
		fwrite($file, "UNLOCK TABLES;\n");
	}

	private function dump_db() {
		global $wpdb;
		trigger_error(__('Run Database Dump to file...','backwpup'),E_USER_NOTICE);
		$this->maintenance_mode(true);
		
		//Tables to backup		
		$tables=$wpdb->get_col('SHOW TABLES FROM `'.DB_NAME.'`');
		if ($sqlerr=mysql_error($wpdb->dbh)) 
			trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), $sqlerr, "SHOW TABLES FROM `'.DB_NAME.'`"),E_USER_ERROR);
		
		foreach($tables as $tablekey => $tablevalue) {
			if (in_array($tablevalue,$this->job['dbexclude']))
				unset($tables[$tablekey]);
		}
		sort($tables);
		

		if (sizeof($tables)>0) {
			$result=$wpdb->get_results("SHOW TABLE STATUS FROM `".DB_NAME."`;", ARRAY_A); //get table status
			if ($sqlerr=mysql_error($wpdb->dbh)) 
				trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), $sqlerr, "SHOW TABLE STATUS FROM `".DB_NAME."`;"),E_USER_ERROR);
			foreach($result as $statusdata) {
				$status[$statusdata['Name']]=$statusdata;
			}

			if ($file = @fopen($this->tempdir.DB_NAME.'.sql', 'w')) {
				fwrite($file, "-- ---------------------------------------------------------\n");
				fwrite($file, "-- Dump with BackWPup ver.: ".BACKWPUP_VERSION."\n");
				fwrite($file, "-- Plugin for WordPress by Daniel Huesken\n");
				fwrite($file, "-- http://danielhuesken.de/portfolio/backwpup/\n");
				fwrite($file, "-- Blog Name: ".get_option('blogname')."\n");
				if (defined('WP_SITEURL')) 
					fwrite($file, "-- Blog URL: ".trailingslashit(WP_SITEURL)."\n");
				else 
					fwrite($file, "-- Blog URL: ".trailingslashit(get_option('siteurl'))."\n");
				fwrite($file, "-- Blog ABSPATH: ".trailingslashit(ABSPATH)."\n");
				fwrite($file, "-- Table Prefix: ".$wpdb->prefix."\n");
				fwrite($file, "-- Database Name: ".DB_NAME."\n");
				fwrite($file, "-- Dump on: ".date_i18n('Y-m-d H:i.s')."\n");
				fwrite($file, "-- ---------------------------------------------------------\n\n");
				//for better import with mysql client
				fwrite($file, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
				fwrite($file, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
				fwrite($file, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
				fwrite($file, "/*!40101 SET NAMES '".mysql_client_encoding()."' */;\n");
				fwrite($file, "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n");
				fwrite($file, "/*!40103 SET TIME_ZONE='".mysql_result(mysql_query("SELECT @@time_zone"),0)."' */;\n");
				fwrite($file, "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n");
				fwrite($file, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n");
				fwrite($file, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n");
				fwrite($file, "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n");
				//make table dumps
				foreach($tables as $table) {
					trigger_error(__('Dump Database table: ','backwpup').' '.$table,E_USER_NOTICE);
					$this->need_free_memory(($status[$table]['Data_length']+$status[$table]['Index_length'])*1.3); //get more memory if needed
					fwrite($file, $this->dump_db_table($table,$status[$table],$file));
				}
				//for better import with mysql client
				fwrite($file, "\n");
				fwrite($file, "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n");
				fwrite($file, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
				fwrite($file, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
				fwrite($file, "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n");
				fwrite($file, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
				fwrite($file, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
				fwrite($file, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
				fwrite($file, "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n");
				fclose($file);
			} else {
				trigger_error(__('Can not create Database Dump file','backwpup'),E_USER_ERROR);
			}
		} else {
			trigger_error(__('No Tables to Dump','backwpup'),E_USER_WARNING);
		}

		trigger_error(__('Database Dump done!','backwpup'),E_USER_NOTICE);
		//add database file to backupfiles
		trigger_error(__('Add Database Dump to Backup:','backwpup').' '.DB_NAME.'.sql '.backwpup_formatBytes(filesize($this->tempdir.DB_NAME.'.sql')),E_USER_NOTICE);
		$this->allfilesize+=filesize($this->tempdir.DB_NAME.'.sql');
		$this->filelist[]=array(79001=>$this->tempdir.DB_NAME.'.sql',79003=>DB_NAME.'.sql');
		
		$this->maintenance_mode(false);
	}	

	private function export_wp() {
		trigger_error(__('Run Wordpress Export to XML file...','backwpup'),E_USER_NOTICE);
		if (copy(plugins_url('wp_xml_export.php',__FILE__).'?wpabs='.trailingslashit(ABSPATH).'&_nonce='.substr(md5(md5(SECURE_AUTH_KEY)),10,10),$this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml')) {
			trigger_error(__('Export to XML done!','backwpup'),E_USER_NOTICE);
			//add database file to backupfiles
			trigger_error(__('Add XML Export to Backup:','backwpup').' wordpress.' . date( 'Y-m-d' ) . '.xml '.backwpup_formatBytes(filesize($this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml')),E_USER_NOTICE);
			$this->allfilesize+=filesize($this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml');
			$this->filelist[]=array(79001=>$this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml',79003=>'wordpress.' . date( 'Y-m-d' ) . '.xml');
		} else {
			trigger_error(__('Can not Export to XML!','backwpup'),E_USER_ERROR);
		}
	}	

	private function optimize_db() {
		global $wpdb;
		
		trigger_error(__('Run Database optimize...','backwpup'),E_USER_NOTICE);
		
		$tables=$wpdb->get_col('SHOW TABLES FROM `'.DB_NAME.'`');
		//exclude tables from optimize
		foreach($tables as $tablekey => $tablevalue) {
			if (in_array($tablevalue,$this->job['dbexclude']))
				unset($tables[$tablekey]);
		}
			
		if (sizeof($tables)>0) {
			$this->maintenance_mode(true);
			foreach ($tables as $table) {
				$optimize=$wpdb->get_row('OPTIMIZE TABLE `'.$table.'`', ARRAY_A);
				if ($optimize['Msg_type']=='error')
					trigger_error(sprintf(__('Result of table optimize for %1$s is: %2$s','backwpup'), $table, $optimize['Msg_text']),E_USER_ERROR);
				elseif ($optimize['Msg_type']=='warning')
					trigger_error(sprintf(__('Result of table optimize for %1$s is: %2$s','backwpup'), $table, $optimize['Msg_text']),E_USER_WARNING);
				else
					trigger_error(sprintf(__('Result of table optimize for %1$s is: %2$s','backwpup'), $table, $optimize['Msg_text']),E_USER_NOTICE);						
					
				if ($sqlerr=mysql_error($wpdb->dbh)) 
					trigger_error(sprintf(__('BackWPup database error %1$s for query %2$s','backwpup'), $sqlerr, $sqlerr->last_query),E_USER_ERROR);
			}
			$wpdb->flush();
			trigger_error(__('Database optimize done!','backwpup'),E_USER_NOTICE);
			$this->maintenance_mode(false);
		} else {
			trigger_error(__('No Tables to optimize','backwpup'),E_USER_WARNING);
		}	
	}

	private function _file_list_folder( $folder = '', $levels = 100, $excludes) {
		if( empty($folder) )
			return false;
		if( ! $levels )
			return false;
		if ( $dir = @opendir( $folder ) ) {
			while (($file = readdir( $dir ) ) !== false ) {
				if ( in_array($file, array('.', '..','.svn') ) )
					continue;
				foreach ($excludes as $exclusion) { //exclude dirs and files
					if (false !== stripos($folder.'/'.$file,$exclusion) and !empty($exclusion) and $exclusion!='/')
						continue 2;
				}
				if (!$this->job['backuproot'] and false !== stripos($folder.'/'.$file,str_replace('\\','/',trailingslashit(ABSPATH))) and false === stripos($folder.'/'.$file,str_replace('\\','/',WP_CONTENT_DIR)) and !is_dir($folder.'/'.$file))
					continue;
				if (!$this->job['backupcontent'] and false !== stripos($folder.'/'.$file,str_replace('\\','/',WP_CONTENT_DIR)) and false === stripos($folder.'/'.$file,str_replace('\\','/',WP_PLUGIN_DIR)) and !is_dir($folder.'/'.$file))
					continue;
				if (!$this->job['backupplugins'] and false !== stripos($folder.'/'.$file,str_replace('\\','/',WP_PLUGIN_DIR)))
					continue;
				if ( is_dir( $folder . '/' . $file ) ) {
					$this->_file_list_folder( $folder . '/' . $file, $levels - 1, $excludes);
				} elseif (is_file( $folder . '/' . $file )) {
					if (is_readable($folder . '/' . $file)) { //add file to filelist
						$this->filelist[]=array(79001=>$folder.'/' .$file,79003=>str_replace(str_replace('\\','/',trailingslashit(ABSPATH)),'',$folder.'/') . $file);
						$this->allfilesize=$this->allfilesize+filesize($folder . '/' . $file);
					} else {
						trigger_error(__('Can not read file:','backwpup').' '.$folder . '/' . $file,E_USER_WARNING);
					}
				} else {
					trigger_error(__('Is not a file or directory:','backwpup').' '.$folder . '/' . $file,E_USER_WARNING);
				}
			}
			@closedir( $dir );
		}	
	}
	
	private function file_list() {

		//Make filelist
		trigger_error(__('Make a list of files to Backup ....','backwpup'),E_USER_NOTICE);

		$backwpup_exclude=explode(',',trim($this->job['fileexclude']));
		//Exclude Temp Files
		$backwpup_exclude[]=$this->tempdir.DB_NAME.'.sql';
		$backwpup_exclude[]=$this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml';
		//Exclude Backup dirs
		$jobs=get_option('backwpup_jobs');
		foreach($jobs as $jobsvale) { 
			if (!empty($jobsvale['backupdir']) and $jobsvale['backupdir']!='/')
				$backwpup_exclude[]=$jobsvale['backupdir'];
		}
		$backwpup_exclude=array_unique($backwpup_exclude);
	
		//include dirs 
		$dirinclude=explode(',',$this->job['dirinclude']);
		
		if ($this->job['backuproot']) //Include extra path
			$dirinclude[]=ABSPATH;
		if ($this->job['backupcontent'] and ((strtolower(str_replace('\\','/',substr(WP_CONTENT_DIR,0,strlen(ABSPATH))))!=strtolower(str_replace('\\','/',ABSPATH)) and $this->job['backuproot']) or !$this->job['backuproot']))
			$dirinclude[]=WP_CONTENT_DIR;
		if ($this->job['backupplugins'] and ((strtolower(str_replace('\\','/',substr(WP_PLUGIN_DIR,0,strlen(ABSPATH))))!=strtolower(str_replace('\\','/',ABSPATH)) and $this->job['backuproot']) or !$this->job['backuproot']) and  ((strtolower(str_replace('\\','/',substr(WP_PLUGIN_DIR,0,strlen(WP_CONTENT_DIR))))!=strtolower(str_replace('\\','/',WP_CONTENT_DIR)) and $this->job['backupcontent']) or !$this->job['backupcontent']))
			$dirinclude[]=WP_PLUGIN_DIR;	
		$dirinclude=array_unique($dirinclude);
		//Crate file list
		if (is_array($dirinclude)) {
			foreach($dirinclude as $dirincludevalue) {
				if (is_dir($dirincludevalue)) 
					$this->_file_list_folder(untrailingslashit(str_replace('\\','/',$dirincludevalue)),100,$backwpup_exclude);
			}
		}

		if (!is_array($this->filelist[0])) {
			trigger_error(__('No files to Backup','backwpup'),E_USER_ERROR);
		} else {
			trigger_error(__('Size off all files:','backwpup').' '.backwpup_formatBytes($this->allfilesize),E_USER_NOTICE);
		}
	
	}	

	private function zip_files() {
		
		if (class_exists('ZipArchive')) {  //use php zip lib
			trigger_error(__('Create Backup Zip file...','backwpup'),E_USER_NOTICE);
			$zip = new ZipArchive;
			if ($res=$zip->open($this->backupdir.$this->backupfile,ZIPARCHIVE::CREATE) === TRUE) {
				foreach($this->filelist as $key => $files) {
					if ($zip->addFile($files[79001], $files[79003])) {
						trigger_error(__('Add File to ZIP file:','backwpup').' '.$files[79001].' '.backwpup_formatBytes(filesize($files[79001])),E_USER_NOTICE);
					} else {
						trigger_error(__('Can not add File to ZIP file:','backwpup').' '.$files[79001],E_USER_ERROR);
					}
				}
				$zip->close();
				trigger_error(__('Backup Zip file create done!','backwpup'),E_USER_NOTICE);
			} else {
				trigger_error(__('Can not create Backup ZIP file:','backwpup').' '.$res,E_USER_ERROR);
			}
		
		} else { //use PclZip
			define( 'PCLZIP_TEMPORARY_DIR', $this->tempdir );
			if (!class_exists('PclZip')) 
				require_once (plugin_dir_path(__FILE__).'libs/pclzip.lib.php');
		
			//Create Zip File
			if (is_array($this->filelist[0])) {
				$this->need_free_memory(10485760); //10MB free memory for zip
				trigger_error(__('Create Backup Zip (PclZip) file...','backwpup'),E_USER_NOTICE);
				foreach($this->filelist as $key => $files) {
					trigger_error(__('Add File to ZIP file:','backwpup').' '.$files[79001].' '.backwpup_formatBytes(filesize($files[79001])),E_USER_NOTICE);
				}	
				$zipbackupfile = new PclZip($this->backupdir.$this->backupfile);
				if (0==$zipbackupfile -> create($this->filelist,PCLZIP_OPT_ADD_TEMP_FILE_ON)) {
					trigger_error(__('Zip file create:','backwpup').' '.$zipbackupfile->errorInfo(true),E_USER_ERROR);
				} else {				
					trigger_error(__('Backup Zip file create done!','backwpup'),E_USER_NOTICE);
				}
			}
		}
	}
	
	private function tar_pack_files() {
		
		if ($this->backupfileformat=='.tar.gz') {
			$tarbackup=gzopen($this->backupdir.$this->backupfile,'w9');
		} elseif ($this->backupfileformat=='.tar.bz2') {
			$tarbackup=bzopen($this->backupdir.$this->backupfile,'w');
		} else {
			$tarbackup=fopen($this->backupdir.$this->backupfile,'w');
		}
		
		if (!$tarbackup) {
			trigger_error(__('Can not create TAR Backup file','backwpup'),E_USER_ERROR);
			return;
		} else {
			trigger_error(__('Create Backup Archive file...','backwpup'),E_USER_NOTICE);
		}
		
		$this->need_free_memory(1048576); //1MB free memory for zip
		
		foreach($this->filelist as $key => $files) {
				trigger_error(__('Add File to Backup Archive:','backwpup').' '.$files[79001].' '.backwpup_formatBytes(filesize($files[79001])),E_USER_NOTICE);
				// Get file information
				$file_information = stat($files[79001]);

				// Generate the TAR header for this file
				$header = pack("a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12",
						  substr($files[79003],0,100),  				//name of file  100
						  sprintf("%07o", $file_information['mode']), 	//file mode  8
						  sprintf("%07o", $file_information['uid']),	//owner user ID  8
						  sprintf("%07o", $file_information['gid']),	//owner group ID  8
						  sprintf("%011o", $file_information['size']),	//length of file in bytes  12
						  sprintf("%011o", $file_information['mtime']),	//modify time of file  12
						  "        ",									//checksum for header  8
						  0,											//type of file  0 or null = File, 5=Dir
						  "",											//name of linked file  100
						  "ustar ",										//USTAR indicator  6
						  " ",											//USTAR version  2
						  "Unknown",									//owner user name 32
						  "Unknown",									//owner group name 32
						  "",											//device major number 8
						  "",											//device minor number 8
						  substr($files[79003],101),					//prefix for file name 155
						  "");											//fill block 512K
							
				// Computes the unsigned Checksum of a file's header
				$checksum = 0;
				for ($i = 0; $i < 512; $i++)
					$checksum += ord(substr($header, $i, 1));
				$checksum = pack("a8", sprintf("%07o", $checksum));
				
				$header = substr_replace($header, $checksum, 148, 8);
				
				if ($this->backupfileformat=='.tar.gz') {
					gzwrite($tarbackup, $header);
				} elseif ($this->backupfileformat=='.tar.bz2') {
					bzwrite($tarbackup, $header);
				} else {
					fwrite($tarbackup, $header);
				}
				
				// read/write files in 512K Blocks
				$fd=fopen($files[79001],'rb');
				while(!feof($fd)) {
					$filedata=fread($fd,512);
					if (strlen($filedata)>0) {
						if ($this->backupfileformat=='.tar.gz') {
							gzwrite($tarbackup,pack("a512", $filedata));
						} elseif ($this->backupfileformat=='.tar.bz2') {
							bzwrite($tarbackup,pack("a512", $filedata));
						} else {
							fwrite($tarbackup,pack("a512", $filedata));
						}
					}
				}
				fclose($fd);
			}
		
		
		if ($this->backupfileformat=='.tar.gz') {
			gzwrite($tarbackup, pack("a1024", "")); // Add 1024 bytes of NULLs to designate EOF
			gzclose($tarbackup);	
		} elseif ($this->backupfileformat=='.tar.bz2') {
			bzwrite($tarbackup, pack("a1024", "")); // Add 1024 bytes of NULLs to designate EOF
			bzclose($tarbackup);
		} else {
			fwrite($tarbackup, pack("a1024", "")); // Add 1024 bytes of NULLs to designate EOF
			fclose($tarbackup);	
		}
				
		trigger_error(__('Backup Archive file create done!','backwpup'),E_USER_NOTICE);
	}
	
		
	private function _ftp_raw_helper($ftp_conn_id,$command) { //FTP Comands helper function
		$return=ftp_raw($ftp_conn_id,$command);
		if (strtoupper(substr(trim($command),0,4))=="PASS") {
			trigger_error(__('FTP Client command:','backwpup').' PASS *******',E_USER_NOTICE);
		} else {
			trigger_error(__('FTP Client command:','backwpup').' '.$command,E_USER_NOTICE);
		}
		foreach ($return as $returnline) {
			$code=substr(trim($returnline),0,3);
			if ($code>=100 and $code<200) {
				trigger_error(__('FTP Server Preliminary reply:','backwpup').' '.$returnline,E_USER_NOTICE);
				return true;
			} elseif ($code>=200 and $code<300) {
				trigger_error(__('FTP Server Completion reply:','backwpup').' '.$returnline,E_USER_NOTICE);
				return true;
			} elseif ($code>=300 and $code<400) {
				trigger_error(__('FTP Server Intermediate reply:','backwpup').' '.$returnline,E_USER_NOTICE);
				return true;
			} elseif ($code>=400)  {
				trigger_error(__('FTP Server reply:','backwpup').' '.$returnline,E_USER_ERROR);
				return false;
			} else {
				trigger_error(__('FTP Server reply:','backwpup').' '.$returnline,E_USER_NOTICE);
				return $return;
			}
		}
	}
	
	
	private function destination_ftp() {
	
		if (empty($this->job['ftphost']) or empty($this->job['ftpuser']) or empty($this->job['ftppass'])) 
			return false;
	
		$ftpport=21;
		$ftphost=$this->job['ftphost'];
		if (false !== strpos($this->job['ftphost'],':')) //look for port
			list($ftphost,$ftpport)=explode(':',$this->job['ftphost'],2);

		if (function_exists('ftp_ssl_connect')) { //make SSL FTP connection
			$ftp_conn_id = ftp_ssl_connect($ftphost,$ftpport,10);
			if ($ftp_conn_id) {
				trigger_error(__('Connected by SSL to FTP server:','backwpup').' '.$this->job['ftphost'],E_USER_NOTICE);
			}
		}
		if (!$ftp_conn_id) { //make normal FTP conection if SSL not work
			$ftp_conn_id = ftp_connect($ftphost,$ftpport,10);
			if ($ftp_conn_id) {
				trigger_error(__('Connected insecure to FTP server:','backwpup').' '.$this->job['ftphost'],E_USER_NOTICE);
			}
		}
	
		if (!$ftp_conn_id) {
			trigger_error(__('Can not connect to FTP server:','backwpup').' '.$this->job['ftphost'],E_USER_ERROR);
			return false;
		}

		//FTP Login
		$loginok=false;
		if (@ftp_login($ftp_conn_id, $this->job['ftpuser'], base64_decode($this->job['ftppass']))) {
			trigger_error(__('FTP Server Completion reply:','backwpup').' 230 User '.$this->job['ftpuser'].' logged in.',E_USER_NOTICE);
			$loginok=true;
		} else { //if PHP ftp login don't work use raw login
			if ($this->_ftp_raw_helper($ftp_conn_id,'USER '.$this->job['ftpuser'])) {
				if ($this->_ftp_raw_helper($ftp_conn_id,'PASS '.base64_decode($this->job['ftppass']))) {
					$loginok=true;
				}
			}
		}
		
		//if (ftp_login($ftp_conn_id, $jobs[$jobid]['ftpuser'], $jobs[$jobid]['ftppass'])) {
		if (!$loginok) 
			return false;
			
		//SYSTYPE
		$this->_ftp_raw_helper($ftp_conn_id,'SYST');
		//PASV
		trigger_error(__('FTP Client command:','backwpup').' PASV',E_USER_NOTICE);
		if (ftp_pasv($ftp_conn_id, true))
			trigger_error(__('Server Completion reply: 227 Entering Passive Mode','backwpup'),E_USER_NOTICE);
		else 
		trigger_error(__('FTP Server reply:','backwpup').' '.__('Can not Entering Passive Mode','backwpup'),E_USER_WARNING);
		//ALLO show no erros in log if do not work
		trigger_error(__('FTP Client command:','backwpup').' ALLO',E_USER_NOTICE);
		ftp_alloc($ftp_conn_id,filesize($this->backupdir.$this->backupfile),$result);
		trigger_error(__('FTP Server reply:','backwpup').' '.$result,E_USER_NOTICE);
			
		//test ftp dir and create it f not exists
		$ftpdirs=explode("/", untrailingslashit($this->job['ftpdir']));
		foreach ($ftpdirs as $ftpdir) {
			if (empty($ftpdir))
				continue;
			if (!@ftp_chdir($ftp_conn_id, $ftpdir)) {
				trigger_error('"'.$ftpdir.'" '.__('FTP Dir on Server not exists!','backwpup'),E_USER_WARNING);
				if (@ftp_mkdir($ftp_conn_id, $ftpdir)) {
					trigger_error('"'.$ftpdir.'" '.__('FTP Dir created!','backwpup'),E_USER_NOTICE);
					ftp_chdir($ftp_conn_id, $ftpdir);
				} else {
					trigger_error('"'.$ftpdir.'" '.__('FTP Dir on Server can not created!','backwpup'),E_USER_ERROR);
				}
			}
		}
			
		if (ftp_put($ftp_conn_id, $this->job['ftpdir'].$this->backupfile, $this->backupdir.$this->backupfile, FTP_BINARY))  //transfere file
			trigger_error(__('Backup File transferred to FTP Server:','backwpup').' '.$this->job['ftpdir'].$this->backupfile,E_USER_NOTICE);
		else
			trigger_error(__('Can not transfer backup to FTP server.','backwpup'),E_USER_ERROR);
		
		if ($this->job['ftpmaxbackups']>0) { //Delete old backups
			$backupfilelist=array();
			if ($filelist=ftp_nlist($ftp_conn_id, $this->job['ftpdir'])) {
				foreach($filelist as $files) {
					if ('backwpup_'.$this->jobid.'_' == substr(basename($files),0,strlen('backwpup_'.$this->jobid.'_')) and $this->backupfileformat == substr(basename($files),-strlen($this->backupfileformat)))
						$backupfilelist[]=basename($files);
				}
				if (sizeof($backupfilelist)>0) {
					rsort($backupfilelist);
					$numdeltefiles=0;
					for ($i=$this->job['ftpmaxbackups'];$i<sizeof($backupfilelist);$i++) {
						if (ftp_delete($ftp_conn_id, $this->job['ftpdir'].$backupfilelist[$i])) //delte files on ftp
						$numdeltefiles++;
						else 
							trigger_error(__('Can not delete file on FTP Server:','backwpup').' '.$this->job['ftpdir'].$backupfilelist[$i],E_USER_ERROR);
					}
					if ($numdeltefiles>0)
						trigger_error($numdeltefiles.' '.__('files deleted on FTP Server:','backwpup'),E_USER_NOTICE);
				}
			}
		}
		ftp_close($ftp_conn_id); 

	}	

	private function destination_mail() {
		if (empty($this->job['mailaddress']))
			return false;
			
		trigger_error(__('Prepare Sending backupfile with mail...','backwpup'),E_USER_NOTICE);
			
		//Crate PHP Mailer
		require_once(ABSPATH.WPINC.'/class-phpmailer.php');
		require_once(ABSPATH.WPINC.'/class-smtp.php');
		$phpmailer = new PHPMailer();
		//Setting den methode
		if ($this->cfg['mailmethod']=="SMTP") {
			$smtpport=25;
			$smtphost=$this->cfg['mailhost'];
			if (false !== strpos($this->cfg['mailhost'],':')) //look for port
				list($smtphost,$smtpport)=explode(':',$this->cfg['mailhost'],2);
			$phpmailer->Host=$smtphost;
			$phpmailer->Port=$smtpport;
			$phpmailer->SMTPSecure=$this->cfg['mailsecure'];
			$phpmailer->Username=$this->cfg['mailuser'];
			$phpmailer->Password=base64_decode($this->cfg['mailpass']);
			if (!empty($this->cfg['mailuser']) and !empty($this->cfg['mailpass']))
				$phpmailer->SMTPAuth=true;
			$phpmailer->IsSMTP();
			trigger_error(__('Send mail with SMTP','backwpup'),E_USER_NOTICE);
		} elseif ($this->cfg['mailmethod']=="Sendmail") {
			$phpmailer->Sendmail=$this->cfg['mailsendmail'];
			$phpmailer->IsSendmail();
			trigger_error(__('Send mail with Sendmail','backwpup'),E_USER_NOTICE);
		} else {
			$phpmailer->IsMail();
			trigger_error(__('Send mail with PHP mail','backwpup'),E_USER_NOTICE);
		}
		

		trigger_error(__('Creating mail','backwpup'),E_USER_NOTICE);
		$phpmailer->From     = $this->cfg['mailsndemail'];
		$phpmailer->FromName = $this->cfg['mailsndname'];
		$phpmailer->AddAddress($this->job['mailaddress']); 
		$phpmailer->Subject  =  __('BackWPup File from','backwpup').' '.date_i18n('Y-m-d H:i',$this->job['starttime']).': '.$this->job['name'];
		$phpmailer->IsHTML(false);
		$phpmailer->Body  =  'Backup File';
		
		//check file Size
		if (!empty($this->job['mailefilesize'])) {
			$maxfilezise=abs($this->job['mailefilesize']*1024*1024);
			if (filesize($this->backupdir.$this->backupfile)>$maxfilezise) {
				trigger_error(__('Backup Archive too big for sending by mail','backwpup'),E_USER_ERROR);
				return false;
			}
		}
		
		trigger_error(__('Adding Attachment to mail','backwpup'),E_USER_NOTICE);
		$this->need_free_memory(filesize($this->backupdir.$this->backupfile)*5);
		$phpmailer->AddAttachment($this->backupdir.$this->backupfile);		
		
		trigger_error(__('Send mail....','backwpup'),E_USER_NOTICE);
		if (false == $phpmailer->Send()) {
			trigger_error(__('Can not send mail:','backwpup').' '.$phpmailer->ErrorInfo,E_USER_ERROR);
		} else {
			trigger_error(__('Mail send!!!','backwpup'),E_USER_NOTICE);
		}	
			
	}

	private function destination_s3() {
		
		if (empty($this->job['awsAccessKey']) or empty($this->job['awsSecretKey']) or empty($this->job['awsBucket'])) 
			return false;

		if (!(extension_loaded('curl') or @dl(PHP_SHLIB_SUFFIX == 'so' ? 'curl.so' : 'php_curl.dll'))) {
			trigger_error(__('Can not load curl extension is needed for S3!','backwpup'),E_USER_ERROR);
			return false;
		}

		if (!class_exists('S3')) 
			require_once(plugin_dir_path(__FILE__).'libs/S3.php');
	
		$s3 = new S3($this->job['awsAccessKey'], $this->job['awsSecretKey'], $this->job['awsSSL']);

		if (in_array($this->job['awsBucket'],$s3->listBuckets())) {
			trigger_error(__('Connected to S3 Bucket:','backwpup').' '.$this->job['awsBucket'],E_USER_NOTICE);
			//Transfer Backup to S3
			if ($s3->putObjectFile($this->backupdir.$this->backupfile, $this->job['awsBucket'], $this->job['awsdir'].$this->backupfile, S3::ACL_PRIVATE))  //transfere file to S3
				trigger_error(__('Backup File transferred to S3://','backwpup').$this->job['awsBucket'].'/'.$this->job['awsdir'].$this->backupfile,E_USER_NOTICE);
			else
				trigger_error(__('Can not transfer backup to S3.','backwpup'),E_USER_ERROR);
			
			if ($this->job['awsmaxbackups']>0) { //Delete old backups
				$backupfilelist=array();
				if (($contents = $s3->getBucket($this->job['awsBucket'],$this->job['awsdir'])) !== false) {
					foreach ($contents as $object) {
						if ($this->job['awsdir'].basename($object['name']) == $object['name']) {//only in the folder and not in complete bucket
							$file=basename($object['name']);
							if ('backwpup_'.$this->jobid.'_' == substr(basename($file),0,strlen('backwpup_'.$this->jobid.'_')) and $this->backupfileformat == substr(basename($file),-strlen($this->backupfileformat)))
								$backupfilelist[]=basename($object['name']);
						}
					}
				}
				if (sizeof($backupfilelist)>0) {
					rsort($backupfilelist);
					$numdeltefiles=0;
					for ($i=$this->job['awsmaxbackups'];$i<sizeof($backupfilelist);$i++) {
						if ($s3->deleteObject($this->job['awsBucket'], $this->job['awsdir'].$backupfilelist[$i])) //delte files on S3
							$numdeltefiles++;
						else 
							trigger_error(__('Can not delete file on S3//:','backwpup').$this->job['awsBucket'].'/'.$this->job['awsdir'].$backupfilelist[$i],E_USER_ERROR);
					}
					if ($numdeltefiles>0)
						trigger_error($numdeltefiles.' '.__('files deleted on S3 Bucket!','backwpup'),E_USER_NOTICE);
				}
			}
		} else {
			trigger_error(__('S3 Bucket not exists:','backwpup').' '.$this->job['awsBucket'],E_USER_ERROR);
		}
	}

	private function destination_dir() {
		if (empty($this->job['backupdir']))  //Go back if no destination dir
			return;
		//Delete old Backupfiles
		$backupfilelist=array();
		if (!empty($this->job['maxbackups'])) {
			if ( $dir = @opendir($this->job['backupdir']) ) { //make file list	
				while (($file = readdir($dir)) !== false ) {
					if ('backwpup_'.$this->jobid.'_' == substr($file,0,strlen('backwpup_'.$this->jobid.'_')) and $this->backupfileformat == substr($file,-strlen($this->backupfileformat)))
						$backupfilelist[]=$file;
				}
				@closedir( $dir );
			}
			if (sizeof($backupfilelist)>0) {
				rsort($backupfilelist);
				$numdeltefiles=0;
				for ($i=$this->job['maxbackups'];$i<sizeof($backupfilelist);$i++) {
					unlink(trailingslashit($this->job['backupdir']).$backupfilelist[$i]);
					$numdeltefiles++;
				}
				if ($numdeltefiles>0)
					trigger_error($numdeltefiles.' '.__('old backup files deleted!!!','backwpup'),E_USER_NOTICE);
			}
		}
	}
	
	public function __destruct() {
		global $backwpup_logfile;
		
		if (is_file($this->backupdir.$this->backupfile)) {
			trigger_error(sprintf(__('Backup Archive File size is %1s','backwpup'),backwpup_formatBytes(filesize($this->backupdir.$this->backupfile))),E_USER_NOTICE);
		}

		if (is_file($this->tempdir.DB_NAME.'.sql') ) { //delete sql temp file
			unlink($this->tempdir.DB_NAME.'.sql');
		}
		
		if (is_file($this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml') ) { //delete WP XML Export temp file
			unlink($this->tempdir.'wordpress.' . date( 'Y-m-d' ) . '.xml');
		}
		
		if (empty($this->job['backupdir']) and is_file($this->backupdir.$this->backupfile)) { //delete backup file in temp dir
			unlink($this->backupdir.$this->backupfile);
		}
		
		//delete old logs
		if (!empty($this->cfg['maxlogs'])) {
			if ( $dir = @opendir($this->logdir) ) { //make file list	
				while (($file = readdir($dir)) !== false ) {
					if ('backwpup_log_' == substr($file,0,strlen('backwpup_log_')) and ".html" == substr($file,-5))
						$logfilelist[]=$file;
				}
				@closedir( $dir );
			}
			if (sizeof($logfilelist)>0) {
				rsort($logfilelist);
				$numdeltefiles=0;
				for ($i=$this->cfg['maxlogs'];$i<sizeof($logfilelist);$i++) {
					unlink($this->logdir.$logfilelist[$i]);
					$numdeltefiles++;
				}
				if ($numdeltefiles>0)
					trigger_error($numdeltefiles.' '.__('old Log files deleted!!!','backwpup'),E_USER_NOTICE);
			}
		}		
		
		$jobs=get_option('backwpup_jobs');
		$jobs[$this->jobid]['stoptime']=current_time('timestamp');
		$jobs[$this->jobid]['lastrun']=$jobs[$this->jobid]['starttime'];
		$jobs[$this->jobid]['lastruntime']=$jobs[$this->jobid]['stoptime']-$jobs[$this->jobid]['starttime'];
		update_option('backwpup_jobs',$jobs); //Save Settings
		trigger_error(sprintf(__('Job done in %1s sec.','backwpup'),$jobs[$this->jobid]['lastruntime']),E_USER_NOTICE);

		//write runtime header
		$fd=@fopen($backwpup_logfile,"r+");
		while (!feof($fd)) {
			$line=@fgets($fd);
			if (stripos($line,"<meta name=\"backwpup_jobruntime\"") !== false) {
				@fseek($fd,$filepos);
				@fputs($fd,str_pad("<meta name=\"backwpup_jobruntime\" content=\"".$jobs[$this->jobid]['lastruntime']."\" />",100)."\n");
				break;
			}
			$filepos=ftell($fd);
		}
		@fclose($fd);
		//logfile end
		$fd=fopen($backwpup_logfile,"a+");
		fputs($fd,"</body>\n</html>\n");
		fclose($fd);
		restore_error_handler();
		$logdata=backwpup_read_logheader($backwpup_logfile);
		//Send mail with log
		$sendmail=false;
		if ($logdata['errors']>0 and $this->job['mailerroronly'] and !empty($this->job['mailaddresslog']))
			$sendmail=true;
		if (!$this->job['mailerroronly'] and !empty($this->job['mailaddresslog']))
			$sendmail=true;
		if ($sendmail) {
			$mailbody=__("Jobname:","backwpup")." ".$logdata['name']."\n";
			$mailbody.=__("Jobtype:","backwpup")." ".$logdata['type']."\n";
			if (!empty($logdata['errors'])) 
				$mailbody.=__("Errors:","backwpup")." ".$logdata['errors']."\n";
			if (!empty($logdata['warnings']))
				$mailbody.=__("Warnings:","backwpup")." ".$logdata['warnings']."\n";
			wp_mail($this->job['mailaddresslog'],__('BackWPup Log File from','backwpup').' '.date_i18n('Y-m-d H:i',$this->job['starttime']).': '.$this->job['name'] ,$mailbody,'',array($this->logdir.$this->logfile));
		}
	}
}
?>