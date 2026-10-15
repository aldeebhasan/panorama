<?php

namespace Aldeebhasan\Panorama\Render;

use Aldeebhasan\Panorama\Exceptions\RenderException;

/**
 * The formats a report can be rendered to, by name.
 *
 * Built-ins are ordinary implementations: registering a renderer under an
 * existing name replaces it, and a new format is one more registration with
 * nothing else changing.
 */
final class RendererRegistry
{
    /** @var array<string,RendererContract> */
    private array $renderers = [];

    /**
     * @param list<RendererContract> $renderers
     */
    public function __construct(array $renderers = [])
    {
        foreach ($renderers as $renderer) {
            $this->register($renderer);
        }
    }

    public function register(RendererContract $renderer): self
    {
        $this->renderers[$renderer->format()] = $renderer;

        return $this;
    }

    /**
     * @throws RenderException
     */
    public function get(string $format): RendererContract
    {
        $format = strtolower(trim($format));

        return $this->renderers[$format] ?? throw RenderException::unknownFormat($format, $this->formats());
    }

    public function has(string $format): bool
    {
        return isset($this->renderers[strtolower(trim($format))]);
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        return array_keys($this->renderers);
    }
}
