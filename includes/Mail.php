<?php
/**
 * Mail System
 *
 * Provides email sending with multiple drivers:
 * - PHP mail() function (default)
 * - SMTP (via stream sockets)
 * - Log/File (for testing)
 *
 * Features:
 * - HTML and plain text emails
 * - Email templates
 * - Attachments
 * - Queue support for async sending
 */

class Mail {
    private string $driver = 'mail';
    private array $config = [];
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private string $from = '';
    private string $fromName = '';
    private string $replyTo = '';
    private string $subject = '';
    private string $body = '';
    private string $altBody = '';
    private array $attachments = [];
    private array $headers = [];
    private string $boundary = '';
    private static ?array $defaultConfig = null;

    /**
     * Create a new mail instance
     */
    public static function create(): self {
        return new self();
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->boundary = bin2hex(random_bytes(16));
        $this->loadConfig();
    }

    /**
     * Load mail configuration
     */
    private function loadConfig(): void {
        // Load from settings or defaults
        if (self::$defaultConfig !== null) {
            $this->config = self::$defaultConfig;
        } else {
            $this->config = [
                'driver' => getSetting('mail_driver', 'mail'),
                'host' => getSetting('mail_host', 'localhost'),
                'port' => (int)getSetting('mail_port', 587),
                'username' => getSetting('mail_username', ''),
                'password' => getSetting('mail_password', ''),
                'encryption' => getSetting('mail_encryption', 'tls'),
                'from_address' => getSetting('mail_from_address', 'noreply@example.com'),
                'from_name' => getSetting('mail_from_name', defined('SITE_NAME') ? SITE_NAME : 'MeshSilo'),
            ];
        }

        $this->driver = $this->config['driver'];
        $this->from = $this->config['from_address'];
        $this->fromName = $this->config['from_name'];
    }

    /**
     * Set default configuration (for testing)
     */
    public static function setDefaultConfig(array $config): void {
        self::$defaultConfig = $config;
    }

    /**
     * Sanitize an email address to prevent header injection
     */
    private function sanitizeEmail(string $email): string {
        $email = str_replace(["\r", "\n", "\0"], '', $email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: $email");
        }
        return $email;
    }

    /**
     * Sanitize a header value to prevent header injection
     */
    private function sanitizeHeaderValue(string $value): string {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Set the recipient(s)
     */
    public function to(string|array $address, ?string $name = null): self {
        if (is_array($address)) {
            foreach ($address as $email => $recipientName) {
                if (is_numeric($email)) {
                    $this->to[] = ['email' => $this->sanitizeEmail($recipientName), 'name' => ''];
                } else {
                    $this->to[] = ['email' => $this->sanitizeEmail($email), 'name' => $this->sanitizeHeaderValue($recipientName)];
                }
            }
        } else {
            $this->to[] = ['email' => $this->sanitizeEmail($address), 'name' => $this->sanitizeHeaderValue($name ?? '')];
        }
        return $this;
    }

    /**
     * Set CC recipient(s)
     */
    public function cc(string|array $address, ?string $name = null): self {
        if (is_array($address)) {
            foreach ($address as $email => $recipientName) {
                if (is_numeric($email)) {
                    $this->cc[] = ['email' => $this->sanitizeEmail($recipientName), 'name' => ''];
                } else {
                    $this->cc[] = ['email' => $this->sanitizeEmail($email), 'name' => $this->sanitizeHeaderValue($recipientName)];
                }
            }
        } else {
            $this->cc[] = ['email' => $this->sanitizeEmail($address), 'name' => $this->sanitizeHeaderValue($name ?? '')];
        }
        return $this;
    }

    /**
     * Set BCC recipient(s)
     */
    public function bcc(string|array $address, ?string $name = null): self {
        if (is_array($address)) {
            foreach ($address as $email => $recipientName) {
                if (is_numeric($email)) {
                    $this->bcc[] = ['email' => $this->sanitizeEmail($recipientName), 'name' => ''];
                } else {
                    $this->bcc[] = ['email' => $this->sanitizeEmail($email), 'name' => $this->sanitizeHeaderValue($recipientName)];
                }
            }
        } else {
            $this->bcc[] = ['email' => $this->sanitizeEmail($address), 'name' => $this->sanitizeHeaderValue($name ?? '')];
        }
        return $this;
    }

    /**
     * Set the sender
     */
    public function from(string $address, ?string $name = null): self {
        $this->from = $this->sanitizeEmail($address);
        $this->fromName = $this->sanitizeHeaderValue($name ?? '');
        return $this;
    }

    /**
     * Set reply-to address
     */
    public function replyTo(string $address): self {
        $this->replyTo = $this->sanitizeEmail($address);
        return $this;
    }

    /**
     * Set the subject
     */
    public function subject(string $subject): self {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set the HTML body
     */
    public function html(string $html): self {
        $this->body = $html;
        return $this;
    }

    /**
     * Set the plain text body
     */
    public function text(string $text): self {
        $this->altBody = $text;
        return $this;
    }

    /**
     * Set both HTML and text body
     */
    public function body(string $html, ?string $text = null): self {
        $this->body = $html;
        $this->altBody = $text ?? strip_tags($html);
        return $this;
    }

    /**
     * Use a template for the email
     */
    public function template(string $name, array $data = []): self {
        $__templatePath = dirname(__DIR__) . '/templates/email/' . $name . '.php';

        if (!file_exists($__templatePath)) {
            throw new \Exception("Email template not found: $name");
        }

        // Filter out reserved variable names to prevent injection attacks
        $__reservedVars = ['this', '__templatePath', '__reservedVars', '__safeData', '__key', '__value'];
        $__safeData = [];
        foreach ($data as $__key => $__value) {
            if (!in_array($__key, $__reservedVars, true)) {
                $__safeData[$__key] = $__value;
            }
        }

        // Extract only safe data variables
        extract($__safeData, EXTR_SKIP);

        // Capture template output
        ob_start();
        include $__templatePath;
        $this->body = ob_get_clean();

        // Auto-generate plain text version
        $this->altBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $this->body));

        return $this;
    }

