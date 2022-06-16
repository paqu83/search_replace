<?php

namespace Drupal\search_replace\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_replace\Services\SearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class SearchReplaceForm.
 *
 * @package Drupal\search_replace\Form
 */
class SearchReplaceForm extends FormBase {
//TODO: try to use this https://www.sitepoint.com/how-to-build-multi-step-forms-in-drupal-8/ rather than single form with steps
  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Search service used for providing search results.
   *
   * @var \Drupal\search_replace\Services\SearchService
   */
  protected $searchService;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, SearchService $searchService) { //TODO: Search service should be Interface like EntityTypeInterface.
    $this->entityTypeManager = $entity_type_manager;
    $this->searchService = $searchService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('search_replace.search.string')

    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'search_replace_form';
  }

  /**
   * Build form.
   *
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state instance.
   *
   * @return array
   *   Render form array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $filters = $form_state->get('filters');
    $search_string = empty($filters['search_string']) ? '' : $filters['search_string'];

    if ($form_state->has('page_num') && $form_state->get('page_num') == 2) {
      return self::formPage2($form, $form_state);
    }
    if ($form_state->has('page_num') && $form_state->get('page_num') == 3) {
      return self::formPage3($form, $form_state);
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
      '#required' => TRUE,
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];
    $form['filters']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];
    // Add a Reset button if any filters are set.
    $return = $this->searchService->searchStringPrepareRows($search_string);
    $rows = $return['rows'];

    if (!empty($search_string)) {
      $form['filters']['actions']['reset'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reset'),
        '#submit' => ['::resetForm'],
      ];
      $form['table_actions'] = [
        '#type' => 'container',
        '#weight' => 10,
        '#attributes' => ['class' => ['container-inline']],
      ];
      $form['table_actions']['replace'] = [
        '#type' => 'submit',
        '#value' => 'Replace selected',
        '#submit' => ['::formPage1Submit'],
        '#weight' => 10,
        '#validate' => ['::replaceValidate'],
      ];
      $form['table_actions']['help'] = [
        '#type' => 'item',
        '#markup' => $this->t('Showing :showCount of :allCount, skipped :skipped (broken relations)',
          [
            ':showCount' => count($rows),
            ':allCount' => $return['allCount'],
            ':skipped' => $return['skipped'],
          ]
        ),
      ];
    }

    $form['table'] = [
      '#header' => [
        $this->t('ID'),
        $this->t('Entity type'),
        $this->t('Lang'),
        $this->t('Entity type bundle'),
        $this->t('Title'),
        $this->t('Edit'),
        $this->t('Found in field'),
        $this->t('Search string surrounding'),

      ],
      '#options' => $rows,
      '#empty' => $this->t('There is no matching content.'),
      '#type' => 'tableselect',
      '#weight' => 20,
    ];

    return $form;
  }

  /**
   * Validate replace string.
   *
   * @param array $form
   *   Form instance.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state data.
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
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $filters = $form_state->getValue('filters');
    if (!empty($filters) && strlen($filters['search_string']) < 3) {
      $form_state->setErrorByName('search_string', $this->t('The string you are searching is too short. Min. 3 characters.'));
    }
  }
  /**
   * Handle page 1 submit.
   *
   * @param array $form
   *   Form instance.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state data.
   */
  public function formPage1Submit(array &$form, FormStateInterface $form_state) {
    $form_state
      ->set('table', $form_state->getValue('table'))
      ->set('page_num', 2)
      ->setRebuild(TRUE);
  }

  /**
   * Handle page 2 submit.
   *
   * @param array $form
   *   Form instance.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state data.
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
   *
   * @param array $form
   *   Form instance.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state data.
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
        'batch_search_replace',
        [
          [
            'table' => $table,
            'search_string' => $form_state->getValue('search_string'),
            'replace' => $form_state->getValue('replace_by'),
          ],
          $this->t('(Operation @operation)', ['@operation' => $i]),
        ],
      ];
    }
    $batch = [
      'title' => $this->t('Working hard on @num operations', ['@num' => count($itemsToProcess)]),
      'operations' => $operations,
      'finished' => 'batch_search_replace_finished',
    ];
    batch_set($batch);
  }

  /**
   * Prepare form for page 2.
   *
   * @param array $form
   *   Form instance.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state data.
   */
  public function formPage2(array &$form, FormStateInterface $form_state) {
    $filters = $form_state->getValue('filters');
    $form['description'] = [
      '#type' => 'item',
      '#title' => $this->t('Replace string: @search_string', ['@search_string' => $filters['search_string']]),
    ];

    $form['replace_by'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Replace by string'),
      '#required' => TRUE,
    ];
    $form['table'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('table'),
    ];
    $form['search_string'] = [
      '#type' => 'hidden',
      '#value' => $filters['search_string'],
    ];
    $form['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::formPage2Back'],
      '#limit_validation_errors' => [],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Submit'),
      '#submit' => ['::formPage2Submit'],
    ];

    return $form;
  }

  /**
   * Prepare form for page 3.
   *
   * @param array $form
   *   Form instance.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state data.
   */
  public function formPage3(array &$form, FormStateInterface $form_state) {

    $form['description'] = [
      '#type' => 'item',
      '#title' => $this->t('Are you sure, you want to replace string: %search_string for: %replace_by ?', ['%search_string' => $form_state->getValue('search_string'), '%replace_by' => $form_state->getValue('replace_by')]),
    ];
    $form['table'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('table'),
    ];
    $form['replace_by'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('replace_by'),
    ];
    $form['search_string'] = [
      '#type' => 'hidden',
      '#value' => $form_state->getValue('search_string'),
    ];

    $form['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => ['::formPage2Back'],
      '#limit_validation_errors' => [],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Yes I am sure.'),
      '#submit' => ['::formPage3Submit'],
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
  public function formPage2Back(array &$form, FormStateInterface $form_state) {
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
  
}
