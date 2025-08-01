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

require_once __DIR__ . '/common_avatar_test_case.php';

/**
 * @group functional
 */
class phpbb_functional_avatar_ucp_users_test extends phpbb_functional_common_avatar_test_case
{
	public function get_url()
	{
		return 'ucp.php?i=ucp_profile&mode=avatar';
	}

	public static function avatar_ucp_groups_data()
	{
		return array(
			// Gravatar with correct settings
			array(
				'PROFILE_UPDATED',
				'avatar_driver_gravatar',
				array(
					'avatar_gravatar_email'		=> 'test@example.com',
					'avatar_gravatar_width'		=> 80,
					'avatar_gravatar_height'	=> 80,
				),
			),

			array(
				array('CONFIRM_AVATAR_DELETE', 'PROFILE_UPDATED'),
				'avatar_driver_gravatar',
				array(
					'avatar_delete'	=> array('tick', ''),
				),
			),
		);
	}

	/**
	* @dataProvider avatar_ucp_groups_data
	*/
	public function test_avatar_ucp_groups($expected, $avatar_type, $data)
	{
		$this->assert_avatar_submit($expected, $avatar_type, $data);
	}

	public function test_display_upload_avatar()
	{
		$this->assert_avatar_submit('PROFILE_UPDATED',
			'avatar_driver_gravatar',
			array(
				'avatar_gravatar_email'		=> 'test@example.com',
				'avatar_gravatar_width'		=> 80,
				'avatar_gravatar_height'	=> 80,
			),
		);

		$crawler = self::request('GET', $this->get_url() . '&sid=' . $this->sid);
		$avatar_link = $crawler->filter('img')->attr('src');
		self::request('GET', $avatar_link . '&sid=' . $this->sid, array(), false);
		$content = self::$client->getResponse()->getContent();
		self::assertEquals(false, stripos(trim($content), 'debug'), 'Output contains debug message');
	}
}
