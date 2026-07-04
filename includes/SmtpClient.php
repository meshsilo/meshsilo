<?php

/**
 * SMTP Client
 *
 * Focused SMTP transport extracted from Mail. Owns the raw protocol
 * exchange over a stream socket: connection (with an authenticated TLS
 * context), STARTTLS negotiation, AUTH LOGIN, envelope (MAIL FROM / RCPT
 * TO), and the DATA phase including RFC 5321 dot-stuffing.
 *
 * The caller (Mail) is responsible for building the raw RFC 5322 message
 * (headers + body); this class only transports it.
 */
class SmtpClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;

    public function __construct(string $host, int $port, string $username, string $password, string $encryption)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = $encryption;
    }

    /**
     * Transport a pre-built raw message to the given recipients.
     *
     * @param string   $rawMessage The assembled RFC 5322 headers + body.
     * @param string   $from       Envelope sender (MAIL FROM).
     * @param string[] $recipients Envelope recipients (RCPT TO): every
     *                             To, Cc, and Bcc address. Bcc is delivered
     *                             here via the envelope only, never as a header.
     */
    public function send(string $rawMessage, string $from, array $recipients): void
    {
        $host = $this->host;
        $port = $this->port;
        $username = $this->username;
        $password = $this->password;
        $encryption = $this->encryption;

        // Connect to SMTP server with an authenticated TLS context
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
            ],
        ]);

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
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                fclose($socket);
                throw new \Exception("Failed to establish STARTTLS encryption with SMTP server");
            }
            $this->smtpCommand($socket, "EHLO " . gethostname());
        }

        // Authenticate
        if ($username && $password) {
            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpCommand($socket, base64_encode($username));
            $this->smtpCommand($socket, base64_encode($password));
        }

        // Send email
        $this->smtpCommand($socket, "MAIL FROM:<{$from}>");

        foreach ($recipients as $recipient) {
            $this->smtpCommand($socket, "RCPT TO:<{$recipient}>");
        }

        $this->smtpCommand($socket, "DATA");

        // Send headers and body
        $message = $rawMessage;
        // Normalize to CRLF line endings and dot-stuff per RFC 5321 so a body
        // line beginning with '.' cannot truncate DATA or smuggle commands.
        $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);
        $message = preg_replace('/(^|\r\n)\./', '$1..', $message);
        fwrite($socket, $message . "\r\n.\r\n");
        $this->smtpGetResponse($socket);

        // Quit
        $this->smtpCommand($socket, "QUIT");
        fclose($socket);
    }

    /**
     * Send SMTP command
     */
    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpGetResponse($socket);
    }

    /**
     * Get SMTP response
     */
    private function smtpGetResponse($socket): string
    {
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
}
