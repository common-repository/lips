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
 * $Id: templating.php 563270 2012-06-24 19:55:49Z bastb $
 * 
 */

if (! class_exists('Smarty'))
	require_once('Smarty/libs/Smarty.class.php');


class LinkedInProfileSync_Security_Policy extends Smarty_Security {
  public $php_functions = array('isset', 'empty', 'count', 'sizeof', 'in_array', 'is_array','time','nl2br');
  public $php_handling = Smarty::PHP_REMOVE;
  
  public function __construct($modifiers = null) {
  	if (null != $modifiers) {
  		$this->php_modifiers = array_merge($this->php_modifiers, $modifiers);
  	}
  	
  }
}

/**
 * Adds small functions to Smarty
 */
class LinkedInProfileSyncSmarty extends Smarty {
	/**
	 * Initializes Smarty, adding each variable as a stored variable
	 * to be used within the template
	 */
	protected $lips_error_reporting = false;
	
	public function __construct($variables = array(), $smarty_reporting = false) {
		parent::__construct();
		$this->enableSecurity(new LinkedInProfileSync_Security_Policy($modifiers = array('in_array')));
		$this->setCaching(Smarty::CACHING_OFF);
		$this->setCompileDir(dirname(__FILE__) . "/template/compiled/");
		$this->clearCompiledTemplate();
		$this->lips_error_reporting = $smarty_reporting;
		
		foreach ($variables as $key => $value)
			$this->assign($key, $value);
	}

	/**	
	 * Attempts to fetch a templated string.
	 * 
	 * @param string $what The template string.
	 * @param array|string $on_error_callback Method being called when an exception is 
	 *  encountered.
	 * @param ? $on_error_callback_arg Data provided to the error handler
	 * 
	 * @return true Did not encounter syntax errors on validation.
	 */
	public function try_fetch($data, $on_error_callback = '', $on_error_callback_arg = null) {
		$valid = false;

		try {
			$this->fetch('string:' . $data);
			$valid = true;
		}
		catch (SmartyException $e) {
			if (! empty($on_error_callback)) {
				call_user_func_array($on_error_callback, array($on_error_callback_arg, $e));
			}
		}
		
		return $valid;
	}
	
	/** 
	 * Executes the template on $data.
	 * 
	 * @param string $data The template.
	 */
	public function fetch($data) {
		if ($this->lips_error_reporting) {
			$this->error_reporting = E_ALL;
			$display_errors = ini_get('display_errors');
			ini_set('display_errors', 1);
		}
		
		$to_return = parent::fetch('string:' . $data);
		
		if ($this->lips_error_reporting) {
			ini_set('display_errors', $display_errors);
		}
		
		$this->clearCompiledTemplate();
		
		return $to_return;
	}
	
	/**
	 * Returns the version of the Smarty Templating Engine
	 * 
	 * @attention: Does so by fetching a template variable. This may overwrite 
	 *  template variables stored in a previous run.
	 */
	public function getVersion() {
		return $this->fetch('{{$smarty.version}|replace:"Smarty-":""}');
	} 
}
?>
