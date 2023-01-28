<?php

/**
 * @file
 * Contains \DrupalComposerManaged\ComposerScripts.
 *
 * Custom Composer scripts and implementations of Composer hooks.
 */

namespace DrupalComposerManaged;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;

/**
 * Implementation for Composer scripts and Composer hooks.
 */
class ComposerScripts {

  // Only for use in preUpdate / postUpdate hooks.
  static $updateCleaner = null;

  /**
   * Add a dependency to the upstream-configuration section of a custom upstream.
   *
   * The upstream-configuration/composer.json is a place to put modules, themes
   * and other dependencies that will be inherited by all sites created from
   * the upstream. Separating the upstream dependencies from the site dependencies
   * has the advantage that changes can be made to the upstream without causing
   * conflicts in the downstream sites.
   *
   * To add a dependency to an upstream:
   *
   *    composer upstream-require drupal/modulename
   *
   * Important: Dependencies should only be removed from upstreams with caution.
   * The module / theme must be uninstalled from all sites that are using it
   * before it is removed from the code base; otherwise, the module cannot be
   * cleanly uninstalled.
   */
  public static function upstreamRequire(Event $event) {
    $io = $event->getIO();
    $composer = $event->getComposer();

    // This command can only be used in custom upstreams
    static::failUnlessIsCustomUpstream($io, $composer);

    static::doUpstreamRequire($io, $event->getArguments());
  }

  /**
   * Do the upstream require operation
   */
  protected static function doUpstreamRequire(IOInterface $io, array $arguments) {
    $hasNoUpdate = array_search('--no-update', $arguments) !== false;
    // $hasNoInstall = array_search('--no-install', $arguments) !== false;
    // Remove --working-dir, --no-update and --no-install, if provided
    $arguments = array_filter($arguments, function ($item) {
      return
        (substr($item, 0, 13) != '--working-dir') &&
        ($item != '--no-update') &&
        ($item != '--no-install');
    });
    // Escape the arguments passed in.
    $args = array_map(function ($item) {
      return escapeshellarg($item);
    }, $arguments);

    // Run `require` with '--no-update' if there is no composer.lock file,
    // and without it if there is.
    $addNoUpdate = $hasNoUpdate || !file_exists('upstream-configuration/composer.lock');

    if ($addNoUpdate) {
      $args[] = '--no-update';
    }
    else {
      $args[] = '--no-install';
    }

    // Insert the new projects into the upstream-configuration composer.json
    // without writing vendor & etc to the upstream-configuration directory.
    $cmd = "composer --working-dir=upstream-configuration require " . implode(' ', $args);
    $io->writeError($cmd . PHP_EOL);
    passthru($cmd, $statusCode);

    if (!$statusCode) {
      $io->writeError('upstream-configuration/composer.json updated. Commit the upstream-configuration/composer.lock file if you with to lock your upstream dependency versions in sites created from this upstream.');
    }
    return $statusCode;
  }

  /**
   * Require that the current project is a custom upstream.
   *
   * If a user runs this command from a Pantheon site, or from a
   * local clone of drupal-composer-managed, then an exception
   * is thrown. If a custom upstream previously forgot to change
   * the project name, this is a good hint to spur them to perhaps
   * do that.
   */
  protected static function failUnlessIsCustomUpstream(IOInterface $io, $composer) {
    $name = $composer->getPackage()->getName();
    $gitRepoUrl = exec('git config --get remote.origin.url');

    // Refuse to run if:
    // a) This is a clone of the standard Pantheon upstream, and it hasn't been renamed
    // b) This is an local working copy of a Pantheon site instread of the upstream
    $isPantheonStandardUpstream = preg_match('#pantheon.*/drupal-composer-managed#', $name); // false = failure, 0 = no match
    $isPantheonSite = (strpos($gitRepoUrl, '@codeserver') !== false);

    if (!$isPantheonStandardUpstream && !$isPantheonSite) {
      return;
    }

    if ($isPantheonStandardUpstream) {
      $io->writeError("<info>The upstream-require command can only be used with a custom upstream. If this is a custom upstream, be sure to change the 'name' item in the top-level composer.json file from $name to something else.</info>");
    }

    if ($isPantheonSite) {
      $io->writeError("<info>The upstream-require command cannot be used with Pantheon sites. Only use it with custom upstreams. Your git repo URL is $gitRepoUrl.</info>");
    }

    $io->writeError("<info>See https://pantheon.io/docs/create-custom-upstream for information on how to create a custom upstream.</info>" . PHP_EOL);
    throw new \RuntimeException("Cannot use upstream-require command with this project.");
  }

