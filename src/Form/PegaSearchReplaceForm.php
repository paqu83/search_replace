<?php

namespace Drupal\pega_search_replace\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Class PegaSearchReplaceForm.
 *
 * @package Drupal\pega_search_replace\Form
 */
class PegaSearchReplaceForm extends FormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;


  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                              DateFormatterInterface $date_formatter
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pega_search_replace_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $filters = $form_state->get('filters');
    $search_string = empty($filters['search_string']) ? "" : $filters['search_string'];

    if ($form_state->has('page_num') && $form_state->get('page_num') == 2) {
      return self::formPageTwo($form, $form_state);
    }
    if ($form_state->has('page_num') && $form_state->get('page_num') == 3) {
      return self::formPageThree($form, $form_state);
    }


    // Add filters.
    $form['filters'] = [
      '#type' => 'container',
      '#weight' => 10,
      '#tree' => TRUE,
    ];
    $form['filters']['search_string'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search string'),
      '#placeholder' => $this->t('search for string'),
      '#default_value' => $search_string,
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];
    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];
    // Add a Reset button if any filters are set.
    if (!empty($search_string)) {
      $form['filters']['actions']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#submit' => array('::resetForm'),
      ];
    }

    $form['table_actions'] = [
      '#type' => 'container',
      '#weight' => 10,
      '#tree' => TRUE,
    ];

    $form['table_actions']['replace'] = [
      '#type' => 'submit',
      '#value' => 'Replace selected',
      '#submit' => array('::formPage1Submit'),
      '#weight' => 10,
      '#validate' => ['::replaceValidate'],
    ];

    $rows = $this->searchAStringPrepareRows($search_string);

    $form['table'] = [
      '#header' => [
        $this->t('ID'),
        $this->t('Entity type'),
        $this->t('Entity type bundle'),
        $this->t('Title'),
        $this->t('Edit'),
        $this->t('Found in field'),
        $this->t('Tips for paragraphs'),

      ],
      '#options' => $rows,
      '#empty' => $this->t('There is no matching content.'),
      '#type' => 'tableselect',
      '#weight' => 20,
    ];


    return $form;
  }

  /**
   * Search for entities by string and prepare row data.
   *
   * @param $search_string
   * @return array
   */
  private function searchAStringPrepareRows($search_string) {
    $entitiesData = [];
    $etitiesToSearch = ['node', 'paragraph'];
    $rows = [];
    if (empty($search_string)) {
      return $rows;
    }

    foreach ($etitiesToSearch as $searchEntityName) {
      $connection = \Drupal::database();
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
   * Validate replace string.
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function replaceValidate(array &$form, FormStateInterface $form_state) {
    $table = $form_state->getValue('table');
    $flag = FALSE;
    foreach ($table as $row) {
      if (!empty($row)) {
        $flag = TRUE;
        break;
      }
    }
    if (!$flag) {
      $form_state->setErrorByName('replace', $this->t('No items selected'));
    }

  }

  /**
   * Handle page 1 submit.
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function formPage1Submit(array &$form, FormStateInterface $form_state) {
    $form_state
      ->set('table', $form_state->getValue('table'))
      ->set('page_num', 2)
      ->setRebuild(TRUE);
  }

  /**
   * Handle page 2 submit.
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function formPage2Submit(array &$form, FormStateInterface $form_state) {
    $form_state
      ->set('table', $form_state->getValue('table'))
      ->set('replace_by', $form_state->getValue('replace_by'))
      ->set('filters', $form_state->getValue('filters'))
      ->set('page_num', 3)
      ->setRebuild(TRUE);
  }

  /**
   * Handle page 3 submit.
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function formPage3Submit(array &$form, FormStateInterface $form_state) {
    $table = $form_state->getValue('table');
    $itemsToProcess = [];
    foreach ($table as $item) {
      if (!empty($item)) {
        $itemsToProcess[] = $item;
      }
    }
    $itemsToProcess = array_chunk($itemsToProcess, 10, TRUE);

    $operations = [];
    foreach ($itemsToProcess as $i => $table) {
      $operations[] = [
        'batch_pega_search_replace',
        [
          [
            'table' => $table,
            "search_string" => $form_state->getValue('search_string'),
            "replace" => $form_state->getValue('replace_by')
          ],
          $this->t('(Operation @operation)', ['@operation' => $i]),
        ],
      ];
    }
    $batch = [
      'title' => $this->t('Working hard on @num operations', ['@num' => count($itemsToProcess)]),
      'operations' => $operations,
      'finished' => 'batch_pega_search_replace_finished',
    ];
    batch_set($batch);
  }

  /**
   * Prepare form for page 2.
   * @param array $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function formPageTwo(array &$form, FormStateInterface $form_state) {
    $filters = $form_state->getValue('filters');
    $form['description'] = [
      '#type' => 'item',
      '#title' => $this->t('Replace string: ') . $filters['search_string'],
    ];

    $form['replace_by'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Replace by string'),
      '#required' => TRUE,
    ];
    $form['table'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('table')
    ];
    $form['search_string'] = [
      '#type' => 'hidden',
      '#value' => $filters['search_string']
    ];
    $form['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::formPageTwoBack'],
      '#limit_validation_errors' => [],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Submit'),
      '#submit' => ['::formPage2Submit']
    ];

    return $form;
  }

  /**
   * Prepare form for page 3.
   * @param array $form
   * @param FormStateInterface $form_state
   * @return array
   */
  public function formPageThree(array &$form, FormStateInterface $form_state) {

    $form['description'] = [
      '#type' => 'item',
      '#title' => $this->t('Are you sure, you want to replace string: %search_string for: %replace_by ?', [ '%search_string' => $form_state->getValue('search_string'), '%replace_by' => $form_state->getValue('replace_by')]),
    ];
    $form['table'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('table')
    ];
    $form['replace_by'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('replace_by')
    ];
    $form['search_string'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('search_string')
    ];

    $form['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::formPageTwoBack'],
      '#limit_validation_errors' => [],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Yes I am sure.'),
      '#submit' => ['::formPage3Submit']
    ];

    return $form;
  }

  /**
   * Provides custom submission handler for 'Back' button (page 2).
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function formPageTwoBack(array &$form, FormStateInterface $form_state) {
    $form_state
      ->set('page_num', 1)
      ->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state
      ->set('filters', $form_state->getValue('filters'))
      ->setRebuild(TRUE);
  }

  /**
   * Clears the form inputs by unsetting the stored values.
   */
  public function resetForm(array &$form, FormStateInterface $form_state) {
    $form_state
      ->set('filters', NULL)
      ->setRebuild(TRUE);
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
