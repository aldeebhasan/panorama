<?php

namespace Aldeebhasan\Panorama\Support;

use Aldeebhasan\Panorama\Dataset\Params;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

/**
 * Checks a run's filters against the rules a dataset or report declared.
 *
 * Takes the validation factory as a contract rather than reaching for the
 * facade, so the whole pipeline stays constructible without an application.
 */
final class ParameterValidator
{
    public function __construct(private readonly Factory $validator)
    {
    }

    /**
     * Validate and return the filters, narrowed to the declared rules.
     *
     * @param array<string,mixed> $rules
     *
     * @throws ValidationException
     */
    public function validate(Params $params, array $rules): Params
    {
        if ($rules === []) {
            return $params;
        }

        $validated = $this->validator->make($params->all(), $rules)->validate();

        return Params::make($validated);
    }
}