  /**
   * Update the dependency versions locked in the upstream-configuration section of a custom upstream.
   *
   * This creates a composer.lock file in the right location in the
   * upstream-configuration directory. Once this is committed to the
   * upstream, then downstream sites that update to this version of the
   * upstream, then it will get the locked versions of the upstream dependencies.
   */
  public static function updateUpstreamDependencies(Event $event) {
    $io = $event->getIO();
    $composer = $event->getComposer();

    // This command can only be used in custom upstreams
    static::failUnlessIsCustomUpstream($io, $composer);

    static::doUpdateUpstreamDependencies($io, static::getCleaner());
  }

  /**
   * Do the upstream dependency update operation.
   */
  protected static function doUpdateUpstreamDependencies(IOInterface $io, $cleaner) {
    if (!file_exists("upstream-configuration/composer.json")) {
      $io->writeError("Upstream has no dependencies; use 'composer upstream-require drupal/modulename' to add some.");
      return;
    }

    passthru("composer --working-dir=upstream-configuration update --no-install");
  }

  /**
   * Get an object that automatically cleans stuff up at the end of execution.
   *
   * We don't have an autoloader set up; otherwise this would just be a
   * separate class rather than a static accessor that returns an anonymous class.
   */
  public static function getCleaner() {
    return new class() {
      /** @var array List of files and directories to remove. */
      private array $fileOrDirList = [];
      /** @var array Map of files to the content to revert them to. */
      private array $revertList = [];
      /** @var bool Flag indicating whether or not we have registered the shutdown function. */
      private $registered = false;

      /**
       * Ensure that our cleanup function is called when php shuts down.
       */
      private function register() {
        if ($this->registered) {
          return;
        }
        register_shutdown_function([$this, 'clean']);
        $this->preventRegistration();
      }

      /**
       * Prevents shutdown function registration, e.g. for testing.
       */
      public function preventRegistration() {
        $this->registered = true;
      }

      /**
       * Clean up everything that needs to be cleaned.
       *
       * Typically, this method will never be called directly by
       * client code, but it may be directly called by unit tests.
       */
      public function clean() {
        $fs = new Filesystem();
        foreach ($this->fileOrDirList as $fileOrDir) {
          $fs->remove($fileOrDir);
        }
        foreach ($this->revertList as $file => $contents) {
          if (file_exists($file) && is_writable($file)) {
            file_put_contents($file, $contents);
          }
        }
        $this->fileOrDirList = [];
        $this->revertList = [];
      }

      /**
       * Remove the specified file or directory when we clean up.
       */
      public function removeWhenDone($fileOrDir) {
        $this->fileOrDirList[] = $fileOrDir;
        $this->register();
      }

      /**
       * Revert the specified file to the provided contents when we clean up.
       */
      public function revertWhenDone($file, $contents = null) {
        if ($contents === null) {
          $contents = file_get_contents($file);
        }
        $this->revertList[$file] = $contents;
        $this->register();
      }

      /**
       * Create a temporary directory; everything inside it will be deleted
       * when we clean up.
       */
      public function tmpdir($dir, $namePrefix) {
        $tempfile = tempnam($dir, $namePrefix);
        // tempnam creates file on disk
        if (file_exists($tempfile)) {
          unlink($tempfile);
        }
        mkdir($tempfile);
        if (is_dir($tempfile)) {
          $this->removeWhenDone($tempfile);
          return $tempfile;
        }
      }
    };
  }

