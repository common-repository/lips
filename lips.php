<?php
/*
Plugin Name: LinkedIn Profile Synchronizer Tool
Plugin URI:  http://www.tenberge-ict.nl/tools/wordpress/lips/
Description: Synchronizes your professional LinkedIn profile, updating WordPress Pages and Posts.
Version: 0.8.3
Author: Bas ten Berge
Author URI: http://www.tenberge-ict.nl/profiel
License: GPL2

 LinkedIn Profile Synchronization Tool downloads the LinkedIn profile and feeds the 
 downloaded data to Smarty, the templating engine, in order to update a local page.
 Copyright (C) 2012 Bas ten Berge

  This library is free software; you can redistribute it and/or
 modify it under the terms of the GNU Library General Public
 License as published by the Free Software Foundation; either
 version 2 of the License, or (at your option) any later version.

 This library is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 Library General Public License for more details.

 You should have received a copy of the GNU Library General Public
 License along with this library; if not, write to the
 Free Software Foundation, Inc., 51 Franklin St, Fifth Floor,
 Boston, MA  02110-1301, USA.

 $Id: lips.php 576798 2012-07-24 19:05:29Z bastb $
 
 References:
  http://ottopress.com/2009/wordpress-settings-api-tutorial/
  https://developer.linkedin.com/documents/quick-start-guide#toggleview:id=php
  http://alisothegeek.com/wp-content/uploads/2011/01/settings-api-tutorial.zip
  http://archive.extralogical.net/2007/06/wphooks/

 ... as it modifies an existing page (edit_private_pages, edit_published_posts), 
 is able to create or modify existing posts (edit_posts, edit_private_posts) and
 creates categories (manage_categories) permissions, the user executing this tool 
 must at least be an editor.
*/
require_once('lips_oauth.php');
require_once('lips_filter.php');
require_once('linkedlist.php');
require_once('templating.php');
require_once('tokenstore.php');
require_once('template_meta.php');
require_once('convenience.php');
require_once('profilesync.php');
require_once('exception.php');
require_once('i18n.php');
if (! defined('GESHI_VERSION')) {
	require_once('GeSHi/geshi.php');
}

define('SETTINGS_ID', "e75b15d0_a917_4711_9347_5033834de19b");
define('LIPS_FILTER_LIST_OPTION', "a3c956b9_1378_4f0e_9ba2_9d7a7c93343b");
define('LIPS_PAGE_TITLE', 'LinkedIn Profile Synchronization Tool');
define('LIPS_OPTIONS_PAGE', "lips");
define('LIPS_PARENT_PAGE', "tools.php");

define('LIPS_POST_META_POSITION_ID', "_linkedin:position_id");
define('LIPS_POST_META_CONTENT', "_lips:content");
define('LIPS_POST_META_LANG', "_lips:lang");
define('LIPS_USER_META_IGNORE', "_lips:ignored");
define('LIPS_USER_META_PROFILE', "linkedin:profile");
define('LIPS_USER_META_LAST_SYNC', "lips:last_synced");
define('LIPS_USER_META_PICTURE', "lips:picture");
define('LIPS_PAGE_META_AVAILABLE', "_lips:page_use");
define('LIPS_PAGE_META_CSS', "_lips:css");
define('LIPS_META_ACCOUNT_ID', "_lips:account_id");

define('LIPS_PROFILE_FETCHED_FILTER', LIPS_OPTIONS_PAGE . "_profile_filter");
define('LIPS_PROFILE_PRE_TEMPLATE_FILTER', LIPS_OPTIONS_PAGE . "_pre_template_filter");
define('LIPS_PROFILE_UPDATED_ACTION', LIPS_OPTIONS_PAGE . "_profile_updated");
define('LIPS_DEFAULT_NOTIFICATION_DAYS', 60);
define('LIPS_DEFAULT_POST_TITLE_TEMPLATE', "{\$position.title|capitalize:true}@{\$position.company.name}");


/**
 * This class is copied from 
 *  http://alisothegeek.com/wp-content/uploads/2011/01/settings-api-tutorial.zip
 * 
 * Modified to fit the WordPress-LinkedIn tool
 * 
 */
class LinkedInProfileSyncOptions {
	// The capability an user must have to view admin notices and so on
	protected $capability = 'edit_published_pages';
	// Array of post ids which must not be shown
	protected $posts_to_hide;
	// Associative array of post_id to post_permalink 
	protected $post_uri;
	// The debug page is stored to the options, as is the option to use geshi. 
	// These settings will not be available on the first time the plugin is 
	// started. This is a workaround.
	protected $debug_page_id = null;
	protected $debug_page_use_geshi = false;
	// Page usage type recognized by this plugin
	protected $jquery_page_usage_types = array('rt' => 'profile', 'dev' => 'dev_profile');
	// No page selected option values
	protected $jquery_no_page_selected = array('page' => 'select-profile-page', 'dbg' => 'select-debug-page');
	// oauth error
	protected $jquery_error_details;
	// message 
	protected $jquery_static_message;
	// Javascript method being invoked when the admin page is displayed to the user
	protected $jquery_autorun;
	// sample page link
	protected $jquery_sample_page;
	protected $jquery_sample_post;
	// profiles stored to the metadata for this user
	protected $jquery_available_profiles = array();
	// uploading message
	protected $jquery_uploading;
	// currently logged on wp user
	protected $current_user; 
	// store containing the OAuth keys and values
	protected $tokenstore;
	// authreq error message
	protected $auth_request_error_message = null;
	// lowest version of WordPress this plugin has run with, inclusive.
	protected $wp_lowest_version = '3';
	protected $has_fetched_profile = false;
	protected $current_picture_size = null; // For profilePictureAdded
	
	private $sections;
	private $checkboxes;
	private $settings;
	
