# MQTT connection idle polling

`RuntimeClientIdConnectionManager` accepts per-connection `idle_sleep_ms` as an
integer or integer string from 5 to 100. The default is 100ms. Invalid values fail
with `ConfigurationInvalidException` before opening a socket.

The setting is captured when the client is created. Recreate the connection or
restart LaravelS after changing it. It is not refreshed per message.

`RuntimePrefixedMqttClient` delegates `loopOnce()` to php-mqtt/client. Only an
omitted third argument with `allowSleep=true` uses the configured value. Explicit
sleep arguments, including named arguments, retain their original semantics.
The parent only sleeps when its socket buffer is empty; received messages are
processed immediately. Parent interrupt/reset, queue exits, resend and keepalive
logic remain in use, and socket/reconnect exceptions still propagate.

The host exposes `LANCE_LOCATION_MQTT_IDLE_SLEEP_MS` only for the named
`lance_location_data` connection. It defaults to 100ms pending controlled 20ms
testing. DIY and other connections keep their current 100ms default. This does
not defer realtime cache writes, coalesce messages or weaken history/alarm checks.

Validation: focused tests use the real php-mqtt/client v2.3.2 parent, isolated
sleep instrumentation, buffered PUBLISH decoding and a localhost TCP CONNECT/
CONNACK exchange through the actual manager. A fake socket read checks exception
propagation; a full broker reconnection/failure campaign is not covered here.
Measured CPU and each-tag P99 comparisons are still required before claiming a
capacity improvement. Installation defaults and production have not changed.
