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

namespace
{
	require_once __DIR__ . '/fixtures/ext/vendor/enabled_4/di/extension.php';

	class phpbb_di_create_container_test extends \phpbb_test_case
	{
		protected $config_php;

		/**
		* @var \phpbb\di\container_builder
		*/
		protected $builder;
		protected $phpbb_root_path;
		protected $filename;

		protected function setUp(): void
		{
			$this->phpbb_root_path = __DIR__ . '/';
			$this->config_php = new \phpbb\config_php_file($this->phpbb_root_path . 'fixtures/', 'php');
			$this->builder = new phpbb_mock_phpbb_di_container_builder($this->phpbb_root_path . 'fixtures/', 'php');
			$this->builder->with_config($this->config_php);

			$this->filename = $this->phpbb_root_path . '../tmp/container.php';
			if (is_file($this->filename))
			{
				unlink($this->filename);
			}

			parent::setUp();
		}

		public function test_default_container()
		{
			$container = $this->builder->get_container();
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);
			$this->assertFalse($container->hasParameter('container_exception'));

			// Checks the core services
			$this->assertTrue($container->hasParameter('core'));

			// Checks compile_container
			$this->assertTrue($container->isCompiled());

			// Checks inject_config
			$this->assertTrue($container->hasParameter('core.table_prefix'));

			// Checks use_extensions
			$this->assertTrue($container->hasParameter('enabled'));
			$this->assertTrue($container->hasParameter('enabled_2'));
			$this->assertTrue($container->hasParameter('enabled_3'));
			$this->assertTrue($container->hasParameter('enabled_4'));
			$this->assertFalse($container->hasParameter('disabled'));
			$this->assertFalse($container->hasParameter('available'));

			// Checks set_custom_parameters
			$this->assertTrue($container->hasParameter('core.root_path'));

			// Checks dump_container
			$this->assertTrue(is_file($this->filename));

			// Checks the construction of a dumped container
			$container = $this->builder->get_container();
			$this->assertEquals('phpbb_cache_container', $container::class);
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\Container', $container);
			$this->assertTrue($container->isCompiled());
		}

		public function test_tables_mapping()
		{
			$this->builder->without_cache();
			$container = $this->builder->get_container();
			$this->assertTrue($container->hasParameter('tables'));
			$tables = $container->getParameter('tables');
			$this->assertGreaterThan(0, count($tables));
			$this->assertTrue($container->hasParameter('tables.foo_bar'));
			$this->assertTrue(isset($tables['foo_bar']));
			$this->assertEquals($tables['acl_groups'], 'phpbb_some_other');
		}

		public function test_without_cache()
		{
			$this->builder->without_cache();
			$container = $this->builder->get_container();
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);

			// Checks dump_container
			$this->assertFalse(is_file($this->filename));

			// Checks the construction of a dumped container
			$container = $this->builder->get_container();
			$this->assertNotEquals('phpbb_cache_container', $container::class);
			$this->assertEquals('Symfony\Component\DependencyInjection\ContainerBuilder', $container::class);
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);
			$this->assertTrue($container->isCompiled());
		}

		public function test_without_extensions()
		{
			$this->builder->without_extensions();
			$container = $this->builder->get_container();
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);

			// Checks the core services
			$this->assertTrue($container->hasParameter('core'));

			// Checks use_extensions
			$this->assertFalse($container->hasParameter('enabled'));
			$this->assertFalse($container->hasParameter('disabled'));
			$this->assertFalse($container->hasParameter('available'));
		}

		public function test_without_compiled_container()
		{
			$this->builder->without_compiled_container();
			$container = $this->builder->get_container();
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);

			// Checks compile_container
			$this->assertFalse($container->isCompiled());
		}

		public function test_with_config_path()
		{
			$this->builder->with_config_path($this->phpbb_root_path . 'fixtures/other_config/');
			$container = $this->builder->get_container();
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);

			$this->assertTrue($container->hasParameter('other_config'));
			$this->assertFalse($container->hasParameter('core'));
		}

		public function test_with_custom_parameters()
		{
			$this->builder->with_custom_parameters(array('my_parameter' => true));
			$container = $this->builder->get_container();
			$this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerBuilder', $container);

			$this->assertTrue($container->hasParameter('my_parameter'));
		}
	}
}

namespace phpbb\extension
{
	class manager_mock extends \phpbb\extension\manager
	{
		public function __construct()
		{
		}

		public function all_enabled($phpbb_relative = true)
		{
			return array(
				'vendor/enabled' => __DIR__ . '/fixtures/ext/vendor/enabled/',
				'vendor/enabled-2' => __DIR__ . '/fixtures/ext/vendor/enabled-2/',
				'vendor/enabled-3' => __DIR__ . '/fixtures/ext/vendor/enabled-3/',
				'vendor/enabled_4' => __DIR__ . '/fixtures/ext/vendor/enabled_4/',
			);
		}
	}
}
