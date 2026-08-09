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
				'description'    => 'Paginated list of mission posts, newest first (?sort=order for in-mission reading order). With posts:read (public) only activated posts show by default (?status=any still permitted as before). A user-bound token with posts:read.own lists that user\'s own posts including drafts; posts:read.all (sysadmin) lists any post including others\' drafts. Every row carries the public-display fields (author_names, published_at, url, excerpt) so a reader UI needs no follow-up lookups, and the envelope carries a meta block with total/last_page for numbered pagination.',
				'parameters'     => array(
					array('name' => 'mission_id', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => null,      'description' => 'Filter to a single mission id.'),
					array('name' => 'mission',  'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => null,        'description' => 'Legacy alias for mission_id. Used only when mission_id is absent.'),
					array('name' => 'status',   'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'activated', 'description' => 'Post status. "any" returns drafts/saved/activated; "saved", "draft" also valid.'),
					array('name' => 'sort',     'in' => 'query', 'required' => false, 'type' => 'string',  'default' => null,        'description' => '"order" lists in in-mission reading order, ascending - the Ordered Mission Posts scheme when that feature is on, else post date ascending. Anything else keeps the default newest-first.'),
					array('name' => 'content',  'in' => 'query', 'required' => false, 'type' => 'boolean', 'default' => true,        'description' => 'Set 0 (or false/no/off) to omit each post\'s content from the response - metadata-only rows, every other field intact. Default includes bodies, unchanged. Useful for an index page that fetches a body only when a reader opens that post.'),
					array('name' => 'updated_since', 'in' => 'query', 'required' => false, 'type' => 'string', 'default' => null,    'description' => 'ISO 8601 datetime (v1.39.0+). Returns only posts whose content_hash changed at or after this instant, and combines with every other filter. A value with no offset is read as UTC, so you never need the sim\'s timezone; a bare unix timestamp is also accepted. INCLUSIVE (>=): store the highest content_updated_at you have seen and pass it back verbatim - the boundary row arrives again, which your hash comparison makes free, and nothing written in the same second can be dropped. Rows whose tracking column is not yet filled in are included rather than hidden. An unparseable value is a 422, never a silently ignored filter. Pair with content=0 to enumerate what changed before fetching bodies.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,           'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,          'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
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
				'description'    => 'Paginated list of characters. Default status filter is "active" (crew_type). ?status=any returns every character (active, inactive, pending, etc.). Each row carries resolved rank and position context (v1.39.0+) so a departmental roster with working character links needs no second call, and the player behind the character (v1.41.0+). Pass include=bio (v1.42.0+) to attach every character\'s bio values to the list.',
				'parameters'     => array(
					array('name' => 'status',   'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'active', 'description' => 'Filter by crew_type. "any" disables the filter.'),
					array('name' => 'include',  'in' => 'query', 'required' => false, 'type' => 'string',  'default' => null,     'description' => 'include=bio attaches each row\'s bio array, the same shape GET /characters/{id} returns (v1.42.0+). Off by default because it is a much larger payload - not because it is slow: the whole page\'s values are read in one query, not one per row.'),
					array('name' => 'updated_since', 'in' => 'query', 'required' => false, 'type' => 'string', 'default' => null, 'description' => 'ISO 8601 datetime (v1.39.0+). Returns only characters whose content_hash changed at or after this instant. A value with no offset is read as UTC. Inclusive (>=), so pass back the highest content_updated_at you have seen and expect the boundary row again. An unparseable value is a 422.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,        'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,       'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
				),
				'response_schema'=> 'CharacterList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/logs',
				'operation_id'   => 'listLogs',
				'scope'          => 'logs:read',
				'summary'        => 'List personal logs.',
				'description'    => 'Paginated list of personal logs, newest first (v1.39.0+). logs:read is a PUBLIC read: activated logs only, and ?status cannot talk it out of that - a draft personal log is one writer\'s private work. v1.42.0 adds the missing tier rather than widening logs:read: a token carrying logs:read.all AND bound to a sysadmin may pass ?status to reach drafts, exactly as posts:read.all works.',
				'parameters'     => array(
					array('name' => 'character_id',  'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Only logs authored by this character.'),
					array('name' => 'status',        'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'activated', 'description' => 'activated (default), saved, pending, or any. Honoured ONLY for a token with logs:read.all bound to a sysadmin (v1.42.0+); every other token sees activated rows whatever this says.'),
					array('name' => 'updated_since', 'in' => 'query', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'ISO 8601 datetime (v1.39.0+). Same semantics as on /posts: inclusive, UTC when unqualified, 422 when unparseable.'),
					array('name' => 'content',       'in' => 'query', 'required' => false, 'type' => 'boolean', 'default' => true, 'description' => 'content=0 drops the body from list rows. excerpt and word_count are still returned.'),
					array('name' => 'page',          'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,    'description' => '1-indexed page number.'),
					array('name' => 'per_page',      'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,   'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
				),
				'response_schema'=> 'LogList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/logs/{id}',
				'operation_id'   => 'getLog',
				'scope'          => 'logs:read',
				'summary'        => 'Get a single personal log.',
				'description'    => 'Fetches one personal log by id (v1.39.0+). A draft returns 404, not 403 - saying "forbidden" would confirm that a draft with that id exists.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Log id.'),
				),
				'response_schema'=> 'Log',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/characters/{id}',
				'operation_id'   => 'getCharacter',
				'scope'          => 'characters:read',
				'summary'        => 'Get a single character.',
				'description'    => 'Fetches one character by id. Returns any status (unlike posts, where the single endpoint hides non-activated rows). Includes "bio" (v1.38.0+): the sim\'s whole bio form for this character - every visible field with its value, its type, and the section and tab it belongs to - ordered the way the sim\'s own bio page renders it. The list endpoint does not include it.',
				'parameters'     => array(
					array('name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer', 'default' => null, 'description' => 'Character id (charid).'),
				),
				'response_schema'=> 'Character',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/characters/bio-fields',
				'operation_id'   => 'listBioFields',
				'scope'          => 'characters:read',
				'summary'        => 'The sim\'s bio form definitions.',
				'description'    => 'The sim\'s whole character bio form, without any character\'s answers, in the order its bio page renders (tab, then section, then field). v1.42.0+. GET /characters/{id} already returns a character\'s bio, but reconstructing the FORM from those is one request per character, and the union of them still cannot tell "empty for this character" apart from "does not exist" - nor does it ever yield field order, help text, or a select\'s option list. A field nobody has filled in is still part of the form. Unlike the bio array on a character, hidden fields ARE included here (with display=false), because this describes the form rather than a character.',
				'parameters'     => array(
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'field_display filter: y or n. Omit to return both (the default).'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'BioFieldDefList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/missions/groups',
				'operation_id'   => 'listMissionGroups',
				'scope'          => 'missions:read',
				'summary'        => 'List mission groups (story arcs).',
				'description'    => 'The sim\'s mission groups, which map onto story arcs. v1.42.0+. Without them a long-running sim\'s missions arrive as one flat list with no way to recover the seasons they were written in; attach missions via group_id on GET /missions. Named /missions/groups because Nova\'s router dispatches on the path segment that has to be a legal method name - it is a namespace, not a claim that a group belongs to a mission.',
				'parameters'     => array(
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'MissionGroupList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/news',
				'operation_id'   => 'listNews',
				'scope'          => 'news:read',
				'summary'        => 'List news items.',
				'description'    => 'The sim\'s news items, newest first. v1.42.0+. Nothing served news history before this - the webhooks library read the table to fire news.posted and that was the only code that touched it. Defaults to activated items, like /posts; ?status=any includes unpublished ones so a consumer can import them AS unpublished rather than dropping them. news_private is reported rather than filtered on: it marks an item the sim showed members rather than visitors, and a consumer mirroring the sim needs to know which so it can reproduce that.',
				'parameters'     => array(
					array('name' => 'status',      'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'activated', 'description' => 'activated (default), saved, pending, or any.'),
					array('name' => 'category_id', 'in' => 'query', 'required' => false, 'type' => 'integer', 'description' => 'Only items in this news category.'),
					array('name' => 'page',        'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page',    'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'NewsList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/awards',
				'operation_id'   => 'listAwards',
				'scope'          => 'awards:read',
				'summary'        => 'The awards catalogue.',
				'description'    => 'Every award the sim can give, in display order, with absolute artwork URLs. v1.42.0+. For who actually received what, see GET /awards/received.',
				'parameters'     => array(
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'award_display filter: y or n. Omit to return both (the default).'),
					array('name' => 'category', 'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'award_cat filter: ic, ooc, or both.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'AwardList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/awards/received',
				'operation_id'   => 'listAwardsReceived',
				'scope'          => 'awards:read',
				'summary'        => 'Who has been given which award.',
				'description'    => 'Every award grant on the sim, newest first, with the reason and date. v1.42.0+. Each grant carries its own id, which matters more than usual: the same award can legitimately be given to the same character twice, so the grant id is the only thing that lets a consumer re-run an import without duplicating grants. Pending and rejected NOMINATIONS (Nova\'s awards queue) are deliberately not exposed by this or any endpoint - that is moderation state, not a record of an award.',
				'parameters'     => array(
					array('name' => 'character_id', 'in' => 'query', 'required' => false, 'type' => 'integer', 'description' => 'Only grants to this character.'),
					array('name' => 'award_id',     'in' => 'query', 'required' => false, 'type' => 'integer', 'description' => 'Only grants of this award.'),
					array('name' => 'page',         'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'AwardReceivedList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/specs',
				'operation_id'   => 'listSpecs',
				'scope'          => 'specs:read',
				'summary'        => 'Ship specifications, flattened.',
				'description'    => 'The sim\'s ship specifications with their fields already flattened out of Nova\'s EAV tables into plain label/value pairs, grouped by section. v1.42.0+. Flattened sim-side because the definitions live in tables a consumer cannot see, and because Nova pre-creates a row per field per item - so empty values are omitted entirely rather than returned as "". Also carries the deck tour\'s DECKS, which despite the name hang off a spec item rather than a tour item (Nova\'s deck editor picks a ship).',
				'parameters'     => array(
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'specs_display filter: y or n. Omit to return both (the default).'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'SpecItemList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/tour',
				'operation_id'   => 'listTour',
				'scope'          => 'specs:read',
				'summary'        => 'The deck tour\'s locations, flattened.',
				'description'    => 'The sim\'s deck tour locations, flattened the same way as GET /specs. v1.42.0+. Two differences, both structural in Nova rather than choices made here: tour fields are ONE FLAT ORDERED LIST (there is no tour sections table), so a tour field carries no section key at all; and decks are NOT here - they belong to a ship, and are returned by GET /specs. Each tour location names the ship it belongs to via spec_item_id.',
				'parameters'     => array(
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'tour_display filter: y or n. Omit to return both (the default).'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500.'),
				),
				'response_schema'=> 'TourItemList',
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
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,   'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
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
				'summary'        => 'List crew positions (open by default).',
				'description'    => 'By default, open positions only (one or more unfilled slots, pos_open > 0), ordered by department then roster order - the set the join page shows, via Nova\'s own open-positions query. Set open=any (v1.41.0+) to return EVERY position regardless of pos_open, filled ones included: the filled positions are the ones the crew actually holds, so that is what a roster mirror or a migration wants. Set top=1 to return only the sim\'s top open positions (the headline billets flagged in the roster admin); it is ignored when open=any, since "top open" has no meaning over a list that is not restricted to open billets.',
				'parameters'     => array(
					array('name' => 'open',     'in' => 'query', 'required' => false, 'type' => 'string',  'default' => '1',   'description' => '1 (default) for open positions only - unchanged behaviour. any returns every position regardless of pos_open. v1.41.0+.'),
					array('name' => 'top',      'in' => 'query', 'required' => false, 'type' => 'boolean', 'default' => false, 'description' => 'When true (1/true/yes), return only top open positions (pos_top_open = y). Ignored when open=any.'),
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'default' => 'y',   'description' => 'pos_display filter: y (default) for listed positions, n for hidden ones. One or the other, never both.'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,     'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25,    'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
				),
				'response_schema'=> 'PositionList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/departments',
				'operation_id'   => 'listDepartments',
				'scope'          => 'positions:read',
				'summary'        => 'List every department.',
				'description'    => 'Every department on the sim, ordered by roster order, with its description, manifest assignment and display flag. v1.41.0+. Before this, department names reached the API only as sub-fields of a character\'s position and as labels in the snapshot manifest, so a department with nobody in it was invisible and its description and ordering were unavailable at any size. Includes child departments (dept_parent), which Nova\'s own default department query omits. parent_id and type are Nova concepts most consumers have no counterpart for; they are returned as provenance.',
				'parameters'     => array(
					array('name' => 'display',     'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'dept_display filter: y for listed departments, n for hidden ones. Omit to return both (the default) - each row carries its own display flag.'),
					array('name' => 'manifest_id', 'in' => 'query', 'required' => false, 'type' => 'integer', 'description' => 'Only departments attached to this manifest.'),
					array('name' => 'page',        'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page',    'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
				),
				'response_schema'=> 'DepartmentList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/manifests',
				'operation_id'   => 'listManifests',
				'scope'          => 'positions:read',
				'summary'        => 'List every manifest.',
				'description'    => 'Every manifest on the sim (a sim can have several - crew, support craft, NPCs), ordered by manifest order. v1.41.0+. Departments attach to one of these via the manifest_id on GET /departments; the snapshot\'s manifest tree carries names but no ids, so until now there was no way to attach a department to the right manifest. Nova\'s presentation-only columns are deliberately not returned: manifest_view stores raw jQuery snippets rather than a view name, and manifest_metadata is a pipe-delimited list of bio field names to render beside each crew member.',
				'parameters'     => array(
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'manifest_display filter: y or n. Omit to return both (the default).'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
				),
				'response_schema'=> 'ManifestList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/ranks',
				'operation_id'   => 'listRanks',
				'scope'          => 'characters:read',
				'summary'        => 'The sim\'s full rank ladder, with artwork.',
				'description'    => 'Every rank on the sim, in ladder order, including rungs nobody currently wears. v1.41.0+. Rank data otherwise reaches the API only denormalised onto characters (rank_name / rank_short / rank_order) and as an image URL inside the snapshot manifest, which between them cover only the ranks somebody is wearing right now - so a consumer mirroring this sim had nothing to promote anyone INTO. image_url is an absolute https URL generated sim-side, never a bare rank_image filename: rank art resolves through assets/common/{genre}/ranks/{set}/{image}{ext}, which depends on a sim setting and a catalogue column no consumer can see. It is null when the rank has no image, and also when the sim\'s configured rank set cannot be resolved (most often a set belonging to another genre) - null rather than a URL that would 404. The rank catalogue itself is not exposed; import the artwork as one game-owned set.',
				'parameters'     => array(
					array('name' => 'display',  'in' => 'query', 'required' => false, 'type' => 'string',  'description' => 'rank_display filter: y or n. Omit to return both (the default).'),
					array('name' => 'page',     'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 1,  'description' => '1-indexed page number.'),
					array('name' => 'per_page', 'in' => 'query', 'required' => false, 'type' => 'integer', 'default' => 25, 'description' => 'Items per page. Capped at 500 (v1.41.0+; was 100).'),
				),
				'response_schema'=> 'RankList',
				'try_it'         => true,
			),
			array(
				'method'         => 'GET',
				'path'           => '/snapshot',
				'operation_id'   => 'getSnapshot',
				'scope'          => 'astrolabe:read',
				'summary'        => 'Astrolabe snapshot of this sim.',
				'description'    => 'One read-only aggregate of this sim\'s PUBLIC data for the Astrolabe platform to mirror: game info, crew manifest (departments and characters, active + NPCs), stories (missions), the 10 most recent posts, and headcounts. All url / avatar_url / rank.image values are absolute https or null; descriptions and excerpts are plain text - HTML flattened with block boundaries preserved as spaces (v1.36.1+), entities decoded, whitespace collapsed - and length-capped. No private data (no email, real name, IP, or account internals). Served from a short-TTL cache since Astrolabe polls on a schedule. A token scoped to only astrolabe:read exposes nothing else.',
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
				'description'    => 'Creates a token and returns the raw value EXACTLY ONCE in the "token" field - store it immediately, only its hash is kept. Requires tokens:write + sysadmin-bound token. Body mirrors the ACP form: label, scopes, optional user binding, optional expires_at. Bind by user_id OR discord_id (v1.32.0+, the linked Discord account - requires the Discord Auth feature, 409 when off/unlinked; 422 when both identifiers are sent). A user binding is required if any post own/write/delete scope is requested; omit both identifiers for an unbound token.',
				'parameters'     => array(
					array('name' => 'label',      'in' => 'body', 'required' => true,  'type' => 'string',  'default' => null, 'description' => 'Label to identify the token.'),
					array('name' => 'scopes',     'in' => 'body', 'required' => true,  'type' => 'array',   'default' => null, 'description' => 'Scopes to grant (array of scope strings).'),
					array('name' => 'user_id',    'in' => 'body', 'required' => false, 'type' => 'integer', 'default' => null, 'description' => 'Bind the token to this user (required for posts own/write/delete scopes). Mutually exclusive with discord_id.'),
					array('name' => 'discord_id', 'in' => 'body', 'required' => false, 'type' => 'string',  'default' => null, 'description' => 'Bind to the user linked to this Discord account ID (requires the Discord Auth feature; 409 when off or unlinked). Mutually exclusive with user_id.'),
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
			'PaginationMeta' => array(
				'type' => 'object',
				'description' => 'Pagination block on every list envelope (v1.36.0+). Restates the envelope\'s flat page/per_page/total and adds last_page, so a client can render numbered pages rather than prev/next.',
				'properties' => array(
					'current_page' => array('type' => 'integer', 'description' => '1-indexed page just returned.'),
					'last_page'    => array('type' => 'integer', 'description' => 'Number of pages at this per_page. Always >= 1.'),
					'per_page'     => array('type' => 'integer'),
					'total'        => array('type' => 'integer', 'description' => 'Total rows matching the filters, across all pages.'),
					'hash_version' => array('type' => 'integer', 'description' => 'Which canonical recipe produced the content_hash values in this response (v1.39.0+). If this is not the number you stored your hashes under, treat every hash you hold as stale and re-fetch rather than diffing across recipes.'),
				),
				'required' => array('current_page', 'last_page', 'per_page', 'total'),
			),
			'Post' => array(
				'type' => 'object',
				'properties' => array(
					'id'         => array('type' => 'integer'),
					'title'      => array('type' => 'string'),
					'content'    => array('type' => 'string', 'description' => 'The post body. Always present, except on GET /posts when called with ?content=0 (v1.36.0+), where the key is omitted entirely.'),
					'mission_id' => array('type' => 'integer', 'nullable' => true),
					'authors'    => array('type' => 'string',   'nullable' => true, 'description' => 'Comma-separated charid list (Nova native format). Unchanged - map these ids to an author picker; for names read author_names.'),
					'author_names' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'The authors\' display names, in the stored order. [] when there are none. Honours the Display Name override when that feature is on.'),
					'tags'       => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'v1.41.0+. The post\'s tags. Accepted on write - and hashed into content_hash - since post authoring shipped, but never projected until now, so a tag written through this API could not be read back by anything. Always an array; the write side accepts either a list or a comma-separated string.'),
					'participants' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'object',
							'properties' => array(
								'user_id' => array('type' => 'integer'),
								'name'    => array('type' => 'string', 'nullable' => true, 'description' => 'Public display name, or null if the user no longer resolves - the entry is kept either way, since on a retiring sim this is the only record that someone took part.'),
							),
						),
						'description' => 'v1.41.0+. Who took part in the post. NOTE these are USER ids, not character ids - post_participants is the one id CSV on a post row that is not character-keyed, hence the explicit user_id key. Nova only maintains the column when the sim has use_post_participants on, so [] is the common case.',
					),
					'status'     => array('type' => 'string'),
					'date'       => array('type' => 'string', 'format' => 'date-time'),
					'published_at' => array('type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'ISO 8601 UTC activation timestamp. Null unless the post is activated.'),
					'url'        => array('type' => 'string', 'description' => 'Absolute https URL of the post\'s public page on the sim.'),
					'excerpt'    => array('type' => 'string', 'nullable' => true, 'description' => 'Plain-text preview of the body, <=300 chars, null when the body is empty. Flattening is block-boundary aware (v1.36.1+): a </p>, <br>, </h2>, </li> or table cell becomes one space, inline tags become nothing, markup stored escaped is removed as markup, a "<" in prose ("in <20 minutes") is kept, script/style contents are dropped, and the cap counts characters so the value is always valid UTF-8.'),
					'content_hash'      => array('type' => 'string', 'nullable' => true, 'description' => 'SHA-256 of a canonical serialization of this post (v1.39.0+): title, content, location, timeline, mission, authors, status, tags, the ordered-timeline fields and the age gate, joined with U+001F. Metadata-only edits move it, so a retitle or an author change is a change. Opaque - compare it, do not recompute it. Null until Setup database has run for the REST API feature.'),
					'content_updated_at'=> array('type' => 'string', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z) instant at which content_hash last changed (v1.39.0+). This is what ?updated_since filters on. Saving a post without editing it does NOT move this value. Null until Setup database has run.'),
					'location'   => array('type' => 'string', 'description' => 'In-character location (Nova post_location). "" when unset.'),
					'timeline'   => array('type' => 'string', 'description' => 'Free-text timeline (Nova post_timeline). The raw stored value - not the webhook payload\'s timeline, which renders the Ordered Mission Posts day/time. "" when unset.'),
					'word_count' => array('type' => 'integer', 'description' => 'Words in this post body, counted after the same flattening as excerpt, with a Unicode-aware token matcher (v1.37.0+: block boundaries separate words, a bare "<" in prose no longer truncates the count, entities and script/style contents no longer count as words, an accented word counts once rather than splitting on its bytes, and digits count). Computed per request from the body, so it is present for EVERY status - drafts included - and never null. Not attributed to any single author. Still present when ?content=0 omits the body.'),
					'saved_character_id' => array('type' => 'integer', 'nullable' => true, 'description' => 'Nova post_saved: the character recorded as the last saver. Null when unknown.'),
					'saved_user_id'      => array('type' => 'integer', 'nullable' => true, 'description' => 'The user who owns the last saving character - compare against a writer\'s own user id for "whose turn is it". Null when unknown/unowned. Matches the webhook actor\'s user.'),
					'saved_user_name'    => array('type' => 'string',  'nullable' => true, 'description' => 'That user\'s public display name (same rule as Character.user_name). Null when unknown.'),
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
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
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
					'middle_name'    => array('type' => 'string', 'nullable' => true, 'description' => 'v1.41.0+. The one name part the API did not previously return.'),
					'last_name'      => array('type' => 'string', 'nullable' => true),
					'suffix'         => array('type' => 'string', 'nullable' => true),
					'status'         => array('type' => 'string', 'description' => 'crew_type: active, inactive, pending, etc.'),
					'rank'           => array('type' => 'integer', 'nullable' => true, 'description' => 'rank_id - resolve against GET /ranks (v1.41.0+), or read rank_name/rank_short below.'),
					'user_id'        => array('type' => 'integer', 'nullable' => true),
					'user_name'      => array('type' => 'string', 'nullable' => true, 'description' => 'The linked user\'s public display name (same value/visibility as the snapshot manifest\'s player.name - never email or account internals). Null when unowned/unlinked.'),
					'player'         => array(
						'type'        => 'object',
						'nullable'    => true,
						'description' => 'v1.41.0+. The same {name, discord_id} object the snapshot manifest carries, so a consumer can resolve this character\'s player to an account of its own. user_id is Nova-internal and user_name collides on duplicates, so discord_id is the only identifier here that means anything off-site. Null when the character has no owning user. Unlike the snapshot manifest - which lists active and NPC characters only - this is present on every character, including inactive and pending ones.',
						'properties'  => array(
							'name'       => array('type' => 'string', 'nullable' => true, 'description' => 'Identical to user_name; both read from one source.'),
							'discord_id' => array('type' => 'string', 'nullable' => true, 'description' => 'The linked public Discord ID. Null when the Discord Sign-In feature is off, the column is absent, or the user never linked. Never email, real name, or account internals.'),
						),
					),
					'images'         => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'v1.41.0+. Absolute https URLs for this character\'s images, in stored order; element 0 is the avatar. Always an array - [] when there are none, never null. Nova stores these as a CSV of bare filenames, which is why they are resolved here rather than handed out raw.',
					),
					'date_activate'   => array('type' => 'string', 'nullable' => true, 'description' => 'v1.41.0+. ISO 8601 UTC (Z) instant the character joined the crew, or null. History, not a live field.'),
					'date_deactivate' => array('type' => 'string', 'nullable' => true, 'description' => 'v1.41.0+. ISO 8601 UTC (Z) instant the character left the crew, or null.'),
					'display_name'   => array('type' => 'string', 'nullable' => true, 'x-suite-feature' => 'Display Name'),
					'preferred_name' => array('type' => 'string', 'x-suite-feature' => 'Display Name', 'description' => 'display_name if set, else first last suffix.'),
					'rank_name'      => array('type' => 'string', 'nullable' => true, 'description' => 'Resolved rank name (v1.39.0+), entity-decoded.'),
					'rank_short'     => array('type' => 'string', 'nullable' => true, 'description' => 'Resolved rank short name (v1.39.0+).'),
					'rank_order'     => array('type' => 'integer', 'nullable' => true, 'description' => 'Rank sort order (v1.39.0+) - seniority ascending, for roster sorting.'),
					'position_primary'   => array('type' => 'object', 'nullable' => true, 'description' => 'Resolved position_1 (v1.39.0+): {id, name, department_id, department}. Null when unset or when the position no longer exists.'),
					'position_secondary' => array('type' => 'object', 'nullable' => true, 'description' => 'Resolved position_2, same shape (v1.39.0+).'),
					'content_hash'       => array('type' => 'string', 'nullable' => true, 'description' => 'SHA-256 of a canonical serialization of this character (v1.39.0+): first/last/suffix, crew_type, rank, position_1, position_2, user, display_name where the Display Name feature is installed, plus every visible bio field value ordered by field id. A bio edit therefore moves it even though the write lands in a different table. Opaque - compare it, do not recompute it.'),
					'content_updated_at' => array('type' => 'string', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z) instant at which content_hash last changed (v1.39.0+). What ?updated_since filters on.'),
					'bio'            => array(
						'type'        => 'array',
						'items'       => array('$ref' => '#/components/schemas/BioField'),
						'description' => 'On GET /characters/{id}, and on the list when called with ?include=bio (v1.42.0+ - one query for the whole page, not one per row). The sim\'s whole bio form for this character, ordered the way its own bio page renders it (tab, then section within the tab, then field within the section). Fields the sim has hidden (field_display = n) are omitted; fields the character has not filled in are present with an empty value. For the form itself - field order, help text, select options, and fields nobody has filled in anywhere - see GET /characters/bio-fields. (v1.38.0+)',
					),
				),
				'required' => array('id', 'status'),
			),
			'BioField' => array(
				'type' => 'object',
				'description' => 'One character bio field and this character\'s value for it.',
				'properties' => array(
					'id'      => array('type' => 'integer', 'description' => 'characters_fields.field_id.'),
					'name'    => array('type' => 'string', 'description' => 'Machine name (field_name), e.g. "hair_color". Stable across renames of the label.'),
					'label'   => array('type' => 'string', 'description' => 'Human label as shown on the bio page, entity-decoded ("Strengths & Weaknesses", not "&amp;"). Falls back to name when the sim left the label blank.'),
					'type'    => array('type' => 'string', 'description' => 'text, textarea, or select.'),
					'value'   => array('type' => 'string', 'description' => 'This character\'s value, raw as stored - a textarea field holds the same rich text a post body does. Always a string; "" when unset.'),
					'section' => array(
						'type' => 'object',
						'description' => 'The bio section this field sits in. A section name may be blank (the stock install ships one), so the section carries its tab - otherwise a blank section cannot be told apart. id and tab.id are null if the section or tab was deleted out from under the field.',
						'properties' => array(
							'id'   => array('type' => 'integer', 'nullable' => true),
							'name' => array('type' => 'string'),
							'tab'  => array(
								'type' => 'object',
								'properties' => array(
									'id'   => array('type' => 'integer', 'nullable' => true),
									'name' => array('type' => 'string'),
								),
							),
						),
					),
				),
				'required' => array('id', 'name', 'label', 'type', 'value', 'section'),
			),
			'Log' => array(
				'type' => 'object',
				'description' => 'A personal log (v1.39.0+). Deliberately the same shape as a Post wherever the two have the same thing to say - content, excerpt, word_count, url, content_hash - so one consumer code path can walk both.',
				'properties' => array(
					'id'             => array('type' => 'integer'),
					'title'          => array('type' => 'string', 'nullable' => true),
					'content'        => array('type' => 'string', 'nullable' => true, 'description' => 'Raw stored body. Omitted entirely when content=0.'),
					'character_id'   => array('type' => 'integer', 'nullable' => true, 'description' => 'The authoring character. A log has exactly one, so the author fields are flat here rather than the parallel arrays /posts uses.'),
					'character_name' => array('type' => 'string', 'nullable' => true, 'description' => 'Display-name rules as everywhere else: the Display Name override when set, otherwise first/last/suffix.'),
					'rank_name'      => array('type' => 'string', 'nullable' => true),
					'rank_short'     => array('type' => 'string', 'nullable' => true),
					'position'       => array('type' => 'string', 'nullable' => true, 'description' => 'The author\'s primary position name.'),
					'user_name'      => array('type' => 'string', 'nullable' => true, 'description' => 'The owning user\'s public display name - never email or account internals.'),
					'status'         => array('type' => 'string', 'description' => 'Always "activated" under logs:read.'),
					'date'           => array('type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z).'),
					'url'            => array('type' => 'string', 'description' => 'Absolute https link to the log\'s public page on the sim.'),
					'excerpt'        => array('type' => 'string', 'nullable' => true, 'description' => 'Plain-text preview, <=300 chars. Same flattener and cap as a post excerpt.'),
					'word_count'     => array('type' => 'integer', 'description' => 'The suite\'s counter, not Nova\'s stored log_words - so it is directly comparable with a post\'s word_count.'),
					'content_hash'       => array('type' => 'string', 'nullable' => true, 'description' => 'SHA-256 of a canonical serialization: title, content, author character, status, tags. Opaque - compare it, do not recompute it.'),
					'content_updated_at' => array('type' => 'string', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z) instant at which content_hash last changed. What ?updated_since filters on.'),
				),
				'required' => array('id', 'status'),
			),
			'LogList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Log')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'CharacterList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Character')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
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
					'images'          => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'v1.41.0+. Absolute https URLs for this mission\'s images, in stored order. Always an array - [] when there are none, never null. mission_images is a CSV of bare filenames, resolved here.'),
					'group_id'        => array('type' => 'integer', 'nullable' => true, 'description' => 'v1.42.0+. The mission group (story arc) this mission belongs to - resolve against GET /missions/groups. Null when ungrouped; Nova records that as either 0 or NULL depending on how the mission got there, and both come back as null.'),
					'notes'           => array('type' => 'string', 'nullable' => true, 'description' => 'v1.42.0+. The GM\'s private writing notes. ONLY returned to a token carrying missions:read.all AND bound to a sysadmin - absent entirely otherwise. Unlike everything else on this object these are NOT public: Nova renders them in the members\' writing area and the ACP, behind a permission, and on no public page.'),
					'word_count'      => array('type' => 'integer', 'description' => 'Total words across this mission\'s activated posts, counted after the same flattening as excerpt (v1.37.0+). Only returned to tokens with posts:read or posts:read.all.'),
					'posts_updated_at'=> array('type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z) - when any post in this mission last changed (v1.39.0+), i.e. the newest content_updated_at across them. Null when the mission has no posts. Scoped to what YOUR token can enumerate: a token without posts:read.all sees the rollup over activated posts only, so the value can never report a mission dirty and then hand you an empty delta. One GET /missions is therefore the whole dirty check for a per-mission sync.'),
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
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'Position' => array(
				'type' => 'object',
				'properties' => array(
					'id'            => array('type' => 'integer'),
					'name'          => array('type' => 'string', 'nullable' => true),
					'description'   => array('type' => 'string', 'nullable' => true),
					'department_id' => array('type' => 'integer', 'nullable' => true),
					'department'    => array('type' => 'string', 'nullable' => true, 'description' => 'Resolved department name.'),
					'open'          => array('type' => 'integer', 'description' => 'Number of unfilled slots (pos_open) - a COUNT, not a flag. A position\'s capacity is (current holders + open). 0 for a fully filled position, which only ?open=any returns.'),
					'type'          => array('type' => 'string', 'nullable' => true),
					'order'         => array('type' => 'integer', 'nullable' => true, 'description' => 'Roster sort order (pos_order).'),
					'display'       => array('type' => 'boolean', 'description' => 'Listed on the roster (pos_display = y). v1.41.0+.'),
					'top_open'      => array('type' => 'boolean', 'description' => 'Flagged as a top open position (pos_top_open = y).'),
				),
				'required' => array('id', 'open', 'display', 'top_open'),
			),
			'BioFieldDef' => array(
				'type' => 'object',
				'description' => 'One field of the sim\'s bio FORM, with no character\'s answer attached (v1.42.0+).',
				'properties' => array(
					'id'      => array('type' => 'integer', 'description' => 'characters_fields.field_id.'),
					'name'    => array('type' => 'string', 'description' => 'Machine name (field_name) - the stable key. Nova renames labels, not this.'),
					'label'   => array('type' => 'string', 'description' => 'Human label as shown on the bio page, entity-decoded. Falls back to name when the sim left it blank.'),
					'type'    => array('type' => 'string', 'description' => 'text, textarea, or select.'),
					'order'   => array('type' => 'integer', 'nullable' => true, 'description' => 'Sort order within its section.'),
					'display' => array('type' => 'boolean', 'description' => 'Whether the sim shows this field. Hidden fields ARE listed here (unlike in a character\'s bio array), because this describes the form.'),
					'help'    => array('type' => 'string', 'nullable' => true, 'description' => 'Help text shown under the field, entity-decoded. Null when blank.'),
					'rows'    => array('type' => 'integer', 'nullable' => true, 'description' => 'Textarea height. Nova stores it for every field, so it is meaningless on text/select.'),
					'section' => array(
						'type' => 'object',
						'description' => 'The section this field sits in, and the tab that section sits on. Ids are null if the section or tab was deleted out from under the field. tab.display is reported, not filtered on.',
						'properties' => array(
							'id'    => array('type' => 'integer', 'nullable' => true),
							'name'  => array('type' => 'string'),
							'order' => array('type' => 'integer', 'nullable' => true),
							'tab'   => array(
								'type' => 'object',
								'properties' => array(
									'id'      => array('type' => 'integer', 'nullable' => true),
									'name'    => array('type' => 'string'),
									'order'   => array('type' => 'integer', 'nullable' => true),
									'display' => array('type' => 'boolean'),
								),
							),
						),
					),
					'options' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'object',
							'properties' => array(
								'value'    => array('type' => 'string', 'description' => 'What a character\'s bio value for this field actually contains.'),
								'label'    => array('type' => 'string', 'description' => 'What the sim\'s own dropdown displays.'),
								'order'    => array('type' => 'integer', 'nullable' => true),
								'selected' => array('type' => 'boolean', 'description' => 'Marked as the default option.'),
							),
						),
						'description' => 'Present ONLY when type is "select"; absent for text and textarea fields.',
					),
				),
				'required' => array('id', 'name', 'label', 'type', 'display'),
			),
			'BioFieldDefList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/BioFieldDef')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'MissionGroup' => array(
				'type' => 'object',
				'description' => 'One mission group - a story arc (v1.42.0+).',
				'properties' => array(
					'id'          => array('type' => 'integer'),
					'name'        => array('type' => 'string', 'description' => 'Entity-decoded.'),
					'description' => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
					'order'       => array('type' => 'integer', 'nullable' => true),
					'parent_id'   => array('type' => 'integer', 'nullable' => true, 'description' => 'Parent group; null when top-level.'),
				),
				'required' => array('id', 'name'),
			),
			'MissionGroupList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/MissionGroup')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'NewsItem' => array(
				'type' => 'object',
				'description' => 'One news item (v1.42.0+).',
				'properties' => array(
					'id'                  => array('type' => 'integer'),
					'title'               => array('type' => 'string', 'description' => 'Entity-decoded.'),
					'content'             => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored, like Post.content.'),
					'date'                => array('type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z).'),
					'status'              => array('type' => 'string', 'description' => 'activated, saved, or pending.'),
					'private'             => array('type' => 'boolean', 'description' => 'The sim showed this to members rather than visitors. Reported, never filtered on - a consumer mirroring the sim needs to reproduce it.'),
					'author_user_id'      => array('type' => 'integer', 'nullable' => true, 'description' => 'Resolve against a character\'s player.discord_id on GET /characters to match the author to an account.'),
					'author_character_id' => array('type' => 'integer', 'nullable' => true),
					'category_id'         => array('type' => 'integer', 'nullable' => true),
					'category'            => array('type' => 'string', 'nullable' => true, 'description' => 'Resolved category name. Null when uncategorised or the category is gone.'),
					'tags'                => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Always an array.'),
				),
				'required' => array('id', 'title', 'status', 'private'),
			),
			'NewsList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/NewsItem')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'Award' => array(
				'type' => 'object',
				'description' => 'One award in the sim\'s catalogue (v1.42.0+).',
				'properties' => array(
					'id'          => array('type' => 'integer'),
					'name'        => array('type' => 'string', 'description' => 'Entity-decoded.'),
					'description' => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
					'category'    => array('type' => 'string', 'nullable' => true, 'description' => 'Nova\'s award_cat: ic (in-character), ooc, or both.'),
					'order'       => array('type' => 'integer', 'nullable' => true),
					'display'     => array('type' => 'boolean'),
					'image_url'   => array('type' => 'string', 'nullable' => true, 'description' => 'Absolute https URL of the award artwork, or null. Never a bare filename.'),
				),
				'required' => array('id', 'name', 'display'),
			),
			'AwardList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Award')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'AwardReceived' => array(
				'type' => 'object',
				'description' => 'One award grant (v1.42.0+).',
				'properties' => array(
					'id'                   => array('type' => 'integer', 'description' => 'The GRANT\'s id. The same award can legitimately be given to the same character twice, so this is the only thing that makes re-running an import idempotent rather than duplicating.'),
					'award_id'             => array('type' => 'integer', 'description' => 'Resolve against GET /awards.'),
					'character_id'         => array('type' => 'integer', 'nullable' => true),
					'user_id'              => array('type' => 'integer', 'nullable' => true),
					'nominated_by_user_id' => array('type' => 'integer', 'nullable' => true),
					'date'                 => array('type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'ISO 8601 UTC (Z).'),
					'reason'               => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
				),
				'required' => array('id', 'award_id'),
			),
			'AwardReceivedList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/AwardReceived')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'EavField' => array(
				'type' => 'object',
				'description' => 'One flattened specification/tour field. Fields with an empty value are omitted entirely rather than returned as "" - Nova pre-creates a row per field per item, so an unflattened dump is mostly blanks.',
				'properties' => array(
					'name'    => array('type' => 'string', 'description' => 'Machine name (field_name) - the stable key.'),
					'label'   => array('type' => 'string', 'description' => 'Human label, entity-decoded. Falls back to name when blank.'),
					'value'   => array('type' => 'string', 'description' => 'Raw as stored. Never empty - empty fields are not returned.'),
					'order'   => array('type' => 'integer', 'nullable' => true),
					'section' => array('type' => 'string', 'description' => 'Section name. Present on GET /specs only - tour fields are one flat list, and the key is ABSENT there rather than null.'),
				),
				'required' => array('name', 'label', 'value'),
			),
			'SpecItem' => array(
				'type' => 'object',
				'description' => 'One ship specification, flattened (v1.42.0+).',
				'properties' => array(
					'id'      => array('type' => 'integer'),
					'name'    => array('type' => 'string', 'description' => 'Entity-decoded.'),
					'summary' => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
					'order'   => array('type' => 'integer', 'nullable' => true),
					'display' => array('type' => 'boolean'),
					'images'  => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Absolute https URLs. Always an array.'),
					'fields'  => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/EavField')),
					'decks'   => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'object',
							'properties' => array(
								'id'      => array('type' => 'integer'),
								'name'    => array('type' => 'string'),
								'order'   => array('type' => 'integer', 'nullable' => true),
								'content' => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
							),
						),
						'description' => 'The deck tour\'s decks. They are here rather than on GET /tour because Nova hangs a deck off a SHIP (a spec item), not off a tour location.',
					),
				),
				'required' => array('id', 'name', 'display', 'images', 'fields'),
			),
			'SpecItemList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/SpecItem')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'TourItem' => array(
				'type' => 'object',
				'description' => 'One deck-tour location, flattened (v1.42.0+). No decks here and no section on its fields - see GET /tour.',
				'properties' => array(
					'id'           => array('type' => 'integer'),
					'name'         => array('type' => 'string', 'description' => 'Entity-decoded.'),
					'summary'      => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
					'order'        => array('type' => 'integer', 'nullable' => true),
					'display'      => array('type' => 'boolean'),
					'spec_item_id' => array('type' => 'integer', 'nullable' => true, 'description' => 'The ship this location belongs to - resolve against GET /specs, which is also where that ship\'s decks are.'),
					'images'       => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Absolute https URLs. Always an array.'),
					'fields'       => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/EavField'), 'description' => 'One flat ordered list; these carry no section key.'),
				),
				'required' => array('id', 'name', 'display', 'images', 'fields'),
			),
			'TourItemList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/TourItem')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'Department' => array(
				'type' => 'object',
				'description' => 'One department (v1.41.0+).',
				'properties' => array(
					'id'          => array('type' => 'integer'),
					'name'        => array('type' => 'string', 'description' => 'Entity-decoded, matching how the snapshot manifest spells it.'),
					'description' => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored - authored HTML, like Post.content.'),
					'order'       => array('type' => 'integer', 'nullable' => true, 'description' => 'Roster sort order (dept_order).'),
					'display'     => array('type' => 'boolean', 'description' => 'Shown on the manifest (dept_display = y).'),
					'type'        => array('type' => 'string', 'nullable' => true, 'description' => 'Nova\'s playing / nonplaying split. Provenance - most consumers have no counterpart and can ignore it.'),
					'parent_id'   => array('type' => 'integer', 'nullable' => true, 'description' => 'Parent department (dept_parent), null when top-level. Nova nests departments one level; a consumer that does not can record this and flatten.'),
					'manifest_id' => array('type' => 'integer', 'nullable' => true, 'description' => 'The manifest this department appears on - resolve against GET /manifests. Null when unassigned.'),
				),
				'required' => array('id', 'name', 'display'),
			),
			'DepartmentList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Department')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'Manifest' => array(
				'type' => 'object',
				'description' => 'One manifest - a roster grouping departments belong to (v1.41.0+).',
				'properties' => array(
					'id'          => array('type' => 'integer'),
					'name'        => array('type' => 'string', 'description' => 'Entity-decoded.'),
					'description' => array('type' => 'string', 'nullable' => true, 'description' => 'Raw as stored.'),
					'order'       => array('type' => 'integer', 'nullable' => true),
					'display'     => array('type' => 'boolean', 'description' => 'manifest_display = y.'),
					'default'     => array('type' => 'boolean', 'description' => 'The sim\'s default manifest (manifest_default = y).'),
				),
				'required' => array('id', 'name', 'display', 'default'),
			),
			'ManifestList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Manifest')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'Rank' => array(
				'type' => 'object',
				'description' => 'One rung of the sim\'s rank ladder (v1.41.0+).',
				'properties' => array(
					'id'         => array('type' => 'integer'),
					'name'       => array('type' => 'string', 'description' => 'Entity-decoded, e.g. "Lieutenant Commander".'),
					'short_name' => array('type' => 'string', 'description' => 'Abbreviation, e.g. "LtCmdr".'),
					'order'      => array('type' => 'integer', 'nullable' => true, 'description' => 'Ladder position - seniority ascending. Same value as Character.rank_order.'),
					'display'    => array('type' => 'boolean', 'description' => 'rank_display = y.'),
					'image_url'  => array('type' => 'string', 'nullable' => true, 'description' => 'Absolute https URL of the rank artwork, generated sim-side - never a bare filename, because the real path depends on the sim\'s genre, its chosen rank set, and a catalogue extension no consumer can see. Null when the rank has no image, and also when the sim\'s rank set cannot be resolved (typically a set from another genre): null rather than a URL that would 404.'),
				),
				'required' => array('id', 'name', 'display'),
			),
			'RankList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Rank')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'PositionList' => array(
				'type' => 'object',
				'properties' => array(
					'data'     => array('type' => 'array', 'items' => array('$ref' => '#/components/schemas/Position')),
					'page'     => array('type' => 'integer'),
					'per_page' => array('type' => 'integer'),
					'total'    => array('type' => 'integer'),
					'meta'     => array('$ref' => '#/components/schemas/PaginationMeta'),
				),
				'required' => array('data', 'page', 'per_page', 'total', 'meta'),
			),
			'Snapshot' => array(
				'type' => 'object',
				'description' => 'Public per-sim aggregate for Astrolabe. All URLs are absolute https or null; text fields are plain text (HTML flattened, block boundaries preserved as spaces) and capped.',
				'properties' => array(
					'version'      => array('type' => 'integer', 'description' => 'Contract version. Always 1 for this shape.'),
					'generated_at' => array('type' => 'string', 'format' => 'date-time', 'description' => 'ISO 8601 UTC time the snapshot was built.'),
					'game'         => array('type' => 'object', 'description' => 'Keys: name, url (absolute https), description (always null - Astrolabe owns the blurb).'),
					'stats'        => array('type' => 'object', 'description' => 'Keys: players (active accounts), characters (active + NPC), stories (mission count).'),
					'manifest'     => array('type' => 'array', 'description' => 'Roster groups. Each: {name, slug, departments:[{department, characters:[{name, position, avatar_url, url, rank:{name,abbreviation,image}, player:{name, discord_id}}]}]}. player.discord_id (v1.32.0+) is the linked public Discord ID when Discord Sign-In is on and the player linked, else null. Active players + NPCs. May be [].'),
					'stories'        => array('type' => 'array', 'description' => 'Missions. Each: {id, title, description, status, start_date, end_date (both null - Nova has no in-character dates), posts_count, url}. id (v1.36.0+) is the Nova mission id - pass it back as mission_id on GET /posts to read that mission\'s archive; posts_count counts the same publicly visible set. No slug key: Nova has no mission slug. May be [].'),
					'recent_posts'   => array('type' => 'array', 'description' => 'Up to 10 most recent activated posts, newest first. Each: {title, authors:[names], published_at, excerpt, url}. May be [].'),
					'open_positions' => array('type' => 'array', 'description' => 'ALL open positions the sim is recruiting for (Nova open positions, pos_open > 0 - not just the featured list). Each: {name, department, openings, top (bool - featured/top-open), description, url}. Always present; may be [].'),
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
