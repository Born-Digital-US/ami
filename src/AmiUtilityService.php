<?php
/**
 * @file
 * src/AmiUtilityService.php
 *
 * Contains Parsing/Processing utilities
 * @author Diego Pino Navarro
 */

namespace Drupal\ami;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Google_Service_Sheets;
use Ramsey\Uuid\Uuid;

class AmiUtilityService {

  use StringTranslationTrait;
  use MessengerTrait;

  /**
   * @var array
   */
  private $parameters = [];

  /**
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The 'file.usage' service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The stream wrapper manager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The archiver manager.
   *
   * @var \Drupal\Core\Archiver\ArchiverManager
   */
  protected $archiverManager;

  /**
   * The Storage Destination Scheme.
   *
   * @var string;
   */
  protected $destinationScheme = NULL;

  /**
   * The Module Handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface;
   */
  protected $moduleHandler;

  /**
   * The language Manager
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The Transliteration
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * The  Configuration settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * StrawberryfieldFilePersisterService constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   * @param \Drupal\file\FileUsage\FileUsageInterface $file_usage
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   * @param \Drupal\Core\Archiver\ArchiverManager $archiver_manager
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param StrawberryfieldUtilityService $strawberryfield_utility_service ,
   */
  public function __construct(
    FileSystemInterface $file_system,
    FileUsageInterface $file_usage,
    EntityTypeManagerInterface $entity_type_manager,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    ArchiverManager $archiver_manager,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    LanguageManagerInterface $language_manager,
    TransliterationInterface $transliteration,
    ModuleHandlerInterface $module_handler,
    LoggerChannelFactoryInterface $logger_factory,
    StrawberryfieldUtilityService $strawberryfield_utility_service
  ) {
    $this->fileSystem = $file_system;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->archiverManager = $archiver_manager;
    //@TODO evaluate creating a ServiceFactory instead of reading this on construct.
    $this->destinationScheme = $config_factory->get(
      'strawberryfield.storage_settings'
    )->get('file_scheme');
    $this->config = $config_factory->get(
      'strawberryfield.filepersister_service_settings'
    );
    $this->languageManager = $language_manager;
    $this->transliteration = $transliteration;
    $this->moduleHandler = $module_handler;
    $this->loggerFactory = $logger_factory;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
  }

  /**
   * Checks if value is prefixed entity or an UUID.
   *
   * @param mixed $val
   *
   * @return bool
   */
  public function isEntityId($val) {
    return (!is_int($val) && is_numeric(
        $this->getIdfromPrefixedEntityNode($val)
      ) || \Drupal\Component\Uuid\Uuid::isValid(
        $val
      ));
  }

  /**
   * Array value callback. True if value is not an array.
   *
   * @param mixed $val
   *
   * @return bool
   */
  private function isNotArray($val) {
    return !is_array($val);
  }

  /**
   * Array value callback. True if $key starts with Entity
   *
   * @param mixed $val
   *
   * @return bool
   */
  public function getIdfromPrefixedEntityNode($key) {
    if (strpos($key, 'entity:node:', 0) !== FALSE) {
      return substr($key, strlen("entity:node:"));
    }
    return FALSE;
  }


