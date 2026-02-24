<?php
// Staff-facing payment form template
// Variables: $ticket, $appId, $locationId, $sandbox, $currency, $payments
if (!defined('INCLUDE_DIR')) die('Access Denied');
$sdkUrl = $sandbox
    ? 'https://sandbox.web.squarecdn.com/v1/square.js'
    : 'https://web.squarecdn.com/v1/square.js';
?>
<h3><?php echo __('Process Payment'); ?> &mdash;
    <?php echo sprintf(__('Ticket #%s'), Format::htmlchars($ticket->getNumber())); ?></h3>
<p class="faded"><?php echo Format::htmlchars($ticket->getSubject()); ?></p>

<ul class="tabs" id="square-tabs">
    <li class="active"><a href="#sq-tab-charge"><?php echo __('Charge'); ?></a></li>
    <li><a href="#sq-tab-history"><?php echo __('Payment History'); ?></a></li>
</ul>

<div id="sq-tab-charge" class="tab_content" style="padding: 10px 0;">
<form id="square-payment-form">
    <table class="form_table" width="100%" border="0" cellspacing="0" cellpadding="2">
        <tbody>
            <tr>
                <td width="180"><?php echo __('Amount'); ?> (<?php echo Format::htmlchars($currency); ?>):</td>
                <td>
                    <input type="number" id="sq-amount" name="amount" step="0.01" min="0.01"
                           placeholder="0.00" required
                           style="width: 150px; padding: 4px;" />
                </td>
            </tr>
            <tr>
                <td><?php echo __('Note'); ?>:</td>
                <td>
                    <input type="text" id="sq-note" name="note" maxlength="200"
                           placeholder="<?php echo __('Payment description'); ?>"
                           style="width: 350px; padding: 4px;" />
                </td>
            </tr>
            <tr>
                <td valign="top"><?php echo __('Card Details'); ?>:</td>
                <td>
                    <div id="card-container" style="min-height: 90px;"></div>
                </td>
            </tr>
        </tbody>
    </table>

    <div id="sq-payment-status" style="display: none; padding: 8px; margin: 10px 0; border-radius: 3px;"></div>

    <hr />
    <p class="full-width">
        <span class="buttons pull-right">
            <input type="button" id="sq-pay-button" disabled
                   value="<?php echo __('Process Payment'); ?>"
                   class="action-button" />
        </span>
    </p>
</form>
</div>

