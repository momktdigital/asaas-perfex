<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway/asaas_lib');
        $this->load->model('payment_modes_model');

        $gateways = $this->payment_modes_model->get('', ['active' => 1]);
        $gateway = null;
        foreach ($gateways as $g) {
            if ($g['id'] == 'asaas_gateway') {
                $gateway = $g;
                break;
            }
        }

        if ($gateway) {
            $this->asaas_lib->set_api_key($this->encryption->decrypt($gateway['instance']->getSetting('api_key')));
            $this->asaas_lib->set_sandbox($gateway['instance']->getSetting('sandbox'));
        }
    }

    public function get_customer_subscriptions()
    {
        if (!has_permission('customers', '', 'view')) {
            access_denied('customers');
        }

        $clientid = $this->input->post('clientid');
        $this->load->model('clients_model');
        $client = $this->clients_model->get($clientid);

        $cpfCnpj = preg_replace('/[^0-9]/', '', isset($client->vat) ? $client->vat : '');

        $html = '';
        if (empty($cpfCnpj)) {
            echo '<div class="alert alert-warning">Cliente não possui CPF/CNPJ configurado para busca no gateway.</div>';
            return;
        }

        $customer_res = $this->asaas_lib->get_customer_by_cpf_cnpj($cpfCnpj);

        if ($customer_res['success'] && !empty($customer_res['data']['data'])) {
            $customer_id = $customer_res['data']['data'][0]['id'];

            $subs_res = $this->asaas_lib->request('/subscriptions', 'GET', ['customer' => $customer_id, 'status' => 'ACTIVE']);

            if ($subs_res['success'] && !empty($subs_res['data']['data'])) {
                $html .= '<div class="table-responsive"><table class="table table-striped">';
                $html .= '<thead><tr><th>ID da Assinatura</th><th>Forma de Pag.</th><th>Valor Total</th><th>Ciclo</th><th>Próxima Cobrança</th><th>Status</th><th>Ações</th></tr></thead><tbody>';

                foreach ($subs_res['data']['data'] as $sub) {
                    $status_label = '<span class="label label-success">Ativa</span>';
                    $cycle_name = str_replace(
                        ['WEEKLY', 'MONTHLY', 'SEMIANNUALLY', 'YEARLY'],
                        ['Semanal', 'Mensal', 'Semestral', 'Anual'],
                        $sub['cycle']
                    );

                    $html .= '<tr>';
                    $html .= '<td>' . $sub['id'] . '</td>';
                    $html .= '<td>' . $sub['billingType'] . '</td>';
                    $html .= '<td>R$ ' . number_format($sub['value'], 2, ',', '.') . '</td>';
                    $html .= '<td>' . $cycle_name . '</td>';
                    $html .= '<td>' . date('d/m/Y', strtotime($sub['nextDueDate'])) . '</td>';
                    $html .= '<td>' . $status_label . '</td>';
                    $html .= '<td><button class="btn btn-danger btn-sm" onclick="cancelAsaasSubscription(\'' . $sub['id'] . '\')"><i class="fa fa-times"></i> Cancelar</button></td>';
                    $html .= '</tr>';
                }

                $html .= '</tbody></table></div>';
            } else {
                $html = '<div class="alert alert-info">Nenhuma assinatura ativa encontrada no gateway para este cliente.</div>';
            }
        } else {
            $html = '<div class="alert alert-info">Cliente não sincronizado/encontrado no gateway Asaas.</div>';
        }

        echo $html;
    }

    public function cancel_subscription()
    {
        if (!has_permission('customers', '', 'edit')) {
            echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
            return;
        }

        $id = $this->input->post('id');
        $res = $this->asaas_lib->request('/subscriptions/' . $id, 'DELETE');

        if ($res['success']) {
            // Se existia auth_pix pra ele, marcamos inativo (não deletamos o log)
            $this->db->where('authorization_id', $id);
            $this->db->update(db_prefix() . 'asaas_pix_auth', ['status' => 'CANCELLED']);

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $res['error']]);
        }
    }

    public function refund_payment()
    {
        if (!has_permission('invoices', '', 'edit')) {
            echo json_encode(['success' => false, 'error' => 'Sem permissão.']);
            return;
        }

        $invoice_id = $this->input->post('invoice_id');

        // Find existing charges
        $charges_res = $this->asaas_lib->get_charges_by_external_reference($invoice_id);

        if ($charges_res['success'] && !empty($charges_res['data']['data'])) {
            $found_paid = false;
            foreach ($charges_res['data']['data'] as $charge) {
                if ($charge['status'] == 'RECEIVED' || $charge['status'] == 'CONFIRMED') {
                    $found_paid = true;
                    $res = $this->asaas_lib->request('/payments/' . $charge['id'] . '/refund', 'POST', ['value' => $charge['value']]);
                    if ($res['success']) {
                        // Optionally record a refund/credit note in perfex
                        $this->db->where('id', $invoice_id);
                        $invoice = $this->db->get(db_prefix() . 'invoices')->row();
                        $note = $invoice->clientnote . '<br><br><b>ESTORNADO VIA ASAAS</b> - Valor: R$ ' . number_format($charge['value'], 2, ',', '.');
                        $this->db->update(db_prefix() . 'invoices', ['clientnote' => $note]);

                        log_activity('Asaas Charge ' . $charge['id'] . ' refunded.');
                        echo json_encode(['success' => true]);
                        return;
                    } else {
                        echo json_encode(['success' => false, 'error' => $res['error']]);
                        return;
                    }
                }
            }
            if(!$found_paid) {
                echo json_encode(['success' => false, 'error' => 'Nenhuma cobrança PAGA/RECEBIDA encontrada para esta fatura no Asaas.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Cobrança não encontrada no Asaas.']);
        }
    }
}