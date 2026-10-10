<?php

declare(strict_types=1);

use Autoatendimento\App;
use Autoatendimento\Config;
use Autoatendimento\Credor\ApiCredor;
use Autoatendimento\Credor\TransporteCurl;
use Autoatendimento\Database;
use Autoatendimento\Sms\SmsEmArquivo;
use Autoatendimento\Sms\SmsWebhook;

date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');

$raiz = dirname(__DIR__);

// Servidor embutido do PHP: arquivos estáticos saem direto.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) {
    return false;
}

require $raiz . '/vendor/autoload.php';

$config = Config::doAmbiente();
if ($config->texto('APP_SEGREDO') === '' && $config->ligado('MODO_DEMO')) {
    // Só na demonstração local: gera um segredo uma vez e reaproveita.
    $arquivoSegredo = $raiz . '/storage/.segredo-demo';
    if (!is_file($arquivoSegredo)) {
        file_put_contents($arquivoSegredo, bin2hex(random_bytes(32)));
    }
    putenv('APP_SEGREDO=' . trim((string) file_get_contents($arquivoSegredo)));
    $config = Config::doAmbiente();
}

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('portal');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => $https,
    'use_strict_mode' => true,
    'gc_maxlifetime' => 1800,
]);

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

try {
    $pdo = Database::conectar($config->texto('DB_DSN'), $config->texto('DB_USUARIO'), $config->texto('DB_SENHA'));
    $marca = $raiz . '/storage/.esquema-v1';
    if (!is_file($marca)) {
        Database::migrar($pdo);
        @touch($marca);
    }

    $transporte = new TransporteCurl($config->inteiro('CREDOR_TIMEOUT'));
    $credor = new ApiCredor($transporte, $pdo, $config->texto('CREDOR_URL'), $config->texto('CREDOR_USUARIO'), $config->texto('CREDOR_SENHA'));
    $sms = $config->texto('SMS_DRIVER') === 'webhook'
        ? new SmsWebhook($transporte, $config->texto('SMS_WEBHOOK_URL'), $config->texto('SMS_WEBHOOK_TOKEN'))
        : new SmsEmArquivo($raiz . '/storage/sms.log');

    $app = new App($pdo, $config, $credor, $sms, $_SESSION, $raiz . '/templates');
    $resposta = $app->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        $_GET,
        $_POST,
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    );
} catch (Throwable $erro) {
    error_log('[portal] ' . $erro::class . ': ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title><p style="font:16px system-ui;padding:2rem">Algo deu errado do nosso lado. Tente de novo em alguns minutos.</p>';
    exit;
}

http_response_code($resposta->status);
foreach ($resposta->cabecalhos as $nome => $valor) {
    header($nome . ': ' . $valor);
}
echo $resposta->corpo;
