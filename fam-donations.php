<?php
/**
 * Plugin Name: FAM Donation System
 * Description: Authorize.net Accept Hosted donations. Use [fam_donation] on any page.
 * Version:     1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FAM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FAM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// ── 1. ADMIN MENU ─────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_menu_page( 'FAM Donations', 'Donations', 'manage_options',
        'fam-donations', 'fam_settings_page', 'dashicons-heart', 30 );
});

function fam_settings_page() {
    if ( isset($_POST['fam_save']) && check_admin_referer('fam_settings_nonce') ) {
        foreach ([
            'fam_anet_login_id'        => 'sanitize_text_field',
            'fam_anet_transaction_key' => 'sanitize_text_field',
            'fam_anet_signature_key'   => 'sanitize_text_field',
            'fam_org_name'             => 'sanitize_text_field',
            'fam_receipt_email'        => 'sanitize_email',
            'fam_tier1_label'          => 'sanitize_text_field',
            'fam_tier2_label'          => 'sanitize_text_field',
            'fam_tier3_label'          => 'sanitize_text_field',
        ] as $key => $fn) update_option( $key, $fn( $_POST[$key] ?? '' ) );
        foreach (['fam_goal_amount','fam_raised_amount','fam_donor_count',
                  'fam_tier1_amount','fam_tier2_amount','fam_tier3_amount'] as $k)
            update_option($k, absint($_POST[$k] ?? 0));
        update_option('fam_sandbox_mode', isset($_POST['fam_sandbox_mode']) ? '1' : '0');
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
    $s = fn($k,$d='') => get_option($k,$d);
    ?>
    <div class="wrap"><h1>FAM Donation Settings</h1>
    <form method="post"><?php wp_nonce_field('fam_settings_nonce'); ?>
    <table class="form-table">
      <tr><th>Organization name</th>
          <td><input type="text" name="fam_org_name"
              value="<?php echo esc_attr($s('fam_org_name','The Filipino American Museum')); ?>"
              class="regular-text"/></td></tr>
      <tr><th>API Login ID</th><td>
          <input type="text" name="fam_anet_login_id"
              value="<?php echo esc_attr($s('fam_anet_login_id')); ?>"
              class="regular-text" autocomplete="off"/>
          <p class="description">Authorize.net → Account → API Credentials & Keys</p></td></tr>
      <tr><th>Transaction Key</th><td>
          <input type="password" name="fam_anet_transaction_key"
              value="<?php echo esc_attr($s('fam_anet_transaction_key')); ?>"
              class="regular-text" autocomplete="off"/></td></tr>
      <tr><th>Signature Key</th><td>
          <input type="password" name="fam_anet_signature_key"
              value="<?php echo esc_attr($s('fam_anet_signature_key')); ?>"
              class="regular-text" autocomplete="off"/>
          <p class="description">Authorize.net → Account → API Credentials &amp; Keys → New Signature Key</p></td></tr>
      <tr><th>Silent Post URL</th><td>
          <code><?php echo esc_html( admin_url('admin-ajax.php') . '?action=fam_silent_post' ); ?></code>
          <p class="description">Paste into Authorize.net → Account → Silent Post URL</p></td></tr>
      <tr><th>Sandbox mode</th><td>
          <label><input type="checkbox" name="fam_sandbox_mode" value="1"
              <?php checked($s('fam_sandbox_mode','1'),'1'); ?>/>
          Enabled (uncheck for live)</label></td></tr>
      <tr><th>Goal ($)</th>
          <td><input type="number" name="fam_goal_amount"
              value="<?php echo esc_attr($s('fam_goal_amount',250000)); ?>"
              class="small-text"/></td></tr>
      <tr><th>Amount raised ($)</th>
          <td><input type="number" name="fam_raised_amount"
              value="<?php echo esc_attr($s('fam_raised_amount',0)); ?>"
              class="small-text"/>
          <p class="description">Auto-updates with each approved donation.</p></td></tr>
      <tr><th>Donor count</th>
          <td><input type="number" name="fam_donor_count"
              value="<?php echo esc_attr($s('fam_donor_count',0)); ?>"
              class="small-text"/></td></tr>
      <tr><th>Receipt from email</th>
          <td><input type="email" name="fam_receipt_email"
              value="<?php echo esc_attr($s('fam_receipt_email')); ?>"
              class="regular-text"/></td></tr>
    </table>
    <h2 style="margin-top:2rem">Donation tiers</h2>
    <p class="description">These are the three preset amounts shown to donors. Tier 2 gets the "Most popular" badge.</p>
    <table class="form-table">
      <?php foreach ([1=>['Friend',25], 2=>['Supporter',50], 3=>['Champion',100]] as $i=>[$dl,$da]): ?>
      <tr>
        <th>Tier <?php echo $i ?><?php echo $i===2 ? ' <span style="font-size:11px;background:#B87D4B;color:#fff;padding:1px 8px;border-radius:8px;font-weight:400">featured</span>' : '' ?></th>
        <td>
          <label style="margin-right:16px">Label&nbsp;
            <input type="text" name="fam_tier<?php echo $i ?>_label"
              value="<?php echo esc_attr($s('fam_tier'.$i.'_label', $dl)); ?>"
              class="regular-text"/>
          </label>
          <label>Amount&nbsp;$
            <input type="number" name="fam_tier<?php echo $i ?>_amount"
              value="<?php echo esc_attr($s('fam_tier'.$i.'_amount', $da)); ?>"
              class="small-text" min="1"/>
          </label>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php submit_button('Save settings','primary','fam_save'); ?>
    </form>
    <hr/><h2>Donation log</h2><?php fam_render_log(); ?>
    </div><?php
}

function fam_render_log() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}fam_donations ORDER BY created_at DESC LIMIT 50"
    );
    if (!$rows) { echo '<p>No donations yet.</p>'; return; }
    echo '<table class="widefat striped"><thead><tr>
          <th>Date</th><th>Name</th><th>Email</th><th>Amount</th>
          <th>Freq</th><th>Tier</th><th>TXN ID</th><th>Status</th>
          </tr></thead><tbody>';
    foreach ($rows as $r) {
        $c = $r->status==='approved' ? 'green'
           : ($r->status==='declined' ? '#c00' : 'orange');
        printf(
            '<tr><td>%s</td><td>%s %s</td><td>%s</td><td><b>$%s</b></td>
             <td>%s</td><td>%s</td><td><code>%s</code></td>
             <td><span style="color:%s">%s</span></td></tr>',
            esc_html($r->created_at),
            esc_html($r->first_name), esc_html($r->last_name),
            esc_html($r->email),
            esc_html(number_format($r->amount,2)),
            esc_html($r->frequency), esc_html($r->tier),
            esc_html($r->txn_id),
            $c, esc_html($r->status)
        );
    }
    echo '</tbody></table>';
}

// ── 2. DB TABLE ───────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $t = $wpdb->prefix.'fam_donations';
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$t} (
        id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        txn_id        VARCHAR(64)   NOT NULL,
        amount        DECIMAL(10,2) NOT NULL,
        frequency     VARCHAR(20)   NOT NULL DEFAULT 'once',
        tier          VARCHAR(50)   NOT NULL DEFAULT '',
        first_name    VARCHAR(100)  NOT NULL DEFAULT '',
        last_name     VARCHAR(100)  NOT NULL DEFAULT '',
        email         VARCHAR(200)  NOT NULL DEFAULT '',
        status        VARCHAR(20)   NOT NULL DEFAULT 'pending',
        anet_response LONGTEXT,
        created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_txn(txn_id),
        KEY idx_status(status)
    ) {$c};");
});

// ── 3. AJAX: GET AUTHORIZE.NET TOKEN ─────────────────────────────────────────
add_action('wp_ajax_fam_get_token',        'fam_get_anet_token');
add_action('wp_ajax_nopriv_fam_get_token', 'fam_get_anet_token');

function fam_get_anet_token() {
    check_ajax_referer('fam_nonce','nonce');

    $amount   = round(floatval($_POST['amount'] ?? 0), 2);
    $freq     = sanitize_text_field($_POST['frequency'] ?? 'once');
    $tier     = sanitize_text_field($_POST['tier'] ?? '');

    if ($amount <= 0) wp_send_json_error(['message'=>'Invalid amount.']);

    $login_id = get_option('fam_anet_login_id','');
    $tx_key   = get_option('fam_anet_transaction_key','');
    $sandbox  = get_option('fam_sandbox_mode','1') === '1';

    if (!$login_id || !$tx_key)
        wp_send_json_error(['message'=>
            'Payment not configured. Contact info@thefilipinoamericanmuseum.com']);

    $invoice_num = 'FAM-' . strtoupper(uniqid('', true));

    $api = $sandbox
        ? 'https://apitest.authorize.net/xml/v1/request.api'
        : 'https://api.authorize.net/xml/v1/request.api';

    $page       = get_permalink() ?: home_url('/donate/');
    $return_url = add_query_arg([
        'fam_return' => '1',
        'fam_freq'   => rawurlencode($freq),
        'fam_tier'   => rawurlencode($tier),
    ], $page);

    $payload = [
        'getHostedPaymentPageRequest' => [
            'merchantAuthentication' => [
                'name' => $login_id, 'transactionKey' => $tx_key,
            ],
            'transactionRequest' => [
                'transactionType' => 'authCaptureTransaction',
                'amount'  => number_format($amount,2,'.',''),
                'order'   => [
                    'invoiceNumber' => $invoice_num,
                    'description'   => 'Donation — '.get_option('fam_org_name','FAM'),
                ],
                'userFields' => ['userField' => [
                    ['name'=>'tier',      'value'=>$tier],
                    ['name'=>'frequency', 'value'=>$freq],
                ]],
            ],
            'hostedPaymentSettings' => ['setting' => [
                ['settingName'=>'hostedPaymentReturnOptions',
                 'settingValue'=> json_encode([
                    'showReceipt'=>false,'url'=>$return_url,
                    'urlText'=>'Return to museum',
                    'cancelUrl'=>$page,'cancelUrlText'=>'Cancel',
                ])],
                ['settingName'=>'hostedPaymentButtonOptions',
                 'settingValue'=> json_encode([
                    'text'=>'Donate $'.number_format($amount,2).' now',
                ])],
                ['settingName'=>'hostedPaymentStyleOptions',
                 'settingValue'=> json_encode(['bgColor'=>'white'])],
                ['settingName'=>'hostedPaymentPaymentOptions',
                 'settingValue'=> json_encode([
                    'cardCodeRequired'=>true,
                    'showCreditCard'=>true,'showBankAccount'=>false,
                ])],
                ['settingName'=>'hostedPaymentOrderOptions',
                 'settingValue'=> json_encode([
                    'show'=>true,
                    'merchantName'=>get_option('fam_org_name','Filipino American Museum'),
                ])],
                ['settingName'=>'hostedPaymentCustomerOptions',
                 'settingValue'=> json_encode([
                    'showEmail'=>true,'requiredEmail'=>true,
                    'addPaymentProfile'=>false,
                ])],
                ['settingName'=>'hostedPaymentIFrameCommunicatorUrl',
                 'settingValue'=> json_encode([
                    'url' => FAM_PLUGIN_URL.'communicator.html',
                ])],
            ]],
        ],
    ];

    $res = wp_remote_post($api, [
        'headers'   => ['Content-Type'=>'application/json; charset=utf-8'],
        'body'      => json_encode($payload),
        'timeout'   => 25,
        'sslverify' => true,
    ]);

    if (is_wp_error($res))
        wp_send_json_error(['message'=>'Connection error: '.$res->get_error_message()]);

    $raw  = ltrim(wp_remote_retrieve_body($res), "\xEF\xBB\xBF\xFE\xFF");
    $body = json_decode($raw, true);

    if (empty($body['token'])) {
        $err = $body['messages']['message'][0]['text'] ?? 'Unexpected response.';
        error_log('[FAM] token error: '.$err);
        wp_send_json_error(['message'=>$err]);
    }

    global $wpdb;
    $wpdb->insert($wpdb->prefix.'fam_donations',[
        'txn_id'     => $invoice_num,
        'amount'     => $amount,
        'frequency'  => $freq,
        'tier'       => $tier,
        'status'     => 'pending',
        'created_at' => current_time('mysql'),
    ]);

    wp_send_json_success([
        'token'      => $body['token'],
        'iframe_url' => $sandbox
            ? 'https://test.authorize.net/payment/payment'
            : 'https://accept.authorize.net/payment/payment',
    ]);
}

// ── 4. AJAX: SAVE CONFIRMED TRANSACTION ──────────────────────────────────────
add_action('wp_ajax_fam_save_txn',        'fam_save_transaction');
add_action('wp_ajax_nopriv_fam_save_txn', 'fam_save_transaction');

function fam_save_transaction() {
    check_ajax_referer('fam_nonce','nonce');
    // Authoritative record is written by fam_handle_silent_post (Silent Post webhook).
    // This handler exists only so the JS confirmation screen gets a 200 response.
    wp_send_json_success();
}

// ── 5. SILENT POST WEBHOOK (Authorize.net → server) ──────────────────────────
add_action('wp_ajax_fam_silent_post',        'fam_handle_silent_post');
add_action('wp_ajax_nopriv_fam_silent_post', 'fam_handle_silent_post');

function fam_handle_silent_post() {
    // Verify HMAC-SHA512 signature when a Signature Key is configured.
    $sig_key = get_option('fam_anet_signature_key', '');
    if ($sig_key !== '') {
        $header = $_SERVER['HTTP_X_ANET_SIGNATURE'] ?? '';
        if ( ! str_starts_with($header, 'sha512=') ) {
            status_header(403);
            exit('Missing signature');
        }
        $raw_body = file_get_contents('php://input');
        $computed = hash_hmac('sha512', $raw_body, $sig_key);
        if ( ! hash_equals($computed, strtolower(substr($header, 7))) ) {
            status_header(403);
            exit('Invalid signature');
        }
    }

    $code    = sanitize_text_field($_POST['x_response_code'] ?? '');
    $txn_id  = sanitize_text_field($_POST['x_trans_id']      ?? '');
    $amount  = round(floatval(     $_POST['x_amount']        ?? 0), 2);
    $fn      = sanitize_text_field($_POST['x_first_name']    ?? '');
    $ln      = sanitize_text_field($_POST['x_last_name']     ?? '');
    $email   = sanitize_email(     $_POST['x_email']         ?? '');
    $invoice = sanitize_text_field($_POST['x_invoice_num']   ?? '');
    $tier    = sanitize_text_field($_POST['x_tier']          ?? '');
    $freq    = sanitize_text_field($_POST['x_frequency']     ?? 'once');
    $raw     = wp_json_encode($_POST);

    $status = match($code) {
        '1'     => 'approved',
        '2'     => 'declined',
        default => 'error',
    };

    global $wpdb;
    $table = $wpdb->prefix . 'fam_donations';

    // Idempotency: skip if real txn_id already recorded as approved.
    if ( $txn_id && $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE txn_id = %s AND status = 'approved'", $txn_id
    ) ) ) {
        status_header(200);
        exit('OK');
    }

    // Prefer updating the pending record (matched by invoice_num stored as txn_id).
    $updated = 0;
    if ( $invoice ) {
        $updated = $wpdb->update(
            $table,
            [
                'txn_id'        => $txn_id ?: ( 'SP-' . uniqid('', true) ),
                'status'        => $status,
                'first_name'    => $fn,
                'last_name'     => $ln,
                'email'         => $email,
                'tier'          => $tier,
                'frequency'     => $freq,
                'anet_response' => $raw,
            ],
            [ 'txn_id' => $invoice, 'status' => 'pending' ]
        );
    }

    // Fallback: insert fresh record if no pending row was found.
    if ( ! $updated ) {
        $wpdb->insert( $table, [
            'txn_id'        => $txn_id ?: ( 'SP-' . uniqid('', true) ),
            'amount'        => $amount,
            'frequency'     => $freq,
            'tier'          => $tier,
            'first_name'    => $fn,
            'last_name'     => $ln,
            'email'         => $email,
            'status'        => $status,
            'anet_response' => $raw,
            'created_at'    => current_time('mysql'),
        ] );
    }

    if ( $status === 'approved' ) {
        update_option('fam_raised_amount',
            floatval(get_option('fam_raised_amount', 0)) + $amount);
        update_option('fam_donor_count',
            intval(get_option('fam_donor_count', 0)) + 1);

        if ( $email )
            fam_send_receipt($email, $fn, $ln, $amount, $freq, $txn_id, $tier);

        $org = get_option('fam_org_name', 'Filipino American Museum');
        wp_mail(
            get_option('admin_email'),
            "[{$org}] New donation \${$amount} from {$fn} {$ln}",
            "TXN: {$txn_id}\nAmount: \${$amount}\nTier: {$tier}\nFreq: {$freq}\nEmail: {$email}"
        );
    }

    status_header(200);
    exit('OK');
}

// ── 6. RECEIPT EMAIL ──────────────────────────────────────────────────────────
function fam_send_receipt($to,$fn,$ln,$amount,$freq,$txn_id,$tier) {
    $from       = get_option('fam_receipt_email', get_option('admin_email'));
    $org        = get_option('fam_org_name','The Filipino American Museum');
    $freq_label = $freq === 'monthly' ? 'monthly recurring' : 'one-time';
    $name       = trim("$fn $ln") ?: 'Friend';
    $date       = date('F j, Y');

    $html = "
<html><body style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto'>
<div style='background:#B87D4B;padding:22px 28px;border-radius:8px 8px 0 0'>
  <h2 style='color:#fff;margin:0'>{$org}</h2>
  <p style='color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px'>Las Vegas, NV · 501(c)(3)</p>
</div>
<div style='padding:28px;background:#fff;border:1px solid #e8e1d9;border-top:none;border-radius:0 0 8px 8px'>
  <p>Dear {$name},</p>
  <p style='line-height:1.6'>Thank you for your <strong>{$freq_label}</strong> donation
     of <strong>\${$amount}</strong> to the Filipino American Museum.</p>
  <table style='width:100%;background:#faf6f1;border-radius:8px;
                padding:16px;margin:20px 0;font-size:14px;border-collapse:collapse'>
    <tr><td style='color:#9a8878;padding:5px 0;border-bottom:1px solid #f0ebe3'>Date</td>
        <td style='text-align:right;font-weight:600;border-bottom:1px solid #f0ebe3'>{$date}</td></tr>
    <tr><td style='color:#9a8878;padding:5px 0;border-bottom:1px solid #f0ebe3'>Transaction ID</td>
        <td style='text-align:right;font-weight:600;font-family:monospace;border-bottom:1px solid #f0ebe3'>{$txn_id}</td></tr>
    <tr><td style='color:#9a8878;padding:5px 0;border-bottom:1px solid #f0ebe3'>Amount</td>
        <td style='text-align:right;font-weight:700;font-size:16px;border-bottom:1px solid #f0ebe3'>\${$amount}</td></tr>
    <tr><td style='color:#9a8878;padding:5px 0;border-bottom:1px solid #f0ebe3'>Tier</td>
        <td style='text-align:right;border-bottom:1px solid #f0ebe3'>{$tier}</td></tr>
    <tr><td style='color:#9a8878;padding:5px 0;border-bottom:1px solid #f0ebe3'>Frequency</td>
        <td style='text-align:right;border-bottom:1px solid #f0ebe3;text-transform:capitalize'>{$freq_label}</td></tr>
    <tr><td style='color:#9a8878;padding:5px 0'>Status</td>
        <td style='text-align:right;color:#3a6d11;font-weight:700'>Approved ✓</td></tr>
  </table>
  <p style='font-size:12px;color:#aaa;line-height:1.7;border-top:1px solid #f0ebe3;padding-top:14px'>
    {$org} is a 501(c)(3) nonprofit. Your donation is tax-deductible. No goods or services
    were provided in exchange. Please retain this email as your official receipt.
  </p>
  <p>With gratitude,<br/><strong>The {$org} Team</strong></p>
</div></body></html>";

    wp_mail($to, "Thank you for your donation — {$org}", $html, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$org} <{$from}>", "Reply-To: {$from}",
    ]);
}

// ── 7. SHORTCODE [fam_donation] ───────────────────────────────────────────────
add_shortcode('fam_donation', 'fam_donation_shortcode');

function fam_donation_shortcode($atts) {
    $a = shortcode_atts([
        'goal'   => get_option('fam_goal_amount',  250000),
        'raised' => get_option('fam_raised_amount', 95000),
        'donors' => get_option('fam_donor_count',     412),
    ], $atts);

    $goal   = intval($a['goal']);
    $raised = intval($a['raised']);
    $donors = intval($a['donors']);
    $pct    = $goal > 0 ? min(100, round(($raised/$goal)*100)) : 0;
    $nonce  = wp_create_nonce('fam_nonce');
    $ajax   = esc_js(admin_url('admin-ajax.php'));
    $org    = esc_html(get_option('fam_org_name','The Filipino American Museum'));

    $tiers = [
        ['amount' => intval(get_option('fam_tier1_amount', 25)),  'label' => get_option('fam_tier1_label', 'Friend')],
        ['amount' => intval(get_option('fam_tier2_amount', 50)),  'label' => get_option('fam_tier2_label', 'Supporter')],
        ['amount' => intval(get_option('fam_tier3_amount', 100)), 'label' => get_option('fam_tier3_label', 'Champion')],
    ];
    $default_tier   = $tiers[1]; // tier 2 is featured/default
    $tiers_json     = wp_json_encode($tiers);

    ob_start(); ?>
<div id="fam-wrap">

<!-- STEP 1 ─ Choose amount -->
<div class="fam-screen active" id="fam-s1">
  <div class="fam-hdr">
    <div class="fam-org"><span class="fam-ico">♥</span><?php echo $org ?><span class="fam-501c">501(c)(3)</span></div>
    <span>🔒</span>
  </div>
  <div class="fam-steps">
    <div class="fam-st act"><i class="fam-sn">1</i> Choose amount</div>
    <div class="fam-sl" id="fsl12"></div>
    <div class="fam-st"><i class="fam-sn">2</i> Secure payment</div>
    <div class="fam-sl"></div>
    <div class="fam-st"><i class="fam-sn">3</i> Confirmation</div>
  </div>
  <div class="fam-hero">
    <h2>Make a donation</h2>
    <p>Your contribution builds exhibits, funds programs, and preserves Filipino American heritage.</p>
  </div>
  <div class="fam-prog">
    <div class="fam-pbar"><div class="fam-pfill" style="width:<?php echo $pct ?>%"></div></div>
    <div class="fam-pmeta">
      <span><b>$<?php echo number_format($raised) ?></b> raised of $<?php echo number_format($goal) ?></span>
      <span><b><?php echo $pct ?>%</b> · <?php echo number_format($donors) ?> donors</span>
    </div>
  </div>
  <div class="fam-tog-row">
    <div class="fam-pill">
      <button class="fam-t act" id="fam-bo" onclick="famFreq('once')">One-time</button>
      <button class="fam-t" id="fam-bm" onclick="famFreq('monthly')">Monthly</button>
    </div>
  </div>
  <div class="fam-cards">
    <?php foreach ($tiers as $i => $tier):
        $amt     = $tier['amount'];
        $lbl     = esc_html($tier['label']);
        $popular = ($i === 1);
        $sel     = $popular ? ' fam-sel' : '';
        $pop     = $popular ? ' fam-pop' : '';
    ?>
    <div class="fam-card<?php echo $pop.$sel ?>" id="fc<?php echo $amt ?>"
         onclick="famPick(<?php echo $amt ?>,'<?php echo esc_js($tier['label']) ?>')">
      <?php if ($popular): ?><div class="fam-popbadge">Most popular</div><?php endif; ?>
      <div class="fam-tn"><?php echo $lbl ?></div>
      <div class="fam-am">$<?php echo $amt ?></div>
      <div class="fam-fl" id="ff<?php echo $amt ?>"></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="fam-custom-section" id="fam-cbox" onclick="famCust()">
    <div class="fam-custom-label">Or enter a custom amount</div>
    <div class="fam-custom-body">
      <span class="fam-custom-prefix">$</span>
      <input type="number" id="fam-ci" class="fam-custom-input"
             placeholder="e.g. 75" min="1"
             oninput="famCustType()" onclick="famCust()"/>
    </div>
  </div>
  <div class="fam-sum">
    <span class="fam-sum-lbl">Your donation</span>
    <span id="fam-sval">$50 — one-time</span>
  </div>
  <button class="fam-btn-main" onclick="famGoPayment()">
    🔒 <span id="fam-blabel">Proceed to secure payment — $50</span>
  </button>
  <div class="fam-trust">
    <span>▲ Authorize.net</span>
    <span>♦ Tax-deductible</span>
    <span>✓ 501(c)(3)</span>
  </div>
</div><!-- /s1 -->

<!-- STEP 2 ─ Authorize.net iframe -->
<div class="fam-screen" id="fam-s2">
  <div class="fam-hdr">
    <div class="fam-org"><span class="fam-ico">♥</span><?php echo $org ?><span class="fam-501c">501(c)(3)</span></div>
    <span>🔒</span>
  </div>
  <div class="fam-steps">
    <div class="fam-st done"><i class="fam-sn">✓</i> Choose amount</div>
    <div class="fam-sl done"></div>
    <div class="fam-st act"><i class="fam-sn">2</i> Secure payment</div>
    <div class="fam-sl"></div>
    <div class="fam-st"><i class="fam-sn">3</i> Confirmation</div>
  </div>
  <div class="fam-infobadge">
    🔒 Card data is entered directly on Authorize.net's servers.
    The museum's website never sees or stores your card number. (PCI DSS SAQ-A)
  </div>
  <a class="fam-back" onclick="famGoBack()">← Change amount</a>
  <div id="fam-loading" class="fam-loading">
    <div class="fam-spin"></div><span>Connecting to secure payment…</span>
  </div>
  <div id="fam-iframe-wrap" style="display:none;padding:0 22px 16px">
    <form id="fam-aform" method="post" target="fam_iframe">
      <input type="hidden" name="token" id="fam-tok" value=""/>
    </form>
    <iframe name="fam_iframe" id="fam_iframe" class="fam-iframe"
            scrolling="no" frameborder="0"
            title="Secure payment form by Authorize.net"></iframe>
  </div>
  <div class="fam-trust" style="padding-bottom:16px">
    <span>▲ PCI DSS SAQ-A</span><span>♦ Receipt by email</span><span>✓ SSL</span>
  </div>
</div><!-- /s2 -->

<!-- STEP 3 ─ Confirmation -->
<div class="fam-screen" id="fam-s3">
  <div class="fam-hdr">
    <div class="fam-org"><span class="fam-ico">♥</span><?php echo $org ?><span class="fam-501c">501(c)(3)</span></div>
  </div>
  <div class="fam-steps">
    <div class="fam-st done"><i class="fam-sn">✓</i> Choose amount</div>
    <div class="fam-sl done"></div>
    <div class="fam-st done"><i class="fam-sn">✓</i> Secure payment</div>
    <div class="fam-sl done"></div>
    <div class="fam-st act"><i class="fam-sn">3</i> Confirmation</div>
  </div>
  <div class="fam-conf">
    <div class="fam-conf-ico">♥</div>
    <h2>Donation confirmed!</h2>
    <p>Thank you for supporting the Filipino American Museum.<br/>
       A tax receipt has been sent to your email.</p>
    <div class="fam-rcpt">
      <div class="fam-rrow"><span>Transaction ID</span><span id="fam-r-txn">—</span></div>
      <div class="fam-rrow"><span>Amount</span><span id="fam-r-amt">—</span></div>
      <div class="fam-rrow"><span>Frequency</span><span id="fam-r-freq">—</span></div>
      <div class="fam-rrow"><span>Processor</span><span>Authorize.net</span></div>
      <div class="fam-rrow"><span>Status</span>
           <span style="color:#3a6d11;font-weight:700">Approved ✓</span></div>
    </div>
    <button class="fam-restart" onclick="famRestart()">Make another donation</button>
  </div>
</div><!-- /s3 -->
</div><!-- #fam-wrap -->

<style>
#fam-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  max-width:640px;margin:2rem auto;background:#fff;border:1px solid #e8e1d9;
  border-radius:12px;overflow:hidden;color:#333}
.fam-screen{display:none}.fam-screen.active{display:block}
.fam-hdr{display:flex;align-items:center;justify-content:space-between;
  padding:13px 22px;border-bottom:1px solid #f0ebe3;background:#faf6f1}
.fam-org{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:#4a3728}
.fam-ico{color:#B87D4B}
.fam-501c{font-size:10px;background:#f0ebe3;color:#8a6a4a;padding:2px 7px;
  border-radius:8px;font-weight:400}
.fam-steps{display:flex;align-items:center;justify-content:center;
  padding:14px 22px 4px;flex-wrap:wrap;gap:4px}
.fam-st{display:flex;align-items:center;gap:5px;font-size:12px;
  color:#bbb;white-space:nowrap}
.fam-st.act,.fam-st.done{color:#4a3728;font-weight:700}
.fam-sn{width:20px;height:20px;border-radius:50%;border:1px solid #ddd;
  display:inline-flex;align-items:center;justify-content:center;font-size:10px;
  background:#fff;font-style:normal;flex-shrink:0}
.fam-st.act .fam-sn,.fam-st.done .fam-sn{background:#B87D4B;border-color:#B87D4B;color:#fff}
.fam-sl{flex:0 0 32px;height:1px;background:#e8e1d9}
.fam-sl.done{background:#B87D4B}
.fam-hero{text-align:center;padding:18px 22px 10px}
.fam-hero h2{font-size:21px;font-weight:800;color:#2a1f15;margin:0 0 8px}
.fam-hero p{font-size:13px;color:#7a6555;line-height:1.6;margin:0}
.fam-prog{padding:0 22px 14px}
.fam-pbar{height:6px;background:#f0ebe3;border-radius:6px;overflow:hidden;margin-bottom:6px}
.fam-pfill{height:100%;background:#B87D4B;border-radius:6px}
.fam-pmeta{display:flex;justify-content:space-between;font-size:11px;color:#9a8878}
.fam-pmeta b{color:#4a3728}
.fam-tog-row{display:flex;justify-content:center;padding:6px 0 12px}
.fam-pill{display:flex;background:#f5f0ea;border:1px solid #e8e1d9;
  border-radius:20px;padding:3px}
.fam-t{border:none;background:transparent;padding:5px 15px;border-radius:16px;
  font-size:12px;cursor:pointer;color:#7a6555;font-family:inherit}
.fam-t.act{background:#fff;color:#2a1f15;font-weight:700;border:1px solid #d4c5b5}
.fam-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:0 22px 12px}
@media(max-width:480px){.fam-cards{grid-template-columns:repeat(3,1fr)}}
.fam-card{background:#fff;border:1px solid #e8e1d9;border-radius:10px;padding:15px 8px;
  cursor:pointer;text-align:center;position:relative;transition:border .15s}
.fam-card:hover{border-color:#B87D4B}
.fam-card.fam-sel{border:2px solid #B87D4B}
.fam-popbadge{position:absolute;top:-9px;left:50%;transform:translateX(-50%);
  background:#B87D4B;color:#fff;font-size:10px;padding:2px 10px;border-radius:7px;
  white-space:nowrap;font-weight:700}
.fam-tn{font-size:10px;font-weight:700;color:#9a8878;letter-spacing:.6px;
  text-transform:uppercase;margin-bottom:5px}
.fam-am{font-size:22px;font-weight:800;color:#2a1f15;line-height:1}
.fam-fl{font-size:11px;color:#9a8878;min-height:15px;margin-top:3px}
.fam-custom-section{margin:0 22px 14px;border:1.5px solid #e8e1d9;border-radius:10px;
  overflow:hidden;cursor:text;transition:border .15s}
.fam-custom-section.fam-sel{border:2px solid #B87D4B}
.fam-custom-label{background:#faf6f1;padding:8px 14px;font-size:11px;font-weight:700;
  color:#9a8878;text-transform:uppercase;letter-spacing:.6px;
  border-bottom:1px solid #e8e1d9}
.fam-custom-body{display:flex;align-items:center}
.fam-custom-prefix{padding:12px 14px;font-size:18px;font-weight:700;color:#9a8878;
  background:#fff;border-right:1px solid #e8e1d9;line-height:1}
.fam-custom-input{border:none;background:#fff;font-size:18px;font-weight:700;
  color:#2a1f15;flex:1;outline:none;font-family:inherit;padding:12px 14px;width:100%}
.fam-sum{display:flex;align-items:center;justify-content:space-between;
  margin:0 22px 12px;background:#faf6f1;border-radius:8px;padding:10px 14px;font-size:14px}
.fam-sum-lbl{color:#9a8878;font-size:12px}
#fam-sval{font-weight:700;color:#2a1f15}
.fam-btn-main{display:flex;align-items:center;justify-content:center;gap:8px;
  width:calc(100% - 44px);margin:0 22px 14px;background:#B87D4B;color:#fff;border:none;
  border-radius:8px;padding:14px;font-size:14px;font-weight:700;cursor:pointer;
  transition:opacity .15s;font-family:inherit}
.fam-btn-main:hover{opacity:.87}
.fam-trust{display:flex;align-items:center;justify-content:center;gap:18px;
  padding:6px 22px 16px;font-size:11px;color:#9a8878;flex-wrap:wrap}
.fam-infobadge{background:#faf6f1;border:1px solid #e8e1d9;border-radius:8px;
  margin:10px 22px 4px;padding:10px 14px;font-size:12px;color:#7a6555;line-height:1.5}
.fam-back{display:inline-block;font-size:12px;color:#9a8878;cursor:pointer;
  padding:4px 22px 10px;text-decoration:underline}
.fam-back:hover{color:#4a3728}
.fam-loading{display:flex;align-items:center;justify-content:center;gap:12px;
  padding:2.5rem 1rem;color:#9a8878;font-size:14px}
.fam-spin{width:24px;height:24px;border:2px solid #e8e1d9;border-top-color:#B87D4B;
border-radius:50%;animation:fam-spin .7s linear infinite;flex-shrink:0}
@keyframes fam-spin{to{transform:rotate(360deg)}}
.fam-iframe{width:100%;min-height:520px;border:none;display:block}
.fam-conf{padding:2.5rem 22px;text-align:center}
.fam-conf-ico{font-size:52px;color:#B87D4B;margin-bottom:12px}
.fam-conf h2{font-size:22px;font-weight:800;color:#2a1f15;margin:0 0 8px}
.fam-conf p{font-size:14px;color:#7a6555;line-height:1.6;margin-bottom:20px}
.fam-rcpt{background:#faf6f1;border-radius:8px;padding:14px 16px;
text-align:left;margin-bottom:22px}
.fam-rrow{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;
border-bottom:1px solid #f0ebe3}
.fam-rrow
.fam-rrow span
.fam-rrow span:last-child{font-weight:700;color:#2a1f15}
.fam-restart{background:transparent;border:1px solid #d4c5b5;border-radius:8px;
padding:10px 24px;font-size:13px;cursor:pointer;color:#4a3728;
font-weight:700;font-family:inherit}
</style>
<script>
(function(){
var _tiers=<?php echo $tiers_json ?>;
var _f='once',_a=<?php echo $default_tier['amount'] ?>,_t='<?php echo esc_js($default_tier['label']) ?>',_c=false;
var _n='<?php echo esc_js($nonce) ?>',_u='<?php echo $ajax ?>';

window.famFreq=function(f){
  _f=f;
  document.getElementById('fam-bo').className='fam-t'+(f==='once'?' act':'');
  document.getElementById('fam-bm').className='fam-t'+(f==='monthly'?' act':'');
  _tiers.forEach(function(t){
    var el=document.getElementById('ff'+t.amount);
    if(el) el.textContent=f==='monthly'?'per month':'';
  });
  famS();
};

window.famPick=function(a,t){
  _c=false;_a=a;_t=t;
  _tiers.forEach(function(ti,i){
    var c=document.getElementById('fc'+ti.amount);
    if(!c) return;
    c.className='fam-card'+(i===1?' fam-pop':'')+(ti.amount===a?' fam-sel':'');
  });
  document.getElementById('fam-cbox').className='fam-custom-section';
  document.getElementById('fam-ci').value='';
  famS();
};

window.famCust=function(){
  _c=true;
  _tiers.forEach(function(ti,i){
    var c=document.getElementById('fc'+ti.amount);
    if(c) c.className='fam-card'+(i===1?' fam-pop':'');
  });
  document.getElementById('fam-cbox').className='fam-custom-section fam-sel';
  var v=parseFloat(document.getElementById('fam-ci').value);
  _a=v>0?v:null;_t='Custom';famS();
};
window.famCustType=function(){famCust();};

function famS(){
  var fl=_f==='monthly'?'/mo':'';
  var al=_a?'$'+_a+fl:'—';
  document.getElementById('fam-sval').innerHTML=
    al+'&nbsp;—&nbsp;'+(_f==='monthly'?'monthly':'one-time');
  document.getElementById('fam-blabel').textContent=_a
    ?'Proceed to secure payment — $'+_a+(_f==='monthly'?'/mo':'')
    :'Select an amount to continue';
}

window.famGoPayment=function(){
  if(!_a){alert('Please select or enter a donation amount.');return;}
  document.getElementById('fam-s1').className='fam-screen';
  document.getElementById('fam-s2').className='fam-screen active';
  document.getElementById('fam-loading').style.display='flex';
  document.getElementById('fam-iframe-wrap').style.display='none';

  var fd=new FormData();
  fd.append('action','fam_get_token');fd.append('nonce',_n);
  fd.append('amount',_a);fd.append('frequency',_f);fd.append('tier',_t);

  fetch(_u,{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      document.getElementById('fam-loading').style.display='none';
      if(!d.success){
        alert('Error: '+(d.data&&d.data.message?d.data.message:'Unknown error'));
        famGoBack();return;
      }
      document.getElementById('fam-tok').value=d.data.token;
      document.getElementById('fam-aform').action=d.data.iframe_url;
      document.getElementById('fam-iframe-wrap').style.display='block';
      document.getElementById('fam-aform').submit();
    })
    .catch(function(){
      document.getElementById('fam-loading').style.display='none';
      alert('Network error. Please try again.');famGoBack();
    });
};

window.famGoBack=function(){
  document.getElementById('fam-s2').className='fam-screen';
  document.getElementById('fam-s1').className='fam-screen active';
};

/* Authorize.net postMessage — relayed by communicator.html */
window.addEventListener('message',function(e){
  if(!e.data||typeof e.data!=='string') return;
  try{
    var m=JSON.parse(e.data);
    if(m.action==='resizeWindow'){
      var fr=document.getElementById('fam_iframe');
      if(fr&&m.height) fr.style.minHeight=(parseInt(m.height)+20)+'px';
      return;
    }
    if(m.action==='transactResponse'){
      var r=JSON.parse(m.response||'{}');
      if(r.responseCode==='1'){
        famConfirm(r.transId,r.firstName||'',r.lastName||'',r.email||'');
      } else {
        var msg=r.responseCode==='2'?'Payment declined. Check your card details and try again.'
               :r.responseCode==='3'?'Payment error. Please try again.'
               :'Not approved (code '+r.responseCode+').';
        alert(msg);famGoBack();
      }
      return;
    }
    if(m.action==='cancel') famGoBack();
  }catch(ex){}
});

window.famConfirm=function(txnId,fn,ln,em){
  document.getElementById('fam-r-txn').textContent=txnId||'N/A';
  document.getElementById('fam-r-amt').textContent='$'+parseFloat(_a).toFixed(2);
  document.getElementById('fam-r-freq').textContent=
    _f==='monthly'?'Monthly recurring':'One-time';
  document.getElementById('fam-s2').className='fam-screen';
  document.getElementById('fam-s3').className='fam-screen active';

  var fd=new FormData();
  fd.append('action','fam_save_txn');fd.append('nonce',_n);fd.append('txn_id',txnId);
  fd.append('amount',_a);fd.append('frequency',_f);fd.append('tier',_t);
  fd.append('first_name',fn);fd.append('last_name',ln);
  fd.append('email',em);fd.append('status','approved');
  fetch(_u,{method:'POST',body:fd});
};

window.famRestart=function(){
  document.getElementById('fam-s3').className='fam-screen';
  document.getElementById('fam-s1').className='fam-screen active';
};

famS();
})();
</script>
<?php
    return ob_get_clean();
}