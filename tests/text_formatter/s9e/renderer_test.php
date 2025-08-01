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

class phpbb_textformatter_s9e_renderer_test extends phpbb_test_case
{
	public function get_cache_dir()
	{
		return __DIR__ . '/../../tmp/';
	}

	public function test_load_from_cache()
	{
		// Save a fake renderer class in the cache dir
		file_put_contents(
			$this->get_cache_dir() . 'renderer_foo.php',
			'<?php class renderer_foo { public function setParameter() {} }'
		);

		$cache = $this->createMock('phpbb_mock_cache');
		$cache->expects($this->once())
		      ->method('get')
		      ->with('_foo_renderer')
		      ->will($this->returnValue(array('class' => 'renderer_foo')));

		$factory = $this->getMockBuilder('phpbb\\textformatter\\s9e\\factory')
		                ->disableOriginalConstructor()
		                ->getMock();
		$factory->expects($this->never())->method('regenerate');

		$renderer = new \phpbb\textformatter\s9e\renderer(
			$cache,
			$this->get_cache_dir(),
			'_foo_renderer',
			$factory,
			new phpbb_mock_event_dispatcher
		);
	}

	public function test_regenerate_on_cache_miss()
	{
		$mock = $this->getMockForAbstractClass('s9e\\TextFormatter\\Renderer');

		$cache = $this->createMock('phpbb_mock_cache');
		$cache->expects($this->once())
		      ->method('get')
		      ->with('_foo_renderer')
		      ->will($this->returnValue(false));

		$factory = $this->getMockBuilder('phpbb\\textformatter\\s9e\\factory')
		                ->disableOriginalConstructor()
		                ->getMock();
		$factory->expects($this->once())
		        ->method('regenerate')
		        ->will($this->returnValue([
					'parser' => $mock,
					'renderer' => $mock,
				]));

		$renderer = new \phpbb\textformatter\s9e\renderer(
			$cache,
			$this->get_cache_dir(),
			'_foo_renderer',
			$factory,
			new phpbb_mock_event_dispatcher
		);
	}

	/**
	* @dataProvider get_options_cases
	*/
	public function test_options($original, $expected, $calls)
	{
		$container = new phpbb_mock_container_builder;
		$this->get_test_case_helpers()->set_s9e_services($container);

		$renderer = $container->get('text_formatter.renderer');

		foreach ($calls as $method => $arg)
		{
			$renderer->$method($arg);
		}

		$this->assertSame($expected, $renderer->render($original));
	}

	public static function get_options_cases()
	{
		return array(
			array(
				'<t>apple</t>',
				'banana',
				array('set_viewcensors' => true)
			),
			array(
				'<t>apple</t>',
				'apple',
				array('set_viewcensors' => false)
			),
			array(
				'<r><IMG src="http://example.org/foo.png"><s>[img]</s>http://example.org/foo.png<e>[/img]</e></IMG></r>',
				'<img src="http://example.org/foo.png" class="postimage" alt="Image">',
				array('set_viewimg' => true)
			),
			array(
				'<r><E>:)</E></r>',
				'<img class="smilies" src="phpBB/images/smilies/icon_e_smile.gif" width="15" height="17" alt=":)" title="Smile">',
				array('set_viewsmilies' => true)
			),
			array(
				'<r><E>:)</E></r>',
				':)',
				array('set_viewsmilies' => false)
			),
		);
	}

	/**
	* @dataProvider get_default_options_cases
	*/
	public function test_default_options($original, $expected, $setup = null)
	{
		$container = new phpbb_mock_container_builder;

		if (isset($setup))
		{
			$setup($container, $this);
		}

		$this->get_test_case_helpers()->set_s9e_services($container);

		$this->assertSame($expected, $container->get('text_formatter.renderer')->render($original));
	}

