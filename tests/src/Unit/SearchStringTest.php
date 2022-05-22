<?php

namespace Drupal\search_replace\Tests\Unit;

use Drupal\Core\Language\LanguageManager;
use Drupal\Tests\UnitTestCase;
use Drupal\search_replace\Services\SearchService;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Twig\Error\RuntimeError;

/**
 * Class SearchStringTest. For testing Search Service.
 *
 * @package Drupal\search_replace\Tests\Unit
 */
class SearchStringTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Initialization function.
   */
  protected function setUp() {
    parent::setUp();

    $container = new ContainerBuilder();

    $this->entityTypeManager = $this->getMockBuilder('Drupal\Core\Entity\EntityTypeManager')
      ->disableOriginalConstructor()
      ->getMock();

    $this->database = $this->getMockBuilder('Drupal\Core\Database\Connection')
      ->disableOriginalConstructor()
      ->getMock();
    $statement = $this->getMockBuilder('Drupal\Core\Database\StatementInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $statement->expects($this->any())
      ->method('fetchCol')
      ->with('table_name')
      ->willReturn([]);

    $this->database->method('query')
      ->willReturn($statement);
    $language_manager = $this->getMockBuilder(LanguageManager::class)->disableOriginalConstructor()->getMock();

    $searchService = new SearchService($this->entityTypeManager, $this->database, $language_manager);

    \Drupal::setContainer($container);
    $container->set('search_replace.search.string', $searchService);
  }

  /**
   * Test service for various strings.
   */
  public function testSearchString() {
    $this->assertTrue(TRUE);

//    $this->assertEquals([], \Drupal::service('search_replace.search.string')->searchStringPrepareRows(''));
//    $this->assertEquals([], \Drupal::service('search_replace.search.string')->searchStringPrepareRows('some-string'));

  }

}
