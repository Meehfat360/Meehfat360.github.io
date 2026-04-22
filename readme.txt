=== StellarSavers AI Social Commerce ===
Contributors: stellarsavers
Tags: woocommerce, social media, automation, facebook, instagram, pinterest, ai, marketing
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.0
WC requires at least: 7.0
WC tested up to: 9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered social media automation for WooCommerce. Auto-selects products, generates creatives, writes captions, and publishes to Facebook, Instagram & Pinterest daily.

== Description ==

StellarSavers AI Social Commerce is a full-stack WooCommerce automation plugin that runs your social media marketing on autopilot.

**Every day, automatically:**

* Selects 5 products using a smart weighted scoring engine (bestseller, high-margin, deal, underperformer, wildcard/seasonal)
* Generates branded social media images (no DALL-E costs — fast PHP template engine)
* Writes AI-powered captions via OpenAI GPT-4o (with fallback templates)
* Publishes to Facebook, Instagram, and Pinterest at optimal times
* Runs A/B tests on every post variant automatically
* Tracks clicks, add-to-carts, and attributes orders back to specific posts

**Key Features:**

= Product Intelligence =
* 30-day rotation — never repeats a product within configurable window
* 7-factor scoring matrix: sales velocity, margin, stock urgency, discount depth, impression deficit, seasonal relevance, performance history
* Blacklist products from ever being promoted

= Creative Generation =
* 4 formats: square (Instagram), landscape (Facebook), story, Pinterest vertical pin
* Brand colors, logo, seasonal themes applied automatically
* Discount badge overlays, price display, product name

= AI Copy (GPT-4o) =
* Platform-specific captions: Facebook, Instagram (with hashtags), Pinterest (SEO descriptions)
* Brand voice enforcement (tone, forbidden words, tagline injection)
* Mines 5-star customer reviews to inject social proof
* A/B variant copy: Variant A (offer-focused) vs Variant B (benefit-focused)
* Falls back to template captions if no OpenAI key configured

= Publishing =
* Action Scheduler queue — battle-tested, no silent failures
* Staggered posting (not all platforms at once)
* Manual approval workflow option
* Auto-retry on publish failure

= Automation Engines =
* Deal Alert: auto-posts when a product goes on sale
* Seasonal Engine: auto-detects active campaign theme (Black Friday, Christmas, Valentine's Day, etc.)
* Evergreen Recycler: republishes best-performing posts after 45+ days
* Winning Product Detector: learns which products drive sales and promotes them more

= Analytics & Attribution =
* First-party click tracking via custom redirect endpoint
* UTM parameter injection on all links
* WooCommerce order attribution (last-click model)
* A/B test evaluation with automatic winner detection

= Platform Health =
* API health monitoring for all platforms
* Token expiry detection and alerts
* Email notifications on failures
* Weekly performance digest email

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through **Plugins → Installed Plugins**
3. Go to **SS Social → Settings → API Connections** and add your API keys
4. Configure your Brand Profile
5. Set your posting schedule
6. The daily workflow runs automatically

== Frequently Asked Questions ==

= Does this require OpenAI? =
No. Without an OpenAI API key, the plugin uses built-in template captions. They're good, but AI captions convert better.

= Which platforms are supported? =
Facebook Pages, Instagram Business accounts, and Pinterest. All three use official APIs.

= Can I approve posts before they go live? =
Yes. Switch to Manual Approval mode in Settings → Approval Workflow.

= Does it work with variable products? =
Yes. The product selector works with all WooCommerce product types.

= How does attribution work? =
When someone clicks a social post, they're redirected through a tracking URL that sets a session cookie. If they complete a purchase within 7 days, the order is attributed to that post.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
