<?php

/**
 * Plugin Name: BP Local Group Notifier
 * Plugin URI: http://buddydev.com/plugins/bp-local-group-notifier/
 * Author URI: http://buddydev.com/members/sbrajesh/
 * Version: 1.0
 * Description: Notifies on any action in the group to all group members. I have tested with group join, group post update. Should be able to notify on new forum post/reply etc too
 */

//load the component
add_action('bp_include','bp_local_group_notifier_load');

function bp_local_group_notifier_load(){
    
    include_once (plugin_dir_path(__FILE__).'loader.php');
    
}

/**
 * This is called white magic
 * filter on all the activities added to the group
 * and notify the users 
 */
add_action('bp_activity_add','bp_local_group_notifier_notify_members');
function bp_local_group_notifier_notify_members($params){
    global $bp;
    //first we need to check if this is a group activity
    if($params['component']!=$bp->groups->id)
        return ;
    
    //now, find that activity
    //we have group id
    $activity_id= bp_activity_get_activity_id($params);
  
	if(empty($activity_id))
        return;
    
    $activity= new BP_Activity_Activity($activity_id);
    $group_id=$activity->item_id;
   
    //$content, $user_id, $group_id, $activity_id
    $members_data=  BP_Groups_Member::get_all_for_group( $group_id, false,false,false );//include admin/mod
    $members=$members_data['members'];
  
    foreach((array)$members as $member){
        if($member->user_id==$activity->user_id)
             continue;
        
        
        //we need to make each notification unique, otherwise bp will group it
         bp_core_add_notification($group_id, $member->user_id, 'localgroupnotifier', 'group_local_notification_'.$activity_id, $activity_id);
    }
    
   
}
/**
 *  Just formats the notification
 * @param type $action
 * @param type $item_id
 * @param type $secondary_item_id
 * @param type $total_items
 * @param type $format
 * @return type
 */

function bp_local_group_notifier_format_notifications($action, $item_id, $secondary_item_id, $total_items, $format = 'string'){
    
   $group_id=$item_id; 
   $group = groups_get_group( array( 'group_id' => $group_id ) );
   $group_link = bp_get_group_permalink( $group ); 
   
   if ( (int) $total_items > 1 ) {
				$text = sprintf( __( '%1$d new activities in the group "%2$s"', 'bp-local-group-notifier' ), (int) $total_items, $group->name );
				
                

				if ( 'string' == $format ) {
					return '<a href="' . $group_link . '" title="' . __( 'New group Activities', 'bp-local-group-notifier' ) . '">' . $text . '</a>';
				} else {
					return array(
						'link' => $group_link,
						'text' => $text
					);
				}
			} else {
                $activity= new BP_Activity_Activity($secondary_item_id);
                
				$text = strip_tags($activity->action);//here is the hack, think about it :)
				
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

/**
 * Delete notification for user when he views single activity
 */
add_action('bp_activity_screen_single_activity_permalink', 'bp_local_group_notifier_delete_notification',10,2);

function bp_local_group_notifier_delete_notification($activity, $has_access){
    if(!is_user_logged_in())
        return;
    
    if(!$has_access)
       return ;
    
    BP_Core_Notification::delete_for_user_by_item_id(get_current_user_id(), $activity->item_id, 'localgroupnotifier','group_local_notification_'.$activity->id, $activity->id);
    
}
?>