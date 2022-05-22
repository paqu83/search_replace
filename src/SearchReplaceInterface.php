<?php

namespace Drupal\search_replace;

/**
 * An interface for all SearchReplace type plugins.
 */
interface SearchReplaceInterface {

  /**
   * Search in database in the scope of specified entity.
   *
   * @param string $search_string
   *   The string to be searched in the database.
   *
   * @return array
   */
  public function searchInDb(string $search_string);

}
