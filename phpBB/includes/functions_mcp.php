<?php
/**
*
* @package phpBB3
* @version $Id$
* @copyright (c) 2006 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

// Common MCP functions

/**
* Approve Post/Topic
*/
function approve_post($post_id_list, $id, $mode)
{
	global $db, $template, $user, $config;
	global $phpEx, $phpbb_root_path;

	if (!check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_approve')))
	{
		trigger_error('NOT_AUTHORISED');
	}

	$redirect = request_var('redirect', build_url(array('quickmod')));
	$success_msg = '';

	$s_hidden_fields = build_hidden_fields(array(
		'i'				=> $id,
		'mode'			=> $mode,
		'post_id_list'	=> $post_id_list,
		'action'		=> 'approve',
		'redirect'		=> $redirect)
	);

	$post_info = get_post_data($post_id_list, 'm_approve');

	if (confirm_box(true))
	{
		$notify_poster = (isset($_REQUEST['notify_poster'])) ? true : false;

		// If Topic -> total_topics = total_topics+1, total_posts = total_posts+1, forum_topics = forum_topics+1, forum_posts = forum_posts+1
		// If Post -> total_posts = total_posts+1, forum_posts = forum_posts+1, topic_replies = topic_replies+1

		$total_topics = $total_posts = 0;
		$topic_approve_sql = $post_approve_sql = $topic_id_list = $forum_id_list = $approve_log = array();
		$user_posts_sql = $post_approved_list = array();

		foreach ($post_info as $post_id => $post_data)
		{
			if ($post_data['post_approved'])
			{
				$post_approved_list[] = $post_id;
				continue;
			}

			$topic_id_list[$post_data['topic_id']] = 1;

			if ($post_data['forum_id'])
			{
				$forum_id_list[$post_data['forum_id']] = 1;
			}

			// User post update (we do not care about topic or post, since user posts are strictly connected to posts)
			// But we care about forums where post counts get not increased. ;)
			if ($post_data['post_postcount'])
			{
				$user_posts_sql[$post_data['poster_id']] = (empty($user_posts_sql[$post_data['poster_id']])) ? 1 : $user_posts_sql[$post_data['poster_id']] + 1;
			}

			// Topic or Post. ;)
			if ($post_data['topic_first_post_id'] == $post_id)
			{
				if ($post_data['forum_id'])
				{
					$total_topics++;
				}
				$topic_approve_sql[] = $post_data['topic_id'];

				$approve_log[] = array(
					'type'			=> 'topic',
					'post_subject'	=> $post_data['post_subject'],
					'forum_id'		=> $post_data['forum_id'],
					'topic_id'		=> $post_data['topic_id'],
				);
			}
			else
			{
				$approve_log[] = array(
					'type'			=> 'post',
					'post_subject'	=> $post_data['post_subject'],
					'forum_id'		=> $post_data['forum_id'],
					'topic_id'		=> $post_data['topic_id'],
				);
			}

			if ($post_data['forum_id'])
			{
				$total_posts++;

				// Increment by topic_replies if we approve a topic...
				// This works because we do not adjust the topic_replies when re-approving a topic after an edit.
				if ($post_data['topic_first_post_id'] == $post_id && $post_data['topic_replies'])
				{
					$total_posts += $post_data['topic_replies'];
				}
			}

			$post_approve_sql[] = $post_id;
		}

		$post_id_list = array_values(array_diff($post_id_list, $post_approved_list));
		for ($i = 0, $size = sizeof($post_approved_list); $i < $size; $i++)
		{
			unset($post_info[$post_approved_list[$i]]);
		}

		if (sizeof($topic_approve_sql))
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_approved = 1
				WHERE ' . $db->sql_in_set('topic_id', $topic_approve_sql);
			$db->sql_query($sql);
		}

		if (sizeof($post_approve_sql))
		{
			$sql = 'UPDATE ' . POSTS_TABLE . '
				SET post_approved = 1
				WHERE ' . $db->sql_in_set('post_id', $post_approve_sql);
			$db->sql_query($sql);
		}

		unset($topic_approve_sql, $post_approve_sql);

		foreach ($approve_log as $log_data)
		{
			add_log('mod', $log_data['forum_id'], $log_data['topic_id'], ($log_data['type'] == 'topic') ? 'LOG_TOPIC_APPROVED' : 'LOG_POST_APPROVED', $log_data['post_subject']);
		}

		if (sizeof($user_posts_sql))
		{
			// Try to minimize the query count by merging users with the same post count additions
			$user_posts_update = array();

			foreach ($user_posts_sql as $user_id => $user_posts)
			{
				$user_posts_update[$user_posts][] = $user_id;
			}

			foreach ($user_posts_update as $user_posts => $user_id_ary)
			{
				$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_posts = user_posts + ' . $user_posts . '
					WHERE ' . $db->sql_in_set('user_id', $user_id_ary);
				$db->sql_query($sql);
			}
		}

		if ($total_topics)
		{
			set_config_count('num_topics', $total_topics, true);
		}

		if ($total_posts)
		{
			set_config_count('num_posts', $total_posts, true);
		}

		sync('topic', 'topic_id', array_keys($topic_id_list), true);
		sync('forum', 'forum_id', array_keys($forum_id_list), true, true);
		unset($topic_id_list, $forum_id_list);

		$messenger = new messenger();

		// Notify Poster?
		if ($notify_poster)
		{
			foreach ($post_info as $post_id => $post_data)
			{
				if ($post_data['poster_id'] == ANONYMOUS)
				{
					continue;
				}

				$email_template = ($post_data['post_id'] == $post_data['topic_first_post_id'] && $post_data['post_id'] == $post_data['topic_last_post_id']) ? 'topic_approved' : 'post_approved';

				$messenger->template($email_template, $post_data['user_lang']);

				$messenger->to($post_data['user_email'], $post_data['username']);
				$messenger->im($post_data['user_jabber'], $post_data['username']);

				$messenger->assign_vars(array(
					'USERNAME'		=> htmlspecialchars_decode($post_data['username']),
					'POST_SUBJECT'	=> htmlspecialchars_decode(censor_text($post_data['post_subject'])),
					'TOPIC_TITLE'	=> htmlspecialchars_decode(censor_text($post_data['topic_title'])),

					'U_VIEW_TOPIC'	=> generate_board_url() . "/viewtopic.$phpEx?f={$post_data['forum_id']}&t={$post_data['topic_id']}&e=0",
					'U_VIEW_POST'	=> generate_board_url() . "/viewtopic.$phpEx?f={$post_data['forum_id']}&t={$post_data['topic_id']}&p=$post_id&e=$post_id")
				);

				$messenger->send($post_data['user_notify_type']);
			}
		}

		$messenger->save_queue();

		// Send out normal user notifications
		$email_sig = str_replace('<br />', "\n", "-- \n" . $config['board_email_sig']);

		foreach ($post_info as $post_id => $post_data)
		{
			if ($post_id == $post_data['topic_first_post_id'] && $post_id == $post_data['topic_last_post_id'])
			{
				// Forum Notifications
				user_notification('post', $post_data['topic_title'], $post_data['topic_title'], $post_data['forum_name'], $post_data['forum_id'], $post_data['topic_id'], $post_id);
			}
			else
			{
				// Topic Notifications
				user_notification('reply', $post_data['post_subject'], $post_data['topic_title'], $post_data['forum_name'], $post_data['forum_id'], $post_data['topic_id'], $post_id);
			}
		}

		if (sizeof($post_id_list) == 1)
		{
			$post_data = $post_info[$post_id_list[0]];
			$post_url = append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f={$post_data['forum_id']}&amp;t={$post_data['topic_id']}&amp;p={$post_data['post_id']}") . '#p' . $post_data['post_id'];
		}
		unset($post_info);

		if ($total_topics)
		{
			$success_msg = ($total_topics == 1) ? 'TOPIC_APPROVED_SUCCESS' : 'TOPICS_APPROVED_SUCCESS';
		}
		else
		{
			$success_msg = (sizeof($post_id_list) + sizeof($post_approved_list) == 1) ? 'POST_APPROVED_SUCCESS' : 'POSTS_APPROVED_SUCCESS';
		}
	}
	else
	{
		$show_notify = false;

		if ($config['email_enable'] || $config['jab_enable'])
		{
			foreach ($post_info as $post_data)
			{
				if ($post_data['poster_id'] == ANONYMOUS)
				{
					continue;
				}
				else
				{
					$show_notify = true;
					break;
				}
			}
		}

		$template->assign_vars(array(
			'S_NOTIFY_POSTER'	=> $show_notify,
			'S_APPROVE'			=> true)
		);

		confirm_box(false, 'APPROVE_POST' . ((sizeof($post_id_list) == 1) ? '' : 'S'), $s_hidden_fields, 'mcp_approve.html');
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);

		// If approving one post, also give links back to post...
		$add_message = '';
		if (sizeof($post_id_list) == 1 && !empty($post_url))
		{
			$add_message = '<br /><br />' . sprintf($user->lang['RETURN_POST'], '<a href="' . $post_url . '">', '</a>');
		}

		trigger_error($user->lang[$success_msg] . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], "<a href=\"$redirect\">", '</a>') . $add_message);
	}
}

/**
* Disapprove Post/Topic
*/
function disapprove_post($post_id_list, $id, $mode)
{
	global $db, $template, $user, $config;
	global $phpEx, $phpbb_root_path;

	if (!check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_approve')))
	{
		trigger_error('NOT_AUTHORISED');
	}

	$redirect = request_var('redirect', build_url(array('t', 'mode', 'quickmod')) . "&amp;mode=$mode");
	$reason = utf8_normalize_nfc(request_var('reason', '', true));
	$reason_id = request_var('reason_id', 0);
	$success_msg = $additional_msg = '';

	$s_hidden_fields = build_hidden_fields(array(
		'i'				=> $id,
		'mode'			=> $mode,
		'post_id_list'	=> $post_id_list,
		'action'		=> 'disapprove',
		'redirect'		=> $redirect)
	);

	$notify_poster = (isset($_REQUEST['notify_poster'])) ? true : false;
	$disapprove_reason = '';

	if ($reason_id)
	{
		$sql = 'SELECT reason_title, reason_description
			FROM ' . REPORTS_REASONS_TABLE . "
			WHERE reason_id = $reason_id";
		$result = $db->sql_query($sql);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if (!$row || (!$reason && strtolower($row['reason_title']) == 'other'))
		{
			$additional_msg = $user->lang['NO_REASON_DISAPPROVAL'];
			unset($_POST['confirm']);
		}
		else
		{
			// If the reason is defined within the language file, we will use the localized version, else just use the database entry...
			$disapprove_reason = (strtolower($row['reason_title']) != 'other') ? ((isset($user->lang['report_reasons']['DESCRIPTION'][strtoupper($row['reason_title'])])) ? $user->lang['report_reasons']['DESCRIPTION'][strtoupper($row['reason_title'])] : $row['reason_description']) : '';
			$disapprove_reason .= ($reason) ? "\n\n" . $reason : '';

			if (isset($user->lang['report_reasons']['DESCRIPTION'][strtoupper($row['reason_title'])]))
			{
				$disapprove_reason_lang = strtoupper($row['reason_title']);
			}

			$email_disapprove_reason = $disapprove_reason;
		}
	}

	$post_info = get_post_data($post_id_list, 'm_approve');

	if (confirm_box(true))
	{
		$disapprove_log = $disapprove_log_topics = $disapprove_log_posts = array();
		$topic_replies_real = $post_disapprove_list = array();

		// Build a list of posts to be unapproved and get the related topics real replies count
		foreach ($post_info as $post_id => $post_data)
		{
			$post_disapprove_list[$post_id] = $post_data['topic_id'];
			if (!isset($topic_replies_real[$post_data['topic_id']]))
			{
				$topic_replies_real[$post_data['topic_id']] = $post_data['topic_replies_real'];
			}
		}

		// Now we build the log array
		foreach ($post_disapprove_list as $post_id => $topic_id)
		{
			// If the count of disapproved posts for the topic is greater
			// than topic's real replies count, the whole topic is disapproved/deleted
			if (sizeof(array_keys($post_disapprove_list, $topic_id)) > $topic_replies_real[$topic_id])
			{
				// Don't write the log more than once for every topic
				if (!isset($disapprove_log_topics[$topic_id]))
				{
					// Build disapproved topics log
					$disapprove_log_topics[$topic_id] = array(
						'type'			=> 'topic',
						'post_subject'	=> $post_info[$post_id]['topic_title'],
						'forum_id'		=> $post_info[$post_id]['forum_id'],
						'topic_id'		=> 0, // useless to log a topic id, as it will be deleted
					);
				}
			}
			else
			{
				// Build disapproved posts log
				$disapprove_log_posts[] = array(
					'type'			=> 'post',
					'post_subject'	=> $post_info[$post_id]['post_subject'],
					'forum_id'		=> $post_info[$post_id]['forum_id'],
					'topic_id'		=> $post_info[$post_id]['topic_id'],
				);

			}
		}

		// Get disapproved posts/topics counts separately
		$num_disapproved_topics = sizeof($disapprove_log_topics);
		$num_disapproved_posts = sizeof($disapprove_log_posts);

		// Build the whole log
		$disapprove_log = array_merge($disapprove_log_topics, $disapprove_log_posts);

		// Unset unneeded arrays
		unset($post_data, $disapprove_log_topics, $disapprove_log_posts);

		// Let's do the job - delete disapproved posts
		if (sizeof($post_disapprove_list))
		{
			if (!function_exists('delete_posts'))
			{
				include_once($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
			}

			// We do not check for permissions here, because the moderator allowed approval/disapproval should be allowed to delete the disapproved posts
			// Note: function delete_posts triggers related forums/topics sync,
			// so we don't need to call update_post_information later and to adjust real topic replies or forum topics count manually
			delete_posts('post_id', array_keys($post_disapprove_list));

			foreach ($disapprove_log as $log_data)
			{
				add_log('mod', $log_data['forum_id'], $log_data['topic_id'], ($log_data['type'] == 'topic') ? 'LOG_TOPIC_DISAPPROVED' : 'LOG_POST_DISAPPROVED', $log_data['post_subject'], $disapprove_reason);
			}
		}

		$messenger = new messenger();

		// Notify Poster?
		if ($notify_poster)
		{
			$lang_reasons = array();

			foreach ($post_info as $post_id => $post_data)
			{
				if ($post_data['poster_id'] == ANONYMOUS)
				{
					continue;
				}

				if (isset($disapprove_reason_lang))
				{
					// Okay we need to get the reason from the posters language
					if (!isset($lang_reasons[$post_data['user_lang']]))
					{
						// Assign the current users translation as the default, this is not ideal but getting the board default adds another layer of complexity.
						$lang_reasons[$post_data['user_lang']] = $user->lang['report_reasons']['DESCRIPTION'][$disapprove_reason_lang];

						// Only load up the language pack if the language is different to the current one
						if ($post_data['user_lang'] != $user->lang_name && file_exists($phpbb_root_path . '/language/' . $post_data['user_lang'] . '/mcp.' . $phpEx))
						{
							// Load up the language pack
							$lang = array();
							@include($phpbb_root_path . '/language/' . basename($post_data['user_lang']) . '/mcp.' . $phpEx);

							// If we find the reason in this language pack use it
							if (isset($lang['report_reasons']['DESCRIPTION'][$disapprove_reason_lang]))
							{
								$lang_reasons[$post_data['user_lang']] = $lang['report_reasons']['DESCRIPTION'][$disapprove_reason_lang];
							}

							unset($lang); // Free memory
						}
					}

					$email_disapprove_reason = $lang_reasons[$post_data['user_lang']];
					$email_disapprove_reason .= ($reason) ? "\n\n" . $reason : '';
				}

				$email_template = ($post_data['post_id'] == $post_data['topic_first_post_id'] && $post_data['post_id'] == $post_data['topic_last_post_id']) ? 'topic_disapproved' : 'post_disapproved';

				$messenger->template($email_template, $post_data['user_lang']);

				$messenger->to($post_data['user_email'], $post_data['username']);
				$messenger->im($post_data['user_jabber'], $post_data['username']);

				$messenger->assign_vars(array(
					'USERNAME'		=> htmlspecialchars_decode($post_data['username']),
					'REASON'		=> htmlspecialchars_decode($email_disapprove_reason),
					'POST_SUBJECT'	=> htmlspecialchars_decode(censor_text($post_data['post_subject'])),
					'TOPIC_TITLE'	=> htmlspecialchars_decode(censor_text($post_data['topic_title'])))
				);

				$messenger->send($post_data['user_notify_type']);
			}

			unset($lang_reasons);
		}
		unset($post_info, $disapprove_reason, $email_disapprove_reason, $disapprove_reason_lang);

		$messenger->save_queue();

		if ($num_disapproved_topics)
		{
			$success_msg = ($num_disapproved_topics == 1) ? 'TOPIC_DISAPPROVED_SUCCESS' : 'TOPICS_DISAPPROVED_SUCCESS';
		}
		else
		{
			$success_msg = ($num_disapproved_posts == 1) ? 'POST_DISAPPROVED_SUCCESS' : 'POSTS_DISAPPROVED_SUCCESS';
		}
	}
	else
	{
		include_once($phpbb_root_path . 'includes/functions_display.' . $phpEx);

		display_reasons($reason_id);

		$show_notify = false;

		foreach ($post_info as $post_data)
		{
			if ($post_data['poster_id'] == ANONYMOUS)
			{
				continue;
			}
			else
			{
				$show_notify = true;
				break;
			}
		}

		$template->assign_vars(array(
			'S_NOTIFY_POSTER'	=> $show_notify,
			'S_APPROVE'			=> false,
			'REASON'			=> $reason,
			'ADDITIONAL_MSG'	=> $additional_msg)
		);

		confirm_box(false, 'DISAPPROVE_POST' . ((sizeof($post_id_list) == 1) ? '' : 'S'), $s_hidden_fields, 'mcp_approve.html');
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);
		trigger_error($user->lang[$success_msg] . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], "<a href=\"$redirect\">", '</a>'));
	}
}

