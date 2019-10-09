<?php

namespace Drupal\search_replace\Services;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Entity\ContentEntityType;
use \Drupal\Core\Entity\EntityTypeManagerInterface;
use \Drupal\Core\Database\Connection;
use \Drupal\Core\Entity\EntityManager;
use \Drupal\Core\Entity\EntityFieldManager;

/**
 * Class SearchService
 * @package Drupal\search_replace\Services
 */
class SearchService {

  /**
   * Entity type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity  Manager.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * Entity  Manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * Database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Tips to help manually finding needed paragraph.
   * @var array
   */
  protected $tips;


  /**
   * SearchService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager,
                              Connection $database,
                              EntityManager $entityManager,
                              EntityFieldManager $entityFieldManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->entityManager = $entityManager;
    $this->entityFieldManager = $entityFieldManager;

  }

// testing.
//  public function searchAStringAlpha() {
//    $content_entity_types = [];
//    $entity_type_definations = $this->entityTypeManager->getDefinitions();
//    /* @var $definition EntityTypeInterface */
//    foreach ($entity_type_definations as $definition) {
//      if ($definition instanceof ContentEntityType) {
//        $content_entity_types[] = $definition;
//      }
//    }
//
//    $field_map = $this->entityFieldManager->getFieldMap();
//    $field_map['node'];
//
//  }

  /**
   * Search for entities by string and prepare row data.
   *
   * @param $search_string
   *   Search string.
   *
   * @return array
   *   Array of result rows.
   */
  public function searchAstringPrepareRows($search_string) {
    if (empty($search_string)) {
      return ['rows' => [], 'allCount' => 0, 'skipped' => 0];
    }
    $entitiesData = [];
    $etitiesToSearch = ['node', 'paragraph'];
    if (empty($search_string)) {
      return ['rows' => [], 'allCount' => 0, 'skipped' => 0];
    }
    $language_manager = \Drupal::languageManager();
    foreach ($language_manager->getLanguages() as $language) {
      $langcode = $language->getId();
      foreach ($etitiesToSearch as $searchEntityName) {
        $connection = $this->database;
        $likeString = $searchEntityName . '__field_%';
        $query = $connection->query("SELECT `table_name` FROM information_schema.tables WHERE `table_name` LIKE :likeString", [":likeString" => $likeString]);
        $results = $query->fetchCol('table_name');
        foreach ($results as $tableName) {
          $fieldName = explode($searchEntityName . "__", $tableName);
          $fieldName = end($fieldName);
          $fieldName .= "_value";
          $fieldExists = $connection->query("SHOW COLUMNS FROM $tableName LIKE :fieldName", [":fieldName" => $fieldName])->fetchAssoc();
          if (!empty($fieldExists)) {
            $sql = "SELECT `entity_id`, `$fieldName` AS `field_content`, `langcode` " .
              "FROM  $tableName WHERE $fieldName LIKE :search_string AND `langcode` = :langcode AND `deleted`=0";
            $found = $connection->query($sql, [":search_string" => '%' . $search_string . '%', ':langcode' => $langcode])->fetchAll();
            if (!empty($found)) {
              foreach ($found as $foundItem) {
                $fieldBody = $foundItem->field_content;

                $entitiesData[] = [
                  'entity_id' => $foundItem->entity_id,
                  'field_name' => str_replace('_value', '', $fieldName),
                  'type' => $searchEntityName,
                  'big_picture' => $this->findWords($fieldBody, $search_string),
                  'langcode' => $foundItem->langcode
                ];
              }
            }
          }
        }
      }
    }


    if (!empty($entitiesData)) {
      $allCount = count($entitiesData);
      $entitiesData = array_slice($entitiesData, 0, 100);
      $this->getAndGroupNodesFromParagraphs($entitiesData);
      $skipped = 100 - count($entitiesData);
      foreach ($entitiesData as $entityData) {

        $url = Url::fromRoute('entity.node.edit_form', ['node' => $entityData['node']->id()], ['language' => $entityData['node']->language()]);
        $link = Link::fromTextAndUrl('edit', $url);


        $entityData['big_picture'] = str_replace($search_string, '<bg class="color-error">' . $search_string . '</bg>', htmlentities($entityData['big_picture']));
        $rows[$entityData['entity']->id() . "::" . $entityData['type'] . "::" . $entityData['field_name'] . "::" . $entityData['langcode']] = [
          $entityData['entity']->id(),
          $entityData['type'],
          $entityData['langcode'],
          $entityData['entity']->bundle(),
          $entityData['node']->getTitle(),
          $link->toString(),
          $entityData['field_name'],
          [
            'data' =>
            [
              '#markup' => $entityData['big_picture'],
              '#allowed_tags' => ['bg']
            ]
          ]



        ];
      }
    }

    return [
      'rows' => empty($rows) ? [] : $rows,
      'allCount' => empty($allCount) ? 0 : $allCount,
      'skipped' => empty($skipped) ? 0 : $skipped
    ];
  }

  /**
   * Helper function to get search string surrounding.
   *
   * @param $haystack
   * @param $needle
   * @return bool|mixed
   */
  private function findWords($haystack, $needle) {
    $regex = '/[^*]{0,300}' . preg_quote($needle) . '[^*]{0,300}/';

    if (preg_match($regex, $haystack, $matches)) {
      return $matches[0];
    }
    else {
      return FALSE;
    }
  }

  /**
   * Group nodes from paragraphs.
   * @param $entitiesData
   */
  private function getAndGroupNodesFromParagraphs(&$entitiesData) {
    foreach ($entitiesData as $key => &$entityData) {
      $this->tips = [];
      $entityData['entity'] = $this->entityTypeManager->getStorage($entityData['type'])->load($entityData['entity_id']);
      if ($entityData['entity']->hasTranslation($entityData['langcode'])) {
        $entityData['entity'] = $entityData['entity']->getTranslation($entityData['langcode']);
      }
      if ($entityData['type'] != 'paragraph') {
        $entityData['node'] = $entityData['entity'];
        continue;
      }
      $return = $this->checkBrokenParagraphRelation($entityData['entity']);
      if ($return['flag']) {
        $entityData['node'] = $return['entity'];
      }
      else {
        unset($entitiesData[$key]);
      }
      if (count($this->tips) > 1) {
        $entityData['tips'] = implode(" => ", array_reverse($this->tips));
      }
    }
  }

  /**
   * Check if paragraph is not disattached from node.
   *
   * @param $entity
   *   Paragraph entity.
   * @return array[flag,entity]
   */
  private function checkBrokenParagraphRelation($entity) {
    $flag = FALSE;
    while ($entity->getEntityType()->get('id') != 'node') {
      $this->tips[] = $entity->getType();
      $flag = FALSE;
      $parentEntity = $entity->getParentEntity();
      if (!empty($entity->parent_field_name)) {
        $parentFieldName = $entity->parent_field_name->getString();
        if (!empty($parentEntity->{$parentFieldName})) {
          $values = $parentEntity->{$parentFieldName}->getValue();
          foreach ($values as $val) {
            if ($val['target_id'] == $entity->id()) {
              $flag = TRUE;
              break;
            }
          }
        }
      }
      if (!$flag) {
        break;
      }
      $entity = $parentEntity;
    }
    return ['flag' => $flag, 'entity' => $entity];
  }

}
