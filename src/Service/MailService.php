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
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;

class MailService implements MailServiceInterface
{
    // =========================================================================
    // Properties
    // =========================================================================

    /** @var array Laatste image errors voor logging door de caller */
    private array $lastImageErrors = [];

    // =========================================================================
    // Interface Methodes
    // =========================================================================

    /**
     * Verstuurt een e-mail.
     */
    public function sendMail(string $recipient, string $subject, string $body, array $placeholders = [], bool $isHtml = false): bool
    {
        $app = Factory::getApplication();

        // Vervang placeholders.
        // LET OP: strtr() i.p.v. een lus van str_replace()-aanroepen om twee
        // redenen:
        // 1) strtr() met een array vervangt alle tokens in ÉÉN gelijktijdige
        //    pass. Een lus van str_replace() zou, als een waarde toevallig
        //    zelf een ander token bevat (bv. iemand registreert met naam
        //    "#link"), dat token in een latere iteratie ALSNOG vervangen --
        //    een subtiele, volgorde-afhankelijke bug.
        // 2) Voor HTML-mails escapen we de waarden eerst met htmlspecialchars().
        //    #name/#email/#reason kunnen (deels) door de eindgebruiker zelf
        //    zijn ingevuld (registratienaam, afkeurreden); zonder escaping
        //    belandt hun ruwe invoer ongefilterd in de HTML-mailbody (HTML-
        //    injectie in uitgaande mail). De subject-regel blijft WEL
        //    ongeëscaped: e-mailonderwerpen zijn platte tekst, geen HTML.
        $subject = strtr($subject, array_map('strval', $placeholders));

        $bodyPlaceholders = array_map('strval', $placeholders);
        if ($isHtml) {
            $bodyPlaceholders = array_map(
                static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'),
                $bodyPlaceholders
            );
        }
        $body = strtr($body, $bodyPlaceholders);

        $mailer = Factory::getMailer();
        $mailer->setSender([$app->get('mailfrom'), $app->get('fromname')]);
        $mailer->addRecipient($recipient);
        $mailer->setSubject($subject);
        $mailer->isHtml($isHtml);

        // Verwerk afbeeldingen voor CID embedding (alleen voor HTML)
        $this->lastImageErrors = []; // Reset
        if ($isHtml) {
            $body = $this->processImagesForCidEmbedding($body, $mailer);
        }

        $mailer->setBody($body);

        // Stuur beheerder melding bij fouten
        if (!empty($this->lastImageErrors)) {
            $this->notifyAdminAboutImageErrors($this->lastImageErrors);
        }

        return $mailer->send();
    }

    /**
     * Bouwt een mail body vanaf een template met placeholders.
     */
    public function buildMailBody(string $template, array $placeholders = []): string
    {
        // strtr(): zie toelichting bij sendMail() -- voorkomt volgorde-
        // afhankelijke token-botsingen tussen placeholders.
        return strtr($template, array_map('strval', $placeholders));
    }

    // =========================================================================
    // Image Error Accessor (voor logging door caller)
    // =========================================================================

    /**
     * Retourneert de laatste image errors voor logging door de caller.
     * @return array
     */
    public function getLastImageErrors(): array
    {
        return $this->lastImageErrors;
    }

    // =========================================================================
    // CID Embedding Validatie
    // =========================================================================

    /**
     * Valideert een afbeeldings-URL voor CID embedding.
     */
    public function validateImageForEmbedding(string $imageUrl): array
    {
        if (empty(trim($imageUrl))) {
            return [
                'status' => 'not_found',
                'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_IMAGE_EMPTY_URL')
            ];
        }