  /**
   * Fix up upstream composer.json with strict pins based on upstream composer.lock file
   *
   * This method backs up the upstream-configuration/composer.json file, and
   * then modifies the version constraints on that file based on the versions in
   * upstream-configuration/composer.lock. When everything is done, then the
   * contents of the composer.json file is restored.
   */
  protected static function lockUpstreamDependencies($io, $cleaner) {
    if (!file_exists("upstream-configuration/composer.lock")) {
      $io->writeError("<warning>No locked dependencies in the upstream; skipping.</warning>");
      return;
    }
    if (!file_exists("composer.lock")) {
      // This situation typically should not arise in the field, as the platform
      // commits composer.lock during the site creation process. If there isn't
      // a top-level composer.lock file, though, then we cannot successfully
      // apply the upstream dependency locks, because doing so will produce
      // conflicts unless we can remove the dependencies locked by
      // drupal/core-recommended.
      $io->writeError("<warning>Project's composer.lock file not present; upstream lock file IGNORED. Always commit the root-level composer.lock file to Composer-managed Drupal sites. Run 'composer update' again to get correct dependency versions.");
      return;
    }
    $composerLockContents = file_get_contents("upstream-configuration/composer.lock");
    $composerLockData = json_decode($composerLockContents, true);

    $composerJsonContents = file_get_contents("upstream-configuration/composer.json");
    $composerJson = json_decode($composerJsonContents, true);

    $cleaner->revertWhenDone("upstream-configuration/composer.json", $composerJsonContents);

    // If there are any packages we want to exclude, list them here.
    $remove_list = static::listDrupalLockedDependencies();

    if (!isset($composerLockData['packages'])) {
      $io->writeError("<warning>No packages in the upstream composer.lock; skipping.</warning>");
      return;
    }
    $io->writeError('Locking upstream dependencies:');

    // Copy the 'packages' section from the Composer lock into our 'require'
    // section. There is also a 'packages-dev' section, but we do not need
    // to pin 'require-dev' versions, as 'require-dev' dependencies are never
    // included from subprojects. Use 'drupal/core-dev' to get Drupal's
    // dev dependencies.
    foreach ($composerLockData['packages'] as $package) {
      // If there is no 'source' record, then this is a path repository
      // or something else that we do not want to include.
      if (isset($package['source']) && !in_array($package['name'], $remove_list)) {
        $composerJson['require'][$package['name']] = $package['version'];
        $io->writeError('  "' . $package['name'] . '": "' . $package['version'] .'"');
      }
    }

    // Write the updated composer.json file
    $composerJsonContents = static::jsonEncodePretty($composerJson);
    file_put_contents("upstream-configuration/composer.json", $composerJsonContents . PHP_EOL);
  }

  /**
   * Determine versions of dependencies locked by Drupal.
   *
   * Get the version constraints of all of the dependencies of the
   * drupal/core-recommended project.
   */
  protected static function listDrupalLockedDependencies() {
    if (!file_exists("composer.lock")) {
      return [];
    }
    $composerLockContents = file_get_contents("composer.lock");
    $composerLockData = json_decode($composerLockContents, true);

    foreach ($composerLockData['packages'] as $package) {
      if ($package['name'] == 'drupal/core-recommended') {
        if (!isset($package['require'])) {
            return [];
        }
        return array_keys($package['require']);
      }
    }
    return [];
  }

