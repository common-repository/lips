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
 * $Id: tokenstore.php 559512 2012-06-17 21:10:09Z bastb $
 * 
 * This file contains the OAuth authentication store. It allows access
 * to the OAuth token and secrets, and allows for modifications.
 * 
 */

require_once('exception.php');

class OAuthTokenBase {
	protected $token;
	protected $secret;
	protected $expires_at = null;
	
	/**
	 * token is the OAuth token, secret is the OAuth secret (which is associated
	 * to the token) and expires_at is the epoch describing when the token is 
	 * automatically invalidated.
	 * 
	 * $expires_at can be omitted.
	 */
	public function __construct($token, $secret, $expires_at = null) {
		/* token:
		 *   ["oauth_token"]=> string(36) "0ff9838d-7995-4f08-8af0-97e48959c14e"
  		 *	 ["oauth_token_secret"]=> string(36) "866ea16b-81e5-46db-a5e2-1628369cfbd8"
  		 *	 ["oauth_callback_confirmed"]=> string(4) "true"
  		 *	 ["xoauth_request_auth_url"]=> string(44) "https://api.linkedin.com/uas/oauth/authorize"
  		 *	 ["oauth_expires_in"]=> string(3) "599"
		 */
		self::verifyToken($token, $secret);
		$this->token = $token;
		$this->secret = $secret;
		if (null != $expires_at) 
			$this->expires_at = intval($expires_at);
	} 
	
	protected static function verifyToken($token, $secret) {
		// Token and secret must not be empty
		if ("" == trim($token) || "" == trim($secret))
			throw new OAuthTokenOrSecretCannotBeEmptyException(__("Either OAuth Token or OAuth Secret is empty"));
	}
	
	/**
	 * Compares two OAuthTokenBase objects and returns true when they are the same.
	 * 
	 * Attention: objects are expected to be equal when the key and secret match
	 */
	public function isSameAs(OAuthTokenBase $other) {
		$result = false;
		if (null != $other)
			$result = $other->token == $this->token && $other->secret == $this->secret;
		
		return $result;
	}
	
	/**
	 * Returns the keys and values associated to this instance
	 * in an associative array.
	 * 
	 */
	public function toAssociativeArray() {
		$to_return = array('token' => $this->token, 'secret' => $this->secret);
		if (null != $this->expires_at)
			$to_return['expires_at'] = $this->expires_at;  
		return $to_return;
	}
	
	public static function fromTokenAndSecret($token, $secret) {
		return new self(array("oauth_token" => $token, "oauth_token_secret" => $secret));
	}
	
	public static function fromToken($token, $class) {
		/* token:
		 *   ["oauth_token"]=> string(36) "0ff9838d-7995-4f08-8af0-97e48959c14e"
  		 *	 ["oauth_token_secret"]=> string(36) "866ea16b-81e5-46db-a5e2-1628369cfbd8"
  		 *	 ["oauth_callback_confirmed"]=> string(4) "true"
  		 *	 ["xoauth_request_auth_url"]=> string(44) "https://api.linkedin.com/uas/oauth/authorize"
  		 *	 ["oauth_expires_in"]=> string(3) "599"
		 */
		$expires_at = null;

		$key = $token["oauth_token"];
		$secret = $token["oauth_token_secret"];
		
		self::verifyToken($key, $secret);
		
		$ttl = "oauth_expires_in";
		if (array_key_exists($ttl, $token))
			$expires_at = time() + intval($token[$ttl]);	

		return new $class($key, $secret, $expires_at);
	}
} 

class OAuthIdentificationToken extends OAuthTokenBase { 
	public static function fromToken($token) {
		return parent::fromToken($token, get_class());
	}
}

class OAuthAccessToken extends OAuthTokenBase { 
	public static function fromToken($token) {
		return parent::fromToken($token, get_class());
	}
}

class OAuthAuthenticationRequestToken extends OAuthTokenBase { 
	public static function fromToken($token) {
		return parent::fromToken($token, get_class());
	}
} 

/**
 * Stores the OAuth token and secret
 */
abstract class LinkedInOAuthTokenStoreBase {
	abstract function expire($include_identification = false, $save = false);
	abstract function getIdentificationToken($assoc = false);
	abstract function getAuthenticationToken($assoc = false);
	abstract function getAuthenticationRequestToken($assoc = false);
	abstract function set(OAuthTokenBase $token, $save = false);
	abstract function save();
	abstract function toAssociativeArray();
}

/**
 * Adds the OAuth token and secret (for identified, authorized and authorization 
 * request) to the metadata of the authorized user. 
 */
class WpUserMetaTokenStore extends LinkedInOAuthTokenStoreBase {
	protected static $default_meta_key = "linkedin:oauth_token";
	protected $wp_uid;
	protected $meta_key; 
	protected $token_id;
	protected $token_auth;
	protected $token_auth_req;
	
