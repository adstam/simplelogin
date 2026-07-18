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

use Joomla\CMS\Factory;

/**
 * Mail Service for SimpleLogin plugin.
 * Handles all email-related operations including sending emails and building
 * email templates with placeholder replacement.
 */
class MailService implements MailServiceInterface
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
    public function sendMail(string $recipient, string $subject, string $body): bool
    {
        $mailer = Factory::getMailer();
        $config = Factory::getApplication()->getConfig();
        $sender = [$config->get('mailfrom'), $config->get('fromname')];

        $mailer->setSender($sender);
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->isHTML(true);
        $mailer->setBody($body);

        return $mailer->send();
    }

    /**
     * Builds a mail body by replacing placeholders in a template.
     * Placeholders are in the format #placeholder# and are replaced with their
     * corresponding values from the $placeholders array.
     *
     * If the template does not contain a #link# placeholder but a link is provided,
     * the link is automatically appended to the body.
     *
     * @param string $template    The email template with placeholders
     * @param array  $placeholders Associative array of placeholder => value pairs
     *
     * @return string The processed mail body
     */
    public function buildMailBody(string $template, array $placeholders): string
    {
        // Sanitize placeholder values
        $replacements = array_map(
            fn($value) => is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : (string)$value,
            $placeholders
        );

        $result = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );

        // Auto-append link if template doesn't contain #link# placeholder
        if (isset($placeholders['#link']) && !str_contains($template, '#link')) {
            $result .= "\n\n" . $placeholders['#link'];
        }

        return $result;
    }
}