	/**
	 * Construct
	 *
	 * @since 1.0
	 */
	public function __construct() {
		$this->sections['li']  = __( 'LinkedIn Data Access and Profile Synchronization' );
		$this->sections['pat']  = __( 'Page Settings' );
		$this->sections['pot']  = __( 'Post Settings' );
		$this->sections['dev']  = __( 'Development Settings' );
		
		// This will keep track of the checkbox options for the validate_settings function.
		$this->checkboxes       = array();
		$this->settings         = array();
		
		register_activation_hook(__FILE__, 'LinkedInProfileSyncMetadataManager::updateMetadata');
		register_deactivation_hook(__FILE__, 'LinkedInProfileSyncMetadataManager::deleteMetadata');
		register_activation_hook(__FILE__, 'LinkedInI18N::storeLanguages');
		register_deactivation_hook(__FILE__, 'LinkedInI18N::deleteLanguages');
		register_activation_hook(__FILE__, 'LinkedInProfileSyncPostFilter::updateFilterList');
		add_action('admin_menu', array(&$this, 'add_pages'));
		add_action('admin_init', array(&$this, 'prepare'));
		
		if ($this->isDisplayingOptionsPage() || $this->isPosting()) {	
			add_action('admin_init', array(&$this, 'register_settings'));
			add_action('admin_notices', array(&$this, 'getInLipsNotification'));
			add_action('wp_ajax_' . LIPS_OPTIONS_PAGE, array(&$this, 'handleAjaxRequest'));

			add_filter('option_page_capability_' . SETTINGS_ID, array($this, 'getCapabilityName'));
			
			add_action(LIPS_PROFILE_UPDATED_ACTION, array($this, 'saveHiddenPostsList'), 8);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'jsonStringToAssociativeArrayFilter'), 1);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'updateLinkedInAccountIdFilter'), 2);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'addProfileLanguageFilter'), 2, 2);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'addCompanyDetailsFilter'), 5);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'generifyTemplateDataFilter'), 6, 2);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'keepCopyOfProfilePictureFilter'), 8);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'groupPositionByCompanyFilter'), 8);
			add_filter(LIPS_PROFILE_FETCHED_FILTER, array($this, 'addRecommendatorProfileLinkFilter'), 8);
			add_filter(LIPS_PROFILE_PRE_TEMPLATE_FILTER, array($this, 'positionToPostFilter'), 10, 2);
		
			if (! get_option(SETTINGS_ID)) {
				$this->initialize_settings();
			}
		}
		else {
			add_action('admin_notices', array(&$this, 'getAttentionNotification'));
		}
	}
	
	/**
	 * Add options page
	 *
	 * @since 1.0
	 */
	public function add_pages() {
 		// add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function );
 		// $parent_slug := <The slug name for the parent menu (or the file name of a standard WordPress admin page)>
 		// $page_title := <The text to be displayed in the title tags of the page when the menu is selected>
 		// $menu_title := <The text to be used for the menu>
 		// $capability := <The capability required for this menu to be displayed to the user>
 		// $menu_slug := <The slug name to refer to this menu by (should be unique for this menu)>
 		// $function := <The function to be called to output the content for this page>
		$suffix = add_submenu_page(LIPS_PARENT_PAGE, LIPS_PAGE_TITLE, 'LinkedIn&reg; Profile Sync', $this->capability, LIPS_OPTIONS_PAGE, array(&$this, 'display_page'));
		add_action(sprintf('load-%s', $suffix), array($this, 'disable_notification'));
		add_action(sprintf('load-%s', $suffix), array($this, 'handleOAuthReset'));
		add_action(sprintf('load-%s', $suffix), array($this, 'handleHideNotification'));
		add_action('admin_print_scripts-' . $suffix, array(&$this, 'scripts'));
		add_action('admin_print_styles-' . $suffix, array(&$this, 'styles'));
	}

	/**
	 * Initializes the data provider, which gains access to OAuth related stuff
	 */	
	public function prepare() {
		$this->tokenstore = new WpUserMetaTokenStore();
		$this->current_user = wp_get_current_user();
		$this->has_fetched_profile = $this->hasSyncedProfile();
		$profiles = get_user_meta($this->current_user->ID, LIPS_USER_META_PROFILE, true);
		if (is_array($profiles)) {
			$standard_languages = LinkedInI18N::getLanguages();
			foreach (array_diff(array_keys($profiles), array("last")) as $lang) {
				$this->jquery_available_profiles[$lang] = $standard_languages[$lang];
			}
			asort($this->jquery_available_profiles, SORT_LOCALE_STRING);
		}
	}

	/**
	* Register settings
	*
	* @since 1.0
	*/
	public function register_settings() {
		register_setting(SETTINGS_ID, SETTINGS_ID, array(&$this, 'validate_settings'));
		
		$options = get_option(SETTINGS_ID);
		
		foreach ( $this->sections as $slug => $title ) {
			add_settings_section($slug, $title, array(&$this, 'display_section'), LIPS_OPTIONS_PAGE);
		}
		
		$ordered_settings = $this->getOrderedSettings();
		
		foreach ( $ordered_settings as $id => $setting ) {
			$setting['id'] = $id;
			if (! array_key_exists('value', $setting)) {
				$setting['value'] = $options[$id];
			}
			$this->create_setting($setting);
		}
	}

	/**
	 * Adds a notication on top of the admin screen when the tool has not been configured,
	 * when the tool has never run or when the tool has not been ran in a month
	 */
	public function getInLipsNotification() {
		global $wp_version;
		if (version_compare($wp_version, $this->wp_lowest_version, '<')) {
			echo $this->skipIgnoredNotification(__(LIPS_PAGE_TITLE ." may be incompatible with this version of WordPress"), "version_check", false);				
		} 
		else {
			if (empty($_SERVER['HTTPS'])) {
				echo $this->skipIgnoredNotification(__(LIPS_PAGE_TITLE ." should really be administered over SSL"), "ssl", false);
			}
			if (! extension_loaded('oauth')) {
				// Copied from http://linkedinapi.blogspot.nl/, just like the revoking thing
				echo $this->skipIgnoredNotification(__(LIPS_PAGE_TITLE ." requires the PHP OAuth PECL extension. It will just not work without this extension"), "pecl_oauth", false);
			}
			if (function_exists('openssl_x509_parse')) {
				$cainfo = openssl_x509_parse(file_get_contents(LinkedInProfileSyncOAuth::getCAInfo()));
				if ($cainfo['validTo_time_t'] - time() <= 60*86400) {
					echo $this->skipIgnoredNotification(__("The installed CA-certificate is valid until ") . date_i18n(get_option('date_format') . " " . get_option('time_format'), $cainfo['validTo_time_t']), "li_expire", false);
				}
			}
		}
	}

	/**
	 * Adds a notification message into the dashboard, drawing attention to the tool
	 */
	public function getAttentionNotification() {
		if(current_user_can($this->capability)) {
			$options = get_option(SETTINGS_ID);
			$message = "";
			
			$smarty = new LinkedInProfileSyncSmarty();
			$smarty_version = $smarty->getVersion();
	
			if (version_compare($smarty_version, "3.1", "<")) {
				$message = $this->skipIgnoredNotification(LIPS_PAGE_TITLE . " " . __("has not been verified to run with Smarty") . " {$smarty_version}", "smarty_version", false);
			} 
			else if (null == $this->tokenstore->getIdentificationToken())
				$message = $this->skipIgnoredNotification(LIPS_PAGE_TITLE . " " . __("is not configured yet"), "attention");
			else {
				// See the difference in last succesfull sync. Add a notice when this has been more than
				$sync_meta = get_user_meta($this->current_user->ID, LIPS_USER_META_LAST_SYNC, true); 
				if (!is_array($sync_meta)) {
					$message = $this->skipIgnoredNotification(LIPS_PAGE_TITLE . " " . __("has not fetched your profile yet"), "never_ran");
				}
				else {
					$notification_days = LIPS_DEFAULT_NOTIFICATION_DAYS;
					$difference_in_seconds = time() - $sync_meta['li_profile'];
					if ($difference_in_seconds > intval($notification_days) * 86400 ) {
						$message = $this->skipIgnoredNotification(LIPS_PAGE_TITLE . " " . sprintf(__("has not been run in %d days"), $difference_in_seconds / 86400), "too_old");
					}
				}
			}
			
			echo $message;
		}
	}
	
	/**
	 * WordPress assumes every plugin uses the "manage_options" capability.
	 * This is intented to fix the Cheatin' uh? message
	 */
	public function getCapabilityName($capability) {
		return $this->capability;
	}
	
	/**
	 * Handles the ajax request, querying the WordPress host for a request.
	 */
	public function handleAjaxRequest() {
		if (isset($_POST['request']) && !empty($_POST['request'])) {
		    $action = $_POST['request'];
		}
		
		global $wpdb;
		
		switch ($action) {
			case 'oalink':
				try {
					$tokenstore = new WpUserMetaTokenStore();
					$oauth = LinkedInProfileSyncOAuth::fromTokenStore($tokenstore, false);
					$auth_request = null;
					$auth_url = $oauth->getAuthorizationUrl(&$auth_request);
					if ($auth_url) {
						$tokenstore->set($auth_request, true);
						die(sprintf('0:%s <a class="lips-ext-ref" href="%s" target="liauth" onclick="window.open(\'%s\', \'%s\', \'status=1,location=1,resizable=1,width=800,height=600\'); return false" class="linkedin-r-auth" id="lips-linkedin-auth">%s</a> %s', __('By visiting the'), $auth_url, $auth_url, "LinkedIn", __('LinkedIn Authorization page,'), __('pasting the security code here and clicking "Fetch" you allow the tool to:')));
					}
				}
				catch (WordPressIntegratedOAuthException $e) {
					die('1:' . $e->getMessage());
				 }
			break;
			
			case 'create_page':
				$page_title = trim($_POST['specific']);
				$page_usage = $_POST['page-usage']; 
				if (empty($page_title)) {
					die('1:' . __('Empty page title'));
				}
				if (! in_array($page_usage, array_values($this->jquery_page_usage_types))) {
					die('2:' . __('Page usage not recognized'));
				}
				// Try to create a page with this title, and add the metadata to it.
				$page = array(
					'post_type' => 'page',
					'post_title' => $page_title,
					'post_status' => 'draft',
				);
				$result = wp_insert_post($page, true);
				if ($result instanceof WP_Error) {
					die('1:' . $result->get_error_message());
				}
				update_post_meta($result, LIPS_PAGE_META_AVAILABLE, $page_usage);						
				die('0:' . $result);
				
			break;
			die();
		}
	}
	
	/**
	 * Called when an existing post is updated. Request the page metadata, verify
	 * if it's a post maintained by this plugin and add it to the list of pages
	 */
	public function onPostSavedAction($post_id) {
		$meta_value = get_post_meta($post_id, LIPS_POST_META_POSITION_ID, true);
		if (! empty($meta_value)) {
			$this->post_uri[$meta_value] = array('uri' => get_permalink($post_id));
			$this->posts_to_hide[] = $post_id;
		}
	}
	
	/**
	 * Called when a new post is created. Could be any post, even the ones not
	 * being managed by this plugin. Check the post for the meta-key and value, and get 
	 * the permalink when this post has the right metadata.
	 */
	public function onPostMetaUpdatedAction($meta_id, $post_id, $meta_key, $meta_value) {
		if (LIPS_POST_META_POSITION_ID == $meta_key) {
			$this->post_uri[$meta_value] = array('uri' => get_permalink($post_id));
			// Save the post id to the lists of posts not being shown when the blog
			// page is shown.
			$this->posts_to_hide[] = $post_id;
		}
	}
	
	/**
	 * Constructs the contents for the About Text Box. This box contains some information
	 * about the plugin and a bunch of links, including a Donate Button.
	 */
	protected function getAboutText() {
		$smarty = new LinkedInProfileSyncSmarty();
		$smarty_version = $smarty->getVersion();
		$geshi_version = GESHI_VERSION;
		
		$about_text = <<<EOS
<p>LinkedIn&reg; Profile Synchronization (LiPS)<br/>
&copy; 2012 Bas ten Berge</p>
<p>LinkedIn Corporation is in no way affiliated with this plugin.</p>
<p>This plugin downloads your professional profile from the LinkedIn&reg; website, processes it using the <a class="lips-ext-ref" href="http://www.smarty.net">Smarty templating engine</a> and saves the outcome to a page. 
It uses <em>OAuth</em> - a secure standard - to read your profile from LinkedIn&reg;, so you do not have to provide your loginname and password.</p>
<p>The profile data is saved in meta-information to your WordPress&trade; account too, allowing you to try different templates without accessing LinkedIn&reg.</p>
<div class="lips-about-links">
<ul>
<li class="menu-top"><a class="menu-top lips-ext-ref" href="http://www.tenberge-ict.nl/tools/wordpress/lips/?utm_source=wp-lips&utm_medium=plugin&utm_campaign=free-plugin" target="wplips-about">About LiPS</a></li>
<li class="menu-top"><a class="menu-top lips-ext-ref" href="http://www.tenberge-ict.nl/tools/wordpress/lips/wp-lips-template/?utm_source=wp-lips&utm_medium=plugin&utm_campaign=free-plugin" target="wplips-about">Templating</a></li>
<li class="menu-top"><a class="menu-top lips-ext-ref" href="http://www.tenberge-ict.nl/contact/english/?utm_source=wp-lips&utm_medium=plugin&utm_campaign=free-plugin" target="wplips-about">Question, bugs, improvements</a></li>
</ul>
<p>This installation uses:
<ul>
<li class="menu-top"><a class="menu-top lips-ext-ref" href="http://www.smarty.net">Smarty</a> {$smarty_version}</li>
<li class="menu-top"><a class="menu-top lips-ext-ref" href="http://qbnz.com/highlighter/">GeSHi</a> {$geshi_version}</li>
</ul>
</p>
</div>
<div class="lips-about-donate"><form action="https://www.paypal.com/cgi-bin/webscr" method="post">
<input type="hidden" name="cmd" value="_s-xclick">
<input type="hidden" name="hosted_button_id" value="589BSLHCP2PUW">
<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
<img alt="" border="0" src="https://www.paypalobjects.com/nl_NL/i/scr/pixel.gif" width="1" height="1">
</form>
</div>
EOS;
		return $about_text;
	}
	
	/**
	 * Display options page
	 *
	 * @since 1.0
	 */
	public function display_page() {
		echo '<div class="wrap">
	<div class="icon32" id="icon-options-general"></div>
	<div class="lips-top"><h2>' . __( LIPS_PAGE_TITLE ) . '</h2><div class="lips-about" id="lips-about"><a href="javascript:void(0);">About</a></div></div>
	<div class="lips-help"><span class="lips-help-text">' . $this->getAboutText() . '</span><a href="javascript:void(0);" class="button lips-close-help" id="lips-close">Close</a></div>';
	
		settings_errors();
		
		echo '<form action="options.php" method="post" id="lips-form">';
	
		settings_fields(SETTINGS_ID);
		
		echo '<div id="lips-pin-box"><span id="pre-pin">' . '<p>' . __('The plugin needs to be authorized to access your data. Click the Authorization Page link, grant access and paste the security code in the textbox:') . '</p></span><input type="text" id="pin" name="' . SETTINGS_ID . '[pin]" class="regular-text" value="PIN" /><p><label for="pin"><span id="oalink"></span><br/>' . '<ul><li>' . __('<strong>Read</strong> your LinkedIn&reg; profile data and <strong>modify</strong> a page on <em>this</em> host using a template') . '</li><li>' . __('<strong>Store</strong> the profile data on <em>this</em> host') . '</li></ul></label></p></div>';
		echo '<div id="lips-page-box">' . __('LiPS uses a page to display the profile or debug-profile on. You can create a new page, which is recognized by the plugin. Enter a title for your new page in the textbox:') . '</p><input type="text" id="lips-page" name="' . SETTINGS_ID . '[lips-page]" class="regular-text" /><p>'.__('Click the Create button to create the page. The settings page will reload and any unsaved changes will get lost') .'</p></div>';
		echo '<div id="lips-err-box">' . '<p><span id="lips-err-text">' . __('There is a problem getting an authorization code from LinkedIn&reg;:') . '</p></span><span id="lips-err-detail" class="lips-err-detail"></span><p><span id="lips-err-additional-detail">' . __('This may be caused by incorrect OAuth Identification, a slow internet connection or a problem at LinkedIn&reg;. Retry in a couple of minutes if you did not change anything') . '</span></div>';
		echo '<div class="ui-tabs">
			<ul class="ui-tabs-nav">';
			
		foreach ( $this->sections as $section_slug => $section )
			echo '<li><a href="#' . $section_slug . '" class="lips-tab-section">' . $section . '</a></li>';
		
		echo '</ul>';

		do_settings_sections($_GET['page']);
		
		echo '</div>';
		if (extension_loaded('oauth')) {
			echo '<div id="save-settings" class="submit"><input type="button" name="Submit" class="button-primary" id="save" value="'.esc_attr(__('Save Changes')).'"/><img alt="Uploading ..." src="' . admin_url("/images/wpspin_light.gif")  .  '" id="lips-saving" class="ajax-loading" style="visibity:hidden;"/>';
			try {
				$oauth = LinkedInProfileSyncOAuth::fromTokenStore($this->tokenstore, false);
				echo '<a class="button lips-reset-oauth" id="lips-reset-button" href="' . add_query_arg("action", "reset", $_SERVER['REQUEST_URI']) . '" title="'.__('Deletes locally stored OAuth identification for this user').'">Forget OAuth</a>';				
			}
			catch (IdentificationMissingException $e) { }
			echo '</div></form></div>';
		} 
	}
	
	
	/**
	 * Create settings field
	 *
	 * @since 1.0
	 */
	public function create_setting( $args = array() ) {
		$defaults = array(
			'id'       => 'default_field',
			'title'    => __( 'Default Field' ),
			'desc'     => __( 'This is a default description.' ),
			'std'      => '',
			'type'     => 'text',
			'section'  => '',
			'choices'  => array(),
			'class'    => '',
			'syntax'   => null,
			'required' => false,
			'previous' => null,
			'depends'  => null,
			'enabled'  => true,
			'has_meta' => false,
			'target'   => '',
			'relates'  => null,
		);
		
		extract( wp_parse_args( $args, $defaults ) );
		
		$field_args = array(
			'type'      => $type,
			'id'        => $id,
			'desc'      => $desc,
			'std'       => $std,
			'choices'   => $choices,
			'label_for' => $id,
			'class'     => $class,
			'value'     => is_array($args) && array_key_exists('value', $args) ? $args['value'] : "",
			'syntax'    => $syntax,
			'required'  => $required,
			'depends'   => $depends,
			'enabled'   => $enabled,
			'has_meta'  => $has_meta,
			'target'    => $target,
			'title'     => $title,
			'relates'   => $relates,
		);
		
		if ( $type == 'checkbox' )
			$this->checkboxes[] = $id;
		
		$span_title = "";
		if ('button' != $type) {
			$additional_class = "";
			if ($required) {
				$additional_class = "lips-required-key";
			}
			$span_title = sprintf('<span class="%s %s">%s</span>', $depends, $additional_class, $title);			
		}
		add_settings_field($id, $span_title, array($this, 'display_setting'), LIPS_OPTIONS_PAGE, $section, $field_args);
	}
	
	/**
	 * Description for section
	 *
	 * @since 1.0
	 */
	public function display_section($section) {
		$section_description['li'] = __("This section handles the OAuth configuration. LinkedIn&reg; uses that to control access to your data. Once configured, you can make your host forget the OAuth details too by clicking the <em>Forget OAuth</em> button below.</p><p>Download your profile by checking <em>Update Profile Page</em>. Select <em>Connect to LinkedIn&reg; and download profile data</em> and click the <em>Save Changes</em> button below."); 
		$section_description['pat'] = __("These settings here configure the Profile Page. This is the page that contains a static copy of your LinkedIn&reg; profile. The page content is created by a <em>template</em>. You can create your own template or select one that comes with LiPS.</p><p>An installed template can use <em>Static Variables</em>. A static variable is data that is used by the template, but is not present in the downloaded data. Want to review your profile page before showing it to the public? Check the <em>Change Page Status</em> option.");
		$section_description['pot'] = __("LiPS can maintain posts too. Once enabled, these posts will not be shown with the other posts, but instead you can link to them from your profile page.<p>You can provide categories for posts being maintained by this plugin and you can provide the template to use handling position details. There's a template for the title and one for the content.</p>");
		$section_description['dev'] = __("Functions supporting development are found in this section. You can make the template be more verbose and you can make the plugin keep a copy of the profile data.<p>The Debug Data On-a-Page function should only be enabled when you want to create or modify page, post or post-title templates because it'll allow you to take a look at the gathered data.</p>");
		
		echo "<p>";	
		$section_id = $section['id'];
		if (array_key_exists($section_id, $section_description))
			echo $section_description[$section_id];
		echo "</p>";
	}
	
	/**
	 * Description for About section
	 *
	 * @since 1.0
	 */
	public function display_about_section() {
	}
	
	/**
	 * HTML output for text field
	 *
	 * @since 1.0
	 */
	public function display_setting( $args = array() ) {
		extract( $args );
		
		$print_desc = true;
		$first_time_class = "";
		
		// A current value can be provided. This is an addition for stuff being
		// stored in another settings page.
		if ( isset( $args['value'] ) )
			$options[$id] = $args['value'];
		
		if ( ! isset( $options[$id] ))
			$options[$id] = $std;
			
		// The value might be stored as an array. This is true for the category field.
		if ( is_array($options[$id]) ) {
			$options[$id] = implode(",", $options[$id]);
		}
		
		$field_class = '';
		if ( $class != '' ) {
			$field_class = ' ' . $class;
		}
			
		if ($has_meta) {
			$field_class .= ' lips-with-meta';
		} 
			
		if ($required) {
			$field_class .= ' lips-required';
			if (! $this->has_fetched_profile) {
				$first_time_class = 'lips-virgin';
			}
		}
			
		$state = "";
		if (! $enabled) {
			$state = 'disabled="disabled"';
		}
		
		$name = sprintf("%s[%s]", SETTINGS_ID, $id);

		// Every control is "caged" in a div -> <id>-container with class lips-settings-container
		// The control itself is in a "floating" div, <id>-control with class lips-settings-control
		// The description is in a cleared div, <id>-description with class lips-description-container
		echo sprintf('<div class="%s %s lips-settings-container" id="%s-container">', $depends, $first_time_class, $id);
		echo '<div class="lips-settings-control">';
				
		switch ( $type ) {
			case 'heading':
				echo '</td></tr><tr valign="top"><td colspan="2"><h4>' . $desc . '</h4>';
				$print_desc = false;
				break;
			
			case 'checkbox':
				echo '<input class="checkbox' . $field_class . '" type="checkbox" id="' . $id . '" name="' . $name . '" value="1" ' . checked( $options[$id], 1, false ) . ' '. $state .' /> <label for="' . $id . '">' . $desc . '</label>';
				$print_desc = false;
				break;
				
			case 'button':
				$onclick = "";
				if (! empty($relates)) {
					$onclick = 'onclick="handleRelates(\'' . $relates . '\')"';
				}
				if (empty($target)) {
					$target = "#";
				}
				echo '<a href="' . $target . '" class="' . $field_class . '" id="' . $id . '" ' . $onclick . '>' . $title . '</a><br/>';
				break;
			
			case 'select':
				echo '<select class="select' . $field_class . '" name="' . $name . '" id="'.$id.'" ' . $state . '>';
				
				foreach ( $choices as $value => $label )
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $options[$id], $value, false ) . '>' . $label . '</option>';
				
				echo '</select>';
				break;
			
			case 'radio':
				$i = 0;
				foreach ( $choices as $value => $label ) {
					echo '<input class="radio' . $field_class . '" type="radio" name="' . $name . '" id="' . $id . $i . '" value="' . esc_attr( $value ) . '" ' . checked( $options[$id], $value, false ) . '> <label for="' . $id . $i . '">' . $label . '</label>';
					if ( $i < count( $choices ) - 1 ) {
						echo '<br />';
					}
					$i++;
				}
				break;
			
			case 'textarea':
				echo '<textarea class="' . $field_class . '" id="' . $id . '" name="' . $name . '" placeholder="' . $std . '" rows="5" cols="30">' . wp_htmledit_pre( $options[$id] ) . '</textarea>';
				break;
			
			case 'password':
				echo '<input class="regular-text' . $field_class . '" type="password" id="' . $id . '" name="'. $name . '" value="' . esc_attr( $options[$id] ) . '" />';
				break;
				
			case 'bundle':
				# w3c validator compliancy
				echo sprintf('<div id="%s">', $id);
				foreach ($choices as $choice) {
					echo sprintf('<div class="lips-static %s">', $choice['class']);
					foreach ($choice['cols'] as $col => $value) {
						$specifics = $choice[$col];
						if (array_key_exists($col, $choice)) {
							echo sprintf('<div class="lips-static-element-container %s">', $col);
							$specific_options = $choice[$col];
							switch($specific_options['type']) {
								case 'label':
									echo sprintf('<label class="lips-static-element %s" for="%s">%s</label>', $col, $specific_options['for'], $value);
								break;
								
								case 'input':
									echo sprintf('<input value="%s" class="lips-static-element %s" name="%s" id="%s" />', $value, $col, $specific_options['name'], $specific_options["id"]);
								break;
								
								case 'a':
									if (null != $specific_options['href']) {
										echo sprintf('<a href="%s" target="%s" class="lips-ext-ref">%s</a>', $specific_options['href'], $specific_options['target'], $value);
									}
								break;
							}
							echo '</div><!--lips-static-element-->';
						}
						else {
							echo sprintf('<span class="lips-static-element %s">%s</span>', $col, $value);
						}
					} 
					echo '</div><!-- lips-static-->';
				}
				echo '</div>';
				break;
			
			case 'text':
			default:
				$enabled_attr = "";
				if (! $enabled)
					$enabled_attr = 'disabled="disabled"';
				
		 		echo '<input class="regular-text' . $field_class . '" type="text" id="' . $id . '" name="' . $name . '" placeholder="' . $std . '" value="' . esc_attr( $options[$id] ) . '"' . $enabled_attr . ' />';
		 		break;
		 	
		}
		
		if ($has_meta) {
			echo sprintf('<div class="lips-meta-container %s-meta-container"><span id="%s" class="lips-meta %s-meta"></span></div>', $type, $id . "-meta", $type);
		}
		echo "</div>"; // settings-control
		echo '<div class="lips-description-container">';
		if ($print_desc && $desc != '') {
			echo sprintf('<span class="lips-description %s" id="%s-description">%s</span>', $class, $id, $desc);
		}
		echo str_repeat('</div>', 2); // lips-description-container, lips-settings-container
	}

	/**
	 * Construct a link for the template details, adds a fragment for each custom
	 * key present in the template. The template can contain additional query
	 * parameters too.
	 * 
	 */	
	protected function produceTemplateLink($value, $static) {
		$base = "{$value['base_uri']}";
		if (array_key_exists('query', $value) && !empty($value['query'])) {
			$cleared = array();
			foreach ($value['query'] as $partial_query) {
				$cleared[$partial_query['key']] = $partial_query['val'];
			}
			return add_query_arg($cleared, $base . "#" . urlencode($static));
		}
		
		return $base . "#" . urlencode($static);		
	}
	
	/**
	 * Produces a page preview link using the WordPress way, without a nonce
	 *  
	 * @attention: This is copied from code found on lines 1335 and 1336 in 
	 *  wp-admin/includes/post.php.
	 *  
	 */
	protected function producePreviewLinkFor($page_id) {
        return add_query_arg(array('preview' => 'true', 'preview_id' => $page_id), get_permalink($page_id));
	}
	
	/**
	 * Produces the label and selectable value for the create new page option
	 */
	protected function addNoPageSelectedOptionTo($option_value, $remaining_options) {
		return array($option_value => "< " . __("Select page ...") . " >") + $remaining_options;
	}
	
	/**
	 * Loads OAuth related settings. First page.
	 */
	protected function getOAuthSettings($available_pages) {
		$options = get_option(SETTINGS_ID);
		$section = "li";
		$is_authorized = false;
		

		try {
			$oauth = LinkedInProfileSyncOAuth::fromTokenStore($this->tokenstore, false);
			$never_synced_class = "lips-identified-never-synced";
			$update_option_class = $this->has_fetched_profile ? "" : $never_synced_class;
			
			$update_profile = array(
				'title'   => __( 'Update Profile Page' ),
				'desc'    => __( 'Check to update the local profile page' ) . '<p class="' . $never_synced_class . ' lips-conditional-visible" id="lips-speech-copy">' . __('Almost ready to synchronize your profile. Check the <em>Update Profile Page</em> checkbox from this tab, select your profile page from the <em>Page Settings</em> tab and click <em>Save Settings</em>') . '</p>',
				'std'     => 0,
				'type'    => 'checkbox',
				'section' => $section,
				'enabled' => count($available_pages) > 0,
				'class'   => $update_option_class,
			);
			
			if (0 == count($available_pages)) {
				$update_profile['value'] = 0;
			}
			
			$this->settings['update_profile'] = $update_profile;
			
			$this->settings['profile_source'] = array(
				'depends' => 'update_profile',
				'type'    => 'radio',
				'title'   => __('Source'),
				'desc'    => __('Select the data the tool should operate on'),
				'section' => $section,
			);
		
			$profile_sources["li_profile"] = __("Connect to LinkedIn&reg; and download profile data.");
	
			if ($this->gotStoredProfile()) {
				$all_stored_profiles = get_user_meta($this->current_user->ID, LIPS_USER_META_PROFILE, true);
				$stored_profile = $all_stored_profiles[$all_stored_profiles['last']];
				$languages = LinkedInI18N::getLanguages(); 
				$profile_sources["local_profile"] = sprintf("%s %s %s %s", __("Use profile data saved after the last update. Last downloaded profile was"), ucwords($languages[$all_stored_profiles['last']]), __("containing changes up to"), date_i18n(get_option('date_format') . " " . get_option('time_format'), $stored_profile['lastModifiedTimestamp']*0.001));
			} 
			else {
				$this->settings['profile_source']['value'] = "li_profile"; 
			}
			
			$this->settings['profile_source']['choices'] = $profile_sources;
			
			$this->settings['profile_lang'] = array(
				'title' => __('Preferred Profile Language'),
				'desc'  => __('Select the <strong>preferred</strong> profile language. Gets the standard profile when a profile in the selected language does not exist'),
				'type'  => 'select',
				'section' => $section,
				'choices' => LinkedInI18N::getLanguages(),
				'section' => $section,
				'depends' => 'update_profile',
			);
		}
		catch (IdentificationMissingException $e) {
			$this->settings['oauth_key'] = array(
				'title'   => __( 'OAuth Token' ),
				'desc'    => sprintf("%s %s, %s", __( 'Visit the'), '<a href="' . LinkedInProfileSyncOAuth::getContentProviderUrl() . '" class="lips-ext-ref" target="linkedindeveloper" onclick="window.open(\'' . LinkedInProfileSyncOAuth::getContentProviderUrl() . '\', \'LinkedIn\', \'status=1,location=1,resizable=1,width=800,height=600\'); return false">LinkedIn developer page</a>', __('provide details and paste the API Key here.')),
				'type'    => 'text',
				'std'     => '',
				'section' => $section,
				'required'=> true,
			);		
		
			$this->settings['oauth_secret'] = array(
				'title'   => __( 'OAuth Secret' ),
				'desc'    => __( 'Paste the Secret Key here.' ),
				'type'    => 'password',
				'std'     => '',
				'section' => $section,
				'required'=> true,
			);
		}
		
		$ignored_notifications = get_user_meta($this->current_user->ID, LIPS_USER_META_IGNORE, true); 
		if (is_array($ignored_notifications) && count($ignored_notifications) > 0) {
			$this->settings['reset_hidden_notification'] = array(
				'title'   => __( 'Reset Hidden Notifications' ),
				'desc'    => __( 'Check to show me <strong>all</strong> notification messages again' ),
				'type'    => 'checkbox',
				'std'     => 0,
				'section' => $section,
			);
		}
	}

	/**
	 * Loads specific settings for the Page tab
	 * 
	 */
	protected function getPageSettings($available_pages) {
		$section = "pat";
		
		$this->settings['profile_page'] = array(
			'title'   => __( 'Profile Page' ),
			'desc'    => __( 'Select an existing page or' ) . ' <a href="#" class="lips-new-page" onclick="handleRelates(\'lips-profile-page-box\')">' . __('create a new page') . '</a>',
			'std'     => '',
			'type'    => 'select',
			'has_meta'=> false,
			'choices' => $this->addNoPageSelectedOptionTo($this->jquery_no_page_selected['page'], $available_pages),
			'section' => $section,
			'required'=> true,
			'class'   => 'page-selector',
			'enabled' => count($available_pages) > 0,
		);
		
		$this->settings['page_draft'] = array(
			'title'   => __( 'Change Page Status' ),
			'desc'    => __( 'Check to change status to <strong>draft</strong> after profile synchronization' ),
			'type'    => 'checkbox',
			'std'     => 0,
			'section' => $section,
			'previous'=> 'custom_page_template',
		);

		$available_templates = null;
		$meta_manager = new LinkedInProfileSyncMetadataManager('page');
		$rows = array();
		foreach ($meta_manager as $value) {
			$hash = $value['selector'];
			
			if (!empty($value['sample_uri'])) {
				$this->jquery_sample_page[$hash] = $value['sample_uri'];
			}			
			$available_templates[$hash] = $value['user_friendly_description'];
			$stored_values = $meta_manager->getValues($hash);
			if (! is_array($value['statics'])) {
				$value['statics'] = array();
			}
			foreach ($value['statics'] as $static) {
				$html_id =  $hash . $static;
				$static_value = "";
				if (is_array($stored_values) && array_key_exists($static, $stored_values)) {
					$static_value = $stored_values[$static];
				}
				$rows[] = array(
					"style" => "diplay:none;",
					"cols" => array("key" => $static, "value" => $static_value, "purpose" => __("Help")),
					"key" => array("type" => "label", "for" => $html_id,),
					"value" => array("type" => "input", "id" => $html_id, "name" => SETTINGS_ID . '[' . $hash . '][' . $static .']',),
					"purpose" => array("type" => "a", "href" => empty($value['base_uri']) ? null : $this->produceTemplateLink($value, $static), "target" => 'lips_purpose'),
					"class" => $hash,
				);
			}
			
			asort($available_templates, SORT_LOCALE_STRING);

			$this->settings['statics'] = array(
				'title' => __("Static Template Variables"),
				'desc'  => __('<strong>%tpl</strong> uses static variables, which need a value before the template is being processed.'),
				'type'  => 'bundle',
				'section' => $section,
				'previous' => 'installed_page_template',
				'choices' => $rows,
				'depends' => 'statics_container',
				'class' => 'lips_static',
			);
			$this->jquery_static_message = $this->settings['statics']['desc'];
		}

		$available_templates['custom'] = __('Create your own template');

		$this->settings['installed_page_template'] = array(
			'title'   => __( 'Installed Page Templates' ),
			'desc'    => sprintf('%s <em>%s</em> %s <a class="lips-ext-ref" href="http://www.smarty.net/">Smarty</a> %s', __( 'Select an installed template or select'), __('Create your own template'), __('to create a'), __('template')),
			'std'     => 0,
			'type'    => 'select',
			'section' => $section,
			'choices' => $available_templates,
			'previous'=> array_key_exists('create_new_page', $this->settings) ? 'create_new_page' : 'profile_page', 
			'required' => true,
			'has_meta' => true,
		);

		$this->settings['custom_page_template'] = array(
			'desc'    => __( 'Set the <a class="lips-ext-ref" href="http://www.smarty.net/">Smarty</a> template to use when constructing your profile page.' ),
			'type'    => 'textarea',
			'section' => $section,
			'syntax'  => 'smarty',
			'class'   => 'custom_page_template',
			'title'   => __('Custom Page Template'),
			'depends' => 'custom_page_template',
		);
	}
	
	/**
	 * Loads settings for the post section
	 * 
	 */
	protected function getPostSettings() {
		$section = "pot";

		$this->settings['have_posts'] = array(
			'title'   => __( 'Create Posts' ),
			'desc'    => __( 'Check to maintain a post for each position from your profile' ),
			'type'    => 'checkbox',
			'section' => $section,
		);

		$this->settings['post_category'] = array(
			'depends' => 'has_posts',
			'title'   => __( 'Post Category' ),
			'desc'    => __( "Provide post category name. Separate multiple categories using a comma" ),
			'std'     => LinkedInProfileSyncConvenience::getCategoryName(get_option('default_category')),
			'type'    => 'text',
			'section' => $section,
		);

		$this->settings['title_template'] = array(
			'depends' => 'has_posts',
			'title'   => __( 'Post Title Template' ),
			'desc'    => sprintf('%s <a href="http://www.smarty.net/" class="lips-ext-ref">Smarty</a> %s', __( 'Provide'), __('template for the post title')),
			'std'     => LIPS_DEFAULT_POST_TITLE_TEMPLATE,
			'type'    => 'text',
			'section' => $section,
			'syntax'  => 'smarty',
		);
		
		// Add the pre-installed templates
		$available_templates = array();
		$meta_manager = new LinkedInProfileSyncMetadataManager('post');
		foreach ($meta_manager as $value) {
			$hash = $value['selector'];
			if (!empty($value['sample_uri'])) {
				$this->jquery_sample_post[$hash] = $value['sample_uri'];
			}			
			$available_templates[$hash] = $value['user_friendly_description'];
		}

		$option = array();
		$option['post_use_summary'] = __("Don't use any template, just use position summary");
		$option['post_use_custom_template'] = __("Create your own template");

		if (count($available_templates) > 0) {
			$option['post_use_installed_template'] = __("Use installed post template");
			asort($available_templates, SORT_LOCALE_STRING);
			$this->settings['installed_post_template'] = array(
				'depends' => 'has_posts post_use_installed_template',
				'type' => 'select',
				'choices' => $available_templates,
				'title' => __('Installed Post Template'),
				'desc' => __('Select an installed post template'),
				'class' => 'post_use_installed_template',
				'previous' => 'post_template',
				'section' => $section,
			);
		}
		
		$this->settings['post_template'] = array(
			'depends' => 'has_posts',
			'title' => __('Post Content'),
			'desc' => __('Select the method to maintain post content'),
			'type' => 'radio',
			'section' => $section,
			'choices' => $option,
			'std' => 'post_use_summary',
			'previous' => 'title_template',
		);

		$this->settings['custom_post_template'] = array(
			'depends' => 'has_posts custom_post_template',
			'title'   => __( 'Custom Post Template' ),
			'desc'    => sprintf('%s <a href="http://www.smarty.net/" class="lips-ext-ref">Smarty</a> %s', __( 'Provide the'), __('template to construct the post contents')),
			'type'    => 'textarea',
			'section' => $section,
			'syntax'  => 'smarty',
			'class'   => 'custom_post_template',
		);
	}
	
	/**
	 * Adds settings for the development section
	 */
	protected function getDevelopmentSettings($available_pages) {
		$section = 'dev';

		$this->settings['keep_local_copy'] = array(
			'desc'   => __( 'Save your LinkedIn profile to this WordPress&trade; user account. Use this when you want to try different page templates' ),
			'title'    => __( 'Keep Local Copy' ),
			'type'    => 'checkbox',
			'std'     => 0,
			'section' => $section,
		);
		
		$this->settings['enable_smarty_reporting'] = array(
			'desc'   => __( 'Enable <a class="lips-ext-ref" href="http://smarty.net">Smarty</a> error reporting. Enabling this may add content to your profile page and cause additional logging' ),
			'title'    => __( 'Enable Smarty Error Reporting' ),
			'type'    => 'checkbox',
			'std'     => 0,
			'section' => $section,
		);
		
		$enabled = count($available_pages) >= 1 && count($this->settings['profile_page']['choices']) > 2;
		
		$enable_profile_data_debug = array(
			'title'   => __( 'Debug Data On-a-Page' ),
			'desc'    => __( 'Check to create or overwrite a page showing profile data' ),
			'type'    => 'checkbox',
			'std'     => 0,
			'section' => $section,
			'enabled'=> $enabled,
		);
		
		if (! $enabled) {
			$enable_profile_data_debug['value'] = 0;
		}

		$this->settings['enable_profile_data_debug'] = $enable_profile_data_debug;
		
		$this->settings['profile_debug_data_page'] = array(
			'title'   => __( 'Debug Data On-a-Page Title' ),
			'desc'    => __( 'Title of the page displaying debug profile data. This page will have the <strong>private</strong> status afterwards, so it will not be world-visible on your site. ' ) . ' <a href="#" class="lips-new-page" onclick="handleRelates(\'lips-debug-page-box\')">' . __('Create a new debug page') . '</a>',
			'type'    => 'select',
			'has_meta'=> false,
			'choices' => $this->addNoPageSelectedOptionTo($this->jquery_no_page_selected["dbg"], $available_pages),				
			'section' => $section,
			'depends' => 'has_profile_debug',
			'class'   => 'page-selector',
		);

		$this->settings['profile_debug_use_geshi'] = array(
			'title'   => __( 'Formatted Data On-a-Page'),
			'desc'    => sprintf('%s <a class="lips-ext-ref" href="http://qbnz.com/highlighter/">GeSHi</a> %s', __( 'Check to create a'), __('formatted page')),
			'type'    => 'checkbox',
			'std'     => 1,
			'section' => $section,
			'depends' => 'has_profile_debug',
		);
	}
	
	/**
	 * Settings and defaults
	 * 
	 * @since 1.0
	 */
	public function get_settings() {
		if (!extension_loaded('oauth')) {
			$this->jquery_error_details['lips_no_oauth'] = array(__('OAuth PECL extension missing'), __('<p>The PHP extension <strong>OAuth</strong> is not available and is absolutely essential. Check to see if the module is installed and make sure the extension is loaded.</p>'));
		}
		else {
			$available_pages = $this->getPagesFor('profile');		
			if (0 == count($available_pages)) {
				$this->jquery_error_details['lips_no_page'] = array(__('No page available'), __('There are no available pages. This tool needs one to write the profile to.<br/><br/>Create a page and retry.'));
			}
			else {
				$this->getOAuthSettings($available_pages);
				$this->getPageSettings($available_pages);
				$this->getPostSettings();
				$this->getDevelopmentSettings($this->getPagesFor('dev_profile'));
			}
		}
	}
	
	/**
	 * Returns an array of pages which can be used by this plugin. 
	 * 
	 * @param string $page_usage Intention of this page. Default: 'profile'
	 * 
	 * @throws RequestedPageTypeNotSupportedException The value of $page_usage
	 *   is not recognized. Specify either 'profile' or 'dev_profile'
	 */
	protected function getPagesFor($page_usage = 'profile') {
		$usage_types = array_values($this->jquery_page_usage_types);
		if (! in_array($page_usage, $usage_types)) {
			throw new RequestedPageTypeNotSupportedException($page_usage);
		}
		
		$query_has_meta = true;
		
		$filter = array(
			'post_status' => array_keys(get_page_statuses()),
			'meta_key' => LIPS_PAGE_META_AVAILABLE,
			'meta_value' => $page_usage,
		);

		if ('page' == get_option('show_on_front')) {
			// Get the id of the posts page, and use this one in a not-in query
			$filter['exclude'] = get_option('page_for_posts');
		}
		
		if (! current_user_can('administrator')) {
			$filter['authors'] = $this->current_user->ID;
		}
		
		$pages = get_pages($filter);
		if (0 == count($pages)) {
			unset($filter['meta_key']);
			unset($filter['meta_value']);
			$query_has_meta = false;
			$pages = get_pages($filter);
		}
		
		foreach ($pages as $page) {
			$add_page = true;
			// Don't include pages that have metadata, and which metadata value describes another type.
			// For instance: don't include pages which are used for the dev_profile when pages used
			// for the profile are being requested.
			if (! $query_has_meta) {
				$page_meta = get_post_meta($page->ID, LIPS_PAGE_META_AVAILABLE, true);
				if (!empty($page_meta) && in_array($page_meta, array_diff($usage_types, array($page_usage)))) {
					$add_page = false;
				}
			}
			if ($add_page) {
				$available_pages[$page->ID] = $page->post_title;
			}
		}
		return $available_pages;
	}
	
	/**
	 * Gets the settings (comes with the framework), and orders them.
	 */
	public function getOrderedSettings() {
		// Get settings only when this thing is not posted back
		$this->get_settings();

		// Apply sort order -- an item will be shown right after the member identified with the value of 'previous'
		// set to the name of that element
		$list = new LinkedList();
		
		$delayed_setting = $this->settings;
		$error_count = 0;
		do {
			$settings = $delayed_setting;
			$previous_error_count = $error_count;
			$error_count = 0; 
			foreach ($settings as $id => $setting ) {
				// See if there is a previous target
				$previous = null;
				if (array_key_exists('previous', $setting))
					$previous = $setting['previous'];
				$node_data = array('id' => $id, 'setting' => $setting);
				if ($previous == null)
					$list->add($node_data);
				else {
					try {
						$list->addAfter($previous, $node_data, array($this, 'itemComparer'));	
					}
					catch (ParentNodeNotFoundException $e) {
						$error_count++;
						$delayed_setting[$id] = $setting; 
					} 
				}
			}
			if ($error_count > 0 && $error_count == $previous_error_count)
				throw new UnableToResolveSortOrderDependency();
		} while (0 != $error_count);
		
		return $list->toAssociativeArray(array($this, 'itemConverter'));
	}

	/**
	 * Compares whether the key of the data is present in $full_data.
	 * 
	 * This is a callback for the LinkedList methods 
	 * 
	 * @param string $key The key being sought
	 * @param array $full_data The data used in this node.
	 * 
	 * @return true The key of this item matches the key being sought.
	 * @return false The key does not match.
	 */	
	public function itemComparer($key, $full_data) {
		return 0 == strcmp($full_data['id'], $key);
	}
	
	/**
	 * Converts full data to an associative array.   
	 *
	 * This is a callback for the LinkedList methods 
	 * 
	 * @param string $key The key being sought
	 * @param array $full_data The data used in this node.
	 * 
	 * @return array: first position is the key to use, next is the entire
	 *  array
	 */
	public function itemConverter($full_data) {
		return array($full_data['id'], $full_data['setting']);
	}
	
	/**
	 * Checks to see if the plugin is called with user-configured values or
	 * an ajax callback is awaiting response.
	 * 
	 */
	protected function isPosting() {
		$option_name = 'option_page';
		$ajax_action = 'action';
		return !empty($_POST) && ((array_key_exists($option_name, $_POST) && SETTINGS_ID == $_POST[$option_name]) || (array_key_exists($ajax_action, $_POST) && LIPS_OPTIONS_PAGE == $_POST[$ajax_action]));
	}
	
	/**
	 * Determines if the page being displayed is the options page for this
	 * plugin. 
	 */
	protected function isDisplayingOptionsPage() {
		global $pagenow;
		return !empty($_GET) && $_GET['page'] == LIPS_OPTIONS_PAGE && $pagenow == LIPS_PARENT_PAGE;
	}
	
	/**
	 * Resets the OAuth details, but only when called from the WordPress LiPS page.
	 */
	public function handleOAuthReset() {
		if (array_key_exists('action', $_GET) && $_GET['action'] == 'reset') {
			$lips_url = admin_url($this->getToolUrl());
			$permitted_referers = array($lips_url, add_query_arg("settings-updated", "true", $lips_url));
			if (is_admin() && in_array($_SERVER['HTTP_REFERER'], $permitted_referers)) {
				$this->tokenstore->expire(true, true);
				header('Location: ' . add_query_arg("settings-updated", "true", $lips_url));
				exit();
			}
		}
	}
	
	/**
	 * Hides notification by adding them to the usermeta of the user currently 
	 * administrating this WordPress.
	 * 
	 */
	public function handleHideNotification() {
		if (is_admin() && array_key_exists('action', $_GET) && $_GET['action'] == 'hide') {
			if (! empty($_GET['id'])) {
				$hidden_notifications = get_user_meta($this->current_user->ID, LIPS_USER_META_IGNORE, true);
				foreach (explode(",", $_GET['id']) as $hidden) {
					$hidden_notifications[$hidden] = true;
				}
				update_user_meta($this->current_user->ID, LIPS_USER_META_IGNORE, $hidden_notifications);
			}
			header('Location: '.$_SERVER['HTTP_REFERER']);
			exit();
		}
	}

	/**
	 * Initialize settings to their default values
	 * 
	 * @since 1.0
	 */
	public function initialize_settings() {
		
		$default_settings = array();
		foreach ( $this->settings as $id => $setting ) {
			if ( $setting['type'] != 'heading' )
				$default_settings[$id] = $setting['std'];
		}
		
		update_option(SETTINGS_ID, $default_settings );
	}
	
	/**
	* jQuery Tabs
	*
	* @since 1.0
	*/
	public function scripts() {
		wp_register_script('lips', plugins_url('js/lips.js', __FILE__), array('jquery', 'jquery-ui-tabs', 'jquery-ui-dialog'));
		wp_localize_script('lips', 'sections', array_flip($this->sections));
		wp_localize_script('lips', 'errors', $this->jquery_error_details);
		wp_localize_script('lips', 'autorun', $this->jquery_autorun);
		wp_localize_script('lips', 'statics', $this->jquery_static_message);
		wp_localize_script('lips', 'sample_links', $this->jquery_sample_page);
		wp_localize_script('lips', 'sample_link_text', __('Example'));
		wp_localize_script('lips', 'language_specific', $this->jquery_available_profiles);
		wp_localize_script('lips', 'page_usage', $this->jquery_page_usage_types);
		wp_localize_script('lips', 'no_page_selection', $this->jquery_no_page_selected);
		wp_localize_script('lips', 'dialog', array(
				"duplicate" => array("title" => __("Duplicate Page Use"), "body" => __("<p>You cannot use this page for both <em>Debug Data On-a-Page Title</em> and <em>Profile Page</em> purposes. Change the page.</p>")),
				"submit" => array("title" => __("Submitting"), "body" => __("<p>Downloading your profile from LinkedIn&reg; and running tasks on it. This may take some time...</p>")),
		));
		wp_enqueue_script('lips');
	}

	/**
	 * Adds the LiPS stylesheet to the page being rendered 
	 */
	public function styles() {
		wp_enqueue_style('wp-jquery-ui-dialog');
		wp_register_style('lips', plugins_url('css/lips.css', __FILE__), array());
		wp_register_style('lips-jquery-custom', plugins_url('css/jquery-ui-1.7.3.custom.css', __FILE__), array());
		wp_enqueue_style('lips');
		wp_enqueue_style('lips-jquery-custom');
	}
	
	/**
	* Validate settings, processes the settings provided by the caller and
	* copies them to a validated settings array. This array is returned as
	* a result. Keys that should not be stored with these settings are stored
	* somewhere else and the option and value are removed from the set.
	*
	* @since 1.0
	*/
	public function validate_settings($input) {
		$errcount = 0;
		$authorization_token = null;
		$validated = get_option(SETTINGS_ID);
		foreach ($input as $key => $value)
			$validated[$key] = $value;
				
		if (LinkedInProfileSyncConvenience::isInstalledPageTemplate($input)) {
			$mm = new LinkedInProfileSyncMetadataManager();
			$mm->setValues($input['installed_page_template'], $input[$input['installed_page_template']]);
			unset($validated[$input['installed_page_template']]);
		}
	
		// Remove keys from validated that are not maintained by this piece of code
		foreach (array("oauth_key", "oauth_secret", "pin") as $key)
			unset($validated[$key]);
		
		foreach ($this->checkboxes as $key)
			$validated[$key] = array_key_exists($key, $input) && "1" == $input[$key] ? 1 : 0;
			
		// Check authorization
		if (array_key_exists("oauth_key", $input) && array_key_exists("oauth_secret", $input)) {
			try {
				$id = new OAuthIdentificationToken($input["oauth_key"], $input["oauth_secret"]);
				$this->tokenstore->set($id, true);
			}
			catch (OAuthTokenExceptionBase $e) {
				add_settings_error("oauth_key", "invalid_value_0", __("Unable to identify application") . ": " . $e->getMessage());
				$errcount++;
			}
		}

		if (array_key_exists('pin', $input) && trim($input['pin']) != "") {
			// The thing is attempted to be authenticated, so it needs a Identification or 
			// another auth_req thing.
			try {
				$oauth = LinkedInProfileSyncOAuth::fromTokenStore($this->tokenstore, false);
				$authorization_token = $oauth->authorize(trim($input["pin"]), array($this, "setOAuthError"), array("pin" => $input["pin"], "oauth" => $oauth, "class" => "auth"));
				if (null != $authorization_token) {
					$this->tokenstore->set($authorization_token);
				}
				else {
					$errcount++;
				}
			}
			catch (OAuthException $e) {
				$errcount++;
			}
		}
		
		$this->handlePreTemplateCleanup();
		
		$do_profile_update = array_key_exists('update_profile', $input) && 1 == $input['update_profile'];
		
		if ($do_profile_update) {
			if ($validated["profile_page"] == $this->jquery_no_page_selected["page"]) {
				$errcount++;
				add_settings_error("profile_page", "profile_page_1", __("The profile page was not yet selected. Select a profile page from the <em>" . $this->sections[$this->settings["profile_page"]["section"]] . "</em> tab"));
			}
		}

		// See which template to use. Assume installed ones have been validated
		$templates = array();
		if (array_key_exists('have_posts', $input) && 1 == $input['have_posts'] && 'post_use_summary' != $input['post_template']) {
			$templates[] = array("template" => LinkedInProfileSyncConvenience::getPostTemplate($input), "required" => true, "callback_arg" => "post", "element" => $this->settings["custom_post_template"]);
		}
		$templates[] = array("template" => LinkedInProfileSyncConvenience::getPageTemplate($input), "required" => $do_profile_update, "callback_arg" => "page", "element" => $this->settings["custom_page_template"]);
		$templates[] = array("template" => $input["title_template"], "required" => false, "callback_arg" => "title", "element" => $this->settings["title_template"]);

		foreach ($templates as $validator) {
			if (empty($validator["template"])) {
				if ($validator["required"]) {
					$title = $validator["element"]["title"];
					add_settings_error($title, "template_validation_1", "A value for <em>" . $this->sections[$validator["element"]["section"]] . " -> " . $title . "</em> is required.");
					$errcount++;
				}
			}
			else {
				// Not empty validate content
				$smarty = new LinkedInProfileSyncSmarty(array('is_validation' => true));
				if (! $smarty->try_fetch($validator["template"], array($this, "setSmartyError"), $validator["callback_arg"])) {
					unset($validated[$validator["callback_arg"] . "_template"]);
					$errcount++;
				}
			}
		}
		
		if (!empty($input["post_category"])) {
			unset($validated["post_category"]); // Overwrite with the category id
			$validated["post_category"] = array_map('trim', explode(",", $input["post_category"]));
			foreach ($validated["post_category"] as $cat) {
				wp_create_category($cat);
			}
		}
		
		if (1 == $validated["enable_profile_data_debug"]) {
			// Now this page title must not match the profile page...
			if ($validated["profile_debug_data_page"] == $validated["profile_page"]) {
				$errcount++;
				add_settings_error("profile_debug_data_page", "debug_1", sprintf("Cannot use page %s for Debug Data-On-a-Page because this is your Profile page. Disable Debug Data On-a-Page or select another page to continue", get_the_title($validated["profile_debug_data_page"])));
				unset($validated["profile_debug_data_page"]);
			}
			else if ($validated["profile_debug_data_page"] == $this->jquery_no_page_selected["dbg"]) {
				$errcount++;
				add_settings_error("profile_debug_data_page", "debug_2", __("The Debug Data-On-a-Page was not yet selected. Select a page from the <em>" . $this->sections[$this->settings["profile_debug_data_page"]["section"]] ."</em> tab"));
			}
			else {
				$this->debug_page_id = $validated["profile_debug_data_page"];
				$this->debug_page_use_geshi = array_key_exists("profile_debug_use_geshi", $validated) && 1 == $validated["profile_debug_use_geshi"];
				add_filter(LIPS_PROFILE_PRE_TEMPLATE_FILTER, array($this, 'saveFormattedDebugPostFilter'), 42);
			}
		}
		
		if (1 == $validated["reset_hidden_notification"]) {
			delete_user_meta($this->current_user->ID, LIPS_USER_META_IGNORE);
			unset($validated["reset_hidden_notification"]);
		}
		
		if (1 == $validated["keep_local_copy"]) {
			if ('li_profile' == $validated["profile_source"]) {
				add_filter(LIPS_PROFILE_PRE_TEMPLATE_FILTER, array($this, 'saveLocalProfileContentFilter'), 41, 2);
			}
		}
		else {
			// Option is not present when the thing is disabled -- or when the thing has no value.
			delete_user_meta($this->current_user->ID, LIPS_USER_META_PROFILE);
		}

		if ($do_profile_update && 0 == $errcount) {
			// Remove the article author from this thing when the user is not admin
			$this->performProfileUpdate(&$validated);
		}
		
		if (null != $authorization_token) {
			$oauth = LinkedInProfileSyncOAuth::fromTokenStore($this->tokenstore, false);			
			$oauth->revoke();
		}
		$this->tokenstore->expire(false, true);

		return $validated;
	}
	
	/**
	 * Clean global variables. Prevents data leaking to the template engine.
	 */
	protected function handlePreTemplateCleanup() {
		foreach (array("pin") as $key) {
			unset($_POST[$key]);
		}
	}
	
	/**
	 * Called when the profile has been saved and it's values were ok
	 */
	public function performProfileUpdate($config) {
		$profile_source = $config["profile_source"];
		if ($profile_source == "li_profile") {
			$sync = new LinkedInProfileSyncer($config, $this->tokenstore);
		}
		else if ($profile_source == "local_profile") {
			$sync = new LinkedInProfileSyncer($config, null);
		}

		$timezone = ini_get('date.timezone');
		if (!empty($timezone) && false !== timezone_open($timezone)) {
			date_default_timezone_set($timezone);
		}
		$sync->updateLocalProfile();
		$errors = $sync->getErrors();
		
		if (null == $errors) {
			$profile_page_id = $config["profile_page"];
			add_settings_error("update_profile", "profile_sync", 'Updated page <a href="'. $this->producePreviewLinkFor($profile_page_id) . '" target="lips-preview">' . get_the_title($profile_page_id) . '</a> and saved settings', "updated");
			$sync_meta = get_user_meta($this->current_user->ID, LIPS_USER_META_LAST_SYNC, true);
			if (! is_array($sync_meta)) {
				$sync_meta = array();
			}
			$sync_meta[$profile_source] = time();
			$sync_meta['last'] = $profile_source;
			update_user_meta($this->current_user->ID, LIPS_USER_META_LAST_SYNC, $sync_meta);
		}
		else {
			$message = "Encountered errors while synchronizing your LinkedIn profile:<br/>";
			$index = 1;
			foreach ($errors as $e) {
				$message .= sprintf("#%.2d | %s<br/>", $index++, $e);
			}
			add_settings_error("update_profile", "profile_sync_1", $message);
		}
	}
	
	/**
	 * Adds a settings error with the smarty specific error message
	 */
	public function setSmartyError($callback_specific, $e) {
		$options = get_settings(SETTINGS_ID);
		if (LinkedInProfileSyncConvenience::isInstalledPageTemplate($options)) {
			$mm = new LinkedInProfileSyncMetadataManager();
			$meta = $mm->find($options[LinkedInProfileSyncConvenience::getPageTemplateConfigKey()]);
			$message = sprintf('%s "%s"', __("Unable to use Smarty template"), $meta['user_friendly_description']);
		} 
		else {
			$message = __("Unable to use custom Smarty template");
		}
		add_settings_error($callback_specific, "template_validation_2", sprintf("%s:<br/>%s", $message, $e->getMessage()));
	}
	
	/**
	 * Adds a settings error with the OAuth exception message.
	 */
	public function setOAuthError($callback_specific, $e) {
		$message = '';
		if (is_array($callback_specific)) {
			if (0 == strcmp('auth', $callback_specific['class'])) {
				$message = sprintf("Unable to authorize the plugin at LinkedIn");
			}
			// ["lastResponse"]=> string(98) "oauth_problem=additional_authorization_required&oauth_problem_advice=verifier%20does%20not%20match"
			$oauth = $callback_specific['oauth'];
			add_settings_error($callback_specific, "oauth_authorization_1", $message . "<br/>" . $oauth->getLastResponse());	
		}
		else if (null == $callback_specific) {
			$this->auth_request_error_message = $e->getMessage();
		}
	}
	
	/**
	 * Checks to see if a notification is disabled
	 */
	protected function skipIgnoredNotification($message, $property_id, $wrap_in_link = true) {
		// See if this thing is in the ignored filter, if so, add it
		$ignore = get_user_meta($this->current_user->ID, LIPS_USER_META_IGNORE, true);
		if (! is_array($ignore) || ! array_key_exists($property_id, $ignore) || ! $ignore[$property_id]) {
			return $this->createNotificationMessage($message, $property_id, $wrap_in_link);
		}
		return "";
	}
	
	protected function createNotificationMessage($message, $property_id, $wrap_in_link = true) {
		$doc = new DOMDocument('1.0');
		$div = $doc->createElement('div');
		$div->setAttribute('id', 'notice');
		$div->setAttribute('class', 'updated fade');
		$text_container = $doc->createElement('p');
		if ($wrap_in_link) {
			$a = $doc->createElement("a", $message);
			$a->setAttribute('href', $this->getToolUrl());
			$child = $a;
		}
		else {
			$child = $doc->createTextNode($message);
		}

		$text_container->appendChild($child);
		
		if (! empty($property_id)) {
			$text_container->appendChild($doc->createElement("span", " | "));
			$ignore = $doc->createElement("a", __("Hide"));
			$ignore->setAttribute("href", add_query_arg(array("action" => "hide", "id" => $property_id), $this->getToolUrl()));
			$text_container->appendChild($ignore);
		}
		$div->appendChild($text_container);
		return $doc->saveXML($div);
	}
	
	/**
	 * Creates an url on which this page can be accessed.
	 */
	protected function getToolUrl() {
		return LIPS_PARENT_PAGE . "?" . "page=" . urlencode(LIPS_OPTIONS_PAGE);
	}
	
	/**
	 * Checks to see if a stored profile copy exists.
	 * 
	 * @return true A stored copy exists for this user.
	 */
	protected function gotStoredProfile() {
		return get_user_meta($this->current_user->ID, LIPS_USER_META_PROFILE, true);
	}
	
	/**
	 * Returns whether the user has ever synchronized the profile.
	 * 
	 */
	protected function hasSyncedProfile() {
		return is_array(get_user_meta($this->current_user->ID, LIPS_USER_META_LAST_SYNC, true));		
	}
	
	/*
	 * Here are the callbacks
	 */
	
	/**
	 * Disables the notification. There is no need to show the messages when the configuration
	 * page of the tool is displayed.
	 * 
	 */
	public function disable_notification() {
		remove_action('admin_notices', array($this, "add_notification"));
	}
	
