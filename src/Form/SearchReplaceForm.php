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
  
}
