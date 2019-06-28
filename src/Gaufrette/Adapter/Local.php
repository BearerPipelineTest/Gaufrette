<?php

namespace Gaufrette\Adapter;

use Gaufrette\Exception\FileNotFound;
use Gaufrette\Exception\InvalidKey;
use Gaufrette\Exception\StorageFailure;
use Gaufrette\Util;
use Gaufrette\Adapter;
use Gaufrette\Stream;


/**
 * Adapter for the local filesystem.
 *
 * @author Antoine Hérault <antoine.herault@gmail.com>
 * @author Leszek Prabucki <leszek.prabucki@gmail.com>
 */
class Local implements Adapter,
    StreamFactory,
    ChecksumCalculator,
    SizeCalculator,
    MimeTypeProvider
{
    protected $directory;
    private $mode;

    /**
     * @param string $directory Directory where the filesystem is located
     * @param int    $mode      Mode of directory created by the adapter.
     *
     */
    public function __construct($directory, $mode = 0777)
    {
        $this->directory = Util\Path::normalize($directory);
        $this->mode = $mode;

        if (is_link($this->directory)) {
            $this->directory = realpath($this->directory);
        }

        if (!is_dir($this->directory)) {
            throw new StorageFailure(
                sprintf('Directory "%s" does not exist.', $directory)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        if ($this->isDirectory($key)) {
            throw StorageFailure::unexpectedFailure(
                'read',
                ['key' => $key],
                new InvalidKey(sprintf('Cannot read "%s" as it is a directory', $key))
            );
        }

        if (false === $content = @file_get_contents($this->computePath($key))) {
            throw StorageFailure::unexpectedFailure('read', ['key' => $key]);
        }

        return $content;
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $path = $this->computePath($key);
        $this->ensureDirectoryExists(\Gaufrette\Util\Path::dirname($key));

        if (false === @file_put_contents($path, $content)) {
            throw StorageFailure::unexpectedFailure('write', ['key' => $key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $targetPath = $this->computePath($targetKey);
        $this->ensureDirectoryExists(\Gaufrette\Util\Path::dirname($targetKey));

        if (!@rename($this->computePath($sourceKey), $targetPath)) {
            throw StorageFailure::unexpectedFailure('rename', ['sourceKey' => $sourceKey, 'targetKey' => $targetKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        return is_file($this->computePath($key));
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        $this->ensureDirectoryExists($this->directory);

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->directory,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
        } catch (\Exception $e) {
            $files = new \EmptyIterator();
        }

        $keys = [];
        foreach ($files as $file) {
            $keys[] = $this->computeKey($file);
        }
        sort($keys);

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        if (false === $mtime = filemtime($this->computePath($key))) {
            throw StorageFailure::unexpectedFailure('mtime', ['key' => $key]);
        }

        return $mtime;
    }

    /**
     * {@inheritdoc}
     *
     * Can also delete a directory recursively when the given $key matches a
     * directory.
     */
    public function delete($key)
    {
        if ($this->isDirectory($key)) {
            try {
                if (!$this->deleteDirectory($this->computePath($key))) {
                    throw StorageFailure::unexpectedFailure('delete', ['key' => $key]);
                }
            } catch (\InvalidArgumentException $e) {
                throw StorageFailure::unexpectedFailure('delete', ['key' => $key], $e);
            }

            return;
        } elseif ($this->exists($key)) {
            if (!unlink($this->computePath($key))) {
                throw StorageFailure::unexpectedFailure('delete', ['key' => $key]);
            }

            return;
        }

        throw new FileNotFound($key);
    }

    /**
     * @param string $key
     *
     * @return bool
     *
     * @throws InvalidKey If the computed path is out of the directory
     */
    public function isDirectory($key)
    {
        return is_dir($this->computePath($key));
    }

    /**
     * {@inheritdoc}
     *
     * @throws \OutOfBoundsException     If the computed path is out of the directory
     * @throws \InvalidArgumentException if the directory already exists
     * @throws \RuntimeException         if the directory could not be created
     */
    public function createStream($key)
    {
        return new Stream\Local($this->computePath($key), $this->mode);
    }

    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        if (!$this->exists($key)) {
            throw new FileNotFound($key);
        }

        if (false === $checksum = Util\Checksum::fromFile($this->computePath($key))) {
            throw StorageFailure::unexpectedFailure('checksum', ['key' => $key]);
        }

        return $checksum;
    }

    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        if (!$this->exists($key)) {
            throw new FileNotFound($key);
        }

        if (false === $size = Util\Size::fromFile($this->computePath($key))) {
            throw StorageFailure::unexpectedFailure('size', ['key' => $key]);
        }

        return $size;
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType($key)
    {
        if (!$this->exists($key)) {
            throw new FileNotFound($key);
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);

        if (false === $mimeType = $fileInfo->file($this->computePath($key))) {
            throw StorageFailure::unexpectedFailure('mimeType', ['key' => $key]);
        }

        return $mimeType;
    }

    /**
     * Computes the key from the specified path.
     *
     * @param string $path
     *
     * @return string
     *
     * @throws InvalidKey If the computed path is out of the directory
     */
    public function computeKey($path)
    {
        $path = $this->normalizePath($path);

        return ltrim(substr($path, strlen($this->directory)), '/');
    }

    /**
     * Computes the path from the specified key.
     *
     * @param string $key The key which for to compute the path
     *
     * @return string A path
     *
     * @throws InvalidKey If the computed path is out of the base directory
     */
    protected function computePath($key)
    {
        return $this->normalizePath($this->directory.'/'.$key);
    }

    /**
     * Normalizes the given path.
     *
     * @param string $path
     *
     * @return string
     *
     * @throws InvalidKey If the computed path is out of the base directory
     */
    protected function normalizePath($path)
    {
        $path = Util\Path::normalize($path);

        if (0 !== strpos($path, $this->directory)) {
            throw new InvalidKey(sprintf('The path "%s" is out of the filesystem.', $path));
        }

        return $path;
    }

    /**
     * Ensures the specified directory exists, creates it if it does not.
     *
     * @param string $key Path of the directory to test, relative to the base directory of the adapter.
     *
     * @throws InvalidKey     When the $key is not valid.
     * @throws StorageFailure When the directory creation failed.
     */
    protected function ensureDirectoryExists($key)
    {
        if (file_exists($key)) {
            if (!is_dir($key)) {
                throw new StorageFailure(sprintf('Could not create directory "%s" because it\'s a file.', $key));
            }

            return;
        }

        $directory = $this->computePath($key);

        if (!@mkdir($directory, $this->mode, true) && !is_dir($directory)) {
            throw new StorageFailure(sprintf('The directory "%s" could not be created.', $key));
        }
    }

    /**
     * @param string The directory's path to delete
     *
     * @throws \InvalidArgumentException When attempting to delete the root
     * directory of this adapter.
     *
     * @return bool Wheter the operation succeeded or not
     */
    private function deleteDirectory($directory)
    {
        if ($this->directory === $directory) {
            throw new \InvalidArgumentException(
                sprintf('Impossible to delete the root directory of this Local adapter ("%s").', $directory)
            );
        }

        $status = true;

        if (file_exists($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    $status = $status && rmdir(strval($item));
                } else {
                    $status = $status && unlink(strval($item));
                }
            }

            $status = $status && rmdir($directory);
        }

        return $status;
    }
}
