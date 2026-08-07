<?php

namespace Mallto\Tool\Tests\Unit;

use FilesystemIterator;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpFilesystemStats;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpInventoryScanner;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class SwooleTaskTmpInventoryScannerTest extends TestCase
{
    private string $temporaryDirectory;

    private string $rootSymlink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/swoole-task-inventory-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        $this->rootSymlink = $this->temporaryDirectory.'-symlink';
        symlink($this->temporaryDirectory, $this->rootSymlink);
    }

    protected function tearDown(): void
    {
        if (is_link($this->rootSymlink)) {
            unlink($this->rootSymlink);
        }

        $this->removeTree($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_inventory_counts_only_matching_regular_files_and_preserves_symlinks(): void
    {
        $this->write('swoole.task.old', 40, 12);
        $this->write('swoole.task.new', 120, 8);
        $this->write('upload.tmp', 30, 4);
        symlink($this->path('swoole.task.old'), $this->path('swoole.task.link'));

        $result = (new SwooleTaskTmpInventoryScanner())->scan($this->temporaryDirectory, 100);

        $this->assertTrue($result->complete);
        $this->assertSame(2, $result->matchingFiles);
        $this->assertSame(20, $result->matchingBytes);
        $this->assertSame(1, $result->staleFiles);
        $this->assertSame(12, $result->staleBytes);
        $this->assertSame(40, $result->oldestMtime);
        $this->assertTrue(is_link($this->path('swoole.task.link')));
    }

    public function test_inventory_rejects_a_symlink_as_the_root_directory(): void
    {
        $result = (new SwooleTaskTmpInventoryScanner())->scan($this->rootSymlink, 100);

        $this->assertFalse($result->complete);
        $this->assertSame(UnexpectedValueException::class, $result->errorType);
    }

    public function test_inventory_can_be_interrupted_without_reporting_a_complete_snapshot(): void
    {
        $this->write('swoole.task.old', 40, 12);

        $result = (new SwooleTaskTmpInventoryScanner())->scan(
            $this->temporaryDirectory,
            100,
            static fn (): bool => false
        );

        $this->assertFalse($result->complete);
        $this->assertSame(0, $result->scanned);
        $this->assertNull($result->errorType);
    }

    public function test_inventory_result_is_serializable_for_a_snapshot(): void
    {
        $this->write('swoole.task.old', 40, 12);

        $result = (new SwooleTaskTmpInventoryScanner())->scan($this->temporaryDirectory, 100);

        $this->assertSame([
            'scanned' => 1,
            'matchingFiles' => 1,
            'matchingBytes' => 12,
            'staleFiles' => 1,
            'staleBytes' => 12,
            'oldestMtime' => 40,
            'complete' => true,
            'durationMilliseconds' => $result->durationMilliseconds,
            'errorType' => null,
        ], $result->toArray());
    }

    public function test_filesystem_stats_reads_capacity_for_a_safe_directory(): void
    {
        $stats = (new SwooleTaskTmpFilesystemStats())->read($this->temporaryDirectory);

        $this->assertTrue($stats['ok']);
        $this->assertIsInt($stats['total_bytes']);
        $this->assertIsInt($stats['free_bytes']);
        $this->assertIsInt($stats['used_bytes']);
        $this->assertIsFloat($stats['used_percent']);
        $this->assertNull($stats['error_type']);
    }

    public function test_filesystem_stats_rejects_a_symlink_as_the_root_directory(): void
    {
        $stats = (new SwooleTaskTmpFilesystemStats())->read($this->rootSymlink);

        $this->assertFalse($stats['ok']);
        $this->assertNull($stats['total_bytes']);
        $this->assertSame(UnexpectedValueException::class, $stats['error_type']);
    }

    public function test_implementation_uses_a_streaming_iterator_and_refreshes_candidate_metadata(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Domain/SwooleTaskTmp/SwooleTaskTmpInventoryScanner.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('new FilesystemIterator(', $source);
        $this->assertStringContainsString('clearstatcache(true, $path);', $source);
        $this->assertStringNotContainsString('glob(', $source);
        $this->assertStringNotContainsString('scandir(', $source);
    }

    private function write(string $name, int $mtime, int $bytes): void
    {
        $path = $this->path($name);
        file_put_contents($path, str_repeat('x', $bytes));
        touch($path, $mtime);
    }

    private function path(string $name): string
    {
        return $this->temporaryDirectory.'/'.$name;
    }

    private function removeTree(string $path): void
    {
        clearstatcache(true, $path);

        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        chmod($path, 0700);

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
            $this->removeTree($entry->getPathname());
        }

        rmdir($path);
    }
}
