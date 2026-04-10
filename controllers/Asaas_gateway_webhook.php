<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Asaas_gateway_webhook extends ClientsController
{
    public function notify()
    {
        try {
            $this->load->model('payment_modes_model');
        $gateways = $this->payment_modes_model->get('', ['active' => 1]);
            $gateway = null;
            foreach ($gateways as $g) {
                if ($g['id'] == 'asaas_gateway') {
                    $gateway = $g;
                    break;
                }
            }

            if (!$gateway) {
                header("HTTP/1.1 500 Internal Server Error");
                echo json_encode(['error' => 'Gateway not found']);
                return;
            }

            $is_sandbox = $gateway['instance']->getSetting('sandbox');
            $webhook_field = $is_sandbox == 1 ? 'webhook_token_sandbox' : 'webhook_token_prod';
            $token = $this->encryption->decrypt($gateway['instance']->getSetting($webhook_field));
            $headers = $this->input->request_headers();
            $incoming_token = '';

            foreach($headers as $key => $val) {
                if(strtolower($key) == 'asaas-access-token') {
                    $incoming_token = $val;
                    break;
                }
            }

            if ($token !== $incoming_token || empty($token)) {
                log_activity('Asaas Webhook Failed: Invalid Token');
                header("HTTP/1.1 401 Unauthorized");
                echo json_encode(['error' => 'Invalid Token']);
                return;
            }

            $post_data = json_decode(file_get_contents('php://input'), true);

            if (!$post_data || !isset($post_data['event'])) {
                header("HTTP/1.1 400 Bad Request");
                echo json_encode(['error' => 'Invalid request payload']);
                return;
            }

            $event = $post_data['event'];
            $payment = isset($post_data['payment']) ? $post_data['payment'] : null;

            if ($event == 'PAYMENT_RECEIVED' && $payment) {
                $this->handle_payment_received($payment);
            }

            header("HTTP/1.1 200 OK");
            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            log_activity('Asaas Webhook Error: ' . $e->getMessage());
            header("HTTP/1.1 500 Internal Server Error");
            echo json_encode(['error' => 'Internal Server Error']);
        }
    }

    private function handle_payment_received($payment)
    {
        $externalReference = isset($payment['externalReference']) ? $payment['externalReference'] : '';
        if (empty($externalReference)) {
            return;
        }

        // Check if it is Auth
        if (strpos($externalReference, 'auth_') === 0) {
            $invoice_id = str_replace('auth_', '', $externalReference);
            $this->load->model('invoices_model');
            $invoice = $this->invoices_model->get($invoice_id);
            if ($invoice) {
                // Mark PENDING auth as ACTIVE for this client
                // Ideally we should match authorization ID but for MVP this links the flow
                $this->db->where('client_id', $invoice->clientid);
                $this->db->where('status', 'PENDING');
                $this->db->update(db_prefix() . 'asaas_pix_auth', ['status' => 'ACTIVE']);

                // We should also record the payment for the invoice!
                // Because the user paid the first installment/amount.
                // So we fall through to payment recording logic using the invoice_id.
            } else {
                return; // Invalid invoice
            }
        } else {
            $invoice_id = $externalReference;
        }

        $this->load->model('invoices_model');
        $this->load->model('payments_model');
        $invoice = $this->invoices_model->get($invoice_id);

        if ($invoice) {
            // Check if payment exists
            $this->db->where('transactionid', $payment['id']);
            $exists = $this->db->get(db_prefix() . 'invoicepaymentrecords')->row();

            if (!$exists) {
                $payment_data = [
                    'amount' => $payment['value'],
                    'invoiceid' => $invoice_id,
                    'paymentmode' => 'asaas_gateway',
                    'date' => date('Y-m-d'),
                    'transactionid' => $payment['id'],
                    'note' => 'Payment via Asaas API. Status: ' . $payment['status']
                ];
                $this->payments_model->add($payment_data);
                log_activity('Asaas Payment Recorded for Invoice ' . $invoice_id);
            }
        }
    }
}