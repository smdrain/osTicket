<?php

/**
 * Square API client wrapper.
 *
 * Handles communication with the Square Payments API using cURL.
 */
class SquareApi {

    private $accessToken;
    private $locationId;
    private $sandbox;
    private $baseUrl;

    function __construct($accessToken, $locationId, $sandbox = true) {
        $this->accessToken = $accessToken;
        $this->locationId = $locationId;
        $this->sandbox = $sandbox;
        $this->baseUrl = $sandbox
            ? 'https://connect.squareupsandbox.com/v2'
            : 'https://connect.squareup.com/v2';
    }

    /**
     * Create a payment using a payment source (nonce from Web Payments SDK).
     */
    function createPayment($sourceId, $amountCents, $currency, $options = array()) {
        $idempotencyKey = $options['idempotency_key']
            ?? bin2hex(random_bytes(16));

        $body = array(
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'amount_money' => array(
                'amount' => (int) $amountCents,
                'currency' => $currency,
            ),
            'location_id' => $this->locationId,
        );

        if (!empty($options['reference_id']))
            $body['reference_id'] = $options['reference_id'];

        if (!empty($options['note']))
            $body['note'] = substr($options['note'], 0, 500);

        if (!empty($options['buyer_email']))
            $body['buyer_email_address'] = $options['buyer_email'];

        if (!empty($options['autocomplete']))
            $body['autocomplete'] = (bool) $options['autocomplete'];
        else
            $body['autocomplete'] = true;

        return $this->request('POST', '/payments', $body);
    }

    /**
     * Retrieve a payment by its Square payment ID.
     */
    function getPayment($paymentId) {
        return $this->request('GET', '/payments/' . urlencode($paymentId));
    }

    /**
     * Refund a payment.
     */
    function refundPayment($paymentId, $amountCents, $currency, $reason = '') {
        $body = array(
            'idempotency_key' => bin2hex(random_bytes(16)),
            'payment_id' => $paymentId,
            'amount_money' => array(
                'amount' => (int) $amountCents,
                'currency' => $currency,
            ),
        );

        if ($reason)
            $body['reason'] = substr($reason, 0, 192);

        return $this->request('POST', '/refunds', $body);
    }

    /**
     * Make an HTTP request to the Square API.
     */
    private function request($method, $endpoint, $body = null) {
        $url = $this->baseUrl . $endpoint;

        $headers = array(
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'Square-Version: 2024-01-18',
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body)
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error)
            return array('success' => false, 'error' => 'Connection error: ' . $error);

        $data = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && !isset($data['errors'])) {
            return array('success' => true, 'data' => $data);
        }

        $errorMsg = 'Payment failed';
        if (isset($data['errors']) && is_array($data['errors'])) {
            $msgs = array();
            foreach ($data['errors'] as $err)
                $msgs[] = $err['detail'] ?? $err['code'] ?? 'Unknown error';
            $errorMsg = implode('; ', $msgs);
        }

        return array('success' => false, 'error' => $errorMsg, 'data' => $data);
    }

    function getLocationId() {
        return $this->locationId;
    }

    function isSandbox() {
        return $this->sandbox;
    }
}
