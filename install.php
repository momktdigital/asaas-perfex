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
