<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\search\sphinx;

/**
 * \phpbb\search\sphinx\config_item
 * Represents a single config item inside the sphinx configuration
 */
abstract class config_item
{
	/** @var string Item name */
	protected $name = '';

	/**
	 * Getter for the item's name
	 *
	 * @return	string	The item object's name
	 */
	public function get_name()
	{
		return $this->name;
	}

	/**
	 * Return string representation of config item
	 *
	 * @return	string	String representation of config item
	 */
	abstract public function to_string();
}
