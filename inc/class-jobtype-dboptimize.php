<?php
/**
 *
 */
class BackWPup_JobType_DBOptimize extends BackWPup_JobTypes {

	/**
	 *
	 */
	public function __construct() {

		$this->info[ 'ID' ]        	 = 'DBOPTIMIZE';
		$this->info[ 'name' ]        = __( 'DB Optimize', 'backwpup' );
		$this->info[ 'description' ] = __( 'Optimize database tables', 'backwpup' );
		$this->info[ 'URI' ]         = translate( BackWPup::get_plugin_data( 'PluginURI' ), 'backwpup' );
		$this->info[ 'author' ]      = BackWPup::get_plugin_data( 'Author' );
		$this->info[ 'authorURI' ]   = translate( BackWPup::get_plugin_data( 'AuthorURI' ), 'backwpup' );
		$this->info[ 'version' ]     = BackWPup::get_plugin_data( 'Version' );

	}

	/**
	 * @return array
	 */
	public function option_defaults() {

		return array( 'dboptimizewponly' => TRUE, 'dboptimizemyisam' => TRUE, 'dboptimizeinnodb' => TRUE, 'dboptimizemaintenance' => FALSE );
	}


	/**
	 * @param $jobid
	 */
	public function edit_tab( $jobid ) {
		?>
		<h3 class="title"><?php _e( 'Settings for database optimization', 'backwpup' ) ?></h3>
		<p></p>
		<table class="form-table">
			<tr valign="top">
                <th scope="row"><?php _e( 'WordPress tables only', 'backwpup' ); ?></th>
				<td>
                    <label for="iddboptimizewponly">
					<input class="checkbox" value="1" id="iddboptimizewponly"
						   type="checkbox" <?php checked( BackWPup_Option::get( $jobid, 'dboptimizewponly' ), TRUE ); ?>
						   name="dboptimizewponly" /> <?php _e( 'Optimize WordPress Database tables only', 'backwpup' ); ?>
					</label>
				</td>
			</tr>
            <tr valign="top">
                <th scope="row"><?php _e( 'Maintenance mode', 'backwpup' ); ?></th>
                <td>
                    <label for="iddboptimizemaintenance">
					<input class="checkbox" value="1" id="iddboptimizemaintenance"
						   type="checkbox" <?php checked( BackWPup_Option::get( $jobid, 'dboptimizemaintenance' ), TRUE ); ?>
						   name="dboptimizemaintenance" /> <?php _e( 'Activate maintenance mode during table optimize', 'backwpup' ); ?>
				    </label>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php _e( 'Table types to optimize', 'backwpup' ); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><span><?php _e( 'Table types to optimize', 'backwpup' ) ?></span>
                        </legend>
                        <label for="iddboptimizemyisam"><input class="checkbox" value="1" id="iddboptimizemyisam"
                               type="checkbox" <?php checked( BackWPup_Option::get( $jobid, 'dboptimizemyisam' ), TRUE ); ?>
                               name="dboptimizemyisam" /> <?php _e( 'Optimize MyISAM Tables', 'backwpup' ); ?>
						<?php BackWPup_help::tip( __( 'Optimize will done with OPTIMIZE TABLE `table`.', 'backwpup' ) ); ?></label>
						<br />
                        <label for="iddboptimizeinnodb"><input class="checkbox" value="1" id="iddboptimizeinnodb"
                               type="checkbox" <?php checked( BackWPup_Option::get( $jobid, 'dboptimizeinnodb' ), TRUE ); ?>
                               name="dboptimizeinnodb" /> <?php _e( 'Optimize InnoDB tables', 'backwpup' ); ?>
						<?php BackWPup_help::tip( __( 'Optimize will done with ALTER TABLE `table` ENGINE=InnoDB', 'backwpup' ) ); ?></label>
                    </fieldset>
                </td>
            </tr>
		</table>
		<?php
	}


	/**
	 * @param $jobid
	 */
	public function edit_form_post_save( $jobid ) {

		BackWPup_Option::update( $jobid, 'dboptimizewponly', ( isset( $_POST[ 'dboptimizewponly' ] ) && $_POST[ 'dboptimizewponly' ] == 1 ) ? TRUE : FALSE );
		BackWPup_Option::update( $jobid, 'dboptimizemaintenance', ( isset( $_POST[ 'dboptimizemaintenance' ] ) && $_POST[ 'dboptimizemaintenance' ] == 1 ) ? TRUE : FALSE );
		BackWPup_Option::update( $jobid, 'dboptimizemyisam', ( isset( $_POST[ 'dboptimizemyisam' ] ) && $_POST[ 'dboptimizemyisam' ] == 1 ) ? TRUE : FALSE );
		BackWPup_Option::update( $jobid, 'dboptimizeinnodb', ( isset( $_POST[ 'dboptimizeinnodb' ] ) && $_POST[ 'dboptimizeinnodb' ] == 1 ) ? TRUE : FALSE );
	}