/**
 * Methods handing the LIPS_PROFILE_FETCHED_FILTER filter
 */	
	
	/**
	 * Decodes the json formatted string to an associated array
	 */
	public function jsonStringToAssociativeArrayFilter($json_string) {
		return json_decode($json_string, true);
	}
	
	/**
	 * Updates the user-meta with the LinkedIn Account id. 
	 */
	public function updateLinkedInAccountIdFilter($json) {
		update_user_meta($this->current_user->ID, LIPS_META_ACCOUNT_ID, $json['id']);
		return $json;
	}
	
	/**
	 * Adds the user-selected language code and description to the data
	 * being passed to the template.
	 * 
	 * Language details are stored in the x_lips.profile_lang variable.
	 * 
	 * @example:
	 * array (
	 *       'code' => 'en-US',
	 *       'full' => 'English (American)',
	 * )
	 */
	public function addProfileLanguageFilter($json, $syncer) {
		$to_return = $json;
		$languages = LinkedInI18N::getLanguages();
		$selected_lang = $syncer->getProfileLanguage();
		$to_return['x_lips']['profile_lang'] = array("code" => $selected_lang, "full" => $languages[$selected_lang]);
		return $to_return;
	}
	
	/**
	 * Groups the positions by company
	 */
	public function groupPositionByCompanyFilter($json) {
		$to_return = $json;
		
		// Handle each position, grouping them by x_lips_positions
		foreach ($json['positions']['values'] as $position) {
			// Fetch details of this company
			$grouped_subset[$position['company']['name']][] = $position;
		}
		
		$to_return['x_lips']['positions'] = $grouped_subset;
		return $to_return;
	}
	
	/**
	 * Adds company details. The details are fetched from LinkedIn.
	 */
	public function addCompanyDetailsFilter($json) {
		$to_return = $json;
		$linkedin = LinkedInProfileSyncOAuth::fromTokenStore($this->tokenstore);
		$company_ids = null;
		
		foreach ($json['positions']['values'] as $position) {
			if (array_key_exists("id", $position['company']))
				$company_ids[] = $position['company']['id'];
		}
		
		if (null != $company_ids) {
			$company_details = json_decode($linkedin->fetchCompanyDetails($company_ids), true);
			foreach ($company_details['values'] as $company) {
				$to_return['x_lips']['company'][$company['name']] = $company;
			}
		}
		
		return $to_return;
	}
	
	/**
	 * Reads details of the recommendator and adds it to the x_lips array 
	 */
	public function addRecommendatorProfileLinkFilter($json) {
		$to_return = $json;
		$linkedin = LinkedInProfileSyncOAuth::fromTokenStore($this->tokenstore);
		foreach ($json['recommendationsReceived']['values'] as $recommendation) {
			$to_return['x_lips']['recommendation'][$recommendation['id']] = json_decode($linkedin->fetchUserProfile($recommendation['recommender']['id']), true);
		}
			
		return $to_return;
	}
	
