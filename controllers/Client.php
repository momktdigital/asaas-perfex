<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Client extends ClientsController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('asaas_gateway/asaas_lib');
        $this->load->model('invoices_model');
        $this->load->model('clients_model');

        // Load the gateway settings to configure the library
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

        $customer_res = $this->get_or_create_asaas_customer($client);

        if(!$customer_res['success']) {
            set_alert('danger', _l('asaas_customer_sync_failed') . ' ' . $customer_res['error']);
            redirect(site_url('asaas_gateway/client/pay/' . $invoice_id . '/' . $hash));
        }
        $customer_id = $customer_res['id'];

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
                'email' => $this->clients_model->get_contact(get_primary_contact_user_id($client->userid))->email ?? '',
                'cpfCnpj' => preg_replace('/[^0-9]/', '', $client->vat ?? ''),
                'postalCode' => preg_replace('/[^0-9]/', '', $client->billing_zip ?? ''),
                'addressNumber' => '0', // Required by Asaas, defaulting to 0 as Perfex doesn't strictly enforce it separately
                'phone' => preg_replace('/[^0-9]/', '', $this->clients_model->get_contact(get_primary_contact_user_id($client->userid))->phonenumber ?? $client->phonenumber ?? ''),
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

        // Check if charge already exists
        $existing_charges = $this->asaas_lib->get_charges_by_external_reference($invoice_id);
        $charge_id = null;

        if ($existing_charges['success'] && !empty($existing_charges['data']['data'])) {
            // Find a pending charge
            foreach ($existing_charges['data']['data'] as $ec) {
                if ($ec['status'] == 'PENDING') {
                    $charge_id = $ec['id'];
                    break;
                }
            }
        }

        $save_card_recurring = $this->input->post('save_card_recurring');

        if ($save_card_recurring && $invoice->recurring > 0) {
            // Create Subscription instead of single charge
            $cycle = 'MONTHLY'; // Default
            if (isset($invoice->recurring_type) && isset($invoice->custom_recurring)) {
                 if ($invoice->recurring == 1 && $invoice->recurring_type == 'weeks') $cycle = 'WEEKLY';
                 else if ($invoice->recurring == 1 && $invoice->recurring_type == 'months') $cycle = 'MONTHLY';
                 else if ($invoice->recurring == 6 && $invoice->recurring_type == 'months') $cycle = 'SEMIANNUALLY';
                 else if ($invoice->recurring == 1 && $invoice->recurring_type == 'years') $cycle = 'YEARLY';
                 else if ($invoice->recurring == 12 && $invoice->recurring_type == 'months') $cycle = 'YEARLY';
            } else if (isset($invoice->recurring)) {
                 if ($invoice->recurring == 1) $cycle = 'MONTHLY';
                 if ($invoice->recurring == 6) $cycle = 'SEMIANNUALLY';
                 if ($invoice->recurring == 12) $cycle = 'YEARLY';
            }

            $dueDate = $invoice->duedate;
            if(strtotime($dueDate) < strtotime(date('Y-m-d'))) $dueDate = date('Y-m-d');

            $sub_data = [
                'customer' => $customer_id,
                'billingType' => 'CREDIT_CARD',
                'value' => $invoice->total,
                'nextDueDate' => $dueDate,
                'cycle' => $cycle,
                'creditCardToken' => $token_res['data']['creditCardToken'],
                'description' => 'Assinatura Cartão - Fatura #' . $invoice->number,
                'externalReference' => 'auth_' . $invoice->id
            ];
            if($split) $sub_data['split'] = $split;

            $charge_res = $this->asaas_lib->create_subscription($sub_data);

            if($charge_res['success']) {
                // Delete the pending undefined charge generated for this invoice
                if ($charge_id) $this->asaas_lib->delete_charge($charge_id);
            }

        } else {
            // Standard single charge creation/update
            if ($charge_id) {
                $charge_res = $this->asaas_lib->update_charge($charge_id, $charge_data);
            } else {
                $charge_res = $this->asaas_lib->create_charge($charge_data);
            }
        }

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

        $customer_res = $this->get_or_create_asaas_customer($client);

        if(!$customer_res['success']) {
            echo json_encode(['success' => false, 'message' => _l('asaas_customer_sync_failed') . ' ' . $customer_res['error']]);
            return;
        }
        $customer_id = $customer_res['id'];

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

        // Check if charge already exists
        $existing_charges = $this->asaas_lib->get_charges_by_external_reference($invoice_id);
        $charge_id = null;

        if ($existing_charges['success'] && !empty($existing_charges['data']['data'])) {
            // Find a pending charge
            foreach ($existing_charges['data']['data'] as $ec) {
                if ($ec['status'] == 'PENDING') {
                    $charge_id = $ec['id'];
                    break;
                }
            }
        }

        if ($charge_id) {
            // Update existing
            $charge_res = $this->asaas_lib->update_charge($charge_id, $charge_data);
        } else {
            // Create new
            $charge_res = $this->asaas_lib->create_charge($charge_data);
        }

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

        $customer_res = $this->get_or_create_asaas_customer($client);

        if(!$customer_res['success']) {
            echo json_encode(['success' => false, 'message' => _l('asaas_customer_sync_failed') . ' ' . $customer_res['error']]);
            return;
        }
        $customer_id = $customer_res['id'];

        // Ensure due date is valid (Asaas requires due date >= today for Boleto)
        $dueDate = $invoice->duedate;
        if(strtotime($dueDate) < strtotime(date('Y-m-d'))) {
            $dueDate = date('Y-m-d');
        }

        $charge_data = [
            'customer' => $customer_id,
            'billingType' => 'BOLETO',
            'value' => $invoice->total,
            'dueDate' => $dueDate,
            'externalReference' => $invoice_id,
            'description' => 'Invoice #' . $invoice->number
        ];

        $split = $this->get_split_config();
        if($split) {
            $charge_data['split'] = $split;
        }

        // Check if charge already exists
        $existing_charges = $this->asaas_lib->get_charges_by_external_reference($invoice_id);
        $charge_id = null;

        if ($existing_charges['success'] && !empty($existing_charges['data']['data'])) {
            // Find a pending charge
            foreach ($existing_charges['data']['data'] as $ec) {
                if ($ec['status'] == 'PENDING') {
                    $charge_id = $ec['id'];
                    break;
                }
            }
        }

        if ($charge_id) {
            // Update existing
            $charge_res = $this->asaas_lib->update_charge($charge_id, $charge_data);
        } else {
            // Create new
            $charge_res = $this->asaas_lib->create_charge($charge_data);
        }

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

        $customer_res = $this->get_or_create_asaas_customer($client);

        if(!$customer_res['success']) {
            echo json_encode(['success' => false, 'message' => _l('asaas_customer_sync_failed') . ' ' . $customer_res['error']]);
            return;
        }
        $customer_id = $customer_res['id'];

        // Determine Frequency
        $cycle = 'MONTHLY'; // Default
        if (isset($invoice->recurring_type) && isset($invoice->custom_recurring)) {
             if ($invoice->recurring == 1 && $invoice->recurring_type == 'weeks') {
                 $cycle = 'WEEKLY';
             } else if ($invoice->recurring == 1 && $invoice->recurring_type == 'months') {
                 $cycle = 'MONTHLY';
             } else if ($invoice->recurring == 6 && $invoice->recurring_type == 'months') {
                 $cycle = 'SEMIANNUALLY';
             } else if ($invoice->recurring == 1 && $invoice->recurring_type == 'years') {
                 $cycle = 'YEARLY';
             } else if ($invoice->recurring == 12 && $invoice->recurring_type == 'months') {
                 $cycle = 'YEARLY';
             }
        } else if (isset($invoice->recurring)) {
             if ($invoice->recurring == 1) $cycle = 'MONTHLY';
             if ($invoice->recurring == 6) $cycle = 'SEMIANNUALLY';
             if ($invoice->recurring == 12) $cycle = 'YEARLY';
        }

        $dueDate = $invoice->duedate;
        if(strtotime($dueDate) < strtotime(date('Y-m-d'))) {
            $dueDate = date('Y-m-d');
        }

        $sub_data = [
            'customer' => $customer_id,
            'billingType' => 'PIX',
            'value' => $invoice->total,
            'nextDueDate' => $dueDate,
            'cycle' => $cycle,
            'description' => 'Assinatura Pix - Fatura #' . $invoice->number,
            'externalReference' => 'auth_' . $invoice->id
        ];

        // Split Logic
        $split = $this->get_split_config();
        if($split) {
            $sub_data['split'] = $split;
        }

        $res = $this->asaas_lib->create_subscription($sub_data);

        if($res['success']) {
            // For subscriptions, Asaas generates a payment link / QR code that can be accessed via the first payment of the subscription.
            // Asaas usually creates the first charge immediately for subscriptions starting today or in the future.

            // Get the charges for this subscription to show the QR Code
            $charges = $this->asaas_lib->request('/subscriptions/' . $res['data']['id'] . '/payments', 'GET');

            $encodedImage = '';
            $payload = '';

            if ($charges['success'] && !empty($charges['data']['data'])) {
                $first_charge_id = $charges['data']['data'][0]['id'];
                $qr_res = $this->asaas_lib->request('/payments/' . $first_charge_id . '/pixQrCode', 'GET');
                if($qr_res['success']) {
                    $encodedImage = $qr_res['data']['encodedImage'];
                    $payload = $qr_res['data']['payload'];
                }
            }

            // Store auth request in DB
            $this->db->insert(db_prefix() . 'asaas_pix_auth', [
                'client_id' => $client->userid,
                'authorization_id' => $res['data']['id'], // Save subscription ID
                'status' => 'PENDING'
            ]);

            if (!empty($encodedImage)) {
                echo json_encode(['success' => true, 'encodedImage' => $encodedImage, 'payload' => $payload]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Assinatura criada, mas falha ao recuperar QR Code do primeiro pagamento.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => $res['error']]);
        }
    }

    private function get_or_create_asaas_customer($client)
    {
        $cpfCnpj = preg_replace('/[^0-9]/', '', isset($client->vat) ? $client->vat : '');

        // Fetch Primary Contact for email and phone
        $primary_contact = $this->clients_model->get_contact(get_primary_contact_user_id($client->userid));
        $client_email = $primary_contact ? $primary_contact->email : '';
        // If primary contact has no phone, fallback to client company phone
        $client_phone = ($primary_contact && !empty($primary_contact->phonenumber)) ? $primary_contact->phonenumber : (isset($client->phonenumber) ? $client->phonenumber : '');

        // Try to find existing by CPF/CNPJ
        if(!empty($cpfCnpj)) {
            $existing = $this->asaas_lib->get_customer_by_cpf_cnpj($cpfCnpj);
            if($existing['success'] && !empty($existing['data']['data'])) {
                return ['success' => true, 'id' => $existing['data']['data'][0]['id']];
            }
        }

        // Create new
        $data = [
            'name' => isset($client->company) ? $client->company : '',
            'cpfCnpj' => $cpfCnpj,
            'email' => $client_email,
            'mobilePhone' => preg_replace('/[^0-9]/', '', $client_phone),
            'externalReference' => $client->userid
        ];

        $res = $this->asaas_lib->create_customer($data);

        if($res['success']) {
            return ['success' => true, 'id' => $res['data']['id']];
        }

        log_message('error', 'Asaas Create Customer Failed: ' . print_r($res, true) . ' Data sent: ' . print_r($data, true));

        return ['success' => false, 'error' => $res['error']];
    }

    private function get_split_config()
    {
        $this->load->model('payment_modes_model');
        $gateways = $this->payment_modes_model->get('', ['active' => 1]);
        $gateway = null;
        foreach ($gateways as $g) {
            if ($g['id'] == 'asaas_gateway') {
                $gateway = $g;
                break;
            }
        }

        if(!$gateway) return null;

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
