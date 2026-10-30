<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Asaas customer notification events/channels that can be controlled globally
 * in the module settings. Keys of 'events' are used in setting names:
 * notif_{key}_{channel}
 */
return [
    'events' => [
        'created'         => ['event' => 'PAYMENT_CREATED', 'label' => 'Avisar criação de novas cobranças'],
        'updated'         => ['event' => 'PAYMENT_UPDATED', 'label' => 'Avisar alteração de valor/vencimento'],
        'duedate_warning' => ['event' => 'PAYMENT_DUEDATE_WARNING', 'label' => 'Lembretes antes e no dia do vencimento'],
        'linha_digitavel' => ['event' => 'SEND_LINHA_DIGITAVEL', 'label' => 'Linha digitável do boleto'],
        'overdue'         => ['event' => 'PAYMENT_OVERDUE', 'label' => 'Atrasos e lembretes de cobranças vencidas'],
    ],
    'channels' => [
        'email'    => 'E-mail',
        'sms'      => 'SMS',
        'whatsapp' => 'WhatsApp',
    ],
];
