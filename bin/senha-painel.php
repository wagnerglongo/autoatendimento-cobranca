<?php

declare(strict_types=1);

/**
 * Gera o hash para PAINEL_SENHA_HASH. A senha é lida sem eco no terminal.
 * Uso: php bin/senha-painel.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
fwrite(STDOUT, 'Senha do painel (mínimo 12 caracteres): ');
$semEco = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
if ($semEco) {
    shell_exec('stty -echo');
}
$senha = trim((string) fgets(STDIN));
if ($semEco) {
    shell_exec('stty echo');
}
fwrite(STDOUT, PHP_EOL);

if (strlen($senha) < 12) {
    fwrite(STDERR, "Use pelo menos 12 caracteres.\n");
    exit(1);
}
echo password_hash($senha, PASSWORD_DEFAULT) . PHP_EOL;
