# Node-RED Live Data for WordPress (`node-red-wp`)

[![CI](https://github.com/bloclabs-io/node-red-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/bloclabs-io/node-red-wp/actions/workflows/ci.yml)
![WordPress 6.5 – 7.1](https://img.shields.io/badge/WordPress-6.5%20–%207.1-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Node-RED 4 & 5](https://img.shields.io/badge/Node--RED-4.x%20|%205.x-8f0000)
![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

Magical things happen when you combine *WordPress* with *Node-RED*. This plugin gives Node-RED (or any IoT device) a small, authenticated REST API for storing data points, and shows those values on your site in real time — no page reload needed — through a **block**, a **shortcode** or a **widget**.

> **Upgrading from 0.x?** Writes now require authentication. See [Upgrading from 0.x](#upgrading-from-0x).

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Displaying data](#displaying-data)
- [REST API](#rest-api)
- [Authentication](#authentication)
- [Node-RED example flow](#node-red-example-flow)
- [Settings](#settings)
- [Hooks for developers](#hooks-for-developers)
- [Upgrading from 0.x](#upgrading-from-0x)
- [Development and testing](#development-and-testing)
- [Changelog](#changelog)

## Features

- **Node-RED Data block** (`nrwp/data`) for the block editor and site editor, with title, unit and fallback text, plus color, typography and spacing controls.
- **`[nodered_data]` shortcode** and a classic **Node-RED Data widget**.
- **REST API** (`/wp-json/nrwp/v1/`) to read, write (single or batch) and delete data points, with proper HTTP status codes and native value types (numbers, strings, booleans).
- **Secure writes** with an API token header (`X-NRWP-Token`) or WordPress [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).
- **Efficient live updates**: a dependency-free, deferred script fetches every value on the page in one request, pauses while the browser tab is hidden, writes values as text (never HTML) and fires an `nrwp:update` event.
- **Safe concurrent writes**: every key is stored separately, so parallel requests from Node-RED never overwrite each other.
- **Jetpack Stats** endpoint for building dashboards.
- **Settings → Node-RED** screen: token management, access options and an overview of the stored data.
- Works with the stock Node-RED `http request` node — no extra Node-RED nodes required.

## Requirements

| Component | Supported versions | Tested |
|-----------|--------------------|--------|
| WordPress | 6.5 or newer       | 6.5.5, 7.1.2 |
| PHP       | 7.4 or newer       | 7.4 (CI), 8.4 |
| Node-RED  | 4.x or newer (only core nodes are used) | 4.1.15, 5.0.7 (Node.js 22) |

## Installation

1. Download the latest zip from the [releases page](https://github.com/bloclabs-io/node-red-wp/releases), or build one with `bin/build-zip.sh`.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate **Node-RED Live Data**.

Or clone the repository straight into your plugins folder:

```bash
cd wp-content/plugins
git clone https://github.com/bloclabs-io/node-red-wp.git
wp plugin activate node-red-wp
```

## Quick start

1. Go to **Settings → Node-RED** and click **Generate token**. Copy the token (it's only shown once).
2. Send a value:

   ```bash
   curl -X POST https://example.com/wp-json/nrwp/v1/data \
     -H 'Content-Type: application/json' \
     -H 'X-NRWP-Token: <your token>' \
     -d '{"temperature": 21.5, "humidity": 40}'
   ```

3. Add the **Node-RED Data** block to a post (data key `temperature`, unit `°C`), or use the shortcode:

   ```
   [nodered_data key="temperature" title="Outside" unit="°C"]
   ```

4. View the post. Every time Node-RED sends a new value, the page updates on its own.

## Displaying data

### Block

Search for **Node-RED Data** in the block inserter. Enter the data key; the editor previews the current value. Optional settings: *Title*, *Unit* (shown after the value), and *Fallback text* (shown while the key has no value).

### Shortcode

```
[nodered_data key="temperature" title="Outside" unit="°C" fallback="n/a" tag="strong"]
```

| Attribute  | Default | Description |
|------------|---------|-------------|
| `key`      | —       | **Required.** Data key to display. |
| `title`    | empty   | Heading shown above the value. |
| `unit`     | empty   | Text shown after the value. |
| `fallback` | `—`     | Shown while the key has no value. |
| `tag`      | `span`  | Wrapper for the value: `span`, `div`, `p` or `strong`. |

### Widget

Classic themes can use the **Node-RED Data** widget (*Appearance → Widgets*) with a title, data key and unit. Block themes should use the block.

### Markup, styling and events

All three render the same markup, which the front-end script keeps up to date:

```html
<h2 class="nrwp-title">Outside</h2>
<span class="nrwp-data nrwp-data-temperature" data-key="temperature" data-fallback="—" data-value="21.5">21.5</span>
<span class="nrwp-unit">°C</span>
```

Style values with `.nrwp-data` / `.nrwp-data-<key>`, or react to changes from your theme:

```js
document.addEventListener( 'nrwp:update', ( event ) => {
	const { key, value, previous } = event.detail;
	if ( key === 'temperature' && parseFloat( value ) > 30 ) {
		event.target.style.color = 'red';
	}
} );
```

## REST API

All routes live under `/wp-json/nrwp/v1/` (or `?rest_route=/nrwp/v1/...` without pretty permalinks). Keys are lowercase letters, numbers, `-` and `_` (max. 64 characters; uppercase is lowercased). Values can be strings (max. 4 KB), numbers or booleans.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/data` | read | All data as `{ "key": value }`. Limit with `?keys=a,b`. |
| `POST` / `PUT` / `PATCH` | `/data` | write | Batch write. Body: JSON object of key/value pairs. Returns `{ "updated": [...], "errors": {...} }`. |
| `GET` | `/data/<key>` | read | `{ "key", "value", "updated" }` (`updated` is ISO 8601), or `404`. |
| `POST` / `PUT` / `PATCH` | `/data/<key>` | write | Write one value. Body: `{ "value": 21.5 }` (JSON or form-encoded). |
| `DELETE` | `/data/<key>` | write | Delete a key, or `404`. |
| `GET` | `/keys` | read | Sorted list of all keys. |
| `GET` | `/stats` | stats | Site stats from [Jetpack](https://jetpack.com/) (`501` when Jetpack Stats isn't available). |

**Auth levels**

- **read** — public by default; when *Public read access* is off, requires a logged-in user or the API token.
- **write** — API token, or a user with `manage_options` (filterable, see [hooks](#hooks-for-developers)).
- **stats** — a user with Jetpack's `view_stats` capability, or *write* access.

Failed requests return standard WordPress REST errors, e.g. `401 rest_forbidden`, `400 nrwp_invalid_value`, `404 nrwp_not_found`.

<details>
<summary>Legacy 0.x routes (enabled by default)</summary>

These keep the original `{ "status", "data", "error", "error_message" }` response format so existing flows keep working. They can be turned off under **Settings → Node-RED**.

| Route | Auth |
|-------|------|
| `GET /get/<key>` | read |
| `GET\|POST /set/<key>/<value>` | **write** (was public in 0.x) |
| `GET /get_keys` | read |
| `GET /get_all` | read |
| `GET /get_stats` | stats |

</details>

## Authentication

Pick one:

**API token** (simplest for Node-RED and devices): generate it under **Settings → Node-RED** and send it in the `X-NRWP-Token` header. Only a SHA-256 hash is stored; regenerate or revoke it any time.

**Application Password**: create one under **Users → Profile → Application Passwords** for an administrator, then use HTTP Basic auth. In the Node-RED `http request` node, tick *Use authentication*, choose *basic authentication* and enter the username and application password.

```bash
curl -X POST https://example.com/wp-json/nrwp/v1/data/temperature \
  -u 'admin:abcd EFGH 1234 ijkl MNOP 6789' \
  -H 'Content-Type: application/json' -d '{"value": 21.5}'
```

> Always use HTTPS in production — both the token and Application Passwords are sent with every request.

## Node-RED example flow

[`examples/node-red-flow.json`](examples/node-red-flow.json) fetches the current weather from [Open-Meteo](https://open-meteo.com/) (no API key required) every five minutes and sends temperature, wind speed and wind direction to WordPress in a single batch request. A second branch reads a value back.

1. In Node-RED, choose **Menu → Import**, and paste or select `examples/node-red-flow.json`.
2. Double-click the **Node-RED → WordPress** flow tab and, under *Environment Variables*, set:
   - `WP_URL` — your site URL, e.g. `https://example.com`
   - `NRWP_TOKEN` — the API token from **Settings → Node-RED**
3. **Deploy**. Then add `[nodered_data key="whistler_temp" unit="°C"]` (or the block) to a post and watch it update.

The core of the flow is a function node in front of an `http request` node set to method *- set by msg.method -*:

```js
msg.method = 'POST';
msg.url = env.get('WP_URL') + '/wp-json/nrwp/v1/data';
msg.headers = { 'Content-Type': 'application/json', 'X-NRWP-Token': env.get('NRWP_TOKEN') };
msg.payload = { whistler_temp: 21.5, whistler_wind_speed: 12 };
return msg;
```

The flow is tested end to end against Node-RED 4 and 5 in CI (see [`tests/node-red.test.mjs`](tests/node-red.test.mjs)).

## Settings

**Settings → Node-RED**

| Setting | Default | Description |
|---------|---------|-------------|
| Public read access | on | Anyone can read data via the API. Needed for live updates for logged-out visitors. |
| Legacy endpoints | on | Keep the 0.x routes. Writes through them still require authentication. |
| Refresh interval | 3000 ms | How often pages poll for new values (1,000 – 600,000 ms). |
| API token | none | Generate, regenerate or revoke the `X-NRWP-Token` token. |

The screen also lists every stored key with its value and last update time.

## Hooks for developers

```php
// Let editors (not just administrators) write data with their Application Password.
add_filter( 'nrwp_write_capability', fn() => 'edit_posts' );

// Decide per request whether anonymous visitors may read data.
add_filter( 'nrwp_public_read', fn( $public, WP_REST_Request $request ) => $public, 10, 2 );

// Change the rendered markup of a data point.
add_filter( 'nrwp_data_point_markup', fn( $html, $args, $value ) => $html, 10, 3 );

// React to writes and deletes, e.g. to send notifications.
add_action( 'nrwp_data_set', function ( $key, $value, $previous ) { /* ... */ }, 10, 3 );
add_action( 'nrwp_data_deleted', function ( $key ) { /* ... */ } );
```

From PHP you can also use the data store directly:

```php
$store = Node_Red_WP::init()->data;
$store->set( 'temperature', 21.5 );
$store->get( 'temperature' );          // 21.5
$store->get_all( [ 'temperature' ] );  // [ 'temperature' => 21.5 ]
$store->delete( 'temperature' );
```

## Upgrading from 0.x

Version 2.0 is a modernization of the original 2016 plugin. What changes for you:

- **Writes need authentication.** Unauthenticated `GET /set/<key>/<value>` requests now return `401`. Generate a token and add an `X-NRWP-Token` header to your Node-RED `http request` nodes — or better, switch to `POST /data`.
- **Your data is migrated automatically** from the old `nrwp_data` option on activation or on the first request after updating. Keys become lowercase (`beanX` → `beanx`).
- **The front-end script no longer uses jQuery** and no longer colours `bean*` accelerometer values. Use the `nrwp:update` event to add your own styling.
- **The shortcode only prints a heading when `title` is set**, using `<h2 class="nrwp-title">` instead of `<h2 class="entry-title">`.
- **`/get_stats` requires authentication** (Jetpack's `view_stats` capability or write access).
- **Requirements:** WordPress 6.5+ and PHP 7.4+.

## Development and testing

The test suite runs against a real WordPress site (SQLite, PHP's built-in server) and a real Node-RED instance.

```bash
npm install                          # Node-RED 5 + Playwright for the tests
composer install                     # PHPCS with WordPress Coding Standards + PHPCompatibility

npm run wp:setup                     # Throw-away WordPress (latest) at http://127.0.0.1:8889 (admin / password)
# tests/bin/setup-wordpress.sh 6.5.5 # ...or a specific WordPress version

npm test                             # All suites
npm run test:rest                    # REST API, rendering, migration, uninstall
npm run test:node-red                # Runs examples/node-red-flow.json in Node-RED against WordPress
                                     # (NODE_RED_DIR=/path/to/other/install to test another Node-RED version)
npm run test:e2e                     # Chromium: live updates, block editor, settings screen

composer lint                        # PHPCS
npm run build:zip                    # Installable node-red-wp.zip (dev files excluded)
```

The browser tests need Chromium for Playwright (`npx playwright install chromium`). Every suite also fails on any PHP notice, warning or deprecation logged by WordPress.

GitHub Actions ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs PHPCS and syntax checks on PHP 7.4 – 8.4, and the full suite on the oldest and newest supported WordPress and PHP versions.

### Project layout

```
node-red-wp.php                 Plugin bootstrap
includes/
  class-node-red-wp.php         Core: settings access, script & block registration, shared renderer
  class-node-red-wp-data.php    Data store (one option per key) and 0.x migration
  class-node-red-wp-rest.php    REST API (v2 + legacy routes)
  class-node-red-wp-settings.php  Settings → Node-RED screen and API token
  class-node-red-wp-shortcodes.php  [nodered_data]
  class-node-red-wp-data-widget.php Classic widget
blocks/data/                    Node-RED Data block (block.json, editor script, server render)
assets/js/nrwp.js               Front-end live updates
examples/node-red-flow.json     Example Node-RED flow
uninstall.php                   Removes all plugin data when the plugin is deleted
tests/                          Integration, Node-RED and browser tests
```

## Changelog

### 2.0.0

- Compatible with WordPress 6.5 – 7.1, PHP 7.4+ and Node-RED 4 & 5.
- **New:** Node-RED Data block (block.json, API version 3).
- **New:** REST routes `/data`, `/data/<key>`, `/keys` and `/stats` with batch writes, deletes, HTTP status codes and native value types.
- **New:** Settings → Node-RED screen with API token management, access options and a data overview.
- **New:** hooks `nrwp_write_capability`, `nrwp_public_read`, `nrwp_data_point_markup`, `nrwp_data_set`, `nrwp_data_deleted`; front-end `nrwp:update` event.
- **Security:** writes and stats require authentication; every REST route has a `permission_callback` (required since WordPress 5.5); live values are inserted as text instead of HTML.
- **Fix:** fatal error on PHP 8 when registering the widget (non-static method called statically).
- **Fix:** undefined index notices in the shortcode and widget.
- **Improved:** one option per key prevents lost updates from concurrent writes; 0.x data is migrated automatically.
- **Improved:** front-end script without jQuery, deferred, loaded only where needed, one request per refresh, paused in hidden tabs.
- **Improved:** Jetpack Stats via the current `WPCOM_Stats` API (with fallback for older Jetpack).
- Added `readme.txt`, `uninstall.php`, test suites, PHPCS config and GitHub Actions CI.

### 0.0.1

- Initial release by Automattic.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
