<?php
//we need a dummy component for manipulating notifications
class BPLocalGroupNotifier extends BP_Component {
    
    public function __construct() {
        
		$bp = buddypress();
		parent::start(
			'localgroupnotifier',
			__( 'Local Group Notifier', 'bp-local-group-notifier' ),
            plugin_dir_path( __FILE__ )
		);
		
        $bp->active_components[$this->id] = 1;
	}
    
    
    public function includes( $files = array() ) {
        
    }
    
    public function setup_globals( $global = array() ) {
		
		global $bp;

        $helper = BPLocalGroupNotifierHelper::get_instance();
		// All globals for messaging component.
		// Note that global_tables is included in this array.
		$globals = array(
			'slug'                  => $this->id,
			'root_slug'             => false,
			'has_directory'         => false,
			'notification_callback' => 'bp_local_group_notifier_format_notifications' ,//Bp cuirrently does not support object method callbacks her
			'global_tables'         => false
		);

		parent::setup_globals( $globals );
        
    }

}
    
function bp_setup_local_group_notifier() {
	
	 $bp = buddypress();

	$bp->localgroupnotifier = new BPLocalGroupNotifier();
}
add_action( 'bp_loaded', 'bp_setup_local_group_notifier' );
    
