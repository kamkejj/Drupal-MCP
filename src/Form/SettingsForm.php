<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\drupal_mcp\Diagnostics\ApprovedConfigProjections;
use Drupal\drupal_mcp\Diagnostics\ApprovedDatabaseSurfaces;

/**
 * Settings for the Drupal MCP endpoint.
 *
 * Database credentials, key paths, and executable paths are deliberately not
 * configurable here: they are deployment-controlled and never tool-editable.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_mcp_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['drupal_mcp.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drupal_mcp.settings');

    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication and transport'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['auth']['read_scope'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Required read scope'),
      '#description' => $this->t('The OAuth scope required by MCP read operations. It must exist as a Simple OAuth scope and be grantable to connecting clients.'),
      '#default_value' => $config->get('read_scope'),
      '#required' => TRUE,
    ];
    $form['auth']['write_scope'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Required write scope'),
      '#description' => $this->t('The OAuth scope required by MCP write operations. It must exist as a Simple OAuth scope and be grantable to connecting clients.'),
      '#default_value' => $config->get('write_scope') ?: 'mcp:write',
      '#required' => TRUE,
    ];
    $form['auth']['resource_binding'] = [
      '#type' => 'select',
      '#title' => $this->t('Resource audience binding policy'),
      '#description' => $this->t('"Require" rejects tokens that are not bound to this canonical /mcp resource. "Audit" is a development fallback that accepts unbound tokens and records an audit entry. Tokens carrying a resource claim for another resource are always rejected.'),
      '#options' => [
        'audit' => $this->t('Audit (development fallback)'),
        'require' => $this->t('Require binding (compliant)'),
      ],
      '#default_value' => $config->get('resource_binding') ?: 'require',
      '#required' => TRUE,
    ];
    $form['auth']['resource_uri'] = [
      '#type' => 'url',
      '#title' => $this->t('Canonical MCP resource URI'),
      '#description' => $this->t('The single absolute HTTPS URI used for OAuth resource binding. It must end with /mcp and must not contain credentials, a query, or a fragment.'),
      '#default_value' => $config->get('resource_uri'),
      '#required' => TRUE,
    ];
    $form['auth']['allowed_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed hosts'),
      '#description' => $this->t('One host per line that may reach the MCP endpoint (Host header validation, DNS-rebinding protection). Leave empty to use the request host after Drupal trusted-host validation.'),
      '#default_value' => implode("\n", $config->get('allowed_hosts') ?? []),
    ];
    $form['auth']['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed CORS origins'),
      '#description' => $this->t('One absolute origin per line for browser-based MCP clients (for example https://clerk.example). Leave empty to disallow cross-origin browser access; non-browser clients are unaffected.'),
      '#default_value' => implode("\n", $config->get('allowed_origins') ?? []),
    ];

    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['limits']['max_body_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum request body size (bytes)'),
      '#min' => 1024,
      '#default_value' => $config->get('max_body_bytes'),
      '#required' => TRUE,
    ];
    $form['limits']['session_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Session time-to-live (seconds)'),
      '#description' => $this->t('Handshake-era MCP sessions are stored in the database and expire after this period of inactivity.'),
      '#min' => 60,
      '#default_value' => $config->get('session_ttl'),
      '#required' => TRUE,
    ];
    $form['limits']['pagination_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum page size for list tools'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $config->get('pagination_limit'),
      '#required' => TRUE,
    ];
    $form['limits']['flood_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per window, per user and client'),
      '#min' => 1,
      '#default_value' => $config->get('flood_limit'),
      '#required' => TRUE,
    ];
    $form['limits']['flood_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit window (seconds)'),
      '#min' => 60,
      '#default_value' => $config->get('flood_window'),
      '#required' => TRUE,
    ];

    $form['families'] = [
      '#type' => 'details',
      '#title' => $this->t('Tool families'),
      '#description' => $this->t('Disabled families hide their tools and make direct calls fail. Diagnostic families additionally require the "administer site configuration" permission.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $familyLabels = [
      'site' => $this->t('Site and caller identity'),
      'entity_schema' => $this->t('Entity type and schema discovery'),
      'content' => $this->t('Content reads'),
      'taxonomy' => $this->t('Taxonomy inspection'),
      'blocks' => $this->t('Reusable block content'),
      'menus' => $this->t('Menus and aliases'),
      'aliases' => $this->t('Path aliases'),
      'files' => $this->t('File metadata'),
      'users' => $this->t('User inspection'),
      'drush' => $this->t('Drush diagnostics (privileged)'),
      'database' => $this->t('Database inspection (privileged)'),
    ];
    foreach ($familyLabels as $key => $label) {
      $form['families'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => (bool) ($config->get('families')[$key] ?? FALSE),
      ];
    }
    $form['families']['node_bundles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Enabled node bundles (machine names, one per line)'),
      '#description' => $this->t('Content read tools only expose explicitly listed node types. An empty list is valid and means no content is exposed. New or renamed types are not exposed until added here.'),
      '#default_value' => implode("\n", $config->get('node_bundles') ?? []),
    ];
    $form['families']['vocabularies'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Enabled vocabularies (machine names, one per line)'),
      '#description' => $this->t('Taxonomy term reads only expose explicitly listed vocabularies. An empty list is valid.'),
      '#default_value' => implode("\n", $config->get('vocabularies') ?? []),
    ];
    $form['mutations'] = [
      '#type' => 'details',
      '#title' => $this->t('Mutations (restricted)'),
      '#description' => $this->t('Mutations are disabled by default and always require OAuth write scope, MCP write permission, native entity access, and field access.'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];
    $form['mutations']['taxonomy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable taxonomy term mutations'),
      '#default_value' => (bool) $config->get('mutation_families.taxonomy'),
    ];
    $enabledVocabularies = (array) ($config->get('vocabularies') ?? []);
    $form['mutations']['writable_vocabularies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Writable vocabularies'),
      '#description' => $this->t('Only vocabularies enabled for reads can be selected. Selection does not bypass native access checks.'),
      '#options' => array_combine($enabledVocabularies, $enabledVocabularies) ?: [],
      '#default_value' => array_values(array_intersect(
        (array) ($config->get('writable_vocabularies') ?? []),
        $enabledVocabularies,
      )),
    ];
    $form['mutations']['destructive_taxonomy_terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable destructive taxonomy term deletion'),
      '#description' => $this->t('Warning: permanently deletes eligible, unreferenced terms. This is separately disabled by default and still requires all write and native delete checks.'),
      '#default_value' => (bool) $config->get('destructive_mutations.taxonomy_terms'),
    ];
    $form['mutations']['idempotency_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Idempotency retention (seconds)'),
      '#min' => 60,
      '#max' => 604800,
      '#default_value' => $config->get('idempotency_ttl') ?: 86400,
      '#required' => TRUE,
    ];

    $form['diagnostics'] = [
      '#type' => 'details',
      '#title' => $this->t('Privileged diagnostics'),
      '#description' => $this->t('Drush diagnostics fail closed: no command runs until its ID is approved here, and callers with "administer site configuration" only. The command set, arguments, output projection, and runtime bounds are fixed by the module; this list only switches them on.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $drushOptions = [
      'status' => $this->t('status (versions, bootstrap, database state; credentials stripped)'),
      'pm:list' => $this->t('pm:list (installed projects and themes)'),
      'config:get' => $this->t('config:get (only for the configuration names approved below)'),
    ];
    $approvedDrush = (array) ($config->get('drush_commands') ?? []);
    foreach ($drushOptions as $id => $label) {
      $form['diagnostics']['drush_commands'][$id] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => \in_array($id, $approvedDrush, TRUE),
      ];
    }
    $form['diagnostics']['drush_config_names'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Configuration projections allowed for config:get'),
      '#description' => $this->t('Only the listed server-defined properties are returned. Configuration objects and properties cannot be added through this form.'),
      '#options' => ApprovedConfigProjections::options(),
      '#default_value' => array_values(array_intersect(
        (array) ($config->get('drush_config_names') ?? []),
        array_keys(ApprovedConfigProjections::options()),
      )),
    ];
    $approvedSurfaces = (array) ($config->get('database_surfaces') ?? []);
    $form['diagnostics']['database_surfaces'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Approved database surfaces'),
      '#description' => $this->t('Only curated views selected here can be described or queried, and only through the separately configured SELECT-only database principal.'),
      '#options' => array_map(
        fn ($surface) => $this->t('@label — @description', [
          '@label' => $surface->label,
          '@description' => $surface->description,
        ]),
        ApprovedDatabaseSurfaces::all(),
      ),
      '#default_value' => $approvedSurfaces,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('drupal_mcp.settings');

    $lines = fn (string $value): array => array_values(array_filter(array_map('trim', explode("\n", (string) $value))));

    $families = [];
    foreach ([
      'site',
      'entity_schema',
      'content',
      'taxonomy',
      'blocks',
      'menus',
      'aliases',
      'files',
      'users',
      'drush',
      'database',
    ] as $key) {
      $families[$key] = (bool) $form_state->getValue(['families', $key]);
    }

    $config
      ->set('read_scope', trim((string) $form_state->getValue(['auth', 'read_scope'])))
      ->set('write_scope', trim((string) $form_state->getValue(['auth', 'write_scope'])))
      ->set('resource_binding', (string) $form_state->getValue(['auth', 'resource_binding']))
      ->set('resource_uri', rtrim(trim((string) $form_state->getValue(['auth', 'resource_uri'])), '/'))
      ->set('allowed_hosts', $lines((string) $form_state->getValue(['auth', 'allowed_hosts'])))
      ->set('allowed_origins', $lines((string) $form_state->getValue(['auth', 'allowed_origins'])))
      ->set('max_body_bytes', (int) $form_state->getValue(['limits', 'max_body_bytes']))
      ->set('session_ttl', (int) $form_state->getValue(['limits', 'session_ttl']))
      ->set('pagination_limit', (int) $form_state->getValue(['limits', 'pagination_limit']))
      ->set('flood_limit', (int) $form_state->getValue(['limits', 'flood_limit']))
      ->set('flood_window', (int) $form_state->getValue(['limits', 'flood_window']))
      ->set('families', $families)
      ->set('node_bundles', $lines((string) $form_state->getValue(['families', 'node_bundles'])))
      ->set('vocabularies', $lines((string) $form_state->getValue(['families', 'vocabularies'])))
      ->set('mutation_families.taxonomy', (bool) $form_state->getValue(['mutations', 'taxonomy']))
      ->set('writable_vocabularies', array_values(array_filter(
        (array) $form_state->getValue(['mutations', 'writable_vocabularies'])
      )))
      ->set(
        'destructive_mutations.taxonomy_terms',
        (bool) $form_state->getValue(['mutations', 'destructive_taxonomy_terms'])
      )
      ->set('idempotency_ttl', (int) $form_state->getValue(['mutations', 'idempotency_ttl']))
      ->set('drush_commands', array_values(array_keys(array_filter([
        'status' => (bool) $form_state->getValue(['diagnostics', 'drush_commands', 'status']),
        'pm:list' => (bool) $form_state->getValue(['diagnostics', 'drush_commands', 'pm:list']),
        'config:get' => (bool) $form_state->getValue(['diagnostics', 'drush_commands', 'config:get']),
      ]))))
      ->set('drush_config_names', array_values(array_filter(
        (array) $form_state->getValue(['diagnostics', 'drush_config_names'])
      )))
      ->set('database_surfaces', array_values(array_filter(
        (array) $form_state->getValue(['diagnostics', 'database_surfaces'])
      )))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $resourceUri = rtrim(trim((string) $form_state->getValue(['auth', 'resource_uri'])), '/');
    $parts = parse_url($resourceUri);
    if (!\is_array($parts)
      || ($parts['scheme'] ?? '') !== 'https'
      || empty($parts['host'])
      || ($parts['path'] ?? '') !== '/mcp'
      || isset($parts['user'])
      || isset($parts['pass'])
      || isset($parts['query'])
      || isset($parts['fragment'])) {
      $form_state->setErrorByName('auth][resource_uri', $this->t('Enter an absolute HTTPS URI ending in /mcp, without credentials, a query, or a fragment.'));
    }

    $allowedHosts = array_values(array_filter(array_map(
      'trim',
      explode("\n", (string) $form_state->getValue(['auth', 'allowed_hosts']))
    )));
    if ($allowedHosts === []) {
      $form_state->setErrorByName('auth][allowed_hosts', $this->t('At least one allowed host is required.'));
    }
    elseif (\is_array($parts) && !\in_array(strtolower((string) ($parts['host'] ?? '')), array_map('strtolower', $allowedHosts), TRUE)) {
      $form_state->setErrorByName('auth][allowed_hosts', $this->t('Allowed hosts must include the canonical resource URI host.'));
    }
  }

}
