<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Account extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (!has_permission('invoices', '', 'view')) {
            access_denied('Conta Asaas');
        }

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
            $is_sandbox = $gateway['instance']->getSetting('sandbox');
            $api_key_field = $is_sandbox == 1 ? 'api_key_sandbox' : 'api_key_prod';
            $this->asaas_lib->set_api_key($this->encryption->decrypt($gateway['instance']->getSetting($api_key_field)));
            $this->asaas_lib->set_sandbox($is_sandbox);
        }
    }

    public function index()
    {
        $data['title'] = 'Conta Asaas - Resumo';

        $balance_res = $this->asaas_lib->get_balance();
        if ($balance_res['success']) {
            $data['balance'] = $balance_res['data']['balance'];
        } else {
            $data['balance'] = 0;
            $data['error'] = 'Erro ao consultar saldo: ' . $balance_res['error'];
        }

        $extract_res = $this->asaas_lib->get_extract(20, 0);
        if ($extract_res['success']) {
            $data['extract'] = $extract_res['data']['data'];
        } else {
            $data['extract'] = [];
        }

        $this->load->view('admin/account/dashboard', $data);
    }

    public function pay_expense($expense_id)
    {
        $this->load->model('expenses_model');
        $expense = $this->expenses_model->get($expense_id);

        if (!$expense) {
            set_alert('danger', 'Despesa não encontrada.');
            redirect(admin_url('expenses/list_expenses'));
        }

        $this->db->where('name', 'Código de Barras / Linha Digitável');
        $this->db->where('fieldto', 'expenses');
        $cf = $this->db->get(db_prefix() . 'customfields')->row();

        if ($cf) {
            $this->db->where('relid', $expense_id);
            $this->db->where('fieldid', $cf->id);
            $this->db->where('fieldto', 'expenses');
            $cv = $this->db->get(db_prefix() . 'customfieldsvalues')->row();

            if ($cv && !empty($cv->value)) {
                $barcode = preg_replace('/[^0-9]/', '', $cv->value);

                $data = [
                    'identificationField' => $barcode,
                    'value' => $expense->amount,
                    'dueDate' => date('Y-m-d'),
                    'description' => $expense->expense_name,
                ];

                $res = $this->asaas_lib->pay_bill($data);

                if ($res['success']) {
                    $note = $expense->note . '<br><br><b>PAGA VIA ASAAS CONTA</b> - Transação: ' . $res['data']['id'] . '<br>Em: ' . date('d/m/Y H:i:s');
                    $this->db->where('id', $expense_id);
                    $this->db->update(db_prefix() . 'expenses', ['note' => $note]);

                    set_alert('success', 'Pagamento da despesa agendado/realizado com sucesso no Asaas!');
                } else {
                    set_alert('danger', 'Erro ao pagar com Asaas: ' . $res['error']);
                }
            } else {
                set_alert('danger', 'Código de barras não encontrado.');
            }
        }

        redirect(admin_url('expenses/list_expenses/' . $expense_id));
    }

    public function transfers()
    {
        $data['title'] = 'Conta Asaas - Transferências';

        $balance_res = $this->asaas_lib->get_balance();
        $data['balance'] = $balance_res['success'] ? $balance_res['data']['balance'] : 0;

        $data['favored'] = $this->db->get(db_prefix() . 'asaas_favored')->result_array();

        if ($this->input->post()) {
            $post = $this->input->post();

            $transferData = [
                'value' => $post['value'],
                'pixAddressKey' => $post['pix_key'],
                'pixAddressKeyType' => $post['pix_type'],
                'description' => $post['description']
            ];

            $res = $this->asaas_lib->transfer($transferData);

            if ($res['success']) {
                if (isset($post['save_favored']) && $post['save_favored'] == '1') {
                    $this->db->insert(db_prefix() . 'asaas_favored', [
                        'name' => $post['favored_name'],
                        'cpfCnpj' => preg_replace('/[^0-9]/', '', $post['favored_cpf']),
                        'pix_key' => $post['pix_key'],
                        'pix_type' => $post['pix_type']
                    ]);
                }
                set_alert('success', 'Transferência Pix realizada/agendada com sucesso!');
                redirect(admin_url('asaas_gateway/account/transfers'));
            } else {
                set_alert('danger', 'Erro na transferência: ' . $res['error']);
            }
        }

        $this->load->view('admin/account/transfers', $data);
    }

    public function anticipations()
    {
        $data['title'] = 'Conta Asaas - Antecipações';

        if ($this->input->post('charge_id')) {
            $res = $this->asaas_lib->request_anticipation(['payment' => $this->input->post('charge_id')]);
            if ($res['success']) {
                set_alert('success', 'Solicitação de antecipação enviada com sucesso!');
            } else {
                set_alert('danger', 'Erro ao solicitar antecipação: ' . $res['error']);
            }
            redirect(admin_url('asaas_gateway/account/anticipations'));
        }

        $charges_res = $this->asaas_lib->request('/payments', 'GET', ['billingType' => 'CREDIT_CARD', 'status' => 'CONFIRMED', 'limit' => 50]);

        $data['eligible_charges'] = [];
        if ($charges_res['success']) {
            $data['eligible_charges'] = $charges_res['data']['data'];
        }

        $this->load->view('admin/account/anticipations', $data);
    }
}
