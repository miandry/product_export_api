<?php

namespace Drupal\product_export_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
  private const MAX_LIMIT = 100;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns paginated products.
   */
  public function listProducts(Request $request) {
    try {
      $limit = (int) $request->query->get('limit', 50);
      $offset = (int) $request->query->get('offset', 0);
      $published = $request->query->get('published');
      $include_total = $request->query->get('include_total') === '1';
      $after_nid = max(0, (int) $request->query->get('after_nid', 0));

      if ($limit < 1) {
        $limit = 50;
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

      // Cursor pagination is more stable than large OFFSET on big datasets.
      if ($after_nid > 0) {
        $query->condition('nid', $after_nid, '>');
        $offset = 0;
      }

      $nids = $query->range($offset, $limit + 1)->execute();
      if (!is_array($nids)) {
        $nids = [$nids];
      }
      $nids = array_values(array_filter(array_map('intval', $nids)));

      $has_prev = $offset > 0 || $after_nid > 0;
      $has_next = count($nids) > $limit;
      if ($has_next) {
        array_pop($nids);
      }
      $count = count($nids);
      $total = NULL;
      if ($include_total) {
        $count_query = clone $query;
        $total = (int) $count_query->count()->execute();
      }

      $prev_offset = max(0, $offset - $limit);
      $next_offset = $offset + $limit;
      $next_after_nid = $count > 0 ? end($nids) : NULL;

      return new JsonResponse([
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'after_nid' => $after_nid,
        'next_after_nid' => $has_next ? $next_after_nid : NULL,
        'include_total' => $include_total,
        'ids' => $nids,
        'count' => $count,
        'has_prev' => $has_prev,
        'has_next' => $has_next,
        'prev_offset' => $has_prev ? $prev_offset : NULL,
        'next_offset' => $has_next ? $next_offset : NULL,
      ]);
    }
    catch (\Throwable $exception) {
      $this->getLogger('product_export_api')->error('Products API failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Products API failed.',
        'message' => $exception->getMessage(),
      ], 500);
    }
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
      return file_create_url((string) $uri);
    }
    catch (\Throwable $exception) {
      return '';
    }
  }

}
