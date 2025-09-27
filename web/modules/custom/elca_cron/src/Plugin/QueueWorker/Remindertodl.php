<?php

namespace Drupal\elca_cron\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends visa reminder emails before joining (14d/7d/3d) while visa not created.
 *
 * @QueueWorker(
 *   id = "elca_cron.mail_preparation_to_dl",
 *   title = @Translation("Visa reminder before join"),
 *   cron = {"time" = 30}
 * )
 */
final class Remindertodl extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    private EntityTypeManagerInterface $etm,
    private MailManagerInterface $mail,
    private LanguageManagerInterface $langman,
    private Connection $db,
    private LoggerInterface $logger,
    private TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $c, array $conf, $id, $def): self {
    return new self(
      $c->get('entity_type.manager'),
      $c->get('plugin.manager.mail'),
      $c->get('language_manager'),
      $c->get('database'),
      $c->get('logger.channel.elca_cron'),
      $c->get('datetime.time'),
    );
  }

  public function processItem($data): void {
    $nid = (int) ($data['nid'] ?? 0);
    $to  = (string) ($data['to'] ?? '');
    $key = (string) ($data['mail_key'] ?? 'visa_reminder');
    $sub = (string) ($data['subject'] ?? 'Visa reminder');

    if (!$nid || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $node = $this->etm->getStorage('node')->load($nid);
    if (!$node) return;

    // Business rule: if visa got created after enqueue, skip now.
    $visa_field = 'field_visa_created';
    if ($node->hasField($visa_field) && $node->get($visa_field)->value === TRUE) {
      $this->logger->info('Skip visa reminder for nid @nid: visa already created.', ['@nid' => $nid]);
      return;
    }

    $is_academy = isset($data['is_academy'])
      ? (bool) $data['is_academy']
      : (bool) ($node->get('field_elcadmy')->value ?? 0);

    $academy_line = $is_academy
      ? "\nNote: This new joiner is an ELCAcademy participant assigned to you."
      : "";

    // Build & send
    $params = [
      'title'   => $node->label(),
      'nid'     => $nid,
      'subject' => $sub,
      'body'    => "Hello,\n\nThe joining date for '{$node->label()}' is approaching and this is reminder to prepare his working environment"
        . $academy_line . "\n\nThanks.",
    ];

    $lang = $this->langman->getDefaultLanguage()->getId();
    $result = $this->mail->mail('elca_cron', 'mail_preparation_to_dl', $to, $lang, $params, NULL, TRUE);

    if (!empty($result['result'])) {
      try {
        $this->db->insert('elca_cron_mail_log')->fields([
          'entity_type' => 'node',
          'entity_id'   => $nid,
          'mail_key'    => $key,
          'sent_at'     => $this->time->getRequestTime(),
        ])->execute();
      } catch (\Exception $e) {
        // Unique key collision => fine (already recorded).
      }
      $this->logger->info('Visa reminder sent to @to for nid @nid (@key).', ['@to' => $to, '@nid' => $nid, '@key' => $key]);
    } else {
      $this->logger->error('Failed sending visa reminder to @to for nid @nid (@key).', ['@to' => $to, '@nid' => $nid, '@key' => $key]);
    }
  }
}
