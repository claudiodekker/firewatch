<?php

namespace ClaudioDekker\Firewatch\Sql;

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Child\Denied;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;

/**
 * The parent of the SQL child: spawns it, hands it the request, reads its lines and folds them into rows or a failure.
 *
 * @internal
 */
class ChildRunner implements SqlRunner
{
    /**
     * The child script, run by path because the child loads no autoloader.
     */
    public const SCRIPT = __DIR__.'/Child/run.php';

    /**
     * The wall-clock deadline of one statement from spawn, in seconds.
     */
    public const DEADLINE_SECONDS = 10.0;

    /**
     * The most seconds a probe waits for the child.
     */
    public const PROBE_DEADLINE_SECONDS = 2.0;

    /**
     * The probe's request.
     */
    protected const PROBE = [
        'sql' => 'SELECT 1',
        'limit' => 1,
        'store' => ':memory:',
        'application_id' => 0,
        'version' => 0,
    ];

    /**
     * The ini the child runs under, forced over the host's so that no setting can change its output or its limits.
     */
    protected const INI = [
        'memory_limit=64M',
        'display_errors=0',
        'log_errors=1',
        'error_log=',
        'precision=-1',
        'serialize_precision=-1',
        'auto_prepend_file=',
        'auto_append_file=',
    ];

    /**
     * The variables the child inherits: those PHP finds its ini with, so the sqlite3 extension loads, and Windows' system root.
     */
    protected const INHERITED_VARIABLES = ['PHPRC', 'PHP_INI_SCAN_DIR', 'SystemRoot'];

    /**
     * The most characters of the child's stderr a failure keeps.
     */
    protected const STDERR_CHARACTERS = 500;

    /**
     * The most bytes of stdout the parent reads before it kills the child.
     */
    protected const OUTPUT_CAP_BYTES = 1048576;

    /**
     * The stops a child's end line reports; the parent alone decides the others.
     */
    protected const REPORTED_STOPS = [QueryStop::COMPLETE, QueryStop::LIMIT, QueryStop::BUDGET, QueryStop::MEMORY, QueryStop::ERROR];

    /**
     * How long the parent waits between two looks at the child.
     */
    protected const POLL_MICROSECONDS = 10_000;

    /**
     * The store states a child reports.
     */
    protected const STATES = [StoreState::ABSENT, StoreState::FOREIGN, StoreState::SCHEMA_MISMATCH, StoreState::CORRUPT, StoreState::BUSY];

    /**
     * The signal that kills a child at once.
     */
    protected const SIGKILL = 9;

    /**
     * Create a new child runner instance.
     *
     * @param  float  $deadline  seconds from spawn
     * @param  string|null  $temporaryDirectory  where the call's files go, the system's when null
     */
    public function __construct(
        protected Configuration $configuration,
        protected float $deadline = self::DEADLINE_SECONDS,
        protected string $script = self::SCRIPT,
        protected Availability $availability = new Availability,
        protected ?string $temporaryDirectory = null,
    ) {
        //
    }

    /**
     * Run one read-only statement of the assistant in the SQL child and get its rows.
     *
     * @param  int<1, 500>  $limit
     *
     * @throws StoreUnusable
     * @throws SqlFailure
     */
    public function run(string $sql, int $limit): SqlRows
    {
        $denied = Policy::screen($sql);

        if ($denied !== null) {
            throw SqlFailure::notAllowed($denied);
        }

        $request = [
            'sql' => $sql,
            'limit' => $limit,
            'store' => $this->configuration->database,
            'application_id' => Schema::APPLICATION_ID,
            'version' => Schema::VERSION,
        ];

        return $this->call($request, $this->deadline);
    }

    /**
     * Probe the child.
     */
    public function probe(): ?Unavailable
    {
        try {
            $rows = $this->call(static::PROBE, min($this->deadline, static::PROBE_DEADLINE_SECONDS));
        } catch (SqlFailure $failure) {
            return $failure->unavailable ?? Unavailable::SPAWN_FAILED;
        } catch (StoreUnusable) {
            return Unavailable::SPAWN_FAILED;
        }

        return $rows->stop === QueryStop::COMPLETE && $rows->rows === [[1]] ? null : Unavailable::SPAWN_FAILED;
    }

