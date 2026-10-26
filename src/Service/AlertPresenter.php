<?php

declare(strict_types=1);

namespace Mpgw\Service;

/**
 * Gor ett larm lasbart: svensk rubrik och forklaring i stallet for typslug,
 * plus lank till mailet/tradden det galler. Ren funktion over larmraden
 * (alerts + valfria join-falt raw_email_uuid, raw_email_subject, template_name).
 */
final class AlertPresenter
{
    /**
     * @param array<string,mixed> $alert
     * @return array{title:string,detail:string,link_url:?string,link_label:?string}
     */
    public static function describe(array $alert): array
    {
        $details = [];
        if (!empty($alert['details_json'])) {
            $decoded = json_decode((string) $alert['details_json'], true);
            $details = is_array($decoded) ? $decoded : [];
        }

        $subject = trim((string) ($alert['raw_email_subject'] ?? ''));
        $template = trim((string) ($alert['template_name'] ?? ''));
        $mailUrl = !empty($alert['raw_email_uuid']) ? '/admin/emails/' . $alert['raw_email_uuid'] : null;

        switch ($alert['type'] ?? '') {
            case 'low_match_rate':
                $missing = array_values(array_filter((array) ($details['unmatched_fields'] ?? []), 'is_string'));
                $detail = ($template !== '' ? 'Mallen ' . $template . ' hittade inte alla fält.' : 'Mallen hittade inte alla fält.')
                    . ($missing !== [] ? ' Saknas: ' . implode(', ', $missing) . '.' : '')
                    . ' Oftast har avsändaren ändrat formatet.';
                return ['title' => 'Mail matchade bara delvis', 'detail' => $detail, 'link_url' => $mailUrl, 'link_label' => 'Granska mailet'];

            case 'thread_anomaly':
                $titles = [
                    ThreadService::ANOMALY_CLOSE_WITHOUT_OPEN => ['Mail stänger en tråd som aldrig öppnades', 'Det saknas ett öppnande mail – kan ett mail ha raderats?'],
                    ThreadService::ANOMALY_DUPLICATE_CLOSE => ['Tråden stängdes två gånger', 'Troligen ett omskickat mail.'],
                    ThreadService::ANOMALY_DUPLICATE_OPEN => ['Tråden öppnades två gånger', 'Troligen ett omskickat mail.'],
                ];
                [$title, $explain] = $titles[$details['anomaly'] ?? ''] ?? ['Avvikelse i en tråd', ''];
                $detail = trim(($subject !== '' ? $subject . '. ' : '') . $explain);
                return ['title' => $title, 'detail' => $detail, 'link_url' => $mailUrl, 'link_label' => 'Visa tråden'];

            case 'no_template_match':
                return ['title' => 'Mail matchade ingen mall', 'detail' => $subject !== '' ? $subject : 'Ingen mall passar avsändare och ämne.', 'link_url' => $mailUrl, 'link_label' => 'Granska mailet'];

            case 'rule_zero_hits':
                return ['title' => 'En regel träffar inte längre', 'detail' => $template !== '' ? 'Mallen ' . $template . '.' : '', 'link_url' => $mailUrl, 'link_label' => 'Granska mailet'];

            case 'imap_connection_failed':
                return ['title' => 'Brevlådan gick inte att nå', 'detail' => 'Kontrollera mailboxens inställningar och lösenord.', 'link_url' => null, 'link_label' => null];
        }

        return ['title' => (string) ($alert['type'] ?? 'Larm'), 'detail' => '', 'link_url' => $mailUrl, 'link_label' => $mailUrl !== null ? 'Granska mailet' : null];
    }
}
