<?php

namespace ClaudioDekker\Firewatch\Store;

/**
 * @internal
 */
class FileIdentity
{
    /**
     * Get the device and inode a path currently names, or null when it names no file.
     */
    public function of(string $path): ?string
    {
        clearstatcache(true, $path);

        // The check is made first so that the usual case of a missing file raises nothing; a file that vanishes in between is the same answer.
        $stat = is_file($path) ? @stat($path) : false;

        return $stat === false ? null : $stat['dev'].':'.$stat['ino'];
    }
}
