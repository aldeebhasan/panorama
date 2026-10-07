<?php

namespace Aldeebhasan\Panorama\Schema;

/**
 * What a dataset does when a row breaks its schema.
 */
enum ViolationPolicy: string
{
    /** Stop the run and name the dataset, field, row and reason. */
    case Fail = 'fail';

    /** Drop the offending row and count it in the violation log. */
    case Skip = 'skip';

    /** Replace the offending value with null and count it in the violation log. */
    case Nullify = 'nullify';
}
