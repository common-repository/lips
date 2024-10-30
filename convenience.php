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
 * $Id: convenience.php 566644 2012-07-02 20:03:27Z bastb $
 *
 */

require_once('exception.php');

class LinkedInProfileSyncConvenience {
	protected static $installed_page_key = "installed_page_template";
	protected static $installed_post_key = "installed_post_template";

	/**
	 * Translates a category label to an id
	 * 
	 * @return null Unable to find a category by this id.
	 * @return int The id associated to category_label.
	 */
	public static function getCategoryId($category_label) {
		$term = get_term_by('name', sanitize_title($category_label), 'category');
		if (false == $term) 
			$id = null;
		else 
			$id = $term->term_id;
		
		return $id;
	}
	
	/**
	 * Returns the name of a category
	 */
	public static function getCategoryName($category_id) {
		$term = get_term_by('id', intval($category_id), 'category');
		if (false == $term) 
			$id = null;
		else 
			$id = $term->name;
		
		return $id;
	}
	
	/**
	 * Finds the metadata from the metadata manager and removes the metadata 
	 * from the template.
	 * 
	 * @throws CannotReadTemplateFileException Unable to read the file
	 */
	protected static function removeMetadata($meta_manager, $tpl_selector) {
		$meta = $meta_manager->find($tpl_selector);
		$to_return = @file_get_contents($meta['filepath']);
		if (false === $to_return)
			throw new CannotReadTemplateFileException($meta['filename']);
			
		// Remove the metadata from the template
		$before = substr($to_return, 0, $meta['loclen']['pos']);
		$after = substr($to_return, $meta['loclen']['len'] + $meta['loclen']['pos']);
		return trim($before . $after);
	} 
	
	/**
	 * @raise CannotReadTemplateFileException The file was present but could
	 * not be read. (file_get_contents returned FALSE)
	 */
	public static function getPageTemplate($config) {
		// Either a custom or an installed profile
		$to_return = null;
		
		if (self::isInstalledPageTemplate($config)) {
			$to_return = self::removeMetadata(new LinkedInProfileSyncMetadataManager('page'), $config[self::$installed_page_key]);
		}
		
		if (null == $to_return) {
			$to_return = $config['custom_page_template'];
		}
			
		return $to_return;
	}
	
	public static function getPostTemplate($config) {
		$to_return = null;
		
		if (self::isInstalledPostTemplate($config)) {
			$to_return = self::removeMetadata(new LinkedInProfileSyncMetadataManager('post'), $config[self::$installed_post_key]);
		}
		
		if (null == $to_return) {
			$custom_key = "post_template";
			$custom_val = "post_use_custom_template";
			if (array_key_exists($custom_key, $config) && $config[$custom_key] == $custom_val) {
				$to_return = $config[$custom_val];
			}
		}
			
		return $to_return;
	}
	
	public static function isInstalledPageTemplate($config = array()) {
		$key = self::$installed_page_key;
		return array_key_exists($key, $config) && 'custom' != $config[$key];
	}
	
	public static function getPageTemplateConfigKey() {
		return self::$installed_page_key;
	}

	public static function isInstalledPostTemplate($config = array()) {
		$post_key = "post_template";
		$post_val = "post_use_installed_template";
		return array_key_exists($post_key, $config) && $config[$post_key] == $post_val;
	}
}

class CannotReadTemplateFileException extends LipsException {}

?>