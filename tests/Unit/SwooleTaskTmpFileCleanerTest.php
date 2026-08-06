<?php

namespace Mallto\Tool\Tests\Unit;

use FilesystemIterator;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpFileCleaner;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class SwooleTaskTmpFileCleanerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/swoole-task-cleaner-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            chmod($this->temporaryDirectory, 0700);

            foreach (new FilesystemIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }

            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_deletes_only_expired_matching_regular_files(): void
    {
        $expiredTask = $this->path('swoole.task.expired');
        $freshTask = $this->path('swoole.task.fresh');
        $otherFile = $this->path('upload.tmp');
        $matchingDirectory = $this->path('swoole.task.directory');
        $matchingLink = $this->path('swoole.task.link');

        file_put_contents($expiredTask, 'expired');
        file_put_contents($freshTask, 'fresh');
        file_put_contents($otherFile, 'other');
        mkdir($matchingDirectory);
        symlink($otherFile, $matchingLink);
        touch($expiredTask, 100);
        touch($freshTask, 101);
        touch($otherFile, 100);

        $result = (new SwooleTaskTmpFileCleaner())->clean($this->temporaryDirectory, 100, 100);

        $this->assertSame(5, $result->scanned);
        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->limitReached);
        $this->assertFalse($result->interrupted);
        $this->assertFileDoesNotExist($expiredTask);
        $this->assertFileExists($freshTask);
        $this->assertFileExists($otherFile);
        $this->assertDirectoryExists($matchingDirectory);
        $this->assertTrue(is_link($matchingLink));
    }

    public function test_stops_at_delete_limit_and_finishes_remaining_files_next_round(): void
    {
        foreach (['a', 'b', 'c'] as $suffix) {
            $path = $this->path('swoole.task.'.$suffix);
            file_put_contents($path, $suffix);
            touch($path, 100);
        }

        $cleaner = new SwooleTaskTmpFileCleaner();
        $first = $cleaner->clean($this->temporaryDirectory, 100, 2);
        $second = $cleaner->clean($this->temporaryDirectory, 100, 2);

        $this->assertSame(2, $first->deleted);
        $this->assertTrue($first->limitReached);
        $this->assertSame(1, $second->deleted);
        $this->assertFalse($second->limitReached);
        $this->assertSame([], iterator_to_array(
            new FilesystemIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS)
        ));
    }

    public function test_rejects_relative_or_missing_directories_without_throwing(): void
    {
        $cleaner = new SwooleTaskTmpFileCleaner();

        foreach (['relative/path', $this->path('missing')] as $directory) {
            $result = $cleaner->clean($directory, 100, 10);

            $this->assertSame(0, $result->scanned);
            $this->assertSame(0, $result->deleted);
            $this->assertSame(1, $result->failed);
            $this->assertSame(UnexpectedValueException::class, $result->errorType);
        }
    }

    public function test_rejects_symlink_root_without_scanning_its_target(): void
    {
        $targetDirectory = $this->path('target');
        $symlinkDirectory = $this->path('symlink-root');
        $targetFile = $targetDirectory.'/swoole.task.expired';

        mkdir($targetDirectory);
        file_put_contents($targetFile, 'expired');
        touch($targetFile, 100);
        symlink($targetDirectory, $symlinkDirectory);

        $result = (new SwooleTaskTmpFileCleaner())->clean($symlinkDirectory, 100, 10);

        $this->assertSame(0, $result->scanned);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(1, $result->failed);
        $this->assertSame(UnexpectedValueException::class, $result->errorType);
        $this->assertFileExists($targetFile);
    }

    public function test_rejects_symlink_root_with_trailing_directory_separator_without_scanning_its_target(): void
    {
        $targetDirectory = $this->path('target-with-trailing-slash');
        $symlinkDirectory = $this->path('symlink-root-with-trailing-slash');
        $targetFile = $targetDirectory.'/swoole.task.expired';

        mkdir($targetDirectory);
        file_put_contents($targetFile, 'expired');
        touch($targetFile, 100);
        symlink($targetDirectory, $symlinkDirectory);

        $result = (new SwooleTaskTmpFileCleaner())->clean($symlinkDirectory.'/', 100, 10);

        $this->assertSame(0, $result->scanned);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(1, $result->failed);
        $this->assertSame(UnexpectedValueException::class, $result->errorType);
        $this->assertFileExists($targetFile);
    }

    public function test_scan_can_be_interrupted_by_laravels_reload_or_stop(): void
    {
        $path = $this->path('swoole.task.expired');
        file_put_contents($path, 'expired');
        touch($path, 100);

        $result = (new SwooleTaskTmpFileCleaner())->clean(
            $this->temporaryDirectory,
            100,
            10,
            static fn (): bool => false
        );

        $this->assertTrue($result->interrupted);
        $this->assertSame(0, $result->scanned);
        $this->assertSame(0, $result->deleted);
        $this->assertFileExists($path);
    }

    public function test_unlink_failures_are_counted_and_do_not_abort_the_scan(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can unlink files from a read-only directory.');
        }

        foreach (['a', 'b'] as $suffix) {
            $path = $this->path('swoole.task.'.$suffix);
            file_put_contents($path, $suffix);
            touch($path, 100);
        }

        chmod($this->temporaryDirectory, 0500);

        try {
            $result = (new SwooleTaskTmpFileCleaner())->clean($this->temporaryDirectory, 100, 10);
        } finally {
            chmod($this->temporaryDirectory, 0700);
        }

        $this->assertSame(2, $result->scanned);
        $this->assertSame(0, $result->deleted);
        $this->assertSame(2, $result->failed);
        $this->assertNull($result->errorType);
    }

    public function test_implementation_streams_entries_without_building_a_path_array(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Domain/SwooleTaskTmp/SwooleTaskTmpFileCleaner.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('new FilesystemIterator(', $source);
        $this->assertStringNotContainsString('glob(', $source);
        $this->assertStringNotContainsString('scandir(', $source);
    }

    private function path(string $name): string
    {
        return $this->temporaryDirectory.'/'.$name;
    }
}
