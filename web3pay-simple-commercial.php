<?php
/**
 * Plugin Name: Web3Pay - MetaMask Gateway for WooCommerce
 * Description: Production-ready MVP: MetaMask native-coin payments with live quote, multi-chain, explorer links, RPC+pricing fallback, server verification, thank-you auto-poll, manual confirm, global anti-replay, wrong-network warning, fee buffer, admin dashboard (colorful + sparkline), WP-Cron auto-verify, system status panel, auto-cancel stale orders, and admin email notifications.
 * Version: 1.5.1
 * Author: Shovon
 */

if (!defined('ABSPATH')) exit;

/**
 * NOTE:
 * This is a single-file WooCommerce payment gateway plugin.
 * Install as: wp-content/plugins/web3pay-simple-commercial/web3pay-simple-commercial.php
 */

// ---------- Cron scheduling helpers (global scope for activation/deactivation) ----------
function w3ps_sc_cron_clear() {
  $ts = wp_next_scheduled('w3ps_sc_cron_tick');
  while ($ts) {
    wp_unschedule_event($ts, 'w3ps_sc_cron_tick');
    $ts = wp_next_scheduled('w3ps_sc_cron_tick');
  }
}

function w3ps_sc_cron_schedule($minutes = 5) {
  $minutes = max(1, (int)$minutes);
  w3ps_sc_cron_clear();
  // Uses a custom interval key; ensured via cron_schedules filter below.
  wp_schedule_event(time() + 60, 'w3ps_sc_every_'.$minutes.'min', 'w3ps_sc_cron_tick');
}

// Custom cron intervals
add_filter('cron_schedules', function ($schedules) {
  foreach ([5,10,15,30] as $m) {
    $key = 'w3ps_sc_every_'.$m.'min';
    if (!isset($schedules[$key])) {
      $schedules[$key] = ['interval' => $m * 60, 'display' => "Every {$m} minutes (Web3Pay)"];
    }
  }
  return $schedules;
});

register_activation_hook(__FILE__, function () {
  // Default schedule; actual interval/enabled can be changed in gateway settings.
  w3ps_sc_cron_schedule(5);
});

register_deactivation_hook(__FILE__, function () {
  w3ps_sc_cron_clear();
});

