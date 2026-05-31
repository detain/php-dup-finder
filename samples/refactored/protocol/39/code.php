<?php
declare(strict_types=1);

namespace App\Services\Upload;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;

abstract class AbstractUploadHandler
{
    protected ConfigManager $config;
    protected LoggerInterface $logger;
    protected string $uploadDir;
    protected int $maxFileSize;
    protected array $allowedTypes;

    abstract protected function getUploadType(): string;
    abstract protected function getConfigPrefix(): string;

    protected function validateUpload(array $file, array &$errors): bool
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $this->getUploadErrorMessage($file['error']);
            return false;
        }
        
        if ($file['size'] > $this->maxFileSize) {
            $errors[] = sprintf('File size exceeds maximum allowed (%d MB)', $this->maxFileSize / 1048576);
            return false;
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($detectedType, $this->allowedTypes, true)) {
            $errors[] = 'Invalid file type. Allowed types: ' . implode(', ', $this->allowedTypes);
            return false;
        }
        
        return true;
    }

    protected function uploadWithProgress(string $sourcePath, string $targetPath, callable $progressCallback = null): bool
    {
        $sourceSize = filesize($sourcePath);
        $uploaded = 0;
        
        $source = fopen($sourcePath, 'rb');
        $target = fopen($targetPath, 'wb');
        
        if (!$source || !$target) {
            return false;
        }
        
        while (!feof($source)) {
            $chunk = fread($source, 8192);
            $written = fwrite($target, $chunk);
            
            if ($written === false) {
                fclose($source);
                fclose($target);
                return false;
            }
            
            $uploaded += $written;
            
            if ($progressCallback !== null) {
                $progress = $sourceSize > 0 ? ($uploaded / $sourceSize) * 100 : 100;
                $progressCallback((int)$progress);
            }
        }
        
        fclose($source);
        fclose($target);
        
        return true;
    }

    protected function generateFileName(string $originalName): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $prefix = strtolower($this->getUploadType());
        return sprintf('%s_%s.%s', uniqid($prefix . '_', true), hash('sha256', time()), $extension);
    }

    protected function getUploadErrorMessage(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        ];
        
        return $errors[$errorCode] ?? 'Unknown upload error';
    }
}
