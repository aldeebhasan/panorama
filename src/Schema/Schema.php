<?php

namespace Aldeebhasan\Panorama\Schema;

use Aldeebhasan\Panorama\Exceptions\SchemaException;
use DateTimeZone;
use Exception;

/**
 * The contract of a dataset: its fields, their types, the primary key, how raw
 * field names map onto them and what happens when a row breaks the contract.
 *
 * Type methods declare a field; modifier methods refine the field that was
 * declared last, so a schema reads as one chain:
 *
 *     Schema::make()
 *         ->primaryKey('id')
 *         ->string('id', from: 'ext_id')
 *         ->string('country')->nullable()
 *         ->datetime('created_at', from: 'createdAt')->timezone('UTC');
 */
final class Schema
{
    /** @var array<string,Field> */
    private array $fields = [];

    /** @var list<string> */
    private array $primaryKey = [];

    /**
     * The field a modifier applies to, i.e. the one declared last.
     */
    private ?string $current = null;

    private ViolationPolicy $policy = ViolationPolicy::Fail;

    public static function make(): self
    {
        return new self;
    }

    /*
    |--------------------------------------------------------------------------
    | Schema level declarations
    |--------------------------------------------------------------------------
    */

    /**
     * Declare the primary key. It may name fields that are declared later.
     */
    public function primaryKey(string ...$fields): self
    {
        $this->primaryKey = array_values($fields);

        return $this;
    }

    public function onViolation(ViolationPolicy $policy): self
    {
        $this->policy = $policy;

        return $this;
    }

    public function failOnViolation(): self
    {
        return $this->onViolation(ViolationPolicy::Fail);
    }

    public function skipOnViolation(): self
    {
        return $this->onViolation(ViolationPolicy::Skip);
    }

    public function nullifyOnViolation(): self
    {
        return $this->onViolation(ViolationPolicy::Nullify);
    }

    /*
    |--------------------------------------------------------------------------
    | Field types
    |--------------------------------------------------------------------------
    */

    public function string(string $name, ?string $from = null): self
    {
        return $this->declare($name, FieldType::String, $from);
    }

    public function int(string $name, ?string $from = null): self
    {
        return $this->declare($name, FieldType::Int, $from);
    }

    public function decimal(string $name, int $scale = 2, ?string $from = null): self
    {
        $this->declare($name, FieldType::Decimal, $from);
        $this->field($name)->scale = max(0, $scale);

        return $this;
    }

    public function bool(string $name, ?string $from = null): self
    {
        return $this->declare($name, FieldType::Bool, $from);
    }

    public function date(string $name, ?string $from = null): self
    {
        return $this->declare($name, FieldType::Date, $from);
    }

    public function datetime(string $name, ?string $from = null): self
    {
        return $this->declare($name, FieldType::Datetime, $from);
    }

    public function json(string $name, ?string $from = null): self
    {
        return $this->declare($name, FieldType::Json, $from);
    }

    /**
     * @param list<string> $values
     *
     * @throws SchemaException
     */
    public function enum(string $name, array $values, ?string $from = null): self
    {
        if ($values === []) {
            throw SchemaException::emptyEnum($name);
        }

        $this->declare($name, FieldType::Enum, $from);
        $this->field($name)->values = array_values($values);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Field modifiers, applied to the field declared last
    |--------------------------------------------------------------------------
    */

    public function nullable(bool $nullable = true): self
    {
        $this->modify(__FUNCTION__)->nullable = $nullable;

        return $this;
    }

    /**
     * Read this field from a differently named key in the raw row.
     */
    public function from(string $rawName): self
    {
        $this->modify(__FUNCTION__)->from = $rawName;

        return $this;
    }

    /**
     * The value to use when the raw row has no value for this field.
     */
    public function default(mixed $default): self
    {
        $field = $this->modify(__FUNCTION__);
        $field->hasDefault = true;
        $field->default = $default;

        return $this;
    }

    /**
     * Convert this datetime to `$timezone` before formatting. Raw values that
     * carry no offset of their own are read as being in `$source`, UTC by
     * default.
     *
     * @throws SchemaException
     */
    public function timezone(string $timezone, ?string $source = null): self
    {
        $field = $this->modify(__FUNCTION__, [FieldType::Datetime]);
        $field->timezone = $this->assertTimezone($timezone);
        $field->sourceTimezone = $source === null ? null : $this->assertTimezone($source);

        return $this;
    }

    /**
     * Tag the amounts in this field with a currency. This is metadata for the
     * renderers, it does not convert the values.
     *
     * @throws SchemaException
     */
    public function currency(string $currency): self
    {
        $this->modify(__FUNCTION__, [FieldType::Decimal, FieldType::Int])->currency = $currency;

        return $this;
    }

    /**
     * Declare that this field points at `dataset.field`, so joins can infer it.
     *
     * @throws SchemaException
     */
    public function references(string $reference): self
    {
        $this->modify(__FUNCTION__)->references = Relation::parse($reference);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Introspection
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string,Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @throws SchemaException
     */
    public function field(string $name): Field
    {
        return $this->fields[$name] ?? throw SchemaException::unknownField($name);
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /**
     * The fields the primary key is made of.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return $this->primaryKey;
    }

    /**
     * The declared relations, keyed by the field they are declared on.
     *
     * @return array<string,Relation>
     */
    public function relations(): array
    {
        $relations = [];
        foreach ($this->fields as $name => $field) {
            if ($field->references !== null) {
                $relations[$name] = $field->references;
            }
        }

        return $relations;
    }

    public function policy(): ViolationPolicy
    {
        return $this->policy;
    }

    /**
     * Check the declarations that can only be verified once the whole schema
     * has been built.
     *
     * @throws SchemaException
     */
    public function assertValid(): void
    {
        foreach ($this->primaryKey as $key) {
            if (!$this->hasField($key)) {
                throw SchemaException::unknownField($key);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @throws SchemaException
     */
    private function declare(string $name, FieldType $type, ?string $from): self
    {
        if (isset($this->fields[$name])) {
            throw SchemaException::duplicateField($name);
        }

        $this->fields[$name] = new Field($name, $type, $from ?? $name);
        $this->current = $name;

        return $this;
    }

    /**
     * @throws SchemaException
     */
    private function assertTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            throw SchemaException::unknownTimezone($timezone);
        }

        return $timezone;
    }

    /**
     * The field a modifier applies to, checked against the types that support it.
     *
     * @param list<FieldType> $allowed
     *
     * @throws SchemaException
     */
    private function modify(string $modifier, array $allowed = []): Field
    {
        if ($this->current === null) {
            throw SchemaException::noCurrentField($modifier);
        }

        $field = $this->fields[$this->current];

        if ($allowed !== [] && !in_array($field->type, $allowed, true)) {
            throw SchemaException::unsupportedModifier($modifier, $field->name, $field->type, $allowed);
        }

        return $field;
    }
}
