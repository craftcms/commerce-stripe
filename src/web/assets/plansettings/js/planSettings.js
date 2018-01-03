var $stripeButton = $('.stripe-refresh-plans');

$stripeButton.on('click', function (ev) {
    if ($stripeButton.hasClass('disabled'))
    {
        return;
    }

    $stripeButton.addClass('disabled').siblings('.spinner').removeClass('hidden');

    var gatewayId = $('.gateway-select select').val();
    var $planSelect = $('.plan-select-' + gatewayId + ' select');

    var data = {
        gatewayId: gatewayId
    };

    Craft.postActionRequest('commerce-stripe', data, function(response, textStatus)
    {
        $stripeButton.removeClass('disabled').siblings('.spinner').addClass('hidden');

        if (textStatus === 'success') {
            if (response.error) {
                alert(response.error);
            } else if (response.length > 0) {
                var currentPlan = $planSelect.val(),
                    currentPlanStillExists = false;

                $planSelect.empty();

                for (var i = 0; i < response.length; i++) {
                    if (response[i].reference === currentPlan) {
                        currentPlanStillExists = true;
                    }

                    $planSelect.append('<option value="'+response[i].reference+'">'+response[i].name+'</option>');
                }

                if (currentPlanStillExists) {
                    $planSelect.val(currentPlan);
                }
            }
        }
    });
});