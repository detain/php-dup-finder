<?php

declare(strict_types=1);

namespace Acme\Content\Gating;

use Acme\Content\Model\Article;
use Acme\Content\Model\Viewer;
use Acme\Content\Audit\AccessLogger;
use Acme\Content\Exception\ContentBlockedException;

final class MatureContentGate
{
    public function __construct(private AccessLogger $log)
    {
    }

    public function open(Viewer $viewer, Article $article): string
    {
        if (!$article->isMatureContent()) {
            return $article->body();
        }

        if ($viewer->isMinor()) {
            $this->log->denied($viewer->id(), $article->id(), 'minor');
            throw new ContentBlockedException('This content is not available to minors.');
        }

        $this->log->granted($viewer->id(), $article->id());

        return $article->body();
    }
}
