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

require_once __DIR__ . '/base.php';

/**
* @group functional
*/
class phpbb_functional_search_mysql_test extends phpbb_functional_search_base
{
	protected $search_backend = '\phpbb\search\fulltext_mysql';

	protected function create_search_index($backend = null)
	{
		parent::create_search_index($backend);

		// Try optimizing posts table after creating search index.
		// Some versions of MariaDB might not return any results in the search
		// until the table has been optimized or the index deleted and re-created.
		$db = $this->get_db();
		$db->sql_return_on_error(true);
		$sql = 'OPTIMIZE TABLE ' . POSTS_TABLE;
		$db->sql_query($sql);
		$db->sql_return_on_error(false);
	}

	public function test_search_backend()
	{
		if ($this->db->sql_layer != 'mysqli') // MySQL Fulltext only runs on MySQL/MariaDB
		{
			$this->markTestIncomplete('MySQL Fulltext is not supported with other DBMS');
		}
		else
		{
			parent::test_search_backend();
		}
	}
}
