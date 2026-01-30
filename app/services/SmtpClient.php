<?php
declare(strict_types=1);

/**
 * Minimal SMTP client for shared-hosting use (no external deps).
 * Supports: plain, STARTTLS, SMTPS; AUTH LOGIN.
 */
final class SmtpClient {
    private string $host;
    private int $port;
    private string $encryption; // '', 'starttls', 'ssl'
    private int $timeout;
    private $sock = null;

    public function __construct(string $host, int $port, string $encryption, int $timeout = 12) {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = strtolower(trim($encryption));
        $this->timeout = $timeout;
    }

    /** @return array{0:bool,1:?string} */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText): array {
        try {
            $ok = $this->connect();
            if (!$ok) return [false, 'smtp_connect_failed'];

            $this->cmdExpect('EHLO certinel', 250);

            if ($this->encryption === 'starttls') {
                $this->cmdExpect('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return [false, 'starttls_failed'];
                }
                $this->cmdExpect('EHLO certinel', 250);
            }

            $user = (string)cfg('SMTP_USER', '');
            $pass = (string)cfg('SMTP_PASS', '');
            if ($user !== '') {
                $this->cmdExpect('AUTH LOGIN', 334);
                $this->cmdExpect(base64_encode($user), 334);
                $this->cmdExpect(base64_encode($pass), 235);
            }

            $this->cmdExpect('MAIL FROM:<' . $fromEmail . '>', 250);
            $this->cmdExpect('RCPT TO:<' . $toEmail . '>', 250);
            $this->cmdExpect('DATA', 354);

            $headers = [];
            $headers[] = 'From: ' . $this->encodeHeaderName($fromName) . ' <' . $fromEmail . '>';
            $headers[] = 'To: <' . $toEmail . '>';
            $headers[] = 'Subject: ' . $this->encodeHeader($subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/plain; charset=utf-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
            // dot-stuff
            $data = preg_replace('/\r\n\./', "\r\n..", $data);
            $this->write($data . "\r\n.");
            $this->expect(250);

            $this->cmdExpect('QUIT', 221);
            $this->close();
            return [true, null];
        } catch (Throwable $e) {
            $this->close();
            return [false, 'smtp_exception: ' . $e->getMessage()];
        }
    }

    private function connect(): bool {
        $target = $this->host;
        if ($this->encryption === 'ssl') {
            $target = 'ssl://' . $this->host;
        }
        $errno = 0; $errstr = '';
        $sock = @fsockopen($target, $this->port, $errno, $errstr, $this->timeout);
        if (!$sock) {
            return false;
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $code = $this->readCode();
        return $code === 220;
    }

    private function close(): void {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    private function write(string $line): void {
        if (!is_resource($this->sock)) {
            throw new RuntimeException('smtp_not_connected');
        }
        $line = rtrim($line, "\r\n");
        fwrite($this->sock, $line . "\r\n");
    }

    private function readLine(): string {
        if (!is_resource($this->sock)) {
            throw new RuntimeException('smtp_not_connected');
        }
        $line = fgets($this->sock, 2048);
        if ($line === false) {
            throw new RuntimeException('smtp_read_failed');
        }
        return rtrim($line, "\r\n");
    }

    private function readCode(): int {
        $line = $this->readLine();
        $code = (int)substr($line, 0, 3);
        // consume multiline responses
        while (strlen($line) >= 4 && $line[3] === '-') {
            $line = $this->readLine();
        }
        return $code;
    }

    private function expect(int $expected): void {
        $code = $this->readCode();
        if ($code !== $expected) {
            throw new RuntimeException('smtp_unexpected_code:' . $code . ' expected:' . $expected);
        }
    }

    private function cmdExpect(string $cmd, int $expected): void {
        $this->write($cmd);
        $this->expect($expected);
    }

    private function encodeHeader(string $s): string {
        // RFC 2047 minimal
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }

    private function encodeHeaderName(string $s): string {
        return $this->encodeHeader($s);
    }
}
