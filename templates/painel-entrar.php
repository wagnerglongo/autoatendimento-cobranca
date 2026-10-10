<?php /** @var string $csrf @var bool $configurado */ $erro = $erro ?? null; ?>
<section class="cartao">
  <h1>Painel da operação</h1>
  <?php if (!$configurado): ?>
    <p class="apoio">O painel está desligado. Defina <code>PAINEL_SENHA_HASH</code> com o resultado de <code>php bin/senha-painel.php</code> para ativá-lo.</p>
  <?php else: ?>
    <form method="post" action="/painel" class="formulario">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label for="senha">Senha do painel</label>
      <input id="senha" name="senha" type="password" autocomplete="current-password" required>
      <?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>
      <button type="submit" class="botao">Entrar</button>
    </form>
  <?php endif; ?>
</section>
