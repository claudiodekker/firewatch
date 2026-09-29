<?php

use ClaudioDekker\Firewatch\Tests\Support\PackageSource;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

const SERVER_PATH = 'Mcp';

const SQL_CHILD_PATH = 'Sql/Child';

const SQL_CHILD_NAMESPACE = 'ClaudioDekker\Firewatch\Sql\Child';

arch('the package source never calls env()')
    ->expect('ClaudioDekker\Firewatch')
    ->not->toUse('env');

arch('the package source holds no debugging or process-ending calls')
    ->expect('ClaudioDekker\Firewatch')
    ->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'exit', 'die']);

arch('classes stay open to extension')
    ->expect('ClaudioDekker\Firewatch')
    ->classes()
    ->not->toBeFinal();

test('every command signature starts with firewatch:', function () {
    $commands = array_filter(
        PackageSource::classes(),
        fn (string $class) => is_subclass_of($class, Command::class) && ! (new ReflectionClass($class))->isAbstract(),
    );

    $names = array_map(commandName(...), array_values($commands));

    $offences = array_values(array_filter($names, fn (string $name) => ! str_starts_with($name, 'firewatch:')));

    expect($offences)->toBe([]);
});

arch('nothing in the package source can leave the machine')
    ->expect('ClaudioDekker\Firewatch')
    ->not->toUse([
        'fsockopen',
        'pfsockopen',
        'stream_socket_client',
        'GuzzleHttp',
        'Illuminate\Http\Client',
        'Illuminate\Support\Facades\Http',
        'Illuminate\Mail',
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Notifications',
        'Illuminate\Support\Facades\Notification',
        'Laravel\Nightwatch\Ingest',
        'Laravel\Nightwatch\Contracts\Ingest',
        'Laravel\Nightwatch\SocketStreamFactory',
    ]);

test('nothing in the package source calls a curl or socket function', function () {
    $offences = PackageSource::offendingTokens('', fn (PhpToken $token) => $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
        && preg_match('/^\\\\?(curl|socket)_/i', $token->text) === 1);

    expect($offences)->toBe([]);
});

test('nothing on the server path writes to standard output', function () {
    $offences = PackageSource::offendingTokens(SERVER_PATH, writesToStdout(...));

    expect($offences)->toBe([]);
});

arch('the SQL child references nothing outside itself')
    ->expect(SQL_CHILD_NAMESPACE)
    ->toOnlyUse(SQL_CHILD_NAMESPACE);

test('the SQL child script references no framework or application class', function () {
    $offences = PackageSource::offendingTokens(SQL_CHILD_PATH, fn (PhpToken $token) => $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
        && ! str_starts_with(ltrim($token->text, '\\').'\\', SQL_CHILD_NAMESPACE.'\\')
        && preg_match('/^\\\\?(Illuminate|Laravel|App|Workbench|ClaudioDekker)\\\\/', $token->text) === 1);

    expect($offences)->toBe([]);
});

function commandName(string $class): string
{
    $properties = (new ReflectionClass($class))->getDefaultProperties();

    if (is_string($properties['signature'] ?? null)) {
        return preg_split('/[\s{]/', trim($properties['signature']))[0];
    }

    if (is_string($properties['name'] ?? null)) {
        return $properties['name'];
    }

    $attribute = (new ReflectionClass($class))->getAttributes(AsCommand::class)[0] ?? null;

    return $attribute?->newInstance()->name ?? '';
}

/**
 * @param  list<PhpToken>  $tokens
 */
function writesToStdout(PhpToken $token, array $tokens, int $index): bool
{
    $previous = $tokens[$index - 1] ?? null;

    return match (true) {
        $token->is([T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO, T_INLINE_HTML]) => true,
        $token->is(T_CONSTANT_ENCAPSED_STRING) => preg_match('#php://(stdout|output)#i', $token->text) === 1,
        $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) => in_array($token->text, [
            'info', 'line', 'comment', 'question', 'warn', 'error', 'alert', 'newLine', 'table', 'components', 'write', 'writeln',
        ], true),
        $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) => in_array(ltrim($token->text, '\\'), [
            'STDOUT', 'printf', 'vprintf', 'fpassthru', 'readfile', 'passthru',
        ], true),
        default => false,
    };
}
