<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * REST API endpoint catalog - single source of truth.
 *
 * Both the admin-side API Explorer page (Manage::api_explorer) and the
 * publicly-served OpenAPI 3.0 spec (Api::openapi) read from here. Keeps
 * the documentation, the interactive UI, and the machine-readable spec
 * in lockstep: add an endpoint once, it shows up everywhere.
 *
 * Suite-feature-conditional response fields (summary, ordered, display_name,
 * etc.) are noted on the relevant schemas with x-suite-feature so the
 * explorer can call them out visually without changing the OpenAPI shape.
 */
class ApiEndpoints
{
	/**
	 * The endpoint catalog. Each entry describes:
	 *   - method            HTTP verb (currently all GET)
	 *   - path              path under the Api base, with {id} placeholders
	 *   - operation_id      OpenAPI operationId (also used as DOM id in explorer)
	 *   - scope             token scope required, or null for "any valid token"
	 *   - summary           one-line description shown in the explorer header
	 *   - description       longer prose for the explorer body + OpenAPI desc
	 *   - parameters        array of param specs (name, in, required, type, default, description)
	 *   - response_schema   logical schema name from schemas() (e.g. 'PostList', 'Post')
	 *   - try_it            true to expose a "Try it" button in the explorer
	 */
	public static function endpoints()
	{
		return array(
			array(
				'method'         => 'GET',
				'path'           => '/ping',
				'operation_id'   => 'ping',
				'scope'          => null,
				'summary'        => 'Sanity check.',
				'description'    => 'Returns ok=true plus the token label and current server time. Use this from external tools (e.g. n8n) to confirm a token is wired up before building the rest of a flow. Requires any valid token; no specific scope needed.',
				'parameters'     => array(),
				'response_schema'=> 'PingResponse',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/posts',
				'operation_id'   => 'listPosts',
				'scope'          => 'posts:read',
				'summary'        => 'List mission posts.',
				'description'    => 'Paginated list of mission posts, newest first. Default status filter is "activated" (public posts only). Use ?status=any to include drafts/saved (requires a token with posts:read scope).',
				'parameters'     => array(
					array('name' => 'mission',  'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => null,        'description' => 'Filter to a single mission id.'),
					array('name' => 'status',   'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'activated', 'description' => 'Post status. "any" returns drafts/saved/activated; "saved", "draft" also valid.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,           'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,          'description' => 'Items per page. Capped at 100.'),
				),
				'response_schema'=> 'PostList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/posts/{id}',
				'operation_id'   => 'getPost',
				'scope'          => 'posts:read',
				'summary'        => 'Get a single post.',
				'description'    => 'Fetches one post by id. Strictly returns activated posts only (404 for drafts/saved) — the single-fetch endpoint is intentionally public-content-only.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'Post',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/characters',
				'operation_id'   => 'listCharacters',
				'scope'          => 'characters:read',
				'summary'        => 'List characters.',
				'description'    => 'Paginated list of characters. Default status filter is "active" (crew_type). ?status=any returns every character (active, inactive, pending, etc.).',
				'parameters'     => array(
					array('name' => 'status',   'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'active', 'description' => 'Filter by crew_type. "any" disables the filter.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,        'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,       'description' => 'Items per page. Capped at 100.'),
				),
				'response_schema'=> 'CharacterList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/characters/{id}',
				'operation_id'   => 'getCharacter',
				'scope'          => 'characters:read',
				'summary'        => 'Get a single character.',
				'description'    => 'Fetches one character by id. Returns any status (unlike posts, where the single endpoint hides non-activated rows).',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Character id (charid).'),
				),
				'response_schema'=> 'Character',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/missions',
				'operation_id'   => 'listMissions',
				'scope'          => 'missions:read',
				'summary'        => 'List missions.',
				'description'    => 'Paginated list of missions, most recent start first.',
				'parameters'     => array(
					array('name' => 'status',   'in' => 'query', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'mission_status filter. Common values: current, upcoming, completed.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,    'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,   'description' => 'Items per page. Capped at 100.'),
				),
				'response_schema'=> 'MissionList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/missions/{id}',
				'operation_id'   => 'getMission',
				'scope'          => 'missions:read',
				'summary'        => 'Get a single mission.',
				'description'    => 'Fetches one mission by id. Returns any status.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Mission id.'),
				),
				'response_schema'=> 'Mission',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/openapi',
				'operation_id'   => 'getOpenApiSpec',
				'scope'          => null,
				'summary'        => 'OpenAPI 3.0 specification for this API.',
				'description'    => 'Returns the OpenAPI 3.0 spec describing every endpoint on this API. No token required - the spec is a public document. Useful for importing into Postman, Insomnia, Stoplight, n8n, or any other OpenAPI-aware tooling.',
				'parameters'     => array(),
				'response_schema'=> 'OpenApiDocument',
				'try_it'         => false, // self-referential, not useful in the explorer
				'no_auth'        => true,
			),
		);
	}

