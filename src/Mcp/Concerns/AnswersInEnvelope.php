<?php

namespace ClaudioDekker\Firewatch\Mcp\Concerns;

use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswerFormat;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Window;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;
use Throwable;

/**
 * @internal
 */
trait AnswersInEnvelope
{
    /**
     * The arguments the twelve tools of the design take among them.
     *
     * @var list<string>
     */
    protected const KNOWN_ARGUMENTS = [
        'type', 'group', 'matching', 'by', 'since', 'until', 'deploy', 'limit', 'cursor', 'shape', 'threshold', 'order', 'method', 'status', 'outcome',
        'level', 'slower_than_ms', 'at_or_above', 'execution_id', 'trace_id', 'job_id', 'user_id', 'who', 'split_at', 'deploy_before', 'deploy_after',
        'buckets', 'sql', 'methods', 'path', 'domain', 'name', 'cron', 'timezone', 'repeat_seconds', 'connection', 'driver', 'store', 'key', 'host', 'class',
        'format',
    ];

    /**
     * Get the argument every tool takes.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'format' => $schema->string()
                ->enum(array_column(AnswerFormat::cases(), 'value'))
                ->description(__('firewatch::messages.format_argument')),
        ];
    }

    /**
     * Get the answer in the format the request asks for.
     *
     * @param  callable(): Answer  $answer
     */
    protected function answer(Request $request, callable $answer): Response|ResponseFactory
    {
        $argument = $request->get('format');
        $format = AnswerFormat::fromArgument($argument);

        try {
            $this->refuseUnacceptedArguments($request);

            $format ??= throw Refusal::format($argument, $this->name());

            return $answer()->response($format);
        } catch (Refusal $refusal) {
            return Response::error($refusal->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return Response::error(Refusal::internal()->getMessage());
        }
    }

    /**
     * Refuse the first argument of the call that the tool does not take.
     */
    protected function refuseUnacceptedArguments(Request $request): void
    {
        $accepted = array_keys($this->schema(new JsonSchemaTypeFactory));

        foreach (array_keys($request->all()) as $argument) {
            if (in_array($argument, $accepted, true)) {
                continue;
            }

            throw in_array($argument, self::KNOWN_ARGUMENTS, true)
                ? Refusal::inapplicable(argument: $argument, tool: $this->name(), accepted: $accepted)
                : Refusal::unknown(argument: $argument, tool: $this->name(), accepted: $accepted);
        }
    }

    /**
     * Get a call of the tool as it is written.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function call(array $arguments): string
    {
        $written = array_map(fn (string $name, mixed $value) => $name.': '.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), array_keys($arguments), $arguments);

        return $this->name().'('.implode(', ', $written).')';
    }

    /**
     * Count all records and those of the window, and find the span the records cover.
     *
     * @return array{int, int, float|null, float|null}
     */
    protected function count(SQLite3 $connection, Window $window): array
    {
        $condition = $window->condition();

        /** @var SQLite3Stmt $statement */
        $statement = $connection->prepare("SELECT count(*), count(*) FILTER (WHERE {$condition}), min(started_at), max(started_at) FROM records");

        $window->bind($statement);

        /** @var SQLite3Result $result */
        $result = $statement->execute();

        /** @var array{int, int, float|null, float|null} */
        return $result->fetchArray(SQLITE3_NUM);
    }
}
