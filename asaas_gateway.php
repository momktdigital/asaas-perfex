<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Asaas Gateway
Description: Asaas Payment Gateway for Perfex CRM
Version: 1.0.0
Requires at least: 2.3.*
Author: Nonamo
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
hooks()->add_action('invoice_marked_as_cancelled', 'asaas_gateway_invoice_cancelled_hook');
hooks()->add_action('before_invoice_deleted', 'asaas_gateway_invoice_deleted_hook');
hooks()->add_filter('customer_profile_tabs', 'asaas_gateway_customer_profile_tabs');
hooks()->add_filter("other_merge_fields_available", "asaas_gateway_register_merge_fields");
hooks()->add_filter("invoice_merge_fields", "asaas_gateway_invoice_merge_fields", 10, 2);
hooks()->add_action('app_admin_footer', 'asaas_gateway_admin_invoice_footer');

hooks()->add_filter('csrf_exclude_uris', 'asaas_gateway_exclude_csrf_webhook');

function asaas_gateway_exclude_csrf_webhook($uris)
{
    // Regra curinga agressiva: ignora CSRF em qualquer rota que contenha "asaas_gateway_webhook"
    $uris[] = 'asaas_gateway/asaas_gateway_webhook/notify';
    $uris[] = 'asaas_gateway/asaas_gateway_webhook/notify/(.*)';
    $uris[] = '(.*)asaas_gateway_webhook(.*)';
    return $uris;
}

function asaas_gateway_admin_invoice_footer()
{
    $CI = &get_instance();
    $uri = $CI->uri->uri_string();

    if (strpos($uri, 'invoices/list_invoices/') !== false || strpos($uri, 'invoices/invoice/') !== false || strpos($uri, 'invoices#') !== false) {
        echo '<script>
        $(function() {
            // Check if we are viewing an invoice
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.url.indexOf("invoices/get_invoice_data_ajax") !== -1) {
                    // Check if it has asaas_gateway payment
                    setTimeout(function() {
                        var hasAsaasPayment = $(".invoice-html-payment-modes").text().indexOf("Asaas") !== -1 || $(".table-invoice-payments").text().indexOf("Asaas") !== -1;
                        if(hasAsaasPayment && $(".invoice-status-bg-paid").length > 0) {
                            var invoiceId = $("input[name=\'invoiceid\']").val();
                            if(invoiceId && $("#asaas_refund_btn").length == 0) {
                                var btnHtml = \'<a href="#" id="asaas_refund_btn" class="btn btn-warning pull-right mleft5" onclick="refundAsaasPayment(\' + invoiceId + \'); return false;"><i class="fa fa-undo"></i> Estornar no Asaas</a>\';
                                $(".invoice-html-status-container").parent().append(btnHtml);

                                // Check for NFE
                                $.get(admin_url + "asaas_gateway/admin/check_nfe/" + invoiceId, function(res) {
                                    var nfeData = JSON.parse(res);
                                    if(nfeData.has_nfe && nfeData.nfe_link) {
                                        var nfeBtn = \'<a href="\' + nfeData.nfe_link + \'" target="_blank" class="btn btn-info pull-right mleft5"><i class="fa fa-file-pdf-o"></i> Visualizar NF-e (Asaas)</a>\';
                                        $(".invoice-html-status-container").parent().append(nfeBtn);
                                    }
                                });
                            }
                        }
                    }, 500);
                }
            });
        });

        function refundAsaasPayment(invoiceId) {
            if(confirm("Tem certeza que deseja estornar totalmente este pagamento no Asaas? O valor será devolvido ao cliente e a cobrança será marcada como estornada.")) {
                $.post(admin_url + "asaas_gateway/admin/refund_payment", { invoice_id: invoiceId }, function(response) {
                    response = JSON.parse(response);
                    if(response.success) {
                        alert_float("success", "Pagamento estornado com sucesso no Asaas!");
                        window.location.reload();
                    } else {
                        alert_float("danger", "Erro ao estornar: " + response.error);
                    }
                }).fail(function() {
                    alert_float("danger", "Erro na requisição.");
                });
            }
        }
        </script>';
    }
}

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

        $is_sandbox = $gateway['instance']->getSetting('sandbox');
        $api_key_field = $is_sandbox == 1 ? 'api_key_sandbox' : 'api_key_prod';
        $CI->asaas_lib->set_api_key($CI->encryption->decrypt($gateway['instance']->getSetting($api_key_field)));
        $CI->asaas_lib->set_sandbox($is_sandbox);

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

