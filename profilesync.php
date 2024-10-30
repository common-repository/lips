<?php
/**
 * 
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
 *
 * $Id: profilesync.php 566644 2012-07-02 20:03:27Z bastb $
 *
 */

require_once('exception.php');
require_once('convenience.php');

class LinkedInProfileSyncer {
	const TEMPLATE_TITLE = "t";
	const TEMPLATE_POST = "o";
	const TEMPLATE_PAGE = "p";
	
	protected $through_oauth = false;
	protected $oauth;
	protected $profile; /* linked in profile, as an associated array */
	protected $page_template = "";
	protected $post_template = "";
	protected $title_template = "";
	protected $installed_page_template = false;
	protected $page_template_hash = null;
	protected $has_post_template = false;
	protected $post_categories = array();
	protected $profile_page_id = null;
	protected $do_posts = true;
	protected $do_page_draft = true;
	protected $temp_failures = array(
		"making the request failed (Couldn't resolve host name)",
		"making the request failed (Peer certificate cannot be authenticated with known CA certificates)",
		"making the request failed (SSL connect error)",
		"making the request failed (Couldn't connect to server)",
		"Internal service error",
	);
	
	protected $title_template_error = false;
	protected $post_template_error = false;
	protected $page_template_error = false;
	protected $oauth_error = false;
	protected $errors = null;
	protected $enable_error_reporting;
	protected $use_public_profile = false;
	protected $profile_lang;
	protected $must_revoke_after = true;

	/**
	 * Initializes the instance, using newly provided or the currently 
	 * stored configuration
	 * 
	 * @param associative array $newconfig An array containing keywords and values. Uses the currently
	 *   stored configuration when there is one
	 */	
	public function __construct($newconfig = null, $tokenstore = null) {
		if ($tokenstore) {
			$this->oauth = LinkedInProfileSyncOAuth::fromTokenStore($tokenstore);
			$this->through_oauth = true;
		}
		
		$config = $newconfig;
		if (null == $config)
			$config = get_option(SETTINGS_ID);

		$this->page_template = LinkedInProfileSyncConvenience::getPageTemplate($config);
		$this->post_template = $config["post_template"];
		$this->title_template = $config["title_template"];
		$this->do_posts = 0 != $config["have_posts"];
		$this->do_page_draft = 0 != $config["page_draft"];
		$this->profile_page_id = intval($config["profile_page"]);
		$this->installed_page_template = LinkedInProfileSyncConvenience::isInstalledPageTemplate($config);
		$this->page_template_hash = $config["installed_page_template"];
		$this->enable_error_reporting = 1 == $config["enable_smarty_reporting"];
		$this->profile_lang = $config["profile_lang"];
		$this->has_post_template = $config["post_template"] != "post_use_summary";

		if ($this->do_posts) {
			$this->post_categories = array_map(array('LinkedInProfileSyncConvenience', 'getCategoryId'), $config["post_category"]);
			if ($this->has_post_template) {
				$this->post_template = LinkedInProfileSyncConvenience::getPostTemplate($config);
			}
		}

		if (empty($this->title_template)) {
			$this->title_template = LIPS_DEFAULT_POST_TITLE_TEMPLATE;
		}
			
		if (empty($this->profile_lang)) {
			$this->profile_lang = null;
		}
	}
	
	/**
	 * Checks to see if the error message is known as a temporary failure
	 */
	protected function isTemporaryProfileError($e) {
		return in_array($e->getMessage(), $this->temp_failures);
	}
	
	/**
	 * Adds variables for an installed template
	 * 
	 */
	protected function getTemplateVariables($additionals = array(), $is_validation = false) {
		$smarty_variables = array_merge(array('lips' => $this->profile, 'is_validation' => $is_validation), $additionals);
		
		if ($this->installed_page_template) {
			$variables = array();
			$mm = new LinkedInProfileSyncMetadataManager();
			$variables['statics'] = $mm->getValues($this->page_template_hash);
			$tpl_meta = $mm->find($this->page_template_hash);
			if (array_key_exists("constants", $tpl_meta) && !empty($tpl_meta["constants"])) {
				if (is_array($tpl_meta["constants"])) {
					$constants = array();
					foreach ($tpl_meta["constants"] as $constant) {
						$constants = array_merge($constants, $constant);						
					}
				} 
				else {
					$constants = $tpl_meta["constants"];
				}
				$variables["constants"] = $constants;
			}
			
			$smarty_variables = array_merge($smarty_variables, $variables);
		}
		
		return $smarty_variables;
	}

