# Changelog

All notable changes to this fork of [bvisible/elementor-mcp-api](https://github.com/bvisible/elementor-mcp-api) are documented here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/); versions are the plugin header version.

## [1.4.4] — 2026-06-10

Template-safety hardening (gap found in the orphan-command review). A theme-builder
template created via `POST /template` used to go in as `publish` with default conditions
`["include/general"]` — i.e. a `header`/`footer` went LIVE site-wide at creation time.

### Security
- **`create_template` defaults to `draft` (REST + MCP ability + data layer).** Publishing
  now requires an explicit `status: "publish"` in the body/input; any other value is
  rejected (REST: 400, ability: `WP_Error invalid_status`). `Elementor_Data::create_template`
  gained an optional `$status = 'draft'` parameter and clamps unknown values to `draft`
  (defense in depth — the raw endpoint can no longer publish by omission).

### Added
- **`DELETE /template/{id}`** (admin-only, `manage_options`) — rollback path for
  `create_template`. Trash by default (restorable); `?force=true` is permanent and also
  unregisters the template from the `elementor_pro_theme_builder_conditions` map (no
  orphaned condition entries). 404 when the post is missing or is not an
  `elementor_library` post — never touches other post types.
- `create_template` REST response now echoes the effective `status` (additive).

### Tests
- New suite `tests/test-template-safety.php` reproducing each gap before the fix:
  draft default, explicit publish, invalid status rejection (REST + ability),
  delete-template trash/permanent/404/wrong-post-type, conditions-map cleanup.
  Stubs gained recording `wp_insert_post` and `get_post`/`wp_trash_post`/`wp_delete_post`.

## [1.4.3] — 2026-06-10

Micro-fix closing the residual LOW finding from the 2026-06-10 @vault re-audit. Additive
allowlist change only; no walk logic or endpoint signature touched.

### Security
- **kses allowlist amplified (LOW).** `Elementor_Data::HTML_SETTING_KEYS` agora cobre
  `tab_content` (tabs/accordion/toggle core), `alert_title`/`alert_description` (alert)
  e `inner_text` (widgets Pro) — keys que carregam HTML e ficavam fora da sanitização
  `wp_kses_post` quando o caller não tem `unfiltered_html` (vetor de stored-XSS).
  Testes novos em `tests/test-kses-gate.php` cobrem `tab_content` (via repeater `tabs`)
  e `alert_description`, nos dois sentidos (strip sem capability, byte-idêntico com).

## [1.4.2] — 2026-06-10

Second security-audit pass (@vault). Three server-side hardening fixes — all additive
(validation/sanitization only); no endpoint or ability signature changed.

### Security
- **Stored-XSS via dead `unfiltered_html` gate — fixed (HIGH).** The capability gate in
  `Elementor_Data::save_page_data()` only existed on the direct-meta fallback, which never
  runs in production (Elementor active → native save path wins), so a caller WITHOUT
  `unfiltered_html` could persist `<script>`/event-handler HTML to visitors. The gate now
  runs at the top of `save_page_data()` for EVERY path: non-privileged callers get
  HTML-carrying settings (`editor`, `html`, `title`, `text`, repeater items, etc.)
  sanitized recursively with `wp_kses_post` (new `kses_widget_html()` helper); callers
  with `unfiltered_html` keep content byte-identical. The old fallback-only hard block
  was removed in favor of the sanitize-always gate.
- **`add_section` skipped validation and the body ceiling — fixed (MEDIUM).** The REST
  `add_section` handler was the only write that called neither `Validator::validate_tree()`
  nor the 4MB raw-body guard. It now runs both before inserting, same contract as
  `add_element` (depth/element-count ceilings, elType allowlist, 413 on oversized body).
- **4MB ceiling now uniform across both surfaces (MEDIUM, DoS).** REST: added the missing
  `reject_if_oversized` guard to `update_element`, `move_element` and `set_column_width`.
  MCP: new `Abilities_Provider::guard_input_size()` applies the same
  `Validator::MAX_BODY_BYTES` ceiling to the JSON-encoded ability input at the top of all
  13 write abilities (the MCP surface has no raw HTTP body, so the REST guard never covered it).

### Tests
- Suite grows 46 → 88 assertions. Three new suites: `test-rest-security.php` (add_section
  ceilings + the 3 previously-unguarded REST writes), `test-abilities-security.php`
  (iterates ALL 13 write abilities against an oversized input), `test-kses-gate.php`
  (script stripped without `unfiltered_html`, content intact with it). `wp-stubs.php`
  extended with REST request/response doubles, capability/meta/option stores and an
  ability-registry collector.

## [1.4.1] — 2026-06-09

Independent security-audit follow-up, applied **before any production install**.
Focuses on stored-XSS surface and capability hardening across both REST and MCP.

### Security (MUST-FIX)
- **SVG stored-XSS — fixed.** Removed `image/svg+xml` from the media-import allowlist
  (now raster-only: jpg/png/gif/webp/avif). SVG is XML that can carry `<script>` and the
  import does a raw `copy()` with no sanitization — it would execute on the site domain.
  WordPress blocks SVG uploads by default for the same reason.
- **Per-post capability checks (REST).** `edit`/`read` permission callbacks now check
  `edit_post`/`read_post` on the **specific** page when the route carries an id (not the
  blanket `edit_posts`), so one Author cannot rewrite or read another user's page/draft.
  Page creation requires `edit_pages`.
- **Per-post capability checks (MCP).** An Ability's `permission_callback` cannot see its
  input, so every write/read ability now re-checks the resolved `post_id` *inside* its
  `execute_callback` (`guard_edit_post` / `guard_read_post` → 403). This closes the MCP
  door that fixing only REST would have left open.
- **Site-global writes require `manage_options`.** `update_kit`, `create_template`, and the
  `register_post_meta` auth callback for `_elementor_data` are administrator-only — they
  are whole-site config, not per-page content. Abilities enforce the same via `guard_admin`.
- **Raw-HTML gate.** The direct-meta fallback save (which bypasses Elementor's own save
  pipeline and could persist inline `<script>`) now requires `unfiltered_html`.
- **Documented Administrator-account requirement** in the README — create the Application
  Password on a dedicated admin user.

### Security / robustness (SHOULD)
- **Draft leak — fixed.** `list_pages` / `list-pages` no longer return non-published pages
  to callers who can't edit them; per-id reads check `read_post`.
- **Field-name consistency.** `build_page` and `import_media` now accept both `source_path`
  (canonical, matches the MCP ability) and the legacy `path`, so neither surface silently
  imports nothing.
- **Payload ceiling.** Write request bodies over ~4 MB are rejected (413) before JSON
  decoding (`Validator::check_body_size`).
- **Snapshot semantics documented.** Restore is one level deep and only valid until the next
  write (two writes in a row destroy the good backup) — noted in README + IMPROVEMENTS.
- `create_template` now validates its element tree (parity with the other writers).

### Tests
- 46/46 assertions (`php tests/run.php`): added SVG-rejection and payload-ceiling cases.
  Capability/draft-leak checks call WordPress and are flagged for on-install validation.

## [1.4.0] — 2026-06-09

First release of the `mt-alarcon` fork. Focus: hardening the plugin for unattended,
AI-driven writes to live WordPress sites, and closing gaps between the REST and MCP
surfaces. Forked from upstream 1.3.0.

### Security
- **Media import path guard (LFI fix).** `import_media` / `build-page` previously
  `copy()`-ed any server path into the media library — a path-traversal / Local File
  Inclusion vector. Paths are now canonicalized and must resolve inside
  `wp_upload_dir()`; remote URLs and non-image MIME types are rejected.
  (`Validator::resolve_media_path`)
- **Element-tree validation.** Every full-page write (`update_page`, `create_page`,
  `build_page`, `add_element`, and their MCP twins) is structurally validated and
  bounded — max depth 30, max 5000 elements, valid `elType`, `widgetType` required on
  widgets, array-typed `settings`/`elements` — before it replaces `_elementor_data`.
  Malformed payloads return a 400 instead of bricking the page.
  (`Validator::validate_tree`)
- **Request-body element-id validation.** Element ids arriving in bodies
  (`add_element`, `patch-bulk`) are validated; URL routes were already constrained.

### Added
- **Save snapshot + rollback.** Each save snapshots the prior `_elementor_data` to a
  backup meta slot. New `POST /page/{id}/restore` endpoint and `restore-page` ability
  revert one level — bad writes are now undoable.
- **`GET /kit/globals`** + `get-kit-globals` ability — the active Kit's global colors
  and fonts in a flat, agent-friendly shape, so generated widgets can reference
  Elementor's `__globals__` (e.g. `globals/colors?id=primary`) instead of hardcoding
  inline hex. (Design-system-first generation — the #1 professional-quality lever.)
- **`GET /health`** — public, unauthenticated probe reporting plugin/Elementor
  versions and active state. Lets a client confirm the plugin is installed without
  edit credentials.
- **MCP parity abilities** — `find-elements`, `patch-elements-bulk`, `restore-page`,
  `get-kit-globals` (the REST surface had these since 1.3.0; the MCP surface lagged).
- **Dependency-free test harness** — `php tests/run.php` (41 assertions, no composer /
  PHPUnit) over the pure-logic surface.

### Fixed
- **`add-element` ability crash** on a parent container with no `elements` key
  (undefined-index). The REST twin already guarded this; the ability now matches.
- **Media dedup correctness.** `import_image` deduped by *title* only — two different
  images sharing a title collapsed into one. Now dedups by SHA-1 content hash and uses
  `wp_unique_filename()` to avoid clobbering existing uploads.
- **PHP 7.4 floor.** Widget discovery used `str_starts_with()` (PHP 8.0+) while the
  header declares `Requires PHP: 7.4`. Added a polyfill so the declared floor is real.

### Notes / needs validation on a real install
- The validation/snapshot/health/globals/parity changes are statically verified
  (lint + unit tests) but the runtime paths (data save, kit reads, REST/MCP wiring)
  require a live WordPress + Elementor to confirm end-to-end.

## [1.3.0] — upstream (bvisible)
- Bulk patch, column-width helper, and find endpoints. See upstream history.
