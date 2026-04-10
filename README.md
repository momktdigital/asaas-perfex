# Asaas Gateway Module for Perfex CRM

Este módulo integra o gateway de pagamento Asaas ao Perfex CRM, permitindo pagamentos via Pix, Boleto e Cartão de Crédito. Ele suporta tanto o ambiente de Produção quanto o Sandbox (para testes).

## Funcionalidades
*   **Pix:** Geração de QR Code e código copia e cola nativos na tela de fatura do Perfex.
*   **Boleto:** Link direto para a fatura gerada no Asaas.
*   **Cartão de Crédito:** Formulário de pagamento na própria tela da fatura, sem redirecionamento para fora do seu CRM. Permite parcelamento em até 12x.
*   **Pix Automático (Assinaturas):** Permite a autorização de débitos automáticos via Pix.
*   **Webhooks:** Atualização automática do status da fatura no Perfex CRM quando o pagamento é recebido no Asaas.
*   **Ambiente Duplo (Produção / Sandbox):** Chaves de API, tokens de webhook e IDs de Carteira (Wallet ID) configuráveis separadamente para produção e ambiente de testes. Alternância com um único clique.
*   **Configuração de Split:** Permite divisão de pagamentos (split) utilizando uma configuração em formato JSON diretamente nas configurações do módulo.
*   **Sincronização de Clientes:** Cria ou atualiza automaticamente o cliente no Asaas para manter os dados sincronizados.
*   **Multa e Juros:** Permite configurar a porcentagem de multa por atraso e juros ao mês.
*   **Estorno no Asaas:** Botão na área administrativa da fatura para estornar um pagamento processado via Asaas.
*   **Sincronização de Cancelamentos:** Deleta as cobranças geradas no Asaas automaticamente se a fatura for cancelada ou apagada no Perfex CRM.

## Instalação

1.  Faça o download do arquivo `.zip` deste repositório.
2.  Extraia o conteúdo para a pasta `modules/` do seu Perfex CRM.
    *   **IMPORTANTE:** A pasta do módulo deve se chamar **exatamente** `asaas_gateway`. Se o arquivo extraído tiver outro nome (ex: `perfex-asaas-main`), renomeie a pasta para `asaas_gateway`.
3.  Acesse o Perfex CRM como Administrador.
4.  Vá em **Configurações > Módulos** e clique em "Instalar/Ativar" no módulo "Asaas".

## Configuração

1.  Vá em **Configurações > Pagamentos > Gateways de Pagamento**.
2.  Selecione a aba **Asaas**.
3.  Preencha as seguintes configurações:
    *   **Chave de API (Produção):** Sua chave de API de produção do Asaas.
    *   **Chave de API (Sandbox):** Sua chave de API de testes do Asaas (Sandbox).
    *   **Modo Sandbox (Teste):** Marque como "Sim" para testar o sistema. Quando "Sim", o módulo usará a Chave de API, Token Webhook e Wallet ID do Sandbox.
    *   **Token Webhook (Produção):** O Token gerado ao configurar o webhook no ambiente de produção do Asaas.
    *   **Token Webhook (Sandbox):** O Token gerado ao configurar o webhook no ambiente Sandbox do Asaas.
    *   **ID da Carteira (Produção):** O ID da sua carteira no Asaas (Produção).
    *   **ID da Carteira (Sandbox):** O ID da sua carteira no Asaas (Sandbox).
    *   **Configuração de Split (JSON):** Configuração em JSON para divisão de pagamentos (Opcional). Exemplo: `[{"walletId": "ID_DA_CARTEIRA_A_RECEBER", "percentage": 10}]`.
    *   **Multa por atraso (%):** Porcentagem aplicada como multa após o vencimento (Ex: `2`).
    *   **Juros ao mês (%):** Porcentagem aplicada como juros mensais após o vencimento (Ex: `1`).
    *   **Moedas Permitidas:** `BRL`.

## Configuração do Webhook no Asaas

Para que as faturas sejam marcadas como "Paga" automaticamente no Perfex, é essencial configurar o Webhook no painel do Asaas.

1.  Acesse seu painel Asaas (Produção ou Sandbox, dependendo de qual deseja configurar).
2.  Vá em **Configurações > Integrações > Webhooks**.
3.  Clique em **Adicionar Webhook** para "Cobranças".
4.  **Nome:** "Perfex CRM" (ou qualquer outro de sua preferência).
5.  **URL:** `https://SEU_DOMINIO_DO_PERFEX.com/asaas_gateway/asaas_gateway_webhook/notify`
6.  **E-mail para notificações:** Seu e-mail.
7.  **Eventos:** Selecione, no mínimo, `PAYMENT_RECEIVED` e `PAYMENT_CONFIRMED`.
8.  **Fila de envio:** Selecione "Sequencial".
9.  Salve a configuração. O Asaas gerará um **Token de Interceptação**. Copie este token e cole no campo "Token Webhook (Produção)" ou "Token Webhook (Sandbox)" nas configurações do gateway no Perfex, dependendo do ambiente que você configurou.

## Erro 403 Forbidden no Webhook do Asaas (Bloqueio CSRF)

### Descrição do Problema
Ao configurar as notificações de pagamento do Asaas no Perfex CRM, os disparos de webhook podem falhar com o **Status Code: 403 (Forbidden)**. 

Isso ocorre devido à proteção nativa de **CSRF (Cross-Site Request Forgery)** do CodeIgniter (framework base do Perfex). Como os webhooks do Asaas são requisições POST externas, eles não possuem os *cookies* de sessão ou o *token* de formulário exigidos pelo sistema, fazendo com que a aplicação rejeite a conexão automaticamente.

### Solução
Para permitir o recebimento dos *payloads*, é necessário declarar a rota do webhook na *whitelist* (lista de exclusão) do CSRF no arquivo principal de configuração do Perfex.

### Como Aplicar a Correção (Passo a Passo)

1. Acesse o servidor onde o Perfex CRM está hospedado.
2. Navegue até o diretório de configurações e abra o arquivo:
   `application/config/config.php`
3. Localize o array de exclusão de rotas, chamado `$config['csrf_exclude_uris']`.
4. Adicione a rota do módulo Asaas na lista de exceções, utilizando o curinga `.*` no final.

**Exemplo de como o código deve ficar:**

```php
// application/config/config.php

$config['csrf_exclude_uris'] = [
    'forms/wtl/[0-9a-z]+', 
    'forms/ticket', 
    'forms/quote/[0-9a-z]+', 
    'admin/tasks/timer_tracking', 
    'api\/.+', 
    'razorpay/success\/.+',
    // Adicione a linha abaixo:
    'asaas_gateway/asaas_gateway_webhook/notify.*' 
];
