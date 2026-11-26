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

namespace phpbb\style;

/**
 * Locates style resources (theme files) on the filesystem across a style
 * inheritance tree.
 */
class resource_locator
{
	/** @var string phpBB root path (with trailing slash) */
	protected $phpbb_root_path;

	/** @var string Directory containing the styles, relative to the root path */
	protected $styles_path;

	/**
	 * Constructor
	 *
	 * @param string $phpbb_root_path phpBB root path (with trailing slash)
	 * @param string $styles_path     Directory containing the styles, relative to the root path
	 */
	public function __construct(string $phpbb_root_path, string $styles_path = 'styles/')
	{
		$this->phpbb_root_path = $phpbb_root_path;
		$this->styles_path = rtrim($styles_path, '/') . '/';
	}

	/**
	 * Get the full filesystem path to a style's base directory.
	 *
	 * @param string $style Style path (e.g. "prosilver")
	 * @return string Full path (e.g. "/var/www/phpBB/styles/prosilver/")
	 */
	public function get_style_dir(string $style): string
	{
		return $this->phpbb_root_path . $this->styles_path . $style . '/';
	}

	/**
	 * Get the full filesystem path to a style's theme directory.
	 *
	 * @param string $style Style path (e.g. "prosilver")
	 * @return string Full path (e.g. "/var/www/phpBB/styles/prosilver/theme/")
	 */
	public function get_theme_dir(string $style): string
	{
		return $this->get_style_dir($style) . 'theme/';
	}

	/**
	 * Locate a theme file across a style inheritance tree.
	 *
	 * The style list must be ordered from the most specific style to the
	 * least specific (child first, ancestors after). The first existing file
	 * wins, mirroring the behaviour of template inheritance.
	 *
	 * @param string[] $style_list    Style paths, most specific first
	 * @param string   $relative_file File path relative to the theme directory
	 * @return string|null Full filesystem path, or null if not found
	 */
	public function locate_theme_file(array $style_list, string $relative_file): ?string
	{
		foreach ($style_list as $style)
		{
			$path = $this->get_theme_dir($style) . $relative_file;

			if (is_file($path))
			{
				return $path;
			}
		}

		return null;
	}

	/**
	 * Get the candidate filesystem paths for a theme file across a style tree,
	 * in resolution order (most specific first). Used to monitor all possible
	 * locations so that a later override/addition in a more specific style also
	 * invalidates a cached result.
	 *
	 * @param string[] $style_list    Style paths, most specific first
	 * @param string   $relative_file File path relative to the theme directory
	 * @return string[] Full filesystem paths, one per style
	 */
	public function get_theme_file_candidates(array $style_list, string $relative_file): array
	{
		$paths = [];

		foreach ($style_list as $style)
		{
			$paths[] = $this->get_theme_dir($style) . $relative_file;
		}

		return $paths;
	}
}
