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
                'name' => 'api_key',
                'encrypted' => true,
                'label' => 'asaas_api_key',
                'type' => 'input',
            ],
            [
                'name' => 'sandbox',
                'label' => 'asaas_sandbox_mode',
                'type' => 'yes_no',
                'default' => 0,
            ],
            [
                'name' => 'webhook_token',
                'encrypted' => true,
                'label' => 'asaas_webhook_token',
                'type' => 'input',
            ],
            [
                'name' => 'wallet_id',
                'label' => 'asaas_wallet_id',
                'type' => 'input',
            ],
            [
                'name' => 'split_config',
                'label' => 'asaas_split_config',
                'type' => 'textarea',
                // 'description' => 'asaas_split_config_description',
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