<div id="sq-tab-history" class="tab_content" style="display:none; padding: 10px 0;">
<?php if ($payments) { ?>
    <table class="list" width="100%" border="0" cellspacing="1" cellpadding="2">
        <thead>
            <tr>
                <th><?php echo __('Date'); ?></th>
                <th><?php echo __('Amount'); ?></th>
                <th><?php echo __('Card'); ?></th>
                <th><?php echo __('Status'); ?></th>
                <th><?php echo __('Square ID'); ?></th>
                <th><?php echo __('Actions'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $p) { ?>
            <tr>
                <td><?php echo Format::datetime($p['created']); ?></td>
                <td><?php echo Format::htmlchars($p['currency'] . ' ' . $p['amount_formatted']); ?></td>
                <td><?php echo Format::htmlchars(($p['card_brand'] ?: 'Card') . ' ...' . ($p['last_four'] ?: '****')); ?></td>
                <td>
                    <?php if ($p['status'] === 'REFUNDED') { ?>
                        <span class="label label-danger"><?php echo __('Refunded'); ?></span>
                    <?php } else { ?>
                        <span class="label label-success"><?php echo __('Completed'); ?></span>
                    <?php } ?>
                </td>
                <td><small><?php echo Format::htmlchars(substr($p['square_payment_id'], 0, 20)); ?></small></td>
                <td>
                    <?php if ($p['status'] !== 'REFUNDED') { ?>
                        <a href="#" class="sq-refund-btn" data-payment-id="<?php echo (int) $p['id']; ?>"
                           data-amount="<?php echo Format::htmlchars($p['amount_formatted']); ?>"
                           data-currency="<?php echo Format::htmlchars($p['currency']); ?>"
                           ><?php echo __('Refund'); ?></a>
                    <?php } ?>
                    <?php if ($p['receipt_url']) { ?>
                        <a href="<?php echo Format::htmlchars($p['receipt_url']); ?>" target="_blank"><?php echo __('Receipt'); ?></a>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } else { ?>
    <p><em><?php echo __('No payments have been recorded for this ticket.'); ?></em></p>
<?php } ?>
</div>

<script src="<?php echo $sdkUrl; ?>"></script>
<script type="text/javascript">
(function() {
    var appId = <?php echo json_encode($appId); ?>;
    var locationId = <?php echo json_encode($locationId); ?>;
    var ticketId = <?php echo (int) $ticket->getId(); ?>;
    var card;

    // Tab switching
    $('#square-tabs a').on('click', function(e) {
        e.preventDefault();
        $('#square-tabs li').removeClass('active');
        $(this).parent().addClass('active');
        $('.tab_content').hide();
        $($(this).attr('href')).show();
    });

    async function initSquare() {
        var payments = Square.payments(appId, locationId);
        card = await payments.card();
        await card.attach('#card-container');
        document.getElementById('sq-pay-button').disabled = false;
    }

    function showStatus(msg, isError) {
        var el = document.getElementById('sq-payment-status');
        el.textContent = msg;
        el.style.display = 'block';
        el.style.background = isError ? '#fdd' : '#dfd';
        el.style.color = isError ? '#900' : '#060';
    }

    function getCSRFToken() {
        return $('[name="__CSRFToken__"]').val()
            || $('meta[name="csrf_token"]').attr('content')
            || '';
    }

    document.getElementById('sq-pay-button').addEventListener('click', async function() {
        var btn = this;
        var amount = document.getElementById('sq-amount').value;
        var note = document.getElementById('sq-note').value;

        if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
            showStatus('<?php echo __('Please enter a valid amount.'); ?>', true);
            return;
        }

        btn.disabled = true;
        btn.value = '<?php echo __('Processing...'); ?>';

        try {
            var result = await card.tokenize();
            if (result.status !== 'OK') {
                showStatus(result.errors ? result.errors[0].message : '<?php echo __('Card tokenization failed.'); ?>', true);
                btn.disabled = false;
                btn.value = '<?php echo __('Process Payment'); ?>';
                return;
            }

            $.ajax({
                url: 'ajax.php/square/process/' + ticketId,
                type: 'POST',
                data: {
                    source_id: result.token,
                    amount: amount,
                    note: note,
                    __CSRFToken__: getCSRFToken()
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showStatus(data.message, false);
                        btn.value = '<?php echo __('Payment Complete'); ?>';
                        if (data.receipt_url) {
                            var link = $('<a>').attr('href', data.receipt_url)
                                .attr('target', '_blank')
                                .text('<?php echo __('View Receipt'); ?>')
                                .css('margin-left', '10px');
                            $('#sq-payment-status').append(link);
                        }
                        setTimeout(function() {
                            $.pjax({url: window.location.href, container: '#pjax-container'});
                        }, 2000);
                    } else {
                        showStatus(data.error || '<?php echo __('Payment failed.'); ?>', true);
                        btn.disabled = false;
                        btn.value = '<?php echo __('Process Payment'); ?>';
                    }
                },
                error: function() {
                    showStatus('<?php echo __('An error occurred. Please try again.'); ?>', true);
                    btn.disabled = false;
                    btn.value = '<?php echo __('Process Payment'); ?>';
                }
            });
        } catch (e) {
            showStatus('<?php echo __('An error occurred. Please try again.'); ?>', true);
            btn.disabled = false;
            btn.value = '<?php echo __('Process Payment'); ?>';
        }
    });

    // Refund handling
    $(document).on('click', '.sq-refund-btn', function(e) {
        e.preventDefault();
        var paymentId = $(this).data('payment-id');
        var amount = $(this).data('amount');
        var currency = $(this).data('currency');

        if (!confirm('<?php echo __('Are you sure you want to refund'); ?> ' + currency + ' ' + amount + '?'))
            return;

        var reason = prompt('<?php echo __('Reason for refund (optional):'); ?>');
        if (reason === null)
            return;

        $.ajax({
            url: 'ajax.php/square/refund/' + paymentId,
            type: 'POST',
            data: {
                reason: reason,
                __CSRFToken__: getCSRFToken()
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    alert(data.message);
                    $.pjax({url: window.location.href, container: '#pjax-container'});
                } else {
                    alert(data.error || '<?php echo __('Refund failed.'); ?>');
                }
            },
            error: function() {
                alert('<?php echo __('An error occurred processing the refund.'); ?>');
            }
        });
    });

    initSquare().catch(function(e) {
        showStatus('<?php echo __('Failed to load payment form. Please refresh the page.'); ?>', true);
    });
})();
</script>
