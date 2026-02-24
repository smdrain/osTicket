<?php
// Client-facing payment form template
// Variables: $ticket, $appId, $locationId, $sandbox, $currency, $payments
if (!defined('INCLUDE_DIR')) die('Access Denied');
$sdkUrl = $sandbox
    ? 'https://sandbox.web.squarecdn.com/v1/square.js'
    : 'https://web.squarecdn.com/v1/square.js';
?>
<div id="square-payment-dialog">
<h3><?php echo __('Make a Payment'); ?></h3>
<p class="faded"><?php echo sprintf(__('Ticket #%s'), $ticket->getNumber()); ?></p>

<?php if ($payments) { ?>
<div class="square-payment-history" style="margin-bottom: 15px;">
    <h4><?php echo __('Payment History'); ?></h4>
    <table class="list" width="100%" border="0" cellspacing="1" cellpadding="2">
        <thead>
            <tr>
                <th><?php echo __('Date'); ?></th>
                <th><?php echo __('Amount'); ?></th>
                <th><?php echo __('Card'); ?></th>
                <th><?php echo __('Status'); ?></th>
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
                        <span style="color: #c00;"><?php echo __('Refunded'); ?></span>
                    <?php } else { ?>
                        <span style="color: #080;"><?php echo __('Completed'); ?></span>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php } ?>

<form id="square-payment-form" style="margin-top: 10px;">
    <div style="margin-bottom: 10px;">
        <label for="sq-amount"><strong><?php echo __('Amount'); ?> (<?php echo Format::htmlchars($currency); ?>):</strong></label>
        <br/>
        <input type="number" id="sq-amount" name="amount" step="0.01" min="0.01"
               placeholder="0.00" required
               style="width: 150px; padding: 6px; font-size: 14px; margin-top: 4px;" />
    </div>

    <div style="margin-bottom: 10px;">
        <label for="sq-note"><strong><?php echo __('Note (optional)'); ?>:</strong></label>
        <br/>
        <input type="text" id="sq-note" name="note" maxlength="200"
               placeholder="<?php echo __('Payment description'); ?>"
               style="width: 100%; padding: 6px; font-size: 14px; margin-top: 4px; box-sizing: border-box;" />
    </div>

    <div style="margin-bottom: 15px;">
        <label><strong><?php echo __('Card Details'); ?>:</strong></label>
        <div id="card-container" style="min-height: 90px; margin-top: 4px;"></div>
    </div>

    <div id="sq-payment-status" style="display: none; padding: 8px; margin-bottom: 10px; border-radius: 3px;"></div>

    <div style="text-align: center;">
        <button type="button" id="sq-pay-button" disabled
                style="padding: 10px 30px; font-size: 14px; background: #006fbe; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
            <?php echo __('Pay Now'); ?>
        </button>
    </div>
</form>
</div>

<script src="<?php echo $sdkUrl; ?>"></script>
<script type="text/javascript">
(function() {
    var appId = <?php echo json_encode($appId); ?>;
    var locationId = <?php echo json_encode($locationId); ?>;
    var ticketId = <?php echo (int) $ticket->getId(); ?>;
    var card;

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

    document.getElementById('sq-pay-button').addEventListener('click', async function() {
        var btn = this;
        var amount = document.getElementById('sq-amount').value;
        var note = document.getElementById('sq-note').value;

        if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
            showStatus('<?php echo __('Please enter a valid amount.'); ?>', true);
            return;
        }

        btn.disabled = true;
        btn.textContent = '<?php echo __('Processing...'); ?>';

        try {
            var result = await card.tokenize();
            if (result.status !== 'OK') {
                showStatus(result.errors ? result.errors[0].message : '<?php echo __('Card tokenization failed.'); ?>', true);
                btn.disabled = false;
                btn.textContent = '<?php echo __('Pay Now'); ?>';
                return;
            }

            var formData = new FormData();
            formData.append('source_id', result.token);
            formData.append('amount', amount);
            formData.append('note', note);
            formData.append('__CSRFToken__', $('meta[name="csrf_token"]').attr('content') || $('[name="__CSRFToken__"]').val() || '');

            var resp = await fetch('ajax.php/square/process/' + ticketId, {
                method: 'POST',
                body: formData,
            });

            var data = await resp.json();
            if (data.success) {
                showStatus(data.message, false);
                btn.textContent = '<?php echo __('Payment Complete'); ?>';
                if (data.receipt_url) {
                    var link = document.createElement('a');
                    link.href = data.receipt_url;
                    link.target = '_blank';
                    link.textContent = '<?php echo __('View Receipt'); ?>';
                    link.style.marginLeft = '10px';
                    document.getElementById('sq-payment-status').appendChild(link);
                }
                setTimeout(function() { location.reload(); }, 3000);
            } else {
                showStatus(data.error || '<?php echo __('Payment failed.'); ?>', true);
                btn.disabled = false;
                btn.textContent = '<?php echo __('Pay Now'); ?>';
            }
        } catch (e) {
            showStatus('<?php echo __('An error occurred. Please try again.'); ?>', true);
            btn.disabled = false;
            btn.textContent = '<?php echo __('Pay Now'); ?>';
        }
    });

    initSquare().catch(function(e) {
        showStatus('<?php echo __('Failed to load payment form. Please refresh the page.'); ?>', true);
    });
})();
</script>