    /**
     * Hand the child a request and fold what it wrote.
     *
     * @param  array<string, mixed>  $request
     *
     * @throws StoreUnusable
     * @throws SqlFailure
     */
    protected function call(array $request, float $deadline): SqlRows
    {
        $started = hrtime(true);
        [$lines, $stderr, $ending] = $this->exchange($request, $deadline);
        $elapsed = intdiv(hrtime(true) - $started, 1_000_000);

        return $this->fold($lines, $stderr, $ending, $elapsed);
    }

    /**
     * Spawn the child on temp files and poll it until it exits, its output passes the cap, or the deadline passes, when it is killed.
     *
     * @param  array<string, mixed>  $request
     * @return array{list<string>, string, Ending} the whole stdout lines, the start of stderr, and why the child stopped
     *
     * @throws SqlFailure
     */
    protected function exchange(array $request, float $deadline): array
    {
        $until = hrtime(true) + (int) ($deadline * 1e9);
        $paths = [];
        $handles = [];

        try {
            foreach (['stdin', 'stdout', 'stderr'] as $name) {
                // tempnam creates the file 0600 on POSIX, in the user's own %TEMP% on Windows.
                $paths[$name] = @tempnam($this->temporaryDirectory ?? sys_get_temp_dir(), 'fws') ?: throw SqlFailure::unavailable(Unavailable::SPAWN_FAILED);
            }

            if (file_put_contents($paths['stdin'], json_encode($request, JSON_THROW_ON_ERROR)) === false) {
                throw SqlFailure::unavailable(Unavailable::SPAWN_FAILED);
            }

            foreach (['stdout', 'stderr'] as $name) {
                $handles[$name] = @fopen($paths[$name], 'rb') ?: throw SqlFailure::unavailable(Unavailable::SPAWN_FAILED);
            }

            $descriptors = [['file', $paths['stdin'], 'r'], ['file', $paths['stdout'], 'w'], ['file', $paths['stderr'], 'w']];
            $process = @proc_open($this->command(), $descriptors, $pipes, dirname(__DIR__, 2), $this->environment());

            if (! is_resource($process)) {
                throw SqlFailure::unavailable(Unavailable::SPAWN_FAILED);
            }

            try {
                $ending = $this->wait($process, $handles['stdout'], $until);
            } finally {
                $this->end($process);
            }

            $output = (string) stream_get_contents($handles['stdout'], static::OUTPUT_CAP_BYTES, 0);
            $errors = (string) stream_get_contents($handles['stderr'], static::STDERR_CHARACTERS * 4, 0);
        } finally {
            // Unlinked only after proc_close, when the child holds none of the files open, which Windows requires.
            array_map(fclose(...), $handles);
            array_map(fn (string $path) => @unlink($path), $paths);
        }

        return [$this->wholeLines($output), trim(mb_substr($errors, 0, static::STDERR_CHARACTERS)), $ending];
    }

    /**
     * Poll the child until it exits, its output passes the cap, or the deadline passes.
     *
     * @param  resource  $process
     * @param  resource  $output  the parent's own handle on the child's stdout file
     */
    protected function wait($process, $output, int $until): Ending
    {
        while (true) {
            $exitedBeforeMeasuring = ! proc_get_status($process)['running'];
            $written = fstat($output)['size'] ?? 0;

            if ($written > static::OUTPUT_CAP_BYTES) {
                return Ending::OUTPUT_CAP;
            }

            if ($exitedBeforeMeasuring) {
                return Ending::EXITED;
            }

            if (hrtime(true) >= $until) {
                return Ending::DEADLINE;
            }

            usleep(static::POLL_MICROSECONDS);
        }
    }

    /**
     * Get the lines of the output that end in a newline, dropping a last line a kill cut short.
     *
     * @return list<string>
     */
    protected function wholeLines(string $output): array
    {
        $last = strrpos($output, "\n");
        $whole = $last === false ? '' : substr($output, 0, $last);

        return array_values(array_filter(explode("\n", $whole), fn (string $line) => $line !== ''));
    }

