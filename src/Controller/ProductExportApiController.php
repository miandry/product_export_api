<?php

namespace Drupal\product_export_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for product export API.
 */
class ProductExportApiController extends ControllerBase {

  /**
   * Max page size.
   */
  private const MAX_LIMIT = 500;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * File URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FileUrlGeneratorInterface $file_url_generator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_url_generator')
    );
  }

  /**
   * Returns paginated products.
   */
  public function listProducts(Request $request) {
    $limit = (int) $request->query->get('limit', 100);
    $offset = (int) $request->query->get('offset', 0);
    $published = $request->query->get('published');

    if ($limit < 1) {
      $limit = 100;
    }
    $limit = min($limit, static::MAX_LIMIT);
    if ($offset < 0) {
      $offset = 0;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'produit')
      ->sort('nid', 'ASC');

    if ($published !== NULL && ($published === '0' || $published === '1')) {
      $query->condition('status', (int) $published);
    }

    $total = (int) $query->count()->execute();
    $nids = $query->range($offset, $limit)->execute();
    $nodes = $storage->loadMultiple($nids);

    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $items[] = $this->normalizeProduct($node);
    }

    return new JsonResponse([
      'total' => $total,
      'limit' => $limit,
      'offset' => $offset,
      'count' => count($items),
      'items' => $items,
    ]);
  }

  /**
   * Returns one product by nid.
   */
  public function productDetail($nid) {
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'produit') {
      return new JsonResponse(['error' => 'Product not found.'], 404);
    }

    return new JsonResponse($this->normalizeProduct($node));
  }

  /**
   * Normalizes product node and all fields.
   */
  protected function normalizeProduct(NodeInterface $node) {
    $data = [
      'nid' => (int) $node->id(),
      'uuid' => (string) $node->uuid(),
      'type' => (string) $node->bundle(),
      'langcode' => (string) $node->language()->getId(),
      'title' => (string) $node->label(),
      'status' => (int) $node->isPublished(),
      'created' => (int) $node->getCreatedTime(),
      'changed' => (int) $node->getChangedTime(),
      'fields' => [],
    ];

    foreach ($node->getFields() as $field_name => $field) {
      if (in_array($field_name, ['nid', 'vid', 'type', 'uuid', 'langcode', 'revision_timestamp', 'revision_uid', 'revision_log', 'status', 'uid', 'title', 'created', 'changed', 'promote', 'sticky', 'default_langcode', 'revision_translation_affected'], TRUE)) {
        continue;
      }
      $data['fields'][$field_name] = $this->normalizeField($field);
    }

    return $data;
  }

  /**
   * Normalizes a field value for API output.
   */
  protected function normalizeField($field) {
    if ($field->isEmpty()) {
      return [];
    }

    $definition = $field->getFieldDefinition();
    $field_type = $definition->getType();

    if (in_array($field_type, ['image', 'file'], TRUE)) {
      $items = [];
      foreach ($field->referencedEntities() as $file) {
        $items[] = [
          'fid' => (int) $file->id(),
          'filename' => (string) $file->getFilename(),
          'uri' => (string) $file->getFileUri(),
          'mime' => (string) $file->getMimeType(),
          'size' => (int) $file->getSize(),
          'url' => $this->buildFileUrl($file->getFileUri()),
        ];
      }
      return $items;
    }

    if ($field_type === 'entity_reference') {
      $items = [];
      foreach ($field->referencedEntities() as $entity) {
        $items[] = [
          'target_id' => (int) $entity->id(),
          'type' => (string) $entity->getEntityTypeId(),
          'bundle' => method_exists($entity, 'bundle') ? (string) $entity->bundle() : '',
          'label' => method_exists($entity, 'label') ? (string) $entity->label() : '',
        ];
      }
      return $items;
    }

    return $field->getValue();
  }

  /**
   * Builds absolute URL for file URI.
   */
  protected function buildFileUrl($uri) {
    try {
      return $this->fileUrlGenerator->generateAbsoluteString((string) $uri);
    }
    catch (\Throwable $exception) {
      return '';
    }
  }

}