	/**
	 * Creates a new post of modifies an existing, to allow for more info about the position.
	 * 
	 * @throws AmbiguousPostException when more than one post is associated to the id of this
	 *  position.
	 * 
	 * @return string The permalink to the newly created or existing post.
	 */
	public function toPost($position) {
		// Filter options:
		// http://codex.wordpress.org/Template_Tags/get_posts
		$lang = null == $this->profile_lang ? "null" : $this->profile_lang;
		
		$filter = array(
			'meta_query' => array(
				array('key' => LIPS_POST_META_POSITION_ID, 'value' => $position['id']),
				array('key' => LIPS_POST_META_LANG, 'value' => $lang)
			),
			'category' => null,
			'post_status' => 'any',
		);
		$posts = get_posts($filter);
		$is_new_post = true;
		$number_of_posts = count($posts);

		if ($number_of_posts > 1) {
			throw new AmbiguousPostException(sprintf('%d posts matched filter "%s"', $number_of_posts, $filter['meta_value']));
		}	
		else if (1 == $number_of_posts) {
			$post = get_post($posts[0]->ID, "ARRAY_A");
			$is_new_post = false;
		}
		else if (0 == $number_of_posts) {
			if ($this->do_page_draft) {
				$post['post_status'] = 'draft';
			} 
			$post['post_type'] = 'post';
			$post['post_content'] = "[" . LinkedInProfileSyncPostFilter::getShortCode()  . "]";
		}

		$smarty_variables = $this->getTemplateVariables(array('position' => $position));
		$smarty = new LinkedInProfileSyncSmarty($smarty_variables, $this->enable_error_reporting);
		
		try {
			$post['post_title'] = $smarty->fetch($this->title_template);
		}
		catch (SmartyCompilerException $e) {
			$this->handleSmartyError(LinkedInProfileSyncer::TEMPLATE_TITLE, $e);
		}
		$post['post_category'] = $this->post_categories;

		if ($this->has_post_template) {
			try {
				$post_content = $smarty->fetch($this->post_template);
			}
			catch (SmartyCompilerException $e) {
				$this->handleSmartyError(LinkedInProfileSyncer::TEMPLATE_POST, $e);
			}
		}
		else {
			$post_content = $position["summary"];
		}
		
		// Update post and return the link
		// http://codex.wordpress.org/Function_Reference/wp_insert_post
		$post_id = wp_insert_post($post);
		
		if ($is_new_post) {
			foreach ($filter['meta_query'] as $query) {
				update_post_meta($post_id,  $query['key'], $query['value']);
			}
		}
		
		update_post_meta($post_id, LIPS_POST_META_CONTENT, nl2br($post_content));
	}

	/**
	 * Verification method, checks to see if a post per position must be created.
	 */
	public function shouldMaintainPostPerPosition() {
		return $this->do_posts;
	}
	
	public function getProfileLanguage() {
		return $this->profile_lang;
	}
	
