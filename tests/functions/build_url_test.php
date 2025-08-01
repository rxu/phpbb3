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

class phpbb_build_url_test extends phpbb_test_case
{
	protected function setUp(): void
	{
		global $user, $phpbb_dispatcher, $phpbb_container, $phpbb_root_path, $phpbb_path_helper, $config;

		parent::setUp();

		$phpbb_container = new phpbb_mock_container_builder();
		$user = new phpbb_mock_user();
		$phpbb_dispatcher = new phpbb_mock_event_dispatcher();
		$config = new \phpbb\config\config([
			'enable_mod_rewrite' => 0,
		]);

		$phpbb_path_helper = new \phpbb\path_helper(
			new \phpbb\symfony_request(
				new phpbb_mock_request()
			),
			$this->createMock('\phpbb\request\request'),
			$phpbb_root_path,
			'php'
		);
		$phpbb_container->set('path_helper', $phpbb_path_helper);
	}
	public static function build_url_test_data()
	{
		return array(
			array(
				'index.php',
				false,
				'phpBB/index.php',
			),
			array(
				'index.php',
				't',
				'phpBB/index.php',
			),
			array(
				'viewtopic.php?t=5',
				false,
				'phpBB/viewtopic.php?t=5',
			),
			array(
				'viewtopic.php?style=1&t=6',
				'f',
				'phpBB/viewtopic.php?style=1&amp;t=6',
			),
			array(
				'viewtopic.php?style=1&t=6',
				array('f', 'style', 't'),
				'phpBB/viewtopic.php',
			),
			array(
				'http://test.phpbb.com/viewtopic.php?style=1&t=6',
				array('f', 'style', 't'),
				'http://test.phpbb.com/viewtopic.php',
			),
			array(
				'posting.php?mode=delete&p=20%22%3Cscript%3Ealert%281%29%3B%3C%2Fscript%3E',
				false,
				'phpBB/posting.php?mode=delete&amp;p=20%22%3Cscript%3Ealert%281%29%3B%3C%2Fscript%3E',
			)
		);
	}

	/**
	* @dataProvider build_url_test_data
	*/
	public function test_build_url($page, $strip_vars, $expected)
	{
		global $config, $user, $phpbb_path_helper, $phpbb_dispatcher, $_SID;

		$_SID = '';
		$user->page['page'] = $page;
		$output = build_url($strip_vars);

		$this->assertEquals($expected, $output);
	}
}
