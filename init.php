<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Asaas Gateway
Description: Asaas Payment Gateway for Perfex CRM
Version: 1.0.0
Requires at least: 2.3.*
Author: Jules
*/

define('ASAAS_GATEWAY_MODULE_NAME', 'asaas_gateway');

register_activation_hook(ASAAS_GATEWAY_MODULE_NAME, 'asaas_gateway_activation_hook');

function asaas_gateway_activation_hook()
{
    $CI = &get_instance();
    require_once(__DIR__ . '/install.php');
}

register_language_files(ASAAS_GATEWAY_MODULE_NAME, [ASAAS_GATEWAY_MODULE_NAME]);

register_payment_gateway('asaas_gateway', 'asaas_gateway');

hooks()->add_action('after_invoice_added', 'asaas_gateway_invoice_added_hook');

function asaas_gateway_invoice_added_hook($invoice_id)
{
    $CI = &get_instance();
    $CI->load->model('invoices_model');
    $CI->load->model('clients_model');
    $invoice = $CI->invoices_model->get($invoice_id);

    // Check if it is a recurring invoice instance (is_recurring_from indicates the parent invoice ID)
    if($invoice->is_recurring_from != NULL) {
        $CI->load->library('asaas_gateway/asaas_lib');

        $gateways = $CI->app->get_payment_gateways();
        $gateway = null;
        foreach ($gateways as $g) {
            if ($g['id'] == 'asaas_gateway') {
                $gateway = $g;
                break;
            }
        }

        if(!$gateway) return;

        $CI->asaas_lib->set_api_key($gateway['instance']->getSetting('api_key'));
        $CI->asaas_lib->set_sandbox($gateway['instance']->getSetting('sandbox'));

        $auth = $CI->db->get_where(db_prefix() . 'asaas_pix_auth', ['client_id' => $invoice->clientid, 'status' => 'ACTIVE'])->row();

        if($auth) {
            // Get Customer ID
            $client = $CI->clients_model->get($invoice->clientid);
            $cpfCnpj = preg_replace('/[^0-9]/', '', $client->vat);
            $customer_id = null;

            if(!empty($cpfCnpj)) {
                $existing = $CI->asaas_lib->get_customer_by_cpf_cnpj($cpfCnpj);
                if($existing['success'] && !empty($existing['data']['data'])) {
                    $customer_id = $existing['data']['data'][0]['id'];
                }
            }

            if(!$customer_id) {
                 $data_c = [
                    'name' => $client->company,
                    'cpfCnpj' => $cpfCnpj,
                    'email' => $client->email,
                    'mobilePhone' => preg_replace('/[^0-9]/', '', $client->phonenumber),
                    'externalReference' => $client->userid
                ];
                $res_c = $CI->asaas_lib->create_customer($data_c);
                if($res_c['success']) $customer_id = $res_c['data']['id'];
            }

            if($customer_id) {
                $charge_data = [
                    'customer' => $customer_id,
                    'billingType' => 'PIX',
                    'value' => $invoice->total,
                    'dueDate' => date('Y-m-d'),
                    'externalReference' => $invoice_id,
                    'description' => 'Invoice #' . $invoice->number,
                    'pixAutomaticAuthorizationId' => $auth->authorization_id
                ];

                // Split Logic
                $json = $gateway['instance']->getSetting('split_config');
                if(!empty($json)) {
                    $split = json_decode($json, true);
                    if(json_last_error() === JSON_ERROR_NONE && is_array($split)) {
                        $split = hooks()->apply_filters('asaas_gateway_before_split', $split);
                        $charge_data['split'] = $split;
                    }
                }

                $charge_res = $CI->asaas_lib->create_charge($charge_data);
                if($charge_res['success']) {
                    log_activity('Asaas Pix Automatico Charge Created for Invoice ' . $invoice_id);
                } else {
                    log_activity('Asaas Pix Automatico Failed for Invoice ' . $invoice_id . ': ' . $charge_res['error']);
                }
            }
        }
    }
}
