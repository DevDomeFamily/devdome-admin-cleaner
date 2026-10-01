=== DevDome Admin Cleaner: Remove Dashboard Widgets and Hide Menu Items ===
Contributors: devdome
Tags: remove dashboard widgets, hide menu items, declutter admin, admin cleanup, hide admin notices
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hide admin notices, remove dashboard widgets from view and hide selected admin menu and toolbar items without changing permissions.

== Description ==

DevDome Admin Cleaner provides admin cleanup for a clean dashboard in WordPress. Remove dashboard widgets from view, including the Welcome panel, Activity, Quick Draft and Site Health. Choose individual settings or use Clean My Admin to apply a broad cleanup.

Hide admin notices for yourself or all administrators, then review captured notices in the Notice Inbox. Snooze selected notices for 1 or 7 days and restore them when needed. Notice hiding applies only to users who can access Admin Cleaner and includes update, error and security notices. It changes on-screen visibility without disabling updates or notification emails.

Use Client Mode to declutter admin screens for chosen roles, including custom roles. Hide menu items in the admin menu without changing permissions; users with manage_options, the Admin Cleaner capability or network management privileges are exempt. Separate cleanup settings hide selected admin bar items, including the WordPress logo, Comments, New, Updates and Customize. The toolbar itself remains available.

= Hide admin notices and review them later =

The Notice Inbox lets you manage on-screen notifications without losing track of their sources.

* Hide plugin notices for yourself or all administrators.
* Snooze selected notices for 1 or 7 days.
* Review notice sources, classifications, first-seen dates and mute status.
* Enable an optional toolbar bell that links to the inbox. Snooze notices for 1 or 7 days from the Notice Inbox.

Notice hiding includes update, error and security notices. Recognised operational notices carry a Critical badge in the inbox. Restore a notice to show it again for everyone.

Notice hiding applies only to users who can access Admin Cleaner, normally administrators. Hiding update notices does not disable updates or fix the issues they report.

= Dashboard customization =

Choose which dashboard widgets remain visible in your admin panel. You can remove dashboard widgets from view individually or apply the broad cleanup.

* Hide the Welcome panel, Activity, Quick Draft, At a Glance, Events & News and Site Health.
* Hide dashboard widgets added by plugins and themes.
* Hide admin footer text, the version number and notice rows beneath plugins on the Plugins screen.

Check the workspace clutter estimate and its inverse, the Cleanliness indicator, to review your workspace preferences. These are not measures of site health or security. Hiding Site Health or Updates does not improve them.

= Admin bar and toolbar cleanup =

Hide selected admin bar items: the WordPress logo, Comments, New, Updates and Customize. You can also hide extra top-level items added by plugins or themes.

This admin customization provides basic cleanup of logos and footer text. The toolbar itself remains available.

= Apply cleanup in wp admin =

Open DevDome Tools > Admin Cleaner. Choose individual settings and press Save Settings, or press Clean My Admin for a broad cleanup.

Clean My Admin applies these changes:

* Hides dashboard widgets and supported toolbar items.
* Hides footer text, the version number and plugin list notice rows.
* Hides all notices already captured in the inbox for all administrators.
* Enables Auto-hide new notices, including supported notices added after page load.

Undo last change restores the previous settings once. Notice mutes are separate: use Restore or Clear all mutes to show hidden notices again. Turn off Auto-hide new notices if you want future notices to remain visible.

Admin Cleaner works on its own and also shows its summary in the shared DevDome Tools dashboard.

= Client Mode and admin menu visibility =

Use Client Mode to hide menu items for roles registered on your site, including custom roles. You can hide menus for Plugins, Tools, Settings, the editors and Updates, plus the footer version number.

For a client dashboard with fewer distractions, choose the entries clients need to see during handover. These custom admin settings simplify the existing workspace. For client handover, use Client Mode to hide selected menu entries for chosen roles without changing permissions.

