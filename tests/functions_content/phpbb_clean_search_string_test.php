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

class phpbb_functions_content_phpbb_clean_search_string_test extends phpbb_test_case
{
	public static function phpbb_clean_search_string_data()
	{
		return array(
			array('*', ''),
			array('* *', ''),
			array('test', 'test'),
			array(' test ', 'test'),
			array(' test * ', 'test'),
			array('test* *', 'test*'),
			array('* *test*', '*test*'),
			array('test  test  * test', 'test test test'),
			array(' some  wild*cards *    between wo*rds  ', 'some wild*cards between wo*rds'),
			array(' we * now have*** multiple wild***cards * ', 'we now have* multiple wild*cards'),
			array('pi is *** . * **** * *****', 'pi is .'),
		);
	}

	/**
	* @dataProvider phpbb_clean_search_string_data
	*/
	public function test_phpbb_clean_search_string($search_string, $expected)
	{
		$this->assertEquals($expected, phpbb_clean_search_string($search_string));
	}
}
