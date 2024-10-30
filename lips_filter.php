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
 * $Id: lips_filter.php 566172 2012-07-01 19:56:32Z bastb $
 *
 * Filters the hidden posts from the BLOG page, or basically page,
 * allowing only the posts to be displayed
 */
 
class LinkedInProfileSyncPostFilter {
	protected static $shortcode = "lips_displaypostcontent";
	protected $posts_to_filter;
	protected $filtered_posts_page;
	
	public function __construct($filter_list = null) {
		if (null == $filter_list) {
			$this->posts_to_filter = get_option(LIPS_FILTER_LIST_OPTION);
			if (! is_array($this->posts_to_filter)) {
				$this->posts_to_filter = array();
			}
		}
		else {
			$this->posts_to_filter = $filter_list;
		}
		
		add_filter('the_content', array($this, 'addCustomCss'));
		add_action('pre_get_posts', array($this, 'filterPosts'));
		add_shortcode(LinkedInProfileSyncPostFilter::getShortCode(), array($this, 'handleShortCode'));
	}
	
	/**
	 * Saves the current list to the database 
	 */
	protected function saveFilterList() {
		update_option(LIPS_FILTER_LIST_OPTION, $this->posts_to_filter);
	}
	
	/**
	 * Updates the filter list, returning the current list.
	 */
	public function setFilterList($filter_list, $save = true) {
		$previous_list = $this->posts_to_filter;
		$this->posts_to_filter = $filter_list;
		
		if ($save)
			$this->saveFilterList();
		
		return $previous_list;
	}
	
	public function addPostsToFilter($filter_list, $save = true) {
		$previous_list = $this->posts_to_filter;
		if (is_array($filter_list)) {
			$this->posts_to_filter = array_unique(array_merge($previous_list, $filter_list), SORT_NUMERIC);
		}
		
		if ($save)
			$this->saveFilterList();
		
		return $previous_list;		
	}
	
	public function addCustomCss($content) {
		$page_id = get_the_ID();
		$meta = get_post_meta($page_id, LIPS_PAGE_META_CSS, true);
		if (!empty($meta)) {
			wp_register_style('lips-page', plugins_url('css/page/' . $meta, __FILE__), array());
			wp_enqueue_style('lips-page');
		}
		
		return $content;
	}
	
	/**
	 * Hides posts from the generic post overview page, which contains all the
	 * posts. The intention of this it to allow a surfer to see the post details
	 * through the profile page, not as a specific post.
	 * 
	 * Should not kick in when posts are being displayed on the admin page or
	 * when the posts are being displayed on a single page.
	 * 
	 */	
	public function filterPosts(&$wpquery) {
		// http://codex.wordpress.org/Option_Reference. When show_on_front is set
		// to 'page', the default page being displayed is a static page, in which
		// case is_home() returns true for the blog page 
		$front_page = get_option('show_on_front');
		
		if (('page' == $front_page && is_home()) || ('posts' == $front_page && is_front_page())) {
			$wpquery->query_vars['post__not_in'] = $this->posts_to_filter;
		}
	}
	
	public function handleShortCode($atts) {
		global $post;
		return get_post_meta($post->ID, LIPS_POST_META_CONTENT, true);
	}
	
	public static function getShortCode() {
		return self::$shortcode;
	}
	
	public static function updateFilterList() {
		// Now handle each post with a LinkedIn id
		$filter = array(
			'meta_key' => LIPS_POST_META_POSITION_ID,
			'category' => null,
			'post_status' => 'any',
		);
		
		$posts_to_filter = array();
		
		foreach (get_posts($filter) as $post) {
			$posts_to_filter[] = $post->ID;
		}
		
		$instance = new LinkedInProfileSyncPostFilter();
		$filters = $instance->setFilterList($posts_to_filter, true);
	}
}
?>
