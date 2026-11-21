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
     * The interface gate runs BEFORE construction: a config-chosen
     * name that is not a plugin never reaches `new`, so arbitrary
     * autoloadable classes cannot leak constructor side effects at
     * validation time. Constructor failures are wrapped into a
     * descriptive RuntimeException naming the entry.
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
            // is_subclass_of() triggers the same autoload as class_exists()
            // but inspects the hierarchy without instantiating: the
            // non-plugin rejection below used to happen only AFTER
            // `new $fqcn()`, running the whole argumentless constructor of
            // whatever the config named.
            if (!is_subclass_of($fqcn, NormalizationPlugin::class)) {
                throw new \RuntimeException("Normalization plugin does not implement NormalizationPlugin: $fqcn");
            }
            try {
                $instance = new $fqcn();
            } catch (\Throwable $e) {
                // A plugin whose constructor needs arguments (ArgumentCountError),
                // is abstract, or throws in userland fails HERE with the config
                // entry in the message — never as an unlabelled bare error.
                throw new \RuntimeException(
                    "Normalization plugin '$fqcn' could not be constructed: " . $e->getMessage(),
                    0,
                    $e,
                );
            }
            $registry->add($instance);
        }
        return $registry;
    }
}
