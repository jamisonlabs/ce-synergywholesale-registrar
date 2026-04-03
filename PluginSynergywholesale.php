<?php
/**
 * ClientExec — Synergy Wholesale Registrar Plugin (Extended)
 *
 * A fork of the official Clientexec Synergy Wholesale registrar plugin,
 * extended to support the full Synergy Wholesale API v3.x.
 *
 * @version   1.0.0
 * @author    Jamison Labs <hello@jamisonlabs.com>
 * @copyright 2026 Jamison Labs
 * @license   MIT
 * @link      https://github.com/jamisonlabs/ce-synergywholesale-registrar
 *
 * Based on the original plugin by Clientexec Inc.
 * https://github.com/clientexec/synergywholesale-registrar
 */

require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginSynergywholesale extends RegistrarPlugin
{
    public $features = [
        'nameSuggest'   => true,
        'importDomains' => true,
        'importPrices'  => true,
    ];

    private $dnsTypes = ['A', 'AAAA', 'MX', 'CNAME', 'TXT', 'SRV'];

    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type'        => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value'       => lang('Synergy Wholesale'),
            ],
            lang('Reseller ID') => [
                'type'        => 'text',
                'description' => lang('Enter your Reseller ID.'),
                'value'       => '',
            ],
            lang('API Key') => [
                'type'        => 'password',
                'description' => lang('Enter your API Key.'),
                'value'       => '',
            ],
            lang('Auto Pricing Sync') => [
                'type'        => 'yesno',
                'description' => lang('Automatically sync TLD pricing from Synergy Wholesale on a schedule. Requires the cron script to be configured on the server.'),
                'value'       => '0',
            ],
            lang('Pricing Sync Interval') => [
                'type'        => 'text',
                'description' => lang('How often to sync pricing (number). e.g. 1'),
                'value'       => '1',
            ],
            lang('Pricing Sync Period') => [
                'type'        => 'dropdown',
                'multiple'    => false,
                'getValues'   => 'getPricingSyncPeriodValues',
                'description' => lang('Period unit for auto pricing sync.'),
                'value'       => 'month',
            ],
            lang('Pricing Margin') => [
                'type'        => 'text',
                'description' => lang('Percentage markup to apply over Synergy Wholesale cost prices during auto sync. Default: 10.'),
                'value'       => '10',
            ],

            lang('Actions') => [
                'type'        => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                'value'       => 'Register',
            ],
            lang('Registered Actions') => [
                'type'        => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value'       => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),RestoreDomain (Restore Domain),CancelTransfer (Cancel Transfer),Cancel',
            ],
            lang('Registered Actions For Customer') => [
                'type'        => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value'       => '',
            ],
        ];

        return $variables;
    }

    // =========================================================================
    // PRICING SYNC
    // =========================================================================

    /**
     * Returns period options for the Pricing Sync Period dropdown.
     */
    public function getPricingSyncPeriodValues()
    {
        return [
            'day'   => lang('Day(s)'),
            'week'  => lang('Week(s)'),
            'month' => lang('Month(s)'),
            'year'  => lang('Year(s)'),
        ];
    }

    /**
     * Import TLD pricing from Synergy Wholesale.
     * CE calls this when "Import TLD Prices" is triggered.
     * Returns an indexed array of TLD pricing entries.
     */
    public function getTLDsAndPrices($params)
    {
        $response = $this->makeRequest('getDomainPricing');

        if ($response->status != 'OK') {
            throw new CE_Exception('Synergy Wholesale: ' . $response->errorMessage);
        }

        $tlds = [];

        // SW SOAP API may return the list under 'pricing' or 'domainPricingList'
        $pricingList = null;
        if (!empty($response->pricing)) {
            $pricingList = $response->pricing;
        } elseif (!empty($response->domainPricingList)) {
            $pricingList = $response->domainPricingList;
        }

        if (empty($pricingList)) {
            return $tlds;
        }

        // CE expects: $tlds[$tld]['pricing']['register'|'transfer'|'renew'] = float
        // The TLD key has no leading dot; CE stores the base 1-year price.
        foreach ($pricingList as $item) {
            $tld = ltrim((string) $item->tld, '.');
            if (empty($tld)) {
                continue;
            }

            $minPeriod   = isset($item->minPeriod) ? (int) $item->minPeriod : 1;
            $regField    = 'register_' . $minPeriod . '_year';
            $registerPrice = !empty($item->$regField) ? (float) $item->$regField : 0;

            if ($registerPrice <= 0) {
                continue;
            }

            $tlds[$tld]['pricing']['register'] = $registerPrice;
            $tlds[$tld]['pricing']['renew']    = !empty($item->renew)    ? (float) $item->renew    : $registerPrice;
            $tlds[$tld]['pricing']['transfer'] = !empty($item->transfer) ? (float) $item->transfer : $registerPrice;
        }

        return $tlds;
    }

    // =========================================================================
    // DOMAIN AVAILABILITY
    // =========================================================================

    public function checkDomain($params)
    {
        $tld = $params['tld'];
        $sld = $params['sld'];

        $response = $this->makeRequest('checkDomain', ['domainName' => $sld . '.' . $tld]);

        if ($response->status == 'AVAILABLE') {
            $status = 0;
        } elseif ($response->status == 'UNAVAILABLE') {
            $status = 1;
        } else {
            CE_Lib::log(4, $response->errorMessage ?? '');
            $status = 5;
        }

        return ['result' => [['tld' => $tld, 'domain' => $sld, 'status' => $status]]];
    }

    // =========================================================================
    // REGISTRATION
    // =========================================================================

    public function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField(
            'Registrar Order Id',
            $userPackage->getCustomField('Registrar') . '-' . $params['userPackageId']
        );
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }

    public function registerDomain($params)
    {
        $domainName = $params['sld'] . '.' . $params['tld'];

        $arguments = [
            'domainName'              => $domainName,
            'years'                   => $params['NumYears'],
            'registrant_organisation' => $params['RegistrantOrganizationName'],
            'registrant_firstname'    => $params['RegistrantFirstName'],
            'registrant_lastname'     => $params['RegistrantLastName'],
            'registrant_email'        => $params['RegistrantEmailAddress'],
            'registrant_phone'        => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'registrant_address'      => [$params['RegistrantAddress1']],
            'registrant_suburb'       => $params['RegistrantCity'],
            'registrant_state'        => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'registrant_postcode'     => $params['RegistrantPostalCode'],
            'registrant_country'      => $params['RegistrantCountry'],
            'technical_organisation'  => $params['RegistrantOrganizationName'],
            'technical_firstname'     => $params['RegistrantFirstName'],
            'technical_lastname'      => $params['RegistrantLastName'],
            'technical_email'         => $params['RegistrantEmailAddress'],
            'technical_phone'         => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'technical_address'       => [$params['RegistrantAddress1']],
            'technical_suburb'        => $params['RegistrantCity'],
            'technical_state'         => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'technical_postcode'      => $params['RegistrantPostalCode'],
            'technical_country'       => $params['RegistrantCountry'],
            'admin_organisation'      => $params['RegistrantOrganizationName'],
            'admin_firstname'         => $params['RegistrantFirstName'],
            'admin_lastname'          => $params['RegistrantLastName'],
            'admin_email'             => $params['RegistrantEmailAddress'],
            'admin_phone'             => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'admin_address'           => [$params['RegistrantAddress1']],
            'admin_suburb'            => $params['RegistrantCity'],
            'admin_state'             => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'admin_postcode'          => $params['RegistrantPostalCode'],
            'admin_country'           => $params['RegistrantCountry'],
            'billing_organisation'    => $params['RegistrantOrganizationName'],
            'billing_firstname'       => $params['RegistrantFirstName'],
            'billing_lastname'        => $params['RegistrantLastName'],
            'billing_email'           => $params['RegistrantEmailAddress'],
            'billing_phone'           => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'billing_address'         => [$params['RegistrantAddress1']],
            'billing_suburb'          => $params['RegistrantCity'],
            'billing_state'           => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'billing_postcode'        => $params['RegistrantPostalCode'],
            'billing_country'         => $params['RegistrantCountry'],
        ];

        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"])) {
                    $arguments['nameServers'][] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        }

        $command = 'domainRegister';
        $tldParts = explode('.', $params['tld']);
        $tldRoot  = end($tldParts);

        if ($tldRoot === 'au') {
            $command                       = 'domainRegisterAU';
            $arguments['registrantName']   = $params['RegistrantFirstName'] . ' ' . $params['RegistrantLastName'];
            $arguments['registrantID']     = $params['ExtendedAttributes']['au_registrantid'] ?? '';
            $arguments['registrantIDType'] = $params['ExtendedAttributes']['au_entityidtype'] ?? '';
        } elseif ($params['tld'] === 'us') {
            $command                     = 'domainRegisterUS';
            $arguments['appPurpose']     = $params['ExtendedAttributes']['us_purpose'] ?? '';
            $arguments['nexusCategory']  = $params['ExtendedAttributes']['us_nexus'] ?? '';
        }

        $response = $this->makeRequest($command, $arguments);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        // Enable ID protection if ordered as an add-on at checkout
        if (!empty($params['package_addons']['IDPROTECT'])) {
            try {
                $this->makeRequest('enableIDProtection', ['domainName' => $arguments['domainName']]);
            } catch (CE_Exception $e) {
                CE_Lib::log(4, 'SW: Could not enable ID protection after registration: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // TRANSFER
    // =========================================================================

    public function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        $userPackage->setCustomField(
            'Registrar Order Id',
            $userPackage->getCustomField('Registrar') . '-' . $params['userPackageId']
        );
        return 'Transfer has been initiated.';
    }

    public function initiateTransfer($params)
    {
        if ($params['tld'] === 'uk') {
            throw new CE_Exception('.uk transfers must be handled manually and assigned to the tag "SYNERGY-AU"');
        }

        $arguments = [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'authInfo'   => $params['eppCode'],
            'firstname'  => $params['RegistrantFirstName'],
            'lastname'   => $params['RegistrantLastName'],
            'address'    => $params['RegistrantAddress1'],
            'suburb'     => $params['RegistrantCity'],
            'state'      => $this->validateState($params['RegistrantStateProvince'], $params['RegistrantCountry']),
            'country'    => $params['RegistrantCountry'],
            'postcode'   => $params['RegistrantPostalCode'],
            'phone'      => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
            'email'      => $params['RegistrantEmailAddress'],
        ];

        $this->makeRequest('transferDomain', $arguments);
    }

    public function getTransferStatus($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);

        if (in_array(strtolower($response->domain_status), ['ok', 'clienttransferprohibited'])) {
            $userPackage = new UserPackage($params['userPackageId']);
            $userPackage->setCustomField('Transfer Status', 'Completed');
            return 'Completed';
        }

        return $response->domain_status;
    }

    public function doCancelTransfer($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $domain      = $userPackage->getCustomField('Domain Name');
        list($sld, $tld) = DomainNameGateway::splitDomain($domain);

        $response = $this->makeRequest('transferCancel', ['domainName' => $sld . '.' . $tld]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'Transfer cancelled successfully.';
    }

    // =========================================================================
    // RENEWAL & RESTORE
    // =========================================================================

    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->renewDomain($this->buildRenewParams($userPackage, $params));
        $userPackage->setCustomField(
            'Registrar Order Id',
            $userPackage->getCustomField('Registrar') . '-' . $params['userPackageId']
        );
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    public function renewDomain($params)
    {
        $response = $this->makeRequest('renewDomain', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'years'      => $params['NumYears'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
    }

    /**
     * Restore an expired domain that is within its redemption grace period.
     * Mapped to the "Restore Domain" admin action button.
     */
    public function doRestoreDomain($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $domain      = $userPackage->getCustomField('Domain Name');
        list($sld, $tld) = DomainNameGateway::splitDomain($domain);

        $response = $this->makeRequest('restoreDomain', ['domainName' => $sld . '.' . $tld]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return $domain . ' has been restored successfully.';
    }

    // =========================================================================
    // GENERAL INFO & DOMAIN LIST
    // =========================================================================

    public function getGeneralInfo($params)
    {
        $data     = [];
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);

        if (
            $response->status == 'ERR_DOMAININFO_FAILED'
            && $response->errorMessage == 'Domain Info Failed - Unable to retrieve domain id'
        ) {
            return [
                'id'                 => '',
                'domain'             => $params['sld'] . '.' . $params['tld'],
                'expiration'         => '',
                'registrationstatus' => 'RGP',
                'purchasestatus'     => 'RGP',
                'autorenew'          => false,
                'idprotect'          => false,
                'is_registered'      => false,
                'is_expired'         => true,
            ];
        }

        $data['id']                 = $response->domainRoid;
        $data['domain']             = $response->domainName;
        $data['expiration']         = date('m/d/Y', strtotime($response->domain_expiry));
        $data['registrationstatus'] = $response->status;
        $data['purchasestatus']     = $response->status;
        $data['autorenew']          = isset($response->autoRenew) && $response->autoRenew != 'off';
        $data['idprotect']          = isset($response->idProtect) && strtolower($response->idProtect) === 'enabled';
        $data['is_registered']      = false;
        $data['is_expired']         = false;

        $domainStatus = strtolower($response->domain_status);

        if (in_array($domainStatus, ['ok', 'clienttransferprohibited'])) {
            $data['is_registered'] = true;
        } elseif (in_array($domainStatus, ['expired', 'clienthold'])) {
            $data['is_expired'] = true;
        } elseif (in_array($domainStatus, ['deleted', 'dropped', 'policydelete'])) {
            $data['registrationstatus'] = 'RGP';
        }

        return $data;
    }

    public function fetchDomains($params)
    {
        $domains  = [];
        $response = $this->makeRequest('listDomains');

        if ($response->status == 'OK') {
            foreach ($response->domainList as $domain) {
                list($sld, $tld) = DomainNameGateway::splitDomain($domain->domainName);
                $domains[] = [
                    'id'  => $domain->domainRoid,
                    'sld' => $sld,
                    'tld' => $tld,
                    'exp' => $domain->domain_expiry,
                ];
            }
        }

        $total    = count($domains);
        $metaData = [
            'total'      => $total,
            'start'      => 0,
            'end'        => max(0, $total - 1),
            'numPerPage' => $total,
            'next'       => null,
        ];

        return [$domains, $metaData];
    }

    // =========================================================================
    // NAMESERVERS
    // =========================================================================

    public function getNameServers($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        $data = [];
        if (!empty($response->nameServers)) {
            foreach ($response->nameServers as $nameserver) {
                $data[] = $nameserver;
            }
        }
        $data['usesDefault'] = ($response->dnsConfig == 4);
        $data['hasDefault']  = true;

        return $data;
    }

    public function setNameServers($params)
    {
        $arguments = ['domainName' => $params['sld'] . '.' . $params['tld']];

        if ($params['default'] == true) {
            $arguments['dnsConfigType'] = 4;
        } else {
            $arguments['dnsConfigType'] = 1;
            foreach ($params['ns'] as $value) {
                $arguments['nameServers'][] = $value;
            }
        }

        $this->makeRequest('updateNameServers', $arguments);
    }

    // =========================================================================
    // CONTACTS
    // =========================================================================

    public function getContactInformation($params)
    {
        $response = $this->makeRequest('listContacts', ['domainName' => $params['sld'] . '.' . $params['tld']]);

        $info    = [];
        $typeMap = [
            'registrant' => 'Registrant',
            'billing'    => 'AuxBilling',
            'admin'      => 'Admin',
            'tech'       => 'Tech',
        ];

        foreach ($typeMap as $apiType => $ceType) {
            if (isset($response->$apiType)) {
                $c = $response->$apiType;
                $info[$ceType] = [
                    'Company'       => [$this->user->lang('Organization'), isset($c->organisation) ? $c->organisation : ''],
                    'FirstName'     => [$this->user->lang('First Name'), $c->firstname],
                    'LastName'      => [$this->user->lang('Last Name'), $c->lastname],
                    'Address1'      => [$this->user->lang('Address') . ' 1', $c->address1],
                    'Address2'      => [$this->user->lang('Address') . ' 2', isset($c->address2) ? $c->address2 : ''],
                    'City'          => [$this->user->lang('City'), $c->suburb],
                    'StateProvince' => [$this->user->lang('Province') . '/' . $this->user->lang('State'), $c->state],
                    'Country'       => [$this->user->lang('Country'), $c->country],
                    'PostalCode'    => [$this->user->lang('Postal Code'), $c->postcode],
                    'EmailAddress'  => [$this->user->lang('E-mail'), $c->email],
                    'Phone'         => [$this->user->lang('Phone'), $c->phone],
                    'Fax'           => [$this->user->lang('Fax'), isset($c->fax) ? $c->fax : ''],
                ];
            } else {
                $info[$ceType] = [
                    'Company'       => [$this->user->lang('Organization'), ''],
                    'FirstName'     => [$this->user->lang('First Name'), ''],
                    'LastName'      => [$this->user->lang('Last Name'), ''],
                    'Address1'      => [$this->user->lang('Address') . ' 1', ''],
                    'Address2'      => [$this->user->lang('Address') . ' 2', ''],
                    'City'          => [$this->user->lang('City'), ''],
                    'StateProvince' => [$this->user->lang('Province') . '/' . $this->user->lang('State'), ''],
                    'Country'       => [$this->user->lang('Country'), ''],
                    'PostalCode'    => [$this->user->lang('Postal Code'), ''],
                    'EmailAddress'  => [$this->user->lang('E-mail'), ''],
                    'Phone'         => [$this->user->lang('Phone'), ''],
                    'Fax'           => [$this->user->lang('Fax'), ''],
                ];
            }
        }

        return $info;
    }

    public function setContactInformation($params)
    {
        $arguments = [
            'domainName'              => $params['sld'] . '.' . $params['tld'],
            'registrant_organisation' => $params['Registrant_Company'],
            'registrant_firstname'    => $params['Registrant_FirstName'],
            'registrant_lastname'     => $params['Registrant_LastName'],
            'registrant_address'      => array_filter([$params['Registrant_Address1'], $params['Registrant_Address2'] ?? '']),
            'registrant_email'        => $params['Registrant_EmailAddress'],
            'registrant_suburb'       => $params['Registrant_City'],
            'registrant_state'        => $params['Registrant_StateProvince'],
            'registrant_country'      => $params['Registrant_Country'],
            'registrant_postcode'     => $params['Registrant_PostalCode'],
            'registrant_phone'        => $this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country']),
            'registrant_fax'          => $this->validatePhone($params['Registrant_Fax'] ?? '', $params['Registrant_Country']),
        ];

        $response = $this->makeRequest('updateContact', $arguments);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return $this->user->lang('Contact Information updated successfully.');
    }

    // =========================================================================
    // REGISTRAR LOCK
    // =========================================================================

    public function getRegistrarLock($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        return ($response->domain_status === 'clientTransferProhibited');
    }

    public function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return 'Updated Registrar Lock.';
    }

    public function setRegistrarLock($params)
    {
        $domainName = $params['sld'] . '.' . $params['tld'];
        $command    = !empty($params['lock']) ? 'lockDomain' : 'unlockDomain';
        $this->makeRequest($command, ['domainName' => $domainName]);
    }

    // =========================================================================
    // AUTO-RENEW
    // =========================================================================

    public function setAutorenew($params)
    {
        $command = ($params['autorenew'] == 1) ? 'enableAutoRenewal' : 'disableAutoRenewal';
        $this->makeRequest($command, ['domainName' => $params['sld'] . '.' . $params['tld']]);
        return 'Domain updated successfully';
    }

    // =========================================================================
    // ID PROTECTION (WHOIS PRIVACY)
    // =========================================================================

    /**
     * Called by CE when the customer/admin enables WHOIS privacy.
     */
    public function enablePrivateRegistration($params)
    {
        $response = $this->makeRequest('enableIDProtection', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
        return 'ID Protection has been enabled.';
    }

    /**
     * Called by CE when the customer/admin disables WHOIS privacy.
     */
    public function disablePrivateRegistration($params)
    {
        $response = $this->makeRequest('disableIDProtection', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }
        return 'ID Protection has been disabled.';
    }

    // =========================================================================
    // EPP CODE
    // =========================================================================

    public function getEPPCode($params)
    {
        $response = $this->makeRequest('domainInfo', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        return !empty($response->domainPassword) ? $response->domainPassword : '';
    }

    public function sendTransferKey($params)
    {
    }

    // =========================================================================
    // DNS RECORDS
    // =========================================================================

    public function getDNS($params)
    {
        $response = $this->makeRequest('listDNSZone', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status == 'ERR_LISTDNSZONE_FAILED') {
            throw new CE_Exception($response->errorMessage);
        }

        $records = [];
        foreach ($response->records ?? [] as $row) {
            if (in_array($row->type, ['NS', 'SOA'])) {
                continue;
            }
            $record = [
                'id'       => $row->id,
                'hostname' => $row->hostName,
                'address'  => $row->content,
                'type'     => $row->type,
            ];
            if (!empty($row->prio) && in_array($row->type, ['MX', 'SRV'])) {
                $record['priority'] = $row->prio;
            }
            $records[] = $record;
        }

        return [
            'records' => $records,
            'types'   => $this->dnsTypes,
            'default' => true,
        ];
    }

    public function setDNS($params)
    {
        $domainName = $params['sld'] . '.' . $params['tld'];

        // SW has no edit — delete matching records then re-add
        $response = $this->makeRequest('listDNSZone', ['domainName' => $domainName]);
        if ($response->status == 'ERR_LISTDNSZONE_FAILED') {
            throw new CE_Exception($response->errorMessage);
        }

        foreach ($response->records ?? [] as $row) {
            if (in_array($row->type, $this->dnsTypes)) {
                $this->makeRequest('deleteDNSRecord', [
                    'domainName' => $domainName,
                    'recordID'   => $row->id,
                ]);
            }
        }

        foreach ($params['records'] as $record) {
            $arguments = [
                'domainName'    => $domainName,
                'recordName'    => $record['hostname'],
                'recordType'    => $record['type'],
                'recordContent' => $record['address'],
                'recordTTL'     => 86400,
            ];
            if (in_array($record['type'], ['MX', 'SRV'])) {
                $arguments['recordPrio'] = isset($record['priority']) ? (string) $record['priority'] : '0';
            }
            $this->makeRequest('addDNSRecord', $arguments);
        }
    }

    // =========================================================================
    // DNSSEC
    // =========================================================================

    /**
     * Retrieve DNSSEC DS records for a domain.
     *
     * @return array List of DS records with keys: uuid, keyTag, algorithm, digestType, digest
     */
    public function getDNSSEC($params)
    {
        $response = $this->makeRequest('DNSSECListDS', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        $records = [];
        if (!empty($response->DSData)) {
            foreach ($response->DSData as $ds) {
                $records[] = [
                    'uuid'       => isset($ds->UUID)       ? $ds->UUID       : '',
                    'keyTag'     => isset($ds->keyTag)     ? $ds->keyTag     : '',
                    'algorithm'  => isset($ds->algorithm)  ? $ds->algorithm  : '',
                    'digestType' => isset($ds->digestType) ? $ds->digestType : '',
                    'digest'     => isset($ds->digest)     ? $ds->digest     : '',
                ];
            }
        }

        return $records;
    }

    /**
     * Add a DNSSEC DS record.
     *
     * @param array $params Must include: sld, tld, keyTag, algorithm, digestType, digest
     */
    public function addDNSSECRecord($params)
    {
        $response = $this->makeRequest('DNSSECAddDS', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'keyTag'     => $params['keyTag'],
            'algorithm'  => $params['algorithm'],
            'digestType' => $params['digestType'],
            'digest'     => $params['digest'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'DNSSEC DS record added successfully.';
    }

    /**
     * Remove a DNSSEC DS record by UUID.
     *
     * @param array $params Must include: sld, tld, uuid
     */
    public function deleteDNSSECRecord($params)
    {
        $response = $this->makeRequest('DNSSECRemoveDS', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'UUID'       => $params['uuid'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'DNSSEC DS record removed successfully.';
    }

    // =========================================================================
    // GLUE RECORDS / CHILD HOSTS
    // =========================================================================

    /**
     * List all glue records (child hosts) registered under a domain.
     *
     * @return array List of hosts with keys: hostname, addresses (array of IPs)
     */
    public function getGlueRecords($params)
    {
        $response = $this->makeRequest('listAllHosts', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        $hosts = [];
        if (!empty($response->hosts)) {
            foreach ($response->hosts as $host) {
                $hosts[] = [
                    'hostname'  => $host->hostName,
                    'addresses' => is_array($host->ip) ? $host->ip : [$host->ip],
                ];
            }
        }

        return $hosts;
    }

    /**
     * Add a glue record (child host) to a domain.
     *
     * @param array $params Must include: sld, tld, hostname (FQDN), addresses (array of IPs)
     */
    public function addGlueRecord($params)
    {
        $response = $this->makeRequest('addHost', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'host'       => $params['hostname'],
            'ipAddress'  => is_array($params['addresses']) ? $params['addresses'] : [$params['addresses']],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'Glue record added successfully.';
    }

    /**
     * Delete a glue record (child host) from a domain.
     *
     * @param array $params Must include: sld, tld, hostname (FQDN)
     */
    public function deleteGlueRecord($params)
    {
        $response = $this->makeRequest('deleteHost', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'host'       => $params['hostname'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'Glue record deleted successfully.';
    }

    // =========================================================================
    // URL FORWARDING
    // =========================================================================

    /**
     * List URL forwards configured for a domain.
     *
     * @return array List of forwards with keys: id, hostname, destination, type (URL|FRAME)
     */
    public function getURLForwarding($params)
    {
        $response = $this->makeRequest('getSimpleURLForwards', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        $records = [];
        if (!empty($response->records)) {
            foreach ($response->records as $record) {
                $records[] = [
                    'id'          => $record->recordID,
                    'hostname'    => $record->hostname,
                    'destination' => $record->destination,
                    'type'        => ($record->redirectType === 'C') ? 'FRAME' : 'URL',
                ];
            }
        }

        return $records;
    }

    /**
     * Add a URL forward for a domain.
     *
     * @param array $params Must include: sld, tld, hostname (subdomain), destination (URL), type (URL|FRAME)
     */
    public function addURLForward($params)
    {
        $response = $this->makeRequest('addSimpleURLForward', [
            'domainName'  => $params['sld'] . '.' . $params['tld'],
            'hostName'    => $params['hostname'],
            'destination' => $params['destination'],
            'type'        => ($params['type'] === 'FRAME') ? 'FRAME' : 'URL',
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'URL forward added successfully.';
    }

    /**
     * Delete a URL forward by record ID.
     *
     * @param array $params Must include: sld, tld, id (recordID)
     */
    public function deleteURLForward($params)
    {
        $response = $this->makeRequest('deleteSimpleURLForward', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'recordID'   => $params['id'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'URL forward deleted successfully.';
    }

    // =========================================================================
    // EMAIL FORWARDING
    // =========================================================================

    /**
     * List email forwards configured for a domain.
     *
     * @return array List of forwards with keys: id, source, destination
     */
    public function getEmailForwarding($params)
    {
        $response = $this->makeRequest('listMailForwards', ['domainName' => $params['sld'] . '.' . $params['tld']]);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        $forwards = [];
        if (!empty($response->forwards)) {
            foreach ($response->forwards as $forward) {
                $forwards[] = [
                    'id'          => $forward->id,
                    'source'      => $forward->source,
                    'destination' => $forward->destination,
                ];
            }
        }

        return $forwards;
    }

    /**
     * Add an email forward for a domain.
     *
     * @param array $params Must include: sld, tld, source (email address), destination (email address)
     */
    public function addEmailForward($params)
    {
        $response = $this->makeRequest('addMailForward', [
            'domainName'  => $params['sld'] . '.' . $params['tld'],
            'source'      => $params['source'],
            'destination' => $params['destination'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'Email forward added successfully.';
    }

    /**
     * Delete an email forward by record ID.
     *
     * @param array $params Must include: sld, tld, id (forwardID)
     */
    public function deleteEmailForward($params)
    {
        $response = $this->makeRequest('deleteMailForward', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
            'forwardID'  => $params['id'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'Email forward deleted successfully.';
    }

    // =========================================================================
    // .AU ELIGIBILITY & CHANGE OF REGISTRANT
    // =========================================================================

    /**
     * Retrieve .AU eligibility fields for a domain (used before registration/COR).
     */
    public function getAUEligibilityFields($params)
    {
        $response = $this->makeRequest('getDomainEligibilityFields', [
            'domainName' => $params['sld'] . '.' . $params['tld'],
        ]);

        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return $response;
    }

    /**
     * Initiate an .AU Change of Registrant (COR) for a domain.
     * The registrant will receive a confirmation email from the registry.
     *
     * @param array $params Must include: sld, tld, and optionally registrantName, registrantID, registrantIDType
     */
    public function initiateAUChangeOfRegistrant($params)
    {
        $arguments = ['domainName' => $params['sld'] . '.' . $params['tld']];

        foreach (['registrantName', 'registrantID', 'registrantIDType'] as $field) {
            if (!empty($params[$field])) {
                $arguments[$field] = $params[$field];
            }
        }

        $response = $this->makeRequest('initiateAUCOR', $arguments);
        if ($response->status != 'OK') {
            throw new CE_Exception($response->errorMessage);
        }

        return 'AU Change of Registrant initiated. The registrant will receive a confirmation email from the registry.';
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function validateState($state, $country)
    {
        if ($country !== 'AU') {
            return $state;
        }

        $normalised = strtoupper(preg_replace('/[\s.]/', '', trim($state)));

        $map = [
            'VICTORIA'                   => 'VIC', 'VIC' => 'VIC',
            'NEWSOUTHWALES'              => 'NSW', 'NSW' => 'NSW',
            'QUEENSLAND'                 => 'QLD', 'QLD' => 'QLD',
            'AUSTRALIANCAPITALTERRITORY' => 'ACT', 'AUSTRALIACAPITALTERRITORY' => 'ACT', 'ACT' => 'ACT',
            'SOUTHAUSTRALIA'             => 'SA',  'SA'  => 'SA',
            'WESTERNAUSTRALIA'           => 'WA',  'WA'  => 'WA',
            'NORTHERNTERRITORY'          => 'NT',  'NT'  => 'NT',
            'TASMANIA'                   => 'TAS', 'TAS' => 'TAS',
        ];

        return isset($map[$normalised]) ? $map[$normalised] : $state;
    }

    private function validatePhone($phone, $country)
    {
        $phone = preg_replace('/[^\d]/', '', (string) $phone);

        if ($phone === '') {
            return $phone;
        }

        $result = $this->db->query(
            "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''",
            $country
        );

        if (!$row = $result->fetch()) {
            return $phone;
        }

        $code  = $row['phone_code'];
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);

        return ($phone[0] === '+') ? $phone : "+$code.$phone";
    }

    private function makeRequest($command, $params = [])
    {
        $request               = [];
        $request['resellerID'] = $this->settings->get('plugin_synergywholesale_Reseller ID');
        $request['apiKey']     = $this->settings->get('plugin_synergywholesale_API Key');
        $request               = array_merge($request, $params);

        try {
            $client = new SoapClient(null, ['location' => 'https://api.synergywholesale.com', 'uri' => '']);
            CE_Lib::log(4, "SW API call: $command");
            CE_Lib::log(4, $request);
            $result = $client->{$command}($request);
            CE_Lib::log(4, $result);
            return $result;
        } catch (SoapFault $e) {
            throw new CE_Exception(
                'SynergyWholesale Plugin Error: ' . $e->getMessage(),
                EXCEPTION_CODE_CONNECTION_ISSUE
            );
        }
    }
}
