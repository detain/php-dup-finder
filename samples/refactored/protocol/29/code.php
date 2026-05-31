<?php
declare(strict_types=1);

namespace App\Services\Transfer;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

abstract class AbstractFtpService
{
    protected ConfigManager $config;
    protected LoggerInterface $logger;
    protected $connection;
    protected bool $isConnected = false;
    protected string $host;
    protected int $port;
    protected string $username;
    protected string $password;

    abstract protected function getConfigPrefix(): string;

    public function connect(): bool
    {
        try {
            $this->connection = ftp_connect($this->host, $this->port);
            
            if ($this->connection === false) {
                throw new \RuntimeException("Failed to connect to {$this->host}");
            }
            
            $loginResult = ftp_login($this->connection, $this->username, $this->password);
            
            if ($loginResult === false) {
                throw new \RuntimeException('FTP login failed');
            }
            
            if (!ftp_pasv($this->connection, true)) {
                throw new \RuntimeException('Failed to enable passive mode');
            }
            
            $this->isConnected = true;
            
            $this->logger->info(static::class . ' FTP connected', [
                'host' => $this->host,
                'port' => $this->port,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' FTP connection failed', [
                'host' => $this->host,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        if (!$this->isConnected) {
            $this->connect();
        }
        
        try {
            $uploaded = ftp_put($this->connection, $remotePath, $localPath, FTP_BINARY);
            
            if ($uploaded === false) {
                throw new \RuntimeException("Failed to upload to {$remotePath}");
            }
            
            $this->logger->info(static::class . ' file uploaded', [
                'local' => $localPath,
                'remote' => $remotePath,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' FTP upload failed', [
                'local' => $localPath,
                'remote' => $remotePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function download(string $remotePath, string $localPath): bool
    {
        if (!$this->isConnected) {
            $this->connect();
        }
        
        try {
            $downloaded = ftp_get($this->connection, $localPath, $remotePath, FTP_BINARY);
            
            if ($downloaded === false) {
                throw new \RuntimeException("Failed to download from {$remotePath}");
            }
            
            $this->logger->info(static::class . ' file downloaded', [
                'remote' => $remotePath,
                'local' => $localPath,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' FTP download failed', [
                'remote' => $remotePath,
                'local' => $localPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function listFiles(string $directory): array
    {
        if (!$this->isConnected) {
            $this->connect();
        }
        
        try {
            $files = ftp_nlist($this->connection, $directory);
            
            if ($files === false) {
                throw new \RuntimeException("Failed to list files in {$directory}");
            }
            
            $this->logger->debug(static::class . ' directory listed', [
                'directory' => $directory,
                'file_count' => count($files),
            ]);
            
            return $files;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' FTP list failed', [
                'directory' => $directory,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function delete(string $remotePath): bool
    {
        if (!$this->isConnected) {
            $this->connect();
        }
        
        try {
            $deleted = ftp_delete($this->connection, $remotePath);
            
            if ($deleted === false) {
                throw new \RuntimeException("Failed to delete {$remotePath}");
            }
            
            $this->logger->info(static::class . ' file deleted', ['remote' => $remotePath]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error(static::class . ' FTP delete failed', [
                'remote' => $remotePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->isConnected && $this->connection !== null) {
            ftp_close($this->connection);
            $this->isConnected = false;
            $this->logger->info(static::class . ' FTP disconnected');
        }
    }
}