	/**
	 * Initializes the store by reading data from the user meta.
	 * 
	 * @param string $meta_key The name of the metadata key which contains the 
	 *   OAuth identification, authorization and request token and secret. 
	 */
	public function __construct($uid = null, $meta_key = null) {
		if (null == $meta_key) {
			$meta_key = self::$default_meta_key;
		}
		$this->meta_key = $meta_key;
		if (null == $uid) {
			$wp_uid = wp_get_current_user();
			$this->wp_uid = $wp_uid->ID;
		}
		else {
			$this->wp_uid = $uid; 
		}
		$persisted_details = $this->getMetaByToken($meta_key);

		if (is_array($persisted_details)) {
			$key_to_class = array(
				"identification" => "OAuthIdentificationToken",
				"access" => "OAuthAccessToken",
				"authreq" => "OAuthAuthenticationRequestToken",
			);
			
			foreach ($key_to_class as $phase => $classname) {
				if (array_key_exists($phase, $persisted_details)) {
					$expires_at = array_key_exists("expires_at", $persisted_details) ? $persisted_details["expires_at"] : null; 
					$this->set(
						new $classname($persisted_details[$phase]["token"], $persisted_details[$phase]["secret"], $expires_at)
					);
				}
			}
		} 
	}

	/**
	 * Attempts to read data from the tokenstore associated to this users'
	 * metadata.
	 * 
	 * @return array The associative array containing keys and values. This
	 *   array may be empty.
	 */	
	protected function getMetaByToken($meta_token) {
		return get_user_meta($this->wp_uid, $meta_token, true);
	}
	
	public function expire($include_identification = false, $save = false) {
		$this->token_auth = null;
		$this->token_auth_req = null;
		if ($include_identification)
			$this->token_id = null;
			
		if ($save)
			$this->save();
	}

	public function getIdentificationToken($assoc = false) {
		if (null == $this->token_id || ! $assoc)
			return $this->token_id;
			
		return $this->token_id->toAssociativeArray();
	}
	
	public function getAuthenticationToken($assoc = false) {
		if (null == $this->token_auth || ! $assoc)
			return $this->token_auth;
		
		return $this->token_auth->toAssociativeArray();
	}
	
	public function getAuthenticationRequestToken($assoc = false) {
		if (null == $this->token_auth_req || ! $assoc)
			return $this->token_auth_req;
			
		return $this->token_auth_req->toAssociativeArray();
	}
	
	/**
	 * Sets (or overwrites) the current tokens and saves the data when $save is set
	 * to true.
	 * 
	 */
	public function set(OAuthTokenBase $pair, $save = false) {
		$previous_value = null;
		
		if ($pair instanceof OAuthIdentificationToken) {
			// Cannot just throw null at the isSameAs method: 
			// PHP Catchable fatal error:  Argument 1 passed to OAuthTokenBase::isSameAs() must be an instance of OAuthTokenBase, null given, called in
			if (null == $this->token_id || ! $pair->isSameAs($this->token_id)) {
				// Expire the rest of them.
				$this->token_auth = $this->token_auth_req = null;
				$previous_value = $this->token_id;
				$this->token_id = $pair;
			}
		}
		else if ($pair instanceof OAuthAccessToken) {
			if (null == $this->token_auth || ! $pair->isSameAs($this->token_auth)) {
				$previous_value = $this->token_auth;
				$this->token_auth_req = null;
				$this->token_auth = $pair;
			}
		}
		else if ($pair instanceof OAuthAuthenticationRequestToken) {
			if (null == $this->token_auth_req || ! $pair->isSameAs($this->token_auth_req)) {
				$previous_value = $this->token_auth_req;
				$this->token_auth_req = $pair;
				$this->token_auth = null;
			}
		}
		
		if ($save)
			$this->save();
		
		return $previous_value;
	}

	/** 
	 * Saves the current store to the metadata, using the key defined 
	 * at creation
	 */
	public function save() {
		$to_save = $this->toAssociativeArray();
		
		if (null != $to_save)
			update_user_meta($this->wp_uid, $this->meta_key, $to_save);
		else
			delete_user_meta($this->wp_uid, $this->meta_key);
	}
	
	/**
	 * Converts each element in this thing to an associative array and 
	 * returns the bundle.
	 * 
	 * @return null Nothing has been done yet
	 * @return array An associative array containing the data
	 */
	public function toAssociativeArray() {
		$to_return = null;
		if (null != $this->token_id) {
			$to_return['identification'] = $this->token_id->toAssociativeArray();
			// Rest cannot be there when there is not identification
			if (null != $this->token_auth)
				$to_return['access'] = $this->token_auth->toAssociativeArray();
			else if (null != $this->token_auth_req)
				$to_return['authreq'] = $this->token_auth_req->toAssociativeArray();
		}
		
		return $to_return;
	}
	
	public static function getDefaultMetaKey() {
		return self::$default_meta_key;
	}
}

class OAuthTokenExceptionBase extends LipsException { }
class OAuthTokenOrSecretCannotBeEmptyException extends OAuthTokenExceptionBase { }

?>
