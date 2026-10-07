<?php

namespace Aldeebhasan\Panorama\Schema;

/**
 * The declared types a dataset field can have.
 */
enum FieldType: string
{
    case String = 'string';
    case Int = 'int';
    case Decimal = 'decimal';
    case Bool = 'bool';
    case Date = 'date';
    case Datetime = 'datetime';
    case Json = 'json';
    case Enum = 'enum';
}
