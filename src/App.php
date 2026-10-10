<?php

declare(strict_types=1);

namespace Autoatendimento;

use Autoatendimento\Credor\ApiCredor;
use Autoatendimento\Credor\ErroCredor;
use Autoatendimento\Seguranca\CodigoVerificacao;
use Autoatendimento\Seguranca\Cpf;
use Autoatendimento\Seguranca\Csrf;
use Autoatendimento\Seguranca\LimiteTentativas;
use Autoatendimento\Seguranca\LinkAssinado;
use Autoatendimento\Sms\EnviadorSms;
use PDO;

/**
 * Rotas e controladores do portal.
 *
 * Fluxo da pessoa: CPF (ou link de campanha) → escolhe um dos telefones que o
 * credor tem dela → recebe código por SMS → vê os contratos → escolhe uma
 * proposta e a data do primeiro vencimento → confirma → acordo gravado no
 * credor → baixa os boletos.
 *
 * O portal não guarda dívida nenhuma: tudo vem do credor na hora, e o que fica
 * na sessão é só o necessário para a navegação.
 */
final class App
{
    private const SESSAO_IDENTIFICACAO = 900;   // 15 min para concluir CPF → código
    private const SESSAO_INATIVIDADE = 900;     // 15 min sem uso encerra a sessão

    private readonly View $view;
    private readonly CodigoVerificacao $codigos;
    private readonly LimiteTentativas $limites;
    private readonly Eventos $eventos;
    private readonly string $segredo;
    /** @var array<string, mixed> */
    private array $sessao;
    /** @var \Closure(): void */
    private \Closure $renovarIdSessao;

