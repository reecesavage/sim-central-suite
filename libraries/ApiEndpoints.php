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
				'path'           => '/me',
				'operation_id'   => 'getMe',
				'scope'          => null,
				'summary'        => 'Identity of the bound user.',
				'description'    => 'Returns the Nova user this token is bound to, their characters (split into PC and NPC, ordered by rank), and the token\'s scopes. Use this to drive a "posting as..." picker. Requires a user-bound token (409 if the token has no user assigned).',
				'parameters'     => array(),
				'response_schema'=> 'Me',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/posts',
				'operation_id'   => 'listPosts',
				'scope'          => 'posts:read',
				'summary'        => 'List mission posts.',
				'description'    => 'Paginated list of mission posts, newest first. With posts:read (public) only activated posts show by default (?status=any still permitted as before). A user-bound token with posts:read.own lists that user\'s own posts including drafts; posts:read.all (sysadmin) lists any post including others\' drafts.',
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
				'description'    => 'Fetches one post by id. With posts:read (public) only activated posts are returned (404 otherwise). A user-bound posts:read.own token may also fetch its own drafts; posts:read.all (sysadmin) may fetch any post.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'Post',
				'try_it'         => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/posts',
				'operation_id'   => 'createPost',
				'scope'          => 'posts:write',
				'summary'        => 'Create a mission post.',
				'description'    => 'Creates a post authored by the token\'s bound user. At least one author must be one of the user\'s own characters (unless posts:write.all + sysadmin). status "saved" (default) keeps it a draft; "activated" publishes it — which fires the post.posted webhook, stamps last_post, sends the crew email, and honours per-user moderation (may land as pending). The saving character (post_saved / webhook actor) is derived: the user\'s main character if on the post, else their highest-ranked character on it. Ordered Mission Posts timeline fields must match the mission\'s scheme (day_time->ordered_day, date_time->ordered_date, stardate->ordered_stardate; ordered_time always applies) — read ordered.config from GET /missions/{id} first, or you get a 422. Returns 201 with the created post.',
				'parameters'     => array(
					array('name' => 'title',      'in' => 'body', 'required' => true,  'type' => 'string',  'default' => null, 'description' => 'Post title.'),
					array('name' => 'authors',    'in' => 'body', 'required' => true,  'type' => 'array',   'default' => null, 'description' => 'Character ids authoring the post (array or CSV).'),
					array('name' => 'mission_id', 'in' => 'body', 'required' => true,  'type' => 'integer', 'default' => null, 'description' => 'Mission this post belongs to.'),
					array('name' => 'body',       'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Post content.'),
					array('name' => 'status',     'in' => 'body', 'required' => false, 'type' => 'string',  'default' => 'saved', 'description' => '"saved" (draft) or "activated" (publish).'),
					array('name' => 'location',   'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'In-character location.'),
					array('name' => 'timeline',   'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Free-text timeline (when Ordered Mission Posts is off).'),
					array('name' => 'tags',       'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'Tags (array or CSV).'),
					array('name' => 'ordered_day',      'in' => 'body', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Ordered Mission Posts: mission day.'),
					array('name' => 'ordered_time',     'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Ordered Mission Posts: time (HH:MM or HHMM).'),
					array('name' => 'ordered_date',     'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Ordered Mission Posts: date.'),
					array('name' => 'ordered_stardate', 'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Ordered Mission Posts: stardate.'),
					array('name' => 'age_gated',  'in' => 'body', 'required' => false, 'type' => 'boolean', 'default' => null, 'description' => 'Content Filter: gate this post behind the age notice.'),
				),
				'response_schema'=> 'Post',
				'success_code'   => 201,
				'try_it'         => false,
			),
			array(
				'method'         => 'PATCH',
				'path'           => '/posts/{id}',
				'operation_id'   => 'updatePost',
				'scope'          => 'posts:write',
				'summary'        => 'Update a mission post (partial).',
				'description'    => 'Updates a post the bound user authors (or any post with posts:write.all + sysadmin). Only supplied fields change. Use body_mode=append to append to the existing body instead of replacing it. Changing status to "activated" on a draft publishes it (fires post.posted, stamps last_post, sends the crew email, honours moderation). PUT is accepted as an alias. Respects edit locks: returns 423 if another user holds a fresh lock (see the /posts/{id}/lock endpoints), and auto-releases your own lock on a successful save.',
				'parameters'     => array(
					array('name' => 'id',         'in' => 'path', 'required' => true,  'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
					array('name' => 'title',      'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'New title.'),
					array('name' => 'body',       'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'New body content.'),
					array('name' => 'body_mode',  'in' => 'body', 'required' => false, 'type' => 'string',  'default' => 'replace', 'description' => '"replace" (default) or "append".'),
					array('name' => 'authors',    'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'Replace the author list (array or CSV of character ids).'),
					array('name' => 'mission_id', 'in' => 'body', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Move to a different mission.'),
					array('name' => 'status',     'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => '"saved" or "activated".'),
					array('name' => 'location',   'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'In-character location.'),
					array('name' => 'timeline',   'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Free-text timeline.'),
					array('name' => 'tags',       'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'Replace tags (array or CSV).'),
				),
				'response_schema'=> 'Post',
				'try_it'         => false,
			),
			array(
				'method'         => 'DELETE',
				'path'           => '/posts/{id}',
				'operation_id'   => 'deletePost',
				'scope'          => 'posts:delete',
				'summary'        => 'Delete a mission post.',
				'description'    => 'Permanently deletes a post the bound user authors (or any post with posts:delete.all + sysadmin).',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'PostDeleteResult',
				'try_it'         => false,
			),
			// ---- post edit locking (collaborative locks) ----
			array(
				'method'         => 'GET',
				'path'           => '/posts/{id}/lock',
				'operation_id'   => 'getPostLock',
				'scope'          => 'posts:write',
				'summary'        => 'Get a post\'s edit-lock state.',
				'description'    => 'Returns the current edit-lock state for a post - a safe pre-flight before acquiring. When the post is locked, the response includes the owner\'s name, how long ago the lock was taken, and minutes until it expires; "yours" is true when the calling token\'s bound user holds it. Requires posts:write and a bound user.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'LockState',
				'try_it'         => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/posts/{id}/lock',
				'operation_id'   => 'acquirePostLock',
				'scope'          => 'posts:write',
				'summary'        => 'Acquire a post edit lock.',
				'description'    => 'Acquires the edit lock for a post (10-minute expiry). Idempotent for the lock owner and for a stale/unclaimed lock - re-acquiring simply refreshes the expiry. Returns 409 with the current owner\'s name and time-to-expiry if another user holds a fresh lock. Requires posts:write and a bound user.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'LockState',
				'try_it'         => false,
			),
			array(
				'method'         => 'PUT',
				'path'           => '/posts/{id}/lock',
				'operation_id'   => 'renewPostLock',
				'scope'          => 'posts:write',
				'summary'        => 'Heartbeat (renew) a post edit lock.',
				'description'    => 'Resets the 10-minute expiry on a lock you already hold - call roughly every 5 minutes during a long edit session to keep it alive. Returns 409 if you do not hold the lock. Requires posts:write and a bound user.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'LockState',
				'try_it'         => false,
			),
			array(
				'method'         => 'DELETE',
				'path'           => '/posts/{id}/lock',
				'operation_id'   => 'releasePostLock',
				'scope'          => 'posts:write',
				'summary'        => 'Release a post edit lock.',
				'description'    => 'Releases the lock. Releasing an already-free lock is a no-op. A posts:write.all (sysadmin) token may force-release a lock held by another user. Requires posts:write and a bound user.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Post id.'),
				),
				'response_schema'=> 'LockState',
				'try_it'         => false,
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
				'path'           => '/positions',
				'operation_id'   => 'listPositions',
				'scope'          => 'positions:read',
				'summary'        => 'List open crew positions.',
				'description'    => 'Open positions (one or more unfilled slots, pos_open > 0), ordered by department then roster order. Set top=1 to return only the sim\'s top open positions (the headline billets flagged in the roster admin, and the same set shown on the join page). Reuses Nova\'s own open-positions query.',
				'parameters'     => array(
					array('name' => 'top',      'in' => 'query', 'required' => false, 'type' => 'boolean', 'default' => false, 'description' => 'When true (1/true/yes), return only top open positions (pos_top_open = y).'),
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'y',   'description' => 'pos_display filter: y (default) for listed positions, n for hidden ones.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,     'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,    'description' => 'Items per page. Capped at 100.'),
				),
				'response_schema'=> 'PositionList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/snapshot',
				'operation_id'   => 'getSnapshot',
				'scope'          => 'astrolabe:read',
				'summary'        => 'Astrolabe snapshot of this sim.',
				'description'    => 'One read-only aggregate of this sim\'s PUBLIC data for the Astrolabe platform to mirror: game info, crew manifest (departments and characters, active + NPCs), stories (missions), the 10 most recent posts, and headcounts. All url / avatar_url / rank.image values are absolute https or null; descriptions and excerpts are plain text (HTML stripped) and length-capped. No private data (no email, real name, IP, or account internals). Served from a short-TTL cache since Astrolabe polls on a schedule. A token scoped to only astrolabe:read exposes nothing else.',
				'parameters'     => array(),
				'response_schema'=> 'Snapshot',
				'try_it'         => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/users/disable',
				'operation_id'   => 'disableUser',
				'scope'          => 'users:write',
				'summary'        => 'Disable a user and their characters.',
				'description'    => 'Sets the user to status=inactive and every currently-active linked character to crew_type=inactive. Identify the user by user_id (always works) or discord_id (requires the Discord Auth feature; returns 409 if it is off). Body accepted as JSON, form-encoded, or query string.',
				'parameters'     => array(
					array('name' => 'user_id',    'in' => 'body', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Nova user id. Provide this OR discord_id.'),
					array('name' => 'discord_id', 'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Linked Discord account id. Requires the Discord Auth feature.'),
				),
				'response_schema'=> 'UserStatusResult',
				'try_it'         => false,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/users/reactivate',
				'operation_id'   => 'reactivateUser',
				'scope'          => 'users:write',
				'summary'        => 'Reactivate a user (and optionally their characters).',
				'description'    => 'Sets the user to status=active. By default every previously-inactive linked character is set back to crew_type=active; pass reactivate_characters=false to leave characters untouched. Identify the user by user_id or discord_id (the latter requires the Discord Auth feature).',
				'parameters'     => array(
					array('name' => 'user_id',               'in' => 'body', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Nova user id. Provide this OR discord_id.'),
					array('name' => 'discord_id',            'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Linked Discord account id. Requires the Discord Auth feature.'),
					array('name' => 'reactivate_characters', 'in' => 'body', 'required' => false, 'type' => 'boolean', 'default' => true, 'description' => 'Set false to reactivate only the user, leaving characters inactive.'),
				),
				'response_schema'=> 'UserStatusResult',
				'try_it'         => false,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/webhooks',
				'operation_id'   => 'listWebhooks',
				'scope'          => 'webhooks:read',
				'summary'        => 'List event webhooks.',
				'description'    => 'Returns every configured event webhook (enabled first). Requires the Event Webhooks feature to be enabled (409 if off). The destination URL is included since these endpoints are already privileged.',
				'parameters'     => array(),
				'response_schema'=> 'WebhookList',
				'try_it'         => true,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/webhooks/{id}',
				'operation_id'   => 'getWebhook',
				'scope'          => 'webhooks:read',
				'summary'        => 'Get a single webhook.',
				'description'    => 'Fetches one event webhook by id. Requires the Event Webhooks feature.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Webhook id.'),
				),
				'response_schema'=> 'Webhook',
				'try_it'         => true,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/webhooks',
				'operation_id'   => 'createWebhook',
				'scope'          => 'webhooks:write',
				'summary'        => 'Create an event webhook.',
				'description'    => 'Creates a webhook. Minimum body: label, url, format ("discord" or "generic"), and events (array of post.saved / post.posted / log.posted / news.posted). Optional: enabled, news_types, mention_role_id + mention_role_events, and the template_* fields. Requires the Event Webhooks feature. Returns 201 with the created webhook.',
				'parameters'     => array(
					array('name' => 'label',                'in' => 'body', 'required' => true,  'type' => 'string',  'default' => null, 'description' => 'Display label (max 120 chars).'),
					array('name' => 'url',                  'in' => 'body', 'required' => true,  'type' => 'string',  'default' => null, 'description' => 'Destination http(s) URL.'),
					array('name' => 'format',               'in' => 'body', 'required' => true,  'type' => 'string',  'default' => null, 'description' => '"discord" or "generic".'),
					array('name' => 'events',               'in' => 'body', 'required' => true,  'type' => 'array',   'default' => null, 'description' => 'One or more of: post.saved, post.posted, log.posted, news.posted.'),
					array('name' => 'enabled',              'in' => 'body', 'required' => false, 'type' => 'boolean', 'default' => true, 'description' => 'Whether the webhook fires. Defaults to true.'),
					array('name' => 'news_types',           'in' => 'body', 'required' => false, 'type' => 'string',  'default' => 'public', 'description' => 'For news.posted: public, private, or both.'),
					array('name' => 'mention_role_id',      'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Numeric Discord role id to ping (discord format only).'),
					array('name' => 'mention_role_events',  'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'Subset of events on which the role is pinged.'),
					array('name' => 'template_title',       'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Discord embed title template for post.posted.'),
					array('name' => 'template_description', 'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Discord embed description template for post.posted.'),
					array('name' => 'template_log_title',       'in' => 'body', 'required' => false, 'type' => 'string', 'default' => null, 'description' => 'Discord embed title template for log.posted.'),
					array('name' => 'template_log_description', 'in' => 'body', 'required' => false, 'type' => 'string', 'default' => null, 'description' => 'Discord embed description template for log.posted.'),
					array('name' => 'template_news_title',       'in' => 'body', 'required' => false, 'type' => 'string', 'default' => null, 'description' => 'Discord embed title template for news.posted.'),
					array('name' => 'template_news_description', 'in' => 'body', 'required' => false, 'type' => 'string', 'default' => null, 'description' => 'Discord embed description template for news.posted.'),
				),
				'response_schema'=> 'Webhook',
				'success_code'   => 201,
				'try_it'         => false,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'PATCH',
				'path'           => '/webhooks/{id}',
				'operation_id'   => 'updateWebhook',
				'scope'          => 'webhooks:write',
				'summary'        => 'Update a webhook (partial).',
				'description'    => 'Updates an existing webhook. Only the fields you send are changed; omitted fields keep their stored values. Same field set and validation as create. PUT is accepted as an alias. Requires the Event Webhooks feature.',
				'parameters'     => array(
					array('name' => 'id',                   'in' => 'path', 'required' => true,  'type' => 'integer', 'default' => null, 'description' => 'Webhook id.'),
					array('name' => 'label',                'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Display label (max 120 chars).'),
					array('name' => 'url',                  'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Destination http(s) URL.'),
					array('name' => 'format',               'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => '"discord" or "generic".'),
					array('name' => 'events',               'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'One or more of: post.saved, post.posted, log.posted, news.posted.'),
					array('name' => 'enabled',              'in' => 'body', 'required' => false, 'type' => 'boolean', 'default' => null, 'description' => 'Whether the webhook fires.'),
					array('name' => 'news_types',           'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'For news.posted: public, private, or both.'),
					array('name' => 'mention_role_id',      'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Numeric Discord role id to ping.'),
					array('name' => 'mention_role_events',  'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'Subset of events on which the role is pinged.'),
				),
				'response_schema'=> 'Webhook',
				'try_it'         => false,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'DELETE',
				'path'           => '/webhooks/{id}',
				'operation_id'   => 'deleteWebhook',
				'scope'          => 'webhooks:write',
				'summary'        => 'Delete a webhook.',
				'description'    => 'Permanently deletes an event webhook by id. Requires the Event Webhooks feature.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Webhook id.'),
				),
				'response_schema'=> 'WebhookDeleteResult',
				'try_it'         => false,
				'feature_gated'  => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/tokens',
				'operation_id'   => 'listTokens',
				'scope'          => 'tokens:read',
				'summary'        => 'List API tokens.',
				'description'    => 'Lists all API tokens (metadata only - never the raw token or its hash). Requires tokens:read AND a token bound to a sysadmin user (403 otherwise), mirroring the ACP where only sysadmins manage tokens.',
				'parameters'     => array(),
				'response_schema'=> 'TokenList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/tokens/{id}',
				'operation_id'   => 'getToken',
				'scope'          => 'tokens:read',
				'summary'        => 'Get a single API token.',
				'description'    => 'Fetches one token\'s metadata by id (no secret material). Requires tokens:read + sysadmin-bound token.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Token id.'),
				),
				'response_schema'=> 'Token',
				'try_it'         => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/tokens',
				'operation_id'   => 'createToken',
				'scope'          => 'tokens:write',
				'summary'        => 'Create an API token.',
				'description'    => 'Creates a token and returns the raw value EXACTLY ONCE in the "token" field - store it immediately, only its hash is kept. Requires tokens:write + sysadmin-bound token. Body mirrors the ACP form: label, scopes, optional user_id binding, optional expires_at. A user binding is required if any post own/write/delete scope is requested.',
				'parameters'     => array(
					array('name' => 'label',      'in' => 'body', 'required' => true,  'type' => 'string',  'default' => null, 'description' => 'Label to identify the token.'),
					array('name' => 'scopes',     'in' => 'body', 'required' => true,  'type' => 'array',   'default' => null, 'description' => 'Scopes to grant (array of scope strings).'),
					array('name' => 'user_id',    'in' => 'body', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Bind the token to this user (required for posts own/write/delete scopes).'),
					array('name' => 'expires_at', 'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Optional future expiry (any strtotime-parseable date/time).'),
				),
				'response_schema'=> 'TokenCreateResult',
				'success_code'   => 201,
				'try_it'         => false,
			),
			array(
				'method'         => 'PATCH',
				'path'           => '/tokens/{id}',
				'operation_id'   => 'updateToken',
				'scope'          => 'tokens:write',
				'summary'        => 'Update a token: edit scopes and/or revoke.',
				'description'    => 'Edits an existing token in place. Send "scopes" (array) to replace the token\'s scope set - re-validated against the token\'s existing user binding, so post own/write/delete scopes still require a bound user. Send "revoked" to set (true) or clear (false) revocation. Either or both may be supplied; a scopes-only PATCH leaves revocation untouched. For backwards compatibility, a PATCH with no "scopes" defaults to revoked=true. Requires tokens:write + sysadmin-bound token. PUT is accepted as an alias. The managed Sim Central access token cannot have its scopes edited here.',
				'parameters'     => array(
					array('name' => 'id',      'in' => 'path', 'required' => true,  'type' => 'integer', 'default' => null, 'description' => 'Token id.'),
					array('name' => 'scopes',  'in' => 'body', 'required' => false, 'type' => 'array',   'default' => null, 'description' => 'New scope list (replaces the existing set). Invalid scopes are dropped; at least one valid scope required.'),
					array('name' => 'revoked', 'in' => 'body', 'required' => false, 'type' => 'boolean', 'default' => true, 'description' => 'true to revoke, false to un-revoke. Defaults to true only when no scopes are supplied.'),
				),
				'response_schema'=> 'Token',
				'try_it'         => false,
			),
			array(
				'method'         => 'DELETE',
				'path'           => '/tokens/{id}',
				'operation_id'   => 'deleteToken',
				'scope'          => 'tokens:write',
				'summary'        => 'Delete a token.',
				'description'    => 'Permanently deletes a token. Requires tokens:write + sysadmin-bound token. (Revoking with PATCH is the softer option - it preserves the row for audit.)',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Token id.'),
				),
				'response_schema'=> 'TokenDeleteResult',
				'try_it'         => false,
			),
			// ---- suite self-management (version + remote upgrade) ----
			array(
				'method'         => 'GET',
				'path'           => '/suite',
				'operation_id'   => 'getSuiteStatus',
				'scope'          => null,
				'summary'        => 'Installed suite version and update status.',
				'description'    => 'Returns the installed Sim Central Suite version, the latest published release (from the 24h update-check cache), and whether an update is available. Any valid token.',
				'parameters'     => array(),
				'response_schema'=> 'SuiteStatus',
				'try_it'         => true,
			),
			array(
				'method'         => 'POST',
				'path'           => '/suite',
				'operation_id'   => 'upgradeSuite',
				'scope'          => 'suite:update',
				'summary'        => 'Upgrade the suite (run the updater).',
				'description'    => 'Runs the one-click updater to install a target version (default: the latest published release). Requires the suite:update scope AND a sysadmin-bound token. Routing is by HTTP method, so any POST to /suite triggers the upgrade; the legacy POST /suite/update also works, but POST /suite avoids host mod_security rules that block the word "update" in a URL. The update swaps the extension on disk and writes a timestamped backup, so this response is the LAST thing the old code returns - re-read GET /suite afterwards to confirm. Already on the target version returns status "noop" unless force is true.',
				'parameters'     => array(
					array('name' => 'version', 'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Target version, e.g. "1.22.0". Omit to upgrade to the newest published release.'),
					array('name' => 'force',   'in' => 'body', 'required' => false, 'type' => 'boolean', 'default' => false, 'description' => 'Reinstall even when already on the target version.'),
				),
				'response_schema'=> 'SuiteUpdateResult',
				'try_it'         => false,
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
					'authors'    => array('type' => 'string',   'nullable' => true, 'description' => 'Comma-separated charid list (Nova native format).'),
					'status'     => array('type' => 'string'),
					'date'       => array('type' => 'string', 'format' => 'date-time'),
					'word_count' => array('type' => 'integer', 'description' => 'Words in this post body (HTML stripped). Not attributed to any single author.'),
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
			'PostDeleteResult' => array(
				'type' => 'object',
				'properties' => array(
					'deleted' => array('type' => 'boolean'),
					'id'      => array('type' => 'integer'),
				),
				'required' => array('deleted', 'id'),
			),
			'Token' => array(
				'type' => 'object',
				'description' => 'API token metadata. Never includes the raw token or its hash.',
				'properties' => array(
					'id'           => array('type' => 'integer'),
					'label'        => array('type' => 'string'),
					'token_prefix' => array('type' => 'string', 'description' => 'First few characters, for identification.'),
					'scopes'       => array('type' => 'array', 'items' => array('type' => 'string')),
					'user_id'      => array('type' => 'integer', 'nullable' => true, 'description' => 'Bound user, if any.'),
					'created_by'   => array('type' => 'integer', 'nullable' => true),
					'created_at'   => array('type' => 'string', 'nullable' => true),
					'last_used_at' => array('type' => 'string', 'nullable' => true),
					'expires_at'   => array('type' => 'string', 'nullable' => true),
					'revoked_at'   => array('type' => 'string', 'nullable' => true),
					'revoked'      => array('type' => 'boolean'),
				),
				'required' => array('id', 'label', 'token_prefix', 'scopes', 'revoked'),
			),
			'TokenList' => array(
				'type' => 'object',
				'properties' => array(
					'data'  => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Token')),
					'total' => array('type' => 'integer'),
				),
				'required' => array('data', 'total'),
			),
			'TokenCreateResult' => array(
				'type' => 'object',
				'description' => 'The created token, plus the raw "token" value shown exactly once.',
				'properties' => array(
					'id'    => array('type' => 'integer'),
					'label' => array('type' => 'string'),
					'token' => array('type' => 'string', 'description' => 'The raw API key - shown ONCE. Store it now; only its hash is kept.'),
					'scopes'=> array('type' => 'array', 'items' => array('type' => 'string')),
				),
				'required' => array('id', 'token'),
			),
			'TokenDeleteResult' => array(
				'type' => 'object',
				'properties' => array(
					'deleted' => array('type' => 'boolean'),
					'id'      => array('type' => 'integer'),
				),
				'required' => array('deleted', 'id'),
			),
			'Me' => array(
				'type' => 'object',
				'properties' => array(
					'user' => array(
						'type' => 'object',
						'properties' => array(
							'id'          => array('type' => 'integer'),
							'name'        => array('type' => 'string'),
							'is_sysadmin' => array('type' => 'boolean'),
						),
					),
					'characters' => array(
						'type' => 'object',
						'description' => 'The bound user\'s characters, split by type. Each entry: id, name, rank, rank_order, crew_type, is_main.',
						'properties' => array(
							'pc'  => array('type' => 'array', 'items' => array('type' => 'object')),
							'npc' => array('type' => 'array', 'items' => array('type' => 'object')),
						),
					),
					'scopes' => array('type' => 'array', 'items' => array('type' => 'string')),
				),
				'required' => array('user', 'characters', 'scopes'),
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
					'word_count'      => array('type' => 'integer', 'description' => 'Total words across this mission\'s activated posts (HTML stripped). Only returned to tokens with posts:read or posts:read.all.'),
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
			'Position' => array(
				'type' => 'object',
				'properties' => array(
					'id'            => array('type' => 'integer'),
					'name'          => array('type' => 'string', 'nullable' => true),
					'description'   => array('type' => 'string', 'nullable' => true),
					'department_id' => array('type' => 'integer', 'nullable' => true),
					'department'    => array('type' => 'string', 'nullable' => true, 'description' => 'Resolved department name.'),
					'open'          => array('type' => 'integer', 'description' => 'Number of unfilled slots (pos_open).'),
					'type'          => array('type' => 'string', 'nullable' => true),
					'order'         => array('type' => 'integer', 'nullable' => true, 'description' => 'Roster sort order (pos_order).'),
					'top_open'      => array('type' => 'boolean', 'description' => 'Flagged as a top open position (pos_top_open = y).'),
				),
				'required' => array('id', 'open', 'top_open'),
			),
			'PositionList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Position')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
				),
				'required' => array('data', 'page', 'per_page', 'total'),
			),
			'Snapshot' => array(
				'type' => 'object',
				'description' => 'Public per-sim aggregate for Astrolabe. All URLs are absolute https or null; text fields are plain (HTML stripped) and capped.',
				'properties' => array(
					'version'      => array('type' => 'integer', 'description' => 'Contract version. Always 1 for this shape.'),
					'generated_at' => array('type' => 'string', 'format' => 'date-time', 'description' => 'ISO 8601 UTC time the snapshot was built.'),
					'game'         => array('type' => 'object', 'description' => 'Keys: name, url (absolute https), description (always null - Astrolabe owns the blurb).'),
					'stats'        => array('type' => 'object', 'description' => 'Keys: players (active accounts), characters (active + NPC), stories (mission count).'),
					'manifest'     => array('type' => 'array', 'description' => 'Roster groups. Each: {name, slug, departments:[{department, characters:[{name, position, avatar_url, url, rank:{name,abbreviation,image}, player:{name}}]}]}. Active players + NPCs. May be [].'),
					'stories'      => array('type' => 'array', 'description' => 'Missions. Each: {title, description, status, start_date, end_date (both null - Nova has no in-character dates), posts_count, url}. May be [].'),
					'recent_posts' => array('type' => 'array', 'description' => 'Up to 10 most recent activated posts, newest first. Each: {title, authors:[names], published_at, excerpt, url}. May be [].'),
				),
				'required' => array('version', 'generated_at', 'game', 'stats', 'manifest'),
			),
			'UserStatusResult' => array(
				'type' => 'object',
				'properties' => array(
					'user_id'    => array('type' => 'integer'),
					'discord_id' => array('type' => 'string', 'nullable' => true),
					'status'     => array('type' => 'string', 'description' => 'New user status: active or inactive.'),
					'characters' => array(
						'type' => 'object',
						'description' => 'Summary of the linked-character change.',
						'properties' => array(
							'status'   => array('type' => 'string', 'description' => 'active, inactive, or unchanged.'),
							'affected' => array('type' => 'integer'),
							'ids'      => array('type' => 'array', 'items' => array('type' => 'integer')),
						),
					),
				),
				'required' => array('user_id', 'status', 'characters'),
			),
			'Webhook' => array(
				'type' => 'object',
				'properties' => array(
					'id'                        => array('type' => 'integer'),
					'label'                     => array('type' => 'string'),
					'url'                        => array('type' => 'string', 'description' => 'Destination URL (included; endpoints are privileged).'),
					'format'                    => array('type' => 'string', 'description' => 'discord or generic.'),
					'events'                    => array('type' => 'array', 'items' => array('type' => 'string')),
					'enabled'                   => array('type' => 'boolean'),
					'news_types'                => array('type' => 'string'),
					'mention_role_id'           => array('type' => 'string', 'nullable' => true),
					'mention_role_events'       => array('type' => 'array', 'items' => array('type' => 'string')),
					'template_title'            => array('type' => 'string', 'nullable' => true),
					'template_description'      => array('type' => 'string', 'nullable' => true),
					'template_log_title'        => array('type' => 'string', 'nullable' => true),
					'template_log_description'  => array('type' => 'string', 'nullable' => true),
					'template_news_title'       => array('type' => 'string', 'nullable' => true),
					'template_news_description' => array('type' => 'string', 'nullable' => true),
					'created_at'                => array('type' => 'string', 'nullable' => true),
					'last_fired_at'             => array('type' => 'string', 'nullable' => true),
					'last_status'               => array('type' => 'integer', 'nullable' => true),
					'last_error'                => array('type' => 'string', 'nullable' => true),
				),
				'required' => array('id', 'label', 'url', 'format', 'events', 'enabled'),
			),
			'WebhookList' => array(
				'type' => 'object',
				'properties' => array(
					'data'  => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Webhook')),
					'total' => array('type' => 'integer'),
				),
				'required' => array('data', 'total'),
			),
			'WebhookDeleteResult' => array(
				'type' => 'object',
				'properties' => array(
					'deleted' => array('type' => 'boolean'),
					'id'      => array('type' => 'integer'),
				),
				'required' => array('deleted', 'id'),
			),
			'LockState' => array(
				'type' => 'object',
				'description' => 'Edit-lock state for a post. The exact field set varies by verb: acquire adds "acquired", heartbeat adds "renewed", release adds "released".',
				'properties' => array(
					'locked'             => array('type' => 'boolean'),
					'post_id'            => array('type' => 'integer'),
					'yours'              => array('type' => 'boolean', 'description' => 'True when the calling token\'s bound user holds the lock.'),
					'acquired'           => array('type' => 'boolean', 'description' => 'Present on a successful POST (acquire).'),
					'renewed'            => array('type' => 'boolean', 'description' => 'Present on a successful PUT (heartbeat).'),
					'released'           => array('type' => 'boolean', 'description' => 'Present on a successful DELETE (release).'),
					'expires_in_minutes' => array('type' => 'integer', 'nullable' => true),
					'lock'               => array('type' => 'object', 'nullable' => true, 'description' => 'Present when the post is held: keys user_id (int), owner (string), age_minutes (int), expires_in_minutes (int).'),
				),
				'required' => array('locked', 'post_id'),
			),
			'SuiteStatus' => array(
				'type' => 'object',
				'properties' => array(
					'version'          => array('type' => 'string', 'description' => 'Installed suite version.'),
					'latest_version'   => array('type' => 'string', 'nullable' => true, 'description' => 'Newest published release (from the 24h cache).'),
					'update_available' => array('type' => 'boolean'),
					'checked_at'       => array('type' => 'string', 'format' => 'date-time', 'nullable' => true),
					'release_url'      => array('type' => 'string', 'nullable' => true),
				),
				'required' => array('version', 'update_available'),
			),
			'SuiteUpdateResult' => array(
				'type' => 'object',
				'properties' => array(
					'status'  => array('type' => 'string', 'description' => '"success" or "noop".'),
					'message' => array('type' => 'string'),
					'version' => array('type' => 'string'),
					'backup'  => array('type' => 'string', 'nullable' => true, 'description' => 'Backup directory created before the swap.'),
				),
				'required' => array('status', 'version'),
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

			$successCode = isset($ep['success_code']) ? (string) $ep['success_code'] : '200';
			$op = array(
				'operationId' => $ep['operation_id'],
				'summary'     => $ep['summary'],
				'description' => $ep['description'],
				'responses'   => array(
					$successCode => array(
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

			// Feature-gated endpoints answer 409 when the underlying suite
			// feature (Event Webhooks, Discord Auth) is toggled off.
			if ( ! empty($ep['feature_gated'])) {
				$op['responses']['409'] = array(
					'description' => 'A required suite feature is not enabled on this sim (e.g. Event Webhooks, or Discord Auth for discord_id lookups).',
					'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
				);
			}

			// Body params (in => body) become a JSON requestBody; path/query
			// params stay in `parameters`.
			if ( ! empty($ep['parameters'])) {
				$pathQueryParams = array();
				$bodyProps       = array();
				$bodyRequired    = array();
				foreach ($ep['parameters'] as $p) {
					$schema = array('type' => $p['type']);
					if (array_key_exists('default', $p) && $p['default'] !== null) {
						$schema['default'] = $p['default'];
					}
					if ($p['in'] === 'body') {
						$bodyProps[$p['name']] = array_merge($schema, array('description' => $p['description']));
						if ( ! empty($p['required'])) {
							$bodyRequired[] = $p['name'];
						}
						continue;
					}
					$pathQueryParams[] = array(
						'name'        => $p['name'],
						'in'          => $p['in'],
						'required'    => ! empty($p['required']),
						'description' => $p['description'],
						'schema'      => $schema,
					);
				}

				if ( ! empty($pathQueryParams)) {
					$op['parameters'] = $pathQueryParams;
				}
				if ( ! empty($bodyProps)) {
					$bodySchema = array('type' => 'object', 'properties' => $bodyProps);
					if ( ! empty($bodyRequired)) {
						$bodySchema['required'] = $bodyRequired;
					}
					$op['requestBody'] = array(
						'required' => ! empty($bodyRequired),
						'content'  => array(
							'application/json' => array('schema' => $bodySchema),
						),
					);
					$op['responses']['422'] = array(
						'description' => 'Validation failed. See the details array.',
						'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/ErrorResponse'))),
					);
				}
			}

			$paths[$pathKey][strtolower($ep['method'])] = $op;
		}

		return array(
			'openapi' => '3.0.3',
			'info' => array(
				'title'       => 'Sim Central Suite REST API',
				'description' => 'HTTP API for external integrations (n8n flows, scripts, dashboards, and mobile apps). Read endpoints for posts, characters, and missions; identity via /me; post authoring (create/update/delete) for tokens bound to a user; plus write endpoints for user activation status and event-webhook management. Authenticate with the X-API-Key header.',
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
