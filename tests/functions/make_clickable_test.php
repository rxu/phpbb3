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

class phpbb_functions_make_clickable_test extends phpbb_test_case
{
	/**
	* Tags:
	* 'm' - full URL like xxxx://aaaaa.bbb.cccc.
	* 'l' - local relative board URL like http://domain.tld/path/to/board/index.php
	* 'w' - URL without http/https protocol like www.xxxx.yyyy[/zzzz] aka 'lazy' URLs
	* 'e' - email@domain type address
	*
	* Classes:
	* "postlink-local" for 'l' URLs
	* "postlink" for the rest of URLs
	* empty for email addresses
	**/
	public static function data_test_make_clickable_url_positive()
	{
		return [
			[
				'http://www.phpbb.com/community/',
				'<!-- m --><a class="postlink" href="http://www.phpbb.com/community/">http://www.phpbb.com/community/</a><!-- m -->'
			],
			[
				'http://www.phpbb.com/path/file.ext#section',
				'<!-- m --><a class="postlink" href="http://www.phpbb.com/path/file.ext#section">http://www.phpbb.com/path/file.ext#section</a><!-- m -->'
			],
			[
				'ftp://ftp.phpbb.com/',
				'<!-- m --><a class="postlink" href="ftp://ftp.phpbb.com/">ftp://ftp.phpbb.com/</a><!-- m -->'
			],
			[
				'sip://bantu@phpbb.com',
				'<!-- m --><a class="postlink" href="sip://bantu@phpbb.com">sip://bantu@phpbb.com</a><!-- m -->'
			],
			[
				'www.phpbb.com/community/',
				'<!-- w --><a class="postlink" href="http://www.phpbb.com/community/">www.phpbb.com/community/</a><!-- w -->'
			],
			[
				'http://testhost/viewtopic.php?t=1',
				'<!-- l --><a class="postlink-local" href="http://testhost/viewtopic.php?t=1">viewtopic.php?t=1</a><!-- l -->'
			],
			[
				'javascript://testhost/viewtopic.php?t=1',
				'javascript://testhost/viewtopic.php?t=1'
			],
			[
				"java\nscri\npt://testhost/viewtopic.php?t=1",
				"java\nscri\n<!-- m --><a class=\"postlink\" href=\"pt://testhost/viewtopic.php?t=1\">pt://testhost/viewtopic.php?t=1</a><!-- m -->"
			],
			[
				'email@domain.com',
				'<!-- e --><a href="mailto:email@domain.com">email@domain.com</a><!-- e -->'
			],
			// Test appending punctuation mark to the URL
			[
				'http://testhost/viewtopic.php?t=1!',
				'<!-- l --><a class="postlink-local" href="http://testhost/viewtopic.php?t=1">viewtopic.php?t=1</a><!-- l -->!'
			],
			[
				'www.phpbb.com/community/?',
				'<!-- w --><a class="postlink" href="http://www.phpbb.com/community/">www.phpbb.com/community/</a><!-- w -->?'
			],
			// Test shortened text for URL > 55 characters long
			// URL text should be turned into: first 39 chars + ' ... ' + last 10 chars
			[
				'http://www.phpbb.com/community/path/to/long/url/file.ext#section',
				'<!-- m --><a class="postlink" href="http://www.phpbb.com/community/path/to/long/url/file.ext#section">http://www.phpbb.com/community/path/to/ ... xt#section</a><!-- m -->'
			],
		];
	}

	public static function data_test_make_clickable_url_idn()
	{
		return [
			[
				'http://www.täst.de/community/',
				'<!-- m --><a class="postlink" href="http://www.täst.de/community/">http://www.täst.de/community/</a><!-- m -->'
			],
			[
				'http://www.täst.de/path/file.ext#section',
				'<!-- m --><a class="postlink" href="http://www.täst.de/path/file.ext#section">http://www.täst.de/path/file.ext#section</a><!-- m -->'
			],
			[
				'ftp://ftp.täst.de/',
				'<!-- m --><a class="postlink" href="ftp://ftp.täst.de/">ftp://ftp.täst.de/</a><!-- m -->'
			],
			[
				'javascript://täst.de/',
				'javascript://täst.de/'
			],
			[
				'sip://bantu@täst.de',
				'<!-- m --><a class="postlink" href="sip://bantu@täst.de">sip://bantu@täst.de</a><!-- m -->'
			],
			[
				'www.täst.de/community/',
				'<!-- w --><a class="postlink" href="http://www.täst.de/community/">www.täst.de/community/</a><!-- w -->'
			],
			// Test appending punctuation mark to the URL
			[
				'http://домен.рф/viewtopic.php?t=1!',
				'<!-- m --><a class="postlink" href="http://домен.рф/viewtopic.php?t=1">http://домен.рф/viewtopic.php?t=1</a><!-- m -->!'
			],
			[
				'www.домен.рф/сообщество/?',
				'<!-- w --><a class="postlink" href="http://www.домен.рф/сообщество/">www.домен.рф/сообщество/</a><!-- w -->?'
			],
			// Test shortened text for URL > 55 characters long
			// URL text should be turned into: first 39 chars + ' ... ' + last 10 chars
			[
				'http://www.домен.рф/сообщество/путь/по/длинной/ссылке/file.ext#section',
				'<!-- m --><a class="postlink" href="http://www.домен.рф/сообщество/путь/по/длинной/ссылке/file.ext#section">http://www.домен.рф/сообщество/путь/по/ ... xt#section</a><!-- m -->'
			],

			// IDN with invalid characters shouldn't be parsed correctly (only 'valid' part)
			[
				'http://www.täst╫.de',
				'<!-- m --><a class="postlink" href="http://www.täst">http://www.täst</a><!-- m -->╫.de'
			],
			// IDN in emails is unsupported yet
			['почта@домен.рф', 'почта@домен.рф'],
		];
	}

