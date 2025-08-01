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

require_once __DIR__ . '/submit_post_base.php';

class phpbb_notification_submit_post_type_mention_test extends phpbb_notification_submit_post_base
{
	protected $item_type = 'notification.type.mention';

	public function setUp(): void
	{
		global $auth, $cache, $config, $db, $phpbb_container, $phpbb_dispatcher, $lang, $user, $request, $phpEx, $phpbb_root_path, $user_loader, $phpbb_log;

		parent::setUp();

		// Add additional permissions
		$auth->expects($this->any())
			->method('acl_get_list')
			->with($this->anything(),
				$this->stringContains('_'),
				$this->greaterThan(0))
			->will($this->returnValueMap(array(
				array(
					array(3, 4, 5, 6, 7, 8, 10),
					'f_read',
					1,
					array(
						1 => array(
							'f_read' => array(3, 5, 6, 7, 8),
						),
					),
				),
			)));
		$auth->expects($this->any())
			->method('acl_gets')
			->with('a_group', 'a_groupadd', 'a_groupdel')
			->will($this->returnValue(false));
	}

	/**
	* submit_post() Notifications test
	*
	* submit_post() $mode = 'reply'
	* Notification item_type = 'mention'
	*/
	public static function submit_post_data()
	{
		return array(
			/**
			* Normal post
			*
			* User => State description
			*	2	=> Poster, should NOT receive a notification
			*	3	=> mentioned, should receive a notification
			*	4	=> mentioned, but unauthed to read, should NOT receive a notification
			*	5	=> mentioned, but already notified, should STILL receive a new notification
			*	6	=> mentioned, but option disabled, should NOT receive a notification
			*	7	=> mentioned, option set to default, should receive a notification
			*	8	=> mentioned as a member of group 1, should receive a notification
			*/
			array(
				array(
					'message'			=> implode(' ', array(
						'[mention=u:2]poster[/mention] poster should not be notified',
						'[mention=u:3]test[/mention] test should be notified',
						'[mention=u:4]unauthorized[/mention] unauthorized to read, should not receive a notification',
						'[mention=u:5]notified[/mention] already notified, should not receive a new notification',
						'[mention=u:6]disabled[/mention] option disabled, should not receive a notification',
						'[mention=u:7]default[/mention] option set to default, should receive a notification',
						'[mention=g:1]normal group[/mention] group members of a normal group shoud receive a notification',
						'[mention=g:2]hidden group[/mention] group members of a hidden group shoud not receive a notification from a non-member',
						'[mention=u:10]doesn\'t exist[/mention] user does not exist, should not receive a notification',
					)),
					'bbcode_uid'		=> 'uid',
				),
				array(
					array('user_id' => 5, 'item_id' => 1, 'item_parent_id' => 1),
				),
				array(
					array('user_id' => 3, 'item_id' => 2, 'item_parent_id' => 1),
					array('user_id' => 5, 'item_id' => 1, 'item_parent_id' => 1),
					array('user_id' => 5, 'item_id' => 2, 'item_parent_id' => 1),
					array('user_id' => 7, 'item_id' => 2, 'item_parent_id' => 1),
					array('user_id' => 8, 'item_id' => 2, 'item_parent_id' => 1),
				),
			),

			/**
			* Unapproved post
			*
			* No new notifications
			*/
			array(
				array(
					'message'			=> implode(' ', array(
						'[mention=u:2]poster[/mention] poster should not be notified',
						'[mention=u:3]test[/mention] test should be notified',
						'[mention=u:4]unauthorized[/mention] unauthorized to read, should not receive a notification',
						'[mention=u:5]notified[/mention] already notified, should not receive a new notification',
						'[mention=u:6]disabled[/mention] option disabled, should not receive a notification',
						'[mention=u:7]default[/mention] option set to default, should receive a notification',
						'[mention=u:8]doesn\'t exist[/mention] user does not exist, should not receive a notification',
					)),
					'bbcode_uid'		=> 'uid',
					'force_approved_state' => false,
				),
				array(
					array('user_id' => 5, 'item_id' => 1, 'item_parent_id' => 1),
				),
				array(
					array('user_id' => 5, 'item_id' => 1, 'item_parent_id' => 1),
				),
			),
		);
	}
}
