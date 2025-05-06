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
class phpbb_functional_search_postgres_test extends phpbb_functional_search_base
{
	protected $search_backend = '\phpbb\search\fulltext_postgres';


	public function test_search_backend()
	{
		if ($this->db->sql_layer != 'postgres') // PostgreSQL Fulltext only runs on PostgreSQL
		{
			$this->markTestIncomplete('PostgreSQL Fulltext is not supported with other DBMS');
		}
		else
		{
			parent::test_search_backend();
		}
	}
}
