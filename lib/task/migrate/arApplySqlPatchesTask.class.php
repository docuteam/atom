<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Apply custom patches directly to SQL
 *
 * @package    AccesstoMemory
 * @subpackage migration
 * @author     David Juhasz <david@artefactual.com>
 */
class QubitApplySqlPatchesTask extends sfBaseTask
{
  /**
   * @see sfBaseTask
   */
  protected function configure()
  {
    $this->namespace = 'tools';
    $this->name = 'apply-sql-patches';
    $this->briefDescription = 'Apply custom SQL patches';
    $this->detailedDescription = <<<EOF
  [./symfony tools:apply-sql-patches|INFO]
EOF;

    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_OPTIONAL, 'The application name', true),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'cli'),
      new sfCommandOption('connection', null, sfCommandOption::PARAMETER_REQUIRED, 'The connection name', 'propel'),
      new sfCommandOption('no-confirmation', 'B', sfCommandOption::PARAMETER_NONE, 'Do not ask for confirmation'),
      new sfCommandOption('verbose', 'v', sfCommandOption::PARAMETER_NONE, 'Verbose mode', null),
    ));

    // Disable plugin loading from plugins/ before this task.
    // Using command.pre_command to ensure that it happens early enough.
    $this->dispatcher->connect('command.pre_command', function($e) {
      if (!$e->getSubject() instanceof self)
      {
        return;
      }

      sfPluginAdminPluginConfiguration::$loadPlugins = false;
    });
  }

  /**
   * @see sfBaseTask
   */
  protected function execute($arguments = array(), $options = array())
  {
    $dbManager = new sfDatabaseManager($this->configuration);
    $database = $dbManager->getDatabase($options['connection']);

    sfContext::createInstance($this->configuration);

    $this->getPluginSettings();
    $this->removeMissingPluginsFromSettings();
    $this->checkMissingThemes();

    // Deactivate search index, must be rebuilt later anyways
    QubitSearch::disable();

    // Confirmation
    if (
      !$options['no-confirmation']
      &&
      !$this->askConfirmation(array(
          'WARNING: Your database has not been backed up!',
          'Please back-up your database manually before you proceed.',
          'If this task fails you may lose your data.',
          '',
          'Have you done a manual backup and wish to proceed? (y/N)'),
        'QUESTION_LARGE', false)
    )
    {
      $this->logSection('apply-sql-patches', 'Task aborted.');

      return 1;
    }

    // Upgrades post to Release 1.3 (v92) are located under
    // task/migrate/migrations and named using the following format:
    // "arMigration%04d.class.php" (the first one is arMigration0093.class.php)
    foreach (sfFinder::type('file')
      ->maxdepth(0)
      ->sort_by_name()
      ->name('arPatch*.class.php')
      ->in(sfConfig::get('sf_lib_dir').'/task/migrate/patches') as $filename)
    {
      // Initialize migration class
      $className = preg_replace('/.*(arPatch.*)\.class\.php/', '$1', $filename);
      $class = new $className;

      // Run migration but don't bump dbversion
      if (true !== $class->up($this->configuration)) throw new sfException('Failed to apply upgrade '.get_class($class));
      $this->logSection('apply-sql-patches', sprintf('Successfully applied %s', get_class($class)));
    }
    // Analyze tables:
    // - Performs and stores a key distribution analysis (if the table
    //   has not changed since the last one, its not analyzed again).
    // - Determines index cardinality, used for join optimizations.
    // - Removes the table from the definition cache.
    foreach (QubitPdo::fetchAll("SHOW TABLES;", [], ['fetchMode' => PDO::FETCH_COLUMN]) as $table)
    {
      QubitPdo::modify(sprintf('ANALYZE TABLE `%s`;', $table));
    }

    // Delete cache files (for menus, etc.)
    foreach (sfFinder::type('file')->name('*.cache')->in(sfConfig::get('sf_cache_dir')) as $cacheFile)
    {
      unlink($cacheFile);
    }

    // Do standard cache clear
    $cacheClear = new sfCacheClearTask(sfContext::getInstance()->getEventDispatcher(), new sfAnsiColorFormatter);
    $cacheClear->run();

    // Clear settings cache to reload them in sfConfig on the first request
    // after the upgrade in QubitSettingsFilter.
    QubitCache::getInstance()->removePattern('settings:i18n:*');

    $this->logSection('apply-sql-patches', 'Successfully applied patches.');
  }

  /**
   * Get the plugin settings and store it for the class instance.
   */
  private function getPluginSettings()
  {
    $this->pluginsSetting = QubitSetting::getByNameAndScope('plugins', null);
    if ($this->pluginsSetting === null)
    {
      throw new sfException('Could not get plugin settings from the database.');
    }
  }

  /**
   * Detect any plugins configured in the settings that aren't present
   * in plugins/, and remove them from the database.
   */
  private function removeMissingPluginsFromSettings()
  {
    $configuredPlugins = unserialize($this->pluginsSetting->getValue(array('sourceCulture' => true)));
    $pluginsPresent = $this->getPluginsPresent();

    foreach ($configuredPlugins as $configPlugin)
    {
      if (!array_key_exists($configPlugin, $pluginsPresent))
      {
        if (($key = array_search($configPlugin, $configuredPlugins)) !== false)
        {
          // Confirmation
          $question = "Plugin $configPlugin no longer exists. Remove it (Y/n)?";
          if (!$options['no-confirmation'] && !$this->askConfirmation(array($question), 'QUESTION_LARGE', true))
          {
            continue;
          }

          unset($configuredPlugins[$key]);
          $this->logSection('apply-sql-patches', "Removing plugin from settings: $configPlugin");
        }
      }
    }

    $this->pluginsSetting->setValue(serialize($configuredPlugins), array('sourceCulture' => true));
    $this->pluginsSetting->save();
  }

  /**
   * Check if a theme configured in setting/setting_i18n isn't present under plugins/
   * If it isn't, prompt the user to choose one of the available themes detected in plugins/
   */
  private function checkMissingThemes()
  {
    $presentThemes = $this->getPluginsPresent(true);
    $configuredPlugins = unserialize($this->pluginsSetting->getValue(array('sourceCulture' => true)));

    // Check to see if any of the present themes in plugins/ are configured
    // to be used in the AtoM settings. If not, we'll prompt for a new theme.
    $themeMissing = true;

    foreach ($presentThemes as $presentThemeName => $presentThemePath)
    {
      if (in_array($presentThemeName, $configuredPlugins))
      {
        // Valid theme configured + present in plugins/
        $themeMissing = false;
        break;
      }
    }

    if ($themeMissing)
    {
      $this->logSection('apply-sql-patches', 'There is not a valid theme set currently.');

      // Confirmation
      $question = 'Would you like to choose a new theme (Y/n)?';
      $shouldConfirm = function_exists('readline') && !$options['no-confirmation'];
      if ($shouldConfirm && !$this->askConfirmation(array($question), 'QUESTION_LARGE', true))
      {
        return;
      }

      $chosenTheme = $this->getNewTheme($presentThemes);
      $configuredPlugins[] = $chosenTheme;

      $this->pluginsSetting->setValue(serialize($configuredPlugins), array('sourceCulture' => true));
      $this->pluginsSetting->save();

      $this->logSection('apply-sql-patches', "AtoM theme changed to $chosenTheme.");
    }
  }

  /**
   * Change to a new theme, selected out of a list provided.
   *
   * @param array  $themes  The themes present in the plugins/ folder ($name => $path)
   * @return string  The new theme name
   */
  private function getNewTheme($themes)
  {
    if (!function_exists('readline'))
    {
      throw new Exception('This task needs the PHP readline extension.');
    }

    for (;;)
    {
      $this->logSection('apply-sql-patches', 'Please enter a new theme choice:');

      $n = 0;
      foreach (array_keys($themes) as $theme)
      {
        print ++$n . ") $theme\n";
      }

      $choice = (int)readline('Select theme number: ');

      if ($choice >= 1 && $choice <= count($themes))
      {
        $themeNames = array_keys($themes);
        return $themeNames[$choice - 1];
      }
    }
  }

  /**
   * Get plugins that are currently present in the plugins/ directory.
   *
   * @param $themePluginsOnly  Whether to get all plugins or just theme plugins
   * @return array  An array containing the theme names and paths ($name => $path)
   */
  private function getPluginsPresent($themePluginsOnly = false)
  {
    $pluginPaths = $this->configuration->getAllPluginPaths();

    $plugins = array();
    foreach ($pluginPaths as $name => $path)
    {
      $className = $name . 'Configuration';

      if (strpos($path, sfConfig::get('sf_plugins_dir')) === 0 &&
          is_readable($classPath = $path . '/config/' . $className . '.class.php'))
      {
        if ($themePluginsOnly)
        {
          require_once $classPath;
          $class = new $className($this->configuration);

          if (isset($class::$summary) && 1 === preg_match('/theme/i', $class::$summary))
          {
            $plugins[$name] = $path;
          }
        }
        else
        {
          $plugins[$name] = $path;
        }
      }
    }

    return $plugins;
  }
}
