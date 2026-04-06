<?php
/**
 * Template part for the Affiliate Dashboard
 * Includes referral link, earnings, bank details form, and commission history
 * 
 * @var $args array {
 *    @var $balance float
 *    @var $ref_link string
 *    @var $total_refs int
 * }
 */
$balance    = isset($args['balance']) ? $args['balance'] : 0;
$ref_link   = isset($args['ref_link']) ? $args['ref_link'] : '';
$total_refs = isset($args['total_refs']) ? $args['total_refs'] : 0;
$user_id    = get_current_user_id();

// Bank details
$bank_code    = get_user_meta( $user_id, 'crane_bank_code', true );
$account_num  = get_user_meta( $user_id, 'crane_account_number', true );
$account_name = get_user_meta( $user_id, 'crane_account_name', true );
$commission_log = get_user_meta( $user_id, 'crane_commission_log', true );
if ( ! is_array( $commission_log ) ) $commission_log = array();

// Nigerian banks list
$banks = array(
    '044' => 'Access Bank', '023' => 'Citibank', '063' => 'Diamond Bank',
    '050' => 'Ecobank', '084' => 'Enterprise Bank', '070' => 'Fidelity Bank',
    '011' => 'First Bank', '214' => 'FCMB', '058' => 'GTBank',
    '030' => 'Heritage Bank', '301' => 'Jaiz Bank', '082' => 'Keystone Bank',
    '526' => 'Parallex Bank', '076' => 'Polaris Bank', '101' => 'Providus Bank',
    '221' => 'Stanbic IBTC', '068' => 'Standard Chartered', '232' => 'Sterling Bank',
    '032' => 'Union Bank', '033' => 'UBA', '215' => 'Unity Bank',
    '035' => 'Wema Bank', '057' => 'Zenith Bank', '999992' => 'Opay',
    '999991' => 'PalmPay', '090267' => 'Kuda', '100004' => 'Moniepoint',
);
?>
<div class="bg-crane-glass border border-white/5 rounded-3xl p-8 backdrop-blur-xl space-y-8">
    <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em]">Affiliate Dashboard</h3>

    <!-- Stats Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white/5 p-5 rounded-2xl border border-white/5 text-center">
            <span class="block text-2xl font-black text-crane-green">₦<?php echo number_format( $balance ); ?></span>
            <span class="text-[11px] text-white/60 uppercase font-bold tracking-widest">Pending Balance</span>
        </div>
        <div class="bg-white/5 p-5 rounded-2xl border border-white/5 text-center">
            <span class="block text-2xl font-black text-white"><?php echo $total_refs; ?></span>
            <span class="text-[11px] text-white/60 uppercase font-bold tracking-widest">Referrals</span>
        </div>
        <div class="bg-white/5 p-5 rounded-2xl border border-white/5 text-center">
            <span class="block text-2xl font-black text-white/60">20%</span>
            <span class="text-[11px] text-white/60 uppercase font-bold tracking-widest">Commission Rate</span>
        </div>
    </div>

    <!-- Referral Link -->
    <div>
        <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Your Referral Link</label>
        <div class="flex gap-2">
            <input type="text" value="<?php echo esc_url( $ref_link ); ?>" readonly class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-xs text-white/60 font-mono">
            <button onclick="copyRefLink(this)" class="bg-crane-green hover:bg-crane-green/80 text-black px-5 rounded-xl text-xs font-black uppercase tracking-widest transition-colors whitespace-nowrap">Copy</button>
        </div>
    </div>

    <!-- Bank Details Form -->
    <div>
        <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Bank Details (For Payouts)</label>
        <form id="bank-details-form" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <select name="bank_code" id="crane-bank-code" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-xs text-white/60 appearance-none">
                        <option value="">Select Bank</option>
                        <?php foreach ( $banks as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $bank_code, $code ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <input type="text" name="account_number" id="crane-account-num" value="<?php echo esc_attr( $account_num ); ?>" placeholder="Account Number" maxlength="10" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-xs text-white/60">
                </div>
            </div>
            <div>
                <input type="text" name="account_name" id="crane-account-name" value="<?php echo esc_attr( $account_name ); ?>" placeholder="Account Name" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-xs text-white/60">
            </div>
            <button type="submit" class="bg-white/10 hover:bg-crane-green hover:text-black px-6 py-3 rounded-xl text-xs font-black uppercase tracking-widest transition-all">
                <?php echo $bank_code ? 'Update Bank Details' : 'Save Bank Details'; ?>
            </button>
            <span id="bank-save-msg" class="ml-3 text-xs font-bold uppercase"></span>
        </form>
    </div>

    <!-- Commission History -->
    <?php if ( ! empty( $commission_log ) ) : ?>
    <div>
        <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Payout History</label>
        <div class="space-y-2 max-h-48 overflow-y-auto">
            <?php foreach ( array_reverse( $commission_log ) as $entry ) : ?>
            <div class="flex justify-between items-center bg-white/5 px-4 py-3 rounded-xl">
                <span class="text-xs font-bold text-white/60">₦<?php echo number_format( isset($entry['amount']) ? $entry['amount'] : 0 ); ?></span>
                <span class="text-[11px] text-white/50 uppercase"><?php echo esc_html( isset($entry['date']) ? $entry['date'] : '' ); ?></span>
                <span class="text-[11px] font-black uppercase <?php echo ( isset($entry['status']) ? $entry['status'] : '' ) === 'success' ? 'text-crane-green' : 'text-white/60'; ?>">
                    <?php echo esc_html( isset($entry['status']) ? $entry['status'] : 'pending' ); ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function copyRefLink(b){navigator.clipboard.writeText(b.previousElementSibling.value);b.innerText='Copied!';setTimeout(()=>b.innerText='Copy',2000);}

document.getElementById('bank-details-form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const msg = document.getElementById('bank-save-msg');
    const formData = new FormData();
    formData.append('action', 'crane_save_bank_details');
    formData.append('security', craneData.nonce);
    formData.append('bank_code', document.getElementById('crane-bank-code').value);
    formData.append('account_number', document.getElementById('crane-account-num').value);
    formData.append('account_name', document.getElementById('crane-account-name').value);

    msg.innerText = 'Saving...';
    msg.className = 'ml-3 text-xs font-bold uppercase text-white/60';

    fetch(craneData.ajax_url, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msg.innerText = '✓ Saved!';
            msg.className = 'ml-3 text-xs font-bold uppercase text-crane-green';
        } else {
            msg.innerText = '✗ Error';
            msg.className = 'ml-3 text-xs font-bold uppercase text-red-400';
        }
    });
});
</script>
