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

require_once __DIR__ . '/../mock/lang.php';

class phpbb_datetime_from_format_test extends phpbb_test_case
{
	/** @var \phpbb\language\language */
	protected $lang;

	/** @var \phpbb\user */
	protected $user;

	protected function setUp(): void
	{
		global $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->lang = new \phpbb\language\language($lang_loader);

		// Set up language data for testing
		$reflection_class = new ReflectionClass('\phpbb\language\language');
		// Set default language files loaded flag to true
		$common_language_files_loaded_flag = $reflection_class->getProperty('common_language_files_loaded');
		$common_language_files_loaded_flag->setAccessible(true);
		$common_language_files_loaded_flag->setValue($this->lang, true);
		// Set up test language data
		$lang_array = $reflection_class->getProperty('lang');
		$lang_array->setAccessible(true);
		$lang_array->setValue($this->lang, [
			'datetime' => [
				'TODAY'		=> 'Today',
				'TOMORROW'	=> 'Tomorrow',
				'YESTERDAY'	=> 'Yesterday',
				'AGO'		=> [
					0		=> 'less than a minute ago',
					1		=> '%d minute ago',
					2		=> '%d minutes ago',
				],		
			],
		]);

		$this->user = new \phpbb\user($this->lang, '\phpbb\datetime');
	}

	public static function from_format_data()
	{
		return [
			[
				'UTC',
				'Y-m-d',
				'2012-06-08',
			],

			[
				'Europe/Berlin',
				'Y-m-d H:i:s',
				'2012-06-08 14:01:02',
			],
		];
	}

	/**
	* @dataProvider from_format_data()
	*/
	public function test_from_format($timezone, $format, $expected)
	{
		$this->user->timezone = new DateTimeZone($timezone);
		$timestamp = $this->user->get_timestamp_from_format($format, $expected, new DateTimeZone($timezone));
		$this->assertEquals($expected, $this->user->format_date($timestamp, $format, true));
	}

	public static function relative_format_date_data()
	{
		// If the current time is too close to the testing time,
		// the relative time will use "x minutes ago" instead of "today ..."
		// So we use 18:01 in the morning and 06:01 in the afternoon.
		$testing_time = gmdate('H') <= 12 ? '18:01' : '06:01';

		return [
			[
				gmdate('Y-m-d', time() + 2 * 86400) . ' ' . $testing_time, false,
				gmdate('Y-m-d', time() + 2 * 86400) . ' ' . $testing_time,
			],
			[
				gmdate('Y-m-d', time() + 86400) . ' ' . $testing_time, false,
				'Tomorrow ' . $testing_time,
			],
			[
				gmdate('Y-m-d', time() + 86400) . ' ' . $testing_time, true,
				gmdate('Y-m-d', time() + 86400) . ' ' . $testing_time,
			],
			[
				gmdate('Y-m-d') . ' ' . $testing_time, false,
				'Today ' . $testing_time,
			],
			[
				gmdate('Y-m-d') . ' ' . $testing_time, true,
				gmdate('Y-m-d') . ' ' . $testing_time,
			],
			[
				gmdate('Y-m-d', time() - 86400) . ' ' . $testing_time, false,
				'Yesterday ' . $testing_time,
			],
			[
				gmdate('Y-m-d', time() - 86400) . ' ' . $testing_time, true,
				gmdate('Y-m-d', time() - 86400) . ' ' . $testing_time,
			],
			[
				gmdate('Y-m-d', time() - 2 * 86400) . ' ' . $testing_time, false,
				gmdate('Y-m-d', time() - 2 * 86400) . ' ' . $testing_time,
			],

			// Test edge cases: Yesterday 00:00, Today 00:00, Tomorrow 00:00
			[
				gmdate('Y-m-d', strtotime('yesterday')) . ' 00:00', false,
				'Yesterday 00:00',
			],
			[
				gmdate('Y-m-d', strtotime('today')) . ' 00:00', false,
				'Today 00:00',
			],
			[
				gmdate('Y-m-d', strtotime('tomorrow')) . ' 00:00', false,
				'Tomorrow 00:00',
			],
		];
	}

	/**
	 * @dataProvider relative_format_date_data()
	 */
	public function test_relative_format_date($timestamp, $forcedate, $expected)
	{
		$this->user->timezone = new DateTimeZone('UTC');
		$timestamp = $this->user->get_timestamp_from_format('Y-m-d H:i', $timestamp, new DateTimeZone('UTC'));

		/* This code is equal to the one from \phpbb\datetime function format()
		 * If the delta is less than or equal to 1 hour
		 * and the delta not more than a minute in the past
		 * and the delta is either greater than -5 seconds
		 * or timestamp and current time are of the same minute
		 * format_date() will return relative date format using "... ago" options
		 */
		$now_ts = strtotime('now');
		$delta = $now_ts - $timestamp;
		if ($delta <= 3600 && $delta > -60 &&
			($delta >= -5 || (($now_ts/ 60) % 60) == (($timestamp / 60) % 60))
		)
		{
			$expected = $this->lang->lang(['datetime', 'AGO'], max(0, (int) floor($delta / 60)));
		}

		$this->assertEquals($expected, $this->user->format_date($timestamp, '|Y-m-d| H:i', $forcedate));
	}
}
