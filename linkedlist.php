<?php
/**
 * LinkedNode is an object holding the data and a pointer to the Next element 
 * in the list, hence a single linked list.

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
 * $Id: linkedlist.php 559512 2012-06-17 21:10:09Z bastb $
 * 
 */
 
require_once('exception.php');
 
class LinkedNode {
    public $data; // Data for this node
    public $next; // Pointer to the next element in the list.
 
    public function __construct($data, $next = null)     {
        $this->data = $data;
        $this->next = $next;
    }
}

/**
 * Class which holds a bunch of LinkedNodes.
 */
class LinkedList {
	/// $front is the first node in the list.
    protected $head = null;
 
 	/**
 	 * adds data to the list, at the end of the list. The end of the list
 	 * is the first node who does not have a null pointer.
 	 */
    public function add($data) {
    	$new_node = new LinkedNode($data);

        if (!$this->head) {
            $this->head = &$new_node;
        } else {
        	$node = $this->head;
        	$added = false;
        	do {
        		if (null == $node->next) {
        			$node->next = $new_node;
        			$added = true;
        		}
        		else {
        			$node = $node->next;
        		}
        	} while (! $added);
        }
    }
    
    /**
     * $callback is a function which returns true when a match is made
     */
    protected function iterateNodes($callback, $callback_specific) {
    	$node = null;
    	$cur = $this->head;
    	/// List maybe empty
    	if (null != $cur) {
		    while (null == $node) {
		    	if (call_user_func_array($callback, array($callback_specific, $cur->data))) {
		    		$node = $cur;
		    	}
		    	$cur = $cur->next;
		    	if (null == $cur->next)
		    		break;
		    }
    	}
	    
	    return $node;
    }
    
    /**
     * Adds $data directly after the node identified by $parent. Uses $callback
     * to fetch the node who matches $parent.
     */
    public function addAfter($parent, $data, $callback) {
    	$cur = $this->iterateNodes($callback, $parent);
    	if (null != $cur) {
    		$current_next = $cur->next;
    		$cur->next = new LinkedNode($data, $current_next);
    	} 
    	else {
    		throw new ParentNodeNotFoundException($parent);
    	}
    }
    
	/**
	 * Converts each LinkedNode to an associative array
	 */
    public function toAssociativeArray($transformer) {
    	$node = $this->head;
    	$ordered = null;
    	
    	while (null != $node) {
    		$kv = call_user_func_array($transformer, array($node->data));
    		if (1 == count($kv))
    			throw new TooLittleParametersForAssociativeArrayException();

    		$ordered[$kv[0]] = $kv[1];
    		$node = $node->next;
    	}
    	
    	return $ordered;
    }
}

/**
 * Exceptions
 */
class LinkedListException extends LipsException { }
class TooLittleParametersForAssociativeArrayException extends LinkedListException { }
class ParentNodeNotFoundException extends LinkedListException { }

?>
