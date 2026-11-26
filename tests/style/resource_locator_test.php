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

require_once __DIR__ . '/style_test_case.php';

class phpbb_style_resource_locator_test extends phpbb_style_test_case
{
	public function test_get_style_dir()
	{
		$this->assertSame($this->fixtures_root . 'styles/prosilver/', $this->locator->get_style_dir('prosilver'));
	}

	public function test_get_theme_dir()
	{
		$this->assertSame($this->fixtures_root . 'styles/prosilver/theme/', $this->locator->get_theme_dir('prosilver'));
	}

	public function test_locate_theme_file_finds_most_specific()
	{
		$this->assertSame(
			$this->fixtures_root . 'styles/child_override/theme/colours.css',
			$this->locator->locate_theme_file(['child_override', 'parent'], 'colours.css')
		);
	}

	public function test_locate_theme_file_falls_back_to_parent()
	{
		$this->assertSame(
			$this->fixtures_root . 'styles/parent/theme/common.css',
			$this->locator->locate_theme_file(['child_override', 'parent'], 'common.css')
		);
	}

	public function test_locate_theme_file_returns_null_when_missing()
	{
		$this->assertNull($this->locator->locate_theme_file(['child_override', 'parent'], 'missing.css'));
	}
}
