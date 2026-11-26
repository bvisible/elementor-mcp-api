=== NeoService Elementor API ===
Contributors: bvisible
Tags: elementor, rest-api, mcp, ai, page-builder
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: elementor
Stable tag: 1.3.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

REST API and MCP abilities for AI-driven Elementor page building.

== Description ==

NeoService Elementor API exposes a REST API (namespace `neoservice/v1`) and, when the WordPress Abilities API and MCP Adapter are available, MCP tools for creating and editing Elementor pages programmatically.

* Create, read and update Elementor pages
* Add, update, move, duplicate and remove single elements
* Manage templates and global kit settings
* Discover available widgets and their controls
* Flush the Elementor CSS cache

Authentication uses WordPress Application Passwords or cookie authentication.

== Installation ==

1. Install and activate Elementor.
2. Upload the plugin folder to `/wp-content/plugins/` and activate it.
3. Create an Application Password under Users > Profile.

== Frequently Asked Questions ==

= Does it need Elementor? =

Yes. Routes and abilities are registered only when Elementor is loaded.

== Changelog ==

= 1.3.0 =
* Bulk patch, column-width and find endpoints.
