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
 * @author     Raphael Barman <r.barman@docuteam.ch>
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

    // Patches are located under task/migrate/patches
    // and named using the following format:
    // "dtPatch{patch_name}.class.php"
    foreach (sfFinder::type('file')
      ->maxdepth(0)
      ->sort_by_name()
      ->name('dtPatch*.class.php')
      ->in(sfConfig::get('sf_lib_dir').'/task/migrate/patches') as $filename)
    {
      // Initialize migration class
      $className = preg_replace('/.*(dtPatch.*)\.class\.php/', '$1', $filename);
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

}
