<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "Grant Sim Central access" lifecycle.
 *
 * Mints a single pre-scoped, sysadmin-bound REST API token that the Sim
 * Central service uses to reach this sim's API, and registers it with the
 * broker (which hands it to the operator's n8n store). When that token is
 * later revoked or deleted - from the ACP token list OR over the API - the
 * broker is told, so Sim Central stops trying to use a dead credential.
 *
 * The token is an ordinary row in sim_central_api_tokens (so it shows up in
 * the normal token list and obeys the same auth path); we just remember its
 * id in a dedicated settings row so we can recognise it later. State row:
 *   setting_key = 'sim_central_access'
 *   setting_value = JSON { token_id, token_prefix, scopes, granted_at,
 *                          last_registered_at, broker_status, last_broker_error }
 *
 * Every broker call is best-effort: a broker outage never blocks granting or
 * revoking locally - it just leaves broker_status describing what happened.
 */
class SimCentralAccess
{
	const SETTING_KEY   = 'sim_central_access';
	const SETTING_LABEL = 'Sim Central Suite - access registration (do not edit by hand)';

	/**
	 * Capabilities handed to Sim Central: read everything, manage webhooks and
	 * user activation, and trigger suite upgrades. Deliberately NO post-write
	 * scopes - Sim Central reads posts and manages config, it does not author.
	 */
	public static function scopes()
	{
		return array(
			'posts:read',
			'characters:read',
			'missions:read',
			'webhooks:read',
			'webhooks:write',
			'users:write',
			'tokens:read',
			'suite:update',
		);
	}

	// ---------- status ----------

	/**
	 * Snapshot for the ACP view. 'active' means the bound token row still
	 * exists and hasn't been revoked; 'granted' means we have ever issued one.
	 */
	public static function status()
	{
		$state = self::loadState();
		$tokenId = isset($state['token_id']) ? (int) $state['token_id'] : 0;

		$row = null;
		if ($tokenId > 0) {
			$ci  =& get_instance();
			$row = $ci->db->get_where('sim_central_api_tokens', array('id' => $tokenId))->row();
		}

		$active = $row && empty($row->revoked_at)
			&& (empty($row->expires_at) || strtotime($row->expires_at) >= time());

		return array(
			'granted'            => $tokenId > 0,
			'active'             => (bool) $active,
			'token_id'           => $tokenId,
			'token_prefix'       => isset($state['token_prefix']) ? $state['token_prefix'] : null,
			'scopes'             => isset($state['scopes']) && is_array($state['scopes']) ? $state['scopes'] : array(),
			'granted_at'         => isset($state['granted_at']) ? $state['granted_at'] : null,
			'last_registered_at' => isset($state['last_registered_at']) ? $state['last_registered_at'] : null,
			'broker_status'      => isset($state['broker_status']) ? $state['broker_status'] : null,
			'last_broker_error'  => isset($state['last_broker_error']) ? $state['last_broker_error'] : null,
		);
	}

	// ---------- grant / revoke ----------

	/**
	 * Issue the Sim Central token and register it with the broker.
	 *
	 * @return array array($flash, $rawToken|null)  where $flash is the
	 *               ('success'|'error', message) pair the ACP renders.
	 */
	public static function grant()
	{
		$ci =& get_instance();

		$status = self::status();
		if ($status['active']) {
			return array(array('error', 'Sim Central access is already granted. Revoke it first if you want to re-issue the token.'), null);
		}

		$userId = ($ci->session) ? (int) $ci->session->userdata('userid') : 0;
		if ($userId <= 0) {
			return array(array('error', 'Could not determine the current user to bind the token to.'), null);
		}

		$token = ApiAuth::generateToken();
		$ci->db->insert('sim_central_api_tokens', array(
			'label'        => 'Sim Central access',
			'token_hash'   => $token['hash'],
			'token_prefix' => $token['prefix'],
			'scopes'       => json_encode(self::scopes()),
			'user_id'      => $userId,
			'created_by'   => $userId,
			'created_at'   => date('Y-m-d H:i:s'),
			'expires_at'   => null,
		));

		// insert_id() is reliable for this table (no insert listeners touch it),
		// but fall back to the unique hash lookup just in case.
		$tokenId = (int) $ci->db->insert_id();
		if ($tokenId <= 0) {
			$fresh = $ci->db->get_where('sim_central_api_tokens', array('token_hash' => $token['hash']))->row();
			$tokenId = $fresh ? (int) $fresh->id : 0;
		}

		self::saveState(array(
			'token_id'     => $tokenId,
			'token_prefix' => $token['prefix'],
			'scopes'       => self::scopes(),
			'granted_at'   => date('Y-m-d H:i:s'),
		));

		$res = self::register('granted', $token['raw']);

		$msg = 'Sim Central access granted. Copy the token below now - it is shown only once.';
		if ( ! Broker::isConfigured()) {
			$msg .= ' (No broker URL is set, so it was not sent to Sim Central automatically - set the Broker URL above and re-grant, or hand the token over manually.)';
		} elseif ( ! $res['ok']) {
			$msg .= ' Note: the broker could not be reached ('.$res['error'].'), so Sim Central was not notified automatically. You can re-grant once the broker is reachable.';
		}

		return array(array('success', $msg), $token['raw']);
	}

	/**
	 * Revoke the Sim Central token locally and tell the broker. Used by the
	 * ACP "Revoke access" button.
	 */
	public static function revoke()
	{
		$ci =& get_instance();
		$state = self::loadState();
		$tokenId = isset($state['token_id']) ? (int) $state['token_id'] : 0;
		if ($tokenId <= 0) {
			return array('error', 'Sim Central access has not been granted.');
		}

		$row = $ci->db->get_where('sim_central_api_tokens', array('id' => $tokenId))->row();
		if ($row && empty($row->revoked_at)) {
			$ci->db->where('id', $tokenId)->update('sim_central_api_tokens', array(
				'revoked_at' => date('Y-m-d H:i:s'),
			));
		}

		self::register('revoked');
		return array('success', 'Sim Central access revoked. The broker has been notified.');
	}

	// ---------- token-list hooks ----------

	/** True when the given token id is the one issued for Sim Central access. */
	public static function isSimCentralToken($id)
	{
		$state = self::loadState();
		return isset($state['token_id']) && (int) $state['token_id'] === (int) $id && (int) $id > 0;
	}

	/**
	 * Called when ANY token is revoked (ACP list or API). No-ops unless the
	 * revoked token is the Sim Central one, in which case the broker is told.
	 */
	public static function onTokenRevoked($id)
	{
		if ( ! self::isSimCentralToken($id)) {
			return;
		}
		self::register('revoked');
	}

	/**
	 * Called when ANY token is deleted. No-ops unless it's the Sim Central
	 * token; then it notifies the broker and forgets the binding.
	 */
	public static function onTokenDeleted($id)
	{
		if ( ! self::isSimCentralToken($id)) {
			return;
		}
		self::register('deleted');

		$state = self::loadState();
		unset($state['token_id']);
		$state['token_prefix'] = isset($state['token_prefix']) ? $state['token_prefix'] : null;
		self::saveState($state, true);
	}

	// ---------- identity + broker registration ----------

	/** Identity block describing this sim, shared with the broker and n8n. */
	public static function simIdentity()
	{
		$ci =& get_instance();
		$row = $ci->db->get_where('settings', array('setting_key' => 'sim_name'))->row();
		$name = $row ? (string) $row->setting_value : '';

		return array(
			'name'    => $name,
			'url'     => site_url(),
			'api_url' => site_url('extensions/nova_ext_sim_central/Api'),
			'version' => Config::version(),
		);
	}

	/**
	 * Send a registration event to the broker and fold the outcome back into
	 * the state row (broker_status / last_broker_error / last_registered_at).
	 *
	 * @param string      $event  granted | revoked | deleted
	 * @param string|null $raw    raw token, only on 'granted'
	 */
	private static function register($event, $raw = null)
	{
		$state = self::loadState();

		$payload = array(
			'event'        => $event,
			'sim'          => self::simIdentity(),
			'token_prefix' => isset($state['token_prefix']) ? $state['token_prefix'] : null,
			'scopes'       => isset($state['scopes']) ? $state['scopes'] : self::scopes(),
			'at'           => date('c'),
		);
		if ($event === 'granted' && $raw !== null) {
			$payload['token'] = $raw;
		}

		$res = Broker::post('/register', $payload);

		$state['last_registered_at'] = date('Y-m-d H:i:s');
		$state['broker_status']      = $res['ok'] ? 'ok' : 'error';
		$state['last_broker_error']  = $res['ok'] ? null : $res['error'];
		self::saveState($state, true);

		return $res;
	}

	// ---------- state persistence ----------

	private static function loadState()
	{
		$ci    =& get_instance();
		$query = $ci->db->get_where('settings', array('setting_key' => self::SETTING_KEY), 1);
		if ($query->num_rows() === 0) {
			return array();
		}
		$decoded = json_decode($query->row()->setting_value, true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Persist the state row. When $merge is true the given keys are merged over
	 * the existing row (so a broker-status update doesn't clobber token_id).
	 */
	private static function saveState(array $state, $merge = false)
	{
		$ci =& get_instance();
		if ($merge) {
			$state = array_merge(self::loadState(), $state);
		}
		$json = json_encode($state);
		if ($json === false) {
			return;
		}

		$existing = $ci->db->get_where('settings', array('setting_key' => self::SETTING_KEY), 1);
		if ($existing->num_rows() > 0) {
			$ci->db->where('setting_id', $existing->row()->setting_id)
				->update('settings', array(
					'setting_value' => $json,
					'setting_label' => self::SETTING_LABEL,
				));
		} else {
			$ci->db->insert('settings', array(
				'setting_key'          => self::SETTING_KEY,
				'setting_value'        => $json,
				'setting_label'        => self::SETTING_LABEL,
				'setting_user_created' => 'n',
			));
		}
	}
}
