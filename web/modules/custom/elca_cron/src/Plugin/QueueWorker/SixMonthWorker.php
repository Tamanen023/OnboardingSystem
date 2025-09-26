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
 *   id = "elca_cron.employee_six_month",
 *   title = @Translation("Employee 6-month milestone email"),
 *   cron = {"time" = 30}
 * )
 */
final class SixMonthWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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

  public function processItem($data): void {
    $nid  = (int) ($data['nid'] ?? 0);
    $to   = (string) ($data['email'] ?? '');
    $name = (string) ($data['name'] ?? 'Employee');

    if (!$nid || !$to) return;

    $node = $this->etm->getStorage('node')->load($nid);
    if (!$node) return;


    $params = [
      'subject' => 'Half-year milestone 🎉',
      'name' => $name,
      'body' =>
        "Hi {$name},\n\n" .
        "Congratulations on your 6-month milestone with us!\n" .
        "Thank you for your continued contributions.\n\n" .
        "Best,\nHR Team" . '<img src="https://www.business-magazine.mu/wp-content/uploads/2023/09/elca.jpg?v=1694158659"/>',
    ];

    $langcode = $this->langman->getDefaultLanguage()->getId();
    $result = $this->mail->mail('elca_cron', 'employee_six_month', $to, $langcode, $params, NULL, TRUE);

    if (!empty($result['result'])) {
      // Dedupe log (table has unique on entity_type+entity_id+mail_key).
      try {
        $this->db->insert('elca_cron_mail_log')->fields([
          'entity_type' => 'node',
          'entity_id'   => $nid,
          'mail_key'    => 'employee_six_month',
          'sent_at'     => $this->time->getRequestTime(),
        ])->execute();
      } catch (\Exception $e) {
        // Ignore duplicate insert races.
      }
      $this->logger->info('6-month email sent to @mail for node @nid.', ['@mail' => $to, '@nid' => $nid]);
    }
    else {
      $this->logger->error('Failed sending 6-month email to @mail for node @nid.', ['@mail' => $to, '@nid' => $nid]);
    }
  }
}
