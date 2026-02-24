<?php

require_once INCLUDE_DIR . '/class.plugin.php';
require_once INCLUDE_DIR . '/class.forms.php';

class SquarePaymentConfig extends PluginConfig {

    function getOptions() {
        return array(
            'square-payments' => new SectionBreakField(array(
                'label' => 'Square API Settings',
                'hint' => 'Configure your Square application credentials. '
                    . 'You can find these in the Square Developer Dashboard.',
            )),
            'sandbox-mode' => new BooleanField(array(
                'label' => 'Sandbox Mode',
                'default' => true,
                'hint' => 'Enable sandbox mode for testing. Disable for production payments.',
                'configuration' => array(
                    'desc' => 'Use Square Sandbox environment',
                ),
            )),
            'application-id' => new TextboxField(array(
                'label' => 'Application ID',
                'required' => true,
                'configuration' => array(
                    'size' => 60,
                    'length' => 100,
                ),
                'hint' => 'Your Square Application ID (starts with sandbox- for sandbox mode).',
            )),
            'access-token' => new TextboxField(array(
                'label' => 'Access Token',
                'required' => true,
                'configuration' => array(
                    'size' => 60,
                    'length' => 200,
                ),
                'hint' => 'Your Square Access Token.',
            )),
            'location-id' => new TextboxField(array(
                'label' => 'Location ID',
                'required' => true,
                'configuration' => array(
                    'size' => 60,
                    'length' => 100,
                ),
                'hint' => 'Your Square Location ID.',
            )),
            'payment-settings' => new SectionBreakField(array(
                'label' => 'Payment Settings',
            )),
            'currency' => new ChoiceField(array(
                'label' => 'Currency',
                'default' => 'USD',
                'choices' => array(
                    'USD' => 'USD - US Dollar',
                    'CAD' => 'CAD - Canadian Dollar',
                    'GBP' => 'GBP - British Pound',
                    'EUR' => 'EUR - Euro',
                    'AUD' => 'AUD - Australian Dollar',
                    'JPY' => 'JPY - Japanese Yen',
                ),
                'hint' => 'Currency for payment processing.',
            )),
            'allow-client-pay' => new BooleanField(array(
                'label' => 'Allow Client Payments',
                'default' => true,
                'hint' => 'Allow customers to make payments from the client portal.',
                'configuration' => array(
                    'desc' => 'Enable payments from client portal',
                ),
            )),
            'allow-staff-pay' => new BooleanField(array(
                'label' => 'Allow Staff Payments',
                'default' => true,
                'hint' => 'Allow staff/technicians to process payments from tickets.',
                'configuration' => array(
                    'desc' => 'Enable payments from staff panel',
                ),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        if (!$config['application-id'])
            $errors['err'] = 'Application ID is required.';
        elseif (!$config['access-token'])
            $errors['err'] = 'Access Token is required.';
        elseif (!$config['location-id'])
            $errors['err'] = 'Location ID is required.';

        return count($errors) === 0;
    }
}
