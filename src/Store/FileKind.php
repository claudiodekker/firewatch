<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
enum FileKind
{
    /**
     * The magic string every SQLite file starts with.
     */
    protected const MAGIC = "SQLite format 3\0";

    /**
     * The offset of the application id in the file header, four bytes big-endian.
     */
    protected const APPLICATION_ID_OFFSET = 68;

    case Empty;
    case NotSqlite;
    case Foreign;
    case Firewatch;

    /**
     * Classify a file by the raw bytes of its header, which needs no working SQLite.
     */
    public static function of(string $path): self
    {
        clearstatcache(true, $path);

        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return self::Empty;
        }

        $header = (string) fread($handle, self::APPLICATION_ID_OFFSET + 4);

        fclose($handle);

        if ($header === '') {
            return self::Empty;
        }

        if (! str_starts_with($header, self::MAGIC)) {
            return self::NotSqlite;
        }

        $applicationId = substr($header, self::APPLICATION_ID_OFFSET, 4);

        return $applicationId === pack('N', Schema::APPLICATION_ID) ? self::Firewatch : self::Foreign;
    }
}
