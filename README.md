# Módulo Asaas Gateway para Perfex CRM

Solução desenvolvida por **Nonamo** - [https://nonamo.com.br](https://nonamo.com.br)

Este módulo integra o Perfex CRM à API v3 do Asaas, fornecendo uma solução de pagamento altamente profissional, segura e totalmente nativa, sem redirecionamentos externos e com suporte completo a assinaturas e estornos diretos pelo painel administrativo.

## Funcionalidades e Diferenciais
- **Checkout Transparente Premium:** Interface de pagamento limpa, moderna (Card Layout) hospedada no seu próprio Perfex CRM, evitando que o cliente perca confiança ao ser redirecionado.
- **Multa e Juros Nativos:** As cobranças geradas no Asaas herdam os percentuais de multa e juros definidos nas configurações do Gateway, sem precisar criar configurações avulsas de atraso.
- **Cobranças Automáticas (Boletos/Pix):** Assim que uma fatura é criada no Perfex CRM, o módulo cria uma cobrança base (Link de Pagamento) no Asaas e anexa o link dinâmico ao PDF e corpo dos E-mails da fatura.
- **Assinaturas Mensais (Subscriptions):** Clientes com faturas recorrentes no Perfex podem assinar o Pix Automático ou "Salvar o Cartão". O módulo criará uma `Subscription` real no Asaas, aprovando cobranças automatizadas todo mês e quitando a fatura no Perfex via Webhook.
- **Sincronização de Cancelamento:** Se uma fatura for cancelada ou excluída no Perfex CRM, o módulo apaga a cobrança pendente/vencida equivalente lá no Asaas (evitando boletos "fantasmas" no DDA do cliente).
- **Gestão de Assinaturas no Admin:** Uma nova aba "Assinaturas" é adicionada ao Perfil do Cliente, permitindo que os administradores visualizem e cancelem as assinaturas ativas no Asaas com 1 clique.
- **Estorno Fácil:** Faturas pagas exibem um botão de "Estornar no Asaas" no canto superior direito do Painel Administrativo.
- **Divisão de Pagamentos (Split):** Permite transferir parte do valor (fixo ou percentual) recebido direto para a carteira Asaas de parceiros/sócios automaticamente.

## Requisitos
- Perfex CRM 3.x ou superior.
- PHP 7.4, 8.0, ou 8.1.
- Extensão `curl` habilitada no servidor.
- Cadastro aprovado na plataforma Asaas (ou Asaas Sandbox para testes).

## Como Instalar e Configurar

1. Envie a pasta `asaas_gateway` para o diretório `modules/` do seu Perfex CRM.
2. Acesse **Configurações > Módulos** no Perfex CRM e ative o "Asaas Gateway".
3. Acesse **Configurações > Opções > Gateways de Pagamento > Asaas**.
4. Configure os seguintes campos:
   - **Chave de API (Produção):** Insira a API Key gerada na sua conta oficial Asaas.
   - **Chave de API (Sandbox):** Insira a API Key gerada na sua conta [Sandbox do Asaas](https://sandbox.asaas.com) (usada caso marque o modo sandbox).
   - **Modo Sandbox:** Marque `Sim` para usar o ambiente de testes ou `Não` para transacionar valores reais.
   - **Token Webhook (Produção):** Crie uma senha segura para a conta oficial (ex: `MeuWebhookSecreto2026`).
   - **Token Webhook (Sandbox):** Crie uma senha segura para a conta de testes.
   - **Multa por atraso (%):** A porcentagem a ser cobrada caso a fatura atrase (Padrão: 2%).
   - **Juros ao mês (%):** A porcentagem de juros pró-rata cobrada por mês de atraso (Padrão: 1%).
   - **Configuração de Split (JSON):** (Opcional) Array JSON com as regras de repasse.

## Como Configurar o Webhook no Asaas

O Webhook é crucial para que o Perfex CRM saiba quando o cliente pagou a fatura, e a marque como "Paga" automaticamente.

1. Acesse sua conta Asaas (Produção ou Sandbox).
2. Vá em **Minha Conta > Integrações > Webhooks**.
3. Em **URL do Webhook**, cole o seguinte endereço (substitua `seu-crm.com.br` pelo domínio do seu Perfex):
   `https://seu-crm.com.br/asaas_gateway/webhook/notify`
4. Em **Token de Interação**, cole exatamente a mesma senha que você digitou no campo "Token Webhook (Produção)" ou "Token Webhook (Sandbox)" (dependendo do ambiente que estiver configurando).
5. Marque para enviar eventos de **Cobranças** (`PAYMENT_RECEIVED`, etc).
6. Salve. O Webhook deve entrar em fila ou ser ativado.

## Regras de Cartão e Pix (Recorrente)
Para que o botão de "Salvar cartão para pagamentos mensais" ou "Assinar Pix Mensal Automático" apareçam para o cliente:
- A fatura atual gerada no Perfex CRM **DEVE estar configurada como "Recorrente"** (ex: Todo 1 Mês).
- Se a fatura não for recorrente, as opções de pagamento atuarão apenas para a cobrança daquele mês.