/**
 * Methods handling the LIPS_PROFILE_PRE_TEMPLATE_FILTER filter
 */	
	/**
	 * Moves the variability from the template, by adding empty values for objects
	 * that don't exist, to prevent template errors from popping up.
	 */
	public function generifyTemplateDataFilter($json, $wpobj) {
		$new_positions = array();
		$to_return = $json;
		
		// Startdate and enddate of positions may be missing
		foreach ($to_return['positions']['values'] as $position) {
			$company = $position['company']['name'];
			if (! is_array($to_return['x_lips']['company']) || ! array_key_exists($company, $to_return['x_lips']['company'])) {
				$to_return['x_lips']['company'][$company]['websiteUrl'] = "";
			}
			foreach (array('startDate', 'endDate') as $milestone) {
				if (array_key_exists($milestone, $position)) {
					$month_year = array(
						'month' => array_key_exists('month', $position[$milestone]) ? $position[$milestone]['month'] : "",
						'year' => array_key_exists('year', $position[$milestone]) ? $position[$milestone]['year'] : "",
					);
				}
				else {
					$month_year = array('month' => "", 'year' => "");
				}
				$position[$milestone] = $month_year;
			}
			$new_positions[] = $position;	
		}
		$to_return['positions']['values'] = $new_positions;

		// Degree may be missing
		$new_educations = array();
		foreach ($to_return['educations']['values'] as $edu) {
			foreach (array("degree") as $key) {
				if (! array_key_exists($key, $edu)) {
					$edu[$key] = "";
				}
			}
			$new_educations[] = $edu;
		}
		$to_return['educations']['values'] = $new_educations;
		
		$modified_uri = array();
		foreach ($to_return['x_lips']['company'] as $company => $details) {
			// Parse uri, add http when missing
			$company_url = $details['websiteUrl'];
			$parsed_uri = parse_url($company_url);
			if (empty($company_url)) {
				$details['websiteUrl'] = "";
			}
			else if (! is_array($parsed_uri) || ! array_key_exists('scheme', $parsed_uri) || empty($parsed_uri['scheme'])) {
				$details['websiteUrl'] = 'http://' . $company_url;
			}
			$modified_uri[$company] = $details;
		}
		$to_return['x_lips']['company'] = $modified_uri;

		foreach (array('specialties') as $missing_key) {
			if (! array_key_exists($missing_key, $to_return)) {
				$to_return[$missing_key] = "";
			}
		}

		return $to_return;
	}

	/**
	 * Downloads the profile picture, and adds it to the metadata stored in 
	 * WordPress.
	 * 
	 */	
	public function keepCopyOfProfilePictureFilter($json) {
		$to_return = $json;
		$key = "pictureUrls";
		$local_picture_copy = "";
		
		$current_picture = get_user_meta($this->current_user->ID, LIPS_USER_META_PICTURE, true);
		$download_picture = empty($current_picture);
		
		if (! $download_picture) {
			$filter = array(
				"post_type" => 'attachment',
				"post_status" => 'any',
				"include" => array_values($current_picture),
			);
			$attachments = get_posts($filter);
			$download_picture = 0 == count($attachments);
			if (! $download_picture) {
				$flipped_meta = array_flip($current_picture);
				$local_picture_copy = array();
				foreach ($attachments as $attachment) {
					$local_picture_copy[$flipped_meta[$attachment->ID]] = wp_get_attachment_url($attachment->ID);	
				}
			}
		}

		if ($download_picture && array_key_exists($key, $json) && !empty($to_return[$key])) {
			$sizes = LinkedInProfileSyncOAuth::getProfilePictureSizes();
			$size_index = 0;
			add_action('add_attachment', array($this, 'profilePictureAdded'));
			$previous_dimensions = "";
			foreach ($json[$key]['values'] as $picture_url) {
				$image_meta = getimagesize($picture_url);
				$dimensions = sprintf("%dx%d", $image_meta[0], $image_meta[1]);
				if (empty($previous_dimensions) || $dimensions != $previous_dimensions) {
					$mimetype = $image_meta['mime'];
					$upload_details = wp_upload_dir();
					$path = $upload_details['basedir'] . '/lips/' . $dimensions . "_" . str_replace(" ", "-", trim($json['id'])) . "." . substr($mimetype, strpos($mimetype, "/") + 1);
					@mkdir(dirname($path), 0, true);
					file_put_contents($path, file_get_contents($picture_url));
					$picture_meta = array(
						"post_title" => sprintf("%s %s", $json["formattedName"], __("profile picture")),
						"post_content" => "",
						"post_status" => "private",
						"post_mime_type" => $mimetype,
					);
					$this->current_picture_size = $sizes[$size_index]; 
					$picture_id = wp_insert_attachment($picture_meta, $path);
					wp_update_attachment_metadata($picture_id, $path);
					$local_picture_copy[$this->current_picture_size] = wp_get_attachment_url($picture_id);
				}
				$size_index++;
			}
		}
		
		$this->current_picture_size = null;
		
		$to_return['x_lips']['picture_url'] = $local_picture_copy;
		
		return $to_return;
	}
	
	/**
	 * Creates a post for each position, allowing the caller to add more details to 
	 * the post afterwards.
	 */
	public function positionToPostFilter($json, $syncer) {
		$to_return = $json;
		$key = "has_post_uri";
		$this->post_uri = array();
		
		$have_post_uri = $syncer->shouldMaintainPostPerPosition();
		$to_return['x_lips'][$key] = $have_post_uri ;
		
		if ($have_post_uri) {
			// The save_post action stores existing pages, whose contents have been modified
			// and the update_post_metadata is called when a new page is being created (eg:
			// a new position is added)
			add_action('save_post', array(&$this, 'onPostSavedAction'));
			add_action('update_post_metadata', array(&$this, 'onPostMetaUpdatedAction'), 10, 4);

			foreach ($json['positions']['values'] as $position) {
				$syncer->toPost($position);
			}
			$to_return['x_lips']['uri'] = $this->post_uri;

			remove_action('save_post', array(&$this, 'onPostSavedAction'));
			remove_action('update_post_metadata', array(&$this, 'onPostMetaUpdatedAction'));
		}

		return $to_return;
	}
	
	/**
	 * Stores the data to a *private* page, allowing a developer to review 
	 * details.
	 */
	public function saveFormattedDebugPostFilter($json) {
		$to_return = $json;
		$page_filter = array(
			'include' => array($this->debug_page_id),
			'post_status' => array_keys(get_page_statuses()),
		);
		
		// Get the page, and overwrite the current data.
		$pages = get_pages($page_filter);
		
		$page_content = var_export($json, true);
		
		if ($this->debug_page_use_geshi) {
			if (! class_exists('GeSHi'))
				require_once('GeSHi/geshi.php'); 

			$geshi = new GeSHi($page_content, 'php');
			$content = $geshi->parse_code();
		}
		else {
			$content = $page_content;
		}
		
		if (1 == count($pages)) {
			// Add profile-page linked positions
			$target_page = $pages[0];
			$target_page->post_content = $content;
			$target_page->post_type = 'page';
			$target_page->post_status = 'private';
			wp_insert_post($target_page, false);
		}
		
		return $to_return;	
	}
	
	/**
	 * Stores the profile to the metadata linked to the user that started this
	 * page.
	 * 
	 * Profiles are stored in an array, key being the profile language id, not the 
	 * full language. The key for the Dutch profile is nl-NL, unless otherwise 
	 * specified in i18n.php.
	 */
	public function saveLocalProfileContentFilter($json, $syncer) {
		$cached_profile = get_user_meta($this->current_user->ID, LIPS_USER_META_PROFILE, true);
		if (! is_array($cached_profile)) {
			$cached_profile = array();
		}
		$language = $syncer->getProfileLanguage();
		$cached_profile[$language] = $json;
		$cached_profile['last'] = $language;
		update_user_meta($this->current_user->ID, LIPS_USER_META_PROFILE, $cached_profile);		
		return $json;
	}
	
