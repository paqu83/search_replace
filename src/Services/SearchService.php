<?php

namespace Drupal\pega_search_replace\Services;

use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Class SearchService
 * @package Drupal\pega_search_replace\Services
 */
class SearchService {

  /**
   * Entity type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
  public function __construct(\Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager, \Drupal\Core\Database\Connection $database) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
  }

  /**
   * Search for entities by string and prepare row data.
   *
   * @param $search_string
   * @return array
   */
  public function searchAStringPrepareRows($search_string) {
    if (empty($search_string)) {
      return [];
    }
    $entitiesData = [];
    $etitiesToSearch = ['node', 'paragraph'];
    $rows = [];
    if (empty($search_string)) {
      return $rows;
    }

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
          $sql = "SELECT `entity_id` FROM  $tableName WHERE $fieldName LIKE :search_string AND `langcode`='en' AND `deleted`=0 GROUP BY `entity_id` LIMIT 500";
          $found = $connection->query($sql, [":search_string" => '%' . $search_string . '%'])->fetchAll();
          if (!empty($found)) {
            foreach ($found as $foundItem) {
              $entitiesData[] = [
                'entity_id' => $foundItem->entity_id,
                'field_name' => str_replace('_value', '', $fieldName),
                'type' => $searchEntityName
              ];
            }
          }
        }
      }
    }

    if (!empty($entitiesData)) {
      $this->getAndGroupNodesFromParagraphs($entitiesData);
      foreach ($entitiesData as $entityData) {

        $url = Url::fromRoute('entity.node.edit_form', ['node' => $entityData['node']->id()]);
        $link = Link::fromTextAndUrl('edit', $url);

        $rows[$entityData['entity']->id() . "::" . $entityData['type'] . "::" . $entityData['field_name']] = [
          $entityData['entity']->id(),
          $entityData['type'],
          $entityData['entity']->bundle(),
          $entityData['node']->getTitle(),
          $link->toString(),
          $entityData['field_name'],
          empty($entityData['tips']) ? "" : $entityData['tips']
        ];
      }
    }

    return $rows;
  }

  /**
   * Group nodes from paragraphs.
   * @param $entitiesData
   */
  private function getAndGroupNodesFromParagraphs(&$entitiesData){
    foreach ($entitiesData as $key => &$entityData) {
      $this->tips = [];
      $entityData['entity'] = $this->entityTypeManager->getStorage($entityData['type'])->load($entityData['entity_id']);
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