        if (strpos($imageUrl, 'data:') === 0) {
            return [
                'status' => 'external',
                'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_IMAGE_DATA_URI_NOT_SUPPORTED')
            ];
        }

        $absolutePath = $this->getAbsoluteImagePath($imageUrl);
        if ($absolutePath === null) {
            return [
                'status' => 'external',
                'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_IMAGE_EXTERNAL')
            ];
        }

        // Controleer of bestand in /media/ OF /images/ folder zit
        $allowedFolders = [JPATH_ROOT . '/media', JPATH_ROOT . '/images'];
        $isInAllowedFolder = false;
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);

        foreach ($allowedFolders as $folder) {
            $normalizedFolder = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $folder . DIRECTORY_SEPARATOR);
            if (strpos($normalizedPath, $normalizedFolder) === 0) {
                $isInAllowedFolder = true;
                break;
            }
        }

        if (!$isInAllowedFolder) {
            return [
                'status' => 'external',
                'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_IMAGE_NOT_ALLOWED_FOLDER')
            ];
        }

        if (!file_exists($absolutePath)) {
            return [
                'status' => 'not_found',
                'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_IMAGE_NOT_FOUND')
            ];
        }

        $fileSize = filesize($absolutePath);
        if ($fileSize === false || $fileSize > 512000) {
            return [
                'status' => 'too_large',
                'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_IMAGE_TOO_LARGE')
            ];
        }

        return ['status' => 'ok', 'message' => ''];
    }

    /**
     * Converteert een afbeeldings-URL naar een absoluut bestandspad.
     */
    private function getAbsoluteImagePath(string $imageUrl): ?string
    {
        $uri = Uri::getInstance($imageUrl);
        if ($uri->getHost()) {
            $rootUri = Uri::getInstance(Uri::root());
            if ($uri->getHost() !== $rootUri->getHost()) {
                return null;
            }
            $path = $uri->getPath();
            if (empty($path) || $path === '/') {
                return null;
            }
            return JPATH_ROOT . $path;
        }

        $path = ltrim($uri->getPath(), '/');
        if (empty($path)) {
            return null;
        }
        return JPATH_ROOT . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Converteert een relatieve URL naar absolute URL.
     */
    public function getAbsoluteImageUrl(string $imageUrl): string
    {
        $uri = Uri::getInstance($imageUrl);
        if ($uri->getHost()) {
            return $imageUrl;
        }
        return Uri::root() . ltrim($imageUrl, '/');
    }

    // =========================================================================
    // CID Embedding Processing
    // =========================================================================

    /**
     * Verwerkt afbeeldingen in HTML body voor CID embedding.
     */
    private function processImagesForCidEmbedding(string $body, $mailer): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // LET OP: geen mb_convert_encoding(..., 'HTML-ENTITIES', ...) meer --
        // dat is sinds PHP 8.2 deprecated. De <?xml encoding="UTF-8">-declaratie
        // hieronder is de huidige, niet-verouderde manier om DOMDocument te
        // vertellen dat de input UTF-8 is; libxml verwijdert deze declaratie
        // automatisch uit de geparste boom.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $images = $dom->getElementsByTagName('img');
        if ($images->length === 0) {
            return $body;
        }

        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if (empty($src)) {
                continue;
            }

            $validation = $this->validateImageForEmbedding($src);
            $status = $validation['status'];

            switch ($status) {
                case 'ok':
                    $this->embedImageAsCid($img, $src, $mailer);
                    break;
                case 'too_large':
                    $this->lastImageErrors[] = [
                        'url' => $src,
                        'status' => 'too_large',
                        'message' => $validation['message']
                    ];
                    $img->setAttribute('src', $this->getAbsoluteImageUrl($src));
                    break;
                case 'not_found':
                    $img->parentNode->removeChild($img);
                    $this->lastImageErrors[] = [
                        'url' => $src,
                        'status' => 'not_found',
                        'message' => $validation['message']
                    ];
                    break;
                case 'external':
                    $img->setAttribute('src', $this->getAbsoluteImageUrl($src));
                    break;
            }
        }

        return $dom->saveHTML();
    }

    /**
     * Embed een afbeelding als CID in de mailer.
     */
    private function embedImageAsCid($img, string $imagePath, $mailer): void
    {
        $absolutePath = $this->getAbsoluteImagePath($imagePath);
        if ($absolutePath === null || !file_exists($absolutePath)) {
            return;
        }

        $cid = 'simplelogin_' . md5($absolutePath . microtime());
        $mimeType = $this->getMimeType($absolutePath);
        $imageContent = file_get_contents($absolutePath);

        if ($imageContent === false) {
            return;
        }

        $mailer->addStringEmbeddedImage(
            $imageContent,
            $cid,
            basename($absolutePath),
            'base64',
            $mimeType,
            'inline'
        );

        $img->setAttribute('src', 'cid:' . $cid);
    }

    /**
     * Bepaalt het MIME type van een bestand.
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        ];
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    // =========================================================================
    // Beheerder Meldingen
    // =========================================================================

    /**
     * Stuur beheerder melding over afbeeldingsfouten.
     */
    private function notifyAdminAboutImageErrors(array $imageErrors): void
    {
        $app = Factory::getApplication();
        $adminEmail = $app->get('mailfrom');
        $siteName = $app->get('sitename');

        if (empty($adminEmail)) {
            return;
        }

        $subject = Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_IMAGE_ERROR_SUBJECT', $siteName);
        $errorMessages = [];
        foreach ($imageErrors as $error) {
            $errorMessages[] = Text::sprintf(
                'PLG_SYSTEM_SIMPLELOGIN_IMAGE_ERROR_ITEM',
                $error['url'],
                Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_' . strtoupper($error['status']))
            );
        }
        $body = Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_IMAGE_ERROR_BODY', $siteName, implode("\n", $errorMessages));

        $mailer = Factory::getMailer();
        $mailer->setSender([$app->get('mailfrom'), $app->get('fromname')]);
        $mailer->addRecipient($adminEmail);
        $mailer->setSubject($subject);
        $mailer->setBody($body);
        $mailer->isHtml(false);
        $mailer->send();
    }
}