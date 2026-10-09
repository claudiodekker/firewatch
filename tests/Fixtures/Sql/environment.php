<?php

stream_get_contents(STDIN);

$names = array_keys(getenv());

fwrite(STDOUT, json_encode(['k' => 'columns', 'columns' => ['name'], 'reads' => []])."\n");

foreach ($names as $name) {
    fwrite(STDOUT, json_encode(['k' => 'row', 'r' => [$name]])."\n");
}

fwrite(STDOUT, json_encode(['k' => 'end', 'rows' => count($names), 'stop' => 'complete'])."\n");
