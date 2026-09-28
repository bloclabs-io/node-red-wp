=== Node-RED Live Data ===
Contributors: automattic, bloclabs
Tags: node-red, iot, live data, real-time, rest api
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push live data from Node-RED flows into your site and show it in real time with a block, shortcode or widget.

== Description ==

Magical things happen when you combine Node-RED with your site. This plugin gives Node-RED (or any IoT device) a small, authenticated REST API to store data points, and displays them on the front end with values that refresh in real time, without reloading the page.

* **Node-RED Data block** for the block editor and site editor.
* **`[nodered_data]` shortcode** and a classic **widget**.
* **REST API** under `/wp-json/nrwp/v1/` to read, write (single or batch) and delete data points.
* **Secure writes**: authenticate with an API token header or a WordPress Application Password.
* **Efficient live updates**: one request per refresh for every value on the page, paused while the browser tab is hidden.
* **Jetpack Stats** endpoint for dashboards.
* Works with Node-RED 4 and 5 and the stock `http request` node, no extra Node-RED nodes required.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install the zip via *Plugins → Add New → Upload Plugin*, then activate it.
2. Go to *Settings → Node-RED* and click **Generate token**. Copy the token.
3. In Node-RED, send data with an `http request` node:
   `POST https://example.com/wp-json/nrwp/v1/data` with the header `X-NRWP-Token: <token>` and a JSON body such as `{"temperature": 21.5}`.
4. Add the **Node-RED Data** block (or `[nodered_data key="temperature" unit="°C"]`) to any post, page or template.

== Frequently Asked Questions ==

= Which values can I store? =

Strings (up to 4 KB), numbers and booleans. Keys are lowercase and may contain letters, numbers, dashes and underscores (up to 64 characters). Keys sent with uppercase letters are lowercased.

= Can I hide my data from anonymous visitors? =

Yes. Untick *Public read access* under *Settings → Node-RED*. Logged-in users still get live updates; anonymous visitors see the value rendered when the page was generated.

= Does it work with page caching? =

Yes. The API responses are sent with no-cache headers, and the front end fetches fresh values right after the page loads.

= How do I react to value changes in my theme? =

Every updated element dispatches a bubbling `nrwp:update` event with `{ key, value, previous }` in `event.detail`.

== Changelog ==

= 2.0.0 =
* Compatible with WordPress 6.5 – 7.1, PHP 7.4+ and Node-RED 4 & 5.
* New: Node-RED Data block (block.json, API version 3).
* New: REST API v2 routes (`/data`, `/data/<key>`, `/keys`, `/stats`) with batch writes, deletes, proper HTTP status codes and value types.
* New: Settings → Node-RED screen with API token management, access options and a data overview.
* Security: all write and stats endpoints now require authentication (API token or Application Password). Every REST route declares a permission callback.
* Security: live values are inserted as text, never HTML.
* Fix: fatal error on PHP 8 caused by calling a non-static method statically when registering the widget.
* Fix: undefined index notices in the shortcode and widget.
* Improved: each data point is stored in its own option so concurrent writes no longer overwrite each other. 0.x data is migrated automatically.
* Improved: the front end script no longer needs jQuery, loads deferred and only on pages that display data, and uses one request per refresh.
* Improved: Jetpack Stats support uses the current `WPCOM_Stats` API.
* Removed: the demo-specific "bean" accelerometer colouring from the front end script (use the `nrwp:update` event instead).

= 0.0.1 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Write requests now require authentication. Generate an API token under Settings → Node-RED and send it in the X-NRWP-Token header from your Node-RED flows.
