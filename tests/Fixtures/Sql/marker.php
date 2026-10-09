<?php

$request = json_decode(stream_get_contents(STDIN), associative: true);

touch($request['store'].'.spawned');

fwrite(STDOUT, json_encode(['k' => 'columns', 'columns' => ['n'], 'reads' => []])."\n");
fwrite(STDOUT, json_encode(['k' => 'row', 'r' => [1]])."\n");
fwrite(STDOUT, json_encode(['k' => 'end', 'rows' => 1, 'stop' => 'complete'])."\n");
