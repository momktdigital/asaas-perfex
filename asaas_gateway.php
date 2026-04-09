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

register_payment_gateway('asaas_gateway_module', 'asaas_gateway');

hooks()->add_action('after_invoice_added', 'asaas_gateway_invoice_added_hook');

hooks()->add_filter('invoice_html_view_data', 'asaas_gateway_inject_payment_button');

function asaas_gateway_inject_payment_button($data)
{
    $CI = &get_instance();
    $CI->load->model('invoices_model');

    // Check if invoice is not paid
    if($data['invoice']->status == Invoices_model::STATUS_PAID || $data['invoice']->status == Invoices_model::STATUS_CANCELLED) {
        return $data;
    }

    $found = false;
    foreach($data['payment_modes'] as $mode) {
        if($mode['id'] == 'asaas_gateway') {
            $found = true;
            break;
        }
    }

    if(!$found) {
        // Check if allowed
        $allowed_modes = unserialize($data['invoice']->allowed_payment_modes);
        if(is_array($allowed_modes) && in_array('asaas_gateway', $allowed_modes)) {

            // Ensure library is loaded to provide instance
            $CI->load->library('asaas_gateway/asaas_gateway_module');

            $data['payment_modes'][] = [
                'id' => 'asaas_gateway',
                'name' => _l('asaas_gateway'),
                'description' => '',
                'selected' => true, // Force selection logic if needed
                'active' => true,
                'show_on_pdf' => 1,
                'invoices_only' => 0,
                'expenses_only' => 0,
                'selected_by_default' => 1,
                'instance' => $CI->asaas_gateway_module
            ];
        }
    }

    return $data;
}

function asaas_gateway_invoice_added_hook($invoice_id)
{
    $CI = &get_instance();
    $CI->load->model('invoices_model');
    $CI->load->model('clients_model');
    $invoice = $CI->invoices_model->get($invoice_id);

    // Check if it is a recurring invoice instance (is_recurring_from indicates the parent invoice ID)
    if($invoice->is_recurring_from != NULL) {
        $CI->load->library('asaas_gateway/asaas_lib');

        $CI->load->model('payment_modes_model');
        $gateways = $CI->payment_modes_model->get('', ['active' => 1]);
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
                 $primary_contact = $CI->clients_model->get_contact(get_primary_contact_user_id($client->userid));
                 $client_email = $primary_contact ? $primary_contact->email : '';
                 $client_phone = ($primary_contact && !empty($primary_contact->phonenumber)) ? $primary_contact->phonenumber : (isset($client->phonenumber) ? $client->phonenumber : '');
                 $data_c = [
                    'name' => isset($client->company) ? $client->company : '',
                    'cpfCnpj' => $cpfCnpj,
                    'email' => $client_email,
                    'mobilePhone' => preg_replace('/[^0-9]/', '', $client_phone),
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
