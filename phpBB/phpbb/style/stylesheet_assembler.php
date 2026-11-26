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
 * Assembles the stylesheet of a style from its own files and the files of
 * its ancestors, mirroring template inheritance for CSS.
 *
 * The entry point of a theme is the file "theme/stylesheet.css" (and the
 * language specific "theme/{lang}/stylesheet.css"). Such an entry file is a
 * manifest: it contains "@import" rules listing the component files, and may
 * also contain literal CSS rules which are appended after all imports.
 *
 * Two inheritance modes are supported, controlled by the "theme-inheritance"
 * key in a style's composer.json "extra" section:
 *
 * - "merge" (default): the import lists of all ancestors are concatenated
 *   (deduplicated by file name, most specific added last) and the literal CSS
 *   of ancestors is emitted before the literal CSS of the child.
 * - "replace": only the entry file of the child style is used; ancestors are
 *   ignored entirely.
 *
 * In both modes every imported file is resolved through the inheritance tree
 * (child first), so a child may override a single component simply by shipping
 * a file with the same name, without re-listing the manifest.
 */
class stylesheet_assembler
{
	public const MODE_MERGE = 'merge';
	public const MODE_REPLACE = 'replace';

	/** @var resource_locator */
	protected $locator;

	/** @var string[] Paths of files read during the current assembly */
	protected $monitored_paths = [];

	/**
	 * Constructor
	 *
	 * @param resource_locator $locator Style resource locator
	 */
	public function __construct(resource_locator $locator)
	{
		$this->locator = $locator;
	}

	/**
	 * Assemble the entry stylesheet ("stylesheet.css") for a style tree.
	 *
	 * @param string[] $style_list   Style paths, most specific first
	 * @param string   $relative_dir Directory of the entry relative to the theme
	 *                               directory ("" for the main stylesheet,
	 *                               "en/" for a language stylesheet)
	 * @return string|null Assembled CSS, or null if no entry exists in the tree
	 */
	public function assemble(array $style_list, string $relative_dir = ''): string|null
	{
		$result = $this->assemble_with_dependencies($style_list, $relative_dir);

		return $result !== null ? $result['content'] : null;
	}

	/**
	 * Assemble the entry stylesheet and return the list of files it depends
	 * on, so that the result can be cached and invalidated when a file changes.
	 *
	 * @param string[] $style_list   Style paths, most specific first
	 * @param string   $relative_dir Directory of the entry relative to the theme
	 *                               directory ("" for the main stylesheet,
	 *                               "en/" for a language stylesheet)
	 * @return array|null Array with "content" and "files" (path => mtime) keys,
	 *                    or null if no entry exists in the tree
	 */
	public function assemble_with_dependencies(array $style_list, string $relative_dir = ''): array|null
	{
		if (empty($style_list))
		{
			return null;
		}

		$relative_dir = $this->normalise_relative_dir($relative_dir);
		$this->monitored_paths = [];

		$structure = $this->assemble_structure($style_list, $relative_dir);

		if ($structure === null)
		{
			return null;
		}

		$content = $this->render($style_list, $relative_dir, $structure);

		$files = [];
		foreach (array_unique($this->monitored_paths) as $path)
		{
			$files[$path] = is_file($path) ? filemtime($path) : 0;
		}

		return [
			'content'	=> $content,
			'files'		=> $files,
		];
	}

	/**
	 * Resolve a single component file through the inheritance tree.
	 *
	 * @param string[] $style_list    Style paths, most specific first
	 * @param string   $relative_file File path relative to the theme directory
	 * @return array|null Array with "path", "content" and "hash" keys, or null
	 */
	public function resolve_component(array $style_list, string $relative_file): array|null
	{
		$path = $this->locator->locate_theme_file($style_list, $relative_file);

		if ($path === null)
		{
			return null;
		}

		$content = file_get_contents($path);

		if ($content === false)
		{
			return null;
		}

		return [
			'path'		=> $path,
			'content'	=> $content,
			'hash'		=> $this->compute_hash($content),
		];
	}

