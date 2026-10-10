<?php

declare(strict_types=1);

namespace Autoatendimento\Seguranca;

use Autoatendimento\Relogio;
use PDO;
use PDOException;

/**
 * Limite de tentativas por janela fixa, guardado no banco para valer entre
 * processos do PHP-FPM/Apache. Usado por IP (consulta de CPF) e por CPF
 * (envio de código), o que dificulta enumeração e abuso de SMS.
 */
final class LimiteTentativas
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Relogio $relogio = new Relogio(),
    ) {
    }

    /** Conta a tentativa e diz se ela está dentro do limite. */
    public function permitir(string $chave, int $maximo, int $janelaSegundos): bool
    {
        $janela = intdiv($this->relogio->agora(), $janelaSegundos);

        try {
            $this->pdo->prepare('INSERT INTO limites (chave, janela, contagem) VALUES (?, ?, 1)')->execute([$chave, $janela]);
        } catch (PDOException) {
            $this->pdo->prepare('UPDATE limites SET contagem = contagem + 1 WHERE chave = ? AND janela = ?')->execute([$chave, $janela]);
        }

        $consulta = $this->pdo->prepare('SELECT contagem FROM limites WHERE chave = ? AND janela = ?');
        $consulta->execute([$chave, $janela]);
        return (int) $consulta->fetchColumn() <= $maximo;
    }

    /** Remove janelas antigas (chamado de vez em quando, não a cada requisição). */
    public function limpar(int $janelaSegundos): void
    {
        $this->pdo->prepare('DELETE FROM limites WHERE janela < ?')
            ->execute([intdiv($this->relogio->agora(), $janelaSegundos) - 1]);
    }
}
