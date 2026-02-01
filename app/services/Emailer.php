<?php
declare(strict_types=1);

final class Emailer {

    /**
     * @return array{ok:bool,error:?string}
     */
    public static function sendText(string $to, string $subject, string $textBody): array {
        $transport = strtolower((string)cfg('MAIL_TRANSPORT', 'mail'));
        if ($transport === 'smtp') {
            return self::sendSmtp($to, $subject, $textBody);
        }
        if ($transport === 'api') {
            return self::sendApi($to, $subject, $textBody);
        }
        return self::sendMailFunction($to, $subject, $textBody);
    }

    /** @return array{ok:bool,error:?string} */
    private static function sendMailFunction(string $to, string $subject, string $textBody): array {
        $from = cfg('MAIL_FROM', 'no-reply@example.com');
        $fromName = cfg('MAIL_FROM_NAME', 'Certisent');
        $headers = [];
        $headers[] = "From: {$fromName} <{$from}>";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/plain; charset=utf-8";
        $ok = (bool)@mail($to, $subject, $textBody, implode("\r\n", $headers));
        return ['ok'=>$ok,'error'=>$ok?null:'mail_failed'];
    }

    /** @return array{ok:bool,error:?string} */
    private static function sendSmtp(string $to, string $subject, string $textBody): array {
        $host = (string)cfg('SMTP_HOST', '');
        $port = (int)cfg('SMTP_PORT', 587);
        $user = (string)cfg('SMTP_USER', '');
        $pass = (string)cfg('SMTP_PASS', '');
        $enc  = strtolower((string)cfg('SMTP_ENCRYPTION', 'starttls'));
        $timeout = (int)cfg('SMTP_TIMEOUT_SECS', 12);

        if ($host === '') {
            return ['ok'=>false,'error'=>'smtp_host_missing'];
        }

        $from = (string)cfg('MAIL_FROM', 'no-reply@example.com');
        $fromName = (string)cfg('MAIL_FROM_NAME', 'Certisent');

        try {
            $c = new SmtpClient($host, $port, $enc, $timeout);
            $c->connect();
            if ($user !== '' || $pass !== '') {
                $c->authLogin($user, $pass);
            }
            $c->send($from, $fromName, $to, $subject, $textBody);
            $c->quit();
            return ['ok'=>true,'error'=>null];
        } catch (Throwable $e) {
            return ['ok'=>false,'error'=>'smtp_error: '.$e->getMessage()];
        }
    }

    /** @return array{ok:bool,error:?string} */
    private static function sendApi(string $to, string $subject, string $textBody): array {
        $url = (string)cfg('MAIL_API_URL', '');
        $token = (string)cfg('MAIL_API_TOKEN', '');
        if ($url === '') {
            return ['ok'=>false,'error'=>'mail_api_url_missing'];
        }

        $payload = json_encode([
            'to' => $to,
            'subject' => $subject,
            'text' => $textBody,
            'from' => (string)cfg('MAIL_FROM', ''),
            'from_name' => (string)cfg('MAIL_FROM_NAME', ''),
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok'=>false,'error'=>'curl_init_failed'];
        }
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok'=>false,'error'=>'mail_api_curl_error: '.$err];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'error'=>'mail_api_http_'.$code];
        }
        return ['ok'=>true,'error'=>null];
    }
}
