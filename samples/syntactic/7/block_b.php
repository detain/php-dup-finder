<?php
declare(strict_types=1);

namespace Acme\Docs;

interface DocNode
{
    public function accept(DocVisitor $v): string;
}

final class Paragraph implements DocNode
{
    public function __construct(public readonly string $text) {}
    public function accept(DocVisitor $v): string { return $v->visitParagraph($this); }
}

final class Section implements DocNode
{
    public function __construct(
        public readonly string $title,
        public readonly DocNode $intro,
        public readonly DocNode $body,
    ) {}
    public function accept(DocVisitor $v): string { return $v->visitSection($this); }
}

final class CalloutBlock implements DocNode
{
    public function __construct(
        public readonly string $variant,
        public readonly DocNode $content,
    ) {}
    public function accept(DocVisitor $v): string { return $v->visitCallout($this); }
}

final class DocOutline implements DocVisitor
{
    public function visitParagraph(Paragraph $p): string
    {
        return sprintf('P(%s)', substr($p->text, 0, 20));
    }

    public function visitSection(Section $s): string
    {
        return sprintf('S(%s, %s, %s)', $s->title, $s->intro->accept($this), $s->body->accept($this));
    }

    public function visitCallout(CalloutBlock $c): string
    {
        return sprintf('C(%s, %s)', $c->variant, $c->content->accept($this));
    }
}
