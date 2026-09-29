# Mirror Nightwatch's wire contract instead of curating a model

Firewatch stores the twelve Nightwatch event types and the user record one-to-one under wire field names and keeps the wire group hash verbatim, adding only a uniform identity and clock, a JSON `data` column for unknown fields, and query bindings. A curated model with Firewatch names and fewer fields would be smaller and easier to read, but would need its own mapping per Nightwatch release and would hide drift.

Mirroring means Nightwatch's documentation applies as written, groups match what Nightwatch shows, unknown or changed fields are visible by name, and fields the sensors never fill (four counters, two failure flags) stay in the model and are documented as "not measured". Consequence: the schema inherits the wire's quirks (for example the `type` collision on cache events, resolved by storing the cache kind as `event`), and every deviation is enumerated in one list.
