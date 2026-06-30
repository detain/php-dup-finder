<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

final class DebugLogger
{
    /** @var resource|false|null */
    private $handle = null;

    public function __construct(private readonly ?string $path)
    {
    }

    public function append(string $message): void
    {
        if ($this->path === null) {
            return;
        }
        if ($this->handle === null || $this->handle === false) {
            $this->handle = fopen($this->path, 'ab');
        }
        if ($this->handle === false) {
            return; // silently skip if we can't open the file
        }
        fwrite($this->handle, $message . "\n");
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
    }
}