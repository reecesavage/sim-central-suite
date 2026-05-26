<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Tiny RS256-only JWT verifier. The whole point of NOT pulling in
 * firebase/php-jwt or any Composer dep is that the Sim Central Suite
 * has no autoloader contract with the host site - everything lives in
 * libraries/ and is require_once'd as needed.
 *
 * Used by DiscordAuth to verify tokens minted by the sim-central-broker
 * Cloudflare Worker. Verification only - we never sign anything here.
 *
 * RS256 is hardcoded; if a future broker switches to a different alg
 * we'd add a branch here, but for now anything other than RS256 in the
 * header is rejected.
 */
class Jwt
{
	/**
	 * Verify $jwt against the PEM-encoded RSA public key, returning the
	 * decoded claims array on success or NULL on any failure (bad shape,
	 * wrong algorithm, broken base64, signature mismatch, missing
	 * required claims).
	 *
	 * exp / nbf claim checking is the caller's responsibility - this
	 * function only proves authenticity, not freshness.
	 */
	public static function decode($jwt, $publicKeyPem)
	{
		if ( ! is_string($jwt) || ! is_string($publicKeyPem)) {
			return null;
		}

		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return null;
		}

		list($headerB64, $payloadB64, $sigB64) = $parts;

		$header  = self::decodeJsonPart($headerB64);
		$payload = self::decodeJsonPart($payloadB64);
		if ( ! is_array($header) || ! is_array($payload)) {
			return null;
		}

		if ( ! isset($header['alg']) || $header['alg'] !== 'RS256') {
			return null;
		}

		$signature = self::base64UrlDecode($sigB64);
		if ($signature === false || $signature === '') {
			return null;
		}

		$key = @openssl_pkey_get_public($publicKeyPem);
		if ($key === false) {
			return null;
		}

		$signingInput = $headerB64.'.'.$payloadB64;
		$ok = @openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

		// PHP 8+ no longer requires openssl_free_key but it's a no-op anyway.

		if ($ok !== 1) {
			return null;
		}

		return $payload;
	}

	private static function decodeJsonPart($part)
	{
		$raw = self::base64UrlDecode($part);
		if ($raw === false) {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	private static function base64UrlDecode($input)
	{
		$input = strtr((string) $input, '-_', '+/');
		$pad   = strlen($input) % 4;
		if ($pad > 0) {
			$input .= str_repeat('=', 4 - $pad);
		}
		$out = base64_decode($input, true);
		return $out === false ? false : $out;
	}
}
