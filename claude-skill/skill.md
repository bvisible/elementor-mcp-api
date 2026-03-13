---
name: elementor-builder
description: |
  Universal AI-driven Elementor page building via NeoService REST API.
  Use when the user:
  - Wants to create, edit, or modify Elementor pages on any WordPress site
  - Wants to update page sections, widgets, or styles
  - Mentions "elementor", "page builder", "staging site"
  - Wants to add/remove/update sections on a WordPress page
  - Wants to check or modify the Elementor global kit (colors, fonts)
  - Wants to create headers, footers, or templates
---

# Elementor Builder - NeoService API Skill

Universal skill for AI-driven Elementor page editing on any WordPress site running the NeoService Elementor API plugin.

## Setup

Before using the API, determine the site's connection info. Check project CLAUDE.md or memory for credentials. Set variables:
```bash
API="https://{site}/wp-json/neoservice/v1"
AUTH="{user}:{application_password}"
```

## Workflow

### 1. Discover: Understand the Site
```bash
# List all pages
curl -s -u "$AUTH" "$API/pages" | python3 -m json.tool

# List available widgets (includes theme + plugin widgets)
curl -s -u "$AUTH" "$API/widgets" | python3 -c "import json,sys; [print(w['name']) for w in json.load(sys.stdin)]"

# Get global kit settings (colors, fonts)
curl -s -u "$AUTH" "$API/kit" | python3 -m json.tool
```

### 2. Explore: Understand Page Structure
```bash
# Get page structure (ALWAYS start here — lightweight, shows IDs + types + hints)
curl -s -u "$AUTH" "$API/page/{ID}/structure" | python3 -m json.tool

# Get a single element's full data (avoids loading whole page)
curl -s -u "$AUTH" "$API/page/{PAGE_ID}/element/{ELEMENT_ID}" | python3 -m json.tool

# Get full page data (heavy — use structure first!)
curl -s -u "$AUTH" "$API/page/{ID}" | python3 -m json.tool
# NOTE: Returns elements under the `data` key (NOT `elementor_data`)
```

### 3. Edit: Make Changes
```bash
# Update element settings (PATCH = merge, only send changed settings)
curl -s -X PATCH -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"settings":{"title":"New Title","title_color":"#333"}}' \
  "$API/page/{PAGE_ID}/element/{ELEMENT_ID}"

# Add element (position: 0-based index, -1 = append; parent_id: null = root)
curl -s -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"parent_id":null,"position":3,"element":{...}}' \
  "$API/page/{PAGE_ID}/element"

# Move element to new position/parent
curl -s -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"parent_id":null,"position":2}' \
  "$API/page/{PAGE_ID}/element/{ELEMENT_ID}/move"

# Remove / Duplicate
curl -s -X DELETE -u "$AUTH" "$API/page/{PAGE_ID}/element/{ELEMENT_ID}"
curl -s -X POST -u "$AUTH" "$API/page/{PAGE_ID}/element/{ELEMENT_ID}/duplicate"

# Create new page
curl -s -X POST -u "$AUTH" -H "Content-Type: application/json" \
  -d '{"title":"Page Name","slug":"page-slug","data":[...elements...]}' "$API/page"
```

### 4. Flush CSS (REQUIRED after any visual change)
```bash
curl -s -X POST -u "$AUTH" "$API/flush-css"
```

### 5. Verify in Chrome (MANDATORY)
After ANY visual change, always verify:
1. `POST /flush-css` to regenerate CSS
2. Navigate to the page using `mcp__claude-in-chrome__navigate`
3. Take screenshot using `mcp__claude-in-chrome__computer` (action: screenshot)
4. Scroll through ALL sections and screenshot each one
5. Fix any issues found before moving on

Never skip verification — the API may succeed but CSS may cache old values.

