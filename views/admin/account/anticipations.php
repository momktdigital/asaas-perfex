<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><i class="fa fa-fast-forward text-warning"></i> Antecipação de Recebíveis (Cartão de Crédito)</h4>
                        <hr class="hr-panel-heading" />

                        <div class="alert alert-warning">
                            <strong>Atenção:</strong> Apenas cobranças por cartão de crédito que já constam como CONFIRMADAS podem ser elegíveis para antecipação, sujeitas à aprovação do Asaas e taxas adicionais.
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID da Cobrança Asaas</th>
                                        <th>Cliente</th>
                                        <th>Valor Líquido</th>
                                        <th>Data Prevista</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($eligible_charges)): ?>
                                        <tr><td colspan="5" class="text-center">Nenhuma cobrança confirmada elegível no momento.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($eligible_charges as $charge): ?>
                                            <tr>
                                                <td><?php echo $charge['id']; ?></td>
                                                <td><?php echo isset($charge['customer']) ? $charge['customer'] : '-'; ?></td>
                                                <td>R$ <?php echo number_format($charge['netValue'], 2, ',', '.'); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($charge['estimatedCreditDate'])); ?></td>
                                                <td>
                                                    <?php echo form_open(admin_url('asaas_gateway/account/anticipations'), ['class' => 'inline']); ?>
                                                        <input type="hidden" name="charge_id" value="<?php echo $charge['id']; ?>">
                                                        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Confirmar solicitação de antecipação deste recebível? Taxas adicionais do Asaas podem ser aplicadas.');">Solicitar Antecipação</button>
                                                    <?php echo form_close(); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
