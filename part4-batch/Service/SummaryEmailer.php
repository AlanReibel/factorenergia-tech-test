<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Exception;

class SummaryEmailer
{
    private string $administratorEmails;
    private string $fromEmail;
    private string $appName;
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        string $administratorEmails = 'admin@factorenergy.es',
        string $fromEmail = 'noreply@factorenergy.es',
        string $appName = 'FactorEnergia'
    ) {
        $this->administratorEmails = $administratorEmails;
        $this->fromEmail = $fromEmail;
        $this->appName = $appName;
        $this->logger = $logger;
    }

    /**
     * Send summary email with batch processing results
     */
    public function sendSummaryEmail(array $stats, string $billingPeriod): void
    {
        try {
            $subject = sprintf(
                '[%s] Invoices Generated - %s (Success Rate: %.2f%%)',
                $this->appName,
                $billingPeriod,
                ($stats['success'] / $stats['total']) * 100
            );

            $body = $this->buildEmailBody($stats, $billingPeriod);

            $this->sendEmail(
                $this->administratorEmails,
                $subject,
                $body
            );

            $this->logger->info('Summary email sent successfully', [
                'billing_period' => $billingPeriod,
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to send summary email', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send error notification email
     */
    public function sendErrorNotification(Exception $exception): void
    {
        try {
            $subject = '[' . $this->appName . '] CRITICAL: Invoice Generation Failed';

            $body = $this->buildErrorEmailBody($exception);

            $this->sendEmail(
                $this->administratorEmails,
                $subject,
                $body
            );

            $this->logger->info('Error notification email sent successfully');

        } catch (Exception $e) {
            $this->logger->error('Failed to send error notification email', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Build summary email body
     */
    private function buildEmailBody(array $stats, string $billingPeriod): string
    {
        $successRate = ($stats['success'] / $stats['total']) * 100;
        $timestamp = date('Y-m-d H:i:s');

        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; }
        .header { background-color: #2c3e50; color: white; padding: 20px; }
        .content { padding: 20px; }
        .stats-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .stats-table th, .stats-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .stats-table th { background-color: #ecf0f1; font-weight: bold; }
        .success { color: #27ae60; }
        .warning { color: #f39c12; }
        .error { color: #e74c3c; }
        .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Invoice Generation Summary Report</h2>
        <p>Billing Period: <strong>{$billingPeriod}</strong></p>
    </div>

    <div class="content">
        <h3>Batch Processing Results</h3>

        <table class="stats-table">
            <tr>
                <th>Metric</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Total Contracts Processed</td>
                <td><strong>{$stats['total']}</strong></td>
            </tr>
            <tr>
                <td>Successfully Generated</td>
                <td><strong class="success">{$stats['success']}</strong></td>
            </tr>
            <tr>
                <td>Skipped (Duplicates)</td>
                <td><strong class="warning">{$stats['skipped']}</strong></td>
            </tr>
            <tr>
                <td>Failed</td>
                <td><strong class="error">{$stats['failed']}</strong></td>
            </tr>
            <tr>
                <td>Success Rate</td>
                <td><strong class="success">{$successRate}%</strong></td>
            </tr>
        </table>

        {$this->buildFailedContractsSection($stats)}

        <p><strong>Generated at:</strong> {$timestamp}</p>
    </div>

    <div class="footer">
        <p>This is an automated report from the {$this->appName} system.</p>
        <p>Do not reply to this email.</p>
    </div>
</body>
</html>
HTML;

        return $body;
    }

    /**
     * Build failed contracts section
     */
    private function buildFailedContractsSection(array $stats): string
    {
        if ($stats['failed'] === 0) {
            return '<p class="success"><strong>✓ All contracts processed successfully!</strong></p>';
        }

        $failedList = '';
        foreach (array_slice($stats['failed_contracts'], 0, 10) as $failure) {
            $failedList .= sprintf(
                '<tr><td>%d</td><td>%s</td></tr>',
                $failure['contract_id'],
                htmlspecialchars($failure['reason'])
            );
        }

        $moreCount = max(0, $stats['failed'] - 10);

        return <<<HTML
<h3 class="error">Failed Contracts ({$stats['failed']})</h3>
<table class="stats-table">
    <tr>
        <th>Contract ID</th>
        <th>Reason</th>
    </tr>
    {$failedList}
</table>
{$this->buildMoreFailuresMessage($moreCount)}
HTML;
    }

    /**
     * Build message for additional failures
     */
    private function buildMoreFailuresMessage(int $count): string
    {
        if ($count === 0) {
            return '';
        }

        return sprintf(
            '<p class="error"><em>... and %d more failures. Check detailed logs for complete information.</em></p>',
            $count
        );
    }

    /**
     * Build error email body
     */
    private function buildErrorEmailBody(Exception $exception): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = htmlspecialchars($exception->getMessage());
        $trace = htmlspecialchars($exception->getTraceAsString());

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; }
        .header { background-color: #c0392b; color: white; padding: 20px; }
        .content { padding: 20px; }
        .error-box { background-color: #fadbd8; border: 1px solid #e74c3c; padding: 10px; margin: 10px 0; }
        .code-box { background-color: #ecf0f1; border: 1px solid #bdc3c7; padding: 10px; overflow-x: auto; font-family: monospace; }
        .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>⚠️ Critical Error: Invoice Generation Failed</h2>
    </div>

    <div class="content">
        <p><strong>Timestamp:</strong> {$timestamp}</p>

        <div class="error-box">
            <h3>Error Details</h3>
            <p><strong>Message:</strong> {$message}</p>
        </div>

        <h3>Stack Trace</h3>
        <div class="code-box">
            {$trace}
        </div>

        <p><strong>Action Required:</strong> Please investigate the logs immediately to determine the cause and prevent data loss.</p>
    </div>

    <div class="footer">
        <p>This is an automated alert from the {$this->appName} system.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send email (abstraction for testing)
     * In production, use Swift Mailer, PHPMailer, or similar
     */
    private function sendEmail(string $to, string $subject, string $body): void
    {
        // Note: In a real application, use Symfony Mailer or similar
        // This is a placeholder for the email sending logic

        // For production, inject MailerInterface from Symfony
        // $this->mailer->send($email);

        // Placeholder implementation - would be replaced with actual mailer
        $headers = implode("\r\n", [
            'From: ' . $this->fromEmail,
            'Content-Type: text/html; charset=UTF-8',
            'MIME-Version: 1.0'
        ]);

        // In development/testing, this would log instead
        $this->logger->debug('Email would be sent', [
            'to' => $to,
            'subject' => $subject,
            'headers' => $headers
        ]);
    }
}