Client Mode never changes roles or capabilities. With the corresponding options enabled, the theme editor, plugin editor and Updates screens redirect to the dashboard. Other direct URLs still follow the user's existing permissions.

Users with manage_options, the Admin Cleaner capability or network management privileges are exempt.

= What cleanup changes and what it preserves =

Cleanup never deletes posts, pages, media, plugins or themes. It never changes user permissions or replaces access control.

Removing inbox records, deleting all notice data and retention housekeeping can permanently delete the plugin's stored notice records and mutes. Uninstalling removes its settings and notice data.

= Local data and privacy =

Settings, notice records and mutes stay in your site's options table. Notice excerpts are redacted before storage and limited to 160 characters.

* Redaction removes email addresses, tokens, keys, nonces, URL query strings and filesystem paths.
* Raw notice HTML is never stored.
* Retention targets 200 records. Unresolved protected notices are kept even when this exceeds the limit, with a storage warning.
* Expired snoozes and orphaned mutes are cleaned automatically.
* Delete all notice data removes stored notices and mutes without resetting settings.

Admin Cleaner sets no cookies and sends no visitor data off-site. Its cleanup features run locally and need no external service. Shared dashboard requests, optional account connections and user-submitted error reports are described in External services.

= AI agents and abilities =

On WordPress 6.9 or newer, 14 abilities let authorised AI agents inspect settings and notices, apply cleanup, undo settings, manage notices and run housekeeping. MCP clients can use them through the WordPress MCP Adapter. Abilities include snoozing for 1 to 90 days.

Every ability checks the plugin capability. Actions affecting all administrators or deleting records require explicit confirmation where specified. Agent output excludes email addresses and server paths. Older versions register no abilities.

== External services ==

**Error reports (`devdome.com`), only when you press "Report this error" on an error message.** The plugin sends the error text, the plugin, bundled library, WordPress and PHP versions, whether the site is a multisite, the site locale, the screen you were on, its own state (counts and flags, never a notice text), your site address, the generated site ID, your DevDome account ID if the site is connected, and your admin e-mail (so support can reply) to `https://devdome.com/api/plugin/error-report`. Nothing is sent unless you press the button. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

**Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours (one hour after a failed fetch), so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

Admin Cleaner's own features (the notice inbox, dashboard, admin-bar and footer cleanup, and Client Mode) run entirely on your own server and make no network requests.

The plugin bundles the shared DevDome suite library that powers the **DevDome Tools** dashboard. What that library may contact depends on the edition you are running; on the WordPress.org edition nothing is contacted before you act; the self-hosted edition also checks for updates as described below.

= WordPress.org edition =

The WordPress.org package carries a build marker that keeps the library's bot-detection feed download permanently off and contains no self-hosted updater; updates come only from WordPress.org. Besides the two requests above (error reports you send, the plugin catalog), the only other request the shared library can make is the optional account connection below.

= Connecting a DevDome account (optional, both editions) =