add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) return;

  // ------------------------
  // Helpers
  // ------------------------

  function w3ps_sanitize_hex($h) {
    $h = strtolower(trim((string)$h));
    if ($h === '') return '';
    if (strpos($h, '0x') !== 0) return '';
    if (!preg_match('/^0x[0-9a-f]+$/', $h)) return '';
    return $h;
  }

  function w3ps_rpc_try($rpc_urls, $method, $params) {
    $rpc_urls = is_array($rpc_urls) ? $rpc_urls : [$rpc_urls];
    foreach ($rpc_urls as $rpc_url) {
      $rpc_url = trim((string)$rpc_url);
      if ($rpc_url === '') continue;

      $payload = ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params];
      $res = wp_remote_post($rpc_url, [
        'timeout' => 20,
        'headers' => ['Content-Type'=>'application/json'],
        'body'    => wp_json_encode($payload),
      ]);
      if (is_wp_error($res)) continue;

      $json = json_decode(wp_remote_retrieve_body($res), true);
      if (!is_array($json)) continue;
      if (isset($json['error'])) continue;

      return $json;
    }
    return null;
  }

  function w3ps_hex_to_dec_str($hex) {
    $hex = strtolower((string)$hex);
    if (strpos($hex,'0x')===0) $hex = substr($hex,2);
    if ($hex==='') return '0';
    $dec = '0';
    for ($i=0;$i<strlen($hex);$i++){
      $d = hexdec($hex[$i]);
      if (function_exists('bcmul')) $dec = bcadd(bcmul($dec,'16',0),(string)$d,0);
      else $dec = (string)((int)$dec*16+$d);
    }
    return $dec;
  }

  function w3ps_dec_to_hex_0x($decStr) {
    $decStr = preg_replace('/[^0-9]/','',(string)$decStr);
    if ($decStr==='' || $decStr==='0') return '0x0';
    if (!function_exists('bcdiv')) return '0x'.dechex((int)$decStr);
    $n=$decStr; $hex='';
    while (function_exists('bccomp') && bccomp($n,'0',0)>0) {
      $rem = bcmod($n,'16');
      $hex = dechex((int)$rem).$hex;
      $n = bcdiv($n,'16',0);
    }
    if (!function_exists('bccomp')) {
      return '0x'.dechex((int)$decStr);
    }
    return '0x'.($hex===''?'0':$hex);
  }

  function w3ps_float_to_wei_str($amountFloat) {
    $s = number_format((float)$amountFloat, 18, '.', '');
    $parts = explode('.', $s, 2);
    $whole = preg_replace('/[^0-9]/','',$parts[0] ?? '0');
    $frac  = preg_replace('/[^0-9]/','',$parts[1] ?? '');
    $frac  = str_pad(substr($frac,0,18),18,'0',STR_PAD_RIGHT);
    $wei = ltrim($whole.$frac,'0');
    return $wei===''?'0':$wei;
  }

  function w3ps_format_native_from_wei($weiStr, $maxFrac=8) {
    $weiStr = preg_replace('/[^0-9]/','',(string)$weiStr);
    $weiStr = ltrim($weiStr,'0'); if ($weiStr==='') $weiStr='0';
    if (strlen($weiStr) <= 18) $weiStr = str_pad($weiStr, 19, '0', STR_PAD_LEFT);
    $intPart = substr($weiStr, 0, -18);
    $fracPart = rtrim(substr($weiStr, -18), '0');
    if ($maxFrac>0) $fracPart = substr($fracPart, 0, $maxFrac);
    return $fracPart ? ($intPart.'.'.$fracPart) : $intPart;
  }

  function w3ps_price_coingecko($coingecko_id, $fiat) {
    $coingecko_id = strtolower(trim((string)$coingecko_id));
    $fiat = strtolower(trim((string)$fiat));
    $url = add_query_arg(['ids'=>$coingecko_id,'vs_currencies'=>$fiat], 'https://api.coingecko.com/api/v3/simple/price');
    $res = wp_remote_get($url, ['timeout'=>15]);
    if (is_wp_error($res)) return null;
    $json = json_decode(wp_remote_retrieve_body($res), true);
    $v = $json[$coingecko_id][$fiat] ?? null;
    return is_numeric($v) ? (float)$v : null;
  }

  function w3ps_price_cryptocompare($symbol, $fiat) {
    $symbol = strtoupper(trim((string)$symbol));
    $fiat = strtoupper(trim((string)$fiat));
    $url = add_query_arg(['fsym'=>$symbol,'tsyms'=>$fiat], 'https://min-api.cryptocompare.com/data/price');
    $res = wp_remote_get($url, ['timeout'=>15]);
    if (is_wp_error($res)) return null;
    $json = json_decode(wp_remote_retrieve_body($res), true);
    $v = $json[$fiat] ?? null;
    return is_numeric($v) ? (float)$v : null;
  }

  function w3ps_get_price_with_fallback($coingecko_id, $symbol, $fiat) {
    $cacheKey='w3ps_price_'.md5(strtolower($coingecko_id).'_'.$symbol.'_'.$fiat);
    $cached=get_transient($cacheKey);
    if (is_numeric($cached) && $cached > 0) return (float)$cached;

    $p = w3ps_price_coingecko($coingecko_id, $fiat);
    if (!$p || $p <= 0) $p = w3ps_price_cryptocompare($symbol, $fiat);

    if ($p && $p > 0) {
      set_transient($cacheKey, $p, 60);
      return (float)$p;
    }
    return null;
  }

  function w3ps_default_networks() {
    return [
      [
        'chainId'=>1,'name'=>'Ethereum','symbol'=>'ETH',
        'rpc'=>['https://cloudflare-eth.com'],
        'explorer'=>'https://etherscan.io',
        'merchant'=>'0xYourEthAddress',
        'coingecko_id'=>'ethereum'
      ],
      [
        'chainId'=>56,'name'=>'BSC','symbol'=>'BNB',
        'rpc'=>['https://bsc-dataseed.binance.org','https://bsc-rpc.publicnode.com'],
        'explorer'=>'https://bscscan.com',
        'merchant'=>'0xYourBscAddress',
        'coingecko_id'=>'binancecoin'
      ],
      [
        'chainId'=>137,'name'=>'Polygon','symbol'=>'MATIC',
        'rpc'=>['https://polygon-rpc.com','https://polygon-bor-rpc.publicnode.com'],
        'explorer'=>'https://polygonscan.com',
        'merchant'=>'0xYourPolygonAddress',
        'coingecko_id'=>'matic-network'
      ],
      [
        'chainId'=>43114,'name'=>'Avalanche','symbol'=>'AVAX',
        'rpc'=>['https://api.avax.network/ext/bc/C/rpc','https://avalanche-c-chain-rpc.publicnode.com'],
        'explorer'=>'https://snowtrace.io',
        'merchant'=>'0xYourAvaxAddress',
        'coingecko_id'=>'avalanche-2'
      ],
    ];
  }

  function w3ps_find_network($nets, $chainId) {
    foreach ($nets as $n) {
      if ((int)($n['chainId'] ?? 0) === (int)$chainId) return $n;
    }
    return null;
  }

  function w3ps_explorer_tx_url($net, $txHash) {
    $base = rtrim((string)($net['explorer'] ?? ''), '/');
    if (!$base) return '';
    $txHash = w3ps_sanitize_hex($txHash);
    if (!$txHash) return '';
    return $base . '/tx/' . $txHash;
  }

  // ------------------------
  // Global Anti-Replay
  // ------------------------
  function w3ps_tx_claimed_by_other_order($txHash, $currentOrderId) {
    $txHash = strtolower(w3ps_sanitize_hex($txHash));
    if (!$txHash) return false;

    $orders = wc_get_orders([
      'limit' => 1,
      'return' => 'ids',
      'meta_key' => '_w3ps_tx_hash',
      'meta_value' => $txHash,
      'meta_compare' => '=',
      'status' => array_keys(wc_get_order_statuses()),
    ]);

    if (empty($orders)) return false;
    $foundId = (int)$orders[0];

    return $foundId && $foundId !== (int)$currentOrderId;
  }

  // ------------------------
  // Admin email notifications
  // ------------------------
  function w3ps_sc_notify_admin($subject, $message, $toEmail = '') {
    $to = $toEmail ? $toEmail : get_option('admin_email');
    if (!$to) return;
    @wp_mail($to, $subject, $message);
  }

  // ------------------------
  // Gateway
  // ------------------------
  class WC_Gateway_Web3Pay_Simple_Commercial extends WC_Payment_Gateway {
    public function __construct() {
      $this->id = 'web3pay_simple_commercial';
      $this->method_title = __('Web3Pay (MetaMask) - Simple', 'web3ps');
      $this->method_description = __('Production-ready MVP: native-coin payments with dashboard + cron.', 'web3ps');
      $this->has_fields = true;
      $this->supports = ['products'];

      $this->init_form_fields();
      $this->init_settings();

      $this->title = $this->get_option('title', 'Crypto (MetaMask)');
      $this->description = $this->get_option('description', 'Pay with crypto using MetaMask. We confirm on-chain.');

      add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this,'process_admin_options']);
      add_action('wp_enqueue_scripts', [$this,'enqueue_scripts']);

      add_action('woocommerce_admin_order_data_after_order_details', [$this, 'admin_manual_confirm_box']);
      add_action('admin_post_w3ps_manual_confirm', [$this, 'handle_manual_confirm']);
    }

    public function init_form_fields() {
      $this->form_fields = [
        'enabled' => [
          'title'=>__('Enable/Disable','web3ps'),
          'type'=>'checkbox',
          'label'=>__('Enable Web3Pay','web3ps'),
          'default'=>'no',
        ],
        'title' => [
          'title'=>__('Title','web3ps'),
          'type'=>'text',
          'default'=>'Crypto (MetaMask)',
        ],
        'description' => [
          'title'=>__('Description','web3ps'),
          'type'=>'textarea',
          'default'=>'Pay with crypto using MetaMask. We confirm on-chain.',
        ],
        'fiat_currency' => [
          'title'=>__('Fiat Currency','web3ps'),
          'type'=>'text',
          'default'=>get_woocommerce_currency(),
          'description'=>__('Used for quote conversion (USD/EUR etc). Default: Woo currency.','web3ps'),
        ],
        'fee_buffer_percent' => [
          'title'=>__('Fee Buffer %','web3ps'),
          'type'=>'number',
          'default'=>0.5,
          'description'=>__('Adds a small buffer to the quoted crypto amount (e.g. 0.5) to reduce volatility/underpay risk.', 'web3ps'),
          'custom_attributes' => ['step'=>'0.1','min'=>'0','max'=>'10'],
        ],
        'quote_ttl' => [
          'title'=>__('Quote TTL (seconds)','web3ps'),
          'type'=>'number',
          'default'=>600,
        ],

        // Cron / auto-verify settings
        'cron_enabled' => [
          'title'=>__('Auto-Verify via WP-Cron','web3ps'),
          'type'=>'checkbox',
          'label'=>__('Enable background auto-verification', 'web3ps'),
          'default'=>'yes',
          'description'=>__('Verifies pending crypto orders every few minutes even if customer closes the browser.', 'web3ps'),
        ],
        'cron_interval_minutes' => [
          'title'=>__('Auto-Verify Interval (minutes)','web3ps'),
          'type'=>'select',
          'default'=>'5',
          'options'=>[
            '5'=>'5',
            '10'=>'10',
            '15'=>'15',
            '30'=>'30',
          ],
        ],
        'stale_minutes' => [
          'title'=>__('Auto-cancel stale pending orders (minutes)','web3ps'),
          'type'=>'number',
          'default'=>30,
          'custom_attributes'=>['min'=>'0','step'=>'1'],
          'description'=>__('If pending longer than this, order will be cancelled (0 disables).', 'web3ps'),
        ],
        'cancel_policy' => [
          'title'=>__('Cancel Policy','web3ps'),
          'type'=>'select',
          'default'=>'no_tx_only',
          'options'=>[
            'no_tx_only'=>__('Cancel only if NO tx hash is stored', 'web3ps'),
            'always'=>__('Cancel even if tx hash exists (still pending)', 'web3ps'),
          ],
        ],

        // Admin email notifications
        'admin_email_to' => [
          'title'=>__('Admin email to notify','web3ps'),
          'type'=>'text',
          'default'=>get_option('admin_email'),
          'description'=>__('Leave blank to use the WordPress admin email.', 'web3ps'),
        ],
        'email_on_confirmed' => [
          'title'=>__('Email on confirmed','web3ps'),
          'type'=>'checkbox',
          'label'=>__('Send admin email when a payment is confirmed', 'web3ps'),
          'default'=>'yes',
        ],
        'email_on_failed' => [
          'title'=>__('Email on failed','web3ps'),
          'type'=>'checkbox',
          'label'=>__('Send admin email when a payment fails on-chain', 'web3ps'),
          'default'=>'yes',
        ],

        'networks' => [
          'title'=>__('Networks (simple JSON)','web3ps'),
          'type'=>'textarea',
          'default'=>wp_json_encode(w3ps_default_networks(), JSON_PRETTY_PRINT),
          'description'=>__('Edit merchant addresses. RPC can be a string or an array. Add "explorer" for links.', 'web3ps'),
        ],
      ];
    }

    public function process_admin_options() {
      $ok = parent::process_admin_options();

      // Update cron schedule based on settings
      $enabled = $this->get_option('cron_enabled', 'yes') === 'yes';
      $mins = (int)$this->get_option('cron_interval_minutes', 5);
      $mins = in_array($mins, [5,10,15,30], true) ? $mins : 5;

      if ($enabled) {
        w3ps_sc_cron_schedule($mins);
      } else {
        w3ps_sc_cron_clear();
      }

      return $ok;
    }

    public function enqueue_scripts() {
      if (!function_exists('is_checkout') || !is_checkout()) return;

      // IMPORTANT: Use a real registered handle (jquery) for inline script.
      // Some WP setups will not print inline scripts attached to an empty-src handle.
      wp_enqueue_script('jquery');

      $cfg = [
        'restUrl' => esc_url_raw(rest_url('web3ps/v1')),
        'nonce'   => wp_create_nonce('wp_rest'),
        'networks'=> $this->get_networks(),
      ];

      wp_add_inline_script('jquery', $this->checkout_js($cfg));
    }

    public function get_networks() {
      $n = json_decode($this->get_option('networks','[]'), true);
      if (!is_array($n) || empty($n)) $n = w3ps_default_networks();

      $out=[];
      foreach($n as $x){
        if (empty($x['chainId']) || empty($x['name']) || empty($x['symbol']) || empty($x['merchant']) || empty($x['coingecko_id'])) continue;

        $rpc = $x['rpc'] ?? '';
        if (is_string($rpc) && $rpc !== '') $rpc = [$rpc];
        if (!is_array($rpc) || empty($rpc)) continue;

        $out[] = [
          'chainId'=>(int)$x['chainId'],
          'name'=>sanitize_text_field($x['name']),
          'symbol'=>sanitize_text_field($x['symbol']),
          'merchant'=>sanitize_text_field($x['merchant']),
          'rpc'=>array_values(array_map('esc_url_raw', $rpc)),
          'explorer'=>esc_url_raw($x['explorer'] ?? ''),
          'coingecko_id'=>sanitize_text_field($x['coingecko_id']),
        ];
      }
      return $out;
    }

    public function payment_fields() {
      echo '<div id="w3ps-box" style="margin:10px 0">';
      echo '<p>'.esc_html($this->description).'</p>';
      echo '<div id="w3ps-warning" style="display:none;margin:10px 0;padding:10px;border:1px solid #f0c36d;background:#fff7e6;border-radius:8px;color:#6b4b00;font-weight:600"></div>';
      echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
      echo '  <button type="button" class="button" id="w3ps-connect">Connect MetaMask</button>';
      echo '  <select id="w3ps-network" style="min-width:240px"></select>';
      echo '  <button type="button" class="button alt" id="w3ps-quote" disabled>Get Quote</button>';
      echo '  <button type="button" class="button alt" id="w3ps-pay" disabled>Pay Now</button>';
      echo '</div>';
      echo '<div id="w3ps-status" style="margin-top:8px"></div>';
      echo '<div id="w3ps-quote-box" style="margin-top:8px"></div>';
      echo '<div id="w3ps-explorer" style="margin-top:8px"></div>';
      echo '<input type="hidden" id="w3ps_payload" name="w3ps_payload" value="" />';
      echo '</div>';
    }

    public function validate_fields() {
      if (empty($_POST['w3ps_payload'])) {
        wc_add_notice(__('Please connect MetaMask, get a quote, and pay before placing the order.', 'web3ps'), 'error');
        return false;
      }

      $payload = json_decode(wp_unslash($_POST['w3ps_payload']), true);
      $txHash = isset($payload['txHash']) ? strtolower(trim($payload['txHash'])) : '';
      if ($txHash && w3ps_tx_claimed_by_other_order($txHash, 0)) {
        wc_add_notice(__('This transaction hash has already been used for another order. Please send a new payment.', 'web3ps'), 'error');
        return false;
      }

      return true;
    }

    public function process_payment($order_id) {
      $order = wc_get_order($order_id);
      $order->update_status('on-hold', __('Awaiting crypto confirmation.', 'web3ps'));

      $payload_raw = wp_unslash($_POST['w3ps_payload']);
      $payload = json_decode($payload_raw, true);

      if (is_array($payload)) {
        $txHash = strtolower(sanitize_text_field($payload['txHash'] ?? ''));

        if ($txHash && w3ps_tx_claimed_by_other_order($txHash, $order_id)) {
          wc_add_notice(__('This transaction hash is already linked to another order. Please send a new payment.', 'web3ps'), 'error');
          return ['result'=>'failure'];
        }

        $order->update_meta_data('_w3ps_chain_id', (int)($payload['chainId'] ?? 0));
        $order->update_meta_data('_w3ps_expected_wei', sanitize_text_field($payload['expectedWei'] ?? ''));
        $order->update_meta_data('_w3ps_merchant', sanitize_text_field($payload['merchant'] ?? ''));
        $order->update_meta_data('_w3ps_tx_hash', $txHash);
        $order->update_meta_data('_w3ps_from', sanitize_text_field($payload['from'] ?? ''));
        $order->update_meta_data('_w3ps_quote_id', sanitize_text_field($payload['quoteId'] ?? ''));
        $order->update_meta_data('_w3ps_status', 'pending');
        $order->save();
      }

      return ['result'=>'success','redirect'=>$this->get_return_url($order)];
    }

    public function admin_manual_confirm_box($order) {
      if (!$order instanceof WC_Order) return;
      if ($order->get_payment_method() !== $this->id) return;

      $tx = $order->get_meta('_w3ps_tx_hash');
      $status = $order->get_meta('_w3ps_status');

      echo '<div class="order_data_column">';
      echo '<h4>Web3Pay</h4>';
      echo '<p><strong>Status:</strong> '.esc_html($status ?: 'n/a').'</p>';
      echo '<p><strong>Tx:</strong> <span style="font-family:monospace">'.esc_html($tx ?: 'n/a').'</span></p>';

      $url = wp_nonce_url(
        admin_url('admin-post.php?action=w3ps_manual_confirm&order_id='.$order->get_id()),
        'w3ps_manual_confirm_'.$order->get_id()
      );

      echo '<p><a class="button" href="'.esc_url($url).'">Manual Confirm (verify now)</a></p>';
      echo '</div>';
    }

    public function handle_manual_confirm() {
      if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
      $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
      if (!$order_id) wp_die('Missing order');

      check_admin_referer('w3ps_manual_confirm_'.$order_id);

      $order = wc_get_order($order_id);
      if (!$order) wp_die('Order not found');

      $result = w3ps_verify_order_tx($order, $this->get_networks(), $this);

      if (!$result['ok']) {
        $order->add_order_note('Web3Pay manual confirm: '.$result['error']);
        $order->save();
      }

      wp_safe_redirect(get_edit_post_link($order_id, ''));
      exit;
    }

    private function checkout_js($cfg) {
      $cfgJson = wp_json_encode($cfg);
      return <<<JS
(function(){
  const CFG = $cfgJson;

  // Avoid double-binding when WooCommerce refreshes checkout fragments
  if (window.__w3ps_sc_bound) return;
  window.__w3ps_sc_bound = true;

  function ready(fn){
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function init(){
    const box = document.getElementById('w3ps-box');
    const connectBtn = document.getElementById('w3ps-connect');
    const netSel = document.getElementById('w3ps-network');
    const quoteBtn = document.getElementById('w3ps-quote');
    const payBtn = document.getElementById('w3ps-pay');
    const warningEl = document.getElementById('w3ps-warning');
    const statusEl = document.getElementById('w3ps-status');
    const quoteBox = document.getElementById('w3ps-quote-box');
    const explorerBox = document.getElementById('w3ps-explorer');
    const payloadEl = document.getElementById('w3ps_payload');

    // Payment fields may be injected after load; if not present yet, wait.
    if(!box || !connectBtn || !netSel || !quoteBtn || !payBtn || !warningEl || !statusEl || !quoteBox || !explorerBox || !payloadEl){
      return false;
    }

    const $ = (id)=>document.getElementById(id);

    let eth=null, account=null, chainId=null, quote=null;

    function setStatus(m){ statusEl.textContent=m || ''; }
    function setWarning(m){
      if(!m){ warningEl.style.display='none'; warningEl.textContent=''; return; }
      warningEl.style.display='block'; warningEl.textContent=m;
    }
    function setQuoteHtml(h){ quoteBox.innerHTML=h || ''; }
    function setExplorerHtml(h){ explorerBox.innerHTML=h || ''; }

    function populateNetworks(){
      netSel.innerHTML='';
      (CFG.networks||[]).forEach(n=>{
        const o=document.createElement('option');
        o.value=String(n.chainId);
        o.textContent=n.name+' ('+n.symbol+')';
        netSel.appendChild(o);
      });
    }

    function networkById(id){
      return (CFG.networks||[]).find(n=>parseInt(n.chainId,10)===parseInt(id,10)) || null;
    }

    async function ensure(){
      if(!window.ethereum) throw new Error('MetaMask not detected. Please install MetaMask and refresh.');
      eth=window.ethereum; return eth;
    }

    async function refreshChain(){
      if(!eth) return;
      const ch=await eth.request({method:'eth_chainId'});
      chainId=parseInt(ch,16);
      checkWrongNetwork();
    }

    function checkWrongNetwork(){
      const target=parseInt(netSel.value,10);
      if(!chainId || chainId!==target){
        const net = networkById(target);
        setWarning('Wrong network in MetaMask. Please switch to '+(net?net.name:('chainId '+target))+' then retry.');
        quoteBtn.disabled=true;
        payBtn.disabled=true;
        return true;
      }
      setWarning('');
      quoteBtn.disabled = !account;
      payBtn.disabled = !quote;
      return false;
    }

    async function connect(){
      const p=await ensure();
      const acc=await p.request({method:'eth_requestAccounts'});
      account=acc && acc[0] ? acc[0] : null;
      await refreshChain();
      setStatus('Connected: '+account);
      checkWrongNetwork();
    }

    async function switchChain(target){
      const hex='0x'+target.toString(16);
      await eth.request({method:'wallet_switchEthereumChain',params:[{chainId:hex}]});
      chainId=target;
      checkWrongNetwork();
    }

    async function post(path, body){
      const res = await fetch(CFG.restUrl+path,{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-WP-Nonce':CFG.nonce},
        body:JSON.stringify(body)
      });
      const json=await res.json().catch(()=> ({}));
      if(!res.ok) throw new Error(json?.error || 'Request failed');
      return json;
    }

    async function getQuote(){
      if(!account) throw new Error('Connect MetaMask first.');
      await ensure();
      await refreshChain();

      const target=parseInt(netSel.value,10);
      if(chainId!==target){
        setWarning('Wrong network in MetaMask. Please switch networks to continue.');
        try{ await switchChain(target); }
        catch(e){ throw new Error('Please switch MetaMask network to the selected chain, then click Get Quote again.'); }
      }

      setStatus('Requesting quote...');
      setQuoteHtml('');
      setExplorerHtml('');
      quote = await post('/quote',{chainId:target});
      setStatus('Quote ready. Expires in '+quote.ttlSeconds+'s');
      setQuoteHtml('<div><strong>Pay:</strong> '+quote.displayAmount+' '+quote.symbol+'<br><small>To: '+quote.merchant+'</small></div>');
      if(quote.explorer && quote.merchant){
        setExplorerHtml('<a href="'+quote.explorer+'/address/'+quote.merchant+'" target="_blank" rel="noopener">View merchant address on explorer</a>');
      }
      payBtn.disabled=false;
    }

    async function pay(){
      if(!quote) throw new Error('Get a quote first.');
      await ensure();
      await refreshChain();

      const target=parseInt(netSel.value,10);
      if(chainId!==target){
        setWarning('Wrong network in MetaMask. Please switch networks to continue.');
        try{ await switchChain(target); }
        catch(e){ throw new Error('Please switch MetaMask network to the selected chain, then click Pay Now again.'); }
      }

      setStatus('Sending transaction...');
      const txHash = await eth.request({
        method:'eth_sendTransaction',
        params:[{from:account,to:quote.merchant,value:quote.expectedWei}]
      });
      setStatus('Tx sent: '+txHash+' — place order to confirm.');
      payloadEl.value = JSON.stringify({
        quoteId: quote.quoteId,
        chainId: target,
        merchant: quote.merchant,
        expectedWei: quote.expectedWei,
        from: account,
        txHash: txHash
      });

      if(quote.explorer){
        setExplorerHtml('<a href="'+quote.explorer+'/tx/'+txHash+'" target="_blank" rel="noopener">View transaction on explorer</a>');
      }
    }

    // Bind once
    populateNetworks();

    connectBtn.addEventListener('click', async()=>{ try{await connect();}catch(e){setStatus(e.message||'Connect failed');} });

    netSel.addEventListener('change', ()=>{ quote=null; checkWrongNetwork(); setQuoteHtml(''); setExplorerHtml(''); payloadEl.value=''; });

    quoteBtn.addEventListener('click', async()=>{ try{await getQuote();}catch(e){setStatus(e.message||'Quote failed');} });

    payBtn.addEventListener('click', async()=>{ try{await pay();}catch(e){setStatus(e.message||'Payment failed');} });

    // React to MetaMask network/account changes
    try{
      ensure().then(()=>{
        eth.on && eth.on('chainChanged', ()=>{ chainId=null; quote=null; refreshChain(); });
        eth.on && eth.on('accountsChanged', (acc)=>{ account = acc && acc[0] ? acc[0] : null; quote=null; setStatus(account?('Connected: '+account):'Wallet disconnected'); checkWrongNetwork(); });
      }).catch(()=>{});
    }catch(_e){}

    // Initial state
    setStatus('Ready. Connect MetaMask to begin.');
    quoteBtn.disabled=true;
    payBtn.disabled=true;
    return true;
  }

  function tryInitLoop(){
    let attempts=0;
    const timer=setInterval(()=>{
      attempts++;
      const ok = init();
      if(ok || attempts>30) clearInterval(timer);
    }, 300);
  }

  ready(()=>{
    tryInitLoop();
    // If classic checkout updates fragments, try again.
    if (window.jQuery) {
      window.jQuery(document.body).on('updated_checkout', function(){ window.__w3ps_sc_bound=false; tryInitLoop(); });
    }
  });
})();
JS;
    }
  }

  // ------------------------
  // Dashboard helpers (color UI + sparkline + system status)
  // ------------------------

  function w3ps_admin_get_date_range() {
    $range = isset($_GET['w3ps_range']) ? sanitize_text_field($_GET['w3ps_range']) : '30d';
    $end = current_time('timestamp');

    if ($range === '7d')  $start = $end - 7 * DAY_IN_SECONDS;
    elseif ($range === '90d') $start = $end - 90 * DAY_IN_SECONDS;
    elseif ($range === 'today') $start = strtotime('today', $end);
    elseif ($range === 'custom') {
      $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
      $to   = isset($_GET['to']) ? sanitize_text_field($_GET['to']) : '';
      $start = $from ? strtotime($from.' 00:00:00') : ($end - 30 * DAY_IN_SECONDS);
      $end   = $to ? strtotime($to.' 23:59:59') : $end;
    } else {
      $start = $end - 30 * DAY_IN_SECONDS;
      $range = '30d';
    }

    if (!$start || $start > $end) $start = $end - 30 * DAY_IN_SECONDS;

    return [$range, $start, $end];
  }

  function w3ps_admin_query_orders($payment_method_id, $start_ts, $end_ts, $limit = 200) {
    $start = gmdate('Y-m-d H:i:s', $start_ts);
    $end   = gmdate('Y-m-d H:i:s', $end_ts);

    return wc_get_orders([
      'limit'        => $limit,
      'orderby'      => 'date',
      'order'        => 'DESC',
      'payment_method' => $payment_method_id,
      'status'       => array_keys(wc_get_order_statuses()),
      'date_created' => $start . '...' . $end,
      'return'       => 'objects',
    ]);
  }

  function w3ps_admin_kpis($orders) {
    $counts = ['confirmed'=>0,'pending'=>0,'failed'=>0,'other'=>0];
    $gross = 0.0;
    $confirmed_gross = 0.0;
    $confirmed_count = 0;

    foreach ($orders as $o) {
      $st = (string)$o->get_meta('_w3ps_status');
      if (!isset($counts[$st])) $counts['other']++;
      else $counts[$st]++;

      $gross += (float)$o->get_total();

      if ($st === 'confirmed') {
        $confirmed_gross += (float)$o->get_total();
        $confirmed_count++;
      }
    }

    $aov = $confirmed_count > 0 ? ($confirmed_gross / $confirmed_count) : 0.0;
    return [$counts, $gross, $confirmed_gross, $aov];
  }

  function w3ps_admin_render_kpi_box($label, $value, $sub = '', $accent = '#2563eb', $icon = 'chart-area') {
    echo '<div style="
      flex:1;min-width:190px;background:#fff;
      border:1px solid #e5e5e5;border-radius:14px;
      padding:14px 14px 12px 14px;
      box-shadow:0 2px 10px rgba(0,0,0,.05);
      border-left:6px solid '.esc_attr($accent).';
    ">';
    echo '<div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">';
    echo '  <span class="dashicons dashicons-'.esc_attr($icon).'" style="
              width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;
              border-radius:10px;
              background:'.esc_attr($accent).'15;
              color:'.esc_attr($accent).';
              font-size:18px;
          "></span>';
    echo '  <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.03em;font-weight:700">'.esc_html($label).'</div>';
    echo '</div>';
    echo '<div style="font-size:26px;font-weight:900;line-height:1.1;color:#111">'.wp_kses_post($value).'</div>';
    if ($sub) echo '<div style="font-size:12px;color:#666;margin-top:6px">'.wp_kses_post($sub).'</div>';
    echo '</div>';
  }

  function w3ps_admin_status_badge($st) {
    $st = strtolower((string)$st);
    $map = [
      'confirmed' => ['#16a34a', 'yes-alt', 'Confirmed'],
      'pending'   => ['#f59e0b', 'clock',   'Pending'],
      'failed'    => ['#dc2626', 'dismiss', 'Failed'],
    ];

    if (!isset($map[$st])) {
      $label = $st ?: 'n/a';
      return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;background:#f3f4f6;color:#111">
        <span class="dashicons dashicons-info" style="font-size:14px;line-height:14px"></span> '.esc_html($label).'</span>';
    }

    list($c,$icon,$nice) = $map[$st];

    return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;background:'.$c.'15;color:'.$c.';border:1px solid '.$c.'35">
      <span class="dashicons dashicons-'.$icon.'" style="font-size:14px;line-height:14px"></span> '.esc_html($nice).'</span>';
  }

  function w3ps_admin_network_symbol_by_chain($nets, $chainId) {
    $net = w3ps_find_network($nets, (int)$chainId);
    return $net ? (string)($net['symbol'] ?? 'NATIVE') : 'NATIVE';
  }

  function w3ps_admin_expected_amount_display($expectedWeiHex, $symbol) {
    $expectedWeiHex = w3ps_sanitize_hex($expectedWeiHex);
    if (!$expectedWeiHex) return '—';
    $weiDec = w3ps_hex_to_dec_str($expectedWeiHex);
    $display = w3ps_format_native_from_wei($weiDec, 8);
    return $display . ' ' . $symbol;
  }

  function w3ps_admin_confirmed_sales_series($orders, $days = 14) {
    $end = current_time('timestamp');
    $start = $end - ($days - 1) * DAY_IN_SECONDS;

    $buckets = [];
    for ($i=0; $i<$days; $i++) {
      $ts = $start + $i*DAY_IN_SECONDS;
      $key = date_i18n('Y-m-d', $ts);
      $buckets[$key] = 0.0;
    }

    foreach ($orders as $o) {
      $st = (string)$o->get_meta('_w3ps_status');
      if ($st !== 'confirmed') continue;
      $dt = $o->get_date_created();
      if (!$dt) continue;
      $ts = $dt->getTimestamp();
      if ($ts < $start || $ts > $end) continue;
      $key = date_i18n('Y-m-d', $ts);
      if (!isset($buckets[$key])) continue;
      $buckets[$key] += (float)$o->get_total();
    }

    $series = [];
    foreach ($buckets as $k=>$v) $series[] = ['label'=>substr($k,5), 'value'=>$v];
    return $series;
  }

  function w3ps_admin_render_sparkline($series, $currency_symbol) {
    $max = 0.0;
    foreach ($series as $p) $max = max($max, (float)$p['value']);
    if ($max <= 0) $max = 1.0;

    echo '<div style="background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:14px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin:12px 0">';
    echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px">';
    echo '<div>';
    echo '<div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.03em;font-weight:800">Confirmed sales trend</div>';
    echo '<div style="font-size:14px;color:#111;font-weight:700;margin-top:4px">Last '.count($series).' days</div>';
    echo '</div>';
    echo '<div style="font-size:12px;color:#666">Hover bars</div>';
    echo '</div>';

    echo '<div style="display:flex;gap:6px;align-items:flex-end;height:64px;margin-top:12px;padding:10px;border-radius:12px;background:linear-gradient(180deg,#ffffff,#fbfbff);border:1px solid #eef2ff;overflow:hidden">';
    foreach ($series as $p) {
      $v = (float)$p['value'];
      $h = (int)round(($v / $max) * 54);
      $h = max(2, $h);
      $title = esc_attr($p['label'].' • '.$currency_symbol.number_format($v,2));
      echo '<div title="'.$title.'" style="flex:1;min-width:6px;border-radius:8px 8px 2px 2px;background:#2563eb;opacity:.85;height:'.$h.'px"></div>';
    }
    echo '</div>';
    echo '<div style="display:flex;justify-content:space-between;margin-top:8px;color:#666;font-size:12px">';
    echo '<span>'.esc_html($series[0]['label']).'</span>';
    echo '<span>'.esc_html($series[count($series)-1]['label']).'</span>';
    echo '</div>';
    echo '</div>';
  }

  function w3ps_admin_system_status_panel($gw, $nets) {
    $fiat = strtoupper(trim((string)$gw->get_option('fiat_currency', get_woocommerce_currency())));
    $lastRun = (int)get_option('w3ps_sc_last_cron_run', 0);
    $lastOk  = (string)get_option('w3ps_sc_last_cron_ok', '');
    $lastMsg = (string)get_option('w3ps_sc_last_cron_msg', '');

    // Pending / stale counts (lightweight)
    $pendingIds = wc_get_orders([
      'limit'=>50,
      'return'=>'ids',
      'payment_method'=>$gw->id,
      'status'=>array_keys(wc_get_order_statuses()),
      'meta_key'=>'_w3ps_status',
      'meta_value'=>'pending',
      'meta_compare'=>'=',
    ]);
    $pendingCount = is_array($pendingIds) ? count($pendingIds) : 0;

    $staleMin = (int)$gw->get_option('stale_minutes', 30);
    $staleCount = 0;
    if ($staleMin > 0 && $pendingIds) {
      foreach ($pendingIds as $oid) {
        $o = wc_get_order($oid);
        if (!$o) continue;
        $ts = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
        if ($ts && (time() - $ts) > ($staleMin * 60)) $staleCount++;
      }
    }

    // RPC health (quick chainId check)
    $rpcRows = [];
    foreach ($nets as $n) {
      $t0 = microtime(true);
      $ok = false;
      $rpc = $n['rpc'] ?? [];
      $res = w3ps_rpc_try($rpc, 'eth_chainId', []);
      if ($res && !empty($res['result'])) $ok = true;
      $ms = (int)round((microtime(true) - $t0) * 1000);
      $rpcRows[] = [
        'name' => $n['name'].' ('.$n['chainId'].')',
        'ok' => $ok,
        'ms' => $ms,
      ];
    }

    // Pricing health (use first network)
    $pricingOk = false;
    $pricingLabel = 'Unavailable';
    if (!empty($nets[0])) {
      $p = w3ps_get_price_with_fallback($nets[0]['coingecko_id'], $nets[0]['symbol'], $fiat);
      if ($p && $p > 0) {
        $pricingOk = true;
        $pricingLabel = $nets[0]['symbol'].'/'.strtoupper($fiat).' = '.number_format($p, 2);
      }
    }

    $pill = function($ok, $text, $colorOk='#16a34a', $colorBad='#dc2626', $iconOk='yes-alt', $iconBad='dismiss'){
      $c = $ok ? $colorOk : $colorBad;
      $icon = $ok ? $iconOk : $iconBad;
      return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;background:'.$c.'15;color:'.$c.';border:1px solid '.$c.'35">
        <span class="dashicons dashicons-'.$icon.'" style="font-size:14px;line-height:14px"></span> '.esc_html($text).'</span>';
    };

    echo '<div style="background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:14px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin:12px 0">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">';
    echo '<div style="display:flex;align-items:center;gap:10px">';
    echo '<span class="dashicons dashicons-admin-tools" style="font-size:20px;color:#111"></span>';
    echo '<div style="font-weight:900;font-size:14px;color:#111">System Status</div>';
    echo '</div>';

    $cronEnabled = ($gw->get_option('cron_enabled','yes') === 'yes');
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
    echo $pill($cronEnabled, $cronEnabled ? 'Auto-verify ON' : 'Auto-verify OFF', '#2563eb', '#6b7280', 'controls-repeat', 'controls-pause');
    echo $pill($pricingOk, $pricingOk ? 'Pricing OK' : 'Pricing FAIL');
    echo $pill($staleCount === 0, $staleCount ? ('Stale: '.$staleCount) : 'No stale', '#f59e0b', '#f59e0b', 'warning', 'warning');
    echo '</div>';
    echo '</div>';

    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">';
    // Cron last run
    $lr = $lastRun ? date_i18n('Y-m-d H:i', $lastRun) : 'Never';
    $cronPill = $pill($lastOk === '1', $lastOk === '1' ? 'Last run OK' : ($lastRun ? 'Last run issues' : 'Not run yet'));
    echo '<div style="flex:1;min-width:260px;border:1px solid #eef2ff;background:#fbfbff;border-radius:12px;padding:12px">';
    echo '<div style="font-size:12px;color:#666;font-weight:800;text-transform:uppercase;letter-spacing:.03em">Cron</div>';
    echo '<div style="margin-top:6px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
    echo $cronPill;
    echo '<span style="color:#111;font-weight:700">Last:</span> <span style="color:#111">'.esc_html($lr).'</span>';
    echo '<span style="color:#111;font-weight:700">Pending:</span> <span style="color:#111">'.esc_html((string)$pendingCount).'</span>';
    echo '</div>';
    if ($lastMsg) {
      echo '<div style="margin-top:8px;color:#666;font-size:12px">'.esc_html($lastMsg).'</div>';
    }
    echo '</div>';

    // RPC summary
    echo '<div style="flex:2;min-width:340px;border:1px solid #eef2ff;background:#fbfbff;border-radius:12px;padding:12px">';
    echo '<div style="font-size:12px;color:#666;font-weight:800;text-transform:uppercase;letter-spacing:.03em">RPC Health</div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">';
    foreach ($rpcRows as $r) {
      $ok = $r['ok'];
      echo '<div style="display:flex;align-items:center;gap:8px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:6px 10px">';
      echo '<span style="width:8px;height:8px;border-radius:999px;background:'.($ok?'#16a34a':'#dc2626').';display:inline-block"></span>';
      echo '<span style="font-size:12px;font-weight:800;color:#111">'.esc_html($r['name']).'</span>';
      echo '<span style="font-size:12px;color:#666">'.esc_html($ok ? ($r['ms'].'ms') : 'down').'</span>';
      echo '</div>';
    }
    echo '</div>';
    echo '<div style="margin-top:8px;color:#666;font-size:12px">Pricing: '.esc_html($pricingLabel).'</div>';
    echo '</div>';
    echo '</div>';

    echo '</div>';
  }

  // ------------------------
  // Dashboard page
  // ------------------------
  add_action('admin_menu', function () {
    add_submenu_page(
      'woocommerce',
      'Web3Pay Dashboard',
      'Web3Pay Dashboard',
      'manage_woocommerce',
      'w3ps-dashboard',
      'w3ps_admin_dashboard_page'
    );
  });

  function w3ps_admin_dashboard_page() {
    if (!current_user_can('manage_woocommerce')) return;

    $gw = new WC_Gateway_Web3Pay_Simple_Commercial();
    $nets = $gw->get_networks();
    $gateway_id = $gw->id;

    list($range, $start_ts, $end_ts) = w3ps_admin_get_date_range();

    $orders = w3ps_admin_query_orders($gateway_id, $start_ts, $end_ts, 500);
    list($counts, $gross, $confirmed_gross, $aov) = w3ps_admin_kpis($orders);

    $currency = function_exists('get_woocommerce_currency_symbol')
      ? get_woocommerce_currency_symbol(get_woocommerce_currency())
      : '$';

    $rangeLabel = ($range === '7d') ? 'Last 7 days'
      : (($range === '90d') ? 'Last 90 days'
      : (($range === 'today') ? 'Today'
      : (($range === 'custom') ? 'Custom range' : 'Last 30 days')));

    $export_url = wp_nonce_url(
      admin_url('admin-post.php?action=w3ps_export_csv&w3ps_range='.$range.'&from='.urlencode($_GET['from'] ?? '').'&to='.urlencode($_GET['to'] ?? '')),
      'w3ps_export_csv'
    );

    echo '<div class="wrap">';
    echo '<h1 style="display:flex;gap:10px;align-items:center">Web3Pay Dashboard <span style="font-size:13px;color:#666;font-weight:600">('.esc_html($rangeLabel).')</span></h1>';

    // System Status panel
    w3ps_admin_system_status_panel($gw, $nets);

    echo '<div style="margin:12px 0;padding:12px;background:#fff;border:1px solid #e5e5e5;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.04)">';
    echo '<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">';
    echo '<input type="hidden" name="page" value="w3ps-dashboard" />';
    echo '<label style="font-weight:800">Range<br>';
    echo '<select name="w3ps_range">';
    foreach (['today'=>'Today','7d'=>'7 days','30d'=>'30 days','90d'=>'90 days','custom'=>'Custom'] as $k=>$v) {
      $sel = selected($range, $k, false);
      echo '<option value="'.esc_attr($k).'" '.$sel.'>'.esc_html($v).'</option>';
    }
    echo '</select></label>';

    $fromVal = isset($_GET['from']) ? esc_attr($_GET['from']) : '';
    $toVal   = isset($_GET['to']) ? esc_attr($_GET['to']) : '';

    echo '<label>From (YYYY-MM-DD)<br><input type="text" name="from" value="'.$fromVal.'" placeholder="2026-01-01" /></label>';
    echo '<label>To (YYYY-MM-DD)<br><input type="text" name="to" value="'.$toVal.'" placeholder="2026-01-31" /></label>';
    echo '<button class="button button-primary" type="submit">Apply</button>';
    echo '<a class="button" href="'.esc_url($export_url).'">Export CSV</a>';
    echo '</form>';
    echo '<div style="margin-top:8px;color:#666;font-size:12px">Tip: use Custom if you want exact dates.</div>';
    echo '</div>';

    // Color KPI row
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">';
    w3ps_admin_render_kpi_box('Orders (confirmed)', (string)$counts['confirmed'], '', '#16a34a', 'yes-alt');
    w3ps_admin_render_kpi_box('Orders (pending)',   (string)$counts['pending'],   '', '#f59e0b', 'clock');
    w3ps_admin_render_kpi_box('Orders (failed)',    (string)$counts['failed'],    '', '#dc2626', 'dismiss');
    w3ps_admin_render_kpi_box('Gross sales (all)',  $currency . number_format($gross, 2), 'Store currency', '#2563eb', 'money');
    w3ps_admin_render_kpi_box('Confirmed sales',    $currency . number_format($confirmed_gross, 2), 'Store currency', '#7c3aed', 'awards');
    w3ps_admin_render_kpi_box('AOV (confirmed)',    $currency . number_format($aov, 2), 'Average order value', '#0ea5e9', 'analytics');
    echo '</div>';

    // Sparkline
    $series = w3ps_admin_confirmed_sales_series($orders, 14);
    w3ps_admin_render_sparkline($series, $currency);

    echo '<h2 style="margin-top:18px">Recent Transactions</h2>';
    echo '<div style="background:linear-gradient(180deg,#ffffff, #fbfbff);border:1px solid #e5e5e5;border-radius:14px;overflow:auto;box-shadow:0 2px 10px rgba(0,0,0,.04)">';
    echo '<table class="widefat striped" style="margin:0">';
    echo '<thead><tr>
      <th>Order</th>
      <th>Date</th>
      <th>Status</th>
      <th>Chain</th>
      <th>Expected</th>
      <th>Tx Hash</th>
      <th>Explorer</th>
    </tr></thead><tbody>';

    $rows = 0;
    foreach ($orders as $o) {
      if ($rows >= 25) break;
      $rows++;

      $id = $o->get_id();
      $date = $o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d H:i') : '';
      $st = (string)$o->get_meta('_w3ps_status');
      $chainId = (int)$o->get_meta('_w3ps_chain_id');
      $expectedWeiHex = (string)$o->get_meta('_w3ps_expected_wei');
      $tx = (string)$o->get_meta('_w3ps_tx_hash');

      $symbol = w3ps_admin_network_symbol_by_chain($nets, $chainId);
      $expectedDisp = w3ps_admin_expected_amount_display($expectedWeiHex, $symbol);

      $net = w3ps_find_network($nets, $chainId);
      $txUrl = $net ? w3ps_explorer_tx_url($net, $tx) : '';

      echo '<tr>';
      echo '<td><a href="'.esc_url(get_edit_post_link($id)).'">#'.esc_html($id).'</a></td>';
      echo '<td>'.esc_html($date).'</td>';
      echo '<td>'.w3ps_admin_status_badge($st).'</td>';
      echo '<td>'.esc_html($chainId).'</td>';
      echo '<td>'.esc_html($expectedDisp).'</td>';
      echo '<td style="font-family:monospace;font-size:12px">'.esc_html($tx ?: '—').'</td>';
      echo '<td>'.($txUrl ? '<a href="'.esc_url($txUrl).'" target="_blank" rel="noopener">View</a>' : '—').'</td>';
      echo '</tr>';
    }

    if ($rows === 0) {
      echo '<tr><td colspan="7" style="text-align:center;padding:18px;color:#666">No Web3Pay orders found for this range.</td></tr>';
    }

    echo '</tbody></table></div>';
    echo '</div>';
  }

  // CSV export handler
  add_action('admin_post_w3ps_export_csv', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    check_admin_referer('w3ps_export_csv');

    $gw = new WC_Gateway_Web3Pay_Simple_Commercial();
    $nets = $gw->get_networks();
    $gateway_id = $gw->id;

    list($range, $start_ts, $end_ts) = w3ps_admin_get_date_range();
    $orders = w3ps_admin_query_orders($gateway_id, $start_ts, $end_ts, 5000);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=web3pay-transactions.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['order_id','date','order_total','currency','w3ps_status','chain_id','expected','tx_hash','payer']);

    foreach ($orders as $o) {
      $id = $o->get_id();
      $date = $o->get_date_created() ? $o->get_date_created()->date('c') : '';
      $total = $o->get_total();
      $curr  = $o->get_currency();

      $st = (string)$o->get_meta('_w3ps_status');
      $chainId = (int)$o->get_meta('_w3ps_chain_id');
      $expectedWeiHex = (string)$o->get_meta('_w3ps_expected_wei');
      $tx = (string)$o->get_meta('_w3ps_tx_hash');
      $payer = (string)$o->get_meta('_w3ps_from');

      $symbol = w3ps_admin_network_symbol_by_chain($nets, $chainId);
      $expectedDisp = w3ps_admin_expected_amount_display($expectedWeiHex, $symbol);

      fputcsv($out, [$id, $date, $total, $curr, $st, $chainId, $expectedDisp, $tx, $payer]);
    }

    fclose($out);
    exit;
  });

  // ------------------------
  // Thank you page polling + explorer link
  // ------------------------
  add_action('woocommerce_thankyou_web3pay_simple_commercial', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $txHash = $order->get_meta('_w3ps_tx_hash');
    $chainId = (int)$order->get_meta('_w3ps_chain_id');
    if (!$txHash || !$chainId) return;

    $gw = new WC_Gateway_Web3Pay_Simple_Commercial();
    $nets = $gw->get_networks();
    $net = w3ps_find_network($nets, $chainId);

    $orderKey = $order->get_order_key();
    $restUrl = esc_url_raw(rest_url('web3ps/v1'));
    $nonce = wp_create_nonce('wp_rest');

    $txUrl = $net ? w3ps_explorer_tx_url($net, $txHash) : '';
    $txLinkHtml = $txUrl ? ('<div style="margin-top:6px"><small><a href="'.esc_url($txUrl).'" target="_blank" rel="noopener">View transaction on explorer</a></small></div>') : '';

    echo '<div style="margin:18px 0;padding:14px;border:1px solid #ddd;border-radius:8px">';
    echo '<h3 style="margin:0 0 8px">Crypto Payment Status</h3>';
    echo '<div id="w3ps-ty-status">Checking confirmation…</div>';
    echo '<div style="margin-top:6px;font-family:monospace;font-size:12px;opacity:.8">'.esc_html($txHash).'</div>';
    echo $txLinkHtml;
    echo '<div id="w3ps-ty-data"
      data-rest="'.esc_attr($restUrl).'"
      data-nonce="'.esc_attr($nonce).'"
      data-oid="'.esc_attr($order_id).'"
      data-ok="'.esc_attr($orderKey).'"
      data-tx="'.esc_attr($txHash).'"
    ></div>';
    echo '</div>';
    ?>
    <script>
    (function(){
      const d=document.getElementById('w3ps-ty-data');
      const s=document.getElementById('w3ps-ty-status');
      if(!d||!s) return;

      const rest=d.dataset.rest, nonce=d.dataset.nonce;
      const orderId=parseInt(d.dataset.oid,10), orderKey=d.dataset.ok, txHash=d.dataset.tx;

      let tries=0, max=90, delay=5000;
      const set=(m)=>{s.textContent=m;};

      async function tick(){
        tries++;
        try{
          const r=await fetch(rest+'/verify',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
            body:JSON.stringify({orderId,orderKey,txHash})
          });
          const j=await r.json();
          if(!r.ok){ set(j?.error ? ('Verification error: '+j.error) : 'Verification error'); return; }
          if(j.status==='confirmed'){ set('✅ Confirmed! Updating order…'); setTimeout(()=>location.reload(),2000); return; }
          if(j.status==='failed'){ set('❌ Failed on-chain. Contact support.'); return; }
          if(tries>=max){ set('⚠️ Still pending. Refresh later if needed.'); return; }
          set('⏳ Waiting for confirmation… ('+tries+'/'+max+')');
        }catch(e){
          if(tries>=max){ set('⚠️ Still pending. Refresh later if needed.'); return; }
          set('⏳ Checking… (temporary network/RPC issue)');
        }
        setTimeout(tick, delay);
      }
      tick();
    })();
    </script>
    <?php
  });

  // ------------------------
  // Verification logic (used by REST + manual confirm + cron)
  // ------------------------
  function w3ps_verify_order_tx($order, $nets, $gw = null) {
    $order_id = $order->get_id();
    $txHash = strtolower((string)$order->get_meta('_w3ps_tx_hash'));
    $txHash = w3ps_sanitize_hex($txHash);

    if (!$txHash) return ['ok'=>false,'error'=>'Missing tx hash'];
    if (w3ps_tx_claimed_by_other_order($txHash, $order_id)) {
      return ['ok'=>false,'error'=>'Tx hash already used by another order'];
    }

    $chainId = (int)$order->get_meta('_w3ps_chain_id');
    $expectedWeiHex = (string)$order->get_meta('_w3ps_expected_wei');

    $net = w3ps_find_network($nets, $chainId);
    if (!$net) return ['ok'=>false,'error'=>'Unsupported network'];
    $merchant = strtolower((string)$net['merchant']);

    $rpcUrls = $net['rpc'] ?? [];
    if (is_string($rpcUrls)) $rpcUrls = [$rpcUrls];

    $rc = w3ps_rpc_try($rpcUrls, 'eth_getTransactionReceipt', [$txHash]);
    if (!$rc || empty($rc['result'])) return ['ok'=>false,'error'=>'Pending (no receipt yet)', 'status'=>'pending'];

    $r = $rc['result'];
    $status = isset($r['status']) ? hexdec($r['status']) : 0;
    if ($status !== 1) {
      $order->update_meta_data('_w3ps_status','failed');
      $order->add_order_note('Web3Pay: Tx failed: '.$txHash);
      $order->save();

      // Email notify (once)
      if ($gw instanceof WC_Gateway_Web3Pay_Simple_Commercial) {
        $to = trim((string)$gw->get_option('admin_email_to', get_option('admin_email')));
        $enabled = ($gw->get_option('email_on_failed','yes') === 'yes');
        $sent = (string)$order->get_meta('_w3ps_notified_failed');
        if ($enabled && $sent !== '1') {
          w3ps_sc_notify_admin(
            '[Web3Pay] Payment FAILED for Order #'.$order_id,
            "Order #{$order_id} payment failed on-chain.\n\nTx: {$txHash}\nNetwork: {$net['name']} ({$chainId})",
            $to
          );
          $order->update_meta_data('_w3ps_notified_failed','1');
          $order->save();
        }
      }

      return ['ok'=>false,'error'=>'Transaction failed', 'status'=>'failed'];
    }

    $tx = w3ps_rpc_try($rpcUrls, 'eth_getTransactionByHash', [$txHash]);
    if (!$tx || empty($tx['result'])) return ['ok'=>false,'error'=>'Tx not found'];

    $t = $tx['result'];
    $to = strtolower($t['to'] ?? '');
    $valueHex = strtolower($t['value'] ?? '0x0');
    if ($to !== $merchant) return ['ok'=>false,'error'=>'Wrong recipient'];

    if ($expectedWeiHex && function_exists('bccomp')) {
      $paid = w3ps_hex_to_dec_str($valueHex);
      $exp  = w3ps_hex_to_dec_str($expectedWeiHex);
      if (function_exists('bccomp') && bccomp($paid,$exp,0)<0) return ['ok'=>false,'error'=>'Underpaid'];
    }

    if ($order->needs_payment()) $order->payment_complete($txHash);

    $order->update_meta_data('_w3ps_status','confirmed');
    $order->update_meta_data('_w3ps_tx_hash', strtolower($txHash));
    $order->update_meta_data('_w3ps_from', sanitize_text_field($t['from']??''));
    $order->add_order_note('Web3Pay: Payment confirmed. Tx: '.$txHash);
    $order->save();

    // Email notify (once)
    if ($gw instanceof WC_Gateway_Web3Pay_Simple_Commercial) {
      $to = trim((string)$gw->get_option('admin_email_to', get_option('admin_email')));
      $enabled = ($gw->get_option('email_on_confirmed','yes') === 'yes');
      $sent = (string)$order->get_meta('_w3ps_notified_confirmed');
      if ($enabled && $sent !== '1') {
        $txUrl = w3ps_explorer_tx_url($net, $txHash);
        $msg = "Order #{$order_id} payment confirmed.\n\nNetwork: {$net['name']} ({$chainId})\nTx: {$txHash}\n";
        if ($txUrl) $msg .= "Explorer: {$txUrl}\n";
        w3ps_sc_notify_admin('[Web3Pay] Payment CONFIRMED for Order #'.$order_id, $msg, $to);
        $order->update_meta_data('_w3ps_notified_confirmed','1');
        $order->save();
      }
    }

    return ['ok'=>true, 'status'=>'confirmed'];
  }

  // ------------------------
  // WP-Cron auto-verify + auto-cancel stale
  // ------------------------
  add_action('w3ps_sc_cron_tick', function () {
    if (!class_exists('WooCommerce')) return;

    $gw = new WC_Gateway_Web3Pay_Simple_Commercial();
    $enabled = ($gw->get_option('cron_enabled','yes') === 'yes');

    if (!$enabled) {
      update_option('w3ps_sc_last_cron_run', time(), false);
      update_option('w3ps_sc_last_cron_ok', '1', false);
      update_option('w3ps_sc_last_cron_msg', 'Cron disabled in settings.', false);
      return;
    }

    // Ensure schedule matches current interval (self-heal)
    $mins = (int)$gw->get_option('cron_interval_minutes', 5);
    $mins = in_array($mins, [5,10,15,30], true) ? $mins : 5;
    $next = wp_next_scheduled('w3ps_sc_cron_tick');
    if (!$next) {
      w3ps_sc_cron_schedule($mins);
    }

    $nets = $gw->get_networks();
    $staleMin = (int)$gw->get_option('stale_minutes', 30);
    $cancelPolicy = (string)$gw->get_option('cancel_policy', 'no_tx_only');

    $checked = 0;
    $confirmed = 0;
    $failed = 0;
    $cancelled = 0;

    try {
      // Get up to 25 pending orders each run to keep it lightweight
      $pendingIds = wc_get_orders([
        'limit' => 25,
        'return' => 'ids',
        'payment_method' => $gw->id,
        'status' => array_keys(wc_get_order_statuses()),
        'meta_key' => '_w3ps_status',
        'meta_value' => 'pending',
        'meta_compare' => '=',
      ]);

      foreach ((array)$pendingIds as $oid) {
        $order = wc_get_order($oid);
        if (!$order) continue;

        $checked++;

        // Auto-cancel stale
        if ($staleMin > 0) {
          $createdTs = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
          if ($createdTs && (time() - $createdTs) > ($staleMin * 60)) {
            $tx = (string)$order->get_meta('_w3ps_tx_hash');
            $hasTx = (bool)w3ps_sanitize_hex($tx);

            $shouldCancel = false;
            if ($cancelPolicy === 'always') $shouldCancel = true;
            if ($cancelPolicy === 'no_tx_only' && !$hasTx) $shouldCancel = true;

            if ($shouldCancel) {
              if ($order->has_status(['pending','on-hold'])) {
                $order->update_status('cancelled', 'Web3Pay: Auto-cancelled stale pending order ('.$staleMin.' min).');
                // Keep internal status consistent for dashboard
                $order->update_meta_data('_w3ps_status', 'failed');
                $order->save();
                $cancelled++;
                continue; // don't verify if cancelled
              }
            }
          }
        }

        // Verify tx if present
        $txHash = (string)$order->get_meta('_w3ps_tx_hash');
        if (!w3ps_sanitize_hex($txHash)) continue;

        $res = w3ps_verify_order_tx($order, $nets, $gw);

        if (!empty($res['status']) && $res['status'] === 'confirmed') $confirmed++;
        if (!empty($res['status']) && $res['status'] === 'failed') $failed++;
      }

      update_option('w3ps_sc_last_cron_run', time(), false);
      update_option('w3ps_sc_last_cron_ok', '1', false);
      update_option('w3ps_sc_last_cron_msg', "Checked {$checked}, confirmed {$confirmed}, failed {$failed}, cancelled {$cancelled}.", false);
    } catch (Exception $e) {
      update_option('w3ps_sc_last_cron_run', time(), false);
      update_option('w3ps_sc_last_cron_ok', '0', false);
      update_option('w3ps_sc_last_cron_msg', 'Cron error: '.$e->getMessage(), false);
    }
  });

  // ------------------------
  // REST: quote + verify
  // ------------------------
  add_action('rest_api_init', function () {

    register_rest_route('web3ps/v1', '/quote', [
      'methods'=>'POST',
      'permission_callback'=>'__return_true',
      'callback'=>function(WP_REST_Request $req){
        $body=$req->get_json_params();
        $chainId=isset($body['chainId'])?(int)$body['chainId']:0;

        $gw=new WC_Gateway_Web3Pay_Simple_Commercial();
        $fiat=strtoupper(trim((string)$gw->get_option('fiat_currency', get_woocommerce_currency())));
        $ttl=max(60,(int)$gw->get_option('quote_ttl',600));

        $bufPct=(float)$gw->get_option('fee_buffer_percent', 0.5);
        if ($bufPct < 0) $bufPct = 0;
        if ($bufPct > 10) $bufPct = 10;

        $nets=$gw->get_networks();
        $net=w3ps_find_network($nets, $chainId);
        if(!$net) return new WP_REST_Response(['error'=>'Unsupported network'], 400);

        // Ensure WooCommerce cart/session is loaded in REST context
        if (function_exists('wc_load_cart')) {
          wc_load_cart();
        }
        if (WC()->cart && method_exists(WC()->cart, 'calculate_totals')) {
          WC()->cart->calculate_totals();
        }

        if(!WC()->cart) return new WP_REST_Response(['error'=>'Cart not available'], 400);
        $totalFiat=(float)WC()->cart->get_total('edit');
        if($totalFiat<=0) return new WP_REST_Response(['error'=>'Invalid cart total'], 400);

        $price = w3ps_get_price_with_fallback($net['coingecko_id'], $net['symbol'], $fiat);
        if(!$price || $price<=0) return new WP_REST_Response(['error'=>'Pricing unavailable'], 502);

        $totalFiatBuffered = $totalFiat * (1.0 + ($bufPct/100.0));
        $amountNative = $totalFiatBuffered / (float)$price;
        $weiStr = w3ps_float_to_wei_str($amountNative);
        $expectedWei = w3ps_dec_to_hex_0x($weiStr);

        $quoteId = wp_generate_password(18, false, false);
        $expiresAt = time() + $ttl;

        $quote=[
          'quoteId'=>$quoteId,
          'expiresAt'=>$expiresAt,
          'ttlSeconds'=>$ttl,
          'chainId'=>$chainId,
          'merchant'=>$net['merchant'],
          'symbol'=>$net['symbol'] ?? 'NATIVE',
          'expectedWei'=>$expectedWei,
          'displayAmount'=>w3ps_format_native_from_wei($weiStr, 8),
          'explorer'=>$net['explorer'] ?? '',
          'feeBufferPercent'=>$bufPct,
        ];

        set_transient('w3ps_quote_'.$quoteId, $quote, $ttl);
        return new WP_REST_Response($quote, 200);
      }
    ]);

    register_rest_route('web3ps/v1', '/verify', [
      'methods'=>'POST',
      'permission_callback'=>'__return_true',
      'callback'=>function(WP_REST_Request $req){
        $body=$req->get_json_params();
        $orderId=isset($body['orderId'])?absint($body['orderId']):0;
        $orderKey=sanitize_text_field($body['orderKey']??'');
        $txHash=w3ps_sanitize_hex($body['txHash']??'');

        if(!$orderId || !$orderKey || !$txHash) return new WP_REST_Response(['error'=>'Missing fields'], 400);

        $order=wc_get_order($orderId);
        if(!$order || $order->get_order_key()!==$orderKey) return new WP_REST_Response(['error'=>'Invalid order'], 403);

        if (w3ps_tx_claimed_by_other_order($txHash, $orderId)) {
          return new WP_REST_Response(['error'=>'Tx hash already used by another order'], 409);
        }

        $existing = strtolower((string)$order->get_meta('_w3ps_tx_hash'));
        if($existing && $existing !== strtolower($txHash)) return new WP_REST_Response(['error'=>'Order already has a different tx'], 409);

        if (!$existing) {
          $order->update_meta_data('_w3ps_tx_hash', strtolower($txHash));
          $order->save();
        }

        $gw=new WC_Gateway_Web3Pay_Simple_Commercial();
        $nets=$gw->get_networks();

        $result = w3ps_verify_order_tx($order, $nets, $gw);
        if (!empty($result['status']) && $result['status']==='pending') return new WP_REST_Response(['status'=>'pending'], 200);
        if ($result['ok']) return new WP_REST_Response(['status'=>'confirmed'], 200);
        if (!empty($result['status']) && $result['status']==='failed') return new WP_REST_Response(['status'=>'failed'], 200);

        return new WP_REST_Response(['error'=>$result['error'] ?? 'Verification failed'], 400);
      }
    ]);

  });

  // ------------------------
  // Register Gateway
  // ------------------------
  add_filter('woocommerce_payment_gateways', function ($g) {
    $g[] = 'WC_Gateway_Web3Pay_Simple_Commercial';
    return $g;
  });

});
