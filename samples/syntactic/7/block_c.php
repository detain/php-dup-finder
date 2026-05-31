<?php
declare(strict_types=1);

namespace Acme\Build;

interface BuildTarget
{
    public function accept(BuildVisitor $v): string;
}

final class FileTarget implements BuildTarget
{
    public function __construct(public readonly string $path) {}
    public function accept(BuildVisitor $v): string { return $v->visitFile($this); }
}

final class CompositeTarget implements BuildTarget
{
    public function __construct(
        public readonly string $name,
        public readonly BuildTarget $primary,
        public readonly BuildTarget $secondary,
    ) {}
    public function accept(BuildVisitor $v): string { return $v->visitComposite($this); }
}

final class PhonyTarget implements BuildTarget
{
    public function __construct(
        public readonly string $alias,
        public readonly BuildTarget $delegate,
    ) {}
    public function accept(BuildVisitor $v): string { return $v->visitPhony($this); }
}

final class TargetDescriber implements BuildVisitor
{
    public function visitFile(FileTarget $f): string
    {
        return sprintf('File(%s)', basename($f->path));
    }

    public function visitComposite(CompositeTarget $c): string
    {
        return sprintf('Group(%s, %s, %s)', $c->name, $c->primary->accept($this), $c->secondary->accept($this));
    }

    public function visitPhony(PhonyTarget $p): string
    {
        return sprintf('Phony(%s, %s)', $p->alias, $p->delegate->accept($this));
    }
}
