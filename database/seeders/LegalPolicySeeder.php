<?php

namespace Database\Seeders;

use App\Models\LegalPolicy;
use Illuminate\Database\Seeder;

class LegalPolicySeeder extends Seeder
{
    public function run(): void
    {
        $policies = [
            [
                'title'        => 'Privacy Policy',
                'slug'         => 'privacy-policy',
                'summary'      => 'How GadgetPlug collects, uses, and protects your personal data.',
                'sort_order'   => 1,
                'is_published' => true,
                'published_at' => now(),
                'content'      => <<<'HTML'
<h2>1. Introduction</h2>
<p>Welcome to <strong>GadgetPlug</strong> (operated by Lukratif Enterprise / GadgetPlug Nigeria Ltd.). We respect your privacy and are committed to protecting your personal data in compliance with the Nigeria Data Protection Act (NDPA) and applicable regulations.</p>
<p>This Privacy Policy explains how we collect, use, store, and share information when you visit our website (gadgetplug.com.ng), make purchases, register as a vendor, or join our Affiliate Partner Program.</p>

<h2>2. Information We Collect</h2>
<p>We may collect and process the following categories of information:</p>
<ul>
    <li><strong>Account & Contact Details:</strong> Full name, email address, Nigerian phone/WhatsApp number, state, local government area (LGA), and delivery address.</li>
    <li><strong>Order & Transaction Records:</strong> Items purchased, order value, payment reference, dispatch and delivery milestones. (Note: Card transactions are securely processed by Paystack; GadgetPlug never stores your full card details or CVV).</li>
    <li><strong>Affiliate Partner Data:</strong> Category (student, business owner, etc.), school/institution, social media platforms, audience reach band, and bank account details for commission disbursements.</li>
    <li><strong>Technical & Usage Data:</strong> IP address, device type, browser information, pages visited, and interaction signals captured via cookies and analytical tools including Meta Pixel.</li>
</ul>

<h2>3. How We Use Your Information</h2>
<p>We use your personal data to:</p>
<ul>
    <li>Fulfill orders, dispatch products, and coordinate doorstep delivery with our logistics riders.</li>
    <li>Send real-time order tracking and customer support updates via WhatsApp and SMS.</li>
    <li>Calculate and disburse affiliate commissions and vendor settlements.</li>
    <li>Prevent fraudulent orders, duplicate referrals, and unauthorized account access.</li>
    <li>Measure advertising performance and optimize promotional campaigns.</li>
</ul>

<h2>4. Data Sharing & Third Parties</h2>
<p>We do not sell your personal data. We only share information with trusted third parties strictly necessary to deliver our services:</p>
<ul>
    <li><strong>Verified Logistics Partners & Riders:</strong> Name, phone number, and delivery address to deliver your items.</li>
    <li><strong>Licensed Payment Processors (Paystack):</strong> Secure payment transaction references.</li>
    <li><strong>Regulatory & Law Enforcement Authorities:</strong> Only when legally required under Nigerian law.</li>
</ul>

<h2>5. Cookies & Tracking Technologies</h2>
<p>We use cookies and conversion tracking tags (such as Meta Pixel) to understand browsing behavior, measure ad campaign conversions, and preserve user shopping carts. You can adjust your browser settings to refuse cookies, though some features of the store may operate with reduced functionality.</p>

<h2>6. Data Security & Retention</h2>
<p>We employ industry-standard encryption, SSL/TLS protocols, and access controls to protect your data. We retain your information only as long as necessary to fulfill transactions, comply with legal obligations, and resolve disputes.</p>

<h2>7. Your Legal Rights</h2>
<p>Under the Nigeria Data Protection Act, you have the right to request access to your personal data, request correction of inaccurate details, object to processing, or request deletion of your information subject to statutory retention requirements.</p>

<h2>8. Contact Us</h2>
<p>If you have any questions or requests regarding this Privacy Policy, please reach out to us:</p>
<ul>
    <li><strong>Email:</strong> support@gadgetplug.com.ng</li>
    <li><strong>WhatsApp Support:</strong> Available via our official support line on the website</li>
    <li><strong>Address:</strong> GadgetPlug Nigeria Ltd., Akwa Ibom State, Nigeria</li>
</ul>
HTML
            ],
            [
                'title'        => 'Terms of Service',
                'slug'         => 'terms-of-service',
                'summary'      => 'General marketplace rules, user accounts, purchases, and delivery guidelines.',
                'sort_order'   => 2,
                'is_published' => true,
                'published_at' => now(),
                'content'      => <<<'HTML'
<h2>1. Agreement to Terms</h2>
<p>By accessing or using <strong>GadgetPlug</strong> (gadgetplug.com.ng), you agree to be bound by these Terms of Service. If you do not agree with any part of these terms, you must not use our website or services.</p>

<h2>2. Marketplace Model</h2>
<p>GadgetPlug operates a verified consumer tech marketplace connecting Nigerian consumers with CAC-verified gadget vendors. All vendors undergo inventory verification, identity checks, and strict quality commitments before listing products on our platform.</p>

<h2>3. Account Registration & Security</h2>
<p>When you create an account, you must provide accurate and complete information. You are solely responsible for maintaining the confidentiality of your login credentials and for all activities that occur under your account.</p>

<h2>4. Orders, Dispatch & "Test Before You Pay"</h2>
<ul>
    <li><strong>Pricing & Availability:</strong> All prices are displayed in Nigerian Naira (₦). While we strive for accuracy, errors may occasionally occur. If a pricing error is discovered, we will notify you before dispatch.</li>
    <li><strong>Test Before You Pay:</strong> For eligible pay-on-delivery locations, customers are encouraged to inspect and test the physical condition and basic functions of the gadget upon arrival with our delivery rider before making final payment.</li>
    <li><strong>Dispatch Timelines:</strong> In-stock items in active coverage zones (e.g. Uyo, Lagos, Abuja) are typically dispatched within 2 hours of order confirmation. Delivery times to other areas depend on transit logistics.</li>
</ul>

<h2>5. Payment Terms</h2>
<p>We accept secure online payments via Paystack (Debit/Credit Card, Bank Transfer, USSD) as well as authorized Pay-on-Delivery where indicated. Payment must be confirmed before riders release ownership of high-value devices.</p>

<h2>6. Prohibited Activities</h2>
<p>Users must not engage in fraudulent orders, chargeback abuse, fake reviews, automated scraping, or any activity that compromises platform security or harms other buyers and vendors.</p>

<h2>7. Limitation of Liability</h2>
<p>To the fullest extent permitted by Nigerian law, GadgetPlug and its affiliates shall not be liable for indirect, incidental, or consequential damages resulting from website downtime, logistics carrier delays, or vendor inventory discrepancies beyond our reasonable control.</p>

<h2>8. Governing Law</h2>
<p>These Terms are governed by and construed in accordance with the laws of the Federal Republic of Nigeria. Any dispute arising under these Terms shall be subject to the exclusive jurisdiction of the competent courts in Nigeria.</p>
HTML
            ],
            [
                'title'        => 'Affiliate Program Agreement',
                'slug'         => 'affiliate-terms',
                'summary'      => 'Rules, commission structures, payout schedules, and promotional guidelines for affiliates.',
                'sort_order'   => 3,
                'is_published' => true,
                'published_at' => now(),
                'content'      => <<<'HTML'
<h2>1. Enrollment & Eligibility</h2>
<p>The GadgetPlug Affiliate Partner Program allows creators, students, marketers, and hustlers to earn commission by promoting verified tech gadgets. To participate, applicants must submit our online application form with valid contact information, state, LGA, and promotional channels. GadgetPlug reserves the right to approve or decline applications at its sole discretion.</p>

<h2>2. Referral Tracking & Attribution</h2>
<ul>
    <li><strong>Tracking Links:</strong> Approved affiliates receive a unique referral link and referral code. Purchases made by customers who visit through your link are automatically tracked to your account.</li>
    <li><strong>Attribution Window:</strong> Referral sessions are tracked across a defined cookie window (configured in platform settings). Orders placed within this window by referred customers count toward your commissions.</li>
</ul>

<h2>3. Commissions & Earnings</h2>
<ul>
    <li><strong>Commission Rates:</strong> Commission rates are calculated as a percentage of the net sales price (excluding shipping fees and taxes). Rates vary by category and product override settings.</li>
    <li><strong>Level Multipliers:</strong> Top-performing affiliates may unlock Level Boosts (e.g. Silver, Gold, Platinum) with higher multiplier earnings based on monthly sales milestones.</li>
    <li><strong>Hold Period:</strong> Commissions remain in a "Pending" status until the order's return/inspection window has safely elapsed without customer cancellation or return.</li>
</ul>

<h2>4. Payouts & Bank Requirements</h2>
<ul>
    <li>Affiliates must provide valid Nigerian bank account details matching their registered name in their Affiliate Dashboard.</li>
    <li>Cleared balances above the minimum payout threshold (typically ₦2,000) are disbursed via direct bank transfer according to our weekly payment schedule.</li>
    <li>No fees or capital are required to register, remain active, or receive payouts.</li>
</ul>

<h2>5. Prohibited Promotional Conduct (Anti-Spam Policy)</h2>
<p>Affiliates are brand ambassadors. The following practices are strictly prohibited and will result in immediate account termination and forfeiture of unpaid commissions:</p>
<ul>
    <li><strong>Spam & Unsolicited Messaging:</strong> Mass messaging strangers on WhatsApp, SMS spam, or automated bot postings in groups without group admin consent.</li>
    <li><strong>False or Misleading Claims:</strong> Advertising fake prices, non-existent free gifts, or impersonating official GadgetPlug staff.</li>
    <li><strong>Trademark Bidding:</strong> Bidding on "GadgetPlug" or brand variations in paid search (Google Ads, Meta Ads) directing straight to referral links.</li>
    <li><strong>Self-Referrals & Fraud:</strong> Creating fake accounts or buying through your own referral link for personal discount abuse.</li>
</ul>

<h2>6. Termination & Inactivity</h2>
<p>Either party may terminate this agreement at any time with or without notice. Inactive accounts with no referral activity over extended periods may be reviewed or demoted in accordance with affiliate platform settings.</p>

<h2>7. Modifications</h2>
<p>GadgetPlug may update this agreement or adjust default commission tiers periodically. Material updates will be communicated through your affiliate dashboard or WhatsApp broadcast.</p>
HTML
            ],
            [
                'title'        => 'Return, Replacement & Refund Policy',
                'slug'         => 'refund-policy',
                'summary'      => 'Doorstep inspection rules, return window, defect replacements, and refund timelines.',
                'sort_order'   => 4,
                'is_published' => true,
                'published_at' => now(),
                'content'      => <<<'HTML'
<h2>1. "Test Before You Pay" Inspection Guarantee</h2>
<p>We want you to be 100% confident in every device you buy. On eligible deliveries, customers are encouraged to open the delivery parcel, turn on the device, check physical integrity, and test core functionality in the presence of the delivery rider before completing payment.</p>

<h2>2. Return & Replacement Window</h2>
<p>If you discover a manufacturer defect or an incorrect specification after delivery, you must notify us within <strong>48 to 72 hours</strong> of receipt (depending on the product category and vendor warranty terms). Defective items reported within this window are eligible for immediate replacement or repair under warranty.</p>

<h2>3. Return Eligibility Requirements</h2>
<p>To qualify for a valid return or exchange, the gadget must meet the following criteria:</p>
<ul>
    <li>The item must be in its original condition with all manufacturer packaging, boxes, manuals, charging cables, and accessories included.</li>
    <li>The serial number, IMEI number, or security seal must match our dispatch records and must not be altered, removed, or defaced.</li>
    <li>The device must be logged out of all personal accounts (iCloud, Google Account, Samsung Account, Passcodes/PINs must be removed).</li>
</ul>

<h2>4. Non-Returnable Conditions</h2>
<p>We cannot accept returns or issue refunds under the following circumstances:</p>
<ul>
    <li>Physical damage occurring after delivery (e.g. cracked screens, liquid intrusion, dents, drops).</li>
    <li>Software modifications, unauthorized OS jailbreaking/rooting, or third-party repair attempts.</li>
    <li>Change of mind after the delivery rider has completed delivery and the item was accepted in good condition.</li>
</ul>

<h2>5. Refund Processing Timelines</h2>
<ul>
    <li><strong>Card / Online Payments:</strong> Once an eligible return is inspected and approved by our quality control team, refunds are credited back to your original payment card or bank account via Paystack within <strong>3 to 5 business days</strong>.</li>
    <li><strong>Store Credit / Replacement:</strong> Customers may also opt for instant store credit or an immediate replacement device dispatched via priority delivery.</li>
</ul>

<h2>6. How to Initiate a Return</h2>
<p>To request a return or report an issue with your order:</p>
<ol>
    <li>Contact our support desk on WhatsApp with your Order Reference Number and clear photos or a short video demonstrating the issue.</li>
    <li>Our customer care team will verify the claim and schedule a courier pickup or direct you to the nearest verified drop-off hub.</li>
</ol>
HTML
            ],
        ];

        foreach ($policies as $data) {
            LegalPolicy::firstOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
