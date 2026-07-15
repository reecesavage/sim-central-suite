# Sim Central Suite v1.29.0 — Private messages on the mobile site

The Mobile Site gains **direct messages**, so members can keep up with their inbox from a phone instead of wrestling Nova's desktop messaging views.

## What's new

### Messages on `/mobile`

A **Messages** tab appears in the mobile nav (with an unread-count badge). From it a member can:

- **Read the inbox** — newest first, with a "new" badge on unread messages. Opening a message marks it read (and drops the badge count).
- **Reply** to a message with the subject and recipient pre-filled.
- **Compose** a new message to any active member, with a checkbox recipient picker.
- **Sent** — review messages they've sent.
- **Delete** — soft-delete from the inbox or from Sent (per-side, exactly like the desktop site: deleting your copy doesn't remove anyone else's).

### Built on Nova's own messaging

It reuses Nova's private-message system directly — no new tables, no new queries. So a message sent from the mobile site lands in the recipient's **desktop** inbox and vice versa; read/unread and deletions stay in sync across both. It also honours Nova's `messages/index` access control: a role that can't use messaging on the desktop won't see the Messages tab on mobile either.

### Notes

- Message bodies are plain text (matching Nova's desktop messaging), so none of the rich-editor round-trip applies here.
- Delivery is in-app only for now — the mobile compose doesn't send the optional "you have a new message" notification email that the desktop form does. The message still arrives in the inbox and bumps the unread badge.

## Upgrade

Use the **Update Now** button on the dashboard, or `POST /Api/suite`. No database changes, no shims.

## Credits

MIT licensed. Thanks to the folks filing feature requests on GitHub.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
