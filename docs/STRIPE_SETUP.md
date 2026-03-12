# Stripe Setup (Projeto Flow)

## 1) Variaveis no `.env`
- `STRIPE_SECRET_KEY` (sk_test_... ou sk_live_...)
- `STRIPE_WEBHOOK_SECRET` (whsec_...)
- `STRIPE_PRICE_ID` (price_... do plano mensal R$5)
- Opcional:
  - `STRIPE_SUCCESS_URL` (se vazio, usa `/access/subscribe_success.php`)
  - `STRIPE_CANCEL_URL` (se vazio, usa `/access/subscribe_cancel.php`)

## 2) Produto e preco (R$5/mes)
No Stripe Dashboard:
- Criar 1 produto "Plano Profissional"
- Criar 1 preco recorrente:
  - Moeda: BRL
  - Valor: 5.00
  - Intervalo: mensal
- Copiar o `price_...` e colocar em `STRIPE_PRICE_ID`.

## 3) Webhook
Criar um endpoint de webhook apontando para:
- `https://seu-dominio.com/access/stripe_webhook.php`
  - Se o app estiver em subpasta, use: `https://seu-dominio.com/SUA_PASTA/access/stripe_webhook.php`

Assinar eventos:
- `checkout.session.completed`
- `invoice.payment_succeeded`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`

Copiar o segredo `whsec_...` para `STRIPE_WEBHOOK_SECRET`.

## 4) Teste rapido do fluxo
1. Abrir `access/checkout.html`
2. Clicar em "Assinar e continuar" (vai para `access/subscribe.php`)
3. Pagar no Stripe Checkout
4. Em "Pagamento recebido", criar conta (ou fazer login)
5. Acessar `secure/save_profile.php`

Observacao:
- A liberacao depende do webhook preencher `current_period_end` e `subscription_status` no banco.
- Regra segura do sistema: `status IN ('active','past_due') AND current_period_end > NOW()`.

