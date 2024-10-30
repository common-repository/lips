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
 * $Id: template_meta.php 565576 2012-06-29 20:22:45Z bastb $
 * 
 * This file contains the metadata parser (metadata contained in an XML comment)
 * 
 */

require_once('exception.php');

define('LIPS_TEMPLATE_NAME', "template_name");
define('LIPS_TEMPLATE_LANG', "template_lang");
define('LIPS_TEMPLATE_VERSION', "template_version");
define('LIPS_TEMPLATE_VARS', "template_statics");
define('LIPS_TEMPLATE_WEBSITE', "template_base_weblink");
define('LIPS_TEMPLATE_SAMPLE', "template_sample_weblink");
define('LIPS_TEMPLATE_LINK_QUERY', "template_query");
define('LIPS_TEMPLATE_CSS', "template_stylesheet");
define('LIPS_TEMPLATE_CONSTANT', "template_constant");


abstract class MetadataParserBase {
	protected $required_meta_keys = array(LIPS_TEMPLATE_NAME, LIPS_TEMPLATE_VERSION, LIPS_TEMPLATE_WEBSITE);
	protected $optional_meta_keys = array(LIPS_TEMPLATE_VARS, LIPS_TEMPLATE_LANG, LIPS_TEMPLATE_SAMPLE, LIPS_TEMPLATE_LINK_QUERY, LIPS_TEMPLATE_CONSTANT);

	protected function validateMetadata($meta) {
		return count(array_intersect($this->required_meta_keys, array_keys($meta))) == count($this->required_meta_keys) ? $meta : null;
	} 		

	public abstract function setSourceFilename($filename);
	public abstract function parse();
	public abstract function getMetadata();
}

class LinkedInProfileSyncXmlMetadataParser extends MetadataParserBase {
	protected $content; 
	protected $content_len;
	protected $filename;
	protected $metadata;
	protected $metadata_length;
	protected $metadata_pos;
	protected $previous_metadata_pos = 0;
	protected $meta_sep = ":";
	protected $parser = null;
	
	public function __construct($filename = null) {
		// Opens filename and parses it 
		if (null != $filename)
			$this->setSourceFilename($filename);
	}
	
	protected function loadFromFile($filename) {
		$this->filename = $filename;
		$this->content = file_get_contents($filename);
		$this->content_len = strlen($this->content);
		$this->previous_metadata_pos = 0;

		$parser = xml_parser_create();
		xml_set_object($parser, &$this);
		xml_set_default_handler($parser, 'commentHandler');
		if ($this->parser)
			xml_parser_free($this->parser);
		$this->parser = $parser;
	}
	
	protected function canHaveMultipleValues($key) {
		return in_array($key, array(LIPS_TEMPLATE_CONSTANT));
	}
	
	protected function handleValue($key, $value) {
		if (LIPS_TEMPLATE_VARS == $key) {
			foreach (explode(",", $value) as $static_entry) {
				$current_val = trim($static_entry);
				if (preg_match('/^[A-Z_]+[A-Z0-9_]*$/i', $current_val))
					$val[] = $current_val;
			}
			return $val;
		}
		else if (LIPS_TEMPLATE_LINK_QUERY == $key) {
			$val = array();
			foreach (explode(",", $value) as $partial_query) {
				$campaign = explode('=', $partial_query, 2);
				$val[] = array("key" => trim($campaign[0]), "val" => trim($campaign[1]));
			}
			return $val;			
		}
		else if (LIPS_TEMPLATE_CONSTANT == $key) {
			$constants = explode("=", $value, 2);
			$value = array(trim($constants[0]) => trim($constants[1]));
		}
		
		return $value;
	}
	
	protected function isMetadata($metadata) {
		return null != $this->parseMetadata($metadata);
	}
	
	protected function parseMetadata($metadata) {
		$recognized_keys = array_merge($this->required_meta_keys, $this->optional_meta_keys);
		
		foreach (explode('<br />', nl2br($metadata)) as $line) {
			$kv = array_map('trim', explode($this->meta_sep, trim($line), 2));
			
			if (in_array($kv[0], $recognized_keys)) {
				if ($this->canHaveMultipleValues($kv[0])) {
					$meta[$kv[0]][] = $this->handleValue($kv[0], $kv[1]);
				}
				else {
					$meta[$kv[0]] = $this->handleValue($kv[0], $kv[1]);
				}
			}
		}

		$validated_meta = parent::validateMetadata($meta);
		if ($validated_meta) {
			$validated_meta['pos'] = $this->metadata_pos;
			$validated_meta['len'] = $this->metadata_length;
		} 
		return $validated_meta; 
	}
	
	public function setSourceFilename($filename) {
		$this->loadFromFile($filename);
	}
	
	public function parse() {
		$this->metadata = null;
		xml_parse($this->parser, $this->content, false);
		
		return $this->getMetadata();
	}

	public function getMetadata() {
		if (null == $this->metadata)
			throw new MetadataNotFoundException();
		
		return $this->parseMetadata($this->metadata);
	}
	
