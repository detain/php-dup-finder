<?php

declare(strict_types=1);

namespace App\Deployment\Ansible;

class AnsiblePlaybookBuilder
{
    private const DEFAULT_PORT = 22;
    private const DEFAULT_USER = 'root';

    private PlaybookConfig $config;
    private array $hosts = [];
    private array $vars = [];
    private array $tasks = [];
    private array $handlers = [];

    public function __construct(PlaybookConfig $config)
    {
        $this->config = $config;
    }

    public function addHost(string $host, ?string $ansibleHost = null, ?int $port = null): self
    {
        $this->hosts[$host] = [
            'ansible_host' => $ansibleHost ?? $host,
            'ansible_port' => $port ?? self::DEFAULT_PORT,
            'ansible_user' => $this->config->getDefaultUser(),
            'ansible_ssh_private_key_file' => $this->config->getSshKeyPath()
        ];

        return $this;
    }

    public function addHostGroup(string $group, array $hosts): self
    {
        $this->hosts["[{$group}]"] = $hosts;

        return $this;
    }

    public function addVariable(string $key, mixed $value): self
    {
        $this->vars[$key] = $value;

        return $this;
    }

    public function addTask(array $task): self
    {
        $this->validateTask($task);

        $this->tasks[] = $task;

        return $this;
    }

    public function addHandler(array $handler): self
    {
        $this->handlers[] = $handler;

        return $this;
    }

    public function task(string $name, string $module, array $args = [], array $options = []): self
    {
        $task = array_merge([
            'name' => $name,
            $module => $args
        ], $options);

        if (isset($options['when'])) {
            $task['when'] = $options['when'];
        }

        if (isset($options['with_items'])) {
            $task['with_items'] = $options['with_items'];
        }

        $this->tasks[] = $task;

        return $this;
    }

    public function installPackage(string $name, string $package = null): self
    {
        return $this->task(
            "Install {$name}",
            'apt',
            [
                'name' => $package ?? $name,
                'state' => 'present',
                'update_cache' => 'yes'
            ]
        );
    }

    public function ensureService(string $name, string $state = 'started', bool $enabled = true): self
    {
        return $this->task(
            "Ensure {$name} service",
            'service',
            [
                'name' => $name,
                'state' => $state,
                'enabled' => $enabled
            ]
        );
    }

    public function createUser(string $username, array $groups = []): self
    {
        $args = [
            'name' => $username,
            'shell' => '/bin/bash',
            'generate_ssh_key' => 'yes'
        ];

        if (!empty($groups)) {
            $args['groups'] = implode(',', $groups);
        }

        return $this->task("Create user {$username}", 'user', $args);
    }

    public function copyFile(string $src, string $dest, string $owner = 'root', string $mode = '0644'): self
    {
        return $this->task(
            "Copy {$src} to {$dest}",
            'copy',
            [
                'src' => $src,
                'dest' => $dest,
                'owner' => $owner,
                'mode' => $mode,
                'backup' => 'yes'
            ]
        );
    }

    public function templateFile(string $src, string $dest, array $vars = []): self
    {
        return $this->task(
            "Template {$src} to {$dest}",
            'template',
            [
                'src' => $src,
                'dest' => $dest,
                'validate' => 'visudo -cf %s'
            ],
            ['vars' => $vars]
        );
    }

    public function runCommand(string $name, string $command, bool $check = false): self
    {
        return $this->task(
            $name,
            'command',
            ['cmd' => $command, 'creates' => $check ? null : null],
            ['changed_when' => false]
        );
    }

    public function addRepo(string $repo): self
    {
        return $this->task("Add repository {$repo}", 'apt_repository', [
            'repo' => $repo,
            'state' => 'present'
        ]);
    }

    public function pullFromGit(string $repo, string $dest, string $version = 'HEAD'): self
    {
        return $this->task(
            "Clone {$repo} to {$dest}",
            'git',
            [
                'repo' => $repo,
                'dest' => $dest,
                'version' => $version,
                'force' => 'yes'
            ]
        );
    }

    public function createDirectory(string $path, string $owner = 'root', string $mode = '0755', bool $recurse = true): self
    {
        return $this->task(
            "Create directory {$path}",
            'file',
            [
                'path' => $path,
                'state' => 'directory',
                'owner' => $owner,
                'mode' => $mode,
                'recurse' => $recurse
            ]
        );
    }

