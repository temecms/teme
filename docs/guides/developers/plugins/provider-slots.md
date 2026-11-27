---
title: Provider slots
type: how-to
audience: plugin
summary: How a plugin fills a provider slot, a service that needs exactly one active provider, and how the administrator picks which one.
rests_on: [app/Domain/Plugins/Slots, app/Domain/Plugins/Operations/ChooseSlotProvider.php, app/Domain/Plugins/Contributions/ContributionsValidator.php, packages/teme-contracts/schema/teme-plugin.schema.json]
---

# Provider slots

Some services need exactly one active provider: the image service, and later search and comment checks. Core declares each of these as a slot. A plugin says which slots it fills, and the administrator picks one plugin per slot. Core's own provider stays the default where core has one.

## Declaring it

The plugin lists the slots it fills under `provides`, by name, at most three:

```json
"provides": ["image-service"]
```

The plugin's class must implement the contract of each slot it names. The image service slot's contract is `HasImageService`, and the plugin must also declare its `imageService`. A plugin that names a slot Teme doesn't offer yet is refused when it is enabled.

## Which provider is used

- The plugin the administrator chose for the slot, while it is enabled and still provides the slot.
- Otherwise, the one enabled plugin that provides the slot, when it is the only one.
- Otherwise, none: core's default stands.

Turning a plugin off clears any choice of it, and the pages it drew are rebuilt on their next visit.
