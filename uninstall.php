<?php
/**
 * $Id: uninstall.php 565576 2012-06-29 20:22:45Z bastb $
 * 
 * Uninstall for the plugin. Removes stored data, as the LinkedIn terms
 * of service requires.
 * 
 * Values of custom variables will still be present after deinstallation. 
 * 
 */
 
require_once('lips.php');
require_once('tokenstore.php');

class LinkedInProfileSyncCleanupManager {
	protected $clean_user_meta = true;
	protected $clean_config    = true;
	
	public function __construct() {
			
	}
	
	protected function cleanUserMeta() {
		foreach (get_users(array('orderby' => 'display_name')) as $user) {
			$uid = $user->ID;
			$tokenstore = new WpUserMetaTokenStore($uid);
			$tokenstore->expire(true, true);
			foreach (array(LIPS_USER_META_PROFILE, LIPS_USER_META_IGNORE, LIPS_USER_META_LAST_SYNC, LIPS_META_ACCOUNT_ID) as $meta) {
				delete_user_meta($uid, $meta);	
			}
		}
	}
	
	protected function cleanConfig() {
		delete_option(SETTINGS_ID);
	}
	
	public function cleanup() {
		if ($this->clean_user_meta) {
			$this->cleanUserMeta();
		}
		
		if ($this->clean_config) {
			$this->cleanConfig();
		}
	}
}

if (defined('WP_UNINSTALL_PLUGIN')) {
	$cleanup = new LinkedInProfileSyncCleanupManager();
	$cleanup->cleanup();
}
?>
