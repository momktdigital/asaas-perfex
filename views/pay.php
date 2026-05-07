<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagamento da Fatura #<?php echo $invoice->number; ?></title>

    <link href="<?php echo base_url('assets/plugins/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <!-- FontAwesome for icons -->
    <link href="<?php echo base_url('assets/plugins/font-awesome/css/font-awesome.min.css'); ?>" rel="stylesheet">

    <script src="<?php echo base_url('assets/plugins/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo base_url('assets/plugins/bootstrap/js/bootstrap.min.js'); ?>"></script>

    <style>
        body {
            background-color: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #333;
        }
        .checkout-wrapper {
            margin-top: 50px;
            margin-bottom: 50px;
        }
        .panel-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .panel-custom .panel-heading {
            background-color: #fff;
            border-bottom: 1px solid #edf1f5;
            padding: 20px 30px;
            text-align: center;
        }
        .company-logo {
            max-height: 50px;
            margin-bottom: 15px;
        }
        .invoice-summary {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        .invoice-amount {
            font-size: 32px;
            font-weight: 700;
            color: #28a745;
            margin: 10px 0 0 0;
        }
        .nav-tabs-custom {
            border-bottom: 2px solid #edf1f5;
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
        }
        .nav-tabs-custom > li {
            margin-bottom: -2px;
            flex: 1;
            text-align: center;
        }
        .nav-tabs-custom > li > a {
            border: none;
            color: #6c757d;
            font-weight: 600;
            font-size: 15px;
            padding: 15px 20px;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .nav-tabs-custom > li > a:hover {
            background: transparent;
            color: #007bff;
        }
        .nav-tabs-custom > li.active > a,
        .nav-tabs-custom > li.active > a:hover,
        .nav-tabs-custom > li.active > a:focus {
            border: none;
            border-bottom: 2px solid #007bff;
            color: #007bff;
            background: transparent;
        }
        .tab-icon {
            display: block;
            font-size: 24px;
            margin-bottom: 5px;
        }
        .btn-custom {
            border-radius: 8px;
            font-weight: 600;
            padding: 12px 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s;
        }
        .btn-pix {
            background-color: #32bcad;
            border-color: #32bcad;
            color: #fff;
        }
        .btn-pix:hover {
            background-color: #2ba093;
            border-color: #2ba093;
            color: #fff;
        }
        .btn-boleto {
            background-color: #4a5568;
            border-color: #4a5568;
            color: #fff;
        }
        .btn-boleto:hover {
            background-color: #343a40;
            border-color: #343a40;
            color: #fff;
        }
        .form-control-custom {
            border-radius: 6px;
            border: 1px solid #ced4da;
            padding: 10px 15px;
            height: auto;
            box-shadow: none;
        }
        .form-control-custom:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        .pix-container {
            background: #fff;
            border: 1px solid #edf1f5;
            border-radius: 12px;
            padding: 30px;
            margin-top: 20px;
        }
        .qr-wrapper img {
            max-width: 250px;
            border-radius: 8px;
            border: 1px solid #edf1f5;
            padding: 10px;
            background: #fff;
        }
        .copy-paste-box {
            position: relative;
            margin-top: 20px;
        }
        .copy-paste-box textarea {
            font-family: monospace;
            font-size: 13px;
            resize: none;
            padding-right: 50px;
        }
        .btn-copy {
            position: absolute;
            right: 5px;
            bottom: 5px;
            height: calc(100% - 10px);
            background: #f8f9fa;
            border: 1px solid #ced4da;
            border-radius: 4px;
            color: #495057;
            padding: 0 15px;
            transition: all 0.2s;
        }
        .btn-copy:hover {
            background: #e2e6ea;
        }
        .alert-custom {
            border-radius: 8px;
            display: none;
            margin-top: 20px;
        }
        .loading-spinner {
            display: none;
            margin-right: 8px;
        }
    </style>
</head>
<body>
<div class="container checkout-wrapper">
    <div class="row">
        <div class="col-md-8 col-md-offset-2 col-lg-6 col-lg-offset-3">

            <!-- Alert Box for errors/success -->
            <div id="checkout-alert" class="alert alert-custom" role="alert"></div>

            <div class="panel panel-default panel-custom">
                <div class="panel-heading">
                    <?php if(get_option('company_logo') != ''){ ?>
                        <img src="<?php echo base_url('uploads/company/'.get_option('company_logo')); ?>" class="company-logo" alt="<?php echo get_option('companyname'); ?>">
                    <?php } else { ?>
                        <h2><?php echo get_option('companyname'); ?></h2>
                    <?php } ?>
                    <p class="text-muted m-b-0">Checkout de Pagamento Seguro</p>
                </div>

                <div class="panel-body" style="padding: 30px;">

                    <!-- Invoice Summary -->
                    <div class="invoice-summary">
                        <p class="text-muted text-uppercase" style="letter-spacing: 1px; font-size: 12px; margin-bottom: 5px;">Fatura #<?php echo $invoice->number; ?></p>
                        <h2 class="invoice-amount"><?php echo app_format_money($invoice->total, $invoice->currency_name); ?></h2>
                        <p class="text-muted" style="margin-top: 8px;">Vencimento: <strong><?php echo _d($invoice->duedate); ?></strong></p>
                    </div>

                    <!-- Tabs -->
                    <ul class="nav nav-tabs nav-tabs-custom" role="tablist">
                        <li role="presentation" class="active">
                            <a href="#pix" aria-controls="pix" role="tab" data-toggle="tab">
                                <i class="fa fa-qrcode tab-icon" style="color: #32bcad;"></i>
                                PIX
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#credit_card" aria-controls="credit_card" role="tab" data-toggle="tab">
                                <i class="fa fa-credit-card tab-icon" style="color: #007bff;"></i>
                                Cartão
                            </a>
                        </li>
                        <li role="presentation">
                            <a href="#boleto" aria-controls="boleto" role="tab" data-toggle="tab">
                                <i class="fa fa-barcode tab-icon" style="color: #4a5568;"></i>
                                Boleto
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content">

                        <!-- PIX TAB -->
                        <div role="tabpanel" class="tab-pane active fade in" id="pix">
                            <div class="text-center">
                                <p class="text-muted">O pagamento via Pix é aprovado em poucos segundos.</p>

                                <button id="generate_pix" class="btn btn-custom btn-pix btn-block btn-lg" onclick="generatePix()">
                                    <i class="fa fa-spinner fa-spin loading-spinner"></i>
                                    Gerar QR Code Pix
                                </button>

                                <?php if($is_recurring && $pix_auth_status != 'ACTIVE'): ?>
                                    <div style="margin: 15px 0;">
                                        <span class="text-muted" style="font-size: 12px; display: block; margin-bottom: 10px;">- OU -</span>
                                        <button id="subscribe_pix_auto" class="btn btn-custom btn-primary btn-block" onclick="subscribePixAuto()">
                                            <i class="fa fa-spinner fa-spin loading-spinner"></i>
                                            <i class="fa fa-refresh"></i> Assinar Pix Mensal Automático
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <!-- Container where QR Code appears -->
                                <div id="pix_qrcode_container" class="pix-container" style="display:none;">
                                    <h4 style="margin-top: 0; margin-bottom: 20px; font-weight: 600;">Escaneie o QR Code</h4>
                                    <div id="qr_image" class="qr-wrapper"></div>

                                    <div class="copy-paste-box">
                                        <p class="text-left text-muted" style="margin-bottom: 5px; font-size: 13px;">Ou copie o código (Pix Copia e Cola):</p>
                                        <textarea class="form-control form-control-custom" id="pix_copypaste" rows="2" readonly></textarea>
                                        <button class="btn btn-copy" onclick="copyPixCode()" title="Copiar código">
                                            <i class="fa fa-copy"></i> Copiar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CREDIT CARD TAB -->
                        <div role="tabpanel" class="tab-pane fade" id="credit_card">
                            <form action="<?php echo site_url('asaas_gateway/client/process_credit_card/' . $invoice->id . '/' . $hash); ?>" method="post" id="cc_form" onsubmit="return processCreditCard(event)">
                                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">

                                <div class="form-group">
                                    <label class="control-label">Nome impresso no cartão</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><i class="fa fa-user"></i></span>
                                        <input type="text" name="holderName" class="form-control form-control-custom" placeholder="NOME DO TITULAR" required autocomplete="cc-name">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="control-label">Número do Cartão</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><i class="fa fa-credit-card"></i></span>
                                        <input type="text" id="cc-number" name="number" class="form-control form-control-custom" placeholder="0000 0000 0000 0000" required autocomplete="cc-number" maxlength="19">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-xs-6">
                                        <div class="form-group">
                                            <label class="control-label">Validade</label>
                                            <input type="text" id="cc-expiry" name="expiry" class="form-control form-control-custom" placeholder="MM/YYYY" required autocomplete="cc-exp" maxlength="7">
                                        </div>
                                    </div>
                                    <div class="col-xs-6">
                                        <div class="form-group">
                                            <label class="control-label">CVV</label>
                                            <input type="text" id="cc-cvv" name="ccv" class="form-control form-control-custom" placeholder="123" required autocomplete="cc-csc" maxlength="4">
                                        </div>
                                    </div>
                                </div>

                                <?php if ($is_recurring): ?>
                                    <div class="form-group" style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #edf1f5;">
                                        <div class="checkbox checkbox-primary m-b-0 m-t-0">
                                            <input type="checkbox" id="save_card_recurring" name="save_card_recurring" value="1">
                                            <label for="save_card_recurring" style="font-weight: 600; color: #007bff;">
                                                Salvar este cartão para cobrar automaticamente as próximas faturas mensais
                                            </label>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" id="btn_cc_submit" class="btn btn-primary btn-custom btn-block btn-lg" style="margin-top: 20px;">
                                    <i class="fa fa-spinner fa-spin loading-spinner"></i>
                                    Pagar com Cartão
                                </button>
                            </form>
                            <div class="text-center" style="margin-top: 15px;">
                                <i class="fa fa-lock text-muted"></i> <span class="text-muted" style="font-size: 12px;">Pagamento 100% seguro processado por Asaas</span>
                            </div>
                        </div>

                        <!-- BOLETO TAB -->
                        <div role="tabpanel" class="tab-pane fade" id="boleto">
                             <div class="text-center" style="padding: 20px 0;">
                                <p class="text-muted" style="margin-bottom: 25px;">O boleto bancário pode levar até 3 dias úteis para ser compensado após o pagamento.</p>

                                <button id="generate_boleto" class="btn btn-boleto btn-custom btn-block btn-lg" onclick="generateBoleto()">
                                    <i class="fa fa-spinner fa-spin loading-spinner"></i>
                                    Gerar Boleto Bancário
                                </button>

                                <div id="boleto_container" class="pix-container" style="display:none;">
                                    <i class="fa fa-check-circle" style="font-size: 48px; color: #28a745; margin-bottom: 15px;"></i>
                                    <h4 style="margin-top: 0; font-weight: 600;">Boleto Gerado com Sucesso!</h4>
                                    <p class="text-muted" style="margin-bottom: 20px;">Clique no botão abaixo para visualizar ou imprimir o seu boleto.</p>
                                    <a href="#" id="boleto_link" target="_blank" class="btn btn-primary btn-custom btn-block">Visualizar Boleto</a>
                                </div>
                             </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="text-center" style="margin-bottom: 30px;">
                <a href="<?php echo site_url('invoice/'.$invoice->id.'/'.$hash); ?>" class="text-muted"><i class="fa fa-arrow-left"></i> Voltar para a fatura</a>
            </div>

            <div class="text-center" style="margin-bottom: 50px;">
                <p class="text-muted" style="font-size: 13px;">
                    Solução desenvolvida por <a href="https://nonamo.com.br" target="_blank" style="color: #007bff; font-weight: 600; text-decoration: none;">Nonamo</a>
                </p>
            </div>

        </div>
    </div>
</div>

<script>
    var csrfName = '<?php echo $this->security->get_csrf_token_name(); ?>';
    var csrfHash = '<?php echo $this->security->get_csrf_hash(); ?>';
    var invoiceId = '<?php echo $invoice->id; ?>';
    var hash = '<?php echo $hash; ?>';
    var siteUrl = '<?php echo site_url(); ?>';

    function showAlert(message, type) {
        var alertBox = $('#checkout-alert');
        alertBox.removeClass('alert-success alert-danger').addClass('alert-' + type);
        alertBox.html(message).slideDown();

        // Auto hide after 5 seconds if success
        if(type === 'success') {
            setTimeout(function() { alertBox.slideUp(); }, 5000);
        }
    }

    function generatePix() {
        var btn = $('#generate_pix');
        btn.prop('disabled', true);
        btn.find('.loading-spinner').show();
        $('#checkout-alert').slideUp();

        $.post(siteUrl + 'asaas_gateway/client/process_pix/' + invoiceId + '/' + hash, { [csrfName]: csrfHash }, function(response) {
            response = JSON.parse(response);
            btn.find('.loading-spinner').hide();

            if(response.success) {
                $('#qr_image').html('<img src="data:image/jpeg;base64,' + response.encodedImage + '" alt="QR Code Pix" />');
                $('#pix_copypaste').val(response.payload);
                btn.hide();
                if($('#subscribe_pix_auto').length > 0) $('#subscribe_pix_auto').parent().hide();
                $('#pix_qrcode_container').slideDown();
                showAlert('<i class="fa fa-check-circle"></i> QR Code gerado! Escaneie ou use o Copia e Cola.', 'success');
            } else {
                showAlert('<i class="fa fa-exclamation-circle"></i> ' + response.message, 'danger');
                btn.prop('disabled', false);
            }
        }).fail(function() {
            btn.find('.loading-spinner').hide();
            btn.prop('disabled', false);
            showAlert('<i class="fa fa-exclamation-circle"></i> Erro de comunicação com o servidor.', 'danger');
        });
    }

    function generateBoleto() {
        var btn = $('#generate_boleto');
        btn.prop('disabled', true);
        btn.find('.loading-spinner').show();
        $('#checkout-alert').slideUp();

        $.post(siteUrl + 'asaas_gateway/client/process_boleto/' + invoiceId + '/' + hash, { [csrfName]: csrfHash }, function(response) {
            response = JSON.parse(response);
            btn.find('.loading-spinner').hide();

            if(response.success) {
                $('#boleto_link').attr('href', response.bankSlipUrl);
                btn.hide();
                $('#boleto_container').slideDown();
                showAlert('<i class="fa fa-check-circle"></i> Boleto gerado com sucesso.', 'success');
            } else {
                showAlert('<i class="fa fa-exclamation-circle"></i> ' + response.message, 'danger');
                btn.prop('disabled', false);
            }
        }).fail(function() {
            btn.find('.loading-spinner').hide();
            btn.prop('disabled', false);
            showAlert('<i class="fa fa-exclamation-circle"></i> Erro de comunicação com o servidor.', 'danger');
        });
    }

    function subscribePixAuto() {
        var btn = $('#subscribe_pix_auto');
        btn.prop('disabled', true);
        btn.find('.loading-spinner').show();
        $('#checkout-alert').slideUp();

        $.post(siteUrl + 'asaas_gateway/client/process_pix_auto_auth/' + invoiceId + '/' + hash, { [csrfName]: csrfHash }, function(response) {
            response = JSON.parse(response);
            btn.find('.loading-spinner').hide();

             if(response.success) {
                $('#qr_image').html('<img src="data:image/jpeg;base64,' + response.encodedImage + '" alt="QR Code Pix" />');
                $('#pix_copypaste').val(response.payload);
                btn.parent().hide();
                $('#generate_pix').hide();
                $('#pix_qrcode_container').slideDown();
                showAlert('<i class="fa fa-check-circle"></i> Assinatura Pix criada! Escaneie o QR Code abaixo para confirmar o pagamento inicial e autorizar os próximos.', 'success');
            } else {
                showAlert('<i class="fa fa-exclamation-circle"></i> ' + response.message, 'danger');
                btn.prop('disabled', false);
            }
        }).fail(function() {
            btn.find('.loading-spinner').hide();
            btn.prop('disabled', false);
            showAlert('<i class="fa fa-exclamation-circle"></i> Erro de comunicação com o servidor.', 'danger');
        });
    }

    $(document).ready(function() {
        // Credit Card Number Mask
        $('#cc-number').on('input', function() {
            var val = $(this).val().replace(/\D/g, '');
            var formatted = val.match(/.{1,4}/g);
            $(this).val(formatted ? formatted.join(' ') : '');
        });

        // Credit Card Expiry Mask (MM/YYYY)
        $('#cc-expiry').on('input', function() {
            var val = $(this).val().replace(/\D/g, '');
            if (val.length > 2) {
                $(this).val(val.substring(0, 2) + '/' + val.substring(2, 6));
            } else {
                $(this).val(val);
            }
        });

        // CVV Mask (Numbers only)
        $('#cc-cvv').on('input', function() {
            $(this).val($(this).val().replace(/\D/g, ''));
        });
    });

    function processCreditCard(event) {
        event.preventDefault();
        var form = $('#cc_form');
        var btn = $('#btn_cc_submit');

        // Client-side Expiry Date Validation
        var expiry = $('#cc-expiry').val();
        if(expiry.length !== 7) {
            showAlert('<i class="fa fa-exclamation-circle"></i> O formato da validade deve ser MM/YYYY', 'danger');
            return false;
        }

        var parts = expiry.split('/');
        var expMonth = parseInt(parts[0], 10);
        var expYear = parseInt(parts[1], 10);

        var currentDate = new Date();
        var currentMonth = currentDate.getMonth() + 1;
        var currentYear = currentDate.getFullYear();

        if (expMonth < 1 || expMonth > 12) {
            showAlert('<i class="fa fa-exclamation-circle"></i> Mês de validade inválido.', 'danger');
            return false;
        }

        if (expYear < currentYear || (expYear === currentYear && expMonth < currentMonth)) {
            showAlert('<i class="fa fa-exclamation-circle"></i> O cartão inserido já está vencido.', 'danger');
            return false;
        }

        btn.prop('disabled', true);
        btn.find('.loading-spinner').show();
        $('#checkout-alert').slideUp();

        $.ajax({
            type: form.attr('method'),
            url: form.attr('action'),
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                btn.find('.loading-spinner').hide();
                if(response.success) {
                    showAlert('<i class="fa fa-check-circle"></i> ' + response.message, 'success');
                    setTimeout(function() {
                        window.location.href = response.redirect_url;
                    }, 2000);
                } else {
                    showAlert('<i class="fa fa-exclamation-circle"></i> ' + response.message, 'danger');
                    btn.prop('disabled', false);
                }
            },
            error: function() {
                btn.find('.loading-spinner').hide();
                btn.prop('disabled', false);
                showAlert('<i class="fa fa-exclamation-circle"></i> Erro de comunicação com o servidor.', 'danger');
            }
        });

        return false;
    }

    function copyPixCode() {
        var copyText = document.getElementById("pix_copypaste");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        document.execCommand("copy");

        var btn = $('.btn-copy');
        var originalHTML = btn.html();
        btn.html('<i class="fa fa-check text-success"></i> Copiado').addClass('btn-success').removeClass('btn-default');

        setTimeout(function() {
            btn.html(originalHTML).removeClass('btn-success').addClass('btn-default');
        }, 3000);
    }
</script>
</body>
</html>
