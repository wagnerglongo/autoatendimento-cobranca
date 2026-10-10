// Melhorias progressivas: o portal funciona sem JavaScript.
(function () {
  'use strict';

  // Máscara de CPF enquanto digita (000.000.000-00).
  document.querySelectorAll('[data-mascara="cpf"]').forEach(function (campo) {
    campo.addEventListener('input', function () {
      var d = campo.value.replace(/\D/g, '').slice(0, 11);
      var partes = [d.slice(0, 3), d.slice(3, 6), d.slice(6, 9)].filter(Boolean).join('.');
      campo.value = d.length > 9 ? partes + '-' + d.slice(9) : partes;
    });
  });

  // Código do SMS: só números.
  document.querySelectorAll('.campo-codigo').forEach(function (campo) {
    campo.addEventListener('input', function () {
      campo.value = campo.value.replace(/\D/g, '').slice(0, 6);
    });
  });

  // Contagem regressiva do "enviar outro código".
  document.querySelectorAll('[data-espera]').forEach(function (botao) {
    var restante = parseInt(botao.getAttribute('data-espera'), 10) || 0;
    if (restante <= 0) return;
    var timer = setInterval(function () {
      restante -= 1;
      if (restante <= 0) {
        clearInterval(timer);
        botao.disabled = false;
        botao.textContent = 'Não recebi, enviar outro código';
      } else {
        botao.textContent = 'Pedir outro código em ' + restante + 's';
      }
    }, 1000);
  });

  // Confirmação do acordo: um clique só (o servidor também protege com chave de idempotência).
  document.querySelectorAll('form[data-envio-unico]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var botao = form.querySelector('button[type="submit"]');
      if (botao) {
        botao.disabled = true;
        botao.textContent = 'Registrando acordo…';
      }
    });
  });
})();
