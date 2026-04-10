<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Asaas_gateway_module extends App_gateway
{
    public function __construct()
    {
        /**
         * Call App_gateway __construct function
         */
        parent::__construct();

        /**
         * Gateway unique id
         */
        $this->setId('asaas_gateway');

        /**
         * Gateway name
         */
        $this->setName('Asaas');

        /**
         * Add gateway settings
         */
        $this->setSettings([
            [
                'name' => 'api_key_prod',
                'encrypted' => true,
                'label' => 'Chave de API (Produção)',
                'type' => 'input',
            ],
            [
                'name' => 'api_key_sandbox',
                'encrypted' => true,
                'label' => 'Chave de API (Sandbox)',
                'type' => 'input',
            ],
            [
                'name' => 'sandbox',
                'label' => 'asaas_sandbox_mode',
                'type' => 'yes_no',
                'default' => 0,
            ],
            [
                'name' => 'webhook_token_prod',
                'encrypted' => true,
                'label' => 'Token Webhook (Produção)',
                'type' => 'input',
            ],
            [
                'name' => 'webhook_token_sandbox',
                'encrypted' => true,
                'label' => 'Token Webhook (Sandbox)',
                'type' => 'input',
            ],
            [
                'name' => 'wallet_id_prod',
                'label' => 'ID da Carteira (Produção)',
                'type' => 'input',
            ],
            [
                'name' => 'wallet_id_sandbox',
                'label' => 'ID da Carteira (Sandbox)',
                'type' => 'input',
            ],
            [
                'name' => 'split_config',
                'label' => 'asaas_split_config',
                'type' => 'textarea',
                // 'description' => 'asaas_split_config_description',
            ],
            [
                'name' => 'fine_value',
                'label' => 'Multa por atraso (%)',
                'type' => 'input',
                'default' => '2',
            ],
            [
                'name' => 'interest_value',
                'label' => 'Juros ao mês (%)',
                'type' => 'input',
                'default' => '1',
            ],
            [
                'name' => 'nfe_codigo_servico',
                'label' => 'Código de Serviço Municipal (NFS-e)',
                'type' => 'input',
            ],
            [
                'name' => 'nfe_descricao_padrao',
                'label' => 'Descrição Padrão da NFS-e',
                'type' => 'textarea',
            ],
            [
                'name' => 'currencies',
                'label' => 'settings_paymentmethod_currencies',
                'default' => 'BRL',
            ],
        ]);

        /**
         * Mandatory for button visibility in Client Profile
         */
        // $this->visible_customer_profile = true; // Not standard property, but some custom themes use it?
        // App_gateway doesn't have it.
        // However, 'process_payment' presence usually triggers it.
    }

    public function process_payment($data)
    {
        redirect(site_url('asaas_gateway/client/pay/' . $data['invoiceid'] . '/' . $data['invoice']->hash));
    }

    public function get_action_url($invoice)
    {
        return site_url('asaas_gateway/client/pay/' . $invoice->id . '/' . $invoice->hash);
    }

    public function is_available($invoice)
    {
        // Force availability if BRL or if not strictly checking currency
        return true;
    }
}
