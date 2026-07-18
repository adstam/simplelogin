<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace StamPlusJ\Plugin\System\Simplelogin\Service;

defined('_JEXEC') or die;

/**
 * Interface for the Mail Service.
 * Provides mail sending and template building capabilities.
 */
interface MailServiceInterface
{
    /**
     * Sends an email to the specified recipient.
     *
     * @param string $recipient The email address of the recipient
     * @param string $subject   The email subject
     * @param string $body      The email body
     *
     * @return bool True on success, false on failure
     */
    public function sendMail(string $recipient, string $subject, string $body): bool;

    /**
     * Builds a mail body by replacing placeholders in a template.
     *
     * @param string $template     The email template with placeholders
     * @param array  $placeholders  Associative array of placeholder => value pairs
     *
     * @return string The processed mail body
     */
    public function buildMailBody(string $template, array $placeholders): string;
}