function asaas_gateway_invoice_cancelled_hook($invoice_id)
{
    asaas_gateway_delete_pending_charges($invoice_id);
}

function asaas_gateway_invoice_deleted_hook($invoice_id)
{
    asaas_gateway_delete_pending_charges($invoice_id);
}

function asaas_gateway_delete_pending_charges($invoice_id)
{
    $CI = &get_instance();
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

    $CI->load->library('asaas_gateway/asaas_lib');
    $is_sandbox = $gateway['instance']->getSetting('sandbox');
    $api_key_field = $is_sandbox == 1 ? 'api_key_sandbox' : 'api_key_prod';
    $CI->asaas_lib->set_api_key($CI->encryption->decrypt($gateway['instance']->getSetting($api_key_field)));
    $CI->asaas_lib->set_sandbox($is_sandbox);

    // Get all charges for this invoice
    $charges_res = $CI->asaas_lib->get_charges_by_external_reference($invoice_id);

    if ($charges_res['success'] && !empty($charges_res['data']['data'])) {
        foreach ($charges_res['data']['data'] as $charge) {
            // Delete if pending or overdue
            if ($charge['status'] == 'PENDING' || $charge['status'] == 'OVERDUE') {
                $res = $CI->asaas_lib->delete_charge($charge['id']);
                if($res['success']) {
                    log_activity('Asaas Charge ' . $charge['id'] . ' deleted due to Invoice ' . $invoice_id . ' cancellation/deletion.');
                }
            }
        }
    }
}

function asaas_gateway_customer_profile_tabs($tabs)
{
    $tabs['asaas_subscriptions'] = [
        'name' => 'Assinaturas',
        'icon' => 'fa fa-refresh',
        'view' => 'asaas_gateway/admin/subscriptions_tab',
        'position' => 90,
    ];
    return $tabs;
}

function asaas_gateway_register_merge_fields($fields)
{
    $fields[] = [
        'name' => 'Link da NF-e (Asaas)',
        'key' => '{asaas_nfe_link}',
        'available' => [
            'invoice',
        ],
    ];
    return $fields;
}

function asaas_gateway_invoice_merge_fields($fields, $invoice_id)
{
    $nfe_link = '';
    $CI = &get_instance();
    $CI->load->library('asaas_gateway/asaas_lib');

    $CI->load->model('payment_modes_model');
    $gateways = $CI->payment_modes_model->get('', ['active' => 1]);
    foreach ($gateways as $g) {
        if ($g['id'] == 'asaas_gateway') {
            $is_sandbox = $g['instance']->getSetting('sandbox');
            $api_key_field = $is_sandbox == 1 ? 'api_key_sandbox' : 'api_key_prod';
            $CI->asaas_lib->set_api_key($CI->encryption->decrypt($g['instance']->getSetting($api_key_field)));
            $CI->asaas_lib->set_sandbox($is_sandbox);

            $nfe_res = $CI->asaas_lib->get_invoices_by_external_reference($invoice_id);
            if ($nfe_res['success'] && !empty($nfe_res['data']['data'])) {
                $nfe = $nfe_res['data']['data'][0];
                if(isset($nfe['invoiceUrl']) && !empty($nfe['invoiceUrl'])) {
                    $nfe_link = '<a href="'.$nfe['invoiceUrl'].'" target="_blank">Visualizar Nota Fiscal</a>';
                }
            }
            break;
        }
    }

    $fields['{asaas_nfe_link}'] = $nfe_link;
    return $fields;
}