	public static function data_test_make_clickable_local_url_idn()
	{
		return [
			[
				'http://www.домен.рф/viewtopic.php?t=1',
				'<!-- l --><a class="postlink-local" href="http://www.домен.рф/viewtopic.php?t=1">viewtopic.php?t=1</a><!-- l -->'
			],
			// Test appending punctuation mark to the URL
			[
				'http://www.домен.рф/viewtopic.php?t=1!',
				'<!-- l --><a class="postlink-local" href="http://www.домен.рф/viewtopic.php?t=1">viewtopic.php?t=1</a><!-- l -->!'
			],
			[
				'http://www.домен.рф/сообщество/?',
				'<!-- l --><a class="postlink-local" href="http://www.домен.рф/сообщество/">сообщество/</a><!-- l -->?'
			],
		];
	}

	public static function data_test_make_clickable_custom_classes()
	{
		return [
			[
				'http://www.домен.рф/viewtopic.php?t=1',
				'http://www.домен.рф',
				'class1',
				'<!-- l --><a class="class1-local" href="http://www.домен.рф/viewtopic.php?t=1">viewtopic.php?t=1</a><!-- l -->'
			],
			[
				'http://www.домен.рф/viewtopic.php?t=1!',
				false,
				'class2',
				'<!-- m --><a class="class2" href="http://www.домен.рф/viewtopic.php?t=1">http://www.домен.рф/viewtopic.php?t=1</a><!-- m -->!'
			],
			[
				'http://www.домен.рф/сообщество/?',
				false,
				'class3',
				'<!-- m --><a class="class3" href="http://www.домен.рф/сообщество/">http://www.домен.рф/сообщество/</a><!-- m -->?'
			],
			[
				'www.phpbb.com/community/',
				false,
				'class2',
				'<!-- w --><a class="class2" href="http://www.phpbb.com/community/">www.phpbb.com/community/</a><!-- w -->'
			],
			[
				'http://testhost/viewtopic.php?t=1',
				false,
				'class1',
				'<!-- l --><a class="class1-local" href="http://testhost/viewtopic.php?t=1">viewtopic.php?t=1</a><!-- l -->'
			],
			[
				'email@domain.com',
				false,
				'class-email',
				'<!-- e --><a href="mailto:email@domain.com">email@domain.com</a><!-- e -->'
			],
		];
	}

	protected function setUp(): void
	{
		parent::setUp();

		global $user, $request, $symfony_request, $config;
		$config = new \phpbb\config\config([
			'force_server_vars' => 0,
			'server_name' => 'testhost',
		]);
		$user = new phpbb_mock_user();
		$request = new phpbb_mock_request();
		$symfony_request = new \phpbb\symfony_request($request);
	}

	/**
	 * @dataProvider data_test_make_clickable_url_positive
	 * @dataProvider data_test_make_clickable_url_idn
	 */
	public function test_urls_matching_positive($url, $expected)
	{
		global $user, $request, $symfony_request, $config;
		$this->assertSame($expected, make_clickable($url));
	}

	/**
	 * @dataProvider data_test_make_clickable_local_url_idn
	 */
	public function test_local_urls_matching_idn($url, $expected)
	{
		global $user, $request, $symfony_request, $config;
		$this->assertSame($expected, make_clickable($url, "http://www.домен.рф"));
	}

	/**
	 * @dataProvider data_test_make_clickable_custom_classes
	 */
	public function test_make_clickable_custom_classes($url, $server_url, $class, $expected)
	{
		global $user, $request, $symfony_request, $config;
		$this->assertSame($expected, make_clickable($url, $server_url, $class));
	}
}
