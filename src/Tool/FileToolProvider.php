<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Tool;

use Drupal\file\FileInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\drupal_mcp\Entity\EntityReadTools;
use Drupal\drupal_mcp\Mcp\ToolFamily;
use Drupal\drupal_mcp\Mcp\ToolProviderInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\file\FileUsage\FileUsageInterface;

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
    private readonly FileUsageInterface $fileUsage,
    private readonly EntityReadTools $reads,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function tools(): array {
    return [
      $this->reads->getTool(
        name: 'drupal_file_get',
        title: 'Read file metadata',
        description: 'Returns metadata and usage references for one existing file the caller may view: filename, MIME type, size, timestamps, and where it is referenced. Does not grant or expose download URLs.',
        family: ToolFamily::Files,
        entityTypeId: 'file',
        label: 'File',
        project: fn ($file, TokenAuthUser $caller): array => $this->projectFile($file, $caller),
      ),
    ];
  }

  /**
   * Projects one file's metadata and viewable usage references.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  private function projectFile(FileInterface $file, TokenAuthUser $caller): array {
    $usage = [];
    foreach ($this->fileUsage->listUsage($file) as $module => $targets) {
      foreach ($targets as $type => $entries) {
        if (!$this->entityTypeManager->hasDefinition($type)) {
          continue;
        }
        $storage = $this->entityTypeManager->getStorage($type);
        foreach ($entries as $targetId => $count) {
          $target = $storage->load($targetId);
          if ($target === NULL || !$target->access('view', $caller)) {
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
