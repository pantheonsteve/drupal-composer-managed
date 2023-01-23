<?php

/**
 * @file
 * Contains \DrupalComposerManaged\ComposerScripts.
 */

namespace DrupalComposerManaged;

use Composer\Script\Event;
use Composer\Semver\Comparator;
use Drupal\Core\Site\Settings;
use DrupalFinder\DrupalFinder;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

class ComposerScripts {

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
    $name = $composer->getPackage()->getName();
    $gitRepoUrl = exec('git config --get remote.origin.url');

    // Refuse to run if:
    //   - This is a clone of the standard Pantheon upstream, and it hasn't been renamed
    //   - This is an local working copy of a Pantheon site instread of the upstream
    $isPantheonStandardUpstream = (strpos($name, 'pantheon-systems/drupal-composer-managed') !== false);
    $isPantheonSite = (strpos($gitRepoUrl, '@codeserver') !== false);

    if ($isPantheonStandardUpstream || $isPantheonSite) {
      $io->writeError("<info>The upstream-require command can only be used with a custom upstream</info>");
      $io->writeError("<info>See https://pantheon.io/docs/create-custom-upstream for information on how to create a custom upstream.</info>" . PHP_EOL);
      throw new \RuntimeException("Cannot use upstream-require command with this project.");
    }

    // Find arguments that look like projects.
    $packages = [];
    foreach ($event->getArguments() as $arg) {
      if (preg_match('#[a-zA-Z][a-zA-Z0-9_-]*/[a-zA-Z][a-zA-Z0-9]:*[~^]*[0-9a-z._-]*#', $arg)) {
        $packages[] = $arg;
      }
    }

    // Insert the new projects into the upstream-configuration composer.json
    // without updating the lock file or downloading the projects
    $packagesParam = implode(' ', $packages);
    $cmd = "composer --working-dir=upstream-configuration require --no-update $packagesParam";
    $io->writeError($cmd . PHP_EOL);
    passthru($cmd);

    // Update composer.lock & etc. if present
    static::updateLocalDependencies($io, $packages);
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

    // Apply composer.json up dates
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
    // for future use
  }

  /**
   *
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

    // add a post-update-cmd hook if one is not already defined
    if(! isset($composerJson['scripts']['post-update-cmd'])) {
      $io->write("<info>Adding post-update-cmd hook</info>");
      $composerJson['scripts']['post-update-cmd'] = ['DrupalComposerManaged\\ComposerScripts::postUpdate'];
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
  public static function jsonEncodePretty($data) {
    $prettyContents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $prettyContents = preg_replace('#": \[\s*("[^"]*")\s*\]#m', '": [\1]', $prettyContents);

    return $prettyContents;
  }

  /**
   * Update the composer.lock file and so on.
   *
   * Upstreams should *not* commit the composer.lock file. If a local working
   * copy
   */
  private static function updateLocalDependencies($io, $packages) {
    if (!file_exists('composer.lock')) {
      return;
    }

    $io->writeError("<warning>composer.lock file present; do not commit composer.lock to a custom upstream, but updating for the purpose of local testing.");

    // Remove versions from the parameters, if any
    $versionlessPackages = array_map(
      function ($package) {
        return preg_replace('/:.*/', '', $package);
      },
      $packages
    );

    // Update the project-level composer.lock file
    $versionlessPackagesParam = implode(' ', $versionlessPackages);
    $cmd = "composer update $versionlessPackagesParam";
    $io->writeError($cmd . PHP_EOL);
    passthru($cmd);
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
    $pantheonYmlPath = $config->get('vendor-dir') . '/../pantheon.yml';
    $pantheonUpstreamYmlPath = $config->get('vendor-dir') . '/../pantheon.upstream.yml';

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
