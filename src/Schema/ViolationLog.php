<?php

namespace Aldeebhasan\Panorama\Schema;

/**
 * What a normalization pass did: how many rows came through, how many were
 * dropped or patched, and a sample of the reasons why.
 *
 * Counts are unbounded, stored violations are not. A source with a million bad
 * rows must not turn the log into the thing that exhausts memory, so only the
 * first `$sampleSize` are kept.
 */
final class ViolationLog
{
    /** @var list<Violation> */
    private array $violations = [];

    private int $accepted = 0;
    private int $skipped = 0;
    private int $nullified = 0;

    public function __construct(private readonly int $sampleSize = 100)
    {
    }

    public function accept(): void
    {
        $this->accepted++;
    }

    public function skip(Violation $violation): void
    {
        $this->skipped++;
        $this->record($violation);
    }

    public function nullify(Violation $violation): void
    {
        $this->nullified++;
        $this->record($violation);
    }

    /**
     * Rows that passed the schema and were yielded.
     */
    public function accepted(): int
    {
        return $this->accepted;
    }

    /**
     * Rows dropped under the `skip` policy.
     */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /**
     * Values replaced with null under the `nullify` policy.
     */
    public function nullified(): int
    {
        return $this->nullified;
    }

    public function clean(): bool
    {
        return $this->skipped === 0 && $this->nullified === 0;
    }

    /**
     * The first violations seen, capped at the sample size.
     *
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    private function record(Violation $violation): void
    {
        if (count($this->violations) < $this->sampleSize) {
            $this->violations[] = $violation;
        }
    }
}
