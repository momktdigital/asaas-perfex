<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><i class="fa fa-exchange text-info"></i> Transferência Pix (Out)</h4>
                        <hr class="hr-panel-heading" />

                        <div class="alert alert-info">
                            <strong>Saldo Disponível:</strong> R$ <?php echo number_format($balance, 2, ',', '.'); ?>
                        </div>

                        <?php echo form_open(admin_url('asaas_gateway/account/transfers')); ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="favored_select">Favorecidos Salvos</label>
                                    <select id="favored_select" class="form-control selectpicker" data-live-search="true" onchange="fillFavored()">
                                        <option value="">-- Selecione ou digite um novo abaixo --</option>
                                        <?php foreach($favored as $fav): ?>
                                            <option value='<?php echo json_encode($fav); ?>'><?php echo $fav['name']; ?> - <?php echo $fav['pix_key']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="value">Valor da Transferência (R$)</label>
                                    <input type="number" step="0.01" class="form-control" id="value" name="value" required>
                                </div>
                                <div class="form-group">
                                    <label for="pix_type">Tipo de Chave Pix</label>
                                    <select class="form-control" id="pix_type" name="pix_type" required>
                                        <option value="CPF">CPF</option>
                                        <option value="CNPJ">CNPJ</option>
                                        <option value="EMAIL">E-mail</option>
                                        <option value="PHONE">Telefone</option>
                                        <option value="EVP">Chave Aleatória (EVP)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="pix_key">Chave Pix</label>
                                    <input type="text" class="form-control" id="pix_key" name="pix_key" required>
                                </div>
                                <div class="form-group">
                                    <label for="description">Descrição (Opcional)</label>
                                    <input type="text" class="form-control" id="description" name="description" maxlength="100">
                                </div>

                                <hr>
                                <div class="checkbox checkbox-primary">
                                    <input type="checkbox" id="save_favored" name="save_favored" value="1">
                                    <label for="save_favored">Salvar como favorecido para próximas vezes</label>
                                </div>
                                <div id="favored_details" style="display:none; margin-top: 10px;">
                                    <div class="form-group">
                                        <label>Nome do Favorecido</label>
                                        <input type="text" class="form-control" id="favored_name" name="favored_name">
                                    </div>
                                    <div class="form-group">
                                        <label>CPF/CNPJ do Favorecido</label>
                                        <input type="text" class="form-control" id="favored_cpf" name="favored_cpf">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-info mtop15">Realizar Transferência Pix</button>
                            </div>
                        </div>
                        <?php echo form_close(); ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<script>
    $('#save_favored').on('change', function(){
        if($(this).is(':checked')) {
            $('#favored_details').slideDown();
            $('#favored_name').attr('required', true);
            $('#favored_cpf').attr('required', true);
        } else {
            $('#favored_details').slideUp();
            $('#favored_name').removeAttr('required');
            $('#favored_cpf').removeAttr('required');
        }
    });

    function fillFavored() {
        var val = $('#favored_select').val();
        if(val) {
            var data = JSON.parse(val);
            $('#pix_type').val(data.pix_type);
            $('#pix_key').val(data.pix_key);
            $('#save_favored').prop('checked', false).trigger('change');
        } else {
            $('#pix_type').val('CPF');
            $('#pix_key').val('');
        }
    }
</script>
</body>
</html>
