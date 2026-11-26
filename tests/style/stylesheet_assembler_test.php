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

class phpbb_style_stylesheet_assembler_test extends phpbb_style_test_case
{
	/** @var \phpbb\style\stylesheet_assembler */
	protected $assembler;

	protected function setUp(): void
	{
		parent::setUp();

		$this->assembler = new \phpbb\style\stylesheet_assembler($this->locator);
	}

	public function test_assemble_returns_null_for_empty_style_list()
	{
		$this->assertNull($this->assembler->assemble([]));
	}

	public function test_assemble_returns_null_when_no_entry_exists()
	{
		$this->assertNull($this->assembler->assemble(['child_empty']));
	}

	public function test_assemble_inherits_parent_entry_when_child_has_none()
	{
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n"
			. '@import url("colours.css?hash=' . $this->hash('/* parent colours */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_empty', 'parent']));
	}

	public function test_assemble_merge_appends_child_import()
	{
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n"
			. '@import url("colours.css?hash=' . $this->hash('/* parent colours */') . "\");\n"
			. '@import url("custom.css?hash=' . $this->hash('/* child custom */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_delta', 'parent']));
	}

	public function test_assemble_merge_child_overrides_component_by_filename()
	{
		// The child overrides colours.css (same file name, no manifest change),
		// and its position in the list is unchanged.
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n"
			. '@import url("colours.css?hash=' . $this->hash('/* child colours */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_override', 'parent']));
	}

	public function test_assemble_merge_deduplicates_redeclared_import()
	{
		// Only one colours.css entry is emitted (resolved to the child's file).
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n"
			. '@import url("colours.css?hash=' . $this->hash('/* child colours */') . "\");\n"
			. '@import url("custom.css?hash=' . $this->hash('/* child custom */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_redeclare', 'parent']));
	}

	public function test_assemble_merge_appends_literal_css_last()
	{
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n"
			. '@import url("colours.css?hash=' . $this->hash('/* parent colours */') . "\");\n"
			. "\n.child { color: green; }\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_literal', 'parent']));
	}

	public function test_assemble_merge_orders_parent_literal_before_child_literal()
	{
		$expected = ".parent { color: blue; }\n.child { color: green; }\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_literal', 'parent_literal']));
	}

	public function test_assemble_replace_ignores_ancestors()
	{
		// base.css from the parent is dropped entirely.
		$expected = '@import url("custom.css?hash=' . $this->hash('/* child custom */') . "\");\n"
			. '@import url("colours.css?hash=' . $this->hash('/* child colours */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_replace', 'parent']));
	}

	public function test_assemble_replace_returns_null_without_child_entry()
	{
		$this->assertNull($this->assembler->assemble(['child_replace_noentry', 'parent']));
	}

	public function test_assemble_replace_still_falls_back_per_file()
	{
		// Replace mode, but the child lists base.css without shipping its own
		// copy, so it falls back to the parent's file.
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_replace_fallback', 'parent']));
	}

	public function test_assemble_three_levels_merge()
	{
		$expected = '@import url("base.css?hash=' . $this->hash('/* grandparent base */') . "\");\n"
			. '@import url("mid.css?hash=' . $this->hash('/* parent mid */') . "\");\n"
			. '@import url("extra.css?hash=' . $this->hash('/* child extra */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_threelevel', 'mid', 'grandparent']));
	}

	public function test_assemble_three_levels_replace_in_middle()
	{
		// The middle style replaces its parent, so base.css must not leak
		// through into the grandchild.
		$expected = '@import url("mid.css?hash=' . $this->hash('/* parent mid */') . "\");\n"
			. '@import url("extra.css?hash=' . $this->hash('/* child extra */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['child_threelevel', 'mid_replace', 'grandparent']));
	}

	public function test_assemble_language_stylesheet()
	{
		$expected = '@import url("lang.css?hash=' . $this->hash('/* parent lang */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['parent'], 'en/'));
	}

	public function test_assemble_preserves_remote_import()
	{
		$expected = '@import url("https://example.com/font.css");' . "\n"
			. '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['parent_remote']));
	}

	public function test_assemble_does_not_resolve_path_traversal_import()
	{
		// The traversal import is neither resolved on the filesystem nor
		// rewritten; it is passed through unchanged.
		$this->assertSame('@import url("../secret.css");' . "\n", $this->assembler->assemble(['parent_traversal']));
	}

	public function test_assemble_preserves_media_query()
	{
		$expected = '@import url("print.css?hash=' . $this->hash('/* parent print */') . "\") print;\n";

		$this->assertSame($expected, $this->assembler->assemble(['parent_media']));
	}

	public function test_assemble_recomputes_existing_hash()
	{
		// The stale hash from the source file is replaced with the fresh one.
		$expected = '@import url("base.css?hash=' . $this->hash('/* parent base */') . "\");\n";

		$this->assertSame($expected, $this->assembler->assemble(['parent_stale_hash']));
	}

	public function test_resolve_component_returns_path_content_and_hash()
	{
		$result = $this->assembler->resolve_component(['child_override', 'parent'], 'colours.css');

		$this->assertNotNull($result);
		$this->assertSame($this->fixtures_root . 'styles/child_override/theme/colours.css', $result['path']);
		$this->assertSame('/* child colours */', $result['content']);
		$this->assertSame($this->hash('/* child colours */'), $result['hash']);
	}

	public function test_resolve_component_returns_null_when_missing()
	{
		$this->assertNull($this->assembler->resolve_component(['parent'], 'missing.css'));
	}

	public function test_assemble_with_dependencies_returns_content_and_files()
	{
		$result = $this->assembler->assemble_with_dependencies(['child_delta', 'parent']);

		$this->assertNotNull($result);
		$this->assertArrayHasKey('content', $result);
		$this->assertArrayHasKey('files', $result);
		$this->assertSame($this->assembler->assemble(['child_delta', 'parent']), $result['content']);

		$existing = $this->fixtures_root . 'styles/parent/theme/base.css';
		$missing = $this->fixtures_root . 'styles/child_delta/theme/base.css';

		// Existing files are recorded with their mtime, missing ones with 0.
		$this->assertArrayHasKey($existing, $result['files']);
		$this->assertSame(filemtime($existing), $result['files'][$existing]);
		$this->assertArrayHasKey($missing, $result['files']);
		$this->assertSame(0, $result['files'][$missing]);
	}

	public function test_assemble_skips_unsafe_style_names()
	{
		$this->assertSame(
			$this->assembler->assemble(['parent']),
			$this->assembler->assemble(['..', '../etc', 'parent'])
		);
	}
}
