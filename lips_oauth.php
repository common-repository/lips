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
 * $Id: lips_oauth.php 576798 2012-07-24 19:05:29Z bastb $
 * 
 * OAuth related things, combined in a convience class.
 * The base class, WordpressIntegratedOAuth, cannot be overriden properly
 * because of a final on "__contruct":
 * [08-May-2012 19:00:44 UTC] PHP Fatal error:  Cannot override final method OAuth::__construct() in <file> on line 0
 * 
 * In order to achieve something like this, we'll need to either provide
 * fake arguments upon creation of an instance, or we'll static methods.
 * I'm going for the last option. 
 * 
 */

require_once('exception.php');

abstract class WordPressIntegratedOAuth extends OAuth {
	protected $provider;
	protected $permit_disable_ssl_checks = false;
		
	/**
	 * Uses the OS name to see if there is a problem with SSL checking.
	 * This happens on Windows 7, with OAuth 1.1 (not mt, cause mt will not run)
	 * 
	 * Cannot be done from __construct, as this method cannot be overwritten.
	 */
	public function setupCAChecking() {
		$os_with_ssl_problems = array();
		$os_name = php_uname('s');
		if (in_array($os_name, $os_with_ssl_problems))
			$this->disableSSLChecks();
		else {
			$this->setRequestEngine(OAUTH_REQENGINE_CURL);
		}
			
	}

	/**
	 * Calls the user-provided failure callback with the exception, the specific
	 * parameter.
	 */
	protected function handleFailureCallback($failure_callback, $failure_callback_param, $e, $rethrow = false) {
		if (!empty($failure_callback)) {
			call_user_func_array($failure_callback, array($failure_callback_param, $e));
		}
		else {
			if ($rethrow) {
				$error_detail = json_decode($e->lastResponse, true);
				if (empty($error_detail )) {
					$error_detail = $e->getMessage();
				}
				throw new RethrownOAuthException($error_detail);
			}
		}
	}

	/**
	 * fetches the $url using $filter over HTTP $method.
	 * 
	 * @return string Last response. 
	 */
	public function fetch($url, $filter, $method, $options = array()) {
		parent::fetch($url, $filter, $method, $options);
		return $this->getLastResponse(); 
	}
	
	/**
	 * Sets the current dataprovider, returning the previous value.
	 */
	protected function setProvider($provider) {
		$previous_provider = $this->provider;
		$this->provider = $provider;
		
		return $previous_provider;
	}
	
	public function getConsumerIdentification() {
		return $this->provider->getIdentificationToken(true);
	}
	
	/**
	 * Creates a new instance using the tokenstore
	 */
	protected static function fromTokenStoreByCallback($tokenstore, $creator, $authorized_only = true) {
		$use_identified = false;
		$identified_token = $tokenstore->getIdentificationToken(true);
		if (null == $identified_token)
			throw new IdentificationMissingException();
			
		$o = call_user_func_array($creator, array($identified_token["token"], $identified_token["secret"]));
		$o->setupCAChecking();
		$o->setProvider($tokenstore);
		
		$authorized_token = $tokenstore->getAuthenticationToken(true);
		if (null == $authorized_token && $authorized_only) 
			throw new AuthorizationMissingException();
		
		$o->setToken($authorized_token["token"], $authorized_token["secret"]);
						
		return $o;
	}
	
	/**
	 * Returns the url on which the application can be maintained. This url is hosted by
	 * the content provider
	 * 
	 */
	public static function getContentProviderUrl() {
		throw new LogicException("getContentProviderUrl must be overridden");
	}
}

define('LINKEDIN_BASE_URL', "https://api.linkedin.com");
define('LINKEDIN_OAUTH_URL', LINKEDIN_BASE_URL . "/uas/oauth");
/**
 * This class handles the LinkedInProfileSync specific oauth integration
 */
