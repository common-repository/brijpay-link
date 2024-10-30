# Changelog
## 2.4.8
- New: Custom checkout fields supported - Updated.

## 2.4.7
- New: Custom checkout fields supported.

## 2.4.6
- New: Introduced Mindbody affiliate order processing, recreates already processed orders from the backend

## 2.4.5

- New: Passing purchase client contract id

## 2.4.4

- Misc: Testing updates

## 2.4.3

- Misc: Remove Mindbody gateway configuration — It is moved under payment settings when Brijpay Mindbody Payment Gateway is installed

## 2.4.2

- Fix: Remove the unused session handler causing errors on some sites

## 2.4.1

- Fix: Cloud sync populates product locations irrespective to the product locations option

## 2.4.0

- New: Option to treat Sale contracts as normal products. Find the settings in WooCommerce - Brijpay
- Improved: Optimized scheduler to enqueue the actions in chunks. Efficient for constraint hosting providers
- Misc: Send plugin version on transaction webhook

## 2.3.1

- Regression: Order sync fail to schedule due to invalid option value

## 2.3.0

- Add: Brijpay Mindbody payment gateway settings
- Fix: Order sync fail to schedule due to invalid option value

## 2.2.0

- New: Support for products distributed in multiple location in Mindbody. Find the option in Brijpay Settings to enable it.

## 2.1.3

- Fix: PHP 7.0 compatibility syntax

## 2.1.2

- New: Scheduler configuration in Brijpay settings for scheduled tasks. Interval type, frequency, start time etc.
- New: Displays the changelog notice in the backend when the plugin is upgraded from previous version.
- Improved: Update language file

## 2.1.1

- Fixed: Unpaid orders no longer sends data to Brijpay Cloud

## 2.1.0

- New: WooCommerce Universal Payment Gateway Integration. You can configure it via Brijpay settings
- New: Allowance to process 100% free products (can be used with coupons)
- Fixed: Improved fault tolerance
- Improved: Update language file

## 2.0.4

- Misc: Removed logged-in only restriction from Contracts item

## 2.0.3

- Fixed: Subscription expiry. Set frequency value to nonzero

## 2.0.2

- New: Settings "Email Report for Deactivated Products" which sends an email report with a list of products that have been deactivated during BRIJPAY Cloud Sync
- New: Settings "Deactivate Product as Draft" set the deactivated products to 'draft' status (unpublished) during BRIJPAY Cloud sync
- New: Automatically restores items in Trash to the default product status set in the BRIJPAY settings.
- Fixed: Compatibility with WooCommerce for checking the existing products.
- Improved: Update language file
- Improved: Log file to show WooCommerce Product ID for troubleshooting

## 2.0.1

- New: Settings "Assign Revenue Category" to toggle the assignment of the category
- Fixed: Append Revenue Category to existing product categories
- Improved: Update language file

## 2.0.0

- New: Integration with WooCommerce Subscription
- New: Product synchronization with Brijpay Cloud API. Products, Packages, Contracts, and Services are all fetched. Contracts are fetched only if WooCommerce Subscription is installed and active
- New: Locked price and subscription fields for the Sale items
- New: Unified the settings page as a single tab and simplified the settings fields. Settings - BRIJPAY
- New: Telr Subscription payment support
- Fixed: Telr Subscription Handling with Brijapy Webhook
- Fixed: WPML post duplication option is now considered
- Improved: Removed AsiaPay PayDollar payment dependency (The payment gateway must be installed separately)
- Improved: Removed support for Recurring Billing Engine
- Improved: Update language file
- Improved: Compatibility with WC 5.x
- Improved: Compatibility with PHP 8.x

After the upgrade to BRIJPAY Link v2.0.0, you will need to contact BRIJPAY Support to retrieve the necessary endpoint URL's.
If you're in any doubts about configuring them, BRIJPAY Support will assist you.

## 1.4.3

- Fix: Telr capture status for Brijpay webhook

## 1.4.2

- New: Mindbody inactive products are indicated with "Deactivated" text on the product listing
- New: The text "Mindbody" and "Deactivated" is also visible in the product edit screen
- New: Mindbody Product ID stored as WooCommerce products SKU

## 1.4.1

- Fix: Regression of 404 product pages

## 1.4.0

- Feature: Adjusted stock management, added config to disable Mindbody stock override [!12]
- Feature: EpayNC payment integration

## 1.3.0

- Feature: Mindbody Client synchronization to WP Users. Configurable under **Brijpay - Mindbody - Users**
  (_plugin [Groups](https://wordpress.org/plugins/groups/) is required_). [!10]
- Compatibility with WC 4.5.2
- Update translation strings
- Settings: Separation of Store ID for Brijpay Webhook

## 1.2.2

- Fix: Decode product names on sync
- Update readme

## 1.2.1

- Sanitize transaction inputs from payment webhooks [!8]

## 1.2.0

- Refactor: Plugin name [!7]
- Refactor: Text domain and translation [!7]
- Refactor: Change of filenames [!7]

## 1.1.2

- Fix: Webhook multi products based on quantity [!6]
- Fix: Unset variable condition [!6]

## 1.1.1

- Fix: Send webhook only for orders containing mb products [!4]

## 1.1.0

- Feature: Telr IPN integration

## 1.0.2

- Regression: Product amount tax inclusive [!3]

## 1.0.1

- Webhook: Product amount tax inclusive [!2]

## 1.0.0

- Initial
