# MRKWP Headless
Locks the WordPress front end behind the login screen for a headless setup, while keeping the REST API and FluentCRM's public endpoints working.

## WordPress Versions and PHP
Requires at least: 6.2
Requires PHP:      8.2

## What it does
Any guest request for a front-end page (home, posts, pages, archives, feeds, 404s) gets a `302` redirect to `wp-login.php`. After logging in, users land in wp-admin.

It hooks `template_redirect`, which only fires when WordPress is about to render a front-end template. These are never touched:

- REST API: `/wp-json/...` (including `/wp-json/fluent-crm/v2/...`)
- `wp-admin`, `wp-login.php`, `admin-ajax.php`, `admin-post.php`
- `wp-cron.php` and `xmlrpc.php`

## What stays public
| Request | Reason |
|---|---|
| `?fluentcrm=1&route=...` | FluentCRM public routes: double opt-in `confirmation`, incoming `contact` webhook, general `webhook`, email `open` tracking, `bounce_handler`, `unsubscribe`, `manage_subscription`, `smart_url`, `email_preview` |
| `?ns_url=...` | FluentCRM click-tracking short links |
| FluentCRM "after confirmation" redirect URL | Double Opt-in settings, when set to redirect to a URL |
| FluentCRM unsubscribe redirect URL | Email settings |
| Any page containing `[fluentcrm_pref]` | Manage subscription page |
| `robots.txt` and the favicon | Crawlers and browsers |
| Logged-in users | Post previews and admin browsing |

## Allowing extra requests
Use the `mrkwp_headless/allow_request` filter to let other front-end requests through:

```php
add_filter( 'mrkwp_headless/allow_request', function ( $allowed ) {
	return $allowed || is_page( 'thank-you' );
} );
```

## Install
1. Upload `mrkwp-headless.zip` via Plugins > Add New > Upload Plugin, or copy the `mrkwp-headless` folder to `/wp-content/plugins`.
2. Activate **MRK WP Headless**.
3. Log out and load the home page to confirm it redirects to the login screen.

To turn it off, deactivate the plugin.

## Build the zip
Ensure you have Node + NPM installed. From the plugin folder run:
```
npm install
npm run plugin-zip
```
This creates `mrkwp-headless.zip` containing only the files listed under `files` in `package.json`.

## Changelog

### 1.0.0
- Redirect guest front-end requests to the login page.
- Allow REST API, FluentCRM webhooks, tracking links, email signup confirmations and FluentCRM landing pages through.
- `mrkwp_headless/allow_request` filter for extra exceptions.
