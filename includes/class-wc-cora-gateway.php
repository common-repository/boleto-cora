<?php

class WC_Cora_Gateway extends WC_Payment_Gateway {

    use CoraMessagePlugin;

    protected $jwt_token = null;

    /**
     * Constructor for the gateway.
     *
     * @return void
     */
    public function __construct() {

        $this->id = 'cora';
        $this->plugin_slug = 'cora-woocommerce';
        $this->version = '1.1.7';
        $this->icon = apply_filters('woocommerce_cora_icon', '');
        $this->has_fields = false;
        $this->method_title = __('Boleto Cora', $this->plugin_slug);
        $this->method_description = __('<hr><h3>Cora. Serviços financeiros poderosamente simples.<br /><hr></h3>', $this->plugin_slug);

        // API.
        $this->domain_base = 'https://pay.divox.com.br';
        $this->uri_base = $this->domain_base . '/api/cora/';

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Define user set variables.
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->divox_key = $this->get_option('divox_key');
        $this->days_to_pay = $this->get_option('days_to_pay', 2);
        $this->debug = $this->get_option('debug');
        $this->testmode = $this->get_option('testmode');

        // Actions.
        add_action('woocommerce_checkout_create_order', 'misha_save_what_we_added', 20, 1);
        add_action('woocommerce_api_wc_cora_gateway', array($this, 'check_webhook_notification'));
        add_action('woocommerce_cora_webhook_notification', array($this, 'successful_webhook_notification'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_email_after_order_table', array($this, 'email_instructions'), 10, 2);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action("woocommerce_update_options_payment_gateways_" . $this->id, array($this, 'sincronize_url_webhook'), 10, 1);
        add_filter('woocommerce_billing_fields', array($this, 'checkout_billing_fields'), 10);
        add_filter('woocommerce_get_order_address', array($this, 'order_address'), 10, 3);

        if (is_admin()) {
            add_filter('woocommerce_admin_order_data_after_shipping_address', array($this, 'shop_order_billing_fields'));
        }

        // Active logs.
        if ('yes' == $this->debug) {
            if (class_exists('WC_Logger')) {
                $this->log = new WC_Logger();
            } else {
                $this->log = $this->woocommerce_instance()->logger();
            }
        }

        if (isset($_GET['error_cora'])) {

            if ($_GET['error_cora'] == 'license_key') {
                $this->flash('Chave Divox inválida.', 'error');
                add_action('admin_notices', [$this, 'flash_message']);
            }
        }

        // Display admin notices.
        $this->admin_notices();
    }

    /**
     * Custom billing admin fields.
     *
     * @param object $order Order data.
     */
    public function shop_order_billing_fields($order) {
        if (!defined('ABSPATH')) {
            exit;
        }

        $data = $order->get_data();
        $html = '
<div class="clear"></div>
<div class="wcbcf-address">
    <h4>Campos personalizados Cora</h4>
    <p>
        <strong>CPF/CNPJ: </strong> ' . $order->get_meta('cora_document') . '<br />
    </p>
</div>';

        echo $html;
    }

    function sincronize_url_webhook($array) {

        $url = $this->cora_url_webhook();

        $params = [
            'method' => 'POST',
            'body' => json_encode(['url' => $url]),
            'charset' => 'UTF-8',
            'sslverify' => false,
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'divoxKey' => $this->get_option('divox_key'),
            ]
        ];

        $response = wp_remote_post($this->uri_base . 'uri-webhooks/sincronize.json', $params);
        if ($response['response']['code'] != 200) {
            $this->flash('Falha ao sincronizar URL webhook. &Eacute; necess&aacute;rio Conectar a sua conta Cora.', 'error');
            add_action('admin_notices', [$this, 'flash_message']);
            return false;
        }

        $this->update_option('url_webhook', $url);

        return true;
    }

    public function order_address($address, $order) {

        // WooCommerce 3.0 or later.
        if (method_exists($order, 'get_meta')) {
            $address['number'] = $order->get_meta('_billing_number');
            $address['neighborhood'] = $order->get_meta('_billing_neighborhood');
        } else {
            $address['number'] = $order->billing_number;
            $address['neighborhood'] = $order->billing_neighborhood;
        }

        return $address;
    }

    public function checkout_billing_fields($fields) {

        if (!isset($fields['billing_cpf']) && !isset($fields['billing_cnpj'])) {
            $fields['billing_document'] = array(
                'label' => 'CPF/CNPJ',
                'class' => array('form-row-wide', 'person-type-field'),
                'placeholder' => 'Somente números',
                'required' => true,
                'type' => 'tel',
                'priority' => 21,
            );
        }

        return apply_filters('wcbcf_billing_fields', $fields);
    }

    protected function cora_url_webhook() {

        $url = get_site_url() . '/?wc-api=' . static::class;

        return $url;
    }

    /**
     * Backwards compatibility with version prior to 1.0.0.
     *
     * @since  1.0.0.
     *
     * @return object Returns the main instance of WooCommerce class.
     */
    protected function woocommerce_instance() {
        if (function_exists('WC')) {
            return WC();
        } else {
            global $woocommerce;
            return $woocommerce;
        }
    }

    /**
     * Displays notifications when the admin has something wrong with the configuration.
     *
     * @since  1.0.0
     *
     * @return void
     */
    protected function admin_notices() {
        if (is_admin()) {
            // Checks if tokens is not empty.
            if (empty($this->divox_key)) {
                add_action('admin_notices', array($this, 'divox_key_missing_message'));
            }

            // Checks that the currency is supported.
            if (!$this->using_supported_currency()) {
                add_action('admin_notices', array($this, 'currency_not_supported_message'));
            }
        }
    }

    /**
     * Returns a bool that indicates if currency is amongst the supported ones.
     *
     * @since  1.0.0
     *
     * @return bool
     */
    protected function using_supported_currency() {
        return ( get_woocommerce_currency() == 'BRL' );
    }

    /**
     * Returns a value indicating the the Gateway is available or not. It's called
     * automatically by WooCommerce before allowing customers to use the gateway
     * for payment.
     *
     * @since  1.0.0
     *
     * @return bool
     */
    public function is_available() {
        // Test if is valid for use.
        $available = ( 'yes' == $this->get_option('enabled') ) && !empty($this->divox_key) &&
                $this->using_supported_currency();

        return $available;
    }

    /**
     * Add error message in checkout.
     *
     * @since  1.0.0
     *
     * @param  string $message Error message.
     *
     * @return string          Displays the error message.
     */
    public function add_error($message) {
        if (version_compare($this->woocommerce_instance()->version, '1.0.0', '>=')) {
            wc_add_notice($message, 'error');
        } else {
            $this->woocommerce_instance()->add_error($message);
        }
    }

    /**
     * Initialise Gateway Settings Form Fields.
     *
     * @since  1.0.0
     *
     * @return void
     */
    public function init_form_fields() {

        $getTab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
        $getSection = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';
        $getPage = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        $current_url = get_admin_url() . 'admin.php?page=' . $getPage . '&tab=' . $getTab . '&section=' . $getSection;

        $urlButtonAutorize = $this->get_option('divox_key') ? $this->domain_base . '/corabrowser/auth?license_key=' . $this->get_option('divox_key') . '&redirect_uri=' . urlencode($current_url) : '#';

        $enableButtonAutorize = $this->get_option('divox_key') ? '' : 'disabled';

        $buttonAutorize = '<a href="' . $urlButtonAutorize . '" class="button-secondary woocommerce-save-button ' . $enableButtonAutorize . '">Autorizar</a>';

        $buttonAccount = '<a href="https://lp.cora.com.br/parceiros/divox" target="_new" class="button-secondary woocommerce-save-button" style="margin-top: -5px;">Abrir minha conta Cora</a>';

        $buttonSupport = '<a href="https://cora.divox.com.br/suporte/" target="_new" class="button-secondary woocommerce-save-button">Obtenha Suporte</a>';

        $this->form_fields = array(
            'cora_home' => array(
                'title' => sprintf('<span style="font-weight: bold; font-size: 15px; color:#23282d;">Bem-vindo(a) à Cora!<span>'),
                'type' => 'title',
                'description' => 'A geração de boletos Cora é gratuita e gerenciada pelo pelo app Cora - Sua conta digital, sem burocracia, sem taxas e sem papelada.',
            ),
            'cora_1' => array(
                'title' => sprintf('<span style="font-weight: bold; font-size: 15px; color:#FD3D6C;">1. Conta Cora:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<span>' . $buttonAccount),
                'type' => 'title',
                'description' => 'A conta Cora é exclusiva para empresas, mas se você é MEI, tem uma conta esperando por você! clique no botão para abrir sua conta. Aguarde a confirmação da aprovação por e-mail para concluir as configurações do plugin.<br /><font color="#96999E">(Se você já possui uma Conta Cora, ignore esta etapa.)</font>',
            ),
            'cora_2' => array(
                'title' => sprintf('<span style="font-weight: bold; font-size: 15px; color:#FD3D6C;">2. Registre o Plugin:<span>'),
                'type' => 'title',
                'description' => 'Se voc&ecirc; baixou o plugin no diret&oacute;rio de plugins do Wordpress, acesse <b><a href="https://cora.divox.com.br" target="_new">https://cora.divox.com.br</b></a> e clique em "Baixar Plugin" para obter sua chave de licen&ccedil;a.</font><br /><font color="#FB3069">(Você deve clicar em SALVAR para ativar a próxima etapa.)</font>',
            ),
            'divox_key' => array(
                'title' => __('Chave de Licença', $this->plugin_slug),
                'type' => 'text',
                'description' => __('Enviamos para o seu e-mail a chave de licença, juntamente com o arquivo do plugin, ela também pode ser obtida no portal de suporte.', $this->plugin_slug),
                'desc_tip' => true,
            ),
            'cora_credential_title' => array(
                'title' => sprintf('<span style="font-weight: bold; font-size: 15px; color:#FD3D6C;">3. Conecte sua conta Cora:<span>'),
                'type' => 'title',
                'description' => 'Para a integração funcionar, é necessário autorizar a sincronização de dados entre o seu site e a sua Conta Cora, clique no botão para autorizar a ativação do webhook.<br /><font color="#96999E">(Necessário na primeira configuração e também se você atualizar o plugin. Este procedimento vincula sua integração a sua conta bancária na Cora.)</font><br /><font color="#FB3069">(Você deve clicar em SALVAR para concluir a integração.)</font><br /><br />' . $buttonAutorize
            ),
            'cora_3' => array(
                'title' => sprintf('<span style="font-weight: bold; font-size: 15px; color:#23282d;"><hr><br />Configurações do Plugin<span>'),
                'type' => 'title',
                'description' => '',
            ),
            'enabled' => array(
                'title' => __('Ativar/Desativar', $this->plugin_slug),
                'label' => __('Ativar Boleto Cora', $this->plugin_slug),
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Nome a exibir', $this->plugin_slug),
                'type' => 'text',
                'description' => __('Nome da modalidade de pagamento exibida no checkout.', $this->plugin_slug),
                'desc_tip' => true,
                'default' => __('Boleto Bancário', $this->plugin_slug)
            ),
            'days_to_pay' => array(
                'title' => __('Dias para o vencimento', $this->plugin_slug),
                'type' => 'text',
                'description' => __('Informe o número de dias para o vencimento a partir da data de emissão do boleto.', $this->plugin_slug),
                'desc_tip' => true,
                'default' => '2'
            ),
            'description' => array(
                'title' => __('Instruções', $this->plugin_slug),
                'type' => 'textarea',
                'description' => __('Esta mensagem é exibida ao apresentar a opção Pague com Boleto Cora no checkout.', $this->plugin_slug),
                'default' => __('Pague com Boleto Cora', $this->plugin_slug)
            ),
            'testing' => array(
                'title' => __('Documentação e Suporte', $this->plugin_slug),
                'type' => 'title',
                'description' => 'Você tem dúvidas ou precisa de ajuda? Clique no botão para acessar nossa Central de Suporte. As informações abaixo são utilizadas para diagnóstico da integração.<br /><br />' . $buttonSupport
            ),
            'url_webhook' => array(
                'title' => __('Webhook URL', $this->plugin_slug),
                'type' => 'text',
                'disabled' => true,
                'placeholder' => $this->cora_url_webhook()
            ),
            'options' => array(
                'title' => __('Termos e Condições de uso', $this->plugin_slug),
                'type' => 'title',
                'description' => 'Esta é uma integração oficial, desenvolvida, mantida e suportada por Divox Tecnologia Ltda que lhe permite oferecer a opção de pagamento por <b>boleto</b> na finalização de cada pedido no Checkout do Woocommerce. O plugin de integração Boleto Cora - Woocommerce é 100% gratuito, mas depende de registro para funcionar corretamente no seu site. A geração de boletos em seu site também é 100% gratuita, mas ao <b>ativar e utilizar</b> este plugin você <b>concorda</b> com os <b>termos de uso</b> do serviço disponíveis em: <b><a href="https://cora.divox.com.br" target="_new">https://cora.divox.com.br</b></a> e com a cobrança de <b>tarifa por compensação</b>, ou seja, que ocorre somente quando o boleto é pago na rede bancária. A tarifa tamb&eacute;m &eacute; informada no site do plugin.',
            ),
            'debug' => array(
                'title' => __('Gerar Debug Log', $this->plugin_slug),
                'type' => 'checkbox',
                'label' => __('Ativar logging', $this->plugin_slug),
                'default' => 'no',
                'description' => sprintf(__('O arquivo de Log, registra as requisições junto a API Cora e é armazenado em %s', $this->plugin_slug), '<code>/uploads/wc-logs/' . $this->id . '-' . sanitize_file_name(wp_hash($this->id)) . '.txt </code><br /><br /><b><font color="#FD3D6C">Atenção!</font></b> Jamais mantenha este recurso ativado em uma loja em produção! Desative após o diagnóstico e fechamento do seu chamado de suporte! <br /><br /> O debug log registra todos os dados transacionados. Manter esta opção ativa pode expor os dados de seus clientes! A responsabilidade pelo armazenamento e segurança desses dados, em conformidade com as leis internacionais de proteção de dados são exclusivamente do proprietário desta loja Woocommerce.')
            ),
        );
    }

    /**
     * Generate the billet on Cora.
     *
     * @since  1.0.0
     *
     * @param  WC_Order $order Order data.
     *
     * @return bool           Fail or success.
     */
    protected function generate_billet($order) {

        $body = $order->data;
        $body['days_to_pay'] = $this->days_to_pay;

        if (empty($_POST['billing_cpf']) && empty($_POST['billing_cnpj']) && empty($_POST['billing_document'])) {
            $this->add_error('<strong>' . $this->title . ' Cora</strong>: Informe seu CPF ou CNPJ.');
            return false;
        }

        if (isset($_POST['billing_cpf']) & !empty($_POST['billing_cpf'])) {
            $body['billing']['document_number'] = str_replace(array('-', '.'), '', sanitize_text_field($_POST['billing_cpf']));
        } else if (isset($_POST['billing_cnpj']) && !empty($_POST['billing_cnpj'])) {
            $body['billing']['document_number'] = str_replace(array('-', '.'), '', sanitize_text_field($_POST['billing_cnpj']));
        } else if (isset($_POST['billing_document']) && !empty($_POST['billing_document'])) {
            $body['billing']['document_number'] = str_replace(array('-', '.'), '', sanitize_text_field($_POST['billing_document']));
        } else {
            if ('yes' == $this->debug) {
                $this->log->add($this->id, 'Campo CPF/CNPJ não encontrado.');
            }
            $this->log->add($this->id, 'Informe o seu CPF ou CNPJ.');

            return false;
        }

        if ('yes' == $this->debug) {
            $this->log->add($this->id, 'Criação do Boleto Cora para o pedido ' . $order->get_order_number() . ' com os seguintes dados: ' . print_r($body, true));
        }

        $params = array(
            'method' => 'POST',
            'charset' => 'UTF-8',
            'body' => json_encode($body),
            'sslverify' => false,
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $uuid,
                'divoxKey' => $this->get_option('divox_key'),
            )
        );

        $response = wp_remote_post($this->uri_base . 'invoices/add.json', $params);

        if ($response['response']['code'] != 200) {
            $this->flash('Falha ao incluir invoice.', 'error');
            add_action('admin_notices', [$this, 'flash_message']);

            if ('yes' == $this->debug) {
                $this->log->add($this->id, 'Falha ao gerar boleto');
            }
            return false;
        }

        if ($response['response']['code'] = 200) {

            $response_invoice = json_decode($response['body']);

            if (isset($response_invoice->id)) {

                if ('yes' == $this->debug) {
                    $this->log->add($response_invoice->id, 'Boleto Cora gerado com sucesso! ID: ' . $response_invice->id . ' com os seguintes dados: ' . print_r($body, true));
                }

                // Save billet data in order meta.
                add_post_meta($order->id, 'cora_id', $response_invoice->id);
                add_post_meta($order->id, 'cora_url', $response_invoice->url);
                add_post_meta($order->id, 'cora_digitable', $response_invoice->digitable);
                add_post_meta($order->id, 'cora_document', $body['billing']['document_number']);

                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Process the payment and return the result.
     *
     * @since  1.0.0
     *
     * @param  int    $order_id Order ID.
     *
     * @return array            Redirect when has success and display error notices when fail.
     */
    public function process_payment($order_id) {
        // Gets the order data.
        $order = new WC_Order($order_id);

        // Generate the billet.
        $billet = $this->generate_billet($order);

        if ($billet) {
            // Mark as on-hold (we're awaiting the payment).
            $order->update_status('on-hold', __('Aguardando pagamento.', $this->plugin_slug));

            // Reduce stock levels.
            $order->reduce_order_stock();

            // Remove cart.
            $this->woocommerce_instance()->cart->empty_cart();

            // Sets the return url.
            if (version_compare($this->woocommerce_instance()->version, '2.1', '>=')) {
                $url = $order->get_checkout_order_received_url();
            } else {
                $url = add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))));
            }

            // Return thankyou redirect.
            return array(
                'result' => 'success',
                'redirect' => $url
            );
        } else {
            // Added error message.
            $this->add_error('<strong>' . $this->title . '</strong>: ' . __('Ocorreu um erro ao processar seu pagamento, por favor tente novamente. Ou contate nosso suporte técnico em https://cora.divox.com.br.', $this->plugin_slug));

            return array(
                'result' => 'fail'
            );
        }
    }

    /**
     * Adds payment instructions on thankyou page.
     *
     * @since  1.0.0
     *
     * @param  int    $order_id Order ID.
     *
     * @return string           Payment instructions.
     */
    public function thankyou_page($order_id) {

        $url = get_post_meta($order_id, 'cora_url', true);
        $digitable_line = get_post_meta($order_id, 'cora_digitable', true);

        $html = '<div class="woocommerce-message" style="border-radius: 15px!important;background: #fff!important;">';

        if (isset($url) && !empty($url)) {
            $print_billet = sprintf('<a class="button" style="float:none;border-radius:25px;margin: 20px 0 0 0;padding: 10px!important;" href="%s" target="_blank">%s</a>', $url, __('Imprimir Boleto Cora', $this->plugin_slug));
        }
        $html .= '<div style="text-align: center;word-break: break-word;line-height: 35px;">';
        $message = sprintf(__('%sQue ótimo!%s<br>Seu Boleto Cora já foi gerado e agora nós vamos monitorar o pagamento para agilizar o seu pedido!', $this->plugin_slug), '<strong>', '</strong>') . '<br />';
        $message .= __('Você pode pagar agora mesmo utilizando a linha digitável em qualquer banco ou aplicativo:', $this->plugin_slug) . '<br />';
        $html .= apply_filters('woocommerce_cora_thankyou_page_instructions', $message, $order_id);
        $html .= '' . __('<font size="4" style="vertical-align: -webkit-baseline-middle;"><b>') . '&nbsp;' . $digitable_line;
        $html .= '</b></font>';
        $html .= '<br>' . $print_billet . '<br>';
        $html .= '</div>';
        $html .= '</div>';

        echo $html;
    }

    /**
     * Adds payment instructions on customer email.
     *
     * @since  1.0.0
     *
     * @param  WC_Order $order         Order data.
     * @param  bool     $sent_to_admin Sent to admin.
     *
     * @return string                  Payment instructions.
     */
    public function email_instructions($order, $sent_to_admin) {
        if ($sent_to_admin || $order->status !== 'on-hold' || $order->payment_method !== $this->id) {
            return;
        }

        $html = '<h2>' . __('Efetue o Pagamento', $this->plugin_slug) . '</h2>';
        $html .= '<p class="order_details">';
        $message = sprintf(__('%sQue ótimo!%s seu Boleto Cora foi gerado e nós vamos monitorar o pagamento para agilizar o seu pedido!', $this->plugin_slug), '<strong>', '</strong>') . '<br />';
        $message .= __('Você pode pagar agora mesmo utilizando a linha digitável em qualquer banco ou aplicativo.', $this->plugin_slug) . '<br />';

        $url = get_post_meta($order->id, 'cora_url', true);

        $html .= apply_filters('woocommerce_cora_email_instructions', $message, $order);

        if (isset($url) && !empty($url)) {
            $html .= '<br />' . sprintf('<a class="button" href="%s" target="_blank">%s</a>', $url, __('Imprimir Boleto Cora &rarr;', $this->plugin_slug)) . '<br />';
        }

        $html .= '</p>';

        echo $html;
    }

    /**
     * Check API Response.
     *
     * @since  1.0.0
     *
     * @return void
     */
    public function check_webhook_notification() {
		@ob_clean();
        $body = file_get_contents('php://input');
        $jsonBody = json_decode($body, true);

        if ('yes' == $this->debug) {
            $this->log->add($this->id, 'Novo Webhook chamado: ' . print_r($jsonBody, true));
        }

        $invoice_id = $_SERVER['HTTP_WEBHOOK_RESOURCE_ID'];

        if (is_null($invoice_id) OR empty($invoice_id))
            throw new Exception(__('Resource ID not found'));

        $params = array(
            'method' => 'GET',
            'charset' => 'UTF-8',
            'sslverify' => false,
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'divoxKey' => $this->get_option('divox_key'),
            )
        );

        $response = wp_remote_post($this->uri_base . 'invoices/view/' . $invoice_id . '.json', $params);

        if ($response['response']['code'] != 200) {
            add_action('admin_notices', array($this, 'failed_comunicate_api_message'));
            return false;
        }

        $remote_order = json_decode($response['body']);

        if ('yes' == $this->debug) {
            $this->log->add($this->id, 'Resposta cora: ' . print_r($remote_order, true));
        }

        if ('PAID' == $remote_order->status) {
            do_action('woocommerce_cora_webhook_notification', $remote_order);
        }

        return true;
    }

    /**
     * Successful notification.
     *
     * @since  1.0.0
     *
     * @param  array $data $_POST data from the webhook.
     *
     * @return void        Updated the order status to processing.
     */
    public function successful_webhook_notification($data) {

        if ('yes' == $this->debug) {
            $this->log->add($this->id, 'Recebemos a notificação com os seguintes dados: ' . print_r($data, true));
        }

        $order = new WC_Order($data->code);

        if ('yes' == $this->debug) {
            $this->log->add($this->id, 'Atualizando para processar o status do pedido ' . $order->get_order_number());
        }

        update_post_meta($order->id, 'cora_url', $data->payment_options->bank_slip->url);

        // Complete the order.
        if ('PAID' == $data->status && $order->status != 'completed') {
            $data->total_paid = $data->total_paid ? $data->total_paid / 100 : $data->total_paid;
            $total_paid = $data->total_paid;

            // Save billet paid data in order meta.
            update_post_meta($order->id, 'cora_paid_amount', number_format($total_paid, 2, '.', ''));
            update_post_meta($order->id, 'cora_paid_at', date('Y-m-d H:i:s'));

            $order->add_order_note(__('Cora: Pagamento Aprovado.', $this->plugin_slug));
            $order->update_status('processing');
            // $order->payment_complete();

            if ('yes' == $this->debug) {
                $this->log->add($this->id, 'Pagamento aprovado!');
            }

            return true;
        }
        return false;
    }

    /**
     * Gets the admin url.
     *
     * @since  1.0.0
     *
     * @return string
     */
    protected function admin_url() {
        if (version_compare($this->woocommerce_instance()->version, '2.1', '>=')) {
            return admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_cora_gateway');
        }

        return admin_url('admin.php?page=woocommerce_settings&tab=payment_gateways&section=WC_Cora_Gateway');
    }

    /**
     * Adds error message when not configured the client ID.
     *
     * @since  1.0.0.
     *
     * @return string Error Mensage.
     */
    public function client_id_missing_message() {
        echo '<div class="error"><p><strong>' . __('Boleto Cora para woocommerce', $this->plugin_slug) . '</strong>: ' . sprintf(__('Você deve informar seu ID de Cliente Cora. %s', $this->plugin_slug), '<a href="' . $this->admin_url() . '">' . __('Clique aqui para configurar ou abrir sua conta gratuitamente!', $this->plugin_slug) . '</a>') . '</p></div>';
    }

    /**
     * Adds error message when not configured the client secret code.
     *
     * @since  1.0.0
     *
     * @return string Error Mensage.
     */
    public function client_secret_missing_message() {
        echo '<div class="error"><p><strong>' . __('Boleto Cora para woocommerce', $this->plugin_slug) . '</strong>: ' . sprintf(__('Você deve informar sua Chave de Segurança. %s', $this->plugin_slug), '<a href="' . $this->admin_url() . '">' . __('Clique aqui para ativar sua integração!', $this->plugin_slug) . '</a>') . '</p></div>';
    }

    /**
     * Adds error message when not configured the divox key.
     *
     * @since  1.0.0
     *
     * @return string Error Mensage.
     */
    public function divox_key_missing_message() {
        echo '<div class="error"><p><strong>' . __('Boleto Cora para woocommerce', $this->plugin_slug) . '</strong>: ' . sprintf(__('Você deve informar sua Chave Divox. %s', $this->plugin_slug), '<a href="' . $this->admin_url() . '">' . __('Clique aqui para ativar sua integração!', $this->plugin_slug) . '</a>') . '</p></div>';
    }

    /**
     * Adds error message when an unsupported currency is used.
     *
     * @since  1.0.0
     *
     * @return string
     */
    public function currency_not_supported_message() {
        echo '<div class="error"><p><strong>' . __('Boleto Cora para Woocommerce', $this->plugin_slug) . '</strong>: ' . sprintf(__('Currency <code>%s</code> is not supported. Works only with Brazilian Real - BRL.', $this->plugin_slug), get_woocommerce_currency()) . '</p></div>';
    }

    public function flash_message() {
        echo '<div class="' . $this->message['type'] . '"><p><strong>' . __('Boleto Cora para woocommerce', $this->plugin_slug) . '</strong>: ' . $this->message['message'] . '</p></div>';
        $this->message = null;
    }
}