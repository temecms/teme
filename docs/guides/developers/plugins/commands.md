---
title: Console commands from a plugin
type: how-to
audience: plugin
summary: How a plugin declares console commands in its manifest and answers them through HasCommands, so a job it schedules can also be run by hand, and what Teme guarantees about when they exist and who runs them.
rests_on: [packages/teme-contracts/src/Console/HasCommands.php, packages/teme-contracts/src/Console/CommandOutput.php, app/Domain/Plugins/Console/DeclaredCommands.php, app/Domain/Plugins/Console/PluginCommand.php, packages/teme-contracts/schema/teme-plugin.schema.json, docs/architecture/plugins.md]
---

# Console commands from a plugin

A plugin that sends shares on a schedule should also let the person at the server's shell send them now. Declare a command in the manifest, answer it in your plugin's class, and it shows up in `php artisan list` for as long as the plugin is enabled.

The contracts are in `teme/contracts` from the release that adds them; their `@since` says which.

## Declare the command

Under `contributes.commands`, list at most ten commands:

```json
"contributes": { "commands": [
  { "name": "sharely:resend", "description": "Resend the shares that failed", "options": [{ "name": "limit", "description": "Resend at most this many", "value": true }] } ] }
```

- `name` starts with your plugin's `code` and a colon, as in `sharely:resend`, then lower-case words of letters, digits and hyphens; it may have up to two more colons for groups. A name that doesn't start with your code, or starts with `teme:`, keeps the plugin from being enabled, A name that is already a command, one of Teme's, Laravel's or another enabled plugin's, also keeps the plugin from being enabled: a plugin never replaces a command.
- `description` is one line of up to 120 characters, as `php artisan list` shows it.
- `arguments` and `options` are optional, at most five of each. An argument is `required` or not. An option is a flag, such as `--dry-run`, or takes a value, such as `--limit=5`, when it says `"value": true`. Their names are lower-case words, and can't be one Teme's own commands already use, such as `help`, `verbose` or `env`.

## Answer the command

Implement `Teme\Contracts\Console\HasCommands` beside `Plugin`:

```php
public function runCommand(string $name, array $arguments, array $options, CommandOutput $output): int
{ $output->info('Sent '.$this->resend((int) $options['limit'])); return 0; }
```

`$name` is the command that was run. `$arguments` holds each declared argument by its name, a string, or null when an optional one wasn't given. `$options` holds each declared option by its name: true or false for a flag, the text given or null for an option that takes a value. They are plain arrays, with no framework type in them.

Write through `$output`: `line()` for plain text, `info()` for something that went well, `warning()` for something to look at, and `error()` for something that failed. What you write is shown as written, never read as formatting. Return the exit code: 0 for success, anything else for failure.

A plugin that declares commands and doesn't implement `HasCommands` doesn't start, and the Plugins page says why. Implementing it without declaring a command adds nothing.

## What Teme guarantees

- The commands exist only while the plugin is enabled and running. Disable it and `php artisan list` stops showing them; a command that was never enabled can't be run.
- The command runs in your plugin as built with its own doors: the capabilities the person agreed to, and no others. Your constructor gets the same things it gets for a web request or a job.
- It runs as the operator at the server's shell, the same as Teme's own `teme:` commands, so what you do through an Operation is allowed as it is for them. Nothing a visitor or a signed-in person can reach runs it.
- If your code throws, Teme prints the first line of the message, reports the failure, and exits with 1. Other commands go on working.

## Test it

Enable your plugin in a test as the testing kit shows, then call it as `php artisan` does: `Artisan::call('sharely:resend', ['service' => 'mastodon', '--limit' => '5'])`, and read `Artisan::output()`. See [Testing a plugin or theme](../testing.md).

Next: [Jobs and schedules](jobs.md), [The manifest](../concepts/manifest.md), and the generated [contracts reference](../../../reference/contracts.md).