The DevDome Tools dashboard offers connecting a free DevDome account (used by other DevDome plugins for optional email alerts; Admin Cleaner's own features stay local; the shared library performs only the checks described here). Nothing is sent until you press the Connect button. If you do connect: the shared library sends your site address, a generated site ID and a generated secret site token to `analytics.devdome.com/api/plugin/connect/start` and `/api/plugin/connect/claim` to link this site to your account; afterwards it confirms the connection with `api.devdome.com/plugin/account` (site address plus the site token in a request header) normally at most once every fifteen minutes while a DevDome screen is open (sooner right after connecting, and after ten minutes when a check failed), and tells `api.devdome.com/plugin/disconnect` when you disconnect. When you connect from the DevDome Tools dashboard, whose Connect card states this before you press the button, those account checks also carry the slug and version of each active DevDome plugin on the site plus the bundled DevDome library, WordPress and PHP versions, so your DevDome account can show your sites and their DevDome plugins for support and update notices. Nothing about other plugins, users, email addresses, content or visitors is included. Sites connected before this was introduced, and sites connected from a button that does not show that text, do not send the list. Disconnecting stops the checks and the plugin list. Terms: https://devdome.com/terms-of-service. Privacy: https://devdome.com/privacy-policy

= Self-hosted edition only =

* `https://api.devdome.com/plugin-updates/devdome-admin-cleaner.json`: the self-hosted update manifest, checked from wp-admin and cron (never on front-end requests), cached six hours, ten-second timeout. The request sends no site data; it is a plain download. The updater validates the manifest (trusted host, HTTPS, matching plugin, sane version) and, when the manifest publishes a checksum, verifies the downloaded package against it before installation; on any failure the installed version keeps working.
* `https://api.devdome.com/bot-protection/*`: the shared once-daily bot-detection reference lists (verified-bot user-agent patterns and datacenter/bad-IP networks) used by other DevDome plugins. A one-way download; no data about your site or visitors is sent.

== Privacy ==

* **Your Admin Cleaner data stays local.** Settings, notice records and mutes are stored only in your own WordPress options table and are removed on uninstall.
* **Stored notice excerpts are redacted first.** Email addresses, tokens, keys, nonces, URL query strings and filesystem paths are stripped before anything is saved, notice excerpts are capped at 160 characters, and raw notice HTML is never stored.
* **Retention is bounded.** At most 200 notice records are kept; unresolved protected notices are never discarded to satisfy the cap (you are warned instead). Expired snoozes and orphaned mutes are cleaned automatically.
* **You can delete everything.** "Delete all notice data" removes every stored record and mute on demand; your settings stay as they are.
* **No cookies** are set by this plugin and no visitor data leaves your site.

= AI agents and MCP =

On WordPress 6.9 and newer, Admin Cleaner registers 14 WordPress Abilities covering the whole plugin, so an AI agent or MCP client (through the official WordPress MCP Adapter) can do what the screen does and a little more: get-status, get-settings, get-notices (filter by status, type or a search word, sort, page), get-notice (one in full), clean-my-admin, undo-last-change, update-settings, notice-action (snooze1, snooze7, hide, hide_admins, restore), snooze-notices (1 to 90 days), delete-notices, clear-all-mutes, delete-notice-data, refresh-summary and run-housekeeping. Every ability requires the plugin capability (manage_options by default); the actions that hide for every administrator, the ones that delete stored records (delete-notices, delete-notice-data, run-housekeeping), clear-all-mutes and an undo that would turn Auto-hide on require confirm: true. Agent output never carries e-mail addresses or server paths. On older WordPress versions nothing is registered.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it, or install it from the Plugins screen.
2. Open **DevDome Tools → Admin Cleaner**.
3. Click **Clean My Admin** to apply cleanup, or use the tabs to choose exactly what to hide.
4. Set up **Client Mode** if you hand the site to editors or clients. **Undo last change** reverses your most recent settings change; restore notice mutes separately.

== Frequently Asked Questions ==

= How do I hide admin notices? =

Open DevDome Tools > Admin Cleaner > Notice Inbox. Select notices, choose Hide (you), Hide for all admins or a snooze action, then press Apply. To remove admin notices from view in one step, use Clean My Admin. It also enables Auto-hide new notices.

= How do I remove dashboard widgets? =

Open the Dashboard Widgets tab. Select the widgets you want to hide and press Save Settings. Built-in widgets have individual options. Widgets added by plugins and themes have a shared option. Clean My Admin turns on all these hiding options.

= Is anything deleted when I clean the dashboard? =

Clean My Admin hides interface items and notices. It does not delete your content. Remove and Delete all notice data permanently delete stored inbox records and their mutes. Retention housekeeping can also delete eligible records. A deleted notice can return if its source displays it again. Uninstalling removes the plugin's settings and notice data.

= Does it hide update notices or disable admin notifications? =

It can hide on-screen update, error and security notices, including those marked Critical. Clean My Admin also hides the toolbar Updates item and plugin list notice rows. It does not disable updates or notification emails. Restore captured notices from the inbox and turn off Auto-hide if you want new notices to remain visible.

= What does Client Mode do? =

Client Mode hides selected menu entries for the roles you choose. It can also redirect the theme editor, plugin editor and Updates screens to the dashboard. Permissions stay unchanged. Users with manage_options, the Admin Cleaner capability or network management privileges are exempt. Notice hiding is separate and applies only to users who can access Admin Cleaner.

= Does it work on multisite? =

Settings and notice records are stored separately for each site. Configure Admin Cleaner from each site's dashboard. The code also handles network dashboard widgets and network admin notice hooks. There is no central screen for applying one configuration across every site.

= How do I undo the cleanup? =

Undo last change restores the settings from before the most recent settings change. It keeps one recovery point. Restore notices separately in the inbox, or use Clear all mutes. Turn off Auto-hide new notices to stop hiding future notices. You can also show individual widgets and toolbar items again through their settings. Deactivation stops the plugin's cleanup while preserving its saved data.

= How do I disable admin notices or hide notifications on screen? =

Enable Auto-hide new notices to hide supported notices, including those added after page load. Use the Notice Inbox to hide or snooze captured notices. These settings change on-screen visibility for users who can access Admin Cleaner; they do not stop the source plugin from generating notices or sending notification emails.

= How do I hide admin menu items for client roles? =

Open Client Mode, choose the roles and select the supported menu entries to hide. Registered custom roles are available too. Users with manage_options, the Admin Cleaner capability or network management privileges are exempt. Hiding an entry does not remove access allowed by the user's permissions.

= Can I make a custom dashboard by hiding existing widgets? =

Yes. Select which built-in dashboard widgets to hide and use the shared option for widgets added by plugins and themes. This custom dashboard setup simplifies the existing WordPress dashboard; it does not add new widgets or build a replacement dashboard.

== Screenshots ==

1. Overview: Clean My Admin, the workspace clutter estimate and what is hidden right now.
2. Notice Inbox: every captured notice with its source, type and status. Hide, snooze, restore or remove in bulk.
3. Dashboard Widgets: choose which dashboard widgets and footer items to hide.
4. Admin Bar: hide the WordPress logo, Comments, New, Updates, Customize and items added by plugins.
5. Client Mode: pick the roles and the menu entries they no longer see. Permissions stay unchanged.

== Changelog ==

= 1.0.9 =

* Shared DevDome library 1.7.10: the one-time Report a bug hint is recorded through a nonce-checked request instead of on a page view; the DevDome dashboard lists only real problems and prints its icons through the WordPress escaping functions.
* The plugin screen is printed directly instead of being buffered, and every value is escaped where it is printed (WordPress.org review rule).
* Listing text updated: title, short description, tags and introduction.

= 1.0.8 =

* AI agents get setting names and role names back unchanged; the output filter no longer treats a long name as a secret.
* Bundled DevDome library 1.7.6: if you connect a DevDome account from the DevDome Tools dashboard, the Connect card now says exactly what is shared, including the list of active DevDome plugins and their versions. Sites that were already connected, and sites that never connect, send nothing new. See External services.

= 1.0.7 =

* The notice capture always closes its own output buffer, even when another plugin leaves one open above it.
* The bundled DevDome library loads WordPress admin files only where they are needed.

= 1.0.6 =

* Security review pass: every request handler checks capability and nonce inline before reading input, all inputs sanitised on the line they are read, output escaping tightened, the bundled DevDome library verifies nonces before any lookup, and the account connect return is bound to the user who started it.
* Notice redaction now covers HTTP Authorization headers and any absolute server path; late-inserted notices reappear if the plugin does not answer within 8 seconds.
* External services disclosure corrected to list every field an error report carries.

= 1.0.5 =

* Settings use plain checkboxes on the left of each option, saved with the Save Settings button, instead of toggle switches; nothing changes until you save.

= 1.0.4 =

* Connect fix (shared DevDome core 1.6.6): the connect claim now waits up to 30 seconds and keeps the handshake for 20 minutes so a refresh retries it, the DevDome hub shows why a connect failed with a Try again link, and the verify file is served through a query form for hosts that answer /.well-known/ before WordPress.

= 1.0.3 =

* First tab is now called Overview, in line with the other DevDome plugins; old links to the Dashboard tab still open it. Overview status card in the suite style, sticky Save Settings footer on every settings tab, darker readable text, consistent spacing under the tab bar.

= 1.0.2 =

* Design: every screen now follows the shared DevDome admin frame (section heads above cards, blue header badge, bug-report button, standard Save button, suite status colours).
* Notice Inbox is a data list with status badges and grouped row actions.
* Confirmations open an in-page dialog instead of the browser prompt.
* Copy: no dashes in customer-facing text.

= 1.0.1 =

* DevDome Dashboard: plugin list, descriptions, logos and versions now come from devdome.com, one-click install of DevDome plugins from WordPress.org, Docs link and Fix buttons, Activate stays on the dashboard.

= 1.0.0 =

* First WordPress.org release. Every identifier now uses the devdadcl_ prefix; shared DevDome core 1.6.0 (site token sent in a POST header, never in a URL).

= 0.2.0 =

* Notice storage rebuilt: one record per notice, so concurrent administrators can no longer overwrite each other's changes, reported counts are true counts, and storage limits never discard an unresolved protected notice.
* Notice identity v2: a changed operational notice no longer inherits a mute stored for an older, different message.
* Stored excerpts are redacted (emails, tokens, keys, query strings, paths) before persistence.
* Mute scope is explicit: hide for yourself or for all administrators; the status column shows who is affected.
* The clutter number is now a documented workspace estimate computed from real state: muted promos stop counting, hiding Site Health or Updates never improves it, and client exposure comes from real users instead of a hardcoded value.
* Presets show the exact diff against your current settings before applying, and Undo last change restores the prior configuration exactly.
* Reset plugin settings, Clear all mutes and Delete all notice data are now separate, honestly named actions.
* Client Mode: capability-based exemptions (manage_options always wins), dynamic role discovery including custom roles, duplicate editor toggle merged, and every security-sounding claim replaced with the cosmetic truth.
* Shared DevDome core updated to 1.4.7: the suite dashboard makes no request to DevDome before you explicitly connect, and its Connect button states what connecting shares.
* Self-hosted updater hardened: manifest validation (trusted host, HTTPS, matching plugin, sane version), fail-closed behavior, and package checksum verification when published.
* Summary cache refreshes immediately after every change, with a visible last-updated time and manual refresh.

= 0.1.8 =

* Expanded External services disclosure.

= 0.1.7 =

* Readme copy polish.

= 0.1.6 =

* Notice Inbox: added a **Remove** button next to Restore on every row that forgets a notice entirely (drops it from the inbox and the mute list), distinct from Restore which only un-hides it.
* Performance: the notice inbox now writes at most once per admin request instead of once per notice, and settings are read from a single memoized autoloaded option, cutting redundant database work on every admin page.
* Design: dashboard-widget, admin-bar and Client Mode settings now use compact toggle switches on the DevDome suite design system.
* Accuracy: corrected the External services and Privacy sections to document the shared DevDome suite bot-detection download; removed dead detection scaffolding and a duplicate theme-editor hide.

= 0.1.0 =

* Initial release: one-click admin cleanup with a live clutter score, a notice inbox with snooze/mute/restore (criticals always kept visible), per-widget dashboard cleanup, admin-bar and footer/version hiding, role-based Client Mode, and five ready-made presets.
