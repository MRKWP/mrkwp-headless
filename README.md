[![WordPress](https://img.shields.io/badge/WordPress-7.2%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.5%2B-blue.svg)](https://php.net/)
[![Version](https://img.shields.io/badge/Version-1.0.1-orange.svg)](https://www.mrkwp.com/)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

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

## Cloudflare Pages rebuilds
Settings > Headless calls a Cloudflare Pages deploy hook so the static front end rebuilds when content changes, on a schedule, or on demand.

1. In Cloudflare go to Workers & Pages > your project > Settings > Builds > Deploy hooks and create a hook for your production branch.
2. Paste the URL into **Deploy hook URL** on Settings > Headless, or keep it out of the database by adding it to `wp-config.php`:
   ```php
   define( 'MRKWP_HEADLESS_DEPLOY_HOOK', 'https://api.cloudflare.com/client/v4/pages/webhooks/deploy_hooks/...' );
   ```
   The constant wins over the saved setting. Anyone with this URL can start a build, so keep it secret.
3. Choose what triggers a rebuild and save.

| Setting | What it does |
|---|---|
| Content changes | New content published, published content updated, content unpublished/trashed/deleted (all on by default), and categories, tags or menus changing (off by default). |
| Post types | Which public post types count. Autosaves, revisions and drafts never trigger a build. |
| Wait before building | Debounce window, default 5 minutes (0–60). The first change queues a build; later changes within the window join it, so a burst of saves is one build. |
| Schedule | Off, hourly, twice daily, daily or weekly rebuild, regardless of content changes. |
| Build now | Calls the hook straight away. |
| Recent builds | The last 20 hook calls with time, reason and Cloudflare's response. |

Queued and scheduled builds run through Action Scheduler when it's active, otherwise WP-Cron. Both need something to load WordPress. On a quiet site, add a server cron that requests `wp-cron.php` every 5 minutes so builds aren't late.

Changes stored outside posts and terms (plugin settings, FluentCRM, Rank Math) don't trigger a build; use Build now or the schedule.

## Install
1. Upload `mrkwp-headless.zip` via Plugins > Add New > Upload Plugin, or copy the `mrkwp-headless` folder to `/wp-content/plugins`.
2. Activate **MRK WP Headless**.
3. Log out and load the home page to confirm it redirects to the login screen.
4. Set the deploy hook on Settings > Headless.

To turn it off, deactivate the plugin. Deactivating also removes any queued and scheduled builds.

## Build the zip
Ensure you have Node + NPM installed. From the plugin folder run:
```
npm install
npm run plugin-zip
```
This creates `mrkwp-headless.zip` containing only the files listed under `files` in `package.json`.

## Changelog

### 1.0.1
- Settings > Headless page for a Cloudflare Pages deploy hook URL, with an optional `MRKWP_HEADLESS_DEPLOY_HOOK` constant in `wp-config.php`.
- Rebuild when content is published, updated, unpublished or deleted, and optionally when terms or menus change, per post type.
- Debounce window so bursts of saves send one build.
- Hourly, twice daily, daily or weekly scheduled rebuilds.
- Build now button and a log of the last 20 builds.

### 1.0.0
- Redirect guest front-end requests to the login page.
- Allow REST API, FluentCRM webhooks, tracking links, email signup confirmations and FluentCRM landing pages through.
- `mrkwp_headless/allow_request` filter for extra exceptions.