/**
* View topic in MCP
*/
function mcp_topic_view($id, $mode, $action)
{
	global $phpEx, $phpbb_root_path, $config;
	global $template, $db, $user, $auth, $cache;

	$url = append_sid("{$phpbb_root_path}mcp.$phpEx?" . extra_url());

	$user->add_lang('viewtopic');

	$topic_id = request_var('t', 0);
	$topic_info = get_topic_data(array($topic_id), false, true);

	if (!sizeof($topic_info))
	{
		trigger_error('TOPIC_NOT_EXIST');
	}

	$topic_info = $topic_info[$topic_id];

	// Set up some vars
	$icon_id		= request_var('icon', 0);
	$subject		= utf8_normalize_nfc(request_var('subject', '', true));
	$start			= request_var('start', 0);
	$sort_days_old	= request_var('st_old', 0);
	$forum_id		= request_var('f', 0);
	$to_topic_id	= request_var('to_topic_id', 0);
	$to_forum_id	= request_var('to_forum_id', 0);
	$sort			= isset($_POST['sort']) ? true : false;
	$submitted_id_list	= request_var('post_ids', array(0));
	$checked_ids = $post_id_list = request_var('post_id_list', array(0));

	// Split Topic?
	if ($action == 'split_all' || $action == 'split_beyond')
	{
		if (!$sort)
		{
			split_topic($action, $topic_id, $to_forum_id, $subject);
		}
		$action = 'split';
	}

	// Merge Posts?
	if ($action == 'merge_posts')
	{
		if (!$sort)
		{
			merge_posts($topic_id, $to_topic_id);
		}
		$action = 'merge';
	}

	if ($action == 'split' && !$subject)
	{
		$subject = $topic_info['topic_title'];
	}

	// Approve posts?
	if ($action == 'approve' && $auth->acl_get('m_approve', $topic_info['forum_id']))
	{
		include($phpbb_root_path . 'includes/functions_mcp.' . $phpEx);
		include_once($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
		include_once($phpbb_root_path . 'includes/functions_messenger.' . $phpEx);

		if (!sizeof($post_id_list))
		{
			trigger_error('NO_POST_SELECTED');
		}

		if (!$sort)
		{
			approve_post($post_id_list, $id, $mode);
		}
	}

	// Jumpbox, sort selects and that kind of things
	make_jumpbox($url . "&amp;i=$id&amp;mode=forum_view", $topic_info['forum_id'], false, 'm_', true);
	$where_sql = ($action == 'reports') ? 'WHERE post_reported = 1 AND ' : 'WHERE';

	$sort_days = $total = 0;
	$sort_key = $sort_dir = '';
	$sort_by_sql = $sort_order_sql = array();
	mcp_sorting('viewtopic', $sort_days, $sort_key, $sort_dir, $sort_by_sql, $sort_order_sql, $total, $topic_info['forum_id'], $topic_id, $where_sql);

	$limit_time_sql = ($sort_days) ? 'AND p.post_time >= ' . (time() - ($sort_days * 86400)) : '';

	if ($total == -1)
	{
		if ($auth->acl_get('m_approve', $topic_info['forum_id']))
		{
			$total = $topic_info['topic_replies_real'] + 1;
		}
		else
		{
			$total = $topic_info['topic_replies'] + 1;
		}
	}

	$posts_per_page = max(0, request_var('posts_per_page', intval($config['posts_per_page'])));
	if ($posts_per_page == 0)
	{
		$posts_per_page = $total;
	}

	if ((!empty($sort_days_old) && $sort_days_old != $sort_days) || $total <= $posts_per_page)
	{
		$start = 0;
	}

	// Make sure $start is set to the last page if it exceeds the amount
	if ($start < 0 || $start >= $total)
	{
		$start = ($start < 0) ? 0 : floor(($total - 1) / $posts_per_page) * $posts_per_page;
	}

	$sql = 'SELECT u.username, u.username_clean, u.user_colour, p.*
		FROM ' . POSTS_TABLE . ' p, ' . USERS_TABLE . ' u
		WHERE ' . (($action == 'reports') ? 'p.post_reported = 1 AND ' : '') . '
			p.topic_id = ' . $topic_id . ' ' .
			((!$auth->acl_get('m_approve', $topic_info['forum_id'])) ? ' AND p.post_approved = 1 ' : '') . '
			AND p.poster_id = u.user_id ' .
			$limit_time_sql . '
		ORDER BY ' . $sort_order_sql;
	$result = $db->sql_query_limit($sql, $posts_per_page, $start);

	$rowset = $post_id_list = array();
	$bbcode_bitfield = '';
	while ($row = $db->sql_fetchrow($result))
	{
		$rowset[] = $row;
		$post_id_list[] = $row['post_id'];
		$bbcode_bitfield = $bbcode_bitfield | base64_decode($row['bbcode_bitfield']);
	}
	$db->sql_freeresult($result);

	if ($bbcode_bitfield !== '')
	{
		include_once($phpbb_root_path . 'includes/bbcode.' . $phpEx);
		$bbcode = new bbcode(base64_encode($bbcode_bitfield));
	}

	$topic_tracking_info = array();

	// Get topic tracking info
	if ($config['load_db_lastread'])
	{
		$tmp_topic_data = array($topic_id => $topic_info);
		$topic_tracking_info = get_topic_tracking($topic_info['forum_id'], $topic_id, $tmp_topic_data, array($topic_info['forum_id'] => $topic_info['forum_mark_time']));
		unset($tmp_topic_data);
	}
	else
	{
		$topic_tracking_info = get_complete_topic_tracking($topic_info['forum_id'], $topic_id);
	}

	$has_unapproved_posts = false;

	// Grab extensions
	$extensions = $attachments = array();
	if ($topic_info['topic_attachment'] && sizeof($post_id_list))
	{
		$extensions = $cache->obtain_attach_extensions($topic_info['forum_id']);

		// Get attachments...
		if ($auth->acl_get('u_download') && $auth->acl_get('f_download', $topic_info['forum_id']))
		{
			$sql = 'SELECT *
				FROM ' . ATTACHMENTS_TABLE . '
				WHERE ' . $db->sql_in_set('post_msg_id', $post_id_list) . '
					AND in_message = 0
				ORDER BY filetime DESC, post_msg_id ASC';
			$result = $db->sql_query($sql);

			while ($row = $db->sql_fetchrow($result))
			{
				$attachments[$row['post_msg_id']][] = $row;
			}
			$db->sql_freeresult($result);
		}
	}

	foreach ($rowset as $i => $row)
	{
		$message = $row['post_text'];
		$post_subject = ($row['post_subject'] != '') ? $row['post_subject'] : $topic_info['topic_title'];

		if ($row['bbcode_bitfield'])
		{
			$bbcode->bbcode_second_pass($message, $row['bbcode_uid'], $row['bbcode_bitfield']);
		}

		$message = bbcode_nl2br($message);
		$message = smiley_text($message);

		if (!empty($attachments[$row['post_id']]))
		{
			$update_count = array();
			parse_attachments($topic_info['forum_id'], $message, $attachments[$row['post_id']], $update_count);
		}

		if (!$row['post_approved'])
		{
			$has_unapproved_posts = true;
		}

		$post_unread = (isset($topic_tracking_info[$topic_id]) && $row['post_time'] > $topic_tracking_info[$topic_id]) ? true : false;

		$template->assign_block_vars('postrow', array(
			'POST_AUTHOR_FULL'		=> get_username_string('full', $row['poster_id'], $row['username'], $row['user_colour'], $row['post_username']),
			'POST_AUTHOR_COLOUR'	=> get_username_string('colour', $row['poster_id'], $row['username'], $row['user_colour'], $row['post_username']),
			'POST_AUTHOR'			=> get_username_string('username', $row['poster_id'], $row['username'], $row['user_colour'], $row['post_username']),
			'U_POST_AUTHOR'			=> get_username_string('profile', $row['poster_id'], $row['username'], $row['user_colour'], $row['post_username']),

			'POST_DATE'		=> $user->format_date($row['post_time']),
			'POST_SUBJECT'	=> $post_subject,
			'MESSAGE'		=> $message,
			'POST_ID'		=> $row['post_id'],
			'RETURN_TOPIC'	=> sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 't=' . $topic_id) . '">', '</a>'),

			'MINI_POST_IMG'			=> ($post_unread) ? $user->img('icon_post_target_unread', 'UNREAD_POST') : $user->img('icon_post_target', 'POST'),

			'S_POST_REPORTED'	=> ($row['post_reported']) ? true : false,
			'S_POST_UNAPPROVED'	=> ($row['post_approved']) ? false : true,
			'S_CHECKED'			=> (($submitted_id_list && !in_array(intval($row['post_id']), $submitted_id_list)) || in_array(intval($row['post_id']), $checked_ids)) ? true : false,
			'S_HAS_ATTACHMENTS'	=> (!empty($attachments[$row['post_id']])) ? true : false,

			'U_POST_DETAILS'	=> "$url&amp;i=$id&amp;p={$row['post_id']}&amp;mode=post_details" . (($forum_id) ? "&amp;f=$forum_id" : ''),
			'U_MCP_APPROVE'		=> ($auth->acl_get('m_approve', $topic_info['forum_id'])) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=approve_details&amp;f=' . $topic_info['forum_id'] . '&amp;p=' . $row['post_id']) : '',
			'U_MCP_REPORT'		=> ($auth->acl_get('m_report', $topic_info['forum_id'])) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=reports&amp;mode=report_details&amp;f=' . $topic_info['forum_id'] . '&amp;p=' . $row['post_id']) : '')
		);

		// Display not already displayed Attachments for this post, we already parsed them. ;)
		if (!empty($attachments[$row['post_id']]))
		{
			foreach ($attachments[$row['post_id']] as $attachment)
			{
				$template->assign_block_vars('postrow.attachment', array(
					'DISPLAY_ATTACHMENT'	=> $attachment)
				);
			}
		}

		unset($rowset[$i]);
	}

	// Display topic icons for split topic
	$s_topic_icons = false;

	if ($auth->acl_gets('m_split', 'm_merge', (int) $topic_info['forum_id']))
	{
		include_once($phpbb_root_path . 'includes/functions_posting.' . $phpEx);
		$s_topic_icons = posting_gen_topic_icons('', $icon_id);

		// Has the user selected a topic for merge?
		if ($to_topic_id)
		{
			$to_topic_info = get_topic_data(array($to_topic_id), 'm_merge');

			if (!sizeof($to_topic_info))
			{
				$to_topic_id = 0;
			}
			else
			{
				$to_topic_info = $to_topic_info[$to_topic_id];

				if (!$to_topic_info['enable_icons'] || $auth->acl_get('!f_icons', $topic_info['forum_id']))
				{
					$s_topic_icons = false;
				}
			}
		}
	}

	$s_hidden_fields = build_hidden_fields(array(
		'st_old'	=> $sort_days,
		'post_ids'	=> $post_id_list,
	));

	$template->assign_vars(array(
		'TOPIC_TITLE'		=> $topic_info['topic_title'],
		'U_VIEW_TOPIC'		=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $topic_info['forum_id'] . '&amp;t=' . $topic_info['topic_id']),

		'TO_TOPIC_ID'		=> $to_topic_id,
		'TO_TOPIC_INFO'		=> ($to_topic_id) ? sprintf($user->lang['YOU_SELECTED_TOPIC'], $to_topic_id, '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $to_topic_info['forum_id'] . '&amp;t=' . $to_topic_id) . '">' . $to_topic_info['topic_title'] . '</a>') : '',

		'SPLIT_SUBJECT'		=> $subject,
		'POSTS_PER_PAGE'	=> $posts_per_page,
		'ACTION'			=> $action,

		'REPORTED_IMG'		=> $user->img('icon_topic_reported', 'POST_REPORTED'),
		'UNAPPROVED_IMG'	=> $user->img('icon_topic_unapproved', 'POST_UNAPPROVED'),
		'INFO_IMG'			=> $user->img('icon_post_info', 'VIEW_INFO'),

		'S_MCP_ACTION'		=> "$url&amp;i=$id&amp;mode=$mode&amp;action=$action&amp;start=$start",
		'S_FORUM_SELECT'	=> ($to_forum_id) ? make_forum_select($to_forum_id, false, false, true, true, true) : make_forum_select($topic_info['forum_id'], false, false, true, true, true),
		'S_CAN_SPLIT'		=> ($auth->acl_get('m_split', $topic_info['forum_id'])) ? true : false,
		'S_CAN_MERGE'		=> ($auth->acl_get('m_merge', $topic_info['forum_id'])) ? true : false,
		'S_CAN_DELETE'		=> ($auth->acl_get('m_delete', $topic_info['forum_id'])) ? true : false,
		'S_CAN_APPROVE'		=> ($has_unapproved_posts && $auth->acl_get('m_approve', $topic_info['forum_id'])) ? true : false,
		'S_CAN_LOCK'		=> ($auth->acl_get('m_lock', $topic_info['forum_id'])) ? true : false,
		'S_CAN_REPORT'		=> ($auth->acl_get('m_report', $topic_info['forum_id'])) ? true : false,
		'S_REPORT_VIEW'		=> ($action == 'reports') ? true : false,
		'S_MERGE_VIEW'		=> ($action == 'merge') ? true : false,
		'S_SPLIT_VIEW'		=> ($action == 'split') ? true : false,

		'S_HIDDEN_FIELDS'	=> $s_hidden_fields,

		'S_SHOW_TOPIC_ICONS'	=> $s_topic_icons,
		'S_TOPIC_ICON'			=> $icon_id,

		'U_SELECT_TOPIC'	=> "$url&amp;i=$id&amp;mode=forum_view&amp;action=merge_select" . (($forum_id) ? "&amp;f=$forum_id" : ''),

		'RETURN_TOPIC'		=> sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f={$topic_info['forum_id']}&amp;t={$topic_info['topic_id']}&amp;start=$start") . '">', '</a>'),
		'RETURN_FORUM'		=> sprintf($user->lang['RETURN_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", "f={$topic_info['forum_id']}&amp;start=$start") . '">', '</a>'),

		'PAGE_NUMBER'		=> on_page($total, $posts_per_page, $start),
		'PAGINATION'		=> (!$posts_per_page) ? '' : generate_pagination(append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;t={$topic_info['topic_id']}&amp;mode=$mode&amp;action=$action&amp;to_topic_id=$to_topic_id&amp;posts_per_page=$posts_per_page&amp;st=$sort_days&amp;sk=$sort_key&amp;sd=$sort_dir"), $total, $posts_per_page, $start),
		'TOTAL_POSTS'		=> ($total == 1) ? $user->lang['VIEW_TOPIC_POST'] : sprintf($user->lang['VIEW_TOPIC_POSTS'], $total),
	));
}

/**
* Split topic
*/
function split_topic($action, $topic_id, $to_forum_id, $subject)
{
	global $db, $template, $user, $phpEx, $phpbb_root_path, $auth, $config;

	$post_id_list	= request_var('post_id_list', array(0));
	$forum_id		= request_var('forum_id', 0);
	$start			= request_var('start', 0);

	if (!sizeof($post_id_list))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_POST_SELECTED']);
		return;
	}

	if (!check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_split')))
	{
		return;
	}

	$post_id = $post_id_list[0];
	$post_info = get_post_data(array($post_id));

	if (!sizeof($post_info))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_POST_SELECTED']);
		return;
	}

	$post_info = $post_info[$post_id];
	$subject = trim($subject);

	// Make some tests
	if (!$subject)
	{
		$template->assign_var('MESSAGE', $user->lang['EMPTY_SUBJECT']);
		return;
	}

	if ($to_forum_id <= 0)
	{
		$template->assign_var('MESSAGE', $user->lang['NO_DESTINATION_FORUM']);
		return;
	}

	$forum_info = get_forum_data(array($to_forum_id), 'f_post');

	if (!sizeof($forum_info))
	{
		$template->assign_var('MESSAGE', $user->lang['USER_CANNOT_POST']);
		return;
	}

	$forum_info = $forum_info[$to_forum_id];

	if ($forum_info['forum_type'] != FORUM_POST)
	{
		$template->assign_var('MESSAGE', $user->lang['FORUM_NOT_POSTABLE']);
		return;
	}

	$redirect = request_var('redirect', build_url(array('quickmod')));

	$s_hidden_fields = build_hidden_fields(array(
		'i'				=> 'main',
		'post_id_list'	=> $post_id_list,
		'f'				=> $forum_id,
		'mode'			=> 'topic_view',
		'start'			=> $start,
		'action'		=> $action,
		't'				=> $topic_id,
		'redirect'		=> $redirect,
		'subject'		=> $subject,
		'to_forum_id'	=> $to_forum_id,
		'icon'			=> request_var('icon', 0))
	);
	$success_msg = $return_link = '';

	if (confirm_box(true))
	{
		if ($action == 'split_beyond')
		{
			$sort_days = $total = 0;
			$sort_key = $sort_dir = '';
			$sort_by_sql = $sort_order_sql = array();
			mcp_sorting('viewtopic', $sort_days, $sort_key, $sort_dir, $sort_by_sql, $sort_order_sql, $total, $forum_id, $topic_id);

			$limit_time_sql = ($sort_days) ? 'AND t.topic_last_post_time >= ' . (time() - ($sort_days * 86400)) : '';

			if ($sort_order_sql[0] == 'u')
			{
				$sql = 'SELECT p.post_id, p.forum_id, p.post_approved
					FROM ' . POSTS_TABLE . ' p, ' . USERS_TABLE . " u
					WHERE p.topic_id = $topic_id
						AND p.poster_id = u.user_id
						$limit_time_sql
					ORDER BY $sort_order_sql";
			}
			else
			{
				$sql = 'SELECT p.post_id, p.forum_id, p.post_approved
					FROM ' . POSTS_TABLE . " p
					WHERE p.topic_id = $topic_id
						$limit_time_sql
					ORDER BY $sort_order_sql";
			}
			$result = $db->sql_query_limit($sql, 0, $start);

			$store = false;
			$post_id_list = array();
			while ($row = $db->sql_fetchrow($result))
			{
				// If split from selected post (split_beyond), we split the unapproved items too.
				if (!$row['post_approved'] && !$auth->acl_get('m_approve', $row['forum_id']))
				{
//					continue;
				}

				// Start to store post_ids as soon as we see the first post that was selected
				if ($row['post_id'] == $post_id)
				{
					$store = true;
				}

				if ($store)
				{
					$post_id_list[] = $row['post_id'];
				}
			}
			$db->sql_freeresult($result);
		}

		if (!sizeof($post_id_list))
		{
			trigger_error('NO_POST_SELECTED');
		}

		$icon_id = request_var('icon', 0);

		$sql_ary = array(
			'forum_id'		=> $to_forum_id,
			'topic_title'	=> $subject,
			'icon_id'		=> $icon_id,
			'topic_approved'=> 1
		);

		$sql = 'INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary);
		$db->sql_query($sql);

		$to_topic_id = $db->sql_nextid();
		move_posts($post_id_list, $to_topic_id);

		$topic_info = get_topic_data(array($topic_id));
		$topic_info = $topic_info[$topic_id];

		add_log('mod', $to_forum_id, $to_topic_id, 'LOG_SPLIT_DESTINATION', $subject);
		add_log('mod', $forum_id, $topic_id, 'LOG_SPLIT_SOURCE', $topic_info['topic_title']);

		// Change topic title of first post
		$sql = 'UPDATE ' . POSTS_TABLE . "
			SET post_subject = '" . $db->sql_escape($subject) . "'
			WHERE post_id = {$post_id_list[0]}";
		$db->sql_query($sql);

		$success_msg = 'TOPIC_SPLIT_SUCCESS';

		// Update forum statistics
		set_config_count('num_topics', 1, true);

		// Link back to both topics
		$return_link = sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $post_info['forum_id'] . '&amp;t=' . $post_info['topic_id']) . '">', '</a>') . '<br /><br />' . sprintf($user->lang['RETURN_NEW_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $to_forum_id . '&amp;t=' . $to_topic_id) . '">', '</a>');
	}
	else
	{
		confirm_box(false, ($action == 'split_all') ? 'SPLIT_TOPIC_ALL' : 'SPLIT_TOPIC_BEYOND', $s_hidden_fields);
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		return;
	}
	else
	{
		meta_refresh(3, append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f=$to_forum_id&amp;t=$to_topic_id"));
		trigger_error($user->lang[$success_msg] . '<br /><br />' . $return_link);
	}
}

/**
* Merge selected posts into selected topic
*/
function merge_posts($topic_id, $to_topic_id)
{
	global $db, $template, $user, $phpEx, $phpbb_root_path, $auth;

	if (!$to_topic_id)
	{
		$template->assign_var('MESSAGE', $user->lang['NO_FINAL_TOPIC_SELECTED']);
		return;
	}

	$topic_data = get_topic_data(array($to_topic_id), 'm_merge');

	if (!sizeof($topic_data))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_FINAL_TOPIC_SELECTED']);
		return;
	}

	$topic_data = $topic_data[$to_topic_id];

	$post_id_list	= request_var('post_id_list', array(0));
	$start			= request_var('start', 0);

	if (!sizeof($post_id_list))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_POST_SELECTED']);
		return;
	}

	if (!check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_merge')))
	{
		return;
	}

	$redirect = request_var('redirect', build_url(array('quickmod')));

	$s_hidden_fields = build_hidden_fields(array(
		'i'				=> 'main',
		'post_id_list'	=> $post_id_list,
		'to_topic_id'	=> $to_topic_id,
		'mode'			=> 'topic_view',
		'action'		=> 'merge_posts',
		'start'			=> $start,
		'redirect'		=> $redirect,
		't'				=> $topic_id)
	);
	$success_msg = $return_link = '';

	if (confirm_box(true))
	{
		$to_forum_id = $topic_data['forum_id'];

		move_posts($post_id_list, $to_topic_id);
		add_log('mod', $to_forum_id, $to_topic_id, 'LOG_MERGE', $topic_data['topic_title']);

		// Message and return links
		$success_msg = 'POSTS_MERGED_SUCCESS';

		// Does the original topic still exist? If yes, link back to it
		$sql = 'SELECT forum_id
			FROM ' . TOPICS_TABLE . '
			WHERE topic_id = ' . $topic_id;
		$result = $db->sql_query_limit($sql, 1);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if ($row)
		{
			$return_link .= sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $row['forum_id'] . '&amp;t=' . $topic_id) . '">', '</a>');
		}
		else
		{
			// If the topic no longer exist, we will update the topic watch table.
			// To not let it error out on users watching both topics, we just return on an error...
			$db->sql_return_on_error(true);
			$db->sql_query('UPDATE ' . TOPICS_WATCH_TABLE . ' SET topic_id = ' . (int) $to_topic_id . ' WHERE topic_id = ' . (int) $topic_id);
			$db->sql_return_on_error(false);

			$db->sql_query('DELETE FROM ' . TOPICS_WATCH_TABLE . ' WHERE topic_id = ' . (int) $topic_id);
		}

		// Link to the new topic
		$return_link .= (($return_link) ? '<br /><br />' : '') . sprintf($user->lang['RETURN_NEW_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $to_forum_id . '&amp;t=' . $to_topic_id) . '">', '</a>');
	}
	else
	{
		confirm_box(false, 'MERGE_POSTS', $s_hidden_fields);
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		return;
	}
	else
	{
		meta_refresh(3, append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f=$to_forum_id&amp;t=$to_topic_id"));
		trigger_error($user->lang[$success_msg] . '<br /><br />' . $return_link);
	}
}

/**
* MCP Front Panel
*/
function mcp_front_view($id, $mode, $action)
{
	global $phpEx, $phpbb_root_path, $config;
	global $template, $db, $user, $auth, $module;

	// Latest 5 unapproved
	if ($module->loaded('queue'))
	{
		$forum_list = array_values(array_intersect(get_forum_list('f_read'), get_forum_list('m_approve')));
		$post_list = array();
		$forum_names = array();

		$forum_id = request_var('f', 0);

		$template->assign_var('S_SHOW_UNAPPROVED', (!empty($forum_list)) ? true : false);

		if (!empty($forum_list))
		{
			$sql = 'SELECT COUNT(post_id) AS total
				FROM ' . POSTS_TABLE . '
				WHERE forum_id IN (0, ' . implode(', ', $forum_list) . ')
					AND post_approved = 0';
			$result = $db->sql_query($sql);
			$total = (int) $db->sql_fetchfield('total');
			$db->sql_freeresult($result);

			if ($total)
			{
				$global_id = $forum_list[0];

				$sql = 'SELECT forum_id, forum_name
					FROM ' . FORUMS_TABLE . '
					WHERE ' . $db->sql_in_set('forum_id', $forum_list);
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$forum_names[$row['forum_id']] = $row['forum_name'];
				}
				$db->sql_freeresult($result);

				$sql = 'SELECT post_id
					FROM ' . POSTS_TABLE . '
					WHERE forum_id IN (0, ' . implode(', ', $forum_list) . ')
						AND post_approved = 0
					ORDER BY post_time DESC';
				$result = $db->sql_query_limit($sql, 5);

				while ($row = $db->sql_fetchrow($result))
				{
					$post_list[] = $row['post_id'];
				}
				$db->sql_freeresult($result);

				if (empty($post_list))
				{
					$total = 0;
				}
			}

			if ($total)
			{
				$sql = 'SELECT p.post_id, p.post_subject, p.post_time, p.poster_id, p.post_username, u.username, u.username_clean, u.user_colour, t.topic_id, t.topic_title, t.topic_first_post_id, p.forum_id
					FROM ' . POSTS_TABLE . ' p, ' . TOPICS_TABLE . ' t, ' . USERS_TABLE . ' u
					WHERE ' . $db->sql_in_set('p.post_id', $post_list) . '
						AND t.topic_id = p.topic_id
						AND p.poster_id = u.user_id
					ORDER BY p.post_time DESC';
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$global_topic = ($row['forum_id']) ? false : true;
					if ($global_topic)
					{
						$row['forum_id'] = $global_id;
					}

					$template->assign_block_vars('unapproved', array(
						'U_POST_DETAILS'	=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=approve_details&amp;f=' . $row['forum_id'] . '&amp;p=' . $row['post_id']),
						'U_MCP_FORUM'		=> (!$global_topic) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=main&amp;mode=forum_view&amp;f=' . $row['forum_id']) : '',
						'U_MCP_TOPIC'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=main&amp;mode=topic_view&amp;f=' . $row['forum_id'] . '&amp;t=' . $row['topic_id']),
						'U_FORUM'			=> (!$global_topic) ? append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $row['forum_id']) : '',
						'U_TOPIC'			=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $row['forum_id'] . '&amp;t=' . $row['topic_id']),

						'AUTHOR_FULL'		=> get_username_string('full', $row['poster_id'], $row['username'], $row['user_colour']),
						'AUTHOR'			=> get_username_string('username', $row['poster_id'], $row['username'], $row['user_colour']),
						'AUTHOR_COLOUR'		=> get_username_string('colour', $row['poster_id'], $row['username'], $row['user_colour']),
						'U_AUTHOR'			=> get_username_string('profile', $row['poster_id'], $row['username'], $row['user_colour']),

						'FORUM_NAME'	=> (!$global_topic) ? $forum_names[$row['forum_id']] : $user->lang['GLOBAL_ANNOUNCEMENT'],
						'POST_ID'		=> $row['post_id'],
						'TOPIC_TITLE'	=> $row['topic_title'],
						'SUBJECT'		=> ($row['post_subject']) ? $row['post_subject'] : $user->lang['NO_SUBJECT'],
						'POST_TIME'		=> $user->format_date($row['post_time']))
					);
				}
				$db->sql_freeresult($result);
			}

			$s_hidden_fields = build_hidden_fields(array(
				'redirect'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=main' . (($forum_id) ? '&amp;f=' . $forum_id : ''))
			));

			$template->assign_vars(array(
				'S_HIDDEN_FIELDS'		=> $s_hidden_fields,
				'S_MCP_QUEUE_ACTION'	=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=queue"),
			));

			if ($total == 0)
			{
				$template->assign_vars(array(
					'L_UNAPPROVED_TOTAL'		=> $user->lang['UNAPPROVED_POSTS_ZERO_TOTAL'],
					'S_HAS_UNAPPROVED_POSTS'	=> false)
				);
			}
			else
			{
				$template->assign_vars(array(
					'L_UNAPPROVED_TOTAL'		=> ($total == 1) ? $user->lang['UNAPPROVED_POST_TOTAL'] : sprintf($user->lang['UNAPPROVED_POSTS_TOTAL'], $total),
					'S_HAS_UNAPPROVED_POSTS'	=> true)
				);
			}
		}
	}

	// Latest 5 reported
	if ($module->loaded('reports'))
	{
		$forum_list = array_values(array_intersect(get_forum_list('f_read'), get_forum_list('m_report')));

		$template->assign_var('S_SHOW_REPORTS', (!empty($forum_list)) ? true : false);

		if (!empty($forum_list))
		{
			$sql = 'SELECT COUNT(r.report_id) AS total
				FROM ' . REPORTS_TABLE . ' r, ' . POSTS_TABLE . ' p
				WHERE r.post_id = p.post_id
					AND r.pm_id = 0
					AND r.report_closed = 0
					AND p.forum_id IN (0, ' . implode(', ', $forum_list) . ')';
			$result = $db->sql_query($sql);
			$total = (int) $db->sql_fetchfield('total');
			$db->sql_freeresult($result);

			if ($total)
			{
				$global_id = $forum_list[0];

				$sql = $db->sql_build_query('SELECT', array(
					'SELECT'	=> 'r.report_time, p.post_id, p.post_subject, p.post_time, u.username, u.username_clean, u.user_colour, u.user_id, u2.username as author_name, u2.username_clean as author_name_clean, u2.user_colour as author_colour, u2.user_id as author_id, t.topic_id, t.topic_title, f.forum_id, f.forum_name',

					'FROM'		=> array(
						REPORTS_TABLE			=> 'r',
						REPORTS_REASONS_TABLE	=> 'rr',
						TOPICS_TABLE			=> 't',
						USERS_TABLE				=> array('u', 'u2'),
						POSTS_TABLE				=> 'p'
					),

					'LEFT_JOIN'	=> array(
						array(
							'FROM'	=> array(FORUMS_TABLE => 'f'),
							'ON'	=> 'f.forum_id = p.forum_id'
						)
					),

					'WHERE'		=> 'r.post_id = p.post_id
						AND r.pm_id = 0
						AND r.report_closed = 0
						AND r.reason_id = rr.reason_id
						AND p.topic_id = t.topic_id
						AND r.user_id = u.user_id
						AND p.poster_id = u2.user_id
						AND p.forum_id IN (0, ' . implode(', ', $forum_list) . ')',

					'ORDER_BY'	=> 'p.post_time DESC'
				));
				$result = $db->sql_query_limit($sql, 5);

				while ($row = $db->sql_fetchrow($result))
				{
					$global_topic = ($row['forum_id']) ? false : true;
					if ($global_topic)
					{
						$row['forum_id'] = $global_id;
					}

					$template->assign_block_vars('report', array(
						'U_POST_DETAILS'	=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'f=' . $row['forum_id'] . '&amp;p=' . $row['post_id'] . "&amp;i=reports&amp;mode=report_details"),
						'U_MCP_FORUM'		=> (!$global_topic) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'f=' . $row['forum_id'] . "&amp;i=$id&amp;mode=forum_view") : '',
						'U_MCP_TOPIC'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'f=' . $row['forum_id'] . '&amp;t=' . $row['topic_id'] . "&amp;i=$id&amp;mode=topic_view"),
						'U_FORUM'			=> (!$global_topic) ? append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $row['forum_id']) : '',
						'U_TOPIC'			=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $row['forum_id'] . '&amp;t=' . $row['topic_id']),

						'REPORTER_FULL'		=> get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']),
						'REPORTER'			=> get_username_string('username', $row['user_id'], $row['username'], $row['user_colour']),
						'REPORTER_COLOUR'	=> get_username_string('colour', $row['user_id'], $row['username'], $row['user_colour']),
						'U_REPORTER'		=> get_username_string('profile', $row['user_id'], $row['username'], $row['user_colour']),

						'AUTHOR_FULL'		=> get_username_string('full', $row['author_id'], $row['author_name'], $row['author_colour']),
						'AUTHOR'			=> get_username_string('username', $row['author_id'], $row['author_name'], $row['author_colour']),
						'AUTHOR_COLOUR'		=> get_username_string('colour', $row['author_id'], $row['author_name'], $row['author_colour']),
						'U_AUTHOR'			=> get_username_string('profile', $row['author_id'], $row['author_name'], $row['author_colour']),

						'FORUM_NAME'	=> (!$global_topic) ? $row['forum_name'] : $user->lang['GLOBAL_ANNOUNCEMENT'],
						'TOPIC_TITLE'	=> $row['topic_title'],
						'SUBJECT'		=> ($row['post_subject']) ? $row['post_subject'] : $user->lang['NO_SUBJECT'],
						'REPORT_TIME'	=> $user->format_date($row['report_time']),
						'POST_TIME'		=> $user->format_date($row['post_time']),
					));
				}
			}

			if ($total == 0)
			{
				$template->assign_vars(array(
					'L_REPORTS_TOTAL'	=>	$user->lang['REPORTS_ZERO_TOTAL'],
					'S_HAS_REPORTS'		=>	false)
				);
			}
			else
			{
				$template->assign_vars(array(
					'L_REPORTS_TOTAL'	=> ($total == 1) ? $user->lang['REPORT_TOTAL'] : sprintf($user->lang['REPORTS_TOTAL'], $total),
					'S_HAS_REPORTS'		=> true)
				);
			}
		}
	}

	// Latest 5 reported PMs
	if ($module->loaded('pm_reports') && $auth->acl_getf_global('m_report'))
	{
		$template->assign_var('S_SHOW_PM_REPORTS', true);
		$user->add_lang(array('ucp'));

		$sql = 'SELECT COUNT(r.report_id) AS total
			FROM ' . REPORTS_TABLE . ' r, ' . PRIVMSGS_TABLE . ' p
			WHERE r.post_id = 0
				AND r.pm_id = p.msg_id
				AND r.report_closed = 0';
		$result = $db->sql_query($sql);
		$total = (int) $db->sql_fetchfield('total');
		$db->sql_freeresult($result);

		if ($total)
		{
			include($phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx);

			$sql = $db->sql_build_query('SELECT', array(
				'SELECT'	=> 'r.report_id, r.report_time, p.msg_id, p.message_subject, p.message_time, p.to_address, p.bcc_address, u.username, u.username_clean, u.user_colour, u.user_id, u2.username as author_name, u2.username_clean as author_name_clean, u2.user_colour as author_colour, u2.user_id as author_id',

				'FROM'		=> array(
					REPORTS_TABLE			=> 'r',
					REPORTS_REASONS_TABLE	=> 'rr',
					USERS_TABLE				=> array('u', 'u2'),
					PRIVMSGS_TABLE				=> 'p'
				),

				'WHERE'		=> 'r.pm_id = p.msg_id
					AND r.post_id = 0
					AND r.report_closed = 0
					AND r.reason_id = rr.reason_id
					AND r.user_id = u.user_id
					AND p.author_id = u2.user_id',

				'ORDER_BY'	=> 'p.message_time DESC'
			));
			$result = $db->sql_query_limit($sql, 5);

			$pm_by_id = $pm_list = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$pm_by_id[(int) $row['msg_id']] = $row;
				$pm_list[] = (int) $row['msg_id'];
			}

			$address_list = get_recipient_strings($pm_by_id);

			foreach ($pm_list as $message_id)
			{
				$row = $pm_by_id[$message_id];

				$template->assign_block_vars('pm_report', array(
					'U_PM_DETAILS'	=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'r=' . $row['report_id'] . "&amp;i=pm_reports&amp;mode=pm_report_details"),

					'REPORTER_FULL'		=> get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']),
					'REPORTER'			=> get_username_string('username', $row['user_id'], $row['username'], $row['user_colour']),
					'REPORTER_COLOUR'	=> get_username_string('colour', $row['user_id'], $row['username'], $row['user_colour']),
					'U_REPORTER'		=> get_username_string('profile', $row['user_id'], $row['username'], $row['user_colour']),

					'PM_AUTHOR_FULL'		=> get_username_string('full', $row['author_id'], $row['author_name'], $row['author_colour']),
					'PM_AUTHOR'			=> get_username_string('username', $row['author_id'], $row['author_name'], $row['author_colour']),
					'PM_AUTHOR_COLOUR'		=> get_username_string('colour', $row['author_id'], $row['author_name'], $row['author_colour']),
					'U_PM_AUTHOR'			=> get_username_string('profile', $row['author_id'], $row['author_name'], $row['author_colour']),

					'PM_SUBJECT'		=> $row['message_subject'],
					'REPORT_TIME'		=> $user->format_date($row['report_time']),
					'PM_TIME'			=> $user->format_date($row['message_time']),
					'RECIPIENTS'		=> implode(', ', $address_list[$row['msg_id']]),
				));
			}
		}

		if ($total == 0)
		{
			$template->assign_vars(array(
				'L_PM_REPORTS_TOTAL'	=>	$user->lang['PM_REPORTS_ZERO_TOTAL'],
				'S_HAS_PM_REPORTS'		=>	false)
			);
		}
		else
		{
			$template->assign_vars(array(
				'L_PM_REPORTS_TOTAL'	=> ($total == 1) ? $user->lang['PM_REPORT_TOTAL'] : sprintf($user->lang['PM_REPORTS_TOTAL'], $total),
				'S_HAS_PM_REPORTS'		=> true)
			);
		}
	}

	// Latest 5 logs
	if ($module->loaded('logs'))
	{
		$forum_list = array_values(array_intersect(get_forum_list('f_read'), get_forum_list('m_')));

		if (!empty($forum_list))
		{
			// Add forum_id 0 for global announcements
			$forum_list[] = 0;

			$log_count = 0;
			$log = array();
			view_log('mod', $log, $log_count, 5, 0, $forum_list);

			foreach ($log as $row)
			{
				$template->assign_block_vars('log', array(
					'USERNAME'		=> $row['username_full'],
					'IP'			=> $row['ip'],
					'TIME'			=> $user->format_date($row['time']),
					'ACTION'		=> $row['action'],
					'U_VIEW_TOPIC'	=> (!empty($row['viewtopic'])) ? $row['viewtopic'] : '',
					'U_VIEWLOGS'	=> (!empty($row['viewlogs'])) ? $row['viewlogs'] : '')
				);
			}
		}

		$template->assign_vars(array(
			'S_SHOW_LOGS'	=> (!empty($forum_list)) ? true : false,
			'S_HAS_LOGS'	=> (!empty($log)) ? true : false)
		);
	}

	$template->assign_var('S_MCP_ACTION', append_sid("{$phpbb_root_path}mcp.$phpEx"));
	make_jumpbox(append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=main&amp;mode=forum_view'), 0, false, 'm_', true);
}

