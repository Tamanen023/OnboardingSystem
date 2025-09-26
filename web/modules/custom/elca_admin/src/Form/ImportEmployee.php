<?php

declare(strict_types=1);

namespace Drupal\elca_admin\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Import Employees from spreadsheet (XLSX/XLS/CSV) and create nodes.
 */
final class ImportEmployee extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected LanguageManagerInterface $languageManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FileSystemInterface $fileSystem,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('language_manager'),
      $container->get('module_handler'),
      $container->get('entity_field.manager'),
      $container->get('file_system'),
    );
  }

  public function getFormId(): string {
    return 'elca_admin_import_employee';
  }

  protected function getEditableConfigNames(): array {
    return ['elca_admin.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('elca_admin.settings');

    $form['help'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'Upload an Excel (.xlsx/.xls) or CSV file. The first row contains headers. '
        . 'Headers can be either <b>field labels</b> (e.g., "Name") or <b>machine names</b> (e.g., <code>field_name</code>). '
        . 'Unknown columns are ignored. Multi-value cells use the separator (default <code>|</code>). '
        . 'Excel dates and TRUE/FALSE booleans are supported.'
      ),
    ];

    $form['csv_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Spreadsheet file'),
      '#description' => $this->t('Upload .xlsx, .xls, or .csv (max 10MB).'),
      '#upload_location' => 'private://employee_imports',
//      '#upload_validators' => [
//        'file_validate_extensions' => ['xlsx xls csv'],
//        'file_validate_size' => [10 * 1024 * 1024],
//      ],
      '#multiple' => FALSE,
      '#required' => TRUE,
      '#default_value' => $config->get('last_import_fid') ? [$config->get('last_import_fid')] : NULL,
      '#attributes' => ['accept' => '.xlsx,.xls,.csv,text/csv'],
    ];

    $form['content_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content type machine name'),
      '#default_value' => $config->get('employee_bundle') ?? 'employee',
      '#required' => TRUE,
      '#description' => $this->t('Usually "employee".'),
    ];

    $form['delimiter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSV delimiter (CSV only)'),
      '#default_value' => $config->get('csv_delimiter') ?? ',',
      '#size' => 2,
      '#maxlength' => 2,
    ];

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run (validate only, do not create content)'),
      '#default_value' => TRUE,
    ];

    $form['multi_value_sep'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Multi-value separator'),
      '#default_value' => $config->get('multi_value_sep') ?? '|',
      '#size' => 2,
      '#maxlength' => 4,
      '#description' => $this->t('Used to split values for multi-value fields (e.g., taxonomy references).'),
    ];

    $form['publish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish created nodes'),
      '#default_value' => TRUE,
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $fids = (array) $form_state->getValue('csv_file');
    if (empty($fids)) {
      $form_state->setErrorByName('csv_file', $this->t('Please upload a file.'));
      return;
    }
    $file = File::load($fids[0]);
    if (!$file) {
      $form_state->setErrorByName('csv_file', $this->t('Uploaded file not found.'));
      return;
    }
    $uri = $file->getFileUri();
    $ext = mb_strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx','xls','csv'], TRUE)) {
      $form_state->setErrorByName('csv_file', $this->t('Unsupported file type. Use .xlsx, .xls, or .csv.'));
    }
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable('elca_admin.settings');

    $fids = (array) $form_state->getValue('csv_file');
    /** @var \Drupal\file\Entity\File $file */
    $file = File::load($fids[0]);
    $uri = $file->getFileUri();
    $ext = mb_strtolower(pathinfo($uri, PATHINFO_EXTENSION));

    // Make the file permanent and track usage.
    $file->setPermanent();
    $file->save();
    \Drupal::service('file.usage')->add($file, 'elca_admin', 'file', $file->id());

    $bundle   = (string) $form_state->getValue('content_type');
    $delimiter = (string) $form_state->getValue('delimiter') ?: ',';
    $dry_run  = (bool) $form_state->getValue('dry_run');
    $multi_sep = (string) $form_state->getValue('multi_value_sep') ?: '|';
    $publish  = (bool) $form_state->getValue('publish');

    // Persist a few prefs.
    $config->set('last_import_fid', $file->id())
      ->set('employee_bundle', $bundle)
      ->set('csv_delimiter', $delimiter)
      ->set('multi_value_sep', $multi_sep)
      ->save();

    // Load bundle fields (base + configurable).
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);

    // Build lookup maps:
    // - by machine name (lowercased)
    // - by label (lowercased, whitespace-normalized)
    $machine_map = [];
    $label_map = [];
    foreach ($fields as $machine => $def) {
      $machine_map[mb_strtolower($machine)] = $machine;
      $label_map[$this->norm((string) $def->getLabel())] = $machine;
    }
    // Support base title.
    $machine_map['title'] = 'title';
    $label_map[$this->norm('Title')] = 'title';

    // Read spreadsheet into rows (assoc arrays keyed by header).
    try {
      $rows = $this->readSpreadsheet($uri, $ext, $delimiter);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed reading spreadsheet: @m', ['@m' => $e->getMessage()]));
      return;
    }

    if (empty($rows)) {
      $this->messenger()->addWarning($this->t('No data rows found.'));
      return;
    }

    // Build a header->machine map once, based on the first row's keys.
    $header_to_machine = [];
    $first = $rows[0] ?? [];
    foreach (array_keys($first) as $header) {
      if ($header === '' || $header === null) { continue; }
      $h_machine = mb_strtolower(trim((string) $header)); // user may provide machine name
      $h_label   = $this->norm((string) $header);         // or a human label
      $machine = $machine_map[$h_machine] ?? $label_map[$h_label] ?? null;
      if ($machine) {
        $header_to_machine[$header] = $machine;
      }
    }

    // Debug what will/ won’t map.
    $mapped  = array_values($header_to_machine);
    $ignored = array_diff(array_keys($first), array_keys($header_to_machine));
    if ($mapped) {
      $this->messenger()->addStatus($this->t('Mapped to fields: @h', ['@h' => implode(', ', $mapped)]));
    }
    if ($ignored) {
      $this->messenger()->addWarning($this->t('Ignored headers (no matching field): @h', ['@h' => implode(', ', $ignored)]));
    }

    $created = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
      // Normalize the row to machine-name keys only.
      $normalized = [];
      foreach ($row as $header => $value) {
        if (isset($header_to_machine[$header])) {
          $normalized[$header_to_machine[$header]] = $value;
        }
      }

      // Build node values.
      $values = [
        'type' => $bundle,
        'langcode' => $this->languageManager->getDefaultLanguage()->getId(),
        'status' => $publish ? Node::PUBLISHED : Node::NOT_PUBLISHED,
      ];

      // Title (direct or guessed from common name-like fields).
      if (isset($normalized['title']) && $normalized['title'] !== '') {
        $values['title'] = (string) $normalized['title'];
      }
      if (empty($values['title'])) {
        $values['title'] = $this->guessTitle($normalized) ?? 'Employee';
      }

      // Map the rest where the header matched a field machine name.
      foreach ($normalized as $machine => $rawValue) {
        if (in_array($machine, ['type', 'langcode', 'status', 'uid', 'created', 'changed', 'title'], TRUE)) {
          continue;
        }
        if (!isset($fields[$machine])) {
          continue;
        }
        $field = $fields[$machine];
        $values[$machine] = $this->coerceFieldValue($field->getType(), $rawValue, $multi_sep, $field->getSettings());
      }

      try {
        $node = Node::create($values);
        $violations = $node->validate();
        if (count($violations)) {
          $skipped++;
          $errors[] = $this->formatViolations($index + 2, $violations);
          continue;
        }
        if (!$dry_run) {
          $node->save();
          $created++;
        }
      }
      catch (\Throwable $e) {
        $skipped++;
        $errors[] = $this->t('Row @r: @m', ['@r' => $index + 2, '@m' => $e->getMessage()]);
      }
    }

    if ($dry_run) {
      $this->messenger()->addStatus($this->t('Dry run complete. Rows checked: @t. Would create: @c. Skipped: @s.', [
        '@t' => count($rows),
        '@c' => $created,
        '@s' => $skipped,
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Import complete. Created: @c. Skipped: @s.', [
        '@c' => $created,
        '@s' => $skipped,
      ]));
    }

    foreach ($errors as $msg) {
      $this->messenger()->addWarning($msg);
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Normalize labels: lowercase, trim, collapse spaces & punctuation.
   */
  private function norm(string $s): string {
    $s = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($s)) ?? $s;
    return strtolower(trim($s));
  }

  /**
   * Read spreadsheet rows into an array of associative arrays keyed by header.
   *
   * @param string $uri
   * @param string $ext
   * @param string $csv_delimiter
   * @return array<int, array<string, mixed>>
   */
  private function readSpreadsheet(string $uri, string $ext, string $csv_delimiter = ','): array {
    $real = $this->fileSystem->realpath($uri);
    if ($ext === 'csv') {
      $reader = IOFactory::createReader('Csv');
      $reader->setDelimiter($csv_delimiter);
      $reader->setEnclosure('"');
      $reader->setSheetIndex(0);
    }
    else {
      $reader = IOFactory::createReaderForFile($real);
      $reader->setReadDataOnly(true);
    }

    $spreadsheet = $reader->load($real);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    if (empty($rows)) {
      return [];
    }

    // First row = header.
    $headerRow = array_shift($rows);
    $headers = [];
    foreach ($headerRow as $col => $label) {
      $headers[] = $label === null ? '' : trim((string) $label);
    }

    // Build associative rows keyed by headers.
    $out = [];
    foreach ($rows as $r) {
      $assoc = [];
      $i = 0;
      foreach ($r as $col => $value) {
        $key = $headers[$i] ?? '';
        if ($key !== '') {
          $assoc[$key] = $value;
        }
        $i++;
      }
      if (!empty($assoc)) {
        $out[] = $assoc;
      }
    }
    return $out;
  }

  /**
   * Coerce a raw value into a field item (or array of items) based on field type.
   *
   * @param string $fieldType
   * @param mixed $raw
   * @param string $multiSep
   * @param array $settings
   * @return mixed
   */
  private function coerceFieldValue(string $fieldType, mixed $raw, string $multiSep, array $settings): mixed {
    if ($raw === null) {
      return null;
    }
    if (is_string($raw)) {
      $raw = trim($raw);
    }

    // Split multi-values if separator found.
    $is_multi = is_string($raw) && str_contains($raw, $multiSep);
    $vals = $is_multi ? array_map('trim', explode($multiSep, (string) $raw)) : [$raw];

    $mapValue = function ($val) use ($fieldType, $settings) {
      if ($val === '' || $val === null) {
        return null;
      }

      switch ($fieldType) {
        case 'string':
        case 'string_long':
        case 'text':
        case 'text_long':
        case 'text_with_summary':
          return ['value' => (string) $val];

        case 'integer':
        case 'float':
        case 'decimal':
          return is_numeric($val) ? (0 + $val) : null;

        case 'boolean':
        case 'list_boolean':
          $v = is_string($val) ? strtolower($val) : $val;
          $truthy = ['1','true','yes','y','on'];
          return in_array((string) $v, $truthy, true) ? 1 : 0;

        case 'datetime':
        case 'timestamp':
          // Support Excel serial numbers (e.g., 45235).
          if (is_numeric($val)) {
            try {
              $dt = XlsDate::excelToDateTimeObject((float) $val);
              return $fieldType === 'timestamp'
                ? $dt->getTimestamp()
                : $dt->format('Y-m-d');
            } catch (\Throwable) { /* fallthrough */ }
          }
          // Parse common date strings.
          try {
            $dt = new \DateTime((string) $val);
            return $fieldType === 'timestamp'
              ? $dt->getTimestamp()
              : $dt->format('Y-m-d');
          } catch (\Throwable) {
            return null;
          }

        case 'list_string':
          // Expect the KEY of the allowed value.
          return (string) $val;

        case 'entity_reference':
          // Accept numeric IDs for any target type.
          if (is_numeric($val)) {
            return ['target_id' => (int) $val];
          }
          // Resolve by label/email/title based on target type and handler settings.
          $target_type = $settings['target_type'] ?? '';
          $etm = \Drupal::entityTypeManager();

          if ($target_type === 'taxonomy_term') {
            $bundles = $settings['handler_settings']['target_bundles'] ?? [];
            $tStorage = $etm->getStorage('taxonomy_term');
            $name = trim((string) $val);
            foreach (array_keys($bundles) as $vid) {
              $found = $tStorage->loadByProperties(['vid' => $vid, 'name' => $name]);
              if ($found) {
                return ['target_id' => reset($found)->id()];
              }
            }
            return null; // (optional: auto-create here)
          }

          if ($target_type === 'user') {
            // Resolve by email.
            $uStorage = $etm->getStorage('user');
            $found = $uStorage->loadByProperties(['mail' => trim((string) $val)]);
            if ($found) {
              return ['target_id' => reset($found)->id()];
            }
            return null;
          }

          if ($target_type === 'node') {
            // Resolve by title within allowed bundle (first allowed).
            $bundles = $settings['handler_settings']['target_bundles'] ?? [];
            $nStorage = $etm->getStorage('node');
            $props = ['title' => trim((string) $val)];
            if (!empty($bundles)) {
              $props['type'] = reset($bundles);
            }
            $found = $nStorage->loadByProperties($props);
            if ($found) {
              return ['target_id' => reset($found)->id()];
            }
            return null;
          }

          return null;

        case 'link':
          $s = (string) $val;
          if (str_contains($s, '|')) {
            [$url, $title] = array_map('trim', explode('|', $s, 2));
            return ['uri' => $url, 'title' => $title];
          }
          return ['uri' => $s];

        case 'email':
          return (string) $val;

        default:
          // Fallback to plain string.
          return is_scalar($val) ? (string) $val : null;
      }
    };

    $coerced = array_values(array_filter(array_map($mapValue, $vals), fn($v) => $v !== null));

    if (count($coerced) === 0) {
      return null;
    }
    if (count($coerced) === 1) {
      return $coerced[0];
    }
    return $coerced;
  }

  /**
   * Guess a title if none provided.
   */
  private function guessTitle(array $normalized): ?string {
    foreach (['name','employee_name','full_name','field_name','field_full_name'] as $k) {
      if (!empty($normalized[$k])) {
        return (string) $normalized[$k];
      }
    }
    return null;
  }

  private function formatViolations(
    int $rowNumber,
    ConstraintViolationListInterface $violations
  ): TranslatableMarkup {
    $msgs = [];
    /** @var ConstraintViolationInterface $v */
    foreach ($violations as $v) {
      $msgs[] = $v->getMessage();
    }

    return $this->t('Row @r validation failed: @m', [
      '@r' => $rowNumber,
      '@m' => implode('; ', $msgs),
    ]);
  }

}
