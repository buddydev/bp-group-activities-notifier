<?php

/**
 * Plugin Name: BP Group Activities Notifier
 * Plugin URI: http://buddydev.com/plugins/bp-group-activities-notifier/
 * Author: Brajesh Singh(BuddyDev)
 * Author URI: http://buddydev.com/members/sbrajesh/
 * Version: 1.0.1
 * Description: Notifies on any action in the group to all group members. I have tested with group join, group post update, forum post/reply. Sould work with others too
 */

//load the component
add_action( 'bp_include', 'bp_local_group_notifier_load' );

function bp_local_group_notifier_load() {
    //we need a dummy component
    include_once plugin_dir_path( __FILE__ ) . 'loader.php';
    
}

class BPLocalGroupNotifierHelper {
    
    private static $instance;

    private function __construct() {
        //notify members on new activity
        add_action( 'bp_activity_add', array( $this, 'notify_members' ) );
        //delete notification when viewing single activity
        add_action( 'bp_activity_screen_single_activity_permalink', array( $this, 'delete_on_single_activity' ), 10, 2 );
        //sniff and delete notification for forum topic/replies
        add_action( 'bp_init', array( $this, 'delete_for_group_forums' ), 20 );
         //load text domain
        add_action ( 'bp_loaded', array( $this, 'load_textdomain' ), 2 );

    }
    
    
    public static function get_instance() {
		
        if( ! isset( self::$instance ) )
           self::$instance= new self();
        
        return self::$instance;
        
    }
   /**
    * Notifies Users of new Group activity
    * 
    * should we put an options in the notifications page of user to allow them opt out ?
    * 
    * @global type $bp
    * @param type $params
    * @return type
    */
    public function notify_members( $params ) {
       
		$bp = buddypress();
 
        //first we need to check if this is a group activity
        if( $params['component'] != $bp->groups->id )
            return ;

        //now, find that activity
        $activity_id = bp_activity_get_activity_id( $params );

        if( empty( $activity_id ) )
            return;
      
        //we found it, good! 
        $activity = new BP_Activity_Activity( $activity_id );
        //ok this is infact the group id
        //I am not sure about 3rd party plugins, but bbpress, buddypress adds group activities like this
        $group_id = $activity->item_id;
       
        //let us fetch all members data for the group except the banned users
		
        $members =  BP_Groups_Member::get_group_member_ids( $group_id );//include admin/mod
        
        //ok let us fetch the members list
       

        //and we will add a notification for each user
        foreach( (array)$members as $user_id ) {
			
            if( $user_id == $activity->user_id )
                 continue;//but not for the current logged user who performed this action

            //we need to make each notification unique, otherwise bp will group it
             self::add_notification( $group_id, $user_id, 'localgroupnotifier', 'group_local_notification_' . $activity_id, $activity_id );
        }


    }


    /**
     * Delete notification for user when he views single activity
     */
    public function delete_on_single_activity( $activity, $has_access ) {
        
		if( ! is_user_logged_in() )
            return;

        if( !$has_access )
           return ;

		//
		BP_Notifications_Notification::delete( array(
			'user_id'			=> get_current_user_id(),
			'item_id'			=> $activity->item_id,
			'component_name'	=> 'localgroupnotifier',
			'component_action'	=> 'group_local_notification_' . $activity->id,
			'secondary_item_id'	=> $activity->id
			
		));
       // BP_Core_Notification::delete_for_user_by_item_id( get_current_user_id(), $activity->item_id, 'localgroupnotifier', 'group_local_notification_'.$activity->id, $activity->id );
    
    }
    /**
     * Delet the notifications for New topic/ Topic replies if viewing the topic/topic replies
     * 
     * I am supporting bbpress 2.3+ plugin and not standalone bbpress which comes with BP 1.6
     * 
     * 
     * @global type $wpdb
     * @return type
     */
    
