# DevDome Admin Cleaner: Remove Dashboard Widgets and Hide Menu Items

Hide admin notices, admin bar items and dashboard widgets. Client Mode hides selected admin menu items for chosen roles; permissions stay unchanged. Simplify the WordPress backend and review captured notifications in a Notice Inbox. Free, GPL, no paid tier; this repository mirrors the release published on WordPress.org.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/devdome-admin-cleaner?label=wp.org)](https://wordpress.org/plugins/devdome-admin-cleaner/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/devdome-admin-cleaner)](https://wordpress.org/plugins/devdome-admin-cleaner/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/devdome-admin-cleaner)](https://wordpress.org/plugins/devdome-admin-cleaner/reviews/)
[![Tested WP](https://img.shields.io/wordpress/plugin/tested/devdome-admin-cleaner)](https://wordpress.org/plugins/devdome-admin-cleaner/)
[![License GPL-2.0+](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)](LICENSE)

[![DevDome Admin Cleaner, free WordPress plugin to hide admin notices and dashboard widgets](https://ps.w.org/devdome-admin-cleaner/assets/banner-1544x500.png)](https://devdome.com)

- **Install from WordPress.org:** https://wordpress.org/plugins/devdome-admin-cleaner/
- **Website:** https://devdome.com
- **Support:** https://wordpress.org/support/plugin/devdome-admin-cleaner/

## Hide admin notices and manage notifications

- Hide plugin notices for yourself or all administrators, or snooze notices for 1 or 7 days.
- Review sources, classifications, first-seen dates and mute status.
- Enable an optional toolbar bell linking to the Notice Inbox.

Notice hiding includes update, error and security notices. Recognised operational notices carry a Critical badge. Restore a notice to show it again for everyone. Hiding applies only to users who can access Admin Cleaner, normally administrators.

## Dashboard customization and admin bar cleanup

Choose what remains visible in your admin panel:

- Remove dashboard widgets from view: Welcome, Activity, Quick Draft, At a Glance, Events & News and Site Health, plus widgets added by plugins and themes.
- Hide selected admin bar items: the WordPress logo, Comments, New, Updates, Customize and extra top-level plugin or theme items.
- Hide admin footer text, the version number and notice rows beneath plugins on the Plugins screen.

This admin customization keeps the toolbar available. The workspace clutter estimate and its inverse, the Cleanliness indicator, reflect workspace preferences, not site health or security. Hiding Site Health or Updates does not improve them.

### How admin cleanup works in wp admin

Open DevDome Tools > Admin Cleaner. Choose individual settings and press Save Settings, or press Clean My Admin for broad cleanup.

Clean My Admin hides dashboard widgets, supported toolbar items, footer text, the version number and plugin list notice rows. It hides all captured notices for all administrators and enables Auto-hide new notices, including supported notices added after page load.

Undo last change restores the previous settings once. Notice mutes are separate: use Restore or Clear all mutes. Turn off Auto-hide new notices to keep future notices visible.

Admin Cleaner works independently and shows its summary in the shared DevDome Tools dashboard.

### Client Mode: hide menu items for client handover

Simplify the admin dashboard for registered roles, including custom roles. Client Mode can hide menus for Plugins, Tools, Settings, the editors and Updates, plus the footer version number. These custom admin settings give clients a less cluttered client dashboard.

Client Mode never changes roles or capabilities. With the corresponding options enabled, the theme editor, plugin editor and Updates screens redirect to the dashboard. Other direct URLs follow existing permissions.

Users with `manage_options`, the Admin Cleaner capability or network management privileges are exempt.

### What cleanup preserves

Cleanup never deletes posts, pages, media, plugins or themes. It never changes permissions or replaces access control. Hiding update notices does not disable updates or fix reported issues.

Removing inbox records, deleting all notice data and retention housekeeping can permanently delete stored notice records and mutes. Uninstalling removes settings and notice data.

### Local data and privacy

Settings, notice records and mutes stay in your site's options table.

- Excerpts are redacted before storage and limited to 160 characters.
- Redaction removes email addresses, tokens, keys, nonces, URL query strings and filesystem paths. Raw notice HTML is never stored.
- Retention targets 200 records. Unresolved protected notices remain even above this limit, with a storage warning.
- Expired snoozes and orphaned mutes are cleaned automatically.
- Delete all notice data removes notices and mutes without resetting settings.

Admin Cleaner sets no cookies and sends no visitor data off-site. Cleanup runs locally without an external service. Shared dashboard requests, optional account connections and user-submitted error reports are covered under External services and privacy.

### AI agents and abilities

On WordPress 6.9 or newer, 14 abilities let authorised AI agents inspect settings and notices, apply cleanup, undo settings, manage notices and run housekeeping. MCP clients can use them through the WordPress MCP Adapter. Abilities include snoozing for 1 to 90 days.

Every ability checks the plugin capability. Actions affecting all administrators or deleting records require explicit confirmation where specified. Agent output excludes email addresses and server paths. Older WordPress versions register no abilities.

## Screenshots

![Overview](screenshots/screenshot-1.png)

*Overview: Clean My Admin, the workspace clutter estimate and what is hidden right now.*

![Notice Inbox](screenshots/screenshot-2.png)

*Notice Inbox: every captured notice with its source, type and status.*

![Dashboard Widgets](screenshots/screenshot-3.png)

*Dashboard Widgets: choose which dashboard widgets and footer items to hide.*

![Admin Bar](screenshots/screenshot-4.png)

*Admin Bar: hide the WordPress logo, Comments, New, Updates, Customize and plugin items.*

![Client Mode](screenshots/screenshot-5.png)

*Client Mode: pick the roles and the menu entries they no longer see.*

## Install

1. In WordPress, open Plugins > Add New, search for "DevDome Admin Cleaner", then install and activate. Or download the zip from [WordPress.org](https://wordpress.org/plugins/devdome-admin-cleaner/).
2. Open DevDome Tools > Admin Cleaner.
3. Press Clean My Admin, or choose individual settings and press Save Settings.

With Composer: `composer require devdome/devdome-admin-cleaner`

## FAQ

### How do I hide admin notices?

Open DevDome Tools > Admin Cleaner > Notice Inbox. Select notices, choose Hide (you), Hide for all admins or a snooze action, then press Apply. Clean My Admin can remove admin notices from view together and enable Auto-hide.

### Can I disable admin notices or hide notifications?

Auto-hide hides supported on-screen notices, including those added after page load. It does not stop source plugins generating notices or sending notification emails. Update, error and security notices, including Critical notices, can be hidden. Restore captured notices and turn off Auto-hide to keep new ones visible.

### How do I remove dashboard widgets for a custom dashboard?

Open Dashboard Widgets, select widgets and press Save Settings. Built-in widgets have individual options; plugin and theme widgets share an option. Clean My Admin enables all these hiding options. This simplifies the existing dashboard without adding widgets or building a replacement.

### Is anything deleted when I clean the dashboard?

Clean My Admin hides interface items without deleting content. Remove, Delete all notice data and retention housekeeping can delete stored inbox records and mutes. A deleted notice can return if its source displays it again. Uninstalling removes settings and notice data.

### How do I hide admin menu items with Client Mode?

Choose roles and supported menu entries in Client Mode. Editor and Updates screens can redirect to the dashboard; permissions remain unchanged. Administrators with `manage_options`, users with the Admin Cleaner capability and network managers are exempt. Notice hiding separately applies only to users who can access Admin Cleaner.

### Does it work on multisite?

Settings and notices are stored separately per site. Configure each site's dashboard individually. Network dashboard widgets and network admin notice hooks are handled, but there is no central configuration screen for all sites.

### How do I undo the cleanup?

Undo last change keeps one settings recovery point. Restore notices separately or use Clear all mutes. Disable Auto-hide to stop hiding future notices. Individual settings can show widgets and toolbar items again. Deactivation stops cleanup while preserving saved data.

## External services and privacy

Cleanup runs on your site without external services. Requests the bundled DevDome library may make, and their triggers, are documented in the External services and Privacy sections of [readme.txt](readme.txt).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