  /**
   * Prepare for Composer to update dependencies.
   *
   * Composer will attempt to guess the version to use when evaluating
   * dependencies for path repositories. This has the undesirable effect
   * of producing different results in the composer.lock file depending on
   * which branch was active when the update was executed. This can lead to
   * unnecessary changes, and potentially merge conflicts when working with
   * path repositories on Pantheon multidevs.
   *
   * To work around this problem, it is possible to define an environment
   * variable that contains the version to use whenever Composer would normally
   * "guess" the version from the git repository branch. We set this invariantly
   * to "dev-main" so that the composer.lock file will not change if the same
   * update is later ran on a different branch.
   *
   * @see https://github.com/composer/composer/blob/main/doc/articles/troubleshooting.md#dependencies-on-the-root-package
   */
  public static function preUpdate(Event $event) {
    $io = $event->getIO();

    // We will only set the root version if it has not already been overriden
    if (!getenv('COMPOSER_ROOT_VERSION')) {
      // This is not an error; rather, we are writing to stderr.
      $io->writeError("<info>Using version 'dev-main' for path repositories.</info>");

      putenv('COMPOSER_ROOT_VERSION=dev-main');
    }

    // Fix up upstream composer.json with strict pins based on upstream composer.lock file.
    static::$updateCleaner = static::getCleaner();
    static::lockUpstreamDependencies($io, static::$updateCleaner);

    // Apply updates to top-level composer.json
    static::applyComposerJsonUpdates($event);
  }

  /**
   * postUpdate
   *
   * After "composer update" runs, we have the opportunity to do additional
   * fixups to the project files.
   *
   * @param Composer\Script\Event $event
   *   The Event object passed in from Composer
   */
  public static function postUpdate(Event $event) {
    if (static::$updateCleaner != null) {
      // We need to force `clean()` to run here, because sometimes the
      // shutdown function doesn't run until much later.
      static::$updateCleaner->clean();
      static::$updateCleaner = null;
    }
  }

  /**
   * Apply composer.json Updates
   *
   * During the Composer pre-update hook, check to see if there are any
   * updates that need to be made to the composer.json file. We cannot simply
   * change the composer.json file in the upstream, because doing so would
   * result in many merge conflicts.
   */
  public static function applyComposerJsonUpdates(Event $event) {
    $io = $event->getIO();

    $composerJsonContents = file_get_contents("composer.json");
    $composerJson = json_decode($composerJsonContents, true);
    $originalComposerJson = $composerJson;

    // Check to see if the platform PHP version (which should be major.minor.patch)
    // is the same as the Pantheon PHP version (which is only major.minor).
    // If they do not match, force an update to the platform PHP version. If they
    // have the same major.minor version, then
    $platformPhpVersion = static::getCurrentPlatformPhp($event);
    $pantheonPhpVersion = static::getPantheonPhpVersion($event);
    $updatedPlatformPhpVersion = static::bestPhpPatchVersion($pantheonPhpVersion);
    if ((substr($platformPhpVersion, 0, strlen($pantheonPhpVersion)) != $pantheonPhpVersion) && !empty($updatedPlatformPhpVersion)) {
      $io->write("<info>Setting platform.php from '$platformPhpVersion' to '$updatedPlatformPhpVersion' to conform to pantheon php version.</info>");

      $composerJson['config']['platform']['php'] = $updatedPlatformPhpVersion;
    }

    // add our post-update-cmd hook if it's not already present
    $our_hook = 'DrupalComposerManaged\\ComposerScripts::postUpdate';
    // if does not exist, add as an empty arry
    if(! isset($composerJson['scripts']['post-update-cmd'])) {
      $composerJson['scripts']['post-update-cmd'] = [];
    }

    // if exists and is a string, convert to a single-item array (n.b. do not actually need the if exists check because we just assured that it does)
    if(is_string($composerJson['scripts']['post-update-cmd'])) {
      $composerJson['scripts']['post-update-cmd'] = [$composerJson['scripts']['post-update-cmd']];
    }

    // if exists and is an array and does not contain our hook, add our hook (again, only the last check is needed)
    if(! in_array($our_hook, $composerJson['scripts']['post-update-cmd'])) {
      $io->write("<info>Adding post-update-cmd hook to composer.json</info>");
      $composerJson['scripts']['post-update-cmd'][] = $our_hook;

      // We're making our other changes if and only if we're already adding our hook
      // so that we don't overwrite customer's changes if they undo these changes.
      // We don't want customers to remove our hook, so it will be re-added if they remove it.

      // Update our upstream convenience scripts, if the user has not removed them
      if (isset($composerJson['scripts']['upstream-require'])) {
        // If the 'update-upstream-dependencies' command does not exist yet, add it.
        if(! isset($composerJson['scripts']['update-upstream-dependencies'])) {
          $composerJson['scripts']['update-upstream-dependencies'] = 'DrupalComposerManaged\\ComposerScripts::updateUpstreamDependencies';
          $composerJson['scripts-descriptions'] = [
            'update-upstream-dependencies' => 'Update the composer.lock file for the upstream dependencies.'
          ];
        }
      }

      // enable patching if it isn't already enabled
      if(! isset($composerJson['extra']['enable-patching'])) {
        $io->write("<info>Setting enable-patching to true</info>");
        $composerJson['extra']['enable-patching'] = true;
      }

      // allow phpstan/extension-installer in preparation for Drupal 10
      if(! isset($composerJson['config']['allow-plugins']['phpstan/extension-installer'])) {
        $io->write("<info>Allow phpstan/extension-installer in preparation for Drupal 10</info>");
        $composerJson['config']['allow-plugins']['phpstan/extension-installer'] = true;
      }
    }

    if(serialize($composerJson) == serialize($originalComposerJson)) {
      return;
    }

    // Write the updated composer.json file
    $composerJsonContents = static::jsonEncodePretty($composerJson);
    file_put_contents("composer.json", $composerJsonContents . PHP_EOL);
  }

