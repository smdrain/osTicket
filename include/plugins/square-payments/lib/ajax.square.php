<?php

if (!defined('INCLUDE_DIR')) die('403');

require_once INCLUDE_DIR . 'class.ajax.php';
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'plugins/square-payments/square.php';
require_once INCLUDE_DIR . 'plugins/square-payments/lib/class.square-api.php';

class SquarePaymentAjaxAPI extends AjaxController {

    /**
     * Retrieve the active Square plugin configuration.
     */
    private function getPluginConfig() {
        $config = SquarePaymentPlugin::getActiveConfig();
        if (!$config)
            return $this->exerr(500, __('Square Payments plugin is not configured.'));
        return $config;
    }

    /**
     * Display payment form for customer portal.
     */
    function getClientPaymentForm($ticket_id) {
        global $thisclient;

        if (!$thisclient || !$thisclient->isValid())
            return $this->exerr(403, __('Access Denied'));

        $ticket = Ticket::lookup($ticket_id);
        if (!$ticket || !$ticket->checkUserAccess($thisclient))
            return $this->exerr(404, __('Invalid ticket'));

        $config = $this->getPluginConfig();
        if (!$config->get('allow-client-pay'))
            return $this->exerr(403, __('Client payments are not enabled'));

        $appId = $config->get('application-id');
        $locationId = $config->get('location-id');
        $sandbox = $config->get('sandbox-mode');
        $currency = $config->get('currency') ?: 'USD';

        $payments = self::getPaymentsForTicket($ticket_id);

        ob_start();
        include INCLUDE_DIR . 'plugins/square-payments/templates/client-payment-form.php';
        $html = ob_get_clean();

        Http::response(200, $html);
    }

    /**
     * Process a payment from the customer portal.
     */
    function processClientPayment($ticket_id) {
        global $thisclient;

        if (!$thisclient || !$thisclient->isValid())
            return $this->exerr(403, __('Access Denied'));

        $ticket = Ticket::lookup($ticket_id);
        if (!$ticket || !$ticket->checkUserAccess($thisclient))
            return $this->exerr(404, __('Invalid ticket'));

        $config = $this->getPluginConfig();
        if (!$config->get('allow-client-pay'))
            return $this->exerr(403, __('Client payments are not enabled'));

        return $this->processPayment($ticket, $config, 'client');
    }

    /**
     * Display payment form for staff panel.
     */
    function getStaffPaymentForm($ticket_id) {
        global $thisstaff;

        if (!$thisstaff || !$thisstaff->isValid())
            return $this->exerr(403, __('Access Denied'));

        $ticket = Ticket::lookup($ticket_id);
        if (!$ticket || !$ticket->checkStaffPerm($thisstaff))
            return $this->exerr(404, __('Invalid ticket'));

        $config = $this->getPluginConfig();
        if (!$config->get('allow-staff-pay'))
            return $this->exerr(403, __('Staff payments are not enabled'));

        $appId = $config->get('application-id');
        $locationId = $config->get('location-id');
        $sandbox = $config->get('sandbox-mode');
        $currency = $config->get('currency') ?: 'USD';

        $payments = self::getPaymentsForTicket($ticket_id);

        ob_start();
        include INCLUDE_DIR . 'plugins/square-payments/templates/staff-payment-form.php';
        $html = ob_get_clean();

        Http::response(200, $html);
    }

    /**
     * Process a payment from the staff panel.
     */
    function processStaffPayment($ticket_id) {
        global $thisstaff;

        if (!$thisstaff || !$thisstaff->isValid())
            return $this->exerr(403, __('Access Denied'));

        $ticket = Ticket::lookup($ticket_id);
        if (!$ticket || !$ticket->checkStaffPerm($thisstaff))
            return $this->exerr(404, __('Invalid ticket'));

        $config = $this->getPluginConfig();
        if (!$config->get('allow-staff-pay'))
            return $this->exerr(403, __('Staff payments are not enabled'));

        return $this->processPayment($ticket, $config, 'staff');
    }

