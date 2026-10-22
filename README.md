# Beacon Events

A free events calendar for WordPress, for one-off events and repeating ones alike. This is the same code as the plugin on wordpress.org; install it from there so you get updates:

**https://wordpress.org/plugins/beacon-events/**

You can try it in your browser first, with sample events and nothing to install: https://wordpress.org/plugins/beacon-events/?preview=1

There is also a live demo site with the list, week, month and day views: https://beacon-events.duckdns.org/demo/

## What it does

- Recurring events are free: daily, weekly, monthly ("second Tuesday", "last Friday", "the first and third Thursday"), yearly, with an end date or a count, skipped dates, and single dates you can cancel or move. Rules follow RFC 5545 and expand in the event's own time zone, so a 6 pm class stays at 6 pm across daylight saving changes. Occurrences live in their own indexed table, so a month view is one query.
- List, week (a class timetable that stacks into rows on phones), month and day views, as blocks, shortcodes or an Elementor widget, with an optional filter bar. The stylesheet is layout only; colours and fonts come from your theme.
- Google Calendar and ICS feed sync with a log of what changed, an iCal export, and "Add to Google Calendar" links.
- One-click import from The Events Calendar, Events Manager, Sugar Calendar, Eventin, Modern Events Calendar, EventON, WP Event Manager, WP Events Manager (ThimPress), All-in-One Event Calendar or a CSV file, with a dry run first and a per-event report. Old event links are redirected.
- Combines events that were entered one date at a time into one repeating event, including copies with the date in the title.
- A printable month for noticeboards, schema.org data for search engines, German included.
- WP-CLI: `wp beacon sources`, `wp beacon import <source> [--dry-run] [--report=<file>]`, `wp beacon combine`.

Tickets, RSVPs and class sign-ups (Stripe or WooCommerce, waitlist, QR check-in, Outlook sync) are a separate paid add-on and are not in this repository.

## Where things are

- `includes/class-recurrence.php` is the recurrence engine: plain PHP, no WordPress calls, an RRULE subset (FREQ, INTERVAL, BYDAY with positions, BYMONTHDAY, COUNT, UNTIL, EXDATE) that expands to occurrence dates.
- `includes/class-events.php` registers the `beacon_event` post type, materialises occurrences into the table, and exposes `beacon/v1/occurrences` over REST for the editor.
- `includes/class-ics.php` imports ICS feeds (a Google Calendar's public iCal address works), re-syncs them hourly through WP-Cron, writes a log of every sync, and serves the iCal export.
- `includes/class-views.php` renders the views, the `beacon/events` and `beacon/term-details` blocks, the `[beacon_events]` and `[beacon_event_details]` shortcodes, the print page and the schema.org markup. `includes/class-elementor.php` and `elementor-widget.php` are the Elementor widget.
- `includes/class-import.php` and `includes/import/` hold the ten import sources and the redirects for old event URLs; `includes/class-combine.php` merges one-date-at-a-time events into a repeating one; `includes/class-cli.php` is the `wp beacon` command.
- `includes/class-schema.php` creates the occurrences table with `dbDelta`; `languages/` has the German translation; `uninstall.php`.

## Bugs and ideas

Open an issue here or a thread on the [wordpress.org support forum](https://wordpress.org/support/plugin/beacon-events/). Pull requests are welcome.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
