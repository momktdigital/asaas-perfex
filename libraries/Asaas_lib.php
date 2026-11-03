<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Asaas_lib
{
    private $ci;
    private $api_key;
    private $sandbox = false;
    private $base_url_prod = 'https://api.asaas.com/v3';
    private $base_url_sandbox = 'https://sandbox.asaas.com/api/v3';

    public function __construct()
    {
        $this->ci = &get_instance();
    }

    public function set_api_key($key)
    {
        $this->api_key = $key;
    }

    public function set_sandbox($sandbox)
    {
        $this->sandbox = $sandbox;
    }

    public function request($endpoint, $method = 'GET', $data = [])
    {
        $url = ($this->sandbox ? $this->base_url_sandbox : $this->base_url_prod) . $endpoint;

        $headers = [
            'Content-Type: application/json',
            'access_token: ' . $this->api_key,
            'User-Agent: PerfexCRM-AsaasModule/1.0.0'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method == 'GET' && !empty($data)) {
             $url .= '?' . http_build_query($data);
             curl_setopt($ch, CURLOPT_URL, $url);
        }

        // Retries on rate limit (429) and, for idempotent verbs, on transient errors (5xx / network)
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $idempotent = $method != 'POST';
            $retry = $http_code == 429 || ($idempotent && ($error || $http_code >= 500));
            if (!$retry || $attempt == 3) break;
            sleep($attempt * 2);
        }
        curl_close($ch);

        if ($error) {
            log_activity('Asaas cURL Error: ' . $error);
            return ['success' => false, 'error' => $error];
        }

        $result = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300) {
            return ['success' => true, 'data' => $result];
        } else {
            // Log error
            $errorMessage = isset($result['errors'][0]['description']) ? $result['errors'][0]['description'] : 'Unknown Error';
            if ($errorMessage === 'Unknown Error') {
                $errorMessage .= ' | Raw Response: ' . $response;
            }
            log_activity('Asaas API Error [' . $http_code . ']: ' . $errorMessage . ' - Endpoint: ' . $endpoint . ' - Data: ' . json_encode($data));
            return ['success' => false, 'error' => $errorMessage, 'details' => $result];
        }
    }

    private $notif_cfg = null;

    /**
     * Reads the global notification settings from the gateway config.
     * Default (nothing saved) is: all notifications disabled.
     */
    public function get_notification_config()
    {
        if ($this->notif_cfg !== null) {
            return $this->notif_cfg;
        }
        $notif = include __DIR__ . '/../notifications_config.php';
        $cfg = ['disable_all' => true, 'events' => []];

        $this->ci->load->model('payment_modes_model');
        foreach ($this->ci->payment_modes_model->get('', ['active' => 1]) as $g) {
            if ($g['id'] == 'asaas_gateway') {
                $inst = $g['instance'];
                $v = $inst->getSetting('notif_disable_all');
                $cfg['disable_all'] = !($v === '0' || $v === 0);
                foreach ($notif['events'] as $key => $ev) {
                    foreach ($notif['channels'] as $ch => $label) {
                        $cfg['events'][$ev['event']][$ch] = ($inst->getSetting('notif_' . $key . '_' . $ch) == '1');
                    }
                }
                break;
            }
        }
        $cfg['hash'] = md5(json_encode([$cfg['disable_all'], $cfg['events']]));
        return $this->notif_cfg = $cfg;
    }

    private function get_synced_ids($hash)
    {
        $raw = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'asaas_notif_synced'])->row();
        $d = $raw ? json_decode($raw->value, true) : null;
        return (is_array($d) && ($d['hash'] ?? '') === $hash) ? ($d['ids'] ?? []) : [];
    }

    private function mark_synced($hash, $customer_id)
    {
        $ids = $this->get_synced_ids($hash);
        $ids[] = $customer_id;
        $value = json_encode(['hash' => $hash, 'ids' => array_values(array_unique($ids))]);
        $exists = $this->ci->db->get_where(db_prefix() . 'options', ['name' => 'asaas_notif_synced'])->row();
        if ($exists) {
            $this->ci->db->where('name', 'asaas_notif_synced')->update(db_prefix() . 'options', ['value' => $value]);
        } else {
            $this->ci->db->insert(db_prefix() . 'options', ['name' => 'asaas_notif_synced', 'value' => $value, 'autoload' => 0]);
        }
    }

    /**
     * Applies the global notification configuration to an Asaas customer.
     * @param bool|null $current_disabled current notificationDisabled flag, if known
     */
    public function sync_customer_notifications($customer_id, $current_disabled = null, $force = false)
    {
        $cfg = $this->get_notification_config();

        if ($cfg['disable_all']) {
            if ($current_disabled === true && !$force) {
                return true;
            }
            $r = $this->update_customer($customer_id, ['notificationDisabled' => true]);
            return $r['success'];
        }

        if (!$force && in_array($customer_id, $this->get_synced_ids($cfg['hash']))) {
            return true;
        }

        $r = $this->update_customer($customer_id, ['notificationDisabled' => false]);
        if (!$r['success']) {
            return false;
        }

        $list = $this->request('/customers/' . $customer_id . '/notifications', 'GET');
        if (!$list['success']) {
            return false;
        }

        $items = [];
        foreach ($list['data']['data'] ?? [] as $n) {
            if (!isset($cfg['events'][$n['event']])) {
                continue; // event not managed here
            }
            $ch = $cfg['events'][$n['event']];
            $items[] = [
                'id'                      => $n['id'],
                'enabled'                 => ($ch['email'] || $ch['sms'] || $ch['whatsapp']),
                'emailEnabledForCustomer'    => $ch['email'],
                'smsEnabledForCustomer'      => $ch['sms'],
                'whatsappEnabledForCustomer' => $ch['whatsapp'],
            ];
        }
        if ($items) {
            $b = $this->request('/notifications/batch', 'PUT', ['customer' => $customer_id, 'notifications' => $items]);
            if (!$b['success']) {
                return false;
            }
        }

        $this->mark_synced($cfg['hash'], $customer_id);
        return true;
    }

    /**
     * Applies the notification config to a page of existing Asaas customers.
     * Returns ['processed' => n, 'failed' => n, 'hasMore' => bool, 'next' => offset]
     */
    public function sync_all_customers_notifications($offset = 0, $limit = 20)
    {
        $res = $this->request('/customers', 'GET', ['offset' => $offset, 'limit' => $limit]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error']];
        }
        $processed = 0;
        $failed = 0;
        foreach ($res['data']['data'] ?? [] as $c) {
            if ($this->sync_customer_notifications($c['id'], !empty($c['notificationDisabled']), true)) {
                $processed++;
            } else {
                $failed++;
            }
        }
        return [
            'success'   => true,
            'processed' => $processed,
            'failed'    => $failed,
            'hasMore'   => !empty($res['data']['hasMore']),
            'next'      => $offset + $limit,
        ];
    }

    /**
     * Turns off every individual notification (email/sms/whatsapp) of a customer and the global flag.
     */
    public function disable_customer_notifications($customer_id)
    {
        $list = $this->request('/customers/' . $customer_id . '/notifications', 'GET');
        $items = [];
        if ($list['success']) {
            foreach ($list['data']['data'] ?? [] as $n) {
                $items[] = [
                    'id' => $n['id'],
                    'enabled' => false,
                    'emailEnabledForCustomer' => false,
                    'smsEnabledForCustomer' => false,
                    'whatsappEnabledForCustomer' => false,
                    'emailEnabledForProvider' => false,
                    'smsEnabledForProvider' => false,
                    'phoneCallEnabledForCustomer' => false,
                ];
            }
        }
        if ($items) {
            $this->request('/notifications/batch', 'PUT', ['customer' => $customer_id, 'notifications' => $items]);
        }
        $r = $this->update_customer($customer_id, ['notificationDisabled' => true]);
        return $r['success'];
    }

    private function norm_key($s)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s)));
    }

    /**
     * Custom field values of a client, keyed by normalized field name (e.g. "bairro", "n", "whatsapp").
     */
    private function client_custom_fields($userid)
    {
        $db = $this->ci->db;
        $rows = $db->select('f.name, v.value')
            ->from(db_prefix() . 'customfieldsvalues v')
            ->join(db_prefix() . 'customfields f', 'f.id = v.fieldid')
            ->where('v.relid', $userid)->where('v.fieldto', 'customers')
            ->get()->result_array();
        $out = [];
        foreach ($rows as $r) {
            if (trim((string) $r['value']) !== '') {
                $out[$this->norm_key($r['name'])] = trim($r['value']);
            }
        }
        return $out;
    }

    /**
     * Maps a Perfex client (+ primary contact + custom fields) to the Asaas customer payload.
     * Empty values are omitted so updates never blank existing Asaas data.
     */
    public function build_customer_payload($client)
    {
        $db = $this->ci->db;
        $contact = $db->where('userid', $client->userid)->order_by('is_primary', 'DESC')->limit(1)->get(db_prefix() . 'contacts')->row();
        $cf = $this->client_custom_fields($client->userid);

        $digits = function ($v) { return preg_replace('/[^0-9]/', '', (string) $v); };
        $phone = function ($v) use ($digits) {
            $v = $digits($v);
            if (strpos($v, '55') === 0 && strlen($v) > 11) $v = substr($v, 2);
            return $v;
        };

        $email = $contact && !empty($contact->email) ? $contact->email : '';
        if ($email === '') {
            foreach ($cf as $k => $v) {
                if (strpos($k, 'email') === 0 && filter_var($v, FILTER_VALIDATE_EMAIL)) { $email = $v; break; }
            }
        }

        $mobile = $contact && !empty($contact->phonenumber) ? $phone($contact->phonenumber) : '';
        if ($mobile === '' && !empty($cf['whatsapp'])) $mobile = $phone($cf['whatsapp']);
        $landline = $phone($client->phonenumber ?? '');
        if ($mobile === '' && strlen($landline) == 11) { $mobile = $landline; $landline = ''; }

        $addr = !empty($client->billing_street) ? $client->billing_street : ($client->address ?? '');
        $number = $cf['n'] ?? ($cf['numero'] ?? '');
        if ($number === '' && preg_match('/(?:n[º°o\.]*\s*|,\s*)(\d+)\s*$/iu', trim($addr), $m)) $number = $m[1];
        // street without the trailing number
        $street = trim(preg_replace('/[,\s-]*(?:n[º°o\.]*\s*)?\d+\s*$/iu', '', trim($addr)), " ,-");
        $zip = $digits(!empty($client->billing_zip) ? $client->billing_zip : ($client->zip ?? ''));

        $payload = [
            'name'              => $client->company ?: ($contact ? trim($contact->firstname . ' ' . $contact->lastname) : ''),
            'cpfCnpj'           => $digits($client->vat ?? ''),
            'email'             => $email,
            'company'           => $client->company ?? '',
            'mobilePhone'       => $mobile,
            'phone'             => $landline,
            'postalCode'        => $zip,
            'address'           => $street,
            'addressNumber'     => $number === '' ? 'S/N' : $number,
            'province'          => $cf['bairro'] ?? '',
            'externalReference' => (string) $client->userid,
        ];
        return array_filter($payload, function ($v) { return $v !== '' && $v !== null; });
    }

    public function find_customer($client)
    {
        $vat = preg_replace('/[^0-9]/', '', $client->vat ?? '');
        if ($vat !== '') {
            $r = $this->request('/customers', 'GET', ['cpfCnpj' => $vat]);
            if ($r['success'] && !empty($r['data']['data'])) return $r['data']['data'][0];
        }
        $r = $this->request('/customers', 'GET', ['externalReference' => (string) $client->userid]);
        if ($r['success'] && !empty($r['data']['data'])) return $r['data']['data'][0];
        return null;
    }

    /**
     * Creates the customer in Asaas or, if it exists, updates it with the Perfex data.
     * Returns ['success'=>bool, 'id'=>?, 'created'=>bool, 'error'=>?]
     */
    public function upsert_customer($client, $update_existing = true)
    {
        $payload = $this->build_customer_payload($client);
        $existing = $this->find_customer($client);
        if ($existing) {
            if ($update_existing) {
                $upd = $payload;
                unset($upd['externalReference']);
                if (!empty($existing['externalReference'])) unset($upd['externalReference']);
                else $upd['externalReference'] = (string) $client->userid;
                $r = $this->update_customer($existing['id'], $upd);
                if (!$r['success']) return ['success' => false, 'error' => $r['error']];
            }
            $this->sync_customer_notifications($existing['id'], !empty($existing['notificationDisabled']), $update_existing);
            return ['success' => true, 'id' => $existing['id'], 'created' => false];
        }
        $r = $this->create_customer($payload);
        if (!$r['success']) return ['success' => false, 'error' => $r['error']];
        return ['success' => true, 'id' => $r['data']['id'], 'created' => true];
    }

    /**
     * Syncs a page of Perfex clients to Asaas (create/update). Returns counters and next offset.
     */
    public function sync_clients_page($offset = 0, $limit = 10)
    {
        $this->ci->load->model('clients_model');
        $rows = $this->ci->db->select('userid')->order_by('userid', 'ASC')->limit($limit, $offset)->get(db_prefix() . 'clients')->result();
        $out = ['success' => true, 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $row) {
            $client = $this->ci->clients_model->get($row->userid);
            if (!$client) continue;
            $r = $this->upsert_customer($client, true);
            if (!$r['success']) {
                $out['failed']++;
                $out['errors'][] = '#' . $client->userid . ' ' . ($client->company ?? '') . ': ' . $r['error'];
            } elseif ($r['created']) {
                $out['created']++;
            } else {
                $out['updated']++;
            }
        }
        $out['hasMore'] = count($rows) == $limit;
        $out['next'] = $offset + $limit;
        return $out;
    }

    public function create_customer($data)
    {
        $cfg = $this->get_notification_config();
        // Perfex sends the notifications; Asaas notifications follow the admin's global settings
        $data['notificationDisabled'] = $cfg['disable_all'];
        $res = $this->request('/customers', 'POST', $data);

        if ($res['success']) {
            if ($cfg['disable_all']) {
                $this->disable_customer_notifications($res['data']['id']);
            } else {
                $this->sync_customer_notifications($res['data']['id'], false, true);
            }
        }
        return $res;
    }

    public function update_customer($id, $data)
    {
        return $this->request('/customers/' . $id, 'PUT', $data);
    }

    public function get_customer_by_cpf_cnpj($cpfCnpj)
    {
        $res = $this->request('/customers', 'GET', ['cpfCnpj' => $cpfCnpj]);

        // Existing customers: make sure the notification config is applied
        if ($res['success'] && !empty($res['data']['data'])) {
            foreach ($res['data']['data'] as $customer) {
                $this->sync_customer_notifications($customer['id'], !empty($customer['notificationDisabled']));
            }
        }

        return $res;
    }


    public function create_charge($data)
    {
        return $this->request('/payments', 'POST', $data);
    }

    public function refund_charge($id, $value = null)
    {
        $data = [];
        if ($value) $data['value'] = $value;
        return $this->request('/payments/' . $id . '/refund', 'POST', $data);
    }

    public function get_charge($id)
    {
        return $this->request('/payments/' . $id, 'GET');
    }

    public function update_charge($id, $data)
    {
        return $this->request('/payments/' . $id, 'PUT', $data);
    }

    public function get_charges_by_external_reference($externalReference)
    {
        return $this->request('/payments', 'GET', ['externalReference' => $externalReference]);
    }

    public function delete_charge($id)
    {
        return $this->request('/payments/' . $id, 'DELETE');
    }

    public function tokenize_credit_card($data)
    {
        return $this->request('/creditCard/tokenize', 'POST', $data);
    }

    public function create_subscription($data)
    {
        return $this->request('/subscriptions', 'POST', $data);
    }

    public function get_subscription($id)
    {
        return $this->request('/subscriptions/' . $id, 'GET');
    }

    public function create_pix_auth($data)
    {
        return $this->request('/pix/automatic/authorizations', 'POST', $data);
    }

    public function get_pix_auth($id)
    {
        return $this->request('/pix/automatic/authorizations/' . $id, 'GET');
    }

    public function create_invoice($data)
    {
        return $this->request('/invoices', 'POST', $data);
    }

    public function get_invoices_by_external_reference($externalReference)
    {
        return $this->request('/invoices', 'GET', ['externalReference' => $externalReference]);
    }

    public function get_balance()
    {
        return $this->request('/finance/balance', 'GET');
    }

    public function get_extract($limit = 10, $offset = 0)
    {
        return $this->request('/financialTransactions', 'GET', ['limit' => $limit, 'offset' => $offset]);
    }

    public function pay_bill($data)
    {
        return $this->request('/bill', 'POST', $data);
    }

    public function transfer($data)
    {
        return $this->request('/transfers', 'POST', $data);
    }

    public function request_anticipation($data)
    {
        return $this->request('/anticipations', 'POST', $data);
    }
}
