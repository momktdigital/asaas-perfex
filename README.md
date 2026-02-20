# Asaas Payment Gateway for Perfex CRM

Módulo de integração com o gateway de pagamentos Asaas para o Perfex CRM.

## Funcionalidades

*   **Cartão de Crédito:** Pagamento transparente com suporte a parcelamento (até 12x) e tokenização segura.
*   **Pix:** Geração de QR Code dinâmico e "Copia e Cola".
*   **Boleto:** Geração de boleto bancário.
*   **Pix Automático:** Suporte para cobranças recorrentes via autorização de Pix Automático.
*   **Splits:** Configuração de divisão de pagamentos (Split) via painel ou código.
*   **Webhooks:** Sincronização automática de status de pagamentos.

## Instalação

1.  Baixe os arquivos deste repositório.
2.  Crie uma pasta chamada **`asaas_gateway`** dentro do diretório `modules/` do seu Perfex CRM.
    *   **Importante:** O nome da pasta deve ser exatamente `asaas_gateway`.
3.  Copie todos os arquivos para dentro desta pasta (`modules/asaas_gateway/`).
4.  Acesse a área administrativa do Perfex CRM.
5.  Vá em **Setup -> Modules** e ative o módulo "Asaas Gateway".
6.  Vá em **Setup -> Finance -> Payment Modes** e configure o gateway "Asaas".

## Configuração

No painel de configuração do gateway (Payment Modes -> Asaas):

1.  **API Key:** Insira sua chave de API do Asaas (Sandbox ou Produção).
2.  **Sandbox Mode:** Ative para testes, desative para produção.
3.  **Webhook Token:** Defina um token aleatório para segurança.
    *   No painel do Asaas, configure a URL do Webhook para: `https://seu-crm.com/asaas_gateway/asaas_gateway_webhook/notify`
    *   Configure o header `asaas-access-token` com o mesmo valor definido aqui.
4.  **Wallet ID:** (Opcional) ID da carteira principal.
5.  **Split Configuration:** JSON para regras de split fixas (Opcional).
    *   Exemplo: `[{"walletId": "xxx", "percentage": 10}]`

## Requisitos

*   Perfex CRM (versão compatível com módulos de pagamento padrão).
*   PHP 7.4+ com extensão cURL.
*   Conexão com a internet para comunicação com a API do Asaas.
