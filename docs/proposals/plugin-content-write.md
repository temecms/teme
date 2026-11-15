# Proposal: plugins write content (v1, wave 1, part A)

**Status:** draft for the maintainers, 2026-11-15. Plan: `docs/plans/plugin-content-write.md`.

## Why

Every site moving to Teme in v1 runs plugins that create or change content: an AI article writer saving drafts with a featured image, a meta tool filling SEO fields, a scheduler publishing at set times. Today a plugin can read content and media (`content.read`, `media.read`) and call outside hosts (`http`). It cannot change anything. `docs/architecture/plugins.md` already names the two missing capabilities, `content.write` and `media.write`, and the manifest validator refuses them as "no door yet". This part builds those doors.

## Settled

1. **Two new capabilities, asked for in the manifest and agreed to by an administrator** like the others: `content.write` (create, change, publish and schedule items through Teme's content Operations) and `media.write` (add media, including from an outside address). An update that newly asks for either asks for consent again (the existing consent rule).
2. **A plugin is handed a door, never the models.** When granted, a plugin's constructor may ask for `Teme\Contracts\Content\ContentWriter` and `Teme\Contracts\Media\MediaWriter` (new contracts, pure PHP, no Laravel types: rule 5). Each method calls the existing Operation (`CreateDraft`, `SaveDraft`, `UpdateContentDetails`, `PublishRevision`, `SchedulePublication`, `UploadMedia`) so every rule, hook and after-commit step applies as it does for a person (rule 1).
3. **A plugin always writes on behalf of a person.** Each call names the acting person (the one whose action started the work, carried through a job as today's `JobActor`). The person's own permissions decide, exactly as in the editor; the plugin's capability decides whether it may try at all. A plugin can never do more than both allow. With no person, the call is refused. (Unattended writes from outside come with the management API, a later part.)
4. **What a plugin wrote is recorded.** Each revision a plugin writes records the plugin's code (`content_revisions.via`, a new nullable column), shown in History as "by Ada, through an AI writing plugin".
5. **Media from an outside address** is fetched only from hosts the plugin declared and the administrator agreed to, through the same guarded door as `http` (no private or local addresses, a size cap, the type checked by `UploadMedia`'s own rules).
6. **Refusals are plain.** A refused call throws `Teme\Contracts\Content\WriteRefused` with one sentence (no permission, unknown type, invalid fields, a host not agreed to), and writes nothing.

## Provisional (built as written unless the maintainers decide otherwise)

- One capability, `content.write`, covers drafts, publishing and scheduling; the person's own `publish` permission still decides. (Alternative: a separate `content.publish`.)
- Bodies are Tiptap JSON only. A helper that turns simple HTML or Markdown into a body is later, as its own part.
- Deleting or trashing content through a plugin is later.

## Out

Unattended writes without a person (management API, later); plugin jobs and schedules (part B, built side by side); a plugin's public routes (later part).
