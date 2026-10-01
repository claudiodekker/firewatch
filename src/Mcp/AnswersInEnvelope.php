<?php

namespace ClaudioDekker\Firewatch\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * @internal
 */
trait AnswersInEnvelope
{
    /**
     * Get the argument every tool takes: the format of its answer.
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
     * Get the answer in the format the request asks for, or the refusal of an argument the grammar of the tool does not read.
     *
     * @param  callable(): Answer  $answer
     */
    protected function answer(Request $request, callable $answer): Response|ResponseFactory
    {
        $argument = $request->get('format');
        $format = AnswerFormat::fromArgument($argument);

        try {
            $format ??= throw Refusal::format($argument, $this->name());

            return $answer()->response($format);
        } catch (Refusal $refusal) {
            return Response::error($refusal->getMessage());
        }
    }
}
