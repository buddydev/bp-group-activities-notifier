<?php
//we need a dummy component for manipulating notifications
class BP_Local_Group_Notifier extends BP_Component{
    
    function __construct() {
        global $bp;
		parent::start(
			'localgroupnotifier',
			__( 'Local Group Notifier', 'bp-local-group-notifier' ),
            plugin_dir_path(__FILE__)
		);
         $bp->active_components[$this->id] = 1;
	}
    
    
    function includes(){
        
    }
    
    function setup_globals() {
		global $bp;

		


		// All globals for messaging component.
		// Note that global_tables is included in this array.
		$globals = array(
			'slug'                  => $this->id,
			'root_slug'             => false,
			'has_directory'         => false,
			'notification_callback' => 'bp_local_group_notifier_format_notifications',
			'global_tables'         => false
		);

		parent::setup_globals( $globals );
        
    }
    
    
    
    
}
    



 function bp_setup_local_group_notifier() {
	global $bp;

	$bp->localgroupnotifier = new BP_Local_Group_Notifier();
}
add_action( 'bp_loaded', 'bp_setup_local_group_notifier' );
    
?>