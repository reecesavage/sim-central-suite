# Sim Central Suite v1.14.3 &mdash; Display Name helper text

Tiny copy change. The **Display Name** field now carries a line of helper text explaining what it's for, on every form where it appears (the bio edit page, the admin character-create page, and the join flow).

## What's new

Under the Display Name input:

> If you want a different name shown on the manifest than the First / Middle / Last / Suffix fields above, write the **full name** here exactly as it should appear &mdash; including a surname, not just a first name or nickname. Leave blank to use the fields above.

## Why

Some writers were entering just a first name or a nickname in the Display Name field, which then replaced their full name on the manifest (Display Name overrides the First/Middle/Last/Suffix fields wholesale). The new note makes it clear the field expects the *complete* name as it should appear, and that leaving it blank falls back to the structured name fields.

## Implementation notes

- `views/main/pages/display_name_form.php` &mdash; added the helper `<span>` beneath the input. This single view is shared by all three injection points (`characters_bio`, `characters_create`, `main_join_2`), so the text appears consistently everywhere the field renders.

No behaviour, settings, or schema changes &mdash; the field works exactly as before; it's just better explained.

## Upgrade

Use the **Update Now** button on the dashboard. No database changes.

## Credits

Same as v1.14.2. MIT licensed.

Issues: <https://github.com/reecesavage/sim-central-suite/issues>
Chat: [Sim Central on Discord](https://discord.gg/simcentral)
