=== Egress Eye ===
Contributors: sam465
Tags: http, performance, diagnostics
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Records slow WordPress HTTP API requests and lets administrators manually block exact hosts.

== Description ==

Egress Eye helps administrators inspect slow server-side requests made through
the WordPress HTTP API. It records blocking requests lasting at least one
second (>= 1 second). Counts and averages include only these slow requests,
not every outgoing request.

Open Tools > Egress Eye to view each host, slow-request count, last and mean
duration, and probable source. The probable plugin, theme or core source is a
guess: wrappers, cron and shared code can make attribution uncertain.

Blocking is always a manual administrator action. It matches only the exact
host: blocking example.com does not also block api.example.com, aliases or a
literal IP for the same server. A blocked literal IP only matches that IP.
Blocking applies to the initial WordPress HTTP API destination; a later
cross-host redirect destination is not guaranteed to be blocked.
Direct cURL, other non-WordPress transports and browser requests are not covered.

Blocking your site's own host or loopback host can interrupt cron and other
essential tasks. A separate confirmation is required; unblock remains available.
No automatic blocking, external service, telemetry, CDN or paid feature lock is
included. The admin stylesheet and Persian translation are bundled locally.

= Compatibility and storage limits =

Version 1 supports single-site WordPress installations; multisite activation
is refused. Diagnostics can be recorded on MyISAM, but manual block/unblock
requires both diagnostic domains and audit tables to use InnoDB so the state
change and audit commit together. Ask your host about table formats.

Latest compatibility checks ran on WordPress 7.1.3 with PHP 8.3,
MariaDB 10.11 and MySQL 8.0.

Database execution is tested on MariaDB 10.11 and MySQL 8.0 only. Other
versions with simultaneous named-lock support (MariaDB 10.0.2 or later,
MySQL 5.7.5 or later) are expected to work but are not tested. Manual
block/unblock takes two nested GET_LOCK locks. Older server versions release
the first lock when the second is acquired and cannot preserve this protection;
they are not supported for manual block/unblock. Ask your host before use.

Storage is bounded to 500 domains, 500 events and 500 audit records. Events are
retained for up to 30 days and audits for up to 180 days, subject to those caps.
Blocked hosts are not automatically evicted. At full domain capacity, new
hosts may not be recorded until retention removes an old unblocked host.
A PHP process holds at most 64 active measurements. Late-preempted requests
can occupy these slots until shutdown/reset; further requests still proceed
and manual blocking still applies. A fresh PHP request is recommended for
each custom long-running worker operation.

= Performance =

A measured shared CI runner (WordPress 7.1.2, PHP 8.3.35, MariaDB 10.11.19)
used 20 warmup pairs and 100 measured pairs of real local HTTP calls.
The active median was about 702 microseconds versus 682 microseconds
without the HTTP observer (about 20 microseconds difference); active p95 was
about 743 microseconds. The measured overhead was a few tens of microseconds
on this shared runner; absolute numbers fluctuate between runs. Warm requests
performed zero SQL queries and zero writes.
A cold blocked-host snapshot used two SQL queries. These are full local HTTP
request measurements on a noisy shared runner, not a universal overhead
guarantee or a claim that Egress Eye makes a site faster.

= Privacy and deletion =

Data stays in this site's database. Recorded diagnostics contain canonical
host, elapsed duration, UTC time, HTTP status or error code, component kind/key
(a probable source), loopback flag and internal sample identifiers. Manual
action audits contain host, administrator user ID, action, previous/new state
and UTC time. Owned options contain schema/cache/maintenance/capacity metadata.
A short-lived administrator notice ticket is stored in user metadata.

Full URLs, paths, query strings, request headers, bodies, response bodies and
backtrace arguments are not stored. Absolute filesystem paths are not displayed.
The plugin sends none of this information outside the site and makes no outgoing
connections of its own.

Deactivation keeps data. Deleting the plugin using WordPress uninstall removes
all its diagnostic tables, owned options, administrator notice tickets and
maintenance cron events. There is no keep-data setting in version 1.

== Installation ==

1. Back up your site and test on a single-site staging installation.
2. Upload egress-eye.zip using Plugins > Add New > Upload Plugin, then activate.
3. After a blocking HTTP API request lasts at least one second, open Tools > Egress Eye.
4. Review the exact-host and loopback warnings before manually blocking a host.

== Frequently Asked Questions ==

= Does it monitor or block every network connection? =

No. Only WordPress HTTP API requests are covered. Statistics include only slow
blocking requests. Direct cURL, browser requests and redirect destinations
beyond the initial host are outside the guarantee.

= Is the probable source certain? =

No. It is explicitly labeled as a guess.

= Does deleting the plugin delete its data? =

Yes. WordPress uninstall removes all owned data and maintenance events.

== Screenshots ==

1. Slow requests by exact host, with probable source labeled as a guess.
2. Manual block/unblock and loopback confirmation warning.
3. Recent manual actions with administrator user ID and UTC time.

== Changelog ==

= 1.0.0 =
* Initial release: slow WordPress HTTP API diagnostics, manual exact-host blocking,
  administrator audit, bounded storage, local styling and Persian translation.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Use a staging site and read the blocking limitations first.
