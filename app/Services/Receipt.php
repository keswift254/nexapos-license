<?php

declare(strict_types=1);

namespace License\Services;

/**
 * The email a customer gets the moment their payment is confirmed: what they paid
 * for, how much, when, the reference - and how to reach us if anything is wrong.
 *
 * Designed to read as the same product as the website and the welcome email (same
 * indigo, same card), in the table-and-inline-style form every mail client renders.
 * Deliberately shows NO email address of ours: help is "reply to this email" (the
 * Reply-To header points at the support inbox - see Purchases) or the WhatsApp
 * button. Paystack sends its own receipt as well unless that is switched off in the
 * Paystack dashboard; this one is ours, and is the one that says what NexaPOS did.
 */
final class Receipt
{
    public const WHATSAPP_URL = 'https://wa.me/message/M5SGWZ664XJ4C1';

    /**
     * @param array{label: string, length: string, amount_kes: int, reference: string, paid_at: string, lifetime: bool} $r
     * @return array{subject: string, text: string, html: string}
     */
    public static function build(array $r): array
    {
        $amount = 'KSh ' . number_format($r['amount_kes']);
        // "6 months (6 months)" says it twice: the length is added only when the name does not already say it.
        $samePlanAndLength = strcasecmp($r['label'], $r['length']) === 0;
        $planName = $samePlanAndLength ? $r['label'] : $r['label'] . ' (' . $r['length'] . ')';
        $activation = $r['lifetime']
            ? 'It never expires: pay once, use it for as long as you run your shop.'
            : 'Your license runs for ' . $r['length'] . ' from the moment your device activates.';

        $subject = 'Payment received - ' . $amount . ' - NexaPOS';
        $text = "Payment received - thank you!\n\n"
            . "Your NexaPOS payment went through and your license is being activated on the device you paid from - there is nothing to type.\n\n"
            . "Plan: $planName\n"
            . "Amount paid: $amount\n"
            . "Date: {$r['paid_at']}\n"
            . "Reference: {$r['reference']}\n\n"
            . "$activation\n\n"
            . "If you have any issue with your payment, reply directly to this email or message us on WhatsApp: " . self::WHATSAPP_URL . "\n\n"
            . '- The NexaPOS team';

        return ['subject' => $subject, 'text' => $text, 'html' => self::html($r, $amount, $activation)];
    }

    private static function html(array $r, string $amount, string $activation): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = $e($r['label']);
        $length = $e($r['length']);
        $reference = $e($r['reference']);
        $paidAt = $e($r['paid_at']);
        $amountHtml = $e($amount);
        $activationHtml = $e($activation);
        $whatsapp = self::WHATSAPP_URL;

        $rows = '';
        foreach ([
            ['Plan', strcasecmp($r['label'], $r['length']) === 0 ? $label : $label . ' <span style="color:#8a8aa0;">(' . $length . ')</span>'],
            ['Amount paid', '<b>' . $amountHtml . '</b>'],
            ['Date', $paidAt],
            ['Reference', '<span style="font-family:Consolas,Menlo,monospace;font-size:13px;">' . $reference . '</span>'],
        ] as [$name, $value]) {
            $rows .= <<<HTML
              <tr>
                <td style="padding: 11px 0; border-bottom: 1px solid #ececf4; color: #6b6b80; font-size: 14px; width: 40%;">{$name}</td>
                <td style="padding: 11px 0; border-bottom: 1px solid #ececf4; color: #14141f; font-size: 14px; text-align: right;">{$value}</td>
              </tr>
            HTML;
        }

        return <<<HTML
        <div style="background: #f7f7fb; padding: 28px 12px; font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;">
          <div style="max-width: 480px; margin: 0 auto;">
            <div style="background: #4f46e5; padding: 26px 20px 22px; text-align: center; border-radius: 14px 14px 0 0;">
              <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 12px; display: inline-block; color: #4f46e5; font-size: 20px; font-weight: 700; line-height: 44px; text-align: center; margin-bottom: 8px;">N</div>
              <div style="color: #ffffff; font-size: 17px; font-weight: 700; letter-spacing: 0.2px;">NexaPOS</div>
            </div>
            <div style="background: #ffffff; padding: 30px 26px 26px; border-radius: 0 0 14px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
              <div style="text-align: center;">
                <div style="width: 54px; height: 54px; background: #dcfce7; border-radius: 50%; display: inline-block; color: #16a34a; font-size: 28px; font-weight: 700; line-height: 54px; text-align: center;">&#10003;</div>
                <h1 style="font-size: 22px; margin: 14px 0 6px; color: #14141f;">Payment received</h1>
                <p style="color: #6b6b80; font-size: 14px; line-height: 1.5; margin: 0 0 18px;">Thank you! Your license is being activated on the device you paid from - there is nothing to type.</p>
                <div style="font-size: 34px; font-weight: 800; color: #4f46e5; letter-spacing: -0.5px; margin: 0 0 22px;">{$amountHtml}</div>
              </div>

              <table role="presentation" style="width: 100%; border-collapse: collapse; margin: 0 0 20px;">
                {$rows}
              </table>

              <div style="background: #eef2ff; border-radius: 10px; padding: 14px 16px; margin: 0 0 22px;">
                <div style="color: #3730a3; font-weight: 700; font-size: 13px; margin: 0 0 4px;">What happens next</div>
                <div style="color: #3f3f55; font-size: 13.5px; line-height: 1.55;">You can close the payment page and go back to NexaPOS. {$activationHtml}</div>
              </div>

              <div style="border: 1px solid #e6e6f0; border-radius: 10px; padding: 18px 16px; text-align: center;">
                <div style="color: #14141f; font-weight: 700; font-size: 14px; margin: 0 0 6px;">Any issue with your payment?</div>
                <div style="color: #6b6b80; font-size: 13.5px; line-height: 1.55; margin: 0 0 14px;">Reply directly to this email, or tap the WhatsApp button below to start a chat with us.</div>
                <a href="{$whatsapp}" style="display: inline-block; background: #25D366; color: #ffffff; text-decoration: none; padding: 13px 30px; border-radius: 8px; font-size: 14px; font-weight: 700;">Chat on WhatsApp</a>
              </div>
            </div>
            <p style="text-align: center; color: #8a8aa0; font-size: 12px; margin: 16px 0 0;">&copy; 2026 NexaPOS</p>
          </div>
        </div>
        HTML;
    }
}