/**
* MCP Forum View
*/
function mcp_forum_view($id, $mode, $action, $forum_info)
{
	global $template, $db, $user, $auth, $cache, $module;
	global $phpEx, $phpbb_root_path, $config;

	$user->add_lang(array('viewtopic', 'viewforum'));

	include_once($phpbb_root_path . 'includes/functions_display.' . $phpEx);

	// merge_topic is the quickmod action, merge_topics is the mcp_forum action, and merge_select is the mcp_topic action
	$merge_select = ($action == 'merge_select' || $action == 'merge_topic' || $action == 'merge_topics') ? true : false;

	if ($merge_select)
	{
		// Fixes a "bug" that makes forum_view use the same ordering as topic_view
		unset($_POST['sk'], $_POST['sd'], $_REQUEST['sk'], $_REQUEST['sd']);
	}

	$forum_id			= $forum_info['forum_id'];
	$start				= request_var('start', 0);
	$topic_id_list		= request_var('topic_id_list', array(0));
	$post_id_list		= request_var('post_id_list', array(0));
	$source_topic_ids	= array(request_var('t', 0));
	$to_topic_id		= request_var('to_topic_id', 0);

	$url_extra = '';
	$url_extra .= ($forum_id) ? "&amp;f=$forum_id" : '';
	$url_extra .= ($GLOBALS['topic_id']) ? '&amp;t=' . $GLOBALS['topic_id'] : '';
	$url_extra .= ($GLOBALS['post_id']) ? '&amp;p=' . $GLOBALS['post_id'] : '';
	$url_extra .= ($GLOBALS['user_id']) ? '&amp;u=' . $GLOBALS['user_id'] : '';

	$url = append_sid("{$phpbb_root_path}mcp.$phpEx?$url_extra");

	// Resync Topics
	switch ($action)
	{
		case 'resync':
			$topic_ids = request_var('topic_id_list', array(0));
			mcp_resync_topics($topic_ids);
		break;

		case 'merge_topics':
			$source_topic_ids = $topic_id_list;
		case 'merge_topic':
			if ($to_topic_id)
			{
				merge_topics($forum_id, $source_topic_ids, $to_topic_id);
			}
		break;
	}

	$selected_ids = '';
	if (sizeof($post_id_list) && $action != 'merge_topics')
	{
		foreach ($post_id_list as $num => $post_id)
		{
			$selected_ids .= '&amp;post_id_list[' . $num . ']=' . $post_id;
		}
	}
	else if (sizeof($topic_id_list) && $action == 'merge_topics')
	{
		foreach ($topic_id_list as $num => $topic_id)
		{
			$selected_ids .= '&amp;topic_id_list[' . $num . ']=' . $topic_id;
		}
	}

	make_jumpbox($url . "&amp;i=$id&amp;action=$action&amp;mode=$mode" . (($merge_select) ? $selected_ids : ''), $forum_id, false, 'm_', true);

	$topics_per_page = ($forum_info['forum_topics_per_page']) ? $forum_info['forum_topics_per_page'] : $config['topics_per_page'];

	$sort_days = $total = 0;
	$sort_key = $sort_dir = '';
	$sort_by_sql = $sort_order_sql = array();
	mcp_sorting('viewforum', $sort_days, $sort_key, $sort_dir, $sort_by_sql, $sort_order_sql, $total, $forum_id);

	$forum_topics = ($total == -1) ? $forum_info['forum_topics'] : $total;
	$limit_time_sql = ($sort_days) ? 'AND t.topic_last_post_time >= ' . (time() - ($sort_days * 86400)) : '';

	$template->assign_vars(array(
		'ACTION'				=> $action,
		'FORUM_NAME'			=> $forum_info['forum_name'],
		'FORUM_DESCRIPTION'		=> generate_text_for_display($forum_info['forum_desc'], $forum_info['forum_desc_uid'], $forum_info['forum_desc_bitfield'], $forum_info['forum_desc_options']),

		'REPORTED_IMG'			=> $user->img('icon_topic_reported', 'TOPIC_REPORTED'),
		'UNAPPROVED_IMG'		=> $user->img('icon_topic_unapproved', 'TOPIC_UNAPPROVED'),
		'LAST_POST_IMG'			=> $user->img('icon_topic_latest', 'VIEW_LATEST_POST'),
		'NEWEST_POST_IMG'		=> $user->img('icon_topic_newest', 'VIEW_NEWEST_POST'),

		'S_CAN_REPORT'			=> $auth->acl_get('m_report', $forum_id),
		'S_CAN_DELETE'			=> $auth->acl_get('m_delete', $forum_id),
		'S_CAN_MERGE'			=> $auth->acl_get('m_merge', $forum_id),
		'S_CAN_MOVE'			=> $auth->acl_get('m_move', $forum_id),
		'S_CAN_FORK'			=> $auth->acl_get('m_', $forum_id),
		'S_CAN_LOCK'			=> $auth->acl_get('m_lock', $forum_id),
		'S_CAN_SYNC'			=> $auth->acl_get('m_', $forum_id),
		'S_CAN_APPROVE'			=> $auth->acl_get('m_approve', $forum_id),
		'S_MERGE_SELECT'		=> ($merge_select) ? true : false,
		'S_CAN_MAKE_NORMAL'		=> $auth->acl_gets('f_sticky', 'f_announce', $forum_id),
		'S_CAN_MAKE_STICKY'		=> $auth->acl_get('f_sticky', $forum_id),
		'S_CAN_MAKE_ANNOUNCE'	=> $auth->acl_get('f_announce', $forum_id),

		'U_VIEW_FORUM'			=> append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id),
		'U_VIEW_FORUM_LOGS'		=> ($auth->acl_gets('a_', 'm_', $forum_id) && $module->loaded('logs')) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=logs&amp;mode=forum_logs&amp;f=' . $forum_id) : '',

		'S_MCP_ACTION'			=> $url . "&amp;i=$id&amp;forum_action=$action&amp;mode=$mode&amp;start=$start" . (($merge_select) ? $selected_ids : ''),

		'PAGINATION'			=> generate_pagination($url . "&amp;i=$id&amp;action=$action&amp;mode=$mode&amp;sd=$sort_dir&amp;sk=$sort_key&amp;st=$sort_days" . (($merge_select) ? $selected_ids : ''), $forum_topics, $topics_per_page, $start),
		'PAGE_NUMBER'			=> on_page($forum_topics, $topics_per_page, $start),
		'TOTAL_TOPICS'			=> ($forum_topics == 1) ? $user->lang['VIEW_FORUM_TOPIC'] : sprintf($user->lang['VIEW_FORUM_TOPICS'], $forum_topics),
	));

	// Grab icons
	$icons = $cache->obtain_icons();

	$topic_rows = array();

	if ($config['load_db_lastread'])
	{
		$read_tracking_join = ' LEFT JOIN ' . TOPICS_TRACK_TABLE . ' tt ON (tt.topic_id = t.topic_id AND tt.user_id = ' . $user->data['user_id'] . ')';
		$read_tracking_select = ', tt.mark_time';
	}
	else
	{
		$read_tracking_join = $read_tracking_select = '';
	}

	$sql = "SELECT t.topic_id
		FROM " . TOPICS_TABLE . " t
		WHERE t.forum_id IN($forum_id, 0)
			" . (($auth->acl_get('m_approve', $forum_id)) ? '' : 'AND t.topic_approved = 1') . "
			$limit_time_sql
		ORDER BY t.topic_type DESC, $sort_order_sql";
	$result = $db->sql_query_limit($sql, $topics_per_page, $start);

	$topic_list = $topic_tracking_info = array();

	while ($row = $db->sql_fetchrow($result))
	{
		$topic_list[] = $row['topic_id'];
	}
	$db->sql_freeresult($result);

	$sql = "SELECT t.*$read_tracking_select
		FROM " . TOPICS_TABLE . " t $read_tracking_join
		WHERE " . $db->sql_in_set('t.topic_id', $topic_list, false, true);

	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result))
	{
		$topic_rows[$row['topic_id']] = $row;
	}
	$db->sql_freeresult($result);

	// If there is more than one page, but we have no topic list, then the start parameter is... erm... out of sync
	if (!sizeof($topic_list) && $forum_topics && $start > 0)
	{
		redirect($url . "&amp;i=$id&amp;action=$action&amp;mode=$mode");
	}

	// Get topic tracking info
	if (sizeof($topic_list))
	{
		if ($config['load_db_lastread'])
		{
			$topic_tracking_info = get_topic_tracking($forum_id, $topic_list, $topic_rows, array($forum_id => $forum_info['mark_time']), array());
		}
		else
		{
			$topic_tracking_info = get_complete_topic_tracking($forum_id, $topic_list, array());
		}
	}

	foreach ($topic_list as $topic_id)
	{
		$topic_title = '';

		$row = &$topic_rows[$topic_id];

		$replies = ($auth->acl_get('m_approve', $forum_id)) ? $row['topic_replies_real'] : $row['topic_replies'];

		if ($row['topic_status'] == ITEM_MOVED)
		{
			$unread_topic = false;
		}
		else
		{
			$unread_topic = (isset($topic_tracking_info[$topic_id]) && $row['topic_last_post_time'] > $topic_tracking_info[$topic_id]) ? true : false;
		}

		// Get folder img, topic status/type related information
		$folder_img = $folder_alt = $topic_type = '';
		topic_status($row, $replies, $unread_topic, $folder_img, $folder_alt, $topic_type);

		$topic_title = censor_text($row['topic_title']);

		$topic_unapproved = (!$row['topic_approved'] && $auth->acl_get('m_approve', $row['forum_id'])) ? true : false;
		$posts_unapproved = ($row['topic_approved'] && $row['topic_replies'] < $row['topic_replies_real'] && $auth->acl_get('m_approve', $row['forum_id'])) ? true : false;
		$u_mcp_queue = ($topic_unapproved || $posts_unapproved) ? $url . '&amp;i=queue&amp;mode=' . (($topic_unapproved) ? 'approve_details' : 'unapproved_posts') . '&amp;t=' . $row['topic_id'] : '';

		$topic_row = array(
			'ATTACH_ICON_IMG'		=> ($auth->acl_get('u_download') && $auth->acl_get('f_download', $row['forum_id']) && $row['topic_attachment']) ? $user->img('icon_topic_attach', $user->lang['TOTAL_ATTACHMENTS']) : '',
			'TOPIC_FOLDER_IMG'		=> $user->img($folder_img, $folder_alt),
			'TOPIC_FOLDER_IMG_SRC'	=> $user->img($folder_img, $folder_alt, false, '', 'src'),
			'TOPIC_ICON_IMG'		=> (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['img'] : '',
			'TOPIC_ICON_IMG_WIDTH'	=> (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['width'] : '',
			'TOPIC_ICON_IMG_HEIGHT'	=> (!empty($icons[$row['icon_id']])) ? $icons[$row['icon_id']]['height'] : '',
			'UNAPPROVED_IMG'		=> ($topic_unapproved || $posts_unapproved) ? $user->img('icon_topic_unapproved', ($topic_unapproved) ? 'TOPIC_UNAPPROVED' : 'POSTS_UNAPPROVED') : '',

			'TOPIC_AUTHOR'				=> get_username_string('username', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
			'TOPIC_AUTHOR_COLOUR'		=> get_username_string('colour', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
			'TOPIC_AUTHOR_FULL'			=> get_username_string('full', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),
			'U_TOPIC_AUTHOR'			=> get_username_string('profile', $row['topic_poster'], $row['topic_first_poster_name'], $row['topic_first_poster_colour']),

			'LAST_POST_AUTHOR'			=> get_username_string('username', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
			'LAST_POST_AUTHOR_COLOUR'	=> get_username_string('colour', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
			'LAST_POST_AUTHOR_FULL'		=> get_username_string('full', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),
			'U_LAST_POST_AUTHOR'		=> get_username_string('profile', $row['topic_last_poster_id'], $row['topic_last_poster_name'], $row['topic_last_poster_colour']),

			'TOPIC_TYPE'		=> $topic_type,
			'TOPIC_TITLE'		=> $topic_title,
			'REPLIES'			=> ($auth->acl_get('m_approve', $row['forum_id'])) ? $row['topic_replies_real'] : $row['topic_replies'],
			'LAST_POST_TIME'	=> $user->format_date($row['topic_last_post_time']),
			'FIRST_POST_TIME'	=> $user->format_date($row['topic_time']),
			'LAST_POST_SUBJECT'	=> $row['topic_last_post_subject'],
			'LAST_VIEW_TIME'	=> $user->format_date($row['topic_last_view_time']),

			'S_TOPIC_REPORTED'		=> (!empty($row['topic_reported']) && empty($row['topic_moved_id']) && $auth->acl_get('m_report', $row['forum_id'])) ? true : false,
			'S_TOPIC_UNAPPROVED'	=> $topic_unapproved,
			'S_POSTS_UNAPPROVED'	=> $posts_unapproved,
			'S_UNREAD_TOPIC'		=> $unread_topic,
		);

		if ($row['topic_status'] == ITEM_MOVED)
		{
			$topic_row = array_merge($topic_row, array(
				'U_VIEW_TOPIC'		=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", "t={$row['topic_moved_id']}"),
				'U_DELETE_TOPIC'	=> ($auth->acl_get('m_delete', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;f=$forum_id&amp;topic_id_list[]={$row['topic_id']}&amp;mode=forum_view&amp;action=delete_topic") : '',
				'S_MOVED_TOPIC'		=> true,
				'TOPIC_ID'			=> $row['topic_moved_id'],
			));
		}
		else
		{
			if ($action == 'merge_topic' || $action == 'merge_topics')
			{
				$u_select_topic = $url . "&amp;i=$id&amp;mode=forum_view&amp;action=$action&amp;to_topic_id=" . $row['topic_id'] . $selected_ids;
			}
			else
			{
				$u_select_topic = $url . "&amp;i=$id&amp;mode=topic_view&amp;action=merge&amp;to_topic_id=" . $row['topic_id'] . $selected_ids;
			}
			$topic_row = array_merge($topic_row, array(
				'U_VIEW_TOPIC'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;f=$forum_id&amp;t={$row['topic_id']}&amp;mode=topic_view"),

				'S_SELECT_TOPIC'	=> ($merge_select && !in_array($row['topic_id'], $source_topic_ids)) ? true : false,
				'U_SELECT_TOPIC'	=> $u_select_topic,
				'U_MCP_QUEUE'		=> $u_mcp_queue,
				'U_MCP_REPORT'		=> ($auth->acl_get('m_report', $forum_id)) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=main&amp;mode=topic_view&amp;t=' . $row['topic_id'] . '&amp;action=reports') : '',
				'TOPIC_ID'			=> $row['topic_id'],
				'S_TOPIC_CHECKED'	=> ($topic_id_list && in_array($row['topic_id'], $topic_id_list)) ? true : false,
			));
		}

		$template->assign_block_vars('topicrow', $topic_row);
	}
	unset($topic_rows);
}

/**
* Resync topics
*/
function mcp_resync_topics($topic_ids)
{
	global $auth, $db, $template, $phpEx, $user, $phpbb_root_path;

	if (!sizeof($topic_ids))
	{
		trigger_error('NO_TOPIC_SELECTED');
	}

	if (!check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('m_')))
	{
		return;
	}

	// Sync everything and perform extra checks separately
	sync('topic_reported', 'topic_id', $topic_ids, false, true);
	sync('topic_attachment', 'topic_id', $topic_ids, false, true);
	sync('topic', 'topic_id', $topic_ids, true, false);

	$sql = 'SELECT topic_id, forum_id, topic_title
		FROM ' . TOPICS_TABLE . '
		WHERE ' . $db->sql_in_set('topic_id', $topic_ids);
	$result = $db->sql_query($sql);

	// Log this action
	while ($row = $db->sql_fetchrow($result))
	{
		add_log('mod', $row['forum_id'], $row['topic_id'], 'LOG_TOPIC_RESYNC', $row['topic_title']);
	}
	$db->sql_freeresult($result);

	$msg = (sizeof($topic_ids) == 1) ? $user->lang['TOPIC_RESYNC_SUCCESS'] : $user->lang['TOPICS_RESYNC_SUCCESS'];

	$redirect = request_var('redirect', $user->data['session_page']);

	meta_refresh(3, $redirect);
	trigger_error($msg . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>'));

	return;
}

/**
* Merge selected topics into selected topic
*/
function merge_topics($forum_id, $topic_ids, $to_topic_id)
{
	global $db, $template, $user, $phpEx, $phpbb_root_path, $auth;

	if (!sizeof($topic_ids))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_TOPIC_SELECTED']);
		return;
	}
	if (!$to_topic_id)
	{
		$template->assign_var('MESSAGE', $user->lang['NO_FINAL_TOPIC_SELECTED']);
		return;
	}

	$topic_data = get_topic_data(array($to_topic_id), 'm_merge');

	if (!sizeof($topic_data))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_FINAL_TOPIC_SELECTED']);
		return;
	}

	$topic_data = $topic_data[$to_topic_id];

	$post_id_list	= request_var('post_id_list', array(0));
	$start			= request_var('start', 0);

	if (!sizeof($post_id_list) && sizeof($topic_ids))
	{
		$sql = 'SELECT post_id
			FROM ' . POSTS_TABLE . '
			WHERE ' . $db->sql_in_set('topic_id', $topic_ids);
		$result = $db->sql_query($sql);

		$post_id_list = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$post_id_list[] = $row['post_id'];
		}
		$db->sql_freeresult($result);
	}

	if (!sizeof($post_id_list))
	{
		$template->assign_var('MESSAGE', $user->lang['NO_POST_SELECTED']);
		return;
	}

	if (!check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_merge')))
	{
		return;
	}

	$redirect = request_var('redirect', build_url(array('quickmod')));

	$s_hidden_fields = build_hidden_fields(array(
		'i'				=> 'main',
		'f'				=> $forum_id,
		'post_id_list'	=> $post_id_list,
		'to_topic_id'	=> $to_topic_id,
		'mode'			=> 'forum_view',
		'action'		=> 'merge_topics',
		'start'			=> $start,
		'redirect'		=> $redirect,
		'topic_id_list'	=> $topic_ids)
	);
	$success_msg = $return_link = '';

	if (confirm_box(true))
	{
		$to_forum_id = $topic_data['forum_id'];

		move_posts($post_id_list, $to_topic_id);
		add_log('mod', $to_forum_id, $to_topic_id, 'LOG_MERGE', $topic_data['topic_title']);

		// Message and return links
		$success_msg = 'POSTS_MERGED_SUCCESS';

		// If the topic no longer exist, we will update the topic watch table.
		// To not let it error out on users watching both topics, we just return on an error...
		$db->sql_return_on_error(true);
		$db->sql_query('UPDATE ' . TOPICS_WATCH_TABLE . ' SET topic_id = ' . (int) $to_topic_id . ' WHERE ' . $db->sql_in_set('topic_id', $topic_ids));
		$db->sql_return_on_error(false);

		$db->sql_query('DELETE FROM ' . TOPICS_WATCH_TABLE . ' WHERE ' . $db->sql_in_set('topic_id', $topic_ids));

		// Link to the new topic
		$return_link .= (($return_link) ? '<br /><br />' : '') . sprintf($user->lang['RETURN_NEW_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $to_forum_id . '&amp;t=' . $to_topic_id) . '">', '</a>');
	}
	else
	{
		confirm_box(false, 'MERGE_TOPICS', $s_hidden_fields);
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		return;
	}
	else
	{
		meta_refresh(3, append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f=$to_forum_id&amp;t=$to_topic_id"));
		trigger_error($user->lang[$success_msg] . '<br /><br />' . $return_link);
	}
}

/**
* Handling actions in post details screen
*/
function mcp_post_details($id, $mode, $action)
{
	global $phpEx, $phpbb_root_path, $config;
	global $template, $db, $user, $auth, $cache;

	$user->add_lang('posting');

	$post_id = request_var('p', 0);
	$start	= request_var('start', 0);

	// Get post data
	$post_info = get_post_data(array($post_id), false, true);

	add_form_key('mcp_post_details');

	if (!sizeof($post_info))
	{
		trigger_error('POST_NOT_EXIST');
	}

	$post_info = $post_info[$post_id];
	$url = append_sid("{$phpbb_root_path}mcp.$phpEx?" . extra_url());

	switch ($action)
	{
		case 'whois':

			if ($auth->acl_get('m_info', $post_info['forum_id']))
			{
				$ip = request_var('ip', '');
				include($phpbb_root_path . 'includes/functions_user.' . $phpEx);

				$template->assign_vars(array(
					'RETURN_POST'	=> sprintf($user->lang['RETURN_POST'], '<a href="' . append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;mode=$mode&amp;p=$post_id") . '">', '</a>'),
					'U_RETURN_POST'	=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;mode=$mode&amp;p=$post_id"),
					'L_RETURN_POST'	=> sprintf($user->lang['RETURN_POST'], '', ''),
					'WHOIS'			=> user_ipwhois($ip),
				));
			}

			// We're done with the whois page so return
			return;

		break;

		case 'chgposter':
		case 'chgposter_ip':

			if ($action == 'chgposter')
			{
				$username = request_var('username', '', true);
				$sql_where = "username_clean = '" . $db->sql_escape(utf8_clean_string($username)) . "'";
			}
			else
			{
				$new_user_id = request_var('u', 0);
				$sql_where = 'user_id = ' . $new_user_id;
			}

			$sql = 'SELECT *
				FROM ' . USERS_TABLE . '
				WHERE ' . $sql_where;
			$result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if (!$row)
			{
				trigger_error('NO_USER');
			}

			if ($auth->acl_get('m_chgposter', $post_info['forum_id']))
			{
				if (check_form_key('mcp_post_details'))
				{
					change_poster($post_info, $row);
				}
				else
				{
					trigger_error('FORM_INVALID');
				}
			}

		break;
	}

	// Set some vars
	$users_ary = $usernames_ary = array();
	$attachments = $extensions = array();
	$post_id = $post_info['post_id'];
	$topic_tracking_info = array();

	// Get topic tracking info
	if ($config['load_db_lastread'])
	{
		$tmp_topic_data = array($post_info['topic_id'] => $post_info);
		$topic_tracking_info = get_topic_tracking($post_info['forum_id'], $post_info['topic_id'], $tmp_topic_data, array($post_info['forum_id'] => $post_info['forum_mark_time']));
		unset($tmp_topic_data);
	}
	else
	{
		$topic_tracking_info = get_complete_topic_tracking($post_info['forum_id'], $post_info['topic_id']);
	}

	$post_unread = (isset($topic_tracking_info[$post_info['topic_id']]) && $post_info['post_time'] > $topic_tracking_info[$post_info['topic_id']]) ? true : false;

	// Process message, leave it uncensored
	$message = $post_info['post_text'];

	if ($post_info['bbcode_bitfield'])
	{
		include_once($phpbb_root_path . 'includes/bbcode.' . $phpEx);
		$bbcode = new bbcode($post_info['bbcode_bitfield']);
		$bbcode->bbcode_second_pass($message, $post_info['bbcode_uid'], $post_info['bbcode_bitfield']);
	}

	$message = bbcode_nl2br($message);
	$message = smiley_text($message);

	if ($post_info['post_attachment'] && $auth->acl_get('u_download') && $auth->acl_get('f_download', $post_info['forum_id']))
	{
		$extensions = $cache->obtain_attach_extensions($post_info['forum_id']);

		$sql = 'SELECT *
			FROM ' . ATTACHMENTS_TABLE . '
			WHERE post_msg_id = ' . $post_id . '
				AND in_message = 0
			ORDER BY filetime DESC, post_msg_id ASC';
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			$attachments[] = $row;
		}
		$db->sql_freeresult($result);

		if (sizeof($attachments))
		{
			$update_count = array();
			parse_attachments($post_info['forum_id'], $message, $attachments, $update_count);
		}

		// Display not already displayed Attachments for this post, we already parsed them. ;)
		if (!empty($attachments))
		{
			$template->assign_var('S_HAS_ATTACHMENTS', true);

			foreach ($attachments as $attachment)
			{
				$template->assign_block_vars('attachment', array(
					'DISPLAY_ATTACHMENT'	=> $attachment)
				);
			}
		}
	}

	$template->assign_vars(array(
		'U_MCP_ACTION'			=> "$url&amp;i=main&amp;quickmod=1&amp;mode=post_details", // Use this for mode paramaters
		'U_POST_ACTION'			=> "$url&amp;i=$id&amp;mode=post_details", // Use this for action parameters
		'U_APPROVE_ACTION'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=queue&amp;p=$post_id&amp;f={$post_info['forum_id']}"),

		'S_CAN_VIEWIP'			=> $auth->acl_get('m_info', $post_info['forum_id']),
		'S_CAN_CHGPOSTER'		=> $auth->acl_get('m_chgposter', $post_info['forum_id']),
		'S_CAN_LOCK_POST'		=> $auth->acl_get('m_lock', $post_info['forum_id']),
		'S_CAN_DELETE_POST'		=> $auth->acl_get('m_delete', $post_info['forum_id']),

		'S_POST_REPORTED'		=> ($post_info['post_reported']) ? true : false,
		'S_POST_UNAPPROVED'		=> (!$post_info['post_approved']) ? true : false,
		'S_POST_LOCKED'			=> ($post_info['post_edit_locked']) ? true : false,
		'S_USER_NOTES'			=> true,
		'S_CLEAR_ALLOWED'		=> ($auth->acl_get('a_clearlogs')) ? true : false,

		'U_EDIT'				=> ($auth->acl_get('m_edit', $post_info['forum_id'])) ? append_sid("{$phpbb_root_path}posting.$phpEx", "mode=edit&amp;f={$post_info['forum_id']}&amp;p={$post_info['post_id']}") : '',
		'U_FIND_USERNAME'		=> append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=searchuser&amp;form=mcp_chgposter&amp;field=username&amp;select_single=true'),
		'U_MCP_APPROVE'			=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=queue&amp;mode=approve_details&amp;f=' . $post_info['forum_id'] . '&amp;p=' . $post_id),
		'U_MCP_REPORT'			=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=reports&amp;mode=report_details&amp;f=' . $post_info['forum_id'] . '&amp;p=' . $post_id),
		'U_MCP_USER_NOTES'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=notes&amp;mode=user_notes&amp;u=' . $post_info['user_id']),
		'U_MCP_WARN_USER'		=> ($auth->acl_get('m_warn')) ? append_sid("{$phpbb_root_path}mcp.$phpEx", 'i=warn&amp;mode=warn_user&amp;u=' . $post_info['user_id']) : '',
		'U_VIEW_POST'			=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $post_info['forum_id'] . '&amp;p=' . $post_info['post_id'] . '#p' . $post_info['post_id']),
		'U_VIEW_TOPIC'			=> append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'f=' . $post_info['forum_id'] . '&amp;t=' . $post_info['topic_id']),

		'MINI_POST_IMG'			=> ($post_unread) ? $user->img('icon_post_target_unread', 'UNREAD_POST') : $user->img('icon_post_target', 'POST'),

		'RETURN_TOPIC'			=> sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f={$post_info['forum_id']}&amp;p=$post_id") . "#p$post_id\">", '</a>'),
		'RETURN_FORUM'			=> sprintf($user->lang['RETURN_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", "f={$post_info['forum_id']}&amp;start={$start}") . '">', '</a>'),
		'REPORTED_IMG'			=> $user->img('icon_topic_reported', $user->lang['POST_REPORTED']),
		'UNAPPROVED_IMG'		=> $user->img('icon_topic_unapproved', $user->lang['POST_UNAPPROVED']),
		'EDIT_IMG'				=> $user->img('icon_post_edit', $user->lang['EDIT_POST']),
		'SEARCH_IMG'			=> $user->img('icon_user_search', $user->lang['SEARCH']),

		'POST_AUTHOR_FULL'		=> get_username_string('full', $post_info['user_id'], $post_info['username'], $post_info['user_colour'], $post_info['post_username']),
		'POST_AUTHOR_COLOUR'	=> get_username_string('colour', $post_info['user_id'], $post_info['username'], $post_info['user_colour'], $post_info['post_username']),
		'POST_AUTHOR'			=> get_username_string('username', $post_info['user_id'], $post_info['username'], $post_info['user_colour'], $post_info['post_username']),
		'U_POST_AUTHOR'			=> get_username_string('profile', $post_info['user_id'], $post_info['username'], $post_info['user_colour'], $post_info['post_username']),

		'POST_PREVIEW'			=> $message,
		'POST_SUBJECT'			=> $post_info['post_subject'],
		'POST_DATE'				=> $user->format_date($post_info['post_time']),
		'POST_IP'				=> $post_info['poster_ip'],
		'POST_IPADDR'			=> ($auth->acl_get('m_info', $post_info['forum_id']) && request_var('lookup', '')) ? @gethostbyaddr($post_info['poster_ip']) : '',
		'POST_ID'				=> $post_info['post_id'],

		'U_LOOKUP_IP'			=> ($auth->acl_get('m_info', $post_info['forum_id'])) ? "$url&amp;i=$id&amp;mode=$mode&amp;lookup={$post_info['poster_ip']}#ip" : '',
		'U_WHOIS'				=> ($auth->acl_get('m_info', $post_info['forum_id'])) ? append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;mode=$mode&amp;action=whois&amp;p=$post_id&amp;ip={$post_info['poster_ip']}") : '',
	));

	// Get User Notes
	$log_data = array();
	$log_count = 0;
	view_log('user', $log_data, $log_count, $config['posts_per_page'], 0, 0, 0, $post_info['user_id']);

	if ($log_count)
	{
		$template->assign_var('S_USER_NOTES', true);

		foreach ($log_data as $row)
		{
			$template->assign_block_vars('usernotes', array(
				'REPORT_BY'		=> $row['username_full'],
				'REPORT_AT'		=> $user->format_date($row['time']),
				'ACTION'		=> $row['action'],
				'ID'			=> $row['id'])
			);
		}
	}

	// Get Reports
	if ($auth->acl_get('m_', $post_info['forum_id']))
	{
		$sql = 'SELECT r.*, re.*, u.user_id, u.username
			FROM ' . REPORTS_TABLE . ' r, ' . USERS_TABLE . ' u, ' . REPORTS_REASONS_TABLE . " re
			WHERE r.post_id = $post_id
				AND r.reason_id = re.reason_id
				AND u.user_id = r.user_id
			ORDER BY r.report_time DESC";
		$result = $db->sql_query($sql);

		if ($row = $db->sql_fetchrow($result))
		{
			$template->assign_var('S_SHOW_REPORTS', true);

			do
			{
				// If the reason is defined within the language file, we will use the localized version, else just use the database entry...
				if (isset($user->lang['report_reasons']['TITLE'][strtoupper($row['reason_title'])]) && isset($user->lang['report_reasons']['DESCRIPTION'][strtoupper($row['reason_title'])]))
				{
					$row['reson_description'] = $user->lang['report_reasons']['DESCRIPTION'][strtoupper($row['reason_title'])];
					$row['reason_title'] = $user->lang['report_reasons']['TITLE'][strtoupper($row['reason_title'])];
				}

				$template->assign_block_vars('reports', array(
					'REPORT_ID'		=> $row['report_id'],
					'REASON_TITLE'	=> $row['reason_title'],
					'REASON_DESC'	=> $row['reason_description'],
					'REPORTER'		=> ($row['user_id'] != ANONYMOUS) ? $row['username'] : $user->lang['GUEST'],
					'U_REPORTER'	=> ($row['user_id'] != ANONYMOUS) ? append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=viewprofile&amp;u=' . $row['user_id']) : '',
					'USER_NOTIFY'	=> ($row['user_notify']) ? true : false,
					'REPORT_TIME'	=> $user->format_date($row['report_time']),
					'REPORT_TEXT'	=> bbcode_nl2br(trim($row['report_text'])),
				));
			}
			while ($row = $db->sql_fetchrow($result));
		}
		$db->sql_freeresult($result);
	}

	// Get IP
	if ($auth->acl_get('m_info', $post_info['forum_id']))
	{
		$rdns_ip_num = request_var('rdns', '');

		if ($rdns_ip_num != 'all')
		{
			$template->assign_vars(array(
				'U_LOOKUP_ALL'	=> "$url&amp;i=main&amp;mode=post_details&amp;rdns=all")
			);
		}

		// Get other users who've posted under this IP
		$sql = 'SELECT poster_id, COUNT(poster_id) as postings
			FROM ' . POSTS_TABLE . "
			WHERE poster_ip = '" . $db->sql_escape($post_info['poster_ip']) . "'
			GROUP BY poster_id
			ORDER BY postings DESC";
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			// Fill the user select list with users who have posted under this IP
			if ($row['poster_id'] != $post_info['poster_id'])
			{
				$users_ary[$row['poster_id']] = $row;
			}
		}
		$db->sql_freeresult($result);

		if (sizeof($users_ary))
		{
			// Get the usernames
			$sql = 'SELECT user_id, username
				FROM ' . USERS_TABLE . '
				WHERE ' . $db->sql_in_set('user_id', array_keys($users_ary));
			$result = $db->sql_query($sql);

			while ($row = $db->sql_fetchrow($result))
			{
				$users_ary[$row['user_id']]['username'] = $row['username'];
				$usernames_ary[utf8_clean_string($row['username'])] = $users_ary[$row['user_id']];
			}
			$db->sql_freeresult($result);

			foreach ($users_ary as $user_id => $user_row)
			{
				$template->assign_block_vars('userrow', array(
					'USERNAME'		=> ($user_id == ANONYMOUS) ? $user->lang['GUEST'] : $user_row['username'],
					'NUM_POSTS'		=> $user_row['postings'],
					'L_POST_S'		=> ($user_row['postings'] == 1) ? $user->lang['POST'] : $user->lang['POSTS'],

					'U_PROFILE'		=> ($user_id == ANONYMOUS) ? '' : append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=viewprofile&amp;u=' . $user_id),
					'U_SEARCHPOSTS' => append_sid("{$phpbb_root_path}search.$phpEx", 'author_id=' . $user_id . '&amp;sr=topics'))
				);
			}
		}

		// Get other IP's this user has posted under

		// A compound index on poster_id, poster_ip (posts table) would help speed up this query a lot,
		// but the extra size is only valuable if there are persons having more than a thousands posts.
		// This is better left to the really really big forums.

		$sql = 'SELECT poster_ip, COUNT(poster_ip) AS postings
			FROM ' . POSTS_TABLE . '
			WHERE poster_id = ' . $post_info['poster_id'] . "
			GROUP BY poster_ip
			ORDER BY postings DESC";
		$result = $db->sql_query($sql);

		while ($row = $db->sql_fetchrow($result))
		{
			$hostname = (($rdns_ip_num == $row['poster_ip'] || $rdns_ip_num == 'all') && $row['poster_ip']) ? @gethostbyaddr($row['poster_ip']) : '';

			$template->assign_block_vars('iprow', array(
				'IP'			=> $row['poster_ip'],
				'HOSTNAME'		=> $hostname,
				'NUM_POSTS'		=> $row['postings'],
				'L_POST_S'		=> ($row['postings'] == 1) ? $user->lang['POST'] : $user->lang['POSTS'],

				'U_LOOKUP_IP'	=> ($rdns_ip_num == $row['poster_ip'] || $rdns_ip_num == 'all') ? '' : "$url&amp;i=$id&amp;mode=post_details&amp;rdns={$row['poster_ip']}#ip",
				'U_WHOIS'		=> append_sid("{$phpbb_root_path}mcp.$phpEx", "i=$id&amp;mode=$mode&amp;action=whois&amp;p=$post_id&amp;ip={$row['poster_ip']}"))
			);
		}
		$db->sql_freeresult($result);

		$user_select = '';

		if (sizeof($usernames_ary))
		{
			ksort($usernames_ary);

			foreach ($usernames_ary as $row)
			{
				$user_select .= '<option value="' . $row['poster_id'] . '">' . $row['username'] . "</option>\n";
			}
		}

		$template->assign_var('S_USER_SELECT', $user_select);
	}

}

/**
* Change a post's poster
*/
function change_poster(&$post_info, $userdata)
{
	global $auth, $db, $config, $phpbb_root_path, $phpEx;

	if (empty($userdata) || $userdata['user_id'] == $post_info['user_id'])
	{
		return;
	}

	$post_id = $post_info['post_id'];

	$sql = 'UPDATE ' . POSTS_TABLE . "
		SET poster_id = {$userdata['user_id']}
		WHERE post_id = $post_id";
	$db->sql_query($sql);

	// Resync topic/forum if needed
	if ($post_info['topic_last_post_id'] == $post_id || $post_info['forum_last_post_id'] == $post_id || $post_info['topic_first_post_id'] == $post_id)
	{
		sync('topic', 'topic_id', $post_info['topic_id'], false, false);
		sync('forum', 'forum_id', $post_info['forum_id'], false, false);
	}

	// Adjust post counts... only if the post is approved (else, it was not added the users post count anyway)
	if ($post_info['post_postcount'] && $post_info['post_approved'])
	{
		$sql = 'UPDATE ' . USERS_TABLE . '
			SET user_posts = user_posts - 1
			WHERE user_id = ' . $post_info['user_id'] .'
			AND user_posts > 0';
		$db->sql_query($sql);

		$sql = 'UPDATE ' . USERS_TABLE . '
			SET user_posts = user_posts + 1
			WHERE user_id = ' . $userdata['user_id'];
		$db->sql_query($sql);
	}

	// Add posted to information for this topic for the new user
	markread('post', $post_info['forum_id'], $post_info['topic_id'], time(), $userdata['user_id']);

	// Remove the dotted topic option if the old user has no more posts within this topic
	if ($config['load_db_track'] && $post_info['user_id'] != ANONYMOUS)
	{
		$sql = 'SELECT topic_id
			FROM ' . POSTS_TABLE . '
			WHERE topic_id = ' . $post_info['topic_id'] . '
				AND poster_id = ' . $post_info['user_id'];
		$result = $db->sql_query_limit($sql, 1);
		$topic_id = (int) $db->sql_fetchfield('topic_id');
		$db->sql_freeresult($result);

		if (!$topic_id)
		{
			$sql = 'DELETE FROM ' . TOPICS_POSTED_TABLE . '
				WHERE user_id = ' . $post_info['user_id'] . '
					AND topic_id = ' . $post_info['topic_id'];
			$db->sql_query($sql);
		}
	}

	// change the poster_id within the attachments table, else the data becomes out of sync and errors displayed because of wrong ownership
	if ($post_info['post_attachment'])
	{
		$sql = 'UPDATE ' . ATTACHMENTS_TABLE . '
			SET poster_id = ' . $userdata['user_id'] . '
			WHERE poster_id = ' . $post_info['user_id'] . '
				AND post_msg_id = ' . $post_info['post_id'] . '
				AND topic_id = ' . $post_info['topic_id'];
		$db->sql_query($sql);
	}

	// refresh search cache of this post
	$search_type = basename($config['search_type']);

	if (file_exists($phpbb_root_path . 'includes/search/' . $search_type . '.' . $phpEx))
	{
		require("{$phpbb_root_path}includes/search/$search_type.$phpEx");

		// We do some additional checks in the module to ensure it can actually be utilised
		$error = false;
		$search = new $search_type($error);

		if (!$error && method_exists($search, 'destroy_cache'))
		{
			$search->destroy_cache(array(), array($post_info['user_id'], $userdata['user_id']));
		}
	}

	$from_username = $post_info['username'];
	$to_username = $userdata['username'];

	// Renew post info
	$post_info = get_post_data(array($post_id), false, true);

	if (!sizeof($post_info))
	{
		trigger_error('POST_NOT_EXIST');
	}

	$post_info = $post_info[$post_id];

	// Now add log entry
	add_log('mod', $post_info['forum_id'], $post_info['topic_id'], 'LOG_MCP_CHANGE_POSTER', $post_info['topic_title'], $from_username, $to_username);
}


/**
* Lock/Unlock Topic/Post
*/
function lock_unlock($action, $ids)
{
	global $auth, $user, $db, $phpEx, $phpbb_root_path;

	if ($action == 'lock' || $action == 'unlock')
	{
		$table = TOPICS_TABLE;
		$sql_id = 'topic_id';
		$set_id = 'topic_status';
		$l_prefix = 'TOPIC';
	}
	else
	{
		$table = POSTS_TABLE;
		$sql_id = 'post_id';
		$set_id = 'post_edit_locked';
		$l_prefix = 'POST';
	}

	$orig_ids = $ids;

	if (!check_ids($ids, $table, $sql_id, array('m_lock')))
	{
		// Make sure that for f_user_lock only the lock action is triggered.
		if ($action != 'lock')
		{
			return;
		}

		$ids = $orig_ids;

		if (!check_ids($ids, $table, $sql_id, array('f_user_lock')))
		{
			return;
		}
	}
	unset($orig_ids);

	$redirect = request_var('redirect', build_url(array('action', 'quickmod')));

	$s_hidden_fields = build_hidden_fields(array(
		$sql_id . '_list'	=> $ids,
		'action'			=> $action,
		'redirect'			=> $redirect)
	);
	$success_msg = '';

	if (confirm_box(true))
	{
		$sql = "UPDATE $table
			SET $set_id = " . (($action == 'lock' || $action == 'lock_post') ? ITEM_LOCKED : ITEM_UNLOCKED) . '
			WHERE ' . $db->sql_in_set($sql_id, $ids);
		$db->sql_query($sql);

		$data = ($action == 'lock' || $action == 'unlock') ? get_topic_data($ids) : get_post_data($ids);

		foreach ($data as $id => $row)
		{
			add_log('mod', $row['forum_id'], $row['topic_id'], 'LOG_' . strtoupper($action), $row['topic_title']);
		}

		$success_msg = $l_prefix . ((sizeof($ids) == 1) ? '' : 'S') . '_' . (($action == 'lock' || $action == 'lock_post') ? 'LOCKED' : 'UNLOCKED') . '_SUCCESS';
	}
	else
	{
		confirm_box(false, strtoupper($action) . '_' . $l_prefix . ((sizeof($ids) == 1) ? '' : 'S'), $s_hidden_fields);
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(2, $redirect);
		trigger_error($user->lang[$success_msg] . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>'));
	}
}

/**
* Change Topic Type
*/
function change_topic_type($action, $topic_ids)
{
	global $auth, $user, $db, $phpEx, $phpbb_root_path;

	// For changing topic types, we only allow operations in one forum.
	$forum_id = check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('f_announce', 'f_sticky', 'm_'), true);

	if ($forum_id === false)
	{
		return;
	}

	switch ($action)
	{
		case 'make_announce':
			$new_topic_type = POST_ANNOUNCE;
			$check_acl = 'f_announce';
			$l_new_type = (sizeof($topic_ids) == 1) ? 'MCP_MAKE_ANNOUNCEMENT' : 'MCP_MAKE_ANNOUNCEMENTS';
		break;

		case 'make_global':
			$new_topic_type = POST_GLOBAL;
			$check_acl = 'f_announce';
			$l_new_type = (sizeof($topic_ids) == 1) ? 'MCP_MAKE_GLOBAL' : 'MCP_MAKE_GLOBALS';
		break;

		case 'make_sticky':
			$new_topic_type = POST_STICKY;
			$check_acl = 'f_sticky';
			$l_new_type = (sizeof($topic_ids) == 1) ? 'MCP_MAKE_STICKY' : 'MCP_MAKE_STICKIES';
		break;

		default:
			$new_topic_type = POST_NORMAL;
			$check_acl = '';
			$l_new_type = (sizeof($topic_ids) == 1) ? 'MCP_MAKE_NORMAL' : 'MCP_MAKE_NORMALS';
		break;
	}

	$redirect = request_var('redirect', build_url(array('action', 'quickmod')));

	$s_hidden_fields = array(
		'topic_id_list'	=> $topic_ids,
		'f'				=> $forum_id,
		'action'		=> $action,
		'redirect'		=> $redirect,
	);
	$success_msg = '';

	if (confirm_box(true))
	{
		if ($new_topic_type != POST_GLOBAL)
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . "
				SET topic_type = $new_topic_type
				WHERE " . $db->sql_in_set('topic_id', $topic_ids) . '
					AND forum_id <> 0';
			$db->sql_query($sql);

			// Reset forum id if a global topic is within the array
			$to_forum_id = request_var('to_forum_id', 0);

			if ($to_forum_id)
			{
				$sql = 'UPDATE ' . TOPICS_TABLE . "
					SET topic_type = $new_topic_type, forum_id = $to_forum_id
					WHERE " . $db->sql_in_set('topic_id', $topic_ids) . '
						AND forum_id = 0';
				$db->sql_query($sql);

				// Update forum_ids for all posts
				$sql = 'UPDATE ' . POSTS_TABLE . "
					SET forum_id = $to_forum_id
					WHERE " . $db->sql_in_set('topic_id', $topic_ids) . '
						AND forum_id = 0';
				$db->sql_query($sql);

				// Do a little forum sync stuff
				$sql = 'SELECT SUM(t.topic_replies + t.topic_approved) as topic_posts, COUNT(t.topic_approved) as topics_authed
					FROM ' . TOPICS_TABLE . ' t
					WHERE ' . $db->sql_in_set('t.topic_id', $topic_ids);
				$result = $db->sql_query($sql);
				$row_data = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);

				$sync_sql = array();

				if ($row_data['topic_posts'])
				{
					$sync_sql[$to_forum_id][]	= 'forum_posts = forum_posts + ' . (int) $row_data['topic_posts'];
				}

				if ($row_data['topics_authed'])
				{
					$sync_sql[$to_forum_id][]	= 'forum_topics = forum_topics + ' . (int) $row_data['topics_authed'];
				}

				$sync_sql[$to_forum_id][]	= 'forum_topics_real = forum_topics_real + ' . (int) sizeof($topic_ids);

				foreach ($sync_sql as $forum_id_key => $array)
				{
					$sql = 'UPDATE ' . FORUMS_TABLE . '
						SET ' . implode(', ', $array) . '
						WHERE forum_id = ' . $forum_id_key;
					$db->sql_query($sql);
				}

				sync('forum', 'forum_id', $to_forum_id);
			}
		}
		else
		{
			// Get away with those topics already being a global announcement by re-calculating $topic_ids
			$sql = 'SELECT topic_id
				FROM ' . TOPICS_TABLE . '
				WHERE ' . $db->sql_in_set('topic_id', $topic_ids) . '
					AND forum_id <> 0';
			$result = $db->sql_query($sql);

			$topic_ids = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$topic_ids[] = $row['topic_id'];
			}
			$db->sql_freeresult($result);

			if (sizeof($topic_ids))
			{
				// Delete topic shadows for global announcements
				$sql = 'DELETE FROM ' . TOPICS_TABLE . '
					WHERE ' . $db->sql_in_set('topic_moved_id', $topic_ids);
				$db->sql_query($sql);

				$sql = 'UPDATE ' . TOPICS_TABLE . "
					SET topic_type = $new_topic_type, forum_id = 0
						WHERE " . $db->sql_in_set('topic_id', $topic_ids);
				$db->sql_query($sql);

				// Update forum_ids for all posts
				$sql = 'UPDATE ' . POSTS_TABLE . '
					SET forum_id = 0
					WHERE ' . $db->sql_in_set('topic_id', $topic_ids);
				$db->sql_query($sql);

				// Do a little forum sync stuff
				$sql = 'SELECT SUM(t.topic_replies + t.topic_approved) as topic_posts, COUNT(t.topic_approved) as topics_authed
					FROM ' . TOPICS_TABLE . ' t
					WHERE ' . $db->sql_in_set('t.topic_id', $topic_ids);
				$result = $db->sql_query($sql);
				$row_data = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);

				$sync_sql = array();

				if ($row_data['topic_posts'])
				{
					$sync_sql[$forum_id][]	= 'forum_posts = forum_posts - ' . (int) $row_data['topic_posts'];
				}

				if ($row_data['topics_authed'])
				{
					$sync_sql[$forum_id][]	= 'forum_topics = forum_topics - ' . (int) $row_data['topics_authed'];
				}

				$sync_sql[$forum_id][]	= 'forum_topics_real = forum_topics_real - ' . (int) sizeof($topic_ids);

				foreach ($sync_sql as $forum_id_key => $array)
				{
					$sql = 'UPDATE ' . FORUMS_TABLE . '
						SET ' . implode(', ', $array) . '
						WHERE forum_id = ' . $forum_id_key;
					$db->sql_query($sql);
				}

				sync('forum', 'forum_id', $forum_id);
			}
		}

		$success_msg = (sizeof($topic_ids) == 1) ? 'TOPIC_TYPE_CHANGED' : 'TOPICS_TYPE_CHANGED';

		if (sizeof($topic_ids))
		{
			$data = get_topic_data($topic_ids);

			foreach ($data as $topic_id => $row)
			{
				add_log('mod', $forum_id, $topic_id, 'LOG_TOPIC_TYPE_CHANGED', $row['topic_title']);
			}
		}
	}
	else
	{
		// Global topic involved?
		$global_involved = false;

		if ($new_topic_type != POST_GLOBAL)
		{
			$sql = 'SELECT forum_id
				FROM ' . TOPICS_TABLE . '
				WHERE ' . $db->sql_in_set('topic_id', $topic_ids) . '
					AND forum_id = 0';
			$result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if ($row)
			{
				$global_involved = true;
			}
		}

		if ($global_involved)
		{
			global $template;

			$template->assign_vars(array(
				'S_FORUM_SELECT'		=> make_forum_select(request_var('f', $forum_id), false, false, true, true),
				'S_CAN_LEAVE_SHADOW'	=> false,
				'ADDITIONAL_MSG'		=> (sizeof($topic_ids) == 1) ? $user->lang['SELECT_FORUM_GLOBAL_ANNOUNCEMENT'] : $user->lang['SELECT_FORUM_GLOBAL_ANNOUNCEMENTS'])
			);

			confirm_box(false, $l_new_type, build_hidden_fields($s_hidden_fields), 'mcp_move.html');
		}
		else
		{
			confirm_box(false, $l_new_type, build_hidden_fields($s_hidden_fields));
		}
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(2, $redirect);
		trigger_error($user->lang[$success_msg] . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>'));
	}
}

/**
* Move Topic
*/
function mcp_move_topic($topic_ids)
{
	global $auth, $user, $db, $template;
	global $phpEx, $phpbb_root_path;

	// Here we limit the operation to one forum only
	$forum_id = check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('m_move'), true);

	if ($forum_id === false)
	{
		return;
	}

	$to_forum_id = request_var('to_forum_id', 0);
	$redirect = request_var('redirect', build_url(array('action', 'quickmod')));
	$additional_msg = $success_msg = '';

	$s_hidden_fields = build_hidden_fields(array(
		'topic_id_list'	=> $topic_ids,
		'f'				=> $forum_id,
		'action'		=> 'move',
		'redirect'		=> $redirect)
	);

	if ($to_forum_id)
	{
		$forum_data = get_forum_data($to_forum_id, 'f_post');

		if (!sizeof($forum_data))
		{
			$additional_msg = $user->lang['FORUM_NOT_EXIST'];
		}
		else
		{
			$forum_data = $forum_data[$to_forum_id];

			if ($forum_data['forum_type'] != FORUM_POST)
			{
				$additional_msg = $user->lang['FORUM_NOT_POSTABLE'];
			}
			else if (!$auth->acl_get('f_post', $to_forum_id) || (!$auth->acl_get('m_approve', $to_forum_id) && !$auth->acl_get('f_noapprove', $to_forum_id)))
			{
				$additional_msg = $user->lang['USER_CANNOT_POST'];
			}
			else if ($forum_id == $to_forum_id)
			{
				$additional_msg = $user->lang['CANNOT_MOVE_SAME_FORUM'];
			}
		}
	}
	else if (isset($_POST['confirm']))
	{
		$additional_msg = $user->lang['FORUM_NOT_EXIST'];
	}

	if (!$to_forum_id || $additional_msg)
	{
		unset($_POST['confirm']);
		unset($_REQUEST['confirm_key']);
	}

	if (confirm_box(true))
	{
		$topic_data = get_topic_data($topic_ids);
		$leave_shadow = (isset($_POST['move_leave_shadow'])) ? true : false;

		$forum_sync_data = array();

		$forum_sync_data[$forum_id] = current($topic_data);
		$forum_sync_data[$to_forum_id] = $forum_data;

		// Real topics added to target forum
		$topics_moved = sizeof($topic_data);

		// Approved topics added to target forum
		$topics_authed_moved = 0;

		// Posts (topic replies + topic post if approved) added to target forum
		$topic_posts_added = 0;

		// Posts (topic replies + topic post if approved and not global announcement) removed from source forum
		$topic_posts_removed = 0;

		// Real topics removed from source forum (all topics without global announcements)
		$topics_removed = 0;

		// Approved topics removed from source forum (except global announcements)
		$topics_authed_removed = 0;

		foreach ($topic_data as $topic_id => $topic_info)
		{
			if ($topic_info['topic_approved'])
			{
				$topics_authed_moved++;
				$topic_posts_added++;
			}

			$topic_posts_added += $topic_info['topic_replies'];

			if ($topic_info['topic_type'] != POST_GLOBAL)
			{
				$topics_removed++;
				$topic_posts_removed += $topic_info['topic_replies'];

				if ($topic_info['topic_approved'])
				{
					$topics_authed_removed++;
					$topic_posts_removed++;
				}
			}
		}

		$db->sql_transaction('begin');

		$sync_sql = array();

		if ($topic_posts_added)
		{
			$sync_sql[$to_forum_id][] = 'forum_posts = forum_posts + ' . $topic_posts_added;
		}

		if ($topics_authed_moved)
		{
			$sync_sql[$to_forum_id][] = 'forum_topics = forum_topics + ' . (int) $topics_authed_moved;
		}

		$sync_sql[$to_forum_id][] = 'forum_topics_real = forum_topics_real + ' . (int) $topics_moved;

		// Move topics, but do not resync yet
		move_topics($topic_ids, $to_forum_id, false);

		$forum_ids = array($to_forum_id);
		foreach ($topic_data as $topic_id => $row)
		{
			// Get the list of forums to resync, add a log entry
			$forum_ids[] = $row['forum_id'];
			add_log('mod', $to_forum_id, $topic_id, 'LOG_MOVE', $row['forum_name'], $forum_data['forum_name']);

			// If we have moved a global announcement, we need to correct the topic type
			if ($row['topic_type'] == POST_GLOBAL)
			{
				$sql = 'UPDATE ' . TOPICS_TABLE . '
					SET topic_type = ' . POST_ANNOUNCE . '
					WHERE topic_id = ' . (int) $row['topic_id'];
				$db->sql_query($sql);
			}

			// Leave a redirection if required and only if the topic is visible to users
			if ($leave_shadow && $row['topic_approved'] && $row['topic_type'] != POST_GLOBAL)
			{
				$shadow = array(
					'forum_id'				=>	(int) $row['forum_id'],
					'icon_id'				=>	(int) $row['icon_id'],
					'topic_attachment'		=>	(int) $row['topic_attachment'],
					'topic_approved'		=>	1, // a shadow topic is always approved
					'topic_reported'		=>	0, // a shadow topic is never reported
					'topic_title'			=>	(string) $row['topic_title'],
					'topic_poster'			=>	(int) $row['topic_poster'],
					'topic_time'			=>	(int) $row['topic_time'],
					'topic_time_limit'		=>	(int) $row['topic_time_limit'],
					'topic_views'			=>	(int) $row['topic_views'],
					'topic_replies'			=>	(int) $row['topic_replies'],
					'topic_replies_real'	=>	(int) $row['topic_replies_real'],
					'topic_status'			=>	ITEM_MOVED,
					'topic_type'			=>	POST_NORMAL,
					'topic_first_post_id'	=>	(int) $row['topic_first_post_id'],
					'topic_first_poster_colour'=>(string) $row['topic_first_poster_colour'],
					'topic_first_poster_name'=>	(string) $row['topic_first_poster_name'],
					'topic_last_post_id'	=>	(int) $row['topic_last_post_id'],
					'topic_last_poster_id'	=>	(int) $row['topic_last_poster_id'],
					'topic_last_poster_colour'=>(string) $row['topic_last_poster_colour'],
					'topic_last_poster_name'=>	(string) $row['topic_last_poster_name'],
					'topic_last_post_subject'=>	(string)  $row['topic_last_post_subject'],
					'topic_last_post_time'	=>	(int) $row['topic_last_post_time'],
					'topic_last_view_time'	=>	(int) $row['topic_last_view_time'],
					'topic_moved_id'		=>	(int) $row['topic_id'],
					'topic_bumped'			=>	(int) $row['topic_bumped'],
					'topic_bumper'			=>	(int) $row['topic_bumper'],
					'poll_title'			=>	(string) $row['poll_title'],
					'poll_start'			=>	(int) $row['poll_start'],
					'poll_length'			=>	(int) $row['poll_length'],
					'poll_max_options'		=>	(int) $row['poll_max_options'],
					'poll_last_vote'		=>	(int) $row['poll_last_vote']
				);

				$db->sql_query('INSERT INTO ' . TOPICS_TABLE . $db->sql_build_array('INSERT', $shadow));

				// Shadow topics only count on new "topics" and not posts... a shadow topic alone has 0 posts
				$topics_removed--;
				$topics_authed_removed--;
			}
		}
		unset($topic_data);

		if ($topic_posts_removed)
		{
			$sync_sql[$forum_id][] = 'forum_posts = forum_posts - ' . $topic_posts_removed;
		}

		if ($topics_removed)
		{
			$sync_sql[$forum_id][]	= 'forum_topics_real = forum_topics_real - ' . (int) $topics_removed;
		}

		if ($topics_authed_removed)
		{
			$sync_sql[$forum_id][]	= 'forum_topics = forum_topics - ' . (int) $topics_authed_removed;
		}

		$success_msg = (sizeof($topic_ids) == 1) ? 'TOPIC_MOVED_SUCCESS' : 'TOPICS_MOVED_SUCCESS';

		foreach ($sync_sql as $forum_id_key => $array)
		{
			$sql = 'UPDATE ' . FORUMS_TABLE . '
				SET ' . implode(', ', $array) . '
				WHERE forum_id = ' . $forum_id_key;
			$db->sql_query($sql);
		}

		$db->sql_transaction('commit');

		sync('forum', 'forum_id', array($forum_id, $to_forum_id));
	}
	else
	{
		$template->assign_vars(array(
			'S_FORUM_SELECT'		=> make_forum_select($to_forum_id, $forum_id, false, true, true, true),
			'S_CAN_LEAVE_SHADOW'	=> true,
			'ADDITIONAL_MSG'		=> $additional_msg)
		);

		confirm_box(false, 'MOVE_TOPIC' . ((sizeof($topic_ids) == 1) ? '' : 'S'), $s_hidden_fields, 'mcp_move.html');
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);

		$message = $user->lang[$success_msg];
		$message .= '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>');
		$message .= '<br /><br />' . sprintf($user->lang['RETURN_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$forum_id") . '">', '</a>');
		$message .= '<br /><br />' . sprintf($user->lang['RETURN_NEW_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$to_forum_id") . '">', '</a>');

		trigger_error($message);
	}
}

/**
* Delete Topics
*/
function mcp_delete_topic($topic_ids)
{
	global $auth, $user, $db, $phpEx, $phpbb_root_path;

	if (!check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('m_delete')))
	{
		return;
	}

	$redirect = request_var('redirect', build_url(array('action', 'quickmod')));
	$forum_id = request_var('f', 0);

	$s_hidden_fields = build_hidden_fields(array(
		'topic_id_list'	=> $topic_ids,
		'f'				=> $forum_id,
		'action'		=> 'delete_topic',
		'redirect'		=> $redirect)
	);
	$success_msg = '';

	if (confirm_box(true))
	{
		$success_msg = (sizeof($topic_ids) == 1) ? 'TOPIC_DELETED_SUCCESS' : 'TOPICS_DELETED_SUCCESS';

		$data = get_topic_data($topic_ids);

		foreach ($data as $topic_id => $row)
		{
			if ($row['topic_moved_id'])
			{
				add_log('mod', $row['forum_id'], $topic_id, 'LOG_DELETE_SHADOW_TOPIC', $row['topic_title']);
			}
			else
			{
				add_log('mod', $row['forum_id'], $topic_id, 'LOG_DELETE_TOPIC', $row['topic_title'], $row['topic_first_poster_name']);
			}
		}

		$return = delete_topics('topic_id', $topic_ids);
	}
	else
	{
		confirm_box(false, (sizeof($topic_ids) == 1) ? 'DELETE_TOPIC' : 'DELETE_TOPICS', $s_hidden_fields);
	}

	if (!isset($_REQUEST['quickmod']))
	{
		$redirect = request_var('redirect', "index.$phpEx");
		$redirect = reapply_sid($redirect);
		$redirect_message = 'PAGE';
	}
	else
	{
		$redirect = append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id);
		$redirect_message = 'FORUM';
	}

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);
		trigger_error($user->lang[$success_msg] . '<br /><br />' . sprintf($user->lang['RETURN_' . $redirect_message], '<a href="' . $redirect . '">', '</a>'));
	}
}

/**
* Delete Posts
*/
function mcp_delete_post($post_ids)
{
	global $auth, $user, $db, $phpEx, $phpbb_root_path;

	if (!check_ids($post_ids, POSTS_TABLE, 'post_id', array('m_delete')))
	{
		return;
	}

	$redirect = request_var('redirect', build_url(array('action', 'quickmod')));
	$forum_id = request_var('f', 0);

	$s_hidden_fields = build_hidden_fields(array(
		'post_id_list'	=> $post_ids,
		'f'				=> $forum_id,
		'action'		=> 'delete_post',
		'redirect'		=> $redirect)
	);
	$success_msg = '';

	if (confirm_box(true))
	{
		if (!function_exists('delete_posts'))
		{
			include($phpbb_root_path . 'includes/functions_admin.' . $phpEx);
		}

		// Count the number of topics that are affected
		// I did not use COUNT(DISTINCT ...) because I remember having problems
		// with it on older versions of MySQL -- Ashe

		$sql = 'SELECT DISTINCT topic_id
			FROM ' . POSTS_TABLE . '
			WHERE ' . $db->sql_in_set('post_id', $post_ids);
		$result = $db->sql_query($sql);

		$topic_id_list = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$topic_id_list[] = $row['topic_id'];
		}
		$affected_topics = sizeof($topic_id_list);
		$db->sql_freeresult($result);

		$post_data = get_post_data($post_ids);

		foreach ($post_data as $id => $row)
		{
			$post_username = ($row['poster_id'] == ANONYMOUS && !empty($row['post_username'])) ? $row['post_username'] : $row['username'];
			add_log('mod', $row['forum_id'], $row['topic_id'], 'LOG_DELETE_POST', $row['post_subject'], $post_username);
		}

		// Now delete the posts, topics and forums are automatically resync'ed
		delete_posts('post_id', $post_ids);

		$sql = 'SELECT COUNT(topic_id) AS topics_left
			FROM ' . TOPICS_TABLE . '
			WHERE ' . $db->sql_in_set('topic_id', $topic_id_list);
		$result = $db->sql_query_limit($sql, 1);

		$deleted_topics = ($row = $db->sql_fetchrow($result)) ? ($affected_topics - $row['topics_left']) : $affected_topics;
		$db->sql_freeresult($result);

		$topic_id = request_var('t', 0);

		// Return links
		$return_link = array();
		if ($affected_topics == 1 && !$deleted_topics && $topic_id)
		{
			$return_link[] = sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", "f=$forum_id&amp;t=$topic_id") . '">', '</a>');
		}
		$return_link[] = sprintf($user->lang['RETURN_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id) . '">', '</a>');

		if (sizeof($post_ids) == 1)
		{
			if ($deleted_topics)
			{
				// We deleted the only post of a topic, which in turn has
				// been removed from the database
				$success_msg = $user->lang['TOPIC_DELETED_SUCCESS'];
			}
			else
			{
				$success_msg = $user->lang['POST_DELETED_SUCCESS'];
			}
		}
		else
		{
			if ($deleted_topics)
			{
				// Some of topics disappeared
				$success_msg = $user->lang['POSTS_DELETED_SUCCESS'] . '<br /><br />' . $user->lang['EMPTY_TOPICS_REMOVED_WARNING'];
			}
			else
			{
				$success_msg = $user->lang['POSTS_DELETED_SUCCESS'];
			}
		}
	}
	else
	{
		confirm_box(false, (sizeof($post_ids) == 1) ? 'DELETE_POST' : 'DELETE_POSTS', $s_hidden_fields);
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		if ($affected_topics != 1 || $deleted_topics || !$topic_id)
		{
			$redirect = append_sid("{$phpbb_root_path}mcp.$phpEx", "f=$forum_id&i=main&mode=forum_view", false);
		}

		meta_refresh(3, $redirect);
		trigger_error($success_msg . '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>') . '<br /><br />' . implode('<br /><br />', $return_link));
	}
}

/**
* Fork Topic
*/
function mcp_fork_topic($topic_ids)
{
	global $auth, $user, $db, $template, $config;
	global $phpEx, $phpbb_root_path;

	if (!check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('m_')))
	{
		return;
	}

	$to_forum_id = request_var('to_forum_id', 0);
	$forum_id = request_var('f', 0);
	$redirect = request_var('redirect', build_url(array('action', 'quickmod')));
	$additional_msg = $success_msg = '';

	$s_hidden_fields = build_hidden_fields(array(
		'topic_id_list'	=> $topic_ids,
		'f'				=> $forum_id,
		'action'		=> 'fork',
		'redirect'		=> $redirect)
	);

	if ($to_forum_id)
	{
		$forum_data = get_forum_data($to_forum_id, 'f_post');

		if (!sizeof($topic_ids))
		{
			$additional_msg = $user->lang['NO_TOPIC_SELECTED'];
		}
		else if (!sizeof($forum_data))
		{
			$additional_msg = $user->lang['FORUM_NOT_EXIST'];
		}
		else
		{
			$forum_data = $forum_data[$to_forum_id];

			if ($forum_data['forum_type'] != FORUM_POST)
			{
				$additional_msg = $user->lang['FORUM_NOT_POSTABLE'];
			}
			else if (!$auth->acl_get('f_post', $to_forum_id))
			{
				$additional_msg = $user->lang['USER_CANNOT_POST'];
			}
		}
	}
	else if (isset($_POST['confirm']))
	{
		$additional_msg = $user->lang['FORUM_NOT_EXIST'];
	}

	if ($additional_msg)
	{
		unset($_POST['confirm']);
		unset($_REQUEST['confirm_key']);
	}

	if (confirm_box(true))
	{
		$topic_data = get_topic_data($topic_ids, 'f_post');

		$total_posts = 0;
		$new_topic_id_list = array();

		if ($topic_data['enable_indexing'])
		{
			// Select the search method and do some additional checks to ensure it can actually be utilised
			$search_type = basename($config['search_type']);

			if (!file_exists($phpbb_root_path . 'includes/search/' . $search_type . '.' . $phpEx))
			{
				trigger_error('NO_SUCH_SEARCH_MODULE');
			}

			if (!class_exists($search_type))
			{
				include("{$phpbb_root_path}includes/search/$search_type.$phpEx");
			}

			$error = false;
			$search = new $search_type($error);
			$search_mode = 'post';

			if ($error)
			{
				trigger_error($error);
			}
		}
		else
		{
			$search_type = false;
		}

		foreach ($topic_data as $topic_id => $topic_row)
		{
			$sql_ary = array(
				'forum_id'					=> (int) $to_forum_id,
				'icon_id'					=> (int) $topic_row['icon_id'],
				'topic_attachment'			=> (int) $topic_row['topic_attachment'],
				'topic_approved'			=> 1,
				'topic_reported'			=> 0,
				'topic_title'				=> (string) $topic_row['topic_title'],
				'topic_poster'				=> (int) $topic_row['topic_poster'],
				'topic_time'				=> (int) $topic_row['topic_time'],
				'topic_replies'				=> (int) $topic_row['topic_replies_real'],
				'topic_replies_real'		=> (int) $topic_row['topic_replies_real'],
				'topic_status'				=> (int) $topic_row['topic_status'],
				'topic_type'				=> (int) $topic_row['topic_type'],
				'topic_first_poster_name'	=> (string) $topic_row['topic_first_poster_name'],
				'topic_last_poster_id'		=> (int) $topic_row['topic_last_poster_id'],
				'topic_last_poster_name'	=> (string) $topic_row['topic_last_poster_name'],
				'topic_last_post_time'		=> (int) $topic_row['topic_last_post_time'],
				'topic_last_view_time'		=> (int) $topic_row['topic_last_view_time'],
				'topic_bumped'				=> (int) $topic_row['topic_bumped'],
				'topic_bumper'				=> (int) $topic_row['topic_bumper'],
				'poll_title'				=> (string) $topic_row['poll_title'],
				'poll_start'				=> (int) $topic_row['poll_start'],
				'poll_length'				=> (int) $topic_row['poll_length'],
				'poll_max_options'			=> (int) $topic_row['poll_max_options'],
				'poll_vote_change'			=> (int) $topic_row['poll_vote_change'],
			);

			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
			$new_topic_id = $db->sql_nextid();
			$new_topic_id_list[$topic_id] = $new_topic_id;

			if ($topic_row['poll_start'])
			{
				$poll_rows = array();

				$sql = 'SELECT *
					FROM ' . POLL_OPTIONS_TABLE . "
					WHERE topic_id = $topic_id";
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$sql_ary = array(
						'poll_option_id'	=> (int) $row['poll_option_id'],
						'topic_id'			=> (int) $new_topic_id,
						'poll_option_text'	=> (string) $row['poll_option_text'],
						'poll_option_total'	=> 0
					);

					$db->sql_query('INSERT INTO ' . POLL_OPTIONS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
				}
			}

			$sql = 'SELECT *
				FROM ' . POSTS_TABLE . "
				WHERE topic_id = $topic_id
				ORDER BY post_time ASC";
			$result = $db->sql_query($sql);

			$post_rows = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$post_rows[] = $row;
			}
			$db->sql_freeresult($result);

			if (!sizeof($post_rows))
			{
				continue;
			}

			$total_posts += sizeof($post_rows);
			foreach ($post_rows as $row)
			{
				$sql_ary = array(
					'topic_id'			=> (int) $new_topic_id,
					'forum_id'			=> (int) $to_forum_id,
					'poster_id'			=> (int) $row['poster_id'],
					'icon_id'			=> (int) $row['icon_id'],
					'poster_ip'			=> (string) $row['poster_ip'],
					'post_time'			=> (int) $row['post_time'],
					'post_approved'		=> 1,
					'post_reported'		=> 0,
					'enable_bbcode'		=> (int) $row['enable_bbcode'],
					'enable_smilies'	=> (int) $row['enable_smilies'],
					'enable_magic_url'	=> (int) $row['enable_magic_url'],
					'enable_sig'		=> (int) $row['enable_sig'],
					'post_username'		=> (string) $row['post_username'],
					'post_subject'		=> (string) $row['post_subject'],
					'post_text'			=> (string) $row['post_text'],
					'post_edit_reason'	=> (string) $row['post_edit_reason'],
					'post_edit_user'	=> (int) $row['post_edit_user'],
					'post_checksum'		=> (string) $row['post_checksum'],
					'post_attachment'	=> (int) $row['post_attachment'],
					'bbcode_bitfield'	=> $row['bbcode_bitfield'],
					'bbcode_uid'		=> (string) $row['bbcode_uid'],
					'post_edit_time'	=> (int) $row['post_edit_time'],
					'post_edit_count'	=> (int) $row['post_edit_count'],
					'post_edit_locked'	=> (int) $row['post_edit_locked'],
					'post_postcount'	=> 0,
				);

				$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
				$new_post_id = $db->sql_nextid();

				// Copy whether the topic is dotted
				markread('post', $to_forum_id, $new_topic_id, 0, $row['poster_id']);

				if ($search_type)
				{
					$search->index($search_mode, $sql_ary['post_id'], $sql_ary['post_text'], $sql_ary['post_subject'], $sql_ary['poster_id'], ($topic_row['topic_type'] == POST_GLOBAL) ? 0 : $to_forum_id);
					$search_mode = 'reply'; // After one we index replies
				}

				// Copy Attachments
				if ($row['post_attachment'])
				{
					$sql = 'SELECT * FROM ' . ATTACHMENTS_TABLE . "
						WHERE post_msg_id = {$row['post_id']}
							AND topic_id = $topic_id
							AND in_message = 0";
					$result = $db->sql_query($sql);

					$sql_ary = array();
					while ($attach_row = $db->sql_fetchrow($result))
					{
						$sql_ary[] = array(
							'post_msg_id'		=> (int) $new_post_id,
							'topic_id'			=> (int) $new_topic_id,
							'in_message'		=> 0,
							'is_orphan'			=> (int) $attach_row['is_orphan'],
							'poster_id'			=> (int) $attach_row['poster_id'],
							'physical_filename'	=> (string) utf8_basename($attach_row['physical_filename']),
							'real_filename'		=> (string) utf8_basename($attach_row['real_filename']),
							'download_count'	=> (int) $attach_row['download_count'],
							'attach_comment'	=> (string) $attach_row['attach_comment'],
							'extension'			=> (string) $attach_row['extension'],
							'mimetype'			=> (string) $attach_row['mimetype'],
							'filesize'			=> (int) $attach_row['filesize'],
							'filetime'			=> (int) $attach_row['filetime'],
							'thumbnail'			=> (int) $attach_row['thumbnail']
						);
					}
					$db->sql_freeresult($result);

					if (sizeof($sql_ary))
					{
						$db->sql_multi_insert(ATTACHMENTS_TABLE, $sql_ary);
					}
				}
			}

			$sql = 'SELECT user_id, notify_status
				FROM ' . TOPICS_WATCH_TABLE . '
				WHERE topic_id = ' . $topic_id;
			$result = $db->sql_query($sql);

			$sql_ary = array();
			while ($row = $db->sql_fetchrow($result))
			{
				$sql_ary[] = array(
					'topic_id'		=> (int) $new_topic_id,
					'user_id'		=> (int) $row['user_id'],
					'notify_status'	=> (int) $row['notify_status'],
				);
			}
			$db->sql_freeresult($result);

			if (sizeof($sql_ary))
			{
				$db->sql_multi_insert(TOPICS_WATCH_TABLE, $sql_ary);
			}
		}

		// Sync new topics, parent forums and board stats
		sync('topic', 'topic_id', $new_topic_id_list);

		$sync_sql = array();

		$sync_sql[$to_forum_id][]	= 'forum_posts = forum_posts + ' . $total_posts;
		$sync_sql[$to_forum_id][]	= 'forum_topics = forum_topics + ' . sizeof($new_topic_id_list);
		$sync_sql[$to_forum_id][]	= 'forum_topics_real = forum_topics_real + ' . sizeof($new_topic_id_list);

		foreach ($sync_sql as $forum_id_key => $array)
		{
			$sql = 'UPDATE ' . FORUMS_TABLE . '
				SET ' . implode(', ', $array) . '
				WHERE forum_id = ' . $forum_id_key;
			$db->sql_query($sql);
		}

		sync('forum', 'forum_id', $to_forum_id);
		set_config_count('num_topics', sizeof($new_topic_id_list), true);
		set_config_count('num_posts', $total_posts, true);

		foreach ($new_topic_id_list as $topic_id => $new_topic_id)
		{
			add_log('mod', $to_forum_id, $new_topic_id, 'LOG_FORK', $topic_row['forum_name']);
		}

		$success_msg = (sizeof($topic_ids) == 1) ? 'TOPIC_FORKED_SUCCESS' : 'TOPICS_FORKED_SUCCESS';
	}
	else
	{
		$template->assign_vars(array(
			'S_FORUM_SELECT'		=> make_forum_select($to_forum_id, false, false, true, true, true),
			'S_CAN_LEAVE_SHADOW'	=> false,
			'ADDITIONAL_MSG'		=> $additional_msg)
		);

		confirm_box(false, 'FORK_TOPIC' . ((sizeof($topic_ids) == 1) ? '' : 'S'), $s_hidden_fields, 'mcp_move.html');
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		$redirect_url = append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id);
		meta_refresh(3, $redirect_url);
		$return_link = sprintf($user->lang['RETURN_FORUM'], '<a href="' . $redirect_url . '">', '</a>');

		if ($forum_id != $to_forum_id)
		{
			$return_link .= '<br /><br />' . sprintf($user->lang['RETURN_NEW_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $to_forum_id) . '">', '</a>');
		}

		trigger_error($user->lang[$success_msg] . '<br /><br />' . $return_link);
	}
}