class LinkedInProfileSyncOAuth extends WordPressIntegratedOAuth {
	protected $base_url = LINKEDIN_BASE_URL;
	protected $oauth_base_url = LINKEDIN_OAUTH_URL;
	static protected $picture_sizes = array("original", "40x40");
	/**
	 * Creates a new instance
	 */
	protected function getAuthorizationToken($failure_callback = '', $failure_callback_param = null) {
		try {
			return OAuthAuthenticationRequestToken::fromToken(
				$this->getRequestToken($this->oauth_base_url . "/requestToken")
			);
		}
		catch (OAuthException $e) {
			if (!$this->sslChecks || !$this->permit_disable_ssl_checks) {
				$this->handleFailureCallback($failure_callback, $failure_callback_param, $e, true);
			}
			$this->disableSSLChecks();
			return $this->getAuthorizationToken();		
		}
	}
	
	/**
	 * Calls the setupCAChecking method from the parent class and attempts to see
	 * if CA checking is disabled. Installs the ca chain when CA checking is not
	 * disabled.
	 * 
	 */
	public function setupCAChecking() {
		parent::setupCAChecking();
		if ($this->sslChecks) {
			$cainfo = self::getCAInfo();
			$this->setCAPath(null, $cainfo);
		}
	}
	
	/**
	 * Returns the authorization url to use for this application.
	 */
	public function getAuthorizationUrl(&$request_token, $failure_callback = '', $failure_callback_param = null) {
		// Get authentication key, and create an url for it. Raise an exception when this
		// cannot be done.
		// Only when the thing has not expired yet
		$request_token = $this->getAuthorizationToken($failure_callback, $failure_callback_param);
		if (null != $request_token) {
			$token = $request_token->toAssociativeArray();
			return sprintf($this->oauth_base_url . "/authenticate?oauth_token=%s", $token['token']);
		}
		return null;
	}
	
	/**
	 * Authorizes the app for LinkedIn.
	 * 
	 * @return true: The app was succesfully authorized.
	 * @return false: Unable to authorize app.
	 */
	public function authorize($pin, $failure_callback = '', $failure_callback_param = null) {
		$authorized_token = null;
		
		// Instantiate this thing using the stored access request thing.
		$req = $this->provider->getAuthenticationRequestToken(true);
		$this->setToken($req["token"], $req["secret"]);
		
		try {
			$authorized_token = OAuthAccessToken::fromToken(
				$this->getAccessToken($this->oauth_base_url . "/accessToken", "", $pin)
			);
		}
		catch (OAuthException $e) {
			if (!$this->sslChecks || !$this->permit_disable_ssl_checks) 
				$this->handleFailureCallback($failure_callback, $failure_callback_param, $e);
			else {
				$this->disableSSLChecks();
				return $this->authorize($pin, $failure_callback, $failure_callback_param);	
			}
			
		}
		
		return $authorized_token;
	}
	
	/**
	 * Revokes an earlier granted token
	 * 
	 */
	public function revoke() {
		$this->fetch($this->oauth_base_url . "/invalidateToken", null, "GET");
	}
	
	/**
	 * Fetches data from $url, returning contents to the caller.
	 * 
	 * @param string $url The url to fetch.
	 * @param array | string $failure_callback Method or function being called upon failure.
	 *   The interface of a callback is *()($failure_callback_param, $exception detail)
	 * @param * $failure_callback_param Argument being passed to the $failure_callback.
	 * 
	 * @return null Unable to fetch the url.
	 * 
	 */
	protected function defaultFetch($url, $failure_callback = '', $failure_callback_param = null, $lang = null) {
		$headers = array('x-li-format' => 'json');
		if (null != $lang) {
			$headers["Accept-Language"] = $lang;
		}
		
		try {
			$result = 
				$this->fetch(
					$url, 
					null, 
					OAUTH_HTTP_METHOD_GET,
					$headers 
				);
		}
		catch (OAuthException $e) {
			if (!$this->sslChecks || !$this->permit_disable_ssl_checks) {
				$this->handleFailureCallback($failure_callback, $failure_callback_param, $e);
				$result = null;
			}
			else {
				$this->disableSSLChecks();
				return $this->defaultFetch($url, $failure_callback, $failure_callback_param);	
			}
		}
		
		return $result;
	}

