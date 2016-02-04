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

require_once dirname(__FILE__) . '/../../phpBB/includes/functions.php';

class phpbb_dbal_database_update_test extends phpbb_database_test_case
{
	protected $db;
	protected $db_tools;
	protected $migrator;

	public function getDataSet()
	{
		return $this->createMySQLXMLDataSet(dirname(__FILE__).'/fixtures/phpbb_30_vanilla_database.xml');
	}

	static public function setUpBeforeClass()
	{
		global $phpbb_root_path, $phpEx, $table_prefix;

/*		$classes = array(
		    '\phpbb\db\migration\data\v30x\release_3_0_0',
		    '\phpbb\db\migration\data\v310\rename_too_long_indexes',
		    '\phpbb\db\migration\data\v310\rename_too_long_indexes2',
		    '\phpbb\db\migration\data\v310\migrations_table',
		);
*/
		$finder = new \phpbb\finder(new \phpbb\filesystem(), $phpbb_root_path, null, $phpEx);
		$finder->core_path('phpbb/db/migration/data/v30x/');
		$schema_sha1 = sha1(serialize($classes));
		$classes = array_merge($finder->get_classes(), array(
		    '\phpbb\db\migration\data\v310\rename_too_long_indexes',
		    '\phpbb\db\migration\data\v310\rename_too_long_indexes2',
		    '\phpbb\db\migration\data\v310\migrations_table',
		));

		self::$schema_file = __DIR__ . '/../tmp/' . $schema_sha1 . '.json';
		self::$install_schema_file = __DIR__ . '/../../phpBB/install/schemas/schema.json';

		$db = new \phpbb\db\driver\sqlite();
		$schema_generator = new \phpbb\db\migration\schema_generator($classes, new \phpbb\config\config(array()), $db, new \phpbb\db\tools($db, true), $phpbb_root_path, $phpEx, $table_prefix);
		file_put_contents(self::$schema_file, json_encode($schema_generator->get_schema()));

		copy(self::$schema_file, self::$install_schema_file);

		PHPUnit_Extensions_Database_TestCase::setUpBeforeClass();
	}

	public function setUp()
	{
		parent::setUp();

		$this->db = $this->new_dbal();
		$this->db_tools = new \phpbb\db\tools($this->db);

		$this->config = new \phpbb\config\db($this->db, new phpbb_mock_cache, 'phpbb_config');

		$tools = array(
			new \phpbb\db\migration\tool\config($this->config),
		);

		$container = new phpbb_mock_container_builder();

		$this->migrator = new \phpbb\db\migrator(
			$container,
			$this->config,
			$this->db,
			$this->db_tools,
			'phpbb_migrations',
			dirname(__FILE__) . '/../../phpBB/',
			'php',
			'phpbb_',
			$tools,
			new \phpbb\db\migration\helper()
		);
		$container->set('migrator', $this->migrator);
		$container->set('dispatcher', new phpbb_mock_event_dispatcher());
		$user = new \phpbb\user('\phpbb\datetime');

		$this->extension_manager = new \phpbb\extension\manager(
			$container,
			$this->db,
			$this->config,
			new phpbb\filesystem(),
			$user,
			'phpbb_ext',
			dirname(__FILE__) . '/../../phpBB/',
			'php',
			null
		);
	}

    public function getConnection()
    {
		$config = $this->get_database_config();
		$manager = $this->create_connection_manager($config);
		$manager->recreate_db();
		$manager->connect();
	    $manager->load_schema($this->new_dbal());

		return $this->createDefaultDBConnection($manager->get_pdo(), 'testdb');
    }

	public function test_update()
	{
		$migrations = $this->extension_manager
			->get_finder()
			->core_path('phpbb/db/migration/data/')
			->get_classes();

		$this->migrator->set_migrations($migrations);

		$update_start_time = time();

		while (!$this->migrator->finished())
		{
			try
			{
				$this->migrator->update();
				$this->assertFalse($this->migrator->finished());
			}
			catch (\phpbb\db\migration\exception $e)
			{

			}
		}

		$this->assertTrue($this->migrator->finished());
	}
}
