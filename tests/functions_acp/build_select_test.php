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

require_once __DIR__ . '/../../phpBB/includes/functions_acp.php';

class phpbb_functions_acp_build_select_test extends phpbb_test_case
{
	protected function setUp(): void
	{
		parent::setUp();

		global $user;

		$user = new phpbb_mock_user();
		$user->lang = new phpbb_mock_lang();
	}

	public static function build_select_data()
	{
		return array(
			array(
				array(
					'test'		=> 'TEST',
					'second'	=> 'SEC_OPTION',
				),
				false,
				[
					[
						'value'		=> 'test',
						'label'		=> 'TEST',
						'selected'	=> false,
					],
					[
						'value'		=> 'second',
						'label'		=> 'SEC_OPTION',
						'selected'	=> false,
					],
				],
			),
			array(
				array(
					'test'		=> 'TEST',
					'second'	=> 'SEC_OPTION',
				),
				'test',
				[
					[
						'value'		=> 'test',
						'label'		=> 'TEST',
						'selected'	=> true,
					],
					[
						'value'		=> 'second',
						'label'		=> 'SEC_OPTION',
						'selected'	=> false,
					],
				],
			),
			array(
				array(
					'test'		=> 'TEST',
					'second'	=> 'SEC_OPTION',
				),
				'second',
				[
					[
						'value'		=> 'test',
						'label'		=> 'TEST',
						'selected'	=> false,
					],
					[
						'value'		=> 'second',
						'label'		=> 'SEC_OPTION',
						'selected'	=> true,
					],
				],
			),
		);
	}

	/**
	* @dataProvider build_select_data
	*/
	public function test_build_select($option_ary, $option_default, $expected)
	{
		global $language;

		$language = new phpbb_mock_lang();

		$this->assertEquals($expected, build_select($option_ary, $option_default));
	}
}
