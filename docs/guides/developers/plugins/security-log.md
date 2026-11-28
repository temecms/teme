---
title: Writing lines to the security log
type: how-to
audience: plugin
summary: How a plugin with the security.log capability writes lines of the kinds it declares into the site's security log under its own name, the limits on them, and how a person's lines reach their data export.
rests_on: [packages/teme-contracts/src/Security/SecurityLines.php, packages/teme-contracts/src/Plugins/Capability.php, app/Domain/Plugins/Doors/PluginSecurityLines.php, app/Domain/Plugins/SecurityLines/DeclaredSecurityKinds.php, app/Domain/Security/SecurityLog.php, app/Domain/Security/Privacy/SecurityLogHolder.php]
---

# Writing lines to the security log

A security plugin, such as one that watches for repeated sign-in attempts, can record what it sees in the site's security log, next to the site's own lines, instead of keeping a log table of its own. It does that with the `SecurityLines` door, which the plugin gets only when it asks for the `security.log` capability.

## What the plugin declares

Each line has a kind, and the kinds are declared in `teme-plugin.json` under `securityLog`, up to 10 of them:

```json
"securityLog": [
  { "id": "blocked", "label": "Blocked sign-in" },
  { "id": "probe", "label": "Probe for an admin page" }
]
```

An id is 2 to 32 lower-case letters, digits and underscores, starting with a letter. A label is 1 to 60 characters and is what the administrator reads when they consent. A plugin that declares kinds without asking for `security.log` is refused when it is installed.

## What the plugin writes

`write($kind, $text, $userId, $email)` adds one line:

- `$kind` is one of the declared ids. Any other kind throws an `InvalidArgumentException` and writes nothing.
- `$text` is 1 to 200 characters, trimmed. Empty text, or text longer than that, throws and writes nothing.
- `$userId` names the account the line is about, when there is one. An account that doesn't exist is left unnamed, and the line is still written.
- `$address` is an IP address the line is about, when there is one. Anything that is not an IP address throws.

The line is stored under one type shared by every plugin, with the plugin's code, its kind and its text in the context, so it sits beside the site's own lines and is pruned on the same schedule, after 365 days.

Never put personal data or secrets in the text. Administrators read it, and a person's data export carries it.

A line written inside a save that is then refused, such as a plugin area's, is rolled back with that save. Lines about a person are kept 365 days after erasure, as the site's own lines are.

## Limits

A plugin may write **60 lines an hour**. The 61st line of an hour is not written, and `write()` returns `false`, so the plugin can stop or wait. Count on the limit: a security plugin that logs every request will hit it, and should log the events that matter.

## Lines in a person's data export

A line that names a person is in that person's data export, under "Security event", with its kind and text, as the site's own lines are. Erasure keeps it for the same reason the site's own lines are kept: the log protects the site.

## What the plugin never does

It cannot read the log, change a line, or write as someone else. It writes only under its own name, and only the kinds it declared.
