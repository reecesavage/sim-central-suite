# Sim Central Suite v1.36.2 — hardening the plain-text flattener

Follow-up to v1.36.1. An adversarial review of the new flattener finished after that release went out and found two problems in it. Both are fixed here. **If you are on v1.36.1, take this one.**

No behaviour change for ordinary content: every existing test still passes, and normal posts and descriptions flatten identically to v1.36.1.

## Fixed

**A malformed tag could reach a public field as raw markup.** The quote-aware tag matcher could consume the `>` that was terminating an *earlier* fragment, leaving a new unterminated one behind — and the sweep for that case had already run. A body of the shape `<a<b<c "d…` therefore put a literal `<a<b<c "d` into `stories[].description` and `posts[].excerpt`. The sweep now runs inside the removal loop, so a fragment exposed by one pass is caught by the next. A chain of adjacent tag starts with no terminator (`<a<a<a…`, including the form produced by decoding escaped markup) is also swept now — a tag start immediately followed by another one is never prose.

**A hostile body could take seconds of CPU.** With `pcre.jit=0` — a common hosting configuration, and also PCRE's own fallback when JIT compilation can't proceed — a crafted 64 KB post body (Nova's `TEXT` ceiling) took **7.3 seconds** to flatten, because a failed match attempt at every `<` scanned to the end of the string. On a list of posts that is minutes of CPU, and well past the 15-second read timeout an external consumer uses.

Three changes bound it:

- An unquoted run inside a tag now stops at `<`, so a failed attempt is cheap instead of scanning the whole body.
- The permissive pass only runs when a `>` actually exists (with none, no tag can match anyway), and the end-of-input sweep only when there really is an unterminated tail.
- `excerpt()` flattens at most the first 16 KB of a body. That is over 50× the 300-character cap, so a real excerpt can't notice; it just stops a pathological body from doing unbounded work. `flatten()` itself is still unbounded for callers that want the whole string.

Worst case across `pcre.jit=1`, `jit=0`, and a reduced backtrack limit is now **under 3 ms**, from 7316 ms — and no shape leaks markup in any of those configurations.

**Bodies with no markup skip the tag machinery entirely.** A measurable win, since on real sims paragraph breaks are usually stored as raw newlines rather than as `<p>` tags.

## Hardened

**PCRE failure now fails closed.** If a pattern ever fails (a backtrack or JIT-stack limit on a pathological body), the previous code handed back the string it was given — which, mid-flatten, can still contain markup. That is a security control failing *open*: raw HTML into a field documented as plain text. A failure is now recorded, and the whole field falls back to a conservative flatten (the pre-v1.36.1 behaviour, which is lossier but cannot leak a tag), with any remaining `<` that could read as a tag start removed.

I could not trigger such a failure at 64 KB in any configuration tested; this closes the path regardless.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no configuration changes.

## Credits

MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
