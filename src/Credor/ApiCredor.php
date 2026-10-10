<?php

declare(strict_types=1);

namespace Autoatendimento\Credor;

use Autoatendimento\Relogio;
use PDO;
use RuntimeException;

/**
 * Cliente da API do credor (contrato em docs/CREDOR_API.md).
 *
 * Traduz o JSON do credor (campos em inglês, valores em reais com ponto
 * flutuante, datas com hora) para arrays simples do portal, com dinheiro em
 * centavos inteiros e datas em Y-m-d. O resto do portal não conhece o
 * formato do credor.
 *
 * Resiliência: token OAuth2 em cache no banco, renovação automática em 401,
 * até 2 novas tentativas em 429/5xx/falha de rede com espera crescente
 * (respeitando Retry-After), e erro tipado quando nada disso resolve.
 */
class ApiCredor
{
    private const TENTATIVAS = 3;

    /** @var \Closure(float): void */
    private \Closure $esperar;

    public function __construct(
        private readonly Transporte $transporte,
        private readonly PDO $pdo,
        private readonly string $url,
        private readonly string $usuario,
        private readonly string $senha,
        private readonly Relogio $relogio = new Relogio(),
        ?\Closure $esperar = null,
    ) {
        $this->esperar = $esperar ?? static function (float $segundos): void {
            usleep((int) ($segundos * 1_000_000));
        };
    }

    /**
     * @return array{nome: string, telefones: list<array{id: string, numero: string, mascara: string, tipo: string}>, emails: list<string>}|null
     */
    public function devedor(string $cpf): ?array
    {
        $resposta = $this->requisitar('GET', '/v1/devedores/' . $cpf);
        if ($resposta['status'] === 404) {
            return null;
        }
        $dados = $this->json($resposta);

        $telefones = [];
        foreach ((array) ($dados['RecordsPhones'] ?? []) as $telefone) {
            // Telefone marcado como incorreto pelo credor não recebe código.
            if (($telefone['Status'] ?? '') === 'I') {
                continue;
            }
            $numero = preg_replace('/\D+/', '', (string) ($telefone['DDD'] ?? '') . (string) ($telefone['PhoneNumber'] ?? '')) ?? '';
            if (strlen($numero) < 10) {
                continue;
            }
            $telefones[] = [
                'id' => (string) ($telefone['PhoneID'] ?? ''),
                'numero' => $numero,
                'mascara' => self::mascararTelefone($numero),
                'tipo' => (string) ($telefone['PhoneTypeAlias'] ?? 'Telefone'),
            ];
        }

        return [
            'nome' => trim((string) ($dados['Name'] ?? '')),
            'telefones' => $telefones,
            'emails' => array_values(array_filter(array_map(
                static fn (array $email): string => (string) ($email['MailAddress'] ?? ''),
                (array) ($dados['RecordsMails'] ?? []),
            ))),
        ];
    }

    /**
     * @return list<array{contrato: string, produto: string, valor: int, desde: string, em_acordo: bool}>
     */
    public function contratos(string $cpf): array
    {
        $resposta = $this->requisitar('GET', '/v1/devedores/' . $cpf . '/contratos');
        if ($resposta['status'] === 404) {
            return [];
        }
        $lista = [];
        foreach ($this->json($resposta) as $contrato) {
            $lista[] = [
                'contrato' => (string) $contrato['Contract'],
                'produto' => (string) ($contrato['Product'] ?? 'Contrato'),
                'valor' => self::centavos($contrato['Amount'] ?? 0),
                'desde' => substr((string) ($contrato['DebtDate'] ?? ''), 0, 10),
                'em_acordo' => mb_strtolower((string) ($contrato['StatusDescription'] ?? '')) === 'em acordo',
            ];
        }
        return $lista;
    }

    /**
     * Opções de pagamento que o credor oferece para o contrato.
     *
     * @return array{valor_divida: int, opcoes: list<array{id: int, parcelas: int, valor_parcela: int, total: int, desconto: int}>, datas: list<string>}
     */
    public function propostas(string $cpf, string $contrato): array
    {
        $dados = $this->json($this->requisitar('GET', '/v1/devedores/' . $cpf . '/contratos/' . rawurlencode($contrato) . '/propostas'));
        $divida = self::centavos($dados['Amount'] ?? 0);

        $opcoes = [];
        foreach ((array) ($dados['ListProposalType'] ?? []) as $proposta) {
            [$parcelas, $valorParcela] = self::lerDescricaoProposta((string) ($proposta['ProposalTypeDescription'] ?? ''));
            if ($parcelas === 0) {
                continue;
            }
            $total = $parcelas * $valorParcela;
            $opcoes[] = [
                'id' => (int) $proposta['ProposalTypeID'],
                'parcelas' => $parcelas,
                'valor_parcela' => $valorParcela,
                'total' => $total,
                'desconto' => max(0, $divida - $total),
            ];
        }
        usort($opcoes, static fn (array $a, array $b): int => $a['parcelas'] <=> $b['parcelas']);

        return [
            'valor_divida' => $divida,
            'opcoes' => $opcoes,
            'datas' => array_values(array_map(static fn ($d): string => substr((string) $d, 0, 10), (array) ($dados['ListInstallmentDate'] ?? []))),
        ];
    }

