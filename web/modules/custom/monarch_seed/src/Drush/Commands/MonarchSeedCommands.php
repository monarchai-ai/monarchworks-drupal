<?php

declare(strict_types=1);

namespace Drupal\monarch_seed\Drush\Commands;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\default_content\Normalizer\ContentEntityNormalizerInterface;
use Drupal\user\EntityOwnerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Brings local Drupal in line with the monarch_seed content/ YAML.
 *
 * default_content 2.0.0-beta1 only creates entities (and fails with a unique
 * key violation on a UUID that already exists), so an edit to an existing
 * seed entity never reached local Drupal. This command creates entities that
 * are missing and updates existing ones whose YAML field values differ,
 * saving a new revision. Entities that already match are not saved, so a
 * second run reports "updated 0".
 */
final class MonarchSeedCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Fields that change on every save and are never compared or copied.
   */
  private const SKIPPED_FIELDS = [
    'revision_uid',
    'revision_user',
    'revision_log',
    'revision_log_message',
    'revision_timestamp',
    'revision_created',
    'changed',
  ];

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity.repository')]
    private readonly EntityRepositoryInterface $entityRepository,
    #[Autowire(service: 'default_content.content_entity_normalizer')]
    private readonly ContentEntityNormalizerInterface $normalizer,
    #[Autowire(service: 'extension.list.module')]
    private readonly ModuleExtensionList $moduleList,
    #[Autowire(service: 'account_switcher')]
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct();
  }

  /**
   * Create missing and update changed entities from monarch_seed content/.
   */
  #[CLI\Command(name: 'monarch-seed:sync')]
  #[CLI\Option(name: 'revision-log', description: 'Revision log message for updated entities.')]
  #[CLI\Usage(name: 'drush monarch-seed:sync', description: 'Apply the seed YAML to local Drupal and print "updated N, created M, unchanged K".')]
  public function sync(array $options = ['revision-log' => 'monarch-seed:sync']): void {
    $documents = $this->loadDocuments();
    $counts = ['updated' => 0, 'created' => 0, 'unchanged' => 0];

    $root_user = $this->entityTypeManager->getStorage('user')->load(1);
    $this->accountSwitcher->switchTo($root_user);
    try {
      foreach ($this->sortByDependencies($documents) as $uuid) {
        $data = $documents[$uuid];
        $entity_type_id = $data['_meta']['entity_type'];
        $existing = $this->entityRepository->loadEntityByUuid($entity_type_id, $uuid);

        if (!$existing instanceof ContentEntityInterface) {
          $entity = $this->normalizer->denormalize($data);
          $entity->enforceIsNew(TRUE);
          if ($entity instanceof EntityOwnerInterface && empty($entity->getOwnerId())) {
            $entity->setOwner($root_user);
          }
          $entity->setSyncing(TRUE);
          $entity->save();
          $counts['created']++;
          $this->logger()->notice(sprintf('created %s %s (%s)', $entity_type_id, $entity->id(), $entity->label()));
          continue;
        }

        $changed_fields = $this->changedFields($existing, $data);
        if (!$changed_fields) {
          $counts['unchanged']++;
          continue;
        }

        $seed = $this->normalizer->denormalize($data);
        foreach ($changed_fields as $field_name) {
          $existing->set($field_name, $seed->get($field_name)->getValue());
        }
        if ($existing instanceof RevisionableInterface && $existing->getEntityType()->isRevisionable()) {
          $existing->setNewRevision(TRUE);
          if ($existing instanceof RevisionLogInterface) {
            $existing->setRevisionLogMessage($options['revision-log']);
            $existing->setRevisionCreationTime(\Drupal::time()->getRequestTime());
            $existing->setRevisionUserId((int) $root_user->id());
          }
        }
        $existing->save();
        $counts['updated']++;
        $this->logger()->notice(sprintf('updated %s %s (%s): %s', $entity_type_id, $existing->id(), $existing->label(), implode(', ', $changed_fields)));
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $this->output()->writeln(sprintf('updated %d, created %d, unchanged %d', $counts['updated'], $counts['created'], $counts['unchanged']));
  }

  /**
   * Parses every YAML file under content/<entity_type>/, keyed by UUID.
   */
  private function loadDocuments(): array {
    $folder = DRUPAL_ROOT . '/' . $this->moduleList->getPath('monarch_seed') . '/content';
    $documents = [];
    foreach (glob($folder . '/*/*.yml') ?: [] as $file) {
      $data = Yaml::decode((string) file_get_contents($file));
      $uuid = $data['_meta']['uuid'] ?? NULL;
      if (!$uuid || empty($data['_meta']['entity_type'])) {
        throw new \RuntimeException(sprintf('Seed file %s has no _meta.uuid or _meta.entity_type.', $file));
      }
      if (isset($documents[$uuid])) {
        throw new \RuntimeException(sprintf('Seed UUID %s appears twice (second file: %s).', $uuid, $file));
      }
      $documents[$uuid] = $data;
    }
    return $documents;
  }

  /**
   * Orders UUIDs so each entity comes after the seed entities it depends on.
   */
  private function sortByDependencies(array $documents): array {
    $sorted = [];
    $visiting = [];
    $visit = function (string $uuid) use (&$visit, &$sorted, &$visiting, $documents): void {
      if (isset($sorted[$uuid]) || isset($visiting[$uuid]) || !isset($documents[$uuid])) {
        return;
      }
      $visiting[$uuid] = TRUE;
      foreach (array_keys($documents[$uuid]['_meta']['depends'] ?? []) as $dependency) {
        $visit((string) $dependency);
      }
      unset($visiting[$uuid]);
      $sorted[$uuid] = TRUE;
    };
    $uuids = array_keys($documents);
    sort($uuids);
    foreach ($uuids as $uuid) {
      $visit((string) $uuid);
    }
    return array_keys($sorted);
  }

  /**
   * Returns the YAML fields whose values differ from the stored entity.
   *
   * The stored entity is normalized with the same normalizer that exported
   * the YAML, so both sides are in the export format (references as UUIDs).
   */
  private function changedFields(ContentEntityInterface $entity, array $data): array {
    $current = $this->normalizer->normalize($entity)['default'] ?? [];
    $changed = [];
    foreach ($data['default'] ?? [] as $field_name => $values) {
      if (in_array($field_name, self::SKIPPED_FIELDS, TRUE) || !$entity->hasField($field_name)) {
        continue;
      }
      if ($this->canonical($current[$field_name] ?? []) !== $this->canonical($values)) {
        $changed[] = $field_name;
      }
    }
    return $changed;
  }

  /**
   * Canonical form for comparison: sorted keys, scalars as strings.
   */
  private function canonical(mixed $value): mixed {
    if (is_array($value)) {
      if (!array_is_list($value)) {
        ksort($value);
      }
      return array_map(fn ($item) => $this->canonical($item), $value);
    }
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    return $value === NULL ? NULL : (string) $value;
  }

}
