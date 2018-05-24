<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2015 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_'))
    exit;

require_once (dirname(__FILE__) . '/lib/api/vendor/autoload.php');

class Mauticprestashop extends Module
{

    public $_fields = array('MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_CUSTOMER', 'MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_GUEST', 'MAUTICPRESTASHOP_TRACKING_CODE', 'MAUTICPRESTASHOP_TRACKING_CODE_JS', 'addtolistusercreated', 'addtolistnewsletteradded', 'addtolistoptinadded', 'addtolistcartcreated', 'addtolistordercreated', 'removetolistordercreated', 'MAUTICPRESTASHOP_CLIENT_KEY', 'MAUTICPRESTASHOP_CLIENT_SECRET', 'MAUTICPRESTASHOP_BASE_URL');
    public $_fields_api = array('MAUTICPRESTASHOP_CLIENT_KEY', 'MAUTICPRESTASHOP_CLIENT_SECRET', 'MAUTICPRESTASHOP_BASE_URL');
    public $mauticBaseUrlApi;

    public function __construct()
    {
        $this->name = 'mauticprestashop';
        $this->tab = 'administration';
        $this->version = '1.7.0';
        $this->author = 'kuzmany.biz/prestashop';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->module_key = '4d10333ceab8a1c5c0901a143144519b';

        parent::__construct();

        $this->displayName = $this->l('Mautic for Prestashop');
        $this->description = $this->l('Integration Mautic API to Prestashop.');

        $this->mauticBaseUrlApi = Configuration::get('MAUTICPRESTASHOP_BASE_URL');
    }

    public function install()
    {

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('actionCustomerAccountAdd') &&
            $this->registerHook('actionCartSave') &&
            $this->registerHook('orderConfirmation') &&
            $this->registerHook('authentication') &&
            $this->registerHook('displayFooter');
    }

    public function uninstall()
    {
        foreach ($this->getFields() as $field) {
            Configuration::deleteByName($field);
        }
        Configuration::deleteByName('MAUTICPRESTASHOP_ACCESS_TOKEN_DATA');
        return parent::uninstall();
    }

    public function hookBackOfficeHeader()
    {
        
    }

    public function hookHeader()
    {
        if (Dispatcher::getInstance()->getController() == 'identity' && Tools::isSubmit('submitIdentity')) {
            
        }
    }

    public function mapFromArray($array)
    {
        $leadId = $this->getLeadId();
        if ($leadId) {
            $data = array();
            $auth = $this->mautic_auth();
            $api = new Mautic\MauticApi();
            $leadApi = $api->newApi('contacts', $auth, $this->mauticBaseUrlApi);
            foreach ($array as $key => $a) {
                foreach ($this->getPrestashopMauticMapping() as $key2 => $a2) {
                    if ($key2 == $key) {
                        $data[$a2] = $a;
                    }
                }
            }
            if (!empty($data)) {
                $leadApi->edit($leadId, $data);
            }
        }
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        if (((bool) Tools::isSubmit('submitMauticprestashopModule')) == true) {
            $this->postProcess();
        }


        return $this->renderForm();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMauticprestashopModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );


        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getPrestashopMauticMapping()
    {
        $fields = $this->getPrestashopMappingWithPrefix();
        $ret = array();
        foreach ($fields as $field) {
            if ($value = Configuration::get($field)) {
                $ret[str_replace($this->name, '', $field)] = $value;
            }
        }
        return $ret;
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        $ret = array();
        foreach ($this->getFields() as $field) {
            $ret[$field] = Configuration::get($field);
        }
        return $ret;
    }

    private function validateAccessToken()
    {
        if (Configuration::get('MAUTICPRESTASHOP_BASE_URL') && Configuration::get('MAUTICPRESTASHOP_CLIENT_KEY') && Configuration::get('MAUTICPRESTASHOP_CLIENT_SECRET')) {
            if (Configuration::get('MAUTICPRESTASHOP_ACCESS_TOKEN_DATA')) {
                $auth = $this->mautic_auth();
                if ($auth) {
                    return $auth->validateAccessToken();
                }
            }
        }
        return false;
    }

