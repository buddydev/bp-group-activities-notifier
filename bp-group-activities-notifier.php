<?php
/**
 * Plugin Name: BuddyPress Group Activities Notifier
 * Plugin URI: https://buddydev.com/plugins/bp-group-activities-notifier/
 * Author: BuddyDev Team
 * Author URI: https://buddydev.com
 * Version: 1.0.6
 * Description: Notifies on any action in the group to all group members. I have tested with group join, group post update, forum post/reply. Should work with others too
 */

// Do not allow direct access over web.
defined( 'ABSPATH' ) || exit;

/**
 * Main Class.
 */
class BP_Local_Group_Notifier_Helper {

	/**
	 * Singleton.
	 *
	 * @var BP_Local_Group_Notifier_Helper
	 */
	private static $instance;

	/**
	 * Constructor.
	 */
	private function __construct() {
		// setup actions on bp_include.
		add_action( 'bp_include', array( $this, 'setup' ) );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return BP_Local_Group_Notifier_Helper
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Sets up actions only if both the groups/notifications components are enabled
	 */
	public function setup() {
		// only load if the notifications/groups components are active.
		if ( ! bp_is_active( 'groups' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$this->load();

		// notify members on new activity.
		add_action( 'bp_activity_add', array( $this, 'notify_members' ), 10, 2 );
		add_action( 'bp_before_activity_delete', array( $this, 'clear_members_notifications' ) );
		// delete notification when viewing single activity.
		add_action(
			'bp_activity_screen_single_activity_permalink',
			array(
				$this,
				'delete_on_single_activity',
			),
			10,
			2
		);
		// sniff and delete notification for forum topic/replies.
		add_action( 'bp_init', array( $this, 'delete_for_group_forums' ), 20 );
		// load text domain.
		add_action( 'bp_init', array( $this, 'load_textdomain' ) );

	}

	/**
	 * Load required dummy component
	 */
	public function load() {
		$path = plugin_dir_path( __FILE__ );

		require_once $path . 'core/bp-group-activities-functions.php';
		require_once $path . 'loader.php';
	}

	/**
	 * Notifies Users of new Group activity
	 *
	 * Should we put an options in the notifications page of user to allow them opt out?
	 *
	 * @param array $params args.
	 *
	 * @return null
	 */
	public function notify_members( $params, $activity_id ) {

		$bp = buddypress();

		// first we need to check if this is a group activity.
		if ( $params['component'] != $bp->groups->id ) {
			return;
		}

		if ( empty( $activity_id ) ) {
			return;
		}

		// we found it, good!
		$activity = new BP_Activity_Activity( $activity_id );

		if ( apply_filters( 'bp_local_group_notifier_skip_notification', false, $activity ) ) {
			return;// do not notify.
		}

		// ok this is in fact the group id.
		// I am not sure about 3rd party plugins, but bbpress, buddypress adds group activities like this.
		$group_id = $activity->item_id;

		// let us fetch all members data for the group except the banned users.
		$members = BP_Groups_Member::get_group_member_ids( $group_id );// include admin/mod.

		// Let us add the local notification in bulk.
		self::add_bulk_notifications( $members, $activity );

		do_action(
			'bp_group_activities_notify_members',
			$members,
			array(
				'group_id'    => $group_id,
				'user_id'     => bp_loggedin_user_id(),
				'activity_id' => $activity_id,
			)
		);
	}

	/**
	 * Clears notification on activity delete.
	 *
	 * @param array $args args.
	 */
	public function clear_members_notifications( $args ) {
		if ( empty( $args['id'] ) ) {
			return;
		}
		$activity = new BP_Activity_Activity( $args['id'] );
		if ( ! $activity->id || $activity->component != 'groups' ) {
			return;
		}

		self::delete_bulk_notifications( $activity );
	}

	/**
	 * Delete notification for user when he views single activity
	 */
	public function delete_on_single_activity( $activity, $has_access ) {

		if ( ! is_user_logged_in() || ! $has_access ) {
			return;
		}

		BP_Notifications_Notification::delete(
			array(
				'user_id'           => get_current_user_id(),
				'item_id'           => $activity->item_id,
				'component_name'    => 'localgroupnotifier',
				'component_action'  => 'group_local_notification_' . $activity->id,
				'secondary_item_id' => $activity->id,

			)
		);

	}

	/**
	 * Delete the notifications for New topic/ Topic replies if viewing the topic/topic replies
	 *
	 * I am supporting bbpress 2.3+ plugin and not standalone bbpress which comes with BP 1.6
	 */
	public function delete_for_group_forums() {

		if ( ! is_user_logged_in() || ! function_exists( 'bbpress' ) ) {
			// just make sure we are doing it for bbpress plugin.
			return;
		}

		// the identification of notification for forum topic/reply is taxing operation
		// so, we need to make sure we don't abuse it.
		if ( bp_is_single_item() && bp_is_groups_component() && bp_is_current_action( 'forum' ) && bp_is_action_variable( 'topic' ) ) {
			// we are on single topic page.
			// bailout if user has no notification related to group.
			if ( ! self::notification_exists(
				array(
					'item_id'   => bp_get_current_group_id(),
					'component' => 'localgroupnotifier',
					'user_id'   => get_current_user_id(),
				) )
			) {
				return;
			}

			// so, the current user has group notifications, now let us see if they belong to this topic.
			// Identify the topic.
			// Get topic data.
			$topic_slug = bp_action_variable( 1 );

			$post_status = array( bbp_get_closed_status_id(), bbp_get_public_status_id() );

			$topic_args = array(
				'name'        => $topic_slug,
				'post_type'   => bbp_get_topic_post_type(),
				'post_status' => $post_status,
			);

			$topics = get_posts( $topic_args );

			// Does this topic exists?
			if ( ! empty( $topics ) ) {
				$topic = $topics[0];
			}

			if ( empty( $topic ) ) {
				return;// if not, let us return.
			}


			// since we are here, the topic exists
			// let us find all the replies for this topic
			// Default query args.
			$default = array(
				'post_type'      => bbp_get_reply_post_type(), // Only replies.
				'post_parent'    => $topic->ID, // Of this topic.
				'posts_per_page' => - 1, // all.
				'paged'          => false,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'post_status'    => 'any',
			);

			global $wpdb;

			$reply_ids = array();

			$replies = get_posts( $default );

			// pluck the reply ids.
			if ( ! empty( $replies ) ) {
				$reply_ids = wp_list_pluck( $replies, 'ID' );
			}

			// since reply/topic are just post type, let us include the ID of the topic too in the list.
			$reply_ids[] = $topic->ID;// put topic id in the list too.
			$list        = '(' . join( ',', $reply_ids ) . ')';

			// find the activity ids associated with these topic/replies.
			$activity_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value AS id FROM {$wpdb->postmeta} WHERE meta_key=%s AND post_id IN {$list}", '_bbp_activity_id' ) );

			// now, we will need to fetch the activities for these activity ids.
			$activities = bp_activity_get_specific(
				array(
					'activity_ids' => $activity_ids,
					'show_hidden'  => true,
					'spam'         => 'all',
				)
			);

			// ok, we do have these activities.
			if ( $activities['total'] > 0 ) {
				$activities = $activities['activities'];
			}

			// this is the logged in user for whom we are trying to delete notification.
			foreach ( (array) $activities as $activity ) {
				// delete now.
				BP_Notifications_Notification::delete(
					array(
						'user_id'           => get_current_user_id(),
						'item_id'           => $activity->item_id,
						'component_name'    => 'localgroupnotifier',
						'component_action'  => 'group_local_notification_' . $activity->id,
						'secondary_item_id' => $activity->id,

					)
				);

			}
		}

	}

	/**
	 * Add notifications in bulk in one query
	 *
	 * @param array                $members members list.
	 * @param BP_Activity_Activity $activity activity object.
	 */
	public static function add_bulk_notifications( $members, $activity ) {
		global $wpdb;

		$item_id           = $activity->item_id;
		$component_name    = 'localgroupnotifier';
		$component_action  = 'group_local_notification_' . $activity->id;
		$secondary_item_id = $activity->id;
		$date_notified     = bp_core_current_time();
		$is_new            = 1;

		$table = buddypress()->notifications->table_name;

		// Chunk members into 200 per list and do a bulk insert for each of this chunk
		// This way, It will save us huge number of queries.
		foreach ( array_chunk( $members, 200 ) as $members_list ) {

			$sql = array();


			foreach ( $members_list as $user_id ) {

				if ( $user_id == $activity->user_id ) {
					continue;// don't add for the user who posted this activity.
				}

				$sql[] = $wpdb->prepare(
					"(%d, %d, %d, %s, %s, %s, %d )",
					$user_id,
					$item_id,
					$secondary_item_id,
					$component_name,
					$component_action,
					$date_notified,
					$is_new
				);
			}


			if ( empty( $sql ) ) {
				return;// It was a single member group.
			}

			$list = join( ',', $sql );

			$sql_insert_query = "INSERT INTO {$table} (user_id, item_id, secondary_item_id, component_name, component_action, date_notified,  is_new) values {$list}";

			// insert.
			$wpdb->query( $sql_insert_query );

		}

	}

	/**
	 * Delete notifications in bulk on activity delete.
	 *
	 * @param BP_Activity_Activity $activity activity object.
	 */
	private function delete_bulk_notifications( $activity ) {
		global $wpdb;

		$item_id           = $activity->item_id;
		$component_name    = 'localgroupnotifier';
		$component_action  = 'group_local_notification_' . $activity->id;
		$secondary_item_id = $activity->id;

		$table = buddypress()->notifications->table_name;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE item_id=%d AND secondary_item_id=%d AND component_name=%s AND component_action=%s", $item_id, $secondary_item_id, $component_name, $component_action ) );
	}

	/**
	 * Check if a notification exists
	 *
	 * @param array $args args.
	 *
	 * @return boolean
	 * @global wpdb $wpdb
	 */
	public function notification_exists( $args = '' ) {

		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'user_id'           => false,
				'item_id'           => false,
				'component'         => false,
				'action'            => false,
				'secondary_item_id' => false,
			)
		);

		$table = buddypress()->notifications->table_name;

		$query = "SELECT id FROM {$table} ";

		$where = array();

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = $wpdb->prepare( 'user_id=%d', $args['user_id'] );
		}

		if ( ! empty( $args['item_id'] ) ) {
			$where[] = $wpdb->prepare( 'item_id=%d', $args['item_id'] );
		}

		if ( $args['component'] ) {
			$where[] = $wpdb->prepare( 'component_name=%s', $args['component'] );
		}

		if ( ! empty( $args['action'] ) ) {
			$where[] = $wpdb->prepare( 'component_action=%s', $args['action'] );
		}

		if ( ! empty( $args['secondary_item_id'] ) ) {
			$where[] = $wpdb->prepare( 'secondary_item_id=%d', $args['secondary_item_id'] );
		}

		$where_sql = join( ' AND ', $where );

		return $wpdb->get_var( $query . " WHERE {$where_sql}" );

	}

	/**
	 * Load translation file
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'bp-group-activities-notifier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
}


// instantiate.
BP_Local_Group_Notifier_Helper::get_instance();

