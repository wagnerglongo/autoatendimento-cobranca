<?php

declare(strict_types=1);

/**
 * Popula o painel da operação com 30 dias de eventos fictícios, para a
 * demonstração não começar vazia. Recusa rodar fora do MODO_DEMO.
 * Uso: MODO_DEMO=1 php bin/eventos-demo.php
 */

use Autoatendimento\Config;
use Autoatendimento\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::doAmbiente();
if (!$config->ligado('MODO_DEMO')) {
    fwrite(STDERR, "Só roda com MODO_DEMO=1.\n");
    exit(1);
}

$pdo = Database::conectar($config->texto('DB_DSN'), $config->texto('DB_USUARIO'), $config->texto('DB_SENHA'));
Database::migrar($pdo);
mt_srand(2026);

$etapas = ['cpf_consultado', 'codigo_enviado', 'login', 'proposta_vista', 'acordo_fechado', 'boleto_baixado'];
// Chance de seguir para a próxima etapa, a partir de cada uma.
$continua = [0.86, 0.82, 0.88, 0.55, 0.78];
$inserir = $pdo->prepare('INSERT INTO eventos (tipo, cpf_hash, contrato, criado_em) VALUES (?, ?, NULL, ?)');
$total = 0;

$pdo->beginTransaction();
for ($dia = 29; $dia >= 0; $dia--) {
    $base = strtotime("today -{$dia} days");
    $visitas = mt_rand(18, 42) + (in_array((int) date('N', $base), [1, 2], true) ? 12 : 0);
    for ($v = 0; $v < $visitas; $v++) {
        $pessoa = hash('sha256', 'demo-' . $dia . '-' . $v);
        $instante = $base + mt_rand(8 * 3600, 21 * 3600);
        foreach ($etapas as $i => $etapa) {
            $inserir->execute([$etapa, $pessoa, $instante + $i * 90]);
            $total++;
            if ($i < count($continua) && mt_rand() / mt_getrandmax() > $continua[$i]) {
                break;
            }
        }
    }
}
$pdo->commit();
echo "{$total} eventos criados.\n";
