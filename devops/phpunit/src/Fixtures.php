<?php
namespace DrupalComposerManaged;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Set up fixtures for tests
 */
trait Fixtures
{
    protected $cleaner;
    protected $sut;

    public function initFixtures() {
        $this->cleaner = new Cleaner();
        $this->cleaner->preventRegistration();
        $tmpDir = $this->cleaner->tmpdir(sys_get_temp_dir(), 'sut');
        $this->sut = $tmpDir . DIRECTORY_SEPARATOR . 'sut';
    }

    public function cleanupFixtures(): void {
        if (!getenv('DRUPAL_COMPOSER_MANAGED_DIRTY')) {
            $this->cleaner->clean();
        }
    }

    protected function createSut() {
        $source = dirname(__DIR__, 3);
        $filesystem = new Filesystem();
        foreach (['upstream-configuration', 'web'] as $dir) {
            $filesystem->mirror($source . DIRECTORY_SEPARATOR . $dir, $this->sut . DIRECTORY_SEPARATOR . $dir);
        }
        foreach (scandir($source) as $item) {
            $fileToCopy = $source . DIRECTORY_SEPARATOR . $item;
            if (is_file($fileToCopy)) {
                $filesystem->copy($fileToCopy, $this->sut . DIRECTORY_SEPARATOR . $item);
            }
        }

        // Make sure we start out without a lock file in the upstream configuration directory
        @unlink($this->sut . '/upstream-configuration/composer.lock');
        @unlink($this->sut . '/composer.lock');

        // Run 'composer update'. This has two important impacts:
        // 1. The composer.lock file is created, which is necessary for the upstream dependency locking feature to work.
        // 2. Our preUpdate modifications are applied to the SUT.
        $this->composer('update');
    }

    public function sutFileContents($file) {
        return file_get_contents($this->sut . DIRECTORY_SEPARATOR . $file);
    }

    public function assertSutFileContains($needle, $haystackFile) {
        $this->assertStringContainsString($needle, $this->sutFileContents($haystackFile));
    }

    public function assertSutFileNotContains($needle, $haystackFile) {
        $this->assertStringNotContainsString($needle, $this->sutFileContents($haystackFile));
    }

    public function assertSutFileDoesNotExist($file) {
        $this->assertFileDoesNotExist($this->sut . DIRECTORY_SEPARATOR . $file);
    }

    public function assertSutFileExists($file) {
        $this->assertFileExists($this->sut . DIRECTORY_SEPARATOR . $file);
    }

    public function pregReplaceSutFile($regExp, $replace, $file) {
        $path = $this->sut . DIRECTORY_SEPARATOR . $file;
        $contents = file_get_contents($path);
        $contents = preg_replace($regExp, $replace, $contents);
        file_put_contents($path, $contents);
    }

    protected function composer(string $command, array $args = []): Process {
        $cmd = array_merge(['composer', '--working-dir=' . $this->sut, $command], $args);
        $process = new Process($cmd);
        $process->run();

        return $process;
    }

    protected function drush(string $command, array $args = []): Process {
        $cmd = array_merge(['vendor/bin/drush', '--root=' . $this->sut, $command], $args);
        $process = new Process($cmd);
        $process->run();

        return $process;
    }
}
