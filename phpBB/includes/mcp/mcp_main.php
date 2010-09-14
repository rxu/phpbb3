<?php
/**
*
* @package mcp
* @version $Id$
* @copyright (c) 2005 phpBB Group
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

/**
* mcp_main
* Handling mcp actions
* @package mcp
*/
class mcp_main
{
	var $p_master;
	var $u_action;

	function mcp_main(&$p_master)
	{
		$this->p_master = &$p_master;
	}

	function main($id, $mode)
	{
		global $auth, $db, $user, $template, $action;
		global $config, $phpbb_root_path, $phpEx;

		include($phpbb_root_path . 'includes/functions_mcp.' . $phpEx);

		$quickmod = ($mode == 'quickmod') ? true : false;

		switch ($action)
		{
			case 'lock':
			case 'unlock':
				$topic_ids = (!$quickmod) ? request_var('topic_id_list', array(0)) : array(request_var('t', 0));

				if (!sizeof($topic_ids))
				{
					trigger_error('NO_TOPIC_SELECTED');
				}

				lock_unlock($action, $topic_ids);
			break;

			case 'lock_post':
			case 'unlock_post':
				$post_ids = (!$quickmod) ? request_var('post_id_list', array(0)) : array(request_var('p', 0));

				if (!sizeof($post_ids))
				{
					trigger_error('NO_POST_SELECTED');
				}

				lock_unlock($action, $post_ids);
			break;

			case 'make_announce':
			case 'make_sticky':
			case 'make_global':
			case 'make_normal':
				$topic_ids = (!$quickmod) ? request_var('topic_id_list', array(0)) : array(request_var('t', 0));

				if (!sizeof($topic_ids))
				{
					trigger_error('NO_TOPIC_SELECTED');
				}

				change_topic_type($action, $topic_ids);
			break;

			case 'move':
				$user->add_lang('viewtopic');

				$topic_ids = (!$quickmod) ? request_var('topic_id_list', array(0)) : array(request_var('t', 0));

				if (!sizeof($topic_ids))
				{
					trigger_error('NO_TOPIC_SELECTED');
				}

				mcp_move_topic($topic_ids);
			break;

			case 'fork':
				$user->add_lang('viewtopic');

				$topic_ids = (!$quickmod) ? request_var('topic_id_list', array(0)) : array(request_var('t', 0));

				if (!sizeof($topic_ids))
				{
					trigger_error('NO_TOPIC_SELECTED');
				}

				mcp_fork_topic($topic_ids);
			break;

			case 'delete_topic':
				$user->add_lang('viewtopic');

				$topic_ids = (!$quickmod) ? request_var('topic_id_list', array(0)) : array(request_var('t', 0));

				if (!sizeof($topic_ids))
				{
					trigger_error('NO_TOPIC_SELECTED');
				}

				mcp_delete_topic($topic_ids);
			break;

			case 'delete_post':
				$user->add_lang('posting');

				$post_ids = (!$quickmod) ? request_var('post_id_list', array(0)) : array(request_var('p', 0));

				if (!sizeof($post_ids))
				{
					trigger_error('NO_POST_SELECTED');
				}

				mcp_delete_post($post_ids);
			break;
		}

		switch ($mode)
		{
			case 'front':
				$user->add_lang('acp/common');

				mcp_front_view($id, $mode, $action);

				$this->tpl_name = 'mcp_front';
				$this->page_title = 'MCP_MAIN';
			break;

			case 'forum_view':
				$user->add_lang('viewforum');

				$forum_id = request_var('f', 0);

				$forum_info = get_forum_data($forum_id, 'm_', true);

				if (!sizeof($forum_info))
				{
					$this->main('main', 'front');
					return;
				}

				$forum_info = $forum_info[$forum_id];

				mcp_forum_view($id, $mode, $action, $forum_info);

				$this->tpl_name = 'mcp_forum';
				$this->page_title = 'MCP_MAIN_FORUM_VIEW';
			break;

			case 'topic_view':
				mcp_topic_view($id, $mode, $action);

				$this->tpl_name = 'mcp_topic';
				$this->page_title = 'MCP_MAIN_TOPIC_VIEW';
			break;

			case 'post_details':
				mcp_post_details($id, $mode, $action);

				$this->tpl_name = ($action == 'whois') ? 'mcp_whois' : 'mcp_post';
				$this->page_title = 'MCP_MAIN_POST_DETAILS';
			break;

			default:
				trigger_error('NO_MODE', E_USER_ERROR);
			break;
		}
	}
}

?>