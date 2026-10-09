<?php

stream_get_contents(STDIN);

fwrite(STDERR, "stand-in stderr\n");

$chunk = str_repeat('x', 65536);

for ($written = 0; $written < 8 * 1048576; $written += 65536) {
    fwrite(STDOUT, $chunk);
}

sleep(30);