    protected function getConfigForm()
    {

        $validAccessToken = $this->validateAccessToken();
        $access_token_data = Configuration::get('MAUTICPRESTASHOP_ACCESS_TOKEN_DATA');
        $back = urlencode(base64_encode(serialize(
                    $this->context->link->getAdminLink('AdminModules', true)
                    . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name . '&authorizedone=1')));
        $auth_url = Tools::getProtocol() . Tools::safeOutput(Tools::getHttpHost()) . __PS_BASE_URI__ . 'modules/' . $this->name . '/authorization.php?id_shop=' . $this->context->shop->id . '&id_shop_group=' . $this->context->shop->id_shop_group . '&back=' . $back . '&reset=1';
        $this->context->smarty->assign(array(
            'has_data' => ((!Configuration::get('MAUTICPRESTASHOP_BASE_URL') || !Configuration::get('MAUTICPRESTASHOP_CLIENT_KEY') || !Configuration::get('MAUTICPRESTASHOP_CLIENT_SECRET')) ? false : true),
            'error' => Tools::getIsset('error'),
            'auth_url' => $auth_url,
            'validAccessToken' => $validAccessToken,
            'access_token_data' => $access_token_data));
        $html_content = $this->display(__FILE__, 'views/templates/admin/auth.tpl');

        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings for OAuth 1 authorization'),
                    'icon' => 'icon-cogs',
                ),
                'tabs' => array(
                    'tracking' => $this->l('Tracking Pixel and OAuth1 settings'),
                ),
                'input' => array(
                    array(
                        'tab' => 'tracking',
                        'type' => 'html',
                        'name' => 'html_data',
                        'html_content' => $html_content
                    ),
                    array(
                        'tab' => 'tracking',
                        'type' => 'switch',
                        'label' => $this->l('Add Mautic Tracking pixel to website'),
                        'name' => 'MAUTICPRESTASHOP_TRACKING_CODE',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'tab' => 'tracking',
                        'type' => 'switch',
                        'label' => $this->l('Javascript Based Tracking for Mautic 1.4 and above'),
                        'name' => 'MAUTICPRESTASHOP_TRACKING_CODE_JS',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'tab' => 'tracking',
                        'type' => 'text',
                        'label' => $this->l('Base URL of the Mautic instance'),
                        'name' => 'MAUTICPRESTASHOP_BASE_URL',
                        'desc' => $this->l('Example: http://my-mautic-server.com  (without slash)'),
                    ),
                    array(
                        'tab' => 'tracking',
                        'type' => 'text',
                        'label' => $this->l('Client/Consumer key from Mautic'),
                        'name' => 'MAUTICPRESTASHOP_CLIENT_KEY',
                    ),
                    array(
                        'tab' => 'tracking',
                        'type' => 'text',
                        'label' => $this->l('Client/Consumer secret key from Mautic'),
                        'name' => 'MAUTICPRESTASHOP_CLIENT_SECRET',
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        if ($validAccessToken) {
            $form['form']['tabs'] = array_merge($form['form']['tabs'], array('leadid' => $this->l('Lead Identification')), array('lists' => $this->l('Lists mapping')), array('mapping' => $this->l('Fields mapping')));

            $form['form']['input'][] = array(
                'tab' => 'leadid',
                'type' => 'select',
                'label' => $this->l('Identify Lead by Customer ID'),
                'desc' => $this->l('Optional. Use just If the store is not running on the same domain as Mautic, set the field for storing Prestashop identifier. Create Publicly updatable field in your Mautic an set it here. Read more in documentation.'),
                'name' => 'MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_CUSTOMER',
                'options' => array(
                    'query' => $this->getMauticMapping(),
                    'id' => 'alias',
                    'name' => 'label',
                ),
            );

            $form['form']['input'][] = array(
                'tab' => 'leadid',
                'type' => 'select',
                'label' => $this->l('Identify Lead by Guest ID'),
                'desc' => $this->l('Optional. Use just If the store is not running on the same domain as Mautic, set the field for storing Prestashop identifier. Create Publicly updatable field in your Mautic an set it here. Read more in documentation.'),
                'name' => 'MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_GUEST',
                'options' => array(
                    'query' => $this->getMauticMapping(),
                    'id' => 'alias',
                    'name' => 'label',
                ),
            );

            $form['form']['input'][] = array(
                'tab' => 'lists',
                'type' => 'select',
                'label' => $this->l('Add to the list if customer is created'),
                'name' => 'addtolistusercreated',
                'options' => array(
                    'query' => $this->getMauticLists(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );


            $form['form']['input'][] = array(
                'tab' => 'lists',
                'type' => 'select',
                'label' => $this->l('Add to the list if customer is added to newsletter'),
                'name' => 'addtolistnewsletteradded',
                'options' => array(
                    'query' => $this->getMauticLists(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );

            $form['form']['input'][] = array(
                'tab' => 'lists',
                'type' => 'select',
                'label' => $this->l('Add to the list If customer want receive special offers from partners!'),
                'name' => 'addtolistoptinadded',
                'options' => array(
                    'query' => $this->getMauticLists(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );

            $form['form']['input'][] = array(
                'tab' => 'lists',
                'type' => 'select',
                'label' => $this->l('Add to the list if Cart is created'),
                'name' => 'addtolistcartcreated',
                'options' => array(
                    'query' => $this->getMauticLists(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );
            $form['form']['input'][] = array(
                'tab' => 'lists',
                'type' => 'select',
                'label' => $this->l('Add to the list if Order is created'),
                'name' => 'addtolistordercreated',
                'options' => array(
                    'query' => $this->getMauticLists(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );
            $form['form']['input'][] = array(
                'tab' => 'lists',
                'type' => 'select',
                'label' => $this->l('Remove from the list if Order is created'),
                'name' => 'removetolistordercreated',
                'options' => array(
                    'query' => $this->getMauticLists(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );


            $prestashop_fields_mapping = $this->getPrestashopMapping();
            foreach ($prestashop_fields_mapping as $prestashop_field_mapping) {
                $form['form']['input'][] = array(
                    'tab' => 'mapping',
                    'type' => 'select',
                    'label' => $this->l($prestashop_field_mapping),
                    'name' => $this->name . $prestashop_field_mapping,
                    'options' => array(
                        'query' => $this->getMauticMapping(),
                        'id' => 'alias',
                        'name' => 'label',
                    ),
                );
            }
        }
        return $form;
    }

    private function getPrestashopMapping()
    {
        $prestashop_fields_mapping = AddressFormat::getValidateFields('Address');
        $prestashop_fields_mapping = array_merge($prestashop_fields_mapping, array('email','id_gender', 'birthday', 'newsletter', 'optin', 'website', 'id_order', 'id_cart', 'cart_content'));
        return $prestashop_fields_mapping;
    }

    private function getPrestashopMappingWithPrefix()
    {
        $ret = array();
        foreach ($this->getPrestashopMapping() as $field) {
            $ret[] = $this->name . $field;
        }
        return $ret;
    }

    private function getMauticMapping()
    {
        if (Cache::retrieve(__CLASS__ . __FUNCTION__ . 'c')) {
            $fields_mapping = Cache::retrieve(__CLASS__ . __FUNCTION__ . 'c');
        } else {
            $fields_mapping = array();
            $auth = $this->mautic_auth();
            if ($auth->validateAccessToken()) {
                $api = new Mautic\MauticApi();
                $leadApi = $api->newApi('leads', $auth, $this->mauticBaseUrlApi);
                $fields_mapping = $leadApi->getFieldList();
            }
            array_unshift($fields_mapping, array('alias' => '', 'label' => $this->l('-skip-')));
            Cache::store(__CLASS__ . __FUNCTION__ . 'c', $fields_mapping);
        }
        return $fields_mapping;
    }

    private function getMauticLists()
    {
        if (Cache::retrieve(__CLASS__ . __FUNCTION__ . 'c')) {
            $lists = Cache::retrieve(__CLASS__ . __FUNCTION__ . 'c');
        } else {
            $lists = array();
            $auth = $this->mautic_auth();
            $validAccessToken = $auth->validateAccessToken();
            if ($validAccessToken) {
                $api = new Mautic\MauticApi();
                $listApi = $api->newApi('segments', $auth, $this->mauticBaseUrlApi);
                $lists = $listApi->getList();

                if(isset($lists['lists'])){
                    $lists = $lists['lists'];
                }
            }
            array_unshift($lists, array('id' => '', 'name' => $this->l('-skip-')));
            Cache::store(__CLASS__ . __FUNCTION__ . 'c', $lists);
        }
        return $lists;
    }

    protected function postProcess()
    {
        $field_api_change = false;
        foreach ($this->_fields_api as $field_api) {
            if ($db_field_api = Configuration::get($field_api)) {
                if ($db_field_api != Tools::getValue($field_api)) {
                    Configuration::updateValue($field_api, Tools::getValue($field_api));
                    $field_api_change = true;
                }
            }
        }
// change just if api don'st change
        if (!$field_api_change) {
            foreach ($this->getFields() as $field) {
                Configuration::updateValue($field, Tools::getValue($field));
            }
        } else {
            Configuration::deleteByName('MAUTICPRESTASHOP_ACCESS_TOKEN_DATA');
        }
    }

    public function mautic_auth($reauthorize = false)
    {
        $settings = array(
            'baseUrl' => Configuration::get('MAUTICPRESTASHOP_BASE_URL'),
            'version' => 'OAuth1a',
            'clientKey' => Configuration::get('MAUTICPRESTASHOP_CLIENT_KEY'),
            'clientSecret' => Configuration::get('MAUTICPRESTASHOP_CLIENT_SECRET'),
            'callback' => Tools::getProtocol() . Tools::safeOutput(Tools::getHttpHost()). __PS_BASE_URI__ . '/modules/mauticprestashop/authorization.php'
        );
        if (Tools::getIsset('back')) {
            $settings['callback'] .= '?back=' . urlencode(Tools::getValue('back'));
        }

        if ($reauthorize != true) {
            $accessTokenData = Tools::unSerialize(Configuration::get('MAUTICPRESTASHOP_ACCESS_TOKEN_DATA'), false);
            if (!$accessTokenData && !is_array($accessTokenData)) {
                
            } else {
                $settings['accessToken'] = $accessTokenData['access_token'];
                $settings['accessTokenSecret'] = $accessTokenData['access_token_secret'];
            }
        }
        $auth = new Mautic\Auth\ApiAuth();
        return $auth->newAuth($settings);
    }

    public function getLeadIdentifyType()
    {
        if (isset($_COOKIE['mtc_id'])) {
            return $_COOKIE['mtc_id'];
        }elseif (isset($_COOKIE['mautic_session_id'])) {
            $sessionId = $_COOKIE['mautic_session_id'];
            if (isset($_COOKIE[$sessionId])) {
                return $_COOKIE[$sessionId];
            }
        }
        $fields = array();
        if (ConfigurationCore::get('MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_CUSTOMER')) {
            $fields['customer'] = ConfigurationCore::get('MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_CUSTOMER');
        }
        if (ConfigurationCore::get('MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_GUEST')) {
            $fields['guest'] = ConfigurationCore::get('MAUTICPRESTASHOP_TRACKING_LEAD_FIELD_GUEST');
        }
        return $fields;
    }

    public function getLeadId()
    {
        $cache_id = __CLASS__ . __FUNCTION__ . 'c';
        if (Cache::retrieve($cache_id)) {
            $leadId = Cache::retrieve($cache_id);
        } else {
            $leadIdType = $this->getLeadIdentifyType();
            if (is_numeric($leadIdType)) {
                $leadId = $leadIdType;
            }
            if ($leadIdType && !is_int($leadIdType) && is_array($leadIdType) && !empty($leadIdType)) {
                $fields = $leadIdType;
                $auth = $this->mautic_auth();
                $api = new Mautic\MauticApi();
                $leadApi = $api->newApi('contacts', $auth, $this->mauticBaseUrlApi);
                foreach ($fields as $type => $field) {
                    if ($type == 'customer') {
                        $search = $field . ':' . Context::getContext()->customer->id;
                    } elseif ($type == 'guest') {
                        $search = $field . ':' . Context::getContext()->cookie->id_guest;
                    }
                    $leads = $leadApi->getList($search);
                    if (isset($leads['total']) && $leads['total'] > 0) {
                        if (isset($leads['contacts'])) {
                            array_reverse($leads['contacts']);
                            $leadId = $leads['contacts'][0]['id'];
                            Cache::store($cache_id, $leadId);
                            break;
                        }
                    }
                }
            }
        }
        return isset($leadId) ? $leadId : null;
    }

    private function objectToArray($object)
    {
        if (is_object($object)) {
            return Tools::jsonDecode(Tools::jsonEncode(($object)), true);
        } else {
            return array();
        }
    }

    public function hookActionAuthentication($params)
    {
        $customer = $this->context->customer;
        $this->proccessCustomerData($customer);
    }

    public function hookActionCustomerAccountAdd($params)
    {
        $this->proccessCustomerData($params['newCustomer'], true);
    }

    public function proccessCustomerData($customer, $new = false)
    {
        $leadId = $this->getLeadId();
        if ($leadId) {
            $listIdUserCreated = Configuration::get('addtolistusercreated');
            $listIdNewsletter = Configuration::get('addtolistnewsletteradded');
            $listIdOptin = Configuration::get('addtolistoptinadded');

            $newsletter = $customer->newsletter;
            $optin = $customer->optin;
            $auth = $this->mautic_auth();
            $api = new Mautic\MauticApi();
            $listApi = $api->newApi('segments', $auth, $this->mauticBaseUrlApi);
            if ($new && $listIdUserCreated) {
               $result =  $listApi->addLead($listIdUserCreated, $leadId);
            }
            if ($newsletter && $listIdNewsletter) {
                $listApi->addLead($listIdNewsletter, $leadId);
            }
            if ($optin && $listIdOptin) {
                $listApi->addLead($listIdOptin, $leadId);
            }
            $this->mapCustomerAndAddress($customer);
        }
    }

    public function hookActionCartSave($params)
    {
        if ($listid = Configuration::get('addtolistcartcreated')) {
            $cart = $params['cart'];
            $leadId = $this->getLeadId();
            if ($leadId) {
                if (Validate::isLoadedObject($cart)) {
                        $auth = $this->mautic_auth();
                        $api = new Mautic\MauticApi();
                        $listApi = $api->newApi('segments', $auth, $this->mauticBaseUrlApi);
                        $listApi->addLead($listid, $leadId);
                        $this->mapFromArray(array('id_cart' => $cart->id, 'cart_content' => $this->returnTextfromArray($cart->getProducts())));
                }
            }
        }
    }

    private function returnTextfromArray(array $products)
    {
        $content = '';
        foreach ($products as $product) {
            foreach ($product as $key => $value) {
                if (!is_array($value)) {
                    $content .= $key . ': ' . $value . "\n";
                }
            }
        }
        return $content;
    }

    public function hookOrderConfirmation($params)
    {

        $order = $params['order'];
        if (Validate::isLoadedObject($order) && $order->getCurrentState() != (int) Configuration::get('PS_OS_ERROR')) {
            $listIdAdd = Configuration::get('addtolistordercreated');
            $listIdRemove = Configuration::get('removetolistordercreated');
            if ($listIdAdd || $listIdRemove) {
                $leadId = $this->getLeadId();
                if ($leadId) {
                    $auth = $this->mautic_auth();
                    $api = new Mautic\MauticApi();
                    $listApi = $api->newApi('segments', $auth, $this->mauticBaseUrlApi);
                    if ($listIdAdd) {
                        $listApi->addLead($listIdAdd, $leadId);
                    }
                    if ($listIdRemove) {
                        $listApi->removeLead($listIdRemove, $leadId);
                    }
                }
            }
            $this->mapCustomerAndAddress(Context::getContext()->customer);
            $this->mapFromArray(array('id_order' => $order->id));
        }
    }

    private function mapCustomerAndAddress($customer)
    {
        if (ValidateCore::isLoadedObject($customer)) {
            $customerArray = $this->objectToArray($customer);
            $this->mapFromArray($customerArray);
            $addresses = $customer->getAddresses(Context::getContext()->language->id);
            if (!empty($addresses)) {
                $this->mapFromArray(end($addresses));
            }
        }
    }

    public function getTrackingCode($email = null)
    {
// tracking code
        $data = array();
        $data['page_language'] = Context::getContext()->language->iso_code;
        if ($email != null) {
            $data['email'] = $email;
        } elseif (isset(Context::getContext()->customer->email)) {
            $data['email'] = Context::getContext()->customer->email;
        }
        if ($fields = $this->getLeadIdentifyType()) {
            if (is_array($fields) && !empty($fields)) {
                foreach ($fields as $type => $field) {
                    if ($type == 'customer' && isset(Context::getContext()->customer->id)) {
                        $data[$field] = Context::getContext()->customer->id;
                    } elseif ($type == 'guest') {
                        $data[$field] = Context::getContext()->cookie->id_guest;
                    }
                }
            }
        }
        if (Configuration::get('MAUTICPRESTASHOP_TRACKING_CODE_JS')) {
            return "<script>
    (function(w,d,t,u,n,a,m){w['MauticTrackingObject']=n;
        w[n]=w[n]||function(){(w[n].q=w[n].q||[]).push(arguments)},a=d.createElement(t),
        m=d.getElementsByTagName(t)[0];a.async=1;a.src=u;m.parentNode.insertBefore(a,m)
    })(window,document,'script','" . Configuration::get('MAUTICPRESTASHOP_BASE_URL') . "/mtc.js','mt');
    mt('send', 'pageview',  " . json_encode($data) . ");
</script>";
        } else {
            $d = urlencode(base64_encode(serialize($data)));
            return '<img src="' . Configuration::get('MAUTICPRESTASHOP_BASE_URL') . '/mtracking.gif?d = ' . $d . '" style="display: none;
            " />';
        }
    }

    public function hookDisplayFooter()
    {
        if (Configuration::get('MAUTICPRESTASHOP_TRACKING_CODE') && !Cache::isStored('mautic_tracking_code')) {
            return $this->getTrackingCode();
        }
    }

    public function get_public_content()
    {


        $content = array();

        foreach ($this->getFields() as $field)
            $content[$field] = Configuration::get($field);
        return $content;
    }

    private function getFields()
    {
        return array_merge(
            $this->_fields, $this->getPrestashopMappingWithPrefix());
    }
}
