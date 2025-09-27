<?php

namespace Drupal\elca_cron\Cron;

use Drupal\Core\CronInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;
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
    // NEW: before-join visa reminders (send only while visa NOT created)
    $this->scheduleReminderToDL('P14D', 'dl_rem_14d', 'DL reminder – 2 weeks before start');
    $this->scheduleReminderToDL('P7D',  'dl_rem_7d',  'DL reminder – 1 week before start');
    $this->scheduleReminderToDL('P3D',  'dl_rem_3d',  'DL reminder – 3 days before start');
    // Add as many milestones as you like.
    $this->scheduleAnniversary('P3M', 'employee_three_month');
    $this->scheduleAnniversary('P6M', 'employee_six_month');

    // TechEC digest for ELCAcademy joiners (before-join reminders)
    $this->scheduleReminderToTEC('P7D', 'tec_elcademy_7d', 'ELCAcademy arrivals – 1 week');
    $this->scheduleReminderToTEC('P3D', 'tec_elcademy_3d', 'ELCAcademy arrivals – 3 days');

    // Candidate interest check (exact-day)
    $this->scheduleInterestCheck('P1M', 'interest_check_1m', 'Quick check: still joining us next month?');
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

      // With exact-day logic (one day only):
      $anchor = $join->add(new \DateInterval($period)); // join + period
      $start  = (clone $anchor)->setTime(0, 0, 0);      // start of that calendar day (server TZ)
      $end    = (clone $start)->add(new \DateInterval('P1D'));

      if (!($now >= $start->getTimestamp() && $now < $end->getTimestamp())) {
        continue; // only send on the milestone day
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
        'due_ts'   => $anchor->getTimestamp(),
        'mail_key' => $mail_key,
      ]);
      $enqueued++;
    }

    // Light debug so you can see it worked in `drush ws`.
    $this->logger->info('Cron scheduled {count} items for {key}.', ['count' => $enqueued, 'key' => $mail_key]);
  }


  /**
   * Enqueue a visa reminder when now >= (join - period) AND now < join,
   * only if field_visa_created is currently FALSE. One item per (nid,
   * mail_key).
   *
   * @param string $period   ISO 8601 interval (P14D / P7D / P3D).
   * @param string $mail_key Unique key used for dedupe per milestone.
   * @param string $subject  Subject line to use.
   */
  private function scheduleReminderToDL(string $period, string $mail_key, string $subject): void {
    // ---- Adjust machine names if needed ----
    $bundle = 'employee';
    $join_field = 'field_datejoined';    // date or datetime
    $visa_field = 'field_visa_created';  // boolean
    $dl_field = 'field_dl';            // user reference to DL owner
    $academy_field = 'field_elcademy'; // <— NEW
    // ---------------------------------------

    $now = $this->time->getRequestTime();
    $tz = new \DateTimeZone(date_default_timezone_get());
    $nodes = $this->etm->getStorage('node')->loadMultiple(
      $this->etm->getStorage('node')->getQuery()
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->exists($join_field)
        ->exists($dl_field)
        ->accessCheck(FALSE)    // D11-safe in cron
        ->execute()
    );

    if (!$nodes) {
      return;
    }

    // Use one queue/worker for all three milestones.
    $queue = $this->queueFactory->get('elca_cron.mail_preparation_to_dl');
    $enq = 0;

    foreach ($nodes as $node) {
      // Only while visa not created (now).
      if (!$node->hasField($visa_field)) {
        continue;
      }
      if ($node->get($visa_field)->value === TRUE) {
        continue;
      }

      // Joining date
      $raw = $node->get($join_field)->first()?->getString() ?? '';
      if ($raw === '') {
        continue;
      }
      try {
        $join = new \DateTimeImmutable($raw, $tz);
      }
      catch (\Throwable) {
        try {
          $join = new \DateTimeImmutable($raw);
        }
        catch (\Throwable) {
          continue;
        }
      }

      $anchor = $join->sub(new \DateInterval($period));   // join - period
      $start  = (clone $anchor)->setTime(0, 0, 0);        // start of that day (server TZ)
      $end    = (clone $start)->add(new \DateInterval('P1D'));

      if (!($now >= $start->getTimestamp() && $now < $end->getTimestamp())) {
        continue; // not the milestone day
      }

      // Dedupe per milestone
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

      // Get DL email from user reference
      if ($node->get($dl_field)->isEmpty()) {
        continue;
      }
      $account = $node->get($dl_field)->entity;
      $to = $account?->getEmail() ?: '';
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $is_academy = $node->hasField($academy_field) && (bool) ($node->get($academy_field)->value ?? 0);
      $queue->createItem([
        'nid' => (int) $node->id(),
        'to' => $to,
        'name' => $node->label(),
        'mail_key' => $mail_key,
        'subject' => $subject,
        'is_academy'=> $is_academy,
      ]);
      $enq++;
    }

    $this->logger->info('Cron scheduled {count} items for {key}.', [
      'count' => $enq,
      'key' => $mail_key,
    ]);
  }


  private function scheduleReminderToTEC(string $period, string $mail_key, string $subject): void {
    // ---- Adjust to your site ----
    $bundle        = 'employee';
    $join_field    = 'field_datejoined';
    $academy_field = 'field_elcademy';       // checkbox TRUE = ELCAcademy
    $dl_field      = 'field_dl';             // (optional) include DL name/email in table if present
    $tec_email     = 'techec@example.com';   // <-- put your TechEC DL here, or load from config
    // --------------------------------

    $now  = $this->time->getRequestTime();
    $tz   = new \DateTimeZone(date_default_timezone_get());
    $store = $this->etm->getStorage('node');

    $nids = $store->getQuery()
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->exists($join_field)
      ->condition($academy_field, 1)  // only ELCAcademy
      ->accessCheck(FALSE)
      ->execute();

    if (!$nids) return;

    $nodes = $store->loadMultiple($nids);

    // Gather rows grouped by the JOIN DATE yyyy-mm-dd that is due for this milestone.
    $groups = [];   // ['2025-10-04' => [ [name=>..., dl=>..., dl_email=>..., nid=>...], ... ], ...]
    $anchor_ymd = null; // All due today for a given period share the same join date (today + period).
    foreach ($nodes as $node) {
      // Parse join date
      $raw = $node->get($join_field)->first()?->getString() ?? '';
      if ($raw === '') continue;
      try { $join = new \DateTimeImmutable($raw, $tz); }
      catch (\Throwable) { try { $join = new \DateTimeImmutable($raw); } catch (\Throwable) { continue; } }

      // Exact-day window for this milestone (join - period is today)
      $anchor = $join->sub(new \DateInterval($period));  // join - 7d / 3d
      $start  = (clone $anchor)->setTime(0, 0, 0);
      $end    = (clone $start)->add(new \DateInterval('P1D'));
      $now_ok = ($now >= $start->getTimestamp() && $now < $end->getTimestamp());
      if (!$now_ok) continue;

      // Prepare row
      $dl_name = '';
      $dl_email = '';
      if ($node->hasField($dl_field) && !$node->get($dl_field)->isEmpty()) {
        $u = $node->get($dl_field)->entity;
        if ($u) {
          $dl_name  = $u->getDisplayName();
          $dl_email = $u->getEmail() ?: '';
        }
      }

      $join_ymd = $join->format('Y-m-d');
      $anchor_ymd = $join_ymd; // all rows today should share this for a given period
      $groups[$join_ymd][] = [
        'nid'      => (int) $node->id(),
        'name'     => $node->label(),
        'dl_name'  => $dl_name,
        'dl_email' => $dl_email,
        'visa' => $node->get('field_visa')->value,
        'emaill' => $node->get('field_email')->value,
        'join_ymd' => $anchor_ymd,
      ];
    }

    if (!$groups) return;

    // Dedupe per day: avoid re-sending the same digest if cron runs multiple times today.
    // We dedupe by mail_key + the anchor day (join date for this period).
    $digest_key = $mail_key . ':' . $anchor_ymd;
    $already = $this->db->select('elca_cron_mail_log', 'l')
      ->fields('l', ['id'])
      ->condition('entity_type', 'digest')   // distinguish from node-based keys
      ->condition('entity_id', 0)
      ->condition('mail_key', $digest_key)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if ($already) return;

    // Enqueue a single digest item for TechEC
    $queue = $this->queueFactory->get('elca_cron.tecec_digest');
    $queue->createItem([
      'to'         => $tec_email,
      'subject'    => $subject,
      'groups'     => $groups,      // grouped by join date
      'digest_key' => $digest_key,  // for dedupe logging
    ]);

    $this->logger->info('Cron scheduled TechEC digest for {key} ({count} groups).', [
      'key'   => $digest_key,
      'count' => count($groups),
    ]);
  }

  private function scheduleInterestCheck(string $period, string $mail_key, string $subject): void {
    $bundle      = 'employee';
    $join_field  = 'field_datejoined';   // date or datetime
    $email_field = 'field_email';        // candidate’s email

    $now   = $this->time->getRequestTime();
    $tz    = new \DateTimeZone(date_default_timezone_get());
    $store = $this->etm->getStorage('node');

    $nids = $store->getQuery()
      ->condition('type', $bundle)
      ->condition('status', 1)
      ->exists($join_field)
      ->exists($email_field)
      ->accessCheck(FALSE)
      ->execute();
    if (!$nids) return;

    $nodes = $store->loadMultiple($nids);
    $queue = $this->queueFactory->get('elca_cron.interest_check');
    $enq = 0;

    foreach ($nodes as $node) {
      $raw = $node->get($join_field)->first()?->getString() ?? '';
      if ($raw === '') continue;

      try { $join = new \DateTimeImmutable($raw, $tz); }
      catch (\Throwable) { try { $join = new \DateTimeImmutable($raw); } catch (\Throwable) { continue; } }

      // EXACT-DAY window: send only when today == (join - period)
      $anchor = $join->sub(new \DateInterval($period)); // join - 1 month
      $start  = (clone $anchor)->setTime(0, 0, 0);
      $end    = (clone $start)->add(new \DateInterval('P1D'));
      if (!($now >= $start->getTimestamp() && $now < $end->getTimestamp())) {
        continue;
      }

      // Dedupe per (nid, mail_key)
      $already = $this->db->select('elca_cron_mail_log', 'l')
        ->fields('l', ['id'])
        ->condition('entity_type', 'node')
        ->condition('entity_id', $node->id())
        ->condition('mail_key', $mail_key)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($already) continue;

      $to = (string) $node->get($email_field)->value;
      if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;

      $queue->createItem([
        'nid'      => (int) $node->id(),
        'to'       => $to,
        'name'     => $node->label(),
        'subject'  => $subject,
        'mail_key' => $mail_key,
        'due_ts'   => $anchor->getTimestamp(),
        'join_date'   => $join->format('F j, Y'),
        'confirm_url' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(), // any confirmation URL
      ]);
      $enq++;
    }

    $this->logger->info('Cron scheduled {count} items for {key}.', ['count' => $enq, 'key' => $mail_key]);
  }

  private function sendMailToIT(string $period, string $mail_key, string $subject) {

  }



}
