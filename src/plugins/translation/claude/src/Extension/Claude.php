<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Translation.claude
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Translation\Claude\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Translations\Administrator\Event\TranslateEvent;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\Http;

/**
 * Translation provider plugin that translates an item's strings with the Anthropic
 * Claude API. It answers the component's onTranslate event, returning the translated
 * strings or throwing so a failure reaches the user rather than passing silently.
 *
 * @since  0.4.0
 */
final class Claude extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  0.4.0
     */
    protected $autoloadLanguage = true;

    /**
     * The Anthropic Messages API endpoint.
     *
     * @var    string
     * @since  0.4.0
     */
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * The Anthropic API version, sent as the anthropic-version header.
     *
     * @var    string
     * @since  0.4.0
     */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Upper bound on the response length, large enough for a long item's translation.
     *
     * @var    integer
     * @since  0.4.0
     */
    private const MAX_TOKENS = 16000;

    /**
     * Seconds to wait for the API, generous because translating a long item takes a while.
     *
     * @var    integer
     * @since  0.4.0
     */
    private const TIMEOUT = 120;

    /**
     * The HTTP client used to call the API.
     *
     * @var    Http
     * @since  0.4.0
     */
    private Http $http;

    /**
     * Constructor.
     *
     * @param   array  $config  The plugin configuration.
     * @param   Http   $http    The HTTP client used to call the API.
     *
     * @since   0.4.0
     */
    public function __construct($config, Http $http)
    {
        parent::__construct($config);

        $this->http = $http;
    }

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    public static function getSubscribedEvents(): array
    {
        return ['onTranslate' => 'onTranslate'];
    }

    /**
     * Translate an item's strings with the Claude API.
     *
     * Reads the strings and languages from the event and adds the translation for the
     * component. A missing key or any API failure throws, so the reason reaches the user.
     *
     * @param   TranslateEvent  $event  The translate event.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the key is missing or the API call fails.
     *
     * @since   0.4.0
     */
    public function onTranslate(TranslateEvent $event): void
    {
        $apiKey = trim((string) $this->params->get('api_key', ''));

        if ($apiKey === '') {
            throw new \RuntimeException('No Claude API key is configured for the translation plugin.');
        }

        $translated = $this->requestTranslation(
            $event->getSourceStrings(),
            $event->getSourceLanguage(),
            $event->getTargetLanguage(),
            $apiKey
        );

        $event->addResult($translated);

        // One provider answers per item; stop the rest of the group running.
        $event->stopPropagation();
    }

    /**
     * Ask the Claude API to translate the strings and return them keyed as given.
     *
     * The whole collection is sent as one JSON object so the model keeps the context
     * between an item's strings; the prompt asks for the same keys back with only the
     * values translated.
     *
     * @param   array   $strings         The source strings keyed by field.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     * @param   string  $apiKey          The Anthropic API key.
     *
     * @return  array  The translated strings keyed by field.
     *
     * @throws  \RuntimeException  When the API cannot be reached or returns an error.
     *
     * @since   0.4.0
     */
    private function requestTranslation(array $strings, string $sourceLanguage, string $targetLanguage, string $apiKey): array
    {
        $payload = [
            'model'         => (string) $this->params->get('model', 'claude-sonnet-5'),
            'max_tokens'    => self::MAX_TOKENS,
            'system'        => $this->systemPrompt($sourceLanguage, $targetLanguage),
            'messages'      => [
                ['role' => 'user', 'content' => json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            // Constrain the reply to a JSON object with exactly these string keys, so a long
            // HTML value can never come back as prose or malformed JSON the decoder would reject.
            'output_config' => ['format' => $this->responseFormat(array_keys($strings))],
        ];

        $headers = [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type'      => 'application/json',
        ];

        try {
            $response = $this->http->post(self::ENDPOINT, json_encode($payload), $headers, self::TIMEOUT);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Could not reach the Claude API: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException($this->errorMessage($response->getStatusCode(), (string) $response->getBody()));
        }

        return $this->parseTranslation((string) $response->getBody(), array_keys($strings));
    }

    /**
     * Build a readable message from an error response, preferring the API's own message.
     *
     * @param   integer  $status  The HTTP status code.
     * @param   string   $body    The API response body.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    private function errorMessage(int $status, string $body): string
    {
        $decoded = json_decode($body, true);
        $message = $body;

        if (\is_array($decoded) && isset($decoded['error']['message']) && \is_string($decoded['error']['message'])) {
            $message = $decoded['error']['message'];
        }

        return \sprintf('Claude API error (HTTP %d): %s', $status, $message);
    }

    /**
     * Build the system prompt that tells the model how to translate.
     *
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    private function systemPrompt(string $sourceLanguage, string $targetLanguage): string
    {
        return \sprintf(
            'You are a professional translator. You receive a JSON object whose values are strings to translate from'
            . ' %s to %s. Translate only the human-readable text in each value; keep every key unchanged, and preserve'
            . ' HTML tags, placeholders and entities exactly. Respond with only the translated JSON object and nothing else.',
            $sourceLanguage,
            $targetLanguage
        );
    }

    /**
     * Build the structured-output format that pins the reply to a JSON object with
     * exactly the given string keys. The API then constrains the model to valid,
     * schema-matching JSON, so a long HTML value cannot return as prose or with the
     * broken escaping (stray quotes or newlines) that a free-form reply can carry.
     *
     * @param   array  $keys  The field keys expected back.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    private function responseFormat(array $keys): array
    {
        $properties = [];

        foreach ($keys as $key) {
            $properties[$key] = ['type' => 'string'];
        }

        return [
            'type'   => 'json_schema',
            'schema' => [
                'type'                 => 'object',
                'properties'           => $properties,
                'required'             => $keys,
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Pull the translated strings out of the API response.
     *
     * Reads the assistant's text, decodes the JSON object it holds, and keeps only the
     * expected keys with string values. Throws when the response holds no usable translation.
     *
     * @param   string  $body          The API response body.
     * @param   array   $expectedKeys  The field keys that were sent for translation.
     *
     * @return  array  The translated strings keyed by field.
     *
     * @throws  \RuntimeException  When the response holds no usable translation.
     *
     * @since   0.4.0
     */
    private function parseTranslation(string $body, array $expectedKeys): array
    {
        $response = json_decode($body, true);

        if (($response['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException('The Claude API refused the translation request.');
        }

        if (($response['stop_reason'] ?? '') === 'max_tokens') {
            throw new \RuntimeException('The Claude API response was cut off because the item is too long to translate in one request.');
        }

        $text = '';

        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');

                break;
            }
        }

        $translated = json_decode(trim($text), true);

        if (!\is_array($translated)) {
            throw new \RuntimeException('The Claude API returned an unreadable translation.');
        }

        // Keep only the strings asked for, so an unexpected key never reaches the draft.
        $strings = [];

        foreach ($expectedKeys as $key) {
            if (isset($translated[$key]) && \is_string($translated[$key])) {
                $strings[$key] = $translated[$key];
            }
        }

        if ($strings === []) {
            throw new \RuntimeException('The Claude API returned no usable translation.');
        }

        return $strings;
    }
}
