<?php

use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Sql\Child\Denied;
use ClaudioDekker\Firewatch\Sql\Child\Policy;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;

test('the readable set is the record views, the raw tables and the JSON table-valued functions', function () {
    $views = array_values(array_filter(array_map(fn (RecordType $type) => $type->view(), RecordType::cases())));

    expect(Policy::READABLE)->toBe([...$views, 'records', 'users', 'drift', 'meta', 'json_each', 'json_tree']);
});

test('the function allow-list is the decision\'s', function () {
    expect(Policy::FUNCTIONS)->toBe([
        'json', 'json_array', 'json_array_length', 'json_extract', 'json_insert', 'json_object', 'json_patch', 'json_remove',
        'json_replace', 'json_set', 'json_type', 'json_valid', 'json_quote', 'json_group_array', 'json_group_object',
        'json_each', 'json_tree', '->', '->>',
        'count', 'sum', 'avg', 'total', 'min', 'max', 'group_concat', 'string_agg',
        'abs', 'round', 'sign', 'ceil', 'ceiling', 'floor', 'trunc', 'mod', 'pow', 'power', 'sqrt', 'exp', 'ln', 'log', 'log2', 'log10',
        'length', 'lower', 'upper', 'trim', 'ltrim', 'rtrim', 'substr', 'substring', 'instr', 'replace', 'like', 'glob',
        'unicode', 'concat', 'concat_ws',
        'coalesce', 'ifnull', 'iif', 'nullif', 'typeof', 'likely', 'unlikely', 'likelihood',
        'row_number', 'rank', 'dense_rank', 'percent_rank', 'cume_dist', 'ntile', 'lag', 'lead', 'first_value', 'last_value', 'nth_value',
        'date', 'time', 'datetime', 'julianday', 'unixepoch', 'strftime',
    ]);
});

test('the SQL text is at most 16,384 bytes', function () {
    expect(Policy::SQL_BYTES)->toBe(16384);
});

test('the closed words the child writes', function () {
    expect(array_column(Denied::cases(), 'value'))->toBe(['function', 'table', 'action', 'second_statement', 'no_columns', 'too_long', 'nul'])
        ->and(array_column(Unavailable::cases(), 'value'))->toBe(['spawn_failed', 'authorizer']);
});
