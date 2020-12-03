<?php

namespace Drupal\search_replace;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A base class to help developers implement their own search replace plugins.
 *
 * @see \Drupal\search_replace\Annotation\SearchReplace
 * @see \Drupal\search_replace\SearchReplaceInterface
 */
abstract class SearchReplaceBase extends PluginBase implements SearchReplaceInterface, ContainerFactoryPluginInterface {

  /**
   * @var LanguageManager
   */
  protected $languageManager;

  /**
   * @var Connection
   */
  protected $database;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LanguageManager $language_manager, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->languageManager = $language_manager;
    $this->database = $database;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  abstract public function searchInDb(string $search_string);

}