	/**
	 * Component schemas (OpenAPI components/schemas + explorer response display).
	 *
	 * Each entry is a logical name -> shape description. The shape uses
	 * OpenAPI-flavoured types and an `x-suite-feature` annotation on
	 * conditional fields to flag them as "only present when feature X is on."
	 * The explorer shows these in italic; OpenAPI consumers see them as
	 * regular optional fields (the x-* extension is purely informational).
	 */
	public static function schemas()
	{
		return array(
			'PingResponse' => array(
				'type' => 'object',
				'properties' => array(
					'ok'          => array('type' => 'boolean'),
					'token_label' => array('type' => 'string'),
					'now'         => array('type' => 'string', 'format' => 'date-time'),
				),
				'required' => array('ok', 'token_label', 'now'),
			),
			'Post' => array(
				'type' => 'object',
				'properties' => array(
					'id'         => array('type' => 'integer'),
					'title'      => array('type' => 'string'),
					'content'    => array('type' => 'string'),
					'mission_id' => array('type' => 'integer', 'nullable' => true),
					'authors'    => array('type' => 'string',  'nullable' => true, 'description' => 'Comma-separated charid list (Nova native format).'),
					'status'     => array('type' => 'string'),
					'date'       => array('type' => 'string', 'format' => 'date-time'),
					'summary'    => array('type' => 'string', 'nullable' => true, 'x-suite-feature' => 'Mission Post Summary'),
					'ordered'    => array('type' => 'object', 'x-suite-feature' => 'Ordered Mission Posts', 'description' => 'Keys: day (int), time ("HHMM"), date, stardate. Only populated keys included.'),
					'age_gated'  => array('type' => 'boolean', 'x-suite-feature' => 'Content Filter', 'description' => 'API still returns full content; flag lets consumers decide whether to redact.'),
				),
				'required' => array('id', 'title', 'content', 'status'),
			),
			'PostList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Post')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
				),
				'required' => array('data', 'page', 'per_page', 'total'),
			),
			'Character' => array(
				'type' => 'object',
				'properties' => array(
					'id'             => array('type' => 'integer'),
					'first_name'     => array('type' => 'string', 'nullable' => true),
					'last_name'      => array('type' => 'string', 'nullable' => true),
					'suffix'         => array('type' => 'string', 'nullable' => true),
					'status'         => array('type' => 'string', 'description' => 'crew_type: active, inactive, pending, etc.'),
					'rank'           => array('type' => 'integer', 'nullable' => true, 'description' => 'rank_id - look up name separately if needed.'),
					'user_id'        => array('type' => 'integer', 'nullable' => true),
					'display_name'   => array('type' => 'string', 'nullable' => true, 'x-suite-feature' => 'Display Name'),
					'preferred_name' => array('type' => 'string', 'x-suite-feature' => 'Display Name', 'description' => 'display_name if set, else first last suffix.'),
				),
				'required' => array('id', 'status'),
			),
			'CharacterList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Character')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
				),
				'required' => array('data', 'page', 'per_page', 'total'),
			),
			'Mission' => array(
				'type' => 'object',
				'properties' => array(
					'id'              => array('type' => 'integer'),
					'title'           => array('type' => 'string', 'nullable' => true),
					'description'     => array('type' => 'string', 'nullable' => true),
					'status'          => array('type' => 'string', 'description' => 'current / upcoming / completed.'),
					'start'           => array('type' => 'string', 'format' => 'date-time', 'nullable' => true),
					'end'             => array('type' => 'string', 'format' => 'date-time', 'nullable' => true),
					'summary_enabled' => array('type' => 'boolean', 'x-suite-feature' => 'Mission Post Summary'),
					'ordered'         => array('type' => 'object', 'x-suite-feature' => 'Ordered Mission Posts', 'description' => 'Keys: config, numbering (bool), default_date, default_stardate, legacy_mode (bool).'),
				),
				'required' => array('id', 'status'),
			),
			'MissionList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Mission')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
				),
				'required' => array('data', 'page', 'per_page', 'total'),
			),
			'ErrorResponse' => array(
				'type' => 'object',
				'properties' => array(
					'error' => array('type' => 'string'),
				),
				'required' => array('error'),
			),
			// Self-referential placeholder for /openapi - explorer doesn't render it,
			// and OpenAPI consumers know the shape from the spec they just fetched.
			'OpenApiDocument' => array(
				'type' => 'object',
				'description' => 'An OpenAPI 3.0 document. See https://spec.openapis.org/oas/v3.0.3 for the shape.',
			),
		);
	}

	/**
	 * Convert the catalog + schemas to an OpenAPI 3.0 document.
	 *
	 * @param string $baseUrl Fully-qualified API base, e.g.
	 *                        https://yoursim.example/extensions/nova_ext_sim_central/Api
	 */
	public static function toOpenApi($baseUrl)
	{
		$paths = array();
		foreach (self::endpoints() as $ep) {
			$pathKey = $ep['path'];
			if ( ! isset($paths[$pathKey])) {
				$paths[$pathKey] = array();
			}

			$op = array(
				'operationId' => $ep['operation_id'],
				'summary'     => $ep['summary'],
				'description' => $ep['description'],
				'responses'   => array(
					'200' => array(
						'description' => 'Success',
						'content' => array(
							'application/json' => array(
								'schema' => array('$ref' => '#/components/schemas/'.$ep['response_schema']),
							),
						),
					),
				),
			);

			if ( ! empty($ep['scope'])) {
				$op['security'] = array(array('apiKey' => array($ep['scope'])));
				$op['responses']['403'] = array(
					'description' => 'Token lacks the required scope.',
					'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
				);
			} elseif (empty($ep['no_auth'])) {
				$op['security'] = array(array('apiKey' => array()));
			}

			if (empty($ep['no_auth'])) {
				$op['responses']['401'] = array(
					'description' => 'Missing, malformed, unknown, revoked, or expired token.',
					'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
				);
				$op['responses']['429'] = array(
					'description' => 'Per-token rate limit exceeded.',
					'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
				);
				$op['responses']['503'] = array(
					'description' => 'API enabled but the tokens table is missing - admin must run Setup database.',
					'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
				);
			}

			$op['responses']['404'] = array(
				'description' => 'Resource not found, or the REST API feature is disabled on this sim.',
				'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
			);

			if ( ! empty($ep['parameters'])) {
				$op['parameters'] = array();
				foreach ($ep['parameters'] as $p) {
					$schema = array('type' => $p['type']);
					if (array_key_exists('default', $p) && $p['default'] !== null) {
						$schema['default'] = $p['default'];
					}
					$op['parameters'][] = array(
						'name'        => $p['name'],
						'in'          => $p['in'],
						'required'    => ! empty($p['required']),
						'description' => $p['description'],
						'schema'      => $schema,
					);
				}
			}

			$paths[$pathKey][strtolower($ep['method'])] = $op;
		}

		return array(
			'openapi' => '3.0.3',
			'info' => array(
				'title'       => 'Sim Central Suite REST API',
				'description' => 'Read-only HTTP API for external integrations (n8n flows, scripts, dashboards). Authenticate with the X-API-Key header.',
				'version'     => Config::version(),
			),
			'servers' => array(
				array('url' => $baseUrl, 'description' => 'This sim'),
			),
			'components' => array(
				'securitySchemes' => array(
					'apiKey' => array(
						'type' => 'apiKey',
						'in'   => 'header',
						'name' => 'X-API-Key',
						'description' => 'Admin-issued bearer-style token. Tokens look like "scapi_" + 40 hex chars.',
					),
				),
				'schemas' => self::schemas(),
			),
			'paths' => $paths,
		);
	}
}
