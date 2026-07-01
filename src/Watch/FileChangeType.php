<?php
declare(strict_types=1);

namespace Phpdup\Watch;

/**
 * Represents the type of file change detected by FileWatcher.
 */
enum FileChangeType: string
{
    case Created = 'created';
    case Modified = 'modified';
    case Deleted = 'deleted';
}