/**
* Closes a report
*/
function close_report($report_id_list, $mode, $action, $pm = false)
{
	global $db, $template, $user, $config, $auth;
	global $phpEx, $phpbb_root_path;

	$pm_where = ($pm) ? ' AND r.post_id = 0 ' : ' AND r.pm_id = 0 ';
	$id_column = ($pm) ? 'pm_id' : 'post_id';
	$module = ($pm) ? 'pm_reports' : 'reports';
	$pm_prefix = ($pm) ? 'PM_' : '';

	$sql = "SELECT r.$id_column
		FROM " . REPORTS_TABLE . ' r
		WHERE ' . $db->sql_in_set('r.report_id', $report_id_list) . $pm_where;
	$result = $db->sql_query($sql);

	$post_id_list = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$post_id_list[] = $row[$id_column];
	}
	$post_id_list = array_unique($post_id_list);

	if ($pm)
	{
		if (!$auth->acl_getf_global('m_report'))
		{
			trigger_error('NOT_AUTHORISED');
		}
	}
	else
	{
		if (!check_ids($post_id_list, POSTS_TABLE, 'post_id', array('m_report')))
		{
			trigger_error('NOT_AUTHORISED');
		}
	}

	if ($action == 'delete' && strpos($user->data['session_page'], 'mode=report_details') !== false)
	{
		$redirect = request_var('redirect', build_url(array('mode', 'r', 'quickmod')) . '&amp;mode=reports');
	}
	elseif ($action == 'delete' && strpos($user->data['session_page'], 'mode=pm_report_details') !== false)
	{
		$redirect = request_var('redirect', build_url(array('mode', 'r', 'quickmod')) . '&amp;mode=pm_reports');
	}
	else if ($action == 'close' && !request_var('r', 0))
	{
		$redirect = request_var('redirect', build_url(array('mode', 'p', 'quickmod')) . '&amp;mode=' . $module);
	}
	else
	{
		$redirect = request_var('redirect', build_url(array('quickmod')));
	}
	$success_msg = '';
	$forum_ids = array();
	$topic_ids = array();

	$s_hidden_fields = build_hidden_fields(array(
		'i'					=> $module,
		'mode'				=> $mode,
		'report_id_list'	=> $report_id_list,
		'action'			=> $action,
		'redirect'			=> $redirect)
	);

	if (confirm_box(true))
	{
		$post_info = ($pm) ? get_pm_data($post_id_list) : get_post_data($post_id_list, 'm_report');

		$sql = "SELECT r.report_id, r.$id_column, r.report_closed, r.user_id, r.user_notify, u.username, u.username_clean, u.user_email, u.user_jabber, u.user_lang, u.user_notify_type
			FROM " . REPORTS_TABLE . ' r, ' . USERS_TABLE . ' u
			WHERE ' . $db->sql_in_set('r.report_id', $report_id_list) . '
				' . (($action == 'close') ? 'AND r.report_closed = 0' : '') . '
				AND r.user_id = u.user_id' . $pm_where;
		$result = $db->sql_query($sql);

		$reports = $close_report_posts = $close_report_topics = $notify_reporters = $report_id_list = array();
		while ($report = $db->sql_fetchrow($result))
		{
			$reports[$report['report_id']] = $report;
			$report_id_list[] = $report['report_id'];

			if (!$report['report_closed'])
			{
				$close_report_posts[] = $report[$id_column];

				if (!$pm)
				{
					$close_report_topics[] = $post_info[$report['post_id']]['topic_id'];
				}
			}

			if ($report['user_notify'] && !$report['report_closed'])
			{
				$notify_reporters[$report['report_id']] = &$reports[$report['report_id']];
			}
		}
		$db->sql_freeresult($result);

		if (sizeof($reports))
		{
			$close_report_posts = array_unique($close_report_posts);
			$close_report_topics = array_unique($close_report_topics);

			if (!$pm && sizeof($close_report_posts))
			{
				// Get a list of topics that still contain reported posts
				$sql = 'SELECT DISTINCT topic_id
					FROM ' . POSTS_TABLE . '
					WHERE ' . $db->sql_in_set('topic_id', $close_report_topics) . '
						AND post_reported = 1
						AND ' . $db->sql_in_set('post_id', $close_report_posts, true);
				$result = $db->sql_query($sql);

				$keep_report_topics = array();
				while ($row = $db->sql_fetchrow($result))
				{
					$keep_report_topics[] = $row['topic_id'];
				}
				$db->sql_freeresult($result);

				$close_report_topics = array_diff($close_report_topics, $keep_report_topics);
				unset($keep_report_topics);
			}

			$db->sql_transaction('begin');

			if ($action == 'close')
			{
				$sql = 'UPDATE ' . REPORTS_TABLE . '
					SET report_closed = 1
					WHERE ' . $db->sql_in_set('report_id', $report_id_list);
			}
			else
			{
				$sql = 'DELETE FROM ' . REPORTS_TABLE . '
					WHERE ' . $db->sql_in_set('report_id', $report_id_list);
			}
			$db->sql_query($sql);


			if (sizeof($close_report_posts))
			{
				if ($pm)
				{
					$sql = 'UPDATE ' . PRIVMSGS_TABLE . '
						SET message_reported = 0
						WHERE ' . $db->sql_in_set('msg_id', $close_report_posts);
					$db->sql_query($sql);

					if ($action == 'delete')
					{
						delete_pm(ANONYMOUS, $close_report_posts, PRIVMSGS_INBOX);
					}
				}
				else
				{
					$sql = 'UPDATE ' . POSTS_TABLE . '
						SET post_reported = 0
						WHERE ' . $db->sql_in_set('post_id', $close_report_posts);
					$db->sql_query($sql);

					if (sizeof($close_report_topics))
					{
						$sql = 'UPDATE ' . TOPICS_TABLE . '
							SET topic_reported = 0
							WHERE ' . $db->sql_in_set('topic_id', $close_report_topics) . '
								OR ' . $db->sql_in_set('topic_moved_id', $close_report_topics);
						$db->sql_query($sql);
					}
				}
			}

			$db->sql_transaction('commit');
		}
		unset($close_report_posts, $close_report_topics);

		foreach ($reports as $report)
		{
			if ($pm)
			{
				add_log('mod', 0, 0, 'LOG_PM_REPORT_' .  strtoupper($action) . 'D', $post_info[$report['pm_id']]['message_subject']);
			}
			else
			{
				add_log('mod', $post_info[$report['post_id']]['forum_id'], $post_info[$report['post_id']]['topic_id'], 'LOG_REPORT_' .  strtoupper($action) . 'D', $post_info[$report['post_id']]['post_subject']);
			}
		}

		$messenger = new messenger();

		// Notify reporters
		if (sizeof($notify_reporters))
		{
			foreach ($notify_reporters as $report_id => $reporter)
			{
				if ($reporter['user_id'] == ANONYMOUS)
				{
					continue;
				}

				$post_id = $reporter[$id_column];

				$messenger->template((($pm) ? 'pm_report_' : 'report_') . $action . 'd', $reporter['user_lang']);

				$messenger->to($reporter['user_email'], $reporter['username']);
				$messenger->im($reporter['user_jabber'], $reporter['username']);

				if ($pm)
				{
					$messenger->assign_vars(array(
						'USERNAME'		=> htmlspecialchars_decode($reporter['username']),
						'CLOSER_NAME'	=> htmlspecialchars_decode($user->data['username']),
						'PM_SUBJECT'	=> htmlspecialchars_decode(censor_text($post_info[$post_id]['message_subject'])),
					));
				}
				else
				{
					$messenger->assign_vars(array(
						'USERNAME'		=> htmlspecialchars_decode($reporter['username']),
						'CLOSER_NAME'	=> htmlspecialchars_decode($user->data['username']),
						'POST_SUBJECT'	=> htmlspecialchars_decode(censor_text($post_info[$post_id]['post_subject'])),
						'TOPIC_TITLE'	=> htmlspecialchars_decode(censor_text($post_info[$post_id]['topic_title'])))
					);
				}

				$messenger->send($reporter['user_notify_type']);
			}
		}

		if (!$pm)
		{
			foreach ($post_info as $post)
			{
				$forum_ids[$post['forum_id']] = $post['forum_id'];
				$topic_ids[$post['topic_id']] = $post['topic_id'];
			}
		}

		unset($notify_reporters, $post_info, $reports);

		$messenger->save_queue();

		$success_msg = (sizeof($report_id_list) == 1) ? "{$pm_prefix}REPORT_" . strtoupper($action) . 'D_SUCCESS' : "{$pm_prefix}REPORTS_" . strtoupper($action) . 'D_SUCCESS';
	}
	else
	{
		confirm_box(false, $user->lang[strtoupper($action) . "_{$pm_prefix}REPORT" . ((sizeof($report_id_list) == 1) ? '' : 'S') . '_CONFIRM'], $s_hidden_fields);
	}

	$redirect = request_var('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);

		$return_forum = '';
		$return_topic = '';

		if (!$pm)
		{
			if (sizeof($forum_ids) === 1)
			{
				$return_forum = sprintf($user->lang['RETURN_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . current($forum_ids)) . '">', '</a>') . '<br /><br />';
			}

			if (sizeof($topic_ids) === 1)
			{
				$return_topic = sprintf($user->lang['RETURN_TOPIC'], '<a href="' . append_sid("{$phpbb_root_path}viewtopic.$phpEx", 't=' . current($topic_ids) . '&amp;f=' . current($forum_ids)) . '">', '</a>') . '<br /><br />';
			}
		}

		trigger_error($user->lang[$success_msg] . '<br /><br />' . $return_forum . $return_topic . sprintf($user->lang['RETURN_PAGE'], "<a href=\"$redirect\">", '</a>'));
	}
}

/**
* Insert the warning into the database
*/
function add_warning($user_row, $warning, $send_pm = true, $post_id = 0)
{
	global $phpEx, $phpbb_root_path, $config;
	global $template, $db, $user, $auth;

	if ($send_pm)
	{
		include_once($phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx);
		include_once($phpbb_root_path . 'includes/message_parser.' . $phpEx);

		$user_row['user_lang'] = (file_exists($phpbb_root_path . 'language/' . $user_row['user_lang'] . "/mcp.$phpEx")) ? $user_row['user_lang'] : $config['default_lang'];
		include($phpbb_root_path . 'language/' . basename($user_row['user_lang']) . "/mcp.$phpEx");

		$message_parser = new parse_message();

		$message_parser->message = sprintf($lang['WARNING_PM_BODY'], $warning);
		$message_parser->parse(true, true, true, false, false, true, true);

		$pm_data = array(
			'from_user_id'			=> $user->data['user_id'],
			'from_user_ip'			=> $user->ip,
			'from_username'			=> $user->data['username'],
			'enable_sig'			=> false,
			'enable_bbcode'			=> true,
			'enable_smilies'		=> true,
			'enable_urls'			=> false,
			'icon_id'				=> 0,
			'bbcode_bitfield'		=> $message_parser->bbcode_bitfield,
			'bbcode_uid'			=> $message_parser->bbcode_uid,
			'message'				=> $message_parser->message,
			'address_list'			=> array('u' => array($user_row['user_id'] => 'to')),
		);

		submit_pm('post', $lang['WARNING_PM_SUBJECT'], $pm_data, false);
	}

	add_log('admin', 'LOG_USER_WARNING', $user_row['username']);
	$log_id = add_log('user', $user_row['user_id'], 'LOG_USER_WARNING_BODY', $warning);

	$sql_ary = array(
		'user_id'		=> $user_row['user_id'],
		'post_id'		=> $post_id,
		'log_id'		=> $log_id,
		'warning_time'	=> time(),
	);

	$db->sql_query('INSERT INTO ' . WARNINGS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));

	$sql = 'UPDATE ' . USERS_TABLE . '
		SET user_warnings = user_warnings + 1,
			user_last_warning = ' . time() . '
		WHERE user_id = ' . $user_row['user_id'];
	$db->sql_query($sql);

	// We add this to the mod log too for moderators to see that a specific user got warned.
	$sql = 'SELECT forum_id, topic_id
		FROM ' . POSTS_TABLE . '
		WHERE post_id = ' . $post_id;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	add_log('mod', $row['forum_id'], $row['topic_id'], 'LOG_USER_WARNING', $user_row['username']);
}

?>