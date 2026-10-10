<?php /** @var string $csrf @var string $telefone @var int $espera */ $erro = $erro ?? null; ?>
<section class="cartao">
  <h1>Digite o código</h1>
  <p class="apoio">Enviamos um SMS com 6 números para <strong><?= e($telefone) ?></strong>. O código vale 5 minutos.</p>

  <form method="post" action="/codigo" class="formulario" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="codigo">Código do SMS</label>
    <input id="codigo" name="codigo" class="campo-codigo" inputmode="numeric" autocomplete="one-time-code"
           pattern="\d{6}" maxlength="6" required autofocus<?= $erro ? ' aria-invalid="true" aria-describedby="erro-codigo"' : '' ?>>
    <?php if ($erro): ?><p class="erro" id="erro-codigo"><?= e($erro) ?></p><?php endif; ?>
    <button type="submit" class="botao">Entrar</button>
  </form>

  <form method="post" action="/codigo/reenviar" class="reenviar">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="botao-texto" data-espera="<?= (int) $espera ?>"<?= $espera > 0 ? ' disabled' : '' ?>>
      <?= $espera > 0 ? 'Pedir outro código em ' . (int) $espera . 's' : 'Não recebi, enviar outro código' ?>
    </button>
  </form>
  <p class="nota"><a href="/telefone">Usar outro telefone</a></p>
</section>
