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

require_once __DIR__ . '/template_test_case_with_tree.php';

class phpbb_template_template_includejs_test extends phpbb_template_template_test_case_with_tree
{
	public static function template_data()
	{
		return array(
			/*
			array(
				// vars
				// expected
			),
			*/
			array(
				array('TEST' => 1),
				'<script src="tests/template/templates/parent_and_child.js?assets_version=1"></script>',
			),
			array(
				array('TEST' => 2),
				'<script src="tests/template/templates/parent_and_child.js?assets_version=0"></script>',
			),
			array(
				array('TEST' => 3),
				'<script src="tests/template/templates/parent_and_child.js?test=1&assets_version=0"></script>',
			),
			array(
				array('TEST' => 4),
				'<script src="tests/template/templates/parent_and_child.js?test=1&amp;assets_version=0"></script>',
			),
			array(
				array('TEST' => 6),
				'<script src="tests/template/parent_templates/parent_only.js?assets_version=1"></script>',
			),
			array(
				array('TEST' => 7),
				'<script src="tests/template/templates/child_only.js?assets_version=1"></script>',
			),
			array(
				array('TEST' => 8),
				'<script src="tests/template/templates/subdir/parent_only.js?assets_version=1"></script>',
			),
			array(
				array('TEST' => 9),
				'<script src="tests/template/templates/subdir/subsubdir/parent_only.js?assets_version=1"></script>',
			),
			array(
				array('TEST' => 10),
				'<script src="tests/template/templates/subdir/parent_only.js?assets_version=1"></script>',
			),
			array(
				array('TEST' => 11),
				'<script src="tests/template/templates/child_only.js?test1=1&amp;test2=2&amp;assets_version=1#test3"></script>',
			),
			array(
				array('TEST' => 12),
				'<script src="tests/template/parent_templates/parent_only.js?test1=1&amp;test2=2&amp;assets_version=1#test3"></script>',
			),
			array(
				array('TEST' => 14),
				'<script src="tests/template/parent_templates/parent_only.js?test1=&quot;&amp;assets_version=1#test3"></script>',
			),
			array(
				array('TEST' => 15),
				'<script src="http://phpbb.com/b.js?c=d#f"></script>',
			),
			array(
				array('TEST' => 16),
				'<script src="http://phpbb.com/b.js?c=d&assets_version=2#f"></script>',
			),
			array(
				array('TEST' => 17),
				'<script src="//phpbb.com/b.js"></script>',
			),
			array(
				array('TEST' => 18),
				'<script src="tests/template/templates/parent_and_child.js?test=1&test2=0&amp;assets_version=1"></script>',
			),
		);
	}

	/**
	* @dataProvider template_data
	*/
	public function test_includejs_compilation($vars, $expected)
	{
		// Reset the engine state
		$this->setup_engine(array('assets_version' => 1));

		$this->template->assign_vars($vars);

		// Run test
		$this->run_template('includejs.html', array_merge(array('PARENT' => 'parent_only.js', 'SUBDIR' => 'subdir', 'EXT' => 'js'), $vars), array(), array(), $expected);
	}

	/**
	 * @dataProvider template_data
	 */
	public function test_include_js_compilation($vars, $expected)
	{
		// Reset the engine state
		$this->setup_engine(array('assets_version' => 1));

		$this->template->assign_vars($vars);

		// Run test
		$this->run_template('includejs_twig.html', array_merge(array('PARENT' => 'parent_only.js', 'SUBDIR' => 'subdir', 'EXT' => 'js'), $vars), array(), array(), $expected);
	}
}
