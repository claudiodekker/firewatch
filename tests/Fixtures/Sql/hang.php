<?php

$request = json_decode(stream_get_contents(STDIN), associative: true);

fwrite(STDOUT, $request['sql']);
fflush(STDOUT);

sleep(30);
