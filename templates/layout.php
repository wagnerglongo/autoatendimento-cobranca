<?php
/** @var string $conteudo @var string $appNome @var string $empresa @var bool $demo @var string $csrf */
$titulo = $titulo ?? null;
$etapa = $etapa ?? null;
$pessoa = $pessoa ?? null;
$aviso = $aviso ?? null;
$largo = $largo ?? false;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= $titulo ? e($titulo) . ' · ' : '' ?><?= e($appNome) ?></title>
<link rel="preload" href="/assets/fontes/atkinson-hyperlegible-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/portal.css">
<link rel="icon" href="/assets/icone.svg" type="image/svg+xml">
<script src="/assets/portal.js" defer></script>
</head>
<body>
<header class="topo">
  <div class="topo-linha">
    <a class="marca" href="/"><img src="/assets/icone.svg" alt="" width="28" height="28"><span><?= e($appNome) ?></span></a>
    <?php if ($pessoa): ?>
      <form method="post" action="/sair" class="sair">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit">Sair</button>
      </form>
    <?php endif; ?>
  </div>
</header>

<?php if ($demo): ?>
  <p class="faixa-demo">Ambiente de demonstração: credor fictício, dados gerados automaticamente e nenhum SMS é enviado.</p>
<?php endif; ?>

<main class="pagina<?= $largo ? ' pagina-larga' : '' ?>" id="conteudo">
  <?php if ($etapa): ?>
    <ol class="etapas" aria-label="Etapas para entrar">
      <?php foreach ([1 => 'CPF', 2 => 'Telefone', 3 => 'Código'] as $numero => $nome): ?>
        <li class="<?= $numero < $etapa ? 'feita' : ($numero === $etapa ? 'atual' : '') ?>"<?= $numero === $etapa ? ' aria-current="step"' : '' ?>><?= e($nome) ?></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if ($aviso): ?>
    <p class="aviso aviso-<?= e($aviso['tipo']) ?>" role="<?= $aviso['tipo'] === 'erro' ? 'alert' : 'status' ?>"><?= e($aviso['mensagem']) ?></p>
  <?php endif; ?>

  <?= $conteudo ?>
</main>

<footer class="rodape">
  <p><?= e($appNome) ?> · canal oficial de negociação de <?= e($empresa) ?></p>
  <p><a href="/privacidade">Privacidade</a> <a href="/termos">Termos de uso</a></p>
</footer>
</body>
</html>
