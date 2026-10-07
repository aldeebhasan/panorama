<?php

namespace Aldeebhasan\Panorama\Connection;

/**
 * How to reach one system. Defined once in config, shared by many datasets.
 *
 * The contract itself is deliberately thin: what a dataset needs from a
 * database connection and from an HTTP API have nothing in common, so each
 * driver exposes its own API and datasets ask for the kind they need through
 * the typed accessors on {@see \Aldeebhasan\Panorama\Dataset\Dataset}.
 */
interface ConnectionContract
{
    /**
     * The name this connection is configured under, e.g. `crm_api`.
     */
    public function name(): string;

    /**
     * The driver that built it: `database`, `http` or `array`.
     */
    public function driver(): string;
}
