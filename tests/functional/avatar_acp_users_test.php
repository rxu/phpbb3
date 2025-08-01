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
class phpbb_functional_avatar_acp_users_test extends phpbb_functional_common_avatar_test_case
{
	public function get_url()
	{
		return 'adm/index.php?i=acp_users&u=2&mode=avatar';
	}

	public static function avatar_acp_users_data()
	{
		return array(
			// Gravatar with incorrect email
			array(
				'EMAIL_INVALID_EMAIL',
				'avatar_driver_gravatar',
				array(
					'avatar_gravatar_email'		=> 'test.example.com',
					'avatar_gravatar_width'		=> 80,
					'avatar_gravatar_height'	=> 80,
				),
			),
			// Remote avatar with correct link
			array(
				'USER_AVATAR_UPDATED',
				'avatar_driver_gravatar',
				array(
					'avatar_gravatar_email'		=> 'test@example.com',
					'avatar_gravatar_width'		=> 80,
					'avatar_gravatar_height'	=> 80,
				),
			),
			// Reset avatar settings
			array(
				array('CONFIRM_AVATAR_DELETE', 'USER_AVATAR_UPDATED'),
				'avatar_driver_gravatar',
				array(
					'avatar_delete'	=> array('tick', ''),
				),
			),
		);
	}

	/**
	* @dataProvider avatar_acp_users_data
	*/
	public function test_avatar_acp_users($expected, $avatar_type, $data)
	{
		$this->assert_avatar_submit($expected, $avatar_type, $data);
	}
}
