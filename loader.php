<?php
// Do not allow direct access over web.
defined( 'ABSPATH' ) || exit;

/**
 * Dummy Component.
 *
 * We need a dummy component for manipulating notifications.
 */
class BPLocalGroupNotifier extends BP_Component {

	/**
	 * Constructor.
	 */
	public function __construct() {

		$bp = buddypress();
		parent::start(
			'localgroupnotifier',
			__( 'Local Group Notifier', 'bp-local-group-notifier' ),
			plugin_dir_path( __FILE__ )
		);

		$bp->active_components[ $this->id ] = 1;
	}


	public function includes( $files = array() ) {
	}

	/**
	 * Setup globals.
	 *
	 * @param array $global globals.
	 */
	public function setup_globals( $global = array() ) {

		// All globals for messaging component.
		// Note that global_tables is included in this array.
		$globals = array(
			'slug'                  => $this->id,
			'root_slug'             => false,
			'has_directory'         => false,
			'notification_callback' => 'bp_local_group_notifier_format_notifications',
			// Bp currently does not support object method callbacks here.
			'global_tables'         => false,
		);

		parent::setup_globals( $globals );

	}

}

/**
 * Registerr Dummy Component.
 */
function bp_setup_local_group_notifier() {
	buddypress()->localgroupnotifier = new BPLocalGroupNotifier();
}

add_action( 'bp_loaded', 'bp_setup_local_group_notifier' );
