<?php

declare(strict_types=1);

namespace Autoatendimento;

use PDO;

/**
 * Registro do funil de autoatendimento (sem dado pessoal: o CPF entra como HMAC).
 * Responde a pergunta que a operação faz: de quem entrou no portal, quantos
 * chegaram a fechar acordo e onde os outros pararam.
 */
final class Eventos
{
    public const ETAPAS = [
        'cpf_consultado' => 'Consultaram o CPF',
        'codigo_enviado' => 'Receberam o código',
        'login' => 'Confirmaram o código',
        'proposta_vista' => 'Viram as propostas',
        'acordo_fechado' => 'Fecharam acordo',
        'boleto_baixado' => 'Baixaram boleto',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Relogio $relogio = new Relogio(),
    ) {
    }

    public function registrar(string $tipo, string $cpfHash, ?string $contrato = null): void
    {
        $this->pdo->prepare('INSERT INTO eventos (tipo, cpf_hash, contrato, criado_em) VALUES (?, ?, ?, ?)')
            ->execute([$tipo, $cpfHash, $contrato, $this->relogio->agora()]);
    }

    /**
     * Pessoas distintas em cada etapa nos últimos $dias.
     * @return list<array{etapa: string, rotulo: string, pessoas: int, taxa: float}>
     */
    public function funil(int $dias): array
    {
        $desde = $this->relogio->agora() - $dias * 86400;
        $consulta = $this->pdo->prepare('SELECT tipo, COUNT(DISTINCT cpf_hash) AS pessoas FROM eventos WHERE criado_em >= ? GROUP BY tipo');
        $consulta->execute([$desde]);
        $contagem = array_column($consulta->fetchAll(), 'pessoas', 'tipo');

        $inicio = (int) ($contagem['cpf_consultado'] ?? 0);
        $funil = [];
        foreach (self::ETAPAS as $etapa => $rotulo) {
            $pessoas = (int) ($contagem[$etapa] ?? 0);
            $funil[] = [
                'etapa' => $etapa,
                'rotulo' => $rotulo,
                'pessoas' => $pessoas,
                'taxa' => $inicio > 0 ? round($pessoas / $inicio * 100, 1) : 0.0,
            ];
        }
        return $funil;
    }

    /** Acordos fechados por dia, para o gráfico do painel. @return array<string, int> */
    public function acordosPorDia(int $dias): array
    {
        $hoje = $this->relogio->hoje()->setTime(0, 0);
        $serie = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $serie[$hoje->modify("-{$i} day")->format('Y-m-d')] = 0;
        }
        $consulta = $this->pdo->prepare("SELECT criado_em FROM eventos WHERE tipo = 'acordo_fechado' AND criado_em >= ?");
        $consulta->execute([$hoje->modify('-' . ($dias - 1) . ' day')->getTimestamp()]);
        foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $instante) {
            $dia = (new \DateTimeImmutable('@' . $instante))->setTimezone($hoje->getTimezone())->format('Y-m-d');
            if (isset($serie[$dia])) {
                $serie[$dia]++;
            }
        }
        return $serie;
    }
}
