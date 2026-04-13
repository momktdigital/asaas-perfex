<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><i class="fa fa-bank text-success"></i> Gestão da Conta Asaas</h4>
                        <hr class="hr-panel-heading" />

                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="widget-box widget-color-blue2">
                                    <div class="widget-header">
                                        <h5 class="widget-title bigger lighter">Saldo Disponível</h5>
                                    </div>
                                    <div class="widget-body">
                                        <div class="widget-main padding-16 text-center">
                                            <h2 style="color: #007bff; font-weight: bold; margin-top:0;">R$ <?php echo number_format($balance, 2, ',', '.'); ?></h2>
                                            <p class="text-muted">Atualizado agora</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h4 class="mtop30"><i class="fa fa-list"></i> Últimas Movimentações (Extrato)</h4>
                        <hr class="hr-panel-heading" />

                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Tipo</th>
                                        <th>Descrição</th>
                                        <th>Valor</th>
                                        <th>Saldo Após</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($extract)): ?>
                                        <tr><td colspan="5" class="text-center">Nenhuma movimentação encontrada.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($extract as $trx): ?>
                                            <?php
                                                $val = $trx['value'];
                                                $color = $val >= 0 ? 'text-success' : 'text-danger';
                                                $icon = $val >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                            ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y H:i', strtotime($trx['date'])); ?></td>
                                                <td><?php echo $trx['type']; ?></td>
                                                <td><?php echo $trx['description'] ?? '-'; ?></td>
                                                <td class="<?php echo $color; ?>"><i class="fa <?php echo $icon; ?>"></i> R$ <?php echo number_format(abs($val), 2, ',', '.'); ?></td>
                                                <td>R$ <?php echo number_format($trx['balance'], 2, ',', '.'); ?></td>
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
