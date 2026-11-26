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

class phpbb_style_test_case extends phpbb_test_case
{
	/** @var string Path to the fixtures directory (with trailing slash) */
	protected $fixtures_root = __DIR__ . '/fixtures/';

	/** @var \phpbb\style\resource_locator */
	protected $locator;

	protected function setUp(): void
	{
		parent::setUp();

		$this->locator = new \phpbb\style\resource_locator($this->fixtures_root);
	}

	/**
	 * Compute a cache-busting hash for CSS content, matching the assembler.
	 *
	 * @param string $content CSS content
	 * @return string 8 character hash
	 */
	protected function hash(string $content): string
	{
		return sprintf('%08x', crc32($content));
	}
}
