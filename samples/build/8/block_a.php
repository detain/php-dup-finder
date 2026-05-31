<?php

declare(strict_types=1);

namespace App\Docker;

class DockerfileGenerator
{
    private const DEFAULT_BASE_IMAGE = 'php:8.2-fpm-alpine';
    private const NGINX_IMAGE = 'nginx:1.25-alpine';
    private const NODE_IMAGE = 'node:18-alpine';

    private DockerfileConfig $config;
    private array $instructions = [];

    public function __construct(DockerfileConfig $config)
    {
        $this->config = $config;
        $this->initializeBaseImage();
    }

    private function initializeBaseImage(): void
    {
        $this->instructions[] = [
            'instruction' => 'FROM',
            'value' => $this->config->getBaseImage()
        ];
    }

    public function addLabel(string $key, string $value): self
    {
        $this->instructions[] = [
            'instruction' => 'LABEL',
            'value' => "{$key}=\"{$value}\""
        ];

        return $this;
    }

    public function setWorkingDirectory(string $path): self
    {
        $this->instructions[] = [
            'instruction' => 'WORKDIR',
            'value' => $path
        ];

        return $this;
    }

    public function installSystemPackages(array $packages): self
    {
        $this->instructions[] = [
            'instruction' => 'RUN',
            'value' => 'apk add --no-cache ' . implode(' ', $packages)
        ];

        return $this;
    }

    public function installPhpExtensions(array $extensions): self
    {
        $installCommands = [];

        foreach ($extensions as $extension) {
            $installCommands[] = "docker-php-ext-install {$extension}";
        }

        $this->instructions[] = [
            'instruction' => 'RUN',
            'value' => implode(' && ', $installCommands)
        ];

        return $this;
    }

    public function copyApplicationFiles(string $source, string $dest): self
    {
        $this->instructions[] = [
            'instruction' => 'COPY',
            'value' => "{$source} {$dest}"
        ];

        return $this;
    }

    public function copyComposerFiles(): self
    {
        $this->instructions[] = [
            'instruction' => 'COPY',
            'value' => 'composer.* /var/www/'
        ];

        return $this;
    }

    public function runComposerInstall(bool $dev = false): self
    {
        $command = 'composer install --no-interaction --prefer-dist --optimize-autoloader';

        if (!$dev) {
            $command .= ' --no-dev';
        }

        $this->instructions[] = [
            'instruction' => 'RUN',
            'value' => $command
        ];

        return $this;
    }

    public function setEnvironmentVariable(string $key, string $value): self
    {
        $this->instructions[] = [
            'instruction' => 'ENV',
            'value' => "{$key}={$value}"
        ];

        return $this;
    }

    public function setPhpConfiguration(string $directive, string $value): self
    {
        $phpini = '/usr/local/etc/php/conf.d/custom.ini';

        $this->instructions[] = [
            'instruction' => 'RUN',
            'value' => "echo '{$directive} = {$value}' >> {$phpini}"
        ];

        return $this;
    }

    public function exposePort(int $port): self
    {
        $this->instructions[] = [
            'instruction' => 'EXPOSE',
            'value' => (string)$port
        ];

        return $this;
    }

    public function setHealthCheck(string $command, int $interval = 30, int $timeout = 3): self
    {
        $this->instructions[] = [
            'instruction' => 'HEALTHCHECK',
            'value' => "--interval={$interval}s --timeout={$timeout}s --retries=3 CMD {$command}"
        ];

        return $this;
    }

    public function setEntrypoint(array $command): self
    {
        $this->instructions[] = [
            'instruction' => 'ENTRYPOINT',
            'value' => '[' . implode(', ', array_map(fn($c) => '"' . $c . '"', $command)) . ']'
        ];

        return $this;
    }

    public function setCmd(array $command): self
    {
        $this->instructions[] = [
            'instruction' => 'CMD',
            'value' => '[' . implode(', ', array_map(fn($c) => '"' . $c . '"', $command)) . ']'
        ];

        return $this;
    }

    public function addVolume(string $path): self
    {
        $this->instructions[] = [
            'instruction' => 'VOLUME',
            'value' => $path
        ];

        return $this;
    }

    public function addUser(string $username, ?int $uid = null): self
    {
        $userPart = $uid !== null ? "{$username}:{$uid}" : $username;

        $this->instructions[] = [
            'instruction' => 'USER',
            'value' => $userPart
        ];

        return $this;
    }

    public function createUser(string $username, int $uid, int $gid): self
    {
        $this->instructions[] = [
            'instruction' => 'RUN',
            'value' => "adduser -u {$uid} -G {$gid} -D {$username}"
        ];

        return $this;
    }

    public function createGroup(string $groupname, int $gid): self
    {
        $this->instructions[] = [
            'instruction' => 'RUN',
            'value' => "addgroup -g {$gid} {$groupname}"
        ];

        return $this;
    }

    public function generateMultiStagePhpFpm(): array
    {
        $stages = [];

        $stages['builder'] = [
            ['instruction' => 'FROM', 'value' => 'php:8.2-alpine AS builder'],
            ['instruction' => 'RUN', 'value' => 'apk add --no-cache git unzip icu-dev'],
            ['instruction' => 'COPY', 'value' => 'composer.* /app/'],
            ['instruction' => 'RUN', 'value' => 'composer install --no-interaction --prefer-dist'],
            ['instruction' => 'COPY', 'value' => 'src /app/src']
        ];

        $stages['runtime'] = [
            ['instruction' => 'FROM', 'value' => 'php:8.2-fpm-alpine'],
            ['instruction' => 'RUN', 'value' => 'apk add --no-cache nginx supervisor'],
            ['instruction' => 'COPY', 'value' => '--from=builder /app/vendor /var/www/vendor'],
            ['instruction' => 'COPY', 'value' => 'src /var/www/src'],
            ['instruction' => 'RUN', 'value' => 'chown -R www-data:www-data /var/www']
        ];

        return $stages;
    }

    public function build(): string
    {
        $dockerfile = '';

        foreach ($this->instructions as $instruction) {
            $dockerfile .= $instruction['instruction'] . ' ' . $instruction['value'] . "\n";
        }

        return $dockerfile;
    }

    public function save(string $path): void
    {
        $dockerfile = $this->build();

        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $dockerfile);
    }
}