    /**
     * Render inline template string
     */
    public function render(string $template, array $data = []): self {
        // Simple placeholder replacement (HTML-escaped by default)
        foreach ($data as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $escaped = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                $template = str_replace('{{' . $key . '}}', $escaped, $template);
                $template = str_replace('{{ ' . $key . ' }}', $escaped, $template);
                // Use {!! key !!} for raw (unescaped) values
                $template = str_replace('{!!' . $key . '!!}', $value, $template);
                $template = str_replace('{!! ' . $key . ' !!}', $value, $template);
            }
        }

        $this->body = $template;
        $this->altBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $template));

        return $this;
    }

    /**
     * Add an attachment
     */
    public function attach(string $path, ?string $name = null, ?string $mimeType = null): self {
        if (!file_exists($path)) {
            throw new \Exception("Attachment file not found: $path");
        }

        $this->attachments[] = [
            'path' => $path,
            'name' => $name ?? basename($path),
            'type' => $mimeType ?? mime_content_type($path)
        ];

        return $this;
    }

    /**
     * Add a custom header
     */
    public function header(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Send the email
     */
    public function send(): bool {
        if (empty($this->to)) {
            throw new \Exception("No recipients specified");
        }

        if (empty($this->subject)) {
            throw new \Exception("No subject specified");
        }

        if (empty($this->body)) {
            throw new \Exception("No body specified");
        }

        if (class_exists('PluginManager')) {
            $mailData = PluginManager::applyFilter('mail_before_send', [
                'to' => $this->to,
                'subject' => $this->subject,
                'body' => $this->body,
            ]);
            $this->to = $mailData['to'] ?? $this->to;
            $this->subject = $mailData['subject'] ?? $this->subject;
            $this->body = $mailData['body'] ?? $this->body;
        }

        switch ($this->driver) {
            case 'smtp':
                return $this->sendSmtp();
            case 'log':
                return $this->sendLog();
            case 'mail':
            default:
                return $this->sendMail();
        }
    }

    /**
     * Queue the email for async sending
     */
    public function queue(string $queue = 'default'): int {
        if (!class_exists('Queue')) {
            throw new \Exception("Queue system not available");
        }

        return Queue::push('SendEmailJob', [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'from' => $this->from,
            'fromName' => $this->fromName,
            'replyTo' => $this->replyTo,
            'subject' => $this->subject,
            'body' => $this->body,
            'altBody' => $this->altBody,
            'attachments' => $this->attachments,
            'headers' => $this->headers
        ], $queue);
    }

    /**
     * Send using PHP mail() function
     */
    private function sendMail(): bool {
        $headers = $this->buildHeaders();
        $body = $this->buildBody();

        $to = $this->formatRecipients($this->to);

        return mail($to, $this->subject, $body, implode("\r\n", $headers));
    }

    /**
     * Send using SMTP
     */
    private function sendSmtp(): bool {
        $host = $this->config['host'];
        $port = $this->config['port'];
        $username = $this->config['username'];
        $password = $this->config['password'];
        $encryption = $this->config['encryption'];

        // Connect to SMTP server
        $context = stream_context_create();

        if ($encryption === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $socket = @stream_socket_client(
            "$host:$port",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new \Exception("Failed to connect to SMTP server: $errstr ($errno)");
        }

        // Read greeting
        $this->smtpGetResponse($socket);

        // EHLO
        $this->smtpCommand($socket, "EHLO " . gethostname());

        // STARTTLS if needed
        if ($encryption === 'tls') {
            $this->smtpCommand($socket, "STARTTLS");
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpCommand($socket, "EHLO " . gethostname());
        }

        // Authenticate
        if ($username && $password) {
            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpCommand($socket, base64_encode($username));
            $this->smtpCommand($socket, base64_encode($password));
        }

        // Send email
        $this->smtpCommand($socket, "MAIL FROM:<{$this->from}>");

        foreach ($this->to as $recipient) {
            $this->smtpCommand($socket, "RCPT TO:<{$recipient['email']}>");
        }
        foreach ($this->cc as $recipient) {
            $this->smtpCommand($socket, "RCPT TO:<{$recipient['email']}>");
        }
        foreach ($this->bcc as $recipient) {
            $this->smtpCommand($socket, "RCPT TO:<{$recipient['email']}>");
        }

        $this->smtpCommand($socket, "DATA");

        // Send headers and body
        $message = implode("\r\n", $this->buildHeaders()) . "\r\n\r\n" . $this->buildBody();
        fwrite($socket, $message . "\r\n.\r\n");
        $this->smtpGetResponse($socket);

        // Quit
        $this->smtpCommand($socket, "QUIT");
        fclose($socket);

        return true;
    }

    /**
     * Send SMTP command
     */
    private function smtpCommand($socket, string $command): string {
        fwrite($socket, $command . "\r\n");
        return $this->smtpGetResponse($socket);
    }

    /**
     * Get SMTP response
     */
    private function smtpGetResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);
        if ($code >= 400) {
            throw new \Exception("SMTP error: $response");
        }

        return $response;
    }

    /**
     * Log email instead of sending (for testing)
     */
    private function sendLog(): bool {
        $logPath = dirname(__DIR__) . '/storage/logs/mail.log';
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $log = sprintf(
            "[%s] To: %s | Subject: %s | From: %s\n%s\n---\n",
            date('Y-m-d H:i:s'),
            $this->formatRecipients($this->to),
            $this->subject,
            $this->from,
            $this->body
        );

        return file_put_contents($logPath, $log, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Build email headers
     */
    private function buildHeaders(): array {
        $headers = [];

        // From
        $headers[] = $this->fromName
            ? "From: {$this->fromName} <{$this->from}>"
            : "From: {$this->from}";

        // Reply-To
        if ($this->replyTo) {
            $headers[] = "Reply-To: {$this->replyTo}";
        }

        // CC
        if (!empty($this->cc)) {
            $headers[] = "Cc: " . $this->formatRecipients($this->cc);
        }

        // BCC
        if (!empty($this->bcc)) {
            $headers[] = "Bcc: " . $this->formatRecipients($this->bcc);
        }

        // Content type
        $boundary = $this->boundary;
        if (!empty($this->attachments)) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        } elseif ($this->body && $this->altBody) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        } else {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        }

        // Custom headers
        foreach ($this->headers as $name => $value) {
            $headers[] = "$name: $value";
        }

        return $headers;
    }

    /**
     * Build email body
     */
    private function buildBody(): string {
        $boundary = $this->boundary;

        if (!empty($this->attachments)) {
            // Multipart with attachments
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($this->body)) . "\r\n";

            foreach ($this->attachments as $attachment) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Type: {$attachment['type']}; name=\"{$attachment['name']}\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n\r\n";
                $body .= chunk_split(base64_encode(file_get_contents($attachment['path']))) . "\r\n";
            }

            $body .= "--{$boundary}--";
            return $body;
        }

        if ($this->body && $this->altBody) {
            // Multipart alternative (HTML + plain text)
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $body .= $this->altBody . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $body .= $this->body . "\r\n";
            $body .= "--{$boundary}--";
            return $body;
        }

        return $this->body;
    }

    /**
     * Format recipients array to string
     */
    private function formatRecipients(array $recipients): string {
        return implode(', ', array_map(function($r) {
            return $r['name'] ? "{$r['name']} <{$r['email']}>" : $r['email'];
        }, $recipients));
    }
}

