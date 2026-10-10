<?php

declare(strict_types=1);

/**
 * Gera o link personalizado de campanha para um CPF.
 * Uso: APP_SEGREDO=... php bin/link.php 52998224725 [dias de validade, padrão 7]
 */

use Autoatendimento\Config;
use Autoatendimento\Seguranca\Cpf;
use Autoatendimento\Seguranca\LinkAssinado;

require dirname(__DIR__) . '/vendor/autoload.php';

$cpf = Cpf::limpar($argv[1] ?? '');
$dias = max(1, (int) ($argv[2] ?? 7));
if (!Cpf::valido($cpf)) {
    fwrite(STDERR, "Informe um CPF válido. Ex.: php bin/link.php 52998224725 7\n");
    exit(1);
}

$config = Config::doAmbiente();
$token = (new LinkAssinado($config->segredo()))->gerar($cpf, time() + $dias * 86400);
echo rtrim($config->texto('APP_URL'), '/') . '/?l=' . $token . PHP_EOL;
