# Beacon Events

A free events calendar for WordPress, for one-off events and repeating ones alike. This is the same code as the plugin on wordpress.org; install it from there so you get updates:

**https://wordpress.org/plugins/beacon-events/**

You can try it in your browser first, with sample events and nothing to install: https://wordpress.org/plugins/beacon-events/?preview=1

## What it does

- Recurring events are free: daily, weekly, monthly ("second Tuesday", "last Friday", "the first and third Thursday"), yearly, with an end date or a count, skipped dates, and single dates you can cancel or move. Rules follow RFC 5545 and expand in the event's own time zone, so a 6 pm class stays at 6 pm across daylight saving changes. Occurrences live in their own indexed table, so a month view is one query.
- List, week (a class timetable that stacks into rows on phones), month and day views, as blocks, shortcodes or an Elementor widget, with an optional filter bar. The stylesheet is layout only; colours and fonts come from your theme.
- Google Calendar and ICS feed sync with a log of what changed, an iCal export, and "Add to Google Calendar" links.
- One-click import from The Events Calendar, Events Manager, Sugar Calendar, Eventin, Modern Events Calendar, EventON, WP Event Manager, WP Events Manager (ThimPress), All-in-One Event Calendar or a CSV file, with a dry run first and a per-event report. Old event links are redirected.
- Combines events that were entered one date at a time into one repeating event, including copies with the date in the title.
- A printable month for noticeboards, schema.org data for search engines, German included.
- WP-CLI: `wp beacon sources`, `wp beacon import <source> [--dry-run] [--report=<file>]`, `wp beacon combine`.

Tickets, RSVPs and class sign-ups (Stripe or WooCommerce, waitlist, QR check-in, Outlook sync) are a separate paid add-on and are not in this repository.

## Bugs and ideas

Open an issue here or a thread on the [wordpress.org support forum](https://wordpress.org/support/plugin/beacon-events/). Pull requests are welcome.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