	/**
	 * Fetches the profile from LinkedIn, optionally calling $failure_callback
	 * method upon failure. 
	 * 
	 * @return associated array.
	 * 
	 * @param array | string $failure_callback: Either an array containing a object
	 *  method pair, or a string, which is called on failure.
	 * @param * $failure_callback_param: Optional parameter for the $failure_callback.
	 *  Ignored when $failure_callback is not provided.
	 * @param bool $public_profile: True downloads the public profile (respecting 
	 *  restrictions applied by the profile owner), False fetches the private 
	 *  profile.
	 */
	public function fetchProfile($profile_lang, $public_profile = false, $failure_callback = '', $failure_callback_param = null) {
		$picture_sizes = implode(",", LinkedInProfileSyncOAuth::getProfilePictureSizes());

		return $this->defaultFetch(
			$this->base_url . "/v1/people/~:(id,picture-urls::(" . $picture_sizes . "),num-connections,main-address,first-name,last-name,formatted-name,date-of-birth,twitter-accounts,last-modified-timestamp,headline,industry,summary,specialties,honors,associations,interests,publications,patents,skills:(skill:(name),proficiency:(level,name),years:(id,name)),certifications:(name,authority:(name),number,start-date,end-date),educations,courses,volunteer,recommendations-received,num-recommenders,public-profile-url,positions,location:(name),languages:(language:(name),proficiency:(level,name)))",
			$failure_callback,
			$failure_callback_param,
			$profile_lang
		);
	}
	
	/**
	 * Fetchs company details, such as the URL, the organization type, etc.
	 * 
	 * @param array $company_ids: A list of company id's. 
	 * @attention: Provide an array, even when only one company is requested.
	 * 
	 */
	public function fetchCompanyDetails($company_ids, $failure_callback = '', $failure_callback_param = null) {
		return $this->defaultFetch(
			sprintf("%s/v1/companies::(%s):(company-type,name,universal-name,website-url,status)", $this->base_url, implode(",", $company_ids)),
			$failure_callback,
			$failure_callback_param
		);
	}
	
	/**
	 * Fetches people details for a user identified by id $id. Used primarly for recommendation
	 * resolving.
	 * 
	 */
	public function fetchUserProfile($id, $failure_callback = '', $failure_callback_param = null) {
		return $this->defaultFetch(
			sprintf("%s/v1/people/%s:(public-profile-url)", $this->base_url, $id),
			$failure_callback,
			$failure_callback_param
		);
	}

	/**
	 * Returns a new object of this class 
	 * 
	 */
	protected static function handleObjectCreation($key, $secret) {
		try {
			return new self($key, $secret);			
		}
		catch (OAuthException $e) {
			throw new UnableToInitializeInstanceException($e);
		}
	}

	/**
	 * This is a wrapper for fromTokenStore, defined in the base class. This method exists only
	 * because PHP static classes used in conjunction with an abstract class behaves rather weird.
	 * 
	 */
	public static function fromTokenStore($tokenstore, $authorized_only = true) {
		return self::fromTokenStoreByCallback($tokenstore, array(get_class(), 'handleObjectCreation'), $authorized_only);
	}
	
	/**
	 * Returns the filename containing the linkedin CA chain
	 */
	public static function getCAInfo() {
		return str_replace('\\', '/', dirname(__FILE__) . "/data/api.linkedin.pem");
	}
	
	/**
	 * Returns an array with the sizes of the profile pictures being requested
	 */
	public static function getProfilePictureSizes() {
		return self::$picture_sizes;
	}

	/**
	 * Returns the url to the LinkedIn developer website
	 * 
	 */	
	public static function getContentProviderUrl() {
		return "https://www.linkedin.com/secure/developer";
	}
	
}

class WordPressIntegratedOAuthException extends LipsException { }
class IdentificationMissingException extends WordPressIntegratedOAuthException { }
class AuthorizationMissingException extends WordPressIntegratedOAuthException { }
class UnableToInitializeInstanceException extends WordPressIntegratedOAuthException { }
class RethrownOAuthException extends WordPressIntegratedOAuthException { }

?>