    public function delete_for_group_forums() {
		
        if( ! is_user_logged_in() || ! function_exists( 'bbpress' ) )//just make sure we are doing it for bbpress plugin
            return;
        
        //the identfication of notification for forum topic/reply is taxing operation
        //so, we need to make sure we don't abuse t
        if ( bp_is_single_item() && bp_is_groups_component() && bp_is_current_action( 'forum' ) && bp_is_action_variable('topic') ) {
            //we are on single topic page
            //
            //bailout if user has no notification related to group

            if( ! self::notification_exists(
                    array(
                        'item_id'	=>  bp_get_current_group_id(),//the group id
                        'component'	=> 'localgroupnotifier',
                        'user_id'	=>  get_current_user_id()
                     ))
              )
             return;

            //so, the current user has group notifications, now let us see if they belong to this topic
           

			//Identify the topic
			// Get topic data
			$topic_slug = bp_action_variable( 1 );
			
			$post_status = array( bbp_get_closed_status_id(), bbp_get_public_status_id() );
			
			$topic_args = array( 'name' => $topic_slug, 'post_type' => bbp_get_topic_post_type(), 'post_status' => $post_status );
			
			$topics     = get_posts( $topic_args );

			// Does this topic exists?
			if ( ! empty( $topics ) ) 
				$topic = $topics[0];

			if( empty( $topic ) )
				return;//if not, let us return


			//since we are here, the topic exists
			//let us find all the replies for this topic
			// Default query args
			$default = array(
				'post_type'      => bbp_get_reply_post_type(), // Only replies
				'post_parent'    => $topic->ID, // Of this topic
				'posts_per_page' => -1, // all
				'paged'          => false, 
				'orderby'        => 'date',
				'order'          => 'ASC' ,
				'post_status'    =>'any'   


			);

            global $wpdb;
                
            $reply_ids = array();
            
            $replies = get_posts($default);
            
            //pluck the reply ids
            if( ! empty( $replies ) )
                $reply_ids = wp_list_pluck( $replies, 'ID' );
            
            //since reply/topic are just post type, let us include the ID of the topic too in the list
            
            $reply_ids[] = $topic->ID;//put topic id in the list too
            $list = '(' . join( ',', $reply_ids ) . ')';

            //find the activity ids associated with these topic/replies
            $activity_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value AS id FROM {$wpdb->postmeta} WHERE meta_key=%s AND post_id IN {$list}", '_bbp_activity_id' ) );

            //now, we will need to fetch the activities for these activity ids
            $activities = bp_activity_get_specific( array( 'activity_ids' => $activity_ids, 'show_hidden' => true, 'spam' => 'all', ) );
            
            //ok, we do have these activities
            if( $activities['total'] > 0 )
                $activities = $activities['activities'];
            
            //this is the logged in user for whom we are trying to delete notification
            
           

            foreach( (array) $activities as $activity ) {
                //delete now
				BP_Notifications_Notification::delete( array(
					'user_id'			=> get_current_user_id(),
					'item_id'			=> $activity->item_id,
					'component_name'	=> 'localgroupnotifier',
					'component_action'	=> 'group_local_notification_' . $activity->id,
					'secondary_item_id'	=> $activity->id
			
			));
                
            }
                
        
    }
    
  }
    
    /**
     * Adds a notification to the user
     * 
     * I am not using bp_core_add_notification as the forum component was mis behaving and we were getting two notifications added for the same activity
     * It checks if there exists a notification for activity, if not, It adds that notification for user
     * 
     * @param type $item_id
     * @param type $user_id
     * @param type $component_name
     * @param type $component_action
     * @param type $secondary_item_id
     * @param type $date_notified
     * @return boolean
     */
    public function add_notification( $item_id, $user_id, $component_name, $component_action, $secondary_item_id = 0, $date_notified = false ) {

            if( self::notification_exists( array(
				'item_id'			=> $item_id,
				'component'			=> $component_name,
				'action'			=> $component_action,
				'secondary_item_id'	=> $secondary_item_id,
				'user_id'			=> $user_id

               ) ) )
             return ;
    
            if ( empty( $date_notified ) )
               $date_notified = bp_core_current_time();
            //check if a notification already exists

            $notification                   = new BP_Notifications_Notification;
            $notification->item_id          = $item_id;
            $notification->user_id          = $user_id;
            $notification->component_name   = $component_name;
            $notification->component_action = $component_action;
            $notification->date_notified    = $date_notified;
            $notification->is_new           = 1;

            if ( !empty( $secondary_item_id ) )
                $notification->secondary_item_id = $secondary_item_id;

            if ( $notification->save() )
                return true;

            return false;
    }

