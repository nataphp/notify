<?php
/**
 * Notify plugin (NataPHP).
 *
 * Shared notification delivery (in-app, email, web push, SMS) with outbox fallback.
 *
 * @package Notify
 */
namespace Notify\Sms;

/**
 * Pure rules of an SMS: encoding, segments, phone numbers and sender IDs.
 *
 * A text that only uses the GSM 03.38 alphabet goes in GSM-7 (160 characters,
 * 153 per segment when split; the extension characters count twice). Any
 * other character (ã, õ, ç, á, emoji…) switches the whole message to UCS-2
 * (70 characters, 67 per segment). Portuguese text with "ã" or "ç" is UCS-2.
 */
final class SmsText {

    const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    const GSM7_EXTENSION = "^{}\\[~]|€\f";

    /**
     * Whether the whole text fits the GSM-7 alphabet.
     *
     * @param string $text Message text (UTF-8).
     * @return bool True for GSM-7, false when it needs UCS-2.
     */
    public static function isGsm7(string $text): bool {
        foreach (mb_str_split($text) as $character) {
            if (mb_strpos(self::GSM7_BASIC, $character) === false && mb_strpos(self::GSM7_EXTENSION, $character) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Characters outside GSM-7 with a close GSM-7 spelling (Portuguese and the
     * usual typography). What is GSM-7 already (é, à, Ç, ü…) stays.
     */
    const TRANSLITERATION = [
        'á' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'À' => 'A',
        'ç' => 'c', 'ê' => 'e', 'ë' => 'e', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
        'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'ú' => 'u', 'û' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U',
        'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
        'º' => 'o', 'ª' => 'a', '°' => 'o',
        '‘' => "'", '’' => "'", '‚' => "'", '´' => "'", '`' => "'",
        '“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"',
        '–' => '-', '—' => '-', '‑' => '-', '…' => '...', '•' => '-', '·' => '.',
        "\u{00A0}" => ' ', "\t" => ' ',
    ];

    /**
     * The text in the GSM-7 alphabet: the characters in TRANSLITERATION get
     * their spelling there, any other non GSM-7 character (emoji, other
     * scripts) is dropped, and runs of spaces collapse. A text that stays
     * GSM-7 fits 160 characters per message instead of 70.
     *
     * @param string $text Message text (UTF-8).
     * @return string GSM-7 text.
     */
    public static function toGsm7(string $text): string {
        $result = '';
        foreach (mb_str_split($text) as $character) {
            if (mb_strpos(self::GSM7_BASIC, $character) !== false || mb_strpos(self::GSM7_EXTENSION, $character) !== false) {
                $result .= $character;
                continue;
            }
            if (isset(self::TRANSLITERATION[$character])) {
                $result .= self::TRANSLITERATION[$character];
            }
        }

        return trim((string)preg_replace('/ {2,}/', ' ', $result));
    }

    /**
     * Number of GSM-7 units a GSM-7 text takes (the extension characters,
     * like €, take two).
     *
     * @param string $text GSM-7 text.
     * @return int Units.
     */
    public static function gsm7Length(string $text): int {
        $units = 0;
        foreach (mb_str_split($text) as $character) {
            $units += mb_strpos(self::GSM7_EXTENSION, $character) === false ? 1 : 2;
        }

        return $units;
    }

    /**
     * Number of segments (billed messages) the text takes.
     *
     * @param string $text Message text (UTF-8).
     * @return int Segments, 0 for an empty text.
     */
    public static function segments(string $text): int {
        if ($text === '') {
            return 0;
        }
        if (self::isGsm7($text)) {
            $units = self::gsm7Length($text);

            return $units <= 160 ? 1 : (int)ceil($units / 153);
        }
        // UCS-2 counts UTF-16 code units: an emoji takes two.
        $units = intdiv(strlen((string)mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);

        return $units <= 70 ? 1 : (int)ceil($units / 67);
    }

    /**
     * Whether a phone number is in E.164 (+, country code, up to 15 digits).
     *
     * @param string $phone Phone number.
     * @return bool True when valid.
     */
    public static function isE164(string $phone): bool {
        return preg_match('/^\+[1-9][0-9]{6,14}$/', $phone) === 1;
    }

    /**
     * Whether a sender ID is valid: an alphanumeric name of 1 to 11 letters,
     * digits and spaces (with at least one letter), or a phone number of up
     * to 15 digits (optionally with +).
     *
     * @param string $sender Sender ID.
     * @return bool True when valid.
     */
    public static function isValidSender(string $sender): bool {
        if (preg_match('/^\+?[0-9]{3,15}$/', $sender) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9 ]{1,11}$/', $sender) === 1 && preg_match('/[A-Za-z]/', $sender) === 1;
    }

}
