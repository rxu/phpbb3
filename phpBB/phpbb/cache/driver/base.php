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

namespace phpbb\cache\driver;

abstract class base implements \phpbb\cache\driver\driver_interface
{
	var $vars = array();
	var $is_modified = false;

	var $sql_rowset = array();
	var $sql_row_pointer = array();
	var $cache_dir = '';

	/**
	* {@inheritDoc}
	*/
	function purge()
	{
		// Purge all phpbb cache files
		try
		{
			$iterator = new \DirectoryIterator($this->cache_dir);

			foreach ($iterator as $fileInfo)
			{
				if ($fileInfo->isDot())
				{
					continue;
				}
				$filename = $fileInfo->getFilename();
				if ($fileInfo->isDir())
				{
					$this->remove_dir($fileInfo->getPathname());
				}
				else if (strpos($filename, 'container_') === 0 ||
					strpos($filename, 'autoload_') === 0 ||
					strpos($filename, 'url_matcher') === 0 ||
					strpos($filename, 'url_generator') === 0 ||
					strpos($filename, 'sql_') === 0 ||
					strpos($filename, 'data_') === 0)
				{
					$this->remove_file($fileInfo->getPathname());
				}
			}
		}
		catch (\Exception $e)
		{
			// Do not return, to purge vars cached in memory
		}

		unset($this->vars);
		unset($this->sql_rowset);
		unset($this->sql_row_pointer);

		if (function_exists('opcache_reset'))
		{
			@opcache_reset();
		}

		$this->vars = array();
		$this->sql_rowset = array();
		$this->sql_row_pointer = array();

		$this->is_modified = false;
	}

	/**
	* {@inheritDoc}
	*/
	function unload()
	{
		$this->save();
		unset($this->vars);
		unset($this->sql_rowset);
		unset($this->sql_row_pointer);

		$this->vars = array();
		$this->sql_rowset = array();
		$this->sql_row_pointer = array();
	}

	/**
	* {@inheritDoc}
	*/
	function sql_load($query)
	{
		// Remove extra spaces and tabs
		$query = preg_replace('/[\n\r\s\t]+/', ' ', $query);
		$query_id = md5($query);

		if (($result = $this->_read('sql_' . $query_id)) === false)
		{
			return false;
		}

		$this->sql_rowset[$query_id] = $result;
		$this->sql_row_pointer[$query_id] = 0;

		return $query_id;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_exists($query_id)
	{
		$query_id = $this->clean_query_id($query_id);
		return isset($this->sql_rowset[$query_id]);
	}

	/**
	* {@inheritDoc}
	*/
	function sql_fetchrow($query_id)
	{
		$query_id = $this->clean_query_id($query_id);
		if ($this->sql_row_pointer[$query_id] < count($this->sql_rowset[$query_id]))
		{
			return $this->sql_rowset[$query_id][$this->sql_row_pointer[$query_id]++];
		}

		return false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_fetchfield($query_id, $field)
	{
		$query_id = $this->clean_query_id($query_id);
		if ($this->sql_row_pointer[$query_id] < count($this->sql_rowset[$query_id]))
		{
			return (isset($this->sql_rowset[$query_id][$this->sql_row_pointer[$query_id]][$field])) ? $this->sql_rowset[$query_id][$this->sql_row_pointer[$query_id]++][$field] : false;
		}

		return false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_rowseek($rownum, $query_id)
	{
		$query_id = $this->clean_query_id($query_id);
		if ($rownum >= count($this->sql_rowset[$query_id]))
		{
			return false;
		}

		$this->sql_row_pointer[$query_id] = $rownum;
		return true;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_freeresult($query_id)
	{
		$query_id = $this->clean_query_id($query_id);
		if (!isset($this->sql_rowset[$query_id]))
		{
			return false;
		}

		unset($this->sql_rowset[$query_id]);
		unset($this->sql_row_pointer[$query_id]);

		return true;
	}

	/**
	* Removes/unlinks file
	*
	* @param string $filename Filename to remove
	* @param bool $check Check file permissions
	* @return bool True if the file was successfully removed, otherwise false
	*/
	function remove_file($filename, $check = false)
	{
		global $phpbb_filesystem;

		if ($check && !$phpbb_filesystem->is_writable($this->cache_dir))
		{
			// E_USER_ERROR - not using language entry - intended.
			trigger_error('Unable to remove files within ' . $this->cache_dir . '. Please check directory permissions.', E_USER_ERROR);
		}

		return @unlink($filename);
	}

	/**
	* Remove directory
	*
	* @param string $dir Directory to remove
	*
	* @return void
	*/
	protected function remove_dir($dir)
	{
		try
		{
			$iterator = new \DirectoryIterator($dir);
		}
		catch (\Exception $e)
		{
			return;
		}

		foreach ($iterator as $fileInfo)
		{
			if ($fileInfo->isDot())
			{
				continue;
			}

			if ($fileInfo->isDir())
			{
				$this->remove_dir($fileInfo->getPathname());
			}
			else
			{
				$this->remove_file($fileInfo->getPathname());
			}
		}

		@rmdir($dir);
	}

	/**
	 * Fetch an item from the cache
	 *
	 * @param string $var Cache key
	 *
	 * @return mixed Cached data
	 */
	abstract protected function _read(string $var);

	/**
	 * {@inheritDoc}
	 */
	public function clean_query_id($query_id)
	{
		// Some DBMS functions accept/return objects and/or resources instead of integer identifier
		// Attempting to cast object to int will throw error, hence correctly handle all cases
		if (is_resource($query_id))
		{
			return function_exists('get_resource_id') ? get_resource_id($query_id) : (int) $query_id;
		}
		else
		{
			return is_object($query_id) ? spl_object_id($query_id) : $query_id;
		}
	}
}
