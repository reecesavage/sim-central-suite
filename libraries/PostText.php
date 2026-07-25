<?php

namespace nova_ext_sim_central;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Rich text -> plain text (v1.36.1).
 *
 * ONE definition of "flatten this HTML to something a human reads", shared by
 * every surface that publishes prose without markup: the Astrolabe snapshot's
 * story descriptions, position descriptions and post excerpts, and the REST
 * API's posts[].excerpt.
 *
 * Loaded unconditionally (init.php, before PostWordCount) rather than living on
 * AstrolabeSnapshot, which init.php only requires when the REST API feature is
 * on. As of v1.37.0 every text-flattening surface in the suite goes through here
 * - the snapshot's descriptions and excerpts, the REST API's post excerpt, the
 * word count, the Discord webhook body, and the mobile blurbs - and those sit
 * behind different feature toggles, so this can't live on a feature-gated class.
 *
 * Why not strip_tags(). Four reasons, all of them things a reader saw:
 *
 *   1. It deletes a tag without leaving anything behind, and a block boundary IS
 *      a word boundary - so "</h2><p>" fused the words either side into
 *      "TROUBLESHOOTERSSeptember". Block-level tags become a space here; inline
 *      tags still become nothing, so "<em>un</em><em>breakable</em>" stays
 *      "unbreakable".
 *   2. It treats "<" as a tag opener followed by anything at all and eats to the
 *      next ">" - or to the end of the string if there isn't one. A description
 *      reading "the fee is <20 credits" lost every character from "<20" onward.
 *   3. It removes a tag but keeps its CONTENT, so a stray <script> or <style>
 *      leaked JavaScript or CSS source into a public field.
 *   4. Truncating the result by BYTES could cut a multi-byte character in half.
 *      The invalid UTF-8 then made json_encode() fail for the whole response,
 *      not just the one field. See truncate().
 *
 * ---
 *
 * The tag passes run TWICE, either side of entity decoding, and they are
 * deliberately not equally trusting - because the two inputs have different
 * provenance:
 *
 *   Pass 1 runs on the STORED string, where anything tag-shaped is markup the
 *   author's editor emitted. A browser treats even an unknown element as an
 *   element, so this pass removes any tag-shaped thing.
 *
 *   Pass 2 runs AFTER entities are decoded, so that markup stored escaped
 *   ("&lt;p&gt;") is removed as the markup it is instead of surfacing to a
 *   reader as a literal tag. It is restricted two ways, and both are load-bearing:
 *
 *     - It only removes KNOWN HTML element names, which keeps "the manifest uses
 *       Map&lt;name,rank&gt;" intact - <name,rank> is not an element.
 *     - It only removes delimiters that DECODING PRODUCED. Both the "<" and the
 *       ">" have to have come from entities. Anything the author typed is parked
 *       out of reach first, so pass 2 cannot strip it even in principle.
 *
 *   The second rule exists because provenance cannot be inferred from the shape
 *   of the finished string. Nova escapes a typed "<" to "&lt;" only when no ">"
 *   follows it (CI_Security), and that decision is taken at WRITE time - but a
 *   later edit through Nova's unfiltered mission-edit form can append a ">" to
 *   the stored value. "see &lt;b for details" then becomes
 *   "see &lt;b for details> ok", which reads back as a terminated <b> tag and
 *   used to have "b for details" deleted from it. The delimiters, not the
 *   result, are what carry the provenance.
 *
 * In both passes a "<" only opens a tag when a letter or "/" follows it, which
 * is the HTML5 tokenizer's own rule (the same rule PostWrite applies to mobile
 * saves), so "I <3 you", "5 <= 6" and "a < b" survive as the prose they are.
 *
 * Residual, accepted costs, both narrower than the bugs they replace:
 *   - Prose that discusses a real element by name in escaped form ("use
 *     &lt;p&gt; for paragraphs") still loses that tag. Escaped markup is far
 *     commoner than prose about HTML, and only real element names are affected.
 *   - A genuinely unclosed <script>/<style> in STORED markup swallows the rest
 *     of the field, which is what a browser does with it too. Prose that merely
 *     mentions those names does not, because pass 2 never does this.
 */
class PostText
{
	/**
	 * Default excerpt cap, in characters. One number for every surface that
	 * publishes an excerpt, so the snapshot's and the REST API's agree by
	 * construction rather than by two matching literals.
	 */
	const CAP_EXCERPT = 300;

	/**
	 * How much raw input excerpt() will flatten, in bytes.
	 *
	 * A 300-character excerpt cannot need more than this: it is over 50x the cap,
	 * so a body would have to be 16KB of almost pure markup before the excerpt
	 * came up short. Bounding it means a hostile 64KB body - Nova's TEXT ceiling
	 * - can't turn one list request into seconds of regex. flatten() itself is
	 * unbounded, for callers that genuinely want the whole string.
	 */
	const SCAN_LIMIT = 16384;

	/**
	 * Elements that end a line of prose: each becomes ONE space. Matched on the
	 * full tag name, so neither a longer name that merely starts with a block
	 * name (<preamble>, <header> vs <head>) nor a custom element (<p-tag>) is
	 * mistaken for one. Includes the legacy block elements that dominate older
	 * Nova WYSIWYG content - <center> above all, which is how pre-HTML5 editors
	 * centred story headers and scene breaks.
	 */
	private static $block = array(
		'address', 'article', 'aside', 'blockquote', 'body', 'br', 'caption',
		'center', 'col', 'colgroup', 'dd', 'details', 'dialog', 'dir', 'div',
		'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form',
		'frame', 'frameset', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head',
		'header', 'hgroup', 'hr', 'html', 'legend', 'li', 'listing', 'main',
		'marquee', 'menu', 'nav', 'noframes', 'ol', 'optgroup', 'option', 'p',
		'plaintext', 'pre', 'section', 'select', 'summary', 'table', 'tbody',
		'td', 'textarea', 'tfoot', 'th', 'thead', 'tr', 'ul', 'xmp',
	);

	/**
	 * Known inline elements: removed leaving nothing, so adjacent words stay
	 * joined. Only used to decide whether a DECODED "<name>" was markup or
	 * prose; unknown names in decoded text are left alone as prose.
	 */
	private static $inline = array(
		'a', 'abbr', 'acronym', 'applet', 'area', 'audio', 'b', 'base',
		'basefont', 'bdi', 'bdo', 'big', 'blink', 'button', 'cite', 'code',
		'data', 'datalist', 'del', 'dfn', 'em', 'embed', 'font', 'i', 'img',
		'input', 'ins', 'kbd', 'label', 'link', 'map', 'mark', 'meta', 'meter',
		'nobr', 'output', 'param', 'picture', 'progress', 'q', 'rp', 'rt',
		'ruby', 's', 'samp', 'slot', 'small', 'source', 'spacer', 'span',
		'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var', 'video',
		'wbr',
	);

	/**
	 * Elements whose CONTENT is not prose: dropped tag and contents together, so
	 * script or CSS source never reaches a reader.
	 */
	private static $opaque = array(
		'script', 'style', 'template', 'svg', 'math', 'iframe', 'noscript',
		'object', 'title',
	);

	/**
	 * Attribute-aware tail of a tag: a quoted value is consumed whole, so a ">"
	 * inside one ( <p title="a>b"> ) can't end the tag early.
	 *
	 * An unquoted run stops at "<", so a failed attempt can't scan past the next
	 * tag start - that plus the input bound in excerpt() is what keeps hostile
	 * bodies cheap. A quoted value may still contain "<", since that is legal and
	 * the alternative would leak the tag as visible text.
	 */
	private static $tail = '(?:"[^"]*+"|\'[^\']*+\'|[^>"\'<])*+';

	/**
	 * Flatten rich text to plain text. Returns '' when there is no text at all;
	 * never returns null.
	 *
	 * $sep is what a BLOCK BOUNDARY becomes. The default ' ' gives the
	 * single-line form every caller wanted until v1.37.0; the Discord webhook
	 * body passes "\n\n" because its truncation picks the last paragraph break
	 * inside the budget, and with spaces there would never be one. Only
	 * whitespace separators are supported - the collapse below and the closing
	 * trim() both assume it.
	 */
	public static function flatten($html, $sep = ' ')
	{
		self::$failed = false;

		// A separator containing a newline switches the collapse at the bottom to
		// a line-preserving form. Decided once, here, so the closure that emits
		// the separator and the collapse that normalises it cannot disagree.
		$vertical = (strpos($sep, "\n") !== false);

		$original = (string) $html;
		$s = self::utf8($original);


		// NUL and the other C0 controls are invisible and not separators (\t \n
		// \r \v \f are left for the whitespace collapse). strip_tags dropped NUL
		// silently; without this one would reach a public field as  .
		$s = self::rx('/[\x00-\x08\x0E-\x1F\x7F]+/', '', $s);

		// No markup and no entities: skip the tag machinery entirely. The common
		// case on real sims, where paragraph breaks are stored as raw newlines
		// rather than as <p> tags.
		if (strpos($s, '<') !== false || strpos($s, '&') !== false) {
			// --- pass 1: the stored string, where tag-shaped means markup ---
			$s = self::removeMarkup($s, true, self::namePattern(true), $sep);

			// Mark the "<" and ">" that are about to be PRODUCED by decoding, so
			// pass 2 can tell them from ones the author typed. Each candidate is
			// decoded individually rather than matched by a hand-written pattern,
			// so this can never disagree with html_entity_decode about what is a
			// reference (it wants the semicolon: "&lt" decodes to itself).
			$s = self::rx_callback('/&(?:[a-zA-Z][a-zA-Z0-9]*|#[0-9]+|#[xX][0-9a-fA-F]+);/', function ($m) {
				$d = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if ($d === '<') { return "\x01"; }
				if ($d === '>') { return "\x02"; }
				return $m[0];
			}, $s);

			// Park every delimiter the author typed. Pass 1 already took anything
			// tag-shaped, so what is left is prose - and parking it means pass 2
			// structurally CANNOT strip it, rather than merely declining to.
			$s = str_replace(array('<', '>'), array("\x03", "\x04"), $s);
			$s = str_replace(array("\x01", "\x02"), array('<', '>'), $s);

			// Decode the rest. Nothing left can yield a "<" or ">".
			// html_entity_decode is not recursive, so "&amp;lt;" stays "&lt;" -
			// double-escaped text isn't unwrapped twice.
			$s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

			// --- pass 2: what was stored ESCAPED, and only real element names ---
			$s = self::removeMarkup($s, false, self::namePattern(false), $sep);

			// Give the author's own delimiters back.
			$s = str_replace(array("\x03", "\x04"), array('<', '>'), $s);
		}

		// Zero-width space and BOM are invisible and are NOT word separators, so
		// they're deleted rather than collapsed to a space. ZWJ (U+200D) and ZWNJ
		// (U+200C) are deliberately kept: the first holds multi-codepoint emoji
		// together, the second is a meaningful letter-joining control in Persian,
		// Arabic and several Indic scripts.
		$s = self::rx('/[\x{200B}\x{FEFF}]+/u', '', $s);

		// Collapse last, so runs of adjacent block tags leave one separator rather
		// than a gap, and a caller's cap is then spent on real characters of
		// prose. \s under /u already covers NBSP; the explicit list adds the
		// other Unicode spaces PCRE doesn't fold in.
		if ($vertical) {
			// A vertical separator has to survive the collapse, so this arm folds
			// HORIZONTAL whitespace only and normalises line breaks separately.
			// The single \s+ pass in the else arm would fold "\n\n" straight back
			// to a space and undo the separator the caller asked for.
			$s = self::rx('/\r\n?/', "\n", $s);        // CRLF and a lone CR
			$s = self::rx('/[^\S\n]+/u', ' ', $s);     // spaces, tabs, NBSP - not \n
			$s = self::rx('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $s);
			$s = self::rx('/ *\n */', "\n", $s);       // no padding around a break
			$s = self::rx('/\n{3,}/', "\n\n", $s);     // at most one blank line
		} else {
			$s = self::rx('/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $s);
		}

		// A pattern failed somewhere above, so this string is only partly
		// flattened and may still hold markup. Fail closed.
		if (self::$failed) {
			return self::safeFallback($original);
		}

		return trim($s);
	}

	/**
	 * One removal pass. $trusted marks the stored-markup pass, which may consume
	 * an unclosed opaque element to the end of the input the way a browser does;
	 * the decoded pass never destroys that much text on the strength of a word a
	 * human typed.
	 */
	private static function removeMarkup($s, $trusted, $namePattern, $sep = ' ')
	{
		$opaque = implode('|', self::$opaque);

		// Comments first: a commented-out "<script>" is not live markup, and
		// removing opaque elements before comments let "<!-- <script src=x> -->"
		// empty the entire field.
		$s = self::rx('#<!--.*?(?:-->|$)#su', '', $s);

		// Opaque elements with their contents. The permissive second form catches
		// an open tag holding an unbalanced quote, which the quote-aware tail
		// cannot cross.
		$s = self::rx('#<('.$opaque.')(?![a-zA-Z0-9:._-])'.self::$tail.'>.*?</\1\s*>#isu', '', $s);
		$s = self::rx('#<('.$opaque.')(?![a-zA-Z0-9:._-])[^>]*>.*?</\1\s*>#isu', '', $s);
		if ($trusted) {
			// Unclosed <script>/<style> in real markup: a browser swallows the
			// rest of the document, so its source never becomes visible prose.
			$s = self::rx('#<(script|style)(?![a-zA-Z0-9:._-])[^>]*>.*$#isu', '', $s);
		}

		// Doctype, processing instruction, CDATA, and the empty end tag "</>" -
		// all render as nothing and none is a word boundary.
		$s = self::rx('#<[!?][^>]*>#u', '', $s);
		$s = self::rx('#</>#', '', $s);

		// Tags. Quote-aware first; then a permissive form for a tag holding an
		// unbalanced quote, which would otherwise be copied verbatim into a field
		// documented as plain text - "<script src="/a.js?v=1>" and everything
		// after it used to survive as raw markup.
		//
		// Re-scanned until stable, because deleting one tag can join its
		// neighbours into a new one ("<<em>img src=x onerror=...>").
		// Quote-aware first: an attribute value containing ">" can't end the tag
		// early. Its unquoted run stops at "<", which is what makes a FAILED
		// attempt cheap instead of scanning to the end of a 64KB body.
		$quoteAware = '#</?('.$namePattern.')'.self::$tail.'>#u';

		// Then a permissive form, for a tag holding an unbalanced quote - which
		// would otherwise be copied verbatim into a field documented as plain
		// text. This one MAY cross "<", because a browser reads "<a<b<c>" as one
		// start tag whose attribute names happen to contain "<". Only run when a
		// ">" exists: with none, no tag can match anyway, and scanning for one
		// from every "<" is what made hostile bodies quadratic.
		$permissive = '#</?('.$namePattern.')[^>]*>#u';

		$replace = function ($m) use ($sep) {
			return in_array(strtolower($m[1]), self::$block, true) ? $sep : '';
		};

		for ($i = 0; $i < 4; $i++) {
			$before = $s;
			$s = self::rx_callback($quoteAware, $replace, $s);
			if (strpos($s, '>') !== false) {
				$s = self::rx_callback($permissive, $replace, $s);
			}
			// A tag start immediately followed by another one, with no ">" to
			// terminate it: "<a<a<a…". Never prose in any language, so it is safe
			// to drop in both passes - unlike a lone trailing "<b", which really
			// can be someone writing "see <b for details".
			$s = self::rx_callback('#</?('.$namePattern.')(?=<)#u', $replace, $s);
			if ($trusted) {
				// A start tag with no ">" at all, at the very end of the input: a
				// clipped body (a long Word paste truncated at the TEXT column
				// boundary mid-tag) rather than prose. A browser drops it too.
				// Only in this pass - in decoded text "see <b for details" is prose.
				//
				// INSIDE the loop, because removing a tag can consume the ">" that
				// was terminating an earlier fragment and leave a fresh unterminated
				// one behind. That is how "<a<b<c \"d" survived into a public field:
				// the quote-aware pass matched from the second "<a" onward (even
				// quote parity) and took the trailing ">" with it.
				//
				// Guarded on there actually being an unterminated tail (the last "<"
				// after the last ">"), so the scan runs once when it can succeed
				// rather than from every "<" in the body.
				$lastLt = strrpos($s, '<');
				$lastGt = strrpos($s, '>');
				if ($lastLt !== false && ($lastGt === false || $lastLt > $lastGt)) {
					$s = self::rx('#<[a-zA-Z][^>]*$#su', '', $s);
				}
			}
			if ($s === $before) {
				break;
			}
		}

		return $s;
	}

	/**
	 * Which names count as a tag in this pass. The stored pass accepts any
	 * name-shaped token (a browser treats an unknown element as an element); the
	 * decoded pass accepts only known HTML element names, so a human's
	 * "Map<name,rank>" stays prose.
	 */
	private static function namePattern($any)
	{
		if ($any) {
			return '[a-zA-Z][a-zA-Z0-9:._-]*';
		}
		static $known = null;
		if ($known === null) {
			$names = array_merge(self::$block, self::$inline, self::$opaque);
			// Longest first, so "h1" can't be shadowed by a shorter alternative,
			// and anchored against a longer name by the lookahead.
			usort($names, function ($a, $b) { return strlen($b) - strlen($a); });
			$known = '(?:'.implode('|', $names).')(?![a-zA-Z0-9:._-])';
		}
		return $known;
	}

	/**
	 * Flatten, then cap to $cap CHARACTERS (the ellipsis counts toward the cap,
	 * so the result is never longer than $cap). Returns null when there is no
	 * text - the snapshot contract is "null for a missing single value".
	 *
	 * Capping happens after flattening, so the budget is spent on prose rather
	 * than on markup or on words fused together by a bad flatten.
	 */
	public static function excerpt($html, $cap = self::CAP_EXCERPT)
	{
		$html = (string) $html;

		// Only ever flatten as much as an excerpt could possibly need. Cutting
		// the RAW string is safe here because the result is capped anyway; it
		// bounds the work a pathological body can cause. Cut on a character
		// boundary so the /u passes still see valid UTF-8.
		if (isset($html[self::SCAN_LIMIT])) {
			$html = self::cutToScanLimit($html);
		}

		$text = self::flatten($html);
		if ($text === '') {
			return null;
		}
		return self::truncate($text, (int) $cap);
	}

	/**
	 * Cut raw input down to SCAN_LIMIT, ending on a boundary that can't leave a
	 * half-formed tag behind.
	 *
	 * A blind byte cut can land inside a tag - or inside its ESCAPED form, so
	 * that "&lt;img src=x onerror=alert(1)&gt;" arrives as
	 * "&lt;img src=x onerror=alert(1)" and decodes into an unterminated tag. The
	 * decoded pass deliberately won't remove one of those, because in an author's
	 * own words "see <b for details" is prose - but this one isn't the author's,
	 * it is an artefact of our own truncation. So end the cut at the last point
	 * that cannot be mid-tag: a ">", an escaped "&gt;", or whitespace.
	 */
	private static function cutToScanLimit($html)
	{
		$cut = substr($html, 0, self::SCAN_LIMIT);

		$floor = (int) (self::SCAN_LIMIT * 0.75);

		// A tag CLOSER first, and only then whitespace. Whitespace is a safe
		// boundary between tags but not inside one - "<img src=x onerror=..."
		// is full of it - so preferring it would reintroduce the very fragment
		// this method exists to avoid.
		$safe = -1;
		foreach (array('>', '&gt;') as $needle) {
			$at = strrpos($cut, $needle);
			if ($at !== false && $at + strlen($needle) > $safe) {
				$safe = $at + strlen($needle);
			}
		}
		if ($safe <= $floor) {
			// No usable closer: the tail is prose rather than markup, so a word
			// boundary is the right place to stop.
			$safe = -1;
			foreach (array(' ', "\n", "\t", "\r") as $needle) {
				$at = strrpos($cut, $needle);
				if ($at !== false && $at + 1 > $safe) {
					$safe = $at + 1;
				}
			}
		}
		if ($safe > $floor) {
			$cut = substr($cut, 0, $safe);
		}

		// Belt and braces for the no-boundary case: drop a trailing partial
		// entity and a trailing unterminated tag, neither of which can be
		// something the author wrote - we made them by cutting.
		foreach (array('/&[a-zA-Z#][a-zA-Z0-9]*$/', '/<[^>]*$/s') as $partial) {
			$trimmed = preg_replace($partial, '', $cut);
			if ($trimmed !== null) {
				$cut = $trimmed;
			}
		}

		// Never hand a /u pattern a split multi-byte sequence.
		if (preg_match('/^.*/us', $cut, $m) === 1) {
			$cut = $m[0];
		}
		return $cut;
	}

	/**
	 * Hard character cap with a trailing ellipsis.
	 *
	 * Counts CHARACTERS, never bytes. The previous byte-length branch fired for
	 * any text whose UTF-8 encoding was longer than its character count - i.e.
	 * all non-ASCII - and cut mid-sequence, yielding invalid UTF-8. That made
	 * json_encode() fail for the ENTIRE response, not just the one field: the
	 * snapshot degraded to {"error":"encode_failed"} and a REST list returned an
	 * empty body. A 210-character Japanese description at cap 300 hit it.
	 */
	private static function truncate($text, $cap)
	{
		if ($cap < 1) {
			return '';
		}

		if (function_exists('mb_strlen')) {
			if (mb_strlen($text, 'UTF-8') <= $cap) {
				return $text;
			}
			return rtrim(mb_substr($text, 0, $cap - 1, 'UTF-8')).'…';
		}

		// No mbstring: still count codepoints, not bytes. /u makes "." one
		// character, so this cannot split a multi-byte sequence.
		if (preg_match('/^.{0,'.($cap - 1).'}/us', $text, $m) === 1 && $m[0] !== $text) {
			return rtrim($m[0]).'…';
		}
		return $text;
	}

	/**
	 * Guarantee valid UTF-8 before any /u pattern runs. A /u pattern returns
	 * NULL on malformed input, and rx() then hands back the subject unchanged -
	 * so without this, one bad byte meant NOTHING was stripped and raw markup
	 * reached the output (and a Latin-1 "Caf\xE9 latte" came back as null before
	 * rx() existed).
	 *
	 * Deliberately does not depend on mbstring: preg_match('//u') is itself a
	 * UTF-8 validity test, so the check works even where mb_* is unavailable.
	 */
	private static function utf8($s)
	{
		if ($s === '' || preg_match('//u', $s) === 1) {
			return $s;
		}
		// Windows-1252 is the realistic non-UTF-8 case for legacy Nova data and
		// is a superset of Latin-1 across the printable range.
		if (function_exists('mb_convert_encoding')) {
			$out = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
			if (is_string($out) && preg_match('//u', $out) === 1) {
				return $out;
			}
		}
		if (function_exists('iconv')) {
			$out = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
			if (is_string($out) && preg_match('//u', $out) === 1) {
				return $out;
			}
		}
		// Last resort: drop the bytes that can't be UTF-8 so the /u passes still
		// run. Losing an accent beats leaking unflattened HTML.
		$out = preg_replace('/[\x80-\xFF]/', '', $s);
		return ($out === null) ? '' : $out;
	}

	/**
	 * Set when any pattern in the current flatten() failed, so the result is
	 * known-incomplete and must not be published as-is.
	 */
	private static $failed = false;

	/**
	 * preg_replace that can't turn an excerpt into null on a PCRE failure (a
	 * backtrack or JIT limit on a pathological body). It returns the subject so
	 * the remaining passes can still run - but it also RECORDS the failure, and
	 * flatten() then discards the half-processed string in favour of a
	 * conservative fallback.
	 *
	 * That distinction matters: handing back the subject and publishing it would
	 * fail OPEN, emitting raw HTML - the one thing these fields must never carry.
	 */
	private static function rx($pattern, $replacement, $subject)
	{
		$out = preg_replace($pattern, $replacement, $subject);
		if ($out === null) {
			self::$failed = true;
			return $subject;
		}
		return $out;
	}

	/** Same null-safety, and the same failure record, for the callback form. */
	private static function rx_callback($pattern, $callback, $subject)
	{
		$out = preg_replace_callback($pattern, $callback, $subject);
		if ($out === null) {
			self::$failed = true;
			return $subject;
		}
		return $out;
	}

	/**
	 * Conservative flatten for when a pattern failed: the pre-v1.36.1 behaviour,
	 * which is lossier (a bare "<" still eats to the next ">") but cannot leak a
	 * tag. Belt-and-braces, any surviving "<" that could read as a tag start is
	 * dropped, so the result is plain text whatever PCRE did.
	 */
	private static function safeFallback($original)
	{
		$t = strip_tags($original);
		$t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$t = strip_tags($t);                       // again: decoding can expose markup
		$collapsed = preg_replace('/\s+/u', ' ', $t);
		if ($collapsed === null) {
			$collapsed = preg_replace('/\s+/', ' ', $t);
		}
		if ($collapsed === null) {
			$collapsed = $t;
		}
		$stripped = preg_replace('/<(?=[a-zA-Z\/])/', '', $collapsed);
		return trim($stripped === null ? $collapsed : $stripped);
	}
}
