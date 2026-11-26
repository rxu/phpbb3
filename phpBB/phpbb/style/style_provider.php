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

use phpbb\db\driver\driver_interface;

/**
 * Resolves the inheritance tree of a style into an ordered list of style
 * paths, most specific first (the style itself, then its ancestors).
 */
class style_provider
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $styles_table;

	/**
	 * Constructor
	 *
	 * @param driver_interface $db           Database driver
	 * @param string           $styles_table Name of the styles table
	 */
	public function __construct(driver_interface $db, string $styles_table)
	{
		$this->db = $db;
		$this->styles_table = $styles_table;
	}

	/**
	 * Get the ordered style list (most specific first) for a given style path.
	 *
	 * @param string $style_path Style path (e.g. "prosilver")
	 * @return string[] Style paths, most specific first. Empty when the style is unknown.
	 */
	public function get_style_list(string $style_path): array
	{
		$sql = 'SELECT style_path, style_parent_id, style_parent_tree
			FROM ' . $this->styles_table . "
			WHERE style_path = '" . $this->db->sql_escape($style_path) . "'";
		$result = $this->db->sql_query($sql, 3600);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return [];
		}

		$style_list = [$row['style_path']];

		if ($row['style_parent_id'] && $row['style_parent_tree'] !== '')
		{
			$style_list = array_merge($style_list, array_reverse(explode('/', $row['style_parent_tree'])));
		}

		return $style_list;
	}
}
