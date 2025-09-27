<?php

namespace Drupal\elca_cron\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends a “still interested?” check to the candidate before start.
 *
 * @QueueWorker(
 *   id = "elca_cron.interest_check",
 *   title = @Translation("Candidate interest check"),
 *   cron = {"time" = 30}
 * )
 */
final class InterestCheck extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    private MailManagerInterface $mail,
    private LanguageManagerInterface $langman,
    private Connection $db,
    private LoggerInterface $logger,
    private TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $c, array $conf, $id, $def): self {
    return new self(
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
    $sub = (string) ($data['subject'] ?? 'Quick check: still joining us?');
    $key = (string) ($data['mail_key'] ?? 'interest_check');

    if (!$nid || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    // Compose message (plain text by default)
    $params = [
      'subject'     => $sub,
      'name'        => $data['name'] ?? '',
      'join_date'   => $data['join_date'] ?? '',
      'confirm_url' => $data['confirm_url'] ?? '#',
      'extra_note'  => '',
    ];

    $lang = $this->langman->getDefaultLanguage()->getId();
    $result = $this->mail->mail('elca_cron', 'interest_check', $to, $lang, $params, NULL, TRUE);

    if (!empty($result['result'])) {
      try {
        $this->db->insert('elca_cron_mail_log')->fields([
          'entity_type' => 'node',
          'entity_id'   => $nid,
          'mail_key'    => $key,
          'sent_at'     => $this->time->getRequestTime(),
        ])->execute();
      } catch (\Exception) {}
      $this->logger->info('Interest-check sent to {to} for nid {nid} ({key}).', ['to' => $to, 'nid' => $nid, 'key' => $key]);
    } else {
      $this->logger->error('Interest-check FAILED for nid {nid} to {to} ({key}).', ['to' => $to, 'nid' => $nid, 'key' => $key]);
    }
  }
}
