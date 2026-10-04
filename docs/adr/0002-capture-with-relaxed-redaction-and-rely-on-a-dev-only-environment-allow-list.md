# Capture with relaxed redaction and rely on a dev-only environment allow-list

Firewatch records payloads, headers, bindings and free text unredacted by default and relies on a dev-only environment allow-list, vetoed transmission and a private on-disk store instead of redaction. The redacted value is usually the answer when debugging locally, and the store file never leaves the machine, though what an assistant reads from it reaches its model provider.

Alternatives were default substring redaction lists, a strict mode, and a "not production" deny-list. The decision is empty redaction defaults with opt-in lists, and safety by construction: environment allow-list, transmit veto, a `0600` store in a `0700` directory. Consequences: anything written to the store is sensitive local data and the README must say so, and adding default redaction later changes what existing answers can show.
