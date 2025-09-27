<?php

declare(strict_types=1);

namespace Drupal\elca_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
/**
 * Returns responses for elca_api routes.
 */
final class ElcaApiController extends ControllerBase {

  public function __construct(private readonly EntityTypeManagerInterface $etm) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Builds the response.
   */
  public function getEmployee(): CacheableJsonResponse {
    $storage = $this->etm->getStorage('node');
    // Load published employees; add filters as you like.
    $nids = $storage->getQuery()
      ->condition('type', 'employee')
      ->condition('status', NodeInterface::PUBLISHED)
      ->range(0, 200)
      ->accessCheck(FALSE)
      ->execute();

    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadMultiple($nids);
    $out = [];
    foreach ($nodes as $node) {
      $out[] = [
        'id' => $node->uuid(),
        'title' => $node->label(),
        'name' => $node->get('field_name')->value,
        'email' =>  $node->get('field_email')->value,
        'visa' => $node->get('field_visa')->value,
        'nationality' =>  $node->get('field_nationality')->value,
        'position' => $this->refTermLabel($node, 'field_position'),
        'level' => $this->refTermLabel($node, 'field_level'),
        'team' => $node->get('field_team')->value,
        // multi
        'supervisor' => $node->get('field_supervisor')->value,
        // node/user
        'medical' => $this->bool($node, 'field_medical'),
        'certificate' => $this->bool($node, 'field_certificate_of_character'),
        'cv_resume' => $this->bool($node, 'field_cv_resume'),
        'nid_required' => $this->bool($node, 'field_nid'),
        'hardware' => $this->bool($node, 'field_hardwares'),
        'joining_date' => $this->date($node, 'field_datejoined'),
        'elcademy' => $this->bool($node, 'field_elcademy'),
        'visa_created' => $this->bool($node, 'field_visa_created'),
        'gift_pack' => $this->bool($node, 'field_gift_pack'),
        'deparment_lead' => $this->refTermLabel($node, 'field_dl'),
        'parking_access' => $this->bool($node, 'field_parking_access'),
        'confirm_first_day' => $this->bool($node, 'field_first_day_confirmation'),
        'prepared_document' => $this->bool($node, 'field_prepared_document'),
        'liaise_with_caterer' => $this->bool($node, 'field_liaise_with_caterer'),
        'lunch_invitation' => $this->bool($node, 'field_lunch_invitation'),
        'welcoming_mail' => $this->bool($node, 'field_welcoming_mail'),
        'visit_tour' => $this->bool($node, 'field_visit_tour'),
        'confirmation_letter' => $this->bool($node, 'field_confirmation_letter'),
        'employee_status' => $this->bool($node, 'field_employee_status'),
        'scanned_docs' => $this->bool($node, 'field_scan_docs'),
        'photo_permission' => $this->bool($node, 'field_photo_permission'),
        'List_of_joiners_leavers' => $this->bool($node, 'field_joiners_and_leavers'),
        // Y-m-d
        'source' => $node->get('field_source')->value,
      ];
    }

    $response = new CacheableJsonResponse($out);
    // Good cache metadata.
    $response->addCacheableDependency($this->entityTypeManager()
      ->getDefinition('node'));
    foreach ($nodes as $n) {
      $response->addCacheableDependency($n);
    }
    return $response;
  }

  private function refTermLabel(NodeInterface $n, string $field): ?string {
    if (!$n->hasField($field) || $n->get($field)->isEmpty()) {
      return null;
    }
    $entity = $n->get($field)->entity; // first item
    return $entity ? $entity->label() : null;
  }

  private function bool(NodeInterface $n, string $field): ?bool {
    $i = $n->get($field);
    return $i->isEmpty() ? NULL : (bool) $i->value;
  }

  private function date(NodeInterface $n, string $field): ?string {
    $v = $n->get($field)->value;
    if (!$v) {
      return NULL;
    }
    // If stored as datetime (YYYY-MM-DD), return as-is; adapt as needed.
    try {
      return (new \DateTime($v))->format('Y-m-d');
    }
    catch (\Throwable) {
      return (string) $v;
    }
  }

}
