<?php

declare(strict_types=1);

namespace Autoatendimento;

use PDO;

/**
 * Conexão e esquema. O portal guarda pouca coisa localmente: os dados da dívida
 * ficam no credor. Aqui ficam só códigos de verificação, limites de tentativa,
 * eventos do funil, chaves de idempotência e o cache do token da API.
 * CPF nunca é gravado em claro: só o HMAC dele.
 */
final class Database
{
    public static function conectar(string $dsn, string $usuario = '', string $senha = ''): PDO
    {
        $pdo = new PDO($dsn, $usuario ?: null, $senha ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 3000');
        }
        return $pdo;
    }

    public static function migrar(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $motor = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        $tabelas = [
            "CREATE TABLE IF NOT EXISTS codigos (
                id {$id},
                chave VARCHAR(64) NOT NULL,
                telefone_id VARCHAR(20) NOT NULL,
                codigo_hash VARCHAR(64) NOT NULL,
                expira_em INTEGER NOT NULL,
                enviado_em INTEGER NOT NULL,
                tentativas INTEGER NOT NULL DEFAULT 0,
                usado INTEGER NOT NULL DEFAULT 0
            ){$motor}",
            "CREATE TABLE IF NOT EXISTS limites (
                chave VARCHAR(120) NOT NULL,
                janela INTEGER NOT NULL,
                contagem INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (chave, janela)
            ){$motor}",
            "CREATE TABLE IF NOT EXISTS eventos (
                id {$id},
                tipo VARCHAR(40) NOT NULL,
                cpf_hash VARCHAR(64) NOT NULL,
                contrato VARCHAR(40) NULL,
                criado_em INTEGER NOT NULL
            ){$motor}",
            "CREATE TABLE IF NOT EXISTS formalizacoes (
                chave VARCHAR(64) NOT NULL PRIMARY KEY,
                cpf_hash VARCHAR(64) NOT NULL,
                contrato VARCHAR(40) NOT NULL,
                acordo_id VARCHAR(40) NOT NULL,
                criado_em INTEGER NOT NULL
            ){$motor}",
            "CREATE TABLE IF NOT EXISTS cache (
                chave VARCHAR(120) NOT NULL PRIMARY KEY,
                valor TEXT NOT NULL,
                expira_em INTEGER NOT NULL
            ){$motor}",
        ];
        foreach ($tabelas as $sql) {
            $pdo->exec($sql);
        }

        $indices = [
            'CREATE INDEX idx_codigos_chave ON codigos (chave, id)',
            'CREATE INDEX idx_eventos_tipo_data ON eventos (tipo, criado_em)',
        ];
        foreach ($indices as $sql) {
            try {
                $pdo->exec($sqlite ? str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $sql) : $sql);
            } catch (\PDOException) {
                // MySQL não tem IF NOT EXISTS para índice: o erro de "já existe" é esperado.
            }
        }
    }
}
