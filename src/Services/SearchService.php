<?php

namespace Drupal\search_replace\Services;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManager;

/**
 * Class SearchService.
 *
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
   * Database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Language Manager.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $languageManager;

  /**
   * Tips to help manually finding needed paragraph.
   *
   * @var array
   */
  protected $tips;

  /**
   * SearchService constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager.
   * @param \Drupal\Core\Database\Connection $database
   *   Database Connection.
   * @param \Drupal\Core\Language\LanguageManager $languageManager
   *   Language Manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database, LanguageManager $languageManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->languageManager = $languageManager;
  }

  /**
   * Search for entities by string and prepare row data.
   *
   * @param string $search_string
   *   Search string.
   *
   * @return array
   *   Array of result rows.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function searchStringPrepareRows($search_string) {
    if (empty($search_string)) {
      return ['rows' => [], 'allCount' => 0, 'skipped' => 0];
    }
    $entities_data = [];
    $entities_to_search = ['node', 'paragraph'];
    if (empty($search_string)) {
      return ['rows' => [], 'allCount' => 0, 'skipped' => 0];
    }

    //TODO: Try to change this chrismas tree to some services.
    foreach ($this->languageManager->getLanguages() as $language) {
      $lang_code = $language->getId();
      foreach ($entities_to_search as $search_entity_name) {
        $connection = $this->database;
        $like_string = $search_entity_name . '__field_%';
        $query = $connection->query("SELECT `table_name` FROM information_schema.tables WHERE `table_name` LIKE :likeString", [":likeString" => $like_string]);
        $results = $query->fetchCol('table_name');
        foreach ($results as $table_name) {
          $field_name = explode($search_entity_name . "__", $table_name);
          $field_name = end($field_name);
          $field_name .= "_value";
          $field_exists = $connection->query("SHOW COLUMNS FROM $table_name LIKE :fieldName", [":fieldName" => $field_name])->fetchAssoc();
          if (!empty($field_exists)) {
            $sql = "SELECT `entity_id`, `$field_name` AS `field_content`, `langcode` " .
              "FROM  $table_name WHERE $field_name LIKE :search_string AND `langcode` = :langcode AND `deleted`=0";
            $found = $connection->query($sql, [":search_string" => '%' . $search_string . '%', ':langcode' => $lang_code])->fetchAll();
            if (!empty($found)) {
              foreach ($found as $found_item) {
                $field_body = $found_item->field_content;

                $entities_data[] = [
                  'entity_id' => $found_item->entity_id,
                  'field_name' => str_replace('_value', '', $field_name),
                  'type' => $search_entity_name,
                  'big_picture' => $this->findWords($field_body, $search_string),
                  'langcode' => $found_item->langcode,
                ];
              }
            }
          }
        }
      }
    }

    if (!empty($entities_data)) {
      $all_count = count($entities_data);
      $entities_data = array_slice($entities_data, 0, 100);
      $this->getAndGroupNodesFromParagraphs($entities_data);
      $skipped = 100 - count($entities_data);
      foreach ($entities_data as $entity_data) {

        $url = Url::fromRoute('entity.node.edit_form', ['node' => $entity_data['node']->id()], ['language' => $entity_data['node']->language()]);
        $link = Link::fromTextAndUrl('edit', $url);

        $entity_data['big_picture'] = str_replace($search_string, '<bg class="color-error">' . $search_string . '</bg>', htmlentities($entity_data['big_picture']));
        $rows[$entity_data['entity']->id() . "::" . $entity_data['type'] . "::" . $entity_data['field_name'] . "::" . $entity_data['langcode']] = [
          $entity_data['entity']->id(),
          $entity_data['type'],
          $entity_data['langcode'],
          $entity_data['entity']->bundle(),
          $entity_data['node']->getTitle(),
          $link->toString(),
          $entity_data['field_name'],
          [
            'data' =>
            [
              '#markup' => $entity_data['big_picture'],
              '#allowed_tags' => ['bg'],
            ],
          ],
        ];
      }
    }

    return [
      'rows' => empty($rows) ? [] : $rows,
      'allCount' => empty($all_count) ? 0 : $all_count,
      'skipped' => empty($skipped) ? 0 : $skipped,
    ];
  }

  /**
   * Helper function to get search string surrounding.
   *
   * @param string $haystack
   *   String to search into.
   * @param string $needle
   *   Searched string.
   *
   * @return bool|array
   *   False or array with matches.
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
   *
   * @param array $entities_data
   *   Array with search results entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getAndGroupNodesFromParagraphs(array &$entities_data) {
    foreach ($entities_data as $key => &$entity_data) {
      $this->tips = [];
      $entity_data['entity'] = $this->entityTypeManager->getStorage($entity_data['type'])->load($entity_data['entity_id']);
      if ($entity_data['entity']->hasTranslation($entity_data['langcode'])) {
        $entity_data['entity'] = $entity_data['entity']->getTranslation($entity_data['langcode']);
      }
      if ($entity_data['type'] != 'paragraph') {
        $entity_data['node'] = $entity_data['entity'];
        continue;
      }
      $return = $this->checkBrokenParagraphRelation($entity_data['entity']);
      if ($return['flag']) {
        $entity_data['node'] = $return['entity'];
      }
      else {
        unset($entities_data[$key]);
      }
      if (count($this->tips) > 1) {
        $entity_data['tips'] = implode(" => ", array_reverse($this->tips));
      }
    }
  }

  /**
   * Check if paragraph is not abandoned.
   *
   * @param object $entity
   *   Parent entity.
   *
   * @return array
   *   Array with flag that informs about broken relation.
   */
  private function checkBrokenParagraphRelation($entity) {
    $flag = FALSE;
    while ($entity->getEntityType()->get('id') != 'node') {
      $this->tips[] = $entity->getType();
      $flag = FALSE;
      $parent_entity = $entity->getParentEntity();
      if (!empty($entity->parent_field_name)) {
        $parent_field_name = $entity->parent_field_name->getString();
        $parent_field = $parent_entity->{$parent_field_name};
        if (!empty($parent_field)) {
          $values = $parent_field->getValue();
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
      $entity = $parent_entity;
    }
    return ['flag' => $flag, 'entity' => $entity];
  }

}
