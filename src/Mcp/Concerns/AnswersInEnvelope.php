<?php

namespace ClaudioDekker\Firewatch\Mcp\Concerns;

use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswerFormat;
use ClaudioDekker\Firewatch\Mcp\Call;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Window;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;
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
     * Get the window arguments, with the sentence that says what an absent bound means for the tool.
     *
     * @return array{since: StringType, until: StringType}
     */
    protected function windowSchema(JsonSchema $schema, string $absent = 'unbounded'): array
    {
        return [
            'since' => $schema->string()->description(__('firewatch::messages.since_argument', ['absent' => __("firewatch::messages.window_absent.{$absent}.since")])),
            'until' => $schema->string()->description(__('firewatch::messages.until_argument', ['absent' => __("firewatch::messages.window_absent.{$absent}.until")])),
        ];
    }

    /**
     * Get the limit argument, from the bounds of the tool and what it lists.
     */
    protected function limitArgument(JsonSchema $schema, int $maximum, int $default): IntegerType
    {
        return $schema->integer()->description(__('firewatch::messages.limit_argument', [
            'items' => __("firewatch::messages.limit_items.{$this->name()}"),
            'maximum' => $maximum,
            'default' => $default,
        ]));
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

            throw in_array($argument, $this->knownArguments(), true)
                ? Refusal::inapplicable(argument: $argument, tool: $this->name(), accepted: $accepted)
                : Refusal::unknown(argument: $argument, tool: $this->name(), accepted: $accepted);
        }
    }

    /**
     * Get the arguments any tool of the server takes.
     *
     * @return list<string>
     */
    protected function knownArguments(): array
    {
        return FirewatchServer::arguments();
    }

    /**
     * Get a call of the tool as it is written.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function call(array $arguments): string
    {
        return Call::written($this->name(), $arguments);
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