// ========================================
// Email Job for Queue
// ========================================

if (class_exists('Job', false)) {
    class SendEmailJob extends Job {
        public function handle(array $data): void {
            $mail = Mail::create()
                ->subject($data['subject'])
                ->html($data['body']);

            if (!empty($data['altBody'])) {
                $mail->text($data['altBody']);
            }

            foreach ($data['to'] as $recipient) {
                $mail->to($recipient['email'], $recipient['name']);
            }

            foreach ($data['cc'] ?? [] as $recipient) {
                $mail->cc($recipient['email'], $recipient['name']);
            }

            foreach ($data['bcc'] ?? [] as $recipient) {
                $mail->bcc($recipient['email'], $recipient['name']);
            }

            if (!empty($data['from'])) {
                $mail->from($data['from'], $data['fromName'] ?? null);
            }

            if (!empty($data['replyTo'])) {
                $mail->replyTo($data['replyTo']);
            }

            foreach ($data['headers'] ?? [] as $name => $value) {
                $mail->header($name, $value);
            }

            $mail->send();
        }
    }
}

// ========================================
// Notification System
// ========================================

class Notification {
    /**
     * Send password reset email
     */
    public static function passwordReset(string $email, string $token, string $name = ''): bool {
        $resetUrl = url('/reset-password?token=' . urlencode($token));
        $siteName = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Silo', ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return Mail::create()
            ->to($email, $name)
            ->subject("Reset Your Password - $siteName")
            ->body("
                <h2>Password Reset Request</h2>
                <p>Hello" . ($safeName ? " $safeName" : "") . ",</p>
                <p>We received a request to reset your password. Click the button below to create a new password:</p>
                <p style='margin: 20px 0;'>
                    <a href='$resetUrl' style='background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;'>
                        Reset Password
                    </a>
                </p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request this, you can safely ignore this email.</p>
                <p>Thanks,<br>$siteName</p>
            ")
            ->send();
    }

    /**
     * Send welcome email
     */
    public static function welcome(string $email, string $name = ''): bool {
        $siteName = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Silo', ENT_QUOTES, 'UTF-8');
        $siteUrl = htmlspecialchars(defined('SITE_URL') ? SITE_URL : '/', ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return Mail::create()
            ->to($email, $name)
            ->subject("Welcome to $siteName!")
            ->body("
                <h2>Welcome to $siteName!</h2>
                <p>Hello" . ($safeName ? " $safeName" : "") . ",</p>
                <p>Your account has been created successfully. You can now:</p>
                <ul>
                    <li>Browse and download 3D models</li>
                    <li>Upload your own models</li>
                    <li>Create collections and favorites</li>
                </ul>
                <p style='margin: 20px 0;'>
                    <a href='$siteUrl' style='background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;'>
                        Get Started
                    </a>
                </p>
                <p>Thanks for joining us!<br>$siteName</p>
            ")
            ->send();
    }

    /**
     * Send upload notification
     */
    public static function uploadComplete(string $email, string $modelName, int $modelId, string $name = ''): bool {
        $siteName = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Silo', ENT_QUOTES, 'UTF-8');
        $modelUrl = url("/model/$modelId");
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeModelName = htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8');

        return Mail::create()
            ->to($email, $name)
            ->subject("Upload Complete: $modelName - $siteName")
            ->body("
                <h2>Upload Complete</h2>
                <p>Hello" . ($safeName ? " $safeName" : "") . ",</p>
                <p>Your model <strong>$safeModelName</strong> has been uploaded successfully.</p>
                <p style='margin: 20px 0;'>
                    <a href='$modelUrl' style='background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;'>
                        View Model
                    </a>
                </p>
                <p>Thanks,<br>$siteName</p>
            ")
            ->send();
    }

    /**
     * Send scheduled report
     */
    public static function scheduledReport(string $email, string $reportName, string $attachmentPath, string $name = ''): bool {
        $siteName = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Silo', ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeReportName = htmlspecialchars($reportName, ENT_QUOTES, 'UTF-8');

        return Mail::create()
            ->to($email, $name)
            ->subject("Scheduled Report: $reportName - " . (defined('SITE_NAME') ? SITE_NAME : 'Silo'))
            ->body("
                <h2>Scheduled Report</h2>
                <p>Hello" . ($safeName ? " $safeName" : "") . ",</p>
                <p>Your scheduled report <strong>$safeReportName</strong> is attached to this email.</p>
                <p>Thanks,<br>$siteName</p>
            ")
            ->attach($attachmentPath)
            ->send();
    }

    /**
     * Send admin alert
     */
    public static function adminAlert(string $subject, string $message): bool {
        $adminEmail = getSetting('admin_email', '');
        if (empty($adminEmail)) {
            return false;
        }

        $siteName = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Silo', ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return Mail::create()
            ->to($adminEmail)
            ->subject("[$siteName Admin] $subject")
            ->body("
                <h2>Admin Alert</h2>
                <p>$safeMessage</p>
                <p>---<br>$siteName Admin System</p>
            ")
            ->send();
    }
}

// ========================================
// Helper Functions
// ========================================

/**
 * Send an email
 */
function send_mail(string $to, string $subject, string $body): bool {
    return Mail::create()
        ->to($to)
        ->subject($subject)
        ->body($body)
        ->send();
}

/**
 * Queue an email
 */
function queue_mail(string $to, string $subject, string $body): int {
    return Mail::create()
        ->to($to)
        ->subject($subject)
        ->body($body)
        ->queue();
}
