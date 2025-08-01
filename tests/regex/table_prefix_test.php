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

class phpbb_regex_table_prefix_test extends phpbb_test_case
{
	public static function table_prefix_test_data()
	{
		return array(
			array('phpbb_', 1),
			array('phpBB', 1),
			array('a', 1),

			array('', 0),
			array('_', 0),
			array('a-', 0),
			array("'", 0),
		);
	}

	/**
	* @dataProvider table_prefix_test_data
	*/
	public function test_table_prefix($prefix, $expected)
	{
		$this->assertEquals($expected, preg_match(get_preg_expression('table_prefix'), $prefix));
	}
}