    /**
     * Check if a notification exists
     * 
     * @global type $bp
     * @global type $wpdb
     * @param array $args
     * @return type
     */
    public function notification_exists( $args= ''  ){
        
        global $bp,$wpdb;
        
        $args=  wp_parse_args( $args, array(
                    'user_id'			=> false,
                    'item_id'			=> false,
                    'component'			=> false,
                    'action'			=> false,
                    'secondary_item_id'	=> false
                ));
        
        extract( $args );

        $query = "SELECT id FROM {$bp->notifications->table_name} ";

        $where = array();

        if( $user_id )
            $where[] = $wpdb->prepare( "user_id=%d", $user_id );

        if( $item_id )
            $where[] = $wpdb->prepare( "item_id=%d", $item_id );

        if( $component )
            $where[] = $wpdb->prepare( "component_name=%s", $component );

        if( $action )
            $where[] = $wpdb->prepare( "component_action=%s", $action );
		
        if( $secondary_item_id )
            $where[] = $wpdb->prepare( "secondary_item_id=%d", $secondary_item_id );


        $where_sql = join( " AND ", $where );
       
        return $wpdb->get_var( $query . " WHERE {$where_sql}" );
    
    }
      
//load text domain
   //localization
    public function load_textdomain() {

        $locale = apply_filters( 'group_activities_load_textdomain_get_locale', get_locale() );
        
      
	// if load .mo file
	if ( ! empty( $locale ) ) {
		$mofile_default = sprintf( '%slanguages/%s.mo', plugin_dir_path( __FILE__ ), $locale );
              
		$mofile = apply_filters( 'group_activities_load_textdomain_mofile', $mofile_default );
		
        if ( is_readable( $mofile ) ) {
                    // make sure file exists, and load it
			load_textdomain( 'bp-group-activities-notifier', $mofile );
		}
	}
}
}



//instantiate
BPLocalGroupNotifierHelper::get_instance();

    

/**
* Just formats the notification
* 
* @param type $action
* @param type $item_id
* @param type $secondary_item_id
* @param type $total_items
* @param type $format
* @return type
*/

function bp_local_group_notifier_format_notifications( $action, $item_id, $secondary_item_id, $total_items, $format = 'string' ) {

	$group_id = $item_id; 
	$group = groups_get_group( array( 'group_id' => $group_id ) );
	$group_link = bp_get_group_permalink( $group ); 

	if ( (int) $total_items > 1 ) {
		
		$text = sprintf( __( '%1$d new activities in the group "%2$s"', 'bp-group-activities-notifier' ), (int) $total_items, $group->name );

		if ( 'string' == $format ) {
			return '<a href="' . $group_link . '" title="' . __( 'New group Activities', 'bp-group-activities-notifier' ) . '">' . $text . '</a>';
		} else {
			return array(
				'link' => $group_link,
				'text' => $text
			);
		}
	} else {
		
		$activity= new BP_Activity_Activity($secondary_item_id);

		$text = strip_tags( $activity->action );//here is the hack, think about it :)

		$notification_link = bp_activity_get_permalink( $activity->id, $activity );

		if ( 'string' == $format ) {
			
			return '<a href="' . $notification_link . '" title="' .$text . '">' . $text . '</a>';
			
		} else {
				
			return array(
				'link' => $notification_link,
				'text' => $text
			);
		}
	} 
}
