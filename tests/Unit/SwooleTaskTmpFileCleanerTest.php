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
        $this->removeTree($this->temporaryDirectory);

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

    public function test_revalidates_root_metadata_after_external_process_replaces_directory_with_symlink(): void
    {
        $cleanupRoot = $this->path('replaceable-root');
        $replacementTarget = $this->path('replacement-target');
        $targetFile = $replacementTarget.'/swoole.task.expired';

        mkdir($cleanupRoot);
        mkdir($replacementTarget);
        file_put_contents($targetFile, 'expired');
        touch($targetFile, 100);

        $cleaner = new SwooleTaskTmpFileCleaner();
        $first = $cleaner->clean($cleanupRoot, 100, 10);

        $this->assertSame(0, $first->failed);
        $this->assertNull($first->errorType);

        $process = proc_open([
            PHP_BINARY,
            '-r',
            'fread(STDIN, 1); if (!rmdir($argv[1]) || !symlink($argv[2], $argv[1])) { exit(1); }',
            $cleanupRoot,
            $replacementTarget,
        ], [0 => ['pipe', 'r']], $pipes);

        $this->assertIsResource($process);
        $this->assertFalse(is_link($cleanupRoot));
        $this->assertTrue(is_dir($cleanupRoot));
        fwrite($pipes[0], 'x');
        fclose($pipes[0]);
        $exitCode = proc_close($process);

        $second = $cleaner->clean($cleanupRoot, 100, 10);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $second->scanned);
        $this->assertSame(0, $second->deleted);
        $this->assertSame(1, $second->failed);
        $this->assertSame(UnexpectedValueException::class, $second->errorType);
        $this->assertFileExists($targetFile);
    }

    public function test_allows_symlinked_ancestor_while_preserving_child_symlinks(): void
    {
        $realAncestor = $this->path('real-ancestor');
        $symlinkedAncestor = $this->path('symlinked-ancestor');
        $cleanupRoot = $realAncestor.'/cleanup-root';
        $expiredTask = $cleanupRoot.'/swoole.task.expired';
        $childLink = $cleanupRoot.'/swoole.task.link';
        $childLinkTarget = $this->path('child-link-target');

        mkdir($cleanupRoot, 0700, true);
        file_put_contents($expiredTask, 'expired');
        touch($expiredTask, 100);
        file_put_contents($childLinkTarget, 'preserve');
        symlink($childLinkTarget, $childLink);
        symlink($realAncestor, $symlinkedAncestor);

        $result = (new SwooleTaskTmpFileCleaner())->clean(
            $symlinkedAncestor.'/cleanup-root',
            100,
            10
        );

        $this->assertSame(2, $result->scanned);
        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, $result->failed);
        $this->assertNull($result->errorType);
        $this->assertFileDoesNotExist($expiredTask);
        $this->assertTrue(is_link($childLink));
        $this->assertFileExists($childLinkTarget);
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

    public function test_implementation_refreshes_root_and_candidate_metadata_before_lstat_checks(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Domain/SwooleTaskTmp/SwooleTaskTmpFileCleaner.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('clearstatcache(true, $normalizedDirectory);', $source);
        $this->assertStringContainsString('$directoryStat = @lstat($normalizedDirectory);', $source);
        $this->assertStringContainsString('clearstatcache(true, $path);', $source);
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