    /** @param array<string, mixed> $sessao referência para $_SESSION */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly ApiCredor $credor,
        private readonly EnviadorSms $sms,
        array &$sessao,
        string $templates,
        private readonly Relogio $relogio = new Relogio(),
        ?\Closure $renovarIdSessao = null,
    ) {
        $this->sessao = &$sessao;
        $this->segredo = $config->segredo();
        $this->codigos = new CodigoVerificacao($pdo, $this->segredo, $relogio);
        $this->limites = new LimiteTentativas($pdo, $relogio);
        $this->eventos = new Eventos($pdo, $relogio);
        $this->renovarIdSessao = $renovarIdSessao ?? static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        };
        $this->view = new View($templates, [
            'appNome' => $config->texto('APP_NOME'),
            'empresa' => $config->texto('APP_EMPRESA'),
            'demo' => $config->ligado('MODO_DEMO'),
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function handle(string $metodo, string $caminho, array $query = [], array $post = [], string $ip = '0.0.0.0'): Resposta
    {
        $caminho = '/' . trim($caminho, '/');
        $this->expirarSessao();
        $this->view->definir('csrf', Csrf::token($this->sessao));
        $this->view->definir('pessoa', $this->sessao['nome'] ?? null);

        if ($metodo === 'POST' && !Csrf::valido($this->sessao, $post['_csrf'] ?? null)) {
            return $this->pagina('erro', ['titulo' => 'Página expirada', 'mensagem' => 'Por segurança, recarregue a página e tente de novo.'], 419);
        }

        try {
            return match (true) {
                $metodo === 'GET' && $caminho === '/' => $this->inicio($query),
                $metodo === 'POST' && $caminho === '/entrar' => $this->entrar($post, $ip),
                $metodo === 'GET' && $caminho === '/telefone' => $this->escolherTelefone(),
                $metodo === 'POST' && $caminho === '/telefone' => $this->enviarCodigo($post),
                $metodo === 'GET' && $caminho === '/codigo' => $this->formCodigo(),
                $metodo === 'POST' && $caminho === '/codigo' => $this->conferirCodigo($post),
                $metodo === 'POST' && $caminho === '/codigo/reenviar' => $this->enviarCodigo(['telefone' => $this->sessao['pre']['telefone_id'] ?? '']),
                $metodo === 'POST' && $caminho === '/sair' => $this->sair(),
                $metodo === 'GET' && $caminho === '/privacidade' => $this->pagina('privacidade'),
                $metodo === 'GET' && $caminho === '/termos' => $this->pagina('termos'),
                $metodo === 'GET' && $caminho === '/painel' => $this->painel(),
                $metodo === 'POST' && $caminho === '/painel' => $this->entrarPainel($post, $ip),
                str_starts_with($caminho, '/inicio') || str_starts_with($caminho, '/contratos') || str_starts_with($caminho, '/boletos') || $caminho === '/contatos'
                    => $this->areaLogada($metodo, $caminho, $post),
                default => $this->naoEncontrado(),
            };
        } catch (ErroCredor $erro) {
            if ($erro->getCode() !== ErroCredor::INDISPONIVEL) {
                throw $erro;
            }
            error_log('[portal] credor indisponível: ' . $erro->getMessage());
            return $this->pagina('erro', [
                'titulo' => 'Sistema temporariamente indisponível',
                'mensagem' => 'Não conseguimos falar com o sistema do credor agora. Tente de novo em alguns minutos; nada do que você fez foi perdido.',
            ], 503);
        }
    }

    /* ==== Identificação ===================================================== */

    /** @param array<string, mixed> $query */
    private function inicio(array $query): Resposta
    {
        if (isset($this->sessao['cpf'])) {
            return Resposta::redirecionar('/inicio');
        }
        if (isset($query['l']) && is_string($query['l'])) {
            $cpf = (new LinkAssinado($this->segredo))->abrir($query['l'], $this->relogio->agora());
            if ($cpf !== null) {
                return $this->identificar($cpf);
            }
            return $this->pagina('entrar', ['erro' => 'Este link expirou. Digite seu CPF para continuar.', 'etapa' => 1]);
        }
        return $this->pagina('entrar', ['etapa' => 1]);
    }

    /** @param array<string, mixed> $post */
    private function entrar(array $post, string $ip): Resposta
    {
        $cpfDigitado = (string) ($post['cpf'] ?? '');
        if (!$this->limites->permitir('ip:' . $ip, 20, 900)) {
            return $this->pagina('entrar', ['erro' => 'Muitas consultas a partir desta conexão. Espere alguns minutos e tente de novo.', 'cpf' => $cpfDigitado, 'etapa' => 1], 429);
        }
        $cpf = Cpf::limpar($cpfDigitado);
        if (!Cpf::valido($cpf)) {
            return $this->pagina('entrar', ['erro' => 'Confira o CPF: ele precisa ter 11 números válidos.', 'cpf' => $cpfDigitado, 'etapa' => 1], 422);
        }
        if (!$this->limites->permitir('cpf:' . Cpf::hash($cpf, $this->segredo), 10, 3600)) {
            return $this->pagina('entrar', ['erro' => 'Muitas consultas para este CPF. Tente de novo daqui a uma hora.', 'etapa' => 1], 429);
        }
        return $this->identificar($cpf);
    }

    private function identificar(string $cpf): Resposta
    {
        $hash = Cpf::hash($cpf, $this->segredo);
        $this->eventos->registrar('cpf_consultado', $hash);

        $devedor = $this->credor->devedor($cpf);
        $contratos = $devedor === null ? [] : $this->credor->contratos($cpf);
        if ($devedor === null || $contratos === []) {
            return $this->pagina('sem-pendencias', ['cpf' => Cpf::mascarar($cpf)]);
        }
        if ($devedor['telefones'] === []) {
            return $this->pagina('sem-telefone', ['cpf' => Cpf::mascarar($cpf)]);
        }

        $this->sessao['pre'] = [
            'cpf' => $cpf,
            'nome' => $devedor['nome'],
            'telefones' => $devedor['telefones'],
            'telefone_id' => null,
            'desde' => $this->relogio->agora(),
        ];
        return Resposta::redirecionar('/telefone');
    }

    private function escolherTelefone(): Resposta
    {
        $pre = $this->preIdentificacao();
        if ($pre === null) {
            return Resposta::redirecionar('/');
        }
        return $this->pagina('telefone', [
            'primeiroNome' => self::primeiroNome($pre['nome']),
            'cpf' => Cpf::mascarar($pre['cpf']),
            'telefones' => $pre['telefones'],
            'etapa' => 2,
        ]);
    }

    /** @param array<string, mixed> $post */
    private function enviarCodigo(array $post): Resposta
    {
        $pre = $this->preIdentificacao();
        if ($pre === null) {
            return Resposta::redirecionar('/');
        }
        $telefone = null;
        foreach ($pre['telefones'] as $opcao) {
            if ($opcao['id'] === (string) ($post['telefone'] ?? '')) {
                $telefone = $opcao;
            }
        }
        if ($telefone === null) {
            $this->avisar('Escolha um dos telefones da lista.', 'erro');
            return Resposta::redirecionar('/telefone');
        }

        $hash = Cpf::hash($pre['cpf'], $this->segredo);
        $emissao = $this->codigos->emitir($hash, $telefone['id']);
        $this->sessao['pre']['telefone_id'] = $telefone['id'];
        if ($emissao['codigo'] === null) {
            $this->avisar("Aguarde {$emissao['aguardar']} segundos para pedir outro código.", 'erro');
            return Resposta::redirecionar('/codigo');
        }

        try {
            $this->sms->enviar($telefone['numero'], sprintf(
                '%s: seu código é %s. Ele vale 5 minutos. Não informe este código a ninguém.',
                $this->config->texto('APP_NOME'),
                $emissao['codigo'],
            ));
        } catch (\RuntimeException $erro) {
            error_log('[portal] falha no SMS: ' . $erro->getMessage());
            $this->avisar('Não conseguimos enviar o SMS agora. Tente de novo em instantes.', 'erro');
            return Resposta::redirecionar('/telefone');
        }

        $this->eventos->registrar('codigo_enviado', $hash);
        if ($this->config->ligado('MODO_DEMO')) {
            $this->avisar("Modo demonstração: nenhum SMS foi enviado. Use o código {$emissao['codigo']}.", 'demo');
        }
        return Resposta::redirecionar('/codigo');
    }

    private function formCodigo(?string $erro = null, int $status = 200): Resposta
    {
        $pre = $this->preIdentificacao();
        if ($pre === null || $pre['telefone_id'] === null) {
            return Resposta::redirecionar('/');
        }
        $telefone = '';
        foreach ($pre['telefones'] as $opcao) {
            if ($opcao['id'] === $pre['telefone_id']) {
                $telefone = $opcao['mascara'];
            }
        }
        return $this->pagina('codigo', [
            'telefone' => $telefone,
            'erro' => $erro,
            'espera' => $this->codigos->esperaParaReenvio(Cpf::hash($pre['cpf'], $this->segredo)),
            'etapa' => 3,
        ], $status);
    }

    /** @param array<string, mixed> $post */
    private function conferirCodigo(array $post): Resposta
    {
        $pre = $this->preIdentificacao();
        if ($pre === null || $pre['telefone_id'] === null) {
            return Resposta::redirecionar('/');
        }
        $hash = Cpf::hash($pre['cpf'], $this->segredo);
        $resultado = $this->codigos->verificar($hash, (string) ($post['codigo'] ?? ''));

        if ($resultado !== 'ok') {
            $mensagem = match ($resultado) {
                'invalido' => 'Código incorreto. Confira o SMS e digite de novo.',
                'expirado' => 'Este código expirou. Peça um novo.',
                default => 'Este código não vale mais. Peça um novo para continuar.',
            };
            return $this->formCodigo($mensagem, 422);
        }

        ($this->renovarIdSessao)();
        $this->sessao['cpf'] = $pre['cpf'];
        $this->sessao['nome'] = $pre['nome'];
        $this->sessao['ultimo'] = $this->relogio->agora();
        unset($this->sessao['pre']);
        $this->eventos->registrar('login', $hash);
        return Resposta::redirecionar('/inicio');
    }

    private function sair(): Resposta
    {
        foreach (array_keys($this->sessao) as $chave) {
            unset($this->sessao[$chave]);
        }
        ($this->renovarIdSessao)();
        return Resposta::redirecionar('/');
    }

    /* ==== Área logada ======================================================== */

    /** @param array<string, mixed> $post */
    private function areaLogada(string $metodo, string $caminho, array $post): Resposta
    {
        if (!isset($this->sessao['cpf'])) {
            $this->avisar('Para continuar, confirme sua identidade.', 'erro');
            return Resposta::redirecionar('/');
        }
        $this->sessao['ultimo'] = $this->relogio->agora();
        $cpf = (string) $this->sessao['cpf'];

        return match (true) {
            $metodo === 'GET' && $caminho === '/inicio' => $this->painelPessoa($cpf),
            $metodo === 'GET' && (bool) preg_match('#^/contratos/([A-Za-z0-9._-]{1,40})$#', $caminho, $m) => $this->contrato($cpf, $m[1]),
            $metodo === 'POST' && (bool) preg_match('#^/contratos/([A-Za-z0-9._-]{1,40})/revisar$#', $caminho, $m) => $this->revisar($cpf, $m[1], $post),
            $metodo === 'POST' && (bool) preg_match('#^/contratos/([A-Za-z0-9._-]{1,40})/acordo$#', $caminho, $m) => $this->formalizar($cpf, $m[1], $post),
            $metodo === 'GET' && (bool) preg_match('#^/boletos/(\d{1,12})$#', $caminho, $m) => $this->boleto($cpf, (int) $m[1]),
            $metodo === 'POST' && $caminho === '/contatos' => $this->atualizarContato($cpf, $post),
            default => $this->naoEncontrado(),
        };
    }

    private function painelPessoa(string $cpf): Resposta
    {
        $contratos = $this->credor->contratos($cpf);
        $emAberto = array_sum(array_map(static fn (array $c): int => $c['em_acordo'] ? 0 : $c['valor'], $contratos));
        return $this->pagina('inicio', [
            'primeiroNome' => self::primeiroNome((string) $this->sessao['nome']),
            'contratos' => $contratos,
            'emAberto' => $emAberto,
        ]);
    }

    private function contrato(string $cpf, string $numero): Resposta
    {
        $contrato = $this->contratoDaPessoa($cpf, $numero);
        if ($contrato === null) {
            return $this->naoEncontrado();
        }

        if ($contrato['em_acordo']) {
            return $this->pagina('acordo', ['contrato' => $contrato, 'acordo' => $this->credor->acordo($cpf, $numero)]);
        }

        $this->eventos->registrar('proposta_vista', Cpf::hash($cpf, $this->segredo), $numero);
        return $this->pagina('negociar', [
            'contrato' => $contrato,
            'propostas' => $this->credor->propostas($cpf, $numero),
            'escolha' => $this->sessao['escolha'][$numero] ?? null,
        ]);
    }

    /** @param array<string, mixed> $post */
    private function revisar(string $cpf, string $numero, array $post): Resposta
    {
        $contrato = $this->contratoDaPessoa($cpf, $numero);
        if ($contrato === null || $contrato['em_acordo']) {
            return Resposta::redirecionar('/contratos/' . rawurlencode($numero));
        }
        $propostas = $this->credor->propostas($cpf, $numero);
        $opcao = null;
        foreach ($propostas['opcoes'] as $item) {
            if ($item['id'] === (int) ($post['opcao'] ?? 0)) {
                $opcao = $item;
            }
        }
        $data = (string) ($post['data'] ?? '');
        if ($opcao === null || !in_array($data, $propostas['datas'], true)) {
            $this->avisar('Escolha uma forma de pagamento e a data do primeiro vencimento.', 'erro');
            return Resposta::redirecionar('/contratos/' . rawurlencode($numero));
        }

        // Chave de idempotência: o mesmo formulário de confirmação enviado duas
        // vezes (duplo clique, botão voltar) não cria dois acordos.
        $chave = bin2hex(random_bytes(16));
        $this->sessao['confirmacoes'][$chave] = ['contrato' => $numero, 'opcao' => $opcao['id'], 'data' => $data];
        $this->sessao['escolha'][$numero] = ['opcao' => $opcao['id'], 'data' => $data];

        return $this->pagina('confirmar', [
            'contrato' => $contrato,
            'opcao' => $opcao,
            'data' => $data,
            'valorDivida' => $propostas['valor_divida'],
            'chave' => $chave,
        ]);
    }

    /** @param array<string, mixed> $post */
    private function formalizar(string $cpf, string $numero, array $post): Resposta
    {
        $destino = Resposta::redirecionar('/contratos/' . rawurlencode($numero));
        $chave = (string) ($post['chave'] ?? '');
        $hash = Cpf::hash($cpf, $this->segredo);

        $jaFeito = $this->pdo->prepare('SELECT acordo_id FROM formalizacoes WHERE chave = ? AND cpf_hash = ?');
        $jaFeito->execute([$chave, $hash]);
        if ($jaFeito->fetchColumn() !== false) {
            return $destino;
        }

        $pedido = $this->sessao['confirmacoes'][$chave] ?? null;
        if (!is_array($pedido) || $pedido['contrato'] !== $numero || $this->contratoDaPessoa($cpf, $numero) === null) {
            $this->avisar('A confirmação expirou. Escolha a proposta de novo.', 'erro');
            return $destino;
        }

        try {
            $acordoId = $this->credor->fecharAcordo($cpf, $numero, (int) $pedido['opcao'], (string) $pedido['data']);
        } catch (ErroCredor $erro) {
            if ($erro->getCode() === ErroCredor::INDISPONIVEL) {
                throw $erro;
            }
            $this->avisar($erro->getMessage(), 'erro');
            return $destino;
        }

        $this->pdo->prepare('INSERT INTO formalizacoes (chave, cpf_hash, contrato, acordo_id, criado_em) VALUES (?, ?, ?, ?, ?)')
            ->execute([$chave, $hash, $numero, $acordoId, $this->relogio->agora()]);
        unset($this->sessao['confirmacoes'][$chave], $this->sessao['escolha'][$numero]);
        $this->eventos->registrar('acordo_fechado', $hash, $numero);
        $this->avisar("Acordo nº {$acordoId} fechado. Os boletos já estão disponíveis abaixo.", 'ok');
        return $destino;
    }

    private function boleto(string $cpf, int $parcelaId): Resposta
    {
        // A parcela precisa ser de um acordo desta pessoa: sem isso, bastaria
        // trocar o número na URL para baixar o boleto de outra pessoa.
        $dono = false;
        foreach ($this->credor->contratos($cpf) as $contrato) {
            if (!$contrato['em_acordo']) {
                continue;
            }
            foreach ($this->credor->acordo($cpf, $contrato['contrato'])['parcelas'] ?? [] as $parcela) {
                $dono = $dono || $parcela['id'] === $parcelaId;
            }
        }
        $boleto = $dono ? $this->credor->boleto($parcelaId) : null;
        if ($boleto === null) {
            return $this->naoEncontrado();
        }
        $this->eventos->registrar('boleto_baixado', Cpf::hash($cpf, $this->segredo));
        return Resposta::pdf($boleto['pdf'], $boleto['arquivo']);
    }

    /** @param array<string, mixed> $post */
    private function atualizarContato(string $cpf, array $post): Resposta
    {
        $tipo = (string) ($post['tipo'] ?? '');
        $valor = trim((string) ($post['valor'] ?? ''));

        if ($tipo === 'telefone') {
            $valor = preg_replace('/\D+/', '', $valor) ?? '';
            $valido = preg_match('/^[1-9]{2}9?\d{8}$/', $valor) === 1;
        } elseif ($tipo === 'email') {
            $valido = filter_var($valor, FILTER_VALIDATE_EMAIL) !== false && strlen($valor) <= 120;
        } else {
            $valido = false;
        }
        if (!$valido) {
            $this->avisar($tipo === 'email' ? 'Confira o e-mail informado.' : 'Informe o celular com DDD, só números.', 'erro');
            return Resposta::redirecionar('/inicio#contatos');
        }

        $this->credor->incluirContato($cpf, $tipo, $valor);
        $this->avisar('Recebemos seu ' . ($tipo === 'email' ? 'e-mail' : 'telefone') . '. Ele será usado nos próximos contatos.', 'ok');
        return Resposta::redirecionar('/inicio#contatos');
    }

    /* ==== Painel da operação ================================================ */

    private function painel(): Resposta
    {
        if (empty($this->sessao['painel'])) {
            return $this->pagina('painel-entrar', ['configurado' => $this->config->texto('PAINEL_SENHA_HASH') !== '']);
        }
        return $this->pagina('painel', [
            'funil7' => $this->eventos->funil(7),
            'funil30' => $this->eventos->funil(30),
            'porDia' => $this->eventos->acordosPorDia(14),
            'largo' => true,
        ]);
    }

    /** @param array<string, mixed> $post */
    private function entrarPainel(array $post, string $ip): Resposta
    {
        $hashSenha = $this->config->texto('PAINEL_SENHA_HASH');
        if ($hashSenha === '' || !$this->limites->permitir('painel:' . $ip, 5, 900)) {
            return $this->pagina('painel-entrar', ['configurado' => $hashSenha !== '', 'erro' => 'Muitas tentativas. Espere 15 minutos.'], 429);
        }
        if (!password_verify((string) ($post['senha'] ?? ''), $hashSenha)) {
            return $this->pagina('painel-entrar', ['configurado' => true, 'erro' => 'Senha incorreta.'], 422);
        }
        ($this->renovarIdSessao)();
        $this->sessao['painel'] = true;
        return Resposta::redirecionar('/painel');
    }

    /* ==== Apoio ============================================================== */

    /** @return array{cpf: string, nome: string, telefones: list<array{id: string, numero: string, mascara: string, tipo: string}>, telefone_id: ?string, desde: int}|null */
    private function preIdentificacao(): ?array
    {
        $pre = $this->sessao['pre'] ?? null;
        if (!is_array($pre) || $this->relogio->agora() - (int) $pre['desde'] > self::SESSAO_IDENTIFICACAO) {
            unset($this->sessao['pre']);
            return null;
        }
        return $pre;
    }

    /** @return array{contrato: string, produto: string, valor: int, desde: string, em_acordo: bool}|null */
    private function contratoDaPessoa(string $cpf, string $numero): ?array
    {
        foreach ($this->credor->contratos($cpf) as $contrato) {
            if ($contrato['contrato'] === $numero) {
                return $contrato;
            }
        }
        return null;
    }

    private function expirarSessao(): void
    {
        if (isset($this->sessao['cpf']) && $this->relogio->agora() - (int) ($this->sessao['ultimo'] ?? 0) > self::SESSAO_INATIVIDADE) {
            foreach (['cpf', 'nome', 'ultimo', 'confirmacoes', 'escolha'] as $chave) {
                unset($this->sessao[$chave]);
            }
            $this->avisar('Sua sessão foi encerrada por inatividade. Entre de novo para continuar.', 'erro');
        }
    }

    private function avisar(string $mensagem, string $tipo): void
    {
        $this->sessao['aviso'] = ['mensagem' => $mensagem, 'tipo' => $tipo];
    }

    /** @return array{mensagem: string, tipo: string}|null */
    private function tirarAviso(): ?array
    {
        $aviso = $this->sessao['aviso'] ?? null;
        unset($this->sessao['aviso']);
        return is_array($aviso) ? $aviso : null;
    }

    /** @param array<string, mixed> $dados */
    private function pagina(string $template, array $dados = [], int $status = 200): Resposta
    {
        // O aviso é lido só quando uma página é desenhada: se esta requisição
        // terminar em redirecionamento, ele fica para a próxima.
        return Resposta::html($this->view->render($template, $dados + ['aviso' => $this->tirarAviso()]), $status);
    }

    private function naoEncontrado(): Resposta
    {
        return $this->pagina('erro', ['titulo' => 'Página não encontrada', 'mensagem' => 'O endereço não existe ou não está disponível para você.'], 404);
    }

    private static function primeiroNome(string $nome): string
    {
        return mb_convert_case(explode(' ', trim($nome))[0] ?: $nome, MB_CASE_TITLE, 'UTF-8');
    }
}
