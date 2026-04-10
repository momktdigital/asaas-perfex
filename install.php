<?php
defined('BASEPATH') or exit('No direct script access allowed');

$CI = &get_instance();

if (!$CI->db->table_exists(db_prefix() . 'asaas_pix_auth')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . 'asaas_pix_auth` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `authorization_id` varchar(255) NOT NULL,
        `status` varchar(50) DEFAULT "PENDING",
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `client_id` (`client_id`),
        KEY `authorization_id` (`authorization_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=' . $CI->db->char_set . ';');
}

// Add Custom Field for NFS-e automatic emission if not exists
$CI->db->where('name', 'Emitir NF-e Automática no Asaas?');
$CI->db->where('fieldto', 'invoice');
$cf = $CI->db->get(db_prefix() . 'customfields')->row();

if (!$cf) {
    $CI->db->insert(db_prefix() . 'customfields', [
        'fieldto' => 'invoice',
        'name' => 'Emitir NF-e Automática no Asaas?',
        'type' => 'select',
        'options' => 'Não,Sim',
        'active' => 1,
        'show_on_pdf' => 0,
        'show_on_client_portal' => 0,
        'show_on_table' => 0
    ]);
}
