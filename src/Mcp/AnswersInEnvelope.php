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
     * Get the answer in the format the request asks for, or the refusal of a format that is none.
     *
     * @param  callable(): Answer  $answer
     */
    protected function answer(Request $request, callable $answer): Response|ResponseFactory
    {
        $argument = $request->get('format');
        $format = AnswerFormat::fromArgument($argument);

        if ($format === null) {
            return Response::error(__('firewatch::messages.format_refused', ['value' => json_encode($argument), 'tool' => $this->name()]));
        }

        return $answer()->response($format);
    }
}
