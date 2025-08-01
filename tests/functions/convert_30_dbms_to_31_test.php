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

class phpbb_convert_30_dbms_to_31_test extends phpbb_test_case
{
	public static function convert_30_dbms_to_31_data()
	{
		return array(
			array('mssql_odbc'),
			array('mssqlnative'),
			array('mysqli'),
			array('oracle'),
			array('postgres'),
		);
	}

	/**
	* @dataProvider convert_30_dbms_to_31_data
	*/
	public function test_convert_30_dbms_to_31($input)
	{
		$expected = "phpbb\\db\\driver\\$input";
		$output = \phpbb\config_php_file::convert_30_dbms_to_31($input);

		$this->assertEquals($expected, $output);
	}
}
