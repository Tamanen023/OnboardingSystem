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
 * Sends the 6-month-after-joining email.
 *
 * @QueueWorker(
 *   id = "elca_cron.employee_three_month",
 *   title = @Translation("Employee 6-month milestone email"),
 *   cron = {"time" = 30}
 * )
 */
final class ThreeMonthWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    private EntityTypeManagerInterface $etm,
    private MailManagerInterface $mail,
    private LanguageManagerInterface $langman,
    private Connection $db,
    private LoggerInterface $logger,
    private TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('database'),
      $container->get('logger.channel.elca_cron'), // <- make sure this exists in elca_cron.services.yml
      $container->get('datetime.time'),
    );
  }

  public function processItem($data) {
    $nid = (int) ($data['nid'] ?? 0);
    if (!$nid) return;

    $node = $this->etm->getStorage('node')->load($nid);
    if (!$node) return;

    $to = $data['email'] ?? '';
    $name = $data['name'] ?? $node->label();

    $params = [
      'subject' => 'Congratulations on 3 months!',
      'name' => $name,
      'body' =>
        "Hi {$name},\n\n" .
        "Congratulations on reaching your 3-month milestone with us.\n" .
        "We appreciate your contributions!\n\n" .
        "Best,\nHR Team",
    ];

    $langcode = $this->langman->getDefaultLanguage()->getId();
    $result = $this->mail->mail('elca_cron', 'employee_three_month', $to, $langcode, $params, NULL, TRUE);

    if (!empty($result['result'])) {
      try {
        $this->db->insert('elca_cron_mail_log')->fields([
          'entity_type' => 'node',
          'entity_id' => $nid,
          'mail_key' => 'employee_three_month',
          'sent_at' => \Drupal::time()->getRequestTime(),
        ])->execute();
      } catch (\Exception $e) {
        // Unique key guards against races; safe to ignore dup insert.
      }
      $this->logger->info('3-month email sent to @mail for node @nid.', ['@mail' => $to, '@nid' => $nid]);
    } else {
      $this->logger->error('Failed sending 3-month email to @mail for node @nid.', ['@mail' => $to, '@nid' => $nid]);
    }
  }

}
