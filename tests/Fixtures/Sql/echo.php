<?php

$request = json_decode(stream_get_contents(STDIN), associative: true);

fwrite(STDERR, "stand-in stderr\n");
fwrite(STDOUT, $request['sql']);
