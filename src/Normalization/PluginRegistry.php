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

    /**
     * Parent-process screen: which config-named plugins fail to load?
     *
     * once-S66 (finding once-075). A plugin whose constructor throws used
     * to detonate inside every worker fork (and every refactor-side
     * BlockAstLoader reload) as a raw RuntimeException — a Symfony usage
     * dump, no report. Command screens the whole list HERE, once, and
     * degrades the run instead: failing entries come back keyed by name
     * with the human-readable reason as value, and the run is rebuilt on
     * the surviving names alone.
     *
     * The gate order mirrors {@see self::fromClassNames()} exactly — the
     * interface gate still runs BEFORE construction, so a non-plugin name
     * never reaches `new` and leaks constructor side effects — but
     * load/shape/construct failures are COLLECTED, not thrown. Malformed
     * entries (non-strings) do throw: that is an admission-class config
     * defect, the same door {@see self::fromClassNames()} guards bare.
     *
     * Healthy plugins are constructed once here and discarded; the real
     * instances are still built per-normalizer from the surviving names,
     * exactly as before (constructor side effects: the docs treat plugin
     * construction as once-per-normalizer-run and cheap).
     *
     * @param list<string> $classNames
     * @return array<string, string> failing FQCN => exclusion reason
     */
    public static function screenClassNames(array $classNames): array
    {
        $failures = [];
        foreach ($classNames as $fqcn) {
            if (!is_string($fqcn) || $fqcn === '') {
                throw new \RuntimeException('normalization.plugins entries must be non-empty strings');
            }
            if (!class_exists($fqcn)) {
                $failures[$fqcn] = 'class not found';
                continue;
            }
            if (!is_subclass_of($fqcn, NormalizationPlugin::class)) {
                // Same pre-construction gate as fromClassNames(): the
                // class is never instantiated here, side-effect-free.
                $failures[$fqcn] = 'does not implement NormalizationPlugin';
                continue;
            }
            try {
                new $fqcn();
            } catch (\Throwable $e) {
                $failures[$fqcn] = 'could not be constructed: ' . $e->getMessage();
            }
        }
        return $failures;
    }
}
