<?php
/**
 * @file
 * Contains \Drupal\ami\Form\AmiMultiStepIngestBaseForm.
 */

namespace Drupal\ami\Form;

use Drupal\ami\AmiUtilityService;
use Drupal\ami\Entity\ImporterAdapter;
use Drupal\ami\Plugin\ImporterAdapterManager;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\node\Entity\Node;

/**
 * Provides a form for that invokes Importer Adapter Plugins and batch imports data
 *
 * This form provides 6 steps
 *  Step 1: select the Importer Plugin to be used
 *  Step 2: Provide the data the Plugin requires or load a saved config
 *  Step 3: Map Columns to Metadata info
 *  Step 4: Map Columns to Node entity Info
 *  Step 5: Provide Binaries if not remote and ingest
 *  last step can be overridden from the Base Class via $lastStep = int()
 * @ingroup ami
 */
class AmiMultiStepIngest extends AmiMultiStepIngestBaseForm {

  protected $lastStep = 6;
  /**
   * Holds a ready select options array with usable metadata displays
   *
   * @var array
   */
  protected array $metadatadisplays = [];

  /**
   * Holds a ready select options array with usable webforms
   *
   * @var array
   */
  protected array $webforms = [];


  /**
   * Holds a ready select options array with usable webforms
   *
   * @var array
   */
  protected array $bundlesAndFields = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'ami_multistep_import_form';
  }

  public function __construct(PrivateTempStoreFactory $temp_store_factory, SessionManagerInterface $session_manager, AccountInterface $current_user, ImporterAdapterManager $importerManager, AmiUtilityService $ami_utility,  EntityTypeManagerInterface $entity_type_manager, TransliterationInterface $transliteration) {
    parent::__construct($temp_store_factory, $session_manager, $current_user, $importerManager, $ami_utility,  $entity_type_manager, $transliteration);
    $this->metadatadisplays = $this->AmiUtilityService->getMetadataDisplays();
    $this->webforms = $this->AmiUtilityService->getWebforms();
    $this->bundlesAndFields = $this->AmiUtilityService->getBundlesAndFields();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form['message-step'] = [
      '#markup' => '<div class="step">' . $this->t('AMI step @step of @laststep',[
          '@step' => $this->step,
          '@laststep' => $this->lastStep,
        ]) . '</div>',
    ];
    if ($this->step == 1) {
      $pluginValue = $this->store->get('plugin', NULL);
      $definitions = $this->importerManager->getDefinitions();
      $options = [];
      foreach ($definitions as $id => $definition) {
        $options[$id] = $definition['label'];
      }

      $form['plugin'] = [
        '#type' => 'select',
        '#title' => $this->t('Plugin'),
        '#default_value' => $pluginValue, // $importer->getPluginId(),
        '#options' => $options,
        '#description' => $this->t('The plugin to be used to import ADOs.'),
        '#required' => TRUE,
        '#empty_option' => $this->t('- Please select a plugin -'),
      ];
    }
    if ($this->step == 2) {
      $parents = ['pluginconfig'];
      $form_state->setValue('pluginconfig', $this->store->get('pluginconfig',[]));
      $pluginValue = $this->store->get('plugin', NULL);
      // Only create a new instance if we do not have the PluginInstace around
      /* @var $plugin_instance \Drupal\ami\Plugin\ImporterAdapterInterface | NULL */
      $plugin_instance = $this->store->get('plugininstance');
      if (!$plugin_instance || $plugin_instance->getPluginid() != $pluginValue || $pluginValue == NULL) {
        $configuration = [];
        $configuration['config'] = ImporterAdapter::create();
        $plugin_instance = $this->importerManager->createInstance($pluginValue,$configuration);
        $this->store->set('plugininstance',$plugin_instance);
      }

      $form['pluginconfig'] = $plugin_instance->interactiveForm($parents, $form_state);
      $form['pluginconfig']['#tree'] = TRUE;
    }
    // TO keep this discrete and easier to edit maybe move to it's own method?
    if ($this->step == 3) {
      $data = $this->store->get('data');
      $column_keys = $data['headers'];
      $mapping = $this->store->get('mapping');
      dpm($mapping);

      $metadata = [
        'direct' => 'Direct ',
        'template' => 'Template',
        'webform' => 'Webform',
      ];
      $template = $this->getMetadatadisplays();
      $webform = $this->getWebforms();
      $bundle = $this->getBundlesAndFields();


      $global_metadata_options = $metadata + ['custom' => 'Custom (Expert Mode)'];
      //Each row (based on its type column) can have its own approach setup(expert mode)
      $element_conditional = [];
      $element = [];
      $element['bundle'] =[
        '#type' => 'select',
        '#title' => $this->t('Fields and Bundles'),
        '#options' => $bundle,
        '#description' => $this->t('Destination Field/Bundle for New ADOs'),
      ];

      $element_conditional['template'] = [
        '#type' => 'select',
        '#title' => $this->t('Template'),
        '#options' => $template,
        '#description' => $this->t('Columns will be casted to ADO metadata (JSON) using a Twig template setup for JSON output'),
      ];

      $element_conditional['webform'] =[
        '#type' => 'select',
        '#title' => $this->t('Webform'),
        '#options' => $webform,
        '#description' => $this->t('Columns are casted to ADO metadata (JSON) by passing/validating Data through an existing Webform'),
      ];

      $form['ingestsetup']['globalmapping'] = [
        '#type' => 'select',
        '#title' => $this->t('Select the data transformation approach'),
        '#default_value' => isset($mapping['globalmapping']) && !empty($mapping['globalmapping']) ? $mapping['globalmapping'] : reset($global_metadata_options),
        '#options' => $global_metadata_options,
        '#description' => $this->t('How your source data will be transformed into ADOs Metadata.'),
        '#required' => TRUE,
      ];
      $newelements_global = $element_conditional;
      foreach ($newelements_global as $key => &$subelement) {
        $subelement['#default_value'] = isset($mapping['metadata_config'][$key]) ? $mapping['metadata_config'][$key]: reset(${$key});
        $subelement['#states'] = [
          'visible' => [
            ':input[name*="globalmapping"]' => ['value' => $key],
          ],
        ];
      }
      $form['ingestsetup']['metadata_config'] = $newelements_global;

      $form['ingestsetup']['files'] = [
        '#type' => 'select',
        '#title' => $this->t('Select which columns contain filenames, entities or URLs where we can fetch files'),
        '#default_value' => isset($mapping['files']) ? $mapping['files'] : [],
        '#options' => array_combine($column_keys, $column_keys),
        '#size' => count($column_keys),
        '#multiple' => TRUE,
        '#description' => $this->t('From where your files will be fetched to be uploaded and attached to an ADOs and described in the Metadata.'),
        '#empty_option' => $this->t('- Please select columns -'),
        '#states' => [
          'visible' => [
            ':input[name*="globalmapping"]' => ['!value' => 'custom'],
          ],
        ]
      ];

      $form['ingestsetup']['bundle'] = $element['bundle'];
      $form['ingestsetup']['bundle']['#default_value'] = isset($mapping['bundle']) ? $mapping['bundle'] : reset($bundle);
      $form['ingestsetup']['bundle']['#states'] = [
        'visible' => [
          ':input[name*="globalmapping"]' => ['!value' => 'custom'],
        ],
      ];

      // Get all headers and check for a 'type' key first, if not allow the user to select one?
      // Wonder if we can be strict about this and simply require always a "type"?

      dpm($data['headers']);

      $type_column_index = array_search('type', $data['headers']);
      if ($type_column_index !== FALSE) {
        $alltypes = $this->AmiUtilityService->getDifferentValuesfromColumn($data, $type_column_index);
        $form['ingestsetup']['custommapping'] = [
          '#type' => 'fieldset',
          '#tree' => TRUE,
          '#title' => t('Please select your custom data transformation and mapping options'),
          '#states' => [
            'visible' => [
              ':input[name*="globalmapping"]' => ['value' => 'custom'],
            ],
          ]
        ];
        foreach ($alltypes as $column_index => $type) {
          // Transliterate $types we can use them as machine names
          $machine_type = $this->getMachineNameSuggestion($type);
          $form['ingestsetup']['custommapping'][$type] = [
            '#type' => 'details',
            '#title' => t('For @type', ['@type' => $type]),
            '#description' => t('Choose your transformation option'),
            '#open' => TRUE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
          ];
          $form['ingestsetup']['custommapping'][$type]['metadata'] = [
            //'#name' => 'metadata_'.$machine_type,
            '#type' => 'select',
            '#title' => $this->t('Select the data transformation approach for @type', ['@type' => $type]),
            '#default_value' => isset($mapping['custommapping'][$type]['metadata']) ? $mapping['custommapping'][$type]['metadata'] : reset($metadata),
            '#options' => $metadata,
            '#description' => $this->t('How your source data will be transformed into ADOs Metadata.'),
            '#required' => TRUE,
            '#attributes' =>  [
              'data-adotype' => 'metadata_'.$machine_type
            ],
          ];
          // We need to reassign or if not circular references mess with the render array
          $newelements = $element_conditional;
          foreach ($newelements as $key => &$subelement) {
            $subelement['#default_value'] = isset($mapping['custommapping'][$type]['metadata_config'][$key]) ? $mapping['custommapping'][$type]['metadata_config'][$key] : reset(${$key});
            $subelement['#states'] = [
              'visible' => [
                ':input[data-adotype="metadata_'.$machine_type.'"]' => ['value' => $key],
              ],
              'required' => [
                ':input[data-adotype="metadata_'.$machine_type.'"]' => ['value' => $key],
              ]
            ];
          }

          $form['ingestsetup']['custommapping'][$type]['metadata_config'] = $newelements;
          $form['ingestsetup']['custommapping'][$type]['bundle'] = $element['bundle'];

          $form['ingestsetup']['custommapping'][$type]['bundle']['#default_value'] = isset($mapping['custommapping'][$type]['bundle']) ? $mapping['custommapping'][$type]['bundle'] : reset($bundle);

          $form['ingestsetup']['custommapping'][$type]['files'] = [
            '#type' => 'select',
            '#title' => $this->t('Select which columns contain filenames, entities or URLs where we can fetch the files for @type', ['@type' => $type]),
            '#default_value' => isset($mapping['custommapping'][$type]['files']) ? $mapping['custommapping'][$type]['files'] : [],
            '#options' => array_combine($column_keys, $column_keys),
            '#size' => count($column_keys),
            '#multiple' => TRUE,
            '#description' => $this->t('From where your files will be fetched to be uploaded and attached to an ADOs and described in the Metadata.'),
            '#empty_option' => $this->t('- Please select columns for @type -', ['@type' => $type]),
          ];
        }
      }
    }

    if ($this->step == 4) {
      $data = $this->store->get('data');
      $column_keys = $data['headers'];
      $column_options = array_combine($column_keys, $column_keys);
      $mapping = $this->store->get('mapping');
      $adomapping = $this->store->get('adomapping');
      dpm($adomapping);
      $required_maps = [
        'sequence' => 'Sequence Order',
        'label' => 'Ado Label',
      ];
      $form['ingestsetup']['adomapping'] = [
        '#type' => 'fieldset',
        '#tree' => TRUE,
        '#title' => t('Please select your Global ADO mappings'),
      ];
      $form['ingestsetup']['adomapping']['parents'] = [
        '#type' => 'select',
        '#title' => $this->t('ADO Parent Columns'),
        '#default_value' => isset($adomapping['parents']) ? $adomapping['parents'] : [],
        '#options' => array_combine($column_keys, $column_keys),
        '#size' => count($column_keys),
        '#multiple' => TRUE,
        '#required' => FALSE,
        '#description' => $this->t('Columns that hold either other row numbers or UUIDs(an existing ADO) connecting ADOs between each other (e.g ismemberof). You can choose multiple'),
        '#empty_option' => $this->t('- Please select columns -'),
      ];
      $form['ingestsetup']['adomapping']['autouuid'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Automatically assign UUID'),
        '#description' => $this->t(
          'Check this to automatically Assign UUIDs to each ADO'
        ),
        '#required' => FALSE,
        '#default_value' => isset($adomapping['autouuid']) ? $adomapping['autouuid'] : TRUE,
      ];
      $form['ingestsetup']['adomapping']['uuid'] = [
        '#type' => 'webform_mapping',
        '#title' => $this->t('UUID assignment'),
        '#description' => $this->t(
          'Please select how your ADO UUID will be assigned'
        ),
        '#description_display' => 'before',
        '#empty_option' =>  $this->t('- Let AMI decide -'),
        '#empty_value' =>  NULL,
        '#default_value' =>  isset($adomapping['uuid']) ? $adomapping['uuid'] : [],
        '#required' => FALSE,
        '#source' => [ 'uuid' => 'ADO UUID'],
        '#source__title' => $this->t('ADO mappings'),
        '#destination__title' => $this->t('Data Columns'),
        '#destination' => $column_options,
        '#states' => [
          'visible' => [
            ':input[name*="autouuid"]' => ['checked' => FALSE],
          ],
          'required' => [
            ':input[name*="autouuid"]' => ['checked' => FALSE],
          ]
        ]
      ];

      $form['ingestsetup']['adomapping']['base'] = [
        '#type' => 'webform_mapping',
        '#title' => $this->t('Required ADO mappings'),
        '#format' => 'list',
        '#description_display' => 'before',
        '#empty_option' =>  $this->t('- Let AMI decide -'),
        '#empty_value' =>  NULL,
        '#default_value' =>  isset($adomapping['base']) ? $adomapping['base'] : [],
        '#required' => true,
        '#source' => $required_maps,
        '#source__title' => $this->t('Base ADO mappings'),
        '#destination__title' => $this->t('columns'),
        '#destination' => $column_options
      ];
    }
    if ($this->step == 5) {
      $fileid = $this->store->get('zip');
      dpm($fileid);
      $form['zip'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Provide an ZIP file'),
        '#required' => false,
        '#multiple' => false,
        '#default_value' => isset($fileid) ? [$fileid] : NULL,
        '#description' => $this->t('Provide an optional ZIP file containing your assets.'),
        '#upload_location' => 'temporary://ami',
        '#upload_validators' => [
          'file_validate_extensions' => ['zip'],
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    if ($form_state->getValue('plugin', NULL)) {
      $this->store->set('plugin', $form_state->getValue('plugin'));
    }
    if ($form_state->getValue('pluginconfig', [])) {
      $this->store->set('pluginconfig', $form_state->getValue('pluginconfig'));
    }
    // First data fetch step
    if ($this->step == 3) {
      /* @var $plugin_instance \Drupal\ami\Plugin\ImporterAdapterInterface| NULL */
      $plugin_instance = $this->store->get('plugininstance');
      if ($plugin_instance) {
        // We may want to run a batch here?
        // @TODO investigate how to run a batch and end in the same form different step?
        // Idea is batch is only needed if there is a certain max number, e.g 5000 rows?
        $data = $plugin_instance->getData($this->store->get('pluginconfig'),0,-1);
        // Why 3? At least title, a type and a parent even if empty

        // Total rows contains data without headers So a single one is good enough.
        if (is_array($data) && !empty($data) and isset($data['headers']) && count($data['headers']>=3) && isset($data['totalrows']) && $data['totalrows']>=1) {
          $this->store->set('data', $data);
        }
      }
    }
    if ($this->step == 4) {
      if ($form_state->getTriggeringElement()['#name'] !== 'prev') {
        $globalmapping = $form_state->getValue('globalmapping');
        $custommapping = $form_state->getValue('custommapping');
        $this->store->set('mapping', [
          'globalmapping' => $globalmapping,
          'custommapping' => $custommapping
        ]);
      }
    }
    if ($this->step == 5) {
      if ($form_state->getTriggeringElement()['#name'] !== 'prev') {
        $adomapping = $form_state->getValue('adomapping');
        $this->store->set('adomapping', $adomapping);
      }
    }
    if ($this->step == 6) {
      $file = $this->entityTypeManager->getStorage('file')
        ->load($form_state->getValue('zip')[0]); // Just FYI. The file id will be stored as an array
      // And you can access every field you need via standard method
      $this->store->set('zip', $file->id());
      dpm($file->get('filename')->value);
      dpm($this->store->get('mapping'));
      $adomapping = $this->store->get('adomapping');
      dpm($this->store->get('pluginconfig'));
      dpm($this->store->get('plugininstance'));

      /* @var $plugin_instance \Drupal\ami\Plugin\ImporterAdapterInterface| NULL */
      $plugin_instance = $this->store->get('plugininstance');
      if ($plugin_instance) {
        // We may want to run a batch here?
        // @TODO investigate how to run a batch and end in the same form different step?
        // Idea is batch is only needed if there is a certain max number, e.g 5000 rows?
        $data = $plugin_instance->getData($this->store->get('pluginconfig'), 0, -1);
        // WE should probably add the UUIDs here right now.
        $uuid_key = isset($adomapping['uuid']['uuid']) && !empty($adomapping['uuid']['uuid']) ? $adomapping['uuid']['uuid'] : 'uuid_node';
        $this->AmiUtilityService->csv_save($data, $uuid_key);
      }
      else {
        // Explain why
        $this->messenger()->addError('Ups. Something went wrong and we could not get your data');
      }
    }

    // Parent already sets rebuild but better to not trust our own base classes
    // In case they change.
    $form_state->setRebuild(TRUE);
    return;

    $host = \Drupal::request()->getHost();
    $url = $host . '/' . drupal_get_path('module', 'batch_import_example') . '/docs/animals.json';
    $request = \Drupal::httpClient()->get($url);
    $body = $request->getBody();
    $data = Json::decode($body);
    $total = count($data);

    $batch = [
      'title' => t('Importing ADOs'),
      'operations' => [],
      'init_message' => t('Import process is starting.'),
      'progress_message' => t('Processed @current out of @total. Estimated time: @estimate.'),
      'error_message' => t('The process has encountered an error.'),
    ];

    foreach($data as $item) {
      $batch['operations'][] = [['\Drupal\batch_import_example\Form\ImportForm', 'importAnimal'], [$item]];
    }

    batch_set($batch);
    \Drupal::messenger()->addMessage('Imported ' . $total . ' animals!');

    $form_state->setRebuild(TRUE);
  }

  /**
   * @return array
   */
  public function getMetadatadisplays(): array {
    return $this->metadatadisplays;
  }

  /**
   * @param array $metadatadisplays
   */
  public function setMetadatadisplays(array $metadatadisplays): void {
    $this->metadatadisplays = $metadatadisplays;
  }

  /**
   * @return array
   */
  public function getWebforms(): array {
    return $this->webforms;
  }

  /**
   * @param array $webforms
   */
  public function setWebforms(array $webforms): void {
    $this->webforms = $webforms;
  }

  /**
   * @return array
   */
  public function getBundlesAndFields(): array {
    return $this->bundlesAndFields;
  }

  /**
   * @param array $bundlesAndFields
   */
  public function setBundlesAndFields(array $bundlesAndFields): void {
    $this->bundlesAndFields = $bundlesAndFields;
  }

}
