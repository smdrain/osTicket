<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/class.square-api.php';

class SquarePaymentPlugin extends Plugin {

    var $config_class = 'SquarePaymentConfig';

    function bootstrap() {
        $config = $this->getConfig();
        if (!$config)
            return;

        // Register AJAX routes for both client and staff
        Signal::connect('ajax.client', array($this, 'registerClientAjax'));
        Signal::connect('ajax.scp', array($this, 'registerStaffAjax'));

        // Inject payment UI into ticket views
        if ($config->get('allow-client-pay'))
            Signal::connect('object.view', array($this, 'onTicketView'));
    }

    /**
     * Register AJAX routes for client-facing payment operations.
     */
    function registerClientAjax($dispatcher) {
        $dispatcher->append(
            url('^/square/', patterns('plugins/square-payments/lib/ajax.square.php:SquarePaymentAjaxAPI',
                url_get('^payment-form/(?P<ticket_id>\d+)$', 'getClientPaymentForm'),
                url_post('^process/(?P<ticket_id>\d+)$', 'processClientPayment'),
                url_get('^history/(?P<ticket_id>\d+)$', 'getPaymentHistory')
            ))
        );
    }

    /**
     * Register AJAX routes for staff-facing payment operations.
     */
    function registerStaffAjax($dispatcher) {
        $dispatcher->append(
            url('^/square/', patterns('plugins/square-payments/lib/ajax.square.php:SquarePaymentAjaxAPI',
                url_get('^payment-form/(?P<ticket_id>\d+)$', 'getStaffPaymentForm'),
                url_post('^process/(?P<ticket_id>\d+)$', 'processStaffPayment'),
                url_post('^refund/(?P<payment_id>\d+)$', 'processRefund'),
                url_get('^history/(?P<ticket_id>\d+)$', 'getPaymentHistory')
            ))
        );
    }

    /**
     * Handle the ticket view signal to inject payment data.
     */
    function onTicketView($ticket, $data) {
        // This signal is used as a hook point; actual UI injection happens
        // via the template includes checking for this plugin.
    }

    /**
     * Create a configured Square API client instance.
     */
    static function getSquareApi($config) {
        return new SquareApi(
            $config->get('access-token'),
            $config->get('location-id'),
            $config->get('sandbox-mode')
        );
    }

    /**
     * Get the active plugin configuration, if available.
     */
    static function getActiveConfig() {
        foreach (PluginManager::allActive() as $p) {
            if ($p instanceof self)
                return $p->getConfig();
        }
        return null;
    }

    function enable() {
        // Create the payments table when the plugin is enabled
        return static::ensurePaymentsTable();
    }

    /**
     * Create the payments table if it doesn't exist.
     */
    static function ensurePaymentsTable() {
        $prefix = TABLE_PREFIX ?? 'ost_';
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}square_payment` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `ticket_id` int(11) unsigned NOT NULL,
            `square_payment_id` varchar(255) NOT NULL DEFAULT '',
            `amount` int(11) NOT NULL DEFAULT 0,
            `currency` varchar(3) NOT NULL DEFAULT 'USD',
            `status` varchar(32) NOT NULL DEFAULT 'COMPLETED',
            `source_type` varchar(32) NOT NULL DEFAULT 'CARD',
            `last_four` varchar(4) DEFAULT NULL,
            `card_brand` varchar(32) DEFAULT NULL,
            `receipt_url` varchar(512) DEFAULT NULL,
            `note` text,
            `staff_id` int(11) unsigned DEFAULT NULL,
            `user_id` int(11) unsigned DEFAULT NULL,
            `refund_id` varchar(255) DEFAULT NULL,
            `created` datetime NOT NULL,
            `updated` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `ticket_id` (`ticket_id`),
            KEY `square_payment_id` (`square_payment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

        return db_query($sql);
    }

    function pre_uninstall(&$errors) {
        // Optionally drop the table on uninstall
        // For safety we keep the data
        return true;
    }
}
