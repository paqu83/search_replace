<?php

namespace Drupal\search_replace\Plugin\SearchReplace;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\search_replace\SearchReplaceBase;

/**
 * A classic MySQL style field database search. Feel free to extend with you own plugin.
 *
 * @SearchReplace(
 *   id = "classic_search_plugin"
 * )
 */
class ClassicSearchPlugin extends SearchReplaceBase {

    /**
     * How to search in mysql.
     *
     * @param string $search_string
     *   The searched string.
     * @return array
     */
    public function searchInDb(string $search_string) {
        $entities_data = [];
        $connection = $this->database;
        $db_options = $connection->getConnectionOptions();
        $database = $db_options["database"]; //TABLE_SCHEMA
        $query = $connection->query("SELECT `table_name` FROM information_schema.tables WHERE `TABLE_SCHEMA` = :database_name AND (`table_name` LIKE :likeString OR `table_name` LIKE :likeString2)", [":likeString" => "%__field_%", ":likeString2" => "%__body%", ":database_name" => $database]);
        $results = $query->fetchCol('table_name');

        foreach ($this->languageManager->getLanguages() as $language) {
            $lang_code = $language->getId();
            foreach ($results as $table_name) {
                $table_name_exploded = explode("__field_", str_replace($db_options['prefix'], '', $table_name));

                try {
                    $storage = $this->entityTypeManager->getStorage(str_replace("__body", "", $table_name_exploded[0]));
                }
                catch (PluginNotFoundException $exception) {
                    continue;
                }
                $field_name_pre = explode("__", $table_name);
                $field_name_pre = end($field_name_pre);
                $possible_field_name_endings = ['_value', '_uri'];
                foreach ($possible_field_name_endings as $ending) {
                    $field_name = $field_name_pre . $ending;
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
                                    'field_name' => str_replace($possible_field_name_endings, '', $field_name),
                                    'type' => $storage->getEntityTypeId(),
                                    'big_picture' => $this->findWords($field_body, $search_string),
                                    'langcode' => $found_item->langcode,
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $entities_data;
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
        $regex = '/[^*]{0,300}' . preg_quote($needle, '/') . '[^*]{0,300}/';

        if (preg_match($regex, $haystack, $matches)) {
            return $matches[0];
        }
        else {
            return FALSE;
        }
    }

}