	public static function get_default_options_cases()
	{
		return array(
			array(
				'<t>apple</t>',
				'banana'
			),
			array(
				'<t>apple</t>',
				'banana',
				function ($phpbb_container)
				{
					global $phpbb_root_path, $phpEx;

					$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
					$lang = new \phpbb\language\language($lang_loader);
					$user = new \phpbb\user($lang, '\phpbb\datetime');
					$user->data['user_options'] = 230271;
					$user->optionset('viewcensors', false);

					$phpbb_container->set('user', $user);
				}
			),
			array(
				'<t>apple</t>',
				'banana',
				function ($phpbb_container)
				{
					global $phpbb_root_path, $phpEx;

					$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
					$lang = new \phpbb\language\language($lang_loader);
					$user = new \phpbb\user($lang, '\phpbb\datetime');
					$user->data['user_options'] = 230271;
					$user->optionset('viewcensors', false);

					$config = new \phpbb\config\config(array('allow_nocensors' => true));

					$phpbb_container->set('user', $user);
					$phpbb_container->set('config', $config);
				}
			),
			array(
				'<t>apple</t>',
				'apple',
				function ($phpbb_container, $test)
				{
					global $phpbb_root_path, $phpEx;

					$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
					$lang = new \phpbb\language\language($lang_loader);
					$user = new \phpbb\user($lang, '\phpbb\datetime');
					$user->data['user_options'] = 230271;
					$user->optionset('viewcensors', false);

					$config = new \phpbb\config\config(array('allow_nocensors' => true));

					$auth = $test->createMock('phpbb\\auth\\auth');
					$auth->expects($test->any())
					     ->method('acl_get')
					     ->with('u_chgcensors')
					     ->will($test->returnValue(true));

					$phpbb_container->set('user', $user);
					$phpbb_container->set('config', $config);
					$phpbb_container->set('auth', $auth);
				}
			),
			array(
				'<r><IMG src="http://localhost/mrgreen.gif"><s>[img]</s><URL url="http://localhost/mrgreen.gif">http://localhost/mrgreen.gif</URL><e>[/img]</e></IMG></r>',
				'<img src="http://localhost/mrgreen.gif" class="postimage" alt="Image">'
			),
			array(
				'<r><IMG src="http://localhost/mrgreen.gif"><s>[img]</s><URL url="http://localhost/mrgreen.gif">http://localhost/mrgreen.gif</URL><e>[/img]</e></IMG></r>',
				'<a href="http://localhost/mrgreen.gif" class="postlink">http://localhost/mrgreen.gif</a>',
				function ($phpbb_container)
				{
					global $phpbb_root_path, $phpEx;

					$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
					$lang = new \phpbb\language\language($lang_loader);
					$user = new \phpbb\user($lang, '\phpbb\datetime');
					$user->data['user_options'] = 230271;
					$user->optionset('viewimg', false);

					$phpbb_container->set('user', $user);
				}
			),
			array(
				'<r><E>:)</E></r>',
				'<img class="smilies" src="phpBB/images/smilies/icon_e_smile.gif" width="15" height="17" alt=":)" title="Smile">'
			),
			array(
				'<r><E>:)</E></r>',
				':)',
				function ($phpbb_container)
				{
					global $phpbb_root_path, $phpEx;

					$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
					$lang = new \phpbb\language\language($lang_loader);
					$user = new \phpbb\user($lang, '\phpbb\datetime');
					$user->data['user_options'] = 230271;
					$user->optionset('viewsmilies', false);

					$phpbb_container->set('user', $user);
				}
			),
		);
	}

	public function test_default_lang()
	{
		global $phpbb_container;
		$this->get_test_case_helpers()->set_s9e_services($phpbb_container, __DIR__ . '/fixtures/default_lang.xml');

		$renderer = $phpbb_container->get('text_formatter.renderer');

		$this->assertSame('FOO_BAR', $renderer->render('<r><FOO/></r>'));
	}

	/**
	* @dataProvider get_option_names
	*/
	public function test_get_option($option_name)
	{
		global $phpbb_container;
		$this->get_test_case_helpers()->set_s9e_services();

		$renderer = $phpbb_container->get('text_formatter.renderer');

		$renderer->{'set_' . $option_name}(false);
		$this->assertFalse($renderer->{'get_' . $option_name}());
		$renderer->{'set_' . $option_name}(true);
		$this->assertTrue($renderer->{'get_' . $option_name}());
	}

	public static function get_option_names()
	{
		return array(
			array('viewcensors'),
			array('viewimg'),
			array('viewsmilies')
		);
	}

	public function test_styles()
	{
		global $phpbb_container;

		$tests = array(
			1 => '<strong>bold</strong>',
			2 => '<b>bold</b>'
		);

		global $phpbb_root_path, $phpEx;

		foreach ($tests as $style_id => $expected)
		{
			$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
			$lang = new \phpbb\language\language($lang_loader);
			$user = new \phpbb\user($lang, '\phpbb\datetime');
			$user->style = array('style_id' => $style_id);

			$phpbb_container = new phpbb_mock_container_builder;
			$phpbb_container->set('user', $user);

			$this->get_test_case_helpers()->set_s9e_services($phpbb_container, __DIR__ . '/fixtures/styles.xml', __DIR__ . '/fixtures/styles/');

			$renderer = $phpbb_container->get('text_formatter.renderer');
			$this->assertSame(
				$expected,
				$renderer->render('<r><B><s>[b]</s>bold<e>[/b]</e></B></r>')
			);
		}
	}

