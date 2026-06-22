<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Thin client for the Sim Central broker - a small Cloudflare Worker that
 * receives registration + status pings from this suite and forwards them to
 * the operator's n8n webhooks (with the header auth n8n expects). The shared
 * secret lives only in the suite's settings and the Worker's secret store;
 * n8n's own auth header never touches this codebase.
 *
 * This library knows nothing about WHAT is being sent - SimCentralAccess and
 * PhoneHome compose payloads and call post(). All calls are best-effort: the
 * broker being down must never break a page render, so every failure is
 * returned as a structured result rather than thrown.
 *
 * Wire:
 *   $res = \nova_ext_sim_central\Broker::post('/register', array(...));
 *   // $res = array('ok' => bool, 'code' => int, 'error' => string|null, 'body' => mixed)
 *
 * Config (suite settings, see Config::load()):
 *   sim_central_broker_url     - base URL of the Worker, e.g. https://registry.simcentral.host
 *   sim_central_broker_secret  - shared secret sent as the X-SC-Secret header
 */
class Broker
{
	const SECRET_HEADER = 'X-SC-Secret';

	/** Base URL of the broker Worker, trailing slash stripped, or '' when unset. */
	public static function url()
	{
		$c = Config::load();
		$v = isset($c['setting']['sim_central_broker_url'])
			? trim((string) $c['setting']['sim_central_broker_url'])
			: '';
		return $v !== '' ? rtrim($v, '/') : '';
	}

	/** Shared secret sent to the broker, or '' when unset. */
	public static function secret()
	{
		$c = Config::load();
		return isset($c['setting']['sim_central_broker_secret'])
			? (string) $c['setting']['sim_central_broker_secret']
			: '';
	}

	/** True when a usable broker URL is configured. */
	public static function isConfigured()
	{
		$url = self::url();
		return $url !== '' && strpos($url, 'http') === 0;
	}

	/**
	 * POST a JSON payload to a broker path (e.g. '/register'). Returns a
	 * structured result; never throws. A missing URL/cURL is reported as
	 * ok=false with a descriptive error rather than a fatal.
	 *
	 * @param string $path     Leading-slash path on the broker.
	 * @param array  $payload  JSON-encodable body.
	 * @return array { ok: bool, code: int, error: string|null, body: mixed }
	 */
	public static function post($path, array $payload)
	{
		if ( ! function_exists('curl_init')) {
			return self::fail(0, 'PHP cURL is not available.');
		}
		if ( ! self::isConfigured()) {
			return self::fail(0, 'No broker URL configured.');
		}

		$json = json_encode($payload);
		if ($json === false) {
			return self::fail(0, 'Could not encode payload.');
		}

		$url = self::url().'/'.ltrim((string) $path, '/');

		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json',
		);
		$secret = self::secret();
		if ($secret !== '') {
			$headers[] = self::SECRET_HEADER.': '.$secret;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_HTTPHEADER     => $headers,
			// Short, so a slow/dead broker never stalls the request that
			// triggered the ping (admin dashboard, update check, etc.).
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 4,
			CURLOPT_USERAGENT      => 'sim-central-suite/'.Config::version(),
		));
		$body = curl_exec($ch);
		$errn = curl_errno($ch);
		$err  = curl_error($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($errn !== 0 || $body === false) {
			return self::fail($code, $err !== '' ? $err : 'Request failed.');
		}
		if ($code < 200 || $code >= 300) {
			return self::fail($code, 'Broker returned HTTP '.$code.'.');
		}

		$decoded = json_decode((string) $body, true);
		return array(
			'ok'    => true,
			'code'  => $code,
			'error' => null,
			'body'  => is_array($decoded) ? $decoded : $body,
		);
	}

	private static function fail($code, $error)
	{
		return array('ok' => false, 'code' => (int) $code, 'error' => (string) $error, 'body' => null);
	}
}
