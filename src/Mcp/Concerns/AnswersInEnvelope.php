<?php

namespace ClaudioDekker\Firewatch\Mcp\Concerns;

use Carbon\CarbonImmutable;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswerFormat;
use ClaudioDekker\Firewatch\Mcp\Call;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Frame;
use ClaudioDekker\Firewatch\Mcp\Measure;
use ClaudioDekker\Firewatch\Mcp\Refusal;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
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
            'format' => $schema->string()->enum(AnswerFormat::class)->default(AnswerFormat::MARKDOWN->value),
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
     * Get the type argument of a tool that reads groups.
     */
    protected function groupedTypeArgument(JsonSchema $schema): StringType
    {
        return $schema->string()
            ->enum(array_column(Measure::types(), 'value'))
            ->description(__('firewatch::messages.grouped_type_argument'));
    }

    /**
     * Get the measure argument of a tool, which takes the measures any type with groups takes.
     *
     * @param  Closure(RecordType): list<Measure>  $taken
     */
    protected function measureArgument(JsonSchema $schema, Closure $taken): StringType
    {
        $measures = Measure::forAnyType($taken);

        return $schema->string()->enum(array_column($measures, 'value'));
    }

    /**
     * Get the limit argument, from the bounds of the tool and what it lists.
     */
    protected function limitArgument(JsonSchema $schema, int $maximum, int $default): IntegerType
    {
        return $schema->integer()
            ->min(1)
            ->max($maximum)
            ->default($default)
            ->description(__("firewatch::messages.limit_argument.{$this->name()}"));
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

            throw in_array($argument, FirewatchServer::arguments(), true)
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
        return Call::written($this->name(), $arguments);
    }

    /**
     * Get the frame an answer of the call is stated in.
     */
    protected function frame(CarbonImmutable $now): Frame
    {
        return new Frame($this->name(), $now, config()->string('app.timezone'), $this->configuration, $this->reader, $this->conditions);
    }
}