    /**
     * Core payment processing logic shared by client and staff flows.
     */
    private function processPayment($ticket, $config, $source) {
        global $thisstaff, $thisclient;

        $sourceId = $_POST['source_id'] ?? '';
        $amount = $_POST['amount'] ?? '';
        $note = $_POST['note'] ?? '';

        if (!$sourceId)
            return Http::response(400,
                $this->json_encode(array('error' => __('Payment token is required.'))),
                'application/json');

        if (!$amount || !is_numeric($amount) || $amount <= 0)
            return Http::response(400,
                $this->json_encode(array('error' => __('A valid amount is required.'))),
                'application/json');

        $currency = $config->get('currency') ?: 'USD';
        // Convert dollars to cents (smallest currency unit)
        $amountCents = (int) round($amount * 100);

        $api = SquarePaymentPlugin::getSquareApi($config);

        $options = array(
            'reference_id' => 'ticket-' . $ticket->getNumber(),
            'note' => sprintf('Ticket #%s - %s',
                $ticket->getNumber(),
                $note ?: $ticket->getSubject()),
            'autocomplete' => true,
        );

        if ($source === 'client' && $thisclient)
            $options['buyer_email'] = $thisclient->getEmail();

        $result = $api->createPayment($sourceId, $amountCents, $currency, $options);

        if (!$result['success']) {
            return Http::response(400,
                $this->json_encode(array('error' => $result['error'])),
                'application/json');
        }

        $payment = $result['data']['payment'] ?? array();
        $this->savePaymentRecord($ticket, $payment, $note, $source);

        // Post an internal note on the ticket about the payment
        $amountFormatted = number_format($amount, 2);
        $lastFour = $payment['card_details']['card']['last_4'] ?? '****';
        $cardBrand = $payment['card_details']['card']['card_brand'] ?? 'Card';

        $noteBody = sprintf(
            "Payment of %s %s processed successfully via Square.\n"
            . "Card: %s ending in %s\n"
            . "Square Payment ID: %s",
            $currency,
            $amountFormatted,
            $cardBrand,
            $lastFour,
            $payment['id'] ?? 'N/A'
        );

        if ($note)
            $noteBody .= "\nNote: " . $note;

        $poster = ($source === 'staff' && $thisstaff)
            ? $thisstaff->getName()->getOriginal()
            : 'System';

        $ticket->getThread()->addNote(array(
            'title' => sprintf('Payment: %s %s', $currency, $amountFormatted),
            'body' => $noteBody,
            'poster' => $poster,
        ));

        return Http::response(200,
            $this->json_encode(array(
                'success' => true,
                'message' => sprintf(__('Payment of %s %s processed successfully.'),
                    $currency, $amountFormatted),
                'receipt_url' => $payment['receipt_url'] ?? null,
            )),
            'application/json');
    }

    /**
     * Process a refund (staff only).
     */
    function processRefund($payment_id) {
        global $thisstaff;

        if (!$thisstaff || !$thisstaff->isValid())
            return $this->exerr(403, __('Access Denied'));

        $config = $this->getPluginConfig();

        $prefix = TABLE_PREFIX ?? 'ost_';
        $sql = sprintf(
            "SELECT * FROM `%ssquare_payment` WHERE `id` = %d",
            $prefix, (int) $payment_id
        );
        $res = db_query($sql);
        if (!$res || !($row = db_fetch_array($res)))
            return $this->exerr(404, __('Payment not found'));

        $ticket = Ticket::lookup($row['ticket_id']);
        if (!$ticket || !$ticket->checkStaffPerm($thisstaff))
            return $this->exerr(403, __('Access Denied'));

        $reason = $_POST['reason'] ?? '';
        $refundAmount = $_POST['amount'] ?? '';

        $amountCents = $refundAmount
            ? (int) round((float) $refundAmount * 100)
            : (int) $row['amount'];

        $api = SquarePaymentPlugin::getSquareApi($config);
        $result = $api->refundPayment(
            $row['square_payment_id'],
            $amountCents,
            $row['currency'],
            $reason
        );

        if (!$result['success'])
            return Http::response(400,
                $this->json_encode(array('error' => $result['error'])),
                'application/json');

        $refund = $result['data']['refund'] ?? array();

        // Update payment record
        $refundId = $refund['id'] ?? '';
        $updateSql = sprintf(
            "UPDATE `%ssquare_payment` SET `status` = 'REFUNDED', `refund_id` = %s, "
            . "`updated` = NOW() WHERE `id` = %d",
            $prefix,
            db_input($refundId),
            (int) $payment_id
        );
        db_query($updateSql);

        $amountFormatted = number_format($amountCents / 100, 2);
        $ticket->getThread()->addNote(array(
            'title' => sprintf('Refund: %s %s', $row['currency'], $amountFormatted),
            'body' => sprintf(
                "Refund of %s %s processed via Square.\nSquare Refund ID: %s%s",
                $row['currency'],
                $amountFormatted,
                $refundId,
                $reason ? "\nReason: $reason" : ''
            ),
            'poster' => $thisstaff->getName()->getOriginal(),
        ));

        return Http::response(200,
            $this->json_encode(array(
                'success' => true,
                'message' => sprintf(__('Refund of %s %s processed successfully.'),
                    $row['currency'], $amountFormatted),
            )),
            'application/json');
    }

