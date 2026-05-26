<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Display-time formatting for the Ordered Mission Posts timeline string.
 *
 * The DB stores raw values:
 *   - dates as ISO YYYY-MM-DD (nova_ext_ordered_post_date)
 *   - times as HHmm without a separator (nova_ext_ordered_post_time)
 *
 * Storage is intentionally unchanged across the format setting; only
 * the rendered output on the public mission/post views, the RSS feed,
 * and post emails goes through here.
 *
 * Stardate and day values are numbers, not dates, so they pass through
 * unformatted; only the time portion of those modes is reformatted.
 *
 * Defaults: date YYYY-MM-DD (zero migration impact), time 24h with
 * colon (e.g. 23:00) - the previous "2300" raw display was not one of
 * the documented format choices.
 */
class TimelineFormat
{
	const DATE_DEFAULT = 'YYYY-MM-DD';
	const TIME_DEFAULT = '24h';

	public static function dateFormatChoices()
	{
		return array(
			'YYYY-MM-DD' => 'YYYY-MM-DD (e.g. 2399-04-20)',
			'YYYY/MM/DD' => 'YYYY/MM/DD (e.g. 2399/04/20)',
			'MM/DD/YYYY' => 'MM/DD/YYYY (e.g. 04/20/2399)',
			'DD/MM/YYYY' => 'DD/MM/YYYY (e.g. 20/04/2399)',
		);
	}

	public static function timeFormatChoices()
	{
		return array(
			'24h' => '24-hour (e.g. 23:00)',
			'12h' => '12-hour AM/PM (e.g. 11:00 PM)',
		);
	}

	public static function currentDateFormat()
	{
		$c = Config::load();
		$f = isset($c['setting']['date_format']) ? $c['setting']['date_format'] : self::DATE_DEFAULT;
		return array_key_exists($f, self::dateFormatChoices()) ? $f : self::DATE_DEFAULT;
	}

	public static function currentTimeFormat()
	{
		$c = Config::load();
		$f = isset($c['setting']['time_format']) ? $c['setting']['time_format'] : self::TIME_DEFAULT;
		return array_key_exists($f, self::timeFormatChoices()) ? $f : self::TIME_DEFAULT;
	}

	/**
	 * Convert a stored ISO date (YYYY-MM-DD) to one of the supported
	 * display formats. Unparseable input passes through unchanged so
	 * legacy or hand-entered values aren't silently destroyed.
	 */
	public static function formatDate($yyyymmdd, $format = null)
	{
		if ($format === null) {
			$format = self::currentDateFormat();
		}
		if ( ! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', (string) $yyyymmdd, $m)) {
			return (string) $yyyymmdd;
		}
		$y  = $m[1];
		$mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
		$d  = str_pad($m[3], 2, '0', STR_PAD_LEFT);

		switch ($format) {
			case 'YYYY/MM/DD': return $y.'/'.$mo.'/'.$d;
			case 'MM/DD/YYYY': return $mo.'/'.$d.'/'.$y;
			case 'DD/MM/YYYY': return $d.'/'.$mo.'/'.$y;
			case 'YYYY-MM-DD':
			default:           return $y.'-'.$mo.'-'.$d;
		}
	}

	/**
	 * Convert a stored time (HHmm, or anything we can pull 1-4 digits
	 * out of) to either "HH:mm" or "h:mm AM/PM". Empty / unparseable
	 * input passes through unchanged.
	 */
	public static function formatTime($hhmm, $format = null)
	{
		if ($format === null) {
			$format = self::currentTimeFormat();
		}
		$digits = preg_replace('/[^0-9]/', '', (string) $hhmm);
		if ($digits === '') {
			return (string) $hhmm;
		}
		$digits = str_pad($digits, 4, '0', STR_PAD_LEFT);
		$hh = (int) substr($digits, 0, 2);
		$mm = substr($digits, 2, 2);

		if ($format === '12h') {
			$period = ($hh >= 12) ? 'PM' : 'AM';
			$h12    = $hh % 12;
			if ($h12 === 0) $h12 = 12;
			return $h12.':'.$mm.' '.$period;
		}
		return str_pad((string) $hh, 2, '0', STR_PAD_LEFT).':'.$mm;
	}

	/**
	 * Single-line builder used by every display point so timeline
	 * assembly only lives in one place. $dayColumnName is the DB column
	 * the day-portion value came from; we only date-format it if it's
	 * the post-date column (other modes carry an integer or decimal).
	 *
	 *   "Mission Day 2 at 23:00 Hours"
	 *   "Date 04/20/2399 at 11:00 PM"
	 *   "Stardate 12345.67 at 23:00"
	 */
	public static function buildLine($prefix, $dayColumnName, $dayValue, $timeValue, $concat, $suffix)
	{
		$day = ($dayColumnName === 'nova_ext_ordered_post_date')
			? self::formatDate($dayValue)
			: $dayValue;
		$time = self::formatTime($timeValue);
		return $prefix.' '.$day.' '.$concat.' '.$time.' '.$suffix;
	}
}
