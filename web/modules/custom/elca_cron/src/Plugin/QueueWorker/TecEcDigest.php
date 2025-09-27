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
 * Sends TechEC digest for ELCAcademy joiners (7d/3d before start).
 *
 * @QueueWorker(
 *   id = "elca_cron.tecec_digest",
 *   title = @Translation("TechEC ELCAcademy digest"),
 *   cron = {"time" = 30}
 * )
 */
final class TecEcDigest extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
    $to    = (string)($data['to'] ?? '');
    $subj  = (string)($data['subject'] ?? 'ELCAcademy arrivals');
    $groups = $data['groups'] ?? [];
    $dkey   = (string)($data['digest_key'] ?? '');

    if (!$to || !$groups) return;

    // Build HTML body with grouped tables by join date.
    $html = '<p>Hello TechEC,</p><p>Below are ELCAcademy joiners approaching their start date.</p>';
    foreach ($groups as $date => $rows) {
      $html .= "<h3>Joining date: {$date}</h3>";
      $html .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse">';
      $html .= '<tr><th>Name</th><th>VISA</th><th>Email</th><th>Joining Date</th></tr>';
      foreach ($rows as $r) {
        $n  = htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8');
        $visa = htmlspecialchars($r['visa'], ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8');
        $html .= "<tr><td>{$n}</td><td>{$visa}</td><td>{$email}</td></tr>";
      }
      $html .= '</table>';
    }
    $html .= '<p>Thanks.</p>';

    $params = [
      'subject' => $subj,
      'body_html' => $html,
    ];
    $lang = $this->langman->getDefaultLanguage()->getId();
    $result = $this->mail->mail('elca_cron', 'tecec_digest', $to, $lang, $params, NULL, TRUE);

    if (!empty($result['result'])) {
      // Mark the digest as sent (dedupe)
      try {
        $this->db->insert('elca_cron_mail_log')->fields([
          'entity_type' => 'digest',
          'entity_id'   => 0,
          'mail_key'    => $dkey,
          'sent_at'     => $this->time->getRequestTime(),
        ])->execute();
      } catch (\Exception) {}
      $this->logger->info('TechEC digest sent ({key}) to {to}.', ['key' => $dkey, 'to' => $to]);
    } else {
      $this->logger->error('Failed sending TechEC digest ({key}) to {to}.', ['key' => $dkey, 'to' => $to]);
    }
  }
}
