# SQLite facts for a local telemetry store: findings

Facts only, no decisions. Spike environment: PHP 8.5.8 (static build), SQLite 3.45.2, macOS. Sources: sqlite.org documentation, php-src stubs and C sources on PHP-8.3, 8.4 and 8.5, Debian/Alpine/Ubuntu/Homebrew package metadata, and the docker-library PHP Dockerfiles. Caveats are at the end.

## 1. WAL and busy handling

- Many readers plus one writer; readers and the writer do not block each other. WAL needs shared memory (`-shm`), so same host only, no network filesystems. (https://www.sqlite.org/wal.html)
- WAL mode persists in the database file. `-wal` and `-shm` are deleted when the last connection closes.
- Auto-checkpoint is PASSIVE at 1000 pages. A checkpoint cannot reset the WAL while any reader is open; with overlapping readers the WAL "will grow without bound". `journal_size_limit` defaults to -1.
- Spike: `wal_checkpoint(TRUNCATE)` with a reader open returned `[1,20,19]` (busy); without the reader it returned `[0,0,0]`.
- `synchronous=NORMAL` in WAL does not sync on commit: the last transactions can be lost on power loss, but the database is not corrupted. The spike build defaults to `synchronous=2` (`DEFAULT_WAL_SYNCHRONOUS=2`), so the default is build-dependent.
- `page_size` cannot change in WAL mode.
- WAL databases are readable read-only since 3.22.0 if `-shm`/`-wal` exist, or the directory is writable, or `immutable=1`.
- Result codes (https://www.sqlite.org/rescode.html): `BUSY` 5, `BUSY_RECOVERY` 261, `BUSY_SNAPSHOT` 517 (read-to-write upgrade after another connection wrote), `BUSY_TIMEOUT` 773; also `READONLY_RECOVERY` 264, `READONLY_CANTINIT` 1288, `AUTH` 23, `INTERRUPT` 9, `FULL` 13, `CORRUPT` 11, `NOTADB` 26.
- Transactions (https://www.sqlite.org/lang_transaction.html): a DEFERRED read-to-write upgrade fails with BUSY if another connection has modified the database; IMMEDIATE takes the write lock at BEGIN; EXCLUSIVE equals IMMEDIATE in WAL.
- There is no busy handler by default. One exists per connection, and `busy_timeout` replaces it. SQLite skips the busy handler when it could deadlock (https://www.sqlite.org/c3ref/busy_handler.html).
- Spike: a `BEGIN IMMEDIATE` while another connection held the write lock gave an immediate `5 database is locked` (timeout 0); reads still worked during the write lock; after another connection committed, a deferred read-then-write on the first connection gave `5 database is locked` even with a 1 s timeout.
- PDO SQLite's default timeout is 60 s (`PRAGMA busy_timeout` = 60000). `PDO::getAttribute(ATTR_TIMEOUT)` throws "driver does not support that attribute". `SQLite3` has `busyTimeout(ms)`; its default was not measured.
- `Pdo\Sqlite::ATTR_TRANSACTION_MODE` (DEFERRED/IMMEDIATE/EXCLUSIVE) exists in PHP 8.5 only (php-src PHP-8.5 UPGRADING).

## 2. The WAL-reset bug

- A data-corruption bug present in SQLite 3.7.0 through 3.51.2 (2026-01-09). Fixed in 3.51.3 (2026-03-13), with backports in 3.44.6 and 3.50.7.
- Trigger: two or more connections in separate threads or processes write or checkpoint at the same instant. The documentation calls it a tight race "unlikely to occur in common use". (wal.html "WAL-Reset Bug"; https://www.sqlite.org/howtocorrupt.html section 8)
- Unpatched upstream versions seen in the builds below: 3.37.2, 3.40.1, 3.45.1/3.45.2, 3.46.1, 3.48.0, 3.49.2. Distribution backports were not checked.

## 3. JSON (https://www.sqlite.org/json1.html)

- Built in since 3.38.0 (`->` and `->>` too). JSON5 and `json_error_position` in 3.42.0. JSONB and `jsonb_*` in 3.45.0. `json_pretty` in 3.46.0.
- All JSON functions are DETERMINISTIC and INNOCUOUS, so they work in generated columns and indexes even with `trusted_schema=0`.
- Malformed JSON makes most functions error with "malformed JSON"; `json_valid()` returns 0 instead. `json_valid(x,6)` accepts JSON5 or JSONB.
- `json_extract` returns SQL types: JSON null becomes SQL NULL, strings are dequoted, true/false become 1/0. `json_each` and `json_tree` exist.
- Spike: `json_extract`, `->>` and `jsonb()` work on 3.45.2; `CHECK(json_valid(p))` rejects invalid text with an integrity error (SQLSTATE 23000).

## 4. Generated columns (https://www.sqlite.org/gencol.html)

- Added in 3.31.0 (2020-01-22). Older versions treat such a schema as corrupt.
- `ALTER TABLE ADD COLUMN` can add VIRTUAL but not STORED. Expressions must be deterministic, with no subqueries or aggregates. They cannot be PRIMARY KEY or have DEFAULT. Both kinds can be indexed.
- Spike: ADD STORED gives "cannot add a STORED column"; ADD VIRTUAL works; an index on a virtual generated column is used in the query plan; inserting non-JSON text into a table with a virtual `json_extract(...)` column fails at INSERT with "malformed JSON". The documentation does not say when expression errors surface.

## 5. Views (https://www.sqlite.org/lang_createview.html)

- Read-only unless INSTEAD OF triggers exist. Expanded inline at query time (no materialization). TEMP views are per connection. The documentation recommends explicit column names. A view over `json_extract` works in the spike.

## 6. Untrusted read-only SQL: SQLite controls

- **Authorizer** (https://www.sqlite.org/c3ref/set_authorizer.html): runs at prepare time only, one per connection. Returns OK, IGNORE (READ becomes NULL) or DENY (error 23). Action codes (https://www.sqlite.org/c3ref/c_alter_table.html): SELECT 21, READ 20, FUNCTION 31, PRAGMA 19, ATTACH 24, INSERT 18, UPDATE 23, DELETE 9, TRANSACTION 22, SAVEPOINT 32, RECURSIVE 33. Already-prepared statements are not re-authorized.
- **Limits** (https://www.sqlite.org/c3ref/limit.html, https://www.sqlite.org/limits.html): `sqlite3_limit` can only lower limits, per connection. Defaults: SQL length 1e9, expression depth 1000, VDBE ops 250M, compound select 500, 64-table join maximum. Function arguments default to 1000 from 3.48.0 (the spike build shows 127). security.html suggests SQL_LENGTH 100000, EXPR_DEPTH 10, VDBE_OP 25000.
- **Runaway queries**: `sqlite3_progress_handler` (non-zero return interrupts), `sqlite3_interrupt` (returns `SQLITE_INTERRUPT`), and `sqlite3_hard_heap_limit64` for memory (https://www.sqlite.org/security.html).
- `SQLITE_DBCONFIG_DEFENSIVE` blocks `writable_schema`, `journal_mode=OFF`, `schema_version` writes and `sqlite_dbpage` writes.
- `trusted_schema` defaults ON. The documentation recommends OFF on every connection. When OFF, functions in views, CHECK constraints and generated columns must be INNOCUOUS. The spike default is 1.
- `PRAGMA query_only=1` blocks writes (error 8), but SQL can turn it back off. Confirmed in the spike.
- `SQLITE_OPEN_READONLY`: in the spike a write fails with "attempt to write a readonly database".
- `pragma_*()` table-valued functions exist since 3.16.0, for side-effect-free pragmas only.
- Spike: a recursive CTE of 3M rows finished in 0.2 s. Nothing in PHP can interrupt a long query (section 7).

## 7. PHP exposure

Sources: php-src stubs and C on PHP-8.3, 8.4 and 8.5.

- **Authorizer**: `SQLite3::setAuthorizer` exists in 8.3, 8.4 and 8.5. `Pdo\Sqlite::setAuthorizer` exists in the 8.5 stub only (absent from the 8.4 stub). The callback signature is `(int $action, ?string $a1, ?string $a2, ?string $db, ?string $trigger)`, returning `OK`/`DENY`/`IGNORE`. PHP's own authorizer, used for `open_basedir` on ATTACH, still runs first; the userland callback is called from inside it, so it does not replace it.
- **Not exposed** by `SQLite3` or PDO in any version: progress handler, interrupt, `sqlite3_limit`, serialize/deserialize, heap limits. `setLimit`, `setProgress` and `serialize` are absent per `method_exists` and grep.
- **Available**: `SQLite3`: `busyTimeout`, `loadExtension` (needs the `sqlite3.extension_dir` ini, empty in the spike), `backup`, `openBlob`, `createFunction`/`createAggregate`/`createCollation`, `SQLite3Stmt::readOnly()`. `Pdo\Sqlite` (8.4+): `ATTR_OPEN_FLAGS`, `OPEN_READONLY`, `ATTR_READONLY_STATEMENT`, `loadExtension`, `createFunction`. PHP 8.5 only: `ATTR_EXPLAIN_STATEMENT`, `ATTR_BUSY_STATEMENT`, `ATTR_TRANSACTION_MODE`.
- **Spike**, `Pdo\Sqlite` authorizer allowing only SELECT/READ/FUNCTION: INSERT, `PRAGMA journal_mode`, ATTACH and `load_extension()` are all denied; `ATTR_READONLY_STATEMENT` is true for `select` and false for `delete`.
- `sqlite3.defensive` ini defaults to 1. It is set for the `SQLite3` class only, when built against SQLite 3.26 or newer (`sqlite3.c`). No DEFENSIVE call was found in the pdo_sqlite sources on 8.5. Neither extension exposes `trusted_schema` via db_config; use `PRAGMA trusted_schema=0` in SQL.
- **Multi-statement behaviour** (spike): `PDO::query` and `PDO::prepare` run only the first statement; `PDO::exec`, `SQLite3::query` and `SQLite3::exec` run all statements.

## 8. Resource knobs (https://www.sqlite.org/pragma.html)

- `PRAGMA max_page_count=N` caps database size (spike: set to 10, read back 10; default 4294967294). Hitting it gives `SQLITE_FULL`.
- Other defaults in the spike build: `cache_size` -2000, `mmap_size` 0, `temp_store` 1 (file).
- Maximum database size is about 281 TB at 64 KB pages.

## 9. Corruption and recovery (https://www.sqlite.org/howtocorrupt.html)

- Relevant causes: deleting a hot `-wal`/`-journal`; copying the database mid-transaction (safe alternatives: backup API, `VACUUM INTO`, `sqlite3_rsync` from 3.47.0); carrying an open connection across `fork()`; unlink, rename or recreate while another process has the database open; hard or soft links; a stray `close()` on the file dropping POSIX locks; network filesystems; `synchronous=OFF`, `journal_mode=OFF|MEMORY` with a crash, `writable_schema`.
- Detection: `integrity_check(N)` / `quick_check(N)` return `ok` or up to 100 messages by default. `quick_check` skips UNIQUE and index-to-table checks. `cell_size_check` is off by default. Spike `quick_check` returned `ok`.
- WAL recovery runs automatically on the next open under an exclusive lock; other openers see `BUSY_RECOVERY`.
- The documentation gives no in-place repair.

## 10. Deletion and space

- DELETE fills the freelist and the file does not shrink. Spike: 4,341,760 bytes before and after a DELETE, with `freelist_count` 1056.
- `auto_vacuum` defaults to 0. It must be set before tables exist, or `VACUUM` is needed to switch. Modes are FULL (truncate at commit) and INCREMENTAL (`PRAGMA incremental_vacuum(N)`). Spike: with incremental set first, the file went from 4,341,760 to 12,288 bytes.
- `VACUUM` (https://www.sqlite.org/lang_vacuum.html) needs up to 2x free disk space, cannot run inside a transaction, and needs a write lock. Rowids without an INTEGER PRIMARY KEY may change. Spike: it worked on a WAL database with a reader open.
- `VACUUM INTO` was added in 3.27.0 (2019-02-07). It makes a consistent compacted copy alongside readers.
- `secure_delete` defaults OFF (FAST since 2017-08). `DROP COLUMN` arrived in 3.35.0.
- WAL size is reclaimed separately: `wal_checkpoint(TRUNCATE)` and `journal_size_limit`.

## 11. Versions

- PHP does not bundle SQLite. It links the system library, minimum 3.7.7 (`config0.m4` on 8.3, 8.4 and 8.5). The docker-library Dockerfiles say "always build against system sqlite3".
- Observed: the static PHP 8.5.8 build uses 3.45.2.
- Docker `php:8.3`, `8.4` and `8.5`: `bookworm` is Debian 12 with SQLite 3.40.1; `trixie` is Debian 13 with 3.46.1; `alpine3.23` has 3.53.4; Alpine 3.20, 3.21 and 3.22 ship 3.45.3, 3.48.0 and 3.49.2.
- Ubuntu: 22.04 has 3.37.2, 24.04 has 3.45.1, 25.10 has 3.46.1.
- Homebrew: `sqlite` is 3.53.4; `php` (8.5.11), `php@8.4` (8.4.26) and `php@8.3` (8.3.35) depend on it.
- Release dates (https://www.sqlite.org/changes.html): 3.35.0 2021-03-12, 3.38.0 2022-02-22, 3.42.0 2023-05-16, 3.45.0 2024-01-15, 3.45.2 2024-03-12, 3.46.1 2024-08-13, 3.47.0 2024-10-21, 3.48.0 2025-01-14, 3.51.0 2025-11-04, 3.51.3 2026-03-13, 3.53.0 2026-04-09, 3.53.4 2026-07-24 (latest listed).

Feature floors by build:

| Feature | Min SQLite | 3.37.2 | 3.40.1 | 3.45.x | 3.46.1 |
|---|---|---|---|---|---|
| Generated columns | 3.31.0 | yes | yes | yes | yes |
| `->` / `->>` | 3.38.0 | no | yes | yes | yes |
| JSON5, `json_error_position` | 3.42.0 | no | no | yes | yes |
| JSONB | 3.45.0 | no | no | yes | yes |
| `VACUUM INTO` | 3.27.0 | yes | yes | yes | yes |
| `DROP COLUMN` | 3.35.0 | yes | yes | yes | yes |
| WAL-reset fix | 3.51.3 (or 3.44.6, 3.50.7) | no | no | 3.45.x unpatched upstream | no |

PHP-side floors: the `Pdo\Sqlite` class is 8.4+. Its `setAuthorizer`, transaction mode, explain and busy-statement attributes are 8.5 only. PHP 8.5 deprecates the `PDO::SQLITE_*` constants in favour of `Pdo\Sqlite::*`.

## Caveats

- php.net manual pages were not used; PHP claims come from php-src and the spike.
- PDO-on-8.3 capabilities are inferred from stub absence, not spiked (the spike PHP is 8.5.8).
- Only SQLite 3.45.2 was exercised; other versions rest on documentation.
- Lock contention was tested between PDO connections inside one PHP process, not separate OS processes.
- SQLite3's default busy timeout was not measured.
- Whether distributions backport the WAL-reset fix was not checked.
- `.recover` and other recovery tooling were not researched.
- sqlite.org pages were read through an automated summarizer; the WAL-reset text and the `VACUUM INTO` version were spot-checked, the rest not verbatim.
