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

namespace phpbb\db\migration\data\v310;

class rename_too_long_indexes2 extends \phpbb\db\migration\migration
{
	static public function depends_on()
	{
		return array('\phpbb\db\migration\data\v30x\release_3_0_0');
	}

	public function update_schema()
	{
		return array(
			'drop_keys' => array(
				$this->table_prefix . 'styles_imageset' => array(
					'imgset_nm',
				),
				$this->table_prefix . 'styles_template' => array(
					'tmplte_nm',
				),
			),
			'add_unique_index' => array(
				$this->table_prefix . 'styles_imageset' => array(
					'imgst_nm'	=> array('imageset_name'),
				),
				$this->table_prefix . 'styles_template' => array(
					'tmplt_nm'	=> array('template_name'),
				),
			),
		);
	}
}