    /**
     * @return array{id: string, situacao: string, total: int, data: string, parcelas: list<array{id: int, descricao: string, valor: int, vencimento: string, situacao: string}>}|null
     */
    public function acordo(string $cpf, string $contrato): ?array
    {
        $resposta = $this->requisitar('GET', '/v1/devedores/' . $cpf . '/contratos/' . rawurlencode($contrato) . '/acordo');
        if ($resposta['status'] === 404) {
            return null;
        }
        $acordo = (array) ($this->json($resposta)['Agreement'] ?? []);
        if ($acordo === []) {
            return null;
        }

        $parcelas = [];
        foreach ((array) ($acordo['ListInstallments'] ?? []) as $parcela) {
            $parcelas[] = [
                'id' => (int) $parcela['InstallmentID'],
                'descricao' => (string) ($parcela['InstallmentDescription'] ?? ''),
                'valor' => self::centavos($parcela['Amount'] ?? 0),
                'vencimento' => substr((string) ($parcela['InstallmentDate'] ?? ''), 0, 10),
                'situacao' => (string) ($parcela['Status'] ?? ''),
            ];
        }

        return [
            'id' => (string) ($acordo['AgreementID'] ?? ''),
            'situacao' => (string) ($acordo['AgreementStatus'] ?? ''),
            'total' => self::centavos($acordo['AgreementAmount'] ?? 0),
            'data' => substr((string) ($acordo['AgreementDate'] ?? ''), 0, 10),
            'parcelas' => $parcelas,
        ];
    }

    /** Grava o acordo no credor e devolve o número dele. */
    public function fecharAcordo(string $cpf, string $contrato, int $opcaoId, string $primeiroVencimento): string
    {
        $resposta = $this->requisitar('POST', '/v1/acordos', [
            'DocNumber' => $cpf,
            'Contract' => $contrato,
            'NegotiationID' => $opcaoId,
            'InstallmentDate' => $primeiroVencimento,
        ]);
        $dados = $this->json($resposta, aceitarErro: true);

        return match (true) {
            $resposta['status'] === 409 => throw ErroCredor::recusado('Já existe um acordo ativo para este contrato.', ErroCredor::ACORDO_EXISTENTE),
            $resposta['status'] >= 400 => throw ErroCredor::recusado((string) ($dados['Message'] ?? 'O credor recusou a proposta.')),
            default => (string) ($dados['AgreementID'] ?? ''),
        };
    }