	/**
	 * Recursively build the merged structure (imports + literal CSS) for a
	 * style tree. Returns null when no entry exists at all.
	 *
	 * @param string[] $style_list   Style paths, most specific first
	 * @param string   $relative_dir Normalised entry directory
	 * @return array|null Array with "imports" and "literal" keys, or null
	 */
	protected function assemble_structure(array $style_list, string $relative_dir): array|null
	{
		if (empty($style_list))
		{
			return null;
		}

		$style = $style_list[0];
		$entry = $this->read_entry($style, $relative_dir);
		$mode = $this->get_inheritance_mode($style);

		if ($mode === self::MODE_REPLACE)
		{
			if ($entry === null)
			{
				return null;
			}

			return $this->parse($entry);
		}

		// Merge mode
		$parent = $this->assemble_structure(array_slice($style_list, 1), $relative_dir);
		$child = $entry !== null ? $this->parse($entry) : null;

		if ($parent === null && $child === null)
		{
			// No entry file anywhere in the tree.
			return null;
		}

		$parent = $parent ?? ['imports' => [], 'literal' => ''];
		$child = $child ?? ['imports' => [], 'literal' => ''];

		$imports = $parent['imports'];
		foreach ($child['imports'] as $import)
		{
			$imports = $this->append_import($imports, $import);
		}

		$literal = $parent['literal'];
		if ($child['literal'] !== '')
		{
			$literal = ($literal !== '' ? $literal . "\n" : '') . $child['literal'];
		}

		return ['imports' => $imports, 'literal' => $literal];
	}

	/**
	 * Render a merged structure into a valid stylesheet.
	 *
	 * @param string[] $style_list   Style paths, most specific first
	 * @param string   $relative_dir Normalised entry directory
	 * @param array    $structure    Array with "imports" and "literal" keys
	 * @return string Assembled CSS
	 */
	protected function render(array $style_list, string $relative_dir, array $structure): string
	{
		$output = '';

		foreach ($structure['imports'] as $import)
		{
			$url = $import['url'];

			$relative = $this->resolve_import_path($url);
			if ($relative !== null)
			{
				// Monitor every candidate location, so a later override or
				// addition in a more specific style also invalidates a cached
				// result.
				foreach ($this->locator->get_theme_file_candidates($style_list, $relative_dir . $relative) as $candidate)
				{
					$this->monitored_paths[] = $candidate;
				}

				$component = $this->resolve_component($style_list, $relative_dir . $relative);
				if ($component !== null)
				{
					$url = $this->rewrite_import_url($url, $component['hash']);
				}
			}

			$output .= '@import url("' . $url . '")' . ($import['media'] !== '' ? ' ' . $import['media'] : '') . ";\n";
		}

		if ($structure['literal'] !== '')
		{
			$output .= ($output !== '' ? "\n" : '') . $structure['literal'] . "\n";
		}

		return $output;
	}

	/**
	 * Read the raw content of a style's entry file.
	 *
	 * @param string $style        Style path
	 * @param string $relative_dir Normalised entry directory
	 * @return string|null Raw content, or null if the style has no entry
	 */
	protected function read_entry(string $style, string $relative_dir): string|null
	{
		if (!$this->locator->is_safe_style_name($style))
		{
			return null;
		}

		$path = $this->locator->get_theme_dir($style) . $relative_dir . 'stylesheet.css';
		$this->monitored_paths[] = $path;

		if (!is_file($path))
		{
			return null;
		}

		$content = file_get_contents($path);

		return $content === false ? null : $content;
	}

	/**
	 * Read the CSS inheritance mode of a style from its composer.json file.
	 *
	 * @param string $style Style path
	 * @return string MODE_MERGE or MODE_REPLACE
	 */
	protected function get_inheritance_mode(string $style): string
	{
		if (!$this->locator->is_safe_style_name($style))
		{
			return self::MODE_MERGE;
		}

		$composer_path = $this->locator->get_style_dir($style) . 'composer.json';
		$this->monitored_paths[] = $composer_path;

		if (is_file($composer_path))
		{
			$json = json_decode(file_get_contents($composer_path), true);
			$configured = is_array($json) && isset($json['extra']['theme-inheritance']) ? $json['extra']['theme-inheritance'] : null;

			if ($configured === self::MODE_REPLACE)
			{
				return self::MODE_REPLACE;
			}
		}

		return self::MODE_MERGE;
	}

	/**
	 * Parse a CSS string into its "@import" rules and the remaining literal CSS.
	 *
	 * @param string $css Raw CSS
	 * @return array Array with "imports" (list of arrays with "url" and "media")
	 *               and "literal" keys
	 */
	protected function parse(string $css): array
	{
		$imports = [];
		$literal = $css;

		preg_match_all('/@import\b[^;]*;/i', $css, $matches, PREG_OFFSET_CAPTURE);

		$ranges = [];
		foreach ($matches[0] as [$statement, $offset])
		{
			$import = $this->parse_import_statement($statement);
			if ($import !== null)
			{
				$imports[] = $import;
			}
			$ranges[] = [$offset, strlen($statement)];
		}

		// Remove the matched statements from the literal (reverse order to
		// preserve offsets).
		for ($i = count($ranges) - 1; $i >= 0; $i--)
		{
			$literal = substr_replace($literal, '', $ranges[$i][0], $ranges[$i][1]);
		}

		return [
			'imports'	=> $imports,
			'literal'	=> trim($literal),
		];
	}

