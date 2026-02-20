<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Client extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway/asaas_lib');
        $this->load->model('invoices_model');
        $this->load->model('clients_model');

        // Load the gateway settings to configure the library
        $gateways = $this->app->get_payment_gateways();
        $gateway = null;
        foreach ($gateways as $g) {
            if ($g['id'] == 'asaas_gateway') {
                $gateway = $g;
                break;
            }
        }

        if ($gateway) {
            $this->asaas_lib->set_api_key($gateway['instance']->getSetting('api_key'));
            $this->asaas_lib->set_sandbox($gateway['instance']->getSetting('sandbox'));
        }

        $this->lang->load('asaas_gateway/asaas_gateway');
    }

    public function pay($invoice_id, $hash)
    {
        check_invoice_restrictions($invoice_id, $hash);

        $invoice = $this->invoices_model->get($invoice_id);

        // Load customer data
        $client = $this->clients_model->get($invoice->clientid);

        // Prepare view data
        $data['invoice'] = $invoice;
        $data['client'] = $client;
        $data['hash'] = $hash;

        // Check if recurring and Pix Automatico enabled
        $data['is_recurring'] = ($invoice->recurring > 0);
        $data['pix_auth_status'] = $this->check_pix_auth_status($client->userid);

        $this->load->view('asaas_gateway/pay', $data);
    }

    public function process_credit_card($invoice_id, $hash)
    {
        check_invoice_restrictions($invoice_id, $hash);
        $invoice = $this->invoices_model->get($invoice_id);
        $client = $this->clients_model->get($invoice->clientid);
        $data = $this->input->post();

        $customer_id = $this->get_or_create_asaas_customer($client);

        if(!$customer_id) {
            set_alert('danger', _l('asaas_customer_sync_failed'));
            redirect(site_url('asaas_gateway/client/pay/' . $invoice_id . '/' . $hash));
        }

        $expiry = explode('/', $data['expiry']);
        if(count($expiry) != 2) {
             set_alert('danger', _l('asaas_invalid_card'));
             redirect(site_url('asaas_gateway/client/pay/' . $invoice_id . '/' . $hash));
        }
        $expiryMonth = trim($expiry[0]);
        $expiryYear = trim($expiry[1]);
        if(strlen($expiryYear) == 2) $expiryYear = '20' . $expiryYear;

        // Tokenize
        $token_data = [
            'customer' => $customer_id,
            'creditCard' => [
                'holderName' => $data['holderName'],
                'number' => $data['number'],
                'expiryMonth' => $expiryMonth,
                'expiryYear' => $expiryYear,
                'ccv' => $data['ccv']
            ],
            'creditCardHolderInfo' => [
                'name' => $data['holderName'],
                'email' => $client->email,
                'cpfCnpj' => preg_replace('/[^0-9]/', '', $client->vat),
                'postalCode' => preg_replace('/[^0-9]/', '', $client->billing_zip),
                'addressNumber' => '0', // Required by Asaas, defaulting to 0 as Perfex doesn't strictly enforce it separately
                'phone' => preg_replace('/[^0-9]/', '', $client->phonenumber),
            ],
            'remoteIp' => $this->input->ip_address()
        ];

        $token_res = $this->asaas_lib->tokenize_credit_card($token_data);

        if(!$token_res['success']) {
            set_alert('danger', _l('asaas_payment_failed') . ' ' . $token_res['error']);
            redirect(site_url('asaas_gateway/client/pay/' . $invoice_id . '/' . $hash));
        }

        $charge_data = [
            'customer' => $customer_id,
            'billingType' => 'CREDIT_CARD',
            'value' => $invoice->total,
            'dueDate' => date('Y-m-d'),
            'creditCardToken' => $token_res['data']['creditCardToken'],
            'externalReference' => $invoice_id,
            'description' => 'Invoice #' . $invoice->number,
            'remoteIp' => $this->input->ip_address()
        ];

        $installmentCount = isset($data['installmentCount']) ? intval($data['installmentCount']) : 1;
        if($installmentCount > 1 && $installmentCount <= 12) {
            $charge_data['installmentCount'] = $installmentCount;
            $charge_data['installmentValue'] = $invoice->total / $installmentCount;
        }

        // Split Logic
        $split = $this->get_split_config();
        if($split) {
            $charge_data['split'] = $split;
        }

        $charge_res = $this->asaas_lib->create_charge($charge_data);

        // Clear sensitive data
        unset($data);
        unset($token_data);

        if($charge_res['success']) {
            set_alert('success', _l('asaas_payment_success'));
            redirect(site_url('invoice/' . $invoice_id . '/' . $hash));
        } else {
            set_alert('danger', _l('asaas_payment_failed') . ' ' . $charge_res['error']);
            redirect(site_url('asaas_gateway/client/pay/' . $invoice_id . '/' . $hash));
        }
    }

    public function process_pix($invoice_id, $hash)
    {
        check_invoice_restrictions($invoice_id, $hash);
        if(!$this->input->is_ajax_request()) show_404();

        $invoice = $this->invoices_model->get($invoice_id);
        $client = $this->clients_model->get($invoice->clientid);

        $customer_id = $this->get_or_create_asaas_customer($client);

        if(!$customer_id) {
            echo json_encode(['success' => false, 'message' => _l('asaas_customer_sync_failed')]);
            return;
        }

        $charge_data = [
            'customer' => $customer_id,
            'billingType' => 'PIX',
            'value' => $invoice->total,
            'dueDate' => date('Y-m-d'),
            'externalReference' => $invoice_id,
            'description' => 'Invoice #' . $invoice->number
        ];

        $split = $this->get_split_config();
        if($split) {
            $charge_data['split'] = $split;
        }

        $charge_res = $this->asaas_lib->create_charge($charge_data);

        if($charge_res['success']) {
            // Get QR Code
            $qr_res = $this->asaas_lib->request('/payments/' . $charge_res['data']['id'] . '/pixQrCode', 'GET');
            if($qr_res['success']) {
                echo json_encode(['success' => true, 'encodedImage' => $qr_res['data']['encodedImage'], 'payload' => $qr_res['data']['payload']]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to retrieve QR Code']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => $charge_res['error']]);
        }
    }

    public function process_boleto($invoice_id, $hash)
    {
        check_invoice_restrictions($invoice_id, $hash);
        if(!$this->input->is_ajax_request()) show_404();

        $invoice = $this->invoices_model->get($invoice_id);
        $client = $this->clients_model->get($invoice->clientid);

        $customer_id = $this->get_or_create_asaas_customer($client);

        if(!$customer_id) {
            echo json_encode(['success' => false, 'message' => _l('asaas_customer_sync_failed')]);
            return;
        }

        $charge_data = [
            'customer' => $customer_id,
            'billingType' => 'BOLETO',
            'value' => $invoice->total,
            'dueDate' => date('Y-m-d', strtotime('+3 days')), // Boleto needs future date usually
            'externalReference' => $invoice_id,
            'description' => 'Invoice #' . $invoice->number
        ];

        $split = $this->get_split_config();
        if($split) {
            $charge_data['split'] = $split;
        }

        $charge_res = $this->asaas_lib->create_charge($charge_data);

        if($charge_res['success']) {
            echo json_encode(['success' => true, 'bankSlipUrl' => $charge_res['data']['bankSlipUrl']]);
        } else {
            echo json_encode(['success' => false, 'message' => $charge_res['error']]);
        }
    }

    public function process_pix_auto_auth($invoice_id, $hash)
    {
        check_invoice_restrictions($invoice_id, $hash);
        if(!$this->input->is_ajax_request()) show_404();

        $invoice = $this->invoices_model->get($invoice_id);
        $client = $this->clients_model->get($invoice->clientid);

        $customer_id = $this->get_or_create_asaas_customer($client);

        if(!$customer_id) {
            echo json_encode(['success' => false, 'message' => _l('asaas_customer_sync_failed')]);
            return;
        }

        $auth_data = [
            'customer' => $customer_id,
            'value' => $invoice->total,
            'description' => 'Pix Automatico for Recurring Invoice',
            'externalReference' => 'auth_' . $invoice->id,
            // 'frequency' => 'MONTHLY', // Depending on recurring type? Asaas doesn't strictly require frequency for Authorization J3?
            // Docs say: "O QR Code gerado contém tanto os dados do primeiro pagamento... quanto os dados necessários para configurar pagamentos recorrentes futuros."
            // We verify the API params.
        ];

        // We probably should check recurring frequency of invoice but for now default.

        $res = $this->asaas_lib->create_pix_auth($auth_data);

        if($res['success']) {
            // Store auth request in DB
            $this->db->insert(db_prefix() . 'asaas_pix_auth', [
                'client_id' => $client->userid,
                'authorization_id' => $res['data']['id'],
                'status' => 'PENDING'
            ]);

            echo json_encode(['success' => true, 'encodedImage' => $res['data']['immediateQrCode']['encodedImage'], 'payload' => $res['data']['immediateQrCode']['payload']]);
        } else {
            echo json_encode(['success' => false, 'message' => $res['error']]);
        }
    }

    private function get_or_create_asaas_customer($client)
    {
        $cpfCnpj = preg_replace('/[^0-9]/', '', $client->vat);

        // Try to find existing by CPF/CNPJ
        if(!empty($cpfCnpj)) {
            $existing = $this->asaas_lib->get_customer_by_cpf_cnpj($cpfCnpj);
            if($existing['success'] && !empty($existing['data']['data'])) {
                return $existing['data']['data'][0]['id'];
            }
        }

        // Create new
        $data = [
            'name' => $client->company,
            'cpfCnpj' => $cpfCnpj,
            'email' => $client->email,
            'mobilePhone' => preg_replace('/[^0-9]/', '', $client->phonenumber),
            'externalReference' => $client->userid
        ];

        $res = $this->asaas_lib->create_customer($data);

        if($res['success']) {
            return $res['data']['id'];
        }

        return false;
    }

    private function get_split_config()
    {
        $gateway = $this->invoices_model->get_payment_gateway('asaas_gateway');
        $json = $gateway['instance']->getSetting('split_config');
        if(empty($json)) return null;

        $split = json_decode($json, true);
        if(json_last_error() === JSON_ERROR_NONE && is_array($split)) {
            // Hook for modification
            $split = hooks()->apply_filters('asaas_gateway_before_split', $split);
            return $split;
        }
        return null;
    }

    private function check_pix_auth_status($client_id) {
         $query = $this->db->get_where(db_prefix() . 'asaas_pix_auth', ['client_id' => $client_id, 'status' => 'ACTIVE']);
         return ($query->num_rows() > 0) ? 'ACTIVE' : 'INACTIVE';
    }
}
