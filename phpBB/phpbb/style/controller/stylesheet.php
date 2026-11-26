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

namespace phpbb\style\controller;

use phpbb\cache\driver\driver_interface;
use phpbb\exception\http_exception;
use phpbb\style\stylesheet_assembler;
use phpbb\style\style_provider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves stylesheets, resolved through the style inheritance tree.
 *
 * The route exposes any CSS file below a style's theme directory. Files named
 * "stylesheet.css" are treated as entry points and assembled (see
 * stylesheet_assembler); all other files are served as-is with per-file
 * fallback through the inheritance tree.
 *
 * The assembled entry points are cached. Each cached entry stores the list of
 * files it depends on (path => mtime); when any of them changes the cache is
 * invalidated and the entry is reassembled. A board-wide cache purge also
 * invalidates them.
 */
class stylesheet
{
	/** @var style_provider */
	protected $provider;

	/** @var stylesheet_assembler */
	protected $assembler;

	/** @var driver_interface */
	protected $cache;

	/**
	 * Constructor
	 *
	 * @param style_provider       $provider  Style tree provider
	 * @param stylesheet_assembler $assembler Stylesheet assembler
	 * @param driver_interface     $cache     Cache driver
	 */
	public function __construct(style_provider $provider, stylesheet_assembler $assembler, driver_interface $cache)
	{
		$this->provider = $provider;
		$this->assembler = $assembler;
		$this->cache = $cache;
	}

	/**
	 * Controller for the "/css/{style}/{file}" route.
	 *
	 * @param Request $request Symfony request
	 * @param string  $style   Style path
	 * @param string  $file    File path relative to the theme directory
	 * @return Response
	 */
	public function handle(Request $request, string $style, string $file): Response
	{
		if (!$this->is_valid_style($style) || !$this->is_valid_file($file))
		{
			throw new http_exception(404, 'PAGE_NOT_FOUND');
		}

		$style_list = $this->provider->get_style_list($style);

		if (empty($style_list))
		{
			throw new http_exception(404, 'PAGE_NOT_FOUND');
		}

		$content = $this->get_content($style_list, $file);

		if ($content === null)
		{
			throw new http_exception(404, 'PAGE_NOT_FOUND');
		}

		$response = new Response($content);
		$response->headers->set('Content-Type', 'text/css; charset=UTF-8');
		$response->setPublic();
		$response->setMaxAge(3600);
		$response->setEtag(md5($content));
		$response->headers->addCacheControlDirective('must-revalidate', true);

		// Respond with 304 Not Modified when the cached copy is still valid.
		$response->isNotModified($request);

		return $response;
	}

	/**
	 * Produce the CSS content for a requested file.
	 *
	 * @param string[] $style_list Style paths, most specific first
	 * @param string   $file       File path relative to the theme directory
	 * @return string|null CSS content, or null when not found
	 */
	protected function get_content(array $style_list, string $file): string|null
	{
		$basename = basename($file);
		$relative_dir = dirname($file);
		$relative_dir = $relative_dir === '.' ? '' : $relative_dir . '/';

		if ($basename === 'stylesheet.css')
		{
			return $this->get_assembled_entry($style_list, $relative_dir);
		}

		$component = $this->assembler->resolve_component($style_list, $file);

		return $component !== null ? $component['content'] : null;
	}

	/**
	 * Get an assembled entry point, served from the cache when still fresh.
	 *
	 * @param string[] $style_list   Style paths, most specific first
	 * @param string   $relative_dir Normalised entry directory
	 * @return string|null Assembled CSS, or null when no entry exists
	 */
	protected function get_assembled_entry(array $style_list, string $relative_dir): string|null
	{
		$cache_key = '_css_' . md5(implode('|', $style_list) . '|' . $relative_dir);

		$cached = $this->cache->get($cache_key);

		if (is_array($cached) && isset($cached['content']) && $this->is_fresh($cached['files'] ?? []))
		{
			return $cached['content'];
		}

		$result = $this->assembler->assemble_with_dependencies($style_list, $relative_dir);

		if ($result === null)
		{
			return null;
		}

		$this->cache->put($cache_key, [
			'content'	=> $result['content'],
			'files'		=> $result['files'],
		]);

		return $result['content'];
	}

	/**
	 * Check whether all files of a cached entry still have the recorded mtime.
	 *
	 * @param array $files Map of filesystem path => mtime (0 for a missing file)
	 * @return bool True when nothing changed
	 */
	protected function is_fresh(array $files): bool
	{
		foreach ($files as $path => $mtime)
		{
			if ((is_file($path) ? filemtime($path) : 0) !== $mtime)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a style path.
	 *
	 * @param string $style Style path
	 * @return bool True when valid
	 */
	protected function is_valid_style(string $style): bool
	{
		return (bool) preg_match('#^[a-zA-Z0-9_\-]+$#', $style);
	}

	/**
	 * Validate a file path relative to the theme directory.
	 *
	 * @param string $file File path
	 * @return bool True when valid
	 */
	protected function is_valid_file(string $file): bool
	{
		if ($file === '' || str_starts_with($file, '/') || str_starts_with($file, '\\'))
		{
			return false;
		}

		// Reject any path traversal attempt.
		foreach (explode('/', str_replace('\\', '/', $file)) as $segment)
		{
			if ($segment === '..')
			{
				return false;
			}
		}

		return (bool) preg_match('#\.css$#i', $file);
	}
}
