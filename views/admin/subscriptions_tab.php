<div class="row">
    <div class="col-md-12">
        <h4 class="no-margin font-bold"><i class="fa fa-refresh"></i> Assinaturas Recorrentes</h4>
        <hr />

        <div id="asaas_subscriptions_wrapper">
            <div class="text-center" style="padding: 20px;">
                <i class="fa fa-spinner fa-spin fa-2x"></i>
                <p>Carregando assinaturas do gateway...</p>
            </div>
        </div>
    </div>
</div>

<script>
    $(function() {
        if ($('.customer-profile-group-asaas_subscriptions').is(':visible')) {
            loadAsaasSubscriptions();
        }

        $('a[href="#asaas_subscriptions"]').on('shown.bs.tab', function (e) {
            loadAsaasSubscriptions();
        });
    });

    function loadAsaasSubscriptions() {
        var clientid = '<?php echo $client->userid; ?>';
        $.post(admin_url + 'asaas_gateway/admin/get_customer_subscriptions', { clientid: clientid }, function(response) {
            $('#asaas_subscriptions_wrapper').html(response);
        }).fail(function() {
            $('#asaas_subscriptions_wrapper').html('<div class="alert alert-danger">Erro ao carregar assinaturas. Verifique a configuração da API.</div>');
        });
    }

    function cancelAsaasSubscription(id) {
        if(confirm('Tem certeza que deseja cancelar permanentemente esta assinatura? O cliente precisará fazer o processo novamente para novas cobranças automáticas.')) {
            $.post(admin_url + 'asaas_gateway/admin/cancel_subscription', { id: id }, function(response) {
                response = JSON.parse(response);
                if(response.success) {
                    alert_float('success', 'Assinatura cancelada com sucesso!');
                    loadAsaasSubscriptions();
                } else {
                    alert_float('danger', 'Erro: ' + response.error);
                }
            }).fail(function() {
                alert_float('danger', 'Erro na requisição.');
            });
        }
    }
</script>
