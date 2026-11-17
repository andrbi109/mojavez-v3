<?php
declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\StructuredLogger;

/**
 * SmsService — sends queued SMS via a real gateway (Kavenegar-compatible).
 *
 * Per docs/00-MASTER.md §16.4 + §11.7 — resolves debt D-4-1 (SMS gateway).
 *
 * Two modes:
 *   - Production (APP_ENV=production + SMS_API_KEY set): real HTTP send via
 *     Kavenegar's /v1/{apiKey}/sms/send.json endpoint. Other Kavenegar-
 *     compatible providers (Farapayamak, Melipayamak) can be added by
 *     implementing the same send() contract.
 *   - Non-production (dev/test/staging OR no API key): log-only. The SMS is
 *     marked as 'ارسال‌شده (آزمایشی)' and the body is written to
 *     storage/logs/sms.log so the developer can see what would have been
 *     sent. No real network call is made.
 *
 * The service is invoked by:
 *   - scripts/run_worker.php — drains the SMS queue every cron tick.
 *   - AdminController (manual retry from the admin panel).
 *
 * Idempotency: a SMS already marked as sent (status != 'در انتظار') is
 * skipped — re-running the worker won't double-send.
 *
 * Schema (user_sms_entries):
 *   - status: 'در انتظار' (pending), 'ارسال‌شده' (sent), 'ارسال‌شده (آزمایشی)'
 *     (sent in dev), 'ناموفق' (failed).
 *   - sms_code: the gateway's message ID (set on success).
 *
 * Kavenegar API reference:
 *   POST https://api.kavenegar.com/v1/{apiKey}/sms/send.json
 *   Body (form-encoded): receptor=09123456789&message=hello&sender=3000...
 *   Response: {"return":{"status":200,"message":"..."},"entries":[{"messageid":12345,...}]}
 */
final class SmsService
{
    private const STATUS_PENDING = 'در انتظار';
    private const STATUS_SENT = 'ارسال‌شده';
    private const STATUS_SENT_DEV = 'ارسال‌شده (آزمایشی)';
    private const STATUS_FAILED = 'ناموفق';

    private const KAVENEGAR_BASE_URL = 'https://api.kavenegar.com/v1';
    private const KAVENEGAR_TIMEOUT = 10;

