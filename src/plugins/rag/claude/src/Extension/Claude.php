<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Rag.claude
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Rag\Claude\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Translations\Administrator\Event\DistilEvent;
use Joomla\Component\Translations\Administrator\Event\NormaliseEvent;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\Http;

/**
 * Distillation provider plugin that distils translation rules from translator
 * corrections with the Anthropic Claude API. It answers the component's onDistil
 * event, returning the rule candidates or throwing so a failure reaches the user
 * rather than passing silently.
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
     * Upper bound on the response length, large enough for a whole batch's rules.
     *
     * @var    integer
     * @since  0.4.0
     */
    private const MAX_TOKENS = 16000;

    /**
     * Seconds to wait for the API, generous because distilling a batch takes a while.
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
        return [
            'onDistil'    => 'onDistil',
            'onNormalise' => 'onNormalise',
        ];
    }

    /**
     * Distil reusable rules from a batch of translator corrections with the Claude API.
     *
     * Reads the corrections and languages from the event and adds the distilled rule
     * candidates for the component. A missing key or any API failure throws, so the reason
     * reaches the user.
     *
     * @param   DistilEvent  $event  The distil event.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the key is missing or the API call fails.
     *
     * @since   0.4.0
     */
    public function onDistil(DistilEvent $event): void
    {
        $apiKey = trim((string) $this->params->get('api_key', ''));

        if ($apiKey === '') {
            throw new \RuntimeException('No Claude API key is configured for the RAG plugin.');
        }

        $rules = $this->requestDistillation(
            $event->getCorrections(),
            $event->getExistingRules(),
            $event->getSourceLanguage(),
            $event->getTargetLanguage(),
            $apiKey
        );

        $event->addResult($rules);

        // One provider answers per batch; stop the rest of the group running.
        $event->stopPropagation();
    }

    /**
     * Reduce words to their standard form with the Claude API.
     *
     * Reads the words and their language from the event and adds the standard form of each
     * one for the component. A missing key or any API failure throws, so the reason reaches
     * the caller, which falls back to matching the words as they were written.
     *
     * @param   NormaliseEvent  $event  The normalise event.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the key is missing or the API call fails.
     *
     * @since   0.9.0
     */
    public function onNormalise(NormaliseEvent $event): void
    {
        $apiKey = trim((string) $this->params->get('api_key', ''));

        if ($apiKey === '') {
            throw new \RuntimeException('No Claude API key is configured for the RAG plugin.');
        }

        $words = $event->getWords();

        if ($words === []) {
            $event->addResult([]);
            $event->stopPropagation();

            return;
        }

        $event->addResult($this->requestNormalisation($words, $event->getLanguage(), $apiKey));

        // One provider answers per batch; stop the rest of the group running.
        $event->stopPropagation();
    }

    /**
     * Ask the Claude API for the standard form of each word.
     *
     * The words are sent as one JSON array; the reply is constrained to the standard-form
     * schema, so it is always valid JSON.
     *
     * @param   array   $words     The words to reduce.
     * @param   string  $language  The language the words are written in.
     * @param   string  $apiKey    The Anthropic API key.
     *
     * @return  array  Standard form keyed by the word it was given.
     *
     * @throws  \RuntimeException  When the API cannot be reached or returns an error.
     *
     * @since   0.9.0
     */
    private function requestNormalisation(array $words, string $language, string $apiKey): array
    {
        $payload = [
            'model'         => (string) $this->params->get('model', 'claude-sonnet-5'),
            'max_tokens'    => self::MAX_TOKENS,
            'system'        => $this->normaliseSystemPrompt($language),
            'messages'      => [
                [
                    'role'    => 'user',
                    'content' => json_encode(
                        ['words' => array_values($words)],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ],
            'output_config' => ['format' => $this->standardFormsResponseFormat()],
        ];

        return $this->parseNormalisation($this->callApi($payload, $apiKey));
    }

    /**
     * Build the system prompt that states how a word is reduced to its standard form.
     *
     * @param   string  $language  The language the words are written in.
     *
     * @return  string
     *
     * @since   0.9.0
     */
    private function normaliseSystemPrompt(string $language): string
    {
        return \sprintf(
            'You reduce %1$s words to the standard form they would be listed under in a'
            . ' dictionary, so that a term and its inflected forms can be matched as one.'
            . ' Give a noun in its singular, a verb in its infinitive without any particle,'
            . ' and an adjective in its positive degree. Return every word you are given,'
            . ' each with the "word" exactly as you received it; a word that is already in'
            . ' its standard form, or that has no other form, is returned unchanged. The'
            . ' standard form is a single %1$s word in lower case, never a phrase, never a'
            . ' translation, and never an explanation. Respond with only the JSON object'
            . ' described by the schema.',
            $language
        );
    }

    /**
     * Build the structured-output format that pins the reply to the standard-form schema.
     *
     * The pairs come back as a list rather than an object keyed by word, because the schema
     * has to name every property it allows and the words differ on each request.
     *
     * @return  array
     *
     * @since   0.9.0
     */
    private function standardFormsResponseFormat(): array
    {
        $word = [
            'type'                 => 'object',
            'properties'           => [
                'word'          => ['type' => 'string'],
                'standard_form' => ['type' => 'string'],
            ],
            'required'             => ['word', 'standard_form'],
            'additionalProperties' => false,
        ];

        return [
            'type'   => 'json_schema',
            'schema' => [
                'type'                 => 'object',
                'properties'           => ['words' => ['type' => 'array', 'items' => $word]],
                'required'             => ['words'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Pull the standard forms out of the API response.
     *
     * @param   string  $body  The API response body.
     *
     * @return  array  Standard form keyed by the word it was given.
     *
     * @throws  \RuntimeException  When the response holds no usable standard forms.
     *
     * @since   0.9.0
     */
    private function parseNormalisation(string $body): array
    {
        $decoded = $this->decodeReply($body, 'normalisation');

        if (!isset($decoded['words']) || !\is_array($decoded['words'])) {
            throw new \RuntimeException('The Claude API returned an unreadable normalisation.');
        }

        $forms = [];

        foreach ($decoded['words'] as $pair) {
            if (\is_array($pair) && ($pair['word'] ?? '') !== '') {
                $forms[(string) $pair['word']] = (string) ($pair['standard_form'] ?? '');
            }
        }

        return $forms;
    }

    /**
     * Ask the Claude API to distil reusable rules from a batch of corrections.
     *
     * The corrections and the rules already learned for the language are sent as one JSON
     * object; the reply is constrained to the rules schema, so it is always valid JSON.
     *
     * @param   array   $corrections     The corrections to distil.
     * @param   array   $existingRules   The rules already learned for the language.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     * @param   string  $apiKey          The Anthropic API key.
     *
     * @return  array  The distilled rule candidates.
     *
     * @throws  \RuntimeException  When the API cannot be reached or returns an error.
     *
     * @since   0.4.0
     */
    private function requestDistillation(array $corrections, array $existingRules, string $sourceLanguage, string $targetLanguage, string $apiKey): array
    {
        $payload = [
            'model'         => (string) $this->params->get('model', 'claude-sonnet-5'),
            'max_tokens'    => self::MAX_TOKENS,
            'system'        => $this->distillSystemPrompt($sourceLanguage, $targetLanguage),
            'messages'      => [
                [
                    'role'    => 'user',
                    'content' => json_encode(
                        ['corrections' => $corrections, 'existing_rules' => $existingRules],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ],
            'output_config' => ['format' => $this->rulesResponseFormat()],
        ];

        return $this->parseDistillation($this->callApi($payload, $apiKey));
    }

    /**
     * Post a request to the Claude API and return the response body, throwing on failure.
     *
     * @param   array   $payload  The request payload.
     * @param   string  $apiKey   The Anthropic API key.
     *
     * @return  string  The response body.
     *
     * @throws  \RuntimeException  When the API cannot be reached or returns an error.
     *
     * @since   0.4.0
     */
    private function callApi(array $payload, string $apiKey): string
    {
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

        return (string) $response->getBody();
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
     * Build the system prompt that tells the model how to distil rules.
     *
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    private function distillSystemPrompt(string $sourceLanguage, string $targetLanguage): string
    {
        return \sprintf(
            'You are an expert reviewer building a reusable guide for translating Joomla content from %1$s to %2$s.'
            . ' You receive a JSON object with "corrections" and "existing_rules". Each correction has the source text'
            . ' (%1$s), the machine draft (%2$s), the human correction (%2$s), a focused "diff" of the change, and an'
            . ' "id". In the diff, "[term]" marks a single-word change (usually terminology), "[phrase]" marks a'
            . ' multi-word change (often tone or phrasing), and "[added]"/"[removed]" mark insertions and deletions.'
            . ' Each existing rule has an "id". Distil only genuine, reusable rules that would help translate future'
            . ' content the same way, each classified as "terminology" (a source term should be translated a certain'
            . ' way), "style" (a tone or phrasing preference), or "preservation" (a term or brand to leave'
            . ' untranslated). Ignore typos, nonsense and one-off content edits; if nothing is reusable, return an empty'
            . ' list. Prefer refining an existing rule over adding a near-duplicate: to refine one return it with its'
            . ' "id" and a raised "confidence", and use "id": 0 for a new rule. Put the term pair in'
            . ' "source_term"/"target_term" for terminology; for preservation put the term to keep in "source_term"'
            . ' only; leave them empty for style. "rule_text" states the rule in plain language as it will be given to the'
            . ' translation model; "confidence" is between 0 and 1 (about 0.9 or higher for a well-established'
            . ' convention, 0.5 to 0.7 for a plausible pattern seen once);'
            . ' "search_keywords" holds only %1$s words, because the rule is matched against the source text'
            . ' before it is translated;'
            . ' "source_feedback_ids" lists the correction ids the rule came from. Respond with only the JSON'
            . ' object described by the schema.',
            $sourceLanguage,
            $targetLanguage
        );
    }

    /**
     * Build the structured-output format that pins the reply to the rules schema, so the
     * distilled rules always come back as valid, schema-matching JSON.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    private function rulesResponseFormat(): array
    {
        $rule = [
            'type'                 => 'object',
            'properties'           => [
                'id'                  => ['type' => 'integer'],
                'rule_type'           => ['type' => 'string'],
                'rule_name'           => ['type' => 'string'],
                'rule_text'           => ['type' => 'string'],
                'source_term'         => ['type' => 'string'],
                'target_term'         => ['type' => 'string'],
                'search_keywords'     => ['type' => 'string'],
                'confidence'          => ['type' => 'number'],
                'source_feedback_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required'             => [
                'id', 'rule_type', 'rule_name', 'rule_text', 'source_term',
                'target_term', 'search_keywords', 'confidence', 'source_feedback_ids',
            ],
            'additionalProperties' => false,
        ];

        return [
            'type'   => 'json_schema',
            'schema' => [
                'type'                 => 'object',
                'properties'           => ['rules' => ['type' => 'array', 'items' => $rule]],
                'required'             => ['rules'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Pull the distilled rules out of the API response.
     *
     * @param   string  $body  The API response body.
     *
     * @return  array  The rule candidates.
     *
     * @throws  \RuntimeException  When the response holds no usable rules.
     *
     * @since   0.4.0
     */
    private function parseDistillation(string $body): array
    {
        $decoded = $this->decodeReply($body, 'distillation');

        if (!isset($decoded['rules']) || !\is_array($decoded['rules'])) {
            throw new \RuntimeException('The Claude API returned an unreadable distillation.');
        }

        return $decoded['rules'];
    }

    /**
     * Decode the JSON object the API was asked to reply with.
     *
     * The reply is pinned by a structured-output schema, so anything that is not a readable
     * JSON object means the request was refused or cut off rather than answered.
     *
     * @param   string  $body  The API response body.
     * @param   string  $what  What was asked for, named in the error the user sees.
     *
     * @return  array  The decoded reply.
     *
     * @throws  \RuntimeException  When the reply is refused, cut off or unreadable.
     *
     * @since   0.9.0
     */
    private function decodeReply(string $body, string $what): array
    {
        $response = json_decode($body, true);

        if (($response['stop_reason'] ?? '') === 'refusal') {
            throw new \RuntimeException(\sprintf('The Claude API refused the %s request.', $what));
        }

        if (($response['stop_reason'] ?? '') === 'max_tokens') {
            throw new \RuntimeException(
                \sprintf('The Claude API response was cut off because the %s request is too large.', $what)
            );
        }

        $text = '';

        foreach ((array) ($response['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');

                break;
            }
        }

        $decoded = json_decode(trim($text), true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('The Claude API returned an unreadable %s.', $what));
        }

        return $decoded;
    }
}