	/**
	 * @param $job_object
	 * @return bool
	 */
	public function job_run( $job_object ) {
		global $wpdb;

		trigger_error( sprintf( __( '%d. Trying to optimize database &hellip;', 'backwpup' ), $job_object->steps_data[ $job_object->step_working ][ 'STEP_TRY' ] ), E_USER_NOTICE );
		if ( ! isset( $job_object->steps_data[ $job_object->step_working ][ 'DONETABLE' ] ) || ! is_array( $job_object->steps_data[ $job_object->step_working ][ 'DONETABLE' ] ) )
			$job_object->steps_data[ $job_object->step_working ][ 'DONETABLE' ] = array();

		//tables to otimize
		$tables = array();
		$restables = $wpdb->get_results( 'SHOW FULL TABLES FROM `' . $wpdb->dbname . '`', ARRAY_N );
		foreach ( $restables as $table ) {
			if ( ! empty( $job_object->job[ 'dboptimizewponly' ] ) ) {
				$tables[ ]                 = $table[ 0 ];
				$tablestype[ $table[ 0 ] ] = $table[ 1 ];
			} elseif ( strstr($table[ 0 ], $wpdb->prefix ) ) {
				$tables[ ]                 = $table[ 0 ];
				$tablestype[ $table[ 0 ] ] = $table[ 1 ];
			}
		}
		//Set num
		$job_object->substeps_todo = sizeof( $tables );

		//Get table status
		$resstatus = $wpdb->get_results( "SHOW TABLE STATUS FROM `" . $wpdb->dbname . "`", ARRAY_A );
		foreach ( $resstatus as $tablestatus ) {
			$status[ $tablestatus[ 'Name' ] ] = $tablestatus;
		}

		if ( $job_object->substeps_todo > 0 ) {
			if ( ! empty( $job_object->job[ 'dboptimizemaintenance' ] ) )
				$job_object->maintenance_mode( TRUE );
			foreach ( $tables as $table ) {
				if ( in_array( $table, $job_object->steps_data[ $job_object->step_working ][ 'DONETABLE' ] ) )
					continue;
				if ( $tablestype[ $table ] == 'VIEW' ) {
					trigger_error( sprintf( __( 'Views can\'t optimize! View %1$s', 'backwpup' ), $table ), E_USER_NOTICE );
					continue;
				}
				//OPTIMIZE TABLE funktioniert nur bei MyISAM-, BDB- und InnoDB-Tabellen. (http://dev.mysql.com/doc/refman/5.1/de/optimize-table.html)
				if ( ! empty( $job_object->job[ 'dboptimizemyisam' ] ) && $status[ $table ][ 'Engine' ] == 'MyISAM' ) {
					$optimize = $wpdb->get_row( "OPTIMIZE TABLE `" . $table . "`", OBJECT );
					if ( strtolower( $optimize->Msg_type ) == 'error' )
						trigger_error( sprintf( __( 'Result of MyISAM table optimize for %1$s is: %2$s', 'backwpup' ), $table, $optimize->Msg_text ), E_USER_ERROR );
					elseif ( strtolower( $optimize->Msg_type ) == 'warning' )
						trigger_error( sprintf( __( 'Result of MyISAM table optimize for %1$s is: %2$s', 'backwpup' ), $table, $optimize->Msg_text ), E_USER_WARNING );
					else
						trigger_error( sprintf( __( 'Result of MyISAM table optimize for %1$s is: %2$s', 'backwpup' ), $table, $optimize->Msg_text ), E_USER_NOTICE );
				}
				elseif ( ! empty( $job_object->job[ 'dboptimizeinnodb' ] ) && $status[ $table ][ 'Engine' ] == 'InnoDB' ) {
					$res = $wpdb->query( "ALTER TABLE `" . $table . "` ENGINE='InnoDB'" );
					if ( ! empty( $res ) )
						trigger_error( sprintf( __( 'InnoDB Table %1$s optimizing done.', 'backwpup' ), $table ), E_USER_NOTICE );
				}
				else {
					trigger_error( sprintf( __( '%2$s table %1$s not optimized.', 'backwpup' ), $table, $status[ $table ][ 'Engine' ] ), E_USER_NOTICE );
				}
				$job_object->steps_data[ $job_object->step_working ][ 'DONETABLE' ][ ] = $table;
				$job_object->substeps_done ++;
			}
			trigger_error( __( 'Database optimize done!', 'backwpup' ), E_USER_NOTICE );
			$job_object->maintenance_mode( FALSE );
		}
		else {
			trigger_error( __( 'No tables to optimize.', 'backwpup' ), E_USER_WARNING );
		}

		unset( $job_object->steps_data[ $job_object->step_working ][ 'DONETABLE' ] );
		return TRUE;
	}

}
