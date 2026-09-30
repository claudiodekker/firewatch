<?php

namespace Workbench\App\Fixtures;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use RuntimeException;

use function Orchestra\Testbench\package_path;

class WireFixture
{
    /**
     * The flags the fixture files are written with.
     */
    protected const FILE_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Create a new wire fixture instance.
     */
    public function __construct(protected Sensors $sensors)
    {
        //
    }

    /**
     * Get the normalised record the sensors write for the producer now.
     *
     * @return array<string, mixed>
     */
    public function produce(Producer $producer): array
    {
        $record = $this->sensors->recordOf($producer);

        return $this->normalise($record);
    }

    /**
     * Get the committed fixture of the producer.
     *
     * @return array<string, mixed>
     */
    public function load(Producer $producer): array
    {
        $contents = file_get_contents($this->path($producer));

        if ($contents === false) {
            throw new RuntimeException("The {$producer->value} fixture can't be read.");
        }

        return json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Write the producer's fixture from the sensors.
     */
    public function write(Producer $producer): void
    {
        $record = $this->produce($producer);

        file_put_contents($this->path($producer), json_encode($record, self::FILE_FLAGS)."\n");
    }

    /**
     * Get the path of the producer's fixture file.
     */
    public function path(Producer $producer): string
    {
        return package_path("tests/Fixtures/wire/{$producer->value}.json");
    }

    /**
     * Get how a normalised record differs from the producer's committed fixture, by field and kind.
     *
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    public function differences(Producer $producer, array $record): array
    {
        $file = basename($this->path($producer));
        $expected = $this->kinds($this->load($producer));
        $actual = $this->kinds($record);
        $differences = [];

        foreach ($expected as $field => $kind) {
            $differences[] = match (true) {
                ! array_key_exists($field, $actual) => "{$file}: {$field} is missing from what the sensors write",
                $actual[$field] !== $kind => "{$file}: {$field} is {$kind} in the fixture, the sensors write {$actual[$field]}",
                default => null,
            };
        }

        foreach (array_diff_key($actual, $expected) as $field => $kind) {
            $differences[] = "{$file}: {$field} is not in the fixture";
        }

        return array_values(array_filter($differences));
    }

    /**
     * Get the kind of each field of a normalised record, a placeholder counting as the kind it stands for.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    protected function kinds(array $record): array
    {
        return array_map(
            fn (mixed $value) => Placeholder::tryFrom(is_string($value) ? $value : '')?->kind() ?? static::kindOf($value),
            $record,
        );
    }

    /**
     * Get the JSON kind of a decoded wire value.
     */
    public static function kindOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            array_is_list($value) => 'array',
            default => 'object',
        };
    }

    /**
     * Turn a record into the plain data Nightwatch would send, with the values that vary between runs as placeholders.
     *
     * @param  array<mixed>  $record
     * @return array<string, mixed>
     */
    public function normalise(array $record): array
    {
        $json = json_encode($record, RecordMapper::JSON_FLAGS);

        $wire = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ($wire as $field => $value) {
            $placeholder = Placeholder::forField($field);

            if ($placeholder?->accepts($value)) {
                $wire[$field] = $placeholder->value;
            }
        }

        return $wire;
    }
}
