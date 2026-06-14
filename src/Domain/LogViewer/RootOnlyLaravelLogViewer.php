<?php

namespace Mallto\Tool\Domain\LogViewer;

use Rap2hpoutre\LaravelLogViewer\LaravelLogViewer;

class RootOnlyLaravelLogViewer extends LaravelLogViewer
{
    /**
     * @param bool $basename
     * @param string $folder
     *
     * @return array
     */
    public function getFiles($basename = false, $folder = '')
    {
        if ($folder !== '') {
            return [];
        }

        $files = [];
        $pattern = function_exists('config') ? config('logviewer.pattern', '*.log') : '*.log';

        foreach ((array)$this->getStoragePath() as $storagePath) {
            $matchedFiles = glob(rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $pattern) ?: [];
            foreach ($matchedFiles as $file) {
                if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'log') {
                    $files[] = $basename ? basename($file) : $file;
                }
            }
        }

        arsort($files);

        return array_values(array_unique($files));
    }

    /**
     * @param string $folder
     *
     * @return array
     */
    public function getFolders($folder = '')
    {
        return [];
    }

    /**
     * @param bool $basename
     *
     * @return array
     */
    public function getFolderFiles($basename = false)
    {
        return [];
    }

    /**
     * @param null $path
     *
     * @return array
     */
    public function foldersAndFiles($path = null)
    {
        return [];
    }

    /**
     * @param string $file
     *
     * @return string
     * @throws \Exception
     */
    public function pathToLogFile($file)
    {
        if ($file !== basename($file) || strpos($file, '\\') !== false) {
            throw new \Exception('No such log file: ' . $file);
        }

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'log') {
            throw new \Exception('No such log file: ' . $file);
        }

        foreach ((array)$this->getStoragePath() as $storagePath) {
            $logsPath = rtrim($storagePath, DIRECTORY_SEPARATOR);
            $logFile = $logsPath . DIRECTORY_SEPARATOR . $file;

            if (is_file($logFile) && dirname($logFile) === $logsPath) {
                return $logFile;
            }
        }

        $storagePath = (array)$this->getStoragePath();
        $logsPath = rtrim(reset($storagePath), DIRECTORY_SEPARATOR);

        return $logsPath . DIRECTORY_SEPARATOR . $file;
    }
}
