# WP Rewrite Rules Viewer

Lists every WordPress rewrite rule in matching order, tells you which rule handles any URL, and flushes the rules with one click — the fastest way to debug "why does this URL 404?" and "why does my custom post type redirect to the wrong page?"

## Features

- **Full rule list** — every rule from `WP_Rewrite`, in the order WordPress tries them (first match wins), with its query string and source (`Top (extra)`, `Extra`, or `Standard`). Paginated at 100/page, with a live filter box.
- **URL tester** — enter a path like `2026/11/hello-world/` and see the matched rule, the resulting query string after `$matches[N]` substitution, and the parsed query vars as JSON. No match means WordPress would serve a 404.
- **Flush button** — regenerates the rewrite rules (soft flush, no `.htaccess` rewrite) when a plugin or theme forgot to flush after registering a post type or taxonomy.

## Notes

- The tester matches exactly the way `WP::parse_request()` does: `preg_match( '#^' . $regex . '#', $request )`, first match wins, `$matches[10]` replaced before `$matches[1]` so multi-digit captures stay intact.
- On subdirectory installs the home path prefix is stripped before testing, so you can paste a full path.
- Stores nothing — no options, no tables.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
