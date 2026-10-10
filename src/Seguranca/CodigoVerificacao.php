<?php

declare(strict_types=1);

namespace Autoatendimento\Seguranca;

use Autoatendimento\Relogio;
use PDO;

/**
 * Código de 6 dígitos enviado por SMS para confirmar que a pessoa tem acesso
 * ao telefone cadastrado no credor.
 *
 * - Só o HMAC do código vai para o banco.
 * - Vale 5 minutos e aceita no máximo 5 tentativas; depois disso é preciso pedir outro.
 * - Um novo código só pode ser pedido 60 segundos depois do anterior.
 * - Pedir um código novo invalida os anteriores.
 */
final class CodigoVerificacao
{
    public const VALIDADE = 300;
    public const MAX_TENTATIVAS = 5;
    public const INTERVALO_REENVIO = 60;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $segredo,
        private readonly Relogio $relogio = new Relogio(),
    ) {
    }

    /**
     * Gera e registra um código. Devolve o código em claro (para o SMS) ou,
     * se ainda não pode reenviar, quantos segundos faltam.
     *
     * @return array{codigo: ?string, aguardar: int}
     */
    public function emitir(string $chave, string $telefoneId): array
    {
        $agora = $this->relogio->agora();
        $ultimo = $this->ultimo($chave);
        if ($ultimo !== null && $agora - (int) $ultimo['enviado_em'] < self::INTERVALO_REENVIO) {
            return ['codigo' => null, 'aguardar' => self::INTERVALO_REENVIO - ($agora - (int) $ultimo['enviado_em'])];
        }

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->pdo->prepare('UPDATE codigos SET usado = 1 WHERE chave = ? AND usado = 0')->execute([$chave]);
        $this->pdo->prepare(
            'INSERT INTO codigos (chave, telefone_id, codigo_hash, expira_em, enviado_em) VALUES (?, ?, ?, ?, ?)'
        )->execute([$chave, $telefoneId, $this->hash($chave, $codigo), $agora + self::VALIDADE, $agora]);

        return ['codigo' => $codigo, 'aguardar' => 0];
    }

    /** @return 'ok'|'invalido'|'expirado'|'bloqueado'|'inexistente' */
    public function verificar(string $chave, string $codigo): string
    {
        $registro = $this->ultimo($chave);
        if ($registro === null || (int) $registro['usado'] === 1) {
            return 'inexistente';
        }
        if ((int) $registro['tentativas'] >= self::MAX_TENTATIVAS) {
            return 'bloqueado';
        }
        if ($this->relogio->agora() > (int) $registro['expira_em']) {
            return 'expirado';
        }

        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        if (hash_equals((string) $registro['codigo_hash'], $this->hash($chave, $codigo))) {
            $this->pdo->prepare('UPDATE codigos SET usado = 1 WHERE id = ?')->execute([$registro['id']]);
            return 'ok';
        }

        $this->pdo->prepare('UPDATE codigos SET tentativas = tentativas + 1 WHERE id = ?')->execute([$registro['id']]);
        return (int) $registro['tentativas'] + 1 >= self::MAX_TENTATIVAS ? 'bloqueado' : 'invalido';
    }

    /** Segundos até poder pedir outro código (0 = já pode). */
    public function esperaParaReenvio(string $chave): int
    {
        $ultimo = $this->ultimo($chave);
        if ($ultimo === null) {
            return 0;
        }
        return max(0, self::INTERVALO_REENVIO - ($this->relogio->agora() - (int) $ultimo['enviado_em']));
    }

    /** @return array<string, mixed>|null */
    private function ultimo(string $chave): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM codigos WHERE chave = ? ORDER BY id DESC LIMIT 1');
        $consulta->execute([$chave]);
        $linha = $consulta->fetch();
        return $linha ?: null;
    }

    private function hash(string $chave, string $codigo): string
    {
        return hash_hmac('sha256', $chave . '|' . $codigo, $this->segredo);
    }
}
