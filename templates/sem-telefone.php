<?php /** @var string $cpf */ $titulo = 'Telefone necessário'; ?>
<section class="cartao">
  <h1>Precisamos de um telefone para confirmar que é você</h1>
  <p class="apoio">Encontramos contratos para o CPF <?= e($cpf) ?>, mas não há nenhum celular válido cadastrado para enviar o código de segurança.</p>
  <p>Fale com a central de atendimento de <?= e($empresa) ?> para atualizar seu cadastro. Depois disso, é só voltar aqui.</p>
  <p><a class="botao botao-secundario" href="/">Voltar ao início</a></p>
</section>