    /**
     * Kill the child when it is still running, and reap it.
     *
     * @param  resource  $process
     */
    protected function end($process): void
    {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, static::SIGKILL);
        }

        proc_close($process);
    }

    /**
     * Fold the child's lines into its rows, or into the store state or the failure they report.
     *
     * A result is complete only when an end line arrives last and counts the rows that streamed. Any other end is
     * partial rows with an abnormal stop, or the failure of that stop when no row streamed.
     *
     * @param  list<string>  $lines
     *
     * @throws StoreUnusable
     * @throws SqlFailure
     */
    protected function fold(array $lines, string $stderr, Ending $ending, int $elapsedMilliseconds): SqlRows
    {
        $columns = null;
        $reads = null;
        $rows = [];
        $end = null;

        foreach ($lines as $position => $text) {
            $line = json_decode($text, associative: true);

            if (! is_array($line) || ! is_string($line['k'] ?? null)) {
                throw SqlFailure::failed("Line {$position} of the child is not a protocol line.");
            }

            if ($end !== null) {
                throw SqlFailure::failed('The child wrote a line after its end line.');
            }

            match ($line['k']) {
                'state' => throw count($lines) === 1 ? $this->unusable($line) : SqlFailure::failed('The child wrote a state line among others.'),
                'error' => throw $this->failure($line),
                'columns' => [$columns, $reads] = $columns === null ? [$this->columns($line), $this->typesRead($line['reads'] ?? null)] : throw SqlFailure::failed('The child wrote its columns twice.'),
                'row' => $rows[] = $this->row($line, $columns),
                'end' => $end = $line,
                default => throw SqlFailure::failed("The child wrote a line of the unknown kind {$line['k']}."),
            };
        }

        if ($end !== null && $columns === null) {
            throw SqlFailure::failed('The child wrote its end line before its columns.');
        }

        if ($end === null || ($end['rows'] ?? null) !== count($rows)) {
            return $this->unfinished($columns, $rows, $reads, $stderr, $ending, $elapsedMilliseconds);
        }

        $stop = QueryStop::tryFrom(is_string($end['stop'] ?? null) ? $end['stop'] : '');

        if ($stop === null || ! in_array($stop, static::REPORTED_STOPS, true)) {
            throw SqlFailure::failed('The end line carries no known stop.');
        }

        return $this->finished($stop, $end, $columns, $rows, $reads ?? [], $elapsedMilliseconds);
    }

    /**
     * Get the rows of a statement the child ended with an end line, or the failure of an abnormal stop that left no row.
     *
     * @param  array<string, mixed>  $end
     * @param  list<string>  $columns
     * @param  list<list<int|float|string|CutText|null>>  $rows
     * @param  list<RecordType>  $reads
     *
     * @throws SqlFailure
     */
    protected function finished(QueryStop $stop, array $end, array $columns, array $rows, array $reads, int $elapsedMilliseconds): SqlRows
    {
        $message = is_string($end['message'] ?? null) ? $end['message'] : null;

        if ($stop === QueryStop::ERROR && ($message === null || $rows === [])) {
            throw SqlFailure::failed('The end line reports an error without a message or after no row.');
        }

        if ($rows === [] && $stop === QueryStop::BUDGET) {
            throw SqlFailure::rowTooLarge();
        }

        if ($rows === [] && $stop === QueryStop::MEMORY) {
            throw SqlFailure::memory();
        }

        return new SqlRows($columns, $rows, $stop, $reads, $elapsedMilliseconds, $stop === QueryStop::ERROR ? $message : null);
    }

    /**
     * Get the rows streamed by a child that never finished, as partial, or the failure of its stop when no row streamed.
     *
     * @param  list<string>|null  $columns
     * @param  list<list<int|float|string|CutText|null>>  $rows
     * @param  list<RecordType>|null  $reads
     *
     * @throws SqlFailure
     */
    protected function unfinished(?array $columns, array $rows, ?array $reads, string $stderr, Ending $ending, int $elapsedMilliseconds): SqlRows
    {
        $deadline = $ending === Ending::DEADLINE;

        if ($rows === []) {
            throw $deadline ? SqlFailure::deadline() : SqlFailure::aborted($stderr);
        }

        return new SqlRows($columns ?? [], $rows, $deadline ? QueryStop::DEADLINE : QueryStop::ABORTED, $reads ?? [], $elapsedMilliseconds, $deadline || $stderr === '' ? null : $stderr);
    }

    /**
     * Get the store state a state line reports.
     *
     * @param  array<string, mixed>  $line
     */
    protected function unusable(array $line): StoreUnusable|SqlFailure
    {
        $state = StoreState::tryFrom(is_string($line['state'] ?? null) ? $line['state'] : '');
        $found = $line['found'] ?? null;

        if ($state === null || ! in_array($state, static::STATES, true) || ($found !== null && ! is_int($found))) {
            return SqlFailure::failed('The state line carries no state a child reports.');
        }

        return new StoreUnusable($state, $found);
    }

    /**
     * Get the failure an error line reports.
     *
     * @param  array<string, mixed>  $line
     */
    protected function failure(array $line): SqlFailure
    {
        $text = fn (string $key) => is_string($line[$key] ?? null) ? $line[$key] : null;
        $code = $line['code'] ?? null;
        $denied = Denied::tryFrom((string) $text('subject'));
        $reason = Unavailable::tryFrom((string) $text('reason'));
        $message = $text('message');

        return match (true) {
            $code === 'not_allowed' && $denied !== null => SqlFailure::notAllowed($denied, $text('name')),
            $code === 'invalid_sql' && $message !== null => SqlFailure::invalid($message),
            $code === 'unavailable' && $reason !== null => SqlFailure::unavailable($reason),
            $code === 'memory' => SqlFailure::memory(),
            $code === 'failed' => SqlFailure::failed($message ?? 'The child failed without a message.'),
            default => SqlFailure::failed('The error line carries no known code and facts.'),
        };
    }

    /**
     * Get the column names a columns line carries.
     *
     * @param  array<string, mixed>  $line
     * @return list<string>
     */
    protected function columns(array $line): array
    {
        $columns = $line['columns'] ?? null;

        if (! is_array($columns) || ! array_is_list($columns) || $columns !== array_filter($columns, is_string(...))) {
            throw SqlFailure::failed('The columns line carries no list of names.');
        }

        return $columns;
    }

    /**
     * Get the values a row line carries, one for each column.
     *
     * @param  array<string, mixed>  $line
     * @param  list<string>|null  $columns
     * @return list<int|float|string|CutText|null>
     */
    protected function row(array $line, ?array $columns): array
    {
        $row = $line['r'] ?? null;

        if ($columns === null || ! is_array($row) || ! array_is_list($row) || count($row) !== count($columns)) {
            throw SqlFailure::failed('A row line does not fit the columns.');
        }

        return array_map($this->cell(...), $row);
    }

    /**
     * Get the value a cell of a row line carries: a scalar, or the text the child cut at the cell cap.
     */
    protected function cell(mixed $cell): int|float|string|CutText|null
    {
        if (is_array($cell) && is_string($cell['text'] ?? null) && is_int($cell['omitted'] ?? null)) {
            return new CutText($cell['text'], $cell['omitted']);
        }

        return is_int($cell) || is_float($cell) || is_string($cell) || $cell === null ? $cell : throw SqlFailure::failed('A row line carries a cell that is no value.');
    }

    /**
     * Get the record types of what the authorizer saw the statement read, in case order; ambiguity over-attaches.
     *
     * A view is read under its own name and again as records with the view responsible, and records read under no
     * view, or through a common table expression, could be of any type.
     *
     * @return list<RecordType>
     */
    protected function typesRead(mixed $reads): array
    {
        if (! is_array($reads)) {
            throw SqlFailure::failed('The columns line carries no reads.');
        }

        $views = [];

        foreach (RecordType::events() as $type) {
            $views[(string) $type->view()] = $type;
        }

        $read = [];

        foreach ($reads as $pair) {
            [$table, $via] = is_array($pair) && count($pair) === 2 ? array_values($pair) : throw SqlFailure::failed('A read is no table and view.');

            array_push($read, ...match (true) {
                isset($views[$table]) => [$views[$table]],
                $table === 'records' && isset($views[$via]) => [$views[$via]],
                $table === 'records' => array_values($views),
                $table === 'users' => [RecordType::USER],
                default => [],
            });
        }

        return array_values(array_filter(RecordType::cases(), fn (RecordType $type) => in_array($type, $read, true)));
    }

    /**
     * Get the argument vector of the child: no shell, and the forced ini over the host's.
     *
     * @return list<string>
     */
    protected function command(): array
    {
        $settings = array_merge(...array_map(fn (string $setting) => ['-d', $setting], static::INI));

        return [$this->availability->phpBinary, ...$settings, $this->script];
    }

    /**
     * Get the child's environment: only the inherited variables the parent has, so no application variable reaches it.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $values = array_map(getenv(...), static::INHERITED_VARIABLES);

        return array_filter(array_combine(static::INHERITED_VARIABLES, $values), is_string(...));
    }
}
