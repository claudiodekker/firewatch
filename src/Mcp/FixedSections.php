<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
class FixedSections
{
    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * Create a new fixed sections instance.
     *
     * @param  array{requests: int, with_status: int, server_errors: int, server_error_pct: float|null, client_errors: int, client_error_pct: float|null}  $errorRate
     * @param  int  $records  the records of the window, those of no known type included
     * @param  list<array{type: string, records: int}>  $byType  the twelve types in catalogue order
     * @param  int  $userDirectory  the rows of the user directory, whatever the window
     */
    protected function __construct(
        public readonly array $errorRate,
        public readonly int $records,
        public readonly array $byType,
        public readonly int $userDirectory,
    ) {
        //
    }

    /**
     * Read the sections of the window, in the fixed order.
     */
    public static function read(SQLite3 $connection, Window $window): self
    {
        $errorRate = self::errorRate($connection, $window);
        $counts = self::countByType($connection, $window);
        $userDirectory = self::countUsers($connection);

        $byType = array_map(fn (RecordType $type) => [
            'type' => $type->value,
            'records' => $counts[$type->value] ?? 0,
        ], RecordType::events());

        return new self(
            errorRate: $errorRate,
            records: array_sum($counts),
            byType: $byType,
            userDirectory: $userDirectory,
        );
    }

    /**
     * Get the sections as the keys of a result, in the fixed order.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return [
            'error_rate' => $this->errorRate,
            'records' => $this->records,
            'records_by_type' => $this->byType,
            'user_directory' => $this->userDirectory,
        ];
    }

    /**
     * Get how many records of the window are of none of the twelve types.
     */
    public function unknownTypes(): int
    {
        return $this->records - array_sum(array_column($this->byType, 'records'));
    }

    /**
     * Read the server errors and client errors among the requests of the window, with their shares of the requests that have a status.
     *
     * @return array{requests: int, with_status: int, server_errors: int, server_error_pct: float|null, client_errors: int, client_error_pct: float|null}
     */
    protected static function errorRate(SQLite3 $connection, Window $window): array
    {
        $status = Stored::number('status_code');
        $serverError = Failure::serverError("({$status})");
        $clientError = Failure::clientError("({$status})");

        /** @var array{requests: int, with_status: int, server_errors: int, client_errors: int} $counts */
        $counts = Stored::rows($connection, "SELECT count(*) AS requests, count({$status}) AS with_status,
            count(*) FILTER (WHERE {$serverError}) AS server_errors,
            count(*) FILTER (WHERE {$clientError}) AS client_errors
            FROM requests WHERE {$window->condition()}", window: $window)[0];

        return [
            'requests' => $counts['requests'],
            'with_status' => $counts['with_status'],
            'server_errors' => $counts['server_errors'],
            'server_error_pct' => self::share($counts['server_errors'], $counts['with_status']),
            'client_errors' => $counts['client_errors'],
            'client_error_pct' => self::share($counts['client_errors'], $counts['with_status']),
        ];
    }

    /**
     * Count the records of the window by the type they were stored with, a type that is none of the twelve included.
     *
     * @return array<string, int>
     */
    protected static function countByType(SQLite3 $connection, Window $window): array
    {
        $rows = Stored::rows($connection, "SELECT COALESCE(type, '') AS type, count(*) AS records FROM records WHERE {$window->condition()} GROUP BY 1", window: $window);

        return array_column($rows, 'records', 'type');
    }

    /**
     * Count the rows of the user directory, which has no start to window by.
     */
    protected static function countUsers(SQLite3 $connection): int
    {
        return Stored::rows($connection, 'SELECT count(*) AS users FROM users')[0]['users'];
    }

    /**
     * Get a part as a percentage of a whole, or null for a whole of nothing.
     */
    protected static function share(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(Ranking::PERCENT * $part / $whole, self::PERCENT_DECIMALS);
    }
}
