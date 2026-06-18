<?php

return [
    // Urgency
    ['category' => 'urgency', 'name' => 'act_now', 'pattern' => '/\bact\s+now\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Urgency phrase: act now', 'suggestion' => 'Remove pressure language like "act now"'],
    ['category' => 'urgency', 'name' => 'limited_time', 'pattern' => '/\blimited\s+time\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Urgency: limited time', 'suggestion' => 'Avoid artificial scarcity phrases'],
    ['category' => 'urgency', 'name' => 'last_chance', 'pattern' => '/\blast\s+chance\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Urgency: last chance', 'suggestion' => 'Replace "last chance" with specific deadline if genuine'],
    ['category' => 'urgency', 'name' => 'expires_today', 'pattern' => '/\bexpires?\s+today\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Urgency: expires today', 'suggestion' => 'Use specific dates instead of urgency stacking'],
    ['category' => 'urgency', 'name' => 'dont_miss', 'pattern' => '/\bdon\'?t\s+miss\b/i', 'weight' => 0.7, 'target' => 'any', 'description' => 'Urgency: don\'t miss', 'suggestion' => 'Soften urgency language'],
    ['category' => 'urgency', 'name' => 'hurry', 'pattern' => '/\bhurry\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Urgency: hurry', 'suggestion' => 'Reduce urgency stacking'],
    ['category' => 'urgency', 'name' => 'urgent_action', 'pattern' => '/\burgent\s+action\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Urgency: urgent action', 'suggestion' => 'Avoid urgent action phrasing unless truly critical'],
    ['category' => 'urgency', 'name' => 'time_sensitive', 'pattern' => '/\btime\s+sensitive\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Urgency: time sensitive', 'suggestion' => 'Explain why timing matters instead of generic urgency'],
    ['category' => 'urgency', 'name' => 'ending_soon', 'pattern' => '/\bending\s+soon\b/i', 'weight' => 0.7, 'target' => 'any', 'description' => 'Urgency: ending soon', 'suggestion' => 'Provide exact end date'],
    ['category' => 'urgency', 'name' => 'final_notice', 'pattern' => '/\bfinal\s+notice\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Urgency: final notice', 'suggestion' => 'Final notice language triggers spam filters heavily'],

    // Promotion
    ['category' => 'promotion', 'name' => 'free_offer', 'pattern' => '/\bfree\s+(offer|gift|trial|shipping)\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Promotional: free offer combo', 'suggestion' => 'Use "complimentary" or be specific about what is free'],
    ['category' => 'promotion', 'name' => 'discount', 'pattern' => '/\b\d+%\s*(off|discount)\b/i', 'weight' => 0.5, 'target' => 'any', 'description' => 'Promotional: percentage discount', 'suggestion' => 'Discounts are fine in context — avoid stacking with urgency'],
    ['category' => 'promotion', 'name' => 'sale', 'pattern' => '/\b(flash|mega|super)\s+sale\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Promotional: sale language', 'suggestion' => 'Tone down promotional adjectives'],
    ['category' => 'promotion', 'name' => 'buy_now', 'pattern' => '/\bbuy\s+now\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: buy now', 'suggestion' => 'Use softer CTAs like "Learn more" or "Shop collection"'],
    ['category' => 'promotion', 'name' => 'order_now', 'pattern' => '/\border\s+now\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: order now', 'suggestion' => 'Vary your call-to-action language'],
    ['category' => 'promotion', 'name' => 'special_offer', 'pattern' => '/\bspecial\s+offer\b/i', 'weight' => 0.5, 'target' => 'any', 'description' => 'Promotional: special offer', 'suggestion' => 'Describe the offer specifically'],
    ['category' => 'promotion', 'name' => 'exclusive_deal', 'pattern' => '/\bexclusive\s+deal\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: exclusive deal', 'suggestion' => 'Explain exclusivity with facts'],
    ['category' => 'promotion', 'name' => 'clearance', 'pattern' => '/\bclearance\b/i', 'weight' => 0.4, 'target' => 'any', 'description' => 'Promotional: clearance', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'coupon', 'pattern' => '/\bcoupon\s+code\b/i', 'weight' => 0.4, 'target' => 'any', 'description' => 'Promotional: coupon code', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'promo_code', 'pattern' => '/\bpromo\s+code\b/i', 'weight' => 0.4, 'target' => 'any', 'description' => 'Promotional: promo code', 'suggestion' => null],

    // Money
    ['category' => 'money', 'name' => 'money_back', 'pattern' => '/\b100%\s+money\s+back\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Money: 100% money back', 'suggestion' => 'State refund policy clearly without hype'],
    ['category' => 'money', 'name' => 'cash_bonus', 'pattern' => '/\bcash\s+bonus\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Money: cash bonus', 'suggestion' => 'Avoid cash bonus language in cold outreach'],
    ['category' => 'money', 'name' => 'earn_money', 'pattern' => '/\bearn\s+\$?\d+/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Money: earn $X', 'suggestion' => 'Income claims are heavily filtered'],
    ['category' => 'money', 'name' => 'make_money', 'pattern' => '/\bmake\s+money\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Money: make money', 'suggestion' => 'Avoid get-rich-quick phrasing'],
    ['category' => 'money', 'name' => 'dollar_amount_subject', 'pattern' => '/\$\d{1,3}(,\d{3})*(\.\d{2})?/', 'weight' => 0.8, 'target' => 'subject', 'description' => 'Money: dollar amount in subject', 'suggestion' => 'Dollar amounts in subject increase spam score when paired with urgency'],
    ['category' => 'money', 'name' => 'triple_dollar', 'pattern' => '/\${3,}/', 'weight' => 1.0, 'target' => 'any', 'description' => 'Money: multiple dollar signs', 'suggestion' => 'Remove repeated $ symbols'],
    ['category' => 'money', 'name' => 'extra_income', 'pattern' => '/\bextra\s+income\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Money: extra income', 'suggestion' => 'Avoid income opportunity language'],
    ['category' => 'money', 'name' => 'financial_freedom', 'pattern' => '/\bfinancial\s+freedom\b/i', 'weight' => 1.3, 'target' => 'any', 'description' => 'Money: financial freedom', 'suggestion' => 'This phrase is common in spam'],
    ['category' => 'money', 'name' => 'double_your', 'pattern' => '/\bdouble\s+your\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Money: double your', 'suggestion' => 'Avoid exaggerated financial claims'],
    ['category' => 'money', 'name' => 'risk_free', 'pattern' => '/\brisk[\s-]free\b/i', 'weight' => 0.9, 'target' => 'any', 'description' => 'Money: risk-free', 'suggestion' => 'Replace with factual guarantee terms'],

    // Shady
    ['category' => 'shady', 'name' => 'you_won', 'pattern' => '/\byou\'?ve?\s+won\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Shady: you won', 'suggestion' => 'Winner language is a top phishing signal'],
    ['category' => 'shady', 'name' => 'winner', 'pattern' => '/\byou\'?re?\s+a\s+winner\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Shady: winner', 'suggestion' => 'Never use winner/lottery language'],
    ['category' => 'shady', 'name' => 'verify_account', 'pattern' => '/\bverify\s+(your\s+)?account\b/i', 'weight' => 1.8, 'target' => 'any', 'description' => 'Shady: verify account', 'suggestion' => 'Account verification requests look like phishing'],
    ['category' => 'shady', 'name' => 'confirm_identity', 'pattern' => '/\bconfirm\s+your\s+identity\b/i', 'weight' => 1.8, 'target' => 'any', 'description' => 'Shady: confirm identity', 'suggestion' => 'Avoid identity confirmation language'],
    ['category' => 'shady', 'name' => 'guaranteed', 'pattern' => '/\bguaranteed?\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Shady: guaranteed', 'suggestion' => 'Replace guarantees with specific terms'],
    ['category' => 'shady', 'name' => 'no_obligation', 'pattern' => '/\bno\s+obligation\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Shady: no obligation', 'suggestion' => 'Common in deceptive offers'],
    ['category' => 'shady', 'name' => 'nigerian', 'pattern' => '/\b(nigerian|prince|inheritance)\b/i', 'weight' => 3.0, 'target' => 'any', 'description' => 'Shady: scam patterns', 'suggestion' => 'Remove immediately'],
    ['category' => 'shady', 'name' => 'password_expired', 'pattern' => '/\bpassword\s+expir/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Shady: password expired', 'suggestion' => 'Security urgency is a phishing pattern'],
    ['category' => 'shady', 'name' => 'suspended_account', 'pattern' => '/\baccount\s+suspended\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Shady: account suspended', 'suggestion' => 'Avoid fake account suspension warnings'],
    ['category' => 'shady', 'name' => 'click_here', 'pattern' => '/\bclick\s+here\b/i', 'weight' => 0.3, 'target' => 'any', 'description' => 'Shady-adjacent: click here', 'suggestion' => 'Use descriptive link text instead of "click here"'],

    // Spam patterns
    ['category' => 'spam', 'name' => 'multiple_exclamation', 'pattern' => '/!{2,}/', 'weight' => 1.0, 'target' => 'any', 'description' => 'Spam: multiple exclamation marks', 'suggestion' => 'Use at most one exclamation mark'],
    ['category' => 'spam', 'name' => 'multiple_question', 'pattern' => '/\?{2,}/', 'weight' => 0.8, 'target' => 'any', 'description' => 'Spam: multiple question marks', 'suggestion' => 'Reduce punctuation in subject'],
    ['category' => 'spam', 'name' => 'all_caps_word', 'pattern' => '/\b[A-Z]{4,}\b/', 'weight' => 0.6, 'target' => 'subject', 'description' => 'Spam: ALL CAPS words in subject', 'suggestion' => 'Use sentence case in subject line'],
    ['category' => 'spam', 'name' => 'dear_friend', 'pattern' => '/\bdear\s+friend\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Spam: dear friend', 'suggestion' => 'Personalize with recipient name'],
    ['category' => 'spam', 'name' => 'dear_customer', 'pattern' => '/\bdear\s+(customer|user|member)\b/i', 'weight' => 0.5, 'target' => 'any', 'description' => 'Spam: generic greeting', 'suggestion' => 'Use recipient name when possible'],
    ['category' => 'spam', 'name' => 'viagra', 'pattern' => '/\bviagra\b/i', 'weight' => 3.0, 'target' => 'any', 'description' => 'Spam: pharmaceutical spam', 'suggestion' => 'Remove pharmaceutical spam terms'],
    ['category' => 'spam', 'name' => 'cialis', 'pattern' => '/\bcialis\b/i', 'weight' => 3.0, 'target' => 'any', 'description' => 'Spam: pharmaceutical spam', 'suggestion' => 'Remove pharmaceutical spam terms'],
    ['category' => 'spam', 'name' => 'weight_loss', 'pattern' => '/\bweight\s+loss\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Spam: weight loss', 'suggestion' => 'Health claims trigger filters'],
    ['category' => 'spam', 'name' => 'miracle', 'pattern' => '/\bmiracle\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Spam: miracle', 'suggestion' => 'Avoid miracle/sensational claims'],
    ['category' => 'spam', 'name' => 'congratulations', 'pattern' => '/\bcongratulations\b/i', 'weight' => 0.8, 'target' => 'subject', 'description' => 'Spam: congratulations in subject', 'suggestion' => 'Congrats in subject without context looks like spam'],

    // Trust positive (negative weight)
    ['category' => 'trust_positive', 'name' => 'unsubscribe', 'pattern' => '/\bunsubscribe\b/i', 'weight' => -0.8, 'target' => 'any', 'description' => 'Good: unsubscribe present', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'physical_address', 'pattern' => '/\b\d+\s+\w+\s+(street|st|avenue|ave|road|rd|blvd)\b/i', 'weight' => -0.5, 'target' => 'any', 'description' => 'Good: physical address', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'privacy_policy', 'pattern' => '/\bprivacy\s+policy\b/i', 'weight' => -0.4, 'target' => 'any', 'description' => 'Good: privacy policy link', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'view_in_browser', 'pattern' => '/\bview\s+in\s+browser\b/i', 'weight' => -0.3, 'target' => 'any', 'description' => 'Good: view in browser', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'company_name', 'pattern' => '/\b(inc|llc|ltd|corp)\b/i', 'weight' => -0.2, 'target' => 'any', 'description' => 'Good: company identifier', 'suggestion' => null],

    // Additional urgency
    ['category' => 'urgency', 'name' => 'only_today', 'pattern' => '/\bonly\s+today\b/i', 'weight' => 0.9, 'target' => 'any', 'description' => 'Urgency: only today', 'suggestion' => 'Specify real deadline'],
    ['category' => 'urgency', 'name' => 'while_supplies', 'pattern' => '/\bwhile\s+supplies\s+last\b/i', 'weight' => 0.7, 'target' => 'any', 'description' => 'Urgency: while supplies last', 'suggestion' => 'Avoid scarcity clichés'],
    ['category' => 'urgency', 'name' => 'once_in_lifetime', 'pattern' => '/\bonce\s+in\s+a\s+lifetime\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Urgency: once in lifetime', 'suggestion' => 'Hyperbolic claims hurt deliverability'],

    // Additional promotion
    ['category' => 'promotion', 'name' => 'limited_offer', 'pattern' => '/\blimited\s+offer\b/i', 'weight' => 0.7, 'target' => 'any', 'description' => 'Promotional: limited offer', 'suggestion' => 'Be specific about the offer limits'],
    ['category' => 'promotion', 'name' => 'best_price', 'pattern' => '/\bbest\s+price\b/i', 'weight' => 0.5, 'target' => 'any', 'description' => 'Promotional: best price', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'lowest_price', 'pattern' => '/\blowest\s+price\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: lowest price', 'suggestion' => 'Superlative claims can trigger filters'],
    ['category' => 'promotion', 'name' => 'shop_now', 'pattern' => '/\bshop\s+now\b/i', 'weight' => 0.5, 'target' => 'any', 'description' => 'Promotional: shop now', 'suggestion' => 'Use varied CTA text'],
    ['category' => 'promotion', 'name' => 'subscribe_save', 'pattern' => '/\bsubscribe\s+(and\s+)?save\b/i', 'weight' => 0.4, 'target' => 'any', 'description' => 'Promotional: subscribe and save', 'suggestion' => null],

    // Additional money
    ['category' => 'money', 'name' => 'no_credit_check', 'pattern' => '/\bno\s+credit\s+check\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Money: no credit check', 'suggestion' => 'Financial spam pattern'],
    ['category' => 'money', 'name' => 'low_interest', 'pattern' => '/\blow\s+interest\s+rate\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Money: low interest', 'suggestion' => 'Loan spam pattern'],
    ['category' => 'money', 'name' => 'refinance', 'pattern' => '/\brefinanc/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Money: refinance', 'suggestion' => 'Financial offers need strong authentication'],
    ['category' => 'money', 'name' => 'credit_score', 'pattern' => '/\bcredit\s+score\b/i', 'weight' => 0.9, 'target' => 'any', 'description' => 'Money: credit score', 'suggestion' => 'Sensitive financial topic'],
    ['category' => 'money', 'name' => 'bitcoin', 'pattern' => '/\b(bitcoin|crypto|nft)\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Money: crypto terms', 'suggestion' => 'Crypto language has high spam association'],

    // Additional shady
    ['category' => 'shady', 'name' => 'update_payment', 'pattern' => '/\bupdate\s+(your\s+)?payment\b/i', 'weight' => 1.8, 'target' => 'any', 'description' => 'Shady: update payment', 'suggestion' => 'Payment update requests look like phishing'],
    ['category' => 'shady', 'name' => 'unusual_activity', 'pattern' => '/\bunusual\s+activity\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Shady: unusual activity', 'suggestion' => 'Security scare tactics trigger filters'],
    ['category' => 'shady', 'name' => 'claim_now', 'pattern' => '/\bclaim\s+(your\s+)?(prize|reward|now)\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Shady: claim prize', 'suggestion' => 'Prize claim language is phishing pattern'],
    ['category' => 'shady', 'name' => 'wire_transfer', 'pattern' => '/\bwire\s+transfer\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Shady: wire transfer', 'suggestion' => 'Never use wire transfer language in marketing'],
    ['category' => 'shady', 'name' => 'social_security', 'pattern' => '/\bsocial\s+security\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Shady: social security', 'suggestion' => 'Government impersonation risk'],

    // Additional spam
    ['category' => 'spam', 'name' => 'work_from_home', 'pattern' => '/\bwork\s+from\s+home\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Spam: work from home', 'suggestion' => 'Common spam category'],
    ['category' => 'spam', 'name' => 'be_your_own_boss', 'pattern' => '/\bbe\s+your\s+own\s+boss\b/i', 'weight' => 1.3, 'target' => 'any', 'description' => 'Spam: be your own boss', 'suggestion' => 'MLM/spam pattern'],
    ['category' => 'spam', 'name' => 'multi_level', 'pattern' => '/\b(multi[\s-]level|mlm)\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Spam: MLM', 'suggestion' => 'MLM language is filtered aggressively'],
    ['category' => 'spam', 'name' => 'adult_content', 'pattern' => '/\b(adult|xxx|porn)\b/i', 'weight' => 3.0, 'target' => 'any', 'description' => 'Spam: adult content', 'suggestion' => 'Remove adult content references'],
    ['category' => 'spam', 'name' => 'opt_in', 'pattern' => '/\byou\s+(are\s+)?receiving\s+this\b/i', 'weight' => -0.3, 'target' => 'any', 'description' => 'Good: opt-in notice', 'suggestion' => null],

    // Combo patterns (higher weight)
    ['category' => 'spam', 'name' => 'free_act_now', 'pattern' => '/\bfree\b.{0,30}\bact\s+now\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Spam combo: free + act now', 'suggestion' => 'Stacked promotional + urgency phrases multiply spam score'],
    ['category' => 'spam', 'name' => 'limited_free', 'pattern' => '/\blimited\b.{0,30}\bfree\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Spam combo: limited + free', 'suggestion' => 'Avoid stacking scarcity with free offers'],
    ['category' => 'spam', 'name' => 'winner_click', 'pattern' => '/\bwinner\b.{0,50}\bclick\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Spam combo: winner + click', 'suggestion' => 'Classic phishing pattern combination'],

    // Extended urgency
    ['category' => 'urgency', 'name' => 'respond_immediately', 'pattern' => '/\brespond\s+immediately\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Urgency: respond immediately', 'suggestion' => 'Soften call to action'],
    ['category' => 'urgency', 'name' => 'today_only', 'pattern' => '/\btoday\s+only\b/i', 'weight' => 0.9, 'target' => 'any', 'description' => 'Urgency: today only', 'suggestion' => null],
    ['category' => 'urgency', 'name' => 'before_midnight', 'pattern' => '/\bbefore\s+midnight\b/i', 'weight' => 1.0, 'target' => 'any', 'description' => 'Urgency: before midnight', 'suggestion' => null],
    ['category' => 'urgency', 'name' => 'dont_wait', 'pattern' => '/\bdon\'?t\s+wait\b/i', 'weight' => 0.7, 'target' => 'any', 'description' => 'Urgency: don\'t wait', 'suggestion' => null],
    ['category' => 'urgency', 'name' => 'immediate_attention', 'pattern' => '/\bimmediate\s+attention\b/i', 'weight' => 1.1, 'target' => 'any', 'description' => 'Urgency: immediate attention', 'suggestion' => null],

    // Extended promotion
    ['category' => 'promotion', 'name' => 'no_cost', 'pattern' => '/\bno\s+cost\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: no cost', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'bonus', 'pattern' => '/\b(bonus|bonuses)\b/i', 'weight' => 0.5, 'target' => 'any', 'description' => 'Promotional: bonus', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'gift_card', 'pattern' => '/\bgift\s+card\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: gift card', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'new_arrival', 'pattern' => '/\bnew\s+arrivals?\b/i', 'weight' => 0.3, 'target' => 'any', 'description' => 'Promotional: new arrival', 'suggestion' => null],
    ['category' => 'promotion', 'name' => 'clearance_sale', 'pattern' => '/\bclearance\s+sale\b/i', 'weight' => 0.6, 'target' => 'any', 'description' => 'Promotional: clearance sale', 'suggestion' => null],

    // Extended money
    ['category' => 'money', 'name' => 'pre_approved', 'pattern' => '/\bpre[\s-]approved\b/i', 'weight' => 1.3, 'target' => 'any', 'description' => 'Money: pre-approved', 'suggestion' => 'Loan spam signal'],
    ['category' => 'money', 'name' => 'consolidate_debt', 'pattern' => '/\bconsolidate\s+debt\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Money: consolidate debt', 'suggestion' => null],
    ['category' => 'money', 'name' => 'eliminate_debt', 'pattern' => '/\beliminate\s+debt\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Money: eliminate debt', 'suggestion' => null],
    ['category' => 'money', 'name' => 'hidden_charges', 'pattern' => '/\bno\s+hidden\s+charges\b/i', 'weight' => 0.7, 'target' => 'any', 'description' => 'Money: no hidden charges', 'suggestion' => null],
    ['category' => 'money', 'name' => 'million_dollars', 'pattern' => '/\bmillion\s+dollars?\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Money: million dollars', 'suggestion' => null],

    // Extended shady
    ['category' => 'shady', 'name' => 'nigerian_prince', 'pattern' => '/\b(dear\s+friend|kindly|beneficiary)\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Shady: scam phrasing', 'suggestion' => null],
    ['category' => 'shady', 'name' => 'bank_details', 'pattern' => '/\b(bank\s+details|account\s+number|routing\s+number)\b/i', 'weight' => 1.8, 'target' => 'any', 'description' => 'Shady: bank details request', 'suggestion' => 'Never request banking info in marketing email'],
    ['category' => 'shady', 'name' => 'ssn_request', 'pattern' => '/\b(ssn|social\s+security\s+number)\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Shady: SSN request', 'suggestion' => null],
    ['category' => 'shady', 'name' => 'password_reset', 'pattern' => '/\breset\s+(your\s+)?password\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Shady: password reset', 'suggestion' => 'Phishing pattern unless transactional'],
    ['category' => 'shady', 'name' => 'confirm_billing', 'pattern' => '/\bconfirm\s+billing\b/i', 'weight' => 1.6, 'target' => 'any', 'description' => 'Shady: confirm billing', 'suggestion' => null],

    // Extended spam
    ['category' => 'spam', 'name' => 'enlargement', 'pattern' => '/\b(enlargement|enhancement)\b/i', 'weight' => 2.5, 'target' => 'any', 'description' => 'Spam: enhancement spam', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'rolex', 'pattern' => '/\brolex\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Spam: replica watch spam', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'nigerian_letter', 'pattern' => '/\bconfidential\s+business\b/i', 'weight' => 1.8, 'target' => 'any', 'description' => 'Spam: 419 scam pattern', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'online_pharmacy', 'pattern' => '/\bonline\s+pharmacy\b/i', 'weight' => 2.0, 'target' => 'any', 'description' => 'Spam: online pharmacy', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'diploma', 'pattern' => '/\b(online\s+degree|diploma\s+mill)\b/i', 'weight' => 1.2, 'target' => 'any', 'description' => 'Spam: diploma spam', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'replica', 'pattern' => '/\breplica\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Spam: replica goods', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'as_seen_on_tv', 'pattern' => '/\bas\s+seen\s+on\s+tv\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Spam: as seen on TV', 'suggestion' => null],
    ['category' => 'spam', 'name' => 'not_spam', 'pattern' => '/\bthis\s+is\s+not\s+spam\b/i', 'weight' => 1.5, 'target' => 'any', 'description' => 'Spam: denying spam', 'suggestion' => 'Never say "this is not spam"'],
    ['category' => 'spam', 'name' => 'bulk_email', 'pattern' => '/\bbulk\s+email\b/i', 'weight' => 0.8, 'target' => 'any', 'description' => 'Spam: bulk email mention', 'suggestion' => null],

    // More trust positive
    ['category' => 'trust_positive', 'name' => 'list_unsubscribe_header', 'pattern' => '/\blist-unsubscribe\b/i', 'weight' => -0.6, 'target' => 'any', 'description' => 'Good: list-unsubscribe', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'preferences', 'pattern' => '/\b(email\s+)?preferences\b/i', 'weight' => -0.3, 'target' => 'any', 'description' => 'Good: preferences link', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'copyright', 'pattern' => '/\b©|\bcopyright\b/i', 'weight' => -0.2, 'target' => 'any', 'description' => 'Good: copyright notice', 'suggestion' => null],
    ['category' => 'trust_positive', 'name' => 'phone_number', 'pattern' => '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', 'weight' => -0.3, 'target' => 'any', 'description' => 'Good: contact phone', 'suggestion' => null],

    // Subject-specific
    ['category' => 'spam', 'name' => 're_fw_spam', 'pattern' => '/^(re|fw|fwd):\s*(re|fw|fwd):/i', 'weight' => 0.8, 'target' => 'subject', 'description' => 'Spam: multiple Re/Fwd prefixes', 'suggestion' => null],
    ['category' => 'urgency', 'name' => 'open_immediately', 'pattern' => '/\bopen\s+immediately\b/i', 'weight' => 1.0, 'target' => 'subject', 'description' => 'Urgency in subject', 'suggestion' => null],
    ['category' => 'money', 'name' => 'invoice_attached', 'pattern' => '/\binvoice\s+attached\b/i', 'weight' => 1.0, 'target' => 'subject', 'description' => 'Money: invoice attached (phishing risk)', 'suggestion' => null],
];
