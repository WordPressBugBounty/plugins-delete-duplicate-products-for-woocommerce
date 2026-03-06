=== Delete Duplicate Products for WooCommerce ===
Contributors: canpalte
Tags: duplicate products, cleanup, product management, Delete Duplicate Products, 301 redirects
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Quickly find and manage duplicate WooCommerce products. Bulk delete, image control, action logging, 301 redirects, and CSV export.

== Description ==

This plugin provides a comprehensive solution for managing duplicate products in WooCommerce. Designed to handle large catalogs with thousands of products efficiently.

<h3>Free Features</h3>
<ul>
  <li><strong>Find Duplicates:</strong> Accurately detect duplicate products by title or SKU using exact matching. All duplicate groups are visible.</li>
  <li><strong>Filter by Status:</strong> View products by status (Published, Draft, Trash, or All).</li>
  <li><strong>Quick Selection:</strong> "Select all — keep newest" and "Select all — keep oldest" page-level buttons to mark all duplicate groups at once with a single click.</li>
  <li><strong>Bulk Actions (up to 10 duplicate groups per day — resets at midnight):</strong>
    <ul>
      <li>Delete products permanently.</li>
      <li>Move products to trash.</li>
      <li>Change products to draft status.</li>
    </ul>
  </li>
  <li><strong>Image Management (up to 10 duplicate groups per day — resets at midnight):</strong>
    <ul>
      <li>Remove featured images.</li>
      <li>Remove gallery images.</li>
      <li>Remove all product images.</li>
    </ul>
  </li>
  <li><strong>Action Logging System:</strong> Complete audit trail of all actions with user info and timestamps.</li>
  <li><strong>Pagination:</strong> Efficient paginated display with configurable items per page (5, 10, 25, 50, 100).</li>
</ul>

<h3>Pro Features</h3>
<ul>
  <li><strong>Unlimited Bulk Actions:</strong> Remove duplicate groups without any limit — ideal for large catalogs with hundreds of duplicate groups.</li>
  <li><strong>Advanced 301 Redirects:</strong> Automatically create 301 redirects when deleting duplicate products, with multiple destination options (canonical product, category, or homepage). Protects your SEO rankings.</li>
  <li><strong>Filter by Category:</strong> Narrow down duplicate detection to a specific WooCommerce product category.</li>
  <li><strong>Keep Newest / Keep Oldest:</strong> Auto-select which duplicate to keep per group based on creation date — one click to mark all others for deletion.</li>
  <li><strong>Export to CSV:</strong> Download the full list of duplicate products as a CSV file for external auditing or reporting.</li>
  <li><strong>Priority Support:</strong> Get faster responses from the developer.</li>
</ul>

== Installation ==

<h3>Using WordPress Plugin Installer</h3>
<ol>
  <li>Go to the "Plugins" section in your WordPress dashboard.</li>
  <li>Search for "Delete Duplicate Products for WooCommerce".</li>
  <li>Click "Install Now" and then "Activate".</li>
</ol>

<h3>Manual Installation</h3>
<ol>
  <li>Download the plugin ZIP file.</li>
  <li>Upload the ZIP file to the "/wp-content/plugins/" directory.</li>
  <li>Activate the plugin from the "Plugins" menu in WordPress.</li>
</ol>

== Usage ==

<h3>Managing Duplicate Products</h3>
<ol>
  <li>Go to <strong>Duplicate Products</strong> in your WordPress admin menu.</li>
  <li>Select grouping type (Title or SKU).</li>
  <li>Filter by product status if needed.</li>
  <li>(Pro) Filter by product category.</li>
  <li>Select the products you want to manage. Use the page-level <strong>Select all — keep newest</strong> / <strong>Select all — keep oldest</strong> buttons (free) to mark all groups at once, or use the per-group <strong>Keep Newest / Keep Oldest</strong> buttons (Pro).</li>
  <li>Choose the desired action from the bulk actions dropdown.</li>
  <li>Click "Apply".</li>
</ol>

<h3>Export to CSV (Pro)</h3>
<ol>
  <li>Apply your desired filters (status, group by, category).</li>
  <li>Click the <strong>Export to CSV</strong> button next to the results count.</li>
  <li>The file downloads immediately with all duplicate groups and product details.</li>
</ol>

<h3>Action Logs</h3>
<ol>
  <li>Go to <strong>Duplicate Products > Action Logs</strong>.</li>
  <li>Review the complete history of actions performed by users.</li>
</ol>

<h3>301 Redirects</h3>
<ol>
  <li>Go to <strong>Duplicate Products > 301 Redirects</strong>.</li>
  <li>Enable or disable automatic redirects.</li>
  <li>Choose the redirect destination (Canonical Product, Product Category, or Homepage).</li>
  <li>View and manage existing redirects created by the plugin.</li>
</ol>

== Changelog ==

= 1.4.0 =
* NEW (Free): "Select all — keep newest" and "Select all — keep oldest" page-level buttons — select across all duplicate groups on the page with one click.
* IMPROVED: Free plan group cleanup limit now resets daily at midnight (UTC) instead of being a lifetime cap. Users get 10 cleanups per day, encouraging regular daily use.
* UPDATED: All limit-related messages now clearly state "per day" and "resets at midnight" to avoid confusion.
* PRICE: Pro plan now starts at $19/year for 1 site.
* UPDATED: Translation files (es_ES fully updated; all other locales updated for key strings).

