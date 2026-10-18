<?php

namespace Aldeebhasan\Panorama\History;

/**
 * Where one recorded run stands.
 */
enum RunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
