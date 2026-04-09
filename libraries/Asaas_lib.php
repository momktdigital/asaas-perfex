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

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
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

    public function create_customer($data)
    {
        return $this->request('/customers', 'POST', $data);
    }

    public function get_customer_by_cpf_cnpj($cpfCnpj)
    {
        return $this->request('/customers', 'GET', ['cpfCnpj' => $cpfCnpj]);
    }

    public function create_charge($data)
    {
        return $this->request('/payments', 'POST', $data);
    }

    public function get_charge($id)
    {
        return $this->request('/payments/' . $id, 'GET');
    }

    public function delete_charge($id)
    {
        return $this->request('/payments/' . $id, 'DELETE');
    }

    public function tokenize_credit_card($data)
    {
        return $this->request('/creditCard/tokenize', 'POST', $data);
    }

    public function create_pix_auth($data)
    {
        return $this->request('/pix/automatic/authorizations', 'POST', $data);
    }

    public function get_pix_auth($id)
    {
        return $this->request('/pix/automatic/authorizations/' . $id, 'GET');
    }
}