    public function __construct(
        private readonly Connection $conn,
        private readonly Environment $env,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * Drain the SMS queue: send all pending SMS, up to $limit.
     *
     * @param int $limit  Max SMS to send in this run (worker chunk size).
     * @return array{sent:int, failed:int, dev_skipped:int}  Counts by outcome.
     */
    public function drainQueue(int $limit = 50): array
    {
        $rows = $this->conn->all(
            'SELECT u.id AS sms_id, u.sms_text, u.applicant_id, u.sms_template_id,
                    a.mobile
             FROM user_sms_entries u
             JOIN applicants a ON a.id = u.applicant_id
             WHERE u.status = ?
             ORDER BY u.created_at ASC
             LIMIT ?',
            [self::STATUS_PENDING, $limit]
        );

        $sent = 0;
        $failed = 0;
        $devSkipped = 0;

        foreach ($rows as $row) {
            $mobile = (string)$row['mobile'];
            $body = (string)$row['sms_text'];
            $smsId = (int)$row['sms_id'];

            // Validate mobile (09xxxxxxxxx, 11 digits).
            if (!preg_match('/^09\d{9}$/', $mobile)) {
                $this->markFailed($smsId, 'شماره موبایل نامعتبر: ' . $mobile);
                $failed++;
                continue;
            }

            $result = $this->send($mobile, $body);

            if ($result['status'] === 'sent') {
                $this->markSent($smsId, $result['message_id'] ?? null, self::STATUS_SENT);
                $sent++;
            } elseif ($result['status'] === 'dev_sent') {
                $this->markSent($smsId, null, self::STATUS_SENT_DEV);
                $devSkipped++;
            } else {
                $this->markFailed($smsId, $result['error'] ?? 'خطای ناشناخته');
                $failed++;
            }
        }

        $this->logger->info('SMS queue drained', [
            'sent' => $sent,
            'failed' => $failed,
            'dev_skipped' => $devSkipped,
            'limit' => $limit,
        ]);

        return ['sent' => $sent, 'failed' => $failed, 'dev_skipped' => $devSkipped];
    }

    /**
     * Send a single SMS. Returns one of:
     *   - ['status' => 'sent',     'message_id' => '<gateway id>']
     *   - ['status' => 'dev_sent', 'message_id' => null]
     *   - ['status' => 'failed',   'error' => '<reason>']
     *
     * Public so admin actions can retry a single SMS.
     */
    public function send(string $mobile, string $body): array
    {
        // Trim body (Kavenegar rejects > 600 chars for normal send).
        $body = trim($body);
        if ($body === '') {
            return ['status' => 'failed', 'error' => 'متن پیام خالی است'];
        }
        if (mb_strlen($body) > 600) {
            // Truncate with ellipsis rather than fail — production SMS limits vary.
            $body = mb_substr($body, 0, 595) . '…';
        }

        $apiKey = $this->env->smsApiKey;
        $sender = $this->env->smsSender;
        $isProduction = $this->env->isProduction();

        // Non-production OR no API key → log-only (no real send).
        if (!$isProduction || $apiKey === '') {
            $this->logger->info('SMS (dev mode, not sent)', [
                'mobile' => $mobile,
                'body' => $body,
                'reason' => !$isProduction ? 'non-production' : 'no SMS_API_KEY',
            ]);
            // Also write to a dedicated sms.log so devs can inspect easily.
            $this->writeDevLog($mobile, $body);
            return ['status' => 'dev_sent', 'message_id' => null];
        }

        // Production: real HTTP send to Kavenegar.
        return $this->sendViaKavenegar($apiKey, $sender, $mobile, $body);
    }

    /**
     * Real HTTP POST to Kavenegar's send.json endpoint.
     *
     * @return array{status:string, message_id?:string, error?:string}
     */
    private function sendViaKavenegar(string $apiKey, ?string $sender, string $mobile, string $body): array
    {
        $url = self::KAVENEGAR_BASE_URL . '/' . urlencode($apiKey) . '/sms/send.json';

        $postFields = http_build_query([
            'receptor' => $mobile,
            'message'  => $body,
            'sender'   => $sender ?? '',
            'date'     => '',
            'type'     => '1',  // 1 = normal text SMS
            'localid'  => '',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::KAVENEGAR_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::KAVENEGAR_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error('Kavenegar curl failed', [
                'mobile' => $mobile, 'curl_error' => $curlError,
            ]);
            return ['status' => 'failed', 'error' => 'خطای اتصال: ' . $curlError];
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return ['status' => 'failed', 'error' => 'پاسخ نامعتبر از دروازه'];
        }

        // Kavenegar returns {"return":{"status":200,...},"entries":[{"messageid":...}]}
        $returnStatus = $decoded['return']['status'] ?? 0;
        if ($returnStatus !== 200) {
            $returnMessage = $decoded['return']['message'] ?? 'unknown';
            $this->logger->error('Kavenegar API error', [
                'mobile' => $mobile, 'status' => $returnStatus, 'message' => $returnMessage,
            ]);
            return ['status' => 'failed', 'error' => "خطای درواره ($returnStatus): $returnMessage"];
        }

        $messageId = (string)($decoded['entries'][0]['messageid'] ?? '');
        $this->logger->info('SMS sent via Kavenegar', [
            'mobile' => $mobile, 'message_id' => $messageId,
        ]);
        return ['status' => 'sent', 'message_id' => $messageId];
    }

    private function markSent(int $smsId, ?string $messageId, string $status): void
    {
        $this->conn->execute(
            'UPDATE user_sms_entries SET status = ?, sms_code = ?, received_at = CURDATE() WHERE id = ?',
            [$status, $messageId, $smsId]
        );
    }

    private function markFailed(int $smsId, string $reason): void
    {
        // Persist the failure reason in reject_reason (re-used column — it's
        // normally for admin-rejected SMS, but a failed send is conceptually
        // a system-rejected SMS too).
        $this->conn->execute(
            'UPDATE user_sms_entries SET status = ?, reject_reason = ? WHERE id = ?',
            [self::STATUS_FAILED, mb_substr($reason, 0, 250), $smsId]
        );
        $this->logger->warning('SMS send failed', ['sms_id' => $smsId, 'reason' => $reason]);
    }

    private function writeDevLog(string $mobile, string $body): void
    {
        $logDir = dirname(__DIR__, 4) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = sprintf(
            "[%s] TO: %s | BODY: %s\n",
            date('Y-m-d H:i:s'),
            $mobile,
            str_replace("\n", ' ⏎ ', $body)
        );
        @file_put_contents($logDir . '/sms.log', $line, FILE_APPEND | LOCK_EX);
    }
}
