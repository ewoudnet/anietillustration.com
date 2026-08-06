<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    /**
     * @param array<string,mixed> $order
     * @throws PHPMailerException
     */
    public static function sendOrderConfirmation(array $order): void
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = Config::get('MAIL_HOST', '');
        $mail->SMTPAuth = true;
        $mail->Username = Config::get('MAIL_USERNAME', '');
        $mail->Password = Config::get('MAIL_PASSWORD', '');
        $mail->SMTPSecure = Config::get('MAIL_ENCRYPTION', 'tls');
        $mail->Port = Config::getInt('MAIL_PORT', 587);
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            Config::get('MAIL_FROM_ADDRESS', 'info@example.com') ?? 'info@example.com',
            Config::get('MAIL_FROM_NAME', 'Aniet Illustration') ?? 'Aniet Illustration'
        );
        $mail->addAddress($order['email'], $order['first_name'] . ' ' . $order['last_name']);

        $mail->isHTML(true);
        $mail->Subject = 'Bevestiging van je bestelling ' . $order['order_reference'];
        $mail->Body = self::renderHtml($order);
        $mail->AltBody = self::renderText($order);

        $mail->send();
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function renderHtml(array $order): string
    {
        $specialTitle = htmlspecialchars((string) ($order['special_title'] ?? 'Special'), ENT_QUOTES, 'UTF-8');
        $variantLabel = htmlspecialchars((string) ($order['variant_label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $reference = htmlspecialchars((string) $order['order_reference'], ENT_QUOTES, 'UTF-8');
        $firstName = htmlspecialchars((string) $order['first_name'], ENT_QUOTES, 'UTF-8');
        $quantity = (int) $order['quantity'];
        $total = number_format(((int) $order['total_amount_cents']) / 100, 2, ',', '.');
        $address = htmlspecialchars(
            sprintf('%s %s, %s %s, %s', $order['street'], $order['house_number'], $order['postal_code'], $order['city'], Countries::ALL[$order['country_code']] ?? $order['country_code']),
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
            <div style="font-family: Arial, sans-serif; color: #012b55; max-width: 560px; margin: 0 auto; background: #fff6fb; border-radius: 14px; padding: 28px;">
                <h2 style="color: #c13873; margin-top: 0;">Hartstikke bedankt voor je bestelling, {$firstName}! 🎉</h2>
                <p>Hartstikke bedankt voor je bestelling bij "{$specialTitle}" van Aniet Illustration.</p>
                <table style="width: 100%; border-collapse: collapse; margin: 16px 0; background: #ffffff; border-radius: 8px;">
                    <tr><td style="padding: 8px 12px;"><strong>Bestelnummer</strong></td><td style="padding: 8px 12px;">{$reference}</td></tr>
                    <tr style="background: #ffbadd33;"><td style="padding: 8px 12px;"><strong>Bestelling</strong></td><td style="padding: 8px 12px;">{$variantLabel} x {$quantity}</td></tr>
                    <tr><td style="padding: 8px 12px;"><strong>Totaal betaald</strong></td><td style="padding: 8px 12px;">€ {$total}</td></tr>
                    <tr style="background: #ffbadd33;"><td style="padding: 8px 12px;"><strong>Verzendadres</strong></td><td style="padding: 8px 12px;">{$address}</td></tr>
                </table>
                <p>Ik hoor het graag als je nog vragen hebt!</p>
                <p>Nogmaals hartstikke bedankt voor je support!</p>
                <p>Een hele fijne dag,<br>Met vriendelijke groet,<br>Anita</p>
                <p>
                    <a href="https://www.anietillustration.com" style="color: #c13873;">www.anietillustration.com</a><br>
                    <a href="https://www.instagram.com/aniet_illustration" style="color: #c13873;">www.instagram.com/aniet_illustration</a>
                </p>
            </div>
        HTML;
    }

    /**
     * @param array<string,mixed> $order
     */
    private static function renderText(array $order): string
    {
        $specialTitle = (string) ($order['special_title'] ?? 'Special');
        $total = number_format(((int) $order['total_amount_cents']) / 100, 2, ',', '.');

        return sprintf(
            "Hartstikke bedankt voor je bestelling bij \"%s\" van Aniet Illustration.\n\n" .
            "Bestelnummer: %s\nBestelling: %s x %d\nTotaal betaald: EUR %s\n\n" .
            "Ik hoor het graag als je nog vragen hebt!\n\n" .
            "Nogmaals hartstikke bedankt voor je support!\n\n" .
            "Een hele fijne dag,\nMet vriendelijke groet,\nAnita\n\n" .
            "www.anietillustration.com\nwww.instagram.com/aniet_illustration",
            $specialTitle,
            $order['order_reference'],
            $order['variant_label'] ?? '',
            (int) $order['quantity'],
            $total
        );
    }
}