## API Endpoints Reference

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/pages` | List all pages |
| GET | `/page/{id}` | Full Elementor data (returns `data` key) |
| GET | `/page/{id}/structure` | Compact structure tree |
| GET | `/page/{id}/element/{eid}` | Single element full data |
| PUT | `/page/{id}` | Replace all page data |
| POST | `/page` | Create new page |
| POST | `/page/{id}/element` | Add element (`parent_id`, `position`) |
| PATCH | `/page/{id}/element/{eid}` | Update element settings (merge) |
| DELETE | `/page/{id}/element/{eid}` | Remove element |
| POST | `/page/{id}/element/{eid}/duplicate` | Duplicate element |
| POST | `/page/{id}/element/{eid}/move` | Move element |
| POST | `/page/{id}/section` | Add root-level section |
| GET | `/templates` | List templates |
| POST | `/template` | Create template |
| GET | `/kit` | Get global kit settings |
| PUT | `/kit` | Update global kit settings |
| GET | `/widgets` | List all available widgets |
| GET | `/widget/{name}/schema` | Widget control schema |
| GET | `/widget/{name}/defaults` | Ready-to-use element JSON with defaults |
| POST | `/flush-css` | Flush CSS cache |
| POST | `/build-page` | Create/update full page |

## Elementor Element Structure

Every element follows this JSON structure:
```json
{
  "id": "8-char-hex",
  "elType": "container|widget",
  "widgetType": "heading|text-editor|image|button|icon|form|...",
  "isInner": false,
  "settings": { ... },
  "elements": [ ... children ... ]
}
```
**Always provide valid 8-char hex IDs when creating elements.** Null IDs make elements unaddressable via the API.

### Container Types
- **Root container**: `elType: "container"`, `isInner: false`
- **Inner container** (nested): `elType: "container"`, `isInner: true`
- **Row layout**: `flex_direction: "row"`, `flex_wrap: "wrap"`
- **Column layout**: `flex_direction: "column"`, `width: {"size": 50, "unit": "%"}`

### Common Widget Settings

**heading**: `title`, `header_size` (h1-h6), `align`, `title_color`, `typography_typography: "custom"`, `typography_font_family`, `typography_font_size`, `typography_font_weight`

**text-editor**: `editor` (HTML content), `text_color`, `typography_*`

**image**: `image: {"url": "...", "id": N}`, `image_size` (thumbnail/medium/large/full), `width`, `image_border_radius`

**button**: `text`, `link: {"url": "..."}`, `background_color`, `button_text_color`
- **Outline button**: `background_color: "rgba(0,0,0,0)"`, `border_border: "solid"`, `border_width`, `border_color` (NOT `button_border_*`)
- **Hover**: `button_background_hover_color`, `button_hover_border_color`

**icon** (stacked): `selected_icon: {"value": "fas fa-star", "library": "fa-solid"}`, `view: "stacked"`, `shape: "circle"`
- **CRITICAL**: In stacked mode, `primary_color` = BACKGROUND, `secondary_color` = ICON (reversed!)

**form**: `form_name`, `form_fields` (array), `email_to`, `button_text`, `show_labels` (set to `""` to hide labels)

**icon-list**: `icon_list` (array of `{"text": "...", "selected_icon": {...}, "link": {...}}`)

**google_maps**: `address`, `zoom: {"size": 16}`, `height: {"size": 450, "unit": "px"}`

**social-icons**: `social_icon_list` (array), `icon_color: "custom"`, `icon_primary_color`, `icon_secondary_color`

**nav-menu**: `menu` (WP menu slug), `layout: "horizontal"`, `pointer`, color settings

### Value Formats
```json
// Background
{"background_background": "classic", "background_color": "#2F251F", "background_image": {"url": "...", "id": 19}}

// Spacing (margin/padding)
{"top": "20", "right": "30", "bottom": "20", "left": "30", "unit": "px", "isLinked": false}

// Size
{"size": 30, "unit": "px"}

