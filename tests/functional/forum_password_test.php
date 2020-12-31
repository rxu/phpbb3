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

/**
* @group functional
*/
class phpbb_functional_forum_password_test extends phpbb_functional_test_case
{
	public function test_setup_forum_with_password()
	{
		$this->add_lang(['acp/common', 'acp/forums']);
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', "adm/index.php?i=acp_forums&mode=manage&sid={$this->sid}");
		$this->assertContainsLang('FORUM_ADMIN_EXPLAIN', $this->get_content());

		$forum_name = 'Password protected';
		$form = $crawler->selectButton('addforum')->form(array(
			'forum_name'	=> $forum_name,
		));
		$crawler = self::submit($form);
		$this->assertContainsLang('CREATE_FORUM', $this->get_content());
		$this->assertStringContainsString($forum_name, $this->get_content());

		$form = $crawler->selectButton('update')->form(array(
			'forum_perm_from'		=> 2,
			'forum_password'		=> 'foobar',
			'forum_password_confirm'	=> 'foobar',
		));
		$crawler = self::submit($form);
		$this->assertContainsLang('FORUM_CREATED', $this->get_content());
	}

	public function data_enter_forum_with_password()
	{
		return array(
			array('foowrong', 'WRONG_PASSWORD'),
			array('foobar', 'NO_TOPICS'),
		);
	}

	/**
	 * @depends test_setup_forum_with_password
	 * @dataProvider data_enter_forum_with_password
	 */
	public function test_enter_forum_with_password($password, $message)
	{
		$crawler = self::request('GET', "index.php?sid={$this->sid}");

		preg_match('/.?f=([0-9]+)/', $crawler->selectLink('Password protected')->link()->getUri(), $match);
		$crawler = self::request('GET', "viewforum.php?f={$match[1]}&sid={$this->sid}");

		$this->assertStringContainsString('Password protected', $this->get_content());
		$this->assertContainsLang('LOGIN_FORUM', $this->get_content());

		$form = $crawler->selectButton('login')->form(array(
			'password'	=> $password,
		));
		$crawler = self::submit($form);
		$this->assertContainsLang($message, $crawler->text());
	}
}
