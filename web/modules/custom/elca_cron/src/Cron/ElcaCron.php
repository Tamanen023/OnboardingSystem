<?php

namespace Drupal\elca_cron\Cron;

use Drupal\Core\CronInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

final class ElcaCron implements CronInterface {

  public function __construct(
    private EntityTypeManagerInterface $etm,
    private QueueFactory $queueFactory,
    private Connection $db,
    private TimeInterface $time,
    private LoggerInterface $logger,
  ) {}

  public function run(): void {
    // Add as many milestones as you like.
    $this->scheduleAnniversary('P3M', 'employee_three_month');
    $this->scheduleAnniversary('P6M', 'employee_six_month');
  }

  /**
   * Enqueue employees whose (join + $period) <= now.
   * Works for date-only and datetime fields. Idempotent via elca_cron_mail_log.
   */
  private function scheduleAnniversary(string $period, string $mail_key): void {
    // === Adjust if your machine names differ ===
    $bundle      = 'employee';
    $join_field  = 'field_datejoined';
    $email_field = 'field_email';
    // ===========================================

    $now = $this->time->getRequestTime();
    $storage = $this->etm->getStorage('node');

    // Load all published employees having a join date; avoid brittle string cutoffs.
    $nids = $storage->getQuery()
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->exists($join_field)
      ->execute();

    if (!$nids) {
      return;
    }

    $nodes = $storage->loadMultiple($nids);
    $queue = $this->queueFactory->get('elca_cron.' . $mail_key);

    $enqueued = 0;

    foreach ($nodes as $node) {
      // SAFE read; no array offset on null.
      $item = $node->get($join_field)->first();
      $raw  = $item ? $item->getString() : '';
      if ($raw === '') {
        continue;
      }

      // Parse date (handles "YYYY-MM-DD" and "YYYY-MM-DDTHH:MM:SS").
      try {
        $join = new \DateTimeImmutable($raw, new \DateTimeZone(date_default_timezone_get()));
      } catch (\Throwable $e) {
        try { $join = new \DateTimeImmutable($raw); }
        catch (\Throwable $e2) { $this->logger->warning('Invalid join date @v on nid @nid', ['@v' => $raw, '@nid' => $node->id()]); continue; }
      }

      $due = $join->add(new \DateInterval($period));
      if ($due->getTimestamp() > $now) {
        continue; // not due yet to hour/minute
      }

      // Dedupe: same table name everywhere.
      $already = $this->db->select('elca_cron_mail_log', 'l')
        ->fields('l', ['id'])
        ->condition('entity_type', 'node')
        ->condition('entity_id', $node->id())
        ->condition('mail_key', $mail_key)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      if ($already) {
        continue;
      }

      $email = (string) $node->get($email_field)->value;
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->logger->notice('Skip nid @nid: invalid email "@e"', ['@nid' => $node->id(), '@e' => $email]);
        continue;
      }

      $queue->createItem([
        'nid'      => (int) $node->id(),
        'email'    => $email,
        'name'     => $node->label(),
        'due_ts'   => $due->getTimestamp(),
        'mail_key' => $mail_key,
      ]);
      $enqueued++;
    }

    // Light debug so you can see it worked in `drush ws`.
    $this->logger->info('Cron scheduled {count} items for {key}.', ['count' => $enqueued, 'key' => $mail_key]);
  }
}
