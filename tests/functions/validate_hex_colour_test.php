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

require_once __DIR__ . '/../../phpBB/includes/functions_user.php';

class phpbb_functions_validate_hex_colour_test extends phpbb_test_case
{
	public static function positive_match_data()
	{
		return array(
			array('a00'),
			array('AFF'),
			array('AA0000'),
			array('aa00FF'),
			array('000'),
			array('000000'),
		);
	}

	public static function negative_match_data()
	{
		return array(
			// Invalid prefix
			array('#aa0'),
			array('#AA0000'),
			array('vAA0000'),

			// Invalid suffix
			array('AA0000v'),

			// Correct length, but out of hex range
			array('ag0'),
			array('AAG000'),

			// Too long
			array('AA00000'),
			array('AA0000 '),
			array('AA0000 abf'),
			array('AA0000 AA0000'),

			// empty()
			array('0'),
		);
	}

	public static function optional_only_data()
	{
		return array(
			// The empty colour, i.e. "no colour".
			array(''),
		);
	}

	public static function strict_negative_match_data()
	{
		return array_merge(
			self::negative_match_data(),
			self::optional_only_data()
		);
	}

	public static function nonstrict_positive_match_data()
	{
		return array_merge(
			self::positive_match_data(),
			self::optional_only_data()
		);
	}

	/**
	* @dataProvider positive_match_data
	*/
	public function test_strict_positive_match($input)
	{
		$this->assertFalse(
			phpbb_validate_hex_colour($input, false),
			"Failed asserting that $input passes as a valid hex colour."
		);
	}

	/**
	* @dataProvider strict_negative_match_data
	*/
	public function test_strict_negative_match($input)
	{
		$this->assertSame(
			'WRONG_DATA',
			phpbb_validate_hex_colour($input, false),
			"Failed asserting that $input does not pass as a valid hex colour."
		);
	}

	/**
	* @dataProvider nonstrict_positive_match_data
	*/
	public function test_nonstrict_positive_match($input)
	{
		$this->assertFalse(
			phpbb_validate_hex_colour($input, true),
			"Failed asserting that $input passes as a valid or optional hex colour."
		);
	}

	/**
	* @dataProvider negative_match_data
	*/
	public function test_nonstrict_negative_match($input)
	{
		$this->assertSame(
			'WRONG_DATA',
			phpbb_validate_hex_colour($input, true),
			"Failed asserting that $input does not pass as a valid or optional hex colour."
		);
	}
}