	public function commentHandler($parser, $data) {
		$comment_token = "<!--";
		if (! $this->metadata && 0 == strcmp(substr($data, 0, strlen($comment_token)), $comment_token)) {
			$metadata = trim(substr($data, strlen($comment_token), -3));
			if ($this->isMetadata($metadata)) {
				$this->metadata = $metadata;
				$this->metadata_pos = $this->previous_metadata_pos;
				$this->metadata_length = xml_get_current_byte_index($parser) - $this->previous_metadata_pos;
			}
		}
		
		$this->previous_metadata_pos = xml_get_current_byte_index($parser);
	}
}

class LinkedInProfileSyncMetadataManager implements Iterator  {
	protected $meta_key = '3ed94470_a4ce_11e1_b3dd_0800200c9a66';
	protected $meta_entries;
	protected $i;
	protected $selector;
	
	public function __construct($selector = null) {
		$this->selector = $selector;
		$this->meta_entries = $this->initializeMetadata();
		$this->i = 0;
	}
	
	protected function initializeMetadata() {
		$entries = null;
		$meta = get_option($this->meta_key);
		if (! empty($meta)) {
			foreach ($meta as $meta_entry) {
				$entry = get_option($meta_entry);
				if (null == $this->selector || $this->selector == $entry['type']) {
					$entries[] = $entry;
				}
			}
		}

		return $entries;
	}
	
	protected function metaToFriendlyLabel($meta) {
		$label = $meta[LIPS_TEMPLATE_NAME] . " v" . $meta[LIPS_TEMPLATE_VERSION];
		if (array_key_exists(LIPS_TEMPLATE_LANG, $meta))
			$label .= " (" . $meta[LIPS_TEMPLATE_LANG] . ")";
			
		return $label;
	}
	
	public function find($key) {
		$this->rewind();
		foreach ($this as $entry) {
			if ($entry['selector'] == $key)
				return $entry;
		}
		return null;
	}
	
	public function updateStoredMetadata() {
		$templates = null;
		$metadata = new LinkedInProfileSyncXmlMetadataParser();
		
		foreach (array("page", "post") as $template_type) {
			foreach (glob(dirname(__FILE__) . "/template/{$template_type}/*.tpl") as $template_filename) {
				try {
					$metadata->setSourceFilename($template_filename);
					$meta = $metadata->parse();
					if (null != $meta) {
						$hashed_filename = md5($template_type . ":" . basename($template_filename));
						$templates[] = $hashed_filename;
						$this_meta = array(
							'filepath' => $template_filename,
							'selector' => $hashed_filename,
							'statics' => $meta[LIPS_TEMPLATE_VARS],
							'constants' => $meta[LIPS_TEMPLATE_CONSTANT],
							'base_uri' => $meta[LIPS_TEMPLATE_WEBSITE],
							'sample_uri' => $meta[LIPS_TEMPLATE_SAMPLE],
							'user_friendly_description' => $this->metaToFriendlyLabel($meta),
							'loclen' => array("pos" => $meta['pos'], "len" => $meta['len']),
							'type' => $template_type,
							'query' => $meta[LIPS_TEMPLATE_LINK_QUERY],
						);
						$template_css = basename($template_filename, ".tpl") . ".css";
						if (file_exists(dirname(__FILE__) . "/css/" . $template_type . "/" . $template_css)) {
							$this_meta['css'] = $template_css;
						}
						update_option($hashed_filename, $this_meta);
					}
				}
				catch (MetadataException $e) { }
			}
		}
		
		if (null != $templates)
			update_option($this->meta_key, $templates);
	}
	
	public function deleteStoredMetadata() {
		foreach (get_option($this->meta_key) as $entry)
			delete_option($entry);
			
		delete_option($this->meta_key);
	}
	
	public function current() {
		return $this->meta_entries[$this->i];
	}
	
	public function key() {
		return $this->i;
	}
	
	public function next() {
		$this->i++;
	}
	
	public function rewind() {
		$this->i = 0;
	}
	
	public function valid() {
		return isset($this->meta_entries[$this->i]);
	}
	
	/**
	 * Sets metadata values to the metadata of this user.
	 * 
	 * @raise MetadataNotFoundException when the metadata has not been stored
	 */
	public function setValues($selector, $values) {
		if (null != $this->find($selector)) {
			$wp_user = wp_get_current_user();
			update_user_meta($wp_user->ID, $selector, $values);
		}
		else {
			throw new MetadataNotFoundException($selector);
		}
	}
	
	public function getValues($selector) {
		$wp_user = wp_get_current_user();
		return get_user_meta($wp_user->ID, $selector, true);
	}
	
	public static function updateMetadata() {
		$obj = new self();
		$obj->updateStoredMetadata();
	}
	
	public static function deleteMetadata() {
		$obj = new self();
		$obj->deleteStoredMetadata();
	}
}

class MetadataException extends LipsException { }
class UnableToParseMetadataException extends MetadataException { }
class MetadataNotFoundException extends MetadataException { }

?>