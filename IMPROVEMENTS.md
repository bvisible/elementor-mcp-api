# IMPROVEMENTS — fork engineering notes

Deep-work record for the `mt-alarcon/elementor-mcp-api` fork (GPL-3.0, forked from
[bvisible/elementor-mcp-api](https://github.com/bvisible/elementor-mcp-api) at 1.3.0).
This documents what the plugin *is*, what was *changed in 1.4.0*, what's *deferred*, and
what *needs a live WordPress* to validate.

---

## 1. Understanding — what the plugin is and how it works

A WordPress plugin (no build step, plain PHP) that exposes an Elementor page-building API
two ways from the same core:

- **REST** under `neoservice/v1` (`class-rest-controller.php`).
- **MCP abilities** under the `neoservice-elementor` category (`class-abilities-provider.php`),
  registered only when the WordPress Abilities API + MCP Adapter plugins are present.

### Boot flow (`neoservice-elementor-api.php`)
1. Guards on `ABSPATH`, defines version/path constants.
2. Loads `includes/` classes.
3. On `rest_api_init` (only if `elementor/loaded` fired) → registers REST routes.
4. On the Abilities API hooks (only if that plugin is active) → registers the category +
   abilities.
5. Registers `_elementor_data` post-meta with `show_in_rest` and an `edit_posts` auth callback.

### The three core classes
- **`Element_Factory`** — pure builders that emit well-formed Elementor element JSON:
  `container`/`row`/`column`/`widget` primitives, ~12 widget shortcuts (heading, text,
  image, button, divider, spacer, icon, social-icons, nav-menu, form), and two composites
  (`hero`, `content_row`). Also `generate_id()` (8-char hex) and `reassign_ids()` (for
  duplication). The composites carry opinionated brand defaults (specific fonts/colors).
- **`Elementor_Data`** — the read/write engine over `_elementor_data`. Dual-path: native
  Elementor document API when present (regenerates CSS), direct post-meta fallback
  otherwise. Tree ops (find/insert/remove/duplicate/update-settings), structure summary,
  templates (theme-builder), kit read/write, media import, widget discovery
  (`list/schema/defaults`, schema is read live from the widget — not hardcoded), CSS flush.
- **`REST_Controller`** / **`Abilities_Provider`** — thin HTTP/MCP wrappers over the above.

### Endpoint / ability inventory (pre-fork)
Pages (list/get/structure/update/create/build), elements (get/add/update/remove/duplicate/
move), bulk (`patch-bulk`, `column-width`, `find`), `section`, templates (list/create),
kit (get/update), widgets (list/schema/defaults), media import, flush-css. Permissions:
reads gate on `read`, writes on `edit_posts`.

### The gaps found in the read (what this fork addresses)
1. **Write API with thin safety.** No structural validation of the element tree before it
   replaces a page; no snapshot/rollback; recursion has no depth/size bound.
2. **Media import LFI.** `import_image` `copy()`-ed *any* server path — a path-traversal /
   Local File Inclusion vector. Dedup was title-only (different images, same title →
   collapsed into one).
3. **REST↔MCP drift.** The 1.3.0 bulk/find/section additions landed on REST only; the MCP
   ability surface lagged. Also a real bug: the `add-element` *ability* inserted into a
   parent's `elements` without ensuring the key existed (the REST twin guarded it).
4. **Design-system exposure.** Nothing surfaced the Kit's global colors/fonts for
   `__globals__` referencing — the #1 lever for *professional*, brand-consistent output.
5. **PHP floor mismatch.** Header says `Requires PHP: 7.4`, code used `str_starts_with()`
   (PHP 8.0+).
6. **No tests, no health probe.**

---

## 2. Plan — prioritized

| Pri | Area | Item | Status |
|-----|------|------|--------|
| P0 | Security | Media path traversal/LFI guard | ✅ done |
| P0 | Security | Element-tree validation (shape + depth + size) on all writes | ✅ done |
| P0 | Safety | Save snapshot + `restore` (undo a bad write) | ✅ done |
| P1 | Correctness | Media dedup by content hash; unique destination filename | ✅ done |
| P1 | Correctness | Fix `add-element` ability undefined-`elements` crash | ✅ done |
| P1 | Parity | Bring MCP abilities to REST parity (find/bulk/restore/globals) | ✅ done |
| P1 | Quality | `GET /kit/globals` for `__globals__` design-system referencing | ✅ done |
| P2 | Compat | PHP 7.4 `str_starts_with` polyfill | ✅ done |
| P2 | Ops | Public `GET /health` probe | ✅ done |
| P2 | Ops | Dependency-free PHP test harness | ✅ done |
| P2 | Docs | README attribution + CHANGELOG + skill.md sync + version bump | ✅ done |
| — | Deferred | Mapper-side `__globals__` / responsive overrides | n/a (Python client) |
| — | Deferred | Per-page write lock (PATCH race is documented, not enforced) | deferred |
| — | Deferred | Multi-level undo history (only one backup slot today) | deferred |

---

## 3. What was implemented (1.4.0)

New file **`includes/class-validator.php`**:
- `validate_tree()` — structural + bounded (depth 30, 5000 elements) validation of any
  element tree before it replaces `_elementor_data`.
- `resolve_media_path()` — canonicalize + confirm-inside-uploads + image-MIME-only;
  rejects `../`, absolute escapes, remote URLs, non-images.
- `is_valid_element_id()` — for ids arriving in request bodies.

**`class-elementor-data.php`** — `save_page_data()` snapshots prior state; new
`restore_backup()`; `get_kit_globals()`; `import_image()` now content-hash dedups and uses
`wp_unique_filename()`.

**`class-rest-controller.php`** — validation wired into `update_page`/`create_page`/
`build_page`/`add_element`; id checks in `patch-bulk`; resolved paths in `import_media`/
`build_page`; new routes `POST /page/{id}/restore`, `GET /kit/globals`, `GET /health`;
uniform `error_response()`.

**`class-abilities-provider.php`** — fixed the `add-element` crash; added `find-elements`,
`patch-elements-bulk`, `restore-page`, `get-kit-globals`; reused the Validator across write
abilities.

**`neoservice-elementor-api.php`** — loads Validator; `str_starts_with` polyfill; version → 1.4.0
(→ 1.4.1 in the audit follow-up, §3b).

**`tests/`** — `run.php` + `wp-stubs.php` + 2 suites, 41 assertions (→ 46 after §3b), runs on bare PHP.

Verification done locally: `php -l` clean on all 10 PHP files; `php tests/run.php` → 41/41.

---

## 3b. v1.4.1 — independent security-audit follow-up (pre-production)

A separate @vault audit found items to fix **before** the first production install. All
applied; tests `php tests/run.php` → **46/46**.

- **MUST-FIX-1 — SVG stored-XSS:** removed `image/svg+xml` from the import allowlist
  (`Validator::resolve_media_path`, now raster-only). Test added.
- **MUST-FIX-2 — capability hardening (REST + MCP + meta):**
  - REST permission callbacks now do per-post `edit_post`/`read_post` when the route has an
    id; create uses `edit_pages`; `build_page` enforces `edit_post` on a body `page_id`.
  - MCP: per-post/admin guards (`guard_edit_post` / `guard_read_post` / `guard_admin`) called
    **inside** each write/read `execute_callback`, because an Ability's permission callback
    can't see input.
  - `update_kit` / `create_template` / the `_elementor_data` meta auth → `manage_options`.
  - Direct-meta fallback save gated on `unfiltered_html`.
- **SHOULD-3 draft leak:** `list_pages` / `list-pages` skip non-published pages the caller
  can't edit; per-id reads check `read_post`.
- **SHOULD-4 field consistency:** `build_page` + `import_media` accept both `source_path`
  (canonical) and legacy `path`.
- **SHOULD-6 payload ceiling:** `Validator::check_body_size` (~4 MB → 413) wired into all
  JSON-body REST writes. Test added.
- **SHOULD-7:** snapshot/restore documented as one-level, valid-until-next-write.

**Upstream-PR candidates (generic, not fork-specific) — alongside the v1.4.0 ones:** the
SVG-XSS fix, the per-post + per-surface capability hardening, and the draft-leak fix are all
generic security bugs in the upstream `bvisible/elementor-mcp-api` and should be offered back.

---

## 3c. v1.4.2 — second security-audit pass (@vault)

Three additive fixes, each shipped with a reproducing test (suite 46 → 88 assertions);
no endpoint/ability signature changed:

1. **`unfiltered_html` gate was dead code → stored-XSS (HIGH).** Gate lived only on the
   direct-meta fallback of `save_page_data`, never reached in production (native path wins).
   Moved to the top of `save_page_data` for all paths: non-privileged callers get
   HTML-carrying settings sanitized recursively via `wp_kses_post`
   (`Elementor_Data::kses_widget_html`, keys: `editor`, `html`, `title`, `text`, `content`,
   `description`, `caption`, `title_text`, `description_text`, `testimonial_content`,
   incl. repeater items); `unfiltered_html` callers untouched.
2. **`add_section` bypassed `validate_tree` + body ceiling (MEDIUM)** — now validates the
   section subtree and rejects oversized bodies like every other write.
3. **4MB ceiling uniform (MEDIUM, DoS)** — REST guard added to `update_element`,
   `move_element`, `set_column_width`; MCP surface gets `guard_input_size()` (same
   `Validator::MAX_BODY_BYTES`, JSON-encoded input) at the top of all 13 write abilities.

**Upstream-PR candidates:** all three are generic upstream bugs.

---

## 4. What needs a live WordPress + Elementor to validate (post-install)

The static layer (lint + pure-logic unit tests) is green, but these runtime paths cannot be
exercised without a real install and **must** be checked on the pilot site:

1. **Save + restore round-trip** — write a page, confirm the backup meta is set, call
   `/page/{id}/restore`, confirm the page reverts and still opens cleanly in the Elementor editor.
   Confirm restore is one-level (a second write destroys the good backup).
2. **Tree validation in situ** — a deliberately malformed payload returns 400 (not a 500 /
   white screen); a valid payload still saves and renders.
3. **Media path guard** — an in-uploads image imports; an out-of-uploads path, a `../` path,
   and an SVG are rejected; content-hash dedup returns the same attachment on re-import.
4. **`/kit/globals`** — returns the real Kit colors/fonts, and a widget using the resulting
   `__globals__` references renders with the global value (the single most important
   professional-quality check).
5. **`/health`** — reachable unauthenticated and reports correct versions / active state.
6. **MCP parity** — the new abilities register and execute through the MCP Adapter.
7. **PHP 7.4** — confirm the polyfill path on an actual 7.4 runtime (CI matrix or a 7.4 box).
8. **Capability matrix (v1.4.1 — cannot be unit-tested without WP):** as a non-admin Author,
   confirm: (a) cannot `edit_post` another user's page via REST **or** MCP (403); (b) cannot
   `update_kit` / `create_template` (403); (c) cannot read another's draft via reads or
   `list_pages`; (d) the `unfiltered_html` gate blocks a `<script>`-bearing fallback save.
   As an Administrator, confirm all of the above succeed. The `manage_options` gate on the
   `_elementor_data` meta route and the per-surface 403s are runtime-only behaviours.
9. **Payload ceiling** — a >4 MB write body returns 413 before any DB write.

---

## 5. Deferred / future work
- **Per-page write lock.** The PATCH race is *documented* but not enforced; a transient
  lock around `save_page_data` would make concurrent writes safe by construction.
- **Multi-level undo.** One backup slot today; a small ring buffer would allow deeper undo.
- **Mapper-side `__globals__` + responsive overrides.** These live in the *Python client*
  that consumes this plugin (`int-elementor-design-to-page`), not in the plugin — the
  plugin now *exposes* the globals (`/kit/globals`); the client decides when to reference them.
- **Upstream PRs.** The LFI guard, the `add-element` crash fix, the tree validation, and the
  PHP 7.4 polyfill are generic, non-fork-specific bug/security fixes — good upstream PR
  candidates to `bvisible/elementor-mcp-api`.
