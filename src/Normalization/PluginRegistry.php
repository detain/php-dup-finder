<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

/**
 * Registry of user-loaded {@see NormalizationPlugin} instances.
 *
 * Resolved from class names in `normalization.plugins` (config file)
 * — each must be autoloadable via Composer and implement the
 * NormalizationPlugin interface. CanonicalizingVisitor consults the
 * registry once per node so plugins act after the built-in passes.
 */
final class PluginRegistry
{
    /** @var list<NormalizationPlugin> */
    private array $plugins = [];

    public function add(NormalizationPlugin $plugin): void
    {
        $this->plugins[] = $plugin;
    }

    /** @return list<NormalizationPlugin> */
    public function plugins(): array
    {
        return $this->plugins;
    }

    /**
     * Build a registry from a list of FQCNs. Each class must be
     * loadable + implement {@see NormalizationPlugin}; otherwise a
     * RuntimeException carries the offending entry.
     *
     * @param list<string> $classNames
     */
    public static function fromClassNames(array $classNames): self
    {
        $registry = new self();
        foreach ($classNames as $fqcn) {
            if (!is_string($fqcn) || $fqcn === '') {
                throw new \RuntimeException('normalization.plugins entries must be non-empty strings');
            }
            if (!class_exists($fqcn)) {
                throw new \RuntimeException("Normalization plugin class not found: $fqcn");
            }
            $instance = new $fqcn();
            if (!$instance instanceof NormalizationPlugin) {
                throw new \RuntimeException("Normalization plugin does not implement NormalizationPlugin: $fqcn");
            }
            $registry->add($instance);
        }
        return $registry;
    }
}