	/**
	 * Parse a single "@import ...;" statement into its URL and media query.
	 *
	 * @param string $statement The "@import ...;" statement
	 * @return array|null Array with "url" and "media" keys, or null
	 */
	protected function parse_import_statement(string $statement): array|null
	{
		$statement = trim($statement);

		if (stripos($statement, '@import') !== 0)
		{
			return null;
		}

		// Strip the terminating semicolon.
		if (str_ends_with($statement, ';'))
		{
			$statement = rtrim(substr($statement, 0, -1));
		}

		$body = trim(substr($statement, strlen('@import')));
		$url = null;
		$media = '';

		if (preg_match('/^url\(\s*(.+?)\s*\)(.*)$/is', $body, $match))
		{
			$url = $this->strip_quotes(trim($match[1]));
			$media = trim($match[2]);
		}
		else if (preg_match('/^(["\'])(.*?)\1(.*)$/is', $body, $match))
		{
			$url = $match[2];
			$media = trim($match[3]);
		}
		else if (preg_match('/^([^\s]+)(.*)$/s', $body, $match))
		{
			$url = $match[1];
			$media = trim($match[2]);
		}

		if ($url === null || $url === '')
		{
			return null;
		}

		return [
			'url'	=> $url,
			'media'	=> $media,
		];
	}

	/**
	 * Append an import to a list, deduplicating by file name.
	 *
	 * @param array $imports Existing imports
	 * @param array $import  Import to append
	 * @return array New import list
	 */
	protected function append_import(array $imports, array $import): array
	{
		$key = $this->import_key($import['url']);

		foreach ($imports as $existing_key => $existing)
		{
			if ($existing_key === $key)
			{
				return $imports;
			}
		}

		$imports[$key] = $import;

		return $imports;
	}

	/**
	 * Compute a deduplication key for an import URL.
	 *
	 * @param string $url Import URL
	 * @return string Key (file name for local files, full URL otherwise)
	 */
	protected function import_key(string $url): string
	{
		$path = preg_replace('/\?.*$/', '', $url);

		if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) || stripos($path, 'data:') === 0 || str_starts_with($path, '/'))
		{
			return $url;
		}

		$basename = basename($path);

		return $basename !== '' ? $basename : $url;
	}

	/**
	 * Convert an import URL into a path relative to the entry directory, or
	 * null when the URL cannot be resolved locally (remote, absolute, data URI).
	 *
	 * @param string $url Import URL
	 * @return string|null Relative path, or null
	 */
	protected function resolve_import_path(string $url): string|null
	{
		$path = preg_replace('/\?.*$/', '', $url);

		if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) || stripos($path, 'data:') === 0 || str_starts_with($path, '/'))
		{
			return null;
		}

		$path = rawurldecode($path);

		// Reject path traversal.
		foreach (explode('/', $path) as $segment)
		{
			if ($segment === '..')
			{
				return null;
			}
		}

		return $path;
	}

	/**
	 * Rewrite an import URL with a fresh cache-busting hash.
	 *
	 * @param string $url  Original import URL
	 * @param string $hash New hash
	 * @return string Rewritten URL
	 */
	protected function rewrite_import_url(string $url, string $hash): string
	{
		$base = preg_replace('/\?.*$/', '', $url);

		return $base . '?hash=' . $hash;
	}

	/**
	 * Compute a cache-busting hash for CSS content, matching the format used
	 * by phpBB's build tooling.
	 *
	 * @param string $content CSS content
	 * @return string 8 character hash
	 */
	protected function compute_hash(string $content): string
	{
		return sprintf('%08x', crc32($content));
	}

	/**
	 * Strip surrounding quotes from a value.
	 *
	 * @param string $value Value
	 * @return string Value without surrounding quotes
	 */
	protected function strip_quotes(string $value): string
	{
		$value = trim($value);

		if (strlen($value) >= 2)
		{
			$first = $value[0];
			$last = $value[strlen($value) - 1];

			if (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))
			{
				return substr($value, 1, -1);
			}
		}

		return $value;
	}

	/**
	 * Normalise a relative entry directory to end with a trailing slash
	 * (or be empty for the theme root).
	 *
	 * @param string $relative_dir Relative directory
	 * @return string Normalised directory
	 */
	protected function normalise_relative_dir(string $relative_dir): string
	{
		$relative_dir = trim(str_replace('\\', '/', $relative_dir), '/');

		return $relative_dir !== '' ? $relative_dir . '/' : '';
	}
}
