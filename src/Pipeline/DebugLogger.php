<?php
declare(strict_types=1);

namespace Phpdup\Pipeline;

final class DebugLogger
{
    /**
     * Handle state machine: null = closed / not yet opened (append may open),
     * resource = open log file, false = last open attempt failed (append retries).
     * Every state is destructor-safe: close only ever runs on a real resource.
     *
     * @var resource|false|null
     */
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

    /**
     * Close the current handle (if any) and return the state machine to
     * "closed". Only a real resource is ever handed to fclose: a failed
     * open leaves handle === false, and fclose(false) raises a TypeError
     * during shutdown — turning a completed successful run into exit 255.
     */
    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }
}