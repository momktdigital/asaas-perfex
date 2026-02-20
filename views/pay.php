<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asaas Payment - Invoice #<?php echo $invoice->number; ?></title>
    <link href="<?php echo base_url('assets/plugins/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <script src="<?php echo base_url('assets/plugins/jquery/jquery.min.js'); ?>"></script>
    <script src="<?php echo base_url('assets/plugins/bootstrap/js/bootstrap.min.js'); ?>"></script>
    <style>
        body { background-color: #f4f5f7; padding-top: 50px; }
        .panel { box-shadow: 0 1px 15px 1px rgba(62,57,107,.07); }
    </style>
</head>
<body>
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title"><?php echo _l('invoice_html_amount'); ?>: <?php echo app_format_money($invoice->total, $invoice->currency_name); ?></h3>
                </div>
                <div class="panel-body">
                    <!-- Tabs -->
                    <ul class="nav nav-tabs" role="tablist">
                        <li role="presentation" class="active"><a href="#pix" aria-controls="pix" role="tab" data-toggle="tab"><?php echo _l('asaas_pay_with_pix'); ?></a></li>
                        <li role="presentation"><a href="#credit_card" aria-controls="credit_card" role="tab" data-toggle="tab"><?php echo _l('asaas_pay_with_card'); ?></a></li>
                        <li role="presentation"><a href="#boleto" aria-controls="boleto" role="tab" data-toggle="tab"><?php echo _l('asaas_pay_with_boleto'); ?></a></li>
                    </ul>

                    <div class="tab-content">
                        <!-- Pix Tab -->
                        <div role="tabpanel" class="tab-pane active" id="pix">
                            <div class="text-center" style="padding: 20px;">
                                <p><?php echo _l('asaas_pix_qrcode'); ?></p>
                                <div id="pix_qrcode_container"></div>
                                <div id="pix_copypaste_container" style="display:none; margin-top: 10px;">
                                    <textarea class="form-control" id="pix_copypaste" rows="3" readonly></textarea>
                                    <br>
                                    <button class="btn btn-default" onclick="copyPixCode()"><?php echo _l('asaas_pix_qrcode_copy'); ?></button>
                                </div>
                                <br>
                                <button id="generate_pix" class="btn btn-success" onclick="generatePix()"><?php echo _l('asaas_pay_with_pix'); ?></button>

                                <?php if($is_recurring && $pix_auth_status != 'ACTIVE'): ?>
                                    <hr>
                                    <button id="subscribe_pix_auto" class="btn btn-primary" onclick="subscribePixAuto()"><?php echo _l('asaas_pix_automatico_subscribe'); ?></button>
                                    <div id="pix_auto_container" style="display:none;"></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Credit Card Tab -->
                        <div role="tabpanel" class="tab-pane" id="credit_card">
                            <div style="padding: 20px;">
                                <form action="<?php echo site_url('asaas_gateway/client/process_credit_card/' . $invoice->id . '/' . $hash); ?>" method="post" id="cc_form">
                                    <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                                    <div class="form-group">
                                        <label><?php echo _l('asaas_card_holder_name'); ?></label>
                                        <input type="text" name="holderName" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label><?php echo _l('asaas_card_number'); ?></label>
                                        <input type="text" name="number" class="form-control" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?php echo _l('asaas_card_expiry'); ?></label>
                                                <input type="text" name="expiry" class="form-control" placeholder="MM/YYYY" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><?php echo _l('asaas_card_cvv'); ?></label>
                                                <input type="text" name="ccv" class="form-control" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label><?php echo _l('asaas_installments'); ?></label>
                                        <select name="installmentCount" class="form-control">
                                            <option value="1">1x - <?php echo app_format_money($invoice->total, $invoice->currency_name); ?></option>
                                            <?php for($i=2; $i<=12; $i++): ?>
                                                <option value="<?php echo $i; ?>"><?php echo $i; ?>x - <?php echo app_format_money($invoice->total / $i, $invoice->currency_name); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-success btn-block"><?php echo _l('asaas_pay_with_card'); ?></button>
                                </form>
                            </div>
                        </div>

                        <!-- Boleto Tab -->
                        <div role="tabpanel" class="tab-pane" id="boleto">
                             <div class="text-center" style="padding: 20px;">
                                <button id="generate_boleto" class="btn btn-info" onclick="generateBoleto()"><?php echo _l('asaas_pay_with_boleto'); ?></button>
                                <div id="boleto_container" style="display:none; margin-top: 20px;">
                                    <a href="#" id="boleto_link" target="_blank" class="btn btn-default"><?php echo _l('asaas_boleto_link'); ?></a>
                                </div>
                             </div>
                        </div>
                    </div>
                </div>
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

    function generatePix() {
        $('#generate_pix').prop('disabled', true).text('<?php echo _l('asaas_payment_processing'); ?>');
        $.post(siteUrl + 'asaas_gateway/client/process_pix/' + invoiceId + '/' + hash, { [csrfName]: csrfHash }, function(response) {
            response = JSON.parse(response);
            if(response.success) {
                $('#pix_qrcode_container').html('<img src="data:image/jpeg;base64,' + response.encodedImage + '" />');
                $('#pix_copypaste').val(response.payload);
                $('#pix_copypaste_container').show();
                $('#generate_pix').hide();
            } else {
                alert(response.message);
                $('#generate_pix').prop('disabled', false).text('<?php echo _l('asaas_pay_with_pix'); ?>');
            }
        });
    }

    function generateBoleto() {
        $('#generate_boleto').prop('disabled', true).text('<?php echo _l('asaas_payment_processing'); ?>');
        $.post(siteUrl + 'asaas_gateway/client/process_boleto/' + invoiceId + '/' + hash, { [csrfName]: csrfHash }, function(response) {
            response = JSON.parse(response);
            if(response.success) {
                $('#boleto_link').attr('href', response.bankSlipUrl);
                $('#boleto_container').show();
                $('#generate_boleto').hide();
            } else {
                alert(response.message);
                $('#generate_boleto').prop('disabled', false).text('<?php echo _l('asaas_pay_with_boleto'); ?>');
            }
        });
    }

    function subscribePixAuto() {
        $('#subscribe_pix_auto').prop('disabled', true);
        $.post(siteUrl + 'asaas_gateway/client/process_pix_auto_auth/' + invoiceId + '/' + hash, { [csrfName]: csrfHash }, function(response) {
            response = JSON.parse(response);
             if(response.success) {
                // Show QR Code for Authorization (Immediate QR Code)
                // Assuming Asaas returns encodedImage or payload similar to normal Pix
                 $('#pix_auto_container').html('<p><?php echo _l('asaas_pix_qrcode'); ?></p><img src="data:image/jpeg;base64,' + response.encodedImage + '" /><br><textarea class="form-control" rows="3" readonly>' + response.payload + '</textarea>');
                 $('#pix_auto_container').show();
            } else {
                alert(response.message);
                $('#subscribe_pix_auto').prop('disabled', false);
            }
        });
    }

    function copyPixCode() {
        var copyText = document.getElementById("pix_copypaste");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        document.execCommand("copy");
        alert("Copied!");
    }
</script>
</body>
</html>