    public function addLineToFile(string $path, string $line, string $regexp = null): self
    {
        $args = [
            'path' => $path,
            'line' => $line
        ];

        if ($regexp !== null) {
            $args['regexp'] = $regexp;
            $args['state'] = 'present';
        } else {
            $args['append'] = 'yes';
        }

        return $this->task("Add line to {$path}", 'lineinfile', $args);
    }

    public function installNginx(): self
    {
        return $this
            ->task('Update apt cache', 'apt', ['update_cache' => 'yes', 'cache_valid_time' => 3600])
            ->task('Install nginx', 'apt', ['name' => 'nginx', 'state' => 'present'])
            ->task('Start nginx', 'service', ['name' => 'nginx', 'state' => 'started', 'enabled' => true]);
    }

    public function installPhp(): self
    {
        return $this
            ->task('Add PHP repository', 'apt_repository', ['repo' => 'ppa:ondrej/php', 'state' => 'present'])
            ->task('Update apt cache', 'apt', ['update_cache' => 'yes'])
            ->task('Install PHP and extensions', 'apt', [
                'name' => ['php8.2-fpm', 'php8.2-mysql', 'php8.2-curl', 'php8.2-mbstring', 'php8.2-xml', 'php8.2-zip'],
                'state' => 'present'
            ])
            ->task('Start PHP-FPM', 'service', ['name' => 'php8.2-fpm', 'state' => 'started', 'enabled' => true]);
    }

    public function installMysql(): self
    {
        return $this
            ->task('Install MySQL', 'apt', [
                'name' => ['mysql-server', 'python3-mysqldb'],
                'state' => 'present'
            ])
            ->task('Start MySQL', 'service', ['name' => 'mysql', 'state' => 'started', 'enabled' => true])
            ->task('Set MySQL root password', 'mysql_user', [
                'name' => 'root',
                'host' => 'localhost',
                'password' => "{{ mysql_root_password }}",
                'check_implicit_admin' => 'yes'
            ]);
    }

    public function setupFirewall(): self
    {
        return $this
            ->task('Install ufw', 'apt', ['name' => 'ufw', 'state' => 'present'])
            ->task('Allow SSH', 'ufw', ['rule' => 'allow', 'port' => '22', 'proto' => 'tcp'])
            ->task('Allow HTTP', 'ufw', ['rule' => 'allow', 'port' => '80', 'proto' => 'tcp'])
            ->task('Allow HTTPS', 'ufw', ['rule' => 'allow', 'port' => '443', 'proto' => 'tcp'])
            ->task('Enable ufw', 'command', ['cmd' => 'ufw --force enable']);
    }

    public function notifyHandler(string $handlerName): self
    {
        $lastIndex = count($this->tasks) - 1;

        if ($lastIndex >= 0) {
            if (!isset($this->tasks[$lastIndex]['notify'])) {
                $this->tasks[$lastIndex]['notify'] = [];
            }

            $this->tasks[$lastIndex]['notify'][] = $handlerName;
        }

        return $this;
    }

    private function validateTask(array $task): void
    {
        if (!isset($task['name'])) {
            throw new \InvalidArgumentException('Task must have a name');
        }
    }

    public function build(): array
    {
        $playbook = [
            'name' => $this->config->getPlaybookName(),
            'hosts' => $this->config->getTargetHosts(),
            'become' => $this->config->isBecomeEnabled(),
            'vars' => $this->vars,
            'tasks' => $this->tasks
        ];

        if (!empty($this->handlers)) {
            $playbook['handlers'] = $this->handlers;
        }

        if (!empty($this->config->getRoles())) {
            $playbook['roles'] = $this->config->getRoles();
        }

        return $playbook;
    }

    public function toYaml(): string
    {
        $playbook = $this->build();

        $yaml = \Symfony\Component\Yaml\Yaml::dump($playbook, 4, 2, \Symfony\Component\Yaml\DUMP_MULTI_LINE_LITERAL);

        return "# Ansible playbook: " . $this->config->getPlaybookName() . "\n---\n" . $yaml;
    }

    public function save(string $path): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $this->toYaml());
    }
}