// Border radius
{"top": "12", "right": "12", "bottom": "12", "left": "12", "unit": "px", "isLinked": true}
```

## Section Patterns (Reusable Templates)

### Hero Section
```json
{
  "elType": "container", "isInner": false,
  "settings": {
    "content_width": "full", "flex_direction": "column",
    "flex_align_items": "center", "flex_justify_content": "center",
    "min_height": {"size": 60, "unit": "vh"},
    "background_background": "classic", "background_color": "#DARK_COLOR",
    "padding": {"top": "80", "right": "20", "bottom": "80", "left": "20", "unit": "px", "isLinked": false}
  },
  "elements": [
    {"elType": "widget", "widgetType": "heading", "settings": {"title": "...", "header_size": "h1", "align": "center", "title_color": "#FFFFFF", "typography_typography": "custom", "typography_font_family": "DISPLAY_FONT", "typography_font_size": {"size": 65, "unit": "px"}, "typography_font_weight": "500"}},
    {"elType": "widget", "widgetType": "text-editor", "settings": {"editor": "<p><em>Subtitle</em></p>", "align": "center", "text_color": "#ACCENT_LIGHT"}}
  ]
}
```

### Content Row (Image + Text, Zigzag)
```json
{
  "elType": "container", "isInner": false,
  "settings": {"content_width": "boxed", "flex_direction": "row", "flex_wrap": "wrap", "flex_gap": {"size": 0, "unit": "px"}, "flex_align_items": "stretch", "padding": {"top": "40", "right": "0", "bottom": "40", "left": "0", "unit": "px", "isLinked": false}},
  "elements": [
    {"elType": "container", "isInner": true, "settings": {"content_width": "full", "width": {"size": 50, "unit": "%"}, "width_tablet": {"size": 100, "unit": "%"}, "flex_direction": "column"}, "elements": [
      {"elType": "widget", "widgetType": "image", "settings": {"image": {"url": "...", "id": N}, "image_size": "large", "width": {"size": 100, "unit": "%"}, "image_border_radius": {"top": "12", "right": "12", "bottom": "12", "left": "12", "unit": "px", "isLinked": true}}}
    ]},
    {"elType": "container", "isInner": true, "settings": {"content_width": "full", "width": {"size": 50, "unit": "%"}, "width_tablet": {"size": 100, "unit": "%"}, "flex_direction": "column", "flex_justify_content": "center", "padding": {"top": "20", "right": "30", "bottom": "20", "left": "30", "unit": "px", "isLinked": false}}, "elements": [
      {"elType": "widget", "widgetType": "heading", "settings": {"title": "...", "header_size": "h2"}},
      {"elType": "widget", "widgetType": "text-editor", "settings": {"editor": "...", "text_color": "#666666"}}
    ]}
  ]
}
```
**Zigzag**: Swap the two inner containers to alternate image left/right between rows.

### Icon Row (Services/Features)
```json
{
  "elType": "container", "isInner": true,
  "settings": {"content_width": "full", "flex_direction": "row", "flex_wrap": "wrap"},
  "elements": [
    {"elType": "container", "isInner": true, "settings": {"width": {"size": 25, "unit": "%"}, "flex_direction": "column", "flex_align_items": "center"}, "elements": [
      {"elType": "widget", "widgetType": "icon", "settings": {"selected_icon": {"value": "fas fa-microscope", "library": "fa-solid"}, "view": "stacked", "shape": "circle", "primary_color": "#ACCENT", "secondary_color": "#FFFFFF"}},
      {"elType": "widget", "widgetType": "heading", "settings": {"title": "Service Name", "header_size": "h3", "align": "center"}}
    ]}
  ]
}
```

### Photo Collage Gallery (3 photos + text)
A visually rich section with overlapping photos in a collage layout, decorative background element, and text/CTA on the side.
```json
{
  "elType": "container", "isInner": false,
  "settings": {
    "content_width": "full", "flex_direction": "row", "flex_wrap": "nowrap",
    "flex_align_items": "center", "flex_gap": {"size": 0, "unit": "px"},
    "min_height": {"size": 700, "unit": "px"},
    "padding": {"top": "80", "right": "40", "bottom": "80", "left": "40", "unit": "px", "isLinked": false},
    "background_background": "classic", "background_color": "#ACCENT_BG",
    "background_image": {"url": "DECORATIVE_IMG_URL", "id": N},
    "background_position": "center center", "background_repeat": "no-repeat", "background_size": "100% 80%",
    "overflow": "hidden"
  },
  "elements": [
    {"elType": "container", "isInner": true, "settings": {"width": {"size": 33, "unit": "%"}, "width_tablet": {"size": 100, "unit": "%"}, "padding": {"top": "120", "right": "0", "bottom": "0", "left": "0", "unit": "px", "isLinked": false}, "z_index": 2}, "elements": [
      {"elType": "widget", "widgetType": "image", "settings": {"image": {"url": "...", "id": N}, "image_size": "large", "width": {"size": 100, "unit": "%"}, "image_box_shadow_box_shadow_type": "yes", "image_box_shadow_box_shadow": {"horizontal": 0, "vertical": 8, "blur": 30, "spread": 0, "color": "rgba(0,0,0,0.12)"}}}
    ]},
    {"elType": "container", "isInner": true, "settings": {"width": {"size": 30, "unit": "%"}, "width_tablet": {"size": 100, "unit": "%"}, "flex_direction": "column", "flex_gap": {"size": 30, "unit": "px"}, "z_index": 2}, "elements": [
      {"elType": "widget", "widgetType": "image", "settings": {"image": {"url": "...", "id": N}, "image_size": "large"}},
      {"elType": "widget", "widgetType": "image", "settings": {"image": {"url": "...", "id": N}, "image_size": "large"}}
    ]},
    {"elType": "container", "isInner": true, "settings": {"width": {"size": 33, "unit": "%"}, "width_tablet": {"size": 100, "unit": "%"}, "flex_direction": "column", "flex_justify_content": "center", "padding": {"top": "40", "right": "40", "bottom": "40", "left": "40", "unit": "px", "isLinked": false}}, "elements": [
      {"elType": "widget", "widgetType": "heading", "settings": {"title": "...", "header_size": "h2", "align": "left"}},
      {"elType": "widget", "widgetType": "text-editor", "settings": {"editor": "..."}},
      {"elType": "widget", "widgetType": "button", "settings": {"text": "CTA TEXT", "link": {"url": "..."}, "background_color": "rgba(0,0,0,0)", "button_text_color": "#555", "border_border": "solid", "border_width": {"top": "2", "right": "2", "bottom": "2", "left": "2", "unit": "px", "isLinked": true}, "border_color": "#ACCENT", "typography_typography": "custom", "typography_letter_spacing": {"size": 4, "unit": "px"}}}
    ]}
  ]
}
```
**Key**: Use `flex_wrap: "nowrap"` + column widths summing to ≤96% to prevent wrapping. The decorative background image (e.g., squiggle/wave PNG) adds visual interest behind the photos. Stagger photos with different padding-top values on columns for a collage effect.

### Contact Page Pattern
Hero + two-column (info left with icon-list, form right) + Google Maps on accent background.

## Design Best Practices (Learned from Experience)

### Background Colors
- **Use strictly 2 background colors** for content sections: white + one accent (e.g., cream, light grey). More than 2 creates an ugly rainbow ("arc-en-ciel") effect.
- Hero and contact sections can use a dark color as a third distinct zone.
- **Never** place two adjacent sections with the same background.
- Pattern: Dark hero → Accent → White → Accent → White → ... → Dark contact

### Zigzag Layout (Mandatory for Content Rows)
- Alternate image position: Image LEFT → Image RIGHT → Image LEFT
- Odd rows (1st, 3rd): image container first, text container second
- Even rows (2nd, 4th): text container first, image container second
- After layout changes, verify via structure endpoint

### Icons
- **Every icon MUST be unique and contextual** — never use the same icon for all items in a row
- Choose Font Awesome icons that relate to the service/feature described
- In stacked mode: `primary_color` = background, `secondary_color` = icon color

### Forms
- Hide external labels with `show_labels: ""` — use placeholders instead for a cleaner look
- Always set `email_to`, `form_name`, descriptive `placeholder` values

### Footer
- Keep footer background clean (white or very light) — text must be readable
- When changing background from dark to light, always update all text colors accordingly
- Include: logo, company name, address, phone, email, social icons, copyright with current year

### Images
- Content row images: border-radius 12px (rounded corners, not sharp)
- Hero images: full-width, no border-radius
- Circular icons: border-radius 50%

### Responsive
- Always set `width_tablet: {"size": 100, "unit": "%"}` on columns for mobile stacking
- Set responsive font sizes for H1: desktop 65px, tablet 45px, mobile 32px

### Sticky Header with Logo Shrink
Apply on the header template's main container:
```json
{
  "sticky": "top",
  "sticky_on": ["desktop", "tablet", "mobile"],
  "sticky_effects_offset": 100,
  "custom_css": "selector { transition: all 0.3s ease; }\nselector.elementor-sticky--effects { padding-top: 8px !important; padding-bottom: 8px !important; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }\nselector.elementor-sticky--effects .elementor-widget-image img { max-height: 50px !important; width: auto !important; transition: all 0.3s ease; }"
}
```
**Requires Elementor Pro.** The `custom_css` setting uses `selector` as a placeholder for the element's CSS selector. `.elementor-sticky--effects` class is added after scrolling past `sticky_effects_offset` pixels.

## Flex Layout Gotchas

- **Column wrapping**: If N columns at X% each + gap exceed 100%, they wrap. Ensure `sum(widths) + (N-1)*gap <= 100%`
- **4 items at 33% = 132%** → wraps to 3+1. Fix: use 25% each
- **3 columns + gap**: 3×33% + 2×gap can overflow. Use `flex_wrap: "nowrap"` or reduce widths

## Critical API Gotchas

### Race Condition: NEVER PATCH in parallel on the same page!
Each PATCH loads the full page data, modifies one element, then saves the whole page. If two PATCH calls run simultaneously on the same page, the second overwrites the first. **Always run PATCH calls SEQUENTIALLY per page.** Cross-page parallelism is safe.

### Other Rules
- Always use **structure** endpoint first (lightweight) before fetching full data
- Use **GET element** to inspect a single element without loading the whole page
- Use `PATCH` to merge settings — don't need to send all settings, only changes
- Element IDs are 8-char hex strings (e.g., `f8703b57`) — always provide valid ones when creating
- `position` is 0-based; use -1 to append at end
- For root-level inserts: `parent_id: null`
- Use **move** endpoint to reorder without remove+add
- Set `$API` and `$AUTH` variables at start for shorter commands
- Use `python3 -c "..."` for inline JSON parsing (not `python3 -m json.tool` which fails on empty responses)
- After adding a page, use WP-CLI (`php wp-cli.phar menu item add-post {menu} {page_id}`) to add it to navigation
- Widget discovery: `GET /widgets` returns ALL registered widgets (core + pro + extensions)
- Widget defaults: `GET /widget/{name}/defaults` returns a ready-to-use element JSON with all defaults populated