  /**
   * jsonEncodePretty
   *
   * Convert a nested array into a pretty-printed json-encoded string.
   *
   * @param array $data
   *   The data array to encode
   * @return string
   *   The pretty-printed encoded string version of the supplied data.
   */
  public static function jsonEncodePretty(array $data) {
    $prettyContents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $prettyContents = preg_replace('#": \[\s*("[^"]*")\s*\]#m', '": [\1]', $prettyContents);

    return $prettyContents;
  }

  /**
   * Get current platform.php value.
   */
  private static function getCurrentPlatformPhp(Event $event) {
    $composer = $event->getComposer();
    $config = $composer->getConfig();
    $platform = $config->get('platform') ?: [];
    if (isset($platform['php'])) {
      return $platform['php'];
    }
    return null;
  }

  /**
   * Get the PHP version from pantheon.yml or pantheon.upstream.yml file.
   */
  private static function getPantheonConfigPhpVersion($path) {
    if (!file_exists($path)) {
      return null;
    }
    if (preg_match('/^php_version:\s?(\d+\.\d+)$/m', file_get_contents($path), $matches)) {
      return $matches[1];
    }
  }

  /**
   * Get the PHP version from pantheon.yml.
   */
  private static function getPantheonPhpVersion(Event $event) {
    $composer = $event->getComposer();
    $config = $composer->getConfig();
    $pantheonYmlPath = dirname($config->get('vendor-dir')) . '/pantheon.yml';
    $pantheonUpstreamYmlPath = dirname($config->get('vendor-dir')) . '/pantheon.upstream.yml';

    if ($pantheonYmlVersion = static::getPantheonConfigPhpVersion($pantheonYmlPath)) {
      return $pantheonYmlVersion;
    } elseif ($pantheonUpstreamYmlVersion = static::getPantheonConfigPhpVersion($pantheonUpstreamYmlPath)) {
      return $pantheonUpstreamYmlVersion;
    }
    return null;
  }

  /**
   * Determine which patch version to use when the user changes their platform php version.
   */
  private static function bestPhpPatchVersion($pantheonPhpVersion) {
    // Drupal 10 requires PHP 8.1 at a minimum.
    // Drupal 9 requires PHP 7.3 at a minimum.
    // Integrated Composer requires PHP 7.1 at a minimum.
    $patchVersions = [
      '8.2' => '8.2.0',
      '8.1' => '8.1.13',
      '8.0' => '8.0.26',
      '7.4' => '7.4.33',
      '7.3' => '7.3.33',
      '7.2' => '7.2.34',
      '7.1' => '7.1.33',
    ];
    if (isset($patchVersions[$pantheonPhpVersion])) {
      return $patchVersions[$pantheonPhpVersion];
    }
    // This feature is disabled if the user selects an unsupported php version.
    return '';
  }
}
