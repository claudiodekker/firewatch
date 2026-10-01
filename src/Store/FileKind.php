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

    case EMPTY;
    case NOT_SQLITE;
    case FOREIGN;
    case FIREWATCH;

    /**
     * Classify a file by the raw bytes of its header, which needs no working SQLite.
     */
    public static function of(string $path): self
    {
        clearstatcache(true, $path);

        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return self::EMPTY;
        }

        $header = (string) fread($handle, self::APPLICATION_ID_OFFSET + 4);

        fclose($handle);

        if ($header === '') {
            return self::EMPTY;
        }

        if (! str_starts_with($header, self::MAGIC)) {
            return self::NOT_SQLITE;
        }

        $applicationId = substr($header, self::APPLICATION_ID_OFFSET, 4);

        return $applicationId === pack('N', Schema::APPLICATION_ID) ? self::FIREWATCH : self::FOREIGN;
    }
}
