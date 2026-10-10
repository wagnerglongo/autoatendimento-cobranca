<?php /** @var string $csrf */ $erro = $erro ?? null; $cpf = $cpf ?? ''; ?>
<section class="cartao">
  <h1>Negocie sua dívida sem sair de casa</h1>
  <p class="apoio">Veja seus contratos em aberto, escolha como pagar e baixe o boleto na hora. Para sua segurança, vamos confirmar sua identidade com um código por SMS.</p>

  <form method="post" action="/entrar" class="formulario" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="cpf">CPF</label>
    <input id="cpf" name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00"
           value="<?= e($cpf) ?>" required data-mascara="cpf"<?= $erro ? ' aria-invalid="true" aria-describedby="erro-cpf"' : '' ?>>
    <?php if ($erro): ?><p class="erro" id="erro-cpf"><?= e($erro) ?></p><?php endif; ?>
    <button type="submit" class="botao">Continuar</button>
  </form>
</section>

<ul class="garantias">
  <li><strong>Sem custo.</strong> Negociar por aqui não tem taxa.</li>
  <li><strong>Acordo na hora.</strong> O acordo é gravado direto no sistema do credor.</li>
  <li><strong>Seus dados protegidos.</strong> Só você, com o código do SMS, acessa as informações.</li>
</ul>
