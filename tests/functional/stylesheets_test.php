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

/**
 * @group functional
 */
class phpbb_functional_stylesheets_test extends phpbb_functional_test_case
{
	public function test_stylesheet_route_serves_assembled_css()
	{
		$crawler = self::request('GET', 'index.php/css/prosilver/stylesheet.css', array(), false);

		$response = self::$client->getResponse();

		$this->assertSame(200, $response->getStatusCode());
		$this->assertStringContainsString('text/css', $response->getHeader('Content-Type'));
		$this->assertStringContainsString('@import url("normalize.css?hash=', self::get_content());
	}

	public function test_page_stylesheet_link_uses_controller_route()
	{
		$crawler = self::request('GET', 'index.php');

		$this->assertStringContainsString('css/prosilver/stylesheet.css', self::get_content());
	}

	public function test_stylesheet_route_returns_404_for_unknown_style()
	{
		$crawler = self::request('GET', 'index.php/css/does_not_exist/stylesheet.css', array(), false);

		$this->assertSame(404, self::$client->getResponse()->getStatusCode());
	}
}