	public function test_style_inheritance1()
	{
		global $phpbb_container, $phpbb_root_path, $phpEx;

		// Style 3 inherits from 2 which inherits from 1. Only style 1 has a bbcode.html
		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$lang = new \phpbb\language\language($lang_loader);
		$user = new \phpbb\user($lang, '\phpbb\datetime');
		$user->style = array('style_id' => 3);

		$phpbb_container = new phpbb_mock_container_builder;
		$phpbb_container->set('user', $user);

		$this->get_test_case_helpers()->set_s9e_services($phpbb_container, __DIR__ . '/fixtures/style_inheritance.xml', __DIR__ . '/fixtures/styles/');

		$renderer = $phpbb_container->get('text_formatter.renderer');
		$this->assertSame(
			'<strong>bold</strong>',
			$renderer->render('<r><B><s>[b]</s>bold<e>[/b]</e></B></r>')
		);
	}

	public function test_style_inheritance2()
	{
		global $phpbb_container, $phpbb_root_path, $phpEx;

		// Style 5 inherits from 4, but both have a bbcode.html
		$tests = array(
			4 => '<b>bold</b>',
			5 => '<b class="barplus">bold</b>'
		);

		foreach ($tests as $style_id => $expected)
		{
			$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
			$lang = new \phpbb\language\language($lang_loader);
			$user = new \phpbb\user($lang, '\phpbb\datetime');
			$user->style = array('style_id' => $style_id);

			$phpbb_container = new phpbb_mock_container_builder;
			$phpbb_container->set('user', $user);

			$this->get_test_case_helpers()->set_s9e_services($phpbb_container, __DIR__ . '/fixtures/style_inheritance.xml', __DIR__ . '/fixtures/styles/');

			$renderer = $phpbb_container->get('text_formatter.renderer');
			$this->assertSame(
				$expected,
				$renderer->render('<r><B><s>[b]</s>bold<e>[/b]</e></B></r>')
			);
		}
	}

	/**
	* @testdox The constructor triggers a core.text_formatter_s9e_renderer_setup event
	*/
	public function test_setup_event()
	{
		$container = $this->get_test_case_helpers()->set_s9e_services();
		$dispatcher = $this->createMock('phpbb\\event\\dispatcher_interface');
		$dispatcher
			->expects($this->once())
			->method('trigger_event')
			->with(
				'core.text_formatter_s9e_renderer_setup',
				$this->callback(array($this, 'setup_event_callback'))
			)
			->will($this->returnArgument(1));

		new \phpbb\textformatter\s9e\renderer(
			$container->get('cache.driver'),
			$container->getParameter('cache.dir'),
			'_foo_renderer',
			$container->get('text_formatter.s9e.factory'),
			$dispatcher
		);
	}

	public function setup_event_callback($vars)
	{
		return isset($vars['renderer'])
			&& $vars['renderer'] instanceof \phpbb\textformatter\s9e\renderer;
	}

	/**
	* @testdox render() triggers a core.text_formatter_s9e_render_before and core.text_formatter_s9e_render_after events
	*/
	public function test_render_event()
	{
		$container = $this->get_test_case_helpers()->set_s9e_services();
		$dispatcher = $this->createMock('phpbb\\event\\dispatcher_interface');
		$dispatcher
			->expects($this->any())
			->method('trigger_event')
			->will($this->returnArgument(1));

		$renderer = new \phpbb\textformatter\s9e\renderer(
			$container->get('cache.driver'),
			$container->getParameter('cache.dir'),
			'_foo_renderer',
			$container->get('text_formatter.s9e.factory'),
			$dispatcher
		);

		$matcher = $this->exactly(2);
		$dispatcher
			->expects($matcher)
			->method('trigger_event')
			->willReturnCallback(function($event, $vars) use ($matcher) {
				$callNr = $matcher->numberOfInvocations();
				match($callNr) {
					1 => $this->assertEquals('core.text_formatter_s9e_render_before', $event),
					2 => $this->assertEquals('core.text_formatter_s9e_render_after', $event),
				};
				match($callNr) {
					1 => $this->assertTrue($this->render_before_event_callback($vars)),
					2 => $this->assertTrue($this->render_after_event_callback($vars)),
				};
				return $vars;
			});
		$renderer->render('<t>...</t>');
	}

	public function render_before_event_callback($vars)
	{
		return isset($vars['renderer'])
			&& $vars['renderer'] instanceof \phpbb\textformatter\s9e\renderer
			&& isset($vars['text'])
			&& $vars['text'] === '<t>...</t>';
	}

	public function render_after_event_callback($vars)
	{
		return isset($vars['html'])
			&& $vars['html'] === '...'
			&& isset($vars['renderer'])
			&& $vars['renderer'] instanceof \phpbb\textformatter\s9e\renderer;
	}

	public function test_get_renderer()
	{
		$container = $this->get_test_case_helpers()->set_s9e_services();
		$renderer = $container->get('text_formatter.renderer');
		$this->assertInstanceOf('s9e\\TextFormatter\\Renderer', $renderer->get_renderer());
	}
}
