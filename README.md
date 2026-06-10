# Elementor MCP API

WordPress plugin that exposes a REST API + MCP (Model Context Protocol) abilities for AI-driven Elementor page editing.

Build, edit, and manage Elementor pages programmatically — designed to be used by AI agents (Claude, GPT, etc.) or any HTTP client.

> **Fork of [bvisible/elementor-mcp-api](https://github.com/bvisible/elementor-mcp-api)** (GPL-3.0).
> This fork hardens the plugin for unattended AI-driven writes and closes gaps between the REST and MCP surfaces. See [CHANGELOG.md](CHANGELOG.md) for the full list. Highlights since the upstream 1.3.0 base:
> - **Security:** input/tree validation on every write, a media-import path guard (closes a path-traversal / LFI vector), and request-body element-id validation.
> - **Safety:** every page save now snapshots the prior state — a bad write is undoable via `POST /page/{id}/restore`.
> - **MCP parity:** the Abilities surface now matches REST (adds `find-elements`, `patch-elements-bulk`, `restore-page`, `get-kit-globals`) and fixes an `add-element` crash on childless parents.
> - **Design system:** `GET /kit/globals` exposes the Kit's global colors/fonts so generated pages can reference `__globals__` instead of hardcoding inline hex.
> - **Ops:** public `GET /health` probe; a dependency-free PHP test harness (`php tests/run.php`).

## Features

- **Full CRUD** on Elementor pages, elements, and templates
- **Granular element editing** — update a single widget's settings without touching the rest
- **Element operations** — add, remove, duplicate, move elements in the page tree
- **Global kit management** — read/write colors, fonts, and site-wide settings
- **Widget discovery** — list all available widgets and get their control schemas
- **MCP protocol support** — auto-registers 20 abilities when used with [WordPress Abilities API](https://github.com/bvisible/wordpress-abilities-api) + [WordPress MCP Adapter](https://github.com/bvisible/wordpress-mcp-adapter)
- **CSS cache management** — flush Elementor CSS after changes

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Elementor (free or Pro)
- Authentication: WordPress Application Passwords (recommended) or cookie auth, on an **Administrator** account (see [Security](#security))

## Installation

1. Download or clone this repository into `wp-content/plugins/`:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/bvisible/elementor-mcp-api.git
   ```
2. Activate the plugin in WordPress admin
3. Create an Application Password in **Users → Your Profile → Application Passwords**

## API Endpoints

Base URL: `https://your-site.com/wp-json/neoservice/v1`

### Pages

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/pages` | List all Elementor pages |
| GET | `/page/{id}` | Full page data (elements tree) |
| GET | `/page/{id}/structure` | Lightweight structure (IDs, types, hints) |
| PUT | `/page/{id}` | Replace all page data (validated) |
| POST | `/page` | Create a new page |
| POST | `/page/{id}/restore` | Roll back the last save (one level) |
| POST | `/build-page` | Create or update a full page |

### Elements

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/page/{id}/element/{eid}` | Get single element data |
| PATCH | `/page/{id}/element/{eid}` | Update element settings (merge) |
| POST | `/page/{id}/element` | Add new element |
| DELETE | `/page/{id}/element/{eid}` | Remove element |
| POST | `/page/{id}/element/{eid}/duplicate` | Duplicate element |
| POST | `/page/{id}/element/{eid}/move` | Move element to new position |

### Templates

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/templates` | List all templates |
| POST | `/template` | Create template (header, footer, etc.) |

### Global Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/kit` | Get global kit settings |
| PUT | `/kit` | Update global kit settings |
| GET | `/kit/globals` | Global colors + fonts (flat, for `__globals__`) |

### Widgets

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/widgets` | List all registered widgets |
| GET | `/widget/{name}/schema` | Get widget control schema |
| GET | `/widget/{name}/defaults` | Ready-to-use element JSON with defaults |

### Cache

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/flush-css` | Flush Elementor CSS cache |

### Health

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/health` | Public probe: plugin/Elementor versions + active state (no auth) |

## Quick Start

```bash
API="https://your-site.com/wp-json/neoservice/v1"
AUTH="username:your-application-password"

# List pages
curl -s -u "$AUTH" "$API/pages"

# Get page structure (always start here)
curl -s -u "$AUTH" "$API/page/8/structure"

# Update an element's title color
curl -s -X PATCH -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"settings":{"title_color":"#333333"}}' \
  "$API/page/8/element/f8703b57"

# Flush CSS after changes
curl -s -X POST -u "$AUTH" "$API/flush-css"
```

## MCP Integration

This plugin can expose its capabilities via the Model Context Protocol for direct AI agent integration:

1. Install [WordPress Abilities API](https://github.com/bvisible/wordpress-abilities-api)
2. Install [WordPress MCP Adapter](https://github.com/bvisible/wordpress-mcp-adapter)
3. The plugin auto-registers its abilities — no configuration needed. This fork keeps the MCP surface at parity with REST (adds `find-elements`, `patch-elements-bulk`, `restore-page`, `get-kit-globals`).

MCP endpoint: `https://your-site.com/wp-json/mcp/mcp-adapter-default-server`

## Claude Code Skill

This repo includes a ready-to-use [Claude Code](https://claude.com/claude-code) skill in `claude-skill/`. It teaches Claude how to use the API: workflows, element structures, widget settings, layout patterns, and design best practices.

### Install the skill

```bash
cd elementor-mcp-api/
bash claude-skill/install.sh
```

This copies the skill to `~/.claude/skills/elementor-builder/`. Restart Claude Code — then just say "build an Elementor page" and it knows how.

### What the skill provides

- Full API workflow (discover → explore → edit → flush → verify)
- Elementor element JSON structure and common widget settings
- Reusable section patterns (hero, content rows, icon grids, contact forms, photo collages)
- Design best practices (zigzag layouts, background color alternation, responsive rules)
- Critical gotchas (race conditions, CSS cache, flex layout math)

## Important Notes

- **Sequential PATCH calls**: Never run multiple PATCH calls in parallel on the same page. Each PATCH loads, modifies, and saves the full page — parallel calls overwrite each other. Cross-page parallelism is safe.
- **Flush CSS**: Always call `/flush-css` after visual changes — Elementor caches CSS aggressively.
- **Element IDs**: Always provide valid 8-character hex IDs when creating elements.
- **PATCH merges settings**: Only send the settings you want to change, not the full settings object.
- **Saves are snapshotted (single-level undo)**: every write keeps a backup of the prior `_elementor_data`. Undo a bad write with `POST /page/{id}/restore` (or the `restore-page` ability). The backup is **one level deep and only valid until the next write** — two writes in a row overwrite the good backup with the bad intermediate state, so restore immediately after the write you want to undo.

## Security

This is a **write-capable API driven by AI agents**, so it ships with guard rails:

- **Run under an Administrator account.** This API touches site-global config (the Kit, theme-builder templates, raw `_elementor_data` meta) and writes element JSON that can contain inline HTML. Those operations require `manage_options` / `unfiltered_html`, which only administrators hold. Create the Application Password on a **dedicated administrator user** for the integration. (A lower role can still drive per-page edits it owns, but Kit/template/global writes will be refused.)
- **Tiered permissions:**
  - Reads require `read`; per-page reads additionally check `read_post` so drafts/private pages a caller can't see are not leaked.
  - Per-page writes check `edit_post` on the **specific** page (not the blanket `edit_posts`), so one author can't rewrite another's page. Creating pages requires `edit_pages`.
  - **Site-global writes — `update_kit`, `create_template`, and the raw `_elementor_data` meta route — require `manage_options`.** The MCP twins enforce the same checks *inside* each ability (an Ability's permission callback can't see its input), so the MCP door is closed alongside REST.
  - The raw-meta fallback save is gated on `unfiltered_html` to block stored-XSS injection of `<script>` through that path.
- **Tree validation** — every full-page write (`update_page`, `create_page`, `build_page`, `add_element` and their MCP twins) is structurally validated and bounded (max depth 30, max 5000 elements) before it touches the database. Malformed payloads are rejected with a clear error instead of bricking a page.
- **Payload ceiling** — write request bodies above ~4 MB are rejected before JSON decoding.
- **Media path guard** — media import resolves and confirms the source path is inside the WordPress uploads directory and is a real **raster** image. Path traversal (`../`), absolute paths outside uploads, remote URLs, **SVG** (XML/script XSS vector — blocked, as WordPress does by default), and non-image MIME types are rejected. Stage assets in the uploads directory before importing.
- **Element-id validation** — element ids arriving in request bodies are validated (URL routes were already pattern-constrained).

## Testing

A dependency-free PHP test harness covers the plugin's pure-logic surface (validation, media-path guard, element factory):

```bash
php tests/run.php
```

Exit code 0 = all pass. No composer or PHPUnit required. Runtime behaviour that needs a live WordPress + Elementor (data save, kit reads, REST wiring) must be verified on a real install.

## License

GPL-3.0 — see [LICENSE](LICENSE)

This fork preserves the original GPL-3.0 license and attribution to **[bvisible/elementor-mcp-api](https://github.com/bvisible/elementor-mcp-api)**. Improvements in this fork are likewise GPL-3.0.