    /** @return array{linha: string, arquivo: string, pdf: string}|null */
    public function boleto(int $parcelaId): ?array
    {
        $resposta = $this->requisitar('GET', '/v1/parcelas/' . $parcelaId . '/boleto');
        if ($resposta['status'] === 404) {
            return null;
        }
        $dados = $this->json($resposta);
        $pdf = base64_decode((string) ($dados['Base64'] ?? ''), true);
        if ($pdf === false || !str_starts_with($pdf, '%PDF')) {
            throw ErroCredor::indisponivel('boleto veio em formato inesperado');
        }
        return [
            'linha' => (string) ($dados['DigitableLine'] ?? ''),
            'arquivo' => preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($dados['FileName'] ?? '')) ?: 'boleto-' . $parcelaId . '.pdf',
            'pdf' => $pdf,
        ];
    }

    /** Envia um telefone ou e-mail informado pela pessoa, como "não confirmado". */
    public function incluirContato(string $cpf, string $tipo, string $valor): void
    {
        $corpo = $tipo === 'telefone'
            ? ['DocNumber' => $cpf, 'DDD' => substr($valor, 0, 2), 'PhoneNumber' => substr($valor, 2), 'PhoneType' => '4', 'Status' => 'P']
            : ['DocNumber' => $cpf, 'MailAddress' => $valor, 'EmailType' => '1', 'Status' => 'P'];
        $this->json($this->requisitar('POST', '/v1/contatos/' . ($tipo === 'telefone' ? 'telefones' : 'emails'), $corpo));
    }

    /* ---- HTTP ------------------------------------------------------------ */

    /**
     * @param array<string, mixed>|null $corpo
     * @return array{status: int, cabecalhos: array<string, string>, corpo: string}
     */
    private function requisitar(string $metodo, string $caminho, ?array $corpo = null): array
    {
        $tokenRenovado = false;
        $ultimoErro = '';

        for ($tentativa = 1; $tentativa <= self::TENTATIVAS; $tentativa++) {
            try {
                $resposta = $this->transporte->enviar($metodo, rtrim($this->url, '/') . $caminho, [
                    'Authorization' => 'Bearer ' . $this->token(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ], $corpo === null ? '' : (string) json_encode($corpo, JSON_UNESCAPED_UNICODE));
            } catch (RuntimeException $erro) {
                $ultimoErro = $erro->getMessage();
                $this->aguardar($tentativa, null);
                continue;
            }

            if ($resposta['status'] === 401 && !$tokenRenovado) {
                $this->esquecerToken();
                $tokenRenovado = true;
                $tentativa--;
                continue;
            }
            if ($resposta['status'] === 429 || $resposta['status'] >= 500) {
                $ultimoErro = 'HTTP ' . $resposta['status'];
                if ($tentativa < self::TENTATIVAS) {
                    $this->aguardar($tentativa, $resposta['cabecalhos']['retry-after'] ?? null);
                }
                continue;
            }
            return $resposta;
        }

        throw ErroCredor::indisponivel($ultimoErro);
    }

    private function aguardar(int $tentativa, ?string $retryAfter): void
    {
        $segundos = is_numeric($retryAfter) ? (float) $retryAfter : 0.3 * (2 ** ($tentativa - 1));
        ($this->esperar)(min(3.0, max(0.0, $segundos)));
    }

    private function token(): string
    {
        $agora = $this->relogio->agora();
        $consulta = $this->pdo->prepare('SELECT valor FROM cache WHERE chave = ? AND expira_em > ?');
        $consulta->execute([$this->chaveToken(), $agora]);
        $token = $consulta->fetchColumn();
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $resposta = $this->transporte->enviar('POST', rtrim($this->url, '/') . '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query(['grant_type' => 'password', 'username' => $this->usuario, 'password' => $this->senha]));
        $dados = json_decode($resposta['corpo'], true);
        if ($resposta['status'] !== 200 || !is_array($dados) || empty($dados['access_token'])) {
            throw ErroCredor::indisponivel('autenticação recusada (HTTP ' . $resposta['status'] . ')');
        }

        $this->esquecerToken();
        $this->pdo->prepare('INSERT INTO cache (chave, valor, expira_em) VALUES (?, ?, ?)')
            ->execute([$this->chaveToken(), (string) $dados['access_token'], $agora + max(60, (int) ($dados['expires_in'] ?? 3600) - 60)]);
        return (string) $dados['access_token'];
    }

    private function esquecerToken(): void
    {
        $this->pdo->prepare('DELETE FROM cache WHERE chave = ?')->execute([$this->chaveToken()]);
    }

    private function chaveToken(): string
    {
        return 'token:' . hash('sha256', $this->url . '|' . $this->usuario);
    }

    /**
     * @param array{status: int, corpo: string} $resposta
     * @return array<mixed>
     */
    private function json(array $resposta, bool $aceitarErro = false): array
    {
        $dados = json_decode($resposta['corpo'], true);
        if (!is_array($dados)) {
            throw ErroCredor::indisponivel('resposta não é JSON (HTTP ' . $resposta['status'] . ')');
        }
        if (!$aceitarErro && $resposta['status'] >= 400) {
            throw ErroCredor::recusado((string) ($dados['Message'] ?? 'Erro ' . $resposta['status']));
        }
        return $dados;
    }

    /* ---- conversões ------------------------------------------------------ */

    /** Reais (float ou "1234.56") para centavos, sem erro de arredondamento binário. */
    public static function centavos(mixed $reais): int
    {
        return (int) round(((float) $reais) * 100);
    }

    /**
     * O credor descreve as propostas em texto ("3x de R$ 1.234,56", "À vista de R$ 900,00").
     * @return array{0: int, 1: int} [parcelas, valor da parcela em centavos]
     */
    public static function lerDescricaoProposta(string $descricao): array
    {
        if (preg_match('/^\s*(\d+)\s*x\s+de\s+R\$\s*([\d.]+,\d{2})/iu', $descricao, $m) === 1) {
            return [(int) $m[1], self::centavosBr($m[2])];
        }
        if (preg_match('/vista.*?R\$\s*([\d.]+,\d{2})/iu', $descricao, $m) === 1) {
            return [1, self::centavosBr($m[1])];
        }
        return [0, 0];
    }

    private static function centavosBr(string $valor): int
    {
        return (int) str_replace(['.', ','], '', $valor);
    }

    public static function mascararTelefone(string $numero): string
    {
        $ddd = substr($numero, 0, 2);
        $final = substr($numero, -4);
        return strlen($numero) === 11 ? "({$ddd}) 9****-{$final}" : "({$ddd}) ****-{$final}";
    }
}
