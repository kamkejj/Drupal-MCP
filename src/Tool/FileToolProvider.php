<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\file\FileInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\drupal_mcp\Mcp\ToolDefinition;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Existing file metadata inspection.
 *
 * Returns only metadata and reference counts for files the caller may view.
 * File entity visibility does NOT authorize downloads; no stream URIs or
 * filesystem paths are exposed.
 */
final class FileToolProvider implements ToolProviderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly FileUsageInterface $fileUsage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      new ToolDefinition(
        name: 'drupal_file_get',
        title: 'Read file metadata',
        description: 'Returns metadata and usage references for one existing file the caller may view: filename, MIME type, size, timestamps, and where it is referenced. Does not grant or expose download URLs.',
        inputSchema: [
          'type' => 'object',
          'properties' => (object) ['id' => ['type' => 'integer', 'minimum' => 1]],
          'required' => ['id'],
          'additionalProperties' => FALSE,
        ],
        handler: fn (array $args): array => $this->fileGet((int) $args['id']),
        family: 'files',
        annotations: new ToolAnnotations(readOnlyHint: TRUE, idempotentHint: TRUE),
      ),
    ];
  }

  /**
   * Executes the operation.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function fileGet(int $id): array {
    $file = $this->entityTypeManager->getStorage('file')->load($id);
    if ($file === NULL) {
      throw new ToolCallException(sprintf('File %d does not exist.', $id));
    }
    $account = $this->currentUser->getAccount();
    if (!$file->access('view', $account)) {
      throw new ToolCallException(sprintf('File %d is not accessible.', $id));
    }
    \assert($file instanceof FileInterface);

    $usage = [];
    foreach ($this->fileUsage->listUsage($file) as $module => $targets) {
      foreach ($targets as $type => $entries) {
        if (!$this->entityTypeManager->hasDefinition($type)) {
          continue;
        }
        $storage = $this->entityTypeManager->getStorage($type);
        foreach ($entries as $targetId => $count) {
          $target = $storage->load($targetId);
          if ($target === NULL || !$target->access('view', $account)) {
            continue;
          }
          $usage[] = ['module' => $module, 'entity_type' => $type, 'id' => (string) $targetId, 'count' => (int) $count];
        }
      }
    }

    return [
      'id' => (int) $file->id(),
      'filename' => $file->getFilename(),
      'filemime' => $file->getMimeType(),
      'filesize' => (int) $file->getSize(),
      'created' => (int) $file->getCreatedTime(),
      'changed' => (int) $file->getChangedTime(),
      'status_permanent' => $file->isPermanent(),
      'usage' => $usage,
    ];
  }

}
