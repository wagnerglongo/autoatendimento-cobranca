<?php

declare(strict_types=1);

namespace Autoatendimento;

/** Templates em PHP puro. Cada template recebe só as variáveis que precisa. */
final class View
{
    /** @param array<string, mixed> $globais Variáveis disponíveis em todo template (nome do app, CSRF...) */
    public function __construct(
        private readonly string $pasta,
        private array $globais = [],
    ) {
    }

    public function definir(string $nome, mixed $valor): void
    {
        $this->globais[$nome] = $valor;
    }

    /** @param array<string, mixed> $dados */
    public function render(string $template, array $dados = [], ?string $layout = 'layout'): string
    {
        $conteudo = $this->incluir($template, $dados);
        return $layout === null ? $conteudo : $this->incluir($layout, $dados + ['conteudo' => $conteudo]);
    }

    /** @param array<string, mixed> $dados */
    private function incluir(string $template, array $dados): string
    {
        $arquivo = $this->pasta . '/' . $template . '.php';
        if (!is_file($arquivo)) {
            throw new \RuntimeException("Template não encontrado: {$template}");
        }
        extract($dados + $this->globais, EXTR_SKIP);
        ob_start();
        try {
            require $arquivo;
        } catch (\Throwable $erro) {
            ob_end_clean();
            throw $erro;
        }
        return (string) ob_get_clean();
    }
}
