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
 * $Id: i18n.php 561338 2012-06-20 20:43:59Z bastb $
 * 
 */

class LinkedInI18N {
	protected static function getI18nKey() {
		return SETTINGS_ID . "_i18n";
	}
	
	public static function storeLanguages() {
		// Find languages at:
		// https://developer.linkedin.com/documents/profile-api
		$languages = array(
			"en-US" => __("English (American)"),
			"fr-FR" => __("French"),
			"nl-NL" => __("Dutch"),
			"de-DE" => __("German"),
			"it-IT" => __("Italian"),
			"pt-BR" => __("Portuguese (Brazilian)"),
			"es-ES" => __("Spanish"),
		);
		update_option(self::getI18nKey(), $languages);
	}
	
	public static function deleteLanguages() {
		delete_option(self::getI18nKey());		
	}
	
	public static function getLanguages() {
		$languages = get_option(self::getI18nKey());
		if (!is_array($languages)) {
			$languages = array();
		}
		else {
			// Add a "default" option
			asort($languages, SORT_LOCALE_STRING);
			$languages = array_merge(array("" => __("Standard Profile Language")), $languages);
		}
		
		return $languages;
	}
}

?>