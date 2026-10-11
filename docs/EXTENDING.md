# Observing committed Egress Eye events

Register listeners with WordPress `add_action()` for these read-only notifications:

| Action | Single array argument | Timing |
|---|---|---|
| `egress_eye_slow_request_recorded` | `host` (canonical string), `duration_us` (integer), `is_loopback` (boolean) | After aggregate and event are successfully written and persistence locks released |
| `egress_eye_block_state_changed` | `host` (canonical string), `user_id` (integer), `previous_state` / `new_state` (booleans) | After an actual transition commits and both persistence locks are released |

Duplicate desired-state POSTs succeed without another audit or change event.
Fast requests, preempted requests and incomplete failed writes emit no event.
Payloads have no URL, path, query, header, body, HTTP arguments, trace or private
sample identity. Changes to a listener's array cannot change persisted state.

Listener `Throwable` is contained. The named notification and WordPress `all`
hook use temporary execution objects; interrupted state is discarded without
replacing active outer hook objects. Successful add/remove registrations persist.
A throwing listener can prevent later listeners receiving that one event. The
boundary does not sandbox arbitrary observer code, repair unrelated hooks, or
contain `exit`, infinite loops or process termination. Keep listeners short and
avoid initiating network requests or altering plugin behavior.

Listeners should never throw. Defensive hook-state isolation is retained even
when no named listener is installed: third-party instrumentation on `all` sees
each action and can throw. A plain try/catch cannot restore the WordPress
current-filter stack or interrupted WP_Hook iteration state. Native tests cover
throwing `all`, recursive notifications and successful registration changes.

The production entry owns one composition root and one block guard per PHP
request. Its dependencies use persistence/HTTP contracts. The guard keeps its
one snapshot for the request; a committed admin POST becomes effective in the
next PHP request. Versioned schema/options stay under the plugin namespace.
There are no mutable filters, new settings or locked features. A future commercial
extension would be a separate plugin; free functionality remains available.
