## Records (the builder's)

### Commits

- `063f014` test(plugins): cover relative paths and skipped-load reason
- `52480e1` fix(plugins): resolve relative plugin and theme paths from base_path
- `75e4158` docs(plans): plugin-paths-relative's records

### Tests seen failing

- `DiscoveryTest` “finds a relative plugin_paths entry when the working directory is public/”: failed with `null` (path not found under `public/` cwd) before the fix; passed after.
- `ThemeRegistryTest` “finds a relative theme_paths entry when the working directory is public/”: failed with null before the fix; passed after.
- `PluginsScreenTest` “shows the reason an enabled plugin was skipped at load”: already passed before the path fix (PluginList already surfaces `LoadedPlugins` failure text); kept as the brief’s screen-reason coverage.

### Gate (`composer check`)

- Pint: passed
- PHPStan: no errors
- `types:check` / `vp check`: passed (451 formatted, 435 lint clean)
- Architecture: **139 passed** (1358 assertions)
- Unit + Feature (parallel): **11714 passed**, **2 skipped** (60350 assertions)
- No Browser suite: no screen change beyond existing reason text.

### B-?? (draft)

None.