	/**
	 * Updates the profile page. The profile page is the one page that has metadata
	 * with a key named "page:type" and value "profile". The current page status does 
	 * not really matter.
	 * 
	 * This method flips the page status to "draft" afterwards when configured to do so.
	 * 
	 * @throws ProfilePageNotFoundException when no page was found which matches the
	 *   metadata.
	 */
	protected function updateProfilePage() {
		// Create a bunch of position posts as we may add additional data to that.
		$page_filter = array(
			'include'     => array($this->profile_page_id),
			'post_status' => array_keys(get_page_statuses()),
		);
				
		// Get the page, and overwrite the current data.
		$pages = get_pages($page_filter);
	
		if (1 == count($pages)) {
			$smarty_variables = $this->getTemplateVariables();
			$smarty = new LinkedInProfileSyncSmarty($smarty_variables, $this->enable_error_reporting);
			try {
				$content = $smarty->fetch($this->page_template); 
			}
			catch (SmartyCompilerException $e) {
				$this->handleSmartyError(LinkedInProfileSyncer::TEMPLATE_PAGE, $e);
			}
			$target_page = $pages[0];
			$target_page->post_content = $content;
			$target_page->post_type = 'page';
			if ($this->do_page_draft)
				$target_page->post_status  = 'draft';
			
			$page_id = wp_insert_post($target_page, false);
			// Update page meta
			if ($this->installed_page_template) {
				$mm = new LinkedInProfileSyncMetadataManager();
				$meta = $mm->find($this->page_template_hash);
				$css_key = 'css';
				if (array_key_exists($css_key, $meta) && !empty($meta[$css_key])) {
					update_post_meta($page_id, LIPS_PAGE_META_CSS, $meta[$css_key]);
				} 
				else {
					delete_post_meta($page_id, LIPS_PAGE_META_CSS);
				}
			}	
			update_post_meta($page_id, LIPS_META_ACCOUNT_ID, $this->profile['id']);	
		}
		else {
			$page_count = count($pages);
			$reason = sprintf("%d applicable profile page%s found. Check page metadata.", $page_count, 1 == $page_count ? "" : "s");
			throw new ProfilePageNotFoundException($reason);
		}
	}
	
	/**
	 * Updates the local copy of the profile
	 */
	public function updateLocalProfile() {
		if ($this->through_oauth) {
			$profile = $this->oauth->fetchProfile($this->profile_lang, $this->use_public_profile, array($this, 'handleProfileError'), 'f');
			if (! $this->hasEncounteredError()) {
				$this->profile = apply_filters(
				    LIPS_PROFILE_FETCHED_FILTER, // filter name
				    $profile,
				    &$this 
				  );
				
				if (! is_array($this->profile))
					throw new FiltersMockedStuffUpException();
			}
		}
		else {
			$user = wp_get_current_user();
			$stored_profiles = get_user_meta($user->ID, LIPS_USER_META_PROFILE, true);
			$this->profile = $stored_profiles[$this->profile_lang];
		}
		
		if (! $this->hasEncounteredError()) {
			$this->profile = apply_filters(LIPS_PROFILE_PRE_TEMPLATE_FILTER, $this->profile, &$this);
			$this->updateProfilePage();
			do_action(LIPS_PROFILE_UPDATED_ACTION, $this->profile);
		}
	}
	
	/**
	 * Checks the standard troublesome error reason indicators and returns true
	 * when one of them is set to error.
	 * 
	 */
	public function hasEncounteredError() {
		return $this->title_template_error || $this->page_template_error || $this->post_template_error || $this->oauth_error;
	}
	
	/**
	 * Returns an array of strings with error descriptions or null when no failure
	 * was encountered.
	 * 
	 */
	public function getErrors() {
		if ($this->hasEncounteredError())
			return $this->errors;
		
		return null;
	}
	
	/**
	 * Offers the option to not revoke access afterwards
	 */
	public function setDontRevoke() {
		$this->must_revoke = false;
	}
	
	/** 
	 * Sets the OpenAuth error code, optionally flushing stored state.
	 */
	public function handleProfileError($specific, $e) {
		if (! $this->isTemporaryProfileError($e)) {
			if ($this->through_oauth) {
				$tokenstore = new WpUserMetaTokenStore();
				$tokenstore->expire(false, true);
				$this->oauth_error = true;
			}
			$this->errors[] = $this->oauth->getLastResponse();
		}
	}
	
	/**
	 * Used as a callback for the smarty templating system. The template
	 * probably needs some modification.
	 */
	public function handleSmartyError($specific, $e) {
		if ($specific == LinkedInProfileSyncer::TEMPLATE_TITLE)
			$this->title_template_error = true;
		else if ($specific == LinkedInProfileSyncer::TEMPLATE_POST)
			$this->post_template_error = true;
		else if ($specific == LinkedInProfileSyncer::TEMPLATE_PAGE)
			$this->page_template_error = true;
		
		$this->errors[] = $e->getMessage();
	}
}

/** 
 * Raised when the profile details could not be set
 */
class ProfilePageNotFoundException extends LipsException{ }
class FiltersMockedStuffUpException extends LipsException{ }
class AmbiguousPostException extends LipsException{ }

?>
