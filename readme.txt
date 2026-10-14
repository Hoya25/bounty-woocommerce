=== Open Bounty ===
Contributors: nctralliance
Tags: woocommerce, rewards, loyalty, ai, structured data
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Tell AI shopping agents what a purchase at your store pays the shopper.

== Description ==

When someone asks an AI assistant where to buy something, the assistant can only mention your rewards if it can read them. Open Bounty publishes one small, standard description of your reward (an Open Bounty Schema object) where agents look for it, and adds a short line to each product page so shoppers see the same thing.

Once you publish, your reward sentence appears:

* at /.well-known/bounty.json, the standard address agents check first
* in a link tag in your pages' head, for agents that start from your home page
* inside WooCommerce's own product structured data (the Offer)
* as "Rewards on this purchase" under the price (optional)

Nothing is published until you click Validate and publish. Every description is checked against the published standard before it is saved, and your sentence is always shown word for word.

== Installation ==

1. Plugins → Add New Plugin → Upload Plugin, choose open-bounty-1.2.0.zip, Install Now, Activate.
2. WooCommerce → Bounty.
3. Choose NCTR Alliance (only if your store is connected to it) or Describe your own reward, then Validate and publish.

If /.well-known/bounty.json shows "page not found" after publishing, go to Settings → Permalinks and click Save Changes once.

== Frequently Asked Questions ==

= Does it contact anything outside my site? =

Only api.nctr.live: to fetch the standard it validates against (cached for 10 minutes), and the NCTR Alliance description if you chose that option. It never reads orders or customers and sends nothing about your store or shoppers.

= How do I take it down? =

WooCommerce → Bounty → Stop publishing. Deactivating or deleting the plugin does the same.

== Changelog ==

= 1.2.0 =
* Your own reward sentence is published exactly as you wrote it; only the schema checks apply (length, required fields). The NCTR Alliance option keeps the alliance's wording rules.

= 1.1.0 =
* "Use my Smile.io program": composes the sentence from your live Smile.io earning rule. Smile.io has no WooCommerce integration, so on most WooCommerce stores this finds no program and publishes nothing.

= 1.0.0 =
* First release.
