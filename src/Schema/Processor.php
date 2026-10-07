<?php

namespace Aldeebhasan\Panorama\Schema;

use Aldeebhasan\Panorama\Exceptions\CastFailed;
use Aldeebhasan\Panorama\Exceptions\SchemaException;
use Aldeebhasan\Panorama\Exceptions\SchemaViolation;
use Aldeebhasan\Panorama\Schema\Casts\BoolCast;
use Aldeebhasan\Panorama\Schema\Casts\Cast;
use Aldeebhasan\Panorama\Schema\Casts\DateCast;
use Aldeebhasan\Panorama\Schema\Casts\DateTimeCast;
use Aldeebhasan\Panorama\Schema\Casts\DecimalCast;
use Aldeebhasan\Panorama\Schema\Casts\EnumCast;
use Aldeebhasan\Panorama\Schema\Casts\IntCast;
use Aldeebhasan\Panorama\Schema\Casts\JsonCast;
use Aldeebhasan\Panorama\Schema\Casts\StringCast;
use Generator;
use stdClass;
use Traversable;

/**
 * Turns the raw rows of a dataset into rows that honour its schema.
 *
 * Each row goes through the same six steps, in this order: map the raw field
 * names, apply defaults, cast to the declared type, normalize, validate, and
 * apply the violation policy. Output rows carry the declared fields and
 * nothing else.
 *
 * Rows are produced lazily, so a dataset of any size costs one row of memory.
 * The violation log is therefore only complete once the generator has been
 * fully consumed.
 */
final class Processor
{
    /** @var array<string,Cast> */
    private array $casts;

    private ViolationLog $log;

    /**
     * @param string $dataset the dataset name, used in error messages
     * @param array<string,Cast>|null $casts override the cast of a type, keyed by {@see FieldType} value
     *
     * @throws SchemaException
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly string $dataset = 'dataset',
        ?array $casts = null,
    ) {
        $schema->assertValid();

        $this->casts = array_merge(self::defaultCasts(), $casts ?? []);
        $this->log = new ViolationLog;
    }

    /**
     * The cast used for each field type unless it is overridden.
     *
     * @return array<string,Cast>
     */
    public static function defaultCasts(): array
    {
        return [
            FieldType::String->value => new StringCast,
            FieldType::Int->value => new IntCast,
            FieldType::Decimal->value => new DecimalCast,
            FieldType::Bool->value => new BoolCast,
            FieldType::Date->value => new DateCast,
            FieldType::Datetime->value => new DateTimeCast,
            FieldType::Json->value => new JsonCast,
            FieldType::Enum->value => new EnumCast,
        ];
    }

    /**
     * Normalize every row, dropping the ones the policy rejects.
     *
     * @param iterable<array-key,mixed> $rows
     * @return Generator<int,array<string,mixed>>
     *
     * @throws SchemaViolation
     */
    public function process(iterable $rows): Generator
    {
        $index = 1;

        foreach ($rows as $row) {
            $normalized = $this->row($row, $index);
            $index++;

            if ($normalized !== null) {
                $this->log->accept();

                yield $normalized;
            }
        }
    }

    /**
     * What the last {@see process()} pass dropped or patched.
     */
    public function log(): ViolationLog
    {
        return $this->log;
    }

    /**
     * Normalize a single row, or return null when the policy drops it.
     *
     * @return array<string,mixed>|null
     *
     * @throws SchemaViolation
     */
    private function row(mixed $raw, int $index): ?array
    {
        $source = $this->toArray($raw);
        $normalized = [];

        foreach ($this->schema->fields() as $name => $field) {
            // 1. map the raw name onto the declared one
            $present = array_key_exists($field->from, $source);
            $value = $present ? $source[$field->from] : null;

            // 2. fall back to the declared default
            if ($value === null && $field->hasDefault) {
                $value = $field->default;
            }

            // 3, 4 and 5. cast, normalize and validate
            if ($value === null) {
                if ($field->nullable) {
                    $normalized[$name] = null;

                    continue;
                }

                $violation = new Violation(
                    $name,
                    $index,
                    $present ? 'is null but the field is not nullable' : sprintf('is missing from the raw row under [%s]', $field->from),
                    $value,
                );
            } else {
                try {
                    $normalized[$name] = $this->castOf($field)->cast($value, $field);

                    continue;
                } catch (CastFailed $failure) {
                    $violation = new Violation($name, $index, $failure->getMessage(), $value);
                }
            }

            // 6. let the policy decide
            switch ($this->schema->policy()) {
                case ViolationPolicy::Fail:
                    throw SchemaViolation::in($this->dataset, $violation);
                case ViolationPolicy::Skip:
                    $this->log->skip($violation);

                    return null;

                case ViolationPolicy::Nullify:
                    $this->log->nullify($violation);
                    $normalized[$name] = null;
            }
        }

        return $normalized;
    }

    private function castOf(Field $field): Cast
    {
        return $this->casts[$field->type->value];
    }

    /**
     * Accept the row shapes sources hand back: arrays, the stdClass objects
     * database cursors yield, and anything exposing `toArray()`.
     *
     * Keys stay as the source wrote them, integers included, so a headerless
     * CSV can be mapped positionally with `from('0')`.
     *
     * @return array<array-key,mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row)) {
            return $row;
        }

        if ($row instanceof stdClass) {
            return get_object_vars($row);
        }

        if (is_object($row) && method_exists($row, 'toArray')) {
            $array = $row->toArray();

            if (is_array($array)) {
                return $array;
            }
        }

        if ($row instanceof Traversable) {
            return iterator_to_array($row);
        }

        return [];
    }
}