    /**
     * Get payment history for a ticket.
     */
    function getPaymentHistory($ticket_id) {
        global $thisstaff, $thisclient;

        $ticket = Ticket::lookup($ticket_id);
        if (!$ticket)
            return $this->exerr(404, __('Invalid ticket'));

        // Check access
        if ($thisstaff && $thisstaff->isValid()) {
            if (!$ticket->checkStaffPerm($thisstaff))
                return $this->exerr(403, __('Access Denied'));
        } elseif ($thisclient && $thisclient->isValid()) {
            if (!$ticket->checkUserAccess($thisclient))
                return $this->exerr(403, __('Access Denied'));
        } else {
            return $this->exerr(403, __('Access Denied'));
        }

        $payments = self::getPaymentsForTicket($ticket_id);

        return Http::response(200,
            $this->json_encode(array('payments' => $payments)),
            'application/json');
    }

    /**
     * Save a payment record to the database.
     */
    private function savePaymentRecord($ticket, $payment, $note, $source) {
        global $thisstaff, $thisclient;

        $prefix = TABLE_PREFIX ?? 'ost_';
        $cardDetails = $payment['card_details']['card'] ?? array();

        $sql = sprintf(
            "INSERT INTO `%ssquare_payment` "
            . "(`ticket_id`, `square_payment_id`, `amount`, `currency`, `status`, "
            . "`source_type`, `last_four`, `card_brand`, `receipt_url`, `note`, "
            . "`staff_id`, `user_id`, `created`) "
            . "VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())",
            $prefix,
            $ticket->getId(),
            db_input($payment['id'] ?? ''),
            (int) ($payment['amount_money']['amount'] ?? 0),
            db_input($payment['amount_money']['currency'] ?? 'USD'),
            db_input($payment['status'] ?? 'COMPLETED'),
            db_input($payment['source_type'] ?? 'CARD'),
            db_input($cardDetails['last_4'] ?? ''),
            db_input($cardDetails['card_brand'] ?? ''),
            db_input($payment['receipt_url'] ?? ''),
            db_input($note ?: ''),
            ($source === 'staff' && $thisstaff) ? (int) $thisstaff->getId() : 'NULL',
            ($source === 'client' && $thisclient) ? (int) $thisclient->getId() : 'NULL'
        );

        db_query($sql);
    }

    /**
     * Retrieve all payment records for a ticket.
     */
    static function getPaymentsForTicket($ticket_id) {
        $prefix = TABLE_PREFIX ?? 'ost_';
        $sql = sprintf(
            "SELECT * FROM `%ssquare_payment` WHERE `ticket_id` = %d ORDER BY `created` DESC",
            $prefix, (int) $ticket_id
        );

        $payments = array();
        if (($res = db_query($sql))) {
            while ($row = db_fetch_array($res)) {
                $row['amount_formatted'] = number_format($row['amount'] / 100, 2);
                $payments[] = $row;
            }
        }
        return $payments;
    }
}
