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

class phpbb_style_style_provider_test extends phpbb_test_case
{
	public static function style_list_data()
	{
		return [
			'no parent' => [
				['style_path' => 'prosilver', 'style_parent_id' => 0, 'style_parent_tree' => ''],
				['prosilver'],
			],
			'single parent' => [
				['style_path' => 'child', 'style_parent_id' => 1, 'style_parent_tree' => 'parent'],
				['child', 'parent'],
			],
			'multi level parent' => [
				['style_path' => 'child', 'style_parent_id' => 1, 'style_parent_tree' => 'grandparent/parent'],
				['child', 'parent', 'grandparent'],
			],
		];
	}

	/**
	 * @dataProvider style_list_data
	 */
	public function test_get_style_list($row, $expected)
	{
		$db = $this->createMock(\phpbb\db\driver\driver_interface::class);
		$db->method('sql_escape')->willReturnCallback(function ($value) {
			return $value;
		});
		$db->method('sql_query')->willReturn('result');
		$db->method('sql_fetchrow')->willReturn($row);
		$db->expects($this->once())->method('sql_freeresult');

		$provider = new \phpbb\style\style_provider($db, 'phpbb_styles');

		$this->assertSame($expected, $provider->get_style_list($row['style_path']));
	}

	public function test_get_style_list_unknown_style_returns_empty()
	{
		$db = $this->createMock(\phpbb\db\driver\driver_interface::class);
		$db->method('sql_escape')->willReturnCallback(function ($value) {
			return $value;
		});
		$db->method('sql_query')->willReturn('result');
		$db->method('sql_fetchrow')->willReturn(false);
		$db->expects($this->once())->method('sql_freeresult');

		$provider = new \phpbb\style\style_provider($db, 'phpbb_styles');

		$this->assertSame([], $provider->get_style_list('unknown'));
	}
}