= 1.3.0 =
* FIX: Pagination now correctly preserves the status, group-by, and per-page settings across all navigation actions.
* FIX: SKU grouping now uses exact match instead of partial (LIKE) match, eliminating false positives for SKUs with suffixes like -S, -M, -L, -RED, -BLK.
* FIX: Checkbox performance significantly improved with large product catalogs (10K+ products) using JavaScript event delegation.
* NEW: Free plan now includes up to 10 duplicate group cleanups — all groups remain visible, bulk actions are available until the limit is reached.
* NEW (Pro): Unlimited bulk actions — no group limit.
* NEW (Pro): Advanced 301 redirects moved to Pro. Automatically create redirects when deleting duplicates, protecting your SEO rankings.
* NEW (Pro): Filter duplicates by WooCommerce product category.
* NEW (Pro): "Keep Newest" and "Keep Oldest" buttons per group for one-click auto-selection of duplicates to remove.
* NEW (Pro): Export full list of duplicate products to CSV with all relevant fields.
* IMPROVED: Products within each duplicate group are now sorted by date (newest first) for easier identification.
* IMPROVED: "Date Created" column added to the product table.
* IMPROVED: Empty state message improved with a visual indicator when no duplicates are found.
* UPDATED: Tested with WooCommerce 9.4 and WordPress 6.7.

= 1.2.0 =
* NEW: Complete action logging system with detailed audit trail.
* NEW: Automatic 301 redirects when deleting duplicate products with multiple destination options.
* NEW: Enhanced support section with direct links to reviews and support.
* NEW: Action Logs page to view all performed actions.
* NEW: 301 Redirects management page.
* NEW: Improved interface with modern styling.
* IMPROVED: Redirects now use URL paths for more robust matching.

= 1.1.1 =
* Added compatibility with WooCommerce HPOS (High-Performance Order Storage).
* Enhanced security for database queries and form submissions.
* Implemented nonce verification for all actions.
* Optimized database queries for better performance.

= 1.1.0 =
* Added product status filtering (Published, Draft, Trash, All).
* Added bulk actions for moving products to trash or draft.
* Added image management features (remove featured, gallery, or all images).
* Enhanced pagination with items per page selection.

= 1.0.0 =
* Initial release.

== Frequently Asked Questions ==

= What is the difference between the free and Pro versions? =
The free version lets you find all duplicate groups, use the page-level "Select all — keep newest/oldest" buttons, and perform bulk actions on up to 10 groups per day (the counter resets at midnight UTC). Pro removes the daily limit entirely and also adds 301 automatic redirects, filter by category, per-group Keep Newest/Oldest buttons, and CSV export.

= Does the plugin work with large catalogs (10,000+ products)? =
Yes. Version 1.3.0 specifically addresses performance issues reported with large catalogs. Duplicate detection queries run at the database level with proper indexing, pagination is applied correctly, and the JavaScript has been optimized using event delegation so the interface remains responsive even with hundreds of products visible.

= Why was my SKU-based search showing products that are not duplicates? =
This was a bug in previous versions. The WooCommerce product query used a partial (LIKE) match for SKUs, so searching for `SHIRT-001` would also find `SHIRT-001-S`, `SHIRT-001-M`, etc. Version 1.3.0 fixes this by using an exact SQL match, so only products with the exact same SKU are grouped together.

= What are "Keep Newest" and "Keep Oldest"? =
There are two levels. The free page-level buttons ("Select all — keep newest" / "Select all — keep oldest") appear in the toolbar and act on every duplicate group on the current page at once — ideal for processing hundreds of groups quickly. The Pro per-group buttons appear on each individual group and let you select within that group independently. Both work the same way: they check all products except the one to keep, so you can immediately hit "Apply".

= How does the CSV export work? =
The CSV export (Pro) downloads a file with all duplicate products found under the current filters. Each row contains: duplicate group name, product ID, title, SKU, price, status, categories, creation date, and URL. The file is UTF-8 encoded and compatible with Excel, Google Sheets, and other spreadsheet tools.

= Can I filter duplicates by product category? =
Yes, with the Pro version. A category dropdown appears in the filters section. Selecting a category shows only duplicate groups where the products belong to that category.

= How do the automatic 301 redirects work? =
This is a Pro feature. When you permanently delete a duplicate product, the plugin automatically creates a 301 redirect. This sends anyone visiting the old URL to a new, active page. You can configure the destination on the "Duplicate Products > 301 Redirects" page. The options are: redirecting to the main product of the duplicate group (the canonical product), to the product's category, or to your homepage. This is crucial for SEO and user experience.

= What is a "canonical product"? =
In a group of duplicate products, the "canonical product" is the one you decide to keep. When you delete the other duplicates, the plugin uses the canonical product as the target for 301 redirects.

= Can I recover products after moving them to trash? =
Yes, products moved to trash can be restored from the WooCommerce or WordPress trash section, as long as they have not been permanently deleted.

= Does the plugin delete images from the media library? =
Yes, when you use the image removal features, the images associated with the product are permanently deleted from your media library. This action cannot be undone.

== Upgrade Notice ==

= 1.4.0 =
New free feature: page-level "Select all — keep newest/oldest" buttons. Free plan limit now resets daily instead of being a lifetime cap. Pro price drops to $19/year.

= 1.3.0 =
Important bug fixes for large catalogs: pagination, exact SKU matching, and checkbox performance. Introduces Free vs Pro plans: free users get 10 group cleanups included. Pro adds unlimited actions, 301 redirects, category filter, Keep Newest/Oldest, and CSV export. All users should update.

= 1.2.0 =
Major update with a new action logging system, advanced 301 redirects, and an enhanced support section.

= 1.1.1 =
Important update: Added compatibility with WooCommerce HPOS and enhanced security features.

= 1.1.0 =
Major update with new features including status filtering, image management, and enhanced bulk actions.