/**
 * Methods handing the LIPS_PROFILE_UPDATED_ACTION action 
 */
	public function saveHiddenPostsList() {
		$ruthere = new LinkedInProfileSyncPostFilter();
		$ruthere->addPostsToFilter($this->posts_to_hide);
	}

	/**
	 * Called when the profile picture is being added to the local WordPress
	 * media box
	 */	
	public function profilePictureAdded($attachment_id) {
		$previous_picture = get_user_meta($this->current_user->ID, LIPS_USER_META_PICTURE, true);
		if (empty($previous_picture) || !is_array($previous_picture)) {
			$previous_picture = null;
			$current_picture = array();
		}
		else if (is_array($previous_picture)) {
			$current_picture = $previous_picture;
		}
		$current_picture[$this->current_picture_size] = $attachment_id;
		update_user_meta($this->current_user->ID, LIPS_USER_META_PICTURE, $current_picture);
	}
}

class UnableToResolveSortOrderDependency extends LipsException { }
class RequestedPageTypeNotSupportedException extends LipsException { }
class OldVersionOfSmartyException extends LipsException { }


if (function_exists('is_admin')) {
	// Called as a regular plugin
	if (is_admin()) {
		$lips_options = new LinkedInProfileSyncOptions();	
	}
	else {
		$lips_filter = new LinkedInProfileSyncPostFilter();	
	}
}

?>