  public function getIngestInfo($file_path) {
    error_log('Running  AMI getIngestInfo');

    $file_data_all = $this->read_filedata(
      $file_path,
      -1,
      $offset = 0
    );
    $this->parameters['data']['headers'] = $file_data_all['headers'];

    $namespace_hash = [];
    // Keeps track of all parents and child that don't have a PID assigned.
    $parent_hash = [];
    $namespace_count = [];
    $info = [];
    // Keeps track of invalid rows.
    $invalid = [];
    foreach ($file_data_all['data'] as $index => $row) {
      // Each row will be an object.
      $objectInfo = [];
      $objectInfo['type'] = trim(
        $row[$this->parameters['type_source_field_index']]
      );
      // Lets start by grouping by parents, namespaces and generate uuids
      // namespaces are inherited, so we just need to find collection
      // objects in parent uuid column.
      $objectInfo['parent'] = trim(
        $row[$this->parameters['object_maping']['parentmap']]
      );
      $possiblePID = "";

      $objectInfo['data'] = $row;


      $possibleUUID = trim($row[$this->parameters['object_maping']['uuidmap']]);

      if (\Drupal\Component\Uuid\Uuid::isValid($possibleUUID)) {
        $objectInfo['uuid'] = $possibleUUID;
        // Now be more strict for action = update
        if (in_array(
          $row[$this->parameters['object_maping']['crudmap']],
          ['create', 'update', 'delete']
        )) {
          $existing_object = $this->entityTypeManager->getStorage('node')
            ->loadByProperties(['uuid' => $objectInfo['uuid']]);
          //@TODO field_descriptive_metadata  is passed from the Configuration
          if (!$existing_object) {
            unset($objectInfo);
            $invalid = $invalid + [$index => $index];
          }
        }
      }
      if (!isset($objectInfo['uuid'])) {
        unset($objectInfo);
        $invalid = $invalid + [$index => $index];
      }

      if (isset($objectInfo)) {
        if (\Drupal\Component\Uuid\Uuid::isValid($objectInfo['parent'])) {
          // If valid PID, let's try to fetch a valid namespace for current type
          // we will store all this stuff in a temp hash to avoid hitting
          // this again and again.
          $objectInfo['parent_type'] = $this->getParentType(
            $objectInfo['parent']
          );
          if ($objectInfo['parent_type']) {
            if (!isset($objectInfo['uuid'])) { //Only do this if no UUID assigned yet
              $objectInfo['namespace'] = 'genericnamespace';
            }
            else {
              // we have a PID but i still want my objectInfo['namespace']
              // NO worries about checking if uuidparts is in fact lenght of 2
              // PID was checked for sanity a little bit earlier
              $objectInfo['namespace'] ='genericnamespace';
            }
          }
          else {
            // No parent type, no object, can't create.
            unset($objectInfo);
            $invalid = $invalid + [$index => $index];
          }
        }
        else {
          // Means our parent object is a ROW index
          // (referencing another row in the spreadsheet)
          // So a different strategy is needed. We will need recurse
          // until we find a non numeric parent or none! Because
          // in Archipelago we allow the none option for sure!
          $notUUID = TRUE;
          $parent = $objectInfo['parent'];
          $parent_hash[$parent][$index] = $index;
          $parentchilds = [];
          // Lets check if the index actually exists before going crazy.

          if (!isset($file_data_all['data'][$parent])) {
            $invalid[$parent] = $parent;
            $invalid[$index] = $index;
          }

          if ((!isset($invalid[$index])) && (!isset($invalid[$parent]))) {
            // Only traverse if we don't have this index or the parent one
            // in the invalid register.
            $objectInfo['parent_type'] = $file_data_all['data'][$parent][$this->parameters['type_source_field_index']];
            $parentchilds = [];
            $i = 0;
            while ($notUUID) {
              $parentup = $file_data_all['data'][$parent][$this->parameters['object_maping']['parentmap_row']['parentmap']];

              // The Simplest approach for breaking a knot /infinite loop,
              // is invalidating the whole parentship chain for good.
              $inaloop = isset($parentchilds[$parentup]);
              // If $inaloop === true means we already traversed this branch
              // so we are in a loop and all our original child and it's
              // parent objects are invalid.
              if ($inaloop) {
                $invalid = $invalid + $parentchilds;
                unset($objectInfo);
                $notUUID = FALSE;
                break;
              }

              $parentchilds[$parentup] = $parentup;
              if (\Drupal\Component\Uuid\Uuid::isValid(trim($parentup))) {
                if (!isset($objectInfo['uuid'])) { //Only do this if no PID assigned yet
                  $namespace = 'genericnamespace';
                  $objectInfo['namespace'] = $namespace;
                }
                else {
                  $objectInfo['namespace'] = 'genericnamespace';
                }

                $notUUID = FALSE;
                break;
              }
              elseif (empty(trim($parent))) {

                // We can't continue here
                // means there must be an error
                // This will fail for any child object that is
                // child of any of these parents.
                $invalid = $invalid + $parentchilds + [$objectInfo['parent'] => $objectInfo['parent']];
                unset($objectInfo);
                $notUUID = FALSE;
              }
              else {
                // This a simple accumulator, means all is well,
                // parent is still an index.
                $parent_hash[$parentup][$parent] = $parent;
              }
              $parent = $parentup;
            }
          }
          else {
            unset($objectInfo);
          }
        }
      }
      if (isset($objectInfo) and !empty($objectInfo)) {
        $info[$index] = $objectInfo;
      }
    }
    // Ok, maybe this is expensive, so let's try it first so.
    // TODO: optimize maybe?
    /*Uuid::uuid5(
      Uuid::NAMESPACE_URL,
      'https://www.php.net'
    );*/
    // Using UUID5 we can make sure that given a certain NameSpace URL (which would
    // be a distributeable UUID amongst many repos and a passed URL, we get always
    // the same UUID.
    //e.g if the source is a remote URL or we get a HANDLE URL per record
    // WE can always generate the SAME URL and that way avoid
    // Duplicated ingests!

    // New first pass: ensure parents have always a PID first
    // since rows/parent/child order could be arbitrary
    foreach ($parent_hash as $parent => $children) {
      if (isset($info[$parent])) {
        $namespace = $info[$parent]['namespace'];
        $info[$parent]['uuid'] = isset($info[$parent]['uuid']) ? $info[$parent]['uuid'] : Uuid::uuid4();
      }
    }

    // Now the real pass, iterate over every row.
    foreach ($info as $index => &$objectInfo) {
      $namespace = $objectInfo['namespace'];
      $objectInfo['uuid'] = isset($objectInfo['uuid']) ? $objectInfo['uuid'] : Uuid::uuid4();

      // Is this object parent of someone?
      if (isset($parent_hash[$objectInfo['parent']])) {
        $objectInfo['parent'] = $info[$objectInfo['parent']]['uuid'];
      }
    }
    // Keep track of what could be processed and which ones not.
    $this->processedObjectsInfo = [
      'success' => array_keys($info),
      MessengerInterface::TYPE_ERROR => array_keys($invalid),
      'fatal' => [],
    ];

    return $info;
  }

