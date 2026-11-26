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

require_once __DIR__ . '/../style_test_case.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class phpbb_style_controller_stylesheet_test extends phpbb_style_test_case
{
	/** @var \phpbb\style\stylesheet_assembler */
	protected $assembler;

	/** @var \phpbb\style\style_provider|\PHPUnit\Framework\MockObject\MockObject */
	protected $provider;

	/** @var \phpbb\cache\driver\driver_interface|\PHPUnit\Framework\MockObject\MockObject */
	protected $cache;

	/** @var \phpbb\style\controller\stylesheet */
	protected $controller;

	protected function setUp(): void
	{
		parent::setUp();

		$this->assembler = new \phpbb\style\stylesheet_assembler($this->locator);
		$this->provider = $this->createMock(\phpbb\style\style_provider::class);
		$this->cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
		$this->controller = new \phpbb\style\controller\stylesheet($this->provider, $this->assembler, $this->cache);
	}

	public function test_handle_serves_assembled_entry()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);
		$this->cache->method('get')->willReturn(false);

		$response = $this->controller->handle(new Request(), 'parent', 'stylesheet.css');

		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
		$this->assertStringContainsString('base.css?hash=', $response->getContent());
	}

	public function test_handle_serves_component_with_fallback()
	{
		$this->provider->method('get_style_list')->willReturn(['child_override', 'parent']);

		$response = $this->controller->handle(new Request(), 'child_override', 'common.css');

		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame('/* parent common */', $response->getContent());
	}

	public function test_handle_serves_language_stylesheet()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);
		$this->cache->method('get')->willReturn(false);

		$response = $this->controller->handle(new Request(), 'parent', 'en/stylesheet.css');

		$this->assertInstanceOf(Response::class, $response);
		$this->assertStringContainsString('lang.css?hash=', $response->getContent());
	}

	public function test_handle_throws_404_for_unknown_style()
	{
		$this->provider->method('get_style_list')->willReturn([]);

		$this->assert_http_404(function () {
			$this->controller->handle(new Request(), 'unknown', 'stylesheet.css');
		});
	}

	public function test_handle_throws_404_for_path_traversal()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);

		$this->assert_http_404(function () {
			$this->controller->handle(new Request(), 'parent', '../secret.css');
		});
	}

	public function test_handle_throws_404_for_missing_file()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);

		$this->assert_http_404(function () {
			$this->controller->handle(new Request(), 'parent', 'missing.css');
		});
	}

	public function test_handle_caches_assembled_entry_on_miss()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);
		$this->cache->method('get')->willReturn(false);

		$put_calls = [];
		$this->cache->method('put')->willReturnCallback(function ($key, $value) use (&$put_calls) {
			$put_calls[] = [$key, $value];
		});

		$response = $this->controller->handle(new Request(), 'parent', 'stylesheet.css');

		$this->assertStringContainsString('base.css?hash=', $response->getContent());
		$this->assertCount(1, $put_calls);
		$this->assertStringStartsWith('_css_', $put_calls[0][0]);
		$this->assertArrayHasKey('content', $put_calls[0][1]);
		$this->assertArrayHasKey('files', $put_calls[0][1]);
	}

	public function test_handle_serves_from_cache_when_fresh()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);

		$existing = $this->fixtures_root . 'styles/parent/theme/base.css';
		$this->cache->method('get')->willReturn([
			'content'	=> '/* cached css */',
			'files'		=> [$existing => filemtime($existing)],
		]);

		$this->cache->expects($this->never())->method('put');

		$response = $this->controller->handle(new Request(), 'parent', 'stylesheet.css');

		$this->assertSame('/* cached css */', $response->getContent());
	}

	public function test_handle_reassembles_when_dependency_changed()
	{
		$this->provider->method('get_style_list')->willReturn(['parent']);

		$existing = $this->fixtures_root . 'styles/parent/theme/base.css';
		$this->cache->method('get')->willReturn([
			'content'	=> '/* stale css */',
			'files'		=> [$existing => 0],
		]);

		$this->cache->expects($this->once())->method('put');

		$response = $this->controller->handle(new Request(), 'parent', 'stylesheet.css');

		$this->assertStringContainsString('base.css?hash=', $response->getContent());
		$this->assertStringNotContainsString('stale', $response->getContent());
	}

	protected function assert_http_404(callable $callback): void
	{
		try
		{
			$callback();
			$this->fail('Expected an http_exception to be thrown');
		}
		catch (\phpbb\exception\http_exception $e)
		{
			$this->assertSame(404, $e->getStatusCode());
		}
	}
}
