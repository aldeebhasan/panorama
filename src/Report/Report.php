<?php

namespace Aldeebhasan\Panorama\Report;

use Aldeebhasan\Panorama\Dataset\Datasets;
use Aldeebhasan\Panorama\Query\Merge;

/**
 * The base class a report extends: name a few things, implement one method.
 *
 * The key and the name are derived from the class name unless declared, the
 * same way a Mailable or a Notification works.
 */
abstract class Report implements ReportContract
{
    /**
     * The key this report is run by. Defaults to the kebab-cased class basename.
     */
    protected string $key = '';

    /**
     * The human readable name. Defaults to the class basename made readable.
     */
    protected string $name = '';

    /**
     * The workspace engine to run on, null for the configured default.
     */
    protected ?string $engine = null;

    abstract public function query(Datasets $datasets): Merge;

    public function key(): string
    {
        if ($this->key !== '') {
            return $this->key;
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $this->basename()));
    }

    public function name(): string
    {
        return $this->name !== ''
            ? $this->name
            : ucwords(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $this->basename())));
    }

    /**
     * @return array<string,mixed>
     */
    public function parameters(): array
    {
        return [];
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return [];
    }

    public function authorize(mixed $user = null): bool
    {
        return true;
    }

    public function engine(): ?string
    {
        return $this->engine;
    }

    /**
     * The file name rendered output is given, without an extension.
     */
    public function filename(): string
    {
        return $this->key();
    }

    private function basename(): string
    {
        return basename(str_replace('\\', '/', static::class));
    }
}
