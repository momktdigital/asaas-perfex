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
hooks()->add_filter('before_email_template_send', 'asaas_gateway_attach_boleto_to_email');

/**
 * Attaches the Asaas boleto PDF to invoice e-mails (send, due notice, overdue notice).
 */
function asaas_gateway_attach_boleto_to_email($hook_data)
{
    $slugs = ['invoice-send-to-client', 'invoice-already-send', 'invoice-due-notice', 'invoice-overdue-notice',
              'invoice_send_to_customer', 'invoice_send_to_customer_already_sent', 'invoice_due_notice', 'invoice_overdue_notice'];
    $tpl = isset($GLOBALS['SENDING_EMAIL_TEMPLATE_CLASS']) ? $GLOBALS['SENDING_EMAIL_TEMPLATE_CLASS'] : null;
    if (!$tpl || $tpl->get_rel_type() !== 'invoice' || !$tpl->get_rel_id()) {
        return $hook_data;
    }
    if (!isset($hook_data['template']->slug) || !in_array($hook_data['template']->slug, $slugs)) {
        return $hook_data;
    }

    try {
        $charge = asaas_gateway_ensure_invoice_charge($tpl->get_rel_id());
        if (!$charge || empty($charge['bankSlipUrl'])) {
            return $hook_data;
        }
        $ch = curl_init($charge['bankSlipUrl']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
        $pdf = curl_exec($ch);
        curl_close($ch);

        if ($pdf && strpos($pdf, '%PDF') === 0) {
            $hook_data['attachments'][] = [
                'attachment' => $pdf,
                'filename'   => 'boleto-' . format_invoice_number($tpl->get_rel_id()) . '.pdf',
                'type'       => 'application/pdf',
            ];
        } else {
            log_activity('Asaas: bankSlipUrl nao retornou PDF para a fatura ' . $tpl->get_rel_id());
        }
    } catch (Throwable $e) {
        log_activity('Asaas: erro ao anexar boleto: ' . $e->getMessage());
    }

    return $hook_data;
}

function asaas_gateway_exclude_csrf_webhook($uris)
{
    // Regra curinga agressiva: ignora CSRF em qualquer rota que contenha "asaas_gateway_webhook"
    $uris[] = 'asaas_gateway/asaas_gateway_webhook/notify';
    $uris[] = 'asaas_gateway/asaas_gateway_webhook/notify/(.*)';
    $uris[] = '(.*)asaas_gateway_webhook(.*)';
    return $uris;
}

hooks()->add_action('app_admin_footer', 'asaas_gateway_settings_notifications_button');

/**
 * Injects, in the gateway settings page, a button that applies the notification
 * settings to every customer that already exists in Asaas.
 */
function asaas_gateway_settings_notifications_button()
{
    $CI = &get_instance();
    if (strpos($CI->uri->uri_string(), 'settings') === false || !is_admin()) {
        return;
    }
    echo '<script>
    $(function() {
        var $field = $("[name$=\'asaas_gateway_notif_disable_all\']").first().closest(".form-group");
        if (!$field.length || $("#asaas_sync_notif_btn").length) return;
        $field.after(\'<div class="form-group"><button type="button" id="asaas_sync_notif_btn" class="btn btn-warning">Aplicar notificações a todos os clientes existentes no Asaas</button> <span id="asaas_sync_notif_status" class="mleft10"></span><p class="text-muted mtop5">Salve as configurações antes de executar. O processo roda em lotes e pode demorar com muitos clientes.</p></div>\');
        function asaasToggleNotif() {
            var disableAll = $("[name$=\'asaas_gateway_notif_disable_all\']:checked").val() == "1";
            $("[name*=\'asaas_gateway_notif_\']").not("[name$=\'asaas_gateway_notif_disable_all\']").each(function() {
                var $group = $(this).closest(".form-group");
                if (disableAll) {
                    if ($(this).val() == "0") { $(this).prop("checked", true); }
                    $group.css({"opacity": 0.5, "pointer-events": "none"});
                } else {
                    $group.css({"opacity": "", "pointer-events": ""});
                }
            });
        }
        $("[name$=\'asaas_gateway_notif_disable_all\']").on("change", asaasToggleNotif);
        asaasToggleNotif();
        $("#asaas_sync_notif_btn").on("click", function() {
            if (!confirm("Aplicar a configuração atual de notificações a TODOS os clientes do Asaas?")) return;
            var $btn = $(this).prop("disabled", true), total = 0, failed = 0;
            function run(offset) {
                $.post(admin_url + "asaas_gateway/admin/sync_notifications", { offset: offset }, function(res) {
                    res = JSON.parse(res);
                    if (!res.success) { $("#asaas_sync_notif_status").text("Erro: " + res.error); $btn.prop("disabled", false); return; }
                    total += res.processed; failed += res.failed;
                    $("#asaas_sync_notif_status").text("Processados: " + total + " | Falhas: " + failed);
                    if (res.hasMore) { run(res.next); } else { $("#asaas_sync_notif_status").append(" - Concluído!"); $btn.prop("disabled", false); }
                }).fail(function() { $("#asaas_sync_notif_status").text("Erro na requisição."); $btn.prop("disabled", false); });
            }
            run(0);
        });
    });
    </script>';
}

function asaas_gateway_admin_invoice_footer()
{
    $CI = &get_instance();
    $uri = $CI->uri->uri_string();

    // Invoice view page: admin/invoices/list_invoices/{id} or admin/invoices/invoice/{id}
    if (!preg_match('#invoices/(?:list_invoices|invoice)/(\d+)#', $uri, $m) || !has_permission('invoices', '', 'edit')) {
        return;
    }
    $invoice_id = (int) $m[1];

    $paid_via_asaas = $CI->db->where('invoiceid', $invoice_id)
        ->where('paymentmode', 'asaas_gateway')
        ->count_all_results(db_prefix() . 'invoicepaymentrecords') > 0;
    if (!$paid_via_asaas) {
        return;
    }

    $nfe_url = admin_url('asaas_gateway/admin/check_nfe/' . $invoice_id);
    $refund_url = admin_url('asaas_gateway/admin/refund_payment');
    echo '<script>
    $(function() {
        if ($("#asaas_refund_btn").length) return;
        var btn = $(\'<a href="#" id="asaas_refund_btn" class="btn btn-warning mleft5"><i class="fa fa-undo"></i> Estornar no Asaas</a>\');
        var targets = ["#invoice-top .btn-group:first", "#invoice-top ._buttons", "._buttons .pull-right", ".invoice-html-status-container", "._buttons", ".panel-body:first"];
        var placed = false;
        for (var i = 0; i < targets.length && !placed; i++) {
            var $t = $(targets[i]).first();
            if ($t.length) { $t.after(btn); placed = true; }
        }
        if (!placed) { btn.css({position: "fixed", bottom: "20px", right: "20px", "z-index": 9999}).appendTo("body"); }
        btn.on("click", function(e) {
            e.preventDefault();
            if (!confirm("Tem certeza que deseja estornar totalmente este pagamento no Asaas? O valor será devolvido ao cliente e a cobrança será marcada como estornada.")) return;
            var csrf = {}; csrf[csrfData.token_name] = csrfData.hash;
            $.post(' . json_encode($refund_url) . ', $.extend({ invoice_id: ' . $invoice_id . ' }, csrf), function(response) {
                response = typeof response === "string" ? JSON.parse(response) : response;
                if (response.success) {
                    alert_float("success", "Pagamento estornado com sucesso no Asaas!");
                    window.location.reload();
                } else {
                    alert_float("danger", "Erro ao estornar: " + response.error);
                }
            }).fail(function() { alert_float("danger", "Erro na requisição."); });
        });
        $.get(' . json_encode($nfe_url) . ', function(res) {
            var d = typeof res === "string" ? JSON.parse(res) : res;
            if (d.has_nfe && d.nfe_link) {
                btn.after(\'<a href="\' + d.nfe_link + \'" target="_blank" class="btn btn-info mleft5"><i class="fa fa-file-pdf-o"></i> Visualizar NF-e (Asaas)</a>\');
            }
        });
    });
    </script>';
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

    // Auto-create the charge (no-op if Pix Automatico above already created one)
    asaas_gateway_ensure_invoice_charge($invoice_id);
}

/**
 * Configures asaas_lib with the active gateway credentials. Returns the gateway array or null.
 */
function asaas_gateway_boot_lib()
{
    $CI = &get_instance();
    $CI->load->library('asaas_gateway/asaas_lib');
    $CI->load->model('payment_modes_model');
    foreach ($CI->payment_modes_model->get('', ['active' => 1]) as $g) {
        if ($g['id'] == 'asaas_gateway') {
            $is_sandbox = $g['instance']->getSetting('sandbox');
            $key = $is_sandbox == 1 ? 'api_key_sandbox' : 'api_key_prod';
            $CI->asaas_lib->set_api_key($CI->encryption->decrypt($g['instance']->getSetting($key)));
            $CI->asaas_lib->set_sandbox($is_sandbox);
            return $g;
        }
    }
    return null;
}

/**
 * Returns the open (PENDING/OVERDUE) Asaas charge of an invoice, or null.
 */
function asaas_gateway_find_open_charge($invoice_id)
{
    $CI = &get_instance();
    $res = $CI->asaas_lib->get_charges_by_external_reference($invoice_id);
    if (!$res['success'] || empty($res['data']['data'])) return null;
    $overdue = null;
    foreach ($res['data']['data'] as $c) {
        if ($c['status'] == 'PENDING') return $c;
        if ($c['status'] == 'OVERDUE' && !$overdue) $overdue = $c;
    }
    return $overdue;
}

/**
 * Creates (once) the Asaas charge for an invoice, with the invoice due date and
 * billingType UNDEFINED so the customer can pick Pix/Boleto/Card on the same charge.
 * Returns the charge array (existing or new) or null.
 */
function asaas_gateway_ensure_invoice_charge($invoice_id)
{
    $CI = &get_instance();
    $CI->load->model('invoices_model');
    $CI->load->model('clients_model');
    $invoice = $CI->invoices_model->get($invoice_id);
    if (!$invoice) return null;

    if (in_array($invoice->status, [Invoices_model::STATUS_PAID, Invoices_model::STATUS_CANCELLED, Invoices_model::STATUS_DRAFT])) {
        return null;
    }

    $gateway = asaas_gateway_boot_lib();
    if (!$gateway) return null;

    // Only for invoices that allow the Asaas gateway
    $allowed = @unserialize($invoice->allowed_payment_modes);
    if (is_array($allowed) && !in_array('asaas_gateway', $allowed)) return null;

    $existing = asaas_gateway_find_open_charge($invoice_id);
    if ($existing) return $existing;

    $client = $CI->clients_model->get($invoice->clientid);
    if (!$client) return null;

    $customer_id = asaas_gateway_ensure_customer($client);
    if (!$customer_id) {
        log_activity('Asaas: falha ao obter/criar cliente para fatura ' . $invoice_id);
        return null;
    }

    $dueDate = (!empty($invoice->duedate) && strtotime($invoice->duedate) >= strtotime(date('Y-m-d'))) ? $invoice->duedate : date('Y-m-d');

    $charge_data = [
        'customer' => $customer_id,
        'billingType' => 'UNDEFINED',
        'value' => $invoice->total,
        'dueDate' => $dueDate,
        'externalReference' => $invoice_id,
        'description' => 'Invoice #' . $invoice->number,
    ];

    $fine = $gateway['instance']->getSetting('fine_value');
    $interest = $gateway['instance']->getSetting('interest_value');
    if (is_numeric($fine) && $fine > 0) $charge_data['fine'] = ['value' => $fine, 'type' => 'PERCENTAGE'];
    if (is_numeric($interest) && $interest > 0) $charge_data['interest'] = ['value' => $interest, 'type' => 'PERCENTAGE'];

    $json = $gateway['instance']->getSetting('split_config');
    if (!empty($json)) {
        $split = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($split)) {
            $charge_data['split'] = hooks()->apply_filters('asaas_gateway_before_split', $split);
        }
    }

    $res = $CI->asaas_lib->create_charge($charge_data);
    if ($res['success']) {
        log_activity('Asaas: cobrança criada automaticamente para a fatura ' . $invoice_id);
        return $res['data'];
    }
    log_activity('Asaas: falha ao criar cobrança automática da fatura ' . $invoice_id . ': ' . $res['error']);
    return null;
}

hooks()->add_action('after_client_added', 'asaas_gateway_client_added_hook');
hooks()->add_action('client_created', 'asaas_gateway_client_added_hook');

/**
 * Creates the customer in Asaas as soon as it is created in Perfex (no invoice needed).
 */
function asaas_gateway_client_added_hook($client_id)
{
    static $done = [];
    if (is_array($client_id)) {
        $client_id = $client_id['id'] ?? ($client_id['userid'] ?? null);
    }
    if (!$client_id || isset($done[$client_id])) return;
    $done[$client_id] = true;
    try {
        $CI = &get_instance();
        $CI->load->model('clients_model');
        $client = $CI->clients_model->get($client_id);
        if ($client) asaas_gateway_ensure_customer($client);
    } catch (Throwable $e) {
        log_activity('Asaas: erro ao criar cliente ' . $client_id . ': ' . $e->getMessage());
    }
}

/**
 * Finds (by CPF/CNPJ) or creates the Asaas customer for a Perfex client. Returns the Asaas id or null.
 */
function asaas_gateway_ensure_customer($client)
{
    $CI = &get_instance();
    if (!asaas_gateway_boot_lib()) return null;

    $cpfCnpj = preg_replace('/[^0-9]/', '', isset($client->vat) ? $client->vat : '');
    if (!empty($cpfCnpj)) {
        $found = $CI->asaas_lib->get_customer_by_cpf_cnpj($cpfCnpj);
        if ($found['success'] && !empty($found['data']['data'])) return $found['data']['data'][0]['id'];
    }
    $contact = $CI->clients_model->get_contact(get_primary_contact_user_id($client->userid));
    $phone = ($contact && !empty($contact->phonenumber)) ? $contact->phonenumber : (isset($client->phonenumber) ? $client->phonenumber : '');
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($phone, '55') === 0 && strlen($phone) > 11) $phone = substr($phone, 2);
    $zip = preg_replace('/[^0-9]/', '', !empty($client->billing_zip) ? $client->billing_zip : ($client->zip ?? ''));
    $addr = !empty($client->billing_street) ? $client->billing_street : ($client->address ?? '');
    $num = preg_replace('/[^0-9]/', '', $addr);
    $created = $CI->asaas_lib->create_customer([
        'name' => $client->company ?? '',
        'cpfCnpj' => $cpfCnpj,
        'email' => $contact ? $contact->email : '',
        'mobilePhone' => $phone,
        'externalReference' => $client->userid,
        'postalCode' => $zip,
        'addressNumber' => $num === '' ? '0' : $num,
    ]);
    if (!$created['success']) {
        log_activity('Asaas: falha ao criar cliente ' . $client->userid . ': ' . $created['error']);
        return null;
    }
    log_activity('Asaas: cliente ' . $client->userid . ' criado no Asaas (' . $created['data']['id'] . ')');
    return $created['data']['id'];
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
    $fields[] = [
        'name' => 'Link do Boleto (Asaas)',
        'key' => '{asaas_boleto_link}',
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

    // Boleto link: ensures the charge exists, so the e-mail always carries the payment link
    $boleto_link = '';
    $charge = asaas_gateway_ensure_invoice_charge($invoice_id);
    if ($charge) {
        $url = !empty($charge['bankSlipUrl']) ? $charge['bankSlipUrl'] : ($charge['invoiceUrl'] ?? '');
        if ($url) {
            $boleto_link = '<a href="' . $url . '" target="_blank">Clique aqui para visualizar/pagar o boleto</a>';
        }
    }
    $fields['{asaas_boleto_link}'] = $boleto_link;
    return $fields;
}