  public function getParentType($uuid) {
    // This is the same as format_strawberry \format_strawberryfield_entity_view_mode_alter
    // @TODO refactor into a reusable method inside strawberryfieldUtility!
    $entity = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['uuid' =>$uuid]);
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield($entity)) {
      foreach ($sbf_fields as $field_name) {
        /* @var $field StrawberryFieldItem */
        $field = $entity->get($field_name);
        if (!$field->isEmpty()) {
          foreach ($field->getIterator() as $delta => $itemfield) {
            /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
            $flatvalues = (array) $itemfield->provideFlatten();
            if (isset($flatvalues['type'])) {
              $adotype = array_merge($adotype, (array) $flatvalues['type']);
            }
          }
        }
      }
    }
    if (!empty($adotype)) {
      return reset($adotype);
    }
    //@TODO refactor into a CONST
    return 'thing';
  }

  /**
   * Wrapper/Chooser for Data source
   *
   * @param array $form_state
   *   $form_state with valid src. options.
   * @param int $page
   *   which page, defaults to 0.
   * @param int $per_page
   *   number of records per page, -1 means all.
   *
   * @return array
   *   array of associative arrays containing header and data as header =>
   *   value pairs
   */
  public function datasource_loader(
    array $form_state,
    $page = 0,
    $per_page = 20
  ) {
    //dpm($form_state);
    if ($form_state['storage']['values']['step0']['data_source'] == 'google' && !empty($form_state['storage']['values']['step2']['google_api']['spreadsheet_id'])) {
      $spreadsheetId = trim(
        $form_state['storage']['values']['step2']['google_api']['spreadsheet_id']
      );
      // Parse the ID from the URL if a full URL was provided.
      // @author of following chunk is Mark Mcfate @McFateM!
      if ($parsed = parse_url($spreadsheetId)) {
        if (isset($parsed['scheme'])) {
          $parts = explode('/', $parsed['path']);
          $spreadsheetId = $parts[3];
        }
      }
      $range = trim(
        $form_state['storage']['values']['step2']['google_api']['spreadsheet_range']
      );
      //dpm($range);
      $file_data = $this->read_googledata(
        $spreadsheetId,
        $range,
        $per_page,
        $page * $per_page
      );

    }
    else {
      $file = $this->entityTypeManager->getStorage('file')->load(
        $form_state['storage']['values']['step1']['file']
      );
      $file_path = $this->fileSystem->realpath($file->uri);
      $file_data = $this->read_filedata(
        $file_path,
        $per_page,
        $page * $per_page
      );
    }
    return $file_data;
  }

  /**
   * Read Tabulated data from file into array.
   *
   * @param url $file_path
   *   Path to file
   * @param int $numrows
   *   Number of rows to return, -1 magic number means all
   * @param int $offset
   *   Offset for rows to return
   *
   * @return array
   *   array of associative arrays containing header and data as header =>
   *   value pairs
   */
  public function read_filedata(
    $file_path,
    $numrows = 20,
    $offset = 0
  ) {

    $tabdata = ['headers' => [], 'data' => [], 'totalrows' => 0];
    try {
      $inputFileType = PHPExcel_IOFactory::identify($file_path);

      $objReader = PHPExcel_IOFactory::createReader($inputFileType);

      $objReader->setReadDataOnly(TRUE);
      $objPHPExcel = $objReader->load($file_path);
    } catch (Exception $e) {
      $this->messenger()->addMessage(
        t(
          'Could not parse file with error: @error',
          ['@error' => $e->getMessage()]
        )
      );
      return $tabdata;
    }
    $table = [];
    $headers = [];
    $maxRow = 0;
    $worksheet = $objPHPExcel->getActiveSheet();
    $highestRow = $worksheet->getHighestRow();
    $highestColumn = $worksheet->getHighestColumn();
    if (($highestRow) > 1) {
      // Returns Row Headers.
      $rowHeaders = $worksheet->rangeToArray(
        'A1:' . $highestColumn . '1',
        NULL,
        TRUE,
        TRUE,
        FALSE
      );
      $rowHeaders_utf8 = array_map('stripslashes', $rowHeaders[0]);
      $rowHeaders_utf8 = array_map('utf8_encode', $rowHeaders_utf8);
      $rowHeaders_utf8 = array_map('strtolower', $rowHeaders_utf8);
      $rowHeaders_utf8 = array_map('trim', $rowHeaders_utf8);
      foreach ($worksheet->getRowIterator() as $row) {
        $rowindex = $row->getRowIndex();
        if (($rowindex > 1) && ($rowindex > ($offset)) && (($rowindex <= ($offset + $numrows + 1)) || $numrows == -1)) {
          $rowdata = [];
          // gets one row data
          $datarow = $worksheet->rangeToArray(
            "A{$rowindex}:" . $highestColumn . $rowindex,
            NULL,
            TRUE,
            TRUE,
            FALSE
          );//Devuelve los titulos de cada columna
          $flat = trim(implode('', $datarow[0]));
          //check for empty row...if found stop there.
          if (strlen($flat) == 0) {
            $maxRow = $rowindex;
            // @TODO check if this is not being overriden at line 64
            break;
          }
          $table[$rowindex] = $datarow[0];
        }
        $maxRow = $rowindex;
      }
    }
    $tabdata = [
      'headers' => $rowHeaders_utf8,
      'data' => $table,
      'totalrows' => $maxRow,
    ];
    $objPHPExcel->disconnectWorksheets();
    return $tabdata;
  }

  /**
   * Read Tabulated data coming from Google into array.
   *
   * @param url $file_path
   *   Path to file
   * @param string $range ,
   *   Google API`s expected Range value in the form of 'Sheet1!A1:B10'.
   * @param int $numrows
   *   Number of rows to return, -1 magic number means all
   * @param int $offset
   *   Offset for rows to return
   *
   * @return array
   *   array of associative arrays containing header and data as header =>
   *   value pairs
   */
  public function read_googledata(
    $spreadsheetId,
    $range = 'Sheet1!A1:B10',
    $numrows = 20,
    $offset = 0
  ) {
    $tabdata = ['headers' => [], 'data' => [], 'totalrows' => 0];
    $sp_data = [];
    $rowdata = [];
    // Load the account
    $google_api_client = \Drupal::entityTypeManager()->getStorage('google_api_client')->load('AMI');

    // Get the service.
    $googleService = \Drupal::service('google_api_client.client');
    // Apply the account to the service
    $googleService->setGoogleApiClient($google_api_client);

    // Fetch Client
    $client = $googleService->googleClient;

    // Establish a connection first
    try {
      $service = new Google_Service_Sheets($client);

      $response = $service->spreadsheets_values->get($spreadsheetId, $range);
      $sp_data = $response->getValues();
      // Empty value? just return
      if (($sp_data == NULL) or empty($sp_data)) {
        $this->messenger()->addMessage(
          t('Nothing to read, check your Data source content'),
          MessengerInterface::TYPE_ERROR
        );
        return $tabdata;

      }
    } catch (Google_Service_Exception $e) {
      $this->messenger()->addMessage(
        t('Google API Error: @e', ['@e' => $e->getMessage()]),
        MessengerInterface::TYPE_ERROR
      );
      return $tabdata;
    }
    $table = [];
    $headers = [];
    $maxRow = 0;
    $highestRow = count($sp_data);

    $rowHeaders = $sp_data[0];
    $rowHeaders_utf8 = array_map('stripslashes', $rowHeaders);
    $rowHeaders_utf8 = array_map('utf8_encode', $rowHeaders_utf8);
    $rowHeaders_utf8 = array_map('strtolower', $rowHeaders_utf8);
    $rowHeaders_utf8 = array_map('trim', $rowHeaders_utf8);

    $headercount = count($rowHeaders);

    if (($highestRow) >= 1) {
      // Returns Row Headers.

      $maxRow = 1; // at least until here.
      foreach ($sp_data as $rowindex => $row) {

        // Google Spreadsheets start with Index 0. But PHPEXCEL, parent`s
        // public function does with 1.
        // To keep both public function responses in sync using the same params, i will compensate offsets here: 

        if (($rowindex >= 1) && ($rowindex > ($offset - 1)) && (($rowindex <= ($offset + $numrows)) || $numrows == -1)) {
          $rowdata = [];
          // gets one row data

          $flat = trim(implode('', $row));
          //check for empty row...if found stop there.
          if (strlen($flat) == 0) {
            $maxRow = $rowindex;
            break;
          }
          $row = $this->array_equallyseize(
            $headercount,
            $row
          );
          $table[$rowindex] = $row;
        }
        $maxRow = $rowindex;
      }
    }
    $tabdata = [
      'headers' => $rowHeaders_utf8,
      'data' => $table,
      'totalrows' => $maxRow,
    ];

    return $tabdata;
  }

  /**
   * Checks if an URI from spreadsheet is remote or local and returns a file
   *
   * @param string $url
   *   The URL of the file to grab.
   *
   * @return mixed
   *   One of these possibilities:
   *   - If remote and exists a Drupal file object
   *   - If not remote and exists a stripped file object
   *   - If does not exist boolean FALSE
   */
  public function remote_file_get($url) {
    $parsed_url = parse_url($url);
    $remote_schemes = ['http', 'https', 'feed'];
    if (!isset($parsed_url['scheme']) || (isset($parsed_url['scheme']) && !in_array(
          $parsed_url['scheme'],
          $remote_schemes
        ))) {
      // If local file, engage any hook_remote_file_get and return the real path.
      $path = [];
      $path = module_invoke_all(
        'remote_file_get',
        $url
      );
      // get only the first path.
      if (!empty($path)) {
        if ($path[0]) {
          return $path[0];
        }
      }

      // if local file, try the path.
      $localfile = $this->fileSystem->realpath($url);
      if (!file_exists($localfile)) {
        return FALSE;
      }
      return $localfile;
    }

    // Simulate what could be the final path of a remote download.
    // to avoid redownloading.
    $localfile = file_build_uri(
      $this->fileSystem->basename($parsed_url['path'])
    );
    if (!file_exists($localfile)) {
      // Actual remote heavy lifting only if not present.
      $destination = "temporary://ami/";
      $localfile = $this->retrieve_remote_file(
        $url,
        $destination,
        FileSystemInterface::EXISTS_RENAME
      );
      return $localfile;
    }
    else {
      return $localfile;
    }
    return FALSE;
  }

  /**
   * Attempts to get a file using drupal_http_request and to store it locally.
   *
   * @param string $url
   *   The URL of the file to grab.
   * @param string $destination
   *   Stream wrapper URI specifying where the file should be placed. If a
   *   directory path is provided, the file is saved into that directory under
   *   its original name. If the path contains a filename as well, that one will
   *   be used instead.
   *   If this value is omitted, the site's default files scheme will be used,
   *   usually "public://".
   * @param int $replace
   *   Replace behavior when the destination file already exists:
   *   - FILE_EXISTS_REPLACE: Replace the existing file.
   *   - FILE_EXISTS_RENAME: Append _{incrementing number} until the filename is
   *     unique.
   *   - FILE_EXISTS_ERROR: Do nothing and return FALSE.
   *
   * @return mixed
   *   One of these possibilities:
   *   - If it succeeds an managed file object
   *   - If it fails, FALSE.
   */
  public function retrieve_remote_file(
    $url,
    $destination = NULL,
    $replace = FileSystemInterface::EXISTS_RENAME
  ) {
    // pre set a failure
    $localfile = FALSE;
    $parsed_url = parse_url($url);
    $mime = 'application/octet-stream';
    if (!isset($destination)) {
      $path = file_build_uri($this->fileSystem->basename($parsed_url['path']));
    }
    else {
      if (is_dir($this->fileSystem->realpath($destination))) {

        // Prevent URIs with triple slashes when glueing parts together.
        $path = str_replace(
            '///',
            '//',
            "{$destination}/"
          ) . $this->fileSystem->basename(
            $parsed_url['path']
          );
      }
      else {
        $path = $destination;
      }
    }
    $result = drupal_http_request($url);
    if ($result->code != 200) {
      $this->messenger()->addMessage(
        t(
          'HTTP error @errorcode occurred when trying to fetch @remote.',
          [
            '@errorcode' => $result->code,
            '@remote' => $url,
          ]
        ),
        MessengerInterface::TYPE_ERROR
      );
      return FALSE;
    }

    // It would be more optimal to run this after saving
    // but i really need the mime in case no extension is present
    $mimefromextension = \Drupal::service('file.mime_type.guesser')->guess(
      $path
    );

    if (($mimefromextension == "application/octet-stream") &&
      isset($result->headers['Content-Type'])) {
      $mimetype = $result->headers['Content-Type'];
      $extension = ExtensionGuesser::getInstance()->guess($mimetype);
      $info = pathinfo($path);
      if (($extension != "bin") && ($info['extension'] != $extension)) {
        $path = $path . "." . $extension;
      }
    }
    // File is being made managed and permanent here, will be marked as
    // temporary once it is processed AND/OR associated with a SET 
    $localfile = file_save_data($result->data, $path, $replace);
    if (!$localfile) {
      $this->messenger()->addError(
        $this->t(
          '@remote could not be saved to @path.',
          [
            '@remote' => $url,
            '@path' => $path,
          ]
        ),
        MessengerInterface::TYPE_ERROR
      );
    }

    return $localfile;
  }


  public function temp_directory($create = TRUE) {
    $directory = &drupal_static(__FUNCTION__, '');
    if (empty($directory)) {
      $directory = 'temporary://ami';
      if ($create && !file_exists($directory)) {
        mkdir($directory);
      }
    }
    return $directory;
  }

  public function metadatadisplay_process(array $twig_input = []) {
    if (count($twig_input) == 0) {
      return;
    }
    $loader = new Twig_Loader_Array(
      [
        $twig_input['name'] => $twig_input['template'],
      ]
    );

    $twig = new \Twig_Environment(
      $loader, [
        'cache' => $this->fileSystem->realpath('private://'),
      ]
    );
    $twig->addExtension(new Jasny\Twig\PcreExtension());

    //We won't validate here. We are here because our form did that
    $output = $twig->render($twig_input['name'], $twig_input['data']);
    //@todo catch anyway any twig error to avoid the worker to fail bad.

    return $output;
  }

  /**
   * Creates an CSV from array and returns file.
   *
   * @param array $data
   *   Same as import form handles, to be dumped to CSV.
   *
   * @return file
   */
  public function csv_save(array $data) {
    global $user;
    $path = 'public:///islandora-multi-importer/csv/';
    $filename = $user->uid . '-' . uniqid() . '.csv';
    // Ensure the directory
    if (!$this->fileSystem->prepareDirectory(
      $path,
      FileSystemInterface::CREATE_DIRECTORY
    )) {
      $this->messenger()->addMessage(
        t('Unable to create directory for CSV file. Verify permissions please'),
        MessengerInterface::TYPE_ERROR
      );
      return;
    }
    // Ensure the file
    $file = file_save_data('', $path . $filename);
    if (!$file) {
      $this->messenger()->addMessage(
        t('Unable to create CSV file . Verify permissions please.'),
        MessengerInterface::TYPE_ERROR
      );
      return;
    }
    $fh = fopen($file->uri, 'w');
    if (!$fh) {
      $this->messenger()->addMessage(
        t('Error reading back the just written file!.'),
        MessengerInterface::TYPE_ERROR
      );
      return;
    }
    array_walk($data['headers'], 'htmlspecialchars');
    fputcsv($fh, $data['headers']);

    foreach ($data['data'] as $row) {
      array_walk($row, 'htmlspecialchars');
      fputcsv($fh, $row);
    }
    fclose($fh);
    // Notify the filesystem of the size change
    $file->filesize = filesize($file->uri);
    $file->status = ~FILE_STATUS_PERMANENT;
    file_save($file);

    // Tell the user where we stuck it

    $this->messenger()->addMessage(
      t(
        'CSV file saved and available at. <a href="!url">!filename</a>.',
        [
          '!url' => file_create_url($file->uri),
          '!filename' => $filename,
        ]
      )
    );

    return $file->fid;
  }

  /**
   * Deal with different sized arrays for combining
   *
   * @param array $header
   *   a CSV header row
   * @param array $row
   *   a CSV data row
   *
   * @return array
   * combined array
   */
  public function islandora_multi_importer_array_combine_special(
    $header,
    $row
  ) {
    $headercount = count($header);
    $rowcount = count($row);
    if ($headercount > $rowcount) {
      $more = $headercount - $rowcount;
      for ($i = 0; $i < $more; $i++) {
        $row[] = "";
      }

    }
    else {
      if ($headercount < $rowcount) {
        // more fields than headers
        // Header wins always
        $row = array_slice($row, 0, $headercount);
      }
    }

    return array_combine($header, $row);
  }


  /**
   * Match different sized arrays.
   *
   * @param integer $headercount
   *   an array length to check against.
   * @param array $row
   *   a CSV data row
   *
   * @return array
   *  a resized to header size data row
   */
  public function array_equallyseize($headercount, $row = []) {

    $rowcount = count($row);
    if ($headercount > $rowcount) {
      $more = $headercount - $rowcount;
      for ($i = 0; $i < $more; $i++) {
        $row[] = "";
      }

    }
    else {
      if ($headercount < $rowcount) {
        // more fields than headers
        // Header wins always
        $row = array_slice($row, 0, $headercount);
      }
    }

    return $row;
